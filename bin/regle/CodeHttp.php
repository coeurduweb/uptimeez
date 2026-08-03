<?php

/**
 * La règle du code HTTP, en isolation.
 *
 * CE QUE CETTE RÈGLE DÉCIDE, ET QUI N'EST PAS ÉVIDENT : la plage attendue est un RÉGLAGE,
 * et son défaut est « 200-299 ». Une sonde qui attend une redirection permanente déclare
 * « 301 » et cesse alors d'être en panne quand elle en reçoit une, ce qui est le seul
 * comportement utilisable sur un parc où des adresses redirigent volontairement.
 *
 * La cause est plus fine que le code : 404, 403, 401 et 429 ont chacune la leur, parce
 * qu'elles appellent des gens différents. Un 404 est un problème de contenu, un 403 un
 * problème de droits, un 429 un quota, et les confondre dans « erreur 4xx » ferait
 * chercher au mauvais endroit.
 */
declare(strict_types=1);

require __DIR__ . '/_harnais.php';

use Uptimeez\Regle\CodeHttp;

$regle = new CodeHttp();

titre('La plage par défaut, et le silence qu\'elle produit');

check('la plage par défaut est bien 200-299', CodeHttp::PLAGE_PAR_DEFAUT, '200-299');

check('200 : rien à dire',
    verdict($regle->evaluer(contexte([], reponse(['status' => 200])))), null);

check('204 : rien à dire non plus, la plage couvre tout le 2xx',
    verdict($regle->evaluer(contexte([], reponse(['status' => 204])))), null);

titre('Chaque famille reçoit sa propre cause');

foreach ([500 => 'HTTP_5XX', 503 => 'HTTP_5XX', 429 => 'HTTP_429', 404 => 'HTTP_404',
          403 => 'HTTP_403', 401 => 'HTTP_401', 418 => 'HTTP_4XX', 302 => 'HTTP_3XX'] as $code => $cause) {
    check("$code : cause $cause, hors service",
        verdict($regle->evaluer(contexte([], reponse(['status' => $code])))),
        ['etat' => 'down', 'cause' => $cause]);
}

titre('Les messages disent le code, pas seulement la famille');

check('un 503 nomme son code dans la phrase',
    message($regle->evaluer(contexte([], reponse(['status' => 503])))),
    'Erreur serveur 503 : le site ne répond plus correctement');

check('une redirection inattendue nomme sa destination',
    message($regle->evaluer(contexte([], reponse(['status' => 302, 'finalUrl' => 'https://ailleurs.fr/'])))),
    'Redirection inattendue (302) vers https://ailleurs.fr/');

titre('La plage attendue est un réglage, et c\'est elle qui décide');

check('301 attendu : une redirection permanente ne déclenche rien',
    verdict($regle->evaluer(contexte(['expect_status' => '301'], reponse(['status' => 301])))), null);

check('301 attendu et 200 reçu : c\'est le 200 qui devient inattendu',
    verdict($regle->evaluer(contexte(['expect_status' => '301'], reponse(['status' => 200])))),
    ['etat' => 'down', 'cause' => 'HTTP_UNEXPECTED']);

check('et le message rappelle ce qui était attendu',
    message($regle->evaluer(contexte(['expect_status' => '301'], reponse(['status' => 200])))),
    'Code HTTP inattendu : 200, attendu 301');

check('une plage explicite fonctionne comme le défaut',
    verdict($regle->evaluer(contexte(['expect_status' => '200-204'], reponse(['status' => 204])))), null);

check('un 404 attendu ne déclenche rien : certaines sondes surveillent une absence',
    verdict($regle->evaluer(contexte(['expect_status' => '404'], reponse(['status' => 404])))), null);

check('un réglage vide retombe sur la plage par défaut',
    verdict($regle->evaluer(contexte(['expect_status' => ''], reponse(['status' => 500])))),
    ['etat' => 'down', 'cause' => 'HTTP_5XX']);

bilan('CodeHttp');
