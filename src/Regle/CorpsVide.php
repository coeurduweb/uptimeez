<?php

namespace Uptimeez\Regle;

/**
 * Une réponse 200 qui ne contient rien n'est pas une page.
 *
 * ------------------------------------------------------------------------------
 * POURQUOI CETTE RÈGLE EXISTE, ET CE QUE SON ABSENCE COÛTAIT
 * ------------------------------------------------------------------------------
 *
 * Le 2026-08-06, en construisant le jeu d'essai INVERSE — des situations réellement
 * cassées, pour voir lesquelles le moteur déclare normales — six cas ont été mesurés.
 * Cinq passaient au vert, dont celui-ci : **un corps entièrement vide, servi en 200**.
 *
 * Les autres cas manqués se rattrapent tous par une chaîne de preuve, et l'import en
 * pose une automatiquement. Celui-ci non : une page vide reste vide quelle que soit la
 * configuration, et il n'existe aucun réglage qui la rende correcte. C'est donc le seul
 * des cinq qui méritait du code plutôt qu'une ligne de documentation.
 *
 * Ce que voit le visiteur : une page blanche. Ce que voyait le moteur : 200 OK.
 *
 * ------------------------------------------------------------------------------
 * DEUX BORNES, ET LA SECONDE EST VOLONTAIREMENT PRUDENTE
 * ------------------------------------------------------------------------------
 *
 * RIEN DU TOUT est une panne : zéro octet, ou seulement des blancs. Aucun site en
 * fonctionnement ne répond ça sur une page ; c'est la signature d'un PHP qui meurt
 * avant d'écrire, d'un cache qui sert un fichier vide, d'un proxy qui coupe.
 *
 * PRESQUE RIEN est laissé de côté, exprès. On pourrait juger « moins de deux cents
 * octets de texte visible », mais une page de redirection, une page d'attente
 * légitime ou une réponse de test peuvent être minuscules et parfaitement voulues.
 * Fixer ce seuil sans l'avoir mesuré sur un parc réel fabriquerait des faux positifs,
 * exactement ce que ce moteur passe son temps à réparer. La règle s'arrête donc là où
 * la certitude s'arrête.
 *
 * ------------------------------------------------------------------------------
 * CE QU'ELLE NE JUGE PAS
 * ------------------------------------------------------------------------------
 *
 * Une réponse tronquée par notre propre lecteur (corps coupé au-delà de la limite
 * mémoire) n'est pas vide : elle est incomplète, et une autre règle le dit déjà. Une
 * sonde qui n'attend pas de HTML non plus : un fichier surveillé peut légitimement
 * peser zéro octet si c'est ce qu'il a toujours pesé, et c'est la règle du fichier
 * qui compare, pas celle-ci.
 */
final class CorpsVide implements Regle
{
    public function evaluer(Contexte $c): ?Verdict
    {
        // Seules les sondes qui attendent une page. Une API, un fichier ou un
        // enregistrement DNS ont chacun leur règle, et leur corps ne se juge pas ainsi.
        if (! in_array((string) $c->reglage('kind', 'page'), ['page', 'keyword'], true)) {
            return null;
        }

        // Une réponse en échec réseau, ou hors de la plage attendue, appartient à la
        // règle du code HTTP : deux règles qui parlent de la même panne rendent deux
        // alertes pour un seul défaut.
        if (! $c->reponse->ok || $c->reponse->status < 200 || $c->reponse->status >= 300) {
            return null;
        }

        // Un corps coupé par notre propre lecteur n'est pas un corps vide.
        if (! $c->corpsComplet()) {
            return null;
        }

        if (trim((string) $c->reponse->body) !== '') {
            return null;
        }

        return Verdict::pour('down', 'EMPTY_BODY',
            'La page répond {code} mais ne contient rien : le visiteur voit une page blanche.',
            ['code' => (string) $c->reponse->status]);
    }
}
