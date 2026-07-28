<?php
declare(strict_types=1);

namespace Uptimer\Check;

use Uptimer\Http;
use Uptimer\Response;

/**
 * Détection de « la page qui se casse la figure ».
 *
 * Une page peut renvoyer un 200 impeccable et s'afficher en Times New Roman brut,
 * ou vide, ou sans aucune interaction. Aucun signal isolé ne suffit, donc on en
 * croise neuf :
 *
 *  1. DISPONIBILITÉ : chaque ressource de rendu référencée répond-elle 200 ?
 *  2. NATURE : le corps renvoyé est-il bien du CSS / du JS, et non une
 *                      page d'erreur HTML, une trace PHP, un fichier vide ?
 *  3. MIME / BLOCAGE. Content-Type incorrect + nosniff = ressource rejetée par
 *                      le navigateur ; contenu mixte http→https = bloqué ; CSP.
 *  4. INTÉGRITÉ SRI : attribut integrity qui ne correspond plus au fichier :
 *                      le navigateur refuse la ressource sans rien afficher.
 *  5. VOLUMÉTRIE : poids CSS, nombre de règles, media queries, comparés à
 *                      une empreinte de référence apprise.
 *  6. COUVERTURE : les classes réellement utilisées dans le corps de la page
 *                      trouvent-elles encore une règle ? C'est LE signal du cas
 *                      « les fichiers se chargent mais ce sont les mauvais ».
 *  7. MISE EN PAGE : présence de flex/grid/max-width/media queries.
 *  8. SCRIPTS : un JS de thème mort casse les menus, les onglets, les
 *                      carrousels ; c'est la première source d'erreurs console.
 *  9. CONTENU MASQUÉ : blocs en attente d'animation (elementor-invisible, AOS,
 *                      WOW) alors que la ressource qui les révèle a disparu.
 *
 * Le rapport reconstitue aussi les messages que le navigateur écrirait dans sa
 * console, pour que le diagnostic soit immédiatement reconnaissable.
 */
final class Css
{
    private const MAX_SHEETS      = 30;
    private const MAX_SCRIPTS     = 25;
    private const MAX_FONTS       = 4;
    private const MAX_SHEET_BYTES = 1_500_000;
    private const MAX_CLASSES     = 60;

    /** Hôtes tiers dont la panne dégrade sans casser la mise en page. */
    private const THIRD_PARTY_SOFT = [
        'fonts.googleapis.com', 'fonts.gstatic.com', 'use.typekit.net', 'use.fontawesome.com',
        'kit.fontawesome.com', 'cdnjs.cloudflare.com', 'maxcdn.bootstrapcdn.com',
        'google-analytics.com', 'googletagmanager.com', 'connect.facebook.net',
        'static.hotjar.com', 'js.stripe.com', 'maps.googleapis.com',
    ];

    /** Motifs d'assets générés par un cache : leur absence est un symptôme classique. */
    private const CACHE_HINTS = [
        '/wp-content/cache/'                 => 'cache WP (purge en cours ou fichier jamais régénéré)',
        '/cache/autoptimize/'                => 'Autoptimize',
        '/min/'                              => 'minification à la volée',
        '/litespeed/'                        => 'LiteSpeed Cache',
        '/wp-content/uploads/elementor/css/' => 'CSS Elementor par page',
        '/cache/wpfc-minified/'              => 'WP Fastest Cache',
        '/breeze-minification/'              => 'Breeze',
        '/wp-rocket/'                        => 'WP Rocket',
        '/_next/static/'                     => 'build Next.js (déploiement incomplet ?)',
        '/build/assets/'                     => 'build Vite/Laravel Mix (manifeste désynchronisé ?)',
    ];

    /**
     * Scripts dont la perte casse réellement la page (menus, onglets, sliders).
     * Couvre aussi les sorties de build modernes, du type app-4f2a1b.js.
     */
    private const CRITICAL_JS = [
        'jquery', 'frontend', 'theme', 'bundle', 'runtime', 'vendor', 'polyfill',
        'elementor', 'swiper', 'slick', 'bootstrap', 'alpine', 'divi', 'et-core',
        'js_composer', 'wp-content/themes', '/build/', '/dist/', '/_next/', '/_nuxt/',
        '/assets/', 'main.', 'app.', 'index.', 'main-', 'app-', 'index-', 'entry',
    ];

    /** Classes qui masquent le contenu jusqu'à ce qu'un script le révèle. */
    private const HIDDEN_CLASSES = [
        'elementor-invisible', 'aos-init', 'wow', 'animate__animated', 'reveal',
        'js-reveal', 'fade-in-up', 'sr-only-until-load', 'data-aos',
    ];

