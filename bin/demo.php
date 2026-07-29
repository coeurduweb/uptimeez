<?php
/**
 * UptimeEZ : jeu de démonstration.
 *
 * Monte un parc d'agence fictif (9 sites, 30 jours d'historique) sur un faux
 * site local, pour visiter l'interface avant de brancher de vrais domaines.
 *
 *   php bin/demo.php            # installe la démo (mot de passe : demo1234)
 *   php bin/demo.php --purge    # supprime la démo
 *
 * Refuse d'écraser une installation existante : vos données sont en sécurité.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use Uptimeez\Config;
use Uptimeez\Db;
use Uptimeez\Heartbeat;
use Uptimeez\Runner;
use Uptimeez\Stats;

if (PHP_SAPI !== 'cli') exit("À lancer en ligne de commande.\n");

$root   = dirname(__DIR__);
$purge  = in_array('--purge', $argv, true);
$marker = $root . '/data/.demo';

if ($purge) {
    if (!is_file($marker)) exit("Aucune démonstration installée.\n");
    foreach (['/config.php', '/data/uptimeez.sqlite', '/data/uptimeez.sqlite-wal', '/data/uptimeez.sqlite-shm', '/data/.demo'] as $f) {
        @unlink($root . $f);
    }
    foreach (glob($root . '/data/demo-site/*') ?: [] as $f) @unlink($f);
    @rmdir($root . '/data/demo-site');
    exit("Démonstration supprimée. Ouvrez install.php pour une installation propre.\n");
}

// Ce garde-fou protège une installation réelle d'un écrasement accidentel. Mais
// UPTIMEEZ_DEMO=1 dit sans ambiguïté que cette instance EST une démonstration :
// le refuser empêchait de monter une démo publique, ce qui était l'inverse du but.
if (is_file($root . '/config.php') && !is_file($marker) && !\Uptimeez\Demo::on()) {
    exit("UptimeEZ est déjà installé pour de vrai : la démonstration écraserait votre configuration.\n"
       . "Supprimez config.php si vous voulez malgré tout repartir de la démo,\n"
       . "ou posez UPTIMEEZ_DEMO=1 si cette instance est justement une démonstration.\n");
}

// Le faux site qui sert de cible aux sondes de démonstration.
$fixtures = $root . '/data/demo-site';
$port = 0;
for ($p = 8840; $p < 8890; $p++) {
    $s = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
    if ($s) { fclose($s); $port = $p; break; }
}
if (!$port) exit("Aucun port libre pour le faux site de démonstration.\n");
$B = "http://127.0.0.1:$port";

// --- Génération du faux site ---------------------------------------------
@mkdir($fixtures, 0775, true);
$classes = ['site-header', 'nav-main', 'nav-link', 'hero', 'hero-title', 'hero-sub', 'btn', 'btn-primary',
            'card', 'card-grid', 'card-title', 'section', 'footer-main', 'footer-col', 'price-box'];
$css = [':root{--brand:#0a58ff}', '*{box-sizing:border-box}', 'body{margin:0;font-family:system-ui,sans-serif}'];
foreach ($classes as $c) {
    $css[] = ".$c{display:flex;padding:12px 16px;margin:0 auto;max-width:1180px;gap:12px}";
    $css[] = ".$c:hover{opacity:.92;transition:.2s}";
}
foreach (['(max-width:1200px)', '(max-width:960px)', '(max-width:720px)'] as $mq) {
    $css[] = "@media $mq{.hero-title{font-size:2rem}.card-grid{display:grid;gap:16px}}";
}
file_put_contents("$fixtures/style.css", implode("\n", $css));
$page = function (string $t, string $links, string $extraHead = '') use ($classes): string {
    $b = '';
    foreach ($classes as $i => $c) $b .= '<div class="' . $c . '">Bloc ' . $i . '</div>';
    return "<!doctype html><html lang=\"fr\"><head><meta charset=\"utf-8\"><title>$t. Agence Bellevue</title>"
        . "<meta property=\"og:site_name\" content=\"Agence Bellevue\">$extraHead$links</head><body>"
        . '<header class="site-header"><nav class="nav-main"><a class="nav-link" href="/contact.html">Contact</a></nav></header>'
        . "<main class=\"hero\"><h1 class=\"hero-title\">$t</h1><a class=\"btn btn-primary\" href=\"/contact.html\">Demander un devis</a></main>"
        . $b . '<footer class="footer-main">© 2026 Agence Bellevue — tous droits réservés</footer></body></html>';
};
$L = '<link rel="stylesheet" href="/style.css?ver=1">';

// Une page volontairement lourde : elle sert la démonstration de l'analyse de
// vitesse. Tous les défauts qu'elle porte sont les défauts réels les plus
// fréquents, dans les proportions où on les rencontre.
$hero = "$fixtures/hero.jpg";
// Une image de 380 Ko, sans dépendre d'aucune bibliothèque : du JPEG minimal
// suivi d'un remplissage. Le poids est ce qui compte pour la démonstration.
file_put_contents($hero, "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00"
    . str_repeat("\x00", 380 * 1024) . "\xFF\xD9");
file_put_contents("$fixtures/gros.css", str_repeat(".remplissage-" . bin2hex(random_bytes(3))
    . "{margin:0;padding:0;border:0}\n", 4200));
file_put_contents("$fixtures/lourd.js", "/* script de démonstration */\n"
    . str_repeat("var x" . bin2hex(random_bytes(2)) . " = 1;\n", 3000));
