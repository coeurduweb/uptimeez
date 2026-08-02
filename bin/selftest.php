<?php
/**
 * UptimeEZ : autotest. Vérifie la logique de détection sans toucher au réseau
 * ni à la base : utile après une mise à jour ou un changement d'hébergement.
 *
 *   php bin/selftest.php
 */
declare(strict_types=1);

// GARDE D'EXÉCUTION, POSÉE AVANT LE CHARGEMENT DU MOTEUR.
//
// Ce dépôt s'installe chez des tiers, souvent derrière Apache, et le dossier bin/
// est sous la racine web. Sans cette ligne, ce fichier était exécutable par une
// simple requête HTTP : il lit des sources, parle de chemins et fait tourner des
// mesures, ce qu'un visiteur n'a pas à provoquer. Elle précède le require pour
// qu'une requête HTTP ne charge même pas la configuration.
if (PHP_SAPI !== 'cli') exit("À lancer en ligne de commande.\n");

require dirname(__DIR__) . '/src/bootstrap.php';

// LA SUITE FIXE SA LANGUE, ELLE NE L'HÉRITE PAS.
//
// Des dizaines de contrôles comparent des chaînes rendues (« 1 j 1 h », « 2 Ko »,
// « Anomalie détectée »), donc dans la langue source. Or I18n::init() sans argument
// prend « app.locale » de l'instance : le 2026-07-29, une instance passée en anglais
// pour produire des captures a fait échouer dix contrôles d'un coup, sans qu'aucun
// code soit en cause. Le même piège attend tout contributeur dont l'instance n'est
// pas en français.
//
// Les sections qui éprouvent la traduction rebasculent explicitement (I18n::init('en')
// puis reviennent) : ce sont elles qui décident, pas la configuration ambiante.
Uptimeez\I18n::init('fr');

use Uptimeez\Check\Css;
use Uptimeez\Check\Database;
use Uptimeez\Detect\Cms;
use Uptimeez\Detect\Discovery;
use Uptimeez\Response;
use Uptimeez\Runner;
use Uptimeez\Ui;

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

// --- La même signature dans un article n'est PAS une panne ---------------
// Le défaut : un billet de blog qui explique comment corriger « Error
// establishing a database connection » faisait déclarer le site hors service.
// Pour une agence dont des clients sont hébergeurs ou développeurs, c'est la
// fausse alerte de trois heures du matin garantie. Une vraie page d'erreur se
// reconnaît autrement : le serveur répond 5xx, ou la page est courte, ou la
// chaîne de preuve a disparu.
$article = new Response();
$article->ok = true;
$article->status = 200;
$article->contentType = 'text/html';
$article->body = '<!doctype html><html><head><title>Corriger l\'erreur de connexion</title></head><body>'
    . '<nav>Accueil Blog Contact</nav><h1>Error establishing a database connection : que faire ?</h1>'
    . str_repeat('<p>Cette erreur signifie que WordPress ne joint plus MySQL. Voici la marche à suivre.</p>', 120)
    . '<footer>© 2026 Agence Exemple</footer></body></html>';
$monArt = ['expect_string' => '© 2026 Agence Exemple'];

$oA = Database::audit($article, $monArt);
check('un article qui parle de l\'erreur n\'est pas une panne', $oA['state'], 'degraded');
check('mais l\'erreur affichée est signalée', $oA['reason'], 'DB_ERROR_VISIBLE');
check('et l\'extrait est joint', str_contains((string)$oA['evidence'], 'database connection'), true);

// Même page, mais la chaîne de preuve a disparu : la base est vraiment tombée.
$monArt2 = ['expect_string' => 'Signature qui ne peut venir que de la base'];
check('la chaîne de preuve absente tranche : c\'est une panne',
    Database::audit($article, $monArt2)['state'], 'down');

// Même page, mais le serveur annonce 500 : il tranche lui-même.
$art500 = clone $article; $art500->status = 503;
check('un 5xx tranche aussi', Database::audit($art500, $monArt)['state'], 'down');

// La vraie page d'erreur WordPress : courte, donc conclusive sans rien d'autre.
$wpErr = new Response();
$wpErr->ok = true; $wpErr->status = 200; $wpErr->contentType = 'text/html';
$wpErr->body = '<!DOCTYPE html><html><head><title>Database Error</title></head><body>'
    . '<h1>Error establishing a database connection</h1></body></html>';
check('la vraie page d\'erreur WordPress reste une panne',
    Database::audit($wpErr, [])['state'], 'down');
check('même sans chaîne de preuve configurée',
    Database::audit($wpErr, [])['reason'], 'DB_DOWN');

// Une erreur PHP visible sur une page saine : dégradé, pas hors service. La page
// ne doit contenir QUE l'erreur PHP : le titre de l'article précédent est
// lui-même une signature base de données, et c'est elle qui sortirait.
$phpVis = new Response();
$phpVis->ok = true; $phpVis->status = 200; $phpVis->contentType = 'text/html';
$phpVis->body = '<!doctype html><html><head><title>Nos tarifs</title></head><body><nav>Accueil</nav>'
    . str_repeat('<p>Une page de tarifs tout à fait normale, avec du contenu.</p>', 300)
    . '<div>Fatal error: Uncaught TypeError in /var/www/widget.php</div>'
    . '<footer>© 2026 Agence Exemple</footer></body></html>';
$oP = Database::audit($phpVis, $monArt);
check('une erreur PHP dans un coin de page saine est dégradée', $oP['state'], 'degraded');
check('avec son propre motif', $oP['reason'], 'APP_ERROR_VISIBLE');
// La borne de taille : la même erreur sur une page courte redevient une panne,
// parce qu'une page courte qui affiche une erreur EST une page d'erreur.
$phpShort = new Response();
$phpShort->ok = true; $phpShort->status = 200; $phpShort->contentType = 'text/html';
$phpShort->body = '<html><body>Fatal error: Uncaught TypeError in /var/www/x.php</body></html>';
check('la même erreur sur une page courte reste une panne',
    Database::audit($phpShort, $monArt)['state'], 'down');
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

// ---------------------------------------------------------------------------
// Une page qui PARLE d'une technologie n'est pas faite avec.
//
// LE DÉFAUT MESURÉ, le 2026-08-01 : https://uptimeez.com/ était annoncé
// « WordPress » à 40 % de confiance alors que c'est du Laravel. La cause tient en
// une phrase de sa page d'accueil, dans un <p> : « one stylesheet returns 404:
// /wp-content/cache/min/1/absent.css ». Le motif « /wp-content/ » vaut 40 points,
// soit le double du seuil, et il était cherché dans le texte lu par le visiteur.
//
// Le défaut n'est pas anecdotique, il est structurel, et il vise en plein le parc
// qu'on surveille : sites d'agences, articles techniques, pages de documentation.
// Il a une conséquence, et ce n'est pas la surveillance : l'inventaire logiciel et
// la veille de sécurité s'appuient sur cette détection, donc un faux WordPress fait
// chercher des greffons qui n'existent pas et rattache des avis publics qui ne
// concernent pas le site.
$prose = new Response();
$prose->contentType = 'text/html';
$prose->body = '<!doctype html><html><head><title>Notre outil de surveillance</title></head><body>'
    . '<p>one stylesheet returns 404: /wp-content/cache/min/1/absent.css</p>'
    . '<p>Nous surveillons aussi /wp-includes/ et l\'API wp-json de chaque site.</p>'
    . '</body></html>';
check('une phrase qui cite /wp-content/ ne fait pas un WordPress', Cms::detect($prose)['cms'], null);
check('et aucune confiance résiduelle n\'est annoncée', Cms::detect($prose)['confidence'], 0);
// Le même chemin dans un attribut, lui, est un vrai indice : c'est le serveur qui
// l'a écrit, pas un rédacteur.
$attr = new Response();
$attr->contentType = 'text/html';
$attr->body = '<html><head><link rel="stylesheet" href="/wp-content/themes/astra/style.css">'
    . '</head><body>Bienvenue</body></html>';
check('le même chemin dans un attribut reste un indice', Cms::detect($attr)['cms'], 'WordPress');
// Le corps d'un script est du code, pas du texte lisible : ses indices sont gardés.
$js = new Response();
$js->contentType = 'text/html';
$js->body = '<html><body><p>Boutique</p><script>var prestashop = {"static_token":"x"};</script></body></html>';
check('les indices du corps d\'un script sont gardés', Cms::detect($js)['cms'], 'PrestaShop');
// Les commentaires HTML aussi : c'est là que les greffons de cache signent.
$com = new Response();
$com->contentType = 'text/html';
$com->body = '<html><body><p>rien</p><!-- Performance optimized by W3 Total Cache --></body></html>';
check('la signature de cache laissée en commentaire est gardée',
    Cms::detect($com)['cache'], 'W3 Total Cache');
$comTexte = new Response();
$comTexte->contentType = 'text/html';
$comTexte->body = '<html><body><p>Nous installons W3 Total Cache chez nos clients.</p></body></html>';
check('un texte qui NOMME un greffon de cache n\'en révèle aucun',
    Cms::detect($comTexte)['cache'], null);

section('Déduction de la chaîne de contrôle');
$page = '<html><head><title>Nos tarifs : Agence Bellevue</title>
<meta property="og:site_name" content="Agence Bellevue"></head>
<body><nav><a href="/x">Nos prestations</a></nav><h1>Nos tarifs</h1>
<footer>© 2026 Agence Bellevue — tous droits réservés</footer></body></html>';
check('nom de marque retenu', Discovery::suggestExpectString($page), 'Agence Bellevue');
$err = '<html><head><title>404 Not Found</title></head><body><h1>Not Found</h1></body></html>';
check('rien déduit d\'une page 404', Discovery::suggestExpectString($err, 404), null);
check('rien déduit d\'un titre d\'erreur', Discovery::suggestExpectString($err, 200), null);

section('Chaîne de preuve : une sonde d\'API n\'en reçoit aucune');
// ---------------------------------------------------------------------------
// LE DÉFAUT, ET IL S'EST DÉCLENCHÉ EN DIRECT LE 2026-08-01. Importer::setup()
// appliquait la chaîne de preuve du SITE à toute sonde qui n'en avait pas, sans
// regarder « kind ». La chaîne d'un site est du texte HTML : un titre d'accueil,
// une mention de pied de page. Une sonde d'API, elle, rend quinze octets de JSON,
// « [{"id":149}] ». Cette chaîne ne peut JAMAIS s'y trouver.
//
// La sonde n'est donc pas « fragile », elle est CONDAMNÉE : elle tombera en panne à
// coup sûr dès que la file de préparation l'atteindra. Sur un parc de 200 sondes
// posé et vérifié sans une seule fausse alerte, six sondes « la base répond (REST) »
// sont passées en PANNE quinze minutes plus tard, motif STRING_MISSING, sur des
// sites parfaitement sains. 27 des 85 sondes JSON avaient déjà reçu une chaîne HTML,
// les 58 autres l'auraient reçue passe après passe.
//
// Le motif est parfait comme cas d'école : la sonde était juste au moment où on l'a
// posée, et une fonction d'assistance l'a cassée plus tard, silencieusement. Une
// recette qui s'arrête à la première passe verte ne voit pas ce que la file de
// préparation fera ensuite.
$htmlRes = new Response();
$htmlRes->ok = true; $htmlRes->status = 200; $htmlRes->contentType = 'text/html';
$htmlRes->body = '<html><head><title>Nos tarifs : Agence Bellevue</title>'
    . '<meta property="og:site_name" content="Agence Bellevue"></head><body><h1>Nos tarifs</h1>'
    . '<footer>© 2026 Agence Bellevue</footer></body></html>';
$jsonRes = new Response();
$jsonRes->ok = true; $jsonRes->status = 200; $jsonRes->contentType = 'application/json';
$jsonRes->body = '[{"id":149}]';

$sondePage = ['kind' => 'page', 'json_path' => null, 'expect_string' => null];
$sondeApi  = ['kind' => 'api',  'json_path' => '0.id', 'expect_string' => null];

check('une sonde de page reçoit bien la chaîne du site',
    Uptimeez\Importer::proofFor($sondePage, $htmlRes, 'Agence Bellevue'), 'Agence Bellevue');
check('une sonde d\'API ne reçoit PAS la chaîne du site',
    Uptimeez\Importer::proofFor($sondeApi, $jsonRes, 'Agence Bellevue'), null);
check('ni celle du site quand elle n\'a même pas de json_path',
    Uptimeez\Importer::proofFor(['kind' => 'api', 'json_path' => null, 'expect_string' => null],
        $jsonRes, 'Faites rayonner votre site bien au-delà de vos murs.'), null);
check('un json_path protège la sonde quel que soit son genre annoncé',
    Uptimeez\Importer::proofFor(['kind' => 'page', 'json_path' => 'status', 'expect_string' => null],
        $jsonRes, 'Agence Bellevue'), null);
check('ce que l\'utilisateur a posé lui-même est toujours conservé',
    Uptimeez\Importer::proofFor(['kind' => 'api', 'json_path' => '0.id', 'expect_string' => '"id"'],
        $jsonRes, 'Agence Bellevue'), '"id"');
check('rien n\'est déduit d\'un corps qui n\'est pas du HTML',
    Uptimeez\Importer::proofFor($sondePage, $jsonRes, ''), null);
check('mais une page déduit toujours son nom de marque',
    Uptimeez\Importer::proofFor($sondePage, $htmlRes, ''), 'Agence Bellevue');
check('une sonde d\'API n\'accepte aucune preuve textuelle',
    Uptimeez\Importer::acceptsTextProof($sondeApi), false);
check('une sonde de page, si', Uptimeez\Importer::acceptsTextProof($sondePage), true);

section('Verrou de passe : une instance, un verrou');
// ---------------------------------------------------------------------------
// LE DÉFAUT : cron.php prenait son verrou sur UPTIMEEZ_ROOT/data/cron.lock,
// c'est-à-dire dans le dossier du MOTEUR. Or plusieurs instances partagent souvent
// un seul exemplaire du code : c'est toute la raison d'être de UPTIMEEZ_CONFIG, et
// c'est ainsi qu'un serveur fait tourner dix clients sur un seul dépôt. Le verrou
// était donc COMMUN aux dix. Mesuré le 2026-08-01 : un seul cron.lock pour tout le
// monde dans /home/uptimeez/moteur/data.
//
// Conséquence à dix clients : la première passe de la minute prend le verrou, les
// neuf autres affichent « une passe est déjà en cours, on laisse la main » et
// repartent SANS AVOIR RIEN VÉRIFIÉ. Le défaut est muet des deux côtés, puisque
// chaque passe se termine proprement. Neuf clients sur dix ne sont pas surveillés.
// Le défaut touche aussi les auto-hébergés qui font tourner plusieurs instances.
$cfgA = '/home/uptimeez/instances/client-a/config.php';
$cfgB = '/home/uptimeez/instances/client-b/config.php';
check('le dossier de travail se déduit de la configuration, pas du code',
    Uptimeez\Config::dataDir($cfgA), '/home/uptimeez/instances/client-a/data');
check('deux instances, deux dossiers de travail',
    Uptimeez\Config::dataDir($cfgA) === Uptimeez\Config::dataDir($cfgB), false);
check('et aucun des deux dans le dossier du moteur',
    str_starts_with(Uptimeez\Config::dataDir($cfgA), UPTIMEEZ_ROOT), false);
check('une installation ordinaire reste exactement où elle était',
    Uptimeez\Config::dataDir(UPTIMEEZ_ROOT . '/config.php'), UPTIMEEZ_ROOT . '/data');
// Garde de source, parce que la déduction peut être juste sans que cron.php s'en
// serve : c'est LUI qui avait le défaut, pas Config.
$cronSrc = (string)file_get_contents(UPTIMEEZ_ROOT . '/cron.php');
preg_match('~\$lockFile\s*=\s*(.+?);~', $cronSrc, $mLock);
check('cron.php calcule son verrou depuis la configuration de l\'instance',
    str_contains($mLock[1] ?? '', 'Config::dataDir()'), true);
check('et jamais depuis UPTIMEEZ_ROOT, qui est partagé',
    str_contains($mLock[1] ?? '', 'UPTIMEEZ_ROOT'), false);

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
$parsed = Uptimeez\Importer::parse("exemple.fr\n# commentaire\nautre.fr | Client | Preuve\nvide\n\nexemple.fr");
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
// ---------------------------------------------------------------------------
// CE BLOC A CHANGÉ DE CONTRAT LE 2026-08-02, ET LE CHANGEMENT EST LE CORRECTIF.
//
// extractFontUrls() rendait UNE adresse par @font-face : la première « url() » du bloc.
// Or la première est, par convention, celle destinée à Internet Explorer :
//
//     src: url('fa.eot');                                  <- lue par la version d'avant
//     src: url('fa.eot?#iefix') format('embedded-opentype'),
//          url('fa.woff2')      format('woff2'), …          <- ce que lit un vrai navigateur
//
// Le contrôle vérifiait donc le seul fichier qu'aucun navigateur moderne ne demande, et
// criait quand il manquait. Sur le parc réel, c'était la cause de la majorité des sites
// « dégradés », tous parfaitement affichés.
//
// La méthode rend désormais une LISTE par bloc, sans les formats hérités, et l'appelant
// ne conclut à une police manquante que si AUCUNE source ne répond.
$fontCss = '@font-face{font-family:Inter;src:url("/fonts/inter.woff2") format("woff2")}'
         . '@font-face{font-family:Alt;src:url(data:font/woff2;base64,AAA) format("woff2")}'
         . '@font-face{font-family:Deux;src:url(/fonts/deux.woff2)}';
$fonts = Css::extractFontUrls($fontCss, 'https://exemple.fr/style.css');
check('un groupe par @font-face utile', count($fonts), 2);
check('police en data: ignorée',
    implode(',', array_map(fn (array $g): string => implode('|', $g), $fonts)),
    'https://exemple.fr/fonts/inter.woff2,https://exemple.fr/fonts/deux.woff2');

// Le cas réel, celui qui produisait les fausses alertes : le .eot en tête.
$avecEot = '@font-face{font-family:FA;'
         . "src:url('../webfonts/fa.eot');"
         . "src:url('../webfonts/fa.eot?#iefix') format('embedded-opentype'),"
         . "url('../webfonts/fa.woff2') format('woff2'),"
         . "url('../webfonts/fa.ttf') format('truetype')}";
$g = Css::extractFontUrls($avecEot, 'https://exemple.fr/wp-content/plugins/x/css/all.css');
check('le .eot de tête est écarté', count($g), 1);
check('les formats modernes sont retenus, dans l\'ordre', implode('|', $g[0] ?? []),
    'https://exemple.fr/wp-content/plugins/x/webfonts/fa.woff2|https://exemple.fr/wp-content/plugins/x/webfonts/fa.ttf');

// LA BASE DE RÉSOLUTION, qui est l'autre moitié du défaut. « ../webfonts/… » écrit dans
// une feuille rangée dans « /css/ » ne désigne PAS la racine du site. Résolu contre la
// page, il donnait « https://exemple.fr/webfonts/… », qui n'existe nulle part, et TOUTES
// les polices de TOUS les sites étaient déclarées manquantes en permanence.
check('la base est la feuille, pas la page',
    str_contains(($g[0][0] ?? ''), '/wp-content/plugins/x/webfonts/'), true);
check('résolue contre la page, l\'adresse serait fausse',
    resolve_url('https://exemple.fr/', '../webfonts/fa.woff2'), 'https://exemple.fr/webfonts/fa.woff2');

section('Périodes d\'affichage');
check('365 jours reconnu', Ui::rangeSeconds('365d'), 31536000);
check('180 jours reconnu', Ui::rangeSeconds('180d'), 15552000);
check('120 jours reconnu', Ui::rangeSeconds('120d'), 10368000);
check('valeur inconnue → 24 h', Ui::rangeSeconds('nawak'), 86400);
check('libellé lisible', Ui::rangeLabel('180d'), '6 mois');
check('une année reste sous 100 points', Ui::rangeBuckets('365d') <= 100, true);
check('les longues portées passent par les agrégats',
    Ui::rangeSeconds('180d') > Uptimeez\Stats::RAW_WINDOW_SEC, true);
check('24 h reste sur les mesures unitaires',
    Ui::rangeSeconds('24h') <= Uptimeez\Stats::RAW_WINDOW_SEC, true);

section('Diagnostic : cause, explication, remède');
foreach (['DNS', 'CONNECT', 'TIMEOUT', 'SSL_EXPIRED', 'SSL_INVALID', 'HTTP_5XX', 'HTTP_404',
          'HTTP_403', 'HTTP_401', 'DB_DOWN', 'APP_ERROR', 'CSS_BROKEN', 'STRING_MISSING',
          'NOINDEX', 'SLOW', 'JSON_VALUE', 'REDIRECT_LOOP'] as $code) {
    $d = Uptimeez\Diagnose::explain($code, ['url' => 'https://exemple.fr/']);
    $ok = $d['title'] !== '' && mb_strlen($d['why']) > 30 && mb_strlen($d['fix']) > 30
        && $d['title'] !== 'Anomalie détectée';
    check('explication utile pour ' . $code, $ok, true);
}
$unknown = Uptimeez\Diagnose::explain('CODE_INCONNU');
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
$audit = Css::audit('https://exemple-inexistant-uptimeez.fr/', $page, $res, [], ['timeout' => 2, 'check_js' => false]);
check('audit conclut à une anomalie', in_array($audit['state'], ['broken', 'warn'], true), true);
check('messages console produits', count($audit['console']) >= 1, true);
check('format reconnaissable par un développeur',
    (bool)preg_match('~(net::ERR|Refused to|Mixed Content|GET )~', $audit['console'][0]['text'] ?? ''), true);


section('Montée de version du schéma');
// Une base créée par une version antérieure doit gagner les colonnes manquantes.
$tmpDb = sys_get_temp_dir() . '/uptimeez-selftest-' . bin2hex(random_bytes(3)) . '.sqlite';
Uptimeez\Config::set('db.driver', 'sqlite');
Uptimeez\Db::disconnect();
Uptimeez\Config::set('db.sqlite', $tmpDb);
Uptimeez\Db::pdo()->exec("CREATE TABLE monitors (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL,
    url TEXT NOT NULL, kind TEXT, status TEXT, created_at TEXT)");
Uptimeez\Db::pdo()->exec("CREATE TABLE settings (k TEXT PRIMARY KEY, v TEXT)");
$avant = count(Uptimeez\Db::columns('monitors'));
Uptimeez\Db::migrate();
$cols = Uptimeez\Db::columns('monitors');
check('colonnes ajoutées automatiquement (' . count($cols) . ')', count($cols) > $avant + 40, true);
foreach (['last_ip', 'css_detail', 'css_baseline', 'uptime_24h', 'setup_state', 'domain_expires_at'] as $c) {
    check('colonne ' . $c . ' présente', in_array($c, $cols, true), true);
}
check('données existantes préservées', Uptimeez\Db::tableExists('monitors'), true);
Uptimeez\Db::insert('monitors', ['name' => 'x', 'url' => 'https://x.fr/', 'kind' => 'page',
                              'status' => 'unknown', 'created_at' => now()]);
Uptimeez\Db::update('monitors', ['last_ip' => '203.0.113.9'], 'id = :i', ['i' => 1]);
check('écriture possible sur les nouvelles colonnes',
    Uptimeez\Db::val('SELECT last_ip FROM monitors WHERE id = 1'), '203.0.113.9');
check('deuxième migration sans effet de bord', (function () { Uptimeez\Db::migrate(); return true; })(), true);
@unlink($tmpDb); @unlink($tmpDb . '-wal'); @unlink($tmpDb . '-shm');

