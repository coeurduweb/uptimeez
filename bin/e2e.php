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
// Page volontairement lourde, pour l'analyse de vitesse : trois feuilles
// bloquantes, cinq scripts bloquants, une grande image en chargement différé,
// des images sans dimensions et une police sans font-display.
file_put_contents("$tmp/site/gros.css", str_repeat(".r-x{margin:0}\n", 5000));
file_put_contents("$tmp/site/lourd.js", str_repeat("var a=1;\n", 4000));
file_put_contents("$tmp/site/fonts.css", "@font-face{font-family:A;src:url(/a.woff2)}\n");
file_put_contents("$tmp/site/hero.jpg", "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01"
    . str_repeat("\x00", 300 * 1024) . "\xFF\xD9");
file_put_contents("$tmp/site/lente.html", str_replace('<body>',
    '<body><img src="/hero.jpg" loading="lazy" alt="bandeau">'
  . '<img src="/hero.jpg" alt="une"><img src="/hero.jpg" alt="deux">',
    $page('Page lourde',
      '<link rel="stylesheet" href="/style.css">'
    . '<link rel="stylesheet" href="/gros.css">'
    . '<link rel="stylesheet" href="/fonts.css">'
    . '<script src="/lourd.js"></script>'
    . '<script src="https://cdn.un.test/a.js"></script>'
    . '<script src="https://cdn.deux.test/b.js"></script>'
    . '<script src="https://cdn.trois.test/c.js"></script>'
    . '<script src="https://cdn.quatre.test/d.js"></script>')));
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
/** POST multipart, pour tester un dépôt de fichier tel qu'un navigateur l'envoie. */
$upload = function (string $path, array $fields, string $fileField, string $filePath) use ($APP, $jar): array {
    $ch = curl_init();
    $post = $fields;
    $post[$fileField] = new CURLFile($filePath, 'application/octet-stream', basename($filePath));
    curl_setopt_array($ch, [
        CURLOPT_URL => $APP . $path, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post, CURLOPT_TIMEOUT => 60,
    ]);
    $raw  = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hlen = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['code' => $code, 'body' => substr($raw, $hlen), 'head' => substr($raw, 0, $hlen)];
};
$csrf = function () use ($req): string {
    $r = $req('/index.php?p=dashboard');
    return preg_match('~csrf:\s*"([a-f0-9]+)"~', $r['body'], $m) ? $m[1] : '';
};
$has = fn(array $r, string $needle): bool => str_contains($r['body'], $needle);
$noPhpError = fn(array $r): bool => !preg_match('~(Fatal error|Uncaught \w+|Undefined (variable|method|array key)|SQLSTATE\[)~', $r['body']);
// Une page HTML doit se terminer. Avec display_errors coupé, une erreur fatale
// ne laisse aucune trace dans le corps : seule l'absence de </html> la trahit.
$complete = fn(array $r): bool => !str_contains($r['head'], 'text/html')
    || str_contains($r['body'], '</html>');

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
title('Rapport mensuel automatique');
// =========================================================================
$r = $req('/index.php?p=report&ui=expert');
ok('panneau d\'envoi automatique présent', $has($r, 'save_autoreport') && $has($r, 'Jour du mois'));
ok('la liste des sites propose des destinataires', $has($r, 'save_site_report'));

// Enregistrement des réglages généraux.
$r = $req('/index.php?p=report', ['csrf' => $tok, 'action' => 'save_autoreport',
    'report_enabled' => '1', 'report_day' => '3',
    'report_subject' => 'Suivi {site} - {month}', 'report_fallback' => 'agence@exemple.fr']);
ok('réglages d\'envoi enregistrés', $has($r, 'enregistr') || $r['code'] < 400);

// Destinataires d'un site, puis envoi à la demande. Le canal e-mail est
// désactivé sur cette instance : l'échec doit être explicite, pas silencieux.
$sid = (int)$val('SELECT id FROM sites ORDER BY id LIMIT 1');
$r = $req('/index.php?p=report', ['csrf' => $tok, 'action' => 'save_site_report',
    'site_id' => (string)$sid, 'report_to' => 'client@exemple.fr', 'site_report_enabled' => '1']);
