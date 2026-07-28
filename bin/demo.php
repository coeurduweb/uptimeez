<?php
/**
 * Uptimer — jeu de démonstration.
 *
 * Monte un parc d'agence fictif (9 sites, 30 jours d'historique) sur un faux
 * site local, pour visiter l'interface avant de brancher de vrais domaines.
 *
 *   php bin/demo.php            # installe la démo (mot de passe : demo1234)
 *   php bin/demo.php --purge    # supprime la démo
 *
 * Refuse d'écraser une installation existante : vos données sont en sécurité.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use Uptimer\Config;
use Uptimer\Db;
use Uptimer\Runner;
use Uptimer\Stats;

if (PHP_SAPI !== 'cli') exit("À lancer en ligne de commande.\n");

$root   = dirname(__DIR__);
$purge  = in_array('--purge', $argv, true);
$marker = $root . '/data/.demo';

if ($purge) {
    if (!is_file($marker)) exit("Aucune démonstration installée.\n");
    foreach (['/config.php', '/data/uptimer.sqlite', '/data/uptimer.sqlite-wal', '/data/uptimer.sqlite-shm', '/data/.demo'] as $f) {
        @unlink($root . $f);
    }
    foreach (glob($root . '/data/demo-site/*') ?: [] as $f) @unlink($f);
    @rmdir($root . '/data/demo-site');
    exit("Démonstration supprimée. Ouvrez install.php pour une installation propre.\n");
}

if (is_file($root . '/config.php') && !is_file($marker)) {
    exit("Uptimer est déjà installé pour de vrai : la démonstration écraserait votre configuration.\n"
       . "Supprimez config.php si vous voulez malgré tout repartir de la démo.\n");
}

// Le faux site qui sert de cible aux sondes de démonstration.
$fixtures = $root . '/data/demo-site';
$port = 0;
for ($p = 8840; $p < 8890; $p++) {
    $s = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
    if ($s) { fclose($s); $port = $p; break; }
}
if (!$port) exit("Aucun port libre pour le faux site de démonstration.\n");
$B = "http://127.0.0.1:$port";

// --- Génération du faux site ---------------------------------------------
@mkdir($fixtures, 0775, true);
$classes = ['site-header', 'nav-main', 'nav-link', 'hero', 'hero-title', 'hero-sub', 'btn', 'btn-primary',
            'card', 'card-grid', 'card-title', 'section', 'footer-main', 'footer-col', 'price-box'];
$css = [':root{--brand:#0a58ff}', '*{box-sizing:border-box}', 'body{margin:0;font-family:system-ui,sans-serif}'];
foreach ($classes as $c) {
    $css[] = ".$c{display:flex;padding:12px 16px;margin:0 auto;max-width:1180px;gap:12px}";
    $css[] = ".$c:hover{opacity:.92;transition:.2s}";
}
foreach (['(max-width:1200px)', '(max-width:960px)', '(max-width:720px)'] as $mq) {
    $css[] = "@media $mq{.hero-title{font-size:2rem}.card-grid{display:grid;gap:16px}}";
}
file_put_contents("$fixtures/style.css", implode("\n", $css));
$page = function (string $t, string $links, string $extraHead = '') use ($classes): string {
    $b = '';
    foreach ($classes as $i => $c) $b .= '<div class="' . $c . '">Bloc ' . $i . '</div>';
    return "<!doctype html><html lang=\"fr\"><head><meta charset=\"utf-8\"><title>$t — Agence Bellevue</title>"
        . "<meta property=\"og:site_name\" content=\"Agence Bellevue\">$extraHead$links</head><body>"
        . '<header class="site-header"><nav class="nav-main"><a class="nav-link" href="/contact.html">Contact</a></nav></header>'
        . "<main class=\"hero\"><h1 class=\"hero-title\">$t</h1><a class=\"btn btn-primary\" href=\"/contact.html\">Demander un devis</a></main>"
        . $b . '<footer class="footer-main">© 2026 Agence Bellevue — tous droits réservés</footer></body></html>';
};
$L = '<link rel="stylesheet" href="/style.css?ver=1">';
foreach (['ok' => 'Accueil', 'contact' => 'Contact', 'services' => 'Nos services',
          'tarifs' => 'Tarifs', 'mentions-legales' => 'Mentions légales'] as $f => $t) {
    file_put_contents("$fixtures/$f.html", $page($t, $L));
}
file_put_contents("$fixtures/css404.html", $page('Accueil', '<link rel="stylesheet" href="/wp-content/cache/min/1/absent.css">'));
file_put_contents("$fixtures/noindex.html", $page('Préproduction', $L, '<meta name="robots" content="noindex, nofollow">'));
file_put_contents("$fixtures/dberror.php", "<?php http_response_code(200); ?><!doctype html><html><head><title>Erreur</title></head><body><h1>Error establishing a database connection</h1></body></html>");
file_put_contents("$fixtures/slow.php", "<?php usleep(2600000); ?><!doctype html><html><body>Lent mais vivant — Agence Bellevue</body></html>");
file_put_contents("$fixtures/health.php", "<?php header('Content-Type: application/json'); echo json_encode(['status'=>'ok','db'=>true]);");
file_put_contents("$fixtures/robots.txt", "User-agent: *\nAllow: /\nSitemap: $B/sitemap.xml\n");
$sm = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];
foreach (['', 'contact.html', 'services.html', 'tarifs.html', 'mentions-legales.html'] as $u) {
    $sm[] = "<url><loc>$B/$u</loc><priority>0.8</priority></url>";
}
file_put_contents("$fixtures/sitemap.xml", implode("\n", $sm) . '</urlset>');
file_put_contents("$fixtures/router.php", "<?php\n"
    . "\$p = parse_url(\$_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';\n"
    . "if (\$p === '/') { readfile(__DIR__ . '/ok.html'); return true; }\n"
    . "\$f = realpath(__DIR__ . \$p);\n"
    . "if (\$f && is_file(\$f) && str_starts_with(\$f, __DIR__)) return false;\n"
    . "http_response_code(404); echo '<!doctype html><html><head><title>404 Not Found</title></head><body>Not Found</body></html>';\n"
    . "return true;\n");

echo "Faux site généré dans data/demo-site.\n";
echo "Pour que les sondes interrogent réellement quelque chose, laissez tourner\n";
echo "dans un autre terminal :\n";
echo "   php -S 127.0.0.1:$port -t " . $fixtures . " " . $fixtures . "/router.php\n\n";
@unlink($root . '/data/uptimer.sqlite');
@unlink($root . '/data/uptimer.sqlite-wal');
@unlink($root . '/data/uptimer.sqlite-shm');
Config::save([
    'db'   => ['driver' => 'sqlite', 'sqlite' => $root . '/data/uptimer.sqlite'],
    'auth' => ['password_hash' => password_hash('demo1234', PASSWORD_DEFAULT)],
    'app'  => ['name' => 'Uptimer', 'demo' => true, 'base_url' => '', 'cron_key' => bin2hex(random_bytes(12)),
               'public_token' => 'demo', 'timezone' => 'Europe/Paris'],
    'notify' => ['discord' => ['enabled' => false, 'webhook' => ''], 'slack' => ['enabled' => false, 'webhook' => ''],
                 'mail' => ['enabled' => false, 'to' => ''], 'webhook' => ['enabled' => false, 'url' => '']],
]);
@file_put_contents($marker, date('c'));
Db::migrate();

/**
 * Parc de démonstration.
 *
 * Les domaines sont ceux de services que tout le monde reconnaît : une capture
 * d'écran parle immédiatement, là où « Boutique Dupont » n'évoque rien. Deux
 * précautions, parce qu'il s'agit de marques réelles :
 *
 *   1. les mesures sont entièrement fictives, et l'interface l'affiche en
 *      permanence (bandeau « mode démonstration ») ;
 *   2. les quatre pannes ne portent jamais sur un service public réel : elles
 *      sont placées sur des sous-domaines de préproduction (staging., preprod.,
 *      beta., recette.) qui n'existent pas. Un préprod cassé est plausible,
 *      recognaissable, et n'affirme rien sur le service que les gens utilisent.
 *
 * Le collecteur ne joindra de toute façon jamais ces domaines : les sondes
 * pointent sur le faux site local, seul l'affichage porte le nom réel.
 */
