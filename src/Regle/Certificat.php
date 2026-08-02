<?php

namespace Uptimeez\Regle;

/**
 * Le certificat TLS est-il valide, et pour combien de temps encore ?
 *
 * ------------------------------------------------------------------------------
 * CE QUE L'EXTRACTION CORRIGE, ET CE N'EST PAS QU'UN DÉPLACEMENT
 * ------------------------------------------------------------------------------
 *
 * Dans evaluate(), ces verdicts étaient écrits DEUX FOIS. Une première fois après une
 * inspection TLS fraîche, une seconde fois quand l'inspection datait de moins de six
 * heures et qu'on se contentait des colonnes en base. Deux copies des mêmes phrases,
 * du même seuil d'alerte, du même cas particulier « expire demain ».
 *
 * Deux copies veulent dire deux endroits à corriger, et donc, tôt ou tard, un seul des
 * deux corrigé. La branche en cache avait d'ailleurs déjà divergé : elle ne savait pas
 * dire SSL_INVALID, si bien qu'un certificat au nom d'hôte erroné passait inaperçu
 * pendant les six heures suivant la première inspection qui l'avait vu.
 *
 * La règle ne connaît qu'une seule forme de faits. C'est au collecteur de la remplir,
 * qu'il l'obtienne du réseau ou de la base, et la question « d'où vient l'information »
 * cesse d'avoir un effet sur le verdict rendu.
 *
 * ------------------------------------------------------------------------------
 * POURQUOI L'ORDRE DES TROIS TESTS N'EST PAS INTERCHANGEABLE
 * ------------------------------------------------------------------------------
 *
 * Un certificat expiré est AUSSI un certificat invalide, et il expire AUSSI dans moins
 * de jours que le seuil. Les trois conditions sont donc vraies en même temps, et seul
 * l'ordre décide de ce qu'on lit dans l'alerte. On annonce l'expiration, parce que
 * c'est la cause : « invalide » et « expire bientôt » en sont les conséquences, et les
 * annoncer à sa place enverrait chercher un problème qui n'existe pas.
 *
 * ------------------------------------------------------------------------------
 * L'INSPECTION RESTE DEHORS, DÉLIBÉRÉMENT
 * ------------------------------------------------------------------------------
 *
 * Ouvrir une connexion TLS est un effet, pas une décision. Si la règle le faisait, la
 * tester demanderait un serveur, un certificat, et une horloge qu'on puisse avancer de
 * quatre-vingt-neuf jours. Elle reçoit donc des faits déjà établis, ce qui rend chaque
 * cas de bord vérifiable en trois lignes, y compris ceux qu'on ne saurait pas fabriquer.
 */
final class Certificat implements Regle
{
    /** Le nom sous lequel le collecteur dépose les faits du certificat. */
    public const DETECTEUR = 'certificat';

    public function evaluer(Contexte $c): ?Verdict
    {
        $cert = $c->detecteur(self::DETECTEUR);

        // Pas de détecteur, ou une inspection qui n'a pas abouti : on ne sait rien.
        // Se taire est le seul verdict honnête, et surtout pas « certificat invalide » :
        // une panne réseau nous ferait alors accuser le certificat.
        if (! is_array($cert) || ($cert['checked'] ?? false) !== true) {
            return null;
        }

        $jours = isset($cert['days_left']) && $cert['days_left'] !== null
            ? (int) $cert['days_left']
            : null;

        if (($cert['code'] ?? null) === 'SSL_EXPIRED') {
            return $this->expire($cert['expires_at'] ?? null);
        }

        if (($cert['valid'] ?? true) !== true) {
            return Verdict::pour('down', 'SSL_INVALID', 'Certificat SSL invalide : {reason}',
                ['reason' => ((string) ($cert['error'] ?? '')) ?: t('refusé')]);
        }

        // Le compte à rebours passé sous zéro dit la même chose que le code, mais c'est
        // la seule chose que sache la branche en cache : sans lui, un certificat expiré
        // resterait annoncé « expire dans -3 jours ».
        if ($jours !== null && $jours < 0) {
            return $this->expire($cert['expires_at'] ?? null);
        }

        $seuil = (int) $c->reglage('ssl_warn_days', 0);

        if ($jours !== null && $jours <= $seuil) {
            return Verdict::pour('degraded', 'SSL_SOON',
                $jours === 1 ? 'Certificat SSL expire demain' : 'Certificat SSL expire dans {n} jours',
                ['n' => $jours]);
        }

        return null;
    }

    /**
     * La date d'expiration est donnée quand on la connaît, et tue une question.
     *
     * « Expiré » seul laisse chercher depuis quand, donc si le renouvellement a échoué
     * hier soir ou il y a trois semaines. Ce n'est pas la même urgence, ni la même cause.
     */
    private function expire(mixed $date): Verdict
    {
        $horodatage = is_string($date) && $date !== '' ? strtotime($date) : false;

        return $horodatage === false
            ? Verdict::pour('down', 'SSL_EXPIRED', 'Certificat SSL expiré')
            : Verdict::pour('down', 'SSL_EXPIRED', 'Certificat SSL expiré le {date}',
                ['date' => date('d/m/Y', $horodatage)]);
    }
}