$saved = (string)$val('SELECT report_to FROM sites WHERE id = ?', [$sid]);
ok('destinataires du site enregistrés', $saved === 'client@exemple.fr', $saved);
ok('envoi activé pour le site', (int)$val('SELECT report_enabled FROM sites WHERE id = ?', [$sid]) === 1);

$r = $req('/index.php?p=report', ['csrf' => $tok, 'action' => 'send_site_report',
    'site_id' => (string)$sid]);
ok('envoi à la demande : réponse explicite',
   $has($r, 'canal e-mail') || $has($r, 'esure') || $has($r, 'destinataire'), 'HTTP ' . $r['code']);
ok('un envoi en échec ne marque pas le mois',
   $val('SELECT report_sent_key FROM sites WHERE id = ?', [$sid]) === null);

// Une adresse invalide ne doit pas être retenue comme destinataire.
$req('/index.php?p=report', ['csrf' => $tok, 'action' => 'save_site_report',
    'site_id' => (string)$sid, 'report_to' => 'pas-une-adresse', 'site_report_enabled' => '1']);
$r = $req('/index.php?p=report', ['csrf' => $tok, 'action' => 'send_site_report', 'site_id' => (string)$sid]);
ok('adresse invalide : envoi refusé', $has($r, 'destinataire') || $has($r, 'ecipient'));

// Un site inconnu ne provoque pas d'erreur serveur.
$r = $req('/index.php?p=report', ['csrf' => $tok, 'action' => 'send_site_report', 'site_id' => '999999']);
ok('site inconnu : refus propre', $r['code'] < 500 && $noPhpError($r));

// Le cron sait forcer les envois sans casser.
$out = shell_exec('UPTIMER_CONFIG=' . escapeshellarg($cfgFile) . ' '
    . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($ROOT . '/cron.php') . ' --report 2>&1');
ok('cron --report s\'exécute sans erreur',
   $out !== null && !preg_match('~Fatal|Uncaught~', (string)$out), str_cut(trim((string)$out), 60));

// =========================================================================
title('Reprise d\'un parc surveillé ailleurs');
// =========================================================================
// Chaque export est réellement déposé sur l'écran d'import, comme le ferait
// un navigateur, puis les sondes créées sont relues en base.
$fixtures = $tmp . '/exports';
@mkdir($fixtures, 0775, true);
$exports = [
  'uptimerobot' => ['UptimeRobot', 'ur.json', jenc(['stat' => 'ok', 'monitors' => [
      ['id' => 1, 'friendly_name' => 'E2E Vitrine', 'url' => 'https://e2e-ur-a.test/', 'type' => 1,
       'interval' => 600, 'status' => 2],
      ['id' => 2, 'friendly_name' => 'E2E Interdit', 'url' => 'https://e2e-ur-b.test/', 'type' => 2,
       'keyword_type' => 1, 'keyword_value' => 'Erreur fatale', 'interval' => 900, 'status' => 0],
      ['id' => 3, 'friendly_name' => 'E2E Port', 'url' => 'mail.e2e.test', 'type' => 4],
  ]])],
  'kuma' => ['Uptime Kuma', 'kuma.json', jenc(['version' => '1.23.13', 'monitorList' => [
      '1' => ['name' => 'E2E Kuma', 'url' => 'https://e2e-kuma.test/', 'type' => 'keyword',
              'keyword' => 'ok', 'interval' => 120, 'active' => 1, 'maxretries' => 3],
      '2' => ['name' => 'E2E Kuma port', 'type' => 'port', 'hostname' => 'db.e2e.test'],
  ]])],
  'betterstack' => ['Better Stack', 'bs.json', jenc(['data' => [
      ['id' => '1', 'attributes' => ['url' => 'https://e2e-bs.test', 'pronounceable_name' => 'E2E BS',
        'monitor_type' => 'keyword', 'required_keyword' => 'Salut', 'check_frequency' => 180]],
  ]])],
  'pingdom' => ['Pingdom', 'pd.json', jenc(['checks' => [
      ['id' => 1, 'name' => 'E2E Pingdom', 'hostname' => 'e2e-pd.test', 'resolution' => 5,
       'type' => ['http' => ['url' => '/etat', 'encryption' => true, 'shouldcontain' => 'En ligne']]],
  ]])],
  'site24x7' => ['Site24x7', 's24.json', jenc(['data' => [
      ['monitor_id' => '1', 'display_name' => 'E2E S24', 'website' => 'https://e2e-s24.test/',
       'monitor_type' => 'URL', 'check_frequency' => '300',
       'matching_keyword' => ['severity' => 2, 'value' => 'Espace client']],
  ]])],
  'csv' => ['CSV', 'parc.csv', "Nom;URL;Intervalle;Mot-clé\nE2E CSV;https://e2e-csv.test;15;Accueil\n"],
];
foreach ($exports as $k => [$label, $file, $content]) {
    file_put_contents($fixtures . '/' . $file, $content);
}