$sites = [
    // --- Ce qui va bien : de vrais services publics -----------------------
    ['Wikipédia',        'wikipedia.org',           'MediaWiki', 'Références', "$B/ok.html",       ['contact.html', 'tarifs.html', 'services.html']],
    ['GitHub',           'github.com',              'Ruby on Rails', 'Outils', "$B/services.html", ['contact.html']],
    ['Stripe',           'stripe.com',              'Next.js',   'Outils',     "$B/tarifs.html",   ['mentions-legales.html']],
    ['Mozilla',          'mozilla.org',             'Django',    'Références', "$B/contact.html",  ['ok.html']],
    ['Shopify',          'shopify.com',             'Shopify',   'Boutiques',  "$B/ok.html",       []],
    ['WordPress.org',    'wordpress.org',           'WordPress', 'Références', "$B/services.html", []],
    ['Le Monde',         'lemonde.fr',              'WordPress', 'Presse',     "$B/tarifs.html",   []],
    ['OVHcloud',         'ovhcloud.com',            'Drupal',    'Outils',     "$B/contact.html",  []],

    // --- Les quatre pannes : uniquement des préproductions fictives -------
    ['Recette Leboncoin',   'staging.leboncoin.fr',  'WordPress', 'Préprod',   "$B/css404.html",   ['ok.html']],
    ['Préprod BlaBlaCar',   'preprod.blablacar.fr',  'Laravel',   'Préprod',   "$B/dberror.php",   []],
    ['Bêta Deezer',         'beta.deezer.com',       'Next.js',   'Préprod',   "$B/slow.php",      []],
    ['Recette La Poste',    'recette.laposte.fr',    'Drupal',    'Préprod',   "$B/noindex.html",  []],

    // --- Une API interne --------------------------------------------------
    ['API interne · état',  'api.exemple-interne.fr', null,       'Interne',   "$B/health.php",    []],
];

