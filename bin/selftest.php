<?php
/**
 * Uptimer : autotest. Vérifie la logique de détection sans toucher au réseau
 * ni à la base : utile après une mise à jour ou un changement d'hébergement.
 *
 *   php bin/selftest.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Uptimer\Check\Css;
use Uptimer\Check\Database;
use Uptimer\Detect\Cms;
use Uptimer\Detect\Discovery;
use Uptimer\Response;
use Uptimer\Runner;
use Uptimer\Ui;

$pass = 0; $fail = 0;
function check(string $label, mixed $got, mixed $want): void
{
    global $pass, $fail;
    $ok = $got === $want;
    $ok ? $pass++ : $fail++;
    // Alignement calculé en caractères (et non en octets) : le libellé est accentué.
    $pad = str_repeat(' ', max(1, 56 - mb_strlen($label)));
    echo ($ok ? ' OK  ' : 'FAIL ') . $label . $pad
       . ($ok ? '' : '→ obtenu ' . var_export($got, true) . ', attendu ' . var_export($want, true)) . "\n";
}
function section(string $s): void { echo "\n=== $s ===\n"; }

// =========================================================================
section('Normalisation des URL');
check('domaine nu',            normalize_url('exemple.fr'), 'https://exemple.fr/');
check('avec chemin',           normalize_url('exemple.fr/contact'), 'https://exemple.fr/contact');
check('http conservé',         normalize_url('http://exemple.fr/'), 'http://exemple.fr/');
check('espaces et majuscules', normalize_url('  EXEMPLE.fr '), 'https://exemple.fr/');
check('entrée invalide',       normalize_url('pas une url'), null);
check('sans point',            normalize_url('localhost'), null);
check('domaine enregistrable', registrable_domain('www.blog.exemple.fr'), 'exemple.fr');
check('ccTLD deux niveaux',    registrable_domain('shop.exemple.co.uk'), 'exemple.co.uk');
check('adresse IP',            registrable_domain('192.168.1.10'), '192.168.1.10');

section('Résolution des URL relatives');
check('racine',      resolve_url('https://a.fr/x/y.html', '/style.css'), 'https://a.fr/style.css');
check('relative',    resolve_url('https://a.fr/x/y.html', 'css/a.css'), 'https://a.fr/x/css/a.css');
check('remontée',    resolve_url('https://a.fr/x/y/z.html', '../a.css'), 'https://a.fr/x/a.css');
check('protocole implicite', resolve_url('https://a.fr/', '//cdn.fr/a.css'), 'https://cdn.fr/a.css');
check('absolue',     resolve_url('https://a.fr/', 'https://b.fr/a.css'), 'https://b.fr/a.css');
check('data ignoré', resolve_url('https://a.fr/', 'data:text/css,a{}'), null);

section('Codes HTTP attendus');
check('200 dans 200-299', Runner::statusMatches(200, '200-299'), true);
check('301 hors 200-299', Runner::statusMatches(301, '200-299'), false);
check('motif 2xx',        Runner::statusMatches(204, '2xx'), true);
check('liste',            Runner::statusMatches(301, '200,301,302'), true);
check('valeur unique',    Runner::statusMatches(404, '404'), true);
check('spécification vide', Runner::statusMatches(302, ''), true);

section('Chaîne de contrôle');
check('présente',            Runner::containsAny('Bonjour le Monde', 'monde'), true);
check('absente',             Runner::containsAny('Bonjour', 'monde'), false);
check('variantes avec |',    Runner::containsAny('Panier vide', 'Boutique|Panier'), true);
check('apostrophe typographique', Runner::containsAny('L’atelier du Web', "L'atelier"), true);
check('entité HTML',         Runner::containsAny('Caf&eacute; Central', 'Café Central'), true);

section('Fenêtres de maintenance');
check('plage vide', Runner::inMaintenance(''), false);
check('format invalide', Runner::inMaintenance('n’importe quoi'), false);
$h = (int)date('H');
$now = sprintf('%02d:00-%02d:59', $h, $h);
check('heure courante incluse', Runner::inMaintenance($now), true);
$other = sprintf('%02d:00-%02d:10', ($h + 3) % 24, ($h + 3) % 24);
check('autre heure exclue', Runner::inMaintenance($other), false);

section('Chemins JSON');
$json = ['data' => ['status' => 'ok', 'items' => [['name' => 'a'], ['name' => 'b']]]];
check('champ imbriqué', Runner::jsonPath($json, 'data.status'), 'ok');
check('index de liste', Runner::jsonPath($json, 'data.items.1.name'), 'b');
check('champ absent',   Runner::jsonPath($json, 'data.absent'), null);

section('Extraction des feuilles de style');
$html = '<!doctype html><html><head>
<link rel="stylesheet" href="/a.css?ver=1">
<link href="/b.css" rel="stylesheet" media="all">
<link rel="stylesheet" href="/print.css" media="print">
<link rel="preload" as="style" href="/c.css">
<link rel="icon" href="/favicon.ico">
<style>.inline{color:red}</style>
</head><body class="page home"><div class="card card-lg">x</div></body></html>';
$sheets = Css::extractStylesheets($html, 'https://a.fr/page');
check('nombre de feuilles retenues', count($sheets), 3);
check('feuille print exclue', in_array('https://a.fr/print.css', array_column($sheets, 'url'), true), false);
check('preload as=style inclus', in_array('https://a.fr/c.css', array_column($sheets, 'url'), true), true);
check('cache-buster conservé dans l\'URL', $sheets[0]['url'], 'https://a.fr/a.css?ver=1');
check('style intégré lu', trim(Css::extractInlineCss($html)), '.inline{color:red}');
$classes = Css::extractUsedClasses($html);
check('classes du corps uniquement', array_keys($classes) === ['card', 'card-lg'], true);

section('Empreinte d\'asset (insensible au hash)');
check('hash neutralisé',
    Css::assetKey('https://a.fr/wp-content/cache/min/1/a1b2c3d4e5f6.css'),
    Css::assetKey('https://a.fr/wp-content/cache/min/1/99887766aabb.css'));
check('chemins différents distingués',
    Css::assetKey('https://a.fr/a.css') === Css::assetKey('https://a.fr/b.css'), false);

section('Couverture des classes par le CSS');
$css = '.card{display:flex}.card-lg{padding:2rem}.hero{color:#000}';
$cov = Css::coverage($css, ['card' => 3, 'card-lg' => 1]);
check('couverture totale', $cov['ratio'], 1.0);
$cov2 = Css::coverage($css, ['card' => 1, 'absente' => 1]);
check('couverture partielle', $cov2['ratio'], 0.5);
check('classe manquante listée', $cov2['missing'], ['absente']);
// Tailwind : le CSS échappe les deux-points
$covTw = Css::coverage('.md\:flex{display:flex}.w-1\/2{width:50%}', ['md:flex' => 1, 'w-1/2' => 1]);
check('classes échappées reconnues (Tailwind)', $covTw['ratio'], 1.0);
// Un préfixe ne doit pas compter comme une correspondance
$covPre = Css::coverage('.btn-primary{color:red}', ['btn' => 1]);
check('préfixe non compté', $covPre['ratio'], 0.0);

section('Signatures base de données');
foreach ([
    'Error establishing a database connection' => 'DB_DOWN',
    'MySQL: Too many connections'              => 'DB_DOWN',
    'SQLSTATE[HY000] [2002]'                   => 'DB_DOWN',
    'WordPress database error Table doesn\'t exist' => 'DB_DOWN',
    'Fatal error: Uncaught TypeError in /var/www/x.php' => 'APP_ERROR',
    'Bienvenue sur notre site, tout va bien'   => null,
] as $body => $want) {
    $res = new Response();
    $res->body = $body;
    $out = Database::audit($res, []);
    check('« ' . str_cut($body, 42) . ' »', $out['reason'], $want);
}

section('Détection du CMS');
$wp = new Response();
$wp->body = '<html><head><meta name="generator" content="WordPress 6.7"><link href="/wp-content/themes/astra/style.css">
</head><body class="elementor-page"><div class="elementor-widget">x</div><script src="/wp-includes/js/x.js"></script></body></html>';
$wp->headers = ['server' => 'LiteSpeed', 'x-litespeed-cache' => 'hit'];
$d = Cms::detect($wp);
check('WordPress identifié', $d['cms'], 'WordPress');
check('constructeur Elementor', $d['builder'], 'Elementor');
check('thème détecté', $d['theme'], 'astra');
check('cache détecté', $d['cache'], 'LiteSpeed Cache');
check('WordPress utilise une base', Cms::usesDatabase('WordPress'), true);
check('site statique sans base', Cms::usesDatabase('Astro'), false);

$sh = new Response();
$sh->body = '<html><body><script src="https://cdn.shopify.com/s/x.js"></script></body></html>';
check('Shopify identifié', Cms::detect($sh)['cms'], 'Shopify');

section('Déduction de la chaîne de contrôle');
$page = '<html><head><title>Nos tarifs : Agence Bellevue</title>
<meta property="og:site_name" content="Agence Bellevue"></head>
<body><nav><a href="/x">Nos prestations</a></nav><h1>Nos tarifs</h1>
<footer>© 2026 Agence Bellevue — tous droits réservés</footer></body></html>';
check('nom de marque retenu', Discovery::suggestExpectString($page), 'Agence Bellevue');
$err = '<html><head><title>404 Not Found</title></head><body><h1>Not Found</h1></body></html>';
check('rien déduit d\'une page 404', Discovery::suggestExpectString($err, 404), null);
check('rien déduit d\'un titre d\'erreur', Discovery::suggestExpectString($err, 200), null);

section('Détection du noindex');
$ni = new Response();
$ni->body = '<html><head><meta name="robots" content="noindex, follow"></head><body>x</body></html>';
check('meta robots', Discovery::noindex($ni) !== null, true);
$ni2 = new Response();
$ni2->body = '<html><head></head><body>x</body></html>';
$ni2->headers = ['x-robots-tag' => 'noindex'];
check('en-tête X-Robots-Tag', Discovery::noindex($ni2) !== null, true);
$ok = new Response();
$ok->body = '<html><head><meta name="robots" content="index, follow"></head></html>';
check('page indexable', Discovery::noindex($ok), null);

section('Empreinte de contenu');
$a = Runner::contentHash('<html><body><p>Bonjour</p><script>var t=1</script></body></html>');
$b = Runner::contentHash('<html><body><p>Bonjour</p><script>var t=2</script></body></html>');
check('les scripts n\'influent pas', $a, $b);
$c = Runner::contentHash('<html><body><p>Bonsoir</p></body></html>');
check('le texte influe', $a === $c, false);

section('Analyse d\'une liste d\'import');
$parsed = Uptimer\Importer::parse("exemple.fr\n# commentaire\nautre.fr | Client | Preuve\nvide\n\nexemple.fr");
check('lignes valides', count($parsed['rows']), 2);
check('doublon ignoré', $parsed['rows'][0]['url'], 'https://exemple.fr/');
check('colonnes lues', [$parsed['rows'][1]['name'], $parsed['rows'][1]['expect']], ['Client', 'Preuve']);
check('ligne invalide signalée', count($parsed['errors']), 1);

section('Repli pour la recherche, toutes langues');
// Propriété utile : une saisie sans diacritiques doit retrouver le texte accentué,
// quelle que soit la langue. C'est ce qu'on teste, pas la graphie produite.
$trouve = fn(string $texte, string $saisie): bool => str_contains(fold($texte), fold($saisie));
check('français : casse → cassé',        $trouve('Site cassé', 'casse'), true);
check('allemand : munchen → München',    $trouve('Agentur München', 'munchen'), true);
check('allemand : strasse → Straße',     $trouve('Hauptstraße', 'strasse'), true);
check('portugais : joao → João',         $trouve('João Pessoa', 'joao'), true);
check('espagnol : nino → niño',          $trouve('El niño', 'nino'), true);
check('polonais : lodz → Łódź',          $trouve('Łódź centrum', 'lodz'), true);
check('tchèque : cestina → čeština',     $trouve('Česká čeština', 'cestina'), true);
check('turc : istanbul → İstanbul',      $trouve('İstanbul ofis', 'istanbul'), true);
check('norvégien : tromso → Tromsø',     $trouve('Tromsø AS', 'tromso'), true);
check('vietnamien : viet → Việt',        $trouve('Tiếng Việt', 'viet'), true);
check('ligature : coeur → Cœur',         $trouve('Cœur du Web', 'coeur'), true);
check('casse seule pour le cyrillique',  $trouve('Сайт Москва', 'москва'), true);
check('casse seule pour le grec',        $trouve('Ελλάδα ΑΕ', 'ελλάδα'), true);
check('arabe traversé sans altération',  $trouve('موقع الشركة', 'موقع'), true);
check('japonais traversé sans altération', $trouve('日本語サイト', 'サイト'), true);
check('mot absent reste absent',         $trouve('Site cassé', 'boulangerie'), false);
check('texte simple inchangé',           fold('boutique.fr'), 'boutique.fr');
check('majuscules réduites',             fold('BOUTIQUE Dupont'), 'boutique dupont');

section('Mise en forme');
check('durée en heures', human_duration(4210), '1 h 10 min');
check('durée en jours',  human_duration(90000), '1 j 1 h');
check('octets', human_bytes(2048), '2 Ko');


section('Extraction des scripts');
$htmlJs = '<html><head>
<script src="/wp-includes/js/jquery/jquery.min.js"></script>
<script src="https://cdn.exemple.fr/tracker.js" async></script>
<script type="application/ld+json">{"@type":"Organization"}</script>
<script type="module" src="/build/assets/app-4f2a1b.js" integrity="sha384-abc"></script>
<script>var inline = 1;</script>
</head><body></body></html>';
$js = Css::extractScripts($htmlJs, 'https://exemple.fr/page');
check('scripts avec src uniquement', count($js), 3);
check('JSON-LD ignoré', in_array('application/ld+json', array_column($js, 'type') ?: [], true), false);
check('jQuery reconnu comme essentiel', $js[0]['critical'], true);
check('traceur tiers non essentiel', $js[1]['critical'], false);
check('build applicatif essentiel', $js[2]['critical'], true);
check('attribut integrity lu', $js[2]['integrity'], 'sha384-abc');
check('async détecté', $js[1]['defer'], true);
check('script essentiel : thème', Css::isCriticalJs('https://a.fr/wp-content/themes/astra/js/x.js'), true);
check('script accessoire', Css::isCriticalJs('https://a.fr/js/newsletter-popup.js'), false);

section('Intégrité SRI');
$body = 'body{color:red}';
$good = 'sha384-' . base64_encode(hash('sha384', $body, true));
$bad  = 'sha384-' . base64_encode(hash('sha384', 'autre chose', true));
check('empreinte correcte acceptée', Css::sriMatches($good, $body), true);
check('empreinte obsolète refusée', Css::sriMatches($bad, $body), false);
check('sha256 accepté', Css::sriMatches('sha256-' . base64_encode(hash('sha256', $body, true)), $body), true);
check('base64url toléré', Css::sriMatches('sha384-' . rtrim(strtr(base64_encode(hash('sha384', $body, true)), '+/', '-_'), '='), $body), true);
check('plusieurs empreintes : une bonne suffit', Css::sriMatches($bad . ' ' . $good, $body), true);
check('attribut illisible : pas de faux positif', Css::sriMatches('md5-zzz', $body), true);
check('algorithme lu', Css::sriAlgo('sha512-xxx'), '512');

section('Polices déclarées en @font-face');
$fontCss = '@font-face{font-family:Inter;src:url("/fonts/inter.woff2") format("woff2")}'
         . '@font-face{font-family:Alt;src:url(data:font/woff2;base64,AAA) format("woff2")}'
         . '@font-face{font-family:Deux;src:url(/fonts/deux.woff2)}';
$fonts = Css::extractFontUrls($fontCss, 'https://exemple.fr/style.css');
check('polices distantes extraites', count($fonts), 2);
check('police en data: ignorée', implode(',', $fonts), 'https://exemple.fr/fonts/inter.woff2,https://exemple.fr/fonts/deux.woff2');

section('Périodes d\'affichage');
check('365 jours reconnu', Ui::rangeSeconds('365d'), 31536000);
check('180 jours reconnu', Ui::rangeSeconds('180d'), 15552000);
check('120 jours reconnu', Ui::rangeSeconds('120d'), 10368000);
check('valeur inconnue → 24 h', Ui::rangeSeconds('nawak'), 86400);
check('libellé lisible', Ui::rangeLabel('180d'), '6 mois');
check('une année reste sous 100 points', Ui::rangeBuckets('365d') <= 100, true);
check('les longues portées passent par les agrégats',
    Ui::rangeSeconds('180d') > Uptimer\Stats::RAW_WINDOW_SEC, true);
check('24 h reste sur les mesures unitaires',
    Ui::rangeSeconds('24h') <= Uptimer\Stats::RAW_WINDOW_SEC, true);

section('Diagnostic : cause, explication, remède');
foreach (['DNS', 'CONNECT', 'TIMEOUT', 'SSL_EXPIRED', 'SSL_INVALID', 'HTTP_5XX', 'HTTP_404',
          'HTTP_403', 'HTTP_401', 'DB_DOWN', 'APP_ERROR', 'CSS_BROKEN', 'STRING_MISSING',
          'NOINDEX', 'SLOW', 'JSON_VALUE', 'REDIRECT_LOOP'] as $code) {
    $d = Uptimer\Diagnose::explain($code, ['url' => 'https://exemple.fr/']);
    $ok = $d['title'] !== '' && mb_strlen($d['why']) > 30 && mb_strlen($d['fix']) > 30
        && $d['title'] !== 'Anomalie détectée';
    check('explication utile pour ' . $code, $ok, true);
}
$unknown = Uptimer\Diagnose::explain('CODE_INCONNU');
check('code inconnu : repli neutre', $unknown['title'], 'Anomalie détectée');

section('Icônes et rendu');
check('icône connue', str_contains(Ui::icon('alert'), '<svg'), true);
check('icône inconnue : repli', str_contains(Ui::icon('nexistepas'), '<svg'), true);
check('aucun emoji dans le jeu d\'icônes',
    (bool)preg_match('~[\x{1F300}-\x{1FAFF}]~u', Ui::icon('alert') . Ui::icon('check')), false);
check('accordéon bien formé',
    str_contains(Ui::accOpen('x', 'info', 'Titre'), '<details') && str_contains(Ui::accClose(), '</details>'), true);
check('pastille accessible', str_contains(Ui::dot('down'), 'aria-label'), true);
check('série vide : message explicite', str_contains(Ui::sparkline([]), 'Pas encore de mesure'), true);
check('graphique vide : message explicite', str_contains(Ui::chart(['buckets' => []]), 'Aucune donnée'), true);
check('libellé d\'état traduit', Ui::statusLabel('degraded'), 'À surveiller');
check('uptime 99,95 % → vert', Ui::uptimeTone(99.95), 'ok');
check('uptime 99,5 % → orange', Ui::uptimeTone(99.5), 'warn');
check('uptime 98 % → rouge', Ui::uptimeTone(98.0), 'bad');
check('millisecondes lisibles', Ui::ms(1500), '1,50 s');
check('millisecondes courtes', Ui::ms(420), '420 ms');

section('Messages console reconstitués');
$page = '<html><head><link rel="stylesheet" href="/absent.css"></head><body class="page"><div class="hero">x</div></body></html>';
$res = new Response();
$res->status = 200; $res->contentType = 'text/html'; $res->body = $page;
$audit = Css::audit('https://exemple-inexistant-uptimer.fr/', $page, $res, [], ['timeout' => 2, 'check_js' => false]);
check('audit conclut à une anomalie', in_array($audit['state'], ['broken', 'warn'], true), true);
check('messages console produits', count($audit['console']) >= 1, true);
check('format reconnaissable par un développeur',
    (bool)preg_match('~(net::ERR|Refused to|Mixed Content|GET )~', $audit['console'][0]['text'] ?? ''), true);


section('Montée de version du schéma');
// Une base créée par une version antérieure doit gagner les colonnes manquantes.
$tmpDb = sys_get_temp_dir() . '/uptimer-selftest-' . bin2hex(random_bytes(3)) . '.sqlite';
Uptimer\Config::set('db.driver', 'sqlite');
Uptimer\Config::set('db.sqlite', $tmpDb);
Uptimer\Db::pdo()->exec("CREATE TABLE monitors (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL,
    url TEXT NOT NULL, kind TEXT, status TEXT, created_at TEXT)");
Uptimer\Db::pdo()->exec("CREATE TABLE settings (k TEXT PRIMARY KEY, v TEXT)");
$avant = count(Uptimer\Db::columns('monitors'));
Uptimer\Db::migrate();
$cols = Uptimer\Db::columns('monitors');
check('colonnes ajoutées automatiquement (' . count($cols) . ')', count($cols) > $avant + 40, true);
foreach (['last_ip', 'css_detail', 'css_baseline', 'uptime_24h', 'setup_state', 'domain_expires_at'] as $c) {
    check('colonne ' . $c . ' présente', in_array($c, $cols, true), true);
}
check('données existantes préservées', Uptimer\Db::tableExists('monitors'), true);
Uptimer\Db::insert('monitors', ['name' => 'x', 'url' => 'https://x.fr/', 'kind' => 'page',
                              'status' => 'unknown', 'created_at' => now()]);
Uptimer\Db::update('monitors', ['last_ip' => '203.0.113.9'], 'id = :i', ['i' => 1]);
check('écriture possible sur les nouvelles colonnes',
    Uptimer\Db::val('SELECT last_ip FROM monitors WHERE id = 1'), '203.0.113.9');
check('deuxième migration sans effet de bord', (function () { Uptimer\Db::migrate(); return true; })(), true);
@unlink($tmpDb); @unlink($tmpDb . '-wal'); @unlink($tmpDb . '-shm');

section('Corrélation des pannes');
check('seuil de regroupement raisonnable', Uptimer\Runner::GROUP_THRESHOLD >= 3, true);
check('libellé d\'évènement groupé',
    Uptimer\Notify\Notifier::eventLabel('grouped_alert'), 'Panne groupée');


section('Extraction depuis un texte libre');
$mail = "Bonjour, merci de surveiller boutique-dupont.fr et https://cabinet-lefevre.fr/contact.\n"
      . "Le préprod est sur preprod.dupont.fr. Contact : jean@exemple.com. Logo : logo-final.png.";
$prose = Uptimer\Importer::extractFromProse($mail);
check('adresses trouvées dans une prose', count($prose), 3);
check('domaine en fin de phrase accepté',
    (bool)preg_grep('~preprod\.dupont\.fr~', $prose), true);
check('domaine d\'une adresse e-mail écarté', (bool)preg_grep('~exemple\.com~', $prose), false);
check('nom de fichier écarté', (bool)preg_grep('~logo-final~', $prose), false);
check('un seul élément par hôte',
    count($prose) === count(array_unique(array_map(fn($u) => host_of(normalize_url($u) ?? ''), $prose))), true);
$pl = Uptimer\Importer::parse($mail);
check('la prose alimente l\'import', count($pl['rows']), 3);
$list = Uptimer\Importer::parse("a-uptimer.fr\nb-uptimer.fr | B\n# note\npas une url");
check('une vraie liste reste traitée comme telle', count($list['rows']), 2);

section('Cadence choisie selon l\'importance de la page');
check('accueil : cadence de référence', Uptimer\Tune::intervalFor('https://a.fr/', 300), 300);
check('page principale : cadence de référence', Uptimer\Tune::intervalFor('https://a.fr/x', 300, null, true), 300);
check('contact : cadence de référence', Uptimer\Tune::intervalFor('https://a.fr/contact', 300, 'contact'), 300);
check('article de blog : deux fois moins souvent',
    Uptimer\Tune::intervalFor('https://a.fr/blog/mon-article', 300, 'contenu'), 600);
check('mentions légales : quatre fois moins souvent',
    Uptimer\Tune::intervalFor('https://a.fr/mentions-legales', 300, 'legal'), 1200);
check('plafonné à une journée', Uptimer\Tune::intervalFor('https://a.fr/cgv', 43200, 'legal'), 86400);
check('seuils de réglage automatique cohérents',
    Uptimer\Tune::SLOW_FLOOR_MS < Uptimer\Tune::SLOW_CEIL_MS && Uptimer\Tune::SLOW_FACTOR > 1, true);

section('Seuil de lenteur auto-ajusté');
$tmpT = sys_get_temp_dir() . '/uptimer-tune-' . bin2hex(random_bytes(3)) . '.sqlite';
Uptimer\Config::set('db.sqlite', $tmpT);
Uptimer\Db::migrate();
$tid = Uptimer\Db::insert('monitors', ['name' => 'lent', 'url' => 'https://a.fr/', 'kind' => 'page',
    'role' => 'primary', 'method' => 'GET', 'interval_sec' => 300, 'timeout_sec' => 15, 'retries' => 0,
    'slow_ms' => 3000, 'expect_status' => '200-299', 'check_ssl' => 0, 'check_css' => 0, 'check_db' => 0,
    'check_noindex' => 0, 'ssl_warn_days' => 14, 'css_drop_pct' => 35, 'enabled' => 1, 'auto_slow' => 1,
    'status' => 'up', 'setup_state' => 'done', 'created_at' => now(), 'next_check_at' => now(),
    'follow_redirects' => 1]);
// Un site lent par nature (4,4 s de façon stable) avec un seuil à 3 s : il
// alerterait en permanence pour rien. Le seuil doit s'adapter à sa réalité.
for ($i = 0; $i < 40; $i++) {
    Uptimer\Db::insert('checks', ['monitor_id' => $tid, 'ts' => date('Y-m-d H:i:s', time() - $i * 600),
        'state' => 'up', 'status_code' => 200, 'total_ms' => 4400 + ($i % 7) * 30, 'attempts' => 1]);
}
$mon = Uptimer\Db::one('SELECT * FROM monitors WHERE id = ?', [$tid]);
$res = Uptimer\Tune::slowThreshold($mon);
check('seuil recalculé', is_array($res) && $res['changed'], true);
$newSlow = (int)Uptimer\Db::val('SELECT slow_ms FROM monitors WHERE id = ?', [$tid]);
check('seuil placé au-dessus du comportement réel (' . $newSlow . ' ms)',
    $newSlow >= 7500 && $newSlow <= 8800, true);
check('un écart insignifiant ne déclenche aucun changement', (function () {
    // Même exercice avec un seuil déjà correct : Uptimer doit rester silencieuse.
    $id = Uptimer\Db::insert('monitors', ['name' => 'stable', 'url' => 'https://b.fr/', 'kind' => 'page',
        'role' => 'primary', 'method' => 'GET', 'interval_sec' => 300, 'timeout_sec' => 15, 'retries' => 0,
        'slow_ms' => 2800, 'expect_status' => '200-299', 'check_ssl' => 0, 'check_css' => 0, 'check_db' => 0,
        'check_noindex' => 0, 'ssl_warn_days' => 14, 'css_drop_pct' => 35, 'enabled' => 1, 'auto_slow' => 1,
        'status' => 'up', 'setup_state' => 'done', 'created_at' => now(), 'next_check_at' => now(),
        'follow_redirects' => 1]);
    for ($i = 0; $i < 30; $i++) {
        Uptimer\Db::insert('checks', ['monitor_id' => $id, 'ts' => date('Y-m-d H:i:s', time() - $i * 600),
            'state' => 'up', 'status_code' => 200, 'total_ms' => 1500 + ($i % 5) * 10, 'attempts' => 1]);
    }
    return Uptimer\Tune::slowThreshold(Uptimer\Db::one('SELECT * FROM monitors WHERE id = ?', [$id])) === null;
})(), true);
check('décision journalisée',
    count(Uptimer\Tune::decisions(Uptimer\Db::one('SELECT * FROM monitors WHERE id = ?', [$tid]))) >= 1, true);
$mon2 = Uptimer\Db::one('SELECT * FROM monitors WHERE id = ?', [$tid]);
check('pas de réajustement dans la foulée', Uptimer\Tune::slowThreshold($mon2), null);
// Réglage manuel : la case décochée doit être respectée
Uptimer\Db::update('monitors', ['auto_slow' => 0, 'tuned_at' => null], 'id = :i', ['i' => $tid]);
check('réglage manuel respecté',
    Uptimer\Tune::slowThreshold(Uptimer\Db::one('SELECT * FROM monitors WHERE id = ?', [$tid])), null);

section('Sonde battement');
$hb = Uptimer\Heartbeat::create('Sauvegarde nocturne', 3600, 300);
check('clé de battement générée', strlen((string)$hb['token']) >= 16, true);
$hbMon = Uptimer\Db::one('SELECT * FROM monitors WHERE id = ?', [$hb['id']]);
check('type battement', (string)$hbMon['kind'], 'heartbeat');
check('ligne à coller fournie', str_contains(Uptimer\Heartbeat::snippet($hbMon), 'beat.php?k='), true);
check('aucun retard à la création', Uptimer\Heartbeat::sweep(), 0);
check('signal accepté', Uptimer\Heartbeat::beat((string)$hb['token'], '412 fichiers')['ok'], true);
check('état passé à opérationnel', Uptimer\Db::val('SELECT status FROM monitors WHERE id = ?', [$hb['id']]), 'up');
Uptimer\Db::q('UPDATE monitors SET heartbeat_at = ? WHERE id = ?',
    [date('Y-m-d H:i:s', time() - 7200), $hb['id']]);
check('silence prolongé détecté', Uptimer\Heartbeat::sweep(), 1);
check('cause renseignée', Uptimer\Db::val('SELECT reason_code FROM monitors WHERE id = ?', [$hb['id']]), 'HEARTBEAT_LATE');
check('incident ouvert', (int)Uptimer\Db::val('SELECT COUNT(*) FROM incidents WHERE monitor_id = ? AND ended_at IS NULL',
    [$hb['id']]), 1);
Uptimer\Heartbeat::beat((string)$hb['token']);
check('retour du signal : incident clos',
    (int)Uptimer\Db::val('SELECT COUNT(*) FROM incidents WHERE monitor_id = ? AND ended_at IS NULL', [$hb['id']]), 0);
check('clé inconnue refusée', Uptimer\Heartbeat::beat('0123456789abcdef0123')['ok'], false);
check('clé trop courte refusée', Uptimer\Heartbeat::beat('abc')['ok'], false);
check('diagnostic dédié au battement',
    Uptimer\Diagnose::explain('HEARTBEAT_LATE')['title'], 'Le signal attendu n\'est pas arrivé');

section('Triage : ce qu\'il y a à faire');
Uptimer\Db::q("UPDATE monitors SET status = 'down', reason_code = 'CSS_BROKEN',
             last_message = 'feuille absente', status_since = ? WHERE id = ?", [now(), $tid]);
$acts = Uptimer\Triage::actions();
check('la sonde en panne remonte dans les tâches', count($acts) >= 1, true);
$first = $acts[0];
check('la tâche porte une cause lisible', $first['cause'] !== '' && $first['cause'] !== 'Anomalie détectée', true);
check('la tâche porte une conduite à tenir', mb_strlen((string)$first['why']) > 30 && mb_strlen((string)$first['fix']) > 30, true);
check('des actions sont proposées', count($first['actions']) >= 3, true);
$labels = array_column($first['actions'], 0);
check('réapprentissage proposé sur une panne CSS', in_array('relearn', $labels, true), true);
check('rapport copiable proposé', in_array('copy', $labels, true), true);
$rep = Uptimer\Triage::report($tid);
check('rapport texte produit', str_contains($rep, '## Diagnostic') && str_contains($rep, '## Conduite à tenir'), true);
check('rapport sans balise HTML', (bool)preg_match('~<[a-z]~i', $rep), false);
check('compteurs de triage', is_array(Uptimer\Triage::counts()), true);
check('seuils d\'anticipation raisonnables',
    Uptimer\Triage::SSL_SOON_DAYS >= 14 && Uptimer\Triage::DOMAIN_SOON_DAYS >= 30, true);
@unlink($tmpT); @unlink($tmpT . '-wal'); @unlink($tmpT . '-shm');

// =========================================================================
section('Sécurité : cibles refusées, cellules de tableur');
// =========================================================================
use Uptimer\Http;

// --- Schémas d'URL : seul HTTP(S) devient une sonde ----------------------
foreach (['file:///etc/passwd', 'gopher://127.0.0.1:70/x', 'dict://127.0.0.1:1/x',
          'ftp://127.0.0.1/', 'php://filter/resource=x', 'data:text/html,<b>x',
          'javascript:alert(1)', 'jar:file:///etc/passwd!/', 'ldap://127.0.0.1/'] as $u) {
    check('schéma refusé : ' . str_cut($u, 26), normalize_url($u), null);
}
check('domaine nu accepté', normalize_url('exemple.fr'), 'https://exemple.fr/');
check('protocole conservé', normalize_url('http://exemple.fr/x'), 'http://exemple.fr/x');

// --- Garde-fou de plages privées : désactivé par défaut ------------------
Uptimer\Config::set('security.block_private_ranges', false);
check('garde-fou inactif par défaut', Http::blockedReason('http://127.0.0.1:22/'), null);
Uptimer\Config::set('security.block_private_ranges', true);
foreach (['http://127.0.0.1:22/', 'http://10.0.0.5/', 'http://192.168.1.1/',
          'http://172.16.0.1/', 'http://[::1]/'] as $u) {
    check('cible interne refusée : ' . str_cut($u, 24), Http::blockedReason($u) !== null, true);
}
check('adresse de métadonnées refusée',
    str_contains((string)Http::blockedReason('http://169.254.169.254/'), 'métadonnées'), true);
check('cible publique permise', Http::blockedReason('https://example.com/'), null);
Uptimer\Config::set('security.block_private_ranges', false);

// --- Cellules de tableur : aucune formule exécutable --------------------
foreach ([
    ['=cmd|\'/C calc\'!A0', "'=cmd|'/C calc'!A0"],
    ['+1+1', "'+1+1"],
    ['-2+3', "'-2+3"],
    ['@SUM(A1:A9)', "'@SUM(A1:A9)"],
    ["\tinjecté", "'\tinjecté"],
    ['Boutique Dupont', 'Boutique Dupont'],
    ['', ''],
    ['déjà sûr = ici', 'déjà sûr = ici'],
] as [$in, $want]) {
    check('cellule neutralisée : ' . str_cut($in, 22), csv_cell($in), $want);
}

// =========================================================================
section('Langues : négociation, pluriels, catalogues');
// =========================================================================
use Uptimer\I18n;

check('dix langues déclarées', count(I18n::LANGS), 10);
check('anglais par défaut', I18n::DEFAULT, 'en');
check('français = langue source des clés', I18n::SOURCE, 'fr');

// --- Sens d'écriture ------------------------------------------------------
check('arabe de droite à gauche', I18n::dir('ar'), 'rtl');
check('ourdou de droite à gauche', I18n::dir('ur'), 'rtl');
check('chinois de gauche à droite', I18n::dir('zh'), 'ltr');

// --- Normalisation d'un code de langue -----------------------------------
foreach ([['fr-FR', 'fr'], ['pt_BR', 'pt'], ['zh-Hans', 'zh'], ['en-GB', 'en'],
          ['ZH', 'zh'], ['', 'en'], ['klingon', 'en'], ['de', 'en'], ['ar-EG', 'ar']] as [$in, $want]) {
    check("code « $in » ramené à « $want »", I18n::normalize($in), $want);
}

// --- En-tête du navigateur ------------------------------------------------
check('Accept-Language simple', I18n::fromHeader('es-ES,es;q=0.9'), 'es');
check('Accept-Language : la meilleure qualité gagne',
    I18n::fromHeader('de;q=0.9,ru;q=0.8,fr;q=0.2'), 'ru');
check('Accept-Language sans langue connue → anglais',
    I18n::fromHeader('de-DE,de;q=0.9,nl;q=0.8'), 'en');
check('Accept-Language vide → anglais', I18n::fromHeader(''), 'en');
check('Accept-Language absurde ne casse rien', I18n::fromHeader('%%%;q=zz,,,'), 'en');

// --- Traduction et substitution ------------------------------------------
I18n::init('en');
check('traduction simple', I18n::t('Aujourd\'hui'), 'Today');
check('substitution de variable',
    I18n::t('depuis {duration}', ['duration' => '2 h']), 'for 2 h');
check('clé inconnue rendue telle quelle',
    I18n::t('Cette phrase n\'existe nulle part'), 'Cette phrase n\'existe nulle part');
check('variable absente laissée en place', I18n::t('depuis {duration}'), 'for {duration}');

I18n::init('fr');
check('en français la clé est la valeur', I18n::t('Aujourd\'hui'), 'Aujourd\'hui');

// --- Pluriels -------------------------------------------------------------
I18n::init('en');
check('anglais singulier', I18n::n(1, 'un échec', '{n} échecs'), 'one failure');
check('anglais pluriel', I18n::n(4, 'un échec', '{n} échecs'), '4 failures');
check('anglais zéro au pluriel', I18n::n(0, 'un échec', '{n} échecs'), '0 failures');
I18n::init('fr');
check('français zéro au singulier', I18n::n(0, 'un échec', '{n} échecs'), 'un échec');
check('français deux au pluriel', I18n::n(2, 'un échec', '{n} échecs'), '2 échecs');
I18n::init('ru');
// Le russe distingue 1 / 2-4 / 5+ : la troisième forme vient du catalogue.
$r1 = I18n::n(1, 'un site à remettre en ligne', '{n} sites à remettre en ligne');
$r3 = I18n::n(3, 'un site à remettre en ligne', '{n} sites à remettre en ligne');
$r7 = I18n::n(7, 'un site à remettre en ligne', '{n} sites à remettre en ligne');
check('russe : trois formes distinctes', $r1 !== $r3 && $r3 !== $r7, true);
check('russe : 21 se comporte comme 1', I18n::n(21, 'un site à remettre en ligne', '{n} sites à remettre en ligne')
    === str_replace('1', '21', $r1), true);
I18n::init('zh');
check('chinois : une seule forme',
    I18n::n(1, 'un échec', '{n} échecs') === I18n::n(9, 'un échec', '{n} échecs')
    || str_contains(I18n::n(9, 'un échec', '{n} échecs'), '9'), true);

// --- Repli en cascade : langue → anglais → source ------------------------
I18n::init('zh');
$long = 'Une exécution par minute suffit quels que soient vos intervalles : {app} choisit elle-même les sondes dues.';
$out  = I18n::t($long);
check('phrase non traduite en chinois → anglais', $out !== $long && !str_contains($out, 'choisit elle-même'), true);

// --- Intégrité des catalogues --------------------------------------------
$catBad = [];
foreach (array_keys(I18n::LANGS) as $lg) {
    if ($lg === I18n::SOURCE) continue;
    $c = I18n::catalogue($lg);
    if ($lg === I18n::DEFAULT && count($c) < 500) { $catBad[] = "$lg trop court"; continue; }
    foreach ($c as $k => $v) {
        if (!is_string($k) || !is_string($v) || $k === '' || $v === '') { $catBad[] = "$lg clé invalide"; break; }
        // Une variable présente dans la clé doit survivre à la traduction.
        if (preg_match_all('~\{([a-z_]+)\}~', $k, $mk)) {
            foreach ($mk[1] as $var) {
                if (!str_contains($v, '{' . $var . '}')) { $catBad[] = "$lg : {" . $var . "} perdu"; break 2; }
            }
        }
    }
}
check('catalogues valides et variables préservées', $catBad, []);

// Le nom du produit ne doit JAMAIS entrer dans une clé : sinon un renommage
// périme les neuf catalogues d'un coup. C'est arrivé deux fois.
$withName = [];
foreach (array_keys(I18n::catalogue('en')) as $k) {
    if (str_contains($k, I18n::APP)) $withName[] = str_cut($k, 40);
}
check('aucune clé ne contient le nom du produit', $withName, []);
check('le nom du produit est substitué',
    str_contains(I18n::t('Rapport produit par {app}'), I18n::APP), true);

$en = I18n::catalogue('en');
check('catalogue anglais complet', count($en) >= 700, true);
check('aucune traduction anglaise vide', count(array_filter($en, fn($v) => trim($v) === '')), 0);

// --- Format des nombres selon la langue ----------------------------------
I18n::init('fr');
$fr = Uptimer\Ui::num(1234.5, 1);
I18n::init('en');
$en2 = Uptimer\Ui::num(1234.5, 1);
I18n::init('es');
$es = Uptimer\Ui::num(1234.5, 1);
check('français : virgule décimale', str_contains($fr, ','), true);
check('anglais : point décimal', str_contains($en2, '.') && str_contains($en2, ','), true);
check('espagnol : point pour les milliers', str_contains($es, '.') && str_contains($es, ','), true);
I18n::init('fr');

// =========================================================================
section('Niveau de détail de l\'interface');
// =========================================================================
unset($_COOKIE['uptimer_mode'], $_SESSION['uptimer_mode']);
check('deux modes seulement', Uptimer\Ui::MODES, ['simple', 'expert']);
check('un mode inconnu retombe sur simple',
    in_array('simple', Uptimer\Ui::MODES, true) && !in_array('nawak', Uptimer\Ui::MODES, true), true);

// =========================================================================
section('Silhouette : ce que le visiteur verrait');
// =========================================================================
use Uptimer\Check\Silhouette;

$pageHtml = '<!doctype html><html><head><title>T</title><style>.x{color:red}</style></head><body>'
    . '<header class="site-header"><nav class="nav-main"><a href="/">Accueil</a><a href="/c">Contact</a></nav></header>'
    . '<main class="container"><h1 class="hero-title">Boulangerie du Marché</h1>'
    . '<p>Pain au levain cuit sur place chaque matin, farines biologiques.</p>'
    . '<div class="card-grid">'
    . '<div class="card"><img src="a.jpg"><h3>Pains</h3><p>Levain naturel</p></div>'
    . '<div class="card"><img src="b.jpg"><h3>Viennoiseries</h3><p>Beurre AOP</p></div>'
    . '<div class="card"><img src="c.jpg"><h3>Traiteur</h3><p>Sur commande</p></div>'
    . '</div><a class="btn" href="/x">Commander</a></main>'
    . '<footer class="footer-main"><p>Tous droits réservés</p></footer></body></html>';
$pageCss = '.container{max-width:1100px;margin:0 auto;padding:0 24px}'
    . '.site-header{background:#fff;padding:12px}.nav-main{display:flex;gap:16px}'
    . '.hero-title{font-size:2.5rem;text-align:center}'
    . '.card-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px}'
    . '.card{background:#fff;border-radius:12px;padding:16px}'
    . '.btn{background:#3b5bdb;border-radius:8px;padding:10px 20px}'
    . '.footer-main{background:#f1f5f9;padding:24px;text-align:center}';

$sil  = Silhouette::build($pageHtml, $pageCss);
$bare = Silhouette::build($pageHtml, '');

check('silhouette produite', str_starts_with($sil['svg'], '<svg'), true);
check('SVG bien fermé', str_ends_with(trim($sil['svg']), '</svg>'), true);
check('des blocs sont dessinés', $sil['nodes'] > 8, true);
check('signature complète',
    array_keys($sil['signature']), ['contained', 'columns', 'height', 'variety', 'density']);

// --- Le CSS change tout : c'est le principe même de la mesure -------------
check('avec CSS : contenu dans un conteneur centré', $sil['signature']['contained'] > 0.5, true);
check('sans CSS : plus rien n\'est contenu', $bare['signature']['contained'] < 0.2, true);
check('avec CSS : des colonnes existent', $sil['signature']['columns'] >= 1, true);
check('sans CSS : plus de colonnes', $bare['signature']['columns'], 0);
check('sans CSS : la page s\'allonge', $bare['signature']['height'] > $sil['signature']['height'], true);
check('sans CSS : tout occupe la largeur', $bare['signature']['density'] > 0.8, true);

$drift = Silhouette::distance($sil['signature'], $bare['signature']);
check('écart mesuré supérieur au seuil d\'alerte', $drift > 0.35, true);
check('écart identique à lui-même', Silhouette::distance($sil['signature'], $sil['signature']), 0.0);
check('écart borné à 1', $drift <= 1.0, true);
check('signature vide : pas d\'écart inventé', Silhouette::distance([], $sil['signature']), 0.0);

// --- Le SVG est produit par nous, jamais par le site ---------------------
// La silhouette est injectée brute dans la page : si un contenu du site pouvait
// y entrer, ce serait une XSS stockée. On le vérifie avec du HTML hostile.
$hostile = '<body><h1 class="a&quot;onload=alert(1)">' . '<script>alert(1)</script>'
    . '</h1><p>' . htmlspecialchars('"><svg onload=alert(1)>') . '</p>'
    . '<div class="\'><script>alert(2)</script>">bloc</div>'
    . '<img src=x onerror=alert(3)></body>';
$hs = Silhouette::build($hostile, '.a{display:flex}');
check('aucun script dans le SVG', str_contains($hs['svg'], '<script'), false);
check('aucun gestionnaire d\'évènement', (bool)preg_match('~\bon[a-z]+\s*=~i', $hs['svg']), false);
check('aucun texte du site dans le SVG', str_contains($hs['svg'], 'alert'), false);
check('le SVG ne contient que des formes',
    (bool)preg_match('~^<svg[^>]*>(?:<(?:rect|path)\b[^>]*/>)*</svg>$~', $hs['svg']), true);

