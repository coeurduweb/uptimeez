<?php

namespace Uptimeez\Regle;

use Uptimeez\Detect\Discovery;

/**
 * La page interdit-elle aux moteurs de l'indexer ?
 *
 * ------------------------------------------------------------------------------
 * CE N'EST PAS UNE PANNE, ET C'EST POURTANT LE DÉFAUT LE PLUS COÛTEUX
 * ------------------------------------------------------------------------------
 *
 * Un « noindex » oublié après une recette est invisible : le site répond, il s'affiche,
 * tout va bien. Il disparaît simplement des moteurs, et personne ne s'en aperçoit avant
 * que le trafic ne s'effondre, c'est-à-dire des semaines plus tard. Aucun autre verdict
 * du moteur n'a ce délai entre la cause et le symptôme.
 *
 * Le parc en portait quatre en production au 2026-08-02, dont trois avec un titre de
 * gabarit jamais personnalisé, ce qui désigne la même cause : une mise en ligne dont la
 * dernière étape a été oubliée.
 *
 * ------------------------------------------------------------------------------
 * DÉGRADÉ, PAS HORS SERVICE, ET CE N'EST PAS UNE HÉSITATION
 * ------------------------------------------------------------------------------
 *
 * Le site fonctionne. Réveiller quelqu'un la nuit pour un « noindex » userait l'alerte
 * pour une correction qui attendra sans dommage jusqu'au matin. Le plafond de gravité du
 * Verdict l'imposerait de toute façon, NOINDEX étant une cause d'apparence.
 *
 * ------------------------------------------------------------------------------
 * LA CONDITION D'ENTRÉE APPARTIENT AU CONTEXTE
 * ------------------------------------------------------------------------------
 *
 * Chercher une directive « robots » dans une réponse 500, dans un PDF ou dans un flux
 * JSON n'a pas de sens : son absence y est normale et n'apprend rien. La question « ai-je
 * une page HTML analysable » est posée par le contexte, en un seul endroit, parce que les
 * quatre règles du CSS la poseront à l'identique et que cinq copies finiraient par
 * diverger.
 */
final class Indexabilite implements Regle
{
    public function evaluer(Contexte $c): ?Verdict
    {
        if (! $c->actif('check_noindex') || ! $c->htmlExploitable()) {
            return null;
        }

        $detail = Discovery::noindex($c->reponse);

        if ($detail === null || $detail === '') {
            return null;
        }

        // Le détail dit OÙ l'interdiction a été trouvée : en-tête « X-Robots-Tag » ou
        // balise dans la page. Ce n'est pas le même fichier à corriger, donc pas le même
        // interlocuteur, et l'omettre transformerait l'alerte en chasse au trésor.
        return Verdict::pour('degraded', 'NOINDEX', 'Page en noindex : {detail}',
            ['detail' => $detail]);
    }
}