file_put_contents("$fixtures/polices.css",
    "@font-face{font-family:Demo;src:url(/demo.woff2) format('woff2')}\n"
  . "@font-face{font-family:DemoBold;src:url(/demo-bold.woff2) format('woff2')}\n");
$slowHead = '<link rel="stylesheet" href="/style.css?ver=1">'
          . '<link rel="stylesheet" href="/gros.css?ver=1">'
          . '<link rel="stylesheet" href="/polices.css?ver=1">'
          . '<script src="/lourd.js"></script>'
          . '<script src="https://cdn.tarteaucitron.io/tag.js"></script>'
          . '<script src="https://static.hotjar.com/c/hotjar.js"></script>'
          . '<script src="https://connect.facebook.net/fr_FR/sdk.js"></script>'
          . '<script src="https://www.googletagmanager.com/gtm.js"></script>';
$slowBody = '<img src="/hero.jpg" loading="lazy" alt="Bandeau">'
          . '<img src="/hero.jpg" alt="Photo un">'
          . '<img src="/hero.jpg" alt="Photo deux">'
          . '<img src="/hero.jpg" alt="Photo trois">';
file_put_contents("$fixtures/lente.html", str_replace('<body>', '<body>' . $slowBody,
    $page('Page vitrine', $slowHead)));
foreach (['ok' => 'Accueil', 'contact' => 'Contact', 'services' => 'Nos services',
          'tarifs' => 'Tarifs', 'mentions-legales' => 'Mentions légales'] as $f => $t) {
    file_put_contents("$fixtures/$f.html", $page($t, $L));
}
file_put_contents("$fixtures/css404.html", $page('Accueil', '<link rel="stylesheet" href="/wp-content/cache/min/1/absent.css">'));
file_put_contents("$fixtures/noindex.html", $page('Préproduction', $L, '<meta name="robots" content="noindex, nofollow">'));
file_put_contents("$fixtures/dberror.php", "<?php http_response_code(200); ?><!doctype html><html><head><title>Erreur</title></head><body><h1>Error establishing a database connection</h1></body></html>");
file_put_contents("$fixtures/slow.php", "<?php usleep(2600000); ?><!doctype html><html><body>Lent mais vivant. Agence Bellevue</body></html>");
file_put_contents("$fixtures/health.php", "<?php header('Content-Type: application/json'); echo json_encode(['status'=>'ok','db'=>true]);");
file_put_contents("$fixtures/robots.txt", "User-agent: *\nAllow: /\nSitemap: $B/sitemap.xml\n");
$sm = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];
foreach (['', 'contact.html', 'services.html', 'tarifs.html', 'mentions-legales.html'] as $u) {
    $sm[] = "<url><loc>$B/$u</loc><priority>0.8</priority></url>";
}
file_put_contents("$fixtures/sitemap.xml", implode("\n", $sm) . '</urlset>');
file_put_contents("$fixtures/router.php", "<?php\n"
    . "\$p = parse_url(\$_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';\n"
    . "if (\$p === '/') { readfile(__DIR__ . '/ok.html'); return true; }\n"
    . "\$f = realpath(__DIR__ . \$p);\n"
    . "if (\$f && is_file(\$f) && str_starts_with(\$f, __DIR__)) return false;\n"
    . "http_response_code(404); echo '<!doctype html><html><head><title>404 Not Found</title></head><body>Not Found</body></html>';\n"
    . "return true;\n");

echo "Faux site généré dans data/demo-site.\n";
echo "Pour que les sondes interrogent réellement quelque chose, laissez tourner\n";
echo "dans un autre terminal :\n";
echo "   php -S 127.0.0.1:$port -t " . $fixtures . " " . $fixtures . "/router.php\n\n";
@unlink($root . '/data/uptimeez.sqlite');
@unlink($root . '/data/uptimeez.sqlite-wal');
@unlink($root . '/data/uptimeez.sqlite-shm');
Config::save([
    'db'   => ['driver' => 'sqlite', 'sqlite' => $root . '/data/uptimeez.sqlite'],
    'auth' => ['password_hash' => password_hash('demo1234', PASSWORD_DEFAULT)],
    'app'  => ['name' => Uptimeez\I18n::APP, 'demo' => true, 'base_url' => '', 'cron_key' => bin2hex(random_bytes(12)),
               'public_token' => 'demo', 'timezone' => 'Europe/Paris'],
    'notify' => ['discord' => ['enabled' => false, 'webhook' => ''], 'slack' => ['enabled' => false, 'webhook' => ''],
                 'mail' => ['enabled' => false, 'to' => ''], 'webhook' => ['enabled' => false, 'url' => '']],
]);
@file_put_contents($marker, date('c'));
Db::migrate();

/**
 * Parc de démonstration.
 *
 * Un parc d'agence : des PME, sur des domaines en « .example » (TLD réservé par la
 * RFC 2606, donc aucun tiers n'est visé). Cette liste portait auparavant des
 * marques réelles, au motif qu'une capture d'écran parle immédiatement. Deux
 * raisons de renoncer :
 *
 *   1. la démo publique affichait une panne inventée au nom d'une entreprise
 *      réelle. Un bandeau l'expliquait, mais personne ne lit les bandeaux, et ce
 *      n'était pas à nous d'en décider pour eux ;
 *   2. ces captures servent la page de vente, où une liste de marques connues
 *      laisse croire qu'il s'agit de nos clients. Aucune explication ne rattrape ça.
 *
 * Ce qui fait la démonstration est la variété des CAS de panne, pas la notoriété
 * des noms : une feuille de style disparue, une base muette, une lenteur, un
 * noindex parti en production.
 */
