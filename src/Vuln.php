<?php
declare(strict_types=1);

namespace Uptimeez;

use Uptimeez\Detect\Stack;

/**
 * Veille de sécurité sur les versions détectées.
 *
 * Ce que ça déplace. Un outil d'uptime dit qu'un site répond. Celui-ci sait en
 * plus quelle version il exécute, parce qu'il lit déjà le HTML de chaque page.
 * Croiser cet inventaire avec les avis publics fait passer du constat à la
 * prévention : « ce site tourne sur une version qui a une faille publiée il y a
 * trois jours » se traite avant l'incident, pas après.
 *
 * Deux signaux, jamais confondus. C'est le point qui décide de la crédibilité de
 * la fonction :
 *
 *   - **faille publiée** : un avis de sécurité identifié couvre précisément la
 *     version détectée. On donne l'identifiant et le lien. Rien n'est déduit.
 *   - **version en retard** : la version détectée est antérieure à la dernière
 *     publiée. Ce n'est pas une faille, c'est une dette. On le dit autrement.
 *
 * Annoncer « vulnérable » quand on ne sait que « pas à jour » ferait perdre au
 * produit exactement ce qu'il cherche à gagner.
 *
 * Sources, toutes publiques et sans clé d'API :
 *   - OSV.dev pour ce qui se publie sur Packagist (Drupal, Laravel, Symfony,
 *     TYPO3, Magento, PrestaShop, Joomla) ;
 *   - api.wordpress.org pour la dernière version du cœur WordPress, de ses
 *     extensions et de ses thèmes.
 *
 * Coût maîtrisé : une interrogation par composant et par version, mise en cache
 * sept jours, plafonnée par passe d'entretien. Un parc de cent sites ne produit
 * pas cent requêtes par jour.
 */
final class Vuln
{
    /** Durée de validité d'une réponse pour un couple composant + version. */
    private const CACHE_DAYS = 7;

    /** Interrogations par passe d'entretien : le mutualisé reste tranquille. */
    private const PER_PASS = 25;

    private const OSV_URL   = 'https://api.osv.dev/v1/query';
    private const WP_CORE   = 'https://api.wordpress.org/core/version-check/1.7/';
    private const WP_PLUGIN = 'https://api.wordpress.org/plugins/info/1.2/';
    private const WP_THEME  = 'https://api.wordpress.org/themes/info/1.2/';

    // =====================================================================
    // Inventaire
    // =====================================================================
    /**
     * Enregistre l'inventaire d'une page. Appelé par le collecteur, sans coût
     * réseau : tout est lu dans le HTML déjà reçu.
     */
    public static function record(int $monitorId, ?int $siteId, string $html, ?string $cms): int
    {
        if ($siteId === null) return 0;
        $found = Stack::inventory($html, $cms);
        if (!$found) return 0;

        $n = 0;
        foreach ($found as $c) {
            $existing = Db::one('SELECT id, version FROM components WHERE site_id = ? AND kind = ? AND slug = ?',
                                [$siteId, $c['kind'], $c['slug']]);
            if ($existing) {
                $upd = ['seen_at' => now(), 'name' => $c['name'], 'monitor_id' => $monitorId];
                // Une version qui change remet la veille à zéro : un site mis à
                // jour ne doit pas rester marqué vulnérable.
                if ($c['version'] !== null && (string)$existing['version'] !== $c['version']) {
                    $upd += ['version' => $c['version'], 'checked_at' => null, 'vuln_count' => 0,
                             'advisories' => null, 'latest' => null, 'outdated' => 0];
                }
                Db::update('components', $upd, 'id = :__i', ['__i' => (int)$existing['id']]);
            } else {
                Db::insert('components', [
                    'site_id' => $siteId, 'monitor_id' => $monitorId, 'kind' => $c['kind'],
                    'slug' => $c['slug'], 'name' => $c['name'], 'version' => $c['version'],
                    'source' => $c['source'], 'seen_at' => now(), 'first_seen_at' => now(),
                ]);
            }
            $n++;
        }
        return $n;
    }

