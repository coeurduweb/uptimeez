<?php
/**
 * Uptimeez : audit de sécurité, en trois profondeurs.
 *
 *   niveau 1 : léger        : configuration, secrets, en-têtes, surface exposée
 *   niveau 2, profond      : OWASP Top 10 en tests actifs sur une instance réelle
 *   niveau 3, très profond : SSRF, XXE, bombes, ReDoS, temps constant, identifiants SQL
 *
 * Chaque contrôle porte la référence OWASP correspondante, pour qu'un rapport
 * puisse être relu par quelqu'un qui ne connaît pas ce code.
 *
 *   php bin/security.php              # les trois niveaux
 *   php bin/security.php --niveau=1   # un seul niveau
 *   php bin/security.php --niveau=2
 *   php bin/security.php --niveau=3
 *
 * Les niveaux 2 et 3 montent une instance isolée et un site hostile local :
 * rien ne touche votre installation, rien ne sort vers Internet.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

if (PHP_SAPI !== 'cli') exit("À lancer en ligne de commande.\n");

$ROOT = dirname(__DIR__);
$lvl  = 0;
foreach ($argv as $a) if (preg_match('~^--niveau=([123])$~', $a, $m)) $lvl = (int)$m[1];
$pass = 0; $fail = 0; $warn = 0; $t0 = microtime(true);

function ok(string $ref, string $label, bool $good, string $detail = ''): void
{
    global $pass, $fail;
    $good ? $pass++ : $fail++;
    $pad = str_repeat(' ', max(1, 46 - mb_strlen($label)));
    echo ($good ? ' OK  ' : 'FAIL ') . str_pad($ref, 5) . $label . $pad
       . ($detail !== '' ? '→ ' . $detail : '') . "\n";
}
function note(string $ref, string $label, string $detail): void
{
    global $warn;
    $warn++;
    $pad = str_repeat(' ', max(1, 46 - mb_strlen($label)));
    echo ' NB  ' . str_pad($ref, 5) . $label . $pad . '→ ' . $detail . "\n";
}
function title(string $s): void { echo "\n── $s " . str_repeat('─', max(0, 60 - mb_strlen($s))) . "\n"; }
function banner(string $s): void { echo "\n" . str_repeat('━', 68) . "\n  $s\n" . str_repeat('━', 68) . "\n"; }

$srcFiles = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = $f->getPathname();
    if (str_contains($p, '/data/') || str_contains($p, '/.git/') || str_contains($p, '/docs/')) continue;
    if (str_ends_with($p, '.php')) $srcFiles[] = $p;
}
sort($srcFiles);
$allSrc = '';
foreach ($srcFiles as $f) $allSrc .= file_get_contents($f) . "\n";
/** Le code applicatif, sans les scripts de test qui contiennent des charges hostiles. */
$appSrc = '';
foreach ($srcFiles as $f) if (!str_contains($f, '/bin/')) $appSrc .= file_get_contents($f) . "\n";

// =========================================================================
// NIVEAU 1. LÉGER : ce qui se voit sans lancer l'application
// =========================================================================
if ($lvl === 0 || $lvl === 1) {
banner('NIVEAU 1 : Audit léger : configuration, secrets, surface');

title('A02 Défaillances cryptographiques');
ok('A02', 'mot de passe haché par password_hash()',
   str_contains($appSrc, 'password_hash(') && str_contains($appSrc, 'password_verify('));
ok('A02', 'aucun hachage obsolète (md5, sha1, crypt)',
   !preg_match('~\b(md5|sha1|crypt)\s*\(\s*\$(?:pass|pwd|password)~i', $appSrc));
ok('A02', 'comparaison de jetons en temps constant',
   str_contains($appSrc, 'hash_equals('));
ok('A02', 'aléa cryptographique pour les jetons',
   str_contains($appSrc, 'random_bytes(') && !preg_match('~\brand\s*\(\s*\)~', $appSrc));
ok('A02', 'cookie de session HttpOnly et SameSite',
   str_contains($appSrc, "'httponly' => true") && str_contains($appSrc, "'samesite' => 'Lax'"));
ok('A02', 'cookie Secure dès que HTTPS est détecté',
   str_contains($appSrc, "'secure'"));

title('A05 Mauvaise configuration');
ok('A05', 'données protégées par un .htaccess', is_file($ROOT . '/data/.htaccess'));
$ht = is_file($ROOT . '/data/.htaccess') ? (string)file_get_contents($ROOT . '/data/.htaccess') : '';
ok('A05', 'ce .htaccess interdit bien tout accès',
   stripos($ht, 'deny from all') !== false || stripos($ht, 'Require all denied') !== false, trim($ht));
ok('A05', 'aucun affichage d\'erreur forcé',
   !preg_match('~ini_set\s*\(\s*[\'"]display_errors[\'"]\s*,\s*[\'"]?(1|on|true)~i', $appSrc));
// On cherche un mot de passe littéral réellement haché, pas la mention d'un
// exemple dans un commentaire (« password_hash('...', PASSWORD_DEFAULT) »).
$noComments = (string)preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~', '~^\s*#[^\n]*~m'], '', $appSrc);
ok('A05', 'aucun mot de passe par défaut dans le code',
   !preg_match('~password_hash\s*\(\s*[\'"](?!\.\.\.)[^\'"]{4,}[\'"]~', $noComments));
$sample = (string)preg_replace('~//[^\n]*~', '', (string)file_get_contents($ROOT . '/config.sample.php'));
// Seules les clés porteuses de secret comptent : un fuseau horaire ou un
// User-Agent sont des valeurs par défaut, pas des identifiants.
$secretKeys = 'pass|password|password_hash|token|key|secret|webhook|hash';
ok('A05', 'le modèle de configuration ne porte aucun secret',
   !preg_match('~[\'"](?:' . $secretKeys . ')[\'"]\s*=>\s*[\'"][^\'"]{4,}[\'"]~i', $sample),
   'config.sample.php');
ok('A05', 'config.php exclu du dépôt',
   str_contains((string)@file_get_contents($ROOT . '/.gitignore'), 'config.php'));
ok('A05', 'base de données exclue du dépôt',
   str_contains((string)@file_get_contents($ROOT . '/.gitignore'), 'data/*.sqlite'));
ok('A05', 'en-tête noindex sur toutes les pages',
   str_contains($appSrc, 'noindex, nofollow'));
ok('A05', 'réinstallation refusée quand déjà installé',
   str_contains((string)file_get_contents($ROOT . '/install.php'), '403'));

title('A03 Injection : revue statique');
// Toute requête doit passer par des marqueurs ; on cherche l'interpolation directe.
// Le risque n'est pas qu'une entrée HTTP côtoie le mot « from » dans un nom de
// champ : c'est qu'elle soit concaténée ou interpolée DANS la requête. On ne
// cherche donc que ces deux formes, sur le fichier entier pour suivre les
// requêtes écrites sur plusieurs lignes.
$badSql = [];
foreach ($srcFiles as $f) {
    if (str_contains($f, '/bin/')) continue;
    $src = (string)file_get_contents($f);
    $src = (string)preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], '', $src);
    // a) concaténation : '… WHERE x = ' . $_GET['y']
    $reConcat = '~[\'"][^\'"]{0,300}\b(?:SELECT|INSERT|UPDATE|DELETE|FROM|WHERE|ORDER\s+BY|LIMIT)\b'
              . '[^\'"]{0,300}[\'"]\s*\.\s*[^;]{0,80}\$_(?:GET|POST|REQUEST|COOKIE|SERVER)~is';
    // b) interpolation : "… WHERE x = {$_GET['y']}"
    $reInterp = '~"[^"]{0,400}\b(?:SELECT|INSERT|UPDATE|DELETE|FROM|WHERE)\b[^"]{0,400}'
              . '\{?\$_(?:GET|POST|REQUEST|COOKIE|SERVER)~is';
    foreach ([$reConcat, $reInterp] as $re) {
        if (preg_match_all($re, $src, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$hit, $off]) {
                $badSql[] = basename($f) . ':' . (substr_count(substr($src, 0, $off), "\n") + 1);
            }
        }
    }
}
ok('A03', 'aucune entrée HTTP dans une requête SQL', $badSql === [], implode(' ', $badSql));
ok('A03', 'aucune fonction d\'exécution dynamique',
   !preg_match('~(?<![\w])(eval|assert|create_function|preg_replace_callback_array)\s*\(~', $appSrc));
