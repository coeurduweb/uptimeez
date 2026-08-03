<?php

/**
 * La base de données derrière un 200 : la panne qui coûte le plus cher.
 *
 * CE QUE LA RÈGLE FAIT, ET CE QU'ELLE NE FAIT PAS. Elle ne cherche rien elle-même : le
 * détecteur `Check\Database` a déjà lu le corps et rendu son constat. La règle traduit ce
 * constat en verdict, et c'est la seule chose qu'elle décide. La séparation compte : le
 * détecteur peut être corrigé cinq fois sans que la gravité bouge, et l'inverse aussi.
 *
 * LA PREUVE EST DANS LE MESSAGE quand il y en a une. « Base de données injoignable » sans
 * l'extrait fait douter du contrôle ; avec les quelques mots trouvés dans la page, personne
 * ne demande de capture d'écran.
 */
declare(strict_types=1);

require __DIR__ . '/_harnais.php';

use Uptimeez\Regle\BaseDeDonnees;

$regle = new BaseDeDonnees();

$avecAudit = static fn (array $audit): array => [BaseDeDonnees::DETECTEUR => $audit];
$corps = static fn (): array => ['body' => '<p>Error establishing a database connection</p>'];

titre('Le contrôle est optionnel, et il exige un corps');

check('réglage absent : rien à dire',
    verdict($regle->evaluer(contexte([], reponse($corps()),
        $avecAudit(['state' => 'down', 'message' => 'x'])))), null);

check('corps vide : rien à dire, la panne est déjà nommée par le réseau ou le code',
    verdict($regle->evaluer(contexte(['check_db' => 1], reponse(),
        $avecAudit(['state' => 'down', 'message' => 'x'])))), null);

titre('Sans détecteur, ou avec un constat sain : silence');

check('aucun détecteur : rien à dire',
    verdict($regle->evaluer(contexte(['check_db' => 1], reponse($corps())))), null);

check('détecteur qui va bien : rien à dire',
    verdict($regle->evaluer(contexte(['check_db' => 1], reponse($corps()),
        $avecAudit(['state' => 'ok'])))), null);

check('détecteur d\'une autre forme : rien à dire plutôt que deviner',
    verdict($regle->evaluer(contexte(['check_db' => 1], reponse($corps()),
        $avecAudit([])))), null);

titre('Le constat devient un verdict, et la cause vient du détecteur');

check('une panne nommée garde sa cause',
    verdict($regle->evaluer(contexte(['check_db' => 1], reponse($corps()),
        $avecAudit(['state' => 'down', 'reason' => 'DB_DOWN',
                    'message' => 'WordPress : connexion à la base impossible'])))),
    ['etat' => 'down', 'cause' => 'DB_DOWN']);

check('une cause absente retombe sur DB_DOWN',
    verdict($regle->evaluer(contexte(['check_db' => 1], reponse($corps()),
        $avecAudit(['state' => 'down', 'message' => 'Erreur SQL'])))),
    ['etat' => 'down', 'cause' => 'DB_DOWN']);

check('une cause plus précise est reprise telle quelle',
    verdict($regle->evaluer(contexte(['check_db' => 1], reponse($corps()),
        $avecAudit(['state' => 'down', 'reason' => 'DB_PROBE', 'message' => 'Sonde REST en erreur 500'])))),
    ['etat' => 'down', 'cause' => 'DB_PROBE']);

titre('La preuve est citée quand le détecteur en a trouvé une');

check('avec extrait : la phrase le porte entre guillemets',
    message($regle->evaluer(contexte(['check_db' => 1], reponse($corps()),
        $avecAudit(['state' => 'down', 'reason' => 'DB_DOWN',
                    'message' => 'WordPress : connexion à la base impossible',
                    'evidence' => 'Error establishing a database connection'])))),
    'WordPress : connexion à la base impossible : « Error establishing a database connection »');

check('sans extrait : la phrase s\'arrête proprement au lieu de finir par un vide',
    message($regle->evaluer(contexte(['check_db' => 1], reponse($corps()),
        $avecAudit(['state' => 'down', 'reason' => 'DB_DOWN',
                    'message' => 'WordPress : connexion à la base impossible'])))),
    'WordPress : connexion à la base impossible');

bilan('BaseDeDonnees');