$i = 0;
foreach ($sites as [$name, $domain, $cms, $group, $url, $children]) {
    $siteId = Db::insert('sites', ['name' => $name, 'domain' => $domain, 'cms' => $cms,
        'cms_detail' => jenc(['confidence' => 85, 'builder' => $cms === 'WordPress' ? 'Elementor' : null,
                              'theme' => $cms === 'WordPress' ? 'astra' : null, 'server' => 'LiteSpeed',
                              'cache' => $cms === 'WordPress' ? 'LiteSpeed Cache' : null]),
        'group_name' => $group, 'expect_string' => $name, 'created_at' => date('Y-m-d H:i:s', time() - 40 * 86400)]);
    $isApi = str_contains($url, 'health');
    $base = [
        'site_id' => $siteId, 'name' => $name, 'url' => $url, 'kind' => $isApi ? 'api' : 'page',
        'role' => 'primary', 'method' => 'GET', 'interval_sec' => 300, 'timeout_sec' => 15,
        'retries' => 1, 'slow_ms' => 6000, 'expect_status' => '200-299',
        'expect_string' => $isApi ? null : $name,
        'json_path' => $isApi ? 'status' : null, 'json_expect' => $isApi ? 'ok' : null,
        'check_ssl' => 0, 'check_css' => $isApi ? 0 : 1, 'check_db' => $cms !== 'Astro' ? 1 : 0,
        'check_noindex' => $isApi ? 0 : 1, 'ssl_warn_days' => 14, 'css_drop_pct' => 35,
        'enabled' => 1, 'status' => 'unknown', 'setup_state' => 'done',
        'setup_note' => trim(($cms ?? 'CMS inconnu') . ($cms === 'WordPress' ? ' + Elementor · thème astra' : '') . ' · LiteSpeed'),
        'created_at' => date('Y-m-d H:i:s', time() - 40 * 86400), 'next_check_at' => now(),
        'follow_redirects' => 1,
        'ssl_days_left' => [61, 38, 12, 89, 74, 5, 44, 26, 120][$i % 9],
        'ssl_expires_at' => date('Y-m-d H:i:s', time() + [61, 38, 12, 89, 74, 5, 44, 26, 120][$i % 9] * 86400),
        'ssl_issuer' => "Let's Encrypt R11",
        'ssl_checked_at' => now(),
        'domain_expires_at' => date('Y-m-d H:i:s', time() + [400, 210, 25, 600, 180, 90, 320, 45, 730][$i % 9] * 86400),
    ];
    Db::insert('monitors', $base);
    foreach ($children as $c) {
        Db::insert('monitors', array_merge($base, [
            'name' => ucfirst(str_replace(['-', '.html'], [' ', ''], $c)),
            'url' => "$B/$c", 'role' => 'secondary', 'kind' => 'page',
            'check_ssl' => 0, 'json_path' => null, 'json_expect' => null,
            'setup_note' => 'page interne (sitemap)',
            'next_check_at' => date('Y-m-d H:i:s', time() + random_int(1, 20)),
        ]));
    }
    $i++;
}