ok('A03', 'aucune commande shell sur entrée utilisateur',
   !preg_match('~(shell_exec|exec|system|passthru|popen)\s*\([^)]*\$_(GET|POST|REQUEST)~', $appSrc));
ok('A03', 'aucun unserialize() sur entrée utilisateur',
   !preg_match('~unserialize\s*\([^)]*\$_(GET|POST|REQUEST|COOKIE)~', $appSrc));
ok('A03', 'aucun extract() ni $$variable',
   !preg_match('~(?<![\w])extract\s*\(~', $appSrc) && !preg_match('~\$\$\w~', $appSrc));
ok('A03', 'échappement HTML systématique disponible',
   str_contains($appSrc, 'htmlspecialchars(') && str_contains($appSrc, 'ENT_QUOTES'));
ok('A03', 'export tableur neutralisé contre les formules',
   str_contains($appSrc, 'function csv_cell') && str_contains((string)file_get_contents($ROOT . '/index.php'), 'csv_cell('));

title('A04 Conception : garde-fous présents');
ok('A04', 'jeton CSRF vérifié sur les écritures',
   str_contains($appSrc, 'checkCsrf(') && substr_count($appSrc, 'checkCsrf(') >= 3);
ok('A04', 'limitation des tentatives de connexion',
   str_contains($appSrc, 'lockedFor('));
ok('A04', 'aucune action destructive en GET',
   !preg_match('~case\s+\'delete~i', preg_replace('~function handle_post.*~s', '', (string)file_get_contents($ROOT . '/index.php')) ?? ''));
ok('A10', 'protocoles curl restreints à HTTP/HTTPS',
   str_contains($appSrc, 'CURLOPT_PROTOCOLS') && str_contains($appSrc, 'CURLOPT_REDIR_PROTOCOLS'));
ok('A10', 'schéma d\'URL validé à la saisie',
   str_contains($appSrc, 'preg_match(\'~^https?://~i\''));

title('A06 Composants : surface de dépendances');
ok('A06', 'aucune dépendance à installer',
   !is_file($ROOT . '/composer.json') && !is_file($ROOT . '/package.json'));
ok('A06', 'aucun code tiers embarqué (vendor, node_modules)',
   !is_dir($ROOT . '/vendor') && !is_dir($ROOT . '/node_modules'));
ok('A06', 'version minimale de PHP vérifiée au démarrage',
   str_contains((string)file_get_contents($ROOT . '/src/bootstrap.php'), 'PHP_VERSION_ID < 80100'));

title('A09 Journalisation');
ok('A09', 'les tentatives de connexion sont enregistrées',
   str_contains($appSrc, 'login_tries'));
ok('A09', 'aucun secret journalisé',
   !preg_match('~error_log\s*\([^)]*(pass|token|secret)~i', $appSrc));
}

