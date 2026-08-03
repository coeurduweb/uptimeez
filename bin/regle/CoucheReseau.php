<?php

/**
 * La couche réseau : la seule règle qui arrête toutes les autres.
 *
 * SANS RÉPONSE, IL N'Y A RIEN À ANALYSER, et c'est le sens de son privilège : pas de corps
 * où chercher une chaîne, pas de code à comparer, pas de feuille de style à télécharger.
 * Les autres règles se tairaient de toute façon, mais elles se tairaient APRÈS avoir
 * travaillé, et un détecteur mal écrit pourrait conclure quelque chose d'une réponse vide.
 *
 * LE CAS DU CERTIFICAT, qui est la partie intéressante. Un échec TLS remonte de la
 * bibliothèque HTTP sous une forme inutilisable : « SSL connect error » ne dit ni pourquoi
 * ni jusqu'à quand. Quand un diagnostic a été fait, il REMPLACE le code brut au lieu de
 * s'y ajouter : deux lignes pour une panne, dont la première n'apprend rien, et c'est
 * toujours la plus vague qui finit citée dans le résumé.
 */
declare(strict_types=1);

require __DIR__ . '/_harnais.php';

use Uptimeez\Regle\CoucheReseau;

$regle = new CoucheReseau();

titre('Une réponse reçue n\'est pas une panne de réseau');

check('un 200 : rien à dire',
    verdict($regle->evaluer(contexte([], reponse()))), null);

check('un 500 non plus : c\'est la règle du code HTTP qui en parlera',
    verdict($regle->evaluer(contexte([], reponse(['status' => 500])))), null);

titre('Rien du tout : le code d\'erreur de la bibliothèque devient la cause');

foreach (['TIMEOUT', 'DNS', 'CONNECT', 'CONNECT_RESET', 'REDIRECT_LOOP'] as $code) {
    check("échec « $code » : hors service, cause reprise telle quelle",
        verdict($regle->evaluer(contexte([], reponse(['ok' => false, 'status' => 0, 'errorCode' => $code])))),
        ['etat' => 'down', 'cause' => $code]);
}

check('un échec sans code nommé retombe sur NET_ERROR',
    verdict($regle->evaluer(contexte([], reponse(['ok' => false, 'status' => 0])))),
    ['etat' => 'down', 'cause' => CoucheReseau::CAUSE_PAR_DEFAUT]);

titre('Le diagnostic TLS remplace le code brut, il ne s\'y ajoute pas');

$avecDiagnostic = static fn (array $diag): array => [CoucheReseau::DETECTEUR => $diag];

check('un diagnostic nomme sa propre cause',
    verdict($regle->evaluer(contexte([],
        reponse(['ok' => false, 'status' => 0, 'errorCode' => 'SSL_HANDSHAKE']),
        $avecDiagnostic(['code' => 'SSL_EXPIRED', 'message' => 'Certificat SSL expiré'])))),
    ['etat' => 'down', 'cause' => 'SSL_EXPIRED']);

check('un diagnostic sans code nommé est un certificat invalide',
    verdict($regle->evaluer(contexte([],
        reponse(['ok' => false, 'status' => 0]),
        $avecDiagnostic(['message' => 'Certificat refusé par le système'])))),
    ['etat' => 'down', 'cause' => 'SSL_INVALID']);

check('l\'échéance est jointe quand on la connaît : « expiré » seul laisse chercher depuis quand',
    message($regle->evaluer(contexte([],
        reponse(['ok' => false, 'status' => 0]),
        $avecDiagnostic(['code' => 'SSL_EXPIRED', 'message' => 'Certificat SSL expiré',
                         'expires_at' => '2026-07-12 08:00:00'])))),
    'Certificat SSL expiré (échéance 12/07/2026)');

check('sans échéance, le message reste celui du diagnostic',
    message($regle->evaluer(contexte([],
        reponse(['ok' => false, 'status' => 0]),
        $avecDiagnostic(['code' => 'SSL_EXPIRED', 'message' => 'Certificat SSL expiré'])))),
    'Certificat SSL expiré');

check('un diagnostic vide est ignoré : on ne remplace pas une cause par du silence',
    verdict($regle->evaluer(contexte([],
        reponse(['ok' => false, 'status' => 0, 'errorCode' => 'TIMEOUT']),
        $avecDiagnostic(['message' => ''])))),
    ['etat' => 'down', 'cause' => 'TIMEOUT']);

bilan('CoucheReseau');
