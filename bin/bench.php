<?php
/**
 * Uptimer — banc d'essai de bout en bout.
 *
 * Monte un faux site local qui reproduit chaque panne à détecter, lance de
 * vraies vérifications dessus, et vérifie les verdicts. Utile pour prouver que
 * la détection fonctionne sur VOTRE hébergement (versions de curl, d'OpenSSL,
 * de SQLite, restrictions sortantes…).
 *
 *   php bin/bench.php
 *
 * Aucune donnée de production n'est touchée : base jetable, notifications
 * redirigées vers le faux site, tout est supprimé à la fin.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Uptimer\Config;
use Uptimer\Db;
use Uptimer\Importer;
use Uptimer\Runner;
use Uptimer\Stats;

if (PHP_SAPI !== 'cli') exit("À lancer en ligne de commande.\n");

$pass = 0; $fail = 0;
function ok(string $label, bool $good, string $detail = ''): void
{
    global $pass, $fail;
    $good ? $pass++ : $fail++;
    $pad = str_repeat(' ', max(1, 52 - mb_strlen($label)));
    echo ($good ? " OK  " : "ÉCHEC ") . $label . $pad . ($detail !== '' ? '→ ' . $detail : '') . "\n";
}
function title(string $s): void { echo "\n── $s " . str_repeat('─', max(0, 60 - mb_strlen($s))) . "\n"; }

// =========================================================================
// 1. Faux site local
// =========================================================================
$tmp = sys_get_temp_dir() . '/uptimer-bench-' . bin2hex(random_bytes(4));
@mkdir($tmp . '/blog', 0775, true);
$port = 0;
for ($p = 8770; $p < 8820; $p++) {
    $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
    if ($sock) { fclose($sock); $port = $p; break; }
}
if (!$port) exit("Aucun port libre entre 8770 et 8820.\n");
$BASE = "http://127.0.0.1:$port";

// --- CSS complet et réaliste ---------------------------------------------
$classes = ['site-header', 'nav-main', 'nav-link', 'hero', 'hero-title', 'hero-sub', 'btn', 'btn-primary',
            'card', 'card-grid', 'card-title', 'section', 'footer-main', 'footer-col', 'contact-form',
            'field', 'testimonial', 'price-box', 'badge-new'];
$css = [":root{--brand:#0a58ff;--ink:#111;--radius:12px}", "*{box-sizing:border-box}",
        "body{margin:0;font-family:system-ui,sans-serif;color:var(--ink);background:#fff}"];
foreach ($classes as $c) {
    $css[] = ".$c{display:flex;padding:12px 16px;margin:0 auto;max-width:1180px;gap:12px;font-family:inherit}";
    $css[] = ".$c:hover{opacity:.92;transition:.2s}";
}
foreach (['(max-width:1200px)', '(max-width:960px)', '(max-width:720px)', '(max-width:480px)'] as $mq) {
    $css[] = "@media $mq{.hero-title{font-size:2rem}.card-grid{display:grid;grid-template-columns:1fr;gap:16px}}";
}
$css[] = ".hero{display:grid;grid-template-columns:1.2fr 1fr;gap:32px;max-width:1180px}";
file_put_contents($tmp . '/style.css', implode("\n", $css));
file_put_contents($tmp . '/thin.css', ".site-header{color:#000}\n.footer-main{color:#000}\n");

$page = function (string $t, string $links, string $body = '', string $head = '') use ($classes): string {
    $blocks = '';
    foreach ($classes as $i => $c) {
        $blocks .= '<div class="' . $c . '"><span class="' . $classes[($i + 3) % count($classes)] . '">Bloc ' . $i . '</span></div>';
    }
    return "<!doctype html>\n<html lang=\"fr\"><head><meta charset=\"utf-8\"><title>$t — Agence Bellevue</title>\n"
        . "<meta property=\"og:site_name\" content=\"Agence Bellevue\">\n$head\n$links\n</head><body>\n"
        . '<header class="site-header"><nav class="nav-main"><a class="nav-link" href="/services.html">Nos services</a>'
        . '<a class="nav-link" href="/contact.html">Contact</a></nav></header>'
        . "<main class=\"hero\"><h1 class=\"hero-title\">$t</h1><p class=\"hero-sub\">Studio de création</p>"
        . '<a class="btn btn-primary" href="/contact.html">Demander un devis</a></main>'
        . $blocks . $body
        . '<footer class="footer-main"><div class="footer-col">© 2026 Agence Bellevue — tous droits réservés</div></footer>'
        . "</body></html>";
};

$L = '<link rel="stylesheet" href="/style.css?ver=4.2">';
foreach (['ok' => 'Accueil', 'contact' => 'Contact', 'services' => 'Nos services', 'tarifs' => 'Tarifs',
          'mentions-legales' => 'Mentions légales'] as $f => $t) {
    file_put_contents("$tmp/$f.html", $page($t, $L));
}
file_put_contents("$tmp/blog/article-un.html", $page('Article un', $L));
file_put_contents("$tmp/css404.html",   $page('Accueil', '<link rel="stylesheet" href="/wp-content/cache/min/1/absent.css">'));
file_put_contents("$tmp/cssmime.html",  $page('Accueil', '<link rel="stylesheet" href="/fake.css.php">'));
file_put_contents("$tmp/fake.css.php",  "<?php header('Content-Type: text/html'); ?><!doctype html><html><body>404 Not Found</body></html>");
file_put_contents("$tmp/cssthin.html",  $page('Accueil', '<link rel="stylesheet" href="/thin.css">'));
file_put_contents("$tmp/cssnone.html",  $page('Accueil', ''));
file_put_contents("$tmp/csshidden.html", $page('Accueil', $L,
    str_repeat('<div class="elementor-invisible" data-settings="{}">Bloc masqué</div>', 4),
    '<script src="/wp-content/plugins/elementor/assets/js/frontend.min.js"></script>'));
file_put_contents("$tmp/noindex.html",  $page('Page privée', $L, '', '<meta name="robots" content="noindex, nofollow">'));
file_put_contents("$tmp/dberror.php",   "<?php http_response_code(200); ?><!doctype html><html><head><title>Erreur</title></head><body><h1>Error establishing a database connection</h1></body></html>");
file_put_contents("$tmp/phpfatal.php",  "<?php http_response_code(200); ?><b>Fatal error</b>: Uncaught Error: Call to a member function prepare() on null in /wp-includes/db.php:1451");
file_put_contents("$tmp/err500.php",    "<?php http_response_code(500); ?>Internal Server Error");
file_put_contents("$tmp/err404.php",    "<?php http_response_code(404); ?>Not Found");
file_put_contents("$tmp/slow.php",      "<?php usleep(2600000); ?><!doctype html><html><body>Lent mais vivant — Agence Bellevue</body></html>");
file_put_contents("$tmp/health.php",    "<?php header('Content-Type: application/json'); echo json_encode(['status'=>'ok','db'=>true]);");
file_put_contents("$tmp/health_bad.php","<?php header('Content-Type: application/json'); echo json_encode(['status'=>'degraded']);");
file_put_contents("$tmp/robots.txt",    "User-agent: *\nAllow: /\nSitemap: $BASE/sitemap.xml\n");
$sm = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
       "<url><loc>$BASE/</loc><priority>1.0</priority></url>"];
foreach (['ok.html', 'contact.html', 'services.html', 'tarifs.html', 'mentions-legales.html', 'blog/article-un.html'] as $u) {
    $sm[] = "<url><loc>$BASE/$u</loc><lastmod>2026-07-01</lastmod><priority>0.8</priority></url>";
}
$sm[] = '</urlset>';
file_put_contents("$tmp/sitemap.xml", implode("\n", $sm));
// Routeur : vrais 404 pour les ressources absentes (le serveur intégré retombe
// sinon sur index.php et renvoie 200 partout).
file_put_contents("$tmp/router.php", <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($path === '/') { header('Content-Type: text/html; charset=utf-8'); readfile(__DIR__ . '/ok.html'); return true; }
$file = realpath(__DIR__ . $path);
if ($file && is_file($file) && str_starts_with($file, __DIR__)) return false;
http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html><head><title>404 Not Found</title></head><body><h1>Not Found</h1></body></html>';
return true;
PHP);

echo "Banc d'essai Uptimer — faux site sur $BASE\n";
// Commande passée en tableau : sans cela proc_open lance « sh -c », et l'arrêt
// ne tuerait que le shell en laissant le serveur derrière lui.
$cmd = [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $tmp, $tmp . '/router.php'];
// Volontairement mono-processus : les workers forkés par le serveur intégré de
// PHP survivraient à l'arrêt du maître et garderaient le port occupé.
$srv  = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $tmp);
if (!is_resource($srv)) exit("Impossible de démarrer le serveur de test.\n");

$cleanup = function () use ($srv, $tmp, $port): void {
    if (is_resource($srv)) {
        proc_terminate($srv);
        // Deuxième chance en SIGKILL si le port reste occupé.
        for ($i = 0; $i < 10; $i++) {
            usleep(100000);
            $s = @stream_socket_client("tcp://127.0.0.1:$port", $e1, $e2, 0.2);
            if (!$s) break;
            fclose($s);
            if ($i === 4) proc_terminate($srv, 9);
        }
        proc_close($srv);
    }
    foreach (['blog', ''] as $sub) {
        foreach (glob(rtrim("$tmp/$sub", '/') . '/*') ?: [] as $f) if (is_file($f)) @unlink($f);
        if ($sub !== '') @rmdir("$tmp/$sub");
    }
    @unlink(dirname(__DIR__) . '/data/bench.sqlite');
    @unlink(dirname(__DIR__) . '/data/bench.sqlite-wal');
    @unlink(dirname(__DIR__) . '/data/bench.sqlite-shm');
    @rmdir($tmp);
};
register_shutdown_function($cleanup);

// Attente de disponibilité
$up = false;
for ($i = 0; $i < 40; $i++) {
    $s = @stream_socket_client("tcp://127.0.0.1:$port", $e1, $e2, 0.3);
    if ($s) { fclose($s); $up = true; break; }
    usleep(150000);
}
if (!$up) exit("Le serveur de test n'a pas démarré.\n");

// =========================================================================
// 2. Base jetable
// =========================================================================
$benchDb = dirname(__DIR__) . '/data/bench.sqlite';
@unlink($benchDb);
Config::set('db.driver', 'sqlite');
Config::set('db.sqlite', $benchDb);
Config::set('notify.discord.enabled', false);
Config::set('notify.slack.enabled', false);
Config::set('notify.mail.enabled', false);
Config::set('notify.webhook.enabled', true);
Config::set('notify.webhook.url', "$BASE/health.php");
Config::set('notify.quiet_hours', '');
Db::migrate();

$mk = function (array $over) use (&$mk): int {
    return Db::insert('monitors', array_merge([
        'name' => 'sonde', 'url' => '', 'kind' => 'page', 'role' => 'primary', 'method' => 'GET',
        // Le faux site répond en série : on laisse de la marge et on ne juge la
        // lenteur que sur la sonde dédiée, seule à avoir un seuil serré.
        'interval_sec' => 300, 'timeout_sec' => 25, 'retries' => 0,
        'expect_status' => '200-299', 'check_ssl' => 0, 'check_css' => 1, 'check_db' => 1,
        'check_noindex' => 1, 'ssl_warn_days' => 14, 'css_drop_pct' => 35, 'enabled' => 1,
        'status' => 'unknown', 'setup_state' => 'done', 'created_at' => now(),
        'next_check_at' => now(), 'follow_redirects' => 1,
    ], $over));
};

// =========================================================================
// 3. Scénarios de panne
// =========================================================================
title('Détection des anomalies');
$cases = [
    ['ok',        '/ok.html',        'up',       null,          []],
    ['css404',    '/css404.html',    'down',     'CSS_BROKEN',  []],
    ['cssmime',   '/cssmime.html',   'down',     'CSS_BROKEN',  []],
    ['csshidden', '/csshidden.html', 'down',     'CSS_BROKEN',  []],
    ['cssnone',   '/cssnone.html',   'degraded', 'CSS_DEGRADED',[]],
    ['dberror',   '/dberror.php',    'down',     'DB_DOWN',     []],
    ['phpfatal',  '/phpfatal.php',   'down',     'DB_DOWN',     []],
    ['err500',    '/err500.php',     'down',     'HTTP_5XX',    []],
    ['err404',    '/err404.php',     'down',     'HTTP_404',    []],
    ['lenteur',   '/slow.php',       'degraded', 'SLOW',        ['check_css' => 0, 'slow_ms' => 2000]],
    ['noindex',   '/noindex.html',   'degraded', 'NOINDEX',     []],
];
$ids = [];
foreach ($cases as [$name, $path, $want, $reason, $over]) {
    $ids[$name] = $mk(array_merge(['name' => $name, 'url' => $BASE . $path], $over));
}
$ids['injoignable'] = $mk(['name' => 'injoignable', 'url' => 'http://127.0.0.1:9/', 'check_css' => 0]);
$ids['dns']         = $mk(['name' => 'dns', 'url' => 'http://ce-domaine-nexiste-pas-4823uptimer.fr/', 'check_css' => 0]);

$t0 = microtime(true);
$run = Runner::runDue(60, 150);
printf("   %d sondes vérifiées en %.1f s (%d HS, %d dégradées, %d OK)\n\n",
    $run['ran'], $run['seconds'], $run['down'], $run['degraded'], $run['up']);

foreach ($cases as [$name, $path, $want, $reason, $over]) {
    $m = Db::one('SELECT status, reason_code, last_message FROM monitors WHERE id = ?', [$ids[$name]]);
    ok(sprintf('%-11s %s', $name, $want), $m['status'] === $want && ($reason === null || $m['reason_code'] === $reason),
        $m['status'] . ($m['reason_code'] ? ' / ' . $m['reason_code'] : '') . ' — ' . str_cut((string)$m['last_message'], 70));
}
foreach ([['injoignable', ['CONNECT', 'TIMEOUT', 'CONNECT_RESET']], ['dns', ['DNS']]] as [$n, $codes]) {
    $m = Db::one('SELECT status, reason_code FROM monitors WHERE id = ?', [$ids[$n]]);
    ok(sprintf('%-11s down', $n), $m['status'] === 'down' && in_array((string)$m['reason_code'], $codes, true),
        $m['status'] . ' / ' . $m['reason_code']);
}

// --- Feuille amputée : nécessite une référence apprise --------------------
title('Feuille de style amputée (référence apprise)');
$b = $mk(['name' => 'baseline', 'url' => "$BASE/ok.html", 'slow_ms' => 9000]);
Runner::runOne($b);
$base = jdec(Db::val('SELECT css_baseline FROM monitors WHERE id = ?', [$b]));
ok('référence CSS apprise sur un état sain', ($base['css_bytes'] ?? 0) > 2000,
    human_bytes((int)($base['css_bytes'] ?? 0)) . ', couverture ' . round((float)($base['coverage'] ?? 0) * 100) . ' %');
Db::update('monitors', ['url' => "$BASE/cssthin.html", 'css_checked_at' => null], 'id = :i', ['i' => $b]);
Runner::runOne($b);
$m = Db::one('SELECT status, reason_code, css_detail FROM monitors WHERE id = ?', [$b]);
ok('chute de CSS détectée', $m['status'] === 'down' && $m['reason_code'] === 'CSS_BROKEN',
    str_cut(implode(' ', jdec($m['css_detail'])['messages'] ?? []), 90));
Runner::runOne($b);
$m2 = Db::one('SELECT status, reason_code FROM monitors WHERE id = ?', [$b]);
ok('verdict CSS conservé entre deux analyses', $m2['status'] === 'down' && $m2['reason_code'] === 'CSS_BROKEN',
    $m2['status'] . ' / ' . $m2['reason_code']);

// --- Chaîne de contrôle --------------------------------------------------
title('Chaîne de contrôle (preuve web + base)');
$s = $mk(['name' => 'preuve', 'url' => "$BASE/ok.html", 'check_css' => 0,
          'expect_string' => 'Agence Bellevue']);
Runner::runOne($s);
ok('chaîne présente → opérationnel', Db::val('SELECT status FROM monitors WHERE id = ?', [$s]) === 'up');
Db::update('monitors', ['expect_string' => 'Texte totalement absent'], 'id = :i', ['i' => $s]);
Runner::runOne($s);
$m = Db::one('SELECT status, reason_code FROM monitors WHERE id = ?', [$s]);
ok('chaîne absente → hors service', $m['status'] === 'down' && $m['reason_code'] === 'STRING_MISSING',
    (string)$m['reason_code']);

// --- API JSON ------------------------------------------------------------
title('Sonde API');
$a = $mk(['name' => 'api', 'url' => "$BASE/health.php", 'kind' => 'api', 'check_css' => 0,
          'json_path' => 'status', 'json_expect' => 'ok']);
Runner::runOne($a);
ok('réponse conforme', Db::val('SELECT status FROM monitors WHERE id = ?', [$a]) === 'up');
Db::update('monitors', ['url' => "$BASE/health_bad.php"], 'id = :i', ['i' => $a]);
Runner::runOne($a);
ok('valeur JSON inattendue détectée',
    Db::val('SELECT reason_code FROM monitors WHERE id = ?', [$a]) === 'JSON_VALUE');

// --- Mot surveillé -------------------------------------------------------
title('Mise à jour de page');
$w = $mk(['name' => 'motclef', 'url' => "$BASE/ok.html", 'check_css' => 0,
          'watch_string' => 'Demander un devis', 'watch_mode' => 'appear']);
Runner::runOne($w);
ok('présence enregistrée', Db::val('SELECT watch_state FROM monitors WHERE id = ?', [$w]) === 'present');
Db::update('monitors', ['watch_string' => 'Soldes de printemps'], 'id = :i', ['i' => $w]);
Runner::runOne($w);
ok('disparition journalisée',
    Db::val('SELECT watch_state FROM monitors WHERE id = ?', [$w]) === 'absent'
    && (int)Db::val('SELECT COUNT(*) FROM events WHERE monitor_id = ?', [$w]) >= 1);

// --- Import et préparation ----------------------------------------------
title('Import de masse et préparation automatique');
$parsed = Importer::parse("$BASE/\n$BASE/contact.html | Contact | Agence Bellevue\n# note\npas une url !!");
ok('analyse de la liste', count($parsed['rows']) === 2 && count($parsed['errors']) === 1,
    count($parsed['rows']) . ' valides, ' . count($parsed['errors']) . ' rejetée(s)');
$res = Importer::createMonitors($parsed['rows'], ['discover' => 1, 'pages' => 4, 'extras' => 1, 'group' => 'Banc']);
$before = (int)Db::val('SELECT COUNT(*) FROM monitors');
foreach ($res['ids'] as $mid) Importer::setup((int)$mid);
$after = (int)Db::val('SELECT COUNT(*) FROM monitors');
ok('pages découvertes via le sitemap', $after > $before, ($after - $before) . ' sonde(s) ajoutée(s)');
$prooved = (int)Db::val("SELECT COUNT(*) FROM monitors WHERE expect_string = 'Agence Bellevue'");
ok('chaîne de contrôle déduite du contenu', $prooved >= 2, $prooved . ' sonde(s)');

// --- Alertes -------------------------------------------------------------
title('Chaîne d\'alerte');
$n0 = (int)Db::val('SELECT COUNT(*) FROM notifications');
$al = $mk(['name' => 'alerte', 'url' => "$BASE/err500.php", 'check_css' => 0, 'retries' => 1, 'slow_ms' => 9000]);
Runner::runOne($al);
$last = Db::one('SELECT * FROM notifications ORDER BY id DESC LIMIT 1');
ok('alerte émise à l\'ouverture de l\'incident',
    (int)Db::val('SELECT COUNT(*) FROM notifications') > $n0 && (int)($last['ok'] ?? 0) === 1,
    ($last['channel'] ?? '?') . ' — ' . str_cut((string)($last['response'] ?? ''), 45));
ok('relance effectuée avant de conclure',
    (int)Db::val('SELECT attempts FROM checks WHERE monitor_id = ? ORDER BY id DESC LIMIT 1', [$al]) >= 2,
    Db::val('SELECT attempts FROM checks WHERE monitor_id = ? ORDER BY id DESC LIMIT 1', [$al]) . ' tentative(s)');
$n1 = (int)Db::val('SELECT COUNT(*) FROM notifications');
Db::update('monitors', ['url' => "$BASE/ok.html"], 'id = :i', ['i' => $al]);
Runner::runOne($al);
ok('alerte de rétablissement émise', (int)Db::val('SELECT COUNT(*) FROM notifications') > $n1);
$inc = Db::one('SELECT * FROM incidents WHERE monitor_id = ? ORDER BY id DESC LIMIT 1', [$al]);
ok('incident clos avec sa durée', !empty($inc['ended_at']) && $inc['duration_sec'] !== null,
    'durée ' . human_duration((int)$inc['duration_sec']));

$h = (int)date('H');
Db::update('monitors', ['url' => "$BASE/err500.php", 'maintenance' => sprintf('%02d:00-%02d:59', $h, $h),
                        'next_check_at' => now()], 'id = :i', ['i' => $al]);
$n2 = (int)Db::val('SELECT COUNT(*) FROM notifications');
Runner::runBatch([Db::one('SELECT * FROM monitors WHERE id = ?', [$al])]);
ok('silence pendant une fenêtre de maintenance',
    (int)Db::val('SELECT COUNT(*) FROM notifications') === $n2
    && Db::val('SELECT status FROM monitors WHERE id = ?', [$al]) === 'paused');

// --- Certificats réels ---------------------------------------------------
// Utilise les endpoints publics de test badssl.com : c'est le seul moyen de
// vérifier que le magasin de certificats de VOTRE serveur est exploitable.
title('Certificats SSL (badssl.com, ignoré sans accès sortant)');
$sslCases = [
    ['badssl.com',               'certificat valide',      [null]],
    ['expired.badssl.com',       'certificat expiré',      ['SSL_EXPIRED', 'SSL_INVALID']],
    ['self-signed.badssl.com',   'certificat auto-signé',  ['SSL_INVALID']],
    ['wrong.host.badssl.com',    'domaine non couvert',    ['SSL_INVALID']],
    ['untrusted-root.badssl.com','autorité inconnue',      ['SSL_INVALID']],
];
$sslReachable = true;
foreach ($sslCases as [$host, $label, $want]) {
    if (!$sslReachable) break;
    $r = Uptimer\Check\Ssl::inspect($host, 443, 10);
    if (!$r['checked'] || ($r['code'] === 'SSL_HANDSHAKE' && $r['days_left'] === null)) {
        echo "   (accès sortant HTTPS indisponible : section ignorée)\n";
        $sslReachable = false;
        break;
    }
    ok($label, in_array($r['code'], $want, true),
        ($r['code'] ?? 'valide') . ($r['error'] ? ' — ' . str_cut((string)$r['error'], 45) : '')
        . ($r['days_left'] !== null ? ' · ' . $r['days_left'] . ' j' : ''));
}

// --- Alertes corrélées ----------------------------------------------------
title('Alertes corrélées (une panne de serveur = une alerte)');
Db::q("DELETE FROM notifications");
$grpSites = [];
for ($i = 1; $i <= 5; $i++) {
    $sid = Db::insert('sites', ['name' => "Client groupé $i", 'domain' => "clientgrp$i.test", 'created_at' => now()]);
    $grpSites[] = $mk(['site_id' => $sid, 'name' => "Client groupé $i", 'url' => 'http://127.0.0.1:9/g' . $i,
                       'check_css' => 0, 'check_db' => 0, 'timeout_sec' => 3]);
}
// Toutes ces sondes pointent (fictivement) sur la même machine.
Db::q("UPDATE monitors SET last_ip = '203.0.113.10', next_check_at = ? WHERE id IN ("
    . implode(',', array_map('intval', $grpSites)) . ")", [now()]);
Db::q("DELETE FROM events WHERE kind = 'grouped_alert'");
$before = (int)Db::val('SELECT COUNT(*) FROM notifications');
Runner::runDue(30, 60);
$after = (int)Db::val('SELECT COUNT(*) FROM notifications');
$grouped = (int)Db::val("SELECT COUNT(*) FROM events WHERE kind = 'grouped_alert'");
ok('5 sites d\'un même serveur → 1 seule alerte', ($after - $before) === 1 && $grouped === 1,
    ($after - $before) . ' notification(s), ' . $grouped . ' évènement groupé');
ok('les 5 incidents sont bien ouverts',
    (int)Db::val("SELECT COUNT(*) FROM incidents WHERE monitor_id IN ("
        . implode(',', array_map('intval', $grpSites)) . ") AND ended_at IS NULL") === 5);
$msg = (string)Db::val("SELECT message FROM events WHERE kind = 'grouped_alert' ORDER BY id DESC LIMIT 1");
ok('le message nomme le serveur fautif', str_contains($msg, '203.0.113.10'), str_cut($msg, 60));

// Deux sites seulement : pas de regroupement, chacun son alerte.
Db::q("DELETE FROM notifications");
Db::q("DELETE FROM incidents WHERE monitor_id IN (" . implode(',', array_map('intval', $grpSites)) . ")");
Db::q("UPDATE monitors SET status = 'up', next_check_at = ? WHERE id IN ("
    . implode(',', array_map('intval', array_slice($grpSites, 0, 2))) . ")", [now()]);
Db::q("UPDATE monitors SET enabled = 0 WHERE id IN ("
    . implode(',', array_map('intval', array_slice($grpSites, 2))) . ")");
Runner::runDue(30, 60);
ok('en dessous du seuil : alertes individuelles',
    (int)Db::val('SELECT COUNT(*) FROM notifications') === 2,
    Db::val('SELECT COUNT(*) FROM notifications') . ' notification(s)');

// --- Statistiques --------------------------------------------------------
title('Statistiques');
Stats::refreshStale(0, 200);
$sr = Stats::series($ids['ok'], 86400, 48);
ok('mesure de l\'instant visible dans la série',
    count(array_filter($sr['buckets'], fn($x) => $x['n'] > 0)) >= 1);
$sb = Stats::sparkBatch(array_values($ids), 86400, 24);
ok('séries groupées pour le tableau de bord', count($sb) === count($ids), count($sb) . ' sonde(s)');
$sum = Stats::summary();
ok('synthèse globale cohérente', $sum['down'] > 0 && $sum['total'] > 0,
    $sum['total'] . ' sondes, ' . $sum['down'] . ' HS, ' . $sum['degraded'] . ' dégradées');
ok('consolidation journalière', Stats::rollup(date('Y-m-d')) > 0);
$w24 = Stats::window($ids['err500'], 86400);
ok('uptime et incidents calculés', $w24['incidents'] >= 1, 'incidents=' . $w24['incidents']);

// =========================================================================
echo "\n" . str_repeat('═', 68) . "\n";
printf("%d contrôle(s) réussi(s), %d échec(s) — %.1f s\n", $pass, $fail, microtime(true) - $t0);
if ($fail > 0) {
    echo "⚠️  Certaines détections ne fonctionnent pas sur cet hébergement.\n";
    exit(1);
}
echo "✅ Toutes les anomalies du banc d'essai sont correctement détectées.\n";