// --- Historique synthétique sur 30 jours ---------------------------------
$mons = Db::all('SELECT id, interval_sec, created_at FROM monitors');
$now  = time();
foreach ($mons as $m) {
    $mid = (int)$m['id'];
    $baseMs = random_int(180, 900);
    // Une mesure toutes les 30 minutes sur 30 jours
    for ($t = $now - 30 * 86400; $t < $now - 300; $t += 1800) {
        $hour = (int)date('G', $t);
        $ms = (int)max(60, $baseMs + random_int(-70, 90) + ($hour >= 9 && $hour <= 18 ? random_int(0, 160) : 0));
        $state = 'up'; $reason = null; $code = 200;
        // Quelques pannes réparties
        $r = ($mid * 7919 + (int)($t / 1800)) % 1500;
        if ($r < 4)        { $state = 'down';     $reason = ['HTTP_5XX', 'TIMEOUT', 'DB_DOWN', 'CSS_BROKEN'][$r % 4]; $code = 500; }
        elseif ($r < 16)   { $state = 'degraded'; $reason = 'SLOW'; $ms += random_int(2200, 4200); }
        Db::insert('checks', [
            'monitor_id' => $mid, 'ts' => date('Y-m-d H:i:s', $t), 'state' => $state,
            'reason_code' => $reason, 'status_code' => $code,
            'message' => $state === 'up' ? 'Tout va bien' : 'Anomalie simulée',
            'dns_ms' => random_int(4, 30), 'connect_ms' => random_int(12, 60),
            'tls_ms' => random_int(40, 120), 'ttfb_ms' => (int)($ms * 0.8),
            'total_ms' => $ms, 'size_bytes' => random_int(28000, 140000), 'redirects' => 0,
            'attempts' => $state === 'down' ? 2 : 1,
        ]);
        if ($state === 'down') {
            Db::insert('incidents', [
                'monitor_id' => $mid, 'severity' => 'down', 'reason_code' => $reason,
                'message' => 'Anomalie simulée', 'started_at' => date('Y-m-d H:i:s', $t),
                'ended_at' => date('Y-m-d H:i:s', $t + random_int(300, 3600)),
                'duration_sec' => random_int(300, 3600), 'checks_failed' => random_int(1, 5),
                'notify_count' => 1, 'last_notified_at' => date('Y-m-d H:i:s', $t),
            ]);
        }
    }
}
echo "historique : " . Db::val('SELECT COUNT(*) FROM checks') . " mesures, "
   . Db::val('SELECT COUNT(*) FROM incidents') . " incidents\n";

// Le faux site répond en série : seuil serré uniquement sur la sonde « lente ».
Db::q("UPDATE monitors SET slow_ms = 2000 WHERE name = 'Groupe Vallis'");