$sites = [
    // Le parc d'une agence, tel qu'il est vraiment : des PME, pas des géants.
    //
    // POURQUOI DES NOMS INVENTÉS, ET POURQUOI LE TLD « .example »
    //
    // Cette liste portait Leboncoin, BlaBlaCar, Airbnb, Deezer, La Poste, Le Monde
    // et OVHcloud, avec des pannes fictives attribuées à des préproductions de ces
    // marques. Deux problèmes, et le second est le plus sérieux :
    //
    //   - sur la démo publique, on affiche une panne inventée au nom d'une
    //     entreprise réelle. Le bandeau explique que tout est fictif, mais
    //     personne ne lit les bandeaux, et ce n'est pas à nous d'en décider ;
    //   - ces captures servent aussi la page de vente, où une liste de marques
    //     connues laisse croire que ce sont nos clients. C'est un usage de marque
    //     d'autrui qu'aucune explication ne rattrape.
    //
    // « .example » est réservé par la RFC 2606 : aucun domaine ne peut l'utiliser,
    // donc aucun tiers n'est visé, et un lecteur technique y lit exactement le bon
    // message : aucun vrai client n'est exposé ici.
    //
    // Le contenu pédagogique est intact : huit sites sains, quatre pannes de nature
    // différente, un site lent, une API interne. C'est la variété des CAS qui fait
    // la démonstration, pas la notoriété des noms.

    // --- Ce qui va bien ---------------------------------------------------
    ['Riverside Dental',    'riverside-dental.example', 'WordPress', 'Clients',   "$B/ok.html",       ['contact.html', 'tarifs.html', 'services.html']],
    ['Northgate Legal',     'northgate-legal.example',  'WordPress', 'Clients',   "$B/services.html", ['contact.html']],
    ['Atlas Outdoor',       'atlas-outdoor.example',    'Shopify',   'Boutiques', "$B/tarifs.html",   ['mentions-legales.html']],
    ['Verger du Coteau',    'verger-coteau.example',    'WooCommerce', 'Boutiques', "$B/contact.html", ['ok.html']],
    ['Kite & Sail School',  'kite-sail.example',        'WordPress', 'Clients',   "$B/ok.html",       []],
    ['Maison Bertin',       'maison-bertin.example',    'PrestaShop', 'Boutiques', "$B/services.html", []],
    ['Le Petit Gazette',    'petit-gazette.example',    'WordPress', 'Presse',    "$B/tarifs.html",   []],
    ['Clinique Vauban',     'clinique-vauban.example',  'Drupal',    'Clients',   "$B/contact.html",  []],

    // --- Les quatre pannes, une par nature -------------------------------
    ['Camping des Pins',    'camping-des-pins.example', 'WordPress', 'Clients',   "$B/css404.html",   ['ok.html']],
    ['Ferronnerie Duval',   'ferronnerie-duval.example', 'Laravel',  'Clients',   "$B/dberror.php",   []],
    ['Studio Halcyon',      'studio-halcyon.example',   'Next.js',   'Clients',   "$B/slow.php",      []],
    ['Mairie de Sainte-Ame', 'sainte-ame.example',      'Drupal',    'Clients',   "$B/noindex.html",  []],

    // --- Un site lent : c'est l'analyse de vitesse qui parle ---------------
    ['Hotel Belvedere',     'hotel-belvedere.example',  'WordPress', 'Boutiques', "$B/lente.html",    []],

    // --- Une API interne --------------------------------------------------
    ['Internal API health', 'api.internal.example',     null,        'Interne',   "$B/health.php",    []],
];

$i = 0;
foreach ($sites as [$name, $domain, $cms, $group, $url, $children]) {
    $siteId = Db::insert('sites', ['name' => $name, 'domain' => $domain, 'cms' => $cms,
        'cms_detail' => jenc(['confidence' => 85, 'builder' => $cms === 'WordPress' ? 'Elementor' : null,
                              'theme' => $cms === 'WordPress' ? 'astra' : null, 'server' => 'LiteSpeed',
                              'cache' => $cms === 'WordPress' ? 'LiteSpeed Cache' : null]),
        'group_name' => $group, 'expect_string' => $name, 'created_at' => date('Y-m-d H:i:s', time() - 40 * 86400)]);
    $isApi = str_contains($url, 'health');
    $base = [
        'site_id' => $siteId, 'name' => $name, 'url' => $url, 'kind' => $isApi ? 'api' : 'page',
        'role' => 'primary', 'method' => 'GET', 'interval_sec' => 300, 'timeout_sec' => 15,
        'retries' => 1, 'slow_ms' => 6000, 'expect_status' => '200-299',
        'expect_string' => $isApi ? null : $name,
        'json_path' => $isApi ? 'status' : null, 'json_expect' => $isApi ? 'ok' : null,
        'check_ssl' => 0, 'check_css' => $isApi ? 0 : 1, 'check_db' => $cms !== 'Astro' ? 1 : 0,
        'check_noindex' => $isApi ? 0 : 1, 'ssl_warn_days' => 14, 'css_drop_pct' => 35,
        'enabled' => 1, 'status' => 'unknown', 'setup_state' => 'done',
        'setup_note' => trim(($cms ?? 'CMS inconnu') . ($cms === 'WordPress' ? ' + Elementor · thème astra' : '') . ' · LiteSpeed'),
        'created_at' => date('Y-m-d H:i:s', time() - 40 * 86400), 'next_check_at' => now(),
        'follow_redirects' => 1,
        'ssl_days_left' => [61, 38, 12, 89, 74, 5, 44, 26, 120][$i % 9],
        'ssl_expires_at' => date('Y-m-d H:i:s', time() + [61, 38, 12, 89, 74, 5, 44, 26, 120][$i % 9] * 86400),
        'ssl_issuer' => "Let's Encrypt R11",
        'ssl_checked_at' => now(),
        'domain_expires_at' => date('Y-m-d H:i:s', time() + [400, 210, 25, 600, 180, 90, 320, 45, 730][$i % 9] * 86400),
    ];
    Db::insert('monitors', $base);
    foreach ($children as $c) {
        Db::insert('monitors', array_merge($base, [
            'name' => ucfirst(str_replace(['-', '.html'], [' ', ''], $c)),
            'url' => "$B/$c", 'role' => 'secondary', 'kind' => 'page',
            'check_ssl' => 0, 'json_path' => null, 'json_expect' => null,
            'setup_note' => 'page interne (sitemap)',
            'next_check_at' => date('Y-m-d H:i:s', time() + random_int(1, 20)),
        ]));
    }
    $i++;
}

