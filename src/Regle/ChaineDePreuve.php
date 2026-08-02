<?php

namespace Uptimeez\Regle;

use Uptimeez\Runner;

/**
 * La chaîne de contrôle est-elle toujours dans la page ?
 *
 * ------------------------------------------------------------------------------
 * CE QUE CETTE RÈGLE DÉTECTE, ET POURQUOI ELLE EST LE CŒUR DU PRODUIT
 * ------------------------------------------------------------------------------
 *
 * Un site peut répondre 200, servir un HTML impeccable, et n'afficher qu'une page
 * vide parce que la base de données a lâché. Tous les vérificateurs qui lisent le
 * code de statut le déclarent en bonne santé. La chaîne de preuve est la réponse à
 * ça : un texte qui ne peut venir QUE du contenu du site, par exemple le copyright
 * du pied de page. S'il disparaît alors que la page répond, ce n'est pas la page qui
 * est cassée, c'est ce qui la remplit.
 *
 * ------------------------------------------------------------------------------
 * LE PIÈGE, ET IL A DÉJÀ FABRIQUÉ DE FAUSSES PANNES
 * ------------------------------------------------------------------------------
 *
 * Le corps d'une réponse est coupé au-delà d'une certaine taille. Une page de
 * catalogue trop lourde perdait donc sa chaîne de contrôle, non pas parce qu'elle
 * avait disparu, mais parce qu'on n'avait pas lu jusqu'à elle. Le verdict tombait :
 * « le contenu n'est plus servi », donc « la base de données ne répond plus », donc
 * une alerte pour un site parfaitement sain.
 *
 * Une absence dans un texte incomplet ne prouve rien. La règle dit alors « je n'ai
 * pas pu vérifier » plutôt que d'inventer une panne, et c'est un état DÉGRADÉ : le
 * contrôle n'a pas conclu, ce qui mérite d'être su sans mériter une alerte de panne.
 *
 * ------------------------------------------------------------------------------
 * PREMIÈRE RÈGLE EXTRAITE DE Runner::evaluate(), LE 2026-08-02
 * ------------------------------------------------------------------------------
 *
 * Choisie en premier parce qu'elle est la plus isolée : elle ne lit que la sonde et
 * le corps de la réponse, sans détecteur, sans référence, sans état précédent. Une
 * extraction se juge d'abord sur ce qu'elle n'entraîne pas avec elle.
 */
final class ChaineDePreuve implements Regle
{
    /** Longueur d'aperçu de la chaîne dans le message : au-delà, elle noie la phrase. */
    private const APERCU = 60;

    public function evaluer(Contexte $c): ?Verdict
    {
        $attendue = trim((string) $c->reglage('expect_string', ''));

        if ($attendue === '') {
            return null;
        }

        if (Runner::containsAny($c->reponse->body, $attendue)) {
            return null;
        }

        if (! $c->corpsComplet()) {
            return Verdict::pour(
                'degraded',
                'BODY_TRUNCATED',
                'Page trop volumineuse pour être vérifiée en entier ({size} lus) : la chaîne de contrôle n\'a pas pu être cherchée jusqu\'au bout.',
                ['size' => human_bytes(strlen($c->reponse->body))]
            );
        }

        return Verdict::pour(
            'down',
            'STRING_MISSING',
            'La chaîne de contrôle « {string} » est absente de la page : le contenu n\'est plus servi, par le serveur web ou par la base de données.',
            ['string' => str_cut($attendue, self::APERCU)]
        );
    }
}