// --- État courant --------------------------------------------------------
// Si le faux site tourne, on lance de vraies vérifications ; sinon on pose un
// état représentatif pour que la démonstration reste lisible sans rien lancer.
$probe = @stream_socket_client("tcp://127.0.0.1:$port", $e1, $e2, 0.4);
if ($probe) {
    fclose($probe);
    $run = Runner::runDue(60, 120);
    printf("vérifications réelles : %d sondes (%d HS, %d dégradées, %d OK)\n",
        $run['ran'], $run['down'], $run['degraded'], $run['up']);
} else {
    echo "faux site non démarré : état simulé (les courbes et l'historique restent réels)\n";
    $states = [
        'Recette Leboncoin' => ['down', 'CSS_BROKEN',   'Mise en page cassée : feuille de style en échec : …/cache/min/1/absent.css → HTTP 404 [cache WP]'],
        'Préprod BlaBlaCar' => ['down', 'DB_DOWN',      'Laravel : erreur de requête — « SQLSTATE[HY000] [2002] Connection refused »'],
        'Bêta Deezer'       => ['degraded', 'SLOW',     'Temps de réponse élevé : 2,60 s'],
        'Recette La Poste'  => ['degraded', 'NOINDEX',  'Page en noindex : balise meta robots : noindex, nofollow'],
    ];
    // Rapport de ressources réaliste, pour que l'accordéon « Ressources de la
    // page » montre à quoi ressemble un vrai diagnostic.
    $cssBroken = jenc([
        'messages' => [
            'Feuille de style en échec : …/min/1/absent.css → HTTP 404 — le fichier n\'existe plus sur le '
                . 'serveur [cache WP (purge en cours ou fichier jamais régénéré)]',
            'Script essentiel en échec : …/js/frontend.min.js → HTTP 404 — le fichier n\'existe plus sur le serveur',
            'Poids CSS en chute de 71 % (38.4 Ko au lieu de 132.6 Ko attendus).',
            'Les classes de la page ne sont plus couvertes par le CSS : 31 % contre 96 % en référence '
                . '(ex. hero-title, card-grid, nav-main, btn-primary).',
        ],
        'console' => [
            ['level' => 'err', 'text' => 'GET https://camping-des-pins.fr/wp-content/cache/min/1/absent.css net::ERR_ABORTED 404 (Not Found)'],
            ['level' => 'err', 'text' => 'GET https://camping-des-pins.fr/wp-content/plugins/elementor/assets/js/frontend.min.js net::ERR_ABORTED 404 (Not Found)'],
            ['level' => 'err', 'text' => "Refused to apply style from 'https://camping-des-pins.fr/wp-content/cache/min/1/absent.css' because its MIME type ('text/html') is not a supported stylesheet MIME type"],
            ['level' => 'warn', 'text' => 'Empty response body for https://camping-des-pins.fr/wp-content/uploads/elementor/css/post-142.css'],
        ],
        'metrics' => [
            'sheets_declared' => 6, 'sheets_ok' => 4, 'sheets_failed' => 2,
            'js_declared' => 9, 'js_ok' => 8, 'js_failed' => 1,
            'fonts_checked' => 2, 'fonts_failed' => 0,
            'css_bytes' => 39321, 'rules' => 412, 'media_queries' => 3, 'layout_score' => 44,
            'coverage' => 0.31, 'classes_missing' => ['hero-title', 'card-grid', 'nav-main', 'btn-primary', 'price-box'],
            'inline_bytes' => 2140, 'hidden_nodes' => 4, 'hidden_risk' => true,
            'assets' => [
                ['url' => 'https://camping-des-pins.fr/wp-content/cache/min/1/absent.css', 'kind' => 'css',
                 'status' => 404, 'bytes' => 0, 'issue' => 'HTTP_404',
                 'note' => 'HTTP 404 — le fichier n\'existe plus sur le serveur', 'soft' => false],
                ['url' => 'https://camping-des-pins.fr/wp-content/uploads/elementor/css/post-142.css', 'kind' => 'css',
                 'status' => 200, 'bytes' => 0, 'issue' => 'EMPTY', 'note' => 'fichier vide (0 octet)', 'soft' => false],
                ['url' => 'https://camping-des-pins.fr/wp-content/plugins/elementor/assets/js/frontend.min.js',
                 'kind' => 'js', 'status' => 404, 'bytes' => 0, 'issue' => 'HTTP_404',
                 'note' => 'HTTP 404 — le fichier n\'existe plus sur le serveur', 'soft' => false],
                ['url' => 'https://camping-des-pins.fr/wp-content/themes/astra/assets/css/main.min.css',
                 'kind' => 'css', 'status' => 200, 'bytes' => 28714, 'issue' => null, 'note' => null, 'soft' => false],
                ['url' => 'https://camping-des-pins.fr/wp-includes/css/dist/block-library/style.min.css',
                 'kind' => 'css', 'status' => 200, 'bytes' => 9204, 'issue' => null, 'note' => null, 'soft' => false],
                ['url' => 'https://fonts.googleapis.com/css2?family=Inter:wght@400;600', 'kind' => 'css',
                 'status' => 200, 'bytes' => 1403, 'issue' => null, 'note' => null, 'soft' => true],
                ['url' => 'https://camping-des-pins.fr/wp-includes/js/jquery/jquery.min.js', 'kind' => 'js',
                 'status' => 200, 'bytes' => 30512, 'issue' => null, 'note' => null, 'soft' => false],
            ],
        ],
        'at' => now(),
    ]);
    $cssRef = jenc(['sheets_declared' => 6, 'sheets_ok' => 6, 'js_declared' => 9, 'js_ok' => 9,
                    'css_bytes' => 135782, 'rules' => 1487, 'media_queries' => 14, 'layout_score' => 88,
                    'coverage' => 0.96, 'inline_bytes' => 2140, 'fingerprint' => [], 'built_at' => now()]);

    foreach (Db::all('SELECT id, name FROM monitors') as $m) {
        [$st, $reason, $msg] = $states[$m['name']] ?? ['up', null, 'Tout va bien'];
        Db::update('monitors', [
            'status' => $st, 'reason_code' => $reason, 'last_message' => $msg,
            'status_since' => date('Y-m-d H:i:s', time() - random_int(600, 40000)),
            'last_check_at' => date('Y-m-d H:i:s', time() - random_int(10, 200)),
            'last_status_code' => $st === 'down' && $reason === 'HTTP_5XX' ? 500 : 200,
            'last_ms' => $st === 'degraded' && $reason === 'SLOW' ? random_int(2600, 3200) : random_int(180, 900),
            'css_state' => $reason === 'CSS_BROKEN' ? 'broken' : 'ok',
            'css_checked_at' => now(),
            'css_detail' => $reason === 'CSS_BROKEN' ? $cssBroken : null,
            'css_baseline' => $reason === 'CSS_BROKEN' ? $cssRef : null,
            'css_baseline_at' => $reason === 'CSS_BROKEN' ? date('Y-m-d H:i:s', time() - 9 * 86400) : null,
        ], 'id = :__i', ['__i' => (int)$m['id']]);
        if ($st === 'down') {
            Db::insert('incidents', ['monitor_id' => (int)$m['id'], 'severity' => 'down',
                'reason_code' => $reason, 'message' => $msg,
                'started_at' => date('Y-m-d H:i:s', time() - random_int(900, 7200)),
                'checks_failed' => random_int(2, 9), 'notify_count' => 1, 'last_notified_at' => now()]);
        }
    }
}
foreach (Db::all('SELECT id FROM monitors') as $m) Stats::refresh((int)$m['id']);
Stats::rollup(date('Y-m-d'));
for ($d = 1; $d <= 30; $d++) Stats::rollup(date('Y-m-d', time() - $d * 86400));
Db::setSetting('last_run_at', now());
echo "\nDémonstration prête. Mot de passe : demo1234\n";
echo "Pour la retirer : php bin/demo.php --purge\n";
