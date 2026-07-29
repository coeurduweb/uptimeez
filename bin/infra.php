<?php
/**
 * UptimeEZ : ce qu'on voit quand UptimeEZ lui-même est en panne.
 *
 * Les autres suites vérifient qu'UptimeEZ marche. Celle-ci vérifie qu'il sait
 * tomber. La distinction n'est pas rhétorique : l'audit a mesuré huit pannes
 * d'infrastructure distinctes qui rendaient toutes exactement la même chose,
 * un HTTP 500 sans un octet de corps. Sur un hébergement mutualisé,
 * display_errors est coupé : l'exploitant voyait une page blanche et n'avait
 * aucun moyen de savoir si le problème venait des droits du dossier data/, d'un
 * mot de passe MySQL périmé ou d'un config.php mal réenregistré.
 *
 * Trois exigences sont vérifiées ici pour chaque panne :
 *
 *   1. la réponse porte un code juste (503 « réessayez » pour une panne de
 *      stockage, pas 500 « c'est cassé ») et un corps non vide ;
 *   2. un exploitant connecté obtient la cause et la commande qui la répare ;
 *   3. un visiteur public n'obtient RIEN de technique : ni chemin, ni moteur,
 *      ni nom d'utilisateur de base. Une page de statut est publique.
 *
 * Les pannes qu'on ne peut pas provoquer depuis un test (disque plein, verrou
 * de plus de huit secondes, serveur saturé) sont contrôlées sur le classement
 * seul, via Fail::explain().
 *
 *   php bin/infra.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Uptimeez\Fail;

if (PHP_SAPI !== 'cli') exit("À lancer en ligne de commande.\n");

$pass = 0; $fail = 0;
function ok(string $label, bool $good, string $detail = ''): void
{
    global $pass, $fail;
    $good ? $pass++ : $fail++;
    $pad = str_repeat(' ', max(1, 52 - mb_strlen($label)));
    echo ($good ? ' OK  ' : 'ÉCHEC ') . $label . $pad . ($detail !== '' ? '→ ' . $detail : '') . "\n";
}
function title(string $s): void { echo "\n── $s " . str_repeat('─', max(0, 56 - mb_strlen($s))) . "\n"; }

$ROOT = dirname(__DIR__);
$tmp  = sys_get_temp_dir() . '/uptimeez-infra-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);

// Un port libre plutôt qu'un port choisi au hasard : deux exécutions
// simultanées ne doivent pas se marcher dessus.
$probe = stream_socket_server('tcp://127.0.0.1:0', $en, $es);
$port  = (int)explode(':', (string)stream_socket_get_name($probe, false))[1];
fclose($probe);
$APP   = "http://127.0.0.1:$port";
$cfg   = $tmp . '/config.php';
$jar   = $tmp . '/cookies.txt';
$PASS  = 'mot-de-passe-infra';

/** Écrit une configuration complète dont seul le bloc « db » change. */
$writeCfg = function (array $db) use ($cfg, $PASS): void {
    file_put_contents($cfg, "<?php return " . var_export([
        'db'   => $db,
        'auth' => ['password_hash' => password_hash($PASS, PASSWORD_DEFAULT), 'session_name' => 'uptimeezinfra'],
        'app'  => ['name' => 'UptimeEZ Infra', 'base_url' => 'http://127.0.0.1', 'timezone' => 'Europe/Paris',
                   'public_token' => 'jeton-infra', 'cron_key' => 'cle-infra'],
        'defaults' => ['interval_sec' => 300, 'timeout_sec' => 10, 'retries' => 0, 'slow_ms' => 9000,
                       'max_parallel' => 6, 'retention_days' => 60, 'ssl_warn_days' => 14, 'css_drop_pct' => 35,
                       'user_agent' => 'UptimeEZBot/1.0 (Infra)'],
        'notify' => ['discord' => ['enabled' => false, 'webhook' => ''], 'slack' => ['enabled' => false, 'webhook' => ''],
                     'mail' => ['enabled' => false, 'to' => ''], 'webhook' => ['enabled' => false, 'url' => ''],
                     'resend_after_min' => 60, 'notify_recovery' => true, 'notify_degraded' => true, 'quiet_hours' => ''],
    ], true) . ";\n");
};

