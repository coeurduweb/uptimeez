#!/usr/bin/env php
<?php
/**
 * Le jeu d'essai INVERSE : des situations réellement cassées, pour voir lesquelles le
 * moteur déclare normales.
 *
 * ------------------------------------------------------------------------------
 * POURQUOI CETTE SUITE, ALORS QU'IL Y EN A DÉJÀ DIX
 * ------------------------------------------------------------------------------
 *
 * Les dix autres vérifient que le moteur ne se trompe pas dans un sens : qu'il n'invente
 * pas de pannes. Le corpus des faux positifs compte sept cas documentés, avec le contenu
 * exact qui les a produits. Sur les faux NÉGATIFS, il n'y avait rien.
 *
 * Or ne pas voir est la panne la plus grave d'un outil de surveillance. Un faux positif
 * agace ; un faux négatif fait croire que tout va bien pendant qu'un client perd des
 * commandes.
 *
 * ------------------------------------------------------------------------------
 * CE QU'ELLE MESURE, ET LES DEUX COLONNES QUI CHANGENT TOUT
 * ------------------------------------------------------------------------------
 *
 * Chaque cas est présenté DEUX FOIS : sans chaîne de preuve, puis avec. La différence est
 * l'argument central du produit, et elle se voit ici en chiffres plutôt qu'en promesse :
 * la plupart des pages cassées sans erreur technique ne se détectent QUE par une chaîne de
 * preuve, et c'est pour ça que l'import en déduit une automatiquement.
 *
 * ------------------------------------------------------------------------------
 * ELLE ÉCHOUE DANS LES DEUX SENS, ET LE SECOND EST INHABITUEL
 * ------------------------------------------------------------------------------
 *
 * Un cas attrapé qui cesse de l'être est une régression : elle échoue, c'est normal.
 *
 * Mais un cas déclaré AVEUGLE qui se met à être attrapé fait échouer la suite AUSSI. Ce
 * n'est pas une erreur : c'est une bonne nouvelle qui doit forcer la mise à jour du corpus,
 * sinon le produit finirait par prétendre être aveugle là où il voit, et cette liste
 * deviendrait un mensonge par vieillissement. Le message le dit alors clairement.
 *
 *   php bin/angles-morts.php
 */
declare(strict_types=1);

// Comme tout script de bin/ : jamais servi par HTTP. bin/security.php le vérifie fichier par
// fichier, et il a attrapé celui-ci le jour de sa création.
if (PHP_SAPI !== 'cli') exit("À lancer en ligne de commande.\n");

require __DIR__ . '/../src/bootstrap.php';

use Uptimeez\Regle\Verdict;
use Uptimeez\Response;
use Uptimeez\Runner;

Uptimeez\I18n::init();

/** La sonde de référence : rien d'activé qui ne soit dans le cas mesuré. */
function sonde(bool $avecPreuve, string $interdit = ''): array
{
    return [
        'id' => 0, 'url' => 'https://cas.test/', 'kind' => 'page', 'method' => 'GET',
        'interval_sec' => 300, 'timeout_sec' => 10, 'retries' => 0, 'expect_status' => '200-299',
        'enabled' => 1, 'check_ssl' => 0, 'check_css' => 0, 'check_db' => 1, 'check_noindex' => 1,
        'check_content' => $avecPreuve ? 1 : 0, 'slow_ms' => 0, 'ssl_warn_days' => 14,
        'css_drop_pct' => 35, 'expect_string' => $avecPreuve ? 'Nos tarifs' : '',
        'forbid_string' => $interdit, 'watch_string' => '', 'watch_mode' => 'disappear',
        'watch_state' => 'present', 'content_hash' => null, 'follow_redirects' => 1,
        'ignore_ssl_errors' => 0,
    ];
}

function reponse(string $corps, int $code = 200): Response
{
    $r = new Response();
    $r->ok = true;
    $r->status = $code;
    $r->body = $corps;
    $r->contentType = 'text/html';
    $r->totalMs = 180;
    $r->url = 'https://cas.test/';
    $r->finalUrl = 'https://cas.test/';

    return $r;
}

