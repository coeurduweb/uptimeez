<?php

namespace Uptimeez\Regle;

use Uptimeez\Http;

/**
 * A-t-on seulement réussi à joindre le serveur ?
 *
 * ------------------------------------------------------------------------------
 * LA SEULE RÈGLE QUI ARRÊTE TOUT LE RESTE
 * ------------------------------------------------------------------------------
 *
 * Sans réponse, il n'y a rien à analyser : pas de corps où chercher une chaîne, pas de
 * code de statut à comparer, pas de feuille de style à télécharger. Les autres règles se
 * tairaient toutes, mais elles se tairaient EN AYANT TRAVAILLÉ, et surtout un détecteur
 * mal écrit pourrait conclure quelque chose d'une réponse vide. Le collecteur s'arrête
 * donc dès que cette règle parle, et c'est la seule à avoir ce privilège.
 *
 * ------------------------------------------------------------------------------
 * QUAND LE CERTIFICAT EST EN CAUSE, ON REDEMANDE AU SERVEUR
 * ------------------------------------------------------------------------------
 *
 * Un échec TLS remonte de la bibliothèque HTTP sous une forme inutilisable : « SSL
 * connect error » ne dit ni pourquoi, ni jusqu'à quand. On rouvre alors une connexion en
 * TLS permissif, uniquement pour lire le certificat et rapporter la vraie cause. C'est le
 * seul cas où le moteur redemande quelque chose au serveur après un échec, et il le
 * mérite : sans ça, l'alerte dit « connexion impossible » alors que le certificat a
 * simplement expiré la veille, ce qui se corrige en cinq minutes quand on le sait.
 *
 * L'échéance est jointe quand on la connaît, pour la même raison que sur la règle du
 * certificat : « expiré » seul laisse chercher depuis quand.
 *
 * ------------------------------------------------------------------------------
 * LE DIAGNOSTIC REMPLACE LE CODE BRUT, IL NE S'Y AJOUTE PAS
 * ------------------------------------------------------------------------------
 *
 * Rendre les deux donnerait « connexion impossible » ET « certificat expiré le 12/07 »
 * pour un seul incident. Deux lignes pour une panne, dont la première est celle qui
 * n'apprend rien, et c'est toujours la plus vague qui finit citée dans le résumé.
 */
final class CoucheReseau implements Regle
{
    /** Le nom sous lequel le collecteur dépose le diagnostic TLS, s'il en a fait un. */
    public const DETECTEUR = 'diagnostic_tls';

    /** Ce qu'on dit quand la bibliothèque HTTP n'a pas su nommer son échec. */
    public const CAUSE_PAR_DEFAUT = 'NET_ERROR';

    public function evaluer(Contexte $c): ?Verdict
    {
        // Une réponse reçue, même en 500, n'est pas une panne de réseau : c'est la règle
        // du code HTTP qui en parlera. Le statut zéro dit qu'il n'y a rien eu du tout.
        if ($c->reponse->ok && $c->reponse->status !== 0) {
            return null;
        }

        $diagnostic = $c->detecteur(self::DETECTEUR);

        if (is_array($diagnostic) && ((string) ($diagnostic['message'] ?? '')) !== '') {
            $cause = ((string) ($diagnostic['code'] ?? '')) ?: 'SSL_INVALID';
            $message = (string) $diagnostic['message'];
            $echeance = (string) ($diagnostic['expires_at'] ?? '');
            $horodatage = $echeance !== '' ? strtotime($echeance) : false;

            return $horodatage === false
                ? Verdict::pour('down', $cause, $message)
                : Verdict::pour('down', $cause, '{reason} (échéance {date})',
                    ['reason' => $message, 'date' => date('d/m/Y', $horodatage)]);
        }

        $code = ((string) ($c->reponse->errorCode ?? '')) ?: self::CAUSE_PAR_DEFAUT;

        return Verdict::pour('down', $code, Http::errorLabel($code));
    }
}