$srv = null;
$up = function () use (&$srv, $ROOT, $port, $cfg, $APP): bool {
    $srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $ROOT],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $ROOT,
        ['UPTIMEEZ_CONFIG' => $cfg, 'PATH' => getenv('PATH') ?: '/usr/bin:/bin']);
    for ($i = 0; $i < 60; $i++) {
        $s = @stream_socket_client("tcp://127.0.0.1:$port", $a, $b, 0.3);
        if ($s) { fclose($s); return true; }
        usleep(120000);
    }
    return false;
};
$down = function () use (&$srv, $port): void {
    if (!is_resource($srv)) return;
    proc_terminate($srv, 9);
    proc_close($srv);
    // Le port doit être rendu avant le redémarrage suivant.
    for ($i = 0; $i < 30; $i++) {
        $s = @stream_socket_client("tcp://127.0.0.1:$port", $a, $b, 0.2);
        if (!$s) return;
        fclose($s);
        usleep(100000);
    }
};

register_shutdown_function(function () use (&$srv, $tmp, $down) {
    $down();
    exec('chmod -R u+rwX ' . escapeshellarg($tmp) . ' 2>/dev/null');
    exec('rm -rf ' . escapeshellarg($tmp));
});

/** @return array{code:int,body:string,head:string} */
$req = function (string $path, ?array $post = null) use ($APP, $jar): array {
    $ch = curl_init(str_starts_with($path, 'http') ? $path : $APP . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30,
                            CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
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

/**
 * Un écran d'exploitant en panne : code annoncé, cause nommée, remède présent,
 * et jamais de trace PHP brute hors du bloc « Détail technique ».
 */
$expectAdmin = function (string $scene, array $r, int $code, string $needle, string $remedy) {
    ok("$scene : code $code", $r['code'] === $code, 'reçu ' . $r['code']);
    ok("$scene : la cause est nommée", stripos($r['body'], $needle) !== false, mb_substr(strip_tags($r['body']), 0, 60));
    ok("$scene : un remède est proposé", stripos($r['body'], $remedy) !== false);
    ok("$scene : page complète", str_contains($r['body'], '</html>'));
    ok("$scene : pas de trace hors du détail",
        !preg_match('~Stack trace|#0 /~', substr($r['body'], 0, (int)(stripos($r['body'], 'Détail technique') ?: 99999))));
};

echo "Suite pannes d'infrastructure : $APP\n";

// =========================================================================
title('Le dossier data/ n\'est pas inscriptible');
// =========================================================================
mkdir($tmp . '/verrou', 0555, true);
$writeCfg(['driver' => 'sqlite', 'sqlite' => $tmp . '/verrou/u.sqlite']);
if (!$up()) exit("Le serveur de test n'a pas démarré.\n");

// La page de connexion ne touche pas la base : elle doit rester affichable,
// sinon plus personne ne peut entrer pour lire le diagnostic.
$r = $req('/index.php?p=login');
ok('la page de connexion s\'affiche quand même', $r['code'] === 200 && str_contains($r['body'], '</html>'),
   'HTTP ' . $r['code']);
$req('/index.php?p=login', ['password' => $PASS]);

$expectAdmin('accueil', $req('/index.php'), 503, 'ne peut pas ouvrir sa base', 'chmod');
ok('l\'en-tête Retry-After est envoyé', str_contains(strtolower($req('/index.php')['head']), 'retry-after'));

// Public : la page de statut ne dit ni le moteur, ni le chemin, ni la cause.
$pub = $req('/index.php?p=status&token=jeton-infra');
ok('statut public : code 503', $pub['code'] === 503, 'reçu ' . $pub['code']);
ok('statut public : message neutre', stripos($pub['body'], 'indisponible') !== false);
foreach (['sqlite', 'chmod', $tmp, 'PDOException', 'SQLSTATE'] as $leak) {
    ok('statut public : rien sur « ' . $leak . ' »', stripos($pub['body'], $leak) === false);
}

// L'API rend du JSON : un écran qui reçoit du HTML n'affiche rien.
$api = $req('/api.php?action=refresh');
$j   = json_decode($api['body'], true);
ok('l\'API répond en JSON', is_array($j), mb_substr($api['body'], 0, 60));
ok('l\'API nomme la panne', is_array($j) && ($j['error'] ?? '') === 'storage', $j['error'] ?? '?');
ok('l\'API joint le remède', is_array($j) && !empty($j['fix']));

// Le battement est appelé par un script tiers : du texte, et rien de technique.
$beat = $req('/beat.php?k=inexistant');
ok('battement : code 503', $beat['code'] === 503, 'reçu ' . $beat['code']);
ok('battement : texte, pas de HTML', !str_contains($beat['body'], '<'), mb_substr($beat['body'], 0, 50));
ok('battement : rien de technique', stripos($beat['body'], 'sqlstate') === false
   && !str_contains($beat['body'], $tmp));

// La passe planifiée doit échouer visiblement : c'est le mail du planificateur.
$cron = $req('/cron.php?key=cle-infra');
ok('cron : code 500 sur échec', $cron['code'] === 500, 'reçu ' . $cron['code']);
ok('cron : la raison est écrite', stripos($cron['body'], 'erreur') !== false);
$down();

// =========================================================================
title('La base est corrompue');
// =========================================================================
$corrupt = $tmp . '/corrompue.sqlite';
file_put_contents($corrupt, random_bytes(40000));
$writeCfg(['driver' => 'sqlite', 'sqlite' => $corrupt]);
$up();
$req('/index.php?p=login', ['password' => $PASS]);
$expectAdmin('base corrompue', $req('/index.php'), 503, 'illisible', 'sauvegarde');
$down();

// =========================================================================
title('La base est en lecture seule');
// =========================================================================
// La base a son propre dossier : verrouiller $tmp empêcherait aussi curl
// d'écrire son fichier de cookies, et le test perdrait sa session.
mkdir($tmp . '/seule', 0775, true);
$ro = $tmp . '/seule/lecture.sqlite';
$writeCfg(['driver' => 'sqlite', 'sqlite' => $ro]);
$up();
$req('/index.php?p=login', ['password' => $PASS]);
$req('/index.php');                       // migration d'abord : schéma valide
$down();
chmod($ro, 0444);
chmod($tmp . '/seule', 0555);             // SQLite écrit aussi le journal à côté
$up();
$req('/index.php?p=login', ['password' => $PASS]);
$r = $req('/index.php');
ok('lecture seule : code 503', $r['code'] === 503, 'reçu ' . $r['code']);
ok('lecture seule : la cause est nommée',
   stripos($r['body'], 'lecture seule') !== false || stripos($r['body'], 'ouvrir sa base') !== false,
   mb_substr(strip_tags($r['body']), 0, 70));
$down();
chmod($tmp . '/seule', 0755);
chmod($ro, 0644);

// =========================================================================
title('Le serveur MySQL ne répond pas');
// =========================================================================
// Un port fermé : on en réserve un puis on le relâche, personne n'écoute.
$s2 = stream_socket_server('tcp://127.0.0.1:0', $e1, $e2);
$dead = (int)explode(':', (string)stream_socket_get_name($s2, false))[1];
fclose($s2);
$writeCfg(['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => $dead, 'name' => 'absente',
           'user' => 'personne', 'pass' => 'rien', 'charset' => 'utf8mb4']);
$up();
$req('/index.php?p=login', ['password' => $PASS]);
$expectAdmin('MySQL éteint', $req('/index.php'), 503, 'ne répond pas', 'config.php');
$pub = $req('/index.php?p=status&token=jeton-infra');
ok('statut public : ni hôte ni port', stripos($pub['body'], '127.0.0.1') === false
   && !str_contains($pub['body'], (string)$dead));
$down();

// =========================================================================
title('config.php inutilisable');
// =========================================================================
foreach ([
    ['syntaxe cassée',        "<?php return [ 'db' => "],
    ['ne renvoie pas un tableau', "<?php return 'bonjour';\n"],
] as [$scene, $content]) {
    file_put_contents($cfg, $content);
    $up();
    $r = $req('/index.php');
    ok("config $scene : corps non vide", trim($r['body']) !== '', 'HTTP ' . $r['code']);
    ok("config $scene : message neutre pour un anonyme",
       stripos($r['body'], 'indisponible') !== false || stripos($r['body'], 'configuration') !== false);
    // Personne n'est connecté : aucune information de chemin ne doit sortir.
    ok("config $scene : aucun chemin divulgué", !str_contains($r['body'], $tmp));
    $down();
}

// Fichier illisible : le cas du chmod 600 posé par un panneau d'hébergeur.
$writeCfg(['driver' => 'sqlite', 'sqlite' => $tmp . '/ok.sqlite']);
chmod($cfg, 0000);
$up();
$r = $req('/index.php');
ok('config illisible : corps non vide', trim($r['body']) !== '', 'HTTP ' . $r['code']);
$down();
chmod($cfg, 0644);

// =========================================================================
title('Le journal des erreurs');
// =========================================================================
$log = $ROOT . '/data/erreurs.log';
ok('les pannes sont écrites dans data/erreurs.log', is_file($log) && filesize($log) > 0,
   is_file($log) ? human_bytes((int)filesize($log)) : 'absent');
ok('le journal contient la trace complète',
   is_file($log) && str_contains((string)file_get_contents($log), 'Db.php'));
// Une panne en boucle ne doit pas remplir le disque qu'elle dénonce.
ok('le journal est borné à 2 Mo', !is_file($log) || filesize($log) <= 2 * 1024 * 1024 + 65536,
   is_file($log) ? human_bytes((int)filesize($log)) : '-');

// =========================================================================
title('Classement des causes qu\'on ne peut pas provoquer');
// =========================================================================
// Chaque message est celui que rend réellement le moteur concerné.
$cases = [
    ['SQLSTATE[HY000] [14] unable to open database file',                'storage',    503],
    ['SQLSTATE[HY000] [26] file is not a database',                      'corrupt',    503],
    ['SQLSTATE[HY000]: General error: 11 database disk image is malformed', 'corrupt',  503],
    ['SQLSTATE[HY000]: General error: 8 attempt to write a readonly database', 'readonly', 503],
    ['SQLSTATE[HY000]: General error: 10 disk I/O error',                'disk',       503],
    ['SQLSTATE[HY000]: General error: 13 database or disk is full',      'disk',       503],
    ['SQLSTATE[HY000]: General error: 5 database is locked',             'locked',     503],
    ['SQLSTATE[HY000] [2002] Connection refused',                        'db_down',    503],
    ["SQLSTATE[HY000] [2002] Can't connect to local MySQL server through socket", 'db_down', 503],
    ["SQLSTATE[28000] [1045] Access denied for user 'x'@'localhost'",     'db_auth',    503],
    ["SQLSTATE[HY000] [1049] Unknown database 'uptimeez'",                'db_missing', 503],
    ['SQLSTATE[08004] [1040] Too many connections',                      'db_busy',    503],
    ['SQLSTATE[HY000]: General error: 2006 MySQL server has gone away',  'db_busy',    503],
    ['Appel d\'une fonction inexistante',                                'internal',   500],
];
foreach ($cases as [$msg, $slug, $code]) {
    $d = Fail::explain($msg, new PDOException($msg));
    ok('« ' . mb_substr($msg, 0, 34) . '… »', ($d['slug'] ?? '') === $slug && ($d['code'] ?? 0) === $code,
       ($d['slug'] ?? '?') . ' / ' . ($d['code'] ?? '?'));
}
// Un config.php cassé n'est pas une PDOException : il arrive avant la base.
$d = Fail::explain('config.php doit renvoyer un tableau, or il renvoie string : /x/config.php', null);
ok('config.php reconnu sans exception PDO', ($d['slug'] ?? '') === 'config', $d['slug'] ?? '?');

// Chaque diagnostic doit proposer au moins un geste : un constat sans remède
// laisse l'exploitant exactement là où il était.
$sansRemede = [];
foreach ($cases as [$msg]) {
    $d = Fail::explain($msg, new PDOException($msg));
    if (empty($d['fixes'])) $sansRemede[] = mb_substr($msg, 0, 30);
}
ok('chaque cause propose un geste', $sansRemede === [], implode(', ', $sansRemede));

echo "\n" . str_repeat('═', 68) . "\n";
echo "$pass contrôle(s) réussi(s), $fail échec(s)\n";
echo $fail === 0
    ? "✅ Une panne d'UptimeEZ s'explique, et ne s'échappe jamais côté public.\n"
    : "⚠️  Une panne d'infrastructure reste muette ou trop bavarde.\n";
exit($fail === 0 ? 0 : 1);
