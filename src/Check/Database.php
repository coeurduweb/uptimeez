<?php
declare(strict_types=1);

namespace Uptimer\Check;

use Uptimer\Http;
use Uptimer\Response;

/**
 * Détection « base de données HS ».
 *
 * Trois angles :
 *  1. Signatures d'erreur dans la réponse (un serveur peut renvoyer 200 avec
 *     « Error establishing a database connection » en clair).
 *  2. Absence de la chaîne de preuve fournie par l'utilisateur : cette chaîne
 *     provient du contenu, donc si elle manque alors que le HTML arrive,
 *     c'est que la couche données ne répond plus.
 *  3. Sonde applicative selon le CMS (wp-json, /api/health…), qui touche
 *     réellement la base et le confirme sans ambiguïté.
 */
final class Database
{
    /** Signatures classées par moteur / framework. */
    private const SIGNATURES = [
        // WordPress
        'error establishing a database connection'      => 'WordPress : connexion à la base impossible',
        'erreur lors de la connexion à la base'         => 'WordPress : connexion à la base impossible',
        'wordpress database error'                      => 'Erreur SQL WordPress',
        'une erreur critique est survenue sur votre site'=> 'Erreur critique WordPress (souvent base ou plugin)',
        'there has been a critical error on this website'=> 'Erreur critique WordPress',
        'ex_db_connect_error'                           => 'Connexion base impossible',
        // MySQL / MariaDB
        'too many connections'                          => 'MySQL : trop de connexions simultanées',
        "can't connect to local mysql"                  => 'MySQL local injoignable',
        "can't connect to mysql server"                 => 'Serveur MySQL injoignable',
        'lost connection to mysql'                      => 'Connexion MySQL perdue',
        'access denied for user'                        => 'MySQL : identifiants refusés',
        'unknown database'                              => 'MySQL : base inconnue',
        'mysql server has gone away'                    => 'MySQL a coupé la connexion',
        'table is marked as crashed'                    => 'Table MySQL corrompue',
        "doesn't exist in engine"                       => 'Table MySQL absente du moteur',
        'incorrect key file for table'                  => 'Index MySQL corrompu (disque plein ?)',
        'the table is full'                             => 'Table pleine / quota disque atteint',
        'disk full'                                     => 'Disque plein sur le serveur',
        // PDO / PHP
        'sqlstate['                                     => 'Erreur SQL (SQLSTATE)',
        'pdoexception'                                  => 'PDOException',
        'mysqli_sql_exception'                          => 'Exception mysqli',
        'mysqli::real_connect'                          => 'Échec de connexion mysqli',
        'call to a member function prepare() on null'   => 'Connexion base non initialisée',
        // Frameworks & autres CMS
        'doctrine\\dbal\\exception'                     => 'Doctrine : erreur base de données',
        'illuminate\\database\\queryexception'          => 'Laravel : erreur de requête',
        'databaseexception'                             => 'Erreur base de données',
        'link to database cannot be established'        => 'Joomla : base injoignable',
        'jdatabase'                                     => 'Erreur base Joomla',
        'drupal\\core\\database'                        => 'Erreur base Drupal',
        'prestashop cannot connect to the database'     => 'PrestaShop : base injoignable',
        'no such table'                                 => 'Table absente (SQLite)',
        'database is locked'                            => 'Base SQLite verrouillée',
        'psycopg2.operationalerror'                     => 'PostgreSQL injoignable',
        'could not connect to server'                   => 'Serveur de base injoignable',
        'redis connection'                              => 'Connexion Redis en échec',
    ];

    /**
     * En dessous de cette taille, une page qui contient une signature d'erreur
     * EST une page d'erreur. La page WordPress « Error establishing a database
     * connection » pèse un peu plus de 200 octets ; la plus bavarde des pages
     * d'erreur de framework tient sous 8 Ko une fois le HTML compté.
     */
    private const ERROR_PAGE_BYTES = 8192;

    /** Signatures d'erreur applicative non-BDD, utiles à distinguer. */
    private const PHP_FATAL = [
        'fatal error:'      => 'Erreur fatale PHP',
        'parse error:'      => 'Erreur de syntaxe PHP',
        'allowed memory size' => 'Mémoire PHP épuisée',
        'maximum execution time' => 'Temps d\'exécution PHP dépassé',
        'uncaught exception' => 'Exception PHP non interceptée',
        'whoops, looks like something went wrong' => 'Erreur applicative (Laravel)',
    ];

