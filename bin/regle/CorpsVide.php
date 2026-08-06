<?php

/**
 * Une page vide servie en 200 : le seul des cinq faux négatifs du 2026-08-06 qui méritait
 * du code plutôt qu'une ligne de documentation.
 *
 * LE JEU D'ESSAI INVERSE, en une phrase : au lieu de vérifier que le moteur n'invente pas
 * de pannes, on lui présente des situations RÉELLEMENT cassées et on regarde lesquelles il
 * déclare normales. Six cas mesurés, cinq au vert. Quatre se rattrapent par une chaîne de
 * preuve, que l'import pose automatiquement. Celui-ci non : aucune configuration ne rend
 * une page vide correcte.
 *
 * CE QUE CES CONTRÔLES GARDENT SURTOUT, c'est la BORNE de la règle. Elle ne juge que la
 * certitude : rien du tout. Le « presque rien » est laissé de côté volontairement, parce
 * qu'une page de redirection ou une page d'attente légitime peut être minuscule, et qu'un
 * seuil posé sans mesure fabriquerait exactement les faux positifs que ce moteur passe son
 * temps à réparer.
 */
declare(strict_types=1);

require __DIR__ . '/_harnais.php';

use Uptimeez\Regle\CorpsVide;

$regle = new CorpsVide();

/** Une réponse HTML complète, avec le corps qu'on veut. */
$page = static fn (string $corps, int $code = 200): array => [
    'body' => $corps, 'contentType' => 'text/html', 'status' => $code,
];

titre('Ce qui est vide est une panne, et le visiteur voit une page blanche');

check('zéro octet : panne, cause EMPTY_BODY',
    verdict($regle->evaluer(contexte(['kind' => 'page'], reponse($page(''))))),
    ['etat' => 'down', 'cause' => 'EMPTY_BODY']);

check('rien que des blancs : pareil, ça ne s\'affiche pas mieux',
    cause($regle->evaluer(contexte(['kind' => 'page'], reponse($page("  \n\t  "))))), 'EMPTY_BODY');

check('le message donne le code, parce que « 200 et vide » est ce qui surprend',
    message($regle->evaluer(contexte(['kind' => 'page'], reponse($page(''))))),
    'La page répond 200 mais ne contient rien : le visiteur voit une page blanche.');

titre('La borne : la règle s\'arrête où la certitude s\'arrête');

check('un seul caractère visible suffit à ne plus rien conclure',
    verdict($regle->evaluer(contexte(['kind' => 'page'], reponse($page('.'))))), null);

check('une page minuscule mais réelle passe',
    verdict($regle->evaluer(contexte(['kind' => 'page'], reponse($page('<html><body>Bonjour</body></html>'))))), null);

titre('Ce qui appartient à une autre règle ne se juge pas ici');

// Un 500 avec un corps vide est une panne de code HTTP. Deux règles qui décrivent la même
// panne rendent deux alertes pour un seul défaut.
check('un 500 vide appartient à la règle du code HTTP',
    verdict($regle->evaluer(contexte(['kind' => 'page'], reponse($page('', 500))))), null);

check('un 302 vide non plus : une redirection n\'a pas de contenu à montrer',
    verdict($regle->evaluer(contexte(['kind' => 'page'], reponse($page('', 302))))), null);

// Une API, un fichier, un port : leur corps ne se juge pas comme une page.
foreach (['api', 'asset', 'heartbeat', 'tcp', 'dns'] as $type) {
    check("une sonde « $type » n'est pas concernée",
        verdict($regle->evaluer(contexte(['kind' => $type], reponse($page(''))))), null);
}

// Un mot-clé se cherche dans une page : cette sonde attend bien du contenu.
check('une sonde « keyword » attend une page, donc elle est concernée',
    cause($regle->evaluer(contexte(['kind' => 'keyword'], reponse($page(''))))), 'EMPTY_BODY');

titre('Un corps coupé par notre lecteur n\'est pas un corps vide');

// La distinction a déjà coûté de fausses pannes ailleurs : une page trop lourde perdait sa
// chaîne de preuve parce qu'on n'avait pas lu jusqu'à elle. Ici le corps arrive vide pour la
// même raison, et une panne inventée serait du même tonneau.
check('corps tronqué et vide : on ne conclut pas',
    verdict($regle->evaluer(contexte(['kind' => 'page'],
        reponse($page('') + ['truncated' => true])))), null);

bilan('CorpsVide');
