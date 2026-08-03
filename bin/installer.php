<?php

/**
 * L'installation en ligne de commande : la deuxième des trois voies.
 *
 * ------------------------------------------------------------------------------
 * POURQUOI TROIS VOIES, ET LAQUELLE FAIT RÉFÉRENCE
 * ------------------------------------------------------------------------------
 *
 * La voie de référence reste `install.php` dans un navigateur : elle montre la liste des
 * contrôles d'environnement, elle explique ce qui manque, et c'est celle que la
 * documentation décrit. Les deux autres existent pour des cas précis et ne la remplacent
 * pas :
 *
 *   - CE SCRIPT, quand on installe par SSH, quand on pose dix instances, ou quand on ne veut
 *     pas ouvrir une adresse d'administration sur un serveur public le temps de
 *     l'installation ;
 *   - L'IMAGE DOCKER, quand la machine est à nous et qu'on préfère une commande à un
 *     transfert de fichiers.
 *
 * CE QUE CE SCRIPT NE FAIT PAS, ET C'EST DÉLIBÉRÉ. Il ne télécharge rien et ne met rien à
 * jour : un installeur qui va chercher du code sur le réseau est un vecteur, et la promesse
 * du produit est qu'on sait ce qu'on a posé. Les fichiers sont déjà là quand ce script
 * tourne, sinon il n'aurait pas pu être lancé.
 *
 * ------------------------------------------------------------------------------
 * IL REFUSE D'ÉCRASER UNE INSTALLATION EXISTANTE
 * ------------------------------------------------------------------------------
 *
 * Comme `install.php`, et pour la même raison : réécrire config.php redéfinit le mot de
 * passe d'accès. Sur ce chemin le risque est même plus grand, puisqu'un script se relance
 * par une flèche vers le haut. Il faut supprimer config.php à la main pour réinstaller.
 *
 * ------------------------------------------------------------------------------
 * USAGE
 * ------------------------------------------------------------------------------
 *
 *   php bin/installer.php                                  interactif, il demande ce qu'il faut
 *   php bin/installer.php --mot-de-passe=… --url=…          non interactif
 *   php bin/installer.php --mysql --db-nom=… --db-user=…    avec MySQL au lieu de SQLite
 *   php bin/installer.php --verifier                        contrôle l'environnement et sort
 *
 * Le mot de passe peut aussi arriver par la variable d'environnement UPTIMEEZ_MOT_DE_PASSE,
 * ce qui évite de le laisser dans l'historique du shell.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("À lancer en ligne de commande.\n");
}

require __DIR__ . '/../src/bootstrap.php';

use Uptimeez\Config;
use Uptimeez\Db;

/** @return array<string,string> */
function options(array $argv): array
{
    $out = [];

    foreach (array_slice($argv, 1) as $arg) {
        if (! str_starts_with($arg, '--')) {
            continue;
        }

        $paire = explode('=', substr($arg, 2), 2);
        $out[$paire[0]] = $paire[1] ?? '1';
    }

    return $out;
}

function dire(string $texte): void
{
    echo $texte . "\n";
}

function demander(string $question, bool $muet = false): string
{
    echo $question . ' ';

    if ($muet && function_exists('shell_exec')) {
        // Le mot de passe ne s'affiche pas : un écran partagé ou une capture de session le
        // garderait sinon en clair. Le repli est assumé si stty manque.
        @shell_exec('stty -echo 2>/dev/null');
        $reponse = trim((string) fgets(STDIN));
        @shell_exec('stty echo 2>/dev/null');
        echo "\n";

        return $reponse;
    }

    return trim((string) fgets(STDIN));
}

$opt = options($argv);

// ---------------------------------------------------------------------------
// 1. L'ENVIRONNEMENT, EXACTEMENT LES MÊMES CONTRÔLES QUE L'INSTALLEUR WEB.
//
// Les deux listes doivent dire la même chose : deux installeurs qui n'exigent pas la même
// chose produisent une installation qui marche par une voie et pas par l'autre, et le
// message d'erreur arrive alors des heures plus tard, sur un autre sujet.
// ---------------------------------------------------------------------------
$controles = [];
$controles[] = ['PHP 8.2 ou plus récent', PHP_VERSION_ID >= 80200, PHP_VERSION];

foreach (['curl' => 'requêtes HTTP', 'pdo' => 'base de données', 'openssl' => 'certificats SSL',
          'mbstring' => 'texte UTF-8', 'json' => 'échanges JSON'] as $ext => $pourquoi) {
    $controles[] = ["Extension $ext ($pourquoi)", extension_loaded($ext),
                    extension_loaded($ext) ? 'présente' : 'absente'];
}

$sqlite = in_array('sqlite', PDO::getAvailableDrivers(), true);
$mysql  = in_array('mysql', PDO::getAvailableDrivers(), true);
$controles[] = ['Pilote SQLite ou MySQL', $sqlite || $mysql,
                implode(', ', PDO::getAvailableDrivers()) ?: 'aucun'];

$dossierData = UPTIMEEZ_ROOT . '/data';

if (! is_dir($dossierData)) {
    @mkdir($dossierData, 0775, true);
}

$controles[] = ['Dossier data/ accessible en écriture', is_writable($dossierData), $dossierData];
$controles[] = ['Racine accessible en écriture (config.php)', is_writable(UPTIMEEZ_ROOT), UPTIMEEZ_ROOT];

dire('');
dire('Environnement');
dire(str_repeat('─', 68));

$bloquants = 0;

foreach ($controles as [$libelle, $ok, $detail]) {
    printf(" %s %-46s %s\n", $ok ? 'OK  ' : 'MANQUE', $libelle, $detail);
    $bloquants += $ok ? 0 : 1;
}

