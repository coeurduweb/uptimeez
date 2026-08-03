<?php

/**
 * La chaîne interdite : le test inversé, celui qui attrape une trace d'erreur dans un 200.
 *
 * POURQUOI IL EXISTE À CÔTÉ DE LA CHAÎNE DE CONTRÔLE. Une page peut contenir tout ce
 * qu'elle doit contenir ET une phrase qui n'a rien à y faire : « Fatal error » en haut,
 * une trace de débogage, un message de maintenance oublié. Aucune vérification de présence
 * ne l'attrape, puisque tout ce qu'on cherchait est bien là.
 *
 * ET POURQUOI IL N'A PAS DE CAS « TRONQUÉ », contrairement à son symétrique : une chaîne
 * interdite TROUVÉE est trouvée, que la lecture ait été complète ou non. C'est l'absence
 * qui demande d'avoir tout lu, jamais la présence.
 */
declare(strict_types=1);

require __DIR__ . '/_harnais.php';

use Uptimeez\Regle\ChaineInterdite;

$regle = new ChaineInterdite();

titre('Sans réglage, la règle se tait');

check('aucune chaîne interdite : rien à dire',
    verdict($regle->evaluer(contexte([], reponse(['body' => 'Fatal error'])))), null);

check('une chaîne faite d\'espaces vaut « pas de réglage »',
    verdict($regle->evaluer(contexte(['forbid_string' => '  '], reponse(['body' => 'Fatal error'])))), null);

titre('Absente : rien à dire');

check('la page ne contient pas la chaîne interdite',
    verdict($regle->evaluer(contexte(['forbid_string' => 'Fatal error'],
        reponse(['body' => '<p>Tout va bien</p>'])))), null);

titre('Présente : hors service, et la phrase la cite');

check('trouvée : hors service',
    verdict($regle->evaluer(contexte(['forbid_string' => 'Fatal error'],
        reponse(['body' => '<b>Fatal error</b>: appel à une fonction inconnue'])))),
    ['etat' => 'down', 'cause' => 'STRING_FORBIDDEN']);

check('le message cite la chaîne, pas la page',
    message($regle->evaluer(contexte(['forbid_string' => 'Fatal error'],
        reponse(['body' => 'Fatal error: x'])))),
    'Chaîne interdite détectée : « Fatal error »');

titre('La présence n\'a pas besoin d\'une lecture complète');

check('trouvée dans un corps tronqué : hors service quand même',
    verdict($regle->evaluer(contexte(['forbid_string' => 'Fatal error'],
        reponse(['body' => 'Fatal error' . str_repeat('x', 2048), 'truncated' => true])))),
    ['etat' => 'down', 'cause' => 'STRING_FORBIDDEN']);

bilan('ChaineInterdite');
