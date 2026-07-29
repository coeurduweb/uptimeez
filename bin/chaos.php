<?php
/**
 * UptimeEZ : béta-test destructif (« l'utilisateur qui fait n'importe quoi »).
 *
 * On joue le rôle de quelqu'un qui écrit mal, clique partout, ne lit aucune
 * consigne, envoie des formulaires vides ou monstrueux, et essaie de casser
 * l'outil : volontairement ou non. Le contrat vérifié ici n'est pas « ça
 * marche » mais : **l'outil ne se casse jamais**.
 *
 * Quatre règles, appliquées à chaque requête :
 *   1. jamais de 500, jamais de page blanche ;
 *   2. jamais de message PHP (warning, notice, deprecated, fatal, trace) ;
 *   3. rien de ce que l'utilisateur écrit n'est réinjecté tel quel dans le HTML ;
 *   4. après le passage, la base reste cohérente et l'application utilisable.
 *
 *   php bin/chaos.php            # ~400 requêtes hostiles
 *   php bin/chaos.php --long     # ajoute les charges volumineuses
 *
 * Instance isolée dans un dossier temporaire, supprimée à la fin.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

if (PHP_SAPI !== 'cli') exit("À lancer en ligne de commande.\n");

$ROOT = dirname(__DIR__);
$LONG = in_array('--long', $argv, true);
$pass = 0; $fail = 0; $t0 = microtime(true);
$problems = [];

function ok(string $label, bool $good, string $detail = ''): void
{
    global $pass, $fail;
    $good ? $pass++ : $fail++;
    $pad = str_repeat(' ', max(1, 54 - mb_strlen($label)));
    echo ($good ? " OK  " : "ÉCHEC ") . $label . $pad . ($detail !== '' ? '→ ' . $detail : '') . "\n";
}
function title(string $s): void { echo "\n── $s " . str_repeat('─', max(0, 58 - mb_strlen($s))) . "\n"; }

// =========================================================================
// Instance isolée
// =========================================================================
$tmp = sys_get_temp_dir() . '/uptimeez-chaos-' . bin2hex(random_bytes(4));
@mkdir($tmp . '/site', 0775, true);

$freePort = function (int $from): int {
    for ($p = $from; $p < $from + 60; $p++) {
        $s = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
        if ($s) { fclose($s); return $p; }
    }
    exit("Aucun port libre à partir de $from.\n");
};
$appPort  = $freePort(8800);
$sitePort = $freePort(8860);
$APP  = "http://127.0.0.1:$appPort";
$SITE = "http://127.0.0.1:$sitePort";
$PASS = 'motdepasse-chaos';

file_put_contents("$tmp/site/index.html",
    '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Site témoin</title></head>'
  . '<body><h1>Site témoin</h1><footer>© 2026 Site témoin — tous droits réservés</footer></body></html>');
file_put_contents("$tmp/site/api.php", "<?php header('Content-Type: application/json'); echo '{\"status\":\"ok\"}';");

$cfgFile = $tmp . '/config.php';
file_put_contents($cfgFile, "<?php return " . var_export([
    'db'   => ['driver' => 'sqlite', 'sqlite' => $tmp . '/chaos.sqlite'],
    'auth' => ['password_hash' => password_hash($PASS, PASSWORD_DEFAULT), 'session_name' => 'uptimeezchaos'],
    'app'  => ['name' => 'UptimeEZ Chaos', 'base_url' => $APP, 'timezone' => 'Europe/Paris',
               'public_token' => 'jeton-chaos', 'cron_key' => 'cle-chaos'],
    'defaults' => ['interval_sec' => 300, 'timeout_sec' => 5, 'retries' => 0, 'slow_ms' => 9000,
                   'max_parallel' => 4, 'retention_days' => 60, 'ssl_warn_days' => 14, 'css_drop_pct' => 35,
                   'user_agent' => 'UptimeEZBot/1.0 (Chaos)'],
    'notify' => ['discord' => ['enabled' => false, 'webhook' => ''], 'slack' => ['enabled' => false, 'webhook' => ''],
                 'mail' => ['enabled' => false, 'to' => ''], 'webhook' => ['enabled' => false, 'url' => ''],
                 'resend_after_min' => 60, 'notify_recovery' => true, 'notify_degraded' => true, 'quiet_hours' => ''],
], true) . ";\n");

$spawn = function (array $cmd, string $cwd, array $env = []) {
    return proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                     $pipes, $cwd, $env + ['PATH' => getenv('PATH') ?: '/usr/bin:/bin']);
};
$siteSrv = $spawn([PHP_BINARY, '-S', "127.0.0.1:$sitePort", '-t', "$tmp/site"], "$tmp/site");
$appSrv  = $spawn([PHP_BINARY, '-S', "127.0.0.1:$appPort", '-t', $ROOT], $ROOT, ['UPTIMEEZ_CONFIG' => $cfgFile]);

register_shutdown_function(function () use ($siteSrv, $appSrv, $tmp, $appPort, $sitePort): void {
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
    foreach (glob($tmp . '/site/*') ?: [] as $f) @unlink($f);
    @rmdir($tmp . '/site');
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
if (!$wait($appPort) || !$wait($sitePort)) exit("Les serveurs de test n'ont pas démarré.\n");

echo "Béta-test destructif : instance $APP\n";

// =========================================================================
// Client HTTP + contrôleur de dégâts
// =========================================================================
$jar = $tmp . '/cookies.txt';
$hits = 0;

/** Trace d'erreur PHP visible dans une réponse : le pire des symptômes. */
$phpNoise = function (string $body): ?string {
    static $needles = [
        'Fatal error', 'Parse error', 'Warning:', 'Notice:', 'Deprecated:',
        'Uncaught ', 'Stack trace', 'PDOException', 'TypeError:',
        'ValueError:', 'ArgumentCountError', 'Undefined array key',
        'Undefined variable', 'Trying to access array offset',
    ];
    foreach ($needles as $n) {
        if (str_contains($body, $n)) return $n;
    }
    // « on line 42 » : la signature d'une trace PHP. Le texte « on line »
    // seul appartient à l'interface anglaise, il ne compte pas.
    if (preg_match('~ on line \d+~', $body)) return 'trace PHP (on line N)';
    return null;
};

