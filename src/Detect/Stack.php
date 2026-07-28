<?php
declare(strict_types=1);

namespace Uptimer\Detect;

/**
 * Inventaire logiciel d'une page : cœur, extensions, thème, et leurs versions.
 *
 * Pourquoi ici. Uptimer récupère déjà le HTML de chaque page surveillée. Ce HTML
 * dit presque toujours quelle version tourne : la balise generator, les
 * paramètres de cache sur les fichiers statiques, les chemins d'extensions. Il
 * n'y a donc rien de plus à demander au serveur pour savoir ce qu'il exécute.
 *
 * Ce que ça permet. Croiser cet inventaire avec les avis de sécurité publics
 * déplace le produit de la surveillance vers la prévention : « ce site tourne
 * sur une version qui a une faille publiée il y a trois jours » est une
 * information qu'aucun outil d'uptime ne donne.
 *
 * Prudence assumée : on ne devine pas. Sans numéro de version lisible, on
 * enregistre le composant sans version plutôt qu'une version approximative,
 * parce qu'une fausse alerte de sécurité coûte plus cher qu'une absence
 * d'alerte.
 */
final class Stack
{
    /** Plafonds : une page ne doit pas produire un inventaire sans fin. */
    private const MAX_COMPONENTS = 40;

    /**
     * Correspondance entre une technologie détectée et le paquet qui la publie.
     * C'est cette clé qui permet d'interroger les avis de sécurité.
     */
    public const PACKAGES = [
        'Drupal'      => ['osv' => 'Packagist:drupal/core'],
        'Laravel'     => ['osv' => 'Packagist:laravel/framework'],
        'Symfony'     => ['osv' => 'Packagist:symfony/symfony'],
        'TYPO3'       => ['osv' => 'Packagist:typo3/cms-core'],
        'Magento'     => ['osv' => 'Packagist:magento/product-community-edition'],
        'PrestaShop'  => ['osv' => 'Packagist:prestashop/prestashop'],
        'Joomla'      => ['osv' => 'Packagist:joomla/joomla-cms'],
        'WordPress'   => ['wporg' => 'core'],
    ];

    /**
     * Inventaire d'une page.
     *
     * @return array<int,array{kind:string,slug:string,name:string,version:?string,source:string}>
     */
    public static function inventory(string $html, ?string $cms = null): array
    {
        $out = [];
        $add = function (string $kind, string $slug, string $name, ?string $version, string $source) use (&$out) {
            $key = $kind . ':' . $slug;
            // Arbitrage entre deux lectures du même composant. La plus précise
            // gagne : « Drupal 10 » dans la balise generator cède devant
            // « 10.1.6 » lu sur drupal.js, parce que c'est ce numéro qui permet
            // de dire si une faille publiée concerne ce site.
            if (isset($out[$key])) {
                $prev = $out[$key]['version'];
                if ($version === null) return;
                if ($prev !== null) {
                    $depth = static fn(string $v): int => substr_count($v, '.');
                    if ($depth($version) < $depth($prev)) return;
                    if ($depth($version) === $depth($prev)) {
                        static $rank = ['generator' => 3, 'asset' => 2, 'path' => 1];
                        if (($rank[$source] ?? 0) <= ($rank[$out[$key]['source']] ?? 0)) return;
                    }
                }
            }
            $out[$key] = ['kind' => $kind, 'slug' => $slug, 'name' => $name,
                          'version' => $version, 'source' => $source];
        };

        // ---- 1. La balise generator, quand elle est bavarde ---------------
        if (preg_match_all('~<meta[^>]+name=["\']generator["\'][^>]*>~i', $html, $mm)) {
            foreach ($mm[0] as $tag) {
                if (!preg_match('~content=["\']([^"\']{2,120})["\']~i', $tag, $c)) continue;
                $content = trim($c[1]);
                // « WordPress 6.4.2 », « Drupal 10 (https://…) », « PrestaShop 8.1.5 »
                if (preg_match('~^([A-Za-z][A-Za-z0-9!\s\.]{1,30}?)\s+v?(\d+(?:\.\d+){0,3})~', $content, $g)) {
                    $soft = trim(rtrim($g[1], ' !'));
                    $add('core', self::slug($soft), $soft, $g[2], 'generator');
                } elseif (preg_match('~^([A-Za-z][A-Za-z0-9!\s]{1,30}?)\b~', $content, $g)) {
                    $soft = trim(rtrim($g[1], ' !'));
                    if ($soft !== '') $add('core', self::slug($soft), $soft, null, 'generator');
                }
            }
        }

        // ---- 2. Le cœur repéré sur ses propres fichiers -------------------
        // WordPress publie sa version sur les assets de wp-includes.
        if (preg_match('~wp-includes/[^"\'\s]+\?ver=(\d+\.\d+(?:\.\d+)?)~i', $html, $m)) {
            $add('core', 'wordpress', 'WordPress', $m[1], 'asset');
        }
        // Drupal expose la sienne sur drupal.js.
        if (preg_match('~/core/misc/drupal(?:\.init)?\.js\?v=(\d+\.\d+(?:\.\d+)?)~i', $html, $m)) {
            $add('core', 'drupal', 'Drupal', $m[1], 'asset');
        }
        if ($cms !== null && $cms !== '' && !self::hasCore($out)) {
            $add('core', self::slug($cms), $cms, null, 'path');
        }

        // ---- 3. Extensions et thèmes WordPress ---------------------------
        // Les chemins portent le nom du composant, et souvent sa version.
        if (preg_match_all('~wp-content/(plugins|themes)/([a-z0-9][a-z0-9_-]{1,60})/[^"\'\s>]*?(?:\?ver=(\d+(?:\.\d+){0,3}))?["\'\s>]~i',
                           $html, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $kind = strtolower($m[1]) === 'plugins' ? 'plugin' : 'theme';
                $slug = strtolower($m[2]);
                $ver  = ($m[3] ?? '') !== '' ? $m[3] : null;
                // La version du cœur voyage sur beaucoup d'assets d'extensions :
                // on ne la confond pas avec celle de l'extension elle-même.
                $add($kind, $slug, self::humanise($slug), $ver, $ver !== null ? 'asset' : 'path');
                if (count($out) >= self::MAX_COMPONENTS) break;
            }
        }
        // Extensions PrestaShop et modules Drupal, même principe.
        foreach ([['~/modules/([a-z0-9][a-z0-9_-]{1,50})/~i', 'plugin'],
                  ['~/sites/[^/]+/(?:modules|themes)/(?:contrib/|custom/)?([a-z0-9][a-z0-9_-]{1,50})/~i', 'plugin']] as [$re, $kind]) {
            if (preg_match_all($re, $html, $mm)) {
                foreach (array_unique($mm[1]) as $slug) {
                    if (count($out) >= self::MAX_COMPONENTS) break;
                    $slug = strtolower($slug);
                    // Dossiers d'organisation, pas des composants.
                    if (in_array($slug, ['contrib', 'custom', 'all', 'default', 'front', 'core'], true)) continue;
                    $add($kind, $slug, self::humanise($slug), null, 'path');
                }
            }
        }

        return array_values(array_slice($out, 0, self::MAX_COMPONENTS));
    }