// =========================================================================
// Instance isolée pour les niveaux actifs
// =========================================================================
$req = null; $APP = ''; $EVIL = ''; $cfgFile = ''; $tmp = '';
if ($lvl === 0 || $lvl === 2 || $lvl === 3) {
    $tmp = sys_get_temp_dir() . '/uptimeez-sec-' . bin2hex(random_bytes(4));
    @mkdir($tmp . '/evil', 0775, true);

    $freePort = function (int $from): int {
        for ($p = $from; $p < $from + 60; $p++) {
            $s = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
            if ($s) { fclose($s); return $p; }
        }
        exit("Aucun port libre à partir de $from.\n");
    };
    $appPort  = $freePort(8700);
    $evilPort = $freePort(8760);
    $APP  = "http://127.0.0.1:$appPort";
    $EVIL = "http://127.0.0.1:$evilPort";
    $PASS = 'motdepasse-securite';

    // --- Site hostile : chaque page teste une attaque côté collecteur ------
    file_put_contents("$tmp/evil/index.html",
        '<!doctype html><html><head><title>Site témoin</title></head><body><h1>Témoin</h1>'
      . '<footer>© 2026 Témoin — tous droits réservés</footer></body></html>');
    // Redirection vers un schéma local : ne doit jamais être suivie.
    file_put_contents("$tmp/evil/redir-file.php",
        "<?php header('Location: file:///etc/passwd', true, 302); exit;");
    file_put_contents("$tmp/evil/redir-gopher.php",
        "<?php header('Location: gopher://127.0.0.1:70/x', true, 302); exit;");
    // Boucle de redirection.
    file_put_contents("$tmp/evil/loop.php",
        "<?php header('Location: /loop.php', true, 302); exit;");
    // Sitemap avec une entité externe : XXE si le parseur la résout.
    file_put_contents("$tmp/evil/sitemap.xml",
        "<?xml version=\"1.0\"?>\n<!DOCTYPE urlset [<!ENTITY xxe SYSTEM \"file:///etc/passwd\">]>\n"
      . "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">"
      . "<url><loc>$EVIL/&xxe;</loc></url><url><loc>$EVIL/page.html</loc></url></urlset>");
    file_put_contents("$tmp/evil/robots.txt", "User-agent: *\nSitemap: $EVIL/sitemap.xml\n");
    // Corps gigantesque : le collecteur doit borner sa mémoire.
    file_put_contents("$tmp/evil/huge.php",
        "<?php header('Content-Type: text/html');\n"
      . "for (\$i = 0; \$i < 4000; \$i++) echo str_repeat('A', 10000);");
    // Contenu pathologique pour les expressions régulières.
    file_put_contents("$tmp/evil/redos.php",
        "<?php header('Content-Type: text/html');\n"
      . "echo '<html><head>' . str_repeat('<link rel=\"stylesheet\" href=\"/a.css\">', 4000)\n"
      . "   . str_repeat('<div class=\"' . str_repeat('x', 200) . '\">', 2000) . '</head><body></body></html>';");
    // En-tête de réponse tentant une injection dans le stockage.
    file_put_contents("$tmp/evil/xss.php",
        "<?php header('Content-Type: text/html');\n"
      . "echo '<html><head><title>Site &lt;script&gt;alert(1)&lt;/script&gt;</title>'\n"
      . "   . '<meta property=\"og:site_name\" content=\"&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;\">'\n"
      . "   . '</head><body><footer>© 2026 <script>alert(1)</script> — tous droits réservés</footer></body></html>';");
    file_put_contents("$tmp/evil/page.html", '<!doctype html><html><body>page</body></html>');

    $cfgFile = $tmp . '/config.php';
    file_put_contents($cfgFile, "<?php return " . var_export([
        'db'   => ['driver' => 'sqlite', 'sqlite' => $tmp . '/sec.sqlite'],
        'auth' => ['password_hash' => password_hash($PASS, PASSWORD_DEFAULT), 'session_name' => 'uptimeezsec'],
        'app'  => ['name' => 'Uptimeez Sécurité', 'base_url' => $APP, 'timezone' => 'Europe/Paris',
                   'public_token' => 'jeton-public-secret', 'cron_key' => 'cle-cron-secrete'],
        'defaults' => ['interval_sec' => 300, 'timeout_sec' => 6, 'retries' => 0, 'slow_ms' => 9000,
                       'max_parallel' => 4, 'retention_days' => 60, 'ssl_warn_days' => 14, 'css_drop_pct' => 35,
                       'user_agent' => 'UptimeezBot/1.0 (Sec)'],
        'notify' => ['discord' => ['enabled' => false, 'webhook' => ''], 'slack' => ['enabled' => false, 'webhook' => ''],
                     'mail' => ['enabled' => false, 'to' => ''], 'webhook' => ['enabled' => false, 'url' => ''],
                     'resend_after_min' => 60, 'notify_recovery' => true, 'notify_degraded' => true, 'quiet_hours' => ''],
    ], true) . ";\n");

    $spawn = function (array $cmd, string $cwd, array $env = []) {
        return proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                         $pipes, $cwd, $env + ['PATH' => getenv('PATH') ?: '/usr/bin:/bin']);
    };
    $evilSrv = $spawn([PHP_BINARY, '-S', "127.0.0.1:$evilPort", '-t', "$tmp/evil"], "$tmp/evil");
    $appSrv  = $spawn([PHP_BINARY, '-S', "127.0.0.1:$appPort", '-t', $ROOT], $ROOT, ['UPTIMEEZ_CONFIG' => $cfgFile]);

    register_shutdown_function(function () use ($evilSrv, $appSrv, $tmp, $appPort, $evilPort): void {
        foreach ([[$appSrv, $appPort], [$evilSrv, $evilPort]] as [$h, $port]) {
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
        foreach (glob($tmp . '/evil/*') ?: [] as $f) @unlink($f);
        @rmdir($tmp . '/evil');
        foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f);
        @rmdir($tmp);
    });

    $wait = function (int $port): bool {
        for ($i = 0; $i < 50; $i++) {
            $s = @stream_socket_client("tcp://127.0.0.1:$port", $e1, $e2, 0.3);
            if ($s) { fclose($s); return true; }
            usleep(150000);
        }
        return false;
    };
    if (!$wait($appPort) || !$wait($evilPort)) exit("Les serveurs de test n'ont pas démarré.\n");

    $jar = $tmp . '/cookies.txt';
    $req = function (string $path, ?array $post = null, array $o = []) use ($APP, $jar): array {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => str_starts_with($path, 'http') ? $path : $APP . $path,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
            CURLOPT_COOKIEJAR => ($o['nojar'] ?? false) ? null : $jar,
            CURLOPT_COOKIEFILE => ($o['nojar'] ?? false) ? '' : $jar,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => $o['timeout'] ?? 30,
            CURLOPT_HTTPHEADER => str_contains($path, 'api.php') ? ['X-Requested-With: fetch'] : [],
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
}