/**
 * Les cas. Chacun est une page qu'un visiteur trouverait cassée, avec ce que le moteur en
 * dit aujourd'hui, mesuré et non supposé.
 *
 * « sans » et « avec » valent la cause attendue, ou null quand le moteur ne voit rien.
 */
$cas = [
    [
        'nom'   => 'Corps entièrement vide, servi en 200',
        'html'  => '',
        'sans'  => 'EMPTY_BODY',
        'avec'  => 'EMPTY_BODY',
        'note'  => 'Attrapé sans rien configurer depuis le 2026-08-06 : aucune configuration ne '
                 . 'rend une page vide correcte, donc c\'est une règle et pas une option.',
    ],
    [
        'nom'   => 'Page d\'erreur de base WordPress, en 200',
        'html'  => '<!doctype html><html><body><h1>Error establishing a database connection</h1></body></html>',
        'sans'  => 'DB_DOWN',
        'avec'  => 'DB_DOWN',
        'note'  => 'Une des 41 signatures. C\'est le cas d\'école du produit, et le seul de cette '
                 . 'liste qu\'un outil concurrent attrape parfois.',
    ],
    [
        'nom'   => 'Faux 404 : « page introuvable » servie en 200',
        'html'  => '<!doctype html><html><head><title>Page introuvable</title></head>'
                 . '<body><h1>404 : cette page n\'existe pas</h1></body></html>',
        'sans'  => null,
        'avec'  => 'STRING_MISSING',
        'note'  => 'Rien dans le HTML ne dit « erreur » à une machine : le mot 404 est du texte '
                 . 'comme un autre. Seule l\'absence du contenu attendu le révèle.',
    ],
    [
        'nom'   => 'Page de maintenance laissée en ligne',
        'html'  => '<!doctype html><html><body><h1>Site en maintenance</h1>'
                 . '<p>Revenez dans quelques minutes.</p></body></html>',
        'sans'  => null,
        'avec'  => 'STRING_MISSING',
        'note'  => 'Le cas le plus fréquent en agence, et le plus cher : la page répond, elle est '
                 . 'jolie, et elle ne vend rien.',
    ],
    [
        'nom'   => 'Message d\'erreur maison, hors des 41 signatures',
        'html'  => '<!doctype html><html><body><p>Oups, une erreur est survenue. '
                 . 'Merci de réessayer plus tard.</p></body></html>',
        'sans'  => null,
        'avec'  => 'STRING_MISSING',
        'note'  => 'Aucune liste de signatures ne peut couvrir les phrases que chaque équipe écrit '
                 . 'elle-même. La chaîne de preuve n\'a pas ce problème : elle ne cherche pas '
                 . 'l\'erreur, elle cherche le contenu.',
    ],
    [
        'nom'   => 'Contenu remplacé par du spam injecté',
        'html'  => '<!doctype html><html><body><h1>Nos tarifs</h1>'
                 . '<p>viagra pas cher, casino en ligne, prêt immédiat</p></body></html>',
        'sans'  => null,
        'avec'  => null,
        'note'  => 'AVEUGLE, et de façon irréductible : le contenu attendu est TOUJOURS LÀ, le spam '
                 . 'est venu s\'ajouter. Se rattrape avec une chaîne interdite, mais il faut avoir '
                 . 'deviné le mot à l\'avance. Une sonde de mot-clé existe pour ça.',
    ],
    [
        'nom'   => 'Coquille d\'application dont l\'API est tombée',
        'html'  => '<!doctype html><html><body><div id="app"></div>'
                 . '<script src="/app.js"></script></body></html>',
        'sans'  => null,
        'avec'  => 'STRING_MISSING',
        'note'  => 'Attrapé ICI parce que le contenu attendu est absent du HTML. En vrai, sur une '
                 . 'application dont TOUT le texte est rendu par le navigateur, aucune chaîne de '
                 . 'preuve n\'est configurable : elle serait absente en permanence. Ce cas reste '
                 . 'donc un angle mort réel, et il demande un vrai navigateur.',
    ],
    [
        'nom'   => 'Tunnel de commande cassé par une erreur JavaScript',
        'html'  => '<!doctype html><html><body><h1>Nos tarifs</h1><p>Formule unique.</p>'
                 . '<button onclick="commander()">Commander</button>'
                 . '<script>function commander(){ throw new Error("boom"); }</script></body></html>',
        'sans'  => null,
        'avec'  => null,
        'note'  => 'AVEUGLE PAR CONSTRUCTION : le HTML est parfait, le bouton est là, et rien ne '
                 . 'marche. Le moteur lit du HTML, il n\'exécute pas de JavaScript. C\'est la '
                 . 'limite que la documentation doit dire, pas un défaut à corriger en douce.',
    ],
];