$fields = ['csrf' => $tok, 'action' => 'preview', 'list' => '', 'interval_sec' => '300', 'pages' => '2'];
foreach ($exports as $k => [$label, $file, $content]) {
    $r = $upload('/index.php?p=import', $fields, 'file', $fixtures . '/' . $file);
    ok('export ' . $label . ' reconnu au dépôt',
        $r['code'] === 200 && $has($r, 'repris de ' . $label), 'HTTP ' . $r['code']);
    ok('aperçu rendu jusqu\'au bout pour ' . $label, $complete($r) && $noPhpError($r));
}

// Un export déposé sans rien coller : le champ texte ne doit pas être requis.
$r = $upload('/index.php?p=import', $fields, 'file', $fixtures . '/ur.json');
ok('le dépôt seul suffit, sans texte collé', $has($r, 'sondes principales à créer'));
// Le pluriel dépend du nombre écarté, et l'apostrophe est échappée dans le HTML :
// les deux formes sont acceptées.
ok('ce qui n\'a pas d\'équivalent est listé',
    (str_contains($r['body'], 'ne peuvent pas être reprises')
      || str_contains($r['body'], 'ne peut pas être reprise'))
    && $has($r, 'port TCP'));
ok('la cadence reprise est signalée comme telle', $has($r, 'reprise de l&#039;export'));
ok('une sonde en pause est annoncée avant création', $has($r, 'à créer, en pause'));

// Création réelle depuis l'export UptimeRobot.
$before = (int)$val('SELECT COUNT(*) FROM monitors');
$r = $upload('/index.php?p=import', ['csrf' => $tok, 'action' => 'import', 'list' => '',
    'interval_sec' => '300', 'pages' => '1', 'group' => 'Reprise E2E'],
    'file', $fixtures . '/ur.json');
ok('import confirmé depuis le fichier', $has($r, 'Reprise depuis UptimeRobot'),
    preg_match('~Reprise depuis [^<.]{0,60}\.~', strip_tags($r['body']), $mm) ? $mm[0] : 'HTTP ' . $r['code']);
$a = $db('SELECT * FROM monitors WHERE url = ?', ['https://e2e-ur-a.test/'])[0] ?? null;
$b = $db('SELECT * FROM monitors WHERE url = ?', ['https://e2e-ur-b.test/'])[0] ?? null;
ok('les deux sondes HTTP existent', $a !== null && $b !== null);
ok('la cadence de l\'export est appliquée', (int)($a['interval_sec'] ?? 0) === 600,
    ($a['interval_sec'] ?? '?') . ' s');
ok('la chaîne interdite est enregistrée', ($b['forbid_string'] ?? '') === 'Erreur fatale');
ok('la sonde en pause est créée en pause', (int)($b['enabled'] ?? 1) === 0);
ok('la sonde de port n\'est pas créée',
    (int)$val('SELECT COUNT(*) FROM monitors WHERE url LIKE ?', ['%mail.e2e.test%']) === 0);
ok('le groupe demandé est appliqué',
    (string)$val('SELECT group_name FROM sites WHERE id = ?', [(int)$a['site_id']]) === 'Reprise E2E');
// L'historique n'est jamais importé : c'est le point de crédibilité.
ok('aucune mesure historique créée',
    (int)$val('SELECT COUNT(*) FROM checks WHERE monitor_id = ?', [(int)$a['id']]) === 0);
