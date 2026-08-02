<?php
/**
 * UptimeEZ : test de bout en bout de l'interface.
 *
 * Démarre une instance isolée de UptimeEZ et un faux site à surveiller, puis
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
$tmp = sys_get_temp_dir() . '/uptimeez-e2e-' . bin2hex(random_bytes(4));
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
// Page volontairement plus grosse que la lecture maximale, servie en blocs de
// tailles variables d'un appel à l'autre : c'est exactement ce que fait un
// serveur réel, et c'est ce qui rendait la coupure — donc l'empreinte de
// contenu — différente à chaque passe.
file_put_contents("$tmp/site/enorme.php", "<?php\n"
    . "header('Content-Type: text/html; charset=utf-8');\n"
    . "echo \"<!doctype html><html><head><title>Enorme</title></head><body>\";\n"
    . "\$bloc = str_repeat('x', 7919);\n"
    . "\$n = 0;\n"
    . "while (\$n < 3_400_000) {\n"
    . "    \$len = 500 + ((\$n / 7919) % 2 ? 6000 : 1000);\n"
    . "    echo substr(\$bloc, 0, \$len); \$n += \$len;\n"
    . "    if (\$n % 100000 < \$len) { flush(); usleep(200); }\n"
    . "}\n"
    . "echo '<footer>Mentions legales 2026</footer></body></html>';\n");
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

// Secret du pont d'authentification : la fonctionnalité n'existe que si un
// secret est configuré, on en pose donc un pour pouvoir l'éprouver.
$BRIDGE = bin2hex(random_bytes(32));

// --- Configuration isolée -------------------------------------------------
$cfgFile = $tmp . '/config.php';
file_put_contents($cfgFile, "<?php return " . var_export([
    'db'   => ['driver' => 'sqlite', 'sqlite' => $tmp . '/e2e.sqlite'],
    'auth' => ['password_hash' => password_hash($PASS, PASSWORD_DEFAULT), 'session_name' => 'uptimeeze2e',
               'bridge_secret' => $BRIDGE],
    'app'  => ['name' => 'UptimeEZ E2E', 'base_url' => $APP, 'timezone' => 'Europe/Paris',
               'public_token' => 'jeton-e2e', 'cron_key' => 'cle-e2e'],
    'defaults' => ['interval_sec' => 300, 'timeout_sec' => 10, 'retries' => 0, 'slow_ms' => 9000,
                   'max_parallel' => 6, 'retention_days' => 60, 'ssl_warn_days' => 14, 'css_drop_pct' => 35,
                   'user_agent' => 'UptimeEZBot/1.0 (E2E)'],
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
$appSrv  = $spawn([PHP_BINARY, '-S', "127.0.0.1:$appPort", '-t', $ROOT], $ROOT, ['UPTIMEEZ_CONFIG' => $cfgFile]);

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
    // Récursif : le verrou de passe vit désormais dans $tmp/data, et la seconde
    // instance du contrôle de verrou dans $tmp/instance2/data. Un ménage écrit
    // dossier par dossier laisse traîner ce qu'on ajoute ensuite.
    if (is_dir($tmp)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    }
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
ok('import accepté', $r['code'] === 200 && $noPhpError($r)
    && (str_contains($r['body'], 'sondes créées') || str_contains($r['body'], 'sonde créée')));
ok('ligne invalide signalée sans bloquer', $has($r, 'ignorée'));

require_once $ROOT . '/src/bootstrap.php';
Uptimeez\Config::set('db.driver', 'sqlite');
Uptimeez\Config::set('db.sqlite', $tmp . '/e2e.sqlite');
$db = fn(string $sql, array $p = []) => Uptimeez\Db::all($sql, $p);
$val = fn(string $sql, array $p = []) => Uptimeez\Db::val($sql, $p);

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

// UNE MISE EN PAGE CASSÉE REND « DÉGRADÉ », PLUS « HORS SERVICE ». Changement de contrat
// du 2026-08-02 : « hors service » est réservé à ce qui prive le visiteur de la page. La
// page casse.html RÉPOND, son contenu est là, c'est son apparence qui souffre. La cause
// reste CSS_BROKEN, qui dit la gravité ; l'état dit ce que le visiteur obtient.
//
// Le contrat a changé le matin et cette suite ne l'a su qu'en fin de journée, faute d'avoir
// été relancée entre les deux : elle est restée rouge plusieurs heures. C'est la raison
// pour laquelle l'extraction des règles impose selftest ET e2e entre CHAQUE règle, et non
// à la fin.
foreach ([[$brokenId, 'degraded', 'CSS_BROKEN', 'mise en page'], [$dbId, 'down', 'DB_DOWN', 'base de données'],
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
// UN PREMIER ÉCHEC N'OUVRE PLUS D'INCIDENT. Changement de contrat du 2026-08-02 : le
// premier échec replanifie la sonde à +30 s et compte, l'incident n'est ouvert qu'au
// SECOND échec consécutif. C'est ce qui supprime les alertes pour une panne déjà finie
// quand le client ouvre son courriel.
//
// Le parcours ne vérifie donc plus « au moins deux incidents ouverts » après une seule
// passe, ce qui reviendrait à exiger le comportement qu'on vient de retirer. Il vérifie ce
// qui compte maintenant : la panne est CONNUE de la sonde, et l'incident arrive à la
// confirmation.
$enPanne = (int)$val("SELECT COUNT(*) FROM monitors WHERE status IN ('down', 'degraded')");
ok('les pannes sont constatées sur les sondes', $enPanne >= 2, $enPanne . ' sonde(s)');

// La confirmation : on refait passer les sondes en panne, et l'incident doit s'ouvrir.
foreach ([$brokenId, $dbId] as $mid) $req('/api.php?action=check', ['csrf' => $tok, 'id' => $mid]);
$openInc = (int)$val('SELECT COUNT(*) FROM incidents WHERE ended_at IS NULL');
ok('incidents ouverts à la confirmation', $openInc >= 2, $openInc . ' ouvert(s)');

$incId = (int)$val('SELECT id FROM incidents WHERE ended_at IS NULL ORDER BY id ASC LIMIT 1');
$r = $req('/index.php?p=incidents', ['csrf' => $tok, 'action' => 'ack_incident', 'id' => $incId]);
ok('incident pris en compte', $has($r, 'pris en compte') || $val('SELECT ack_at FROM incidents WHERE id = ?', [$incId]) !== null);
// ---- Le retour d'exploitation, et la garantie qu'il ne change rien -------
//
// L'ACCEPTATION DU SPRINT B1 SE JOUE ICI, PAS DANS LE SELFTEST. Le contrôle unitaire
// prouve que la classe n'écrit pas dans « monitors » ; celui-ci prouve que le PARCOURS
// entier, formulaire compris, ne modifie ni l'état de la sonde ni celui de l'incident.
// C'est la dérive que tout le sprint cherche à éviter : un bouton qui, de correction en
// correction, finirait par faire taire l'alerte qu'il devait seulement commenter.
$avantEtat = $val('SELECT status FROM monitors WHERE id = ?',
    [(int)$val('SELECT monitor_id FROM incidents WHERE id = ?', [$incId])]);
$avantFin  = $val('SELECT ended_at FROM incidents WHERE id = ?', [$incId]);

$r = $req('/api.php?action=retour', ['csrf' => $tok, 'action' => 'retour',
    'incident_id' => $incId, 'motif' => 'controle_errone', 'portee' => 'sonde',
    'commentaire' => 'la police manque mais la page est correcte']);
$j = json_decode($r['body'], true);

ok('un retour d\'exploitation est accepté', ($j['ok'] ?? false) === true, str_cut(trim($r['body']), 70));
ok('et il est enregistré au corpus',
    (int)$val('SELECT COUNT(*) FROM retours WHERE incident_id = ?', [$incId]) === 1);
ok('la cause vient de l\'incident, pas du formulaire',
    $val('SELECT reason_code FROM retours WHERE incident_id = ?', [$incId])
        === $val('SELECT reason_code FROM incidents WHERE id = ?', [$incId]));
// LA GARANTIE, DITE AU CLIENT ET VÉRIFIÉE DANS LA BASE.
ok('la réponse annonce qu\'aucun verdict n\'a changé', ($j['verdict_modifie'] ?? true) === false);
ok('l\'incident n\'a pas été clos par le retour',
    $val('SELECT ended_at FROM incidents WHERE id = ?', [$incId]) === $avantFin);
ok('et la sonde a gardé son état',
    $val('SELECT status FROM monitors WHERE id = ?',
        [(int)$val('SELECT monitor_id FROM incidents WHERE id = ?', [$incId])]) === $avantEtat);

// Un motif inventé est refusé, et le message d'erreur ne fuit pas l'exception interne.
$r = $req('/api.php?action=retour', ['csrf' => $tok, 'action' => 'retour',
    'incident_id' => $incId, 'motif' => 'peu importe', 'portee' => 'sonde']);
ok('un motif hors liste est refusé', $r['code'] === 422, 'HTTP ' . $r['code']);
ok('et le message interne ne fuit pas',
    !str_contains($r['body'], 'Motif inconnu'), str_cut(trim($r['body']), 60));

// L'écran qui lit le corpus. Le selftest prouve que les requêtes disent le vrai ; celui-ci
// prouve que la page s'affiche, ce qui n'est pas la même chose : une vue peut être juste
// et tomber sur une colonne absente ou un appel manquant.
$r = $req('/index.php?p=retours');
ok('l\'écran des retours répond', $r['code'] === 200 && !str_contains($r['body'], 'Fatal error'),
    'HTTP ' . $r['code']);
ok('et il montre les contradictions en premier',
    strpos($r['body'], 'contredisent') !== false
        && strpos($r['body'], 'contredisent') < (strpos($r['body'], 'derniers retours') ?: PHP_INT_MAX));
ok('et le retour déposé plus haut y figure', $has($r, 'la police manque'));
// LA PAGE DIT ELLE-MÊME QU'ELLE NE DÉCIDE RIEN. Sans cette phrase, un écran listant des
// « contrôles jugés faux » se lit comme une file de corrections déjà appliquées.
ok('et elle rappelle qu\'aucun verdict n\'a changé', $has($r, 'ils décrivent'));

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
    && ($has($r, 'À traiter d&#039;abord') || $has($r, 'Rien à faire')), 'HTTP ' . $r['code']);
ok('chaque tâche porte sa cause', $has($r, 'La mise en page est cassée')
    || $has($r, 'La base de données ne répond plus'));
ok('chaque tâche porte la conduite à tenir', $has($r, 'hero-fix'));
ok('actions disponibles sur place', $has($r, 'js-fix') && $has($r, 'js-copy-report'));
ok('bloc d\'anticipation présent', $has($r, 'À prévoir') || $has($r, 'sans rien à signaler'));
$r2 = $req('/index.php');
ok('la racine mène à Aujourd\'hui', ($r2['code'] === 200
      && ($has($r2, 'À traiter d&#039;abord') || $has($r2, 'Rien à faire')))
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
Uptimeez\Db::insert('monitors', ['name' => 'Boulangerie Créchêt', 'url' => "$SITE/tarifs.html",
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
$hb = Uptimeez\Heartbeat::create('Cron client E2E', 3600, 300);
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
    'list' => "Bonjour, merci de surveiller aperçu-un-uptimeez.fr et https://apercu-deux-uptimeez.fr/contact. Logo : x.png",
    'interval_sec' => 300, 'pages' => 3]);
ok('aperçu affiché', $r['code'] === 200 && $has($r, 'Aperçu :') && $noPhpError($r));
ok('rien n\'a été créé à cette étape', (int)$val('SELECT COUNT(*) FROM monitors') === $before,
    $before . ' → ' . $val('SELECT COUNT(*) FROM monitors'));
ok('adresses extraites d\'un texte libre', $has($r, 'apercu-deux-uptimeez.fr'));
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
$out = shell_exec('UPTIMEEZ_CONFIG=' . escapeshellarg($cfgFile) . ' '
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
ok('import confirmé depuis le fichier', $has($r, 'reprise depuis UptimeRobot'),
    preg_match('~[0-9]+ sondes créées[^<.]{0,80}~', strip_tags($r['body']), $mm) ? $mm[0] : 'HTTP ' . $r['code']);
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
// Un seul foyer d'attention : la panne la plus urgente occupe une carte, les
// suivantes une ligne chacune. C'est ce qui dit où regarder.
ok('une seule carte détaillée', substr_count($r['body'], 'class="hero-task') === 1,
    substr_count($r['body'], 'class="hero-task') . ' carte(s)');
ok('la cause est le point d\'entrée du regard', $has($r, 'hero-cause'));
ok('la conduite à tenir est étiquetée', $has($r, 'hero-fix-tag'));
ok('la durée est affichée sans voler le titre', $has($r, 'hero-since'));
// La preuve : la silhouette de la page cassée, ou la courbe des 24 heures.
ok('la carte porte une preuve', $has($r, 'hero-proof'));
ok('la reconstitution est annoncée comme telle',
    !$has($r, 'hero-proof-view') || $has($r, 'pas une capture'));
// Un seul bouton principal par tâche : le reste est replié.
ok('les actions secondaires sont repliées', $has($r, 'act-more'));
// Le total affiché doit correspondre au parc : une carte plus la file.
$queue = substr_count($r['body'], 'class="q-row');
$expected = (int)$val("SELECT COUNT(DISTINCT COALESCE(site_id, -id)) FROM monitors
                       WHERE enabled = 1 AND status IN ('down', 'degraded')");
ok('toutes les pannes sont montrées, une carte puis une file',
    1 + $queue === max(1, $expected), '1 + ' . $queue . ' pour ' . $expected . ' site(s) en panne');
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
    'app_name' => 'UptimeEZ E2E', 'timezone' => 'Europe/Paris',
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
    'app_name' => 'UptimeEZ E2E', 'timezone' => 'Europe/Paris',
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
    $sid = Uptimeez\Db::insert('sites', ['name' => $name, 'domain' => $domain,
        'group_name' => $group, 'created_at' => now()]);
    $mid = Uptimeez\Db::insert('monitors', ['site_id' => $sid, 'name' => $name,
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
    !str_contains($ra['body'], 'UPTIMEEZ') && !str_contains($ra['body'], 'csrf'));
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
Uptimeez\Db::update('sites', ['report_to' => null], 'id = :__i', ['__i' => $sA]);
$siteRow = $db('SELECT * FROM sites WHERE id = ?', [$sA])[0];
ok('destinataire hérité du client quand le site n\'en a pas',
    in_array('alpha@exemple.fr', Uptimeez\Report::recipients($siteRow), true),
    implode(', ', Uptimeez\Report::recipients($siteRow)) ?: '(aucun)');
// Et l'inverse : ce que porte le site gagne toujours sur l'adresse du client.
Uptimeez\Db::update('sites', ['report_to' => 'direct@exemple.fr'], 'id = :__i', ['__i' => $sA]);
$siteOwn = $db('SELECT * FROM sites WHERE id = ?', [$sA])[0];
ok('adresse propre au site prioritaire sur celle du client',
    Uptimeez\Report::recipients($siteOwn) === ['direct@exemple.fr']);

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
    'app_name' => 'UptimeEZ E2E renommée', 'base_url' => $APP, 'timezone' => 'Europe/Paris',
    'def_interval' => 300, 'def_timeout' => 15, 'def_retries' => 2, 'def_ssl_days' => 21,
    'def_slow' => 4000, 'def_css_drop' => 30, 'def_parallel' => 8, 'def_retention' => 45,
    'resend_after' => 30, 'notify_recovery' => 1, 'public_token' => 'jeton-e2e', 'cron_key' => 'cle-e2e',
    'webhook_enabled' => 1, 'webhook_url' => "$SITE/api.php"]);
ok('réglages enregistrés', $has($r, 'Réglages enregistrés'));
$cfg = require $cfgFile;
ok('écrits dans le fichier de configuration',
    ($cfg['app']['name'] ?? '') === 'UptimeEZ E2E renommée' && (int)($cfg['defaults']['def_retention'] ?? 45) !== 0
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
title('Le verrou de passe appartient à l\'instance, pas au code partagé');
// =========================================================================
// LE DÉFAUT REPRODUIT ICI. Le verrou était pris sur UPTIMEEZ_ROOT/data/cron.lock,
// c'est-à-dire dans le dossier du MOTEUR. Or plusieurs instances partagent un seul
// exemplaire du code : c'est toute la raison d'être de UPTIMEEZ_CONFIG, et c'est
// ainsi qu'un serveur fait tourner dix clients. Le verrou était donc COMMUN aux dix.
// Constaté le 2026-08-01 sur un serveur à deux instances : un seul cron.lock.
//
// Conséquence, et elle est muette des deux côtés : la première passe de la minute
// prend le verrou, les neuf autres affichent « une passe est déjà en cours, on laisse
// la main » et repartent SANS AVOIR RIEN VÉRIFIÉ. Chaque passe se termine proprement.
// Neuf clients sur dix ne sont pas surveillés et personne ne peut le voir.
//
// Ce contrôle est BEHAVIORAL et non de source : on retire le verrou du dossier de
// code, on lance une passe désignant la configuration de CETTE instance, et on
// regarde où le verrou est réapparu. Remettre le défaut fait échouer la seconde
// ligne, pas la première : c'est ce qui distingue « le verrou existe » de « le verrou
// est au bon endroit ».
$verrouInstance = $tmp . '/data/cron.lock';
$verrouPartage  = $ROOT . '/data/cron.lock';
@unlink($verrouInstance);
@unlink($verrouPartage);
$out = shell_exec('UPTIMEEZ_CONFIG=' . escapeshellarg($cfgFile) . ' '
    . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($ROOT . '/cron.php') . ' --setup 2>&1');
ok('la passe s\'exécute et prend son verrou',
   $out !== null && !preg_match('~Impossible d\'ouvrir le verrou|Fatal|Uncaught~', (string)$out),
   str_cut(trim((string)$out), 60));
clearstatcache();
ok('le verrou est posé à côté de la configuration de l\'instance',
   is_file($verrouInstance), is_file($verrouInstance) ? basename(dirname($tmp)) . '/…/data/cron.lock'
                                                     : 'ABSENT : ' . $verrouInstance);
ok('et RIEN n\'est posé dans le dossier du code, que toutes les instances partagent',
   !is_file($verrouPartage),
   is_file($verrouPartage) ? 'verrou COMMUN recréé dans ' . $verrouPartage
                             . ' : les instances s\'affament entre elles' : '');

// Deux instances doivent pouvoir passer EN MÊME TEMPS. C'est la conséquence utile
// du correctif, et le seul moyen de la prouver est de lancer les deux passes en
// parallèle et de regarder si la seconde a laissé la main.
$cfg2 = $tmp . '/instance2/config.php';
@mkdir($tmp . '/instance2/data', 0775, true);
copy($cfgFile, $cfg2);
file_put_contents($cfg2, str_replace($tmp . '/e2e.sqlite', $tmp . '/instance2/e2e2.sqlite',
                                     (string)file_get_contents($cfg2)));
$lance = function (string $cfg) use ($ROOT): array {
    $h = proc_open([PHP_BINARY, $ROOT . '/cron.php', '--setup'],
                   [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $p, $ROOT,
                   ['UPTIMEEZ_CONFIG' => $cfg, 'PATH' => getenv('PATH') ?: '/usr/bin:/bin']);
    return [$h, $p];
};
[$h1, $p1] = $lance($cfgFile);
[$h2, $p2] = $lance($cfg2);
$s1 = stream_get_contents($p1[1]) . stream_get_contents($p1[2]);
$s2 = stream_get_contents($p2[1]) . stream_get_contents($p2[2]);
foreach ([[$h1, $p1], [$h2, $p2]] as [$h, $p]) {
    foreach ($p as $f) if (is_resource($f)) fclose($f);
    if (is_resource($h)) proc_close($h);
}
$mainLevee = str_contains($s1, 'déjà en cours') || str_contains($s2, 'déjà en cours');
ok('deux instances passent en parallèle sans se voler la main', !$mainLevee,
   $mainLevee ? 'une passe a laissé la main : le verrou est encore commun' : '');
$deuxVerrous = is_file($tmp . '/data/cron.lock') && is_file($tmp . '/instance2/data/cron.lock');
ok('et chacune a son propre verrou', $deuxVerrous, $deuxVerrous ? '' : 'un verrou par instance attendu');

// =========================================================================
title('Sonde d\'API : la préparation ne lui pose pas une preuve HTML');
// =========================================================================
// LE DÉFAUT REPRODUIT ICI, ET IL S'EST PRODUIT EN DIRECT LE 2026-08-01. Importer::
// setup() appliquait la chaîne de preuve du SITE à toute sonde qui n'en avait pas,
// sans regarder « kind ». La chaîne du site est du texte HTML ; une sonde d'API rend
// quinze octets de JSON. Cette chaîne ne peut JAMAIS s'y trouver : la sonde n'est pas
// fragile, elle est condamnée à tomber en panne dès que la file de préparation
// l'atteint. Six sondes saines sont passées en PANNE quinze minutes après une pose
// vérifiée sans une seule fausse alerte.
//
// Le contrôle se fait en DEUX temps, parce que la première correction en production
// n'avait fait que le premier et s'est défaite toute seule : vider la chaîne ne suffit
// pas si la sonde reste dans la file de préparation, qui la repose au passage suivant.
$monApi = Uptimeez\Db::insert('monitors', [
    'site_id' => $sid, 'name' => 'API JSON e2e', 'url' => "$SITE/api.php", 'kind' => 'api',
    'role' => 'secondary', 'method' => 'GET', 'interval_sec' => 900, 'timeout_sec' => 10,
    'retries' => 0, 'slow_ms' => 9000, 'expect_status' => '200-299',
    'json_path' => 'status', 'json_expect' => '', 'expect_string' => null,
    'check_ssl' => 0, 'check_css' => 0, 'check_db' => 0, 'check_noindex' => 0,
    'follow_redirects' => 1, 'enabled' => 1, 'status' => 'unknown',
    'setup_state' => 'pending', 'created_at' => now(), 'next_check_at' => now(),
]);
$siteProof = (string)$val('SELECT expect_string FROM sites WHERE id = ?', [$sid]);
ok('le site porte bien une chaîne de preuve HTML, sinon ce contrôle ne prouve rien',
   trim($siteProof) !== '', 'chaîne du site : « ' . $siteProof . ' »');

$rr = $req('/api.php?action=setup', ['csrf' => $tok, 'id' => $monApi]);
ok('la préparation de la sonde d\'API aboutit', $rr['code'] === 200 && $noPhpError($rr),
   'HTTP ' . $rr['code']);
$apres = Uptimeez\Db::one('SELECT expect_string, setup_state FROM monitors WHERE id = ?', [$monApi]);
$posee = trim((string)($apres['expect_string'] ?? ''));
ok('aucune chaîne de preuve textuelle posée sur une sonde d\'API', $posee === '',
   $posee === '' ? '' : 'reçu « ' . $posee . ' » : STRING_MISSING garanti à la passe suivante');
ok('et la sonde sort de la file de préparation',
   (string)($apres['setup_state'] ?? '') === 'done', (string)($apres['setup_state'] ?? '?'));
$siteApres = (string)$val('SELECT expect_string FROM sites WHERE id = ?', [$sid]);
ok('la chaîne du SITE n\'a pas été effacée au passage', $siteApres === $siteProof,
   $siteApres === $siteProof ? '' : 'préparer une sonde d\'API ne doit rien retirer aux autres sondes du site');

// Le second temps : la sonde est remise dans la file, comme les 85 sondes du parc
// réel l'étaient. La passe de cron doit la préparer SANS lui reposer la chaîne.
Uptimeez\Db::q('UPDATE monitors SET setup_state = ? WHERE id = ?', ['pending', $monApi]);
shell_exec('UPTIMEEZ_CONFIG=' . escapeshellarg($cfgFile) . ' '
    . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($ROOT . '/cron.php') . ' --setup 2>&1');
$apres2 = Uptimeez\Db::one('SELECT expect_string, setup_state FROM monitors WHERE id = ?', [$monApi]);
$reposee = trim((string)($apres2['expect_string'] ?? ''));
ok('la file de préparation ne repose pas la chaîne au passage suivant', $reposee === '',
   $reposee === '' ? '' : 'reçu « ' . $reposee
     . ' » : la correction se défait toute seule, c\'est ce qui s\'est passé en production');

// Et la sonde doit être VERTE : c'est la preuve utile, le reste n'est que de la
// donnée. Son json_path est sa preuve, et il suffit.
$rr = $req('/api.php?action=check', ['csrf' => $tok, 'id' => $monApi]);
$j  = json_decode($rr['body'], true);
ok('la sonde d\'API est verte, sa preuve étant son chemin JSON',
   ($j['result']['state'] ?? '?') === 'up',
   'état=' . ($j['result']['state'] ?? '?') . ' cause=' . ($j['result']['reason'] ?? '—'));
Uptimeez\Db::q('DELETE FROM monitors WHERE id = ?', [$monApi]);

// =========================================================================
title('Page trop volumineuse : une lecture partielle ne conclut rien');
// =========================================================================
// Http lit au plus 3 Mo. Au-delà, le corps est coupé et la fin de la page n'est
// pas lue. Le drapeau existait depuis toujours et personne ne le lisait : une
// chaîne de contrôle placée en pied de page était donc déclarée absente, ce qui
// veut dire « la base de données ne répond plus ». Fausse panne permanente.
$big1 = Uptimeez\Http::fetch("$SITE/enorme.php", ['timeout' => 30]);
$big2 = Uptimeez\Http::fetch("$SITE/enorme.php", ['timeout' => 30]);
ok('la page dépasse la lecture maximale', $big1->truncated, human_bytes(strlen($big1->body)));
ok('le corps est coupé exactement à la borne', strlen($big1->body) === Uptimeez\Http::MAX_BODY,
   number_format(strlen($big1->body), 0, ',', ' ') . ' octets');
// Le point qui compte : deux lectures de la même page, servie en blocs de
// tailles différentes, donnent le même corps. Sinon l'empreinte de contenu
// change à chaque passe et « le contenu a changé » se déclenche pour rien.
ok('deux lectures de la même page donnent le même corps',
   strlen($big1->body) === strlen($big2->body) && $big1->body === $big2->body,
   strlen($big1->body) . ' vs ' . strlen($big2->body));
ok('et donc la même empreinte de contenu',
   Uptimeez\Runner::contentHash($big1->body) === Uptimeez\Runner::contentHash($big2->body));

// Puis le verdict : la sonde ne doit pas déclarer de panne sur cette base.
$tokB = $csrf();
$req('/index.php?p=import', ['csrf' => $tokB, 'action' => 'import',
    'list' => "$SITE/enorme.php | Page enorme | Mentions legales 2026",
    'interval_sec' => 3600, 'pages' => 1, 'check_css' => 0, 'check_db' => 0,
    'check_ssl' => 0, 'check_noindex' => 0]);
$bid = (int)$val("SELECT id FROM monitors WHERE url LIKE '%enorme.php%' LIMIT 1");
if ($bid) {
    // On isole le contrôle : l'analyse CSS d'une page de 3 Mo sans feuille de
    // style produit son propre verdict, qui masquerait celui qu'on mesure ici.
    Uptimeez\Db::q('UPDATE monitors SET expect_string = ?, setup_state = ?, check_css = 0,
                   check_content = 1, css_state = NULL WHERE id = ?',
          ['Mentions legales 2026', 'done', $bid]);
    $rr = $req('/api.php?action=check', ['csrf' => $tokB, 'id' => $bid]);
    $j  = json_decode($rr['body'], true);
    $st = $j['result']['state'] ?? '?';
    $rs = $j['result']['reason'] ?? '';
    ok('aucune panne inventée sur une page coupée', $st !== 'down', 'état=' . $st . ' cause=' . $rs);
    ok('et la raison est nommée', $rs === 'BODY_TRUNCATED' || $st === 'up', $rs ?: 'aucune');
    $req('/index.php?p=monitors', ['csrf' => $tokB, 'action' => 'delete_monitor', 'id' => $bid]);
} else {
    ok('page énorme importée', false, 'sonde non créée');
}

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
title('Anglais par défaut : plus un mot de français dans les écrans');
// =========================================================================
// La promesse du produit est l'anglais par défaut. Un « aucune » ou un « est »
// au milieu d'une page anglaise se voit tout de suite, et c'est ce qui restait
// après la traduction des chaînes évidentes : verdicts composés, libellés
// passés par variable, phrases coupées autour d'une balise.
$frenchWords = '~\b(?:est|sont|pas|pour|avec|dans|aucune|aucun|votre|cette|jamais'
             . '|déjà|sonde|sondes|réglages|chaîne|vérifier|vérifié)\b~iu';
$leftovers = [];
foreach (['today', 'dashboard', 'monitors', 'incidents', 'events', 'report',
          'settings', 'import', 'clients'] as $p) {
    $rr = $req('/index.php?p=' . $p . '&lang=en&ui=expert');
    // On ne juge que le texte visible : scripts, styles, SVG et listes de
    // langues (où « Français » est le nom de la langue) sont écartés.
    $body = (string)preg_replace('~<script.*?</script>|<style.*?</style>|<svg.*?</svg>'
        . '|<option[^>]*>.*?</option>~is', ' ', $rr['body']);
    $text = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (preg_match_all($frenchWords, $text, $mm, PREG_OFFSET_CAPTURE)) {
        $ctx = [];
        foreach (array_slice($mm[0], 0, 2) as [$w, $at]) {
            $ctx[] = trim(preg_replace('~\s+~', ' ',
                mb_strcut($text, max(0, $at - 60), 120)));
        }
        $leftovers[] = $p . ' : ' . implode(' ⟂ ', $ctx);
    }
}
ok('aucun mot français dans les écrans en anglais', $leftovers === [],
    implode(' | ', array_slice($leftovers, 0, 3)));

// Et l'inverse : la version française ne doit pas se remplir d'anglais.
$rr = $req('/index.php?p=today&lang=fr&ui=expert');
ok('la version française reste française',
    $has($rr, 'À traiter d&#039;abord') || $has($rr, 'Rien à faire'));

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
title('Pont d\'authentification : ouvrir sans mot de passe');
// =========================================================================
// Ce parcours a trouvé un défaut que le banc d'essai ne pouvait pas voir : le
// pont exige un stockage pour garantir l'usage unique, or l'écran de connexion
// s'affiche AVANT la migration du schéma. Sur une instance fraîche, l'écriture
// échouait et le jeton était refusé : le pont ne fonctionnait pas du tout en
// vrai, alors que les vingt contrôles unitaires passaient.
$jar2 = $tmp . '/cookies-pont.txt';
$reqPont = function (string $path) use ($APP, $jar2): array {
    $ch = curl_init($APP . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
                            CURLOPT_COOKIEJAR => $jar2, CURLOPT_COOKIEFILE => $jar2,
                            CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30]);
    $raw  = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hlen = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $head = substr($raw, 0, $hlen);
    return ['code' => $code, 'body' => substr($raw, $hlen),
            'location' => preg_match('~^location:\s*(\S+)~im', $head, $m) ? trim($m[1]) : null];
};

// L'émetteur est un AUTRE programme que l'instance : ici le banc joue son rôle,
// et il doit donc connaître le secret partagé, comme le fera la coque SaaS. La
// configuration du banc n'est pas celle du serveur qu'il a lancé.
Uptimeez\Config::set('auth.bridge_secret', $BRIDGE);
$jeton = Uptimeez\Auth::makeToken(60);
ok('un jeton se fabrique côté émetteur', is_string($jeton) && substr_count((string)$jeton, '.') === 2);

$r = $reqPont('/index.php?p=login&t=' . urlencode((string)$jeton));
ok('le lien d\'accès redirige au lieu d\'afficher la connexion',
   $r['code'] === 302 && str_contains((string)$r['location'], 'p=today'),
   'HTTP ' . $r['code'] . ' → ' . (string)$r['location']);
$r = $reqPont('/index.php');
ok('et la session est réellement ouverte', $r['code'] === 200 && str_contains($r['body'], '</html>'),
   'HTTP ' . $r['code']);

// Le point qui compte : le jeton ne repasse pas. Un jeton capturé dans un
// journal d'accès ou dans un référent ne doit pas rouvrir la session.
$r = $reqPont('/index.php?p=logout');
$r = $reqPont('/index.php?p=login&t=' . urlencode((string)$jeton));
ok('le rejeu du même jeton est refusé',
   $r['code'] === 200 && !str_contains((string)$r['location'] ?? '', 'p=today'), 'HTTP ' . $r['code']);
$r = $reqPont('/index.php');
ok('et la session reste fermée', $r['code'] === 302, 'HTTP ' . $r['code']);

$r = $reqPont('/index.php?p=login&t=' . urlencode('v1.eyJhIjoxfQ.faux'));
ok('un jeton fabriqué est refusé', $r['code'] === 200 && $r['location'] === null, 'HTTP ' . $r['code']);
$r = $reqPont('/index.php?p=login');
ok('sans jeton, aucun message d\'erreur parasite',
   !str_contains($r['body'], 'invalide ou expir'));

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

        // LA BRANCHE EN CACHE, ÉPROUVÉE DE BOUT EN BOUT. Une inspection TLS ne se refait
        // pas à chaque passe : en deçà de six heures on relit les colonnes en base. C'est
        // cette branche-là qui portait sa propre copie des verdicts, et qui avait divergé
        // de l'autre. Aucun contrôle ne la traversait, ni ici ni dans le selftest, parce
        // qu'il aurait fallu un certificat sur le point d'expirer. On n'en fabrique pas :
        // on écrit le compte à rebours en base, ce qui est EXACTEMENT ce que la branche
        // relit, et la branche ne sait pas d'où vient ce qu'elle lit.
        //
        // IL FAUT UNE PASSE PLANIFIÉE, PAS UNE VÉRIFICATION MANUELLE. Un déclenchement
        // humain force la réouverture de la connexion TLS, donc emprunte l'autre branche
        // et écrase le compte à rebours qu'on vient d'écrire. La première version de ce
        // contrôle est tombée dedans et rendait « causes=— » : elle mesurait la branche
        // qu'elle croyait éviter.
        Uptimeez\Db::update('monitors', [
            'ssl_days_left'  => 5,
            'ssl_checked_at' => date('Y-m-d H:i:s'),
            'ssl_warn_days'  => 30,
            'next_check_at'  => date('Y-m-d H:i:s', time() - 3600),
        ], 'id = :__id', ['__id' => $rid]);

        $req('/cron.php?key=cle-e2e');

        $dernier = $db('SELECT state, reason_code, details FROM checks WHERE monitor_id = ?
                        ORDER BY id DESC LIMIT 1', [$rid])[0] ?? [];
        $traces = ((string)($dernier['reason_code'] ?? '')) . ' ' . ((string)($dernier['details'] ?? ''));

        ok('un certificat proche de l\'expiration est signalé depuis le cache',
            str_contains($traces, 'SSL_SOON'),
            'cause=' . (($dernier['reason_code'] ?? '') ?: '—'));
        // ET IL EST DÉGRADÉ, PAS HORS SERVICE : le site fonctionne, il fonctionnera encore
        // demain. Confondre les deux réveille quelqu'un la nuit pour un renouvellement.
        ok('et une expiration proche ne met pas le site hors service',
            ($dernier['state'] ?? '?') !== 'down', 'état=' . ($dernier['state'] ?? '?'));
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
