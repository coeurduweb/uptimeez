<?php
declare(strict_types=1);

namespace Uptimer\Detect;

use Uptimer\Response;

/**
 * Identification du CMS / stack à partir d'une réponse HTML.
 * Sert à choisir automatiquement les règles de surveillance :
 * chaîne de preuve par défaut, sonde base de données, pages à découvrir.
 */
final class Cms
{
    /**
     * @return array{cms:?string,confidence:int,builder:?string,theme:?string,server:?string,
     *               cache:?string,generator:?string,signals:array}
     */
    public static function detect(Response $res): array
    {
        $html = substr($res->body, 0, 400000);
        $low  = strtolower($html);
        $hdr  = $res->headers;
        $out  = ['cms' => null, 'confidence' => 0, 'builder' => null, 'theme' => null,
                 'server' => null, 'cache' => null, 'generator' => null, 'signals' => []];

        $gen = null;
        if (preg_match('~<meta[^>]+name=["\']generator["\'][^>]+content=["\']([^"\']+)~i', $html, $m)) {
            $gen = trim($m[1]);
            $out['generator'] = $gen;
        }

        $score = [];
        $add = function (string $cms, int $pts, string $why) use (&$score, &$out) {
            $score[$cms] = ($score[$cms] ?? 0) + $pts;
            $out['signals'][] = $why;
        };

        // ---- WordPress ----------------------------------------------------
        if (str_contains($low, '/wp-content/'))  $add('WordPress', 40, '/wp-content/');
        if (str_contains($low, '/wp-includes/')) $add('WordPress', 30, '/wp-includes/');
        if (str_contains($low, 'wp-json'))       $add('WordPress', 20, 'API wp-json');
        if (str_contains($low, 'wp-emoji'))      $add('WordPress', 10, 'wp-emoji');
        if ($gen && stripos($gen, 'wordpress') !== false) $add('WordPress', 40, 'meta generator');
        if (isset($hdr['x-pingback']))           $add('WordPress', 15, 'en-tête X-Pingback');

        // ---- Autres CMS ---------------------------------------------------
        $needles = [
            'PrestaShop' => ['prestashop' => 35, '/modules/ps_' => 25, 'var prestashop' => 30],
            'Shopify'    => ['cdn.shopify.com' => 40, 'shopify.theme' => 30, 'myshopify.com' => 20],
            'Joomla'     => ['/media/jui/' => 30, '/components/com_' => 30, 'joomla' => 20, '/media/system/js/' => 25],
            'Drupal'     => ['drupal-settings-json' => 40, '/sites/default/files' => 30, 'drupal.js' => 25],
            'Magento'    => ['/static/version' => 30, 'magento' => 25, 'mage/cookies' => 25],
            'Wix'        => ['static.wixstatic.com' => 40, 'wix-warmup-data' => 30],
            'Squarespace'=> ['static1.squarespace.com' => 40, 'squarespace-cdn' => 30, 'sqs-block' => 20],
            'Webflow'    => ['data-wf-page' => 40, 'webflow.js' => 30, 'assets.website-files.com' => 25],
            'Ghost'      => ['ghost-sdk' => 30, '/assets/built/' => 15],
            'SPIP'       => ['spip.php' => 30, 'spip_out' => 20],
            'TYPO3'      => ['typo3conf' => 35, 'typo3temp' => 30],
            'Odoo'       => ['odoo.define' => 35, '/web/content/' => 20],
            'Next.js'    => ['__next_data__' => 40, '/_next/static' => 35],
            'Nuxt'       => ['__nuxt__' => 40, '/_nuxt/' => 35],
            'Astro'      => ['astro-island' => 40, 'data-astro-' => 30],
            'Gatsby'     => ['___gatsby' => 40, 'gatsby-' => 15],
            'Hugo'       => [],
            'Craft CMS'  => ['/cpresources/' => 30],
            'HubSpot'    => ['hs-scripts.com' => 35, 'hubspot' => 15],
        ];
        foreach ($needles as $cms => $sigs) {
            foreach ($sigs as $needle => $pts) {
                if (str_contains($low, $needle)) $add($cms, $pts, $cms . ' : ' . $needle);
            }
        }
        if ($gen) {
            foreach (['PrestaShop', 'Joomla', 'Drupal', 'TYPO3', 'Ghost', 'Hugo', 'Jekyll', 'SPIP', 'Astro', 'Concrete', 'Grav'] as $cms) {
                if (stripos($gen, $cms) !== false) $add($cms, 40, 'meta generator : ' . $gen);
            }
        }
        if (isset($hdr['x-generator'])) {
            $xg = $hdr['x-generator'];
            foreach (['Drupal', 'TYPO3', 'Sulu', 'Ibexa'] as $cms) {
                if (stripos($xg, $cms) !== false) $add($cms, 35, 'X-Generator : ' . $xg);
            }
        }
        if (isset($hdr['x-shopify-stage']) || isset($hdr['x-shopid'])) $add('Shopify', 40, 'en-tête Shopify');
        if (isset($hdr['x-wix-request-id']))                            $add('Wix', 40, 'en-tête Wix');
        if (isset($hdr['x-drupal-cache']) || isset($hdr['x-drupal-dynamic-cache'])) $add('Drupal', 35, 'en-tête Drupal');
        if (isset($hdr['set-cookie']) && str_contains(strtolower($hdr['set-cookie']), 'xsrf-token')) $add('Laravel', 20, 'cookie XSRF-TOKEN');

        arsort($score);
        $best = array_key_first($score);
        if ($best !== null && $score[$best] >= 20) {
            $out['cms'] = $best;
            $out['confidence'] = (int)min(100, $score[$best]);
        }

        // ---- Constructeur de pages / thème --------------------------------
        $builders = [
            'Elementor'      => ['elementor-widget', 'data-elementor-type', '/elementor/assets/'],
            'Divi'           => ['et_pb_', 'et-core'],
            'WPBakery'       => ['vc_row', 'js_composer'],
            'Beaver Builder' => ['fl-builder'],
            'Bricks'         => ['brxe-'],
            'Oxygen'         => ['ct-section'],
            'Gutenberg'      => ['wp-block-'],
            'Breakdance'     => ['breakdance'],
        ];
        foreach ($builders as $b => $sigs) {
            foreach ($sigs as $s) {
                if (str_contains($low, $s)) { $out['builder'] = $b; break 2; }
            }
        }
        if (preg_match('~/wp-content/themes/([a-z0-9_\-]+)/~i', $html, $m)) $out['theme'] = $m[1];

        // ---- Serveur & cache ----------------------------------------------
        $out['server'] = $hdr['server'] ?? null;
        $cacheSignals = [
            'x-litespeed-cache' => 'LiteSpeed Cache',
            'x-rocket-nginx-serving-static' => 'WP Rocket',
            'cf-cache-status'   => 'Cloudflare',
            'x-cache'           => 'cache HTTP',
            'x-wp-cf-super-cache' => 'Super Page Cache',
            'x-nananana'        => 'Kinsta',
            'x-sucuri-cache'    => 'Sucuri',
        ];
        foreach ($cacheSignals as $h => $label) {
            if (isset($hdr[$h])) { $out['cache'] = $label; break; }
        }
        if (!$out['cache']) {
            foreach (['wp rocket' => 'WP Rocket', 'w3 total cache' => 'W3 Total Cache',
                      'wp super cache' => 'WP Super Cache', 'autoptimize' => 'Autoptimize',
                      'litespeed' => 'LiteSpeed Cache', 'wp fastest cache' => 'WP Fastest Cache'] as $n => $label) {
                if (str_contains($low, $n)) { $out['cache'] = $label; break; }
            }
        }

        return $out;
    }

