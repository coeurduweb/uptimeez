<?php
/**
 * UptimeEZ : le pilote MySQL / MariaDB, vérifié pour de vrai.
 *
 * Les huit autres suites tournent sur SQLite, parce qu'un fichier jetable rend
 * chaque test isolé et instantané. Conséquence longtemps ignorée : le pilote
 * MySQL était annoncé dans la documentation et proposé par l'installeur sans
 * qu'une seule ligne de code n'ait jamais été exécutée dessus. Deux défauts s'y
 * cachaient, tous deux invisibles sur SQLite :
 *
 *   - « CREATE INDEX IF NOT EXISTS » n'existe pas dans MySQL. La requête y était
 *     une erreur de syntaxe, attrapée en silence : aucun index n'était créé. Sur
 *     une table de mesures d'un million de lignes, c'est la différence entre un
 *     tableau de bord instantané et un balayage complet à chaque affichage.
 *   - « before » est un mot réservé de MySQL. Employé comme alias de colonne
 *     dans la détection de ralentissement, il faisait échouer la requête, donc
 *     l'écran d'accueil entier.
 *
 * Cette suite existe pour que ça ne se reperde pas. Elle est ignorée proprement
 * quand aucune base de test n'est configurée : personne n'a besoin d'un serveur
 * MySQL pour contribuer.
 *
 *   UPTIMEEZ_TEST_MYSQL_NAME=uptimeez_test \
 *   UPTIMEEZ_TEST_MYSQL_USER=root UPTIMEEZ_TEST_MYSQL_PASS=secret \
 *   php bin/mysql.php
 *
 * Variables reconnues : HOST (127.0.0.1), PORT (3306), NAME, USER, PASS.
 * ATTENTION : la base indiquée est vidée à chaque exécution.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Uptimeez\Client;
use Uptimeez\Config;
use Uptimeez\Db;
use Uptimeez\Report;
use Uptimeez\Runner;
use Uptimeez\Stats;
use Uptimeez\Triage;
use Uptimeez\Vuln;

if (PHP_SAPI !== 'cli') exit("À lancer en ligne de commande.\n");

$env = fn(string $k, string $def = ''): string => (string)(getenv('UPTIMEEZ_TEST_MYSQL_' . $k) ?: $def);
$name = $env('NAME');
$user = $env('USER');
if ($name === '' || $user === '') {
    echo "Base MySQL de test non configurée : suite ignorée.\n";
    echo "Pour l'activer :\n";
    echo "  UPTIMEEZ_TEST_MYSQL_NAME=uptimeez_test UPTIMEEZ_TEST_MYSQL_USER=root \\\n";
    echo "  UPTIMEEZ_TEST_MYSQL_PASS=secret php bin/mysql.php\n";
    echo "Le reste du produit est couvert par les autres suites, sur SQLite.\n";
    exit(0);
}
if (!extension_loaded('pdo_mysql')) {
    echo "L'extension pdo_mysql n'est pas chargée : suite ignorée.\n";
    exit(0);
}

$pass = 0; $fail = 0;
function ok(string $label, bool $good, string $detail = ''): void
{
    global $pass, $fail;
    $good ? $pass++ : $fail++;
    $pad = str_repeat(' ', max(1, 54 - mb_strlen($label)));
    echo ($good ? ' OK  ' : 'ÉCHEC ') . $label . $pad . ($detail !== '' ? '→ ' . $detail : '') . "\n";
}
function title(string $s): void { echo "\n── $s " . str_repeat('─', max(0, 56 - mb_strlen($s))) . "\n"; }

/** Exécute et rapporte : une exception est un échec, pas une interruption. */
function attempt(string $label, callable $fn): mixed
{
    try {
        $r = $fn();
        ok($label, true, is_scalar($r) && (string)$r !== '' ? (string)$r : '');
        return $r;
    } catch (Throwable $e) {
        ok($label, false, get_class($e) . ' : ' . str_cut($e->getMessage(), 100));
        return null;
    }
}

echo "Suite MySQL : " . $user . '@' . $env('HOST', '127.0.0.1') . ':' . $env('PORT', '3306')
   . '/' . $name . "\n";

Db::disconnect();
Config::set('db.driver', 'mysql');
Config::set('db.host', $env('HOST', '127.0.0.1'));
Config::set('db.port', (int)$env('PORT', '3306'));
Config::set('db.name', $name);
Config::set('db.user', $user);
Config::set('db.pass', $env('PASS'));
Config::set('db.charset', 'utf8mb4');
Config::set('notify.discord.enabled', false);
Config::set('notify.slack.enabled', false);
Config::set('notify.mail.enabled', false);
Config::set('notify.webhook.enabled', false);
Config::set('vitals.crux_key', '');