$req = function (string $path, ?array $post = null, array $opt = []) use ($APP, $jar, &$hits): array {
    $hits++;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => str_starts_with($path, 'http') ? $path : $APP . $path,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => str_contains($path, 'api.php') ? ['X-Requested-With: fetch'] : [],
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        // http_build_query gère les tableaux : c'est justement ce qu'on veut
        // envoyer là où le code attend une chaîne.
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    if (!empty($opt['method'])) curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $opt['method']);
    $raw  = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hlen = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['code' => $code, 'body' => substr($raw, $hlen), 'head' => substr($raw, 0, $hlen)];
};

/**
 * Une requête hostile est « survivable » si elle ne provoque ni 500, ni bruit
 * PHP, ni réinjection du texte de l'attaque. Un refus (400/403/404) est une
 * bonne réponse : c'est un outil qui se défend, pas un outil qui casse.
 */
$survives = function (array $r, string $payload = '') use ($phpNoise, &$problems): bool {
    $ok = true;
    if ($r['code'] >= 500)            { $problems[] = 'HTTP ' . $r['code']; $ok = false; }
    if ($n = $phpNoise($r['body']))   { $problems[] = 'bruit PHP « ' . $n . ' »'; $ok = false; }
    if ($payload !== '' && str_contains($payload, '<script')
        && str_contains($r['body'], '<script>alert')) { $problems[] = 'XSS réfléchie'; $ok = false; }
    return $ok;
};

