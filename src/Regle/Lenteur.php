<?php

namespace Uptimeez\Regle;

/**
 * La page a-t-elle mis plus longtemps que le seuil accepté ?
 *
 * ------------------------------------------------------------------------------
 * ZÉRO VEUT DIRE « PAS DE SEUIL », ET LE CONTRAIRE A DÉJÀ ÉTÉ CODÉ
 * ------------------------------------------------------------------------------
 *
 * Le formulaire annonce qu'un seuil à zéro désactive le contrôle. Le code repliait
 * pourtant sur trois secondes quand le champ valait zéro, si bien que le désactiver
 * y remettait en réalité la valeur par défaut : le client croyait avoir éteint une
 * alerte, et il continuait à la recevoir.
 *
 * C'est le genre de défaut qu'aucune alerte ne révèle, puisqu'il se manifeste par
 * une alerte. Le seuil est donc lu tel quel, sans repli d'aucune sorte, et un test
 * garde ce comportement explicitement.
 *
 * ------------------------------------------------------------------------------
 * POURQUOI C'EST DÉGRADÉ ET JAMAIS HORS SERVICE
 * ------------------------------------------------------------------------------
 *
 * Une page lente a répondu. Elle est là, elle sert son contenu, et personne n'a
 * besoin d'être réveillé la nuit. Le plafond de gravité du Verdict l'imposerait de
 * toute façon, SLOW faisant partie des causes d'apparence : le dire ici aussi n'est
 * pas une redondance mais une intention, car la règle doit rester juste même si
 * quelqu'un desserre un jour ce plafond.
 */
final class Lenteur implements Regle
{
    public function evaluer(Contexte $c): ?Verdict
    {
        $seuil = (int) $c->reglage('slow_ms', 0);

        if ($seuil <= 0 || $c->reponse->totalMs <= $seuil) {
            return null;
        }

        // La durée est annoncée en secondes avec deux décimales, parce que
        // « 4 187 ms » demande une conversion mentale que « 4,19 s » évite.
        return Verdict::pour('degraded', 'SLOW', 'Temps de réponse élevé : {seconds} s',
            ['seconds' => number_format($c->reponse->totalMs / 1000, 2, ',', ' ')]);
    }
}
