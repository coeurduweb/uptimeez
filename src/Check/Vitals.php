<?php
declare(strict_types=1);

namespace Uptimer\Check;

use Uptimer\Http;
use Uptimer\Response;

/**
 * Ce qui rend une page lente, lu dans la page elle-même.
 *
 * Le problème des Core Web Vitals, pour un outil comme celui-ci : les trois
 * mesures officielles (LCP, INP, CLS) viennent de vrais navigateurs, sur de
 * vrais visiteurs. Sans navigateur et sans clé d'API, Uptimer ne peut pas les
 * inventer, et il ne le fera pas. Un chiffre de performance inventé serait la
 * pire chose à afficher : on le croirait.
 *
 * En revanche, il peut faire ce que personne ne fait sans lancer Chrome :
 * **expliquer** ces mesures. Parce qu'il a déjà tout sous la main.
 *
 *   - Le **TTFB** est mesuré par curl à chaque vérification, en millisecondes,
 *     sur le vrai réseau. C'est une mesure, pas une estimation, et c'est le
 *     premier plafond du LCP : un LCP ne sera jamais meilleur que le TTFB.
 *   - Les **fichiers qui bloquent le premier affichage** sont connus : l'audit
 *     CSS les télécharge déjà, avec leur poids exact et leur temps de transfert
 *     exact.
 *   - Les **causes classiques de décalage** (CLS) se lisent dans le HTML : une
 *     image sans dimensions, une police sans `font-display`.
 *   - L'**erreur la plus fréquente sur le LCP** se lit aussi : la grande image
 *     du haut de page marquée `loading="lazy"`, ce qui la fait charger en
 *     dernier alors que c'est elle que le visiteur attend.
 *
 * Vocabulaire tenu du début à la fin : ce qui est mesuré est appelé mesure, ce
 * qui est déduit du HTML est appelé cause probable. Les deux ne sont jamais
 * mélangés dans une même phrase, et aucun LCP n'est annoncé sans venir d'un
 * vrai navigateur (voir \Uptimer\Vitals pour les données de terrain).
 */
final class Vitals
{
    /** Seuils officiels du TTFB, en millisecondes (web.dev). */
    private const TTFB_GOOD = 800;
    private const TTFB_POOR = 1800;

    /** Au-delà, une image du haut de page pèse à elle seule sur le LCP. */
    private const IMG_HEAVY_BYTES = 250 * 1024;

    /** Un seul appel réseau supplémentaire par analyse, sur la seule image utile. */
    private const HEAD_TIMEOUT = 6;

    /** Nombre de causes retenues : au-delà, la liste cesse d'être actionnable. */
    private const MAX_FINDINGS = 8;