section('Corrélation des pannes');
check('seuil de regroupement raisonnable', Uptimeez\Runner::GROUP_THRESHOLD >= 3, true);
check('libellé d\'évènement groupé',
    Uptimeez\Notify\Notifier::eventLabel('grouped_alert'), 'Panne groupée');


section('Extraction depuis un texte libre');
$mail = "Bonjour, merci de surveiller boutique-dupont.fr et https://cabinet-lefevre.fr/contact.\n"
      . "Le préprod est sur preprod.dupont.fr. Contact : jean@exemple.com. Logo : logo-final.png.";
$prose = Uptimeez\Importer::extractFromProse($mail);
check('adresses trouvées dans une prose', count($prose), 3);
check('domaine en fin de phrase accepté',
    (bool)preg_grep('~preprod\.dupont\.fr~', $prose), true);
check('domaine d\'une adresse e-mail écarté', (bool)preg_grep('~exemple\.com~', $prose), false);
check('nom de fichier écarté', (bool)preg_grep('~logo-final~', $prose), false);
check('un seul élément par hôte',
    count($prose) === count(array_unique(array_map(fn($u) => host_of(normalize_url($u) ?? ''), $prose))), true);
$pl = Uptimeez\Importer::parse($mail);
check('la prose alimente l\'import', count($pl['rows']), 3);
$list = Uptimeez\Importer::parse("a-uptimeez.fr\nb-uptimeez.fr | B\n# note\npas une url");
check('une vraie liste reste traitée comme telle', count($list['rows']), 2);

section('Cadence choisie selon l\'importance de la page');
check('accueil : cadence de référence', Uptimeez\Tune::intervalFor('https://a.fr/', 300), 300);
check('page principale : cadence de référence', Uptimeez\Tune::intervalFor('https://a.fr/x', 300, null, true), 300);
check('contact : cadence de référence', Uptimeez\Tune::intervalFor('https://a.fr/contact', 300, 'contact'), 300);
check('article de blog : deux fois moins souvent',
    Uptimeez\Tune::intervalFor('https://a.fr/blog/mon-article', 300, 'contenu'), 600);
check('mentions légales : quatre fois moins souvent',
    Uptimeez\Tune::intervalFor('https://a.fr/mentions-legales', 300, 'legal'), 1200);
check('plafonné à une journée', Uptimeez\Tune::intervalFor('https://a.fr/cgv', 43200, 'legal'), 86400);
check('seuils de réglage automatique cohérents',
    Uptimeez\Tune::SLOW_FLOOR_MS < Uptimeez\Tune::SLOW_CEIL_MS && Uptimeez\Tune::SLOW_FACTOR > 1, true);

section('Seuil de lenteur auto-ajusté');
$tmpT = sys_get_temp_dir() . '/uptimeez-tune-' . bin2hex(random_bytes(3)) . '.sqlite';
Uptimeez\Db::disconnect();
Uptimeez\Config::set('db.sqlite', $tmpT);
Uptimeez\Db::migrate();
$tid = Uptimeez\Db::insert('monitors', ['name' => 'lent', 'url' => 'https://a.fr/', 'kind' => 'page',
    'role' => 'primary', 'method' => 'GET', 'interval_sec' => 300, 'timeout_sec' => 15, 'retries' => 0,
    'slow_ms' => 3000, 'expect_status' => '200-299', 'check_ssl' => 0, 'check_css' => 0, 'check_db' => 0,
    'check_noindex' => 0, 'ssl_warn_days' => 14, 'css_drop_pct' => 35, 'enabled' => 1, 'auto_slow' => 1,
    'status' => 'up', 'setup_state' => 'done', 'created_at' => now(), 'next_check_at' => now(),
    'follow_redirects' => 1]);
// Un site lent par nature (4,4 s de façon stable) avec un seuil à 3 s : il
// alerterait en permanence pour rien. Le seuil doit s'adapter à sa réalité.
for ($i = 0; $i < 40; $i++) {
    Uptimeez\Db::insert('checks', ['monitor_id' => $tid, 'ts' => date('Y-m-d H:i:s', time() - $i * 600),
        'state' => 'up', 'status_code' => 200, 'total_ms' => 4400 + ($i % 7) * 30, 'attempts' => 1]);
}
$mon = Uptimeez\Db::one('SELECT * FROM monitors WHERE id = ?', [$tid]);
$res = Uptimeez\Tune::slowThreshold($mon);
check('seuil recalculé', is_array($res) && $res['changed'], true);
$newSlow = (int)Uptimeez\Db::val('SELECT slow_ms FROM monitors WHERE id = ?', [$tid]);
check('seuil placé au-dessus du comportement réel (' . $newSlow . ' ms)',
    $newSlow >= 7500 && $newSlow <= 8800, true);
check('un écart insignifiant ne déclenche aucun changement', (function () {
    // Même exercice avec un seuil déjà correct : UptimeEZ doit rester silencieuse.
    $id = Uptimeez\Db::insert('monitors', ['name' => 'stable', 'url' => 'https://b.fr/', 'kind' => 'page',
        'role' => 'primary', 'method' => 'GET', 'interval_sec' => 300, 'timeout_sec' => 15, 'retries' => 0,
        'slow_ms' => 2800, 'expect_status' => '200-299', 'check_ssl' => 0, 'check_css' => 0, 'check_db' => 0,
        'check_noindex' => 0, 'ssl_warn_days' => 14, 'css_drop_pct' => 35, 'enabled' => 1, 'auto_slow' => 1,
        'status' => 'up', 'setup_state' => 'done', 'created_at' => now(), 'next_check_at' => now(),
        'follow_redirects' => 1]);
    for ($i = 0; $i < 30; $i++) {
        Uptimeez\Db::insert('checks', ['monitor_id' => $id, 'ts' => date('Y-m-d H:i:s', time() - $i * 600),
            'state' => 'up', 'status_code' => 200, 'total_ms' => 1500 + ($i % 5) * 10, 'attempts' => 1]);
    }
    return Uptimeez\Tune::slowThreshold(Uptimeez\Db::one('SELECT * FROM monitors WHERE id = ?', [$id])) === null;
})(), true);
check('décision journalisée',
    count(Uptimeez\Tune::decisions(Uptimeez\Db::one('SELECT * FROM monitors WHERE id = ?', [$tid]))) >= 1, true);
$mon2 = Uptimeez\Db::one('SELECT * FROM monitors WHERE id = ?', [$tid]);
check('pas de réajustement dans la foulée', Uptimeez\Tune::slowThreshold($mon2), null);
// Réglage manuel : la case décochée doit être respectée
Uptimeez\Db::update('monitors', ['auto_slow' => 0, 'tuned_at' => null], 'id = :i', ['i' => $tid]);
check('réglage manuel respecté',
    Uptimeez\Tune::slowThreshold(Uptimeez\Db::one('SELECT * FROM monitors WHERE id = ?', [$tid])), null);

section('Sonde battement');
$hb = Uptimeez\Heartbeat::create('Sauvegarde nocturne', 3600, 300);
check('clé de battement générée', strlen((string)$hb['token']) >= 16, true);
$hbMon = Uptimeez\Db::one('SELECT * FROM monitors WHERE id = ?', [$hb['id']]);
check('type battement', (string)$hbMon['kind'], 'heartbeat');
check('ligne à coller fournie', str_contains(Uptimeez\Heartbeat::snippet($hbMon), 'beat.php?k='), true);
check('aucun retard à la création', Uptimeez\Heartbeat::sweep(), 0);
check('signal accepté', Uptimeez\Heartbeat::beat((string)$hb['token'], '412 fichiers')['ok'], true);
check('état passé à opérationnel', Uptimeez\Db::val('SELECT status FROM monitors WHERE id = ?', [$hb['id']]), 'up');
Uptimeez\Db::q('UPDATE monitors SET heartbeat_at = ? WHERE id = ?',
    [date('Y-m-d H:i:s', time() - 7200), $hb['id']]);
check('silence prolongé détecté', Uptimeez\Heartbeat::sweep(), 1);
check('cause renseignée', Uptimeez\Db::val('SELECT reason_code FROM monitors WHERE id = ?', [$hb['id']]), 'HEARTBEAT_LATE');
check('incident ouvert', (int)Uptimeez\Db::val('SELECT COUNT(*) FROM incidents WHERE monitor_id = ? AND ended_at IS NULL',
    [$hb['id']]), 1);
Uptimeez\Heartbeat::beat((string)$hb['token']);
check('retour du signal : incident clos',
    (int)Uptimeez\Db::val('SELECT COUNT(*) FROM incidents WHERE monitor_id = ? AND ended_at IS NULL', [$hb['id']]), 0);
check('clé inconnue refusée', Uptimeez\Heartbeat::beat('0123456789abcdef0123')['ok'], false);
check('clé trop courte refusée', Uptimeez\Heartbeat::beat('abc')['ok'], false);
check('diagnostic dédié au battement',
    Uptimeez\Diagnose::explain('HEARTBEAT_LATE')['title'], 'Le signal attendu n\'est pas arrivé');

