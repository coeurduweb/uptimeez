<?php
declare(strict_types=1);

namespace Uptimer\Detect;

use Uptimer\Http;
use Uptimer\Response;

/**
 * Choix automatique des pages à surveiller pour un domaine, et déduction
 * d'une chaîne de preuve (« ce texte doit être présent »).
 *
 * Ordre de préférence : sitemap déclaré dans robots.txt → sitemaps usuels →
 * liens internes de la page d'accueil.
 */
final class Discovery
{
    private const SKIP_PATTERNS = [
        '~/wp-admin~i', '~/wp-login~i', '~/xmlrpc~i', '~/feed/?$~i', '~/comments~i',
        '~/tag/~i', '~/author/~i', '~/page/\d+~i', '~/category/.*/page~i',
        '~\.(jpg|jpeg|png|gif|webp|svg|pdf|zip|mp4|mp3|css|js|ico|woff2?)$~i',
        '~/cart~i', '~/panier~i', '~/checkout~i', '~/commande~i', '~/mon-compte~i',
        '~/my-account~i', '~/connexion~i', '~/logout~i', '~/deconnexion~i',
        '~/wc-api~i', '~\?add-to-cart~i', '~/attachment/~i', '~#~',
    ];

    /** Chemins « à forte valeur » : on essaie d'en attraper un de chaque famille. */
    private const VALUE_PATTERNS = [
        'contact'   => '~/(contact|nous-contacter|contactez)~i',
        'offre'     => '~/(services?|prestations?|offres?|solutions?|produits?|boutique|shop|catalogue)~i',
        'tarifs'    => '~/(tarifs?|prix|pricing|devis)~i',
        'apropos'   => '~/(a-propos|qui-sommes-nous|about|entreprise|agence|equipe)~i',
        'contenu'   => '~/(blog|actualites?|news|articles?|conseils?|guides?)~i',
        'legal'     => '~/(mentions-legales|cgv|politique-de-confidentialite|privacy)~i',
    ];

    /** Récupère la liste des sitemaps déclarés + emplacements usuels. */
    public static function sitemapCandidates(string $root, array $opt = []): array
    {
        $cands = [];
        $robots = Http::fetch($root . '/robots.txt', [
            'timeout' => (int)($opt['timeout'] ?? 10), 'maxBody' => 200000,
            'insecure' => (bool)($opt['insecure'] ?? false),
        ]);
        if ($robots->ok && $robots->status === 200 && !$robots->isHtml()) {
            if (preg_match_all('~^\s*sitemap\s*:\s*(\S+)~im', $robots->body, $m)) {
                foreach ($m[1] as $u) $cands[] = trim($u);
            }
        }
        foreach (['/sitemap.xml', '/sitemap_index.xml', '/wp-sitemap.xml', '/sitemap-index.xml',
                  '/sitemap/sitemap-index.xml', '/sitemap1.xml'] as $p) {
            $cands[] = $root . $p;
        }
        return array_values(array_unique($cands));
    }

    /**
     * URLs listées par les sitemaps (récursion sur un niveau d'index).
     * @return array<int,array{loc:string,lastmod:?string,priority:?float}>
     */
    public static function fromSitemaps(string $root, array $opt = [], int $max = 400): array
    {
        $urls = [];
        $tried = 0;
        foreach (self::sitemapCandidates($root, $opt) as $sm) {
            if ($tried >= 4 || count($urls) >= $max) break;
            $res = Http::fetch($sm, ['timeout' => (int)($opt['timeout'] ?? 12), 'maxBody' => 2000000,
                                     'insecure' => (bool)($opt['insecure'] ?? false)]);
            if (!$res->ok || $res->status !== 200) continue;
            $body = $res->body;
            if (!str_contains($body, '<')) continue;
            $tried++;

            // Index de sitemaps : on descend d'un niveau (3 fichiers max)
            if (preg_match('~<sitemapindex~i', $body)) {
                $subs = [];
                if (preg_match_all('~<sitemap>.*?<loc>\s*([^<\s]+)\s*</loc>~is', $body, $m)) {
                    $subs = array_slice($m[1], 0, 3);
                }
                foreach ($subs as $sub) {
                    $r2 = Http::fetch(html_entity_decode($sub), ['timeout' => 12, 'maxBody' => 2000000,
                                                                'insecure' => (bool)($opt['insecure'] ?? false)]);
                    if ($r2->ok && $r2->status === 200) $urls = array_merge($urls, self::parseUrlset($r2->body));
                    if (count($urls) >= $max) break;
                }
            } else {
                $urls = array_merge($urls, self::parseUrlset($body));
            }
            if ($urls) break; // un sitemap exploitable suffit
        }
        return array_slice($urls, 0, $max);
    }