    /**
     * Analyse une page déjà reçue.
     *
     * @param array $css  Le tableau `metrics` renvoyé par Css::audit(), qui
     *                    porte les ressources avec leur poids et leur durée.
     * @param array $opt  timeout, ua, insecure, head (bool, autorise la requête
     *                    HEAD sur l'image du haut de page).
     * @return array{ttfb_ms:?int,ttfb_verdict:string,blocking:array,findings:array,level:string,lcp_image:?array}
     */
    public static function analyse(string $pageUrl, string $html, array $css = [],
                                   ?Response $res = null, array $opt = []): array
    {
        // Un TTFB de 0 ms est une mesure, pas une absence de mesure : sur un
        // serveur local, la réponse arrive en moins d'une milliseconde. C'est
        // l'existence d'une réponse qui dit si l'on a mesuré quelque chose.
        $measured = $res !== null && ($res->status > 0 || $res->ttfbMs > 0 || $res->totalMs > 0);
        $out = [
            'ttfb_ms'      => $measured ? $res->ttfbMs : null,
            'ttfb_verdict' => 'unknown',
            'blocking'     => ['css' => 0, 'js' => 0, 'bytes' => 0, 'css_bytes' => 0,
                               'js_bytes' => 0, 'ms' => 0, 'items' => []],
            'findings'     => [],
            'lcp_image'    => null,
            'level'        => 'ok',
        ];
        $find = [];

        // ---- 1. TTFB : une mesure, pas une estimation ---------------------
        $ttfb = $out['ttfb_ms'];
        if ($ttfb !== null) {
            $out['ttfb_verdict'] = $ttfb <= self::TTFB_GOOD ? 'good'
                                 : ($ttfb <= self::TTFB_POOR ? 'improve' : 'poor');
            if ($out['ttfb_verdict'] !== 'good') {
                $find[] = [
                    'code' => 'TTFB', 'severity' => $out['ttfb_verdict'] === 'poor' ? 'high' : 'medium',
                    'metric' => 'lcp',
                    'what' => 'Le serveur met {ms} avant de renvoyer le premier octet.',
                    'vars' => ['ms' => $ttfb . ' ms'],
                    'why'  => 'Aucun affichage ne peut commencer avant. Le LCP ne sera jamais meilleur que ce temps.',
                    'fix'  => 'Cache de pages côté serveur, ou un hébergement moins chargé. Le seuil visé est 800 ms.',
                    'evidence' => $ttfb . ' ms mesurés sur cette vérification',
                ];
            }
        }

        // ---- 2. Ce qui bloque le premier affichage ------------------------
        // Une feuille de style dans l'en-tête bloque le rendu, c'est par
        // construction. Un script sans defer ni async bloque l'analyse du HTML.
        $head = self::headOf($html);
        $blockingUrls = [];
        foreach (Css::extractStylesheets($html, $pageUrl) as $sheet) {
            $media = strtolower((string)($sheet['media'] ?? 'all'));
            // print, ou une media query qui ne s'applique pas tout de suite, ne
            // bloque pas le rendu : on ne le compte pas.
            if ($media !== '' && $media !== 'all' && !str_contains($media, 'screen')) continue;
            if (!self::inHead($head, (string)$sheet['url'])) continue;
            $out['blocking']['css']++;
            $blockingUrls[self::key((string)$sheet['url'])] = 'css';
        }
        foreach (Css::extractScripts($html, $pageUrl) as $script) {
            if (!empty($script['defer'])) continue;
            if (!self::inHead($head, (string)$script['url'])) continue;
            $out['blocking']['js']++;
            $blockingUrls[self::key((string)$script['url'])] = 'js';
        }
        // Poids et durée réels, pris sur les ressources déjà téléchargées.
        foreach (($css['assets'] ?? []) as $a) {
            $k = self::key((string)($a['url'] ?? ''));
            if (!isset($blockingUrls[$k])) continue;
            $out['blocking']['bytes'] += (int)($a['bytes'] ?? 0);
            // Le poids est compté par nature : « trois feuilles de style pèsent
            // 400 Ko » serait faux si le total incluait le JavaScript.
            $out['blocking'][$blockingUrls[$k] . '_bytes'] += (int)($a['bytes'] ?? 0);
            $out['blocking']['ms']     = max($out['blocking']['ms'], (int)($a['ms'] ?? 0));
            $out['blocking']['items'][] = [
                'url' => (string)$a['url'], 'kind' => $blockingUrls[$k],
                'bytes' => (int)($a['bytes'] ?? 0), 'ms' => (int)($a['ms'] ?? 0),
            ];
        }
        usort($out['blocking']['items'], fn($x, $y) => $y['bytes'] <=> $x['bytes']);

        if ($out['blocking']['js'] > 0) {
            $find[] = [
                'code' => 'BLOCKING_JS', 'severity' => $out['blocking']['js'] >= 3 ? 'high' : 'medium',
                'metric' => 'lcp',
                'what' => '{n} script(s) de l\'en-tête bloquent l\'analyse du HTML.',
                'vars' => ['n' => $out['blocking']['js']],
                'why'  => 'Le navigateur arrête de lire la page pour les télécharger et les exécuter, avant même d\'avoir affiché quoi que ce soit.',
                'fix'  => 'Ajouter defer, ou async pour ce qui est indépendant. Un seul attribut par balise.',
                'evidence' => self::listUrls($out['blocking']['items'], 'js'),
            ];
        }
        if ($out['blocking']['css'] >= 3 || $out['blocking']['css_bytes'] > 150 * 1024) {
            $find[] = [
                'code' => 'BLOCKING_CSS',
                'severity' => $out['blocking']['css_bytes'] > 400 * 1024 ? 'high' : 'medium',
                'metric' => 'lcp',
                'what' => '{n} feuille(s) de style bloquent le premier affichage, {kb} au total.',
                'vars' => ['n' => $out['blocking']['css'], 'kb' => self::kb($out['blocking']['css_bytes'])],
                'why'  => 'Rien ne s\'affiche avant que tout ce CSS soit téléchargé et analysé.',
                'fix'  => 'Regrouper les fichiers, retirer ce qui ne sert pas à la première vue, et charger le reste en media="print" onload.',
                'evidence' => self::listUrls($out['blocking']['items'], 'css'),
            ];
        }

        // ---- 3. L'image que le visiteur attend ---------------------------
        $img = self::lcpCandidate($html, $pageUrl);
        if ($img !== null) {
            // Un seul HEAD, sur cette image et sur aucune autre : c'est le poids
            // le plus utile de la page, et il ne se lit pas dans le HTML.
            if (!empty($opt['head']) && $img['url'] !== null) {
                $h = Http::fetch($img['url'], [
                    'method' => 'HEAD', 'timeout' => (int)($opt['timeout'] ?? self::HEAD_TIMEOUT),
                    'ua' => $opt['ua'] ?? null, 'insecure' => (bool)($opt['insecure'] ?? false),
                ]);
                $len = $h->header('content-length');
                if ($h->status >= 200 && $h->status < 300 && $len !== null && ctype_digit(trim($len))) {
                    $img['bytes'] = (int)trim($len);
                }
                $img['ms'] = $h->totalMs ?: null;
            }
            $out['lcp_image'] = $img;

            if (!empty($img['lazy'])) {
                $find[] = [
                    'code' => 'LCP_LAZY', 'severity' => 'high', 'metric' => 'lcp',
                    'what' => 'La grande image du haut de page est en chargement différé.',
                    'vars' => [],
                    'why'  => 'loading="lazy" dit au navigateur de la charger en dernier. C\'est pourtant elle que le visiteur attend, et c\'est elle que le LCP mesure.',
                    'fix'  => 'Retirer loading="lazy" sur cette image, et ajouter fetchpriority="high".',
                    'evidence' => self::shortUrl((string)$img['url']),
                ];
            }
            if (($img['bytes'] ?? 0) > self::IMG_HEAVY_BYTES) {
                $find[] = [
                    'code' => 'LCP_HEAVY', 'severity' => ($img['bytes'] > 3 * self::IMG_HEAVY_BYTES) ? 'high' : 'medium',
                    'metric' => 'lcp',
                    'what' => 'L\'image du haut de page pèse {kb}.',
                    'vars' => ['kb' => self::kb((int)$img['bytes'])],
                    'why'  => 'Sur un téléphone en 4G, ce seul fichier ajoute plus d\'une seconde avant que la page paraisse complète.',
                    'fix'  => 'Réencoder en WebP ou AVIF, servir la taille réellement affichée, viser moins de 150 Ko.',
                    'evidence' => self::shortUrl((string)$img['url']) . ' · ' . self::kb((int)$img['bytes']),
                ];
            }
        }

        // ---- 4. Décalages de mise en page (CLS) --------------------------
        $noDim = self::imagesWithoutDimensions($html);
        if ($noDim['count'] > 0) {
            $find[] = [
                'code' => 'IMG_NO_DIM', 'severity' => $noDim['count'] >= 5 ? 'medium' : 'low',
                'metric' => 'cls',
                'what' => '{n} image(s) sans largeur ni hauteur déclarées.',
                'vars' => ['n' => $noDim['count']],
                'why'  => 'Le navigateur ne peut pas réserver la place : le texte saute quand l\'image arrive. C\'est la première cause de décalage de mise en page.',
                'fix'  => 'Ajouter width et height sur la balise, ou aspect-ratio en CSS. Les valeurs servent de proportion, pas de taille finale.',
                'evidence' => implode(' · ', array_slice($noDim['samples'], 0, 3)),
            ];
        }
        $fonts = self::fontsWithoutDisplay((string)($css['css_text'] ?? ''));
        if ($fonts > 0) {
            $find[] = [
                'code' => 'FONT_NO_DISPLAY', 'severity' => 'low', 'metric' => 'cls',
                'what' => '{n} police(s) chargées sans font-display.',
                'vars' => ['n' => $fonts],
                'why'  => 'Le texte reste invisible pendant le téléchargement de la police, puis apparaît d\'un coup en décalant la mise en page.',
                'fix'  => 'Ajouter font-display: swap dans la règle @font-face.',
                'evidence' => $fonts . ' règle(s) @font-face concernée(s)',
            ];
        }

        // ---- 5. Scripts tiers : ce qui pèse sur la réactivité ------------
        $third = self::thirdPartyHosts($head, $pageUrl);
        if (count($third) >= 4) {
            $find[] = [
                'code' => 'THIRD_PARTY', 'severity' => count($third) >= 8 ? 'medium' : 'low',
                'metric' => 'inp',
                'what' => '{n} domaines tiers chargent du script dans l\'en-tête.',
                'vars' => ['n' => count($third)],
                'why'  => 'Chacun ajoute une résolution DNS, une négociation TLS et du travail sur le fil principal, ce qui retarde la réaction au premier clic.',
                'fix'  => 'Charger les traceurs après l\'affichage, ou les regrouper. Un gestionnaire de balises compte pour un domaine, pas pour zéro.',
                'evidence' => implode(' · ', array_slice($third, 0, 5)),
            ];
        }

        // ---- Verdict -----------------------------------------------------
        usort($find, function (array $a, array $b): int {
            static $rank = ['high' => 0, 'medium' => 1, 'low' => 2];
            return ($rank[$a['severity']] ?? 3) <=> ($rank[$b['severity']] ?? 3);
        });
        $out['findings'] = array_slice($find, 0, self::MAX_FINDINGS);
        $high = count(array_filter($out['findings'], fn($f) => $f['severity'] === 'high'));
        $out['level'] = $high > 0 ? 'bad' : ($out['findings'] ? 'watch' : 'ok');
        return $out;
    }