    /**
     * Une signature trouvée dans une page conclut-elle à une panne ?
     *
     * Pas toujours, et c'était le trou. « Error establishing a database
     * connection » dans un article de blog qui explique comment la corriger,
     * « too many connections » dans une documentation d'hébergeur, « disk full »
     * dans un billet technique : la sonde déclarait le site hors service. Pour
     * une agence dont les clients sont parfois des hébergeurs ou des
     * développeurs, c'est la fausse alerte de trois heures du matin garantie.
     *
     * Une vraie page d'erreur de base se reconnaît autrement que par un mot. Il
     * en faut au moins un de ces trois signes, et un seul suffit :
     *
     *   - le serveur répond 5xx : il annonce lui-même son échec ;
     *   - la page est courte : la page WordPress « connexion impossible » pèse
     *     quelques centaines d'octets, un article qui en parle des dizaines de
     *     milliers ;
     *   - la chaîne de preuve a disparu. C'est le signe le plus fort : ce texte
     *     ne peut venir que de la base, donc son absence dit que la couche
     *     données est partie.
     *
     * Aucun des trois : la signature est affichée sur une page qui répond
     * normalement, avec son pied de page intact. C'est un défaut visible, à
     * montrer — mais « dégradé », pas « hors service ».
     *
     * @return array{state:string,reason:?string,message:?string,evidence:?string,probe:?array}
     */
    public static function audit(Response $res, array $monitor, array $opt = []): array
    {
        $out  = ['state' => 'ok', 'reason' => null, 'message' => null, 'evidence' => null, 'probe' => null];
        $body = $res->body;
        if ($body === '') return $out;

        // On ne scanne que le début + la fin : les traces sont soit en tête, soit en pied.
        $hay = strtolower(substr($body, 0, 60000));
        if (strlen($body) > 80000) $hay .= "\n" . strtolower(substr($body, -20000));

        // La page se comporte-t-elle comme une page d'erreur ?
        $expect  = trim((string)($monitor['expect_string'] ?? ''));
        $proofOk = $expect !== '' && mb_stripos($body, $expect) !== false;
        $broken  = $res->status >= 500
                || strlen($body) < self::ERROR_PAGE_BYTES
                || ($expect !== '' && !$proofOk);

        foreach (self::SIGNATURES as $needle => $label) {
            if (!str_contains($hay, $needle)) continue;
            return $broken ? [
                'state'    => 'down',
                'reason'   => 'DB_DOWN',
                // L'étiquette est un msgid : elle s'écrit dans la langue de
                // l'installation, celle du relevé technique.
                'message'  => t($label),
                'evidence' => self::excerpt($body, $needle),
                'probe'    => null,
            ] : [
                'state'    => 'degraded',
                'reason'   => 'DB_ERROR_VISIBLE',
                'message'  => t('{error} affichée sur une page qui répond normalement',
                                ['error' => t($label)]),
                'evidence' => self::excerpt($body, $needle),
                'probe'    => null,
            ];
        }

        foreach (self::PHP_FATAL as $needle => $label) {
            if (!str_contains($hay, $needle)) continue;
            return $broken ? [
                'state'    => 'down',
                'reason'   => 'APP_ERROR',
                'message'  => t($label),
                'evidence' => self::excerpt($body, $needle),
                'probe'    => null,
            ] : [
                'state'    => 'degraded',
                'reason'   => 'APP_ERROR_VISIBLE',
                'message'  => t('{error} affichée sur une page qui répond normalement',
                                ['error' => t($label)]),
                'evidence' => self::excerpt($body, $needle),
                'probe'    => null,
            ];
        }

        return $out;
    }

    /**
     * Sonde applicative qui traverse réellement la base.
     * Retourne null si le CMS n'expose rien d'exploitable.
     */
    public static function probe(string $baseUrl, ?string $cms, array $opt = []): ?array
    {
        $endpoints = self::endpointsFor($baseUrl, $cms);
        if (!$endpoints) return null;

        foreach ($endpoints as $ep) {
            $res = Http::fetch($ep['url'], [
                'timeout'  => (int)($opt['timeout'] ?? 10),
                'insecure' => (bool)($opt['insecure'] ?? false),
                'ua'       => $opt['ua'] ?? null,
                'maxBody'  => 200000,
                'headers'  => ['Accept' => 'application/json,*/*;q=0.5'],
            ]);
            if (!$res->ok) continue;

            $sig = self::audit($res, []);
            if ($sig['state'] !== 'ok') {
                return ['url' => $ep['url'], 'ok' => false, 'reason' => $sig['reason'],
                        'message' => t('{verdict} (sonde {probe})',
                                       ['verdict' => $sig['message'], 'probe' => t($ep['label'])])];
            }
            if ($res->status >= 500) {
                return ['url' => $ep['url'], 'ok' => false, 'reason' => 'DB_DOWN',
                        'message' => t('Sonde {probe} en erreur {code}',
                                       ['probe' => t($ep['label']), 'code' => (string)$res->status])];
            }
            if ($res->status === 200 && $ep['expect'] !== '' && !str_contains($res->body, $ep['expect'])) {
                continue; // endpoint non concluant, on tente le suivant
            }
            if ($res->status === 200) {
                return ['url' => $ep['url'], 'ok' => true, 'reason' => null,
                        'message' => t('Sonde {probe} OK', ['probe' => t($ep['label'])]),
                        'ms' => $res->totalMs];
            }
        }
        return null;
    }

    private static function endpointsFor(string $baseUrl, ?string $cms): array
    {
        $root = self::root($baseUrl);
        return match ($cms) {
            'WordPress' => [
                ['url' => $root . '/wp-json/', 'label' => 'REST WordPress', 'expect' => '"namespaces"'],
                ['url' => $root . '/?feed=rss2', 'label' => 'flux RSS', 'expect' => '<rss'],
            ],
            'PrestaShop' => [['url' => $root . '/', 'label' => 'accueil', 'expect' => '']],
            'Drupal'     => [['url' => $root . '/user/login', 'label' => 'formulaire de connexion', 'expect' => 'form']],
            'Joomla'     => [['url' => $root . '/index.php?format=feed&type=rss', 'label' => 'flux RSS', 'expect' => '<rss']],
            default      => [],
        };
    }

    private static function root(string $url): string
    {
        $p = parse_url($url);
        if (!$p || empty($p['host'])) return rtrim($url, '/');
        return ($p['scheme'] ?? 'https') . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    }

    private static function excerpt(string $body, string $needle): string
    {
        $pos = stripos($body, $needle);
        if ($pos === false) return '';
        $start = max(0, $pos - 90);
        $chunk = substr($body, $start, 300);
        // Les balises deviennent des espaces : sans cela « </title><h1>Error… »
        // donnerait « ErreurError… », illisible dans l'alerte.
        $chunk = strip_tags(preg_replace('~<[^>]*>~', ' ', $chunk) ?? $chunk);
        return str_cut($chunk, 220);
    }
}