// --- Installation puis connexion (le seul moment où l'on suit la consigne) --
$req('/install.php', ['password' => $PASS, 'password2' => $PASS]);
$csrfOf = function () use ($req): string {
    $r = $req('/index.php?p=today');
    return preg_match('~csrf:\s*"([a-f0-9]+)"~', $r['body'], $m) ? $m[1] : '';
};
$r = $req('/index.php?p=login', ['password' => $PASS]);
$csrf = $csrfOf();
ok('connexion établie avant de tout casser', $csrf !== '', 'csrf ' . substr($csrf, 0, 6));

// Un site valide, pour avoir un identifiant réel à maltraiter.
$req('/index.php?p=import', ['csrf' => $csrf, 'action' => 'import', 'list' => $SITE . '/',
                             'run_setup' => '1', 'add_pages' => '0']);
$realId = 0;
if (preg_match('~p=monitor&(?:amp;)?id=(\d+)~', $req('/index.php?p=monitors')['body'], $m)) {
    $realId = (int)$m[1];
}
ok('une sonde réelle existe', $realId > 0, 'id ' . $realId);

// =========================================================================
title('Saisies absurdes dans l\'URL');
// =========================================================================
$nasty = [
    '',                             // vide
    ' ',                            // espace
    '0',                            '-1',                    '999999999999999999999',
    'null',                         'undefined',             'NaN',
    "' OR 1=1 --",                  '1; DROP TABLE monitors;--',
    '<script>alert(1)</script>',    '"><img src=x onerror=alert(1)>',
    '../../../../etc/passwd',       '..%2f..%2fconfig.php',
    '%00',                          "a\x00b",
    str_repeat('A', 5000),
    '🙈🙉🙊',                        'Ｆｕｌｌｗｉｄｔｈ',
    '{{7*7}}',                      '${jndi:ldap://x}',
    'https://evil.example/',        'javascript:alert(1)',
    '%%%',                          '&&&',                    '?p=?p=?p=',
];
$pages = ['today', 'dashboard', 'monitors', 'monitor', 'incidents', 'events',
          'settings', 'import', 'report', 'status', 'login', 'logout',
          'inexistant', 'Today', 'TODAY', '../settings', 'monitor/../..'];

$bad = 0;
foreach ($pages as $p) {
    $r = $req('/index.php?p=' . rawurlencode($p));
    if (!$survives($r)) { $bad++; echo "      p=$p → HTTP {$r['code']}\n"; }
}
ok('toutes les pages, y compris inventées', $bad === 0, count($pages) . ' page(s)');

// La liste contenait « logout » : un utilisateur qui clique partout finit par
// se déconnecter. C'est le bon comportement : on se reconnecte pour la suite.
$req('/index.php?p=login', ['password' => $PASS]);
$csrf = $csrfOf();
ok('reconnexion après une déconnexion accidentelle', $csrf !== '');

$bad = 0;
foreach ($nasty as $v) {
    foreach (['id', 'range', 'lang', 'mode', 'filter', 'sort', 'group', 'token', 'q', 'export', 'site'] as $k) {
        $r = $req('/index.php?p=monitor&' . $k . '=' . rawurlencode($v));
        if (!$survives($r, $v)) { $bad++; echo '      ' . $k . '=' . str_cut($v, 30) . " → HTTP {$r['code']}\n"; }
    }
}
ok('paramètres remplis de n\'importe quoi', $bad === 0, count($nasty) * 11 . ' combinaison(s)');

// Un paramètre attendu scalaire, envoyé comme tableau : le grand classique.
$bad = 0;
foreach (['id', 'range', 'lang', 'mode', 'p'] as $k) {
    $r = $req('/index.php?p=monitor&' . $k . '[]=1&' . $k . '[]=2');
    if (!$survives($r)) { $bad++; echo "      {$k}[] → HTTP {$r['code']}\n"; }
}
ok('tableau là où une chaîne est attendue', $bad === 0);