dire('');

if ($bloquants > 0) {
    dire("⚠️  $bloquants prérequis manquant(s) : rien n'a été écrit.");
    exit(1);
}

if (isset($opt['verifier'])) {
    dire('✅ Environnement conforme. Rien n\'a été écrit, --verifier ne fait que regarder.');
    exit(0);
}

// ---------------------------------------------------------------------------
// 2. LE REFUS D'ÉCRASER.
// ---------------------------------------------------------------------------
if (Config::isInstalled()) {
    dire('UptimeEZ est déjà installé sur cette copie.');
    dire('Pour repartir de zéro : supprimez config.php, puis relancez. Le dossier data/ est');
    dire('conservé, donc l\'historique survit à une réinstallation.');
    exit(1);
}

// ---------------------------------------------------------------------------
// 3. LES RÉPONSES : en option, en variable d'environnement, ou demandées.
// ---------------------------------------------------------------------------
$motDePasse = (string) ($opt['mot-de-passe'] ?? getenv('UPTIMEEZ_MOT_DE_PASSE') ?: '');

if ($motDePasse === '') {
    $motDePasse = demander('Mot de passe d\'accès (8 caractères minimum) :', true);
}

if (strlen($motDePasse) < 8) {
    dire('⚠️  Le mot de passe doit faire au moins 8 caractères. Rien n\'a été écrit.');
    exit(1);
}

$url = rtrim((string) ($opt['url'] ?? ''), '/');

if ($url === '') {
    // L'adresse n'est pas obligatoire, et le dire évite de la remplir n'importe comment :
    // elle ne sert qu'à mettre un lien cliquable dans les alertes.
    $url = rtrim(demander('Adresse publique de cette installation (facultatif, pour les liens des alertes) :'), '/');
}

$fuseau = (string) ($opt['fuseau'] ?? 'Europe/Paris');
$pilote = isset($opt['mysql']) ? 'mysql' : 'sqlite';

$patch = [
    'db' => ['driver' => $pilote],
    'auth' => ['password_hash' => password_hash($motDePasse, PASSWORD_DEFAULT)],
    'app' => [
        'base_url' => $url,
        'timezone' => $fuseau,
        // La clé de cron est tirée au sort ici comme dans l'installeur web : une clé
        // choisie par l'exploitant serait devinable, et elle autorise l'exécution de la
        // passe par une URL publique.
        'cron_key' => bin2hex(random_bytes(12)),
    ],
];

if ($pilote === 'mysql') {
    $patch['db'] += [
        'host' => (string) ($opt['db-hote'] ?? 'localhost'),
        'port' => (int) ($opt['db-port'] ?? 3306),
        'name' => (string) ($opt['db-nom'] ?? ''),
        'user' => (string) ($opt['db-user'] ?? ''),
        'pass' => (string) ($opt['db-pass'] ?? getenv('UPTIMEEZ_DB_PASS') ?: ''),
        'charset' => 'utf8mb4',
    ];

    if ($patch['db']['name'] === '' || $patch['db']['user'] === '') {
        dire('⚠️  Avec --mysql, il faut au moins --db-nom et --db-user. Rien n\'a été écrit.');
        exit(1);
    }
} else {
    $patch['db']['sqlite'] = $dossierData . '/uptimeez.sqlite';
}

// ---------------------------------------------------------------------------
// 4. L'ÉCRITURE, puis la base, puis la protection du dossier de données.
// ---------------------------------------------------------------------------
if (! Config::save($patch)) {
    dire('⚠️  Impossible d\'écrire config.php. Vérifiez les droits sur ' . UPTIMEEZ_ROOT . '.');
    exit(1);
}

try {
    Db::migrate();
} catch (Throwable $e) {
    dire('⚠️  Connexion à la base impossible : ' . $e->getMessage());
    dire('    config.php a été écrit : corrigez-le, puis relancez « php bin/installer.php ».');
    exit(1);
}

// Les mêmes deux fichiers que l'installeur web. Sur nginx, qui ignore .htaccess, il faut
// déplacer data/ hors de la racine web : la documentation le dit, et ce script ne peut pas
// le faire à la place de l'exploitant sans deviner son arborescence.
@file_put_contents($dossierData . '/.htaccess',
    "Require all denied\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n");
@file_put_contents($dossierData . '/index.html', '');

// ---------------------------------------------------------------------------
// 5. CE QU'IL RESTE À FAIRE, ET LA LIGNE EXACTE À COPIER.
//
// Un installeur qui finit par « installation terminée » sans donner la ligne de cron laisse
// une installation qui ne surveille rien, et c'est l'état le plus trompeur possible :
// l'écran s'ouvre, tout est vert, et rien ne tourne.
// ---------------------------------------------------------------------------
dire('');
dire('✅ Installé. Base créée, config.php écrit.');
dire('');
dire('Il reste UNE chose, sans laquelle rien ne sera surveillé : la tâche planifiée.');
dire('');
dire('  * * * * * ' . PHP_BINDIR . '/php ' . UPTIMEEZ_ROOT . '/cron.php >/dev/null 2>&1');
dire('');
dire('Une exécution par minute suffit quels que soient vos intervalles : le moteur choisit');
dire('lui-même les sondes dues. L\'écran des réglages annonce « inactive » tant qu\'aucune');
dire('passe n\'a eu lieu depuis quinze minutes, ce qui est votre contrôle que la ligne marche.');
dire('');
dire('Sans accès à crontab, les réglages donnent une URL secrète à appeler depuis un');
dire('planificateur externe.');
dire('');
exit(0);