    /**
     * @param array $baseline empreinte de référence (peut être vide au premier passage)
     * @param array $opt      ['drop_pct','timeout','insecure','ua','check_js']
     * @return array{state:string,reason:?string,messages:array,severity:string,metrics:array,baseline:array,changed:bool,console:array}
     */
    public static function audit(string $pageUrl, string $html, ?Response $pageRes, array $baseline = [], array $opt = []): array
    {
        $dropPct = (int)($opt['drop_pct'] ?? 35);
        $result  = [
            'state' => 'ok', 'reason' => null, 'messages' => [], 'severity' => 'none',
            'metrics' => [], 'baseline' => $baseline, 'changed' => false, 'console' => [],
        ];
        if (trim($html) === '') { $result['state'] = 'unknown'; return $result; }

        $isHttps  = str_starts_with(strtolower($pageUrl), 'https://');
        $pageHost = host_of($pageUrl);
        $console  = [];   // messages tels que le navigateur les écrirait
        $critical = 0;    // anomalies qui cassent le rendu
        $soft     = 0;    // anomalies qui dégradent

        // ---- 1. Extraction -------------------------------------------------
        $refs        = self::extractStylesheets($html, $pageUrl);
        $scripts     = ($opt['check_js'] ?? true) ? self::extractScripts($html, $pageUrl) : [];
        $inline      = self::extractInlineCss($html);
        $usedClasses = self::extractUsedClasses($html);
        $hidden      = self::countHiddenContent($html);
        $nosniff     = $pageRes && stripos((string)$pageRes->header('x-content-type-options'), 'nosniff') !== false;

        $metrics = [
            'sheets_declared' => count($refs), 'sheets_ok' => 0, 'sheets_failed' => 0,
            'js_declared' => count($scripts), 'js_ok' => 0, 'js_failed' => 0,
            'fonts_checked' => 0, 'fonts_failed' => 0,
            'inline_bytes' => strlen($inline), 'css_bytes' => 0, 'rules' => 0,
            'media_queries' => 0, 'vars' => 0, 'layout_score' => 0,
            'coverage' => null, 'classes_tested' => 0, 'classes_missing' => [],
            'hidden_nodes' => $hidden['count'], 'hidden_risk' => false,
            'assets' => [], 'fingerprint' => [],
        ];

        $fetchOpt = [
            'timeout'  => (int)($opt['timeout'] ?? 12),
            'insecure' => (bool)($opt['insecure'] ?? false),
            'ua'       => $opt['ua'] ?? null,
            'maxBody'  => self::MAX_SHEET_BYTES,
            'headers'  => ['Accept' => '*/*', 'Referer' => $pageUrl],
        ];

        // ---- 2. Récupération parallèle ------------------------------------
        $requests = [];
        foreach ($refs as $i => $ref)    $requests['s' . $i] = [$ref['url'], $fetchOpt];
        foreach ($scripts as $i => $ref) $requests['j' . $i] = [$ref['url'], $fetchOpt];
        $responses = $requests ? Http::fetchMany($requests, 8) : [];

        // @import de second niveau
        $imports = [];
        foreach ($responses as $key => $res) {
            if (!str_starts_with((string)$key, 's')) continue;
            foreach (self::extractImports($res->body, $res->finalUrl ?: $res->url) as $iu) {
                if (count($imports) >= 8) break 2;
                $imports['i' . count($imports)] = [$iu, $fetchOpt];
            }
        }
        foreach (self::extractImports($inline, $pageUrl) as $iu) {
            if (count($imports) >= 8) break;
            $imports['i' . count($imports)] = [$iu, $fetchOpt];
        }

        // Attention : ['kind' => …] doit être écrasé et non fusionné, l'opérateur +
        // conserve la clé existante, ce qui ferait passer une feuille pour un script.
        $index = [];
        foreach ($refs as $i => $ref)    $index['s' . $i] = array_merge($ref, ['kind' => 'css']);
        foreach ($scripts as $i => $ref) $index['j' . $i] = array_merge($ref, ['kind' => 'js']);
        if ($imports) {
            foreach (Http::fetchMany($imports, 6) as $k => $r) {
                $responses[$k] = $r;
                $index[$k] = ['url' => $r->url, 'media' => 'all', 'kind' => 'css',
                              'rel' => 'import', 'integrity' => null, 'critical' => true];
            }
            $metrics['sheets_declared'] += count($imports);
        }

        // ---- 3. Analyse ressource par ressource ---------------------------
        $cssParts = [$inline];
        foreach ($responses as $key => $res) {
            $ref  = $index[$key] ?? ['url' => $res->url, 'kind' => 'css', 'media' => 'all', 'integrity' => null];
            $kind = ($ref['kind'] ?? 'css') === 'js' ? 'js' : 'css';
            $url  = $res->url ?: (string)$ref['url'];
            $host = host_of($url);
            $isSoft = self::isSoftThirdParty($host, $pageHost) && empty($ref['critical']);
            $bytes  = strlen($res->body);
            $issue  = null; $note = null; $cons = null;

            if (!$res->ok) {
                $issue = $res->errorCode === 'TIMEOUT' ? 'TIMEOUT' : 'UNREACHABLE';
                $note  = Http::errorLabel($res->errorCode);
                $cons  = ['err', 'GET ' . $url . ' net::ERR_FAILED (' . strtolower($note) . ')'];
            } elseif ($res->status >= 400 || $res->status === 0) {
                $issue = 'HTTP_' . $res->status;
                $note  = 'HTTP ' . $res->status . ' : le fichier n\'existe plus sur le serveur';
                $cons  = ['err', 'GET ' . $url . ' net::ERR_ABORTED ' . $res->status
                        . ' (' . ($res->status === 404 ? 'Not Found' : 'Error') . ')'];
            } elseif ($bytes === 0) {
                $issue = 'EMPTY';
                $note  = 'fichier vide (0 octet)';
                $cons  = ['warn', 'Empty response body for ' . $url];
            } elseif (self::looksLikeErrorPage($res)) {
                $issue = 'NOT_' . strtoupper($kind);
                $note  = 'le serveur renvoie du HTML ou une trace PHP au lieu du ' . strtoupper($kind);
                $cons  = $kind === 'css'
                    ? ['err', "Refused to apply style from '" . $url . "' because its MIME type ('"
                            . (str_contains((string)$res->contentType, 'html') ? 'text/html' : (string)$res->contentType)
                            . "') is not a supported stylesheet MIME type"]
                    : ['err', 'Uncaught SyntaxError: Unexpected token \'<\' (' . self::shortAsset($url) . ')'];
            } elseif (!self::mimeOk($res, $kind) && $nosniff) {
                $issue = 'MIME_BLOCKED';
                $note  = 'Content-Type « ' . str_cut((string)$res->contentType, 40) . ' » + nosniff : ressource rejetée';
                $cons  = ['err', 'Refused to ' . ($kind === 'css' ? 'apply style' : 'execute script') . " from '" . $url
                        . "' because its MIME type ('" . str_cut((string)$res->contentType, 40)
                        . "') is not " . ($kind === 'css' ? 'a supported stylesheet MIME type' : 'executable')
                        . ', and strict MIME type checking is enabled'];
            } elseif ($kind === 'css' && !self::mimeOk($res, 'css') && !$res->looksLikeCss()) {
                $issue = 'NOT_CSS';
                $note  = 'contenu non reconnu comme du CSS (Content-Type : ' . str_cut((string)$res->contentType, 40) . ')';
                $cons  = ['err', "Refused to apply style from '" . $url . "' because its MIME type is not supported"];
            }

            // Contenu mixte : ressource http sur une page https → bloquée.
            if (!$issue && $isHttps && str_starts_with(strtolower($url), 'http://')) {
                $issue = 'MIXED_CONTENT';
                $note  = 'servie en HTTP sur une page HTTPS : bloquée par le navigateur';
                $cons  = ['err', 'Mixed Content: The page at \'' . $pageUrl . '\' was loaded over HTTPS, but requested an '
                        . 'insecure ' . ($kind === 'css' ? 'stylesheet' : 'script') . ' \'' . $url . '\'. '
                        . 'This request has been blocked'];
            }

            // Intégrité (SRI) : un fichier régénéré sans mise à jour du hash est refusé.
            if (!$issue && !empty($ref['integrity']) && $bytes > 0) {
                if (!self::sriMatches((string)$ref['integrity'], $res->body)) {
                    $issue = 'SRI_MISMATCH';
                    $note  = 'empreinte integrity obsolète : le navigateur refuse le fichier';
                    $cons  = ['err', 'Failed to find a valid digest in the \'integrity\' attribute for resource \''
                            . $url . '\' with computed SHA-' . self::sriAlgo((string)$ref['integrity']) . ' integrity. '
                            . 'The resource has been blocked'];
                }
            }

            $asset = [
                'url' => $url, 'kind' => $kind, 'status' => $res->status, 'bytes' => $bytes,
                'ms' => $res->totalMs, 'sha1' => $bytes ? substr(sha1($res->body), 0, 12) : null,
                'issue' => $issue, 'note' => $note, 'soft' => $isSoft, 'media' => $ref['media'] ?? 'all',
            ];

            if ($issue) {
                $hint  = self::cacheHint($url);
                $label = self::shortAsset($url) . ' → ' . $note . ($hint ? ' [' . $hint . ']' : '');
                if ($kind === 'css') $metrics['sheets_failed']++; else $metrics['js_failed']++;

                $hard = !$isSoft && ($kind === 'css' || !empty($ref['critical']));
                if ($hard) {
                    $critical++;
                    $result['messages'][] = ($kind === 'css' ? 'Feuille de style' : 'Script essentiel') . ' en échec : ' . $label;
                } else {
                    $soft++;
                    $result['messages'][] = ($isSoft ? 'Ressource tierce' : 'Script secondaire') . ' en échec : ' . $label;
                }
            } else {
                if ($kind === 'css') { $metrics['sheets_ok']++; $cssParts[] = $res->body; }
                else $metrics['js_ok']++;
                $metrics['fingerprint'][self::assetKey($url)] = ['bytes' => $bytes, 'sha1' => $asset['sha1']];
            }
            if ($cons) $console[] = ['level' => $cons[0], 'text' => $cons[1]];
            $metrics['assets'][] = $asset;
        }

        // ---- 4. Métriques CSS globales ------------------------------------
        $clean = self::stripComments(implode("\n", $cssParts));
        $metrics['css_bytes']     = strlen($clean);
        $metrics['rules']         = substr_count($clean, '{');
        $metrics['media_queries'] = preg_match_all('~@media[^{]{1,200}\{~i', $clean);
        $metrics['vars']          = preg_match_all('~--[a-z0-9-]+\s*:~i', $clean);
        $metrics['layout_score']  = self::layoutScore($clean);

        // ---- 5. Polices déclarées en @font-face ---------------------------
        $fonts = self::extractFontUrls($clean, $pageUrl);
        if ($fonts) {
            foreach (Http::fetchMany(array_map(fn($u) => [$u, $fetchOpt + ['range' => '0-2048']], $fonts), 4) as $r) {
                $metrics['fonts_checked']++;
                if (!$r->ok || $r->status >= 400) {
                    $metrics['fonts_failed']++;
                    $soft++;
                    $result['messages'][] = 'Police introuvable : ' . self::shortAsset($r->url)
                        . ' → ' . ($r->status ?: Http::errorLabel($r->errorCode));
                    $console[] = ['level' => 'err', 'text' => 'GET ' . $r->url . ' net::ERR_ABORTED ' . ($r->status ?: 0)];
                }
            }
        }

        // ---- 6. Couverture des classes ------------------------------------
        $cov = self::coverage($clean, $usedClasses);
        $metrics['coverage']        = $cov['ratio'];
        $metrics['classes_tested']  = $cov['tested'];
        $metrics['classes_missing'] = $cov['missing'];

        // ---- 7. Contenu potentiellement invisible -------------------------
        if ($hidden['count'] > 0) {
            $revealBroken = false;
            foreach ($metrics['assets'] as $a) {
                if ($a['issue'] && self::isRevealAsset($a['url'])) $revealBroken = true;
            }
            if ($revealBroken) {
                $critical++;
                $metrics['hidden_risk'] = true;
                $result['messages'][] = $hidden['count'] . ' bloc(s) masqué(s) en attente d\'animation alors que la '
                    . 'ressource qui devait les révéler est cassée : le contenu risque de rester invisible.';
            }
        }

        // ---- 8. CSP -------------------------------------------------------
        if ($pageRes) {
            $csp = (string)($pageRes->header('content-security-policy') ?? '');
            if ($csp !== '' && preg_match('~style-src([^;]*)~i', $csp, $m)
                && str_contains(strtolower($m[1]), "'none'")) {
                $critical++;
                $result['messages'][] = 'En-tête CSP style-src \'none\' : toutes les feuilles de style sont bloquées.';
                $console[] = ['level' => 'err', 'text' => 'Refused to load the stylesheet because it violates the '
                    . 'following Content Security Policy directive: "style-src \'none\'"'];
            }
        }

        // ---- 9. Comparaison à la référence --------------------------------
        $delta = [];
        // Une page peut être entièrement stylée en interne : on ne s'alarme que si
        // le style intégré ne suffit pas à mettre la page en forme.
        $inlineStyled = $metrics['inline_bytes'] >= 200 || $metrics['layout_score'] >= 20;
        if ($metrics['sheets_declared'] === 0 && !$inlineStyled) {
            if (!empty($baseline['sheets_declared'])) {
                $critical++;
                $result['messages'][] = 'Plus aucune feuille de style déclarée dans le HTML (référence : '
                    . (int)$baseline['sheets_declared'] . ').';
            } elseif (!$baseline) {
                // Première observation : on le signale une fois. Si c'est l'état
                // normal de la page, la référence l'enregistrera et on se taira.
                $soft++;
                $result['messages'][] = 'Aucune feuille de style détectée sur cette page.';
            }
        }
        if ($baseline) {
            $delta = self::compare($metrics, $baseline, $dropPct);
            foreach ($delta['critical'] as $msg) { $critical++; $result['messages'][] = $msg; }
            foreach ($delta['warn'] as $msg)     { $soft++;     $result['messages'][] = $msg; }
            $result['changed'] = $delta['changed'];
        }

        // ---- Verdict ------------------------------------------------------
        if ($critical > 0) {
            $result['state'] = 'broken'; $result['severity'] = 'critical'; $result['reason'] = 'CSS_BROKEN';
        } elseif ($soft > 0) {
            $result['state'] = 'warn'; $result['severity'] = 'warn'; $result['reason'] = 'CSS_DEGRADED';
        }

        $result['metrics']  = $metrics;
        $result['delta']    = $delta;
        $result['console']  = array_slice($console, 0, 12);
        $result['baseline'] = self::buildBaseline($metrics, $usedClasses);
        return $result;
    }