// --- Historique synthétique sur 30 jours ---------------------------------
$mons = Db::all('SELECT id, interval_sec, created_at FROM monitors');
$now  = time();
foreach ($mons as $m) {
    $mid = (int)$m['id'];
    $baseMs = random_int(180, 900);
    // Une mesure toutes les 30 minutes sur 30 jours
    for ($t = $now - 30 * 86400; $t < $now - 300; $t += 1800) {
        $hour = (int)date('G', $t);
        $ms = (int)max(60, $baseMs + random_int(-70, 90) + ($hour >= 9 && $hour <= 18 ? random_int(0, 160) : 0));
        $state = 'up'; $reason = null; $code = 200;
        // Quelques pannes réparties
        $r = ($mid * 7919 + (int)($t / 1800)) % 1500;
        if ($r < 4)        { $state = 'down';     $reason = ['HTTP_5XX', 'TIMEOUT', 'DB_DOWN', 'CSS_BROKEN'][$r % 4]; $code = 500; }
        elseif ($r < 16)   { $state = 'degraded'; $reason = 'SLOW'; $ms += random_int(2200, 4200); }
        Db::insert('checks', [
            'monitor_id' => $mid, 'ts' => date('Y-m-d H:i:s', $t), 'state' => $state,
            'reason_code' => $reason, 'status_code' => $code,
            'message' => $state === 'up' ? 'Tout va bien' : 'Anomalie simulée',
            'dns_ms' => random_int(4, 30), 'connect_ms' => random_int(12, 60),
            'tls_ms' => random_int(40, 120), 'ttfb_ms' => (int)($ms * 0.8),
            'total_ms' => $ms, 'size_bytes' => random_int(28000, 140000), 'redirects' => 0,
            'attempts' => $state === 'down' ? 2 : 1,
        ]);
        if ($state === 'down') {
            Db::insert('incidents', [
                'monitor_id' => $mid, 'severity' => 'down', 'reason_code' => $reason,
                'message' => 'Anomalie simulée', 'started_at' => date('Y-m-d H:i:s', $t),
                'ended_at' => date('Y-m-d H:i:s', $t + random_int(300, 3600)),
                'duration_sec' => random_int(300, 3600), 'checks_failed' => random_int(1, 5),
                'notify_count' => 1, 'last_notified_at' => date('Y-m-d H:i:s', $t),
            ]);
        }
    }
}
echo "historique : " . Db::val('SELECT COUNT(*) FROM checks') . " mesures, "
   . Db::val('SELECT COUNT(*) FROM incidents') . " incidents\n";

// Le faux site répond en série : seuil serré uniquement sur la sonde « lente ».
Db::q("UPDATE monitors SET slow_ms = 2000 WHERE name = 'Groupe Vallis'");