// =========================================================================
title('Formulaires envoyés n\'importe comment');
// =========================================================================
$forms = [
    ['/index.php?p=import',   ['action' => 'import']],
    ['/index.php?p=import',   ['action' => 'preview', 'list' => '']],
    ['/index.php?p=import',   ['action' => 'import', 'list' => 'pas une url du tout']],
    ['/index.php?p=monitors', ['action' => 'save_monitor']],
    ['/index.php?p=monitors', ['action' => 'save_monitor', 'id' => 'abc', 'url' => 'nope']],
    ['/index.php?p=monitors', ['action' => 'bulk', 'ids' => 'tout']],
    ['/index.php?p=monitors', ['action' => 'bulk', 'ids' => [1, 'x', -5], 'bulk_action' => 'inconnue']],
    ['/index.php?p=settings', ['action' => 'save_settings']],
    ['/index.php?p=settings', ['action' => 'save_settings', 'quiet_hours' => 'n\'importe quoi',
                               'retention_days' => '-999', 'max_parallel' => '99999']],
    ['/index.php?p=settings', ['action' => 'inexistante']],
    ['/index.php?p=monitors', ['action' => 'delete_monitor', 'id' => '999999']],
];
$bad = 0;
foreach ($forms as [$path, $post]) {
    // 1. sans jeton : doit être refusé proprement
    $r = $req($path, $post);
    if (!$survives($r)) { $bad++; echo "      $path sans jeton → HTTP {$r['code']}\n"; }
    // 2. avec un jeton bidon
    $r = $req($path, $post + ['csrf' => 'faux']);
    if (!$survives($r)) { $bad++; echo "      $path jeton faux → HTTP {$r['code']}\n"; }
    // 3. avec le bon jeton mais des données invalides
    $r = $req($path, $post + ['csrf' => $csrf]);
    if (!$survives($r)) { $bad++; echo "      $path données invalides → HTTP {$r['code']}\n"; }
}
ok('formulaires vides, faux jetons, données absurdes', $bad === 0, count($forms) * 3 . ' envoi(s)');

// Champs monstrueux dans le formulaire de sonde.
$monster = [
    'csrf' => $csrf, 'action' => 'save_monitor', 'id' => (string)$realId,
    'name' => str_repeat('nom très long ', 400),
    'url' => 'http://' . str_repeat('a', 300) . '.example',
    'expect_string' => '<script>alert("xss")</script>',
    'forbid_string' => "\x00\x01\x02",
    'interval_sec' => '-42', 'timeout_sec' => '0', 'slow_ms' => 'beaucoup',
    'retries' => '9999', 'css_drop_pct' => '500', 'ssl_warn_days' => '-1',
    'status_codes' => '200,abc,,,999999,-1', 'method' => 'TRUC',
    'headers' => "sans-deux-points\nx: y\n\n\n", 'json_path' => '..[]..',
    'maintenance_window' => 'lun-sam 99:99-88:88', 'notify_channels' => 'inconnu,,,discord',
];
$r = $req('/index.php?p=monitors', $monster);
ok('sonde enregistrée avec des valeurs monstrueuses',
   $survives($r, $monster['expect_string']), 'HTTP ' . $r['code']);
$r = $req('/index.php?p=monitor&id=' . $realId);
ok('sa fiche reste affichable', $survives($r) && $r['code'] === 200, 'HTTP ' . $r['code']);
ok('le script injecté n\'est pas exécutable',
   !str_contains($r['body'], '<script>alert("xss")</script>'));

// =========================================================================
title('API appelée à tort et à travers');
// =========================================================================
$actions = ['check', 'toggle', 'setup', 'fix', 'undo', 'summary', 'series',
            'pending', 'report', 'search', '', 'inexistante', 'CHECK', 'ch eck'];