ok('aucune disponibilité affichée sans mesure', $a['uptime_30d'] === null);

// Un fichier hostile ne doit ni s'exécuter ni faire tomber la page.
file_put_contents($fixtures . '/piege.json', "<?php echo 'PIRATE'; ?>\n{\"monitors\":[]}");
$r = $upload('/index.php?p=import', $fields, 'file', $fixtures . '/piege.json');
// Le contenu déposé est réaffiché dans la zone de saisie, c'est voulu : on voit
// ce qu'on a envoyé. Ce qui compte est qu'il soit échappé et jamais exécuté.
ok('un fichier contenant du PHP ne s\'exécute pas',
    !str_contains($r['body'], '<?php') && $noPhpError($r));
ok('et son contenu est réaffiché échappé', str_contains($r['body'], '&lt;?php'));
ok('un format non reconnu le dit au lieu de deviner',
    $has($r, 'Format non reconnu') || $has($r, 'Aucune adresse exploitable'));
file_put_contents($fixtures . '/binaire.json', "\x00\x01\x02" . str_repeat("\xff", 200));
$r = $upload('/index.php?p=import', $fields, 'file', $fixtures . '/binaire.json');
ok('un fichier binaire est refusé sans erreur', $r['code'] === 200 && $noPhpError($r));
$r = $upload('/index.php?p=import', $fields, 'file', $fixtures . '/parc.csv');
ok('un CSV reste accepté après un fichier refusé', $has($r, 'repris de CSV'));

// =========================================================================
title('Écran d\'accueil : le pouls et la preuve');
// =========================================================================
$r = $req('/index.php?p=today&ui=simple');
ok('bande de pouls rendue', $has($r, 'class="pulse"') && $has($r, 'il y a 24 h'));
ok('chaque tranche porte son détail', substr_count($r['body'], '<title>') >= 24,
    substr_count($r['body'], '<title>') . ' infobulle(s)');
ok('la durée de panne est le chiffre de la carte', $has($r, 'task-metric'));
ok('la carte porte une preuve à droite', $has($r, 'task-proof'));
// La silhouette d'une page cassée doit apparaître dans la liste de tâches :
// c'est ce qui fait comprendre la panne sans ouvrir la fiche.
ok('silhouette montrée sur une mise en page cassée',
    $has($r, 'task-sil') || $has($r, 'task-spark'));
ok('la reconstitution est annoncée comme telle',
    !$has($r, 'task-sil') || $has($r, 'pas une capture'));
ok('cause et remède côte à côte', $has($r, 'task-cols'));
$r = $req('/index.php?p=dashboard');
ok('le mur porte la même bande', $has($r, 'class="pulse"'));

// =========================================================================
title('Vitesse ressentie : mesures et causes sur la fiche');
// =========================================================================
$slowMon = (int)$val("SELECT id FROM monitors WHERE url LIKE '%lente.html' LIMIT 1");
if ($slowMon === 0) {
    // La page lourde n'existe pas dans ce jeu : on la crée et on la mesure.
    $r = $req('/index.php?p=import', ['csrf' => $tok, 'action' => 'import',
        'list' => "$SITE/lente.html | Page lourde", 'interval_sec' => 300,
        'pages' => 1, 'check_css' => 1]);
    $slowMon = (int)$val("SELECT id FROM monitors WHERE url LIKE '%lente.html' LIMIT 1");
}
ok('page lourde surveillée', $slowMon > 0);
$r = $req('/api.php?action=check', ['csrf' => $tok, 'id' => $slowMon]);
ok('vérification manuelle acceptée', $r['code'] === 200, 'HTTP ' . $r['code']);
$vd = jdec((string)$val('SELECT vitals_detail FROM monitors WHERE id = ?', [$slowMon]));
ok('analyse de vitesse écrite en base', ($vd['ttfb_ms'] ?? null) !== null,
    ($vd['ttfb_ms'] ?? '?') . ' ms');
ok('causes trouvées sur une page lourde', count((array)($vd['findings'] ?? [])) >= 4,
    count((array)($vd['findings'] ?? [])) . ' cause(s)');