// --- État courant --------------------------------------------------------
// Si le faux site tourne, on lance de vraies vérifications ; sinon on pose un
// état représentatif pour que la démonstration reste lisible sans rien lancer.
$probe = @stream_socket_client("tcp://127.0.0.1:$port", $e1, $e2, 0.4);
if ($probe) {
    fclose($probe);
    $run = Runner::runDue(60, 120);
    printf("vérifications réelles : %d sondes (%d HS, %d dégradées, %d OK)\n",
        $run['ran'], $run['down'], $run['degraded'], $run['up']);
} else {
    echo "faux site non démarré : état simulé (les courbes et l'historique restent réels)\n";
    // L'ÉTAT EST ATTACHÉ À LA CIBLE, PAS AU NOM AFFICHÉ.
    //
    // Cette table était indexée par le nom de la sonde (« Recette Leboncoin »…).
    // Renommer le parc l'a donc vidée en silence : la démo est restée entièrement
    // verte, « Nothing to do, everything is running », et le défaut ne s'est vu que
    // sur une capture d'écran. Le fichier de destination, lui, dit ce que la sonde
    // ÉPROUVE : css404.html teste une feuille de style manquante, et ça ne changera
    // pas parce que le client s'appelle autrement.
    $parCible = [
        'css404.html'  => ['down',     'CSS_BROKEN', 'Mise en page cassée : feuille de style en échec : …/cache/min/1/absent.css → HTTP 404 [cache WP]'],
        'dberror.php'  => ['down',     'DB_DOWN',    'Laravel : erreur de requête : « SQLSTATE[HY000] [2002] Connection refused »'],
        'slow.php'     => ['degraded', 'SLOW',       'Temps de réponse élevé : 2,60 s'],
        'noindex.html' => ['degraded', 'NOINDEX',    'Page en noindex : balise meta robots : noindex, nofollow'],
    ];
    // On reconstruit la table par nom à partir du parc réellement créé : aucune
    // liste à tenir à jour à deux endroits.
    $states = [];
    foreach ($sites as [$nom, , , , $url]) {
        foreach ($parCible as $cible => $etat) {
            if (str_contains($url, $cible)) { $states[$nom] = $etat; }
        }
    }
    printf("états simulés : %d panne(s) placée(s)\n", count($states));
    // Silhouettes de démonstration : une page de boulangerie mise en page, et la
    // même sans CSS. C'est la comparaison que l'on montre à un client.
    $demoHtml = '<body><header class="site-header"><nav class="nav-main"><a href="/">Accueil</a>'
        . '<a href="/carte">La carte</a><a href="/contact">Contact</a></nav></header>'
        . '<main class="container"><h1 class="hero-title">Votre boutique en ligne</h1>'
        . '<p>Livraison en 24 h, paiement sécurisé, retours gratuits pendant trente jours.</p>'
        . '<div class="card-grid">'
        . '<div class="card"><img src="a.jpg"><h3>Nouveautés</h3><p>La collection de saison</p></div>'
        . '<div class="card"><img src="b.jpg"><h3>Promotions</h3><p>Jusqu\'à 40 %</p></div>'
        . '<div class="card"><img src="c.jpg"><h3>Best-sellers</h3><p>Les plus commandés</p></div>'
        . '</div><a class="btn" href="/panier">Voir le panier</a></main>'
        . '<footer class="footer-main"><p>Mentions légales, CGV, contact</p></footer></body>';
    $demoCss = '.container{max-width:1140px;margin:0 auto;padding:0 24px}'
        . '.site-header{background:#fff;padding:14px}.nav-main{display:flex;gap:18px}'
        . '.hero-title{font-size:2.6rem;text-align:center}'
        . '.card-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:22px}'
        . '.card{background:#fff;border-radius:14px;padding:18px}'
        . '.btn{background:#3b5bdb;border-radius:8px;padding:12px 22px}'
        . '.footer-main{background:#f1f5f9;padding:28px;text-align:center}';
    $sOk = Uptimeez\Check\Silhouette::build($demoHtml, $demoCss);
    $sKo = Uptimeez\Check\Silhouette::build($demoHtml, '');
    $silRef = $sOk['svg']; $silRefSig = $sOk['signature'];
    $silKo  = $sKo['svg'];  $silKoSig  = $sKo['signature'];
    $silDrift = (int)round(Uptimeez\Check\Silhouette::distance($silRefSig, $silKoSig) * 100);

    // Rapport de ressources réaliste, pour que l'accordéon « Ressources de la
    // page » montre à quoi ressemble un vrai diagnostic.
    $cssBroken = jenc([
        'messages' => [
            'Feuille de style en échec : …/min/1/absent.css → HTTP 404 : le fichier n\'existe plus sur le '
                . 'serveur [cache WP (purge en cours ou fichier jamais régénéré)]',
            'Script essentiel en échec : …/js/frontend.min.js → HTTP 404 : le fichier n\'existe plus sur le serveur',
            'Poids CSS en chute de 71 % (38.4 Ko au lieu de 132.6 Ko attendus).',
            'Les classes de la page ne sont plus couvertes par le CSS : 31 % contre 96 % en référence '
                . '(ex. hero-title, card-grid, nav-main, btn-primary).',
        ],
        'console' => [
            ['level' => 'err', 'text' => 'GET https://camping-des-pins.example/wp-content/cache/min/1/absent.css net::ERR_ABORTED 404 (Not Found)'],
            ['level' => 'err', 'text' => 'GET https://camping-des-pins.example/wp-content/plugins/elementor/assets/js/frontend.min.js net::ERR_ABORTED 404 (Not Found)'],
            ['level' => 'err', 'text' => "Refused to apply style from 'https://camping-des-pins.example/wp-content/cache/min/1/absent.css' because its MIME type ('text/html') is not a supported stylesheet MIME type"],
            ['level' => 'warn', 'text' => 'Empty response body for https://camping-des-pins.example/wp-content/uploads/elementor/css/post-142.css'],
        ],
        'metrics' => [
            'sheets_declared' => 6, 'sheets_ok' => 4, 'sheets_failed' => 2,
            'js_declared' => 9, 'js_ok' => 8, 'js_failed' => 1,
            'fonts_checked' => 2, 'fonts_failed' => 0,
            'css_bytes' => 39321, 'rules' => 412, 'media_queries' => 3, 'layout_score' => 44,
            'coverage' => 0.31, 'classes_missing' => ['hero-title', 'card-grid', 'nav-main', 'btn-primary', 'price-box'],
            'inline_bytes' => 2140, 'hidden_nodes' => 4, 'hidden_risk' => true,
            'assets' => [
                ['url' => 'https://camping-des-pins.example/wp-content/cache/min/1/absent.css', 'kind' => 'css',
                 'status' => 404, 'bytes' => 0, 'issue' => 'HTTP_404',
                 'note' => 'HTTP 404 : le fichier n\'existe plus sur le serveur', 'soft' => false],
                ['url' => 'https://camping-des-pins.example/wp-content/uploads/elementor/css/post-142.css', 'kind' => 'css',
                 'status' => 200, 'bytes' => 0, 'issue' => 'EMPTY', 'note' => 'fichier vide (0 octet)', 'soft' => false],
                ['url' => 'https://camping-des-pins.example/wp-content/plugins/elementor/assets/js/frontend.min.js',
                 'kind' => 'js', 'status' => 404, 'bytes' => 0, 'issue' => 'HTTP_404',
                 'note' => 'HTTP 404 : le fichier n\'existe plus sur le serveur', 'soft' => false],
                ['url' => 'https://camping-des-pins.example/wp-content/themes/astra/assets/css/main.min.css',
                 'kind' => 'css', 'status' => 200, 'bytes' => 28714, 'issue' => null, 'note' => null, 'soft' => false],
                ['url' => 'https://camping-des-pins.example/wp-includes/css/dist/block-library/style.min.css',
                 'kind' => 'css', 'status' => 200, 'bytes' => 9204, 'issue' => null, 'note' => null, 'soft' => false],
                ['url' => 'https://fonts.googleapis.com/css2?family=Inter:wght@400;600', 'kind' => 'css',
                 'status' => 200, 'bytes' => 1403, 'issue' => null, 'note' => null, 'soft' => true],
                ['url' => 'https://camping-des-pins.example/wp-includes/js/jquery/jquery.min.js', 'kind' => 'js',
                 'status' => 200, 'bytes' => 30512, 'issue' => null, 'note' => null, 'soft' => false],
            ],
        ],
        'at' => now(),
    ]);
    $cssRef = jenc(['sheets_declared' => 6, 'sheets_ok' => 6, 'js_declared' => 9, 'js_ok' => 9,
                    'css_bytes' => 135782, 'rules' => 1487, 'media_queries' => 14, 'layout_score' => 88,
                    'coverage' => 0.96, 'inline_bytes' => 2140, 'fingerprint' => [], 'built_at' => now()]);

    /**
     * Mesures de vitesse ressentie, à la forme exacte de ce que Vitals::analyse
     * produit. Sans elles, le bloc « Vitesse ressentie » n'apparaissait pas sur
     * une démo fraîche : une fonction du produit restait invisible, et la suite
     * navigateur dépendait d'une passe de cron pour passer.
     */
    $vitalsFor = function (string $name, string $st, ?string $reason): array {
        // La sonde lente porte le cas intéressant : mauvais TTFB, fichiers
        // bloquants, image de haut de page trop lourde et en chargement différé.
        $slow = $reason === 'SLOW';
        $ttfb = $slow ? random_int(1800, 2400) : random_int(120, 620);
        $level = $slow ? 'bad' : ($ttfb > 500 ? 'watch' : 'ok');
        $findings = [];
        if ($slow) {
            $findings = [
                ['id' => 'ttfb', 'level' => 'high',
                 'label' => 'Le serveur met plus de 1,5 s à répondre'],
                ['id' => 'lcp_lazy', 'level' => 'high',
                 'label' => 'L\'image du haut de page est en chargement différé'],
                ['id' => 'blocking_css', 'level' => 'medium',
                 'label' => 'Trois feuilles de style bloquent le premier affichage'],
            ];
        } elseif ($level === 'watch') {
            $findings = [['id' => 'blocking_js', 'level' => 'medium',
                          'label' => 'Un script bloque le premier affichage']];
        }
        return [
            'vitals_level' => $level,
            'vitals_at'    => date('Y-m-d H:i:s', time() - random_int(120, 3000)),
            'vitals_detail' => jenc([
                'ttfb_ms'      => $ttfb,
                'ttfb_verdict' => $ttfb > 1500 ? 'bad' : ($ttfb > 800 ? 'watch' : 'good'),
                'blocking' => [
                    'css'   => $slow ? 3 : 1,
                    'js'    => $slow ? 5 : 0,
                    'bytes' => $slow ? 412_880 : 28_714,
                    'items' => $slow
                        ? [['url' => '/wp-content/themes/demo/style.css', 'kind' => 'css', 'bytes' => 135_782],
                           ['url' => '/wp-content/plugins/slider/slider.css', 'kind' => 'css', 'bytes' => 92_410],
                           ['url' => '/wp-content/plugins/slider/slider.js', 'kind' => 'js', 'bytes' => 184_688]]
                        : [['url' => '/assets/app.css', 'kind' => 'css', 'bytes' => 28_714]],
                ],
                'lcp_image' => $slow
                    ? ['url' => '/wp-content/uploads/2026/05/hero-4000x2200.jpg',
                       'bytes' => 1_284_112, 'lazy' => true]
                    : null,
                'findings' => $findings,
            ]),
        ];
    };

    foreach (Db::all('SELECT id, name FROM monitors') as $m) {
        [$st, $reason, $msg] = $states[$m['name']] ?? ['up', null, 'Tout va bien'];
        Db::update('monitors', [
            'status' => $st, 'reason_code' => $reason, 'last_message' => $msg,
            'status_since' => date('Y-m-d H:i:s', time() - random_int(600, 40000)),
            'last_check_at' => date('Y-m-d H:i:s', time() - random_int(10, 200)),
            'last_status_code' => $st === 'down' && $reason === 'HTTP_5XX' ? 500 : 200,
            'last_ms' => $st === 'degraded' && $reason === 'SLOW' ? random_int(2600, 3200) : random_int(180, 900),
            'silhouette_ref'     => $silRef,
            'silhouette_ref_sig' => jenc($silRefSig),
            'silhouette_ref_at'  => date('Y-m-d H:i:s', time() - 9 * 86400),
            'silhouette_now'     => $reason === 'CSS_BROKEN' ? $silKo : $silRef,
            'silhouette_now_sig' => jenc($reason === 'CSS_BROKEN' ? $silKoSig : $silRefSig),
            'silhouette_at'      => date('Y-m-d H:i:s', time() - 120),
            'silhouette_drift'   => $reason === 'CSS_BROKEN' ? $silDrift : 0,
            'css_state' => $reason === 'CSS_BROKEN' ? 'broken' : 'ok',
            'css_checked_at' => now(),
            'css_detail' => $reason === 'CSS_BROKEN' ? $cssBroken : null,
            'css_baseline' => $reason === 'CSS_BROKEN' ? $cssRef : null,
            'css_baseline_at' => $reason === 'CSS_BROKEN' ? date('Y-m-d H:i:s', time() - 9 * 86400) : null,
        ] + $vitalsFor($m['name'], $st, $reason), 'id = :__i', ['__i' => (int)$m['id']]);
        if ($st === 'down') {
            Db::insert('incidents', ['monitor_id' => (int)$m['id'], 'severity' => 'down',
                'reason_code' => $reason, 'message' => $msg,
                'started_at' => date('Y-m-d H:i:s', time() - random_int(900, 7200)),
                'checks_failed' => random_int(2, 9), 'notify_count' => 1, 'last_notified_at' => now()]);
        }
    }
}
// ---------------------------------------------------------------------------
// Inventaire logiciel et veille de sécurité
// ---------------------------------------------------------------------------
// Les avis ci-dessous sont fictifs, comme le reste des mesures : le bandeau de
// l'interface le dit en permanence. Ils reproduisent la forme d'un vrai avis
// (identifiant, date, gravité, résumé) pour que l'écran montre ce qu'il montrera
// en production.
$stacks = [
    'WordPress' => [
        ['core',   'wordpress',      'WordPress',      '6.4.2',  '7.0.2'],
        ['theme',  'astra',          'Astra',          '4.6.2',  '4.11.1'],
        ['plugin', 'elementor',      'Elementor',      '3.18.3', '4.2.1'],
        ['plugin', 'contact-form-7', 'Contact Form 7', '5.8.6',  '6.1.4'],
        ['plugin', 'woocommerce',    'WooCommerce',    '8.5.1',  '9.9.7'],
    ],
    'Drupal' => [
        ['core',   'drupal',  'Drupal',  '10.1.6', '11.2.4'],
        ['plugin', 'webform', 'Webform', '6.2.1',  '6.3.2'],
    ],
    'Laravel' => [['core', 'laravel', 'Laravel', '10.34.2', '12.9.0']],
    'Next.js' => [['core', 'next-js', 'Next.js', '14.0.4', '15.4.2']],
    'Shopify' => [],
    'MediaWiki' => [['core', 'mediawiki', 'MediaWiki', '1.41.0', '1.44.1']],
    'Ruby on Rails' => [],
    'Django' => [],
];
$demoAdvisories = [
    'elementor' => [[
        'id' => 'DEMO-2026-0142', 'severity' => 'high', 'published' => date('Y-m-d', time() - 3 * 86400),
        // Les avis de sécurité réels sont publiés en anglais : la démonstration
        // fait pareil, sinon elle donne une fausse idée de ce qu'on lira.
        'summary' => "Unrestricted file upload in the template builder. An authenticated "
                   . "contributor can execute arbitrary code.",
        'url' => null, 'aliases' => ['CVE-2026-00000'],
    ]],
    'contact-form-7' => [[
        'id' => 'DEMO-2026-0117', 'severity' => 'medium', 'published' => date('Y-m-d', time() - 11 * 86400),
        'summary' => "Stored cross-site scripting in the form preview, exploitable by a site "
                   . "administrator without the code-editing capability.",
        'url' => null, 'aliases' => [],
    ]],
];
foreach (Db::all('SELECT id, name, cms FROM sites') as $st) {
    foreach ($stacks[(string)$st['cms']] ?? [] as [$kind, $slug, $name, $ver, $latest]) {
        $adv = $demoAdvisories[$slug] ?? [];
        Db::insert('components', [
            'site_id' => (int)$st['id'],
            'monitor_id' => (int)Db::val('SELECT id FROM monitors WHERE site_id = ? AND role = \'primary\' LIMIT 1',
                                         [(int)$st['id']]),
            'kind' => $kind, 'slug' => $slug, 'name' => $name, 'version' => $ver,
            'source' => $kind === 'core' ? 'generator' : 'asset',
            'latest' => $latest,
            'outdated' => Uptimeez\Detect\Stack::compare($ver, $latest) < 0 ? 1 : 0,
            'vuln_count' => count($adv),
            'worst' => $adv ? Uptimeez\Vuln::worstSeverity($adv) : null,
            'advisories' => $adv ? jenc($adv) : null,
            'checked_at' => date('Y-m-d H:i:s', time() - random_int(1, 20) * 3600),
            'first_seen_at' => date('Y-m-d H:i:s', time() - 30 * 86400),
            'seen_at' => now(),
        ]);
    }
}
// ---------------------------------------------------------------------------
// LA VARIÉTÉ DES USAGES, ET POURQUOI ELLE COMPTE PLUS QUE LE NOMBRE DE SONDES.
//
// La démo ne montrait que des sondes de page et une d'API. Or les visiteurs
// n'arrivent pas avec le même besoin : l'un veut savoir si son site répond, l'autre
// si sa sauvegarde nocturne tourne encore, un troisième si son certificat expire.
// Celui qui ne reconnaît pas SON usage en un coup d'oeil repart.
//
// Quatre battements couvrent le cas « ce n'est pas une page que je surveille, c'est
// une tâche » : une sauvegarde, un import, un envoi de facturation, un cron WordPress.
// L'un est volontairement en retard, parce que c'est là que la sonde battement se
// comprend : ce n'est pas une erreur qui déclenche l'alerte, c'est un SILENCE.
$battements = [
    ['Nightly backup · Riverside Dental',  86400, 3600,  'ok'],
    ['Stock import · Atlas Outdoor',        3600,  900,  'ok'],
    ['Monthly invoicing · Maison Bertin', 2592000, 86400, 'ok'],
    ['WP cron · Le Petit Gazette',           900,  600,  'retard'],
];
$nbBattements = 0;
foreach ($battements as [$nom, $toutes, $tolerance, $etat]) {
    $h = Heartbeat::create($nom, $toutes, $tolerance);
    // Le dernier signal reçu : dans les temps pour trois, hors délai pour le
    // quatrième, ce qui le fait remonter en tête de l'écran du jour.
    $recu = $etat === 'ok'
        ? time() - (int)($toutes * 0.3)
        : time() - $toutes - $tolerance - 420;
    // On n'écrit QUE la date du dernier signal, et on laisse le moteur conclure.
    //
    // Le premier essai posait « status = down » à la main : la sonde en silence
    // affichait alors « Anomaly detected » avec le conseil « ouvrez la page dans un
    // navigateur », qui n'a aucun sens pour une tâche cron. Il manquait le code
    // d'anomalie, donc l'explication dédiée du moteur ne s'appliquait pas.
    //
    // Heartbeat::sweep() fait le travail complet et juste : le code
    // HEARTBEAT_LATE, le message avec la durée du silence, la ligne d'historique et
    // l'incident. Simuler à la main ce que le produit sait faire est exactement ce
    // qui a produit le bug des pannes indexées par nom.
    Db::update('monitors', [
        'heartbeat_at'  => date('Y-m-d H:i:s', $recu),
        'last_check_at' => now(),
        'uptime_24h'    => $etat === 'ok' ? 100.0 : 91.4,
        'status'        => 'up',
    ], 'id = :__i', ['__i' => (int)$h['id']]);
    $nbBattements++;
}
$enRetard = Heartbeat::sweep();
printf("battements : %d sonde(s), dont %d en silence (verdict posé par le moteur)\n",
       $nbBattements, $enRetard);