    // =====================================================================
    // Veille
    // =====================================================================
    /**
     * Interroge les avis pour les composants qui le méritent.
     * Appelée par l'entretien quotidien du cron.
     *
     * @return array{checked:int,vulnerable:int,outdated:int}
     */
    public static function scan(int $limit = self::PER_PASS): array
    {
        $res = ['checked' => 0, 'vulnerable' => 0, 'outdated' => 0];
        if (!Config::get('vuln.enabled', true)) return $res;

        $cut = date('Y-m-d H:i:s', time() - self::CACHE_DAYS * 86400);
        // On ne regarde que ce dont on connaît la version : sans numéro, il n'y
        // a rien à comparer et une alerte serait une supposition.
        $rows = Db::all('SELECT c.*, s.name AS site_name FROM components c
                         JOIN sites s ON s.id = c.site_id
                         WHERE c.version IS NOT NULL AND c.version <> \'\'
                           AND (c.checked_at IS NULL OR c.checked_at < ?)
                         ORDER BY CASE c.kind WHEN \'core\' THEN 0 ELSE 1 END,
                                  c.checked_at IS NOT NULL, c.id ASC
                         LIMIT ?', [$cut, max(1, $limit)]);

        foreach ($rows as $c) {
            $find = self::lookup((string)$c['kind'], (string)$c['slug'], (string)$c['name'], (string)$c['version']);
            Db::update('components', [
                'checked_at'  => now(),
                'vuln_count'  => count($find['advisories']),
                'advisories'  => $find['advisories'] ? jenc($find['advisories']) : null,
                'latest'      => $find['latest'],
                'outdated'    => $find['outdated'] ? 1 : 0,
                'worst'       => $find['worst'],
            ], 'id = :__i', ['__i' => (int)$c['id']]);
            $res['checked']++;
            if ($find['advisories']) $res['vulnerable']++;
            if ($find['outdated'])   $res['outdated']++;
        }
        return $res;
    }

    /**
     * Interroge les sources pour un composant précis.
     *
     * @return array{advisories:array,latest:?string,outdated:bool,worst:?string}
     */
    public static function lookup(string $kind, string $slug, string $name, string $version): array
    {
        $out = ['advisories' => [], 'latest' => null, 'outdated' => false, 'worst' => null];
        $timeout = (int)Config::get('vuln.timeout_sec', 8);

        // ---- Le cœur, quand il correspond à un paquet public --------------
        if ($kind === 'core') {
            $map = null;
            foreach (Stack::PACKAGES as $soft => $conf) {
                if (Stack::slug($soft) === $slug) { $map = $conf; break; }
            }
            if (isset($map['osv'])) {
                [$eco, $pkg] = explode(':', $map['osv'], 2);
                $out['advisories'] = self::osv($eco, $pkg, $version, $timeout);
            }
            if (($map['wporg'] ?? '') === 'core') {
                $out['latest'] = self::wpCoreLatest($timeout);
            }
        }

        // ---- Extensions et thèmes WordPress ------------------------------
        if ($kind === 'plugin' || $kind === 'theme') {
            $out['latest'] = self::wpComponentLatest($kind, $slug, $timeout);
        }

        if ($out['latest'] !== null && $version !== '') {
            $out['outdated'] = Stack::isBehind($version, $out['latest']);
        }
        $out['worst'] = self::worstSeverity($out['advisories']);
        return $out;
    }

    /** Avis OSV couvrant cette version. Réponse déjà filtrée par le service. */
    private static function osv(string $ecosystem, string $package, string $version, int $timeout): array
    {
        $res = Http::fetch(self::OSV_URL, [
            'method'  => 'POST',
            'timeout' => $timeout,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => jenc(['package' => ['ecosystem' => $ecosystem, 'name' => $package],
                               'version' => $version]),
            'maxBody' => 800_000,
        ]);
        if (!$res->ok || $res->status !== 200) return [];
        $data = json_decode($res->body, true);
        if (!is_array($data) || empty($data['vulns'])) return [];

        $out = [];
        foreach (array_slice($data['vulns'], 0, 12) as $v) {
            $ref = null;
            foreach (($v['references'] ?? []) as $r) {
                if (($r['type'] ?? '') === 'ADVISORY' || $ref === null) $ref = $r['url'] ?? null;
            }
            $out[] = [
                'id'       => (string)($v['id'] ?? ''),
                'summary'  => str_cut((string)($v['summary'] ?? $v['details'] ?? ''), 200),
                'severity' => self::osvSeverity($v),
                'published'=> substr((string)($v['published'] ?? ''), 0, 10),
                'url'      => $ref,
                'aliases'  => array_slice($v['aliases'] ?? [], 0, 3),
            ];
        }
        return $out;
    }

    /**
     * Gravité d'un avis. OSV la donne parfois en CVSS, parfois pas du tout : on
     * ne l'invente pas, on renvoie null quand elle est absente.
     */
    private static function osvSeverity(array $v): ?string
    {
        foreach (($v['severity'] ?? []) as $s) {
            $score = (string)($s['score'] ?? '');
            if (preg_match('~/AV:~', $score)) {
                // Vecteur CVSS : on ne recalcule pas un score, on retient les
                // marqueurs qui suffisent à trier.
                if (preg_match('~C:H|I:H|A:H~', $score)) return 'high';
                return 'medium';
            }
            if (is_numeric($score)) {
                $n = (float)$score;
                return $n >= 7 ? 'high' : ($n >= 4 ? 'medium' : 'low');
            }
        }
        $db = strtoupper((string)($v['database_specific']['severity'] ?? ''));
        return match (true) {
            str_contains($db, 'CRITICAL'), str_contains($db, 'HIGH') => 'high',
            str_contains($db, 'MODERATE'), str_contains($db, 'MEDIUM') => 'medium',
            str_contains($db, 'LOW') => 'low',
            default => null,
        };
    }

    public static function worstSeverity(array $advisories): ?string
    {
        $rank = ['high' => 3, 'medium' => 2, 'low' => 1];
        $worst = null; $best = 0;
        foreach ($advisories as $a) {
            $s = $a['severity'] ?? null;
            if ($s !== null && ($rank[$s] ?? 0) > $best) { $best = $rank[$s]; $worst = $s; }
        }
        // Un avis sans gravité annoncée reste un avis : on ne le rend pas muet.
        if ($worst === null && $advisories) $worst = 'unknown';
        return $worst;
    }

    private static function wpCoreLatest(int $timeout): ?string
    {
        $res = Http::fetch(self::WP_CORE, ['timeout' => $timeout, 'maxBody' => 200_000]);
        if (!$res->ok || $res->status !== 200) return null;
        $d = json_decode($res->body, true);
        $v = $d['offers'][0]['current'] ?? null;
        return is_string($v) && $v !== '' ? $v : null;
    }

    private static function wpComponentLatest(string $kind, string $slug, int $timeout): ?string
    {
        $base = $kind === 'theme' ? self::WP_THEME : self::WP_PLUGIN;
        $url  = $base . '?action=' . ($kind === 'theme' ? 'theme_information' : 'plugin_information')
              . '&request%5Bslug%5D=' . rawurlencode($slug) . '&request%5Bfields%5D%5Bversion%5D=1';
        $res  = Http::fetch($url, ['timeout' => $timeout, 'maxBody' => 300_000]);
        if (!$res->ok || $res->status !== 200) return null;
        $d = json_decode($res->body, true);
        // Une extension absente du dépôt officiel (thème sur mesure, extension
        // payante) renvoie une erreur : ce n'est pas un problème à signaler.
        if (!is_array($d) || isset($d['error'])) return null;
        $v = $d['version'] ?? null;
        return is_string($v) && $v !== '' ? $v : null;
    }

    // =====================================================================
    // Lecture
    // =====================================================================
    /** Composants d'un site, cœur d'abord, puis ce qui alerte. */
    public static function forSite(int $siteId): array
    {
        return Db::all('SELECT * FROM components WHERE site_id = ?
                        ORDER BY CASE kind WHEN \'core\' THEN 0 WHEN \'theme\' THEN 1 ELSE 2 END,
                                 vuln_count DESC, outdated DESC, name ASC', [$siteId]);
    }

    /**
     * Ce qui mérite d'apparaître dans « À prévoir ».
     *
     * Une faille publiée ne casse pas le site : elle le mettra en danger si
     * personne n'intervient. Elle appartient donc à ce qui va arriver, pas à ce
     * qui est arrivé.
     */
    public static function findings(int $limit = 8): array
    {
        $rows = Db::all('SELECT c.*, s.name AS site_name, s.id AS sid,
                                (SELECT m.id FROM monitors m WHERE m.site_id = c.site_id
                                   AND m.role = \'primary\' LIMIT 1) AS monitor_id
                         FROM components c JOIN sites s ON s.id = c.site_id
                         WHERE c.vuln_count > 0
                         ORDER BY CASE c.worst WHEN \'high\' THEN 0 WHEN \'medium\' THEN 1
                                  WHEN \'low\' THEN 2 ELSE 3 END, c.vuln_count DESC
                         LIMIT ?', [max(1, $limit)]);
        $out = [];
        foreach ($rows as $c) {
            $adv = jdec($c['advisories'] ?? null);
            $first = $adv[0] ?? [];
            $out[] = [
                'kind' => 'vuln',
                'id' => (int)($c['monitor_id'] ?? 0),
                'icon' => 'shield',
                'urgency' => ($c['worst'] ?? '') === 'high' ? 'warn' : 'info',
                'days' => 0,
                'severity' => $c['worst'],
                'title' => t('{soft} {version} sur {site} : {n} faille(s) publiée(s)', [
                    'soft' => (string)$c['name'], 'version' => (string)$c['version'],
                    'site' => (string)$c['site_name'], 'n' => (int)$c['vuln_count']]),
                'why' => trim((string)($first['summary'] ?? '')) !== ''
                    ? str_cut((string)$first['summary'], 180)
                    : t('Un avis de sécurité couvre précisément cette version. La mise à jour est la seule réponse.'),
                'advisory' => $first['id'] ?? null,
                'url' => $first['url'] ?? null,
            ];
        }
        return $out;
    }

    /** Compteurs pour les écrans de synthèse. */
    public static function counts(): array
    {
        return [
            'components'  => (int)Db::val('SELECT COUNT(*) FROM components', [], 0),
            'with_vuln'   => (int)Db::val('SELECT COUNT(*) FROM components WHERE vuln_count > 0', [], 0),
            'high'        => (int)Db::val('SELECT COUNT(*) FROM components WHERE worst = \'high\'', [], 0),
            'outdated'    => (int)Db::val('SELECT COUNT(*) FROM components WHERE outdated = 1', [], 0),
            'unchecked'   => (int)Db::val('SELECT COUNT(*) FROM components WHERE checked_at IS NULL
                                           AND version IS NOT NULL', [], 0),
        ];
    }

    public static function severityLabel(?string $s): string
    {
        return match ($s) {
            'high'    => t('critique'),
            'medium'  => t('moyenne'),
            'low'     => t('faible'),
            'unknown' => t('gravité non annoncée'),
            default   => t('aucune'),
        };
    }
}