$bad = 0;
foreach ($actions as $a) {
    foreach ([['GET', null], ['POST', ['csrf' => $csrf]], ['POST', ['csrf' => 'faux']]] as [$meth, $post]) {
        $path = '/api.php?action=' . rawurlencode($a) . '&id=' . $realId;
        $r = $meth === 'GET' ? $req($path) : $req($path, $post);
        if (!$survives($r)) { $bad++; echo "      $a ($meth) → HTTP {$r['code']}\n"; }
    }
}
ok('toutes les actions, toutes les méthodes', $bad === 0, count($actions) * 3 . ' appel(s)');

$bad = 0;
foreach (['relearn', 'raise_slow', 'ignore_noindex', 'adopt_url', 'snooze', 'ack',
          '', 'rm -rf /', 'DROP TABLE'] as $fx) {
    foreach ([$realId, 0, -1, 999999] as $id) {
        $r = $req('/api.php?action=fix&id=' . $id, ['csrf' => $csrf, 'fix' => $fx]);
        if (!$survives($r)) { $bad++; echo "      fix=$fx id=$id → HTTP {$r['code']}\n"; }
    }
}
ok('correctifs sur des sondes qui n\'existent pas', $bad === 0, '36 appel(s)');

$bad = 0;
foreach (['', 'zzz', str_repeat('f', 200), '../../x', '<b>'] as $tk) {
    $r = $req('/api.php?action=undo', ['csrf' => $csrf, 'token' => $tk]);
    if (!$survives($r)) $bad++;
}
ok('annulation avec un jeton inventé', $bad === 0);

// La recherche reçoit tout ce qu'un clavier peut produire.
$bad = 0;
foreach ($nasty as $q) {
    $r = $req('/api.php?action=search&q=' . rawurlencode($q));
    if (!$survives($r, $q)) { $bad++; echo '      q=' . str_cut($q, 24) . " → HTTP {$r['code']}\n"; }
}
ok('recherche : accents, emoji, injection, 5 Ko', $bad === 0, count($nasty) . ' requête(s)');

// =========================================================================
title('Import : ce qu\'un client colle vraiment');
// =========================================================================
$pastes = [
    '',
    "\n\n\n\n",
    'bonjour, pouvez-vous surveiller mes sites ? merci',
    'ftp://truc.local  mailto:jean@exemple.fr  tel:+33600000000',
    "exemple.fr\nexemple.fr\nEXEMPLE.FR\nwww.exemple.fr\nhttp://exemple.fr\nhttps://exemple.fr/",
    'localhost 127.0.0.1 192.168.1.1 0.0.0.0 [::1]',
    'a.b  c.d  e.f  .  ..  ...  http://  https://  //',
    "<script>alert('x')</script>.fr",
    "site.fr | <b>nom</b> | <img src=x onerror=alert(1)>",
    str_repeat("site-", 200) . '.fr',
    "\xC3\x28 octets invalides \xE2\x82",
    'fichier.pdf image.png feuille.css script.js',
    "🙈.fr\nñoño.es\nмойсайт.рф\n中文.cn",
    "site.fr;;;;nom;;;;chaine\nsite2.fr\t\t\tnom2\t\tchaine2",
    '#tout est commenté' . "\n" . '# encore',
];
if ($LONG) $pastes[] = implode("\n", array_map(fn($i) => "site$i.example", range(1, 3000)));

$bad = 0;
foreach ($pastes as $i => $paste) {
    foreach (['preview', 'import'] as $act) {
        $r = $req('/index.php?p=import', ['csrf' => $csrf, 'action' => $act, 'list' => $paste,
                                          'run_setup' => '0', 'add_pages' => '0']);
        if (!$survives($r, $paste)) { $bad++; echo "      collage #$i ($act) → HTTP {$r['code']}\n"; }
    }
}
ok('collages hostiles à l\'import', $bad === 0, count($pastes) * 2 . ' collage(s)');