// Une sonde « clé attendue » explicite, pour montrer le cas le plus demandé : je
// veux savoir que MON mot est encore sur la page. Les autres sondes l'utilisent
// aussi, mais avec le nom du site, ce qui ne se remarque pas.
$idCle = Db::val("SELECT id FROM monitors WHERE name = 'Atlas Outdoor' AND kind = 'page' LIMIT 1");
if ($idCle) {
    Db::update('monitors', [
        'expect_string' => 'Add to basket',
        'forbid_string' => 'Out of stock',
        'setup_note'    => 'Shopify · proof string on the buy button',
    ], 'id = :__i', ['__i' => (int)$idCle]);
    echo "clé attendue : 1 sonde règlée sur le bouton d'achat\n";
}

echo 'inventaire : ' . Db::val('SELECT COUNT(*) FROM components') . " composant(s), "
   . Db::val('SELECT COUNT(*) FROM components WHERE vuln_count > 0') . " avec faille publiée\n";

// --- Clients de l'agence --------------------------------------------------
// Les groupes saisis plus haut font déjà le classement : on montre la reprise
// automatique plutôt que de saisir une deuxième fois la même chose.
$cl = Uptimeez\Client::fromGroups();
foreach (Db::all('SELECT id, name FROM clients') as $c) {
    Db::update('clients', [
        'contact_email' => 'contact@' . Uptimeez\Detect\Stack::slug((string)$c['name']) . '.exemple.fr',
        // Un client consulte son lien de temps en temps : sans cette trace, la
        // colonne « lien consulté » de la démonstration serait toujours vide.
        'last_seen_at'  => date('Y-m-d H:i:s', time() - random_int(2, 96) * 3600),
        'views'         => random_int(3, 48),
        // La préproduction ne se montre pas au client : accès fermé, ce qui
        // donne à voir l'état correspondant dans l'écran de gestion.
        'enabled'       => (string)$c['name'] === 'Préprod' ? 0 : 1,
    ], 'id = :__i', ['__i' => (int)$c['id']]);
}
echo 'clients : ' . $cl['created'] . ' créé(s) depuis les groupes, '
   . $cl['linked'] . " site(s) rattaché(s)\n";

foreach (Db::all('SELECT id FROM monitors') as $m) Stats::refresh((int)$m['id']);
Stats::rollup(date('Y-m-d'));
for ($d = 1; $d <= 30; $d++) Stats::rollup(date('Y-m-d', time() - $d * 86400));
Db::setSetting('last_run_at', now());
echo "\nDémonstration prête. Mot de passe : demo1234\n";
echo "Pour la retirer : php bin/demo.php --purge\n";