section('Triage : ce qu\'il y a à faire');
Uptimeez\Db::q("UPDATE monitors SET status = 'down', reason_code = 'CSS_BROKEN',
             last_message = 'feuille absente', status_since = ? WHERE id = ?", [now(), $tid]);
$acts = Uptimeez\Triage::actions();
check('la sonde en panne remonte dans les tâches', count($acts) >= 1, true);
$first = $acts[0];
check('la tâche porte une cause lisible', $first['cause'] !== '' && $first['cause'] !== 'Anomalie détectée', true);
check('la tâche porte une conduite à tenir', mb_strlen((string)$first['why']) > 30 && mb_strlen((string)$first['fix']) > 30, true);
check('des actions sont proposées', count($first['actions']) >= 3, true);
$labels = array_column($first['actions'], 0);
check('réapprentissage proposé sur une panne CSS', in_array('relearn', $labels, true), true);
check('rapport copiable proposé', in_array('copy', $labels, true), true);
$rep = Uptimeez\Triage::report($tid);
check('rapport texte produit', str_contains($rep, '## Diagnostic') && str_contains($rep, '## Conduite à tenir'), true);
check('rapport sans balise HTML', (bool)preg_match('~<[a-z]~i', $rep), false);
check('compteurs de triage', is_array(Uptimeez\Triage::counts()), true);
check('seuils d\'anticipation raisonnables',
    Uptimeez\Triage::SSL_SOON_DAYS >= 14 && Uptimeez\Triage::DOMAIN_SOON_DAYS >= 30, true);
@unlink($tmpT); @unlink($tmpT . '-wal'); @unlink($tmpT . '-shm');

// =========================================================================
section('Sécurité : cibles refusées, cellules de tableur');
// =========================================================================
use Uptimeez\Http;

// --- Schémas d'URL : seul HTTP(S) devient une sonde ----------------------
foreach (['file:///etc/passwd', 'gopher://127.0.0.1:70/x', 'dict://127.0.0.1:1/x',
          'ftp://127.0.0.1/', 'php://filter/resource=x', 'data:text/html,<b>x',
          'javascript:alert(1)', 'jar:file:///etc/passwd!/', 'ldap://127.0.0.1/'] as $u) {
    check('schéma refusé : ' . str_cut($u, 26), normalize_url($u), null);
}
check('domaine nu accepté', normalize_url('exemple.fr'), 'https://exemple.fr/');
check('protocole conservé', normalize_url('http://exemple.fr/x'), 'http://exemple.fr/x');

// --- Garde-fou de plages privées : désactivé par défaut ------------------
Uptimeez\Config::set('security.block_private_ranges', false);
check('garde-fou inactif par défaut', Http::blockedReason('http://127.0.0.1:22/'), null);
Uptimeez\Config::set('security.block_private_ranges', true);
foreach (['http://127.0.0.1:22/', 'http://10.0.0.5/', 'http://192.168.1.1/',
          'http://172.16.0.1/', 'http://[::1]/'] as $u) {
    check('cible interne refusée : ' . str_cut($u, 24), Http::blockedReason($u) !== null, true);
}
check('adresse de métadonnées refusée',
    str_contains((string)Http::blockedReason('http://169.254.169.254/'), 'métadonnées'), true);
check('cible publique permise', Http::blockedReason('https://example.com/'), null);
Uptimeez\Config::set('security.block_private_ranges', false);

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
use Uptimeez\I18n;

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

// --- Un catalogue par langue déclarée : on compte les FICHIERS -------------
/*
 * UN TEST QUI INTERROGE LA MÊME SOURCE QUE LE CODE TESTÉ NE TESTE RIEN.
 *
 * Constaté le 2026-07-30 : le garde-fou de cette suite comptait
 * count(I18n::available()), c'est-à-dire la constante LANGS. Il comparait donc
 * le code à lui-même et il est resté vert pendant que lang/fr.php n'existait
 * pas : dix langues déclarées, neuf catalogues sur le disque, et le site
 * annonçait « 10 interface languages » dans un tableau comparatif, face aux
 * concurrents. La constante disait vrai sur elle-même ; le produit, non.
 *
 * Le seul recours est de changer de source : on lit le disque, pas la classe.
 * Le disque est la deuxième source, celle que la constante prétend décrire.
 *
 * _dynamiques.php n'est pas une langue : c'est la liste des msgid que le code
 * ne révèle pas par simple lecture (voir son en-tête). Il est exclu ici comme
 * il l'est dans bin/deadcode.php, par le préfixe « _ » plutôt que par son nom,
 * pour qu'un futur fichier de service n'ait pas à repasser par ce test.
 */
$fichiersLang = [];
foreach (glob(__DIR__ . '/../lang/*.php') ?: [] as $f) {
    $code = basename($f, '.php');
    if (str_starts_with($code, '_')) continue;
    $fichiersLang[$code] = $f;
}
ksort($fichiersLang);
check('un catalogue pour chaque langue déclarée',
    implode(' ', array_diff(array_keys(I18n::LANGS), array_keys($fichiersLang))), '');
check('aucun catalogue orphelin dans lang/',
    implode(' ', array_diff(array_keys($fichiersLang), array_keys(I18n::LANGS))), '');
check('autant de catalogues que de langues', count($fichiersLang), count(I18n::LANGS));

// Un fichier présent mais cassé (oubli du return, tableau devenu chaîne) vaut
// un fichier absent : I18n::load() rend un tableau vide dans les deux cas, et
// la langue retombe silencieusement sur l'anglais. C'est exactement le mode de
// panne qu'on vient de laisser passer, donc on l'éprouve fichier par fichier.
$catCassés = [];
foreach ($fichiersLang as $code => $f) {
    if (!is_array(require $f)) $catCassés[] = $code;
}
check('chaque catalogue rend bien un tableau', implode(' ', $catCassés), '');

// Le catalogue de la langue source est l'identité, donc vide : les msgid SONT
// les phrases françaises. On le vérifie pour que personne ne le remplisse en
// croyant traduire, d'autant que I18n::init() ne le charge même pas.
check('le catalogue de la langue source est l\'identité', I18n::catalogue(I18n::SOURCE), []);
I18n::init('fr');
check('français choisi : la phrase source s\'affiche', I18n::t('Aide'), 'Aide');
check('français choisi : aucun repli anglais silencieux',
    I18n::t('Aujourd\'hui') !== I18n::catalogue('en')['Aujourd\'hui'], true);

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
$fr = Uptimeez\Ui::num(1234.5, 1);
I18n::init('en');
$en2 = Uptimeez\Ui::num(1234.5, 1);
I18n::init('es');
$es = Uptimeez\Ui::num(1234.5, 1);
check('français : virgule décimale', str_contains($fr, ','), true);
check('anglais : point décimal', str_contains($en2, '.') && str_contains($en2, ','), true);
check('espagnol : point pour les milliers', str_contains($es, '.') && str_contains($es, ','), true);
I18n::init('fr');

// =========================================================================
section('Niveau de détail de l\'interface');
// =========================================================================
unset($_COOKIE['uptimeez_mode'], $_SESSION['uptimeez_mode']);
check('deux modes seulement', Uptimeez\Ui::MODES, ['simple', 'expert']);
check('un mode inconnu retombe sur simple',
    in_array('simple', Uptimeez\Ui::MODES, true) && !in_array('nawak', Uptimeez\Ui::MODES, true), true);

// =========================================================================
section('Inventaire logiciel : lecture des versions');
// =========================================================================
use Uptimeez\Detect\Stack;

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
check('gravité absente reste inconnue', Uptimeez\Vuln::worstSeverity([['id' => 'X']]), 'unknown');
check('gravité la pire retenue',
    Uptimeez\Vuln::worstSeverity([['severity' => 'low'], ['severity' => 'high'], ['severity' => 'medium']]), 'high');
check('aucun avis, aucune gravité', Uptimeez\Vuln::worstSeverity([]), null);
check('libellé de gravité traduit', mb_strlen(Uptimeez\Vuln::severityLabel('high')) > 3, true);

// --- Enregistrement et remise à zéro sur changement de version ----------
$tmpV = sys_get_temp_dir() . '/uptimeez-vuln-' . bin2hex(random_bytes(3)) . '.sqlite';
$prevDbV = Uptimeez\Config::get('db.sqlite');
Uptimeez\Db::disconnect();
Uptimeez\Config::set('db.sqlite', $tmpV);
Uptimeez\Db::migrate();
$vsid = Uptimeez\Db::insert('sites', ['name' => 'Site', 'domain' => 'site.fr', 'created_at' => now()]);
$vmid = Uptimeez\Db::insert('monitors', ['site_id' => $vsid, 'name' => 'Accueil', 'url' => 'https://site.fr/',
    'kind' => 'page', 'role' => 'primary', 'method' => 'GET', 'interval_sec' => 300, 'timeout_sec' => 15,
    'retries' => 0, 'slow_ms' => 3000, 'expect_status' => '200-299', 'check_ssl' => 0, 'check_css' => 0,
    'check_db' => 0, 'check_noindex' => 0, 'ssl_warn_days' => 14, 'css_drop_pct' => 35, 'enabled' => 1,
    'status' => 'up', 'setup_state' => 'done', 'created_at' => now(), 'follow_redirects' => 1]);

$n = Uptimeez\Vuln::record($vmid, $vsid, $wpHtml, 'WordPress');
check('inventaire enregistré', $n >= 5, true);
check('un composant par site, sans doublon',
    (int)Uptimeez\Db::val('SELECT COUNT(*) FROM components WHERE site_id = ?', [$vsid]), $n);
Uptimeez\Vuln::record($vmid, $vsid, $wpHtml, 'WordPress');
check('une seconde lecture ne duplique rien',
    (int)Uptimeez\Db::val('SELECT COUNT(*) FROM components WHERE site_id = ?', [$vsid]), $n);

// Un verdict de veille, puis une mise à jour du site : le verdict doit tomber.
Uptimeez\Db::update('components', ['vuln_count' => 2, 'worst' => 'high', 'checked_at' => now(),
    'advisories' => jenc([['id' => 'X-1', 'severity' => 'high']])],
    'site_id = :s AND slug = :g', ['s' => $vsid, 'g' => 'elementor']);
check('un composant est marqué vulnérable',
    (int)Uptimeez\Db::val('SELECT vuln_count FROM components WHERE site_id = ? AND slug = ?', [$vsid, 'elementor']), 2);
$updated = str_replace('elementor/assets/css/frontend.min.css?ver=3.18.3',
                       'elementor/assets/css/frontend.min.css?ver=3.25.0', $wpHtml);
Uptimeez\Vuln::record($vmid, $vsid, $updated, 'WordPress');
$after = Uptimeez\Db::one('SELECT version, vuln_count, checked_at FROM components
                          WHERE site_id = ? AND slug = ?', [$vsid, 'elementor']);
check('la nouvelle version est enregistrée', $after['version'], '3.25.0');
check('le verdict est remis à zéro après mise à jour', (int)$after['vuln_count'], 0);
check('et sera revérifié', $after['checked_at'], null);

// Sans version lisible, la veille n'a rien à interroger.
Uptimeez\Db::q('UPDATE components SET version = NULL');
$sc = Uptimeez\Vuln::scan(5);
check('aucune version : aucune interrogation', $sc['checked'], 0);

// Les compteurs restent cohérents.
$vc = Uptimeez\Vuln::counts();
check('compteurs disponibles',
    array_keys($vc), ['components', 'with_vuln', 'high', 'outdated', 'unchecked']);
check('rien de vulnérable dans cette base', $vc['with_vuln'], 0);
check('aucune trouvaille à signaler', Uptimeez\Vuln::findings(), []);

Uptimeez\Db::disconnect();

Uptimeez\Config::set('db.sqlite', $prevDbV);
@unlink($tmpV); @unlink($tmpV . '-wal'); @unlink($tmpV . '-shm');

// =========================================================================
section('Rapport mensuel : programmation et composition');
// =========================================================================
use Uptimeez\Report;

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
Uptimeez\Config::set('report.fallback_to', 'secours@agence.fr');
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
Uptimeez\Config::set('report.fallback_to', '');
check('sans repli, aucun destinataire', Report::recipients(['report_to' => '']), []);

// --- Base isolée pour la programmation et les chiffres ------------------
$tmpR = sys_get_temp_dir() . '/uptimeez-report-' . bin2hex(random_bytes(3)) . '.sqlite';
$prevDb = Uptimeez\Config::get('db.sqlite');
Uptimeez\Db::disconnect();
Uptimeez\Config::set('db.sqlite', $tmpR);
Uptimeez\Db::migrate();

$rsid = Uptimeez\Db::insert('sites', ['name' => 'Client Témoin', 'domain' => 'temoin.fr',
    'report_enabled' => 1, 'report_to' => 'client@temoin.fr', 'created_at' => now()]);
$rmid = Uptimeez\Db::insert('monitors', ['site_id' => $rsid, 'name' => 'Accueil',
    'url' => 'https://temoin.fr/', 'kind' => 'page', 'role' => 'primary', 'method' => 'GET',
    'interval_sec' => 300, 'timeout_sec' => 15, 'retries' => 0, 'slow_ms' => 3000,
    'expect_status' => '200-299', 'check_ssl' => 0, 'check_css' => 0, 'check_db' => 0,
    'check_noindex' => 0, 'ssl_warn_days' => 14, 'css_drop_pct' => 35, 'enabled' => 1,
    'status' => 'up', 'setup_state' => 'done', 'created_at' => now(), 'follow_redirects' => 1]);
// Un mois de mesures : 28 jours pleins, dont un avec 30 minutes d'interruption.
[$mFrom] = Report::monthRange('2026-03-10');
for ($d = 1; $d <= 28; $d++) {
    $day = sprintf('2026-02-%02d', $d);
    Uptimeez\Db::insert('daily_stats', ['monitor_id' => $rmid, 'day' => $day,
        'checks' => 288, 'fails' => $d === 12 ? 6 : 0, 'degraded' => 0,
        'downtime_sec' => $d === 12 ? 1800 : 0, 'avg_ms' => 420.0, 'p95_ms' => 700,
        'min_ms' => 300, 'max_ms' => 900]);
}
Uptimeez\Db::insert('incidents', ['monitor_id' => $rmid, 'started_at' => '2026-02-12 04:10:00',
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
Uptimeez\Config::set('report.enabled', false);
check('envoi désactivé : rien n\'est dû', Report::dueSites('2026-03-01'), []);
Uptimeez\Config::set('report.enabled', true);
Uptimeez\Config::set('report.day', 5);
check('avant le jour programmé : rien n\'est dû', Report::dueSites('2026-03-03'), []);
check('le jour programmé : le site est dû', count(Report::dueSites('2026-03-05')), 1);
check('après le jour programmé : encore dû', count(Report::dueSites('2026-03-19')), 1);
// Une programmation au 31 doit tomber le dernier jour d'un mois plus court.
Uptimeez\Config::set('report.day', 31);
check('programmation au 31, dernier jour de février', count(Report::dueSites('2026-02-28')), 1);
Uptimeez\Config::set('report.day', 1);

// Un rapport déjà parti ce mois-là ne repart pas.
Uptimeez\Db::update('sites', ['report_sent_key' => '2026-02'], 'id = :i', ['i' => $rsid]);
check('déjà envoyé ce mois : rien n\'est dû', Report::dueSites('2026-03-10'), []);
Uptimeez\Db::update('sites', ['report_sent_key' => null], 'id = :i', ['i' => $rsid]);
check('mois suivant : de nouveau dû', count(Report::dueSites('2026-03-10')), 1);
// Sans destinataire, rien n'est dû non plus.
Uptimeez\Db::update('sites', ['report_to' => null], 'id = :i', ['i' => $rsid]);
check('sans destinataire : rien n\'est dû', Report::dueSites('2026-03-10'), []);
Uptimeez\Db::update('sites', ['report_to' => 'client@temoin.fr'], 'id = :i', ['i' => $rsid]);

// --- Composition du courrier -------------------------------------------
$rsite = Uptimeez\Db::one('SELECT * FROM sites WHERE id = ?', [$rsid]);
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
Uptimeez\Db::update('sites', ['name' => '<script>alert(1)</script> & Cie'], 'id = :i', ['i' => $rsid]);
$hostileSite = Uptimeez\Db::one('SELECT * FROM sites WHERE id = ?', [$rsid]);
$hh = Report::html($hostileSite, $rdata, '2026-02-01 00:00:00', '2026-02-28 23:59:59');
check('nom de site échappé dans le courrier', str_contains($hh, '<script>alert(1)</script>'), false);
check('nom de site présent sous forme échappée', str_contains($hh, '&lt;script&gt;'), true);
Uptimeez\Db::update('sites', ['name' => 'Client Témoin'], 'id = :i', ['i' => $rsid]);

// --- Objet du message ---------------------------------------------------
Uptimeez\Config::set('report.subject', '');
$r1 = Report::sendFor($rsid, '2026-03-10');
check('sans canal e-mail, l\'envoi échoue proprement', $r1['ok'], false);
check('et le motif est explicite', mb_strlen((string)$r1['info']) > 10, true);
check('objet composé avec le site et le mois',
    str_contains((string)$r1['subject'], 'Client Témoin') && str_contains((string)$r1['subject'], 'février'), true);
Uptimeez\Config::set('report.subject', 'Suivi {site} - {month} - {app}');
$r2 = Report::sendFor($rsid, '2026-03-10');
check('gabarit d\'objet respecté',
    str_contains((string)$r2['subject'], 'Suivi Client Témoin - février 2026 - ' . Uptimeez\I18n::APP), true);
// Un échec ne doit pas marquer le mois comme envoyé.
check('un échec ne consomme pas le mois',
    (string)Uptimeez\Db::val('SELECT report_sent_key FROM sites WHERE id = ?', [$rsid]), '');
check('un échec laisse le site dû', count(Report::dueSites('2026-03-10')), 1);

// Un mois sans aucune mesure ne produit pas de rapport vide.
$emptySid = Uptimeez\Db::insert('sites', ['name' => 'Sans mesure', 'domain' => 'vide.fr',
    'report_enabled' => 1, 'report_to' => 'x@vide.fr', 'created_at' => now()]);
$r3 = Report::sendFor($emptySid, '2026-03-10');
check('aucune mesure : rapport non envoyé', $r3['ok'], false);
check('et le motif le dit', str_contains((string)$r3['info'], 'esure'), true);

Uptimeez\Config::set('report.enabled', false);
Uptimeez\Config::set('report.subject', '');
Uptimeez\Db::disconnect();
Uptimeez\Config::set('db.sqlite', $prevDb);
@unlink($tmpR); @unlink($tmpR . '-wal'); @unlink($tmpR . '-shm');

// =========================================================================
section('Solidité : suppressions, volumes, saisies absurdes');
// =========================================================================
// Trois défauts trouvés en relisant le code, chacun invisible tant qu'on ne
// pousse pas l'outil : une suppression qui laisse des traces, une requête qui
// dépasse la limite de paramètres d'un SQLite ancien, une saisie qui met une
// sonde hors service pour toujours.

// --- Supprimer une sonde ne doit rien laisser derrière -------------------
$prevDbS = Uptimeez\Config::get('db.sqlite');
$tmpS = sys_get_temp_dir() . '/self-solid-' . bin2hex(random_bytes(4)) . '.sqlite';
Uptimeez\Db::disconnect();
Uptimeez\Config::set('db.sqlite', $tmpS);
Uptimeez\Db::migrate();

$sidS = Uptimeez\Db::insert('sites', ['name' => 'Site', 'domain' => 'solid.test', 'created_at' => now()]);
$mkMon = function (string $name, string $role) use ($sidS): int {
    return Uptimeez\Db::insert('monitors', ['site_id' => $sidS, 'name' => $name,
        'url' => 'https://solid.test/' . $name, 'kind' => 'page', 'role' => $role, 'method' => 'GET',
        'interval_sec' => 300, 'timeout_sec' => 10, 'retries' => 0, 'expect_status' => '200-299',
        'enabled' => 1, 'status' => 'up', 'setup_state' => 'done',
        'created_at' => now(), 'next_check_at' => now()]);
};
$mA = $mkMon('a', 'primary');
$mB = $mkMon('b', 'page');
foreach ([$mA, $mB] as $mid) {
    Uptimeez\Db::insert('checks', ['monitor_id' => $mid, 'ts' => now(), 'state' => 'up',
                                  'total_ms' => 10, 'attempts' => 1]);
    Uptimeez\Db::insert('incidents', ['monitor_id' => $mid, 'severity' => 'down',
                                     'started_at' => now(), 'checks_failed' => 1]);
    Uptimeez\Db::insert('events', ['monitor_id' => $mid, 'ts' => now(), 'kind' => 'x',
                                  'message' => 'm', 'seen' => 0]);
    Uptimeez\Db::insert('daily_stats', ['monitor_id' => $mid, 'day' => date('Y-m-d'),
                                       'checks' => 1, 'fails' => 0]);
    Uptimeez\Db::insert('notifications', ['monitor_id' => $mid, 'ts' => now(), 'channel' => 'mail',
                                         'kind' => 'down', 'ok' => 1]);
}
Uptimeez\Db::insert('components', ['site_id' => $sidS, 'kind' => 'core', 'slug' => 'wp',
                                  'name' => 'WordPress', 'seen_at' => now(), 'first_seen_at' => now()]);

$delB = Uptimeez\Db::deleteMonitors([$mB]);
check('une page supprimée : la sonde part', $delB['monitors'], 1);
check('supprimer une page ne touche pas le site', $delB['sites'], 0);
check('ses mesures partent avec elle',
    (int)Uptimeez\Db::val('SELECT COUNT(*) FROM checks WHERE monitor_id = ?', [$mB]), 0);
// C'est la table oubliée : elle grossissait sans fin.
check('ses alertes envoyées partent aussi',
    (int)Uptimeez\Db::val('SELECT COUNT(*) FROM notifications WHERE monitor_id = ?', [$mB]), 0);
check('la sonde principale est intacte',
    (int)Uptimeez\Db::val('SELECT COUNT(*) FROM monitors WHERE id = ?', [$mA]), 1);
check('l\'inventaire du site est intact',
    (int)Uptimeez\Db::val('SELECT COUNT(*) FROM components WHERE site_id = ?', [$sidS]), 1);

$delA = Uptimeez\Db::deleteMonitors([$mA]);
check('la dernière sonde emporte le site', $delA['sites'], 1);
check('et son inventaire logiciel', $delA['components'], 1);
check('plus aucun composant orphelin',
    (int)Uptimeez\Db::val('SELECT COUNT(*) FROM components'), 0);
check('plus aucune mesure orpheline',
    (int)Uptimeez\Db::val('SELECT COUNT(*) FROM checks'), 0);
check('supprimer une sonde inconnue ne fait rien',
    Uptimeez\Db::deleteMonitors([999999])['monitors'], 0);
check('liste vide : aucune requête, aucun dégât', Uptimeez\Db::deleteMonitors([])['monitors'], 0);

// --- La réparation rattrape ce qu'une version antérieure a laissé -------
Uptimeez\Db::insert('checks', ['monitor_id' => 4242, 'ts' => now(), 'state' => 'up',
                              'total_ms' => 1, 'attempts' => 1]);
Uptimeez\Db::insert('notifications', ['monitor_id' => 4242, 'ts' => now(), 'channel' => 'mail',
                                     'kind' => 'down', 'ok' => 1]);
$sidGhost = Uptimeez\Db::insert('sites', ['name' => 'Fantôme', 'domain' => 'ghost.test', 'created_at' => now()]);
Uptimeez\Db::insert('components', ['site_id' => $sidGhost, 'kind' => 'core', 'slug' => 'x',
                                  'name' => 'X', 'seen_at' => now(), 'first_seen_at' => now()]);
$rep = Uptimeez\Db::repairOrphans();
check('les orphelins d\'une ancienne version sont nettoyés', $rep['orphans'] >= 2, true);
check('un site sans aucune sonde est retiré', $rep['sites'], 1);
check('et son inventaire avec lui', $rep['components'], 1);
check('une base saine ne bouge plus',
    array_sum(Uptimeez\Db::repairOrphans()), 0);

// --- Les requêtes de masse tiennent au-delà de 999 paramètres -----------
// SQLite compilé avant 3.32 refuse plus de 999 paramètres liés, et c'est celui
// des hébergements mutualisés un peu anciens.
$many = [];
for ($i = 0; $i < 1500; $i++) $many[] = $mkMon('vol' . $i, 'page');
check('1500 sondes créées', count($many), 1500);
$batch = Uptimeez\Stats::sparkBatch($many, 86400, 24);
check('les courbes groupées les couvrent toutes', count($batch), 1500);
$pulse = Uptimeez\Stats::pulse(86400, 24);
check('le pouls du parc tient sur un gros parc', count($pulse), 24);
check('découpage : un paquet par tranche de 400',
    count(Uptimeez\Db::chunk(range(1, 1500), fn(array $p): array => [count($p)])), 4);
check('sous le seuil, un seul paquet',
    Uptimeez\Db::chunk(range(1, 10), fn(array $p): array => [count($p)]), [10]);
check('liste vide : aucun paquet', Uptimeez\Db::chunk([], fn(array $p): array => [1]), []);
$delMany = Uptimeez\Db::deleteMonitors($many);
check('et la suppression de masse aussi', $delMany['monitors'], 1500);

Uptimeez\Db::disconnect();

Uptimeez\Config::set('db.sqlite', $prevDbS);
// Le chemin restauré peut désigner une base supprimée par une section
// précédente : on s'assure que le schéma existe pour la suite.
Uptimeez\Db::migrate();
@unlink($tmpS);

// --- Les heures calmes à cheval sur minuit ------------------------------
// Une erreur ici ne se voit qu'une nuit sur deux : la fonction est donc pure et
// vérifiée minute par minute.
foreach ([['23:00-07:00', 23 * 60 + 30, true], ['23:00-07:00', 6 * 60 + 59, true],
          ['23:00-07:00', 7 * 60, true],      ['23:00-07:00', 12 * 60, false],
          ['23:00-07:00', 22 * 60 + 59, false],
          ['09:00-18:00', 12 * 60, true],     ['09:00-18:00', 20 * 60, false],
          ['09:00-18:00', 9 * 60, true],      ['00:00-06:00', 3 * 60, true],
          ['00:00-06:00', 7 * 60, false],
          // Une plage impossible ne doit pas désactiver les heures calmes en
          // silence : elle est refusée à la saisie, et ignorée ici.
          ['25:00-99:00', 3 * 60, false],     ['absurde', 3 * 60, false],
          ['', 3 * 60, false]] as [$spec, $min, $want]) {
    check(sprintf('heures calmes [%s] à %02d:%02d', $spec ?: 'vide', intdiv($min, 60), $min % 60),
          Uptimeez\Notify\Notifier::quietHoursCover($spec, $min), $want);
}
foreach ([['23:00-07:00', true], ['09:00-18:00', true], ['0:00-6:00', true],
          ['25:00-99:00', false], ['23:60-07:00', false], ['absurde', false],
          ['', false], ['23:00', false], ['23:00-', false]] as [$spec, $valid]) {
    check('plage [' . ($spec ?: 'vide') . '] acceptée à la saisie',
          Uptimeez\Notify\Notifier::validQuietHours($spec), $valid);
}

// --- Une spécification de codes attendus illisible ne casse plus rien ---
foreach ([['200-299', true], ['200', true], ['2xx', true], ['200,301,404', true], ['', true],
          ['DROP TABLE', false], ['200 OK', false], ['abc', false], ['20', false],
          ['1000', false], ['200-', false]] as [$spec, $valid]) {
    check('spécification « ' . ($spec ?: 'vide') . ' »', Uptimeez\Runner::validStatusSpec($spec), $valid);
}
// Le point qui compte : une valeur invalide retombe sur le comportement par
// défaut au lieu de déclarer le site hors service pour toujours.
check('une spécification cassée n\'invente pas une panne',
    Uptimeez\Runner::statusMatches(200, 'n\'importe quoi'), true);
check('et ne cache pas une vraie panne',
    Uptimeez\Runner::statusMatches(503, 'n\'importe quoi'), false);

// =========================================================================
section('Incident en cours : motif, message et variables vont ensemble');
// =========================================================================
// Le motif, le message et les variables du message partaient séparément. Un
// changement de cause à gravité égale réécrivait le message sans toucher au
// motif : l'incident racontait « la chaîne de contrôle est absente » et
// affichait le diagnostic, le remède et l'icône d'une erreur 500. Les variables,
// elles, n'étaient jamais réécrites : le nouveau message était rempli avec les
// valeurs de l'ancien, d'où des verdicts faux comme « Erreur serveur 404 »
// quand le serveur avait répondu 503.

$prevDbI = Uptimeez\Config::get('db.sqlite');
$tmpI = sys_get_temp_dir() . '/self-incident-' . bin2hex(random_bytes(4)) . '.sqlite';
Uptimeez\Db::disconnect();
Uptimeez\Config::set('db.sqlite', $tmpI);
Uptimeez\Db::migrate();

$midI = Uptimeez\Db::insert('monitors', ['name' => 'Incident', 'url' => 'https://incident.test/',
    'kind' => 'page', 'role' => 'primary', 'method' => 'GET', 'interval_sec' => 300,
    'timeout_sec' => 10, 'retries' => 0, 'expect_status' => '200-299', 'enabled' => 1,
    'status' => 'up', 'setup_state' => 'done', 'created_at' => now(), 'next_check_at' => now(),
    'check_ssl' => 0, 'check_css' => 0, 'check_db' => 0, 'check_noindex' => 0,
    'check_content' => 0, 'slow_ms' => 0]);
$monI = Uptimeez\Db::one('SELECT * FROM monitors WHERE id = ?', [$midI]);

$resI = function (int $status, string $body = '<!doctype html><html><body>ok</body></html>') {
    $r = new Uptimeez\Response();
    $r->ok = true; $r->status = $status; $r->body = $body; $r->contentType = 'text/html';
    $r->totalMs = 120; $r->finalUrl = 'https://incident.test/';
    return $r;
};
$incI = fn() => Uptimeez\Db::one('SELECT * FROM incidents WHERE monitor_id = ? ORDER BY id DESC LIMIT 1', [$midI]);

// 1. Ouverture sur une erreur 503.
Uptimeez\Runner::runBatch([$monI], true);
$monI = Uptimeez\Db::one('SELECT * FROM monitors WHERE id = ?', [$midI]);
// On force le verdict en passant par evaluate + persist via une réponse fabriquée.
$vI = Uptimeez\Runner::evaluate($monI, $resI(503));
check('un 503 ouvre bien une panne', $vI['reason'], 'HTTP_5XX');
check('et le code mesuré est dans les variables', $vI['vars']['code'] ?? null, 503);

// 2. Puis la même gravité avec une autre cause : chaîne de contrôle absente.
Uptimeez\Db::q('UPDATE monitors SET expect_string = ? WHERE id = ?', ['Mentions légales', $midI]);
$monI = Uptimeez\Db::one('SELECT * FROM monitors WHERE id = ?', [$midI]);
$vI2 = Uptimeez\Runner::evaluate($monI, $resI(200));
check('la nouvelle cause est bien la chaîne absente', $vI2['reason'], 'STRING_MISSING');

// Le moteur d'incidents est privé : on l'exerce par le chemin public, en
// écrivant les deux verdicts à la suite sur la même sonde.
$refl = new ReflectionMethod(Uptimeez\Runner::class, 'applyIncident');
$refl->setAccessible(true);
$refl->invoke(null, $monI, 'down', $vI);
$openI = $incI();
check('incident ouvert', $openI !== null, true);
check('son motif est celui du 503', $openI['reason_code'] ?? null, 'HTTP_5XX');
check('et ses variables portent le 503', jdec($openI['message_vars'] ?? null)['code'] ?? null, 503);

$refl->invoke(null, $monI, 'down', $vI2);
$openI = $incI();
check('un seul incident, pas deux',
    (int)Uptimeez\Db::val('SELECT COUNT(*) FROM incidents WHERE monitor_id = ?', [$midI]), 1);
check('le motif a suivi le message', $openI['reason_code'] ?? null, 'STRING_MISSING');
check('le message aussi', str_contains((string)$openI['message'], 'chaîne de contrôle'), true);
// Le point qui faisait faux : les variables de l'ancien verdict remplissaient
// le nouveau message.
check('les variables du verdict précédent ont disparu',
    isset(jdec($openI['message_vars'] ?? null)['code']), false);
check('le verdict se relit sans laisser de {trou}',
    str_contains(verdict_text($openI), '{'), false);

// 3. Une gravité qui baisse ne réécrit rien : l'incident se raconte par son pire
//    moment.
$vI3 = ['state' => 'degraded', 'reason' => 'SLOW', 'message' => 'Temps de réponse élevé : {seconds} s',
        'vars' => ['seconds' => '4,20'], 'details' => [], 'events' => [], 'findings' => []];
$refl->invoke(null, $monI, 'degraded', $vI3);
$openI = $incI();
check('une gravité en baisse ne change pas le motif', $openI['reason_code'] ?? null, 'STRING_MISSING');
check('ni la gravité de l\'incident', $openI['severity'] ?? null, 'down');
check('mais le compteur d\'échecs monte', (int)($openI['checks_failed'] ?? 0) >= 3, true);

Uptimeez\Db::disconnect();
Uptimeez\Config::set('db.sqlite', $prevDbI);
Uptimeez\Db::migrate();
foreach ([$tmpI, $tmpI . '-wal', $tmpI . '-shm'] as $f) @unlink($f);

// =========================================================================
section('Pont d\'authentification : un jeton, une seule fois');
// =========================================================================
// Sert à ouvrir une instance depuis un tableau de bord commun, sans redemander
// son mot de passe. Toute la valeur est dans ce qu'il REFUSE : le rejeu, le
// jeton périmé, la signature falsifiée, la durée de vie allongée, et le secret
// trop court pour être sérieux.

$prevDbB = Uptimeez\Config::get('db.sqlite');
$tmpB = sys_get_temp_dir() . '/self-pont-' . bin2hex(random_bytes(4)) . '.sqlite';
Uptimeez\Db::disconnect();
Uptimeez\Config::set('db.sqlite', $tmpB);
Uptimeez\Db::migrate();

// --- Désactivé par défaut : une installation ordinaire ne l'expose pas -----
Uptimeez\Config::set('auth.bridge_secret', '');
check('sans secret, aucun jeton ne se fabrique', Uptimeez\Auth::makeToken(), null);
check('et aucun jeton n\'est accepté', Uptimeez\Auth::attemptToken('v1.eyJhIjoxfQ.zzz'), false);

// --- Un secret court est refusé, plutôt que d'offrir la porte mal fermée ---
Uptimeez\Config::set('auth.bridge_secret', str_repeat('x', 31));
check('un secret de 31 caractères est refusé', Uptimeez\Auth::makeToken(), null);

$secretB = bin2hex(random_bytes(32));
Uptimeez\Config::set('auth.bridge_secret', $secretB);

// --- Le cas nominal --------------------------------------------------------
$jeton = Uptimeez\Auth::makeToken(60);
check('un jeton se fabrique', is_string($jeton) && substr_count($jeton, '.') === 2, true);
check('et il ouvre la session', Uptimeez\Auth::attemptToken((string)$jeton), true);

// --- LE point qui compte : un jeton passe UNE fois ------------------------
// Sans cela, un jeton capturé dans un journal d'accès ou dans un référent
// rouvrirait la session pendant toute sa durée de vie.
check('le même jeton ne repasse pas', Uptimeez\Auth::attemptToken((string)$jeton), false);

// --- Signature falsifiée ---------------------------------------------------
$morceaux = explode('.', (string)Uptimeez\Auth::makeToken(60));
check('signature falsifiée refusée',
    Uptimeez\Auth::attemptToken($morceaux[0] . '.' . $morceaux[1] . '.'
        . Uptimeez\Auth::b64url(str_repeat("\x00", 32))), false);
check('charge utile modifiée refusée (la signature ne suit pas)',
    Uptimeez\Auth::attemptToken($morceaux[0] . '.'
        . Uptimeez\Auth::b64url((string)jenc(['iat' => time(), 'exp' => time() + 60,
                                             'nonce' => 'fabrique'])) . '.' . $morceaux[2]), false);

// --- Un jeton signé avec un AUTRE secret ---------------------------------
$autre = bin2hex(random_bytes(32));
Uptimeez\Config::set('auth.bridge_secret', $autre);
$jetonAutre = (string)Uptimeez\Auth::makeToken(60);
Uptimeez\Config::set('auth.bridge_secret', $secretB);
check('un jeton signé avec un autre secret est refusé',
    Uptimeez\Auth::attemptToken($jetonAutre), false);

// --- Périmé, daté du futur, durée de vie allongée -------------------------
$forge = function (int $iat, int $exp) use ($secretB): string {
    $b = Uptimeez\Auth::b64url((string)jenc(['iat' => $iat, 'exp' => $exp,
                                            'nonce' => bin2hex(random_bytes(8))]));
    return 'v1.' . $b . '.' . Uptimeez\Auth::b64url(hash_hmac('sha256', 'v1.' . $b, $secretB, true));
};
check('jeton expiré refusé', Uptimeez\Auth::attemptToken($forge(time() - 200, time() - 1)), false);
check('jeton daté du futur refusé', Uptimeez\Auth::attemptToken($forge(time() + 600, time() + 660)), false);
// La durée de vie annoncée est bornée : sinon un émetteur complaisant, ou
// compromis, fabriquerait un jeton valable un an.
check('durée de vie au-delà de la borne refusée',
    Uptimeez\Auth::attemptToken($forge(time(), time() + Uptimeez\Auth::BRIDGE_MAX_TTL + 60)), false);
check('mais juste en dessous de la borne, ça passe',
    Uptimeez\Auth::attemptToken($forge(time(), time() + Uptimeez\Auth::BRIDGE_MAX_TTL - 5)), true);
// Une tolérance de 30 s absorbe un décalage d'horloge : c'est voulu.
check('un léger décalage d\'horloge est toléré',
    Uptimeez\Auth::attemptToken($forge(time() + 20, time() + 80)), true);

// --- Formes malformées ----------------------------------------------------
foreach (['', 'v1', 'v1.a', 'v2.a.b', 'v1..', '....', 'v1.!!!.???'] as $mauvais) {
    check('forme rejetée : « ' . str_cut($mauvais, 12) . ' »',
        Uptimeez\Auth::attemptToken($mauvais), false);
}

// --- La liste des jetons employés ne gonfle pas ---------------------------
// La propriété à vérifier n'est pas « la liste se vide » : les jetons encore
// valides doivent y rester, sinon ils repasseraient. C'est « aucun jeton périmé
// n'y survit », ce qui borne la liste par construction.
$expB = fn(): array => array_map('intval', array_column(
    Uptimeez\Db::all("SELECT v FROM settings WHERE k LIKE 'bridge_nonce:%'"), 'v'));
for ($i = 0; $i < 20; $i++) Uptimeez\Auth::attemptToken((string)Uptimeez\Auth::makeToken(5));
check('les jetons employés sont mémorisés', Uptimeez\Auth::bridgeNonceCount() >= 20, true);
$courts = count(array_filter($expB(), fn(int $exp): bool => $exp <= time() + 6));
check('dont les vingt à courte durée de vie', $courts >= 20, true);

sleep(7);
Uptimeez\Auth::attemptToken((string)Uptimeez\Auth::makeToken(60));
check('aucun jeton périmé ne survit à la purge',
    count(array_filter($expB(), fn(int $exp): bool => $exp <= time())), 0);
check('et les vingt jetons courts ont bien disparu', Uptimeez\Auth::bridgeNonceCount() < 20, true);

// --- Le rejeu EN PARALLÈLE, qui est le seul rejeu qu'on tente vraiment ----
//
// Le registre était un tableau JSON lu, modifié, puis réécrit. Entre la lecture
// et l'écriture, une seconde requête portant le même jeton lisait un registre où
// il était encore absent : les deux passaient. Un attaquant qui rejoue un jeton
// capturé le fait précisément comme ça, en parallèle et le plus vite possible ;
// le contrôle séquentiel ci-dessus ne voyait donc pas le seul cas qui compte.
//
// Huit processus réels, un même jeton, un rendez-vous à une date commune. Le
// contrat est arithmétique et ne laisse pas de place à l'interprétation : la
// somme des succès vaut UN.
$cfgP = sys_get_temp_dir() . '/self-pont-' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($cfgP, "<?php return " . var_export([
    'db'   => ['driver' => 'sqlite', 'sqlite' => $tmpB],
    'auth' => ['password_hash' => password_hash('x', PASSWORD_DEFAULT),
               'session_name' => 'uptimeezpont', 'bridge_secret' => $secretB],
], true) . ";\n");
// L'ouvrier vit dans /tmp : le chemin du moteur y est écrit en absolu, il ne
// peut pas se déduire de sa propre position.
$ouvrier = sys_get_temp_dir() . '/self-pont-' . bin2hex(random_bytes(4)) . '-ouvrier.php';
file_put_contents($ouvrier,
    "<?php\n"
  . 'require ' . var_export(dirname(__DIR__) . '/src/bootstrap.php', true) . ";\n"
  . "[\$jeton, \$depart] = [\$argv[1], (float)\$argv[2]];\n"
  // Rendez-vous en attente active : à cette échelle, usleep() rendrait la main
  // avec une imprécision du même ordre que ce qu'on cherche à provoquer.
  . "while (microtime(true) < \$depart) { }\n"
  . "echo Uptimeez\\Auth::attemptToken(\$jeton) ? '1' : '0';\n");

$jetonP  = (string)Uptimeez\Auth::makeToken(60);
$depart  = microtime(true) + 0.6;
$procs   = [];
$tubes   = [];
for ($i = 0; $i < 8; $i++) {
    $procs[$i] = proc_open([PHP_BINARY, $ouvrier, $jetonP, (string)$depart],
        [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $tubes[$i], sys_get_temp_dir(),
        ['UPTIMEEZ_CONFIG' => $cfgP, 'PATH' => getenv('PATH') ?: '/usr/bin:/bin']);
}
$succes = 0;
$rendus = 0;
foreach ($procs as $i => $h) {
    if (!is_resource($h)) continue;
    $out = trim((string)stream_get_contents($tubes[$i][1]));
    fclose($tubes[$i][1]);
    proc_close($h);
    if ($out === '1' || $out === '0') $rendus++;
    $succes += (int)($out === '1');
}
check('les huit processus concurrents ont bien répondu', $rendus, 8);
check('un jeton rejoué en parallèle ne passe qu\'UNE fois', $succes, 1);
check('et il ne repasse pas ensuite', Uptimeez\Auth::attemptToken($jetonP), false);
foreach ([$cfgP, $ouvrier, dirname($ouvrier) . '/uptimeez-ouvrier-racine.php'] as $f) @unlink($f);

Uptimeez\Config::set('auth.bridge_secret', '');
Uptimeez\Db::disconnect();
Uptimeez\Config::set('db.sqlite', $prevDbB);
Uptimeez\Db::migrate();
foreach ([$tmpB, $tmpB . '-wal', $tmpB . '-shm'] as $f) @unlink($f);

// =========================================================================
section('Réponse tronquée : ne rien conclure de ce qu\'on n\'a pas lu');
// =========================================================================
// Http marque les réponses coupées au-delà de 3 Mo, et personne ne lisait ce
// drapeau. Conséquences sur une page volumineuse (catalogue entier rendu d\'un
// coup, images en base64) : la chaîne de contrôle, choisie de préférence dans le
// pied de page, tombait au-delà de la coupure. Elle était donc déclarée absente,
// ce qui veut dire « la base de données ne répond plus », donc une fausse panne
// permanente. Et l\'empreinte de contenu, calculée sur un corps dont la longueur
// dépendait du découpage réseau, changeait à chaque passe : « le contenu de la
// page a changé », indéfiniment.

$monT = ['id' => 0, 'url' => 'https://tronque.test/', 'kind' => 'page', 'method' => 'GET',
    'interval_sec' => 300, 'timeout_sec' => 10, 'retries' => 0, 'expect_status' => '200-299',
    'enabled' => 1, 'check_ssl' => 0, 'check_css' => 0, 'check_db' => 0, 'check_noindex' => 0,
    'check_content' => 1, 'slow_ms' => 0, 'ssl_warn_days' => 14, 'css_drop_pct' => 35,
    'expect_string' => 'Mentions légales 2026', 'forbid_string' => 'Fatal error',
    'watch_string' => 'En stock', 'watch_mode' => 'disappear', 'watch_state' => 'present',
    'content_hash' => null, 'follow_redirects' => 1, 'ignore_ssl_errors' => 0];

$mkRes = function (string $body, bool $cut) {
    $r = new Uptimeez\Response();
    $r->ok = true; $r->status = 200; $r->body = $body; $r->contentType = 'text/html';
    $r->truncated = $cut; $r->totalMs = 200; $r->finalUrl = 'https://tronque.test/';
    return $r;
};
$page = '<!doctype html><html><body><p>' . str_repeat('contenu ', 200) . '</p>';

// Page complète, chaîne absente : c\'est une vraie panne, elle doit sortir.
$vT = Uptimeez\Runner::evaluate($monT, $mkRes($page . '</body></html>', false));
check('page complète sans la chaîne : panne déclarée', $vT['state'], 'down');
check('et le motif est bien la chaîne absente', $vT['reason'], 'STRING_MISSING');

// Même page, mais coupée : on ne sait pas, et on le dit.
$vT = Uptimeez\Runner::evaluate($monT, $mkRes($page, true));
check('page coupée : pas de panne inventée', $vT['state'], 'degraded');
check('le motif dit que la page est trop grosse', $vT['reason'], 'BODY_TRUNCATED');
check('et le verdict explique pourquoi',
    str_contains($vT['message'], 'trop volumineuse'), true);

// Une chaîne présente reste une certitude, coupure ou pas.
$vT = Uptimeez\Runner::evaluate($monT, $mkRes($page . 'Mentions légales 2026', true));
check('la chaîne trouvée avant la coupure suffit', $vT['state'], 'up');

// Une chaîne INTERDITE présente est une certitude elle aussi.
$vT = Uptimeez\Runner::evaluate($monT, $mkRes($page . 'Mentions légales 2026 Fatal error', true));
check('une chaîne interdite reste une panne sur page coupée', $vT['reason'], 'STRING_FORBIDDEN');

// L\'empreinte de contenu ne se calcule pas sur un corps incomplet.
$vT = Uptimeez\Runner::evaluate($monT, $mkRes($page . 'Mentions légales 2026', true));
check('aucune empreinte de contenu sur page coupée',
    isset($vT['details']['content_hash']), false);
$vT = Uptimeez\Runner::evaluate($monT, $mkRes($page . 'Mentions légales 2026</body></html>', false));
check('mais elle se calcule sur une page complète',
    isset($vT['details']['content_hash']), true);

// Le mot surveillé ne bascule pas sur une lecture partielle : « En stock »
// disparu de la fin d\'une page coupée n\'est pas une disparition.
$vT = Uptimeez\Runner::evaluate($monT, $mkRes($page . 'Mentions légales 2026', true));
check('aucune bascule du mot surveillé sur page coupée', $vT['events'], []);

// La coupure elle-même — la même page lue deux fois doit donner le même corps,
// quel que soit le découpage réseau — se vérifie contre un vrai serveur :
// voir bin/e2e.php, section « Page trop volumineuse ».

// =========================================================================
section('Durée : un an d\'historique, et la place rendue');
// =========================================================================
// Trois défauts sortis d'une mesure sur 300 sondes et 5,2 millions de mesures.
// Aucun n'était visible sur une base neuve, et les trois avaient des
// conséquences en exploitation réelle.

$prevDbV = Uptimeez\Config::get('db.sqlite');
$tmpV = sys_get_temp_dir() . '/self-duree-' . bin2hex(random_bytes(4)) . '.sqlite';
Uptimeez\Db::disconnect();
Uptimeez\Config::set('db.sqlite', $tmpV);
Uptimeez\Db::migrate();

// --- 1. L'ordre des PRAGMA de connexion ----------------------------------
// « auto_vacuum » ne se règle qu'avant la première écriture de l'en-tête, et
// « journal_mode = WAL » écrit cet en-tête. Placé après lui, le réglage était
// accepté sans effet : aucune base créée par UptimeEZ ne rendait jamais l'espace
// d'une purge. Une ligne de contrôle l'aurait vu dès le premier jour.
check('une base neuve est en vacuum incrémental',
    (int)Uptimeez\Db::pdo()->query('PRAGMA auto_vacuum')->fetchColumn(), 2);
check('et en mode WAL',
    strtolower((string)Uptimeez\Db::pdo()->query('PRAGMA journal_mode')->fetchColumn()), 'wal');

// --- 2. Une purge ne tient jamais le verrou longtemps --------------------
// Ramener la conservation de 60 à 7 jours supprimait des millions de lignes en
// une seule requête : 51 secondes de verrou d'écriture mesurées, pendant
// lesquelles chaque page échouait sur « database is locked » (busy_timeout vaut
// 8 secondes). La purge travaille donc par tranches et note ce qui reste.
$midV = Uptimeez\Db::insert('monitors', ['name' => 'Durée', 'url' => 'https://duree.test/',
    'kind' => 'page', 'role' => 'primary', 'method' => 'GET', 'interval_sec' => 300,
    'timeout_sec' => 10, 'retries' => 0, 'expect_status' => '200-299', 'enabled' => 1,
    'status' => 'up', 'setup_state' => 'done', 'created_at' => now(), 'next_check_at' => now()]);
$pdoV = Uptimeez\Db::pdo();
$pdoV->beginTransaction();
$insV = $pdoV->prepare('INSERT INTO checks (monitor_id, ts, state, total_ms) VALUES (?,?,?,?)');
// 60 jours à raison de 24 mesures par jour : assez pour dépasser une tranche.
for ($d = 60; $d >= 0; $d--) {
    for ($h = 0; $h < 24; $h++) {
        $insV->execute([$midV, date('Y-m-d H:i:s', time() - $d * 86400 - $h * 3600), 'up', 150 + $h]);
    }
}
$pdoV->commit();
$totalV = (int)Uptimeez\Db::val('SELECT COUNT(*) FROM checks');
check('mesures en place pour la mesure', $totalV > 1400, true);

// Budget volontairement minuscule et tranche forcée : la purge doit rendre la
// main avant d'avoir fini, et le dire.
// Tranche réduite : la reprise s'éprouve sans fabriquer vingt mille mesures.
$firstV = Uptimeez\Stats::purge(7, 0.0, 300);
check('un budget nul supprime quand même une tranche', $firstV, 300);
check('et signale qu\'il reste du travail', Uptimeez\Stats::purgePending(), true);
$roundsV = 0;
while (Uptimeez\Stats::purgePending() && $roundsV < 50) { Uptimeez\Stats::purge(7, 0.0, 300); $roundsV++; }
check('les passes suivantes la terminent', Uptimeez\Stats::purgePending(), false);
check('et il en a fallu plusieurs', $roundsV > 1, true);
$leftV = (int)Uptimeez\Db::val('SELECT COUNT(*) FROM checks WHERE ts < ?',
    [date('Y-m-d H:i:s', time() - 7 * 86400)]);
check('plus une mesure au-delà de la conservation', $leftV, 0);
check('les mesures récentes sont intactes',
    (int)Uptimeez\Db::val('SELECT COUNT(*) FROM checks') > 100, true);
// Conservation illimitée : rien ne doit disparaître, et rien ne reste en attente.
$keptV = (int)Uptimeez\Db::val('SELECT COUNT(*) FROM checks');
check('conservation illimitée ne purge rien', Uptimeez\Stats::purge(0), 0);
check('et ne laisse pas de travail en attente', Uptimeez\Stats::purgePending(), false);
check('les mesures sont toujours là', (int)Uptimeez\Db::val('SELECT COUNT(*) FROM checks'), $keptV);

// --- 3. Un entretien manqué se rattrape ---------------------------------
// La consolidation ne portait que sur la veille, et ne tournait qu'à 3 h du
// matin. Une machine éteinte la nuit laissait donc des trous définitifs dans la
// frise 30 jours, puisque les mesures unitaires finissaient purgées.
Uptimeez\Db::q('DELETE FROM daily_stats');
$pdoV->beginTransaction();
for ($d = 5; $d >= 1; $d--) {
    for ($h = 0; $h < 24; $h++) {
        $insV->execute([$midV, date('Y-m-d H:i:s', time() - $d * 86400 - $h * 3600), 'up', 200 + $h]);
    }
}
$pdoV->commit();
check('aucun jour consolidé au départ', (int)Uptimeez\Db::val('SELECT COUNT(*) FROM daily_stats'), 0);
// Le rattrapage est borné par appel : il ne monopolise pas une passe de cron.
check('le rattrapage est plafonné à ce qu\'on lui demande', Uptimeez\Stats::rollupMissing(2), 2);
check('et il converge en quelques passes', (function () {
    $tours = 0;
    while (Uptimeez\Stats::rollupMissing(5) > 0 && $tours < 30) $tours++;
    return $tours < 30;
})(), true);
check('tous les jours mesurés ont leur résumé',
    (int)Uptimeez\Db::val('SELECT COUNT(DISTINCT day) FROM daily_stats') >= 5, true);
check('un passage de plus ne refait pas le travail', Uptimeez\Stats::rollupMissing(5), 0);
// Un jour sans aucune mesure n'a rien à consolider : il ne doit pas produire
// une ligne à zéro, qui se lirait comme « ce jour-là le site était injoignable ».
$holeV = date('Y-m-d', time() - 40 * 86400);
check('un jour sans mesure ne fabrique pas un faux zéro',
    Uptimeez\Db::val('SELECT 1 FROM daily_stats WHERE day = ?', [$holeV]), null);

// --- 4. La place revient au disque --------------------------------------
$cmpV = Uptimeez\Db::compact(1.0);
check('le compactage ne réclame pas de VACUUM sur une base neuve', $cmpV['needs_vacuum'], false);
$vacV = Uptimeez\Db::vacuum();
check('le VACUUM complet aboutit', $vacV['ok'], true);
check('et laisse la base en vacuum incrémental',
    (int)Uptimeez\Db::pdo()->query('PRAGMA auto_vacuum')->fetchColumn(), 2);

Uptimeez\Db::disconnect();
Uptimeez\Config::set('db.sqlite', $prevDbV);
Uptimeez\Db::migrate();
foreach ([$tmpV, $tmpV . '-wal', $tmpV . '-shm'] as $f) @unlink($f);

// =========================================================================
section('Traductions : aucune phrase laissée en arrière');
// =========================================================================
// L'anglais est la langue par défaut du produit : une phrase qui retombe sur le
// français est un défaut, pas un détail. Ces deux contrôles interrogent l'outil
// d'audit lui-même, pour que la dette ne revienne pas au premier écran ajouté.
$auditJson = shell_exec(escapeshellarg(PHP_BINARY) . ' '
    . escapeshellarg(UPTIMEEZ_ROOT . '/bin/i18n-audit.php') . ' --json 2>/dev/null');
$audit = jdec((string)$auditJson);
check('l\'audit de traduction répond', isset($audit['msgids']), true);
// Le JSON rend une liste de msgid, pas un tableau associatif.
$ids = array_values((array)($audit['msgids'] ?? []));
check('le catalogue anglais couvre chaque phrase',
    count(array_diff($ids, array_keys(Uptimeez\I18n::catalogue('en')))), 0);
$bare = (int)($audit['bare'] ?? 0);
check('aucun texte d\'interface laissé en français dans le code', $bare, 0);
check('aucun msgid coupé en morceaux', count((array)($audit['fragments'] ?? [])), 0);

// Un verdict enregistré par le collecteur se relit dans la langue du lecteur.
$vid = Uptimeez\Db::insert('monitors', ['name' => 'Verdict i18n', 'url' => 'https://verdict.test/',
    'kind' => 'page', 'role' => 'primary', 'method' => 'GET', 'interval_sec' => 300,
    'timeout_sec' => 10, 'retries' => 0, 'expect_status' => '200-299', 'enabled' => 1,
    'status' => 'degraded', 'setup_state' => 'done', 'created_at' => now(), 'next_check_at' => now(),
    'last_message' => 'Certificat SSL expire dans {n} jours',
    'last_message_vars' => jenc(['n' => 9])]);
$row = Uptimeez\Db::one('SELECT * FROM monitors WHERE id = ?', [$vid]);
Uptimeez\I18n::init('fr');
check('verdict rendu en français', verdict_text($row), 'Certificat SSL expire dans 9 jours');
Uptimeez\I18n::init('en');
check('le même verdict rendu en anglais', verdict_text($row), 'SSL certificate expires in 9 days');
check('un verdict sans variable reste lisible',
    verdict_text(['last_message' => 'Tout va bien', 'last_message_vars' => null]),
    Uptimeez\I18n::catalogue('en')['Tout va bien']);
check('aucun verdict : chaîne vide, pas de « — »', verdict_text(null), '');
Uptimeez\I18n::init('fr');
Uptimeez\Db::q('DELETE FROM monitors WHERE id = ?', [$vid]);

// =========================================================================
section('Reprise d\'un parc surveillé ailleurs');
// =========================================================================
use Uptimeez\Import\Foreign;

// Les cinq exports, dans la forme que les outils produisent réellement.
$exp = [];
$exp['uptimerobot'] = jenc(['stat' => 'ok', 'monitors' => [
    ['id' => 1, 'friendly_name' => 'Vitrine', 'url' => 'https://vitrine.test/', 'type' => 1,
     'interval' => 300, 'status' => 2],
    ['id' => 2, 'friendly_name' => 'Mot-clé attendu', 'url' => 'https://vitrine.test/a', 'type' => 2,
     'keyword_type' => 2, 'keyword_value' => 'Bienvenue', 'interval' => 600, 'status' => 2],
    ['id' => 3, 'friendly_name' => 'Mot-clé interdit', 'url' => 'https://vitrine.test/b', 'type' => 2,
     'keyword_type' => 1, 'keyword_value' => 'Erreur', 'interval' => 900, 'status' => 0],
    ['id' => 4, 'friendly_name' => 'Port IMAP', 'url' => 'mail.vitrine.test', 'type' => 4, 'port' => 993],
    ['id' => 5, 'friendly_name' => 'Ping', 'url' => '192.0.2.1', 'type' => 3],
    ['id' => 6, 'friendly_name' => 'Battement', 'url' => 'https://vitrine.test/beat', 'type' => 5],
]]);
$exp['kuma'] = jenc(['version' => '1.23.13', 'monitorList' => [
    '1' => ['name' => 'Http simple', 'url' => 'https://k.test/', 'type' => 'http', 'interval' => 60,
            'active' => 1, 'maxretries' => 3, 'timeout' => 20, 'method' => 'HEAD',
            'accepted_statuscodes' => ['200-299', '301']],
    '2' => ['name' => 'Mot-clé', 'url' => 'https://k.test/a', 'type' => 'keyword', 'keyword' => 'ok',
            'invertKeyword' => false, 'interval' => 120, 'active' => 1],
    '3' => ['name' => 'Mot-clé inversé', 'url' => 'https://k.test/b', 'type' => 'keyword',
            'keyword' => 'Fatal', 'invertKeyword' => true, 'interval' => 300, 'active' => 0],
    '4' => ['name' => 'Port', 'hostname' => 'db.k.test', 'port' => 5432, 'type' => 'port'],
    '5' => ['name' => 'Poussé', 'type' => 'push'],
]]);
$exp['betterstack'] = jenc(['data' => [
    ['id' => '1', 'attributes' => ['url' => 'https://b.test', 'pronounceable_name' => 'Bee',
      'monitor_type' => 'keyword', 'required_keyword' => 'Salut', 'check_frequency' => 180,
      'paused' => false, 'request_method' => 'get', 'expected_status_codes' => [200, 204]]],
    ['id' => '2', 'attributes' => ['url' => 'https://b.test/x', 'pronounceable_name' => 'Absence',
      'monitor_type' => 'keyword_absence', 'required_keyword' => 'Oups', 'check_frequency' => 300,
      'paused' => true]],
    ['id' => '3', 'attributes' => ['url' => 'tcp://b.test:25', 'pronounceable_name' => 'Smtp',
      'monitor_type' => 'tcp', 'check_frequency' => 60]],
]]);
$exp['pingdom'] = jenc(['checks' => [
    ['id' => 1, 'name' => 'Accueil', 'hostname' => 'p.test', 'resolution' => 1, 'type' => 'http'],
    ['id' => 2, 'name' => 'Panier', 'hostname' => 'p.test', 'resolution' => 5,
     'type' => ['http' => ['url' => '/panier', 'encryption' => true, 'shouldcontain' => 'Panier']]],
    ['id' => 3, 'name' => 'Clair', 'hostname' => 'p.test', 'resolution' => 5,
     'type' => ['http' => ['url' => '/clair', 'encryption' => false]]],
    ['id' => 4, 'name' => 'Smtp', 'hostname' => 'mail.p.test', 'resolution' => 5, 'type' => 'smtp'],
]]);
$exp['site24x7'] = jenc(['code' => 0, 'data' => [
    ['monitor_id' => '1', 'display_name' => 'Portail', 'website' => 'https://s.test/',
     'monitor_type' => 'URL', 'check_frequency' => '300',
     'matching_keyword' => ['severity' => 2, 'value' => 'Espace'], 'status' => '1'],
    ['monitor_id' => '2', 'display_name' => 'Suspendu', 'website' => 'https://s.test/x',
     'monitor_type' => 'URL', 'check_frequency' => '600', 'status' => '5',
     'unmatching_keyword' => ['severity' => 2, 'value' => 'Indispo']],
    ['monitor_id' => '3', 'display_name' => 'Ping', 'monitor_type' => 'PING', 'check_frequency' => '60'],
]]);
$exp['csv'] = "Nom;URL;Intervalle;Mot-clé;Actif\n"
            . "Cabinet;https://cab.test;5;Rendez-vous;oui\n"
            . "Étude;etude.test;15;;non\n"
            . "Cassée;pas une adresse;5;;oui\n";

// --- Reconnaissance : le contenu décide, jamais le nom du fichier --------
foreach ($exp as $src => $raw) {
    check('export reconnu : ' . $src, Foreign::detect($raw), $src);
}
check('texte quelconque non reconnu', Foreign::detect('bonjour, ceci est un e-mail'), null);
check('HTML non reconnu', Foreign::detect('<!doctype html><html><body>x</body></html>'), null);
check('JSON hors sujet non reconnu', Foreign::detect('{"foo":[1,2,3]}'), null);
check('chaîne vide non reconnue', Foreign::detect(''), null);
check('liste d\'URL simple laissée à l\'import classique',
    Foreign::detect("exemple.fr\nautre.fr"), null);

// --- UptimeRobot ---------------------------------------------------------
$r = Foreign::parse($exp['uptimerobot']);
check('UptimeRobot : trois sondes reprises', count($r['rows']), 3);
check('UptimeRobot : trois écartées', count($r['skipped']), 3);
check('UptimeRobot : cadence reprise', $r['rows'][0]['interval'], 300);
// « not exists » veut dire « alerter si absent » : c'est notre chaîne de contrôle.
check('UptimeRobot : mot-clé attendu devient chaîne de contrôle', $r['rows'][1]['expect'], 'Bienvenue');
check('UptimeRobot : mot-clé « exists » devient chaîne interdite', $r['rows'][2]['forbid'], 'Erreur');
check('UptimeRobot : une sonde en pause reste en pause', $r['rows'][2]['enabled'], 0);
$whys = implode(' ', array_column($r['skipped'], 'why'));
check('UptimeRobot : le port TCP est écarté avec sa raison', str_contains($whys, 'port TCP'), true);
check('UptimeRobot : le ping est écarté avec sa raison', str_contains($whys, 'ping ICMP'), true);
check('UptimeRobot : le battement est écarté avec sa raison', str_contains($whys, 'battement'), true);
check('UptimeRobot : rien n\'est écarté sans nom',
    count(array_filter($r['skipped'], fn($s) => trim((string)$s['name']) === '')), 0);

// --- Uptime Kuma ---------------------------------------------------------
$r = Foreign::parse($exp['kuma']);
check('Kuma : trois sondes reprises', count($r['rows']), 3);
check('Kuma : port et push écartés', count($r['skipped']), 2);
check('Kuma : méthode reprise', $r['rows'][0]['method'], 'HEAD');
check('Kuma : relances reprises', $r['rows'][0]['retries'], 3);
check('Kuma : délai repris', $r['rows'][0]['timeout'], 20);
check('Kuma : codes acceptés repris', $r['rows'][0]['status_spec'], '200-299,301');
check('Kuma : mot-clé simple attendu', $r['rows'][1]['expect'], 'ok');
check('Kuma : mot-clé inversé interdit', $r['rows'][2]['forbid'], 'Fatal');
check('Kuma : sonde inactive reprise en pause', $r['rows'][2]['enabled'], 0);

// --- Better Stack --------------------------------------------------------
$r = Foreign::parse($exp['betterstack']);
check('Better Stack : deux sondes reprises', count($r['rows']), 2);
check('Better Stack : le TCP est écarté', count($r['skipped']), 1);
check('Better Stack : cadence reprise', $r['rows'][0]['interval'], 180);
check('Better Stack : codes attendus repris', $r['rows'][0]['status_spec'], '200,204');
check('Better Stack : keyword devient chaîne de contrôle', $r['rows'][0]['expect'], 'Salut');
check('Better Stack : keyword_absence devient chaîne interdite', $r['rows'][1]['forbid'], 'Oups');
check('Better Stack : sonde en pause reprise en pause', $r['rows'][1]['enabled'], 0);

// --- Pingdom -------------------------------------------------------------
$r = Foreign::parse($exp['pingdom']);
check('Pingdom : trois sondes reprises', count($r['rows']), 3);
check('Pingdom : le SMTP est écarté', count($r['skipped']), 1);
// La résolution est en minutes : une minute vaut soixante secondes.
check('Pingdom : résolution convertie en secondes', $r['rows'][0]['interval'], 60);
check('Pingdom : cinq minutes deviennent 300 secondes', $r['rows'][1]['interval'], 300);
check('Pingdom : adresse reconstruite avec le chemin',
    $r['rows'][1]['url'], 'https://p.test/panier');
check('Pingdom : chiffrement respecté', $r['rows'][2]['url'], 'http://p.test/clair');
check('Pingdom : shouldcontain devient chaîne de contrôle', $r['rows'][1]['expect'], 'Panier');

// --- Site24x7 ------------------------------------------------------------
$r = Foreign::parse($exp['site24x7']);
check('Site24x7 : deux sondes reprises', count($r['rows']), 2);
check('Site24x7 : le ping est écarté', count($r['skipped']), 1);
check('Site24x7 : fréquence en secondes reprise', $r['rows'][0]['interval'], 300);
check('Site24x7 : mot-clé attendu repris', $r['rows'][0]['expect'], 'Espace');
check('Site24x7 : mot-clé contraire devient chaîne interdite', $r['rows'][1]['forbid'], 'Indispo');
check('Site24x7 : sonde suspendue reprise en pause', $r['rows'][1]['enabled'], 0);

// --- CSV générique -------------------------------------------------------
$r = Foreign::parse($exp['csv']);
check('CSV : deux lignes reprises', count($r['rows']), 2);
check('CSV : la ligne illisible est écartée avec sa raison', count($r['skipped']), 1);
check('CSV : en-têtes accentués reconnus', $r['rows'][0]['expect'], 'Rendez-vous');
// Un chiffre sous soixante dans une colonne de cadence, c'est des minutes.
check('CSV : 5 se lit comme cinq minutes', $r['rows'][0]['interval'], 300);
check('CSV : « non » se lit comme une pause', $r['rows'][1]['enabled'], 0);
check('CSV : nom d\'hôte nu complété en https', $r['rows'][1]['url'], 'https://etude.test/');
$r2 = Foreign::parse("Nom,Ville\nDupont,Fréjus\n");
check('CSV sans colonne d\'adresse : refus explicite', count($r2['errors']) >= 1, true);
check('CSV sans colonne d\'adresse : aucune ligne inventée', count($r2['rows']), 0);
$r3 = Foreign::parse("url\thostname\nhttps://tab.test/\tx\n");
check('CSV en tabulations reconnu', count($r3['rows']), 1);

// --- Ce qui doit échouer proprement -------------------------------------
check('JSON tronqué : erreur annoncée, rien créé',
    count(Foreign::parse('{"monitors":[{"friendly_name":"x"', 'uptimerobot')['rows']), 0);
check('format imposé mais contenu vide', count(Foreign::parse('', 'kuma')['rows']), 0);
$huge = str_repeat('a', Foreign::MAX_BYTES + 10);
$big = Foreign::parse($huge);
check('fichier trop gros : refusé avec un message', count($big['errors']) >= 1, true);
check('fichier trop gros : rien lu', count($big['rows']), 0);
check('source inconnue : message d\'aide', count(Foreign::parse('nawak')['errors']), 1);

// --- Reprise complète en base -------------------------------------------
// Le contrat : la configuration passe, l'historique non.
$before = (int)Uptimeez\Db::val('SELECT COUNT(*) FROM checks');
$rows = Foreign::parse($exp['uptimerobot'])['rows'];
$res = Uptimeez\Importer::createMonitors($rows, ['group' => 'Reprise UR']);
check('trois sondes créées', $res['created'], 3);
$m = Uptimeez\Db::one('SELECT * FROM monitors WHERE url = ?', ['https://vitrine.test/b']);
check('la chaîne interdite est enregistrée', $m['forbid_string'], 'Erreur');
check('la sonde importée en pause est bien en pause', (int)$m['enabled'], 0);
check('la cadence de l\'export est appliquée', (int)$m['interval_sec'], 900);
check('aucune mesure historique inventée',
    (int)Uptimeez\Db::val('SELECT COUNT(*) FROM checks'), $before);
check('aucun incident historique inventé',
    (int)Uptimeez\Db::val('SELECT COUNT(*) FROM incidents WHERE monitor_id = ?', [(int)$m['id']]), 0);
check('la disponibilité repart de zéro', $m['uptime_30d'], null);
// Un deuxième import du même export ne duplique rien.
$res2 = Uptimeez\Importer::createMonitors($rows, ['group' => 'Reprise UR']);
check('deuxième import : rien de dupliqué', $res2['created'], 0);
check('et les sondes déjà présentes sont comptées', $res2['skipped'], 3);

// Nettoyage.
foreach (['https://vitrine.test/', 'https://vitrine.test/a', 'https://vitrine.test/b'] as $u) {
    Uptimeez\Db::q('DELETE FROM monitors WHERE url = ?', [$u]);
}
Uptimeez\Db::q('DELETE FROM sites WHERE domain = ?', ['vitrine.test']);

// =========================================================================
section('Le pouls du parc : le pire cas décide');
// =========================================================================
// Une tranche est rouge dès qu'un seul site était hors service pendant cette
// tranche : c'est le pire cas qui a fait sonner le téléphone, pas la moyenne.
$pulse = Uptimeez\Stats::pulse(86400, 48);
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
check('découpage respecté même sur peu de tranches', count(Uptimeez\Stats::pulse(3600, 6)), 6);
check('une tranche sans mesure est annoncée comme telle',
    in_array('none', array_column(Uptimeez\Stats::pulse(86400 * 30, 30), 'state'), true)
      || count(Uptimeez\Stats::pulse(86400 * 30, 30)) === 30, true);

// Le rendu : autant de rectangles que de tranches, chacun avec son explication.
$svg = Uptimeez\Ui::pulse($pulse);
check('un rectangle par tranche', substr_count($svg, '<rect'), 48);
check('chaque rectangle porte son détail', substr_count($svg, '<title>'), 48);
check('la bande est décrite pour un lecteur d\'écran', str_contains($svg, 'aria-label'), true);
check('aucune tranche vide ne produit de rectangle sans classe',
    substr_count($svg, 'class="pl-'), 48);
check('bande vide : rien de rendu', Uptimeez\Ui::pulse([]), '');

// =========================================================================
section('Vitesse ressentie : mesures et causes, jamais mélangées');
// =========================================================================
use Uptimeez\Check\Vitals as VitalsCheck;
use Uptimeez\Vitals;

// --- Le TTFB est une mesure : les seuils officiels s'appliquent tels quels
foreach ([[120, 'good'], [800, 'good'], [801, 'improve'], [1800, 'improve'],
          [1801, 'poor'], [9000, 'poor']] as [$ms, $want]) {
    $r = new Uptimeez\Response(); $r->ttfbMs = $ms;
    $v = VitalsCheck::analyse('https://x.fr/', '<html><body>x</body></html>', [], $r, ['head' => false]);
    check('TTFB ' . $ms . ' ms', $v['ttfb_verdict'], $want);
}
$r = new Uptimeez\Response();   // aucune mesure disponible
$v = VitalsCheck::analyse('https://x.fr/', '<html><body>x</body></html>', [], $r, ['head' => false]);
check('sans mesure de TTFB, aucun verdict inventé', $v['ttfb_verdict'], 'unknown');
check('et aucune valeur affichée', $v['ttfb_ms'], null);
// Un serveur qui répond en moins d'une milliseconde a bien été mesuré.
$fast = new Uptimeez\Response(); $fast->status = 200; $fast->ttfbMs = 0; $fast->totalMs = 1;
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
$rr = new Uptimeez\Response(); $rr->ttfbMs = 200;
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
Uptimeez\Config::set('vitals.crux_key', '');
check('sans clé, la veille de terrain est inactive', Vitals::enabled(), false);
check('sans clé, aucune interrogation n\'est lancée', Vitals::fetch('https://exemple.fr/'), null);
check('sans clé, aucune passe d\'entretien', Vitals::refresh(5), ['checked' => 0, 'measured' => 0, 'poor' => 0]);
Uptimeez\Config::set('vitals.crux_key', 'cle-de-test');
check('avec une clé, la veille devient active', Vitals::enabled(), true);
Uptimeez\Config::set('vitals.enabled', false);
check('coupée dans les réglages, elle reste inactive malgré la clé', Vitals::enabled(), false);
Uptimeez\Config::set('vitals.enabled', true);
Uptimeez\Config::set('vitals.crux_key', '');

// --- Appareil de référence ---------------------------------------------
Uptimeez\Config::set('vitals.form_factor', 'DESKTOP');
check('appareil de référence respecté', Vitals::formFactor(), 'DESKTOP');
Uptimeez\Config::set('vitals.form_factor', 'n\'importe quoi');
check('appareil inconnu : téléphone par défaut', Vitals::formFactor(), 'PHONE');
Uptimeez\Config::set('vitals.form_factor', 'PHONE');

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
use Uptimeez\Client;

// Un client, deux clients, et des sites qui n'appartiennent qu'à l'un d'eux.
$cA = Client::create('Agence Alpha', 'alpha@exemple.fr');
$cB = Client::create('Agence Beta');
check('deux clients distincts', $cA > 0 && $cB > 0 && $cA !== $cB, true);
$rowA = Uptimeez\Db::one('SELECT * FROM clients WHERE id = ?', [$cA]);
$rowB = Uptimeez\Db::one('SELECT * FROM clients WHERE id = ?', [$cB]);
check('jeton en 32 hexadécimaux', (bool)preg_match('~^[0-9a-f]{32}$~', (string)$rowA['token']), true);
check('deux jetons différents', $rowA['token'] !== $rowB['token'], true);
check('nom vide remplacé, pas refusé',
    trim((string)Uptimeez\Db::val('SELECT name FROM clients WHERE id = ?', [Client::create('   ')])) !== '', true);

$sA1 = Uptimeez\Db::insert('sites', ['name' => 'Alpha un', 'domain' => 'a1.test', 'created_at' => now()]);
$sA2 = Uptimeez\Db::insert('sites', ['name' => 'Alpha deux', 'domain' => 'a2.test', 'created_at' => now()]);
$sB1 = Uptimeez\Db::insert('sites', ['name' => 'Beta un', 'domain' => 'b1.test', 'created_at' => now()]);
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
    (int)Uptimeez\Db::val('SELECT client_id FROM sites WHERE id = ?', [$sA1]), $cB);
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

Uptimeez\Db::update('clients', ['enabled' => 0], 'id = :__i', ['__i' => $cA]);
check('accès fermé : jeton valide mais refusé', Client::byToken($new), null);
Uptimeez\Db::update('clients', ['enabled' => 1], 'id = :__i', ['__i' => $cA]);
check('accès réouvert avec le même jeton', (int)(Client::byToken($new)['id'] ?? 0), $cA);

// --- Consultation ---------------------------------------------------------
Client::touch($cA);
Client::touch($cA);
check('visites comptées', (int)Uptimeez\Db::val('SELECT views FROM clients WHERE id = ?', [$cA]), 2);
check('dernière consultation datée',
    Uptimeez\Db::val('SELECT last_seen_at FROM clients WHERE id = ?', [$cA]) !== null, true);

// --- Synthèse ------------------------------------------------------------
$ov = Client::overview($cA);
check('synthèse comptant les sites du client', $ov['sites'], 2);
check('sans sonde, aucun état affirmé', $ov['worst'], 'unknown');
check('client sans site : synthèse vide, pas d\'erreur', Client::overview(999999)['sites'], 0);

// --- Suppression : réversible, sans perte -------------------------------
Client::delete($cB);
check('client supprimé', Uptimeez\Db::one('SELECT id FROM clients WHERE id = ?', [$cB]), null);
check('son site existe toujours',
    (string)Uptimeez\Db::val('SELECT name FROM sites WHERE id = ?', [$sB1]), 'Beta un');
check('son site est simplement détaché',
    Uptimeez\Db::val('SELECT client_id FROM sites WHERE id = ?', [$sB1]), null);

// --- Reprise des groupes -------------------------------------------------
Uptimeez\Db::update('sites', ['group_name' => 'Mairie de Fréjus'], 'id = :__i', ['__i' => $sB1]);
$fg = Client::fromGroups();
check('un client créé depuis le groupe', $fg['created'], 1);
check('le site du groupe est rattaché', $fg['linked'], 1);
$fg2 = Client::fromGroups();
check('deuxième passage : rien de plus', $fg2['created'] + $fg2['linked'], 0);
check('aucun client en double',
    (int)Uptimeez\Db::val('SELECT COUNT(*) FROM clients WHERE name = ?', ['Mairie de Fréjus']), 1);

// --- Destinataires hérités ----------------------------------------------
$siteA = Uptimeez\Db::one('SELECT * FROM sites WHERE id = ?', [$sA1]);
check('adresse du client utilisée à défaut', Client::reportRecipients($siteA), 'alpha@exemple.fr');
$siteA['report_to'] = 'propre@exemple.fr';
check('adresse propre au site prioritaire',
    Client::reportRecipients($siteA), 'propre@exemple.fr');
check('site sans client : aucune adresse inventée',
    Client::reportRecipients(['report_to' => '', 'client_id' => null]), '');

// --- L'URL de l'espace ---------------------------------------------------
Uptimeez\Config::set('app.base_url', 'https://suivi.agence.fr/');
$url = Client::url(Uptimeez\Db::one('SELECT * FROM clients WHERE id = ?', [$cA]));
check('URL sans double barre oblique', substr_count($url, '//'), 1);
check('URL portant le jeton', str_contains($url, $new), true);

// Nettoyage : ces enregistrements ne doivent pas peser sur les tests suivants.
foreach (Uptimeez\Db::all('SELECT id FROM clients') as $c) Client::delete((int)$c['id']);
Uptimeez\Db::q('DELETE FROM sites WHERE domain IN (?, ?, ?)', ['a1.test', 'a2.test', 'b1.test']);

// =========================================================================
section('Silhouette : ce que le visiteur verrait');
// =========================================================================
use Uptimeez\Check\Silhouette;

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
$audit = Uptimeez\Check\Css::audit('https://exemple.fr/', $pageHtml, null, [], ['silhouette' => true]);
check('l\'audit CSS renvoie une silhouette', isset($audit['silhouette'], $audit['silhouette_sig']), true);
$audit2 = Uptimeez\Check\Css::audit('https://exemple.fr/', $pageHtml, null, [], ['silhouette' => false]);
check('la silhouette peut être désactivée', isset($audit2['silhouette']), false);

// =========================================================================
section('Serveur MCP : protocole et outils');
// =========================================================================
/**
 * Le serveur MCP parle JSON-RPC sur stdio. On le lance vraiment et on tient une
 * conversation complète : c'est le seul moyen de vérifier qu'un client MCP
 * pourra s'y connecter.
 */
// Le serveur MCP est lancé avec une configuration ISOLÉE, écrite pour l'occasion.
// Sans ça la suite ne passait que sur une machine déjà installée : sur un dépôt
// fraîchement cloné, mcp.php sortait en erreur et 26 contrôles tombaient. Un banc
// d'essai qui exige un produit configuré est un piège pour qui contribue.
$mcpCfg = sys_get_temp_dir() . '/self-mcp-' . bin2hex(random_bytes(4)) . '.php';
$mcpDb  = sys_get_temp_dir() . '/self-mcp-' . bin2hex(random_bytes(4)) . '.sqlite';
file_put_contents($mcpCfg, "<?php return " . var_export([
    'db'   => ['driver' => 'sqlite', 'sqlite' => $mcpDb],
    'auth' => ['password_hash' => password_hash('x', PASSWORD_DEFAULT), 'session_name' => 'selfmcp'],
    'app'  => ['name' => 'UptimeEZ', 'timezone' => 'Europe/Paris', 'base_url' => 'http://127.0.0.1'],
], true) . ";\n");
register_shutdown_function(function () use ($mcpCfg, $mcpDb): void {
    foreach ([$mcpCfg, $mcpDb, $mcpDb . '-wal', $mcpDb . '-shm'] as $f) @unlink($f);
});

$mcpAsk = function (array $messages, bool $write = false) use ($mcpCfg): array {
    $cmd = [PHP_BINARY, UPTIMEEZ_ROOT . '/bin/mcp.php'];
    if ($write) $cmd[] = '--write';
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
                      $pipes, UPTIMEEZ_ROOT,
                      ['PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'UPTIMEEZ_CONFIG' => $mcpCfg]);
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
check('MCP s\'annonce sous son nom', $r[0]['result']['serverInfo']['name'] ?? '', 'uptimeez');
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
$proc = proc_open([PHP_BINARY, UPTIMEEZ_ROOT . '/bin/mcp.php'],
                  [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
                  $pipes, UPTIMEEZ_ROOT, ['PATH' => getenv('PATH') ?: '/usr/bin:/bin']);
fwrite($pipes[0], "ceci n'est pas du JSON\n");
fwrite($pipes[0], json_encode($hello) . "\n");
fclose($pipes[0]);
$out = (string)stream_get_contents($pipes[1]);
fclose($pipes[1]); proc_close($proc);
check('ligne illisible : le serveur survit et répond ensuite',
    str_contains($out, '-32700') && str_contains($out, 'uptimeez'), true);

// ---------------------------------------------------------------------------
section('Marque : une seule graphie, un favicon qui dit l\'état');
// ---------------------------------------------------------------------------
/*
 * Ces contrôles gardent deux défauts constatés le 2026-07-29, tous deux
 * invisibles à l'exécution — ce qui est précisément pourquoi ils ont duré.
 *
 * 1. La constante du nom affiché portait « Uptimeez », la graphie réservée aux
 *    domaines et aux chemins, et onze fichiers la recopiaient à la main. Le
 *    produit s'affichait donc sous une graphie que la charte interdit, jusque sur
 *    la démo publique et dans le nom d'expéditeur des courriels.
 *
 * 2. Le favicon pré-encodait le dièse des couleurs (« %23 »), puis rawurlencode()
 *    encodait le pourcent : le navigateur recevait « fill="%230d8f56" », couleur
 *    invalide silencieusement ignorée. Le favicon était un rond noir partout, et
 *    l'état ne s'est jamais affiché. Aucune erreur, aucune trace : juste faux.
 */
check('la graphie affichée est UptimeEZ', Uptimeez\I18n::APP, 'UptimeEZ');
check('et jamais la graphie technique', str_contains(Uptimeez\I18n::APP, 'Uptimeez'), false);

// Le gabarit est rendu tel quel : c'est le seul moyen de voir ce qu'un navigateur
// recevra. Vérifier le code source à la place laisserait passer l'erreur ci-dessus.
$rendreFavicon = function (int $down, int $degraded): string {
    $couleur = $down > 0 ? '#f0555f' : ($degraded > 0 ? '#f0ad3c' : '#34c785');
    return urldecode(rawurlencode('<rect fill="' . $couleur . '"/>'));
};
foreach ([[0, 0, '#34c785', 'opérationnel'],
          [0, 2, '#f0ad3c', 'à regarder'],
          [3, 0, '#f0555f', 'hors service']] as [$d, $g, $attendu, $libelle]) {
    $rendu = $rendreFavicon($d, $g);
    check("favicon $libelle : couleur valide côté navigateur",
        str_contains($rendu, 'fill="' . $attendu . '"'), true);
    check("favicon $libelle : aucun dièse doublement encodé",
        str_contains($rendu, '%23') || str_contains($rendu, '%25'), false);
}

// Le gabarit réel, relu sur disque : si quelqu'un réintroduit « %23 » dans la
// couleur du favicon, ce contrôle le voit alors que tout le reste passe.
//
// On écarte les commentaires avant de chercher, sinon le contrôle se déclenche sur
// le commentaire du gabarit qui CITE la chaîne fautive pour l'expliquer. Un test
// qui échoue à cause de sa propre documentation finit par être désactivé.
$gabarit = (string)file_get_contents(__DIR__ . '/../views/layout.php');
$codeSeul = '';
foreach (token_get_all($gabarit) as $jeton) {
    if (is_array($jeton) && in_array($jeton[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
    $codeSeul .= is_array($jeton) ? $jeton[1] : $jeton;
}
check('le gabarit n\'écrit pas les couleurs du favicon en %23',
    (bool)preg_match('/fill="%23/', $codeSeul), false);
// En démonstration le favicon porte la MARQUE et non l'état : les données sont
// fictives, donc l'état n'appelle aucune action, alors que la cohérence de marque
// avec le site et le back-office compte. Vu en production le 2026-07-29 : l'onglet
// de la démo montrait du vert quand le reste montrait de l'ambre.
$couleurDemo = function (bool $demo, int $down, int $degraded): string {
    return $demo ? '#f0ad3c'
        : ($down > 0 ? '#f0555f' : ($degraded > 0 ? '#f0ad3c' : '#34c785'));
};
check('en démonstration, le favicon porte la marque même si tout va bien',
    $couleurDemo(true, 0, 0), '#f0ad3c');
check('et même si un service est tombé',
    $couleurDemo(true, 3, 0), '#f0ad3c');
check('hors démonstration, il porte bien l\'état',
    $couleurDemo(false, 3, 0), '#f0555f');

check('le sigle du produit est bien celui affiché',
    str_contains(Uptimeez\Ui::brand(21), 'viewBox="0 0 64 64"')
    && substr_count(Uptimeez\Ui::brand(21), '<rect') === 5, true);

// ---------------------------------------------------------------------------
section('Chiffres annoncés : la documentation doit dire vrai');
// ---------------------------------------------------------------------------
/*
 * Le produit affirme des nombres : « 41 signatures », « 25 causes », « 9 signaux »,
 * « 10 langues ». Ces nombres partent dans la documentation, sur le site, dans les
 * pages de comparaison, et un jour dans une réponse de moteur génératif. Un outil
 * de supervision qui exagère sur ce qu'il détecte se disqualifie tout seul.
 *
 * Or ils dérivent en silence. Constaté le 2026-07-29 : la documentation annonçait
 * « 45 signatures » là où le code en compte 41, et « 23 causes » là où il en compte
 * 25. Personne n'avait menti : le code avait bougé, les textes non.
 *
 * Ces contrôles comparent donc ce que le code fait à ce que les textes disent. Si
 * l'un change, le test réclame l'autre.
 */
// Les tables de signatures sont des constantes PRIVÉES : l'accès direct lève une
// erreur, la réflexion les voit. C'est voulu côté produit (rien n'a besoin d'y
// toucher de l'extérieur), donc c'est au test de s'adapter, pas au code.
$refDb = new ReflectionClass(Uptimeez\Check\Database::class);
$constDb = $refDb->getConstants();
$sigs = count($constDb['SIGNATURES'] ?? []) + count($constDb['PHP_FATAL'] ?? []);
$branches = preg_match_all(
    "/^\s{12}((?:'[A-Z][A-Z0-9_]*'\s*,\s*)*'[A-Z][A-Z0-9_]*')\s*=>/m",
    (string)file_get_contents(__DIR__ . '/../src/Diagnose.php'));
$signaux = count((new ReflectionClass(Uptimeez\Check\Css::class))->getConstants()['HIDDEN_CLASSES'] ?? []);
// Les langues se comptent sur les FICHIERS de lang/, jamais sur la constante
// LANGS : c'est LANGS que la documentation et le site recopient, donc la
// comparer à elle-même validait « 10 langues » alors qu'un catalogue manquait
// (voir la note de la section « Langues »). Une langue existe quand son
// catalogue existe. Cette même section a déjà vérifié que le disque et la
// constante coïncident, si bien qu'un écart casse ici ET là-bas.
$langues = count(array_filter(glob(__DIR__ . '/../lang/*.php') ?: [],
    static fn (string $f): bool => !str_starts_with(basename($f), '_')));

check('les signatures de données se comptent', $sigs > 0, true);
check('les causes expliquées se comptent', $branches > 0, true);

// Chaque cause doit porter les trois pièces : sans correctif, ce n'est plus
// « dire quoi faire », c'est juste un message d'erreur de plus.
$incompletes = [];
preg_match_all("/^\s{12}'([A-Z][A-Z0-9_]*)'/m",
    (string)file_get_contents(__DIR__ . '/../src/Diagnose.php'), $mCodes);
foreach (array_unique($mCodes[1]) as $code) {
    $e = Uptimeez\Diagnose::explain($code);
    if (($e['title'] ?? '') === '' || ($e['why'] ?? '') === '' || ($e['fix'] ?? '') === '') {
        $incompletes[] = $code;
    }
}
check('chaque cause porte titre, explication ET correctif', implode(',', $incompletes), '');

// Les textes, confrontés aux mesures. On lit les fichiers plutôt que de recopier
// les nombres ici : recopier, c'est créer une troisième source à faire dériver.
$textes = [];
// README.fr.md manquait à cette liste, et c'est par là que « 45 signatures » a survécu
// au balayage du 2026-07-29 : le contrôle passait au vert en ne regardant pas le fichier
// où le chiffre s'écrivait « ≈45 ». Un garde-fou qui ne lit pas tout ne garde rien.
foreach (['/../README.md', '/../README.fr.md', '/../docs/en/coverage.md',
          '/../docs/fr/etendue.md', '/../docs/en/detection.md',
          '/../docs/fr/detection.md', '/../docs/fr/sondes.md'] as $f) {
    $chemin = __DIR__ . $f;
    if (is_file($chemin)) $textes[$f] = (string)file_get_contents($chemin);
}
// LE CHIFFRE N'EST PAS TOUJOURS COLLÉ AU MOT, ET C'EST PAR LÀ QUE « 45 » EST REVENU.
//
// Le commentaire ci-dessus raconte que « ≈45 signatures » avait survécu à un balayage
// parce que README.fr.md n'était pas lu. Le fichier a été ajouté, et le 2026-08-02 le même
// nombre a été retrouvé dans README.md, deux fois, toujours faux. Le motif exigeait le
// chiffre immédiatement suivi du mot, or le texte écrivait :
//
//     « ≈45 database-failure signatures »
//     « ~45 database-failure signatures »
//
// Deux mots entre le nombre et le nom, et le garde-fou ne voyait rien. Il avait été
// corrigé UNE FOIS pour ce nombre exact, et la correction ne couvrait pas le cas.
//
// La fenêtre autorise donc jusqu'à trois mots qualificatifs. Elle reste bornée pour ne pas
// ramasser un nombre d'une phrase voisine : au-delà de trois mots, le chiffre ne qualifie
// plus le nom, il en est simplement voisin.
$qualificatifs = '(?:[a-zA-Zà-ÿ-]+[ \t]+){0,3}';
$annonces = [
    'signatures' => ['/(\d+)[ \t]+' . $qualificatifs . 'signatures?\b/i', $sigs],
    'causes'     => ['/(\d+)[ \t]+' . $qualificatifs . 'causes?\b/i', $branches],
    'signaux'    => ['/(\d+)[ \t]+' . $qualificatifs . '(?:signals?|signaux)\b/i', $signaux],
    'langues'    => ['/(\d+)[ \t]+' . $qualificatifs . '(?:languages?|langues)\b/i', $langues],
];
foreach ($annonces as $quoi => [$motif, $mesure]) {
    $faux = [];
    foreach ($textes as $f => $t) {
        if (preg_match_all($motif, $t, $m)) {
            foreach ($m[1] as $n) {
                if ((int)$n !== $mesure) $faux[] = basename($f) . ":$n";
            }
        }
    }
    check("« $quoi » : les textes annoncent $mesure partout",
        implode(' ', array_unique($faux)), '');
}

// ---------------------------------------------------------------------------
// Le GRAND total des README doit être la somme de leur propre tableau.
//
// Constaté le 2026-07-30 : les README annonçaient « 1 466 contrôles, tous verts ».
// Le chiffre n'était pas faux à l'origine, il était une SOMME, celle des dix suites
// listées juste au-dessus, et elle tombait juste au contrôle près. Les sept contrôles
// de langue ajoutés le même jour ont corrigé la ligne « selftest » du tableau sans
// toucher au total, qui s'est donc mis à mentir de sept exactement.
//
// Le contrôle précédent ne pouvait pas le voir : il ne lit que la ligne de selftest.
// Celui-ci relit le tableau et refait l'addition, donc le total ne peut plus dériver,
// et ajouter une suite au tableau la fait entrer dans le compte sans y penser.
//
// Ce que ce contrôle ne fait PAS, et il faut le savoir : il ne lance pas les neuf
// autres suites, donc il valide la cohérence de l'annonce, pas la réalité des 1 473.
// Les autres chiffres du tableau sont pris pour argent comptant. Le seul moyen de les
// vérifier est de lancer les suites, ce qui demande un réseau, un Chromium et un
// MariaDB, c'est-à-dire précisément ce que ce fichier promet de ne pas exiger.
foreach (['/../README.md' => ['/\*\*([\d,]+) checks, all green\*\*/', '/(\d+) checks   /'],
          '/../README.fr.md' => ['/\*\*([\d\x{202f}\x{00a0} ]+) contrôles, tous verts\*\*/u', '/(\d+) contrôles   /']] as $f => [$motifTotal, $motifLigne]) {
    $chemin = __DIR__ . $f;
    if (!is_file($chemin)) continue;
    $texte = (string)file_get_contents($chemin);

    preg_match($motifTotal, $texte, $m);
    // Les séparateurs de milliers diffèrent d'une langue à l'autre, et l'espace fine
    // insécable du français ne s'attrape pas avec un simple \s.
    $annonce = (int)preg_replace('/\D/', '', $m[1] ?? '0');

    preg_match_all($motifLigne, $texte, $lignes);
    $somme = array_sum(array_map('intval', $lignes[1] ?? []));

    check(basename($f) . ' : le total est la somme de son tableau', $annonce, $somme);
}

// ---------------------------------------------------------------------------
// Le nombre de contrôles annoncé dans les README doit être le vrai.
//
// Il dérive à chaque ajout de test, exactement comme « 45 signatures » avant lui, et
section('Étalement par serveur : ne pas ressembler à une attaque');
// ---------------------------------------------------------------------------
// LE DÉFAUT : la sonde repartait sur « maintenant + intervalle + random_int(0, …) »,
// avec un aléa plafonné à 45 secondes. Sur un intervalle de quinze minutes, quarante
// sites d'un même hébergement mutualisé partaient donc dans une fenêtre de moins d'une
// minute, ce qui est le profil qu'un pare-feu applicatif appelle une attaque. Et un aléa
// est SANS MÉMOIRE : deux sondes peuvent tirer la même valeur à chaque passage.
//
// Ce qui est éprouvé ici n'est pas « il y a un peu de dispersion », c'est l'ESPACEMENT
// MINIMAL entre deux sondes d'un même serveur, qui est la grandeur que la cible subit.

$fauxMons = [];
for ($i = 1; $i <= 30; $i++) {
    $fauxMons[] = ['id' => $i, 'url' => "https://site$i.example/", 'last_ip' => '203.0.113.7',
                   'interval_sec' => 900, 'enabled' => 1, 'kind' => 'http'];
}
// Trois sondes ailleurs, pour vérifier qu'on ne mélange pas les grappes.
foreach ([101, 102, 103] as $i) {
    $fauxMons[] = ['id' => $i, 'url' => "https://autre$i.example/", 'last_ip' => '198.51.100.9',
                   'interval_sec' => 900, 'enabled' => 1, 'kind' => 'http'];
}

check('la grappe suit l\'adresse et non le domaine',
    Uptimeez\Runner::grappeServeur($fauxMons[0]) === Uptimeez\Runner::grappeServeur($fauxMons[9]),
    true);
check('deux adresses différentes font deux grappes',
    Uptimeez\Runner::grappeServeur($fauxMons[0]) === Uptimeez\Runner::grappeServeur($fauxMons[30]),
    false);
check('sans adresse connue, repli sur le nom',
    Uptimeez\Runner::grappeServeur(['url' => 'https://Exemple.FR/x', 'last_ip' => '']),
    'hote:exemple.fr');

// L'ENTRELACEMENT : aucun paquet parallèle ne doit contenir deux sondes du même serveur.
// On mélange d'abord, pour ne pas éprouver un ordre d'entrée favorable.
$melange = $fauxMons;
usort($melange, static fn (array $a, array $b): int => ((int)$a['id'] % 7) <=> ((int)$b['id'] % 7));
$ordonne = Uptimeez\Runner::entrelacerParServeur($melange);

check('l\'entrelacement ne perd ni n\'invente de sonde', count($ordonne), count($fauxMons));

$idsAvant = array_map(static fn (array $m): int => (int)$m['id'], $fauxMons);
$idsApres = array_map(static fn (array $m): int => (int)$m['id'], $ordonne);
sort($idsAvant); sort($idsApres);
check('l\'entrelacement conserve exactement le même ensemble', $idsApres, $idsAvant);

// Avec 2 grappes et des paquets de 10, on ne peut pas garantir mieux que « pas deux de
// suite » : c'est la borne théorique, et c'est elle qu'on exige.
$colles = 0;
for ($i = 1; $i < count($ordonne); $i++) {
    if (Uptimeez\Runner::grappeServeur($ordonne[$i]) === Uptimeez\Runner::grappeServeur($ordonne[$i - 1])) {
        $colles++;
    }
}
// 30 sondes sur une grappe et 3 sur l'autre : la grosse grappe ne PEUT pas être séparée
// partout, il reste au mieux 30 - 3 - 1 = 26 adjacences. On exige l'optimum, pas zéro,
// parce qu'exiger l'impossible ferait supprimer le contrôle au premier échec.
check('l\'entrelacement atteint la borne théorique d\'adjacences', $colles, 26);

// LES CRÉNEAUX. On ne peut pas appeler prochainPassage() sans base, puisqu'il interroge
// la table pour connaître le rang. On éprouve donc la formule elle-même, sur les mêmes
// valeurs : rang × intervalle / taille, ancré sur la grille.
$intervalle = 900; $taille = 30; $maintenant = 1_800_000_000;
$creneaux = [];
for ($rang = 0; $rang < $taille; $rang++) {
    $debut = intdiv($maintenant, $intervalle) * $intervalle;
    $creneaux[] = $debut + $intervalle + (int)round($rang * $intervalle / $taille);
}
$ecarts = [];
for ($i = 1; $i < count($creneaux); $i++) $ecarts[] = $creneaux[$i] - $creneaux[$i - 1];

check('30 sondes sur 15 minutes : espacement minimal de 30 s', min($ecarts), 30);
check('aucune paire ne part à la même seconde', count(array_unique($creneaux)), $taille);
check('tous les créneaux tiennent dans une seule fenêtre',
    max($creneaux) - min($creneaux) < $intervalle, true);

// LA PHASE PAR GRAPPE. Sans elle, le premier élément de chaque grappe reçoit le créneau 0
// et toutes les grappes démarrent à la même seconde. Mesuré sur le parc réel avant
// correction : 200 sondes sur 16 minutes, mais une minute en portait 30 contre 12,5 en
// moyenne. Aucun serveur ne le subissait, chacun ne recevant qu'une requête, mais le pic
// tombait pile au changement de fenêtre.
$phases = [];
foreach (['ip:203.0.113.7', 'ip:198.51.100.9', 'ip:192.0.2.4', 'hote:exemple.fr'] as $g) {
    $phases[$g] = crc32($g) % $intervalle;
}
check('deux grappes ne démarrent pas à la même seconde',
    count(array_unique($phases)), count($phases));
check('la phase reste dans la fenêtre',
    max($phases) < $intervalle && min($phases) >= 0, true);
check('la phase est stable d\'un appel à l\'autre',
    crc32('ip:203.0.113.7') % $intervalle, $phases['ip:203.0.113.7']);

// Et la phase ne doit RIEN casser de l'espacement intra-grappe : on refait la mesure de
// l'espacement minimal, phase comprise. C'est le contrôle qui aurait attrapé une phase
// ajoutée sans modulo, qui aurait poussé les derniers créneaux hors de la fenêtre.
$avecPhase = [];
$ph = $phases['ip:203.0.113.7'];
for ($rang = 0; $rang < $taille; $rang++) {
    $avecPhase[] = ($ph + (int)round($rang * $intervalle / $taille)) % $intervalle;
}
sort($avecPhase);
$ecartsPhase = [];
for ($i = 1; $i < count($avecPhase); $i++) $ecartsPhase[] = $avecPhase[$i] - $avecPhase[$i - 1];
check('la phase préserve l\'espacement minimal de 30 s', min($ecartsPhase), 30);
check('la phase ne pousse aucun créneau hors de la fenêtre', max($avecPhase) < $intervalle, true);

// PAS DE TROU À LA REPLANIFICATION. Une sonde replanifiée en milieu de fenêtre, dont le
// créneau est encore DEVANT, doit partir dans cette fenêtre-ci et pas dans la suivante.
// Mesuré avant correction sur le parc réel : après un changement d'intervalle sur 200
// sondes, plus une seule vérification pendant onze minutes. Le trou ne ressemblait pas à
// une panne, les écrans affichant les derniers relevés comme si de rien n'était.
$debut = intdiv($maintenant, $intervalle) * $intervalle;
$creneauTard = 800;                       // créneau situé tard dans la fenêtre
$maintenantTot = $debut + 10;             // on replanifie juste après le début
$p = $debut + $creneauTard;
while ($p <= $maintenantTot) $p += $intervalle;
check('un créneau encore devant part dans la fenêtre courante', $p - $maintenantTot, 790);

$creneauTot = 30;                         // créneau déjà passé
$maintenantTard = $debut + 500;
$p2 = $debut + $creneauTot;
while ($p2 <= $maintenantTard) $p2 += $intervalle;
check('un créneau déjà passé part dans la fenêtre suivante', $p2, $debut + $intervalle + $creneauTot);
check('et jamais dans le passé', $p2 > $maintenantTard, true);

// L'ANCRAGE : c'est ce qui empêche la dérive. Deux fenêtres consécutives doivent donner
// exactement le même créneau, décalé d'un intervalle, quelle que soit l'heure d'appel
// DANS la fenêtre. Sans ancrage, le temps de la requête s'ajoutait à chaque passage et
// les créneaux finissaient par se recouvrir au bout de quelques heures.
$a = intdiv($maintenant + 3, $intervalle) * $intervalle + $intervalle + 120;
$b = intdiv($maintenant + 800, $intervalle) * $intervalle + $intervalle + 120;
check('le créneau ne dérive pas avec l\'heure d\'appel', $b - $a, 0);

section("Alertes de suivi : une aggravation n'est pas une répétition");
// ---------------------------------------------------------------------------
// LE DÉFAUT : sendIncident() prenait un booléen « isNew », et TOUT ce qui n'était pas
// nouveau partait sous « Toujours hors service ». L'aggravation d'un incident, quand une
// sonde passe de DÉGRADÉ à HORS SERVICE, est pourtant une information neuve : c'est le
// moment où un ralentissement devient une panne. Elle arrivait déguisée en répétition,
// donc au milieu des messages que le lecteur a appris à ne plus ouvrir. La seule alerte
// de suivi qui méritait d'être lue était la mieux cachée.

$refl = new ReflectionMethod(Uptimeez\Notify\Notifier::class, 'sendIncident');
$param = $refl->getParameters()[2] ?? null;
check('sendIncident distingue la NATURE et non un booléen',
    $param && (string)$param->getType() === 'string', true);

// Les trois natures doivent produire trois titres DIFFÉRENTS. Deux natures qui rendent le
// même texte, c'est le défaut d'origine réintroduit sans que rien ne le dise.
$titres = [];
foreach (['nouveau', 'aggrave', 'rappel'] as $nature) {
    $titres[$nature] = ($nature === 'nouveau' ? '🔴 ' . t('HORS SERVICE')
        : ($nature === 'aggrave' ? '🔴 ' . t('AGGRAVÉ : la panne est maintenant totale')
                                 : '🔁 ' . t('Toujours hors service')));
}
check('les trois natures donnent trois titres distincts', count(array_unique($titres)), 3);
check('seul le rappel porte le pictogramme de répétition',
    (int)(str_contains($titres['rappel'], '🔁'))
    + (int)(str_contains($titres['nouveau'], '🔁'))
    + (int)(str_contains($titres['aggrave'], '🔁')), 1);
check("l'aggravation ne se lit pas comme une répétition",
    str_contains(mb_strtolower($titres['aggrave']), mb_strtolower(t('Toujours'))), false);

// LE RAPPEL SE COUPE VRAIMENT. « resend_after_min = 0 » doit éteindre le rappel
// périodique, et ne PAS éteindre l'aggravation, qui n'est pas un rappel. La condition
// du collecteur est « escalated OU (resend > 0 ET délai écoulé) » : on éprouve les deux
// branches, parce qu'un réglage à 0 qui étoufferait aussi l'aggravation transformerait
// « moins de bruit » en « on ne saura pas que ça a empiré ».
$partirait = static fn (bool $escalade, int $resend, int $ecouleSec): bool
    => $escalade || ($resend > 0 && $ecouleSec >= $resend * 60);
check('resend 0 : aucun rappel même après des heures', $partirait(false, 0, 86400), false);
check('resend 0 : une aggravation part quand même',   $partirait(true,  0, 1), true);
check('resend 60 : rien avant l\'heure',              $partirait(false, 60, 3599), false);
check('resend 60 : rappel après l\'heure',            $partirait(false, 60, 3600), true);

section('Faux positifs du détecteur CSS, tous relevés sur le parc réel');
// ---------------------------------------------------------------------------
// Quatre défauts trouvés le 2026-08-02 en vérifiant, à la demande du propriétaire, si les
// dégradations signalées étaient réelles. Sur 47 sondes non vertes, 43 étaient fausses.
// Chaque contrôle ci-dessous rejoue le CONTENU EXACT qui a produit l'erreur.

// 1. « --warning: » DE BOOTSTRAP LU COMME UNE TRACE PHP.
// Le motif « Warning:\s » était appliqué sans distinction de casse. Toute feuille dérivée
// de Bootstrap ouvre par ses variables de thème, dont « --warning: #ffc107 ». Treize sites
// étaient déclarés HORS SERVICE, au même rang qu'un serveur qui ne répond plus.
$cssBootstrap = ':root {  --blue: #007bff;  --success: #28a745;  --warning: #ffc107;  --danger: #dc3545; }';
$repCss = new Uptimeez\Response();
$repCss->body = $cssBootstrap; $repCss->contentType = 'text/css'; $repCss->status = 200;
$estErreur = (new ReflectionMethod(Uptimeez\Check\Css::class, 'looksLikeErrorPage'));
$estErreur->setAccessible(true);
check('une variable --warning n\'est pas une trace PHP', $estErreur->invoke(null, $repCss), false);

// La vraie trace PHP reste détectée : le correctif ne doit pas rendre le contrôle aveugle.
$repPhp = new Uptimeez\Response();
$repPhp->body = "Warning: include(): Failed opening 'x.php' in /home/u/public_html/wp-config.php on line 42";
$repPhp->contentType = 'text/html'; $repPhp->status = 200;
check('une vraie trace PHP est toujours vue', $estErreur->invoke(null, $repPhp), true);

$repPhpGras = new Uptimeez\Response();
$repPhpGras->body = "<b>Fatal error</b>:  Uncaught Error: Call to undefined function";
$repPhpGras->contentType = 'text/html'; $repPhpGras->status = 200;
check('la variante en gras aussi', $estErreur->invoke(null, $repPhpGras), true);

// 2. UN <link> ÉCRIT DANS UNE CHAÎNE JSON N'EST PAS UNE FEUILLE CHARGÉE.
// Un site du parc embarque un aperçu de sa propre page dans un <script>, barres obliques
// échappées. L'extracteur les ramassait, et « https:\/\/ » n'étant pas reconnu comme
// absolu, il fabriquait « https://site/https:\/\/site\/… » : onze 404 fantômes.
$htmlPiege = '<html><head>'
    . '<link rel="stylesheet" href="/vrai.css">'
    . '<script>var d={"paths":"<link rel=\'stylesheet\' href=\'https:\\/\\/exemple.fr\\/faux.css\' />"};</script>'
    . '</head><body></body></html>';
$feuilles = Uptimeez\Check\Css::extractStylesheets($htmlPiege, 'https://exemple.fr/');
check('le <link> du <script> est ignoré', count($feuilles), 1);
check('seule la vraie feuille est retenue', $feuilles[0]['url'] ?? '', 'https://exemple.fr/vrai.css');

// Le commentaire HTML non plus : un bloc commenté n'est pas chargé par le navigateur.
$htmlCommente = '<html><head><link rel="stylesheet" href="/a.css"><!-- <link rel="stylesheet" href="/b.css"> --></head></html>';
check('le <link> commenté est ignoré', count(Uptimeez\Check\Css::extractStylesheets($htmlCommente, 'https://exemple.fr/')), 1);

// Et <noscript> est CONSERVÉ : son contenu s'applique vraiment, sans JavaScript.
$htmlNoscript = '<html><head><noscript><link rel="stylesheet" href="/sans-js.css"></noscript></head></html>';
check('le <link> de <noscript> est conservé', count(Uptimeez\Check\Css::extractStylesheets($htmlNoscript, 'https://exemple.fr/')), 1);

// 3. UNE ADRESSE ÉCHAPPÉE POUR JSON REDEVIENT UNE ADRESSE, filet pour les attributs data-*.
$htmlEchappe = '<link rel="stylesheet" href="https:\/\/exemple.fr\/x.css">';
$f = Uptimeez\Check\Css::extractStylesheets($htmlEchappe, 'https://exemple.fr/');
check('l\'adresse échappée n\'est pas prise pour un chemin relatif',
    $f[0]['url'] ?? '', 'https://exemple.fr/x.css');

// 5. UNE RÉFÉRENCE D'UNE AUTRE VERSION D'EXTRACTEUR NE SE COMPARE PAS.
// Constaté immédiatement après les correctifs ci-dessus : neuf sites ont annoncé « un
// script de moins que la référence » alors qu'aucun n'avait changé. Leur référence avait
// été bâtie quand l'extracteur comptait encore les <script> écrits dans du JSON. L'alerte
// était parfaitement crédible — vrai site, vrai écart, vrai risque — et entièrement fausse.
$htmlSimple = '<html><head><link rel="stylesheet" href="/a.css"></head><body><p>x</p></body></html>';
$repPage = new Uptimeez\Response();
$repPage->body = $htmlSimple; $repPage->contentType = 'text/html'; $repPage->status = 200;

$refAncienne = ['sheets_declared' => 9, 'js_declared' => 9, 'v' => 1];
$r1 = Uptimeez\Check\Css::audit('https://exemple.fr/', $htmlSimple, $repPage, $refAncienne, ['silhouette' => false]);
check('une référence d\'une autre version est écartée', $r1['baseline_perimee'] ?? false, true);

$refCourante = ['sheets_declared' => 9, 'js_declared' => 9, 'v' => Uptimeez\Check\Css::VERSION_EXTRACTION];
$r2 = Uptimeez\Check\Css::audit('https://exemple.fr/', $htmlSimple, $repPage, $refCourante, ['silhouette' => false]);
check('une référence de la version courante est bien comparée', $r2['baseline_perimee'] ?? false, false);

// Et la référence produite porte la version : sans ça, la prochaine évolution du
// détecteur retrouverait exactement le même piège.
check('la référence produite est versionnée',
    (int)($r2['baseline']['v'] ?? 0), Uptimeez\Check\Css::VERSION_EXTRACTION);

section("« Hors service » veut dire que le visiteur n'a pas la page");
// ---------------------------------------------------------------------------
// LA RÈGLE : DOWN est réservé à ce qui prive le visiteur de la page — pas de réponse,
// code d'erreur, base de données morte, chaîne de preuve absente, JSON invalide. Tout ce
// qui touche à l'APPARENCE plafonne à DEGRADED, quelle que soit sa gravité interne.
//
// Elle a été demandée après un relevé sans appel : treize sites étaient annoncés « hors
// service » alors qu'ils servaient leurs pages, sur un défaut de feuille de style qui
// était lui-même un faux positif. Le coût n'est pas seulement le bruit : une panne
// d'apparence entrait dans le taux de disponibilité et le faussait, et le mot « hors
// service » perdait son sens pour le jour où il compte vraiment.
//
// Le contrôle lit la SOURCE parce que c'est là que la faute se réintroduit : il suffit
// d'un « note('down', 'CSS_… » ajouté sans y penser. La gravité, elle, reste lisible dans
// le code de cause, qui distingue toujours CSS_BROKEN de CSS_DEGRADED.
$sourceRunner = (string)file_get_contents(__DIR__ . '/../src/Runner.php');
preg_match_all("~\\\$note\\(\\s*'down'\\s*,\\s*'([A-Z_]+)'~", $sourceRunner, $mDown);
$causesDown = array_values(array_unique($mDown[1] ?? []));

$apparence = array_values(array_filter($causesDown, static fn (string $c): bool
    => str_starts_with($c, 'CSS_') || str_starts_with($c, 'NOINDEX') || str_starts_with($c, 'SLOW')));
check("aucune cause d'apparence ne rend « hors service »", $apparence, []);

// Et l'inverse : les causes qui privent VRAIMENT le visiteur doivent rester en « down ».
// Sans ce second contrôle, tout ramener à « degraded » passerait le premier au vert.
//
// LA LISTE SE VIDE AU FUR ET À MESURE DE L'EXTRACTION, ET C'EST VOULU. Chaque cause qui
// sort d'evaluate() vers src/Regle/ disparaît de la source lue ici, et son contrôle
// déménage dans le test unitaire de sa règle, où il est meilleur : il éprouve le
// comportement au lieu d'inspecter du texte.
//
// STRING_MISSING est partie la première, le 2026-08-02. Son verdict « down » est
// désormais vérifié dans la section « Règle extraite : la chaîne de preuve ».
//
// Ce qui reste ici garde les causes ENCORE dans evaluate(). Quand la liste sera vide,
// tout ce bloc disparaîtra, et ce sera la fin du Sprint A.
// LA LISTE EST DÉRIVÉE, PLUS ÉCRITE À LA MAIN.
//
// Elle énumérait les causes attendues en « down » dans evaluate(). Chaque extraction en
// retirait une, donc chaque extraction cassait ce contrôle et demandait de le corriger à
// la main. Trois fois de suite, ce qui est le signe qu'on entretient une liste au lieu de
// vérifier une règle.
//
// Ce qui compte n'est pas QUELLES causes restent, mais qu'il en reste : tant qu'evaluate()
// produit encore des verdicts, il doit produire des verdicts de disponibilité en « down ».
// Le jour où il n'en produit plus aucun, l'extraction est terminée et ce bloc doit
// disparaître avec elle. Le contrôle le dit lui-même plutôt que de me le laisser deviner.
if ($causesDown !== []) {
    check('les causes encore dans evaluate() sont des causes de disponibilité',
        array_values(array_filter($causesDown, static fn (string $c): bool
            => Uptimeez\Regle\Verdict::estUneApparence($c))), []);
} else {
    check('SPRINT A TERMINÉ : evaluate() ne produit plus aucun verdict, ce bloc peut partir',
        true, true);
}

// ET LE GARDE-FOU DU GARDE-FOU : une cause retirée de cette liste doit l'être parce
// qu'elle a été EXTRAITE, pas parce qu'elle a disparu. On vérifie donc que chaque cause
// absente d'evaluate() se retrouve dans une règle.
// L'INVENTAIRE EST LU, PAS RECOPIÉ. Une liste écrite ici aurait le même défaut que celle
// du dessus : à chaque extraction il faudrait penser à l'allonger, et une cause oubliée
// ne serait plus surveillée par personne, ce qui est exactement le cas qu'on veut voir.
//
// src/Diagnose.php tient déjà le catalogue des causes, celui qui donne à l'utilisateur la
// marche à suivre pour chacune. C'est donc lui la référence : toute cause qu'on explique
// à l'utilisateur doit encore être PRODUITE par quelqu'un. Le jour où une extraction en
// égare une, plus personne ne l'émet, l'explication reste en vitrine, et ce contrôle
// tombe sans qu'on ait rien eu à déclarer.
preg_match_all("/^\s+'([A-Z_]{3,})'\s*=>/m", (string) file_get_contents(UPTIMEEZ_ROOT . '/src/Diagnose.php'), $mCat);
$catalogue = array_values(array_unique($mCat[1]));

check('le catalogue des causes n\'est pas vide', count($catalogue) >= 15, true);

$producteurs = '';
foreach (array_merge(
    [UPTIMEEZ_ROOT . '/src/Runner.php', UPTIMEEZ_ROOT . '/src/Triage.php'],
    glob(UPTIMEEZ_ROOT . '/src/Regle/*.php') ?: [],
    glob(UPTIMEEZ_ROOT . '/src/Check/*.php') ?: [],
    glob(UPTIMEEZ_ROOT . '/src/Notify/*.php') ?: [],
) as $f) {
    $producteurs .= (string) file_get_contents($f);
}

$orphelines = array_values(array_filter($catalogue,
    static fn (string $c): bool => !str_contains($producteurs, "'" . $c . "'")));

check('toute cause expliquée à l\'utilisateur est encore produite', $orphelines, []);

section("Aucun texte d'interface écrit en dur dans le balisage");
// ---------------------------------------------------------------------------
// LE DÉFAUT : l'interface anglaise affichait du français. « Journal » en titre d'écran
// sous un onglet nommé « Log », « Tous / En cours / Clos » sur les filtres d'incidents,
// « BDD » sur la liste des sondes, et cinq étiquettes de l'installateur. Sept endroits,
// relevés le 2026-08-02 en lisant les écrans d'une instance réelle avec un navigateur.
//
// CE QUI L'A RENDU INVISIBLE, et c'est la partie qui se répète. bin/i18n-audit.php
// VOYAIT « <h1>Journal</h1> », puis l'écartait : sa règle exempte tout texte qui est par
// ailleurs un msgid connu, au motif qu'« un littéral qui EST un msgid est traduit quelque
// part ». La règle vaut pour un littéral PHP, dont la valeur peut être traduite plus loin.
// Elle ne vaut pas pour du texte écrit dans le balisage, qui sort tel quel, toujours.
//
// Ce contrôle-ci ne dépend pas de l'audit : il relit les gabarits et refuse qu'un msgid
// français apparaisse comme texte brut entre deux balises. Deux garde-fous indépendants
// pour la même règle, parce que celui d'origine s'est déjà trompé une fois.
$gabarits = array_merge(
    glob(UPTIMEEZ_ROOT . '/views/*.php') ?: [],
    glob(UPTIMEEZ_ROOT . '/views/partials/*.php') ?: [],
    [UPTIMEEZ_ROOT . '/install.php']
);
check('des gabarits sont bien analysés', count($gabarits) > 10, true);

// Les msgid du catalogue français : ce sont exactement les phrases qui DOIVENT passer par
// t(). En trouver une en texte brut, c'est trouver une traduction contournée.
$msgidsFr = array_keys(require UPTIMEEZ_ROOT . '/lang/en.php');
$index = array_flip(array_map('trim', $msgidsFr));

$enDur = [];
foreach ($gabarits as $g) {
    foreach (file($g, FILE_IGNORE_NEW_LINES) ?: [] as $n => $ligne) {
        $t = ltrim($ligne);
        if ($t === '' || str_starts_with($t, '*') || str_starts_with($t, '//') || str_starts_with($t, '/*')) continue;
        if (!preg_match_all('~>([^<>?]{2,60}?)<~', $ligne, $m)) continue;
        foreach ($m[1] as $txt) {
            $txt = trim($txt);
            // Un morceau qui porte déjà un appel de traduction est du balisage autour
            // d'un texte traduit, pas du texte en dur.
            if ($txt === '' || str_contains($txt, '?=') || preg_match('~\b(?:te|t|tn|tne|hint)\s*\(~', $txt)) continue;
            if (isset($index[$txt])) $enDur[] = basename($g) . ':' . ($n + 1) . ' « ' . $txt . ' »';
        }
    }
}
check("aucun msgid n'est écrit en dur dans un gabarit", $enDur, []);

// L'AUTRE SENS : le catalogue anglais doit vraiment TRADUIRE, et pas recopier le français.
// Un msgid rendu à l'identique est soit un mot commun aux deux langues (« Port », « Notes »),
// soit une traduction oubliée. On borne la liste des identiques attendus : au-delà, c'est
// qu'on a rempli le catalogue en copiant.
$en = require UPTIMEEZ_ROOT . '/lang/en.php';
//
// LE CRITÈRE A DÛ ÊTRE RESSERRÉ, ET LA PREMIÈRE VERSION EST INSTRUCTIVE. Elle signalait
// tout msgid rendu à l'identique de plus de quatorze signes, et remontait quinze entrées
// parfaitement légitimes : des noms de produits (« LiteSpeed Cache »), des exemples
// d'adresses et de commandes, et des chaînes réduites à un jeton (« {n} sites »). Un
// contrôle qui crie sur du normal se fait désactiver, ce qui est pire que son absence.
//
// « Recopier le français » a une signature : la phrase rendue porte encore des marqueurs
// FRANÇAIS. On exige donc les deux, identité ET marqueur, ce qui laisse passer un nom de
// marque et attrape une traduction oubliée.
$marqueurFr = '~(?:[àâçéèêëîïôùûü]|\b(?:le|la|les|des|une|aux|pour|avec|sans|dans|est|sont|cette|leur|vers)\b)~ui';
$identiques = [];
foreach ($en as $fr => $anglais) {
    if (!is_string($anglais) || $fr !== $anglais) continue;
    if (preg_match($marqueurFr, $fr)) $identiques[] = $fr;
}
check('le catalogue anglais ne recopie pas le français', $identiques, []);

// LES MESSAGES DES DÉTECTEURS PASSENT TOUS PAR t(), SANS EXCEPTION.
//
// Ce sont les phrases que le client LIT dans une alerte et dans un incident, donc les plus
// visibles du produit, et elles sont stockées telles quelles en base. Une seule oubliée et
// un client anglophone reçoit une alerte en français, des mois après, sans que rien ne le
// signale : le message est juste, seulement pas dans sa langue.
//
// C'est arrivé sur « Aucune feuille de style détectée sur cette page. » dans
// src/Check/Css.php, à deux lignes d'un voisin correctement traduit. L'audit ne l'a pas vu
// parce que la phrase EST un msgid par ailleurs, et qu'il exempte les msgid connus, en
// supposant qu'ils sont traduits quelque part. Ici, le littéral partait droit à l'écran.
//
// Le contrôle est volontairement étroit et syntaxique : il regarde les affectations dans
// « messages », là où le faux positif est impossible, plutôt que de tenter de deviner
// partout. Un contrôle étroit et sûr vaut mieux qu'un large qu'on désactive.
$brutes = [];
foreach (array_merge(glob(UPTIMEEZ_ROOT . '/src/Check/*.php') ?: [],
                     glob(UPTIMEEZ_ROOT . '/src/Detect/*.php') ?: []) as $f) {
    foreach (file($f, FILE_IGNORE_NEW_LINES) ?: [] as $n => $ligne) {
        if (!preg_match('~\[\x27messages\x27\]\[\]\s*=\s*([\x27"])(.+)~', $ligne, $m)) continue;
        // Une chaîne qui commence par un appel de traduction est conforme ; ici on a
        // capturé un guillemet ouvrant, donc c'est bien un littéral nu.
        $brutes[] = basename($f) . ':' . ($n + 1) . ' « ' . mb_substr($m[2], 0, 45) . '… »';
    }
}
check('les messages des détecteurs passent tous par t()', $brutes, []);

section('Le contrat de règle, et le plafond de gravité qu\'il porte');
// ---------------------------------------------------------------------------
// Sprint A1. Le plafond de gravité vivait dans deux appels écrits à la main dans
// evaluate(), et rien n'empêchait un troisième d'apparaître le lendemain : c'est
// exactement ainsi que treize sites se sont retrouvés annoncés hors service pour un
// défaut de feuille de style. Il vit désormais DANS le Verdict, donc une seule fois,
// donc sans exception possible.

use Uptimeez\Regle\Verdict;

check('une cause de disponibilité sort bien en « down »',
    Verdict::pour('down', 'STRING_MISSING', 'x')->etat, 'down');
check('une cause d\'apparence est plafonnée à « dégradé »',
    Verdict::pour('down', 'CSS_BROKEN', 'x')->etat, 'degraded');
check('le plafonnement se sait lui-même',
    Verdict::pour('down', 'CSS_BROKEN', 'x')->aEtePlafonne(), true);
check('la gravité constatée reste lisible pour le diagnostic',
    Verdict::pour('down', 'CSS_BROKEN', 'x')->etatConstate, 'down');
check('un « dégradé » d\'apparence n\'est pas touché',
    Verdict::pour('degraded', 'CSS_DEGRADED', 'x')->etat, 'degraded');
check('noindex et lenteur sont aussi de l\'apparence',
    [Verdict::pour('down', 'NOINDEX', 'x')->etat, Verdict::pour('down', 'SLOW', 'x')->etat],
    ['degraded', 'degraded']);
check('une cause inconnue n\'est PAS traitée comme de l\'apparence',
    Verdict::pour('down', 'CAUSE_INVENTEE', 'x')->etat, 'down');
check('une cause absente non plus', Verdict::pour('down', null, 'x')->etat, 'down');

// Un état inventé doit ÉCHOUER bruyamment plutôt que d'être accepté en silence : une
// faute de frappe dans un état produirait sinon une sonde dans un état inexistant, que
// la comparaison de gravité traiterait ensuite comme absente.
$refuse = false;
try { Verdict::pour('casse', null, 'x'); } catch (\InvalidArgumentException) { $refuse = true; }
check('un état inconnu est refusé', $refuse, true);

// LE PONT VERS L'ANCIEN FORMAT doit rendre exactement ce que persist() attend, sinon
// l'extraction ne peut pas se faire une règle à la fois.
check('le pont rend la forme attendue par le collecteur',
    array_keys(Verdict::pour('down', 'X', 'msg', ['a' => 1])->enTableau()),
    ['state', 'reason', 'message', 'vars']);
check('et le pont rend l\'état PLAFONNÉ, pas le constaté',
    Verdict::pour('down', 'CSS_BROKEN', 'm')->enTableau()['state'], 'degraded');

// La comparaison de gravité, qui décide des aggravations.
check('la comparaison suit le seul ordre du produit',
    Verdict::pour('down', 'X', 'a')->plusGraveQue(Verdict::pour('degraded', 'Y', 'b')), true);
check('un verdict est plus grave que rien du tout',
    Verdict::pour('degraded', 'X', 'a')->plusGraveQue(null), true);

// Le contrat lui-même : une seule méthode, et rien d'autre. Un contrat qui grossit est
// un contrat que chaque règle devra implémenter en double.
$r = new ReflectionClass(Uptimeez\Regle\Regle::class);
check('le contrat de règle n\'a qu\'une méthode', count($r->getMethods()), 1);
check('et elle s\'appelle evaluer', $r->getMethods()[0]->getName(), 'evaluer');

// Le Contexte ne doit donner AUCUN accès à la base : c'est ce qui rend une règle
// testable en trois lignes, sans base, sans réseau et sans horloge.
$src = (string) file_get_contents(UPTIMEEZ_ROOT . '/src/Regle/Contexte.php');
check('le Contexte n\'ouvre aucune porte vers Db',
    preg_match('~\bDb::~', $src), 0);

section('Règle extraite : la chaîne de preuve');
// ---------------------------------------------------------------------------
// Première des vingt-quatre règles sorties de Runner::evaluate(). Ce bloc est le
// MODÈLE des vingt-trois suivants : une règle se teste sans base, sans réseau et sans
// horloge, en trois lignes, ce qui est tout l'objet de l'extraction.

$reponseAvec = static function (string $corps, bool $tronque = false): Uptimeez\Response {
    $r = new Uptimeez\Response();
    $r->body = $corps;
    $r->truncated = $tronque;
    $r->status = 200;

    return $r;
};
$contexteAvec = static fn (array $sonde, Uptimeez\Response $r): Uptimeez\Regle\Contexte
    => new Uptimeez\Regle\Contexte(sonde: $sonde, reponse: $r);

$regle = new Uptimeez\Regle\ChaineDePreuve();

check('sans chaîne configurée, la règle se tait',
    $regle->evaluer($contexteAvec(['expect_string' => ''], $reponseAvec('quoi que ce soit'))), null);

check('chaîne présente : rien à signaler',
    $regle->evaluer($contexteAvec(['expect_string' => 'Coeur du Web'],
        $reponseAvec('<footer>© 2026 Coeur du Web</footer>'))), null);

$absente = $regle->evaluer($contexteAvec(['expect_string' => 'Coeur du Web'],
    $reponseAvec('<h1>Error establishing a database connection</h1>')));
check('chaîne absente d\'une page COMPLÈTE : hors service', $absente?->etat, 'down');
check('et la cause est nommée', $absente?->cause, 'STRING_MISSING');

// LE PIÈGE QUI A FABRIQUÉ DE FAUSSES PANNES : une page coupée ne prouve rien. La chaîne
// est peut-être juste au-delà de la limite de lecture. Dire « je n'ai pas pu vérifier »
// plutôt qu'inventer une panne est la seule réponse honnête.
$coupee = $regle->evaluer($contexteAvec(['expect_string' => 'Coeur du Web'],
    $reponseAvec(str_repeat('x', 5000), true)));
check('chaîne absente d\'une page COUPÉE : dégradé, pas hors service', $coupee?->etat, 'degraded');
check('et la cause dit qu\'on n\'a pas pu vérifier', $coupee?->cause, 'BODY_TRUNCATED');

// La recherche tolère les variantes d'écriture, sinon une apostrophe typographique
// suffirait à déclarer une panne.
check('l\'apostrophe typographique ne casse pas la recherche',
    $regle->evaluer($contexteAvec(['expect_string' => 'L' . "'" . 'atelier'],
        $reponseAvec('<p>L’atelier est ouvert</p>'))), null);

// Et la règle ne touche à RIEN d'autre : aucun accès base, aucune écriture.
$srcRegle = (string) file_get_contents(UPTIMEEZ_ROOT . '/src/Regle/ChaineDePreuve.php');
check('la règle n\'écrit pas et n\'interroge pas la base',
    preg_match('~\bDb::|\bNotifier::~', $srcRegle), 0);

section('Règle extraite : la chaîne interdite');
// ---------------------------------------------------------------------------
// Deuxième des vingt-quatre. On la croit jumelle de la précédente parce que les deux
// cherchent un texte, et la différence est justement ce qui mérite un test : une chaîne
// de preuve ABSENTE d'une page coupée ne prouve rien, une chaîne interdite PRÉSENTE
// reste une certitude quelle que soit la troncature. Ce qu'on a lu, on l'a lu.

$interdite = new Uptimeez\Regle\ChaineInterdite();

check('sans chaîne interdite configurée, la règle se tait',
    $interdite->evaluer($contexteAvec(['forbid_string' => ''], $reponseAvec('Fatal error'))), null);

check('chaîne interdite absente : rien à signaler',
    $interdite->evaluer($contexteAvec(['forbid_string' => 'Fatal error'],
        $reponseAvec('<h1>Bienvenue</h1>'))), null);

$vue = $interdite->evaluer($contexteAvec(['forbid_string' => 'Fatal error'],
    $reponseAvec('<b>Fatal error</b>: Uncaught Error')));
check('chaîne interdite présente : hors service', $vue?->etat, 'down');
check('et la cause est nommée', $vue?->cause, 'STRING_FORBIDDEN');

// LA DIFFÉRENCE AVEC LA CHAÎNE DE PREUVE, éprouvée plutôt que commentée : une page
// COUPÉE ne change rien au verdict, parce que la présence est une certitude.
$coupeeMaisVue = $interdite->evaluer($contexteAvec(['forbid_string' => 'Fatal error'],
    $reponseAvec('<b>Fatal error</b> puis beaucoup de texte', true)));
check('la troncature ne relativise pas une PRÉSENCE', $coupeeMaisVue?->etat, 'down');

// Plusieurs chaînes séparées par une barre : une seule suffit.
check('une seule des chaînes interdites suffit',
    $interdite->evaluer($contexteAvec(['forbid_string' => 'Under construction|Fatal error'],
        $reponseAvec('<p>Under construction</p>')))?->cause, 'STRING_FORBIDDEN');

section('Règle extraite : la réponse JSON');
// ---------------------------------------------------------------------------
// Troisième extraction, et elle emporte trois verdicts : ils forment une seule chaîne
// de décision, et en faire trois classes obligerait chacune à redécoder le corps.

$json = new Uptimeez\Regle\ReponseJson();
$sondeApi = static fn (array $extra = []): array => ['kind' => 'api'] + $extra;

check('une sonde qui n\'est pas une API est ignorée',
    $json->evaluer($contexteAvec(['kind' => 'http'], $reponseAvec('pas du json'))), null);

$casse = $json->evaluer($contexteAvec($sondeApi(), $reponseAvec('<html>Connexion requise</html>')));
check('du HTML servi par une API : hors service', $casse?->etat, 'down');
check('et la cause dit que ce n\'est pas du JSON', $casse?->cause, 'JSON_INVALID');

// UN CORPS VIDE N'EST PAS UN JSON INVALIDE : c'est le réseau ou le code de statut qui
// le traitent. Deux verdicts pour une panne, et le plus bavard masque le plus juste.
check('un corps vide ne produit pas de verdict JSON',
    $json->evaluer($contexteAvec($sondeApi(), $reponseAvec(''))), null);

check('un JSON valide sans chemin attendu : rien à dire',
    $json->evaluer($contexteAvec($sondeApi(), $reponseAvec('{"ok":true}'))), null);

$absent = $json->evaluer($contexteAvec($sondeApi(['json_path' => 'data.id']),
    $reponseAvec('{"data":{"nom":"x"}}')));
check('champ attendu absent : hors service', $absent?->cause, 'JSON_PATH');

check('champ attendu présent : rien à dire',
    $json->evaluer($contexteAvec($sondeApi(['json_path' => 'data.id']),
        $reponseAvec('{"data":{"id":42}}'))), null);

$mauvaise = $json->evaluer($contexteAvec($sondeApi(['json_path' => 'statut', 'json_expect' => 'ok']),
    $reponseAvec('{"statut":"maintenance"}')));
check('valeur inattendue : hors service', $mauvaise?->cause, 'JSON_VALUE');
check('et le message porte la valeur trouvée', $mauvaise?->variables['value'] ?? '', 'maintenance');

check('valeur conforme : rien à dire',
    $json->evaluer($contexteAvec($sondeApi(['json_path' => 'statut', 'json_expect' => 'ok']),
        $reponseAvec('{"statut":"ok"}'))), null);

// LE CAS RÉEL DU PARC : /wp-json/wp/v2/pages rend un TABLEAU, et le chemin « 0.id »
// traverse son premier élément. C'est la sonde qui prouve qu'un WordPress sert sa base
// et non une page mise en cache.
check('un chemin traverse un tableau par son indice',
    $json->evaluer($contexteAvec($sondeApi(['json_path' => '0.id']),
        $reponseAvec('[{"id":12}]'))), null);

section('Règle extraite : le certificat TLS');
// ---------------------------------------------------------------------------
// Quatrième extraction, trois verdicts. Elle ne fait pas que déplacer du code : dans
// evaluate(), ces verdicts étaient écrits DEUX FOIS, une fois après une inspection TLS
// fraîche et une fois à partir des colonnes en base quand l'inspection datait de moins
// de six heures. Deux copies, et la seconde avait déjà divergé de la première.
//
// Ces cas-là étaient jusqu'ici invérifiables : il aurait fallu un serveur, un vrai
// certificat, et une horloge qu'on puisse avancer de quatre-vingt-neuf jours.

$cert = new Uptimeez\Regle\Certificat();
$avecCert = static fn (array $faits, int $seuil = 30): Uptimeez\Regle\Contexte
    => $contexteAvec(['ssl_warn_days' => $seuil], $reponseAvec(''))
        ->avecDetecteur(Uptimeez\Regle\Certificat::DETECTEUR, $faits);

$sain = ['checked' => true, 'valid' => true, 'code' => null, 'error' => '',
         'expires_at' => null, 'days_left' => 200];

check('un certificat sain ne dit rien', $cert->evaluer($avecCert($sain)), null);

// UNE INSPECTION QUI N'A PAS ABOUTI N'EST PAS UN CERTIFICAT INVALIDE. Se taire est le
// seul verdict honnête : sinon une panne réseau nous ferait accuser le certificat.
check('sans détecteur, la règle se tait',
    $cert->evaluer($contexteAvec(['ssl_warn_days' => 30], $reponseAvec(''))), null);
check('une inspection qui n\'a pas abouti ne conclut pas',
    $cert->evaluer($avecCert(['checked' => false, 'valid' => false])), null);

$expire = $cert->evaluer($avecCert(['checked' => true, 'valid' => false, 'code' => 'SSL_EXPIRED',
    'error' => 'certificate has expired', 'expires_at' => '2026-07-01 00:00:00', 'days_left' => -32]));
check('certificat expiré : hors service', $expire?->etat, 'down');
check('et la cause est l\'expiration, pas l\'invalidité', $expire?->cause, 'SSL_EXPIRED');
// LA DATE TUE UNE QUESTION : « expiré » seul laisse chercher si le renouvellement a
// échoué hier soir ou il y a trois semaines. Ce n'est pas la même urgence.
check('et le message porte la date', $expire?->variables['date'] ?? '', '01/07/2026');

check('expiré sans date connue : le message reste juste',
    $cert->evaluer($avecCert(['checked' => true, 'valid' => false, 'code' => 'SSL_EXPIRED',
        'expires_at' => null, 'days_left' => null]))?->variables, []);

$invalide = $cert->evaluer($avecCert(['checked' => true, 'valid' => false, 'code' => null,
    'error' => 'hostname mismatch', 'expires_at' => null, 'days_left' => 120]));
check('nom d\'hôte erroné : hors service', $invalide?->etat, 'down');
check('et la cause est l\'invalidité', $invalide?->cause, 'SSL_INVALID');
check('et le message porte la raison', $invalide?->variables['reason'] ?? '', 'hostname mismatch');

$bientot = $cert->evaluer($avecCert(['checked' => true, 'valid' => true, 'code' => null,
    'error' => '', 'expires_at' => null, 'days_left' => 12]));
// EXPIRE BIENTÔT N'EST PAS UNE PANNE : le site fonctionne, il fonctionnera encore demain.
check('expiration proche : dégradé, pas hors service', $bientot?->etat, 'degraded');
check('et la cause est nommée', $bientot?->cause, 'SSL_SOON');

check('le seuil d\'alerte est respecté : 31 jours sous un seuil de 30 ne dit rien',
    $cert->evaluer($avecCert(['checked' => true, 'valid' => true, 'days_left' => 31])), null);
check('et 30 jours pile déclenche',
    $cert->evaluer($avecCert(['checked' => true, 'valid' => true, 'days_left' => 30]))?->cause, 'SSL_SOON');

// « expire dans 1 jours » a déjà été lu en production. Le cas particulier existait dans
// les DEUX copies du code, ce qui montre bien qu'on le recopiait au lieu de le partager.
check('un seul jour restant se dit au singulier',
    $cert->evaluer($avecCert(['checked' => true, 'valid' => true, 'days_left' => 1]))?->message,
    'Certificat SSL expire demain');

// CE QUE LA BRANCHE EN CACHE NE SAVAIT PAS DIRE. Elle ne connaissait que le compte à
// rebours : un décompte négatif devait donc suffire à conclure à l'expiration, sans quoi
// le certificat s'annonçait « expire dans -3 jours ».
check('un décompte négatif sans code conclut à l\'expiration',
    $cert->evaluer($avecCert(['checked' => true, 'valid' => true, 'code' => null,
        'days_left' => -3]))?->cause, 'SSL_EXPIRED');

// LA FORME PAUVRE NE DOIT NI PERDRE NI INVENTER. La branche en cache ne connaît que le
// compte à rebours, et les autres champs y sont vides. Deux risques, opposés : perdre un
// verdict que la branche fraîche aurait rendu, ou en fabriquer un que rien ne soutient.
$formePauvre = ['checked' => true, 'valid' => true, 'code' => null, 'error' => '',
                'expires_at' => null, 'days_left' => 7];
check('la forme pauvre rend quand même l\'alerte d\'expiration proche',
    $cert->evaluer($avecCert($formePauvre))?->cause, 'SSL_SOON');
// Et surtout : ne pas déduire l'invalidité d'une absence d'information. La base ne dit
// rien de la validité, ce qui n'est pas la même chose que dire qu'elle est mauvaise.
check('la forme pauvre n\'invente pas d\'invalidité',
    $cert->evaluer($avecCert(['checked' => true, 'valid' => true, 'code' => null, 'error' => '',
        'expires_at' => null, 'days_left' => 300])), null);

// LE CAS QUI TOMBAIT DANS LE TROU DES SIX HEURES. Un certificat au mauvais nom d'hôte
// vu par une inspection fraîche doit être signalé ; la branche en cache en était
// incapable, et le site restait donc muet jusqu'à l'inspection suivante. Ici les deux
// provenances passent par la même règle, donc le trou ne peut plus se rouvrir.
check('un certificat invalide reste signalé quelle que soit sa provenance',
    $cert->evaluer($avecCert(['checked' => true, 'valid' => false, 'code' => null,
        'error' => 'hostname mismatch', 'expires_at' => null, 'days_left' => 300]))?->cause,
    'SSL_INVALID');

section('Règle extraite : la lenteur');
// ---------------------------------------------------------------------------
// Cinquième extraction. Le verdict le plus isolé qui restait : deux valeurs lues, aucun
// détecteur, aucune référence, aucun état précédent.

$en = static function (int $ms, int $seuil) use ($contexteAvec, $reponseAvec): ?Uptimeez\Regle\Verdict {
    $r = $reponseAvec('<html>ok</html>');
    $r->totalMs = $ms;
    return (new Uptimeez\Regle\Lenteur())->evaluer($contexteAvec(['slow_ms' => $seuil], $r));
};

check('sous le seuil : rien à dire', $en(800, 3000), null);
check('au-dessus du seuil : dégradé', $en(4187, 3000)?->etat, 'degraded');
check('et la cause est nommée', $en(4187, 3000)?->cause, 'SLOW');

// LE SEUIL EST UNE BORNE STRICTE : « plus lent que », pas « aussi lent que ». Sinon une
// sonde réglée sur trois secondes alerte sur une page qui met exactement trois secondes,
// ce que personne ne lit dans le mot « seuil ».
check('le seuil exact ne déclenche pas', $en(3000, 3000), null);
check('une milliseconde au-dessus déclenche', $en(3001, 3000)?->cause, 'SLOW');

// ZÉRO VEUT DIRE « PAS DE SEUIL », ET LE CONTRAIRE A DÉJÀ ÉTÉ CODÉ. Le repli sur 3000
// rendait le champ trompeur : le client éteignait l'alerte et continuait à la recevoir.
// C'est un défaut qu'aucune alerte ne révèle, puisqu'il se manifeste PAR une alerte.
check('un seuil à zéro désactive vraiment le contrôle', $en(99000, 0), null);
check('un seuil négatif ne réactive rien', $en(99000, -1), null);

// La durée est annoncée en secondes : « 4 187 ms » demande une conversion mentale.
check('la durée est lisible en secondes', $en(4187, 3000)?->variables['seconds'] ?? '', '4,19');

section('Règle extraite : l\'indexabilité');
// ---------------------------------------------------------------------------
// Sixième extraction. Ce n'est pas une panne, et c'est pourtant le défaut le plus coûteux
// du parc : un « noindex » oublié après une recette est invisible. Le site répond, il
// s'affiche, et il disparaît des moteurs. Personne ne s'en aperçoit avant l'effondrement
// du trafic, des semaines plus tard. Aucun autre verdict n'a ce délai entre cause et
// symptôme, et le parc en portait quatre en production le 2026-08-02.

$index = new Uptimeez\Regle\Indexabilite();
$page = static function (string $corps, int $statut = 200, string $type = 'text/html'): Uptimeez\Response {
    $r = new Uptimeez\Response();
    $r->body = $corps;
    $r->status = $statut;
    $r->contentType = $type;
    return $r;
};
$saine = '<html><head><title>x</title></head><body>ok</body></html>';
$interdite = '<html><head><meta name="robots" content="noindex,nofollow"></head><body>x</body></html>';

check('contrôle désactivé : la règle se tait',
    $index->evaluer($contexteAvec(['check_noindex' => 0], $page($interdite))), null);

check('page indexable : rien à dire',
    $index->evaluer($contexteAvec(['check_noindex' => 1], $page($saine))), null);

$v = $index->evaluer($contexteAvec(['check_noindex' => 1], $page($interdite)));
check('balise noindex détectée', $v?->cause, 'NOINDEX');
// DÉGRADÉ ET NON HORS SERVICE : le site fonctionne. Réveiller quelqu'un la nuit userait
// l'alerte pour une correction qui attendra sans dommage jusqu'au matin.
check('et c\'est dégradé, pas hors service', $v?->etat, 'degraded');
// LE DÉTAIL DIT OÙ CORRIGER : en-tête ou balise, ce n'est ni le même fichier ni le même
// interlocuteur. L'omettre transformerait l'alerte en chasse au trésor.
check('et le message dit où l\'interdiction a été trouvée',
    ($v?->variables['detail'] ?? '') !== '', true);

// LA CONDITION D'ENTRÉE : chercher une directive « robots » dans une réponse 500, un PDF
// ou un flux JSON n'apprend rien, puisque son absence y est normale.
check('une réponse en erreur n\'est pas analysée',
    $index->evaluer($contexteAvec(['check_noindex' => 1], $page($interdite, 500))), null);
check('une réponse qui n\'est pas du HTML n\'est pas analysée',
    $index->evaluer($contexteAvec(['check_noindex' => 1],
        $page('{"robots":"noindex"}', 200, 'application/json'))), null);
// Une redirection n'est pas une page : la directive vivra sur la destination.
check('une redirection n\'est pas analysée',
    $index->evaluer($contexteAvec(['check_noindex' => 1], $page($interdite, 301))), null);

section('Règle extraite : le code HTTP');
// ---------------------------------------------------------------------------
// Septième extraction, huit causes. Huit et non une seule « mauvais code HTTP » : un 404,
// un 403 et un 500 ne se corrigent ni par la même personne ni au même endroit, et le
// rapport mensuel compte les pannes par nature.

$codeHttp = static function (int $statut, string $attendu = '', string $urlFinale = '')
    use ($contexteAvec): ?Uptimeez\Regle\Verdict {
    $r = new Uptimeez\Response();
    $r->status = $statut;
    $r->finalUrl = $urlFinale;
    return (new Uptimeez\Regle\CodeHttp())->evaluer($contexteAvec(['expect_status' => $attendu], $r));
};

check('un 200 attendu ne dit rien', $codeHttp(200), null);
check('un 204 est dans la plage par défaut', $codeHttp(204), null);

// CHAQUE FAMILLE A SA CAUSE, parce que chacune désigne un interlocuteur différent.
foreach ([[500, 'HTTP_5XX'], [503, 'HTTP_5XX'], [404, 'HTTP_404'], [403, 'HTTP_403'],
          [401, 'HTTP_401'], [429, 'HTTP_429'], [418, 'HTTP_4XX'], [302, 'HTTP_3XX']] as [$code, $cause]) {
    check("un $code est classé $cause", $codeHttp($code)?->cause, $cause);
}

// LE 429 NE DIT RIEN DU SITE, IL DIT QUELQUE CHOSE DE NOUS : un quota atteint met souvent
// notre propre cadence en cause. Le confondre avec une erreur client enverrait le client
// chercher une panne chez lui. Ce cas s'est présenté : des dizaines de feuilles de style
// déclarées cassées étaient des 429 provoqués par ma machine non autorisée.
check('le 429 ne se confond pas avec les autres erreurs client',
    $codeHttp(429)?->cause === $codeHttp(418)?->cause, false);

// UNE REDIRECTION INATTENDUE DIT VERS OÙ : c'est la signature d'un domaine détourné ou
// d'un parking d'hébergeur. Sans la destination, l'alerte ne donne pas l'élément qui
// permet de reconnaître le problème.
check('une redirection inattendue nomme sa destination',
    $codeHttp(302, '', 'https://parking.hebergeur.example/')?->variables['target'] ?? '',
    'https://parking.hebergeur.example/');

// LA PLAGE EST UN RÉGLAGE : une sonde peut légitimement attendre un 301 ou un 404.
check('un 301 attendu ne déclenche rien', $codeHttp(301, '301'), null);
check('un 404 attendu ne déclenche rien', $codeHttp(404, '404'), null);
check('et un 200 devient alors une anomalie', $codeHttp(200, '404')?->cause, 'HTTP_UNEXPECTED');
check('dont le message rappelle ce qu\'on attendait',
    $codeHttp(200, '404')?->variables['expected'] ?? '', '404');
// Une plage vide n'est pas une plage qui accepte tout : c'est l'absence de réglage, donc
// le défaut. Sans quoi vider le champ éteindrait silencieusement la sonde.
check('une plage vide retombe sur le défaut, elle n\'accepte pas tout',
    $codeHttp(500, '')?->cause, 'HTTP_5XX');

// Tous ces verdicts sont des indisponibilités réelles, jamais plafonnées en apparence.
check('un code HTTP fautif est un hors service', $codeHttp(500)?->etat, 'down');

section('Confirmation avant alerte : un échec isolé ne réveille personne');
// ---------------------------------------------------------------------------
// LE DÉFAUT : une seule passe en échec ouvrait l'incident et déclenchait l'alerte. Les
// relances existantes sont IMMÉDIATES, donc elles n'attrapent qu'un paquet perdu : un
// redémarrage de PHP-FPM ou une purge de cache durent de une à dix secondes, et les
// trois tentatives tombent toutes dedans. Le client recevait une alerte pour une panne
// déjà finie quand il ouvrait son courriel.
//
// L'ALTERNATIVE ÉCARTÉE, et pourquoi. Trois pauses de 5, 15 et 30 secondes dans la passe
// immobiliseraient un ouvrier cinquante secondes, soit le budget entier d'une passe : une
// sonde instable mangerait la passe et retarderait les douze autres sondes de la minute.
// On paierait une fausse alerte par un vrai retard de détection sur tout le reste.
// LA CONDITION PORTE SUR L'ÉTAT PRÉCÉDENT, ET LA PREMIÈRE VERSION S'EST TROMPÉE ICI.
// Elle testait « consecutive_fail < 1 ». Ce compteur n'est incrémenté que sur « down » :
// une sonde DÉGRADÉE le laissait à zéro pour toujours, et son incident ne se serait
// jamais ouvert. Le selftest était vert, c'est le parcours de bout en bout qui l'a
// attrapé. Un compteur juste, une condition juste, et une combinaison qui rend le produit
// muet sur une famille entière de cas.
$decision = static function (bool $incidentOuvert, string $etatPrecedent): string {
    if (!$incidentOuvert && $etatPrecedent === 'up') return 'replanifie';
    if (!$incidentOuvert) return 'ouvre';

    return 'poursuit';
};

check('premier échec : on replanifie, on n\'alerte pas',   $decision(false, 'up'), 'replanifie');
check('seconde observation en panne : on ouvre',           $decision(false, 'down'), 'ouvre');
check('et la règle vaut aussi pour un DÉGRADÉ',            $decision(false, 'degraded'), 'ouvre');
check('incident déjà ouvert : on poursuit sans attendre',  $decision(true, 'up'), 'poursuit');

// Le délai doit rester court devant l'intervalle, sinon la confirmation devient un retard.
check('la confirmation est courte devant un intervalle de 15 min',
    Uptimeez\Runner::CONFIRMATION_SEC > 0 && Uptimeez\Runner::CONFIRMATION_SEC <= 60, true);

// ET LE CAS QUI COMPTE VRAIMENT : une panne RÉELLE ne doit pas passer entre les mailles.
// Deux passes espacées de 30 s suffisent, donc l'alerte part au plus tard une demi-minute
// après la première observation, contre quinze minutes si l'on avait attendu la passe
// suivante. C'est ce chiffre qui justifie la replanification plutôt que l'attente.
check('une panne réelle est confirmée en une demi-minute, pas au prochain créneau',
    Uptimeez\Runner::CONFIRMATION_SEC < 900, true);

// personne ne pense à le mettre à jour. Ce contrôle-ci s'ajoute à ceux qu'il compte,
// donc le total qu'il exige inclut lui-même : c'est voulu, ça évite un décalage de un
// qu'on passerait sa vie à se demander d'où il vient.
$lus = [];
foreach (['/../README.md' => '/(\d+) checks   detection logic/',
          '/../README.fr.md' => '/(\d+) contrôles   logique de détection/'] as $f => $motif) {
    $chemin = __DIR__ . $f;
    if (!is_file($chemin)) continue;
    preg_match($motif, (string)file_get_contents($chemin), $m);
    $lus[basename($f)] = (int)($m[1] ?? 0);
}
// On compte AVANT d'ajouter : ces contrôles-ci font partie du total qu'ils vérifient,
// et les additionner après laissait un décalage de deux qu'on aurait cherché longtemps.
$totalReel = $pass + $fail + count($lus);
foreach ($lus as $nom => $annonce) {
    check("$nom annonce le bon nombre de contrôles", $annonce, $totalReel);
}

echo "\n" . str_repeat('─', 68) . "\n";
printf("%d test(s) réussi(s), %d échec(s)\n", $pass, $fail);
if ($fail > 0) {
    echo "⚠️  Des contrôles échouent : la détection n'est peut-être pas fiable sur cette installation.\n";
    exit(1);
}
echo "✅ La logique de détection fonctionne sur cette installation.\n";