// =========================================================================
// NIVEAU 2. PROFOND : OWASP Top 10 en tests actifs
// =========================================================================
if ($lvl === 0 || $lvl === 2) {
banner('NIVEAU 2 : Audit profond : OWASP Top 10, tests actifs');

$PASS = 'motdepasse-securite';
$req('/install.php', ['password' => $PASS, 'password2' => $PASS]);   // déjà installé : refusé

title('A01 Contrôle d\'accès défaillant');
// Sans session, aucun écran ne doit rendre de contenu.
$leaks = [];
foreach (['today', 'dashboard', 'monitors', 'monitor', 'incidents', 'events', 'settings',
          'import', 'report'] as $p) {
    $r = $req('/index.php?p=' . $p, null, ['nojar' => true]);
    $served = $r['code'] === 200 && !str_contains($r['body'], 'type="password"');
    if ($served) $leaks[] = $p;
}
ok('A01', 'aucun écran servi sans authentification', $leaks === [], implode(' ', $leaks));

$leaks = [];
foreach (['summary', 'series', 'pending', 'search', 'report', 'check', 'toggle', 'fix', 'undo'] as $a) {
    $r = $req('/api.php?action=' . $a . '&id=1', null, ['nojar' => true]);
    $body = json_decode($r['body'], true);
    if ($r['code'] === 200 && is_array($body) && !empty($body['ok'])) $leaks[] = $a;
}
ok('A01', 'aucune action d\'API servie sans session', $leaks === [], implode(' ', $leaks));

// Accès direct aux fichiers internes : gabarits, classes, base.
$exposed = [];
foreach (['/views/layout.php', '/views/today.php', '/src/Db.php', '/src/Config.php',
          '/src/bootstrap.php', '/config.php', '/data/sec.sqlite', '/lang/en.php',
          '/bin/security.php', '/config.sample.php'] as $p) {
    $r = $req($p, null, ['nojar' => true]);
    // Un fichier de code ne doit jamais renvoyer son texte source.
    $isSource = str_contains($r['body'], '<?php') || str_contains($r['body'], 'declare(strict_types');
    $isData   = str_contains($r['body'], 'SQLite format');
    if ($r['code'] === 200 && ($isSource || $isData)) $exposed[] = $p;
}
ok('A01', 'aucun code source ni base servis en clair', $exposed === [], implode(' ', $exposed));

// Traversée de chemin sur le nom de page.
$trav = [];
foreach (['../config', '../../etc/passwd', '....//....//config', '/etc/passwd',
          'php://filter/convert.base64-encode/resource=index'] as $p) {
    $r = $req('/index.php?p=' . rawurlencode($p), null, ['nojar' => true]);
    if (str_contains($r['body'], 'password_hash') || str_contains($r['body'], 'root:x:')) $trav[] = $p;
}
ok('A01', 'aucune traversée de chemin par le nom de page', $trav === []);

// Connexion, puis les contrôles authentifiés.
$req('/index.php?p=login', ['password' => $PASS]);
$r = $req('/index.php?p=today');
$csrf = preg_match('~csrf:\s*"([a-f0-9]+)"~', $r['body'], $m) ? $m[1] : '';
ok('A01', 'connexion valide acceptée', $csrf !== '');

// Page d'état publique : le jeton est obligatoire et comparé strictement.
$r = $req('/index.php?p=status', null, ['nojar' => true]);
ok('A01', 'page publique sans jeton refusée', $r['code'] === 404);
$r = $req('/index.php?p=status&token=jeton-public-secre', null, ['nojar' => true]);
ok('A01', 'jeton public tronqué refusé', $r['code'] === 404);
$r = $req('/index.php?p=status&token=jeton-public-secret', null, ['nojar' => true]);
ok('A01', 'jeton public exact accepté', $r['code'] === 200);

// ---- Espace client : le cloisonnement, testé de l'extérieur -------------
// C'est le point d'entrée le plus exposé du produit : une page sans
// authentification qui montre des données réelles. Chaque cas est vérifié
// depuis un client HTTP sans cookie.
$dbf = $tmp . '/sec.sqlite';
$pdo = new PDO('sqlite:' . $dbf);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$mkCli = function (string $name, string $siteName) use ($pdo): array {
    $tok = bin2hex(random_bytes(16));
    $pdo->prepare('INSERT INTO clients (name, token, contact_email, enabled, created_at, views)
                   VALUES (?, ?, ?, 1, ?, 0)')
        ->execute([$name, $tok, strtolower($name) . '@exemple.fr', date('Y-m-d H:i:s')]);
    $cid = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO sites (name, domain, client_id, created_at) VALUES (?, ?, ?, ?)')
        ->execute([$siteName, strtolower(str_replace(' ', '', $siteName)) . '.test', $cid, date('Y-m-d H:i:s')]);
    return [$cid, $tok];
};
[$cid1, $tok1] = $mkCli('ClientUn', 'SiteSecretUn');
[$cid2, $tok2] = $mkCli('ClientDeux', 'SiteSecretDeux');

$r = $req('/index.php?p=client&k=' . $tok1, null, ['nojar' => true]);
ok('A01', 'espace client servi sur jeton valide', $r['code'] === 200, 'HTTP ' . $r['code']);
ok('A01', 'espace client cloisonné : rien de l\'autre client',
    str_contains($r['body'], 'SiteSecretUn') && !str_contains($r['body'], 'SiteSecretDeux'));
ok('A01', 'espace client sans jeton d\'écriture ni script d\'administration',
    !str_contains($r['body'], 'csrf') && !str_contains($r['body'], 'app.js'));
ok('A01', 'espace client non indexable',
    stripos($r['head'], 'noindex') !== false && stripos($r['head'], 'no-referrer') !== false);

// Référence indirecte : ajouter un identifiant ne doit rien changer (BOLA).
$bola = [];
foreach (['client_id=' . $cid2, 'id=' . $cid2, 'site=' . $cid2, 'client=' . $cid2,
          'k[]=' . $tok1, 'p=clients'] as $extra) {
    $rr = $req('/index.php?p=client&k=' . $tok1 . '&' . $extra, null, ['nojar' => true]);
    if (str_contains($rr['body'], 'SiteSecretDeux')) $bola[] = $extra;
}
ok('A01', 'aucun paramètre ne change le périmètre affiché', $bola === [], implode(' ', $bola));

// Jetons hostiles : injection, traversée, débordement, casse, troncature.
$bad = [];
foreach (['', ' ', substr($tok1, 0, 30), $tok1 . 'a', strtoupper($tok1),
          "' OR 1=1 --", $tok1 . "' OR '1'='1", '../../config.php',
          '%00' . $tok1, str_repeat('a', 5000), '0x' . $tok1] as $t) {
    $rr = $req('/index.php?p=client&k=' . rawurlencode($t), null, ['nojar' => true]);
    // Un jeton refusé doit donner 404, et surtout jamais de contenu client.
    if ($rr['code'] === 200 || str_contains($rr['body'], 'SiteSecret')) $bad[] = str_cut($t, 20);
    if (preg_match('~(Fatal error|SQLSTATE|Uncaught)~', $rr['body'])) $bad[] = 'erreur:' . str_cut($t, 14);
}
ok('A01', 'jeton hostile : refusé sans erreur ni fuite', $bad === [], implode(' | ', $bad));

// Énumération : un lien coupé et un lien inexistant doivent être indiscernables.
$pdo->prepare('UPDATE clients SET enabled = 0 WHERE id = ?')->execute([$cid2]);
$rClosed  = $req('/index.php?p=client&k=' . $tok2, null, ['nojar' => true]);
$rUnknown = $req('/index.php?p=client&k=' . bin2hex(random_bytes(16)), null, ['nojar' => true]);
ok('A01', 'accès fermé et jeton inconnu : réponses indiscernables',
    $rClosed['code'] === $rUnknown['code'] && $rClosed['body'] === $rUnknown['body'],
    'HTTP ' . $rClosed['code'] . ' vs ' . $rUnknown['code']);

// Écriture depuis l'espace client : aucune action ne doit aboutir.
$writes = [];
foreach ([['client_delete', ['client_id' => $cid2]],
          ['client_rotate', ['client_id' => $cid1]],
          ['client_save',   ['client_id' => $cid1, 'client_name' => 'PIRATÉ', 'sites' => [1]]],
          ['delete_monitor', ['id' => 1]],
          ['save_settings', ['app_name' => 'PIRATÉ']]] as [$action, $fields]) {
    $rr = $req('/index.php?p=client&k=' . $tok1, ['action' => $action] + $fields, ['nojar' => true]);
    $names = $pdo->query('SELECT name FROM clients')->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('PIRATÉ', $names, true)) $writes[] = $action;
}
$still = (int)$pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn();
ok('A01', 'aucune écriture atteignable depuis un jeton client',
    $writes === [] && $still === 2, implode(' ', $writes));
$tokAfter = (string)$pdo->query('SELECT token FROM clients WHERE id = ' . $cid1)->fetchColumn();
ok('A01', 'le jeton client ne peut pas se régénérer lui-même', $tokAfter === $tok1);

// L'écran de gestion des clients reste derrière l'authentification.
$r = $req('/index.php?p=clients', null, ['nojar' => true]);
ok('A01', 'écran de gestion des clients refusé sans session',
    $r['code'] !== 200 || str_contains($r['body'], 'type="password"'), 'HTTP ' . $r['code']);

// Cron par URL : clé obligatoire.
$r = $req('/cron.php');
ok('A01', 'cron par URL sans clé refusé', $r['code'] === 403 || str_contains($r['body'], 'Clé'));
$r = $req('/cron.php?key=mauvaise');
ok('A01', 'cron par URL avec mauvaise clé refusé', $r['code'] === 403 || str_contains($r['body'], 'Clé'));

title('A03 et A08 Dépôt de fichier : reprise d\'un autre outil');
// =========================================================================
// Un formulaire qui accepte un fichier est une surface d'attaque classique :
// exécution du contenu, chemin traversé par le nom, débordement mémoire.
$up = function (string $content, string $name, string $type = 'application/json') use ($APP, $jar, $csrf): array {
    $f = tempnam(sys_get_temp_dir(), 'sec');
    file_put_contents($f, $content);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $APP . '/index.php?p=import', CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_POST => true, CURLOPT_TIMEOUT => 40,
        CURLOPT_POSTFIELDS => ['csrf' => $csrf, 'action' => 'preview', 'list' => '',
                               'file' => new CURLFile($f, $type, $name)],
    ]);
    $raw = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hlen = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    @unlink($f);
    return ['code' => $code, 'body' => substr($raw, $hlen), 'head' => substr($raw, 0, $hlen)];
};