    /**
     * Règles de surveillance par défaut pour un CMS donné.
     * @return array{expect_hint:?string,db_probe:bool,extra_monitors:array,notes:?string}
     */
    public static function rules(?string $cms, ?string $builder = null): array
    {
        $base = ['expect_hint' => null, 'db_probe' => false, 'extra_monitors' => [], 'notes' => null];

        return match ($cms) {
            'WordPress' => [
                'expect_hint' => null, // déduit du contenu réel (footer, nav)
                'db_probe'    => true,
                'extra_monitors' => [
                    ['path' => '/wp-json/', 'kind' => 'api', 'name' => 'API REST', 'expect' => '"namespaces"'],
                    ['path' => '/wp-sitemap.xml', 'kind' => 'asset', 'name' => 'Sitemap', 'expect' => '<sitemap'],
                ],
                'notes' => $builder === 'Elementor'
                    ? 'Elementor : surveiller le CSS par page (uploads/elementor/css) et purger le cache après déploiement.'
                    : 'Surveiller le CSS généré par le cache (wp-content/cache).',
            ],
            'PrestaShop' => [
                'expect_hint' => null, 'db_probe' => true,
                'extra_monitors' => [['path' => '/sitemap.xml', 'kind' => 'asset', 'name' => 'Sitemap', 'expect' => '<urlset']],
                'notes' => 'Surveiller aussi une fiche produit : le panier dépend de la base.',
            ],
            'Drupal' => ['expect_hint' => null, 'db_probe' => true, 'extra_monitors' => [], 'notes' => null],
            'Joomla' => ['expect_hint' => null, 'db_probe' => true, 'extra_monitors' => [], 'notes' => null],
            'Shopify', 'Wix', 'Squarespace', 'Webflow' => [
                'expect_hint' => null, 'db_probe' => false, 'extra_monitors' => [],
                'notes' => 'Hébergement SaaS : la sonde base de données n\'a pas d\'objet, on surveille le rendu.',
            ],
            'Astro', 'Next.js', 'Nuxt', 'Gatsby', 'Hugo', 'Jekyll' => [
                'expect_hint' => null, 'db_probe' => false, 'extra_monitors' => [],
                'notes' => 'Site statique / SSG : pas de base de données côté rendu, on surveille le déploiement (empreinte CSS) et les 404.',
            ],
            default => $base,
        };
    }

    /** Le CMS s'appuie-t-il sur une base de données pour rendre la page ? */
    public static function usesDatabase(?string $cms): bool
    {
        if ($cms === null) return true; // par défaut on suppose du dynamique
        return !in_array($cms, ['Astro', 'Next.js', 'Nuxt', 'Gatsby', 'Hugo', 'Jekyll', 'Shopify', 'Wix', 'Squarespace', 'Webflow'], true);
    }
}