$pass = 0;
$fail = 0;
$attrapesSans = 0;
$attrapesAvec = 0;
$aveugles = 0;

echo "\nJeu d'essai inverse : ce qui est cassé, et ce que le moteur en dit\n";
echo str_repeat('─', 76) . "\n";

foreach ($cas as $c) {
    $vSans = Runner::evaluate(sonde(false), reponse($c['html']));
    $vAvec = Runner::evaluate(sonde(true), reponse($c['html']));
    $causeSans = $vSans['reason'] ?? null;
    $causeAvec = $vAvec['reason'] ?? null;

    $bonSans = $causeSans === $c['sans'];
    $bonAvec = $causeAvec === $c['avec'];
    $bonSans ? $pass++ : $fail++;
    $bonAvec ? $pass++ : $fail++;

    if ($c['sans'] !== null) {
        $attrapesSans++;
    } elseif ($c['avec'] !== null) {
        $attrapesAvec++;
    } else {
        $aveugles++;
    }

    printf("\n%s %s\n", $bonSans && $bonAvec ? '✅' : '❌', $c['nom']);
    printf("   sans chaîne de preuve : %-14s %s\n", $causeSans ?? 'RIEN VU',
        $bonSans ? '' : '← attendu : ' . ($c['sans'] ?? 'RIEN VU'));
    printf("   avec chaîne de preuve : %-14s %s\n", $causeAvec ?? 'RIEN VU',
        $bonAvec ? '' : '← attendu : ' . ($c['avec'] ?? 'RIEN VU'));
    echo '   ' . wordwrap($c['note'], 70, "\n   ") . "\n";

    // LA BONNE NOUVELLE FAIT ÉCHOUER AUSSI. Un angle mort qui se ferme doit sortir de cette
    // liste, sinon le produit se déclarera aveugle là où il voit.
    if ($c['avec'] === null && $causeAvec !== null) {
        echo "   ⚠️  BONNE NOUVELLE : ce cas est maintenant attrapé (" . $causeAvec
           . "). Mettez ce corpus à jour, et le README avec.\n";
    }
}

echo "\n" . str_repeat('═', 76) . "\n";
printf("%d cas · %d attrapé(s) sans rien configurer · %d attrapé(s) grâce à la chaîne de preuve · %d angle(s) mort(s)\n",
    count($cas), $attrapesSans, $attrapesAvec, $aveugles);
printf("%d contrôle(s) réussi(s), %d échec(s)\n", $pass, $fail);

// Ce que ce tableau démontre, et qui vaut d'être dit ici plutôt que dans une plaquette :
// la chaîne de preuve fait passer la détection de deux cas sur huit à six sur huit.
printf("\nSans chaîne de preuve : %d/%d détectés. Avec : %d/%d.\n",
    $attrapesSans, count($cas), $attrapesSans + $attrapesAvec, count($cas));

if ($fail > 0) {
    echo "\n⚠️  Le corpus ne dit plus la vérité : un cas a changé de comportement.\n";
    exit(1);
}

echo "\n✅ Le corpus inverse est à jour : le moteur voit ce qu'il dit voir, et rien de plus.\n";