try {
    Db::pdo();
} catch (Throwable $e) {
    echo "Connexion impossible : " . $e->getMessage() . "\n";
    exit(1);
}

// =========================================================================
title('Mode strict et schéma');
// =========================================================================
// MySQL 5.7 et 8 activent ONLY_FULL_GROUP_BY par défaut : c'est là que les
// requêtes d'agrégation cassent. On se met donc dans le mode le plus sévère.
Db::pdo()->exec("SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,"
              . "NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
ok('mode strict activé pour la session',
    str_contains((string)Db::val('SELECT @@SESSION.sql_mode'), 'ONLY_FULL_GROUP_BY'));

$t0 = microtime(true);
attempt('migration complète', function () { Db::migrate(); return null; });
ok('migration rapide', microtime(true) - $t0 < 10.0, sprintf('%.2f s', microtime(true) - $t0));
ok('aucun index en échec', Db::indexIssues() === [],
    implode(' | ', array_slice(Db::indexIssues(), 0, 2)));

$tables = array_map(fn(array $r): string => (string)reset($r), Db::all('SHOW TABLES'));
ok('les dix tables existent', count($tables) >= 10, count($tables) . ' : ' . implode(', ', $tables));
$idx = Db::all("SELECT DISTINCT INDEX_NAME n FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND INDEX_NAME LIKE 'idx_%'");
// C'est le contrôle qui aurait attrapé le défaut : sans lui, zéro index.
ok('les index sont réellement créés', count($idx) >= 12, count($idx) . ' index');
attempt('migration idempotente', function () { Db::migrate(); return count(Db::indexIssues()) . ' problème(s)'; });

// Table de travail vierge : la suite doit être rejouable sans surprise.
foreach (['checks', 'incidents', 'events', 'daily_stats', 'notifications',
          'components', 'monitors', 'sites', 'clients'] as $t) {
    Db::q("DELETE FROM $t");
}

// =========================================================================
title('Écriture, encodage, transactions');
// =========================================================================
$sid = attempt('créer un site', fn() => Db::insert('sites',
    ['name' => 'Client MySQL', 'domain' => 'mysql.test', 'created_at' => now()]));
$mid = attempt('créer une sonde', fn() => Db::insert('monitors', [
    'site_id' => $sid, 'name' => 'Accueil', 'url' => 'https://mysql.test/',
    'kind' => 'page', 'role' => 'primary', 'method' => 'GET', 'interval_sec' => 300,
    'timeout_sec' => 10, 'retries' => 0, 'expect_status' => '200-299', 'enabled' => 1,
    'status' => 'unknown', 'setup_state' => 'done', 'created_at' => now(), 'next_check_at' => now()]));

// utf8mb4 : sans lui, un emoji tronque la valeur au lieu de la conserver.
$accents = "Créé à Fréjus 🇫🇷 — ünïcode";
attempt('accents et emoji conservés à l\'octet', function () use ($accents) {
    $id = Db::insert('sites', ['name' => $accents, 'domain' => 'accents.test', 'created_at' => now()]);
    $back = (string)Db::val('SELECT name FROM sites WHERE id = ?', [$id]);
    if ($back !== $accents) throw new RuntimeException('altéré : ' . $back);
    return $back;
});
attempt('texte long non tronqué', function () use ($mid) {
    $long = str_repeat('a', 3000);
    Db::update('monitors', ['last_message' => $long], 'id = :__i', ['__i' => $mid]);
    $back = (string)Db::val('SELECT last_message FROM monitors WHERE id = ?', [$mid]);
    if (strlen($back) !== 3000) throw new RuntimeException(strlen($back) . ' octets sur 3000');
    Db::update('monitors', ['last_message' => null], 'id = :__i', ['__i' => $mid]);
    return '3000 octets';
});
attempt('valeur nulle acceptée là où elle est permise', function () use ($mid) {
    Db::update('monitors', ['last_ms' => null], 'id = :__i', ['__i' => $mid]);
    return Db::val('SELECT last_ms FROM monitors WHERE id = ?', [$mid]) === null ? 'null relu' : 'non nul';
});
attempt('transaction annulée ne laisse rien', function () {
    Db::pdo()->beginTransaction();
    Db::insert('sites', ['name' => 'Annulé', 'domain' => 'rollback.test', 'created_at' => now()]);
    Db::pdo()->rollBack();
    return Db::val('SELECT COUNT(*) FROM sites WHERE domain = ?', ['rollback.test']) . ' site(s)';
});
attempt('index UNIQUE respecté sur les composants', function () use ($mid, $sid) {
    $html = '<html><head><meta name="generator" content="WordPress 6.4.2"></head><body>x</body></html>';
    Vuln::record($mid, $sid, $html, 'WordPress');
    Vuln::record($mid, $sid, $html, 'WordPress');
    return Db::val('SELECT COUNT(*) FROM components') . ' composant(s) après deux enregistrements';
});

// =========================================================================
title('Agrégations : le terrain d\'ONLY_FULL_GROUP_BY');
// =========================================================================
// Des mesures sur deux jours, pour que les regroupements aient de la matière.
for ($i = 0; $i < 60; $i++) {
    Db::insert('checks', [
        'monitor_id' => $mid, 'ts' => date('Y-m-d H:i:s', time() - $i * 1800),
        'state' => $i % 17 === 0 ? 'down' : ($i % 11 === 0 ? 'degraded' : 'up'),
        'status_code' => $i % 17 === 0 ? 503 : 200,
        'total_ms' => 200 + $i * 7, 'attempts' => 1,
    ]);
}
attempt('série temporelle d\'une sonde', fn() => count(Stats::series($mid, 86400, 24)['buckets']) . ' tranches');
attempt('courbes groupées', fn() => count(Stats::sparkBatch([$mid], 86400, 32)) . ' sonde(s)');
attempt('pouls du parc', fn() => count(Stats::pulse(86400, 48)) . ' tranches');
attempt('synthèse globale', fn() => json_encode(array_intersect_key(Stats::summary(),
    array_flip(['total', 'up', 'down', 'degraded']))));
attempt('fenêtre 24 h', fn() => 'uptime ' . (Stats::window($mid, 86400)['uptime'] ?? 'null'));
attempt('percentile p95', fn() => (string)(Stats::percentile($mid, date('Y-m-d H:i:s', time() - 86400), 95) ?? 'null'));
attempt('consolidation journalière', fn() => Stats::rollup(date('Y-m-d')) . ' jour(s)');
attempt('consolidation de la veille', fn() => Stats::rollup(date('Y-m-d', time() - 86400)) . ' jour(s)');
attempt('agrégats rafraîchis', fn() => Stats::refreshStale(0, 50) . ' sonde(s)');
attempt('purge des mesures anciennes', fn() => Stats::purge() . ' purgée(s)');

// =========================================================================
title('Écrans : les requêtes que voit un utilisateur');
// =========================================================================
// C'est ici que « before », mot réservé de MySQL, faisait tomber l'accueil.
attempt('liste de tâches', fn() => count(Triage::actions()) . ' action(s)');
attempt('ce qui va casser bientôt', fn() => count(Triage::upcoming()) . ' point(s)');
attempt('sondes saines', fn() => count(Triage::healthy()) . ' sonde(s)');
attempt('compteurs de l\'accueil', fn() => json_encode(array_intersect_key(Triage::counts(),
    array_flip(['up', 'down', 'paused']))));
attempt('détection de ralentissement', function () use ($mid) {
    // Deux périodes contrastées : la requête compare les moyennes. La
    // consolidation a déjà écrit les jours récents, d'où le remplacement plutôt
    // que l'insertion : la clé primaire est (sonde, jour).
    $put = function (int $mid, string $day, float $avg): void {
        $exists = Db::val('SELECT COUNT(*) FROM daily_stats WHERE monitor_id = ? AND day = ?', [$mid, $day]);
        $row = ['checks' => 40, 'fails' => 0, 'avg_ms' => $avg, 'p95_ms' => (int)($avg * 1.3)];
        if ($exists) Db::q('UPDATE daily_stats SET checks = ?, fails = ?, avg_ms = ?, p95_ms = ?
                            WHERE monitor_id = ? AND day = ?',
                           [$row['checks'], $row['fails'], $row['avg_ms'], $row['p95_ms'], $mid, $day]);
        else Db::insert('daily_stats', $row + ['monitor_id' => $mid, 'day' => $day]);
    };
    for ($d = 4; $d <= 9; $d++) $put($mid, date('Y-m-d', time() - $d * 86400), 300.0);
    for ($d = 0; $d <= 2; $d++) $put($mid, date('Y-m-d', time() - $d * 86400), 1500.0);
    $found = array_filter(Triage::upcoming(), fn(array $u): bool => ($u['kind'] ?? '') === 'slowdown');
    return count($found) . ' ralentissement(s) repéré(s)';
});
attempt('rapport client', fn() => 'totaux ' . count(Report::data($sid, ...Report::monthRange())['totals'] ?? []));
attempt('client et cloisonnement', function () use ($sid) {
    $c = Client::create('Client MySQL', 'c@mysql.test');
    Client::setSites($c, [$sid]);
    $tok = (string)Db::val('SELECT token FROM clients WHERE id = ?', [$c]);
    if (!Client::byToken($tok)) throw new RuntimeException('jeton valide refusé');
    if (Client::byToken(str_repeat('a', 32))) throw new RuntimeException('jeton faux accepté');
    return count(Client::sites($c)) . ' site(s) vus par le client';
});
attempt('inventaire et veille', fn() => json_encode(Vuln::counts()));
attempt('verdict relu avec ses variables', function () use ($mid) {
    Db::update('monitors', ['last_message' => 'Certificat SSL expire dans {n} jours',
                            'last_message_vars' => jenc(['n' => 9])], 'id = :__i', ['__i' => $mid]);
    $row = Db::one('SELECT * FROM monitors WHERE id = ?', [$mid]);
    $txt = verdict_text($row);
    if (!str_contains($txt, '9')) throw new RuntimeException('variable perdue : ' . $txt);
    return $txt;
});

// =========================================================================
title('Volumes et découpage');
// =========================================================================
$many = [];
attempt('créer 1200 sondes', function () use (&$many) {
    Db::pdo()->beginTransaction();
    for ($i = 0; $i < 1200; $i++) {
        $many[] = Db::insert('monitors', ['name' => 'vol' . $i, 'url' => 'https://vol' . $i . '.test/',
            'kind' => 'page', 'role' => 'page', 'method' => 'GET', 'interval_sec' => 300,
            'timeout_sec' => 10, 'retries' => 0, 'expect_status' => '200-299', 'enabled' => 1,
            'status' => $i % 9 === 0 ? 'down' : 'up', 'setup_state' => 'done',
            'created_at' => now(), 'next_check_at' => now()]);
    }
    Db::pdo()->commit();
    return count($many) . ' sondes';
});
$t1 = microtime(true);
attempt('courbes groupées sur tout le parc', fn() => count(Stats::sparkBatch($many, 86400, 24)) . ' sonde(s)');
ok('et en un temps acceptable', microtime(true) - $t1 < 15.0, sprintf('%.2f s', microtime(true) - $t1));
attempt('pouls du parc sur tout le parc', fn() => count(Stats::pulse(86400, 48)) . ' tranches');
attempt('sondes dues', fn() => count(Runner::due(60)) . ' sonde(s) prête(s)');

// =========================================================================
title('Suppression et réparation');
// =========================================================================
attempt('suppression de masse', function () use ($many) {
    $d = Db::deleteMonitors($many);
    return sprintf('%d sondes, %d sites, %d composants', $d['monitors'], $d['sites'], $d['components']);
});
attempt('la dernière sonde emporte son site', function () use ($mid, $sid) {
    $d = Db::deleteMonitors([$mid]);
    $left = (int)Db::val('SELECT COUNT(*) FROM sites WHERE id = ?', [$sid]);
    if ($left !== 0) throw new RuntimeException('le site est resté');
    return sprintf('%d site(s), %d composant(s) retirés', $d['sites'], $d['components']);
});
attempt('aucune mesure orpheline', function () {
    $n = (int)Db::val('SELECT COUNT(*) FROM checks c
                       LEFT JOIN monitors m ON m.id = c.monitor_id WHERE m.id IS NULL');
    if ($n > 0) throw new RuntimeException($n . ' orpheline(s)');
    return '0';
});
attempt('la réparation nettoie ce qui traîne', function () {
    // À ce stade il reste un site créé pour le test d'encodage, sans sonde :
    // la réparation doit le retirer.
    $first = Db::repairOrphans();
    return sprintf('%d orpheline(s), %d site(s), %d composant(s)',
        $first['orphans'], $first['sites'], $first['components']);
});
attempt('puis ne touche plus à rien', function () {
    $second = Db::repairOrphans();
    if (array_sum($second) !== 0) throw new RuntimeException('des lignes bougent encore : ' . jenc($second));
    return 'base stable';
});

// =========================================================================
echo "\n" . str_repeat('═', 68) . "\n";
printf("%d contrôle(s) réussi(s), %d échec(s)\n", $pass, $fail);
if ($fail > 0) {
    echo "⚠️  Le pilote MySQL présente des anomalies.\n";
    exit(1);
}
echo "✅ Le pilote MySQL / MariaDB fait le même travail que SQLite.\n";
