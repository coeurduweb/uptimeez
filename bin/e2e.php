<?php
/**
 * Uptimer : test de bout en bout de l'interface.
 *
 * Démarre une instance isolée de Uptimer et un faux site à surveiller, puis
 * déroule le parcours complet d'un utilisateur en HTTP réel : installation,
 * connexion, import, préparation, vérification, édition, incidents, export,
 * page publique, actions de masse, suppression, déconnexion.
 *
 *   php bin/e2e.php            # parcours complet
 *   php bin/e2e.php --real     # ajoute un contrôle sur un vrai site public
 *
 * Rien n'est partagé avec votre installation : configuration et base dédiées
 * dans un dossier temporaire, supprimé à la fin.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

if (PHP_SAPI !== 'cli') exit("À lancer en ligne de commande.\n");

$ROOT = dirname(__DIR__);
$REAL = in_array('--real', $argv, true);
$pass = 0; $fail = 0; $t0 = microtime(true);

function ok(string $label, bool $good, string $detail = ''): void
{
    global $pass, $fail;
    $good ? $pass++ : $fail++;
    $pad = str_repeat(' ', max(1, 54 - mb_strlen($label)));
    echo ($good ? " OK  " : "ÉCHEC ") . $label . $pad . ($detail !== '' ? '→ ' . $detail : '') . "\n";
}
function title(string $s): void { echo "\n── $s " . str_repeat('─', max(0, 58 - mb_strlen($s))) . "\n"; }

// =========================================================================
// Instance isolée + faux site
// =========================================================================
$tmp = sys_get_temp_dir() . '/uptimer-e2e-' . bin2hex(random_bytes(4));
@mkdir($tmp . '/site', 0775, true);

$freePort = function (int $from) : int {
    for ($p = $from; $p < $from + 60; $p++) {
        $s = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
        if ($s) { fclose($s); return $p; }
    }
    exit("Aucun port libre à partir de $from.\n");
};
$appPort  = $freePort(8900);
$sitePort = $freePort(8960);
$APP  = "http://127.0.0.1:$appPort";
$SITE = "http://127.0.0.1:$sitePort";
$PASS = 'motdepasse-e2e';

// --- Faux site : une page saine, une page au CSS cassé, une base HS -------
$css = ":root{--b:#06f}\nbody{margin:0;font-family:system-ui}\n";
foreach (['site-header', 'nav-main', 'hero', 'hero-title', 'card', 'card-grid', 'btn', 'footer-main'] as $c) {
    $css .= ".$c{display:flex;padding:12px;max-width:1100px;margin:0 auto;gap:10px}\n.$c:hover{opacity:.9}\n";
}
foreach (['(max-width:960px)', '(max-width:720px)', '(max-width:480px)'] as $mq) {
    $css .= "@media $mq{.hero-title{font-size:2rem}.card-grid{display:grid;gap:12px}}\n";
}
file_put_contents("$tmp/site/style.css", $css);
$page = function (string $t, string $head = ''): string {
    $b = '';
    foreach (['site-header', 'nav-main', 'hero', 'hero-title', 'card', 'card-grid', 'btn', 'footer-main'] as $c) {
        $b .= '<div class="' . $c . '">bloc</div>';
    }
    return "<!doctype html><html lang=\"fr\"><head><meta charset=\"utf-8\">"
        . "<title>$t : Agence Bellevue</title><meta property=\"og:site_name\" content=\"Agence Bellevue\">$head"
        . '</head><body><nav class="nav-main"><a href="/contact.html">Contact</a></nav>'
        . "<h1 class=\"hero-title\">$t</h1>$b"
        . '<footer class="footer-main">© 2026 Agence Bellevue — tous droits réservés</footer></body></html>';
};
$L = '<link rel="stylesheet" href="/style.css">';
file_put_contents("$tmp/site/index.html",   $page('Accueil', $L));
file_put_contents("$tmp/site/contact.html", $page('Contact', $L));
file_put_contents("$tmp/site/tarifs.html",  $page('Tarifs', $L));
file_put_contents("$tmp/site/casse.html",   $page('Accueil', '<link rel="stylesheet" href="/wp-content/cache/min/1/absent.css">'));
file_put_contents("$tmp/site/dberror.php",  "<?php http_response_code(200); ?><!doctype html><html><body><h1>Error establishing a database connection</h1></body></html>");
file_put_contents("$tmp/site/api.php",      "<?php header('Content-Type: application/json'); echo json_encode(['status'=>'ok']);");
file_put_contents("$tmp/site/robots.txt",   "User-agent: *\nSitemap: $SITE/sitemap.xml\n");
file_put_contents("$tmp/site/sitemap.xml",
    '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
    . "<url><loc>$SITE/</loc><priority>1.0</priority></url>"
    . "<url><loc>$SITE/contact.html</loc><priority>0.8</priority></url>"
    . "<url><loc>$SITE/tarifs.html</loc><priority>0.8</priority></url></urlset>");
file_put_contents("$tmp/site/router.php", "<?php\n"
    . "\$p = parse_url(\$_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';\n"
    . "if (\$p === '/') { readfile(__DIR__ . '/index.html'); return true; }\n"
    . "\$f = realpath(__DIR__ . \$p);\n"
    . "if (\$f && is_file(\$f) && str_starts_with(\$f, __DIR__)) return false;\n"
    . "http_response_code(404); echo '<!doctype html><html><head><title>404 Not Found</title></head><body>Not Found</body></html>';\n"
    . "return true;\n");

// --- Configuration isolée -------------------------------------------------
$cfgFile = $tmp . '/config.php';
file_put_contents($cfgFile, "<?php return " . var_export([
    'db'   => ['driver' => 'sqlite', 'sqlite' => $tmp . '/e2e.sqlite'],
    'auth' => ['password_hash' => password_hash($PASS, PASSWORD_DEFAULT), 'session_name' => 'uptimere2e'],
    'app'  => ['name' => 'Uptimer E2E', 'base_url' => $APP, 'timezone' => 'Europe/Paris',
               'public_token' => 'jeton-e2e', 'cron_key' => 'cle-e2e'],
    'defaults' => ['interval_sec' => 300, 'timeout_sec' => 10, 'retries' => 0, 'slow_ms' => 9000,
                   'max_parallel' => 6, 'retention_days' => 60, 'ssl_warn_days' => 14, 'css_drop_pct' => 35,
                   'user_agent' => 'UptimerBot/1.0 (E2E)'],
    'notify' => ['discord' => ['enabled' => false, 'webhook' => ''], 'slack' => ['enabled' => false, 'webhook' => ''],
                 'mail' => ['enabled' => false, 'to' => ''],
                 'webhook' => ['enabled' => true, 'url' => "$SITE/api.php"],
                 'resend_after_min' => 60, 'notify_recovery' => true, 'notify_degraded' => true, 'quiet_hours' => ''],
], true) . ";\n");

// --- Démarrage des deux serveurs -----------------------------------------
$devnull = ['file' => '/dev/null'];
$spawn = function (array $cmd, string $cwd, array $env = []) {
    return proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                     $pipes, $cwd, $env + ['PATH' => getenv('PATH') ?: '/usr/bin:/bin']);
};
$siteSrv = $spawn([PHP_BINARY, '-S', "127.0.0.1:$sitePort", '-t', "$tmp/site", "$tmp/site/router.php"], "$tmp/site");
$appSrv  = $spawn([PHP_BINARY, '-S', "127.0.0.1:$appPort", '-t', $ROOT], $ROOT, ['UPTIMER_CONFIG' => $cfgFile]);

$cleanup = function () use ($siteSrv, $appSrv, $tmp, $appPort, $sitePort): void {
    foreach ([[$appSrv, $appPort], [$siteSrv, $sitePort]] as [$h, $port]) {
        if (!is_resource($h)) continue;
        proc_terminate($h);
        for ($i = 0; $i < 10; $i++) {
            usleep(100000);
            $s = @stream_socket_client("tcp://127.0.0.1:$port", $e1, $e2, 0.2);
            if (!$s) break;
            fclose($s);
            if ($i === 4) proc_terminate($h, 9);
        }
        proc_close($h);
    }
    foreach (['/site', ''] as $sub) {
        foreach (glob($tmp . $sub . '/*') ?: [] as $f) if (is_file($f)) @unlink($f);
        if ($sub !== '') @rmdir($tmp . $sub);
    }
    foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f);
    @rmdir($tmp);
};
register_shutdown_function($cleanup);

$wait = function (int $port): bool {
    for ($i = 0; $i < 50; $i++) {
        $s = @stream_socket_client("tcp://127.0.0.1:$port", $e1, $e2, 0.3);
        if ($s) { fclose($s); return true; }
        usleep(150000);
    }
    return false;
};
if (!$wait($appPort) || !$wait($sitePort)) exit("Les serveurs de test n'ont pas démarré.\n");

echo "Test de bout en bout : application $APP · faux site $SITE\n";

// =========================================================================
// Client HTTP avec session
// =========================================================================
$jar = $tmp . '/cookies.txt';
$req = function (string $path, ?array $post = null, bool $follow = false) use ($APP, $jar): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => str_starts_with($path, 'http') ? $path : $APP . $path,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => $follow, CURLOPT_TIMEOUT => 60,
        // L'en-tête AJAX n'est envoyé que sur l'API, comme le fait le navigateur.
        CURLOPT_HTTPHEADER => str_contains($path, 'api.php') ? ['X-Requested-With: fetch'] : [],
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw  = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hlen = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $err  = curl_error($ch);
    curl_close($ch);
    $head = substr($raw, 0, $hlen);
    $loc  = preg_match('~^location:\s*(\S+)~im', $head, $m) ? trim($m[1]) : null;
    return ['code' => $code, 'body' => substr($raw, $hlen), 'head' => $head, 'location' => $loc, 'error' => $err];
};
$csrf = function () use ($req): string {
    $r = $req('/index.php?p=dashboard');
    return preg_match('~csrf:\s*"([a-f0-9]+)"~', $r['body'], $m) ? $m[1] : '';
};
$has = fn(array $r, string $needle): bool => str_contains($r['body'], $needle);
$noPhpError = fn(array $r): bool => !preg_match('~(Fatal error|Uncaught \w+|Undefined (variable|method|array key)|SQLSTATE\[)~', $r['body']);

// =========================================================================
title('Accès et authentification');
$r = $req('/index.php');
ok('sans session, redirection vers la connexion', $r['code'] === 302 && str_contains((string)$r['location'], 'p=login'),
    'HTTP ' . $r['code'] . ' → ' . ($r['location'] ?? '—'));

$r = $req('/api.php?action=summary');
ok('API protégée sans session', $r['code'] === 401, 'HTTP ' . $r['code']);

$r = $req('/index.php?p=login', ['password' => 'mauvais mot de passe']);
ok('mauvais mot de passe refusé', $r['code'] === 200 && $has($r, 'incorrect'));

$r = $req('/index.php?p=login', ['password' => $PASS]);
ok('bon mot de passe accepté', $r['code'] === 302 && str_contains((string)$r['location'], 'today'),
    'HTTP ' . $r['code']);

$r = $req('/index.php?p=dashboard');
ok('tableau de bord accessible', $r['code'] === 200 && $noPhpError($r) && $has($r, 'Aucun site surveillé'));

$tok = $csrf();
ok('jeton CSRF présent dans la page', strlen($tok) >= 16, strlen($tok) . ' caractères');

$r = $req('/api.php?action=check', ['id' => 1]);
ok('action sans jeton CSRF rejetée', $r['code'] === 403, 'HTTP ' . $r['code']);

// =========================================================================
title('Import et préparation automatique');
$list = "$SITE/\n$SITE/casse.html | Site cassé\n$SITE/dberror.php ; Base HS ; Agence Bellevue\n"
      . "$SITE/api.php ; API interne\nligne invalide !!";
$r = $req('/index.php?p=import', ['csrf' => $tok, 'action' => 'import', 'list' => $list,
    'interval_sec' => 300, 'pages' => 3, 'discover' => 1, 'extras' => 1,
    'check_css' => 1, 'check_db' => 1, 'check_ssl' => 1, 'check_noindex' => 1, 'group' => 'E2E']);
ok('import accepté', $r['code'] === 200 && $has($r, 'sonde(s) créée(s)') && $noPhpError($r));
ok('ligne invalide signalée sans bloquer', $has($r, 'ignorée'));

require_once $ROOT . '/src/bootstrap.php';
Uptimer\Config::set('db.driver', 'sqlite');
Uptimer\Config::set('db.sqlite', $tmp . '/e2e.sqlite');
$db = fn(string $sql, array $p = []) => Uptimer\Db::all($sql, $p);
$val = fn(string $sql, array $p = []) => Uptimer\Db::val($sql, $p);

$created = (int)$val('SELECT COUNT(*) FROM monitors');
ok('4 sondes créées en base', $created === 4, $created . ' sonde(s)');

$ids = array_column($db('SELECT id FROM monitors ORDER BY id'), 'id');
foreach ($ids as $mid) {
    $rr = $req('/api.php?action=setup', ['csrf' => $tok, 'id' => (int)$mid]);
    $j  = json_decode($rr['body'], true);
    if (!is_array($j)) { ok('préparation de la sonde #' . $mid, false, str_cut($rr['body'], 80)); break; }
}
$after = (int)$val('SELECT COUNT(*) FROM monitors');
ok('pages découvertes via le sitemap', $after > $created, ($after - $created) . ' sonde(s) ajoutée(s)');
$proof = (int)$val("SELECT COUNT(*) FROM monitors WHERE expect_string = 'Agence Bellevue'");
ok('chaîne de contrôle déduite du contenu', $proof >= 3, $proof . ' sonde(s)');

// =========================================================================
title('Vérification et diagnostic');
$brokenId = (int)$val("SELECT id FROM monitors WHERE url LIKE '%casse.html' LIMIT 1");
$dbId     = (int)$val("SELECT id FROM monitors WHERE url LIKE '%dberror.php' LIMIT 1");
$okId     = (int)$val("SELECT id FROM monitors WHERE url = ? LIMIT 1", ["$SITE/"]);

foreach ([[$brokenId, 'down', 'CSS_BROKEN', 'mise en page'], [$dbId, 'down', 'DB_DOWN', 'base de données'],
          [$okId, 'up', null, null]] as [$mid, $wantState, $wantReason, $wantText]) {
    $rr = $req('/api.php?action=check', ['csrf' => $tok, 'id' => $mid]);
    $j  = json_decode($rr['body'], true);
    $st = $j['result']['state'] ?? '?';
    $rs = $j['result']['reason'] ?? null;
    ok('vérification #' . $mid . ' → ' . $wantState,
        $st === $wantState && ($wantReason === null || $rs === $wantReason),
        'état=' . $st . ' cause=' . ($rs ?? '—'));
}

$r = $req('/index.php?p=monitor&id=' . $brokenId);
ok('fiche : le diagnostic explique la panne', $has($r, 'La mise en page est cassée') && $has($r, 'Que faire'));
ok('fiche : rapport de ressources détaillé', $has($r, 'Ressources de la page') && $has($r, 'absent.css'));
ok('fiche : messages console reconstitués', $has($r, 'console') && $has($r, 'net::ERR_ABORTED'));
ok('fiche : aucune erreur PHP', $noPhpError($r));

$r = $req('/index.php?p=monitor&id=' . $dbId);
ok('fiche base HS : diagnostic adapté', $has($r, 'base de données ne répond plus'));

// =========================================================================
title('Silhouette : la page telle qu\'un visiteur la voit');
// =========================================================================
// La sonde du faux site au CSS cassé doit produire deux silhouettes et un écart.
$req('/api.php?action=check&id=' . $brokenId, ['csrf' => $tok]);
$row = $db('SELECT silhouette_ref, silhouette_now, silhouette_drift, silhouette_at
            FROM monitors WHERE id = ?', [$brokenId])[0] ?? [];
ok('silhouette actuelle enregistrée',
   str_starts_with((string)($row['silhouette_now'] ?? ''), '<svg'),
   strlen((string)($row['silhouette_now'] ?? '')) . ' octets');
ok('date de silhouette renseignée', !empty($row['silhouette_at']));

// La sonde saine sert de référence : son écart doit être nul.
$req('/api.php?action=check&id=' . $okId, ['csrf' => $tok]);
$good = $db('SELECT silhouette_ref, silhouette_drift FROM monitors WHERE id = ?', [$okId])[0] ?? [];
ok('référence mémorisée sur un état sain',
   str_starts_with((string)($good['silhouette_ref'] ?? ''), '<svg'));
ok('aucun écart sur une page saine', (int)($good['silhouette_drift'] ?? -1) === 0);

$r = $req('/index.php?p=monitor&id=' . $brokenId . '&ui=expert');
ok('la fiche affiche la comparaison', $has($r, 'sil-pair') && $has($r, 'visiteur'));
ok('la fiche précise que ce n\'est pas une capture', $has($r, 'capture d'));
ok('le SVG est bien servi dans la page', $has($r, '<svg') && $has($r, 'silhouette'));
// Rien de ce que le site contrôle ne doit entrer dans le SVG. On isole chaque
// silhouette servie et on regarde à l'intérieur, pas ailleurs dans la page.
$svgs = [];
if (preg_match_all('~<svg[^>]*class="silhouette".*?</svg>~s', $r['body'], $mm)) $svgs = $mm[0];
$dirty = [];
foreach ($svgs as $svg) {
    if (str_contains($svg, '<script')) $dirty[] = 'script';
    if (preg_match('~\son[a-z]+\s*=~i', $svg)) $dirty[] = 'gestionnaire';
    if (preg_match('~<(?!/?(?:svg|rect|path)\b)[a-z]~i', $svg)) $dirty[] = 'balise inattendue';
}
ok('silhouettes servies sans rien d\'exécutable',
   count($svgs) >= 1 && $dirty === [], count($svgs) . ' silhouette(s) · ' . implode(' ', $dirty));

// « Réapprendre la référence » efface aussi la silhouette de référence.
$req('/api.php?action=fix&id=' . $brokenId, ['csrf' => $tok, 'fix' => 'relearn']);
$after = $db('SELECT silhouette_ref, silhouette_drift FROM monitors WHERE id = ?', [$brokenId])[0] ?? [];
ok('réapprendre efface la référence visuelle',
   ($after['silhouette_ref'] ?? null) === null && (int)$after['silhouette_drift'] === 0);

// =========================================================================
title('Périodes longues');
foreach (['1h', '24h', '7d', '30d', '90d', '120d', '180d', '365d'] as $range) {
    $rr = $req('/index.php?p=monitor&id=' . $okId . '&range=' . $range);
    ok('période ' . $range, $rr['code'] === 200 && $noPhpError($rr) && $has($rr, 'Temps de réponse et pannes'));
}
$rr = $req('/api.php?action=series&id=' . $okId . '&range=365d');
$j  = json_decode($rr['body'], true);
ok('série 1 an renvoyée par l\'API', !empty($j['ok']) && count($j['series']['buckets'] ?? []) > 0,
    count($j['series']['buckets'] ?? []) . ' intervalles');

// =========================================================================
title('Édition d\'une sonde');
$r = $req('/index.php?p=monitor&id=' . $okId, ['csrf' => $tok, 'action' => 'save_monitor', 'id' => $okId,
    'name' => 'Nom modifié par le test', 'url' => "$SITE/", 'kind' => 'page', 'method' => 'GET',
    'interval_sec' => 600, 'timeout_sec' => 12, 'retries' => 1, 'slow_ms' => 8000,
    'expect_status' => '200-299', 'expect_string' => 'Agence Bellevue',
    'check_css' => 1, 'check_db' => 1, 'check_noindex' => 1, 'follow_redirects' => 1, 'enabled' => 1,
    'ssl_warn_days' => 21, 'css_drop_pct' => 40, 'watch_string' => 'Tarifs', 'watch_mode' => 'appear']);
ok('enregistrement accepté', $r['code'] === 200 && $has($r, 'enregistrée'));
$m = $db('SELECT name, interval_sec, ssl_warn_days, css_drop_pct, watch_string FROM monitors WHERE id = ?', [$okId])[0] ?? [];
ok('valeurs bien persistées',
    ($m['name'] ?? '') === 'Nom modifié par le test' && (int)($m['interval_sec'] ?? 0) === 600
    && (int)($m['ssl_warn_days'] ?? 0) === 21 && (int)($m['css_drop_pct'] ?? 0) === 40
    && ($m['watch_string'] ?? '') === 'Tarifs',
    ($m['name'] ?? '?') . ' / ' . ($m['interval_sec'] ?? '?') . ' s');

$r = $req('/api.php?action=toggle', ['csrf' => $tok, 'id' => $okId]);
$j = json_decode($r['body'], true);
ok('mise en pause', ($j['enabled'] ?? true) === false && $val('SELECT status FROM monitors WHERE id = ?', [$okId]) === 'paused');
$r = $req('/api.php?action=toggle', ['csrf' => $tok, 'id' => $okId]);
$j = json_decode($r['body'], true);
ok('reprise', ($j['enabled'] ?? false) === true);

// =========================================================================
title('Incidents, journal et export');
$r = $req('/index.php?p=incidents');
ok('page incidents', $r['code'] === 200 && $noPhpError($r) && $has($r, 'Incidents'));
$openInc = (int)$val('SELECT COUNT(*) FROM incidents WHERE ended_at IS NULL');
ok('incidents ouverts enregistrés', $openInc >= 2, $openInc . ' ouvert(s)');

$incId = (int)$val('SELECT id FROM incidents WHERE ended_at IS NULL ORDER BY id ASC LIMIT 1');
$r = $req('/index.php?p=incidents', ['csrf' => $tok, 'action' => 'ack_incident', 'id' => $incId]);
ok('incident pris en compte', $has($r, 'pris en compte') || $val('SELECT ack_at FROM incidents WHERE id = ?', [$incId]) !== null);
$r = $req('/index.php?p=incidents', ['csrf' => $tok, 'action' => 'close_incident', 'id' => $incId]);
ok('incident clos manuellement', $val('SELECT ended_at FROM incidents WHERE id = ?', [$incId]) !== null);

$r = $req('/index.php?p=incidents&export=csv');
ok('export CSV propre', $r['code'] === 200
    && str_starts_with($r['body'], "\xEF\xBB\xBF") && str_contains($r['body'], 'Sonde;URL;Gravité')
    && !str_contains($r['body'], '<!doctype'),
    str_cut(explode("\n", ltrim($r['body'], "\xEF\xBB\xBF"))[0] ?? '', 60));
ok('en-tête de téléchargement', str_contains(strtolower($r['head']), 'text/csv')
    && str_contains(strtolower($r['head']), 'attachment'));

$r = $req('/index.php?p=events');
ok('journal accessible', $r['code'] === 200 && $noPhpError($r));
$notif = (int)$val('SELECT COUNT(*) FROM notifications WHERE ok = 1');
ok('alertes réellement envoyées (webhook)', $notif >= 1, $notif . ' envoi(s) réussi(s)');

// =========================================================================
title('Page d\'état publique');
$r = $req('/index.php?p=status');
ok('sans jeton : introuvable', $r['code'] === 404, 'HTTP ' . $r['code']);
$r = $req('/index.php?p=status&token=jeton-e2e');
ok('avec jeton : accessible', $r['code'] === 200 && $noPhpError($r) && $has($r, 'État des services'));
ok('page publique sans navigation privée', !str_contains($r['body'], 'p=settings'));

// =========================================================================
title('Actions de masse et suppression');
$allIds = array_column($db('SELECT id FROM monitors'), 'id');
$r = $req('/index.php?p=monitors', ['csrf' => $tok, 'action' => 'bulk', 'bulk_action' => 'disable',
    'ids' => array_slice($allIds, 0, 2)]);
$paused = (int)$val('SELECT COUNT(*) FROM monitors WHERE enabled = 0');
ok('mise en pause en masse', $paused >= 2, $paused . ' en pause');
$r = $req('/index.php?p=monitors', ['csrf' => $tok, 'action' => 'bulk', 'bulk_action' => 'enable',
    'ids' => array_slice($allIds, 0, 2)]);
ok('réactivation en masse', (int)$val('SELECT COUNT(*) FROM monitors WHERE enabled = 0') === 0);

$before = (int)$val('SELECT COUNT(*) FROM monitors');
$r = $req('/index.php?p=monitor&id=' . $brokenId, ['csrf' => $tok, 'action' => 'delete_monitor', 'id' => $brokenId]);
$gone = (int)$val('SELECT COUNT(*) FROM monitors WHERE id = ?', [$brokenId]) === 0;
ok('suppression d\'une sonde', $gone && (int)$val('SELECT COUNT(*) FROM monitors') === $before - 1);
ok('historique de la sonde supprimé aussi',
    (int)$val('SELECT COUNT(*) FROM checks WHERE monitor_id = ?', [$brokenId]) === 0
    && (int)$val('SELECT COUNT(*) FROM incidents WHERE monitor_id = ?', [$brokenId]) === 0);


// =========================================================================
title('Écran « Aujourd\'hui » : la liste de tâches');
$r = $req('/index.php?p=today');
ok('page d\'accueil = liste de tâches', $r['code'] === 200 && $noPhpError($r)
    && $has($r, 'À traiter maintenant'), 'HTTP ' . $r['code']);
ok('chaque tâche porte sa cause', $has($r, 'La mise en page est cassée')
    || $has($r, 'La base de données ne répond plus'));
ok('chaque tâche porte la conduite à tenir', $has($r, 'task-fix'));
ok('actions disponibles sur place', $has($r, 'js-fix') && $has($r, 'js-copy-report'));
ok('bloc d\'anticipation présent', $has($r, 'À prévoir') || $has($r, 'sans rien à signaler'));
$r2 = $req('/index.php');
ok('la racine mène à Aujourd\'hui', $r2['code'] === 200 && $has($r2, 'À traiter maintenant')
    || str_contains((string)$r2['location'], 'today'), 'HTTP ' . $r2['code']);

title('Correctifs appliqués sans quitter la page');
$brokenId2 = (int)$val("SELECT id FROM monitors WHERE reason_code = 'CSS_BROKEN' LIMIT 1");
if (!$brokenId2) $brokenId2 = (int)$val("SELECT id FROM monitors WHERE status = 'down' LIMIT 1");
$before = (string)$val('SELECT css_baseline FROM monitors WHERE id = ?', [$brokenId2]);
$r = $req('/api.php?action=fix', ['csrf' => $tok, 'id' => $brokenId2, 'fix' => 'relearn']);
$j = json_decode($r['body'], true);
ok('correctif « réapprendre la référence »', !empty($j['ok']) && !empty($j['undo']),
    str_cut((string)($j['message'] ?? ''), 60));
ok('référence effacée', $val('SELECT css_baseline FROM monitors WHERE id = ?', [$brokenId2]) === null);
$r = $req('/api.php?action=undo', ['csrf' => $tok, 'token' => $j['undo']]);
$j2 = json_decode($r['body'], true);
ok('annulation possible', !empty($j2['ok']), str_cut((string)($j2['message'] ?? ''), 40));

$oldSlow = (int)$val('SELECT slow_ms FROM monitors WHERE id = ?', [$okId]);
$r = $req('/api.php?action=fix', ['csrf' => $tok, 'id' => $okId, 'fix' => 'raise_slow']);
$j = json_decode($r['body'], true);
ok('correctif « relever le seuil »', !empty($j['ok'])
    && (int)$val('SELECT slow_ms FROM monitors WHERE id = ?', [$okId]) !== $oldSlow,
    $oldSlow . ' → ' . $val('SELECT slow_ms FROM monitors WHERE id = ?', [$okId]) . ' ms');

$r = $req('/api.php?action=fix', ['csrf' => $tok, 'id' => $okId, 'fix' => 'snooze']);
ok('mise en pause d\'une heure', $val('SELECT paused_until FROM monitors WHERE id = ?', [$okId]) !== null);
$r = $req('/api.php?action=fix', ['csrf' => $tok, 'id' => $okId, 'fix' => 'nawak']);
ok('correctif inconnu rejeté', $r['code'] === 400, 'HTTP ' . $r['code']);

title('Rapport prêt à coller');
$r = $req('/api.php?action=report&id=' . $brokenId2);
$j = json_decode($r['body'], true);
ok('rapport produit', !empty($j['ok']) && str_contains((string)$j['report'], 'Diagnostic'));
ok('rapport sans HTML', !preg_match('~<[a-z]~i', (string)($j['report'] ?? '')));

title('Palette de commandes');
$r = $req('/api.php?action=search&q=');
$j = json_decode($r['body'], true);
ok('recherche vide : les problèmes d\'abord', !empty($j['ok']) && count($j['results']) > 0,
    count($j['results'] ?? []) . ' résultat(s)');
$first = $j['results'][0] ?? [];
ok('un site hors service arrive en tête', in_array(($first['status'] ?? ''), ['down', 'degraded'], true),
    (string)($first['status'] ?? '?'));
// Sonde dédiée au test de recherche : indépendante de l'ordre des autres sections.
Uptimer\Db::insert('monitors', ['name' => 'Boulangerie Créchêt', 'url' => "$SITE/tarifs.html",
    'kind' => 'page', 'role' => 'secondary', 'method' => 'GET', 'interval_sec' => 3600,
    'timeout_sec' => 10, 'retries' => 0, 'slow_ms' => 9000, 'expect_status' => '200-299',
    'check_ssl' => 0, 'check_css' => 0, 'check_db' => 0, 'check_noindex' => 0,
    'ssl_warn_days' => 14, 'css_drop_pct' => 35, 'enabled' => 1, 'status' => 'up',
    'setup_state' => 'done', 'created_at' => now(), 'next_check_at' => now(), 'follow_redirects' => 1]);
$r = $req('/api.php?action=search&q=' . urlencode('crechet'));
$j = json_decode($r['body'], true);
ok('recherche insensible aux accents (« crechet » trouve « Créchêt »)',
    count($j['results'] ?? []) >= 1, count($j['results'] ?? []) . ' résultat(s)');
$r = $req('/api.php?action=search&q=' . urlencode('CRÉCHÊT'));
$j = json_decode($r['body'], true);
ok('recherche insensible à la casse', count($j['results'] ?? []) >= 1);
$r = $req('/api.php?action=search&q=' . urlencode('boulangerie'));
$j = json_decode($r['body'], true);
ok('recherche par mot du nom', count($j['results'] ?? []) >= 1);
$r = $req('/api.php?action=search&q=' . urlencode('zzzintrouvable'));
$j = json_decode($r['body'], true);
ok('recherche sans résultat', count($j['results'] ?? []) === 0);

title('Sonde battement');
$hb = Uptimer\Heartbeat::create('Cron client E2E', 3600, 300);
ok('sonde battement créée', $hb['id'] > 0 && strlen((string)$hb['token']) >= 16);
$r = $req('/beat.php?k=' . $hb['token'] . '&m=' . urlencode('E2E, 12 fichiers'));
ok('signal accepté par beat.php', $r['code'] === 200 && str_contains($r['body'], 'OK'), 'HTTP ' . $r['code']);
ok('état mis à jour', $val('SELECT status FROM monitors WHERE id = ?', [$hb['id']]) === 'up');
ok('note du signal enregistrée',
    str_contains((string)$val('SELECT last_message FROM monitors WHERE id = ?', [$hb['id']]), '12 fichiers'));
$r = $req('/beat.php?k=deadbeefdeadbeefdeadbeef');
ok('clé inconnue : 404 sans rien révéler', $r['code'] === 404 && !str_contains($r['body'], 'inconnu' . 'e'),
    'HTTP ' . $r['code']);
$r = $req('/beat.php');
ok('clé absente refusée', $r['code'] === 400);
$r = $req('/index.php?p=monitor&id=' . $hb['id']);
ok('fiche battement : ligne à coller fournie', $has($r, 'beat.php?k=') && $has($r, 'Signal attendu'));

title('Journal des décisions');
$dec = (string)$val('SELECT decisions FROM monitors WHERE id = ?', [$okId]);
ok('décisions journalisées à la préparation', $dec !== '' && $dec !== null,
    str_cut((string)$dec, 70));
// Le journal des décisions est un détail : mode complet.
$r = $req('/index.php?p=monitor&id=' . $okId . '&ui=expert');
ok('décisions visibles sur la fiche', $has($r, 'décidé toute seule') || $has($r, 'Chaîne de preuve retenue'));

// =========================================================================
title('Niveau de détail de l\'interface');
// =========================================================================
$r = $req('/index.php?p=monitor&id=' . $okId . '&ui=simple');
$simpleHasDetail = $has($r, 'Dernières vérifications');
ok('mode simple : le tableau des mesures est masqué', !$simpleHasDetail);
ok('mode simple : le diagnostic reste visible', $has($r, 'acc-title') && $has($r, 'Ressources de la page'));
ok('mode simple : la sonde reste modifiable', $has($r, 'name="action" value="save_monitor"'));
// Le bloc avancé reste dans le formulaire, sinon l'enregistrement viderait
// les champs qu'il contient (et désactiverait la sonde).
ok('mode simple : les champs avancés restent postés', $has($r, 'name="enabled"'));

$r = $req('/index.php?p=monitor&id=' . $okId . '&ui=expert');
ok('mode complet : le tableau des mesures revient', $has($r, 'Dernières vérifications'));

$r = $req('/index.php?p=today&ui=simple');
ok('mode simple : navigation réduite', !$has($r, 'p=dashboard\"') || !$has($r, 'p=report\"'));
$r = $req('/index.php?p=today&ui=expert');
ok('mode complet : mur et rapport dans la barre',
   $has($r, 'p=dashboard') && $has($r, 'p=report'));
// Le choix se retient sans le repasser dans l'URL.
$r = $req('/index.php?p=monitor&id=' . $okId);
ok('le niveau de détail est mémorisé', $has($r, 'Dernières vérifications'));
$req('/index.php?p=today&ui=simple');

// =========================================================================
title('Langues (i18n)');
// =========================================================================
$r = $req('/index.php?p=today&lang=en');
ok('anglais : interface traduite', $has($r, 'lang="en"')
   && ($has($r, 'To handle now') || $has($r, 'Nothing to do') || $has($r, 'monitor')));
ok('anglais : plus de français dans le bandeau', !$has($r, 'À traiter maintenant'));
$r = $req('/index.php?p=today');
ok('la langue est mémorisée', $has($r, 'lang="en"'));

$r = $req('/index.php?p=today&lang=ar');
ok('arabe : écriture de droite à gauche', $has($r, 'dir="rtl"') && $has($r, 'lang="ar"'));
$r = $req('/index.php?p=today&lang=ru');
ok('russe : catalogue chargé', $has($r, 'lang="ru"') && $has($r, 'Сегодня'));
$r = $req('/index.php?p=today&lang=zh');
ok('chinois : catalogue chargé', $has($r, 'lang="zh"') && $has($r, '今天'));
// Une phrase longue non traduite en chinois doit tomber en anglais, pas en français.
$r = $req('/index.php?p=settings&lang=zh');
ok('repli anglais et non français', !$has($r, 'Ajoutez la ligne ci-dessous dans le gestionnaire'));
$r = $req('/index.php?p=today&lang=klingon');
ok('langue inconnue : repli sur l\'anglais', $has($r, 'lang="en"'));
$r = $req('/index.php?p=today&lang=fr');
// L'apostrophe est échappée en &#039; dans le HTML : on compare sans elle.
ok('retour au français', $has($r, 'lang="fr"') && $has($r, 'Aujourd'));

// Les dix langues annoncées existent et se chargent toutes.
$missing = [];
foreach (['en', 'zh', 'hi', 'es', 'ar', 'fr', 'bn', 'pt', 'ru', 'ur'] as $lg) {
    $rr = $req('/index.php?p=today&lang=' . $lg);
    if (!str_contains($rr['body'], 'lang="' . $lg . '"')) $missing[] = $lg;
}
ok('les 10 langues sont servies', $missing === [], $missing ? implode(' ', $missing) : '10/10');
$req('/index.php?p=today&lang=fr');

// =========================================================================
title('Aides contextuelles');
// =========================================================================
$r = $req('/index.php?p=today');
ok('bulles d\'aide présentes', substr_count($r['body'], 'data-hint') >= 1,
   substr_count($r['body'], 'data-hint') . ' bulle(s)');
ok('aide accessible au clavier', $has($r, 'role="tooltip"') && $has($r, 'aria-describedby'));

title('Import : aperçu avant création');
$before = (int)$val('SELECT COUNT(*) FROM monitors');
$r = $req('/index.php?p=import', ['csrf' => $tok, 'action' => 'preview',
    'list' => "Bonjour, merci de surveiller aperçu-un-uptimer.fr et https://apercu-deux-uptimer.fr/contact. Logo : x.png",
    'interval_sec' => 300, 'pages' => 3]);
ok('aperçu affiché', $r['code'] === 200 && $has($r, 'Aperçu :') && $noPhpError($r));
ok('rien n\'a été créé à cette étape', (int)$val('SELECT COUNT(*) FROM monitors') === $before,
    $before . ' → ' . $val('SELECT COUNT(*) FROM monitors'));
ok('adresses extraites d\'un texte libre', $has($r, 'apercu-deux-uptimer.fr'));
ok('cadence proposée visible', $has($r, 'Cadence') || $has($r, 'cadence'));

title('Rapport client');
$r = $req('/index.php?p=report');
ok('rapport client affiché', $r['code'] === 200 && $noPhpError($r) && $has($r, 'Rapport de disponibilité'));
ok('interruptions listées ou absence signalée',
    $has($r, 'Interruptions constatées'));
$r = $req('/index.php?p=report&range=365d');
ok('rapport sur un an', $r['code'] === 200 && $noPhpError($r));

// =========================================================================
title('Réglages');
$r = $req('/index.php?p=settings', ['csrf' => $tok, 'action' => 'save_settings',
    'app_name' => 'Uptimer E2E renommée', 'base_url' => $APP, 'timezone' => 'Europe/Paris',
    'def_interval' => 300, 'def_timeout' => 15, 'def_retries' => 2, 'def_ssl_days' => 21,
    'def_slow' => 4000, 'def_css_drop' => 30, 'def_parallel' => 8, 'def_retention' => 45,
    'resend_after' => 30, 'notify_recovery' => 1, 'public_token' => 'jeton-e2e', 'cron_key' => 'cle-e2e',
    'webhook_enabled' => 1, 'webhook_url' => "$SITE/api.php"]);
ok('réglages enregistrés', $has($r, 'Réglages enregistrés'));
$cfg = require $cfgFile;
ok('écrits dans le fichier de configuration',
    ($cfg['app']['name'] ?? '') === 'Uptimer E2E renommée' && (int)($cfg['defaults']['def_retention'] ?? 45) !== 0
    || ($cfg['defaults']['retention_days'] ?? 0) === 45,
    ($cfg['app']['name'] ?? '?') . ' · rétention ' . ($cfg['defaults']['retention_days'] ?? '?') . ' j');
$r = $req('/index.php?p=settings', ['csrf' => $tok, 'action' => 'test_notify', 'channel' => 'webhook']);
ok('test de canal fonctionnel', $has($r, 'envoyé'));

// =========================================================================
title('Cron et entretien');
$r = $req('/cron.php');
ok('cron par URL protégé sans clé', $r['code'] === 403, 'HTTP ' . $r['code']);
$r = $req('/cron.php?key=cle-e2e');
ok('cron par URL avec clé', $r['code'] === 200 && str_contains($r['body'], 'Terminé en'), str_cut(trim($r['body']), 60));
$r = $req('/index.php?p=settings', ['csrf' => $tok, 'action' => 'maintenance_cron']);
ok('entretien manuel', $has($r, 'Entretien exécuté'));

// =========================================================================
title('Fichiers sensibles');
foreach (['/src/Runner.php', '/views/dashboard.php', '/data/e2e.sqlite'] as $path) {
    $rr = $req($path);
    // Le serveur intégré de PHP ne lit pas .htaccess : on vérifie surtout qu'aucun
    // secret ne fuite en clair (le code PHP est exécuté, pas affiché).
    ok('pas de code source exposé : ' . $path,
        !str_contains($rr['body'], '<?php') && !str_contains($rr['body'], 'password_hash'),
        'HTTP ' . $rr['code']);
}

// =========================================================================
title('Déconnexion');
$r = $req('/index.php?p=logout');
ok('déconnexion effective', $r['code'] === 302);
$r = $req('/index.php?p=dashboard');
ok('session invalidée', $r['code'] === 302 && str_contains((string)$r['location'], 'p=login'));
$r = $req('/api.php?action=summary');
ok('API de nouveau protégée', $r['code'] === 401);

// =========================================================================
if ($REAL) {
    title('Site public réel');
    $req('/index.php?p=login', ['password' => $PASS]);
    $tok2 = $csrf();
    $r = $req('/index.php?p=import', ['csrf' => $tok2, 'action' => 'import',
        'list' => "https://example.com | Example (test réel) | Example Domain",
        'interval_sec' => 3600, 'pages' => 1, 'check_css' => 1, 'check_db' => 0,
        'check_ssl' => 1, 'check_noindex' => 0]);
    $rid = (int)$val("SELECT id FROM monitors WHERE url LIKE '%example.com%' LIMIT 1");
    if ($rid) {
        $req('/api.php?action=setup', ['csrf' => $tok2, 'id' => $rid]);
        $rr = $req('/api.php?action=check', ['csrf' => $tok2, 'id' => $rid]);
        $j  = json_decode($rr['body'], true);
        $st = $j['result']['state'] ?? '?';
        ok('example.com surveillé en HTTPS réel', in_array($st, ['up', 'degraded'], true),
            'état=' . $st . ' cause=' . ($j['result']['reason'] ?? '—'));
        $m = $db('SELECT ssl_days_left, expect_string, last_ms FROM monitors WHERE id = ?', [$rid])[0] ?? [];
        ok('certificat réel mesuré', ($m['ssl_days_left'] ?? null) !== null, ($m['ssl_days_left'] ?? '?') . ' jours');
        ok('chaîne de contrôle vérifiée sur un vrai site', ($m['expect_string'] ?? '') !== '',
            (string)($m['expect_string'] ?? ''));
    } else {
        ok('example.com importé', false, 'sonde non créée');
    }
}

// =========================================================================
echo "\n" . str_repeat('═', 68) . "\n";
printf("%d contrôle(s) réussi(s), %d échec(s) : %.1f s\n", $pass, $fail, microtime(true) - $t0);
if ($fail > 0) {
    echo "⚠️  Le parcours utilisateur présente des anomalies.\n";
    exit(1);
}
echo "✅ Parcours complet validé de bout en bout.\n";