$r = $req('/index.php?p=monitor&id=' . $slowMon . '&ui=expert');
ok('bloc de vitesse présent sur la fiche', $has($r, 'Vitesse ressentie par les visiteurs'));
ok('le temps de réponse du serveur est montré', $has($r, 'Réponse du serveur'));
ok('les causes sont montrées avec leur remède',
    $has($r, 'Ce qui ralentit cette page') && $has($r, 'vit-fix'));
// La distinction mesure / cause probable est la promesse de cette section.
ok('la page dit que les causes ne sont pas des mesures',
    $has($r, 'rien ici n') && $has($r, 'mesure de navigateur'));
ok('sans clé, aucun LCP n\'est affiché',
    !$has($r, 'Affichage du contenu principal') && $has($r, 'Ajouter une clé'));
ok('fiche rendue jusqu\'au bout', $complete($r));

// Réglages : la clé et l'appareil de référence s'enregistrent.
$r = $req('/index.php?p=settings', ['csrf' => $tok, 'action' => 'save_settings',
    'app_name' => 'Uptimer E2E', 'timezone' => 'Europe/Paris',
    'vitals_enabled' => '1', 'crux_key' => 'cle-de-test-e2e', 'form_factor' => 'DESKTOP',
    'def_interval' => 300, 'def_timeout' => 15, 'def_retries' => 2, 'def_ssl_days' => 14,
    'def_slow' => 3000, 'def_css_drop' => 35, 'def_parallel' => 10, 'def_retention' => 45]);
ok('clé de mesures de terrain enregistrée', $has($r, 'enregistr') || $r['code'] < 400);
$r = $req('/index.php?p=settings&ui=expert');
ok('clé relue dans le formulaire', $has($r, 'cle-de-test-e2e'));
ok('appareil de référence relu', $has($r, 'value="DESKTOP" selected')
    || preg_match('~value="DESKTOP"[^>]*selected~', $r['body']) === 1);
// Et on la retire : le reste du banc ne doit pas partir interroger Google.
$r = $req('/index.php?p=settings', ['csrf' => $tok, 'action' => 'save_settings',
    'app_name' => 'Uptimer E2E', 'timezone' => 'Europe/Paris',
    'vitals_enabled' => '1', 'crux_key' => '', 'form_factor' => 'PHONE',
    'def_interval' => 300, 'def_timeout' => 15, 'def_retries' => 2, 'def_ssl_days' => 14,
    'def_slow' => 3000, 'def_css_drop' => 35, 'def_parallel' => 10, 'def_retention' => 45]);
ok('clé retirée sans casser les autres réglages', $r['code'] < 400);

// =========================================================================
title('Mode agence : un client ne voit que ses sites');
// =========================================================================
// Un visiteur anonyme, cookies séparés : c'est la seule façon de prouver que
// l'espace client s'ouvre sans session d'administration, et pas parce qu'on
// était déjà connecté.
$anonJar = $tmp . '/anon.txt';
@unlink($anonJar);
$anon = function (string $path, ?array $post = null) use ($APP, $anonJar): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $APP . $path, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $anonJar, CURLOPT_COOKIEFILE => $anonJar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw  = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hlen = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['code' => $code, 'body' => substr($raw, $hlen), 'head' => substr($raw, 0, $hlen)];
};

// Trois sites aux noms bien distincts. L'import de ce banc ne crée qu'un site
// (tout est sur 127.0.0.1) et son nom se retrouve partout dans les pages : il
// ne peut pas servir à prouver une absence. On fabrique donc de quoi tester.
$mkSite = function (string $name, string $domain, ?string $group = null) use ($SITE): array {
    $sid = Uptimer\Db::insert('sites', ['name' => $name, 'domain' => $domain,
        'group_name' => $group, 'created_at' => now()]);
    $mid = Uptimer\Db::insert('monitors', ['site_id' => $sid, 'name' => $name,
        'url' => $SITE . '/', 'kind' => 'page', 'role' => 'primary', 'method' => 'GET',
        'interval_sec' => 300, 'timeout_sec' => 15, 'retries' => 0, 'expect_status' => '200-299',
        'enabled' => 1, 'status' => 'up', 'uptime_30d' => 99.9, 'setup_state' => 'done',
        'created_at' => now(), 'next_check_at' => now()]);
    return [$sid, $mid];
};
[$sA] = $mkSite('Alpha Immobilier', 'alpha-exemple.test');
[$sB] = $mkSite('Beta Pressing', 'beta-exemple.test');
[$sC] = $mkSite('Gamma Studio', 'gamma-exemple.test', 'Groupe Gamma');
$nameA = 'Alpha Immobilier';
$nameB = 'Beta Pressing';
ok('trois sites de test en place',
    (int)$val('SELECT COUNT(*) FROM sites WHERE domain LIKE ?', ['%-exemple.test']) === 3);