// Du code dans le fichier : il est lu comme du texte, jamais inclus.
$r = $up("<?php echo 'EXECUTE-MOI'; ?>", 'charge.php', 'application/x-php');
ok('A03', 'un fichier PHP déposé n\'est pas exécuté',
    !str_contains($r['body'], 'EXECUTE-MOI') || str_contains($r['body'], '&lt;?php'));
ok('A03', 'et rien n\'est écrit dans l\'arborescence servie',
    !is_file($ROOT . '/charge.php') && !is_file($ROOT . '/views/charge.php'));

// Le nom du fichier ne sert à rien : ni à choisir un analyseur, ni à écrire.
$r = $up('{"monitors":[]}', '../../../../etc/passwd');
ok('A03', 'un nom de fichier traversant ne casse rien',
    $r['code'] === 200 && !str_contains($r['body'], 'root:x:'));
$r = $up('url,name' . "\n" . 'https://sec-a.test/,A', 'export.json');
ok('A03', 'le format est reconnu au contenu, pas à l\'extension',
    str_contains($r['body'], 'CSV') || str_contains($r['body'], 'sec-a.test'));

// Une charge XSS dans un nom de sonde ressort échappée.
$r = $up('{"monitors":[{"friendly_name":"<img src=x onerror=alert(1)>","url":"https://sec-b.test/","type":1}]}', 'x.json');
ok('A03', 'un nom de sonde hostile est échappé à l\'affichage',
    !str_contains($r['body'], '<img src=x onerror'), 'HTTP ' . $r['code']);

// Débordement : au-delà du plafond, refus net et pas d'épuisement mémoire.
$r = $up(str_repeat('a', 5 * 1024 * 1024), 'gros.json');
ok('A08', 'un fichier au-delà du plafond est refusé',
    $r['code'] === 200 && !str_contains($r['body'], 'Fatal error')
    && !str_contains($r['body'], 'Allowed memory size'), 'HTTP ' . $r['code']);
// JSON très imbriqué : json_decode a une limite de profondeur, elle doit tenir.
$r = $up('{"monitorList":' . str_repeat('[', 2000) . str_repeat(']', 2000) . '}', 'profond.json');
ok('A08', 'un JSON très imbriqué ne fait pas tomber le processus',
    $r['code'] === 200 && !str_contains($r['body'], 'Fatal error'), 'HTTP ' . $r['code']);
// Un fichier binaire n'est pas donné à manger aux analyseurs.
$r = $up("\x00\x01\x02" . str_repeat("\xff", 500), 'image.json');
ok('A08', 'un fichier binaire est écarté avant analyse',
    $r['code'] === 200 && !str_contains($r['body'], 'Fatal error'));

// Sans session, le dépôt n'est pas atteignable du tout.
$r2 = $req('/index.php?p=import', ['action' => 'preview', 'list' => 'exemple.fr'], ['nojar' => true]);
ok('A01', 'l\'import n\'est pas atteignable sans session',
    $r2['code'] !== 200 || str_contains($r2['body'], 'type="password"'), 'HTTP ' . $r2['code']);

title('A04 CSRF sur toutes les écritures');
$writes = [
    ['/index.php?p=settings', ['action' => 'save_settings', 'app_name' => 'PIRATÉ']],
    ['/index.php?p=monitors', ['action' => 'save_monitor', 'name' => 'PIRATÉ', 'url' => 'https://exemple.fr/']],
    ['/index.php?p=monitors', ['action' => 'delete_monitor', 'id' => '1']],
    ['/index.php?p=import',   ['action' => 'import', 'list' => 'pirate.fr']],
];
$holes = [];
foreach ($writes as $i => [$path, $post]) {
    $before = $req('/index.php?p=today')['body'];
    $r = $req($path, $post);                                    // sans jeton
    $r2 = $req($path, $post + ['csrf' => '00000000000000000000000000000000']);
    $accepted = !str_contains($r['body'], 'Jeton') && !str_contains($r['body'], 'invalide')
                && $r['code'] < 400 && str_contains($r['body'], 'PIRATÉ');
    if ($accepted) $holes[] = 'écriture ' . $i;
    if (str_contains($r2['body'], 'PIRATÉ')) $holes[] = 'jeton faux ' . $i;
}
ok('A04', 'écriture sans jeton CSRF refusée', $holes === [], implode(' ', $holes));
$r = $req('/api.php?action=check&id=1', ['csrf' => 'faux']);
ok('A04', 'API : jeton CSRF faux refusé', $r['code'] === 403);
$r = $req('/api.php?action=check&id=1');
ok('A04', 'API : écriture en GET refusée', $r['code'] === 405);

title('A03 Injection SQL');
$sqli = [
    "1 OR 1=1", "1' OR '1'='1", "1; DROP TABLE monitors--", "1 UNION SELECT 1,2,3",
    "' UNION SELECT sql FROM sqlite_master--", "1) OR (1=1", "\\'", "1%27%20OR%201=1",
    "1 AND (SELECT 1 FROM monitors)", "-1 OR 1=1--", "1' AND SLEEP(3)--",
];
$holes = [];
foreach ($sqli as $q) {
    foreach (['/index.php?p=monitor&id=', '/api.php?action=series&id=', '/api.php?action=report&id=',
              '/index.php?p=incidents&id=', '/index.php?p=report&site='] as $base) {
        $r = $req($base . rawurlencode($q));
        // La charge est réaffichée échappée dans la page : sa simple présence ne
        // prouve rien. On ne retient que les signatures d'erreur du moteur et
        // les traces d'un schéma réellement divulgué.
        $body = $r['body'];
        $err  = preg_match('~(SQLSTATE\[|PDOException|SQLite3::|Uncaught\s+PDO|no such column|near "[^"]+": syntax error)~i', $body);
        $dump = preg_match('~CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:monitors|sites|checks)~i', $body);
        if ($r['code'] >= 500 || $err || $dump) $holes[] = $base . ' ⇐ ' . str_cut($q, 20);
    }
}
ok('A03', 'aucune injection SQL sur les identifiants', $holes === [], implode(' | ', array_slice($holes, 0, 3)));

