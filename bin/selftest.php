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
    I18n::t('réponse {ms}', ['ms' => '120 ms']), 'response 120 ms');
check('clé inconnue rendue telle quelle',
    I18n::t('Cette phrase n\'existe nulle part'), 'Cette phrase n\'existe nulle part');
check('variable absente laissée en place', I18n::t('réponse {ms}'), 'response {ms}');

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
section('Inventaire logiciel : lecture des versions');
// =========================================================================
use Uptimer\Detect\Stack;

$wpHtml = '<html><head><meta name="generator" content="WordPress 6.4.2">'
    . '<link rel="stylesheet" href="/wp-includes/css/dist/block-library/style.min.css?ver=6.4.2">'
    . '<link rel="stylesheet" href="/wp-content/plugins/elementor/assets/css/frontend.min.css?ver=3.18.3">'
    . '<link rel="stylesheet" href="/wp-content/plugins/contact-form-7/includes/css/styles.css?ver=5.8.6">'
    . '<link rel="stylesheet" href="/wp-content/themes/astra/style.css?ver=4.6.2">'
    . '<script src="/wp-content/plugins/woocommerce/assets/js/cart.min.js"></script>'
    . '</head><body>x</body></html>';
$inv = [];
foreach (Stack::inventory($wpHtml, 'WordPress') as $c) $inv[$c['kind'] . ':' . $c['slug']] = $c;

check('cœur WordPress détecté', $inv['core:wordpress']['version'] ?? null, '6.4.2');
check('version lue dans la balise generator', $inv['core:wordpress']['source'] ?? null, 'generator');
check('extension avec sa version', $inv['plugin:elementor']['version'] ?? null, '3.18.3');
check('seconde extension', $inv['plugin:contact-form-7']['version'] ?? null, '5.8.6');
check('thème détecté', $inv['theme:astra']['version'] ?? null, '4.6.2');
check('extension sans version enregistrée sans version',
    array_key_exists('plugin:woocommerce', $inv) && $inv['plugin:woocommerce']['version'] === null, true);
check('nom lisible déduit du dossier', $inv['plugin:contact-form-7']['name'] ?? null, 'Contact Form 7');

// La version la plus précise doit gagner, quelle que soit sa provenance.
$dr = '<html><head><meta name="Generator" content="Drupal 10 (https://www.drupal.org)">'
    . '<script src="/core/misc/drupal.js?v=10.1.6"></script>'
    . '<link href="/sites/default/modules/contrib/webform/css/webform.css"></head><body></body></html>';
$dinv = [];
foreach (Stack::inventory($dr, 'Drupal') as $c) $dinv[$c['kind'] . ':' . $c['slug']] = $c;
check('version précise préférée à la version majeure', $dinv['core:drupal']['version'] ?? null, '10.1.6');
check('module Drupal détecté', isset($dinv['plugin:webform']), true);
check('dossier « contrib » n\'est pas un module', isset($dinv['plugin:contrib']), false);

// Sans rien de lisible, on n'invente pas de version.
$bare = '<html><head><title>x</title></head><body><p>rien</p></body></html>';
$binv = Stack::inventory($bare, 'WordPress');
check('sans indice, le cœur est noté sans version',
    count($binv) === 1 && $binv[0]['version'] === null, true);
check('sans technologie connue, inventaire vide', Stack::inventory($bare, null), []);

// Robustesse : le collecteur ne doit pas s'étouffer.
foreach ([['vide', ''], ['balises cassées', '<html><head><meta name=generator content=WordPress'],
          ['chemins hostiles', '<link href="/wp-content/plugins/../../etc/passwd/x.css?ver=1.0">'],
          ['énorme', str_repeat('<link href="/wp-content/plugins/p' . 'x/a.css?ver=1.0">', 500)]] as [$lbl, $bad]) {
    $r = Stack::inventory($bad, 'WordPress');
    check('HTML ' . $lbl . ' : inventaire borné', is_array($r) && count($r) <= 40, true);
}

// Comparaison de versions, y compris avec des suffixes.
foreach ([['6.4.2', '6.4.3', -1], ['6.4.3', '6.4.2', 1], ['6.4.2', '6.4.2', 0],
          ['6.4', '6.4.0', 0], ['10.1.6', '9.5.0', 1], ['6.4.2-beta1', '6.4.2', 0],
          ['', '1.0', -1], ['10', '10.1.6', -1], ['1.0.0.0', '1', 0]] as [$a, $b, $want]) {
    check('version ' . ($a ?: '(vide)') . ' vs ' . $b, Stack::compare($a, $b), $want);
}
// Le retard n'est affirmé que quand il est certain.
check('6.4.2 est en retard sur 6.4.3', Stack::isBehind('6.4.2', '6.4.3'), true);
check('6.4.3 n\'est pas en retard sur 6.4.2', Stack::isBehind('6.4.3', '6.4.2'), false);
check('version majeure seule : retard indécidable, donc non affirmé',
    Stack::isBehind('10', '10.1.6'), false);
check('version majeure différente : retard certain', Stack::isBehind('9', '10.1.6'), true);
check('version identique : aucun retard', Stack::isBehind('6.4.2', '6.4.2'), false);
check('version absente : aucune affirmation', Stack::isBehind('', '6.4.2'), false);
check('précision comptée correctement', Stack::precision('6.4.2-beta1'), 3);