// =========================================================================
title('Clics au hasard dans toute l\'interface');
// =========================================================================
// On récolte tous les liens internes rencontrés et on les suit, comme
// quelqu'un qui clique partout sans savoir ce qu'il fait.
$seen = []; $queue = ['/index.php?p=today'];
$bad = 0; $visited = 0;
while ($queue && $visited < 120) {
    $u = array_shift($queue);
    if (isset($seen[$u])) continue;
    $seen[$u] = true;
    if (str_contains($u, 'p=logout')) continue;    // sinon on perd la session
    $r = $req($u);
    $visited++;
    if (!$survives($r)) { $bad++; echo "      $u → HTTP {$r['code']}\n"; }
    if (preg_match_all('~href="(index\.php\?[^"#]*|api\.php\?[^"#]*)"~', $r['body'], $m)) {
        foreach ($m[1] as $href) {
            $href = html_entity_decode($href, ENT_QUOTES, 'UTF-8');
            if (!isset($seen['/' . $href])) $queue[] = '/' . $href;
        }
    }
}
ok('parcours exhaustif des liens de l\'interface', $bad === 0, $visited . ' page(s) visitée(s)');

// Toutes les langues, tous les modes, toutes les périodes, sur tous les écrans.
$bad = 0;
foreach (['en', 'fr', 'ar', 'zh', 'ru', 'ur', 'xx'] as $lg) {
    foreach (['simple', 'expert', 'nawak'] as $md) {
        foreach (['today', 'dashboard', 'monitors', 'settings', 'report', 'incidents', 'clients'] as $p) {
            $r = $req("/index.php?p=$p&lang=$lg&ui=$md");
            if (!$survives($r)) { $bad++; echo "      $p/$lg/$md → HTTP {$r['code']}\n"; }
        }
    }
}
ok('toutes les langues × tous les modes × tous les écrans', $bad === 0, '126 combinaison(s)');

$bad = 0;
foreach (['1h', '24h', '7j', '30j', '90j', '120j', '180j', '365j', '', 'siècle', '-1', '99999j'] as $rg) {
    foreach (['monitor&id=' . $realId, 'report', 'incidents'] as $p) {
        $r = $req("/index.php?p=$p&range=$rg");
        if (!$survives($r)) { $bad++; echo "      $p range=$rg → HTTP {$r['code']}\n"; }
    }
    $r = $req('/api.php?action=series&id=' . $realId . '&range=' . rawurlencode($rg));
    if (!$survives($r)) $bad++;
}
ok('toutes les périodes, y compris impossibles', $bad === 0, '48 requête(s)');

// =========================================================================
title('Exports d\'autres outils, déposés n\'importe comment');
// =========================================================================
// Le dépôt de fichier est une porte d'entrée : un utilisateur y mettra son
// export, mais aussi une photo, un tableur, un fichier tronqué à mi-chemin.
$upl = function (string $content, string $name) use ($APP, $jar, $csrf): array {
    $tmpF = tempnam(sys_get_temp_dir(), 'chaos');
    file_put_contents($tmpF, $content);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $APP . '/index.php?p=import', CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_POST => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_POSTFIELDS => ['csrf' => $csrf, 'action' => 'preview', 'list' => '',
                               'file' => new CURLFile($tmpF, 'application/octet-stream', $name)],
    ]);
    $raw = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hlen = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    @unlink($tmpF);
    return ['code' => $code, 'body' => substr($raw, $hlen), 'head' => substr($raw, 0, $hlen)];
};
$bad = 0;
$files = [
    ['{"monitors":[{"friendly_name":"tronqué"', 'tronque.json'],
    ['{"monitorList":' . str_repeat('[', 400) . str_repeat(']', 400) . '}', 'profond.json'],
    ["\x00\x01\x02\x03binaire", 'photo.jpg'],
    [str_repeat("url,name\n", 40000), 'enorme.csv'],
    ['url;name' . "\n" . str_repeat('a', 100000) . ';x', 'ligne-longue.csv'],
    ['<?php system($_GET["c"]); ?>', 'porte.php'],
    ['{"monitors":[{"friendly_name":"<script>alert(1)</script>","url":"https://x.test/","type":1}]}', 'xss.json'],
    ['{"data":[{"attributes":{"url":"javascript:alert(1)","monitor_type":"status"}}]}', 'js.json'],
    ['{"checks":[{"hostname":"' . str_repeat('x', 5000) . '","type":"http"}]}', 'hote-long.json'],
    ['url,name' . "\n" . '=cmd|calc,injection', 'formule.csv'],
    ['', 'vide.json'],
];
foreach ($files as [$content, $name]) {
    $r = $upl($content, $name);
    if (!$survives($r, $name)) { $bad++; echo "      import $name → HTTP {$r['code']}\n"; }
    // Aucun contenu déposé ne doit ressortir exécutable ni non échappé.
    if (str_contains($r['body'], '<?php') || str_contains($r['body'], '<script>alert(1)</script>')) {
        $bad++; echo "      import $name → contenu renvoyé sans échappement\n";
    }
}
ok('exports absurdes, tronqués ou hostiles : l\'écran tient', $bad === 0);

