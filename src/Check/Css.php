<?php
declare(strict_types=1);

namespace Uptimeez\Check;

use Uptimeez\Http;
use Uptimeez\Response;
use Uptimeez\Ui;

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

    /**
     * Formats de police qu'aucun navigateur encore utilisé ne demande.
     *
     * « eot » est propre à Internet Explorer, dont la dernière version a cessé d'être
     * maintenue en 2022 ; « svg » ne servait qu'à Safari 4 et aux vieux WebKit mobiles.
     * Les deux restent déclarés dans presque tous les @font-face du web, parce que les
     * générateurs de polices continuent de les produire. Leur absence sur le serveur est
     * donc l'état NORMAL d'un site moderne, pas une panne.
     */
    private const FORMATS_HERITES = ['eot', 'svg'];

    /**
     * Version du CONTRAT D'EXTRACTION, à incrémenter dès que ce fichier change ce qu'il
     * compte : feuilles retenues, scripts retenus, polices retenues, base de résolution.
     *
     * POURQUOI CE NOMBRE EXISTE, ET CE QU'IL A COÛTÉ DE NE PAS L'AVOIR
     *
     * La référence enregistre « ce site charge 4 scripts et 12 feuilles », et toute baisse
     * déclenche une alerte. C'est juste tant que la MANIÈRE DE COMPTER ne bouge pas. Le
     * 2026-08-02, l'extracteur a cessé de ramasser les <link> et <script> écrits dans des
     * chaînes JSON, ce qui était un correctif. Résultat immédiat : neuf sites ont annoncé
     * « un script de moins que la référence », alors qu'aucun n'avait changé. Ils
     * comparaient un comptage neuf à une référence ancienne.
     *
     * Le piège est vicieux parce que l'alerte est parfaitement crédible : elle nomme un
     * vrai site, un vrai écart, et un vrai risque. Rien ne dit que la règle du jeu a
     * changé entre les deux mesures.
     *
     * Une référence dont la version diffère est donc ÉCARTÉE et reconstruite, jamais
     * comparée. Perdre une référence coûte un cycle de silence ; la comparer à travers un
     * changement de contrat coûte la confiance dans toutes les alertes.
     */
    public const VERSION_EXTRACTION = 2;
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
        '/wp-content/cache/'                 => 'cache WordPress : purge en cours, ou fichier jamais régénéré',
        '/cache/autoptimize/'                => 'Autoptimize',
        '/min/'                              => 'minification à la volée',   // étiquette traduite à l'affichage
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
        // Chaque morceau de CSS garde SA base de résolution. Le style intégré se résout
        // contre la page ; une feuille externe se résout contre ELLE-MÊME.
        $cssParts   = [$inline];
        $cssSources = [[$inline, $pageUrl]];
        foreach ($responses as $key => $res) {
            $ref  = $index[$key] ?? ['url' => $res->url, 'kind' => 'css', 'media' => 'all', 'integrity' => null];
            $kind = ($ref['kind'] ?? 'css') === 'js' ? 'js' : 'css';
            $url  = $res->url ?: (string)$ref['url'];
            $host = host_of($url);
            $isSoft = self::isSoftThirdParty($host, $pageHost) && empty($ref['critical']);
            $bytes  = strlen($res->body);
            $issue  = null; $note = null; $cons = null;

            // ------------------------------------------------------------------------------
            // NOTRE PROPRE DÉBIT N'EST PAS UNE PANNE DU SITE. Défaut trouvé le 2026-08-04.
            // ------------------------------------------------------------------------------
            //
            // Laurent a reçu des centaines d'alertes « mise en page cassée » sur des sites qui
            // n'avaient rien. Trois faits l'expliquent ensemble :
            //
            //   - le collecteur surveille environ deux cents sondes, et TOUS les sites du parc
            //     tiennent sur deux serveurs ;
            //   - à chaque passe il demande la page PUIS toutes ses feuilles de style, par lots
            //     de dix en parallèle, donc plusieurs centaines de requêtes à la même machine
            //     en quelques secondes, depuis une seule adresse ;
            //   - un hébergement mutualisé répond alors 429 ou 503 à ce qui dépasse son quota.
            //
            // Le code lisait ce 429 comme « le fichier n'existe plus sur le serveur », donc
            // « mise en page cassée ». D'où le clignotement, d'où les « Downtime 0 s », et d'où
            // le fait que Laurent, en ouvrant le site seul, ne voyait jamais rien : il n'était
            // pas en train de saturer le serveur.
            //
            // Un quota atteint et un fichier absent ne se disent pas avec le même mot. 429 et
            // 503 ne concluent donc plus RIEN sur la page : ils sont notre limite, pas la
            // sienne. Le 404 reste un défaut, parce qu'un fichier qui n'existe pas n'existe pas
            // pour le visiteur non plus.
            if (in_array($res->status, [429, 503], true)) {
                $metrics['throttled'] = ($metrics['throttled'] ?? 0) + 1;
                continue;
            }

            if (!$res->ok) {
                $issue = $res->errorCode === 'TIMEOUT' ? 'TIMEOUT' : 'UNREACHABLE';
                $note  = Http::errorLabel($res->errorCode);
                $cons  = ['err', 'GET ' . $url . ' net::ERR_FAILED (' . strtolower($note) . ')'];
            } elseif ($res->status >= 400 || $res->status === 0) {
                $issue = 'HTTP_' . $res->status;
                $note  = t('HTTP {code} : le fichier n\'existe plus sur le serveur', ['code' => $res->status]);
                $cons  = ['err', 'GET ' . $url . ' net::ERR_ABORTED ' . $res->status
                        . ' (' . ($res->status === 404 ? 'Not Found' : 'Error') . ')'];
            } elseif ($bytes === 0) {
                // UN FICHIER VIDE SERVI EN 200 N'EST PAS UNE PANNE, C'EST UNE FEUILLE SANS
                // RÈGLE.
                //
                // Les greffons WordPress génèrent couramment une feuille vide quand la
                // fonction qu'elle habille n'est pas utilisée : le méga-menu d'Astra, les
                // styles conditionnels d'Elementor, les feuilles compilées à la demande.
                // Le navigateur la charge, n'en tire rien, et la page s'affiche
                // parfaitement. Mesuré le 2026-08-02 : trois des quatre derniers sites
                // « cassés » du parc l'étaient pour cette seule raison, et les trois
                // s'affichaient normalement.
                //
                // La distinction qui compte est celle du SERVEUR : un 200 avec zéro octet
                // est une réponse délibérée, là où un 404 ou une coupure de connexion
                // signalent une vraie absence, et sont traités plus haut. Le signal reste
                // donc noté dans les métriques, pour qu'une chute soudaine du poids total
                // des feuilles reste détectable par comparaison à la référence, mais il ne
                // déclenche plus rien à lui seul.
                $metrics['sheets_empty'] = ($metrics['sheets_empty'] ?? 0) + 1;
            } elseif (self::looksLikeErrorPage($res)) {
                $issue = 'NOT_' . strtoupper($kind);
                $note  = t('le serveur renvoie du HTML ou une trace PHP au lieu du {kind}',
                           ['kind' => strtoupper($kind)]);
                $cons  = $kind === 'css'
                    ? ['err', "Refused to apply style from '" . $url . "' because its MIME type ('"
                            . (str_contains((string)$res->contentType, 'html') ? 'text/html' : (string)$res->contentType)
                            . "') is not a supported stylesheet MIME type"]
                    : ['err', 'Uncaught SyntaxError: Unexpected token \'<\' (' . self::shortAsset($url) . ')'];
            } elseif (!self::mimeOk($res, $kind) && $nosniff) {
                $issue = 'MIME_BLOCKED';
                $note  = t('Content-Type « {type} » avec nosniff : ressource rejetée',
                           ['type' => str_cut((string)$res->contentType, 40)]);
                $cons  = ['err', 'Refused to ' . ($kind === 'css' ? 'apply style' : 'execute script') . " from '" . $url
                        . "' because its MIME type ('" . str_cut((string)$res->contentType, 40)
                        . "') is not " . ($kind === 'css' ? 'a supported stylesheet MIME type' : 'executable')
                        . ', and strict MIME type checking is enabled'];
            } elseif ($kind === 'css' && !self::mimeOk($res, 'css') && !$res->looksLikeCss()) {
                $issue = 'NOT_CSS';
                $note  = t('contenu non reconnu comme du CSS, Content-Type {type}',
                           ['type' => str_cut((string)$res->contentType, 40)]);
                $cons  = ['err', "Refused to apply style from '" . $url . "' because its MIME type is not supported"];
            }

            // Contenu mixte : ressource http sur une page https → bloquée.
            if (!$issue && $isHttps && str_starts_with(strtolower($url), 'http://')) {
                $issue = 'MIXED_CONTENT';
                $note  = t('servie en HTTP sur une page HTTPS : bloquée par le navigateur');
                $cons  = ['err', 'Mixed Content: The page at \'' . $pageUrl . '\' was loaded over HTTPS, but requested an '
                        . 'insecure ' . ($kind === 'css' ? 'stylesheet' : 'script') . ' \'' . $url . '\'. '
                        . 'This request has been blocked'];
            }

            // Intégrité (SRI) : un fichier régénéré sans mise à jour du hash est refusé.
            if (!$issue && !empty($ref['integrity']) && $bytes > 0) {
                if (!self::sriMatches((string)$ref['integrity'], $res->body)) {
                    $issue = 'SRI_MISMATCH';
                    $note  = t('empreinte integrity obsolète : le navigateur refuse le fichier');
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
                    $result['messages'][] = $kind === 'css'
                        ? t('Feuille de style en échec : {detail}', ['detail' => $label])
                        : t('Script essentiel en échec : {detail}', ['detail' => $label]);
                } else {
                    $soft++;
                    $result['messages'][] = $isSoft
                        ? t('Ressource tierce en échec : {detail}', ['detail' => $label])
                        : t('Script secondaire en échec : {detail}', ['detail' => $label]);
                }
            } else {
                if ($kind === 'css') { $metrics['sheets_ok']++; $cssParts[] = $res->body; $cssSources[] = [$res->body, $url]; }
                else $metrics['js_ok']++;
                $metrics['fingerprint'][self::assetKey($url)] = ['bytes' => $bytes, 'sha1' => $asset['sha1']];
            }
            if ($cons) $console[] = ['level' => $cons[0], 'text' => $cons[1]];
            $metrics['assets'][] = $asset;
        }

        // ---- 4. Métriques CSS globales ------------------------------------
        $clean = self::stripComments(implode("\n", $cssParts));
        // Le CSS concaténé sert à l'analyse de vitesse, qui cherche les polices
        // sans font-display. Il est renvoyé mais jamais enregistré : ce serait
        // des centaines de kilo-octets par sonde dans la base.
        $result['css_text']       = $clean;
        $metrics['css_bytes']     = strlen($clean);
        $metrics['rules']         = substr_count($clean, '{');
        $metrics['media_queries'] = preg_match_all('~@media[^{]{1,200}\{~i', $clean);
        $metrics['vars']          = preg_match_all('~--[a-z0-9-]+\s*:~i', $clean);
        $metrics['layout_score']  = self::layoutScore($clean);

        // ---- 5. Polices déclarées en @font-face ---------------------------
        // UNE POLICE EST MANQUANTE QUAND AUCUNE DE SES SOURCES NE RÉPOND, PAS QUAND LA
        // PREMIÈRE MANQUE. La liste de « src » d'un @font-face EST un mécanisme de repli :
        // signaler la première absence, c'est signaler que le repli fonctionne.
        // LA BASE EST CELLE DE LA FEUILLE, PAS CELLE DE LA PAGE, ET C'EST TOUT LE SUJET.
        //
        // Les « url() » d'un @font-face sont relatives au FICHIER CSS qui les déclare, la
        // règle est celle de CSS depuis toujours. En concaténant toutes les feuilles puis
        // en résolvant contre l'adresse de la page, le contrôle cherchait chaque police à
        // une adresse qui n'existe pas. Exemple mesuré sur le parc le 2026-08-02 :
        //
        //   écrit dans …/plugins/elementor/assets/lib/font-awesome/css/all.min.css :
        //       url(../webfonts/fa-brands-400.woff2)
        //   résolu contre la PAGE     -> https://site/webfonts/fa-brands-400.woff2   404
        //   résolu contre la FEUILLE  -> https://site/wp-content/…/webfonts/…woff2   200
        //
        // Toutes les polices déclarées dans une feuille rangée ailleurs qu'à la racine
        // étaient donc signalées manquantes, sur tous les sites, en permanence. C'est la
        // cause de la quasi-totalité des sites « dégradés » du parc, et le défaut était
        // d'autant plus crédible que le message citait un vrai nom de fichier.
        $groupes = [];
        foreach ($cssSources as [$corpsCss, $baseCss]) {
            foreach (self::extractFontUrls(self::stripComments($corpsCss), $baseCss) as $g) {
                $groupes[] = $g;
                if (count($groupes) >= self::MAX_FONTS) break 2;
            }
        }
        if ($groupes) {
            $aTester = [];
            foreach ($groupes as $g => $candidats) {
                foreach ($candidats as $i => $u) $aTester["f{$g}_{$i}"] = [$u, $fetchOpt + ['range' => '0-2048']];
            }
            $reponses = Http::fetchMany($aTester, 4);

            foreach ($groupes as $g => $candidats) {
                $metrics['fonts_checked']++;
                $echecs = [];
                $uneMarche = false;
                foreach ($candidats as $i => $u) {
                    $r = $reponses["f{$g}_{$i}"] ?? null;
                    if ($r && $r->ok && $r->status < 400) { $uneMarche = true; break; }
                    $echecs[] = [$u, $r ? (string)($r->status ?: Http::errorLabel($r->errorCode)) : '—'];
                }
                if ($uneMarche || !$echecs) continue;

                $metrics['fonts_failed']++;
                $soft++;
                [$u, $etat] = $echecs[0];
                $result['messages'][] = t('Police introuvable : {asset} → {status}', [
                    'asset'  => self::shortAsset($u) . (count($echecs) > 1
                        ? ' ' . t('({n} sources, aucune ne répond)', ['n' => count($echecs)])
                        : ''),
                    'status' => $etat,
                ]);
                foreach ($echecs as [$eu, $es]) {
                    $console[] = ['level' => 'err', 'text' => 'GET ' . $eu . ' net::ERR_ABORTED ' . $es];
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
                $result['messages'][] = tn($hidden['count'],
                    'Un bloc reste masqué en attente d\'animation alors que la ressource qui devait le révéler est cassée : le contenu risque de rester invisible.',
                    '{n} blocs restent masqués en attente d\'animation alors que la ressource qui devait les révéler est cassée : le contenu risque de rester invisible.');
            }
        }

        // ---- 8. CSP -------------------------------------------------------
        if ($pageRes) {
            $csp = (string)($pageRes->header('content-security-policy') ?? '');
            if ($csp !== '' && preg_match('~style-src([^;]*)~i', $csp, $m)
                && str_contains(strtolower($m[1]), "'none'")) {
                $critical++;
                $result['messages'][] = t('En-tête CSP style-src « none » : toutes les feuilles de style sont bloquées.');
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
                $result['messages'][] = t('Plus aucune feuille de style déclarée dans le HTML, la référence en comptait {n}.',
                    ['n' => (int)$baseline['sheets_declared']]);
            } elseif (!$baseline) {
                // Première observation : on le signale une fois. Si c'est l'état
                // normal de la page, la référence l'enregistrera et on se taira.
                $soft++;
                $result['messages'][] = t('Aucune feuille de style détectée sur cette page.');
                // ET IL FAUT LE DIRE AU COLLECTEUR, SANS QUOI CETTE PHRASE EST UN PIÈGE.
                //
                // Le commentaire ci-dessus décrivait une intention que le code ne tenait
                // pas. La référence n'est mémorisée que sur un état SAIN (voir Runner) ;
                // cette remarque rendant l'état « dégradé », aucune référence n'était
                // jamais enregistrée, donc « !$baseline » restait vrai à la passe suivante,
                // qui reproduisait la remarque. Un état qui se nourrit de lui-même.
                //
                // Trouvé le 2026-08-06 sur come-together.fr, une page HTML 4.0 exportée
                // d'OpenOffice, sans aucune feuille de style et parfaitement fonctionnelle :
                // elle était annoncée dégradée en permanence depuis son ajout. Le drapeau
                // autorise le collecteur à mémoriser la référence malgré la remarque, ce
                // qui fait taire les passes suivantes comme promis.
                $result['premiere_sans_feuille'] = true;
            }
        }
        // Une référence bâtie par une autre version de l'extracteur ne se compare pas :
        // voir VERSION_EXTRACTION. Elle sera reconstruite au prochain passage vert.
        if ($baseline && (int)($baseline['v'] ?? 1) !== self::VERSION_EXTRACTION) {
            $baseline = [];
            $result['baseline_perimee'] = true;
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

        // ---- 8. Silhouette : ce que le visiteur verrait --------------------
        // On la calcule à chaque audit, avec le CSS réellement chargé. Comparée
        // à celle d'un état sain, elle montre la panne au lieu de la décrire.
        if ($opt['silhouette'] ?? true) {
            $sil = Silhouette::build($html, $clean);
            $result['silhouette']     = $sil['svg'];
            $result['silhouette_sig'] = $sil['signature'];
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
    /**
     * Le HTML débarrassé de ce qui RESSEMBLE à du balisage sans en être.
     *
     * LE DÉFAUT QUE ÇA FERME, RELEVÉ SUR UN SITE RÉEL
     *
     * www.provencepromotion.com embarque, dans une chaîne JSON à l'intérieur d'un
     * <script>, un aperçu de sa propre page :
     *
     *     "paths":"<link rel='stylesheet' href='https:\/\/…\/grid.css' …>…"
     *
     * L'extracteur balayait tout le document et ramassait ces <link>-là. Pire, leurs
     * barres obliques sont échappées pour JSON : « https:\/\/ » n'est pas reconnu comme
     * une adresse absolue, donc resolve_url() le prenait pour un chemin RELATIF et
     * fabriquait « https://site/https:\/\/site\/… ». Résultat mesuré : onze feuilles
     * déclarées en échec sur une page qui n'en a aucune de cassée, et le site classé
     * hors service.
     *
     * On vide donc le CONTENU des <script> et des <template> en gardant leur balise
     * ouvrante, ce qui préserve les « src » que extractScripts() doit voir. Les
     * commentaires HTML partent aussi : un bloc commenté n'est pas chargé par le
     * navigateur, et l'auditer revient à surveiller du code mort.
     *
     * <noscript> est CONSERVÉ : son contenu est du vrai balisage, appliqué aux visiteurs
     * sans JavaScript. Le retirer masquerait une feuille réellement utilisée.
     */
    private static function sansContenusInertes(string $html): string
    {
        $html = preg_replace('~<!--.*?-->~s', ' ', $html) ?? $html;
        $html = preg_replace('~(<script\b[^>]*>).*?</script\s*>~is', '$1</script>', $html) ?? $html;
        $html = preg_replace('~(<template\b[^>]*>).*?</template\s*>~is', '$1</template>', $html) ?? $html;

        return $html;
    }

    /**
     * Une adresse écrite pour JSON redevient une adresse.
     *
     * Filet de sécurité pour les cas que sansContenusInertes() ne couvre pas, par exemple
     * un attribut « data-* » qui transporte du JSON. Sans lui, « https:\/\/exemple.fr »
     * est traité comme un chemin relatif et produit un 404 fantôme.
     */
    private static function desechapper(string $href): string
    {
        return str_contains($href, '\\/') ? str_replace('\\/', '/', $href) : $href;
    }

    public static function extractStylesheets(string $html, string $base): array
    {
        $html = self::sansContenusInertes($html);
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
                $url = resolve_url($base, self::desechapper(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
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
        // Même assainissement que pour les feuilles : un « <script src=… > » écrit dans une
        // chaîne JSON n'est pas un script chargé. C'est de là que venaient les alertes
        // « Secondary script failed: …/gtag\/js » vues sur huit sites du parc : l'antislash
        // trahissait l'origine, une adresse échappée pour JSON.
        $html = self::sansContenusInertes($html);
        $out = []; $seen = [];
        if (!preg_match_all('~<script\b[^>]*>~i', $html, $tags)) return [];
        foreach ($tags[0] as $tag) {
            $src = self::attr($tag, 'src');
            if (!$src) continue;
            $type = strtolower(self::attr($tag, 'type') ?? '');
            if ($type !== '' && !in_array($type, ['text/javascript', 'application/javascript', 'module'], true)) continue;
            $url = resolve_url($base, self::desechapper(html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
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
    /**
     * Les polices déclarées, GROUPÉES PAR @font-face et sans les formats que plus aucun
     * navigateur ne demande.
     *
     * CE QUE FAISAIT LA VERSION PRÉCÉDENTE, ET POURQUOI ELLE SE TROMPAIT PRESQUE TOUJOURS
     *
     * Elle prenait la PREMIÈRE « url() » de chaque bloc, une seule, et l'appelait « la
     * police ». Or un @font-face s'écrit par convention ainsi :
     *
     *     @font-face {
     *       font-family: 'FontAwesome';
     *       src: url('fa.eot');
     *       src: url('fa.eot?#iefix') format('embedded-opentype'),
     *            url('fa.woff2')      format('woff2'),
     *            url('fa.woff')       format('woff'),
     *            url('fa.ttf')        format('truetype');
     *     }
     *
     * La première ligne existe pour Internet Explorer, qui ne sait pas lire la liste de
     * formats. Elle est déclarée en tête EXPRÈS, et aucun navigateur moderne ne la
     * demande jamais. Le contrôle vérifiait donc, systématiquement, le seul fichier qui
     * n'a aucun effet sur aucun visiteur, et criait quand il manquait.
     *
     * Mesuré sur le parc réel le 2026-08-02 : la majorité des sites « dégradés » l'étaient
     * pour un .eot absent. Le site s'affichait parfaitement.
     *
     * DEUX RÈGLES REMPLACENT LA PREMIÈRE URL
     *
     * 1. Les formats hérités sont ÉCARTÉS du jugement (.eot pour IE, .svg pour Safari 4).
     *    Leur absence est invisible sur tout navigateur encore utilisé.
     * 2. On rend une LISTE par bloc, et l'appelant ne conclura à une police manquante que
     *    si AUCUNE source du bloc ne répond. C'est exactement ce que la liste de
     *    « src » veut dire : des solutions de repli. Alerter sur la première qui manque,
     *    c'est alerter sur le fonctionnement normal du mécanisme.
     *
     * @return list<list<string>>  une liste d'adresses par @font-face
     */
    public static function extractFontUrls(string $css, string $base): array
    {
        $groupes = [];
        if (preg_match_all('~@font-face\s*\{([^}]*)\}~i', $css, $blocks)) {
            foreach ($blocks[1] as $b) {
                if (!preg_match_all('~url\(\s*["\']?([^"\')\s]+)~i', $b, $m)) continue;
                $candidats = [];
                foreach ($m[1] as $brut) {
                    if (str_starts_with($brut, 'data:')) continue;
                    // Le format se lit sur l'extension, avant l'éventuel « ?#iefix ».
                    $ext = strtolower(pathinfo(parse_url($brut, PHP_URL_PATH) ?? $brut, PATHINFO_EXTENSION));
                    if (in_array($ext, self::FORMATS_HERITES, true)) continue;
                    $u = resolve_url($base, $brut);
                    if ($u && !in_array($u, $candidats, true)) $candidats[] = $u;
                }
                if ($candidats) $groupes[] = $candidats;
                if (count($groupes) >= self::MAX_FONTS) break;
            }
        }

        return $groupes;
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

    /**
     * La réponse est-elle une page d'erreur déguisée en ressource ?
     *
     * CE CONTRÔLE A DÉCLARÉ TREIZE SITES HORS SERVICE ALORS QU'ILS ALLAIENT BIEN.
     *
     * Il cherchait « Warning:\s » SANS DISTINCTION DE CASSE dans les 1 500 premiers
     * signes. Or toute feuille dérivée de Bootstrap ouvre par ses variables de thème :
     *
     *     :root { --primary: #007bff; --success: #28a745; --warning: #ffc107; … }
     *
     * « --warning: » contient « warning: » suivi d'une espace. Le motif tombait donc sur
     * une feuille de style parfaitement valide, servie en 200 avec le type text/css et
     * 200 ko de contenu. Comme une feuille en échec est CRITIQUE, le site passait HORS
     * SERVICE, au même rang qu'un serveur qui ne répond plus. Mesuré le 2026-08-02 sur le
     * parc réel : treize sites, presque tous sous JupiterX ou Elementor, c'est-à-dire la
     * majorité du WordPress moderne.
     *
     * Trois corrections, et la première seule n'aurait pas suffi.
     *
     * 1. LE TYPE ANNONCÉ FAIT FOI QUAND IL EST BON. Un serveur qui renvoie une page
     *    d'erreur n'annonce pas « text/css » : il annonce « text/html ». Se fier au type
     *    quand il est correct élimine d'un coup toute la classe des faux positifs par
     *    ressemblance textuelle, sans rien perdre, puisque le cas « type faux » est déjà
     *    traité par mimeOk() juste à côté.
     * 2. LES MOTIFS D'ERREUR PHP SONT ANCRÉS SUR LEUR VRAIE FORME. PHP écrit
     *    « Warning: message in /chemin/fichier.php on line 42 » ou sa variante HTML
     *    « <b>Warning</b>: ». Exiger la suite (« in … on line N », ou le gras) distingue
     *    une vraie trace d'un mot du dictionnaire. La casse redevient significative :
     *    PHP écrit « Warning », jamais « warning ».
     * 3. « Not Found</title> » ET SES SŒURS RESTENT, mais seulement dans du HTML, sinon
     *    un commentaire CSS citant un titre de page suffirait à déclencher l'alarme.
     */
    private static function looksLikeErrorPage(Response $res): bool
    {
        $head = ltrim(substr($res->body, 0, 1500));
        if ($head === '') return false;

        // Ouverture HTML ou PHP : sans appel, c'est une page et pas une ressource.
        if (preg_match('~^(<!doctype|<html|<\?xml|<\?php)~i', $head)) return true;

        // Le type annoncé est cohérent avec du CSS ou du JavaScript : on le croit. Le
        // désaccord entre type annoncé et contenu est le travail de mimeOk(), pas d'ici.
        $ct = strtolower((string)$res->contentType);
        if ($ct !== '' && (str_contains($ct, 'css') || str_contains($ct, 'javascript') || str_contains($ct, 'ecmascript'))) {
            return false;
        }

        // Une trace PHP réelle, avec sa queue. « Warning: » tout seul est un mot.
        if (preg_match('~(Fatal error|Parse error|Warning|Notice|Deprecated)\s*:\s.{0,300}? in .{1,200}? on line \d+~s', $head)) return true;
        if (preg_match('~<b>\s*(Fatal error|Parse error|Warning|Notice|Deprecated)\s*</b>\s*:~i', $head)) return true;
        if (preg_match('~^(Fatal error|Parse error):\s~m', $head)) return true;

        // Titres de pages d'erreur : seulement s'il y a bien du HTML autour.
        return (bool)preg_match('~<title>[^<]*(Not Found|Forbidden|Internal Server Error)~i', $head);
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
                $crit[] = t('Poids CSS en chute de {pct} % ({now} au lieu de {ref} attendus).', [
                    'pct' => Ui::num($d, 0), 'now' => human_bytes($m['css_bytes']),
                    'ref' => human_bytes((int)$b['css_bytes'])]);
            } elseif ($d >= max(12, $dropPct / 2)) {
                $warn[] = t('Poids CSS en baisse de {pct} % ({now} / {ref}).', [
                    'pct' => Ui::num($d, 0), 'now' => human_bytes($m['css_bytes']),
                    'ref' => human_bytes((int)$b['css_bytes'])]);
            }
        }

        $dr = $drop((float)$m['rules'], (float)($b['rules'] ?? 0));
        if ($dr !== null && $dr >= $dropPct) {
            $crit[] = t('Nombre de règles CSS divisé : {now} au lieu de {ref}.',
                        ['now' => (int)$m['rules'], 'ref' => (int)$b['rules']]);
        }

        $refCount = (int)($b['sheets_ok'] ?? 0);
        if ($refCount > 0 && $m['sheets_ok'] < $refCount) {
            $msg = tn($refCount - $m['sheets_ok'],
                'Une feuille de style en moins qu\'en référence : {ok} sur {ref} chargées.',
                '{n} feuilles de style en moins qu\'en référence : {ok} sur {ref} chargées.',
                ['ok' => (int)$m['sheets_ok'], 'ref' => $refCount]);
            if (($d ?? 0) >= 10) $crit[] = $msg; else $warn[] = $msg;
        }

        $refJs = (int)($b['js_ok'] ?? 0);
        if ($refJs > 0 && ($m['js_ok'] ?? 0) < $refJs) {
            $warn[] = tn($refJs - (int)$m['js_ok'],
                'Un script en moins qu\'en référence : {ok} sur {ref} chargés.',
                '{n} scripts en moins qu\'en référence : {ok} sur {ref} chargés.',
                ['ok' => (int)$m['js_ok'], 'ref' => $refJs]);
        }

        if (($b['media_queries'] ?? 0) >= 3 && $m['media_queries'] === 0) {
            $crit[] = t('Plus aucune media query : la mise en page responsive est perdue.');
        }
        if (($b['layout_score'] ?? 0) >= 40 && $m['layout_score'] < ((int)$b['layout_score']) * 0.5) {
            $crit[] = t('Capacité de mise en page effondrée : score {now} au lieu de {ref}.',
                        ['now' => (int)$m['layout_score'], 'ref' => (int)$b['layout_score']]);
        }

        $covNow = $m['coverage']; $covRef = $b['coverage'] ?? null;
        if ($covNow !== null) {
            if ($covRef !== null && $covRef >= 0.5) {
                $gap = round(($covRef - $covNow) * 100, 1);
                if ($gap >= 25 || ($covNow < 0.45 && $gap >= 15)) {
                    $crit[] = t('Les classes de la page ne sont plus couvertes par le CSS : {now} % contre {ref} % en référence.{examples}',
                        ['now' => round($covNow * 100), 'ref' => round($covRef * 100),
                         'examples' => !empty($m['classes_missing'])
                            ? ' ' . t('Par exemple : {list}.',
                                      ['list' => implode(', ', array_slice($m['classes_missing'], 0, 4))])
                            : '']);
                } elseif ($gap >= 12) {
                    $warn[] = t('Couverture CSS en baisse : {now} % contre {ref} %.',
                                ['now' => round($covNow * 100), 'ref' => round($covRef * 100)]);
                }
            } elseif ($covRef === null && $covNow < 0.25 && $m['classes_tested'] >= 10) {
                $warn[] = t('Seulement {pct} % des classes de la page trouvent une règle CSS.',
                            ['pct' => round($covNow * 100)]);
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
            'v' => self::VERSION_EXTRACTION,
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
        // L'étiquette est un msgid : elle s'affiche entre crochets dans le
        // message d'échec, donc dans la langue de qui lit.
        foreach (self::CACHE_HINTS as $needle => $label) if (str_contains($l, $needle)) return t($label);
        return null;
    }
}