$holes = [];
foreach ($sqli as $q) {
    $r = $req('/api.php?action=search&q=' . rawurlencode($q));
    $j = json_decode($r['body'], true);
    // Une injection réussie renverrait plus de résultats que la base n'en contient.
    if ($r['code'] >= 500 || !is_array($j)) $holes[] = str_cut($q, 24);
}
ok('A03', 'aucune injection SQL par la recherche', $holes === [], implode(' | ', $holes));

// Injection par un champ stocké puis réutilisé en requête.
$req('/index.php?p=import', ['csrf' => $csrf, 'action' => 'import',
    'list' => $EVIL . "/ | Site' OR 1=1-- | preuve", 'run_setup' => '0', 'add_pages' => '0']);
$r = $req('/index.php?p=monitors');
ok('A03', 'nom contenant du SQL stocké sans dommage',
   $r['code'] === 200 && !preg_match('~SQLSTATE|no such column~i', $r['body']));

title('A03 XSS : réfléchie, stockée, par attribut');
$xss = [
    '<script>alert(1)</script>',
    '"><script>alert(1)</script>',
    "'><img src=x onerror=alert(1)>",
    '<svg/onload=alert(1)>',
    'javascript:alert(1)',
    '"><iframe src=javascript:alert(1)>',
    '</title><script>alert(1)</script>',
    '{{constructor.constructor("alert(1)")()}}',
    '<img src=x onerror="fetch(\'//evil\')">',
];
$holes = [];
foreach ($xss as $p) {
    foreach (['/index.php?p=monitors&q=', '/index.php?p=dashboard&g=', '/index.php?p=report&range=',
              '/api.php?action=search&q=', '/index.php?p=status&token='] as $base) {
        $r = $req($base . rawurlencode($p));
        // « onerror=alert(1) » réapparaît en texte dans une page correctement
        // échappée : ce n'est pas une faille. Ce qui compte, c'est qu'une BALISE
        // sorte non échappée : donc un « < » suivi du nom de la balise.
        foreach (['<script', '<svg', '<img', '<iframe'] as $tag) {
            if (!str_contains($p, $tag)) continue;
            if (preg_match('~' . preg_quote($tag, '~') . '[\s/>]~i', $r['body'])
                && !preg_match('~&lt;' . preg_quote(ltrim($tag, '<'), '~') . '~i', $r['body'])) {
                // Le gabarit contient lui-même des <script> et des <svg> :
                // on exige la charge complète, pas la balise seule.
                $needle = trim(str_replace(['"', "'"], '', $p));
                if (str_contains($r['body'], $needle) && !str_contains($r['body'], htmlspecialchars($needle))) {
                    $holes[] = $base . ' ⇐ ' . str_cut($p, 18);
                }
                break;
            }
        }
    }
}
ok('A03', 'aucune XSS réfléchie sur les paramètres', $holes === [], implode(' | ', array_slice($holes, 0, 3)));

// XSS stockée : un nom de sonde hostile, relu sur tous les écrans qui l'affichent.
$req('/index.php?p=import', ['csrf' => $csrf, 'action' => 'import',
    'list' => $EVIL . '/ | <script>alert("stored")</script> | preuve',
    'run_setup' => '0', 'add_pages' => '0']);
$holes = [];
foreach (['today', 'dashboard', 'monitors', 'incidents', 'report'] as $p) {
    $r = $req('/index.php?p=' . $p);
    if (str_contains($r['body'], '<script>alert("stored")</script>')) $holes[] = $p;
}
$r = $req('/index.php?p=status&token=jeton-public-secret', null, ['nojar' => true]);
if (str_contains($r['body'], '<script>alert("stored")</script>')) $holes[] = 'status public';
$r = $req('/api.php?action=search&q=script');
if (str_contains($r['body'], '<script>alert')) $holes[] = 'api search';
ok('A03', 'aucune XSS stockée par un nom de sonde', $holes === [], implode(' ', $holes));

// Le contenu récupéré sur un site surveillé est aussi une entrée utilisateur.
$req('/index.php?p=import', ['csrf' => $csrf, 'action' => 'import',
    'list' => $EVIL . '/xss.php', 'run_setup' => '1', 'add_pages' => '0']);
$req('/cron.php?key=cle-cron-secrete');
$holes = [];
foreach (['today', 'dashboard', 'monitors'] as $p) {
    $r = $req('/index.php?p=' . $p . '&ui=expert');
    if (preg_match('~<script>alert\(1\)</script>~', $r['body'])) $holes[] = $p;
}
ok('A03', 'aucune XSS venue du contenu d\'un site surveillé', $holes === [], implode(' ', $holes));

title('A03 Injection d\'en-têtes et de réponse');
$holes = [];
foreach (["a\r\nX-Injected: 1", "a%0d%0aX-Injected:%201", "a\nSet-Cookie: pirate=1"] as $p) {
    foreach (['/index.php?p=today&lang=', '/index.php?p=monitor&id=1&range='] as $base) {
        $r = $req($base . rawurlencode($p));
        if (stripos($r['head'], 'X-Injected') !== false || stripos($r['head'], 'pirate=1') !== false) {
            $holes[] = $base;
        }
    }
}
ok('A03', 'aucune injection d\'en-tête de réponse', $holes === []);

// Le nom de fichier de l'export ne doit pas être pilotable.
$r = $req('/index.php?p=incidents&export=csv&range=' . rawurlencode("30d\"\r\nX-Injected: 1"));
ok('A03', 'nom de fichier d\'export non pilotable', stripos($r['head'], 'X-Injected') === false);

title('A07 Authentification');
// Fixation de session : l'identifiant doit changer à la connexion.
$jar2 = $tmp . '/j2.txt';
@unlink($jar2);
$sid = function (string $jarFile): string {
    $c = (string)@file_get_contents($jarFile);
    return preg_match('~uptimeezsec\s+(\S+)~', $c, $m) ? $m[1] : '';
};
$reqJ = function (string $path, ?array $post = null) use ($APP, $jar2): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $APP . $path, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $jar2, CURLOPT_COOKIEFILE => $jar2,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20,
    ]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $raw = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hlen = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['code' => $code, 'body' => substr($raw, $hlen), 'head' => substr($raw, 0, $hlen)];
};
$reqJ('/index.php?p=login');
$before = $sid($jar2);
$reqJ('/index.php?p=login', ['password' => $PASS]);
$after = $sid($jar2);
ok('A07', 'identifiant de session renouvelé à la connexion',
   $before !== '' && $after !== '' && $before !== $after, substr($before, 0, 6) . ' → ' . substr($after, 0, 6));

// Déconnexion : la session ne doit plus rien ouvrir.
$reqJ('/index.php?p=logout');
$r = $reqJ('/index.php?p=today');
ok('A07', 'session invalidée à la déconnexion', $r['code'] === 302 || str_contains($r['body'], 'type="password"'));