// =========================================================================
title('Points d\'entrée publics');
// =========================================================================
$bad = 0;
foreach (['', 'jeton-chaos', 'faux', '../config.php', '<script>', str_repeat('x', 300)] as $tk) {
    $r = $req('/index.php?p=status&token=' . rawurlencode($tk));
    if (!$survives($r, $tk)) { $bad++; echo "      status token=$tk → HTTP {$r['code']}\n"; }
}
ok('page d\'état publique : jetons faux et vrais', $bad === 0);

// L'espace client est l'autre porte ouverte du produit : elle prend un jeton
// dans l'URL, donc elle prendra tout ce qu'un navigateur peut y mettre.
$bad = 0;
foreach (['', 'k=', 'k=faux', 'k=' . str_repeat('a', 32), 'k=' . str_repeat('a', 5000),
          'k[]=1', 'k[]=a&k[]=b', 'k=%00', 'k=' . rawurlencode("' OR 1=1 --"),
          'k=' . rawurlencode('../../config.php'), 'k=' . rawurlencode('<script>alert(1)</script>'),
          'k=1&client_id=1&id=1&site=99999', 'k=aa&lang=zz&ui=nawak'] as $qs) {
    $r = $req('/index.php?p=client&' . $qs);
    if (!$survives($r, $qs)) { $bad++; echo "      p=client&$qs → HTTP {$r['code']}\n"; }
}
ok('espace client : jetons absurdes et paramètres en trop', $bad === 0);

$bad = 0;
foreach (['', 'k=', 'k=faux', 'k=' . str_repeat('a', 500), 'k[]=1', 'k=x&m=' . str_repeat('m', 3000)] as $qs) {
    $r = $req('/beat.php?' . $qs);
    if (!$survives($r)) { $bad++; echo "      beat.php?$qs → HTTP {$r['code']}\n"; }
}
ok('point de battement : clés absurdes', $bad === 0);

$bad = 0;
foreach (['', 'key=cle-chaos', 'key=faux', 'key[]=x'] as $qs) {
    $r = $req('/cron.php?' . $qs);
    if (!$survives($r)) { $bad++; echo "      cron.php?$qs → HTTP {$r['code']}\n"; }
}
ok('cron par URL : clés absurdes', $bad === 0);

// Réinstallation tentée alors que c'est déjà installé.
$r = $req('/install.php', ['password' => 'autre-chose-1234', 'password2' => 'autre-chose-1234']);
ok('réinstallation refusée', $r['code'] === 403 || str_contains($r['body'], 'déjà install'),
   'HTTP ' . $r['code']);
$r = $req('/index.php?p=login', ['password' => $PASS]);
ok('le mot de passe n\'a pas changé', $req('/index.php?p=today')['code'] === 200);

