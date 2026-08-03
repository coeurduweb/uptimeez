<?php

/**
 * La règle du port, en isolation.
 *
 * CE QU'ELLE N'A PAS, ET C'EST LE SUJET : aucun état intermédiaire. Un port lent à accepter
 * la connexion est un port ouvert ; la durée sert à afficher une mesure, pas à fabriquer un
 * verdict. Les tests ci-dessous vérifient donc surtout que la règle SE TAIT dans tous les
 * cas où elle ne sait rien, parce que c'est là qu'une condition inversée ferait déclarer
 * tout un parc hors service.
 */
declare(strict_types=1);

require __DIR__ . '/_harnais.php';

use Uptimeez\Regle\Port;

$regle = new Port();
$sonde = static fn (array $s): array => [Port::DETECTEUR => $s];

titre('Sans sonde aboutie, la règle se tait');

check('aucune sonde : rien à dire',
    verdict($regle->evaluer(contexte())), null);

check('sonde non aboutie : rien à dire, et surtout pas « fermé »',
    verdict($regle->evaluer(contexte([], reponse(), $sonde(['checked' => false, 'open' => false])))), null);

titre('Port ouvert : rien à dire, quelle que soit la durée');

check('ouvert en 3 ms',
    verdict($regle->evaluer(contexte([], reponse(), $sonde(['checked' => true, 'open' => true, 'ms' => 3])))), null);

check('ouvert en 2 400 ms : lent n\'est pas un verdict ici',
    verdict($regle->evaluer(contexte([], reponse(), $sonde(['checked' => true, 'open' => true, 'ms' => 2400])))), null);

titre('Port fermé : hors service, et le message nomme l\'hôte et le port');

check('fermé : hors service, cause PORT_CLOSED',
    verdict($regle->evaluer(contexte([], reponse(),
        $sonde(['checked' => true, 'open' => false, 'host' => 'mail.exemple.fr', 'port' => 25,
                'error' => 'Connection refused'])))),
    ['etat' => 'down', 'cause' => 'PORT_CLOSED']);

check('le message porte les trois informations utiles',
    message($regle->evaluer(contexte([], reponse(),
        $sonde(['checked' => true, 'open' => false, 'host' => 'mail.exemple.fr', 'port' => 25,
                'error' => 'Connection refused'])))),
    'Port 25 fermé sur mail.exemple.fr : Connection refused');

check('sans raison technique, la phrase reste complète',
    message($regle->evaluer(contexte([], reponse(),
        $sonde(['checked' => true, 'open' => false, 'host' => 'mail.exemple.fr', 'port' => 25])))),
    'Port 25 fermé sur mail.exemple.fr : rien n\'écoute');

bilan('Port');