// --- Robustesse : le collecteur ne doit jamais s'étouffer -----------------
foreach ([
    ['vide', ''],
    ['sans corps', '<html><head><title>x</title></head></html>'],
    ['balises non fermées', '<body><div><section><p>texte<div><span>'],
    ['fermetures orphelines', '<body></div></section></p></body>'],
    ['profondeur extrême', '<body>' . str_repeat('<div>', 200) . 'x' . str_repeat('</div>', 200) . '</body>'],
    ['largeur extrême', '<body>' . str_repeat('<p>texte</p>', 500) . '</body>'],
    ['octets invalides', "<body><p>\xC3\x28 texte \xE2\x82</p></body>"],
    ['entités', '<body><p>&lt;&gt;&amp;&nbsp;&#233;</p></body>'],
] as [$label, $bad]) {
    $r = Silhouette::build($bad, $pageCss);
    check('HTML ' . $label . ' : silhouette valide',
        str_starts_with($r['svg'], '<svg') && str_ends_with(trim($r['svg']), '</svg>'), true);
}
foreach ([
    ['CSS vide', ''],
    ['CSS tronqué', '.a{display:flex'],
    ['accolades déséquilibrées', '}}}.a{color:red}{{{'],
    ['CSS gigantesque', str_repeat('.c' . 'x{padding:4px}', 3000)],
] as [$label, $badCss]) {
    $r = Silhouette::build($pageHtml, $badCss);
    check($label . ' : silhouette valide', str_starts_with($r['svg'], '<svg'), true);
}

