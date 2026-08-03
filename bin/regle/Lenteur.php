<?php

/**
 * La règle de lenteur, en isolation.
 *
 * LE CAS QUI JUSTIFIE CE FICHIER : un seuil à zéro DÉSACTIVE le contrôle. Le code a
 * autrefois replié sur trois secondes quand le champ valait zéro, si bien qu'éteindre
 * l'alerte y remettait la valeur par défaut : le client croyait l'avoir coupée et
 * continuait à la recevoir. Un défaut dont le symptôme est une alerte ne se voit dans
 * aucune alerte, donc il se garde par un test et pas autrement.
 */
declare(strict_types=1);

require __DIR__ . '/_harnais.php';

use Uptimeez\Regle\Lenteur;

$regle = new Lenteur();

titre('Le silence, qui est la réponse la plus fréquente');

check('aucun seuil réglé : rien à dire',
    verdict($regle->evaluer(contexte([], reponse(['totalMs' => 9000])))), null);

check('seuil à zéro : le contrôle est éteint, même à neuf secondes',
    verdict($regle->evaluer(contexte(['slow_ms' => 0], reponse(['totalMs' => 9000])))), null);

check('sous le seuil : rien à dire',
    verdict($regle->evaluer(contexte(['slow_ms' => 3000], reponse(['totalMs' => 2999])))), null);

check('exactement au seuil : encore rien, le seuil est une limite atteinte et non franchie',
    verdict($regle->evaluer(contexte(['slow_ms' => 3000], reponse(['totalMs' => 3000])))), null);

titre('Le verdict, et sa gravité');

check('au-dessus du seuil : dégradé, cause SLOW',
    verdict($regle->evaluer(contexte(['slow_ms' => 3000], reponse(['totalMs' => 4187])))),
    ['etat' => 'degraded', 'cause' => 'SLOW']);

check('la durée est annoncée en secondes, avec deux décimales',
    message($regle->evaluer(contexte(['slow_ms' => 3000], reponse(['totalMs' => 4187])))),
    'Temps de réponse élevé : 4,19 s');

check('un seuil serré reste un seuil : 501 ms sur 500',
    verdict($regle->evaluer(contexte(['slow_ms' => 500], reponse(['totalMs' => 501])))),
    ['etat' => 'degraded', 'cause' => 'SLOW']);

titre('Le seuil est lu tel quel, sans repli d\'aucune sorte');

check('un seuil négatif vaut « éteint » et non « toujours en alerte »',
    verdict($regle->evaluer(contexte(['slow_ms' => -1], reponse(['totalMs' => 9000])))), null);

check('un seuil en chaîne de caractères est lu comme un entier',
    verdict($regle->evaluer(contexte(['slow_ms' => '3000'], reponse(['totalMs' => 4000])))),
    ['etat' => 'degraded', 'cause' => 'SLOW']);

bilan('Lenteur');
