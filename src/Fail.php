<?php
/**
 * UptimeEZ : ce qui s'affiche quand UptimeEZ lui-même est en panne.
 *
 * Un outil dont le métier est de dire « ce site est cassé, voilà pourquoi » n'a
 * pas le droit de répondre par une page blanche quand c'est lui qui tombe.
 * L'audit a pourtant trouvé exactement ça : huit pannes d'infrastructure
 * distinctes (dossier data/ non inscriptible, base corrompue, fichier en
 * lecture seule, serveur MySQL éteint, identifiants faux, config.php illisible,
 * cassé ou renvoyant autre chose qu'un tableau) donnaient huit fois le même
 * résultat, un HTTP 500 sans un octet de corps. Sur un hébergement mutualisé,
 * display_errors est coupé : l'exploitant ne voit rien du tout.
 *
 * Deux règles gouvernent cette classe.
 *
 * 1. Le détail technique ne sort que pour qui a le droit de le voir. Une page
 *    de statut, un espace client et un battement sont publics : ils répondent
 *    503 avec une phrase neutre. « Access denied for user 'untel'@'localhost' »
 *    ne s'affiche jamais à un visiteur. Le contexte de confiance est explicite
 *    et doit être demandé (Fail::trusted()) : par défaut, on se tait.
 *
 * 2. Le diagnostic vaut mieux que le message d'erreur. « unable to open
 *    database file » n'apprend rien ; « le dossier data/ n'est pas inscriptible,
 *    voici la commande » résout la panne. Chaque cause est donc vérifiée sur le
 *    système de fichiers au moment de l'affichage, pas devinée.
 *
 * Dans tous les cas le détail complet part dans un journal que seul le
 * propriétaire de l'hébergement peut lire.
 */
declare(strict_types=1);

namespace Uptimeez;

use Uptimeez\I18n;

use Throwable;

final class Fail
{
    /** Le visiteur a-t-il droit au détail technique ? Par défaut : non. */
    private static bool $trusted = false;
    /** Réponse attendue : « html », « json » ou « text ». */
    private static string $format = 'html';
    private static bool $installed = false;

