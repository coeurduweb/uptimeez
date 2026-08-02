<?php

namespace Uptimeez\Regle;

/**
 * La page trahit-elle une base de données en panne, ou une application qui plante ?
 *
 * ------------------------------------------------------------------------------
 * LA PANNE QUE LE CODE DE STATUT NE VOIT JAMAIS
 * ------------------------------------------------------------------------------
 *
 * « Error establishing a database connection » sort en HTTP 200. Le serveur web va très
 * bien, il sert consciencieusement une page qui annonce que plus rien ne fonctionne. Tous
 * les vérificateurs qui s'arrêtent au code de statut déclarent le site en bonne santé,
 * et le client découvre la panne par un appel de son propre client.
 *
 * ------------------------------------------------------------------------------
 * LA PREUVE EST CITÉE, ET CE N'EST PAS DE L'ORNEMENT
 * ------------------------------------------------------------------------------
 *
 * Une signature de panne est une reconnaissance de texte, donc faillible : un article de
 * blog qui parle des erreurs MySQL en contient les mots. Citer l'extrait trouvé permet à
 * celui qui reçoit l'alerte de trancher en une seconde, au lieu d'ouvrir le site pour
 * comprendre de quoi on lui parle. Une alerte qu'on ne peut pas vérifier finit ignorée,
 * et une alerte ignorée ne vaut pas mieux que pas d'alerte.
 *
 * Quand l'audit n'a pas d'extrait à montrer, on ne fabrique pas de guillemets vides : le
 * message se suffit à lui-même.
 *
 * ------------------------------------------------------------------------------
 * LE CORPS VIDE EST TRAITÉ AILLEURS
 * ------------------------------------------------------------------------------
 *
 * Sans corps, il n'y a pas de signature à chercher, et la panne est déjà dite par le
 * réseau ou par le code de statut. Signaler ici en plus donnerait deux verdicts pour une
 * seule panne, et le plus bavard des deux masquerait le plus juste.
 */
final class BaseDeDonnees implements Regle
{
    /** Le nom sous lequel le collecteur dépose le résultat de l'audit. */
    public const DETECTEUR = 'base';

    public function evaluer(Contexte $c): ?Verdict
    {
        if (! $c->actif('check_db') || $c->reponse->body === '') {
            return null;
        }

        $audit = $c->detecteur(self::DETECTEUR);

        if (! is_array($audit) || ($audit['state'] ?? 'ok') === 'ok') {
            return null;
        }

        $cause = isset($audit['reason']) && is_string($audit['reason']) && $audit['reason'] !== ''
            ? $audit['reason']
            : 'DB_DOWN';
        $message = (string) ($audit['message'] ?? '');
        $preuve = (string) ($audit['evidence'] ?? '');

        if ($preuve === '') {
            return Verdict::pour('down', $cause, $message);
        }

        return Verdict::pour('down', $cause, '{reason} : « {evidence} »',
            ['reason' => $message, 'evidence' => $preuve]);
    }
}