// --- Coût : le plafond de nœuds tient -----------------------------------
$huge = '<body>' . str_repeat('<section class="card"><h2>T</h2><p>texte assez long pour compter</p>'
      . '<img src="a.jpg"></section>', 400) . '</body>';
$t0 = microtime(true);
$r  = Silhouette::build($huge, $pageCss);
$ms = (microtime(true) - $t0) * 1000;
check('page énorme : nombre de blocs plafonné', $r['nodes'] <= 120, true);
check('page énorme : analysée en moins de 500 ms', $ms < 500, true);
check('SVG de taille raisonnable', strlen($r['svg']) < 40000, true);

// --- La silhouette entre bien dans le résultat de l'audit ---------------
$audit = Uptimer\Check\Css::audit('https://exemple.fr/', $pageHtml, null, [], ['silhouette' => true]);
check('l\'audit CSS renvoie une silhouette', isset($audit['silhouette'], $audit['silhouette_sig']), true);
$audit2 = Uptimer\Check\Css::audit('https://exemple.fr/', $pageHtml, null, [], ['silhouette' => false]);
check('la silhouette peut être désactivée', isset($audit2['silhouette']), false);

// =========================================================================
section('Serveur MCP : protocole et outils');
// =========================================================================
/**
 * Le serveur MCP parle JSON-RPC sur stdio. On le lance vraiment et on tient une
 * conversation complète : c'est le seul moyen de vérifier qu'un client MCP
 * pourra s'y connecter.
 */
