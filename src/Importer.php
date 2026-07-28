<?php
declare(strict_types=1);

namespace Uptimer;

use Uptimer\Detect\Cms;
use Uptimer\Tune;
use Uptimer\Detect\Discovery;

/**
 * Import de masse : on colle une liste de domaines ou d'URLs, rien d'autre.
 *
 * L'import est instantané (aucun appel réseau) ; l'enrichissement : détection du
 * CMS, choix des pages, déduction de la chaîne de preuve, est fait ensuite,
 * sonde par sonde, par la file de préparation (UI en direct ou cron).
 * C'est ce découpage qui permet d'avaler 200 domaines sans timeout sur mutualisé.
 */
final class Importer
{
    /**
     * Analyse le texte collé.
     * Formats acceptés, mélangés librement :
     *   exemple.fr
     *   https://exemple.fr/contact
     *   exemple.fr | Nom du client | Chaîne qui prouve que tout tourne
     *   exemple.fr ; Nom ; Chaîne
     *   exemple.fr <TAB> Nom <TAB> Chaîne
     *   # ligne de commentaire
     *
     * @return array{rows:array<int,array>,errors:array<int,string>}
     */
    public static function parse(string $text): array
    {
        $rows = []; $errors = []; $seen = [];
        $lines = preg_split('~\R~', $text) ?: [];

        // Un texte collé n'est pas toujours une liste propre : e-mail du client,
        // export de tableur, phrase avec des adresses dedans. Si aucune ligne ne
        // ressemble à une URL, on récupère ce qui traîne dans la prose.
        if (!self::looksLikeList($lines)) {
            $found = self::extractFromProse($text);
            if ($found) $lines = $found;
        }

        foreach ($lines as $n => $raw) {
            $line = trim($raw);
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '//')) continue;

            $sep = null;
            foreach (['|', "\t", ';'] as $s) {
                if (str_contains($line, $s)) { $sep = $s; break; }
            }
            $parts = $sep ? array_map('trim', explode($sep, $line)) : [$line];
            // Une ligne « url, nom » avec virgule reste tolérée si l'URL n'en contient pas.
            if (!$sep && substr_count($line, ',') === 1 && !str_contains(explode(',', $line)[0], ' ')) {
                $parts = array_map('trim', explode(',', $line));
            }