// Gravité : on ne l'invente pas quand la source ne la donne pas.
check('gravité absente reste inconnue', Uptimer\Vuln::worstSeverity([['id' => 'X']]), 'unknown');
check('gravité la pire retenue',
    Uptimer\Vuln::worstSeverity([['severity' => 'low'], ['severity' => 'high'], ['severity' => 'medium']]), 'high');
check('aucun avis, aucune gravité', Uptimer\Vuln::worstSeverity([]), null);
check('libellé de gravité traduit', mb_strlen(Uptimer\Vuln::severityLabel('high')) > 3, true);

// --- Enregistrement et remise à zéro sur changement de version ----------
$tmpV = sys_get_temp_dir() . '/uptimer-vuln-' . bin2hex(random_bytes(3)) . '.sqlite';
$prevDbV = Uptimer\Config::get('db.sqlite');
Uptimer\Config::set('db.sqlite', $tmpV);
Uptimer\Db::migrate();
$vsid = Uptimer\Db::insert('sites', ['name' => 'Site', 'domain' => 'site.fr', 'created_at' => now()]);
$vmid = Uptimer\Db::insert('monitors', ['site_id' => $vsid, 'name' => 'Accueil', 'url' => 'https://site.fr/',
    'kind' => 'page', 'role' => 'primary', 'method' => 'GET', 'interval_sec' => 300, 'timeout_sec' => 15,
    'retries' => 0, 'slow_ms' => 3000, 'expect_status' => '200-299', 'check_ssl' => 0, 'check_css' => 0,
    'check_db' => 0, 'check_noindex' => 0, 'ssl_warn_days' => 14, 'css_drop_pct' => 35, 'enabled' => 1,
    'status' => 'up', 'setup_state' => 'done', 'created_at' => now(), 'follow_redirects' => 1]);

$n = Uptimer\Vuln::record($vmid, $vsid, $wpHtml, 'WordPress');
check('inventaire enregistré', $n >= 5, true);
check('un composant par site, sans doublon',
    (int)Uptimer\Db::val('SELECT COUNT(*) FROM components WHERE site_id = ?', [$vsid]), $n);
Uptimer\Vuln::record($vmid, $vsid, $wpHtml, 'WordPress');
check('une seconde lecture ne duplique rien',
    (int)Uptimer\Db::val('SELECT COUNT(*) FROM components WHERE site_id = ?', [$vsid]), $n);

// Un verdict de veille, puis une mise à jour du site : le verdict doit tomber.
Uptimer\Db::update('components', ['vuln_count' => 2, 'worst' => 'high', 'checked_at' => now(),
    'advisories' => jenc([['id' => 'X-1', 'severity' => 'high']])],
    'site_id = :s AND slug = :g', ['s' => $vsid, 'g' => 'elementor']);
check('un composant est marqué vulnérable',
    (int)Uptimer\Db::val('SELECT vuln_count FROM components WHERE site_id = ? AND slug = ?', [$vsid, 'elementor']), 2);
$updated = str_replace('elementor/assets/css/frontend.min.css?ver=3.18.3',
                       'elementor/assets/css/frontend.min.css?ver=3.25.0', $wpHtml);