    // =====================================================================
    // Lectures dans le HTML
    // =====================================================================
    /** L'en-tête du document, ou le début du fichier si la balise manque. */
    private static function headOf(string $html): string
    {
        if (preg_match('~<head\b[^>]*>(.*?)</head>~is', $html, $m)) return $m[1];
        $cut = stripos($html, '<body');
        return $cut !== false ? substr($html, 0, $cut) : substr($html, 0, 20000);
    }

    private static function inHead(string $head, string $url): bool
    {
        // La comparaison porte sur la fin de l'URL : le HTML contient souvent un
        // chemin relatif là où l'extracteur a rendu une URL absolue.
        $needle = self::tail($url);
        return $needle !== '' && str_contains($head, $needle);
    }

    /** Les derniers segments d'une URL, sans les paramètres. */
    private static function tail(string $url): string
    {
        $path = (string)(parse_url($url, PHP_URL_PATH) ?: '');
        $parts = array_values(array_filter(explode('/', $path)));
        if (!$parts) return '';
        return implode('/', array_slice($parts, -2));
    }

    private static function key(string $url): string
    {
        return Css::dedupeKey($url);
    }

    /**
     * L'image que le LCP mesurera, très probablement.
     *
     * Heuristique volontairement simple et vérifiable : la première balise
     * `img` du corps qui n'est ni une icône ni un pixel de suivi. Sans
     * navigateur, on ne saura jamais laquelle occupe le plus de place à
     * l'écran ; on prend donc celle que le visiteur voit en premier, ce qui est
     * la bonne réponse dans l'immense majorité des pages.
     *
     * @return array{url:?string,lazy:bool,width:?int,height:?int,bytes:?int,ms:?int}|null
     */
    public static function lcpCandidate(string $html, string $base): ?array
    {
        $bodyPos = stripos($html, '<body');
        $body = $bodyPos !== false ? substr($html, $bodyPos, 60000) : $html;
        if (!preg_match_all('~<img\b[^>]*>~i', $body, $m)) return null;
        foreach ($m[0] as $tag) {
            $src = self::attr($tag, 'src') ?? self::attr($tag, 'data-src');
            if ($src === null || $src === '') continue;
            if (str_starts_with(strtolower($src), 'data:')) continue;
            $w = self::intAttr($tag, 'width');
            $h = self::intAttr($tag, 'height');
            // Une icône, un logo minuscule ou un pixel de suivi ne sont pas le
            // plus grand élément affiché.
            if ($w !== null && $h !== null && $w <= 100 && $h <= 100) continue;
            if (preg_match('~(sprite|icon|logo|pixel|tracking|spacer|blank)~i', $src)
                && ($w === null || $w <= 200)) continue;
            $url = resolve_url($base, html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($url === null) continue;
            return [
                'url' => $url,
                'lazy' => strtolower((string)(self::attr($tag, 'loading') ?? '')) === 'lazy',
                'width' => $w, 'height' => $h, 'bytes' => null, 'ms' => null,
            ];
        }
        return null;
    }

    /**
     * Images sans dimensions déclarées.
     *
     * Une image portant un aspect-ratio en style en ligne est exclue : la place
     * est réservée, il n'y aura pas de décalage.
     *
     * @return array{count:int,samples:array<int,string>}
     */
    public static function imagesWithoutDimensions(string $html): array
    {
        $count = 0; $samples = [];
        if (!preg_match_all('~<img\b[^>]*>~i', $html, $m)) return ['count' => 0, 'samples' => []];
        foreach ($m[0] as $tag) {
            $src = self::attr($tag, 'src') ?? self::attr($tag, 'data-src') ?? '';
            if ($src === '' || str_starts_with(strtolower($src), 'data:')) continue;
            if (self::intAttr($tag, 'width') !== null && self::intAttr($tag, 'height') !== null) continue;
            $style = strtolower((string)(self::attr($tag, 'style') ?? ''));
            if (str_contains($style, 'aspect-ratio') ||
                (str_contains($style, 'width') && str_contains($style, 'height'))) continue;
            $count++;
            if (count($samples) < 5) $samples[] = self::shortUrl($src);
        }
        return ['count' => $count, 'samples' => $samples];
    }

    /** Règles @font-face sans font-display : le texte reste invisible. */
    public static function fontsWithoutDisplay(string $css): int
    {
        if ($css === '') return 0;
        $n = 0;
        if (!preg_match_all('~@font-face\s*\{([^}]{0,1200})\}~i', $css, $m)) return 0;
        foreach ($m[1] as $rule) {
            if (!preg_match('~font-display\s*:~i', $rule)) $n++;
        }
        return $n;
    }

    /** Domaines tiers qui chargent du script dans l'en-tête. */
    public static function thirdPartyHosts(string $head, string $pageUrl): array
    {
        $own = self::registrable((string)(parse_url($pageUrl, PHP_URL_HOST) ?: ''));
        $hosts = [];
        if (preg_match_all('~<script\b[^>]*\bsrc=["\']([^"\']+)["\']~i', $head, $m)) {
            foreach ($m[1] as $src) {
                $h = strtolower((string)(parse_url(trim($src), PHP_URL_HOST) ?: ''));
                if ($h === '' || self::registrable($h) === $own) continue;
                $hosts[$h] = true;
            }
        }
        ksort($hosts);
        return array_keys($hosts);
    }

    /** Domaine « propriétaire » approché : les deux derniers labels. */
    private static function registrable(string $host): string
    {
        $p = explode('.', strtolower($host));
        return count($p) >= 2 ? implode('.', array_slice($p, -2)) : $host;
    }

    // =====================================================================
    // Petits outils
    // =====================================================================
    private static function attr(string $tag, string $name): ?string
    {
        if (preg_match('~\b' . preg_quote($name, '~') . '\s*=\s*"([^"]*)"~i', $tag, $m)) return $m[1];
        if (preg_match('~\b' . preg_quote($name, '~') . '\s*=\s*\'([^\']*)\'~i', $tag, $m)) return $m[1];
        if (preg_match('~\b' . preg_quote($name, '~') . '(?=[\s/>])~i', $tag)) return '';
        return null;
    }

    private static function intAttr(string $tag, string $name): ?int
    {
        $v = self::attr($tag, $name);
        if ($v === null) return null;
        $v = trim($v);
        return ctype_digit($v) && $v !== '' ? (int)$v : null;
    }

    private static function kb(int $bytes): string
    {
        return human_bytes($bytes);
    }

    public static function shortUrl(string $url): string
    {
        $path = (string)(parse_url($url, PHP_URL_PATH) ?: $url);
        $base = basename($path);
        return $base !== '' ? $base : $url;
    }

    private static function listUrls(array $items, string $kind): string
    {
        $out = [];
        foreach ($items as $i) {
            if ($i['kind'] !== $kind) continue;
            $out[] = self::shortUrl($i['url']) . ($i['bytes'] ? ' (' . self::kb($i['bytes']) . ')' : '');
            if (count($out) >= 3) break;
        }
        return implode(' · ', $out);
    }
}
