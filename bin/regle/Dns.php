<?php

/**
 * La règle DNS, en isolation.
 *
 * LE CAS QUI JUSTIFIE CETTE RÈGLE est le dernier de ce fichier : un enregistrement qui
 * RÉPOND, mais plus la bonne valeur. Rien d'autre dans le moteur ne le voit, puisque la page
 * s'affiche parfaitement depuis la nouvelle adresse. C'est la seule situation où la
 * surveillance DNS apprend quelque chose qu'aucune sonde de page ne peut apprendre.
 *
 * LA COMPARAISON EST UNE INCLUSION, et les tests le fixent : un MX rend « 10 mx.exemple.fr »,
 * donc attendre « mx.exemple.fr » doit convenir. Exiger l'égalité obligerait l'exploitant à
 * recopier une syntaxe qu'il n'a aucune raison de connaître, et la première alerte serait un
 * faux positif sur un espace.
 */
declare(strict_types=1);

require __DIR__ . '/_harnais.php';

use Uptimeez\Regle\Dns;

$regle = new Dns();
$sonde = static fn (array $s): array => [Dns::DETECTEUR => $s];

titre('Sans sonde aboutie, la règle se tait');

check('aucune sonde : rien à dire',
    verdict($regle->evaluer(contexte())), null);

check('sonde non aboutie : rien à dire, et surtout pas « absent »',
    verdict($regle->evaluer(contexte([], reponse(), $sonde(['checked' => false])))), null);

titre('Aucune réponse : l\'enregistrement a disparu');

check('rien trouvé : hors service, cause DNS_MISSING',
    verdict($regle->evaluer(contexte([], reponse(),
        $sonde(['checked' => true, 'found' => false, 'type' => 'MX', 'name' => 'exemple.fr'])))),
    ['etat' => 'down', 'cause' => 'DNS_MISSING']);

check('le message nomme le type et le nom',
    message($regle->evaluer(contexte([], reponse(),
        $sonde(['checked' => true, 'found' => false, 'type' => 'MX', 'name' => 'exemple.fr'])))),
    'Aucun enregistrement MX pour exemple.fr');

titre('Réponse présente et aucune valeur exigée : rien à dire');

check('un A qui répond, sans valeur attendue',
    verdict($regle->evaluer(contexte([], reponse(),
        $sonde(['checked' => true, 'found' => true, 'type' => 'A', 'name' => 'exemple.fr',
                'values' => ['203.0.113.10']])))), null);

titre('La valeur attendue : l\'inclusion, et le changement silencieux');

check('valeur présente parmi plusieurs réponses : rien à dire',
    verdict($regle->evaluer(contexte(['dns_expect' => '203.0.113.10'], reponse(),
        $sonde(['checked' => true, 'found' => true, 'type' => 'A', 'name' => 'exemple.fr',
                'values' => ['198.51.100.7', '203.0.113.10']])))), null);

check('inclusion et non égalité : « mx.exemple.fr » convient pour « 10 mx.exemple.fr »',
    verdict($regle->evaluer(contexte(['dns_expect' => 'mx.exemple.fr'], reponse(),
        $sonde(['checked' => true, 'found' => true, 'type' => 'MX', 'name' => 'exemple.fr',
                'values' => ['10 mx.exemple.fr']])))), null);

check('la casse ne compte pas : un nom de domaine n\'y est pas sensible',
    verdict($regle->evaluer(contexte(['dns_expect' => 'MX.Exemple.FR'], reponse(),
        $sonde(['checked' => true, 'found' => true, 'type' => 'MX', 'name' => 'exemple.fr',
                'values' => ['10 mx.exemple.fr']])))), null);

check('valeur absente : hors service, cause DNS_VALUE',
    verdict($regle->evaluer(contexte(['dns_expect' => '203.0.113.10'], reponse(),
        $sonde(['checked' => true, 'found' => true, 'type' => 'A', 'name' => 'exemple.fr',
                'values' => ['198.51.100.7']])))),
    ['etat' => 'down', 'cause' => 'DNS_VALUE']);

check('et le message donne l\'attendu ET le trouvé, sinon il faut aller chercher',
    message($regle->evaluer(contexte(['dns_expect' => '203.0.113.10'], reponse(),
        $sonde(['checked' => true, 'found' => true, 'type' => 'A', 'name' => 'exemple.fr',
                'values' => ['198.51.100.7']])))),
    'L\'enregistrement A de exemple.fr ne contient plus « 203.0.113.10 » : 198.51.100.7');

bilan('Dns');