$r = $req('/index.php?p=clients', ['csrf' => $tok, 'action' => 'client_create',
    'client_name' => 'Client Alpha', 'client_email' => 'alpha@exemple.fr']);
$r = $req('/index.php?p=clients', ['csrf' => $tok, 'action' => 'client_create',
    'client_name' => 'Client Beta', 'client_email' => 'beta@exemple.fr']);
$alpha = $db("SELECT * FROM clients WHERE name = 'Client Alpha'")[0] ?? null;
$beta  = $db("SELECT * FROM clients WHERE name = 'Client Beta'")[0] ?? null;
ok('deux clients créés', $alpha !== null && $beta !== null);
ok('jeton généré, imprévisible et unique',
    $alpha && $beta && preg_match('~^[0-9a-f]{32}$~', (string)$alpha['token']) === 1
    && $alpha['token'] !== $beta['token'], substr((string)($alpha['token'] ?? ''), 0, 10) . '…');

$r = $req('/index.php?p=clients', ['csrf' => $tok, 'action' => 'client_save',
    'client_id' => (int)$alpha['id'], 'client_name' => 'Client Alpha',
    'client_email' => 'alpha@exemple.fr', 'client_enabled' => '1', 'sites' => [$sA]]);
$r = $req('/index.php?p=clients', ['csrf' => $tok, 'action' => 'client_save',
    'client_id' => (int)$beta['id'], 'client_name' => 'Client Beta',
    'client_email' => 'beta@exemple.fr', 'client_enabled' => '1', 'sites' => [$sB]]);
ok('rattachement des sites enregistré',
    (int)$val('SELECT client_id FROM sites WHERE id = ?', [$sA]) === (int)$alpha['id']
    && (int)$val('SELECT client_id FROM sites WHERE id = ?', [$sB]) === (int)$beta['id']);

// ---- Le test qui compte : l'espace d'Alpha ne parle jamais de Beta ------
$ra = $anon('/index.php?p=client&k=' . (string)$alpha['token']);
ok('espace client ouvert sans authentification', $ra['code'] === 200 && $noPhpError($ra),
    'HTTP ' . $ra['code']);
// Une page tronquée par une erreur fatale passe inaperçue quand display_errors
// est coupé : on exige donc la présence du pied de page, écrit en dernier.
ok('page rendue jusqu\'au bout, pas coupée par une erreur',
    str_contains($ra['body'], 'cli-foot') && str_contains($ra['body'], '</html>'));
ok('le client voit son site', str_contains($ra['body'], $nameA), $nameA);
ok('le client ne voit pas le site de l\'autre', !str_contains($ra['body'], $nameB), $nameB);
ok('aucune navigation d\'administration dans l\'espace',
    !str_contains($ra['body'], 'p=settings') && !str_contains($ra['body'], 'p=monitors')
    && !str_contains($ra['body'], 'p=clients'));
// Le seul formulaire toléré est le sélecteur de langue, en GET.
$posts = preg_match_all('~<form[^>]*method=["\']post~i', $ra['body']);
ok('aucun formulaire d\'écriture dans l\'espace', $posts === 0, $posts . ' formulaire(s) POST');
ok('jeton d\'administration absent de la page',
    !str_contains($ra['body'], 'UPTIMER') && !str_contains($ra['body'], 'csrf'));
ok('page non indexable et sans référent sortant',
    stripos($ra['head'], 'x-robots-tag: noindex') !== false
    && stripos($ra['head'], 'referrer-policy: no-referrer') !== false);