Uptimer\Vuln::record($vmid, $vsid, $updated, 'WordPress');
$after = Uptimer\Db::one('SELECT version, vuln_count, checked_at FROM components
                          WHERE site_id = ? AND slug = ?', [$vsid, 'elementor']);
check('la nouvelle version est enregistrée', $after['version'], '3.25.0');
check('le verdict est remis à zéro après mise à jour', (int)$after['vuln_count'], 0);
check('et sera revérifié', $after['checked_at'], null);

// Sans version lisible, la veille n'a rien à interroger.
Uptimer\Db::q('UPDATE components SET version = NULL');
$sc = Uptimer\Vuln::scan(5);
check('aucune version : aucune interrogation', $sc['checked'], 0);

// Les compteurs restent cohérents.
$vc = Uptimer\Vuln::counts();
check('compteurs disponibles',
    array_keys($vc), ['components', 'with_vuln', 'high', 'outdated', 'unchecked']);
check('rien de vulnérable dans cette base', $vc['with_vuln'], 0);
check('aucune trouvaille à signaler', Uptimer\Vuln::findings(), []);

Uptimer\Config::set('db.sqlite', $prevDbV);
@unlink($tmpV); @unlink($tmpV . '-wal'); @unlink($tmpV . '-shm');

// =========================================================================
section('Rapport mensuel : programmation et composition');
// =========================================================================
use Uptimer\Report;

// --- Le mois couvert est toujours le mois écoulé -------------------------
check('mois couvert par un envoi du 1er mars', Report::monthKey('2026-03-01'), '2026-02');
check('mois couvert par un envoi du 31 mars', Report::monthKey('2026-03-31'), '2026-02');
check('bascule d\'année en janvier', Report::monthKey('2026-01-05'), '2025-12');
[$rFrom, $rTo] = Report::monthRange('2026-03-10');
check('début du mois couvert', $rFrom, '2026-02-01 00:00:00');
check('fin du mois couvert, année bissextile', $rTo, '2026-02-28 23:59:59');
[$rFrom2, $rTo2] = Report::monthRange('2024-03-10');
check('février 2024 comptait 29 jours', $rTo2, '2024-02-29 23:59:59');

// --- Destinataires -------------------------------------------------------
Uptimer\Config::set('report.fallback_to', 'secours@agence.fr');
check('séparateurs variés',
    Report::recipients(['report_to' => 'a@b.fr, c@d.fr; e@f.fr  g@h.fr']),
    ['a@b.fr', 'c@d.fr', 'e@f.fr', 'g@h.fr']);
check('adresses invalides écartées',
    Report::recipients(['report_to' => 'bon@exemple.fr, pas-une-adresse, @rien, x@y']),
    ['bon@exemple.fr']);
check('doublons fusionnés',
    Report::recipients(['report_to' => 'a@b.fr, a@b.fr, A@b.fr']), ['a@b.fr', 'A@b.fr']);
check('repli sur les destinataires par défaut',
    Report::recipients(['report_to' => '']), ['secours@agence.fr']);
Uptimer\Config::set('report.fallback_to', '');
check('sans repli, aucun destinataire', Report::recipients(['report_to' => '']), []);

// --- Base isolée pour la programmation et les chiffres ------------------
$tmpR = sys_get_temp_dir() . '/uptimer-report-' . bin2hex(random_bytes(3)) . '.sqlite';
$prevDb = Uptimer\Config::get('db.sqlite');
Uptimer\Config::set('db.sqlite', $tmpR);
Uptimer\Db::migrate();

$rsid = Uptimer\Db::insert('sites', ['name' => 'Client Témoin', 'domain' => 'temoin.fr',
    'report_enabled' => 1, 'report_to' => 'client@temoin.fr', 'created_at' => now()]);
$rmid = Uptimer\Db::insert('monitors', ['site_id' => $rsid, 'name' => 'Accueil',
    'url' => 'https://temoin.fr/', 'kind' => 'page', 'role' => 'primary', 'method' => 'GET',
    'interval_sec' => 300, 'timeout_sec' => 15, 'retries' => 0, 'slow_ms' => 3000,
    'expect_status' => '200-299', 'check_ssl' => 0, 'check_css' => 0, 'check_db' => 0,
    'check_noindex' => 0, 'ssl_warn_days' => 14, 'css_drop_pct' => 35, 'enabled' => 1,
    'status' => 'up', 'setup_state' => 'done', 'created_at' => now(), 'follow_redirects' => 1]);
// Un mois de mesures : 28 jours pleins, dont un avec 30 minutes d'interruption.
[$mFrom] = Report::monthRange('2026-03-10');
for ($d = 1; $d <= 28; $d++) {
    $day = sprintf('2026-02-%02d', $d);
    Uptimer\Db::insert('daily_stats', ['monitor_id' => $rmid, 'day' => $day,
        'checks' => 288, 'fails' => $d === 12 ? 6 : 0, 'degraded' => 0,
        'downtime_sec' => $d === 12 ? 1800 : 0, 'avg_ms' => 420.0, 'p95_ms' => 700,
        'min_ms' => 300, 'max_ms' => 900]);
}
Uptimer\Db::insert('incidents', ['monitor_id' => $rmid, 'started_at' => '2026-02-12 04:10:00',
    'ended_at' => '2026-02-12 04:40:00', 'duration_sec' => 1800, 'severity' => 'down',
    'reason_code' => 'HTTP_5XX', 'message' => 'Le serveur renvoie une erreur',
    'checks_failed' => 6, 'notify_count' => 1]);

$rdata = Report::data($rsid, '2026-02-01 00:00:00', '2026-02-28 23:59:59');
check('mesures agrégées sur le mois', $rdata['checks'], 288 * 28);
check('uptime calculé sur les échecs réels', round((float)$rdata['uptime'], 2), 99.93);
check('indisponibilité cumulée', $rdata['down_sec'], 1800);
check('bande de 28 jours', count($rdata['days']), 28);
check('une interruption listée', count($rdata['incidents']), 1);
check('temps de réponse moyen', $rdata['avg_ms'], 420);

// --- Programmation ------------------------------------------------------
Uptimer\Config::set('report.enabled', false);
check('envoi désactivé : rien n\'est dû', Report::dueSites('2026-03-01'), []);
Uptimer\Config::set('report.enabled', true);
Uptimer\Config::set('report.day', 5);
check('avant le jour programmé : rien n\'est dû', Report::dueSites('2026-03-03'), []);
check('le jour programmé : le site est dû', count(Report::dueSites('2026-03-05')), 1);
check('après le jour programmé : encore dû', count(Report::dueSites('2026-03-19')), 1);
// Une programmation au 31 doit tomber le dernier jour d'un mois plus court.
Uptimer\Config::set('report.day', 31);
check('programmation au 31, dernier jour de février', count(Report::dueSites('2026-02-28')), 1);
Uptimer\Config::set('report.day', 1);

// Un rapport déjà parti ce mois-là ne repart pas.
Uptimer\Db::update('sites', ['report_sent_key' => '2026-02'], 'id = :i', ['i' => $rsid]);
check('déjà envoyé ce mois : rien n\'est dû', Report::dueSites('2026-03-10'), []);
Uptimer\Db::update('sites', ['report_sent_key' => null], 'id = :i', ['i' => $rsid]);
check('mois suivant : de nouveau dû', count(Report::dueSites('2026-03-10')), 1);
// Sans destinataire, rien n'est dû non plus.
Uptimer\Db::update('sites', ['report_to' => null], 'id = :i', ['i' => $rsid]);
check('sans destinataire : rien n\'est dû', Report::dueSites('2026-03-10'), []);
Uptimer\Db::update('sites', ['report_to' => 'client@temoin.fr'], 'id = :i', ['i' => $rsid]);

// --- Composition du courrier -------------------------------------------
$rsite = Uptimer\Db::one('SELECT * FROM sites WHERE id = ?', [$rsid]);
$rhtml = Report::html($rsite, $rdata, '2026-02-01 00:00:00', '2026-02-28 23:59:59');
$rtext = Report::text($rsite, $rdata, '2026-02-01 00:00:00', '2026-02-28 23:59:59');
check('le courrier porte le nom du client', str_contains($rhtml, 'Client Témoin'), true);
check('le courrier porte le mois couvert', str_contains($rhtml, 'février 2026'), true);
check('le courrier porte le chiffre de disponibilité', str_contains($rhtml, '99,93'), true);
check('le courrier liste l\'interruption', str_contains($rhtml, 'Erreur serveur') && str_contains($rhtml, '12/02'), true);
// Contraintes propres au courrier : rien d'externe, rien que les clients ne rendent pas.
check('aucune ressource distante', (bool)preg_match('~(src|href)=["\']https?://~i', $rhtml), false);
check('aucun SVG dans le courrier', str_contains($rhtml, '<svg'), false);
check('styles en ligne uniquement', str_contains($rhtml, '<style'), false);
check('mise en page par tableaux', str_contains($rhtml, '<table'), true);
check('version texte sans balise', (bool)preg_match('~<[a-z]~i', $rtext), false);
check('version texte avec les chiffres', str_contains($rtext, '99,93'), true);

// Le nom d'un site vient de l'utilisateur : il doit être échappé dans le courrier.
Uptimer\Db::update('sites', ['name' => '<script>alert(1)</script> & Cie'], 'id = :i', ['i' => $rsid]);
$hostileSite = Uptimer\Db::one('SELECT * FROM sites WHERE id = ?', [$rsid]);
$hh = Report::html($hostileSite, $rdata, '2026-02-01 00:00:00', '2026-02-28 23:59:59');
check('nom de site échappé dans le courrier', str_contains($hh, '<script>alert(1)</script>'), false);
check('nom de site présent sous forme échappée', str_contains($hh, '&lt;script&gt;'), true);
Uptimer\Db::update('sites', ['name' => 'Client Témoin'], 'id = :i', ['i' => $rsid]);

// --- Objet du message ---------------------------------------------------
Uptimer\Config::set('report.subject', '');
$r1 = Report::sendFor($rsid, '2026-03-10');
check('sans canal e-mail, l\'envoi échoue proprement', $r1['ok'], false);
check('et le motif est explicite', mb_strlen((string)$r1['info']) > 10, true);
check('objet composé avec le site et le mois',
    str_contains((string)$r1['subject'], 'Client Témoin') && str_contains((string)$r1['subject'], 'février'), true);
Uptimer\Config::set('report.subject', 'Suivi {site} - {month} - {app}');
$r2 = Report::sendFor($rsid, '2026-03-10');
check('gabarit d\'objet respecté',
    str_contains((string)$r2['subject'], 'Suivi Client Témoin - février 2026 - ' . Uptimer\I18n::APP), true);
// Un échec ne doit pas marquer le mois comme envoyé.
check('un échec ne consomme pas le mois',
    (string)Uptimer\Db::val('SELECT report_sent_key FROM sites WHERE id = ?', [$rsid]), '');
check('un échec laisse le site dû', count(Report::dueSites('2026-03-10')), 1);

// Un mois sans aucune mesure ne produit pas de rapport vide.
$emptySid = Uptimer\Db::insert('sites', ['name' => 'Sans mesure', 'domain' => 'vide.fr',
    'report_enabled' => 1, 'report_to' => 'x@vide.fr', 'created_at' => now()]);
$r3 = Report::sendFor($emptySid, '2026-03-10');
check('aucune mesure : rapport non envoyé', $r3['ok'], false);
check('et le motif le dit', str_contains((string)$r3['info'], 'esure'), true);

Uptimer\Config::set('report.enabled', false);
Uptimer\Config::set('report.subject', '');
Uptimer\Config::set('db.sqlite', $prevDb);
@unlink($tmpR); @unlink($tmpR . '-wal'); @unlink($tmpR . '-shm');

// =========================================================================
section('Le pouls du parc : le pire cas décide');
// =========================================================================
// Une tranche est rouge dès qu'un seul site était hors service pendant cette
// tranche : c'est le pire cas qui a fait sonner le téléphone, pas la moyenne.
$pulse = Uptimer\Stats::pulse(86400, 48);
check('une tranche par intervalle demandé', count($pulse), 48);
check('chaque tranche porte un horodatage',
    count(array_filter($pulse, fn($b) => (int)$b['t'] > 0)), 48);
check('chaque tranche porte un état',
    count(array_filter($pulse, fn($b) => in_array($b['state'], ['up', 'down', 'degraded', 'none'], true))), 48);
$last = end($pulse);
$first = $pulse[0];
check('les tranches vont du plus ancien au plus récent', $first['t'] < $last['t'], true);
check('la dernière tranche est proche de maintenant', time() - (int)$last['t'] < 3600, true);
// Un parc vide ne doit pas produire d'erreur, seulement des tranches vides.
check('découpage respecté même sur peu de tranches', count(Uptimer\Stats::pulse(3600, 6)), 6);
check('une tranche sans mesure est annoncée comme telle',
    in_array('none', array_column(Uptimer\Stats::pulse(86400 * 30, 30), 'state'), true)
      || count(Uptimer\Stats::pulse(86400 * 30, 30)) === 30, true);

// Le rendu : autant de rectangles que de tranches, chacun avec son explication.
$svg = Uptimer\Ui::pulse($pulse);
check('un rectangle par tranche', substr_count($svg, '<rect'), 48);
check('chaque rectangle porte son détail', substr_count($svg, '<title>'), 48);
check('la bande est décrite pour un lecteur d\'écran', str_contains($svg, 'aria-label'), true);
check('aucune tranche vide ne produit de rectangle sans classe',
    substr_count($svg, 'class="pl-'), 48);
check('bande vide : rien de rendu', Uptimer\Ui::pulse([]), '');

// =========================================================================
section('Vitesse ressentie : mesures et causes, jamais mélangées');
// =========================================================================
use Uptimer\Check\Vitals as VitalsCheck;
use Uptimer\Vitals;

// --- Le TTFB est une mesure : les seuils officiels s'appliquent tels quels
foreach ([[120, 'good'], [800, 'good'], [801, 'improve'], [1800, 'improve'],
          [1801, 'poor'], [9000, 'poor']] as [$ms, $want]) {
    $r = new Uptimer\Response(); $r->ttfbMs = $ms;
    $v = VitalsCheck::analyse('https://x.fr/', '<html><body>x</body></html>', [], $r, ['head' => false]);
    check('TTFB ' . $ms . ' ms', $v['ttfb_verdict'], $want);
}
$r = new Uptimer\Response();   // aucune mesure disponible
$v = VitalsCheck::analyse('https://x.fr/', '<html><body>x</body></html>', [], $r, ['head' => false]);
check('sans mesure de TTFB, aucun verdict inventé', $v['ttfb_verdict'], 'unknown');
check('et aucune valeur affichée', $v['ttfb_ms'], null);
// Un serveur qui répond en moins d'une milliseconde a bien été mesuré.
$fast = new Uptimer\Response(); $fast->status = 200; $fast->ttfbMs = 0; $fast->totalMs = 1;
$vf = VitalsCheck::analyse('https://x.fr/', '<html><body>x</body></html>', [], $fast, ['head' => false]);
check('0 ms est une mesure, pas une absence', $vf['ttfb_ms'], 0);
check('et le verdict est bon', $vf['ttfb_verdict'], 'good');
check('page sobre : rien à signaler', $v['level'], 'ok');

// --- Ce qui bloque le premier affichage ---------------------------------
$html = '<!doctype html><html><head>'
      . '<link rel="stylesheet" href="/a.css">'
      . '<link rel="stylesheet" href="/print.css" media="print">'
      . '<link rel="stylesheet" href="/mobile.css" media="screen and (max-width:600px)">'
      . '<script src="/bloque.js"></script>'
      . '<script src="/ok.js" defer></script>'
      . '<script src="/aussi-ok.js" async></script>'
      . '</head><body><script src="/pied.js"></script></body></html>';
$assets = ['assets' => [
    ['url' => 'https://x.fr/a.css', 'bytes' => 210000, 'ms' => 180],
    ['url' => 'https://x.fr/print.css', 'bytes' => 400, 'ms' => 10],
    ['url' => 'https://x.fr/mobile.css', 'bytes' => 5000, 'ms' => 20],
    ['url' => 'https://x.fr/bloque.js', 'bytes' => 90000, 'ms' => 100],
    ['url' => 'https://x.fr/ok.js', 'bytes' => 40000, 'ms' => 60],
]];
$rr = new Uptimer\Response(); $rr->ttfbMs = 200;
$v = VitalsCheck::analyse('https://x.fr/', $html, $assets, $rr, ['head' => false]);
check('media="print" ne bloque pas le rendu', $v['blocking']['css'], 2);
check('defer et async ne bloquent pas', $v['blocking']['js'], 1);
check('un script en fin de corps ne compte pas', $v['blocking']['js'], 1);
check('poids des feuilles compté à part du JavaScript', $v['blocking']['css_bytes'], 215000);
check('poids des scripts bloquants', $v['blocking']['js_bytes'], 90000);
$codes = array_column($v['findings'], 'code');
check('feuilles trop lourdes signalées', in_array('BLOCKING_CSS', $codes, true), true);
check('script bloquant signalé', in_array('BLOCKING_JS', $codes, true), true);

// --- L'image du haut de page -------------------------------------------
$lazy = VitalsCheck::lcpCandidate(
    '<html><body><img src="/pixel.gif" width="1" height="1">'
  . '<img src="/logo.svg" width="80" height="30">'
  . '<img src="/grande.jpg" loading="lazy" width="1400" height="800"></body></html>', 'https://x.fr/');
check('un pixel de suivi n\'est pas l\'image principale', str_contains((string)$lazy['url'], 'grande.jpg'), true);
check('chargement différé repéré', $lazy['lazy'], true);
$noImg = VitalsCheck::lcpCandidate('<html><body><p>texte</p></body></html>', 'https://x.fr/');
check('page sans image : aucune image affirmée', $noImg, null);
$dataUri = VitalsCheck::lcpCandidate('<html><body><img src="data:image/gif;base64,R0lGODdh"></body></html>', 'https://x.fr/');
check('image en ligne ignorée', $dataUri, null);

// --- Décalages de mise en page -----------------------------------------
$dim = VitalsCheck::imagesWithoutDimensions(
    '<img src="/a.jpg" width="10" height="10">'
  . '<img src="/b.jpg">'
  . '<img src="/c.jpg" style="aspect-ratio:16/9">'
  . '<img src="/d.jpg" width="10">'
  . '<img src="data:image/gif;base64,R0lGODdh">');
check('images sans dimensions comptées', $dim['count'], 2);
check('aspect-ratio compte comme une place réservée',
    in_array('c.jpg', $dim['samples'], true), false);

check('polices sans font-display comptées', VitalsCheck::fontsWithoutDisplay(
    '@font-face{font-family:A;src:url(a.woff2)}'
  . '@font-face{font-family:B;src:url(b.woff2);font-display:swap}'
  . '@font-face{font-family:C;src:url(c.woff2)}'), 2);
check('sans CSS, aucune police signalée', VitalsCheck::fontsWithoutDisplay(''), 0);

// --- Domaines tiers -----------------------------------------------------
$hosts = VitalsCheck::thirdPartyHosts(
    '<script src="https://www.googletagmanager.com/gtm.js"></script>'
  . '<script src="https://static.hotjar.com/h.js"></script>'
  . '<script src="/local.js"></script>'
  . '<script src="https://cdn.exemple.fr/interne.js"></script>', 'https://exemple.fr/');
check('un sous-domaine du site n\'est pas un tiers', in_array('cdn.exemple.fr', $hosts, true), false);
check('domaines tiers listés', count($hosts), 2);

// --- Classement des causes : la gravité d'abord ------------------------
$sev = array_column($v['findings'], 'severity');
$rank = ['high' => 0, 'medium' => 1, 'low' => 2];
$sorted = $sev;
usort($sorted, fn($a, $b) => $rank[$a] <=> $rank[$b]);
check('causes classées par impact', $sev, $sorted);

// =====================================================================
// Mesures de terrain : seuils, verdicts, lecture de la réponse
// =====================================================================
foreach ([['lcp', 2400, 'good'], ['lcp', 2500, 'good'], ['lcp', 3000, 'improve'],
          ['lcp', 4001, 'poor'], ['inp', 200, 'good'], ['inp', 300, 'improve'],
          ['inp', 900, 'poor'], ['cls', 0.05, 'good'], ['cls', 0.2, 'improve'],
          ['cls', 0.4, 'poor']] as [$metric, $value, $want]) {
    check('seuil ' . $metric . ' ' . $value, Vitals::rate($metric, (float)$value), $want);
}
check('métrique inconnue : aucun verdict', Vitals::rate('inventée', 1.0), 'unknown');

// Le pire des trois décide : c'est la règle de Google et la seule honnête.
check('un CLS catastrophique décide seul',
    Vitals::verdict(['lcp_ms' => 900, 'inp_ms' => 90, 'cls' => 0.5]), 'poor');
check('tout bon donne bon', Vitals::verdict(['lcp_ms' => 900, 'inp_ms' => 90, 'cls' => 0.02]), 'good');
check('un seul « à améliorer » suffit à retirer le bon',
    Vitals::verdict(['lcp_ms' => 3000, 'inp_ms' => 90, 'cls' => 0.02]), 'improve');
check('aucune valeur : aucun mauvais verdict', Vitals::verdict([]), 'good');

// Lecture d'une réponse du Chrome UX Report, sans réseau.
$crux = jenc(['record' => ['key' => ['url' => 'https://exemple.fr/'], 'metrics' => [
    'largest_contentful_paint' => ['percentiles' => ['p75' => 4820]],
    'interaction_to_next_paint' => ['percentiles' => ['p75' => 168]],
    'cumulative_layout_shift' => ['percentiles' => ['p75' => '0.19']],
    'experimental_time_to_first_byte' => ['percentiles' => ['p75' => 1100]],
]]]);
$p = Vitals::parse($crux);
check('LCP lu au p75', $p['lcp_ms'], 4820);
check('INP lu au p75', $p['inp_ms'], 168);
check('CLS lu même en chaîne de caractères', $p['cls'], 0.19);
check('TTFB de terrain lu', $p['ttfb_ms'], 1100);
check('verdict d\'ensemble sur le pire', $p['verdict'], 'poor');
// Une métrique absente reste absente : jamais un zéro, qui se lirait « parfait ».
$partiel = Vitals::parse(jenc(['record' => ['metrics' => [
    'largest_contentful_paint' => ['percentiles' => ['p75' => 1800]]]]]));
check('métrique absente reste nulle', $partiel['inp_ms'], null);
check('et le verdict ne porte que sur ce qui existe', $partiel['verdict'], 'good');
check('réponse vide : rien de lu', Vitals::parse('{}'), null);
check('réponse illisible : rien de lu', Vitals::parse('pas du json'), null);
check('réponse sans aucune métrique : rien de lu',
    Vitals::parse(jenc(['record' => ['metrics' => []]])), null);

// --- Sans clé, rien n'est ni demandé ni affiché -------------------------
Uptimer\Config::set('vitals.crux_key', '');
check('sans clé, la veille de terrain est inactive', Vitals::enabled(), false);
check('sans clé, aucune interrogation n\'est lancée', Vitals::fetch('https://exemple.fr/'), null);
check('sans clé, aucune passe d\'entretien', Vitals::refresh(5), ['checked' => 0, 'measured' => 0, 'poor' => 0]);
Uptimer\Config::set('vitals.crux_key', 'cle-de-test');
check('avec une clé, la veille devient active', Vitals::enabled(), true);
Uptimer\Config::set('vitals.enabled', false);
check('coupée dans les réglages, elle reste inactive malgré la clé', Vitals::enabled(), false);
Uptimer\Config::set('vitals.enabled', true);
Uptimer\Config::set('vitals.crux_key', '');

// --- Appareil de référence ---------------------------------------------
Uptimer\Config::set('vitals.form_factor', 'DESKTOP');
check('appareil de référence respecté', Vitals::formFactor(), 'DESKTOP');
Uptimer\Config::set('vitals.form_factor', 'n\'importe quoi');
check('appareil inconnu : téléphone par défaut', Vitals::formFactor(), 'PHONE');
Uptimer\Config::set('vitals.form_factor', 'PHONE');

// --- Mise en forme des valeurs -----------------------------------------
check('un temps long s\'écrit en secondes', Vitals::format('lcp', 4820.0), '4,8 s');
check('un temps court reste en millisecondes', Vitals::format('inp', 168.0), '168 ms');
check('le CLS est un nombre sans unité', Vitals::format('cls', 0.19), '0,19');
check('valeur absente : un tiret', Vitals::format('lcp', null), '—');

// --- La métrique la plus mauvaise, pour la liste de tâches -------------
[$wm, $wv] = Vitals::worstOf(['field_lcp_ms' => 1200, 'field_inp_ms' => 900, 'field_cls' => 0.02]);
check('la plus mauvaise métrique est retenue', $wm, 'inp');
check('avec sa valeur', $wv, 900.0);
[$wm2] = Vitals::worstOf([]);
check('aucune mesure : aucune métrique', $wm2, null);
check('libellé de métrique traduit', mb_strlen(Vitals::metricLabel('lcp')) > 5, true);

// =========================================================================
section('Mode agence : cloisonnement et révocation');
// =========================================================================
use Uptimer\Client;

// Un client, deux clients, et des sites qui n'appartiennent qu'à l'un d'eux.
$cA = Client::create('Agence Alpha', 'alpha@exemple.fr');
$cB = Client::create('Agence Beta');
check('deux clients distincts', $cA > 0 && $cB > 0 && $cA !== $cB, true);
$rowA = Uptimer\Db::one('SELECT * FROM clients WHERE id = ?', [$cA]);
$rowB = Uptimer\Db::one('SELECT * FROM clients WHERE id = ?', [$cB]);
check('jeton en 32 hexadécimaux', (bool)preg_match('~^[0-9a-f]{32}$~', (string)$rowA['token']), true);
check('deux jetons différents', $rowA['token'] !== $rowB['token'], true);
check('nom vide remplacé, pas refusé',
    trim((string)Uptimer\Db::val('SELECT name FROM clients WHERE id = ?', [Client::create('   ')])) !== '', true);

$sA1 = Uptimer\Db::insert('sites', ['name' => 'Alpha un', 'domain' => 'a1.test', 'created_at' => now()]);
$sA2 = Uptimer\Db::insert('sites', ['name' => 'Alpha deux', 'domain' => 'a2.test', 'created_at' => now()]);
$sB1 = Uptimer\Db::insert('sites', ['name' => 'Beta un', 'domain' => 'b1.test', 'created_at' => now()]);
check('rattachement de deux sites', Client::setSites($cA, [$sA1, $sA2]), 2);
Client::setSites($cB, [$sB1]);

// --- Le cloisonnement, testé sur les lectures elles-mêmes ---------------
$namesA = array_column(Client::sites($cA), 'name');
sort($namesA);
check('un client ne lit que ses sites', $namesA, ['Alpha deux', 'Alpha un']);
check('aucun site de l\'autre client dans la liste',
    in_array('Beta un', array_column(Client::sites($cA), 'name'), true), false);
check('les identifiants de sondes sont ceux du client', Client::monitorIds($cB), []);

// --- Un site n'appartient qu'à un client ---------------------------------
Client::setSites($cB, [$sB1, $sA1]);
check('rattacher ailleurs déplace, sans dupliquer',
    (int)Uptimer\Db::val('SELECT client_id FROM sites WHERE id = ?', [$sA1]), $cB);
check('l\'ancien client ne le voit plus',
    in_array('Alpha un', array_column(Client::sites($cA), 'name'), true), false);
Client::setSites($cB, [$sB1]);
Client::setSites($cA, [$sA1, $sA2]);

// --- Jetons : ce qui est accepté, ce qui ne l'est pas --------------------
check('jeton valide accepté', (int)(Client::byToken((string)$rowA['token'])['id'] ?? 0), $cA);
foreach (['', '   ', 'abc', str_repeat('z', 32), str_repeat('a', 20), str_repeat('a', 200),
          "' OR 1=1 --", '../../etc/passwd', strtoupper((string)$rowA['token'])] as $bad) {
    check('jeton refusé : ' . (trim($bad) === '' ? '(vide)' : str_cut($bad, 16)),
          Client::byToken($bad), null);
}
$old = (string)$rowA['token'];
$new = Client::rotate($cA);
check('changer le jeton coupe l\'ancien', Client::byToken($old), null);
check('le nouveau jeton ouvre le même client', (int)(Client::byToken($new)['id'] ?? 0), $cA);
check('changer le jeton ne détache aucun site', count(Client::sites($cA)), 2);

Uptimer\Db::update('clients', ['enabled' => 0], 'id = :__i', ['__i' => $cA]);
check('accès fermé : jeton valide mais refusé', Client::byToken($new), null);
Uptimer\Db::update('clients', ['enabled' => 1], 'id = :__i', ['__i' => $cA]);
check('accès réouvert avec le même jeton', (int)(Client::byToken($new)['id'] ?? 0), $cA);

// --- Consultation ---------------------------------------------------------
Client::touch($cA);
Client::touch($cA);
check('visites comptées', (int)Uptimer\Db::val('SELECT views FROM clients WHERE id = ?', [$cA]), 2);
check('dernière consultation datée',
    Uptimer\Db::val('SELECT last_seen_at FROM clients WHERE id = ?', [$cA]) !== null, true);

// --- Synthèse ------------------------------------------------------------
$ov = Client::overview($cA);
check('synthèse comptant les sites du client', $ov['sites'], 2);
check('sans sonde, aucun état affirmé', $ov['worst'], 'unknown');
check('client sans site : synthèse vide, pas d\'erreur', Client::overview(999999)['sites'], 0);

// --- Suppression : réversible, sans perte -------------------------------
Client::delete($cB);
check('client supprimé', Uptimer\Db::one('SELECT id FROM clients WHERE id = ?', [$cB]), null);
check('son site existe toujours',
    (string)Uptimer\Db::val('SELECT name FROM sites WHERE id = ?', [$sB1]), 'Beta un');
check('son site est simplement détaché',
    Uptimer\Db::val('SELECT client_id FROM sites WHERE id = ?', [$sB1]), null);

// --- Reprise des groupes -------------------------------------------------
Uptimer\Db::update('sites', ['group_name' => 'Mairie de Fréjus'], 'id = :__i', ['__i' => $sB1]);
$fg = Client::fromGroups();
check('un client créé depuis le groupe', $fg['created'], 1);
check('le site du groupe est rattaché', $fg['linked'], 1);
$fg2 = Client::fromGroups();
check('deuxième passage : rien de plus', $fg2['created'] + $fg2['linked'], 0);
check('aucun client en double',
    (int)Uptimer\Db::val('SELECT COUNT(*) FROM clients WHERE name = ?', ['Mairie de Fréjus']), 1);

// --- Destinataires hérités ----------------------------------------------
$siteA = Uptimer\Db::one('SELECT * FROM sites WHERE id = ?', [$sA1]);
check('adresse du client utilisée à défaut', Client::reportRecipients($siteA), 'alpha@exemple.fr');
$siteA['report_to'] = 'propre@exemple.fr';
check('adresse propre au site prioritaire',
    Client::reportRecipients($siteA), 'propre@exemple.fr');
check('site sans client : aucune adresse inventée',
    Client::reportRecipients(['report_to' => '', 'client_id' => null]), '');

// --- L'URL de l'espace ---------------------------------------------------
Uptimer\Config::set('app.base_url', 'https://suivi.agence.fr/');
$url = Client::url(Uptimer\Db::one('SELECT * FROM clients WHERE id = ?', [$cA]));
check('URL sans double barre oblique', substr_count($url, '//'), 1);
check('URL portant le jeton', str_contains($url, $new), true);

// Nettoyage : ces enregistrements ne doivent pas peser sur les tests suivants.
foreach (Uptimer\Db::all('SELECT id FROM clients') as $c) Client::delete((int)$c['id']);
Uptimer\Db::q('DELETE FROM sites WHERE domain IN (?, ?, ?)', ['a1.test', 'a2.test', 'b1.test']);

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