$mcpAsk = function (array $messages, bool $write = false) : array {
    $cmd = [PHP_BINARY, UPTIMER_ROOT . '/bin/mcp.php'];
    if ($write) $cmd[] = '--write';
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
                      $pipes, UPTIMER_ROOT, ['PATH' => getenv('PATH') ?: '/usr/bin:/bin']);
    if (!is_resource($proc)) return [];
    foreach ($messages as $m) fwrite($pipes[0], json_encode($m) . "\n");
    fclose($pipes[0]);
    $out = (string)stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    proc_close($proc);
    $replies = [];
    foreach (explode("\n", trim($out)) as $line) {
        if (trim($line) === '') continue;
        $d = json_decode($line, true);
        if (is_array($d)) $replies[] = $d;
    }
    return $replies;
};
$hello = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
          'params' => ['protocolVersion' => '2024-11-05', 'capabilities' => []]];

// --- Poignée de main -----------------------------------------------------
$r = $mcpAsk([$hello]);
check('MCP répond à initialize', isset($r[0]['result']['serverInfo']['name']), true);
check('MCP s\'annonce sous son nom', $r[0]['result']['serverInfo']['name'] ?? '', 'uptimer');
check('MCP annonce une version de protocole',
    (bool)preg_match('~^\d{4}-\d{2}-\d{2}$~', (string)($r[0]['result']['protocolVersion'] ?? '')), true);
