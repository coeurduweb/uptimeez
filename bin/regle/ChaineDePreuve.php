<?php

/**
 * La chaîne de contrôle : ce qui doit être là, et ce qu'on dit quand ça n'y est pas.
 *
 * LE CAS QUI FAIT TOUT L'INTÉRÊT DE CE FICHIER est celui du corps TRONQUÉ. Une chaîne
 * absente d'une page lue en entier veut dire que le contenu n'est plus servi : c'est hors
 * service. La même absence sur une page qu'on n'a pas pu lire jusqu'au bout ne veut rien
 * dire du tout, et annoncer « hors service » y serait un faux positif fabriqué par notre
 * propre limite de lecture. Le verdict est donc différent, et dégradé.
 */
declare(strict_types=1);

require __DIR__ . '/_harnais.php';

use Uptimeez\Regle\ChaineDePreuve;

$regle = new ChaineDePreuve();

titre('Sans réglage, la règle se tait');

check('aucune chaîne attendue : rien à dire',
    verdict($regle->evaluer(contexte([], reponse(['body' => 'peu importe'])))), null);

check('une chaîne faite d\'espaces vaut « pas de réglage »',
    verdict($regle->evaluer(contexte(['expect_string' => '   '], reponse(['body' => 'x'])))), null);

titre('La chaîne est présente : rien à dire');

check('présente telle quelle',
    verdict($regle->evaluer(contexte(['expect_string' => 'Bienvenue'],
        reponse(['body' => '<p>Bienvenue chez nous</p>'])))), null);

titre('La chaîne est absente : hors service, et la phrase la cite');

check('absente d\'une page lue en entier : hors service',
    verdict($regle->evaluer(contexte(['expect_string' => 'Ajouter au panier'],
        reponse(['body' => '<p>Boutique en travaux</p>'])))),
    ['etat' => 'down', 'cause' => 'STRING_MISSING']);

check('la chaîne cherchée est citée dans le message',
    message($regle->evaluer(contexte(['expect_string' => 'Ajouter au panier'],
        reponse(['body' => 'rien'])))),
    'La chaîne de contrôle « Ajouter au panier » est absente de la page : le contenu n\'est plus servi, par le serveur web ou par la base de données.');

titre('Le corps tronqué : notre limite de lecture n\'est pas une panne du site');

check('absente d\'une page tronquée : dégradé et non hors service',
    verdict($regle->evaluer(contexte(['expect_string' => 'Ajouter au panier'],
        reponse(['body' => str_repeat('x', 2048), 'truncated' => true])))),
    ['etat' => 'degraded', 'cause' => 'BODY_TRUNCATED']);

check('et le message dit combien on a lu, pas ce qu\'on n\'a pas trouvé',
    message($regle->evaluer(contexte(['expect_string' => 'Ajouter au panier'],
        reponse(['body' => str_repeat('x', 2048), 'truncated' => true])))),
    'Page trop volumineuse pour être vérifiée en entier (2 Ko lus) : la chaîne de contrôle n\'a pas pu être cherchée jusqu\'au bout.');

check('présente dans une page tronquée : rien à dire, on a trouvé avant la coupure',
    verdict($regle->evaluer(contexte(['expect_string' => 'Bienvenue'],
        reponse(['body' => 'Bienvenue' . str_repeat('x', 2048), 'truncated' => true])))), null);

bilan('ChaineDePreuve');
