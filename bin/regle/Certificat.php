<?php

/**
 * Le certificat : deux provenances, un seul verdict, et c'est là qu'était le défaut.
 *
 * CE QUE CE FICHIER GARDE EN PREMIER. Les verdicts de certificat étaient écrits DEUX FOIS
 * dans le collecteur, une fois sur la branche fraîche et une fois sur la branche en cache,
 * et les deux copies avaient divergé : celle du cache ne savait pas dire « certificat
 * invalide ». Un certificat au mauvais nom d'hôte est donc resté silencieux six heures. La
 * règle est maintenant le seul endroit qui décide, et le compte à rebours négatif est traité
 * ici pour la même raison : sans lui, un certificat expiré resterait annoncé « expire dans
 * -3 jours », ce qui est ce que la branche en cache savait produire.
 *
 * SE TAIRE EST UN VERDICT. Sans inspection aboutie, la règle ne dit rien, et surtout pas
 * « invalide » : une panne réseau nous ferait alors accuser le certificat.
 */
declare(strict_types=1);

require __DIR__ . '/_harnais.php';

use Uptimeez\Regle\Certificat;

$regle = new Certificat();

$cert = static fn (array $c): array => [Certificat::DETECTEUR => $c];

titre('La présence du détecteur EST la preuve du TLS, et rien d\'autre');

/*
 * CE QUE J'AI ESSAYÉ ET QUE LA SUITE A REFUSÉ, LE 2026-08-03.
 *
 * Le contrôle de code mort signalait Contexte::estEnHttps() comme jamais appelée, et j'ai
 * voulu la faire servir ici : une sonde en http n'a pas de certificat, donc la règle
 * pouvait se taire d'emblée. Quatorze contrôles du selftest sont passés au rouge, et leur
 * motif est exactement le défaut que cette règle existe pour réparer : le schéma de l'URL
 * de la sonde devenait une SECONDE source de vérité sur « est-ce du TLS », à côté de
 * l'existence du détecteur. Deux provenances pour un même fait, c'est-à-dire ce qui avait
 * laissé un certificat au mauvais nom d'hôte silencieux pendant six heures.
 *
 * Un site qui redirige http vers https se fait bien inspecter, et la sonde peut porter
 * l'adresse en clair : la garde aurait alors caché une expiration réelle. La bonne réponse
 * n'était pas de faire vivre la méthode, c'était de la supprimer.
 */
check('un certificat inspecté est jugé, quel que soit le schéma écrit sur la sonde',
    verdict($regle->evaluer(contexte(['url' => 'http://exemple.fr/'],
        reponse(['url' => 'http://exemple.fr/']),
        $cert(['checked' => true, 'valid' => false, 'error' => 'nom d\'hôte non couvert'])))),
    ['etat' => 'down', 'cause' => 'SSL_INVALID']);

titre('Sans inspection aboutie, la règle se tait');

check('aucun détecteur : rien à dire',
    verdict($regle->evaluer(contexte())), null);

check('inspection non aboutie : rien à dire, et surtout pas « invalide »',
    verdict($regle->evaluer(contexte([], reponse(), $cert(['checked' => false])))), null);

check('inspection aboutie et certificat sain, sans seuil : rien à dire',
    verdict($regle->evaluer(contexte([], reponse(),
        $cert(['checked' => true, 'valid' => true, 'days_left' => 60])))), null);

titre('Expiré : la date de fin est dans la phrase');

check('code SSL_EXPIRED : hors service',
    verdict($regle->evaluer(contexte([], reponse(),
        $cert(['checked' => true, 'code' => 'SSL_EXPIRED', 'expires_at' => '2026-07-12 08:00:00'])))),
    ['etat' => 'down', 'cause' => 'SSL_EXPIRED']);

check('un compte à rebours négatif dit la même chose, et c\'est tout ce que sait la branche en cache',
    verdict($regle->evaluer(contexte([], reponse(),
        $cert(['checked' => true, 'valid' => true, 'days_left' => -3])))),
    ['etat' => 'down', 'cause' => 'SSL_EXPIRED']);

titre('Invalide : la raison est reprise du détecteur');

check('valid à faux : hors service, cause SSL_INVALID',
    verdict($regle->evaluer(contexte([], reponse(),
        $cert(['checked' => true, 'valid' => false, 'error' => 'nom d\'hôte non couvert'])))),
    ['etat' => 'down', 'cause' => 'SSL_INVALID']);

check('la raison est citée dans le message',
    message($regle->evaluer(contexte([], reponse(),
        $cert(['checked' => true, 'valid' => false, 'error' => 'nom d\'hôte non couvert'])))),
    'Certificat SSL invalide : nom d\'hôte non couvert');

check('sans raison, la phrase dit « refusé » plutôt que de s\'arrêter net',
    message($regle->evaluer(contexte([], reponse(),
        $cert(['checked' => true, 'valid' => false])))),
    'Certificat SSL invalide : refusé');

titre('L\'échéance qui approche : dégradé, jamais hors service');

check('sous le seuil : dégradé, cause SSL_SOON',
    verdict($regle->evaluer(contexte(['ssl_warn_days' => 14], reponse(),
        $cert(['checked' => true, 'valid' => true, 'days_left' => 9])))),
    ['etat' => 'degraded', 'cause' => 'SSL_SOON']);

check('exactement au seuil : dégradé aussi, la limite est incluse',
    verdict($regle->evaluer(contexte(['ssl_warn_days' => 14], reponse(),
        $cert(['checked' => true, 'valid' => true, 'days_left' => 14])))),
    ['etat' => 'degraded', 'cause' => 'SSL_SOON']);

check('au-dessus du seuil : rien à dire',
    verdict($regle->evaluer(contexte(['ssl_warn_days' => 14], reponse(),
        $cert(['checked' => true, 'valid' => true, 'days_left' => 15])))), null);

check('le message compte les jours',
    message($regle->evaluer(contexte(['ssl_warn_days' => 14], reponse(),
        $cert(['checked' => true, 'valid' => true, 'days_left' => 9])))),
    'Certificat SSL expire dans 9 jours');

check('un jour restant se dit « demain », parce que « dans 1 jours » se voit',
    message($regle->evaluer(contexte(['ssl_warn_days' => 14], reponse(),
        $cert(['checked' => true, 'valid' => true, 'days_left' => 1])))),
    'Certificat SSL expire demain');

check('zéro jour : le certificat expire aujourd\'hui, et ce n\'est pas encore « expiré »',
    verdict($regle->evaluer(contexte(['ssl_warn_days' => 14], reponse(),
        $cert(['checked' => true, 'valid' => true, 'days_left' => 0])))),
    ['etat' => 'degraded', 'cause' => 'SSL_SOON']);

bilan('Certificat');