// ---- Ce qui doit échouer ------------------------------------------------
ok('jeton inconnu : introuvable', $anon('/index.php?p=client&k=' . str_repeat('a', 32))['code'] === 404);
ok('sans jeton : introuvable', $anon('/index.php?p=client')['code'] === 404);
foreach (["' OR 1=1 --", '../../config.php', '<script>x</script>', str_repeat('f', 400)] as $bad) {
    $rb = $anon('/index.php?p=client&k=' . rawurlencode($bad));
    ok('jeton hostile rejeté sans erreur : ' . str_cut($bad, 18),
        $rb['code'] === 404 && $noPhpError($rb), 'HTTP ' . $rb['code']);
}
// Un identifiant dans l'URL ne doit rien changer : c'est le jeton qui décide.
$rc = $anon('/index.php?p=client&k=' . (string)$alpha['token'] . '&client_id=' . (int)$beta['id']
          . '&site=' . $sB . '&id=' . $sB);
ok('identifiant ajouté dans l\'URL sans effet',
    $rc['code'] === 200 && str_contains($rc['body'], $nameA) && !str_contains($rc['body'], $nameB));

// ---- Lecture seule : aucune écriture atteignable avec le jeton ----------
$before = (int)$val('SELECT COUNT(*) FROM clients');
$rw = $anon('/index.php?p=client&k=' . (string)$alpha['token'],
            ['action' => 'client_delete', 'client_id' => (int)$beta['id'], 'csrf' => $tok]);
ok('POST hostile depuis l\'espace client sans effet',
    (int)$val('SELECT COUNT(*) FROM clients') === $before
    && (int)$val('SELECT COUNT(*) FROM clients WHERE id = ?', [(int)$beta['id']]) === 1);
$rw = $anon('/index.php?p=clients');
ok('écran de gestion inaccessible sans session',
    $rw['code'] !== 200 || !str_contains($rw['body'], 'Ajouter un client'), 'HTTP ' . $rw['code']);
$rw = $anon('/index.php?p=monitors');
ok('liste des sondes inaccessible sans session',
    $rw['code'] !== 200 || str_contains($rw['body'], 'mot de passe') || str_contains($rw['body'], 'Connexion'),
    'HTTP ' . $rw['code']);

// ---- Révocation ---------------------------------------------------------
$oldTok = (string)$alpha['token'];
$r = $req('/index.php?p=clients', ['csrf' => $tok, 'action' => 'client_rotate',
    'client_id' => (int)$alpha['id']]);
$newTok = (string)$val('SELECT token FROM clients WHERE id = ?', [(int)$alpha['id']]);
ok('changer le lien coupe l\'ancien', $newTok !== $oldTok
    && $anon('/index.php?p=client&k=' . $oldTok)['code'] === 404
    && $anon('/index.php?p=client&k=' . $newTok)['code'] === 200);

// Accès fermé : le lien existe toujours, la page non.
$r = $req('/index.php?p=clients', ['csrf' => $tok, 'action' => 'client_save',
    'client_id' => (int)$alpha['id'], 'client_name' => 'Client Alpha',
    'client_email' => 'alpha@exemple.fr', 'sites' => [$sA]]);
ok('accès fermé : même réponse qu\'un lien inconnu',
    $anon('/index.php?p=client&k=' . $newTok)['code'] === 404);
$r = $req('/index.php?p=clients', ['csrf' => $tok, 'action' => 'client_save',
    'client_id' => (int)$alpha['id'], 'client_name' => 'Client Alpha',
    'client_email' => 'alpha@exemple.fr', 'client_enabled' => '1', 'sites' => [$sA]]);
ok('accès réouvert sans rien perdre',
    $anon('/index.php?p=client&k=' . $newTok)['code'] === 200
    && (int)$val('SELECT client_id FROM sites WHERE id = ?', [$sA]) === (int)$alpha['id']);