    /**
     * Branche les gardes. Appelé par bootstrap.php, donc pour toutes les
     * entrées : web, CLI, cron.
     */
    public static function install(): void
    {
        if (self::$installed) return;
        self::$installed = true;

        // En ligne de commande, celui qui lance la commande est l'exploitant.
        if (PHP_SAPI === 'cli') self::$trusted = true;

        set_exception_handler([self::class, 'handle']);

        // Une erreur fatale (mémoire épuisée, temps dépassé) ne passe pas par
        // set_exception_handler : elle ne se rattrape qu'à l'extinction.
        register_shutdown_function(static function (): void {
            $e = error_get_last();
            if ($e === null || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR,
                                                      E_COMPILE_ERROR, E_USER_ERROR], true)) return;
            self::render($e['message'], $e['file'], (int)$e['line'], null);
        });
    }

    /** Le visiteur est l'exploitant : il peut voir les chemins et les causes. */
    public static function trusted(): void { self::$trusted = true; }

    /** L'appelant attend du JSON (api.php). */
    public static function asJson(): void { self::$format = 'json'; }

    /** L'appelant attend du texte brut (beat.php, cron.php). */
    public static function asText(): void { self::$format = 'text'; }

    public static function handle(Throwable $e): void
    {
        self::render($e->getMessage(), $e->getFile(), $e->getLine(), $e);
        // Sans cette sortie, PHP reprend son chemin d'exception non rattrapée à
        // la fin du gestionnaire et réécrit le code de réponse en 500 : le 503
        // « réessayez plus tard » devenait un 500 « c'est cassé ».
        exit(1);
    }

    // =====================================================================
    // Rendu
    // =====================================================================
    private static function render(string $message, string $file, int $line, ?Throwable $e): void
    {
        $tech = sprintf('%s: %s (%s:%d)', $e ? get_class($e) : 'Fatal', $message, $file, $line);
        self::log($tech, $e);

        $d = self::explain($message, $e);

        // Une sortie déjà commencée interdit d'envoyer un code ou un en-tête :
        // on ajoute alors un encart lisible plutôt que d'échouer en silence.
        $late = headers_sent();
        if (!$late) {
            http_response_code($d['code']);
            header('Cache-Control: no-store');
            if ($d['code'] === 503) header('Retry-After: 120');
        }

        if (self::$format === 'json' && !$late) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(array_filter([
                'error'   => $d['slug'],
                'message' => self::$trusted ? $d['title'] . ' ' . $d['cause'] : $d['public'],
                'detail'  => self::$trusted ? $tech : null,
                'fix'     => self::$trusted ? $d['fixes'] : null,
            ], fn($v) => $v !== null), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        if (self::$format === 'text') {
            if (!$late) header('Content-Type: text/plain; charset=utf-8');
            echo self::$trusted
                ? $d['title'] . "\n" . $d['cause'] . "\n"
                  . ($d['fixes'] ? '- ' . implode("\n- ", $d['fixes']) . "\n" : '')
                  . $tech . "\n"
                : $d['public'] . "\n";
            return;
        }

        if (!$late) header('Content-Type: text/html; charset=utf-8');
        echo self::page($d, $tech, $late);
    }

    /**
     * Le HTML est autonome : ni feuille de style externe, ni police, ni script.
     * La panne peut justement être ce qui empêche de servir assets/style.css, et
     * une page d'erreur qui dépend de ce qui est cassé n'est pas une page
     * d'erreur. Les styles sont donc en ligne, rassemblés ici pour rester
     * lisibles.
     */
    private static function page(array $d, string $tech, bool $late): string
    {
        $s = [
            'page'  => 'font:16px/1.6 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;max-width:44rem;'
                     . 'margin:3rem auto;padding:0 1.25rem;color:#1c2431;background:#fff',
            'brand' => 'font:600 .8rem/1 system-ui;letter-spacing:.08em;text-transform:uppercase;'
                     . 'color:#b4232a;margin:0 0 .5rem',
            'h1'    => 'font-size:1.55rem;line-height:1.25;margin:0 0 .75rem',
            'h1pub' => 'font-size:1.4rem;margin:0 0 .5rem',
            'h2'    => 'font-size:1.05rem;margin:0 0 .5rem',
            'lead'  => 'margin:0 0 1.5rem',
            'mute'  => 'color:#5a6472;margin:0',
            'list'  => 'margin:0 0 1.5rem;padding-left:1.3rem',
            'item'  => 'margin-bottom:.4rem',
            'sum'   => 'cursor:pointer;color:#5a6472',
            'pre'   => 'white-space:pre-wrap;word-break:break-word;background:#f2f4f7;border-radius:6px;'
                     . 'padding:.75rem;font-size:.85rem;margin:.5rem 0 0',
            'note'  => 'color:#5a6472;font-size:.9rem',
            'foot'  => 'color:#5a6472;font-size:.9rem;border-top:1px solid #e2e6ec;padding-top:1rem',
            'code'  => 'display:inline-block;background:#f2f4f7;border:1px solid #e2e6ec;border-radius:4px;'
                     . 'padding:.1rem .35rem;font-size:.9em;user-select:all',
        ];
        $tag = fn(string $el, string $style, string $inner): string
            => '<' . $el . ' style="' . $s[$style] . '">' . $inner . '</' . explode(' ', $el)[0] . '>';

        // Le titre de l'onglet est du contenu visible comme un autre : en contexte
        // public il doit rester neutre, sinon la page de statut d'un client
        // annonce la nature exacte de la panne dans l'onglet du navigateur.
        $title = self::$trusted ? $d['title'] : $d['publicTitle'];
        $head  = '';
        if (!$late) {
            $head = '<!doctype html><html lang="' . e(self::safe(fn() => I18n::lang(), 'en'))
                  . '" dir="' . e(self::safe(fn() => I18n::dir(), 'ltr')) . '">'
                  . '<head><meta charset="utf-8">'
                  . '<meta name="viewport" content="width=device-width,initial-scale=1">'
                  . '<meta name="robots" content="noindex,nofollow">'
                  . '<title>' . e($title) . '</title></head><body>';
        }
        $out = $head . '<div style="' . $s['page'] . '">';

        if (!self::$trusted) {
            // Rien de technique : ni chemin, ni moteur, ni identifiant.
            $out .= $tag('h1', 'h1pub', e($d['publicTitle'])) . $tag('p', 'mute', e($d['public']));
            return $out . '</div>' . ($late ? '' : '</body></html>');
        }

        $out .= $tag('p', 'brand', e(I18n::APP))
              . $tag('h1', 'h1', e($d['title']))
              . $tag('p', 'lead', e($d['cause']));

        if ($d['fixes']) {
            $items = '';
            foreach ($d['fixes'] as $fix) {
                // Une commande shell se reconnaît à ses accents graves : on la met
                // en évidence et on la rend sélectionnable d'un clic.
                $items .= $tag('li', 'item', preg_replace('~`([^`]+)`~',
                    '<code style="' . $s['code'] . '">$1</code>', e($fix)));
            }
            $out .= $tag('h2', 'h2', e(self::tr('Ce qu\'il faut faire')))
                  . $tag('ol', 'list', $items);
        }

        $out .= '<details style="' . $s['lead'] . '">'
              . $tag('summary', 'sum', e(self::tr('Détail technique')))
              . $tag('pre', 'pre', e($tech))
              . $tag('p', 'note', e(self::tr('Ce détail est aussi écrit dans data/erreurs.log.')))
              . '</details>'
              . $tag('p', 'foot', e(self::tr('Les sondes ne tournent plus tant que ce problème n\'est pas réglé : aucune alerte ne partira, et une panne réelle passerait inaperçue.')))
              . '</div>';

        return $out . ($late ? '' : '</body></html>');
    }

    // =====================================================================
    // Diagnostic
    // =====================================================================
    /**
     * Reconnaît la panne et vérifie sa cause sur le système de fichiers.
     *
     * Publique pour une seule raison : bin/infra.php vérifie les douze causes
     * une par une, et certaines ne se provoquent pas depuis un test (un disque
     * plein, une base verrouillée plus de huit secondes). Le classement doit
     * pouvoir être contrôlé sans provoquer la panne.
     *
     * @return array{slug:string,code:int,title:string,cause:string,fixes:string[],
     *               public:string,publicTitle:string}
     */
    public static function explain(string $message, ?Throwable $e = null): array
    {
        $m      = strtolower($message);
        $driver = (string)self::safe(fn() => (string)Config::get('db.driver', 'sqlite'), 'sqlite');
        $isPdo  = $e instanceof \PDOException || str_contains($m, 'sqlstate');

        $publicTitle = self::tr('Service momentanément indisponible');
        $public      = self::tr('Cette page ne peut pas s\'afficher pour le moment. Réessayez dans quelques minutes.');
        $base = ['slug' => 'internal', 'code' => 500, 'public' => $public, 'publicTitle' => $publicTitle];

        // ---- SQLite ------------------------------------------------------
        if (str_contains($m, 'unable to open database file')) {
            [$path, $dir] = self::sqlitePaths();
            $why = !is_dir($dir)
                ? self::tr('Le dossier {dir} n\'existe pas.', ['dir' => $dir])
                : (!is_writable($dir)
                    ? self::tr('Le dossier {dir} existe mais n\'est pas accessible en écriture.', ['dir' => $dir])
                    : self::tr('Le dossier {dir} semble inscriptible : la cause est ailleurs, souvent un quota d\'hébergement atteint ou un open_basedir trop étroit.',
                               ['dir' => $dir]));
            return [
                'slug'  => 'storage',
                'code'  => 503,
                'title' => self::tr('{app} ne peut pas ouvrir sa base de données.'),
                'cause' => $why . ' ' . self::tr('Le fichier attendu est {path}.', ['path' => $path]),
                'fixes' => array_values(array_filter([
                    self::tr('Donnez les droits d\'écriture au dossier : `chmod 755 {dir}`', ['dir' => $dir]),
                    self::tr('Vérifiez que le dossier appartient bien à l\'utilisateur du site : `ls -ld {dir}`', ['dir' => $dir]),
                    self::diskHint($dir),
                    self::tr('Sur un hébergement mutualisé, un quota d\'inodes ou d\'espace atteint produit la même erreur : regardez le panneau de l\'hébergeur.'),
                ])),
            ] + $base;
        }

        if (str_contains($m, 'file is not a database') || str_contains($m, 'malformed')
            || str_contains($m, 'not a database')) {
            [$path] = self::sqlitePaths();
            return [
                'slug'  => 'corrupt',
                'code'  => 503,
                'title' => self::tr('La base de données est illisible.'),
                'cause' => self::tr('Le fichier {path} existe mais son contenu n\'est pas une base SQLite valide. C\'est ce que produit un transfert FTP en mode texte, une copie interrompue ou un disque saturé pendant une écriture.', ['path' => $path]),
                'fixes' => [
                    self::tr('Restaurez la dernière sauvegarde du fichier, en transfert binaire.'),
                    self::tr('Tentez une réparation : `sqlite3 {path} ".recover" | sqlite3 repare.sqlite`', ['path' => $path]),
                    self::tr('En dernier recours, renommez le fichier : {app} en recréera un vide au prochain affichage. Les sondes et l\'historique seraient perdus, pas la configuration.'),
                ],
            ] + $base;
        }

        if (str_contains($m, 'readonly database') || str_contains($m, 'attempt to write')) {
            [$path] = self::sqlitePaths();
            return [
                'slug'  => 'readonly',
                'code'  => 503,
                'title' => self::tr('La base de données est en lecture seule.'),
                'cause' => self::tr('{app} peut lire {path} mais pas y écrire : aucune mesure ne peut être enregistrée.', ['path' => $path]),
                'fixes' => [
                    self::tr('Rendez le fichier inscriptible : `chmod 644 {path}`', ['path' => $path]),
                    self::tr('SQLite écrit aussi à côté du fichier : le dossier qui le contient doit également être inscriptible (journal -wal et -shm).'),
                ],
            ] + $base;
        }

        if (str_contains($m, 'disk i/o error') || str_contains($m, 'disk is full')
            || str_contains($m, 'no space left')) {
            [, $dir] = self::sqlitePaths();
            return [
                'slug'  => 'disk',
                'code'  => 503,
                'title' => self::tr('Le disque est plein.'),
                'cause' => self::tr('L\'écriture a été refusée par le système de fichiers.')
                         . ' ' . (self::diskHint($dir) ?: ''),
                'fixes' => [
                    self::tr('Libérez de l\'espace, puis réduisez la durée de conservation dans Réglages : les mesures anciennes sont ce qui occupe le plus de place.'),
                    self::tr('Compactez la base : `sqlite3 {path} "VACUUM"`', ['path' => self::sqlitePaths()[0]]),
                ],
            ] + $base;
        }

        if (str_contains($m, 'database is locked')) {
            return [
                'slug'  => 'locked',
                'code'  => 503,
                'title' => self::tr('La base de données est occupée.'),
                'cause' => self::tr('Une autre écriture la retient depuis plus de huit secondes. C\'est en général une passe de vérification très longue, ou deux tâches planifiées qui se chevauchent.'),
                'fixes' => [
                    self::tr('Rechargez la page : le verrou se libère presque toujours seul.'),
                    self::tr('Vérifiez que la tâche planifiée ne tourne pas plus d\'une fois par minute.'),
                    self::tr('Si le parc dépasse quelques centaines de sondes, MySQL supporte mieux les écritures concurrentes que SQLite.'),
                ],
            ] + $base;
        }

        // ---- MySQL / MariaDB --------------------------------------------
        if (str_contains($m, 'connection refused') || str_contains($m, "can't connect")
            || str_contains($m, 'no such file or directory') && $isPdo) {
            $host = (string)self::safe(fn() => (string)Config::get('db.host', 'localhost'), 'localhost');
            $port = (int)self::safe(fn() => (int)Config::get('db.port', 3306), 3306);
            return [
                'slug'  => 'db_down',
                'code'  => 503,
                'title' => self::tr('Le serveur de base de données ne répond pas.'),
                'cause' => self::tr('Aucune connexion possible vers {host}:{port}. Le serveur est arrêté, ou l\'adresse configurée n\'est pas la bonne.',
                                    ['host' => $host, 'port' => (string)$port]),
                'fixes' => [
                    self::tr('Vérifiez que le serveur tourne : `mysqladmin -h {host} -P {port} ping`',
                             ['host' => $host, 'port' => (string)$port]),
                    self::tr('Sur un hébergement mutualisé, l\'hôte est souvent `localhost` et non `127.0.0.1` : les deux ne passent pas par le même canal.'),
                    self::tr('Corrigez la ligne correspondante dans config.php.'),
                ],
            ] + $base;
        }

        if (str_contains($m, 'access denied')) {
            return [
                'slug'  => 'db_auth',
                'code'  => 503,
                'title' => self::tr('Le serveur de base de données refuse les identifiants.'),
                'cause' => self::tr('L\'utilisateur ou le mot de passe de config.php ne correspond pas, ou ce compte n\'a pas de droits sur cette base.'),
                'fixes' => [
                    self::tr('Recréez les droits : `GRANT ALL ON base.* TO utilisateur@localhost`'),
                    self::tr('Un mot de passe changé dans le panneau de l\'hébergeur doit aussi être reporté dans config.php.'),
                ],
            ] + $base;
        }

        if (str_contains($m, 'unknown database')) {
            $name = (string)self::safe(fn() => (string)Config::get('db.name', ''), '');
            return [
                'slug'  => 'db_missing',
                'code'  => 503,
                'title' => self::tr('La base de données n\'existe pas.'),
                'cause' => self::tr('Le serveur répond, mais la base {name} est introuvable.', ['name' => $name]),
                'fixes' => [
                    self::tr('Créez-la : `CREATE DATABASE {name} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`',
                             ['name' => $name !== '' ? $name : 'uptimeez']),
                    self::tr('Ou corrigez le nom dans config.php : une base renommée ne se devine pas.'),
                ],
            ] + $base;
        }

        if (str_contains($m, 'too many connections') || str_contains($m, 'server has gone away')) {
            return [
                'slug'  => 'db_busy',
                'code'  => 503,
                'title' => self::tr('Le serveur de base de données est saturé.'),
                'cause' => self::tr('Il a refusé ou coupé la connexion en cours de route.'),
                'fixes' => [
                    self::tr('Réessayez dans une minute.'),
                    self::tr('Baissez le nombre de vérifications simultanées dans Réglages : chaque passe ouvre une connexion.'),
                ],
            ] + $base;
        }

        // ---- Configuration ----------------------------------------------
        if ($e instanceof \ParseError || str_contains($m, 'syntax error') && str_contains($m, 'config')) {
            return [
                'slug'  => 'config',
                'code'  => 500,
                'title' => self::tr('Le fichier de configuration est cassé.'),
                'cause' => self::tr('config.php ne peut pas être lu par PHP. Une édition manuelle a laissé une virgule ou un guillemet de trop.'),
                'fixes' => [
                    self::tr('Vérifiez la syntaxe : `php -l config.php`'),
                    self::tr('Comparez avec config.sample.php, qui garde la structure attendue.'),
                ],
            ] + $base;
        }

        if (str_contains($m, 'config.php')) {
            return [
                'slug'  => 'config',
                'code'  => 500,
                'title' => self::tr('Le fichier de configuration est inutilisable.'),
                'cause' => $message,
                'fixes' => [
                    self::tr('Vérifiez la syntaxe : `php -l config.php`'),
                    self::tr('Vérifiez que le fichier est lisible par le serveur web : `chmod 640 config.php`'),
                ],
            ] + $base;
        }

        // ---- Reste ------------------------------------------------------
        return [
            'title' => self::tr('{app} a rencontré une erreur inattendue.'),
            'cause' => $message,
            'fixes' => [
                self::tr('Rechargez la page : une erreur passagère ne se reproduit pas.'),
                self::tr('Si elle revient, le détail ci-dessous est ce qu\'il faut joindre à un signalement.'),
            ],
        ] + $base;
    }

    // =====================================================================
    // Utilitaires
    // =====================================================================
    /** @return array{0:string,1:string} chemin du fichier, dossier qui le contient */
    private static function sqlitePaths(): array
    {
        $path = (string)self::safe(fn() => (string)Config::get('db.sqlite', ''), '');
        if ($path === '') $path = UPTIMEEZ_ROOT . '/data/uptimeez.sqlite';
        return [$path, dirname($path)];
    }

    /** Espace libre, quand le système accepte de le dire. */
    private static function diskHint(string $dir): string
    {
        $free = @disk_free_space(is_dir($dir) ? $dir : UPTIMEEZ_ROOT);
        if ($free === false) return '';
        return self::tr('Espace libre sur ce disque : {size}.', ['size' => human_bytes((int)$free)]);
    }

    /**
     * Le détail part dans data/erreurs.log, lisible par le seul propriétaire de
     * l'hébergement. Si ce dossier est justement la panne, on retombe sur le
     * journal de PHP, qui existe toujours quelque part.
     */
    private static function log(string $tech, ?Throwable $e): void
    {
        $line = date('c') . ' ' . $tech
              . ' [' . (PHP_SAPI === 'cli' ? 'cli' : (string)($_SERVER['REQUEST_URI'] ?? '-')) . ']' . "\n";
        if ($e !== null) $line .= '  ' . str_replace("\n", "\n  ", $e->getTraceAsString()) . "\n";

        $dir = UPTIMEEZ_ROOT . '/data';
        if (is_dir($dir) && is_writable($dir)) {
            $file = $dir . '/erreurs.log';
            // Journal borné : une panne en boucle ne remplit pas le disque
            // qu'elle est peut-être en train de dénoncer.
            if (@filesize($file) > 2 * 1024 * 1024) @unlink($file);
            if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) !== false) return;
        }
        error_log('UptimeEZ: ' . $tech);
    }

    /**
     * Traduit sans jamais échouer : la panne peut être antérieure au chargement
     * des catalogues, et un message d'erreur qui plante en s'affichant ne sert
     * plus à rien.
     */
    private static function tr(string $msgid, array $vars = []): string
    {
        $out = self::safe(fn() => I18n::t($msgid, $vars), null);
        if (is_string($out)) return $out;
        foreach ($vars as $k => $v) $msgid = str_replace('{' . $k . '}', (string)$v, $msgid);
        return str_replace('{app}', I18n::APP, $msgid);
    }

    private static function safe(callable $fn, mixed $default): mixed
    {
        try { return $fn(); } catch (Throwable) { return $default; }
    }
}