    private static function parseUrlset(string $xml): array
    {
        $out = [];
        if (!preg_match_all('~<url>(.*?)</url>~is', $xml, $blocks)) {
            // Sitemap plat sans <url> : on prend les <loc>
            if (preg_match_all('~<loc>\s*([^<\s]+)\s*</loc>~i', $xml, $m)) {
                foreach ($m[1] as $loc) $out[] = ['loc' => html_entity_decode($loc), 'lastmod' => null, 'priority' => null];
            }
            return $out;
        }
        foreach ($blocks[1] as $b) {
            if (!preg_match('~<loc>\s*([^<\s]+)\s*</loc>~i', $b, $m)) continue;
            $lastmod = preg_match('~<lastmod>\s*([^<\s]+)~i', $b, $m2) ? $m2[1] : null;
            $prio    = preg_match('~<priority>\s*([\d.]+)~i', $b, $m3) ? (float)$m3[1] : null;
            $out[] = ['loc' => html_entity_decode($m[1]), 'lastmod' => $lastmod, 'priority' => $prio];
        }
        return $out;
    }

    /** Liens internes trouvés dans le HTML de l'accueil. */
    public static function fromHtml(string $html, string $base): array
    {
        $host = host_of($base);
        $out  = [];
        if (preg_match_all('~<a\b[^>]*\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\')~i', $html, $m)) {
            foreach ($m[1] as $i => $v) {
                $href = $v !== '' ? $v : ($m[2][$i] ?? '');
                $u = resolve_url($base, html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if (!$u) continue;
                if (host_of($u) !== $host) continue;
                $u = strtok($u, '#');
                if (!$u) continue;
                $out[$u] = ['loc' => $u, 'lastmod' => null, 'priority' => null];
            }
        }
        return array_values($out);
    }

    /**
     * Sélection finale : accueil + pages représentatives et variées.
     * @return array<int,array{url:string,label:string,why:string}>
     */
    public static function pickPages(string $root, string $homeHtml, int $limit = 5, array $opt = []): array
    {
        $home = $root . '/';
        $pool = self::fromSitemaps($root, $opt);
        $source = 'sitemap';
        if (count($pool) < 3) {
            $pool = self::fromHtml($homeHtml, $home);
            $source = 'liens internes';
        }

        $host = host_of($root);
        $cands = [];
        foreach ($pool as $item) {
            $u = $item['loc'];
            if (host_of($u) !== $host) continue;
            $path = parse_url($u, PHP_URL_PATH) ?: '/';
            if ($path === '/' || $path === '') continue;
            foreach (self::SKIP_PATTERNS as $re) if (preg_match($re, $u)) continue 2;
            if (isset($cands[$u])) continue;
            $depth = count(array_filter(explode('/', trim($path, '/'))));
            $score = 0;
            $family = null;
            foreach (self::VALUE_PATTERNS as $fam => $re) {
                if (preg_match($re, $path)) { $score += 30; $family = $fam; break; }
            }
            $score += match ($depth) { 1 => 20, 2 => 14, 3 => 6, default => 0 };
            if ($item['priority'] !== null) $score += (int)round($item['priority'] * 10);
            if (strlen($path) > 120) $score -= 10;
            $cands[$u] = ['url' => $u, 'score' => $score, 'family' => $family, 'depth' => $depth, 'path' => $path];
        }

        usort($cands, fn($a, $b) => $b['score'] <=> $a['score'] ?: strlen($a['path']) <=> strlen($b['path']));

        $picked   = [['url' => $home, 'label' => 'Accueil', 'why' => 'page d\'accueil']];
        $families = [];
        $segments = [];

        foreach ($cands as $c) {
            if (count($picked) >= $limit) break;
            $seg = explode('/', trim($c['path'], '/'))[0] ?? '';
            // Diversité : une page par famille, et pas trois pages du même dossier.
            if ($c['family'] && isset($families[$c['family']])) continue;
            if (($segments[$seg] ?? 0) >= 1 && count($picked) > 1) continue;
            if ($c['family']) $families[$c['family']] = true;
            $segments[$seg] = ($segments[$seg] ?? 0) + 1;
            $picked[] = [
                'url'    => $c['url'],
                'label'  => self::labelFor($c['path'], $c['family']),
                'family' => $c['family'],
                'why'    => $c['family'] ? 'page ' . $c['family'] . ' (' . $source . ')' : 'page interne (' . $source . ')',
            ];
        }
        return $picked;
    }

    private static function labelFor(string $path, ?string $family): string
    {
        $slug = basename(rtrim($path, '/'));
        $slug = preg_replace('~\.(html?|php)$~i', '', $slug) ?? $slug;
        $words = ucfirst(str_replace('-', ' ', urldecode($slug)));
        $words = str_cut($words, 40);
        return $words !== '' ? $words : ($family ? ucfirst($family) : 'Page');
    }

    /**
     * Déduit une chaîne de caractères qui prouve que le serveur web ET la base
     * répondent : on privilégie un texte du corps (nav, pied de page), car il
     * disparaît dès que la couche données tombe.
     */
    public static function suggestExpectString(string $html, int $status = 200): ?string
    {
        // Jamais de chaîne déduite d'une page d'erreur : elle deviendrait la
        // « preuve » que le site fonctionne alors qu'elle prouve l'inverse.
        if ($status < 200 || $status >= 300) return null;
        if (preg_match('~<title[^>]*>\s*(\d{3}\s+)?(not found|forbidden|error|erreur|maintenance|service unavailable)~i', $html)) {
            return null;
        }

        $cands = [];

        // 1. Pied de page : mention de copyright (rendue depuis les réglages du site)
        if (preg_match('~(?:©|&copy;|&#169;)\s*(?:\d{4}\s*(?:[-–]\s*\d{4})?\s*)?([^<>|·•\n\r]{3,60})~u', $html, $m)) {
            $cands[] = ['v' => trim($m[1]), 'w' => 90];
        }
        // 2. Nom du site déclaré (Open Graph)
        if (preg_match('~<meta[^>]+property=["\']og:site_name["\'][^>]+content=["\']([^"\']{3,60})~i', $html, $m)) {
            $cands[] = ['v' => trim($m[1]), 'w' => 80];
        }
        // 3. Nom du site déduit du <title> (après le séparateur)
        if (preg_match('~<title[^>]*>(.*?)</title>~is', $html, $m)) {
            $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (preg_match('~[-|–—•·]\s*([^-|–—•·]{3,50})$~u', $title, $t)) {
                $cands[] = ['v' => trim($t[1]), 'w' => 70];
            } elseif ($title !== '') {
                $cands[] = ['v' => str_cut($title, 45), 'w' => 40];
            }
        }
        // 4. Premier élément de navigation
        if (preg_match('~<nav\b[^>]*>(.*?)</nav>~is', $html, $m)
            && preg_match_all('~>([^<>{}]{4,40})</a>~', $m[1], $links)) {
            foreach ($links[1] as $txt) {
                $txt = trim(html_entity_decode($txt, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($txt !== '' && !preg_match('~^\s*(menu|accueil|home)\s*$~i', $txt)) {
                    $cands[] = ['v' => $txt, 'w' => 55];
                    break;
                }
            }
        }
        // 5. Titre H1
        if (preg_match('~<h1[^>]*>(.*?)</h1>~is', $html, $m)) {
            $h1 = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($h1 !== '') $cands[] = ['v' => str_cut($h1, 45), 'w' => 50];
        }

        usort($cands, fn($a, $b) => $b['w'] <=> $a['w']);

        // Formulations passe-partout : présentes partout, donc sans valeur de preuve.
        $generic = '~^(accueil|home|contact|menu|blog|actualit[ée]s?|services?|tous droits r[ée]serv[ée]s|'
                 . 'mentions l[ée]gales|copyright|all rights reserved|politique de confidentialit[ée])$~iu';

        foreach ($cands as $c) {
            foreach (self::segments((string)$c['v']) as $v) {
                if (mb_strlen($v) < 3 || mb_strlen($v) > 60) continue;
                if (preg_match('~^[\d\s\W]+$~u', $v)) continue;
                if (preg_match($generic, $v)) continue;
                // La chaîne doit réellement figurer dans le HTML telle quelle.
                if (stripos($html, $v) === false) continue;
                return $v;
            }
        }
        return null;
    }

    /**
     * Découpe un candidat sur ses séparateurs et propose d'abord le segment le
     * plus identifiant : « © 2026 Agence Bellevue, tous droits réservés »
     * donne « Agence Bellevue » avant la phrase entière.
     */
    private static function segments(string $raw): array
    {
        $clean = trim(preg_replace('~\s+~u', ' ', $raw) ?? $raw);
        $clean = trim($clean, " \t\n\r\0\x0B.,;:–—-|·•\"'");
        if ($clean === '') return [];

        $out = [];
        foreach (preg_split('~\s*[|·•—–]\s*|\s+[-]\s+~u', $clean) ?: [] as $piece) {
            $piece = trim($piece, " \t.,;:\"'");
            if ($piece !== '' && $piece !== $clean) $out[] = $piece;
        }
        $out[] = $clean;                       // repli : la chaîne complète
        return array_values(array_unique($out));
    }

    /** Détecte un noindex accidentel (en-tête ou meta). */
    public static function noindex(Response $res): ?string
    {
        $xr = strtolower((string)$res->header('x-robots-tag'));
        if ($xr !== '' && str_contains($xr, 'noindex')) {
            return t('en-tête {name} : {value}', ['name' => 'X-Robots-Tag', 'value' => str_cut($xr, 40)]);
        }
        $head = substr($res->body, 0, 60000);
        if (preg_match('~<meta[^>]+name=["\']robots["\'][^>]+content=["\']([^"\']*)~i', $head, $m)
            && stripos($m[1], 'noindex') !== false) {
            return 'balise meta robots : ' . str_cut(trim($m[1]), 40);
        }
        return null;
    }
}