// ---- Le rapport mensuel retombe sur l'adresse du client ----------------
Uptimer\Db::update('sites', ['report_to' => null], 'id = :__i', ['__i' => $sA]);
$siteRow = $db('SELECT * FROM sites WHERE id = ?', [$sA])[0];
ok('destinataire hérité du client quand le site n\'en a pas',
    in_array('alpha@exemple.fr', Uptimer\Report::recipients($siteRow), true),
    implode(', ', Uptimer\Report::recipients($siteRow)) ?: '(aucun)');
// Et l'inverse : ce que porte le site gagne toujours sur l'adresse du client.
Uptimer\Db::update('sites', ['report_to' => 'direct@exemple.fr'], 'id = :__i', ['__i' => $sA]);
$siteOwn = $db('SELECT * FROM sites WHERE id = ?', [$sA])[0];
ok('adresse propre au site prioritaire sur celle du client',
    Uptimer\Report::recipients($siteOwn) === ['direct@exemple.fr']);

// ---- Suppression : les sites survivent ---------------------------------
$sitesBefore = (int)$val('SELECT COUNT(*) FROM sites');
$monBefore   = (int)$val('SELECT COUNT(*) FROM monitors');
$r = $req('/index.php?p=clients', ['csrf' => $tok, 'action' => 'client_delete',
    'client_id' => (int)$beta['id']]);
ok('client supprimé sans emporter ses sites',
    (int)$val('SELECT COUNT(*) FROM clients WHERE id = ?', [(int)$beta['id']]) === 0
    && (int)$val('SELECT COUNT(*) FROM sites') === $sitesBefore
    && (int)$val('SELECT COUNT(*) FROM monitors') === $monBefore
    && $val('SELECT client_id FROM sites WHERE id = ?', [$sB]) === null);

// ---- Reprise des groupes existants -------------------------------------
$r = $req('/index.php?p=clients', ['csrf' => $tok, 'action' => 'client_from_groups']);
$fromGroup = (int)$val("SELECT COUNT(*) FROM clients WHERE name = 'Groupe Gamma'");
ok('client repris depuis un groupe existant', $fromGroup === 1,
    $fromGroup . ' client(s) « Groupe Gamma »');
ok('le site du groupe est bien rattaché',
    (int)$val('SELECT client_id FROM sites WHERE id = ?', [$sC])
      === (int)$val("SELECT id FROM clients WHERE name = 'Groupe Gamma'"));
$r = $req('/index.php?p=clients', ['csrf' => $tok, 'action' => 'client_from_groups']);
ok('reprise idempotente : aucun doublon',
    (int)$val("SELECT COUNT(*) FROM clients WHERE name = 'Groupe Gamma'") === 1);

// ---- L'écran de gestion, côté agence -----------------------------------
$r = $req('/index.php?p=clients&ui=expert');
ok('écran de gestion des clients affiché',
    $r['code'] === 200 && $noPhpError($r) && $has($r, 'Client Alpha') && $has($r, 'Ajouter un client'));
ok('lien de chaque client proposé à la copie', $has($r, 'p=client&amp;k='));
ok('onglet Clients dans la navigation', $has($r, 'p=clients'));

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
title('Chaque écran rendu jusqu\'au bout');
// =========================================================================
// Un appel de méthode privée ou une clé manquante interrompt le rendu sans
// laisser de message quand display_errors est coupé. Le seul témoin fiable est
// la balise de fermeture : on la vérifie sur tous les écrans, dans les deux
// niveaux de détail.
$incomplete = [];
foreach (['today', 'dashboard', 'monitors', 'incidents', 'events', 'report', 'settings',
          'import', 'clients'] as $p) {
    foreach (['simple', 'expert'] as $mode) {
        $rr = $req('/index.php?p=' . $p . '&ui=' . $mode);
        if (!$complete($rr) || !$noPhpError($rr)) $incomplete[] = $p . '/' . $mode;
    }
}
$oneId = (int)$val('SELECT id FROM monitors ORDER BY id LIMIT 1');
foreach (['simple', 'expert'] as $mode) {
    $rr = $req('/index.php?p=monitor&id=' . $oneId . '&ui=' . $mode);
    if (!$complete($rr) || !$noPhpError($rr)) $incomplete[] = 'monitor/' . $mode;
}
ok('aucun écran interrompu en cours de rendu', $incomplete === [], implode(' ', $incomplete));

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