    // =====================================================================
    // Extraction
    // =====================================================================

    /** @return array<int,array{url:string,media:string,rel:string,integrity:?string,critical:bool}> */
    public static function extractStylesheets(string $html, string $base): array
    {
        $out = []; $seen = [];
        if (preg_match_all('~<link\b[^>]*>~i', $html, $tags)) {
            foreach ($tags[0] as $tag) {
                $rel   = strtolower(self::attr($tag, 'rel') ?? '');
                $as    = strtolower(self::attr($tag, 'as') ?? '');
                $href  = self::attr($tag, 'href');
                $media = strtolower(self::attr($tag, 'media') ?? 'all');
                if (!$href) continue;
                if (!(str_contains($rel, 'stylesheet') || ($rel === 'preload' && $as === 'style'))) continue;
                if ($media !== '' && preg_match('~^\s*print\s*$~', $media)) continue;  // print : n'affecte pas l'écran
                $url = resolve_url($base, html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if (!$url) continue;
                $k = self::dedupeKey($url);
                if (isset($seen[$k])) continue;
                $seen[$k] = true;
                $out[] = ['url' => $url, 'media' => $media ?: 'all',
                          'rel' => $rel === 'preload' ? 'preload' : 'link',
                          'integrity' => self::attr($tag, 'integrity'), 'critical' => true];
                if (count($out) >= self::MAX_SHEETS) break;
            }
        }
        return $out;
    }

    /**
     * Tous les scripts de la page, avec repérage de ceux dont la perte casse
     * réellement le rendu ou les interactions.
     * @return array<int,array{url:string,media:string,integrity:?string,critical:bool,defer:bool}>
     */
    public static function extractScripts(string $html, string $base): array
    {
        $out = []; $seen = [];
        if (!preg_match_all('~<script\b[^>]*>~i', $html, $tags)) return [];
        foreach ($tags[0] as $tag) {
            $src = self::attr($tag, 'src');
            if (!$src) continue;
            $type = strtolower(self::attr($tag, 'type') ?? '');
            if ($type !== '' && !in_array($type, ['text/javascript', 'application/javascript', 'module'], true)) continue;
            $url = resolve_url($base, html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (!$url) continue;
            $k = self::dedupeKey($url);
            if (isset($seen[$k])) continue;
            $seen[$k] = true;
            $out[] = [
                'url' => $url, 'media' => 'all', 'type' => $type,
                'integrity' => self::attr($tag, 'integrity'),
                // Un script de type module est presque toujours le point d'entrée
                // de l'application : sa perte casse tout.
                'critical'  => $type === 'module' || self::isCriticalJs($url),
                'defer'     => self::attr($tag, 'defer') !== null || self::attr($tag, 'async') !== null,
            ];
            if (count($out) >= self::MAX_SCRIPTS) break;
        }
        return $out;
    }

    public static function extractInlineCss(string $html): string
    {
        $css = '';
        if (preg_match_all('~<style\b[^>]*>(.*?)</style>~is', $html, $m)) {
            foreach ($m[1] as $block) $css .= "\n" . $block;
        }
        return $css;
    }

    public static function extractImports(string $css, string $base): array
    {
        $out = [];
        if (preg_match_all('~@import\s+(?:url\(\s*)?["\']?([^"\'\)\s;]+)~i', substr($css, 0, 200000), $m)) {
            foreach ($m[1] as $rel) {
                $u = resolve_url($base, $rel);
                if ($u) $out[] = $u;
            }
        }
        return array_slice(array_unique($out), 0, 8);
    }

    /** URLs de polices déclarées en @font-face (une par famille, échantillon). */
    public static function extractFontUrls(string $css, string $base): array
    {
        $out = [];
        if (preg_match_all('~@font-face\s*\{([^}]*)\}~i', $css, $blocks)) {
            foreach ($blocks[1] as $b) {
                if (!preg_match('~url\(\s*["\']?([^"\')\s]+)~i', $b, $m)) continue;
                if (str_starts_with($m[1], 'data:')) continue;
                $u = resolve_url($base, $m[1]);
                if ($u && !in_array($u, $out, true)) $out[] = $u;
                if (count($out) >= self::MAX_FONTS) break;
            }
        }
        return $out;
    }

    /** Classes les plus utilisées dans le corps du document. */
    public static function extractUsedClasses(string $html): array
    {
        $body = $html;
        if (preg_match('~<body\b[^>]*>(.*)~is', $html, $m)) $body = $m[1];

        $freq = [];
        if (preg_match_all('~\sclass\s*=\s*(?:"([^"]*)"|\'([^\']*)\')~i', $body, $mm)) {
            foreach ($mm[1] as $i => $v) {
                $val = $v !== '' ? $v : ($mm[2][$i] ?? '');
                foreach (preg_split('~\s+~', trim($val)) ?: [] as $cls) {
                    $cls = trim($cls);
                    if ($cls === '' || strlen($cls) < 3 || strlen($cls) > 60) continue;
                    if (!preg_match('~^[a-zA-Z_-][a-zA-Z0-9_:./\[\]%!@#-]*$~', $cls)) continue;
                    if (in_array($cls, self::HIDDEN_CLASSES, true)) continue;
                    $freq[$cls] = ($freq[$cls] ?? 0) + 1;
                }
            }
        }
        arsort($freq);
        return array_slice($freq, 0, self::MAX_CLASSES, true);
    }

    private static function countHiddenContent(string $html): array
    {
        $count = 0; $libs = [];
        foreach (self::HIDDEN_CLASSES as $cls) {
            $n = preg_match_all('~class\s*=\s*["\'][^"\']*\b' . preg_quote($cls, '~') . '\b~i', $html);
            if ($n) {
                $count += $n;
                if (str_contains($cls, 'elementor')) $libs[] = 'elementor';
                elseif (str_starts_with($cls, 'aos')) $libs[] = 'aos';
                elseif ($cls === 'wow' || str_contains($cls, 'animate__')) $libs[] = 'animate';
            }
        }
        $n = preg_match_all('~\sdata-aos\s*=~i', $html);
        if ($n) { $count += $n; $libs[] = 'aos'; }
        return ['count' => $count, 'libs' => array_values(array_unique($libs))];
    }

    // =====================================================================
    // Analyse
    // =====================================================================

    private static function attr(string $tag, string $name): ?string
    {
        if (preg_match('~\b' . preg_quote($name, '~') . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))~i', $tag, $m)) {
            // PCRE n'alimente pas les groupes suivant celui qui a matché.
            foreach ([1, 2, 3] as $g) if (($m[$g] ?? '') !== '') return $m[$g];
            return '';
        }
        if (preg_match('~\b' . preg_quote($name, '~') . '\b~i', $tag)) return '';
        return null;
    }

    private static function mimeOk(Response $res, string $kind): bool
    {
        $ct = strtolower((string)$res->contentType);
        if ($ct === '') return true;
        return $kind === 'css'
            ? str_contains($ct, 'css')
            : (str_contains($ct, 'javascript') || str_contains($ct, 'ecmascript') || str_contains($ct, 'text/plain'));
    }

    private static function looksLikeErrorPage(Response $res): bool
    {
        $head = ltrim(substr($res->body, 0, 1500));
        if ($head === '') return false;
        if (preg_match('~^(<!doctype|<html|<\?xml|<\?php)~i', $head)) return true;
        return (bool)preg_match('~(Fatal error|Parse error|Warning:\s|Notice:\s|Not Found</title>|Forbidden</title>|Internal Server Error)~i', $head);
    }

    /** Vérifie un attribut integrity (« sha384-base64 », plusieurs valeurs possibles). */
    public static function sriMatches(string $integrity, string $body): bool
    {
        $any = false;
        foreach (preg_split('~\s+~', trim($integrity)) ?: [] as $token) {
            if (!preg_match('~^sha(256|384|512)-(.+)$~i', $token, $m)) continue;
            $any = true;
            $algo = 'sha' . $m[1];
            $expected = rtrim(strtr($m[2], '-_', '+/'), '=');
            $actual   = rtrim(strtr(base64_encode(hash($algo, $body, true)), '-_', '+/'), '=');
            if (hash_equals($expected, $actual)) return true;
        }
        return !$any;   // attribut illisible : on ne conclut pas à une erreur
    }

    public static function sriAlgo(string $integrity): string
    {
        return preg_match('~^sha(256|384|512)~i', trim($integrity), $m) ? $m[1] : '384';
    }

    private static function stripComments(string $css): string
    {
        return (string)preg_replace('~/\*.*?\*/~s', '', $css);
    }

    private static function layoutScore(string $css): int
    {
        $score  = min(25, preg_match_all('~display\s*:\s*(flex|grid|inline-flex)~i', $css) * 2);
        $score += min(20, preg_match_all('~(max-width|min-width)\s*:~i', $css));
        $score += min(20, preg_match_all('~@media[^{]{1,200}\{~i', $css) * 3);
        $score += min(15, (int)(preg_match_all('~(margin|padding)\s*:~i', $css) / 4));
        $score += min(10, preg_match_all('~font-family\s*:~i', $css) * 2);
        $score += min(10, preg_match_all('~(grid-template|flex-direction|gap)\s*:~i', $css) * 2);
        return (int)round($score);
    }

    /** Fraction pondérée des classes du HTML qui trouvent une règle dans le CSS. */
    public static function coverage(string $css, array $usedClasses): array
    {
        $tested = count($usedClasses);
        if ($tested === 0) return ['ratio' => null, 'tested' => 0, 'missing' => []];

        $hay = strtolower(str_replace('\\', '', $css));
        if (strlen($hay) > 2_500_000) $hay = substr($hay, 0, 2_500_000);

        $classes = array_keys($usedClasses);
        usort($classes, fn($a, $b) => strlen($b) <=> strlen($a));

        $covered = [];
        foreach (array_chunk($classes, 20) as $chunk) {
            $alt = implode('|', array_map(fn($c) => preg_quote(strtolower($c), '~'), $chunk));
            if ($alt === '') continue;
            if (@preg_match_all('~\.(' . $alt . ')(?![a-z0-9_-])~', $hay, $m)) {
                foreach ($m[1] as $hit) $covered[$hit] = true;
            }
        }

        $totalW = 0; $okW = 0; $missing = [];
        foreach ($usedClasses as $cls => $w) {
            $totalW += $w;
            if (isset($covered[strtolower($cls)])) $okW += $w;
            elseif (count($missing) < 12) $missing[] = $cls;
        }
        return ['ratio' => $totalW > 0 ? round($okW / $totalW, 4) : null, 'tested' => $tested, 'missing' => $missing];
    }

    private static function compare(array $m, array $b, int $dropPct): array
    {
        $crit = []; $warn = []; $changed = false;
        $drop = function (?float $now, ?float $ref): ?float {
            if ($ref === null || $ref <= 0 || $now === null) return null;
            return round((1 - ($now / $ref)) * 100, 1);
        };

        $d = $drop((float)$m['css_bytes'], (float)($b['css_bytes'] ?? 0));
        if ($d !== null) {
            if ($d >= $dropPct) {
                $crit[] = sprintf('Poids CSS en chute de %.0f %% (%s au lieu de %s attendus).',
                    $d, human_bytes($m['css_bytes']), human_bytes((int)$b['css_bytes']));
            } elseif ($d >= max(12, $dropPct / 2)) {
                $warn[] = sprintf('Poids CSS en baisse de %.0f %% (%s / %s).',
                    $d, human_bytes($m['css_bytes']), human_bytes((int)$b['css_bytes']));
            }
        }

        $dr = $drop((float)$m['rules'], (float)($b['rules'] ?? 0));
        if ($dr !== null && $dr >= $dropPct) {
            $crit[] = sprintf('Nombre de règles CSS divisé (%d au lieu de %d).', $m['rules'], (int)$b['rules']);
        }

        $refCount = (int)($b['sheets_ok'] ?? 0);
        if ($refCount > 0 && $m['sheets_ok'] < $refCount) {
            $msg = sprintf('%d feuille(s) de style en moins qu\'en référence (%d/%d chargées).',
                $refCount - $m['sheets_ok'], $m['sheets_ok'], $refCount);
            if (($d ?? 0) >= 10) $crit[] = $msg; else $warn[] = $msg;
        }

        $refJs = (int)($b['js_ok'] ?? 0);
        if ($refJs > 0 && ($m['js_ok'] ?? 0) < $refJs) {
            $warn[] = sprintf('%d script(s) en moins qu\'en référence (%d/%d chargés).',
                $refJs - $m['js_ok'], $m['js_ok'], $refJs);
        }

        if (($b['media_queries'] ?? 0) >= 3 && $m['media_queries'] === 0) {
            $crit[] = 'Plus aucune media query : la mise en page responsive est perdue.';
        }
        if (($b['layout_score'] ?? 0) >= 40 && $m['layout_score'] < ((int)$b['layout_score']) * 0.5) {
            $crit[] = sprintf('Capacité de mise en page effondrée (score %d au lieu de %d).',
                $m['layout_score'], (int)$b['layout_score']);
        }

        $covNow = $m['coverage']; $covRef = $b['coverage'] ?? null;
        if ($covNow !== null) {
            if ($covRef !== null && $covRef >= 0.5) {
                $gap = round(($covRef - $covNow) * 100, 1);
                if ($gap >= 25 || ($covNow < 0.45 && $gap >= 15)) {
                    $crit[] = sprintf('Les classes de la page ne sont plus couvertes par le CSS : %.0f %% contre %.0f %% en référence%s.',
                        $covNow * 100, $covRef * 100,
                        !empty($m['classes_missing']) ? ' (ex. ' . implode(', ', array_slice($m['classes_missing'], 0, 4)) . ')' : '');
                } elseif ($gap >= 12) {
                    $warn[] = sprintf('Couverture CSS en baisse : %.0f %% contre %.0f %%.', $covNow * 100, $covRef * 100);
                }
            } elseif ($covRef === null && $covNow < 0.25 && $m['classes_tested'] >= 10) {
                $warn[] = sprintf('Seulement %.0f %% des classes de la page trouvent une règle CSS.', $covNow * 100);
            }
        }

        foreach ($m['fingerprint'] as $k => $v) {
            if (isset($b['fingerprint'][$k]) && $b['fingerprint'][$k]['sha1'] !== $v['sha1']) { $changed = true; break; }
        }
        return ['critical' => $crit, 'warn' => $warn, 'changed' => $changed];
    }

    private static function buildBaseline(array $m, array $usedClasses): array
    {
        return [
            'sheets_declared' => $m['sheets_declared'], 'sheets_ok' => $m['sheets_ok'],
            'js_declared' => $m['js_declared'], 'js_ok' => $m['js_ok'],
            'css_bytes' => $m['css_bytes'], 'rules' => $m['rules'],
            'media_queries' => $m['media_queries'], 'vars' => $m['vars'],
            'layout_score' => $m['layout_score'], 'coverage' => $m['coverage'],
            'inline_bytes' => $m['inline_bytes'],
            'classes' => array_slice(array_keys($usedClasses), 0, 30),
            'fingerprint' => $m['fingerprint'], 'built_at' => now(),
        ];
    }

    // =====================================================================
    // Utilitaires
    // =====================================================================

    /**
     * Clé de déduplication : URL complète, purgée des seuls paramètres de
     * cache-busting. Indispensable pour les sites qui servent tous leurs assets
     * par un même script (…/cached.php?f=…, …/load.php?modules=…) : réduire au
     * chemin ferait disparaître neuf feuilles sur dix.
     */
    public static function dedupeKey(string $url): string
    {
        $p = parse_url($url);
        $base = strtolower(($p['host'] ?? '') . ($p['path'] ?? '/'));
        $q = [];
        if (!empty($p['query'])) {
            parse_str($p['query'], $q);
            foreach (['ver', 'v', 't', 'ts', '_', 'cache', 'rev', 'version'] as $bust) unset($q[$bust]);
            ksort($q);
        }
        return $base . ($q ? '?' . http_build_query($q) : '');
    }

    /** Clé stable d'un asset pour l'empreinte : chemin sans cache-buster. */
    public static function assetKey(string $url): string
    {
        $p = parse_url($url);
        $path = preg_replace('~[0-9a-f]{8,32}~i', '#', $p['path'] ?? '/') ?? '/';
        return strtolower(($p['host'] ?? '') . $path);
    }

    public static function shortAsset(string $url): string
    {
        $p    = parse_url($url);
        $file = basename((string)($p['path'] ?? '')) ?: ($p['host'] ?? $url);
        $dir  = trim(dirname((string)($p['path'] ?? '')), '/');
        $dir  = ($dir === '' || $dir === '.') ? '' : '…/' . basename($dir) . '/';
        return $dir . $file;
    }

    public static function isCriticalJs(string $url): bool
    {
        $l = strtolower($url);
        foreach (self::CRITICAL_JS as $needle) if (str_contains($l, $needle)) return true;
        return false;
    }

    private static function isSoftThirdParty(string $host, string $pageHost): bool
    {
        if ($host === '' || $host === $pageHost) return false;
        foreach (self::THIRD_PARTY_SOFT as $soft) if (str_ends_with($host, $soft)) return true;
        return registrable_domain($host) !== registrable_domain($pageHost) && !str_contains($host, 'cdn');
    }

    private static function isRevealAsset(string $url): bool
    {
        return (bool)preg_match('~(animat|aos|wow|elementor|reveal|transition|frontend)~i', $url);
    }

    private static function cacheHint(string $url): ?string
    {
        $l = strtolower($url);
        foreach (self::CACHE_HINTS as $needle => $label) if (str_contains($l, $needle)) return $label;
        return null;
    }
}