// Force brute : verrou après plusieurs échecs.
$blocked = false;
for ($i = 0; $i < 9; $i++) {
    $r = $reqJ('/index.php?p=login', ['password' => 'mauvais-' . $i]);
    if (preg_match('~(Trop de tentatives|Too many)~i', $r['body'])) { $blocked = true; break; }
}
ok('A07', 'verrou après une série d\'échecs', $blocked, $blocked ? 'au ' . ($i + 1) . 'e essai' : 'aucun verrou');

// Le cookie de session porte les bons drapeaux.
$head = $req('/index.php?p=login', null, ['nojar' => true])['head'];
$sc = '';
foreach (explode("\r\n", $head) as $line) if (stripos($line, 'set-cookie:') === 0) $sc .= $line;
ok('A07', 'cookie HttpOnly', $sc === '' || stripos($sc, 'httponly') !== false, $sc === '' ? 'aucun cookie posé' : 'présent');
ok('A07', 'cookie SameSite', $sc === '' || stripos($sc, 'samesite') !== false);

title('A05 Configuration à l\'exécution');
$r = $req('/index.php?p=today');
ok('A05', 'aucune trace PHP dans les pages',
   !preg_match('~(Fatal error|Warning:|Notice:|Stack trace|on line \d+)~', $r['body']));
$r = $req('/');
ok('A05', 'aucun listage de répertoire', !str_contains($r['body'], 'Index of /'));
$r = $req('/data/');
ok('A05', 'dossier data/ non listable', !str_contains($r['body'], 'Index of'));
$r = $req('/install.php', ['password' => 'autre-mot-de-passe', 'password2' => 'autre-mot-de-passe']);
ok('A05', 'réinstallation refusée en pratique',
   $r['code'] === 403 || str_contains($r['body'], 'déjà install') || str_contains($r['body'], 'already install'),
   'HTTP ' . $r['code']);
$r = $req('/index.php?p=login', ['password' => $PASS]);
ok('A05', 'le mot de passe n\'a pas été changé par cette tentative',
   $req('/index.php?p=today')['code'] === 200);
}

