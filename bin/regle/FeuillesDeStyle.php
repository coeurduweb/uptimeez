<?php

/**
 * Les feuilles de style : la règle qui a coûté treize faux « hors service » en une journée.
 *
 * LE PLAFOND EST ICI POUR DE BON. Une mise en page ruinée est un vrai problème, et ce n'est
 * pas une panne : le visiteur obtient sa page. Les deux verdicts de cette règle sont donc
 * dégradés, et le Verdict le rendrait de toute façon puisque « CSS_ » est une cause
 * d'apparence. Le dire ici aussi est une intention, pas une redondance : la règle doit
 * rester juste même si quelqu'un desserre un jour ce plafond.
 *
 * LE CAS QUI A ÉTÉ CORRIGÉ ET QUI SE GARDE ICI : un verdict SANS DÉTAIL. Les messages du
 * détecteur peuvent être absents, et la phrase rendait alors « Mise en page cassée : » et
 * s'arrêtait net, ce qui envoie chercher un défaut sans dire lequel.
 */
declare(strict_types=1);

require __DIR__ . '/_harnais.php';

use Uptimeez\Regle\FeuillesDeStyle;

$regle = new FeuillesDeStyle();

$page = static fn (): array => ['body' => '<html><head><link rel="stylesheet" href="/a.css">',
                                'contentType' => 'text/html'];
$avecCss = static fn (array $css): array => [FeuillesDeStyle::DETECTEUR => $css];

titre('Le contrôle est optionnel, et il ne lit que du HTML');

check('réglage absent : rien à dire',
    verdict($regle->evaluer(contexte([], reponse($page()),
        $avecCss(['state' => 'broken', 'messages' => ['a.css répond 404']])))), null);

check('une réponse JSON : rien à dire',
    verdict($regle->evaluer(contexte(['check_css' => 1],
        reponse(['body' => '{}', 'contentType' => 'application/json']),
        $avecCss(['state' => 'broken', 'messages' => ['x']])))), null);

titre('Sans détecteur, ou dans un état qui ne se cite pas : silence');

check('aucun détecteur : rien à dire',
    verdict($regle->evaluer(contexte(['check_css' => 1], reponse($page())))), null);

check('état sain : rien à dire',
    verdict($regle->evaluer(contexte(['check_css' => 1], reponse($page()),
        $avecCss(['state' => 'ok'])))), null);

check('état inconnu : rien à dire plutôt qu\'un verdict inventé',
    verdict($regle->evaluer(contexte(['check_css' => 1], reponse($page()),
        $avecCss(['state' => 'bizarre', 'messages' => ['x']])))), null);

titre('Les deux verdicts, tous deux dégradés et jamais hors service');

check('cassé : dégradé, cause CSS_BROKEN',
    verdict($regle->evaluer(contexte(['check_css' => 1], reponse($page()),
        $avecCss(['state' => 'broken', 'messages' => ['a.css répond 404']])))),
    ['etat' => 'degraded', 'cause' => 'CSS_BROKEN']);

check('averti : dégradé, cause CSS_DEGRADED',
    verdict($regle->evaluer(contexte(['check_css' => 1], reponse($page()),
        $avecCss(['state' => 'warn', 'messages' => ['a.css a perdu 40 % de son poids']])))),
    ['etat' => 'degraded', 'cause' => 'CSS_DEGRADED']);

titre('Le détail, et le nombre de messages cités par état');

check('cassé cite jusqu\'à trois messages',
    message($regle->evaluer(contexte(['check_css' => 1], reponse($page()),
        $avecCss(['state' => 'broken', 'messages' => ['un', 'deux', 'trois', 'quatre']])))),
    'Mise en page cassée : un deux trois');

check('averti en cite deux',
    message($regle->evaluer(contexte(['check_css' => 1], reponse($page()),
        $avecCss(['state' => 'warn', 'messages' => ['un', 'deux', 'trois']])))),
    'CSS dégradé : un deux');

check('sans message, la phrase dit quand même quelque chose',
    message($regle->evaluer(contexte(['check_css' => 1], reponse($page()),
        $avecCss(['state' => 'broken', 'messages' => []])))),
    'Mise en page cassée : anomalie détectée à la dernière analyse');

titre('La date n\'apparaît que sur un verdict reporté');

check('avec une date d\'analyse, elle est jointe : sinon un défaut vieux d\'une heure semble constaté à l\'instant',
    message($regle->evaluer(contexte(['check_css' => 1], reponse($page()),
        $avecCss(['state' => 'broken', 'messages' => ['a.css répond 404'],
                  'analyse_le' => '2026-08-03 09:30:00'])))),
    'Mise en page cassée : a.css répond 404 (analyse du 03/08 09:30)');

bilan('FeuillesDeStyle');