    private static function hasCore(array $out): bool
    {
        foreach ($out as $c) if ($c['kind'] === 'core') return true;
        return false;
    }

    public static function slug(string $name): string
    {
        $s = strtolower(trim($name));
        $s = (string)preg_replace('~[^a-z0-9]+~', '-', $s);
        return trim($s, '-');
    }

    /** « contact-form-7 » devient « Contact Form 7 » : lisible dans une liste. */
    public static function humanise(string $slug): string
    {
        $s = str_replace(['-', '_'], ' ', $slug);
        return mb_convert_case(trim($s), MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Compare deux versions. Renvoie -1, 0 ou 1.
     *
     * Les deux numéros sont complétés par des zéros pour être comparés à
     * profondeur égale : « 6.4 » et « 6.4.0 » désignent la même version, et
     * version_compare() les dit pourtant différentes. Les suffixes de
     * préversion sont écartés (6.4.2-beta1 devient 6.4.2).
     */
    public static function compare(string $a, string $b): int
    {
        $parts = static function (string $v): array {
            $v = (string)preg_replace('~[^0-9.].*$~', '', trim($v));
            $p = array_map('intval', array_filter(explode('.', $v), fn($x) => $x !== ''));
            return $p ?: [0];
        };
        $x = $parts($a); $y = $parts($b);
        $n = max(count($x), count($y));
        for ($i = 0; $i < $n; $i++) {
            $xi = $x[$i] ?? 0; $yi = $y[$i] ?? 0;
            if ($xi !== $yi) return $xi < $yi ? -1 : 1;
        }
        return 0;
    }

    /** Nombre de composantes réellement lues dans un numéro de version. */
    public static function precision(string $v): int
    {
        $v = (string)preg_replace('~[^0-9.].*$~', '', trim($v));
        return count(array_filter(explode('.', $v), fn($x) => $x !== ''));
    }

    /**
     * Cette version est-elle réellement en retard sur la dernière publiée ?
     *
     * Nuance qui évite une fausse affirmation : quand on ne connaît que la
     * version majeure (« Drupal 10 » lu dans la balise generator) et que la
     * dernière publiée commence par le même numéro, on ne peut pas savoir. On
     * préfère ne rien dire plutôt que d'annoncer un retard inexistant.
     */
    public static function isBehind(string $detected, string $latest): bool
    {
        if ($detected === '' || $latest === '') return false;
        if (self::compare($detected, $latest) >= 0) return false;
        $pd = self::precision($detected);
        $pl = self::precision($latest);
        if ($pd < $pl) {
            // Préfixe commun sur la profondeur connue : indécidable.
            $d = explode('.', (string)preg_replace('~[^0-9.].*$~', '', $detected));
            $l = explode('.', (string)preg_replace('~[^0-9.].*$~', '', $latest));
            for ($i = 0; $i < $pd; $i++) {
                if ((int)($d[$i] ?? 0) !== (int)($l[$i] ?? 0)) return true;
            }
            return false;
        }
        return true;
    }
}