check('MCP déclare la capacité outils', isset($r[0]['result']['capabilities']['tools']), true);
check('MCP fournit des instructions à l\'agent',
    mb_strlen((string)($r[0]['result']['instructions'] ?? '')) > 100, true);

// --- Catalogue d'outils --------------------------------------------------
$r = $mcpAsk([$hello, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']]);
$tools = $r[1]['result']['tools'] ?? [];
$names = array_column($tools, 'name');
check('en lecture seule, aucun outil d\'écriture',
    array_values(array_intersect($names, ['check_now', 'apply_fix', 'set_enabled', 'add_sites'])), []);
foreach (['status', 'tasks', 'list_monitors', 'monitor_detail', 'incidents', 'report'] as $t) {
    check('outil de lecture exposé : ' . $t, in_array($t, $names, true), true);
}
$badSchema = [];
foreach ($tools as $t) {
    if (($t['inputSchema']['type'] ?? '') !== 'object') $badSchema[] = $t['name'] . ' : type';
    if (mb_strlen((string)($t['description'] ?? '')) < 40) $badSchema[] = $t['name'] . ' : description trop courte';
    if (!isset($t['annotations']['readOnlyHint'])) $badSchema[] = $t['name'] . ' : annotation manquante';
}
check('chaque outil a un schéma et une description utilisables', $badSchema, []);

$r = $mcpAsk([$hello, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']], true);
$namesW = array_column($r[1]['result']['tools'] ?? [], 'name');
foreach (['check_now', 'apply_fix', 'set_enabled', 'add_sites'] as $t) {
    check('avec --write, outil exposé : ' . $t, in_array($t, $namesW, true), true);
}

// --- Appels d'outils -----------------------------------------------------
$call = fn(string $name, array $args = []) => ['jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/call',
                                               'params' => ['name' => $name, 'arguments' => $args]];
$payload = function (array $replies): array {
    foreach ($replies as $d) {
        if (isset($d['result']['content'][0]['text'])) {
            return json_decode((string)$d['result']['content'][0]['text'], true) ?: [];
        }
    }
    return [];
};
$r = $payload($mcpAsk([$hello, $call('status')]));
check('outil status : compteurs présents',
    isset($r['down'], $r['up'], $r['total'], $r['collector_has_run']), true);
$r = $payload($mcpAsk([$hello, $call('tasks')]));
check('outil tasks : deux listes', isset($r['now'], $r['upcoming'], $r['nothing_to_do']), true);
$r = $payload($mcpAsk([$hello, $call('list_monitors', ['limit' => 3])]));
check('outil list_monitors : borne respectée', count($r['monitors'] ?? []) <= 3, true);
$r = $payload($mcpAsk([$hello, $call('monitor_detail', ['monitor_id' => 999999])]));
check('identifiant inconnu : erreur explicite, pas de plantage',
    str_contains((string)($r['error'] ?? ''), '999999'), true);
$r = $payload($mcpAsk([$hello, $call('security_target_check', ['url' => 'file:///etc/passwd'])]));
check('outil de contrôle de cible : file:// refusé', $r['allowed'] ?? true, false);
$r = $payload($mcpAsk([$hello, $call('security_target_check', ['url' => 'exemple.fr'])]));
check('outil de contrôle de cible : domaine nu accepté', $r['allowed'] ?? false, true);

// --- Refus et robustesse -------------------------------------------------
$r = $payload($mcpAsk([$hello, $call('check_now')]));
check('outil d\'écriture refusé en lecture seule',
    str_contains((string)($r['error'] ?? ''), 'read-only'), true);
$r = $mcpAsk([$hello, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
                       'params' => ['name' => 'inexistant', 'arguments' => []]]]);
check('outil inconnu : erreur JSON-RPC', isset($r[1]['error']['code']), true);
$r = $mcpAsk([$hello, ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'methode/inconnue']]);
check('méthode inconnue : erreur JSON-RPC', ($r[1]['error']['code'] ?? 0), -32601);
// Une ligne illisible ne doit pas interrompre la conversation.
$proc = proc_open([PHP_BINARY, UPTIMER_ROOT . '/bin/mcp.php'],
                  [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
                  $pipes, UPTIMER_ROOT, ['PATH' => getenv('PATH') ?: '/usr/bin:/bin']);
fwrite($pipes[0], "ceci n'est pas du JSON\n");
fwrite($pipes[0], json_encode($hello) . "\n");
fclose($pipes[0]);
$out = (string)stream_get_contents($pipes[1]);
fclose($pipes[1]); proc_close($proc);
check('ligne illisible : le serveur survit et répond ensuite',
    str_contains($out, '-32700') && str_contains($out, 'uptimer'), true);

echo "\n" . str_repeat('─', 68) . "\n";
printf("%d test(s) réussi(s), %d échec(s)\n", $pass, $fail);
if ($fail > 0) {
    echo "⚠️  Des contrôles échouent : la détection n'est peut-être pas fiable sur cette installation.\n";
    exit(1);
}
echo "✅ La logique de détection fonctionne sur cette installation.\n";