// =========================================================================
title('Méthodes HTTP inattendues');
// =========================================================================
$bad = 0;
foreach (['PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS', 'TRACE', 'BREW'] as $meth) {
    foreach (['/index.php?p=today', '/api.php?action=summary', '/beat.php'] as $p) {
        $r = $req($p, null, ['method' => $meth]);
        // 501 vient du serveur web lui-même face à un verbe inconnu : ce n'est
        // pas UptimeEZ qui casse. Seul un 5xx applicatif compte.
        if ($r['code'] >= 500 && $r['code'] !== 501) { $bad++; echo "      $meth $p → HTTP {$r['code']}\n"; }
        if ($n = $phpNoise($r['body'])) { $bad++; echo "      $meth $p → bruit PHP « $n »\n"; }
    }
}
ok('verbes HTTP exotiques', $bad === 0, '21 requête(s)');

// =========================================================================
title('L\'application est-elle encore vivante ?');
// =========================================================================
$r = $req('/index.php?p=today&lang=fr&mode=simple');
ok('l\'écran d\'accueil répond toujours', $r['code'] === 200 && $survives($r));
ok('la barre de navigation est intacte', str_contains($r['body'], 'class="nav"'));

$r = $req('/api.php?action=summary');
$sum = json_decode($r['body'], true);
ok('l\'API répond du JSON valide', is_array($sum) && !empty($sum['ok']));

// Base de données : cohérence après le passage.
$pdo = new PDO('sqlite:' . $tmp . '/chaos.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$integrity = (string)$pdo->query('PRAGMA integrity_check')->fetchColumn();
ok('base de données intègre', $integrity === 'ok', $integrity);

$orphans = (int)$pdo->query('SELECT COUNT(*) FROM checks c
                             LEFT JOIN monitors m ON m.id = c.monitor_id
                             WHERE m.id IS NULL')->fetchColumn();
ok('aucune mesure orpheline', $orphans === 0, (string)$orphans);

$nulls = (int)$pdo->query("SELECT COUNT(*) FROM monitors WHERE url IS NULL OR url = ''")->fetchColumn();
ok('aucune sonde sans adresse', $nulls === 0, (string)$nulls);

$badInt = (int)$pdo->query('SELECT COUNT(*) FROM monitors WHERE interval_sec < 30 OR interval_sec > 604800')->fetchColumn();
ok('cadences ramenées dans des bornes saines', $badInt === 0, (string)$badInt);

$badTimeout = (int)$pdo->query('SELECT COUNT(*) FROM monitors WHERE timeout_sec < 1 OR timeout_sec > 120')->fetchColumn();
ok('délais d\'attente dans des bornes saines', $badTimeout === 0, (string)$badTimeout);

// Le collecteur doit encore tourner sur cette base malmenée.
$out = shell_exec('UPTIMEEZ_CONFIG=' . escapeshellarg($cfgFile) . ' '
    . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($ROOT . '/cron.php') . ' --once 2>&1');
ok('le collecteur tourne encore', $out !== null && !preg_match('~Fatal|Parse error|Uncaught~', (string)$out),
   str_cut(trim((string)$out), 60));

// =========================================================================
echo "\n" . str_repeat('═', 68) . "\n";
printf("%d contrôle(s) réussi(s), %d échec(s) : %d requêtes hostiles en %.1f s\n",
       $pass, $fail, $hits, microtime(true) - $t0);
if ($problems) {
    $agg = array_count_values($problems);
    echo "\nSymptômes rencontrés :\n";
    foreach ($agg as $p => $n) echo "  ×$n  $p\n";
}
echo $fail === 0
    ? "✅ L'outil résiste à un utilisateur qui fait n'importe quoi.\n"
    : "⚠️  L'outil se laisse casser : voir les échecs ci-dessus.\n";
exit($fail === 0 ? 0 : 1);
