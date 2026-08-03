<?php

/**
 * Le point d'entrée JSON : trois lectures qui forment une chaîne, dans le seul ordre possible.
 *
 * SANS ANALYSE RÉUSSIE, il n'y a pas de champ à chercher ; sans le champ, pas de valeur à
 * comparer. Chaque réponse n'a de sens que si la précédente a abouti, et c'est pour ça que
 * l'ordre n'est pas une préférence.
 *
 * LE CORPS VIDE NE DIT RIEN ICI, délibérément : l'absence de réponse est déjà nommée par la
 * couche réseau ou par le code HTTP, et un second verdict pour le même incident mettrait
 * deux alertes sur une panne. La plus vague des deux étant toujours la plus voyante, c'est
 * elle qui finirait citée.
 */
declare(strict_types=1);

require __DIR__ . '/_harnais.php';

use Uptimeez\Regle\ReponseJson;

$regle = new ReponseJson();

$api = static fn (array $reglages = []): array => ['kind' => 'api'] + $reglages;

titre('La règle ne parle que des sondes d\'API');

check('une sonde de page : rien à dire, même sur du HTML là où du JSON était attendu',
    verdict($regle->evaluer(contexte([], reponse(['body' => '<html>connexion</html>'])))), null);

titre('Le corps vide est le silence assumé');

check('corps vide sur une sonde d\'API : rien à dire',
    verdict($regle->evaluer(contexte($api(), reponse(['body' => ''])))), null);

titre('Première lecture : est-ce du JSON');

check('du HTML servi par un point d\'entrée : hors service, cause JSON_INVALID',
    verdict($regle->evaluer(contexte($api(), reponse(['body' => '<html>Veuillez vous connecter</html>'])))),
    ['etat' => 'down', 'cause' => 'JSON_INVALID']);

check('un JSON valide sans chemin réglé : rien à dire, la forme suffisait',
    verdict($regle->evaluer(contexte($api(), reponse(['body' => '{"ok":true}'])))), null);

titre('Deuxième lecture : le champ est-il là');

check('champ absent : hors service, cause JSON_PATH',
    verdict($regle->evaluer(contexte($api(['json_path' => 'status']),
        reponse(['body' => '{"etat":"ok"}'])))),
    ['etat' => 'down', 'cause' => 'JSON_PATH']);

check('et le message nomme le champ cherché',
    message($regle->evaluer(contexte($api(['json_path' => 'status']),
        reponse(['body' => '{"etat":"ok"}'])))),
    'Champ « status » absent de la réponse');

check('champ présent : rien à dire quand aucune valeur n\'est exigée',
    verdict($regle->evaluer(contexte($api(['json_path' => 'status']),
        reponse(['body' => '{"status":"maintenance"}'])))), null);

titre('Le chemin traverse une liste par son indice : la preuve de vie par la base');

check('le premier enregistrement de /wp-json/wp/v2/pages',
    verdict($regle->evaluer(contexte($api(['json_path' => '0.id']),
        reponse(['body' => '[{"id":42,"title":"Accueil"}]'])))), null);

check('une liste vide : le champ est absent, et c\'est exactement ce qu\'on veut savoir',
    verdict($regle->evaluer(contexte($api(['json_path' => '0.id']),
        reponse(['body' => '[]'])))),
    ['etat' => 'down', 'cause' => 'JSON_PATH']);

titre('Troisième lecture : la valeur est-elle celle attendue');

check('valeur conforme : rien à dire',
    verdict($regle->evaluer(contexte($api(['json_path' => 'status', 'json_expect' => 'ok']),
        reponse(['body' => '{"status":"ok"}'])))), null);

check('valeur différente : hors service, cause JSON_VALUE',
    verdict($regle->evaluer(contexte($api(['json_path' => 'status', 'json_expect' => 'ok']),
        reponse(['body' => '{"status":"maintenance"}'])))),
    ['etat' => 'down', 'cause' => 'JSON_VALUE']);

check('le message donne la valeur trouvée ET celle attendue',
    message($regle->evaluer(contexte($api(['json_path' => 'status', 'json_expect' => 'ok']),
        reponse(['body' => '{"status":"maintenance"}'])))),
    'Champ « status » vaut « maintenance », attendu « ok »');

check('la comparaison est textuelle : 200 et « 200 » sont d\'accord',
    verdict($regle->evaluer(contexte($api(['json_path' => 'code', 'json_expect' => '200']),
        reponse(['body' => '{"code":200}'])))), null);

bilan('ReponseJson');