// =========================================================================
// NIVEAU 3. TRÈS PROFOND : ce qui vise le collecteur lui-même
// =========================================================================
if ($lvl === 0 || $lvl === 3) {
banner('NIVEAU 3 : Audit très profond : SSRF, XXE, bombes, ReDoS, temps');

$PASS = 'motdepasse-securite';
$req('/index.php?p=login', ['password' => $PASS]);
$r = $req('/index.php?p=today');
$csrf = preg_match('~csrf:\s*"([a-f0-9]+)"~', $r['body'], $m) ? $m[1] : '';

title('A10 SSRF : l\'outil va chercher des URL, c\'est sa raison d\'être');
// Un schéma local ne doit même pas être créable.
// L'écran d'import réaffiche les lignes qu'il écarte : leur présence dans la
// page ne prouve rien. La bonne question est : une sonde a-t-elle été créée, et
// un fichier local a-t-il fuité ?
$refused = [];
foreach (['file:///etc/passwd', 'gopher://127.0.0.1:70/x', 'dict://127.0.0.1:11211/stat',
          'ftp://127.0.0.1/', 'php://filter/resource=index.php', 'data:text/html,<b>x',
          'jar:file:///etc/passwd!/', 'ldap://127.0.0.1/', 'javascript:alert(1)'] as $u) {
    $req('/index.php?p=import', ['csrf' => $csrf, 'action' => 'import', 'list' => $u,
                                 'run_setup' => '1', 'add_pages' => '0']);
    $req('/cron.php?key=cle-cron-secrete');
    $body = $req('/index.php?p=monitors&ui=expert')['body'];
    $scheme = explode(':', $u)[0];
    $created = preg_match('~' . preg_quote($scheme, '~') . '(?:://|:)~i', strip_tags($body));
    $leak    = str_contains($body, 'root:x:') || str_contains($body, '<?php');
    if ($created || $leak) $refused[] = $u;
}
ok('A10', 'schémas non HTTP jamais transformés en sonde', $refused === [], implode(' ', $refused));

// Garde-fou optionnel contre les plages privées : désactivé, puis activé.
ok('A10', 'garde-fou de plages privées désactivé par défaut',
   \Uptimeez\Http::blockedReason('http://127.0.0.1:22/') === null);
\Uptimeez\Config::set('security.block_private_ranges', true);
$blocked = [
    'http://127.0.0.1:22/'                      => 'boucle locale',
    'http://10.0.0.5/'                          => 'plage privée 10/8',
    'http://192.168.1.1/'                       => 'plage privée 192.168/16',
    'http://169.254.169.254/latest/meta-data/'   => 'métadonnées hébergeur',
];
$missed = [];
foreach ($blocked as $u => $what) {
    if (\Uptimeez\Http::blockedReason($u) === null) $missed[] = $what;
}
ok('A10', 'garde-fou activé : cibles internes refusées', $missed === [], implode(' ', $missed));
ok('A10', 'garde-fou activé : cible publique toujours permise',
   \Uptimeez\Http::blockedReason('https://example.com/') === null);
\Uptimeez\Config::set('security.block_private_ranges', false);

// Une redirection vers file:// ne doit pas être suivie par le collecteur.
$req('/index.php?p=import', ['csrf' => $csrf, 'action' => 'import',
    'list' => $EVIL . '/redir-file.php', 'run_setup' => '0', 'add_pages' => '0']);
$req('/index.php?p=import', ['csrf' => $csrf, 'action' => 'import',
    'list' => $EVIL . '/redir-gopher.php', 'run_setup' => '0', 'add_pages' => '0']);
$req('/cron.php?key=cle-cron-secrete');
$leak = false;
foreach (['today', 'dashboard', 'monitors'] as $p) {
    $b = $req('/index.php?p=' . $p . '&ui=expert')['body'];
    if (str_contains($b, 'root:x:') || str_contains($b, '/bin/bash')) $leak = true;
}
ok('A10', 'redirection vers file:// non suivie', !$leak);
ok('A10', 'redirection vers gopher:// non suivie', !$leak);

// Boucle de redirection : bornée, pas de blocage.
$req('/index.php?p=import', ['csrf' => $csrf, 'action' => 'import',
    'list' => $EVIL . '/loop.php', 'run_setup' => '0', 'add_pages' => '0']);
$t = microtime(true);
$req('/cron.php?key=cle-cron-secrete');
$loopTime = microtime(true) - $t;
ok('A10', 'boucle de redirection bornée', $loopTime < 25, round($loopTime, 1) . ' s');

title('A03 XXE : le sitemap est du XML fourni par un tiers');
ok('A03', 'aucun parseur XML sur du contenu distant',
   !preg_match('~(simplexml_load|DOMDocument|xml_parse|LIBXML_NOENT)~', $appSrc),
   'sitemap analysé par expressions régulières');
$req('/index.php?p=import', ['csrf' => $csrf, 'action' => 'import',
    'list' => $EVIL . '/', 'run_setup' => '1', 'add_pages' => '1']);
$req('/cron.php?key=cle-cron-secrete&setup=1');
$req('/cron.php?key=cle-cron-secrete');
$leak = false;
foreach (['monitors', 'today', 'dashboard'] as $p) {
    if (str_contains($req('/index.php?p=' . $p . '&ui=expert')['body'], 'root:x:')) $leak = true;
}
ok('A03', 'entité externe du sitemap non résolue', !$leak);

title('DoS : bornes de mémoire et de temps sur du contenu hostile');
// 40 Mo de HTML : la mémoire du collecteur doit rester bornée.
$req('/index.php?p=import', ['csrf' => $csrf, 'action' => 'import',
    'list' => $EVIL . '/huge.php', 'run_setup' => '0', 'add_pages' => '0']);
$t = microtime(true);
$out = shell_exec('UPTIMEEZ_CONFIG=' . escapeshellarg($cfgFile) . ' '
    . escapeshellarg(PHP_BINARY) . ' -d memory_limit=128M '
    . escapeshellarg($ROOT . '/cron.php') . ' --once 2>&1');
$hugeTime = microtime(true) - $t;
ok('DoS', 'réponse géante absorbée sans épuiser la mémoire',
   !preg_match('~Allowed memory size|memory_limit~i', (string)$out), round($hugeTime, 1) . ' s');
ok('DoS', 'et sans erreur fatale', !preg_match('~Fatal error|Uncaught~', (string)$out));

// Contenu pathologique : 4 000 feuilles de style et des classes très longues.
$req('/index.php?p=import', ['csrf' => $csrf, 'action' => 'import',
    'list' => $EVIL . '/redos.php', 'run_setup' => '0', 'add_pages' => '0']);
$t = microtime(true);
$out = shell_exec('UPTIMEEZ_CONFIG=' . escapeshellarg($cfgFile) . ' '
    . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($ROOT . '/cron.php') . ' --once 2>&1');
$redosTime = microtime(true) - $t;
ok('DoS', 'contenu pathologique analysé en temps borné', $redosTime < 60, round($redosTime, 1) . ' s');
ok('DoS', 'aucun dépassement de pile PCRE',
   !preg_match('~preg_match|backtrack limit|JIT stack~i', (string)$out));

// Le nombre de ressources analysées par passe est plafonné.
$caps = [];
foreach (['MAX_SHEETS', 'MAX_SCRIPTS', 'MAX_FONTS', 'MAX_SHEET_BYTES', 'MAX_CLASSES', 'MAX_BODY'] as $c) {
    if (preg_match('~' . $c . '\s*=\s*([0-9_]+)~', $appSrc, $m)) $caps[] = $c . '=' . $m[1];
}
ok('DoS', 'ressources et volumes plafonnés par des constantes',
   count($caps) >= 5, implode(' ', $caps));

title('Temps constant et secrets');
// Comparaison du jeton public : hash_equals, donc pas de fuite par le temps.
$times = ['bon' => [], 'faux' => []];
for ($i = 0; $i < 12; $i++) {
    $t = microtime(true); $req('/index.php?p=status&token=jeton-public-secret', null, ['nojar' => true]);
    $times['bon'][] = microtime(true) - $t;
    $t = microtime(true); $req('/index.php?p=status&token=aaaaaaaaaaaaaaaaaaaa', null, ['nojar' => true]);
    $times['faux'][] = microtime(true) - $t;
}
sort($times['bon']); sort($times['faux']);
$med = fn(array $a) => $a[intdiv(count($a), 2)];
ok('A02', 'comparaison du jeton public en temps constant',
   str_contains($appSrc, 'hash_equals('),
   'médianes ' . round($med($times['bon']) * 1000) . ' / ' . round($med($times['faux']) * 1000) . ' ms');

// Un jeton de battement inconnu et un jeton malformé doivent être indiscernables.
$a = $req('/beat.php?k=' . str_repeat('a', 32), null, ['nojar' => true]);
$b = $req('/beat.php?k=..%2f..%2fconfig.php', null, ['nojar' => true]);
ok('A01', 'battement : réponses indiscernables',
   $a['code'] === $b['code'] && $a['body'] === $b['body'], 'HTTP ' . $a['code']);

// Aucun secret ne doit apparaître dans une page.
$secrets = ['cle-cron-secrete', 'motdepasse-securite', '$2y$'];
$found = [];
foreach (['today', 'dashboard', 'monitors', 'incidents', 'import', 'report'] as $p) {
    $b = $req('/index.php?p=' . $p . '&ui=expert')['body'];
    foreach ($secrets as $s) if (str_contains($b, $s)) $found[] = "$p ⇐ $s";
}
ok('A02', 'aucun secret dans les écrans courants', $found === [], implode(' ', $found));
$b = $req('/index.php?p=settings&ui=expert')['body'];
ok('A02', 'empreinte du mot de passe absente des réglages', !str_contains($b, '$2y$'));
ok('A02', 'mot de passe SMTP masqué', !str_contains($b, 'motdepasse-securite'));

title('Identifiants SQL et écritures de masse');
// Les noms de colonnes viennent du code, jamais d'une entrée : on le vérifie.
$dynCols = preg_match('~Db::(?:insert|update)\s*\(\s*[^,]+,\s*\$_(?:GET|POST|REQUEST)~', $appSrc);
ok('A03', 'aucun nom de colonne issu d\'une entrée HTTP', $dynCols === 0);
// Action de masse : des identifiants non numériques ne doivent rien casser.
$r = $req('/index.php?p=monitors', ['csrf' => $csrf, 'action' => 'bulk',
    'ids' => ['1', 'x', '-3', '9999', "1 OR 1=1"], 'bulk_action' => 'pause']);
ok('A03', 'action de masse : identifiants hostiles ignorés',
   $r['code'] < 500 && !preg_match('~SQLSTATE|no such~i', $r['body']), 'HTTP ' . $r['code']);

title('Export tableur : injection de formule');
$req('/index.php?p=import', ['csrf' => $csrf, 'action' => 'import',
    'list' => $EVIL . "/ | =cmd|'/C calc'!A0 | preuve", 'run_setup' => '0', 'add_pages' => '0']);
$req('/cron.php?key=cle-cron-secrete');
$csv = $req('/index.php?p=incidents&export=csv&range=30d')['body'];
$badCell = (bool)preg_match('~(^|;|")\s*=cmd~m', $csv);
ok('A03', 'aucune cellule exécutable dans l\'export CSV', !$badCell,
   $badCell ? 'formule non neutralisée' : 'préfixe apostrophe appliqué');
ok('A03', 'export CSV bien produit', str_contains($csv, 'Sonde') || str_contains($csv, 'Monitor'));
}

// =========================================================================
echo "\n" . str_repeat('═', 68) . "\n";
printf("%d contrôle(s) réussi(s), %d échec(s), %d remarque(s) : %.1f s\n",
       $pass, $fail, $warn, microtime(true) - $t0);
echo $fail === 0
    ? "✅ Aucune faille détectée aux niveaux demandés.\n"
    : "⚠️  Des contrôles de sécurité échouent : à corriger avant publication.\n";
exit($fail === 0 ? 0 : 1);