            $url = normalize_url((string)($parts[0] ?? ''));
            if ($url === null) {
                $errors[] = 'Ligne ' . ($n + 1) . ' : « ' . str_cut($raw, 60) . ' » n\'est pas un domaine ou une URL exploitable.';
                continue;
            }
            $key = rtrim(strtolower($url), '/');
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            $rows[] = [
                'url'    => $url,
                'name'   => (string)($parts[1] ?? ''),
                'expect' => (string)($parts[2] ?? ''),
                'line'   => $n + 1,
            ];
        }
        return ['rows' => $rows, 'errors' => $errors];
    }

    /** La saisie ressemble-t-elle à une liste d'adresses ? */
    private static function looksLikeList(array $lines): bool
    {
        $useful = 0; $urlish = 0;
        foreach ($lines as $l) {
            $l = trim($l);
            if ($l === '' || str_starts_with($l, '#')) continue;
            $useful++;
            $first = trim(preg_split('~[|;\t,]~', $l)[0] ?? '');
            if (normalize_url($first) !== null) $urlish++;
        }
        return $useful > 0 && $urlish >= max(1, (int)floor($useful * 0.5));
    }

    /** Récupère les adresses présentes dans un texte libre. */
    public static function extractFromProse(string $text): array
    {
        $out = [];
        // URLs complètes d'abord, puis domaines nus plausibles.
        if (preg_match_all('~https?://[^\s<>"\'\)\]]+~i', $text, $m)) {
            foreach ($m[0] as $u) $out[] = rtrim($u, '.,;:!?');
        }
        if (preg_match_all('~(?<![@\w.-])((?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+'
            . '(?:fr|com|net|org|eu|be|ch|ca|io|co|dev|app|shop|store|info|pro|paris|bzh|corsica'
            . '|alsace|re|gp|mq|nc|pf|xyz|online|site|tech|agency|studio))'
            // Un point final de phrase ne doit pas invalider le domaine : on ne
            // refuse la suite que s'il s'agit d'un vrai prolongement de nom.
            . '(?![\w-])(?!\.[a-z0-9])~i', $text, $m2)) {
            foreach ($m2[1] as $d) {
                $d = strtolower(rtrim($d, '.'));
                // On écarte les noms de fichiers et les adresses e-mail déjà consommées.
                if (preg_match('~\.(png|jpe?g|gif|webp|svg|css|js|pdf|zip|docx?|xlsx?)$~', $d)) continue;
                $out[] = $d;
            }
        }
        // Un hôte n'apparaît qu'une fois : dans un texte libre, la page précise
        // trouvée (…/contact) suffit, inutile d'ajouter aussi la racine.
        $seenHost = [];
        $clean = [];
        foreach ($out as $u) {
            $n = normalize_url($u);
            if ($n === null) continue;
            $h = host_of($n);
            if (isset($seenHost[$h])) continue;
            $seenHost[$h] = true;
            $clean[] = $u;
        }
        return $clean;
    }

    /**
     * Aperçu de ce que l'import va produire, sans rien écrire.
     * @return array{rows:array,errors:array,existing:int,groups:array}
     */
    public static function preview(string $text, array $opt = []): array
    {
        $parsed = self::parse($text);
        $base   = max(30, (int)($opt['interval_sec'] ?? Config::get('defaults.interval_sec', 300)));
        $pages  = max(1, (int)($opt['pages'] ?? 4));

        $existing = 0;
        $byDomain = [];
        foreach ($parsed['rows'] as $i => $row) {
            $host = host_of($row['url']);
            $reg  = registrable_domain($host);
            $dup  = (bool)Db::val('SELECT 1 FROM monitors WHERE url = ?', [$row['url']]);
            if ($dup) $existing++;
            $parsed['rows'][$i]['host']     = $host;
            $parsed['rows'][$i]['domain']   = $reg;
            $parsed['rows'][$i]['exists']   = $dup;
            $parsed['rows'][$i]['interval'] = Tune::intervalFor($row['url'], $base, null, true);
            $parsed['rows'][$i]['pages']    = $pages;
            $parsed['rows'][$i]['proof']    = $row['expect'] !== '' ? $row['expect'] : null;
            $byDomain[$reg] = ($byDomain[$reg] ?? 0) + 1;
        }
        return ['rows' => $parsed['rows'], 'errors' => $parsed['errors'],
                'existing' => $existing, 'groups' => $byDomain];
    }

    /**
     * Crée sites + sondes principales. Aucun appel réseau ici.
     * @param array $opt group, interval_sec, discover (bool), pages (int), extras (bool),
     *                   check_css, check_db, check_ssl, check_noindex, notify_channels
     * @return array{created:int,skipped:int,ids:array<int,int>,errors:array}
     */
    public static function createMonitors(array $rows, array $opt = []): array
    {
        $created = 0; $skipped = 0; $ids = []; $errors = [];
        $d = Config::get('defaults', []);

        foreach ($rows as $row) {
            $url  = $row['url'];
            $host = host_of($url);
            $reg  = registrable_domain($host);

            $exists = Db::val('SELECT id FROM monitors WHERE url = ?', [$url]);
            if ($exists) { $skipped++; continue; }

            try {
                $siteId = self::ensureSite($host, $reg, $row['name'] ?: null, (string)($opt['group'] ?? ''), $row['expect'] ?: null);

                $name = $row['name'] !== '' ? $row['name'] : self::defaultName($url, $host, $reg);
                $id = Db::insert('monitors', [
                    'site_id'        => $siteId,
                    'name'           => str_cut($name, 180),
                    'url'            => $url,
                    'kind'           => self::guessKind($url),
                    'role'           => 'primary',
                    'method'         => 'GET',
                    'interval_sec'   => max(30, (int)($opt['interval_sec'] ?? $d['interval_sec'] ?? 300)),
                    'timeout_sec'    => (int)($opt['timeout_sec'] ?? $d['timeout_sec'] ?? 15),
                    'retries'        => (int)($opt['retries'] ?? $d['retries'] ?? 2),
                    'slow_ms'        => (int)($opt['slow_ms'] ?? $d['slow_ms'] ?? 3000),
                    'expect_status'  => '200-299',
                    'expect_string'  => $row['expect'] !== '' ? $row['expect'] : null,
                    'check_ssl'      => (int)($opt['check_ssl'] ?? 1),
                    'check_css'      => (int)($opt['check_css'] ?? 1),
                    'check_db'       => (int)($opt['check_db'] ?? 1),
                    'check_noindex'  => (int)($opt['check_noindex'] ?? 1),
                    'check_content'  => (int)($opt['check_content'] ?? 0),
                    'ssl_warn_days'  => (int)($d['ssl_warn_days'] ?? 14),
                    'css_drop_pct'   => (int)($d['css_drop_pct'] ?? 35),
                    'notify_channels'=> ($opt['notify_channels'] ?? '') ?: null,
                    'follow_redirects' => 1,
                    'enabled'        => 1,
                    'status'         => 'unknown',
                    'setup_state'    => 'pending',
                    'created_at'     => now(),
                    'next_check_at'  => now(),
                ]);
                $ids[] = $id;
                $created++;
            } catch (\Throwable $e) {
                $errors[] = 'Ligne ' . $row['line'] . ' : ' . str_cut($e->getMessage(), 120);
            }
        }

        Db::setSetting('import_opt', jenc([
            'discover' => (int)($opt['discover'] ?? 1),
            'pages'    => (int)($opt['pages'] ?? 4),
            'extras'   => (int)($opt['extras'] ?? 1),
        ]));

        return ['created' => $created, 'skipped' => $skipped, 'ids' => $ids, 'errors' => $errors];
    }

    /**
     * Préparation d'une sonde : détection du CMS, chaîne de preuve, pages filles.
     * Appelée par l'UI (une requête par sonde, barre de progression) ou par le cron.
     * @return array{ok:bool,cms:?string,expect:?string,pages:int,message:string}
     */
    public static function setup(int $monitorId, array $opt = []): array
    {
        $mon = Db::one('SELECT * FROM monitors WHERE id = ?', [$monitorId]);
        if (!$mon) return ['ok' => false, 'cms' => null, 'expect' => null, 'pages' => 0, 'message' => 'Sonde introuvable'];

        $stored = jdec(Db::setting('import_opt'));
        $discover = (int)($opt['discover'] ?? $stored['discover'] ?? 1);
        $maxPages = (int)($opt['pages'] ?? $stored['pages'] ?? 4);
        $extras   = (int)($opt['extras'] ?? $stored['extras'] ?? 1);

        $res      = Http::fetch($mon['url'], Runner::requestOptions($mon) + ['certinfo' => false]);
        $noHttps  = false;

        // Domaine sans HTTPS exploitable : on retente en clair et on le signale.
        if ((!$res->ok || $res->status === 0)
            && str_starts_with(strtolower((string)$mon['url']), 'https://')
            && in_array($res->errorCode, ['SSL_HANDSHAKE', 'SSL_INVALID', 'CONNECT', 'CONNECT_RESET'], true)) {
            $plain = 'http://' . substr($mon['url'], 8);
            $try   = Http::fetch($plain, Runner::requestOptions($mon) + ['certinfo' => false]);
            if ($try->ok && $try->status > 0 && $try->status < 500) {
                $mon['url'] = $plain;
                $res        = $try;
                $noHttps    = true;
                Db::update('monitors', ['url' => $plain, 'check_ssl' => 0], 'id = :__i', ['__i' => $monitorId]);
            }
        }

        if (!$res->ok || $res->status === 0) {
            // On garde la sonde : c'est peut-être justement un site en panne.
            Db::update('monitors', [
                'setup_state' => 'failed',
                'setup_note'  => 'Page injoignable à la préparation : ' . Http::errorLabel($res->errorCode),
            ], 'id = :__i', ['__i' => $monitorId]);
            return ['ok' => false, 'cms' => null, 'expect' => null, 'pages' => 0,
                    'message' => 'Injoignable (' . Http::errorLabel($res->errorCode) . ') : la sonde reste active'];
        }

        // Si l'URL redirige vers une autre (http→https, www), on adopte la cible.
        $finalUrl = $res->finalUrl ?: $mon['url'];
        $switched = false;
        if ($finalUrl !== $mon['url'] && host_of($finalUrl) !== '' && $res->status < 400) {
            $sameSite = registrable_domain(host_of($finalUrl)) === registrable_domain(host_of($mon['url']));
            if ($sameSite && preg_match('~^https?://~', $finalUrl)) {
                $mon['url'] = $finalUrl;
                $switched = true;
            }
        }

        $detect = Cms::detect($res);
        $rules  = Cms::rules($detect['cms'], $detect['builder']);
        $html   = $res->body;

        $expect = trim((string)($mon['expect_string'] ?? ''));
        if ($expect === '') {
            $siteExpect = $mon['site_id'] ? (string)Db::val('SELECT expect_string FROM sites WHERE id = ?', [(int)$mon['site_id']], '') : '';
            $expect = trim($siteExpect) !== ''
                ? $siteExpect
                : (string)(Discovery::suggestExpectString($html, $res->status) ?? '');
        }

        $badStatus = $res->status < 200 || $res->status >= 300;
        $upd = [
            'setup_state'   => 'done',
            'setup_note'    => ($badStatus ? '⚠️ la page répond ' . $res->status . ' : aucune chaîne de preuve déduite · ' : '')
                             . self::setupNote($detect, $rules, $switched, $finalUrl, $noHttps),
            'expect_string' => $expect !== '' ? $expect : null,
            'check_db'      => Cms::usesDatabase($detect['cms']) ? (int)$mon['check_db'] : 0,
        ];
        if ($switched) $upd['url'] = $finalUrl;
        Db::update('monitors', $upd, 'id = :__i', ['__i' => $monitorId]);

        if ($mon['site_id']) {
            Db::update('sites', [
                'cms'        => $detect['cms'],
                'cms_detail' => jenc([
                    'confidence' => $detect['confidence'], 'builder' => $detect['builder'],
                    'theme' => $detect['theme'], 'server' => $detect['server'],
                    'cache' => $detect['cache'], 'generator' => $detect['generator'],
                ]),
                'expect_string' => $expect !== '' ? $expect : null,
            ], 'id = :__s', ['__s' => (int)$mon['site_id']]);
        }

        // ---- Pages filles -------------------------------------------------
        $pagesCreated = 0;
        $root = self::rootOf($mon['url']);
        if ($discover && $maxPages > 1 && $mon['role'] === 'primary') {
            $picked = Discovery::pickPages($root, $html, $maxPages, [
                'timeout'  => (int)$mon['timeout_sec'],
                'insecure' => (int)$mon['ignore_ssl_errors'] === 1,
            ]);
            foreach ($picked as $p) {
                if (rtrim($p['url'], '/') === rtrim($mon['url'], '/')) continue;
                if (Db::val('SELECT 1 FROM monitors WHERE url = ?', [$p['url']])) continue;
                $row = self::childRow($mon, $p['url'], $p['label'], 'page', $expect, $p['why']);
                // La cadence suit l'importance réelle de la page.
                $row['interval_sec'] = Tune::intervalFor($p['url'], (int)$mon['interval_sec'], $p['family'] ?? null);
                Db::insert('monitors', $row);
                $pagesCreated++;
            }
        }

        if ($extras) {
            foreach (($rules['extra_monitors'] ?? []) as $ex) {
                $u = $root . $ex['path'];
                if (Db::val('SELECT 1 FROM monitors WHERE url = ?', [$u])) continue;
                $row = self::childRow($mon, $u, $ex['name'], $ex['kind'], $ex['expect'] ?? '', 'sonde ' . $ex['name']);
                $row['check_css'] = 0;
                $row['check_noindex'] = 0;
                $row['interval_sec'] = max(600, (int)$mon['interval_sec']);
                Db::insert('monitors', $row);
                $pagesCreated++;
            }
        }

        // Journal des décisions : l'utilisateur doit pouvoir relire nos choix.
        if ($detect['cms']) {
            Tune::note($monitorId, 'Technologie identifiée : ' . $detect['cms']
                . ($detect['builder'] ? ' + ' . $detect['builder'] : ''),
                'Indices relevés dans le HTML et les en-têtes (confiance ' . (int)$detect['confidence'] . ' %).');
        }
        if ($expect !== '') {
            Tune::note($monitorId, 'Chaîne de preuve retenue : « ' . str_cut($expect, 60) . ' »',
                'Ce texte vient du contenu du site : sa disparition trahit une panne du serveur web ou de la base.');
        }
        if (!Cms::usesDatabase($detect['cms'])) {
            Tune::note($monitorId, 'Contrôle base de données désactivé',
                'Site statique ou hébergé en SaaS : aucune base n\'intervient dans le rendu.');
        }
        if ($pagesCreated > 0) {
            Tune::note($monitorId, $pagesCreated . ' sonde(s) supplémentaire(s) créée(s)',
                'Pages représentatives choisies dans le sitemap, une par famille, avec une cadence '
                . 'proportionnelle à leur importance.');
        }

        return [
            'ok'      => true,
            'cms'     => $detect['cms'],
            'builder' => $detect['builder'],
            'expect'  => $expect !== '' ? $expect : null,
            'pages'   => $pagesCreated,
            'message' => trim(($detect['cms'] ?? 'CMS inconnu')
                        . ($detect['builder'] ? ' + ' . $detect['builder'] : '')
                        . ($pagesCreated ? ' · ' . $pagesCreated . ' sonde(s) ajoutée(s)' : '')
                        . ($expect !== '' ? ' · preuve « ' . str_cut($expect, 30) . ' »' : ' · aucune chaîne de preuve trouvée')),
        ];
    }

    /** Sondes en attente de préparation. */
    public static function pending(int $limit = 20): array
    {
        return Db::all("SELECT id, name, url FROM monitors WHERE setup_state = 'pending' ORDER BY id ASC LIMIT " . max(1, $limit));
    }

    // =====================================================================

    private static function childRow(array $parent, string $url, string $label, string $kind, string $expect, string $why): array
    {
        return [
            'site_id'        => $parent['site_id'],
            'name'           => str_cut($label, 180),
            'url'            => $url,
            'kind'           => $kind,
            'role'           => 'secondary',
            'method'         => 'GET',
            'interval_sec'   => (int)$parent['interval_sec'],
            'timeout_sec'    => (int)$parent['timeout_sec'],
            'retries'        => (int)$parent['retries'],
            'slow_ms'        => (int)$parent['slow_ms'],
            'expect_status'  => '200-299',
            'expect_string'  => $kind === 'page' ? ($expect ?: null) : ($expect ?: null),
            'check_ssl'      => 0, // le certificat est déjà suivi par la sonde principale
            'check_css'      => $kind === 'page' ? (int)$parent['check_css'] : 0,
            'check_db'       => (int)$parent['check_db'],
            'check_noindex'  => $kind === 'page' ? (int)$parent['check_noindex'] : 0,
            'ssl_warn_days'  => (int)$parent['ssl_warn_days'],
            'css_drop_pct'   => (int)$parent['css_drop_pct'],
            'notify_channels'=> $parent['notify_channels'],
            'follow_redirects' => 1,
            'enabled'        => 1,
            'status'         => 'unknown',
            'setup_state'    => 'done',
            'setup_note'     => $why,
            'created_at'     => now(),
            'next_check_at'  => date('Y-m-d H:i:s', time() + random_int(5, 90)),
        ];
    }

    private static function ensureSite(string $host, string $reg, ?string $name, string $group, ?string $expect): int
    {
        $id = Db::val('SELECT id FROM sites WHERE domain = ?', [$reg]);
        if ($id) {
            if ($group !== '') Db::update('sites', ['group_name' => $group], 'id = :__i', ['__i' => (int)$id]);
            return (int)$id;
        }
        return Db::insert('sites', [
            'name'          => str_cut($name ?: $reg, 180),
            'domain'        => $reg,
            'group_name'    => $group !== '' ? $group : null,
            'expect_string' => $expect,
            'created_at'    => now(),
        ]);
    }

    private static function defaultName(string $url, string $host, string $reg = ''): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $base = preg_replace('~^www\.~', '', $host) ?: ($reg ?: $host);
        if ($path === '/' || $path === '') return $base;
        return $base . ' ' . str_cut(trim($path, '/'), 40);
    }

    private static function guessKind(string $url): string
    {
        $p = strtolower((string)(parse_url($url, PHP_URL_PATH) ?: ''));
        if (preg_match('~(/api/|/wp-json|/graphql|\.json$)~', $p)) return 'api';
        if (preg_match('~\.(xml|txt|css|js)$~', $p)) return 'asset';
        return 'page';
    }

    private static function rootOf(string $url): string
    {
        $p = parse_url($url);
        if (!$p || empty($p['host'])) return rtrim($url, '/');
        return ($p['scheme'] ?? 'https') . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    }

    private static function setupNote(array $detect, array $rules, bool $switched, string $finalUrl, bool $noHttps = false): string
    {
        $bits = [];
        if ($noHttps)           $bits[] = t('⚠️ pas de HTTPS exploitable : surveillance en HTTP');
        if ($detect['cms'])     $bits[] = $detect['cms'] . ' (' . $detect['confidence'] . '%)';
        if ($detect['builder']) $bits[] = $detect['builder'];
        if ($detect['theme'])   $bits[] = 'thème ' . $detect['theme'];
        if ($detect['server'])  $bits[] = str_cut((string)$detect['server'], 30);
        if ($detect['cache'])   $bits[] = $detect['cache'];
        if ($switched)          $bits[] = 'URL alignée sur ' . str_cut($finalUrl, 60);
        if (!empty($rules['notes'])) $bits[] = $rules['notes'];
        return str_cut(implode(' · ', $bits), 500);
    }
}
