<?php

namespace Uptimeez;

/**
 * L'adresse par laquelle CE serveur sort, et le jour où elle change.
 *
 * ------------------------------------------------------------------------------
 * LE DANGER, ET IL EST DE LA FAMILLE QUE CE PRODUIT COMBAT
 * ------------------------------------------------------------------------------
 *
 * Un collecteur qui suit deux cents sites finit par être mis en liste blanche chez les
 * hébergeurs : c'est ce qui l'autorise à demander une page toutes les quinze minutes sans
 * se faire prendre pour une attaque. Cette autorisation porte sur une ADRESSE IP.
 *
 * Le jour où l'adresse sortante change — migration, changement d'offre, bascule de
 * passerelle — les listes blanches ne connaissent plus personne, et **rien ne le signale**.
 * Les sondes continuent de partir, l'hébergeur commence à les refuser, et le refus ressemble
 * à des pannes chez les clients. On passe alors ses journées à chercher un défaut chez eux.
 *
 * C'est exactement la forme du 2026-08-04 : une limitation de débit lue comme une panne de
 * site. La différence est qu'ici la cause serait chez l'hébergeur, invisible dans le code,
 * et qu'aucune relecture ne la trouverait.
 *
 * ------------------------------------------------------------------------------
 * ÉTEINTE PAR DÉFAUT, ET C'EST UNE DÉCISION, PAS UNE PRÉCAUTION
 * ------------------------------------------------------------------------------
 *
 * Connaître son adresse sortante demande de la faire dire par un tiers : une machine
 * derrière une passerelle ne peut pas la deviner localement. Ce produit n'appelle aucun
 * service extérieur sans qu'on le lui demande, et ce n'est pas négociable : une installation
 * auto-hébergée qui contacterait un inconnu à chaque passe trahirait la raison pour laquelle
 * on l'auto-héberge.
 *
 * Le réglage est donc vide par défaut, et c'est l'exploitant qui nomme le service — le sien
 * s'il en a un. Sans réglage, cette classe ne fait rien et ne se plaint pas.
 *
 * ------------------------------------------------------------------------------
 * UNE FOIS PAR JOUR, ET UNE REMARQUE, PAS UN COURRIEL
 * ------------------------------------------------------------------------------
 *
 * Une adresse sortante ne change pas deux fois dans la journée : la relever à chaque passe
 * serait une requête par minute pour une information qui bouge une fois par an.
 *
 * Et le changement n'envoie pas de courriel. C'est délibéré, et c'est la règle posée le
 * 2026-08-04 : le courriel est réservé à ce qui prive un visiteur de sa page. Un changement
 * d'adresse ne casse rien tout seul, il rend une autorisation périmée. Sa place est sur
 * l'écran, dans « à prévoir », là où vivent déjà les certificats et les failles.
 */
final class AdresseSortante
{
    /** Sous ce réglage, l'exploitant nomme le service qui lui dit son adresse. */
    public const REGLAGE_URL = 'security.echo_ip_url';

    /** Les deux clés d'état : l'adresse connue, et la date du dernier relevé. */
    public const CLE_ADRESSE = 'adresse_sortante';
    public const CLE_RELEVE  = 'adresse_sortante_le';

    /** L'adresse relevée la veille reste vraie aujourd'hui : un relevé par jour suffit. */
    public const INTERVALLE_SEC = 86400;

    /**
     * Faut-il relever maintenant ?
     *
     * Extrait pour être éprouvé sans horloge partagée ni réseau : c'est la seule décision de
     * cette classe qui pourrait se tromper en silence, en relevant à chaque passe.
     */
    public static function relevePrevu(?string $dernierReleve, int $maintenant, int $intervalle = self::INTERVALLE_SEC): bool
    {
        if ($dernierReleve === null || trim($dernierReleve) === '') {
            return true;
        }

        $t = strtotime($dernierReleve);

        return $t === false || ($maintenant - $t) >= $intervalle;
    }

    /**
     * Ce qu'on retient d'une réponse de service d'écho.
     *
     * ON N'ACCEPTE QU'UNE ADRESSE, ET RIEN D'AUTRE. Un service d'écho peut rendre du JSON, du
     * texte, une page d'erreur ou une page de connexion à un portail captif. Enregistrer
     * n'importe quoi ferait annoncer un changement d'adresse à chaque incident du service,
     * c'est-à-dire un faux positif sur un contrôle censé n'en produire aucun.
     */
    public static function adresseLue(string $corps): ?string
    {
        $corps = trim($corps);

        // Le cas courant : le corps EST l'adresse.
        if (filter_var($corps, FILTER_VALIDATE_IP) !== false) {
            return $corps;
        }

        // Le cas JSON, sans décoder : on cherche une adresse, pas une structure. Un service
        // qui change la forme de son JSON ne doit pas faire taire ce contrôle.
        if (preg_match('~\b((?:\d{1,3}\.){3}\d{1,3})\b~', $corps, $m)
            && filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $m[1];
        }

        if (preg_match('~\b([0-9a-f:]{6,45})\b~i', $corps, $m)
            && filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return $m[1];
        }

        return null;
    }

    /**
     * Relève l'adresse si c'est l'heure, et rend ce qu'il faut en dire.
     *
     * @return array{etat:'eteint'|'inchange'|'reporte'|'illisible'|'premier'|'change',
     *               avant:?string, apres:?string}
     */
    public static function verifier(?int $maintenant = null): array
    {
        $maintenant = $maintenant ?? time();
        $url = trim((string) Config::get(self::REGLAGE_URL, ''));
        $connue = Db::setting(self::CLE_ADRESSE);
        $connue = $connue === null ? null : (string) $connue;

        if ($url === '') {
            return ['etat' => 'eteint', 'avant' => $connue, 'apres' => null];
        }

        $dernier = Db::setting(self::CLE_RELEVE);

        if (! self::relevePrevu($dernier === null ? null : (string) $dernier, $maintenant)) {
            return ['etat' => 'reporte', 'avant' => $connue, 'apres' => $connue];
        }

        $res = Http::fetch($url, ['timeout' => 8, 'maxBody' => 4096]);
        $lue = $res->ok ? self::adresseLue((string) $res->body) : null;

        // UN SERVICE MUET NE VAUT PAS UN CHANGEMENT D'ADRESSE. On ne touche ni à l'adresse
        // connue ni à la date : le prochain passage réessaiera, et rien n'est annoncé.
        if ($lue === null) {
            return ['etat' => 'illisible', 'avant' => $connue, 'apres' => null];
        }

        Db::setSetting(self::CLE_RELEVE, date('Y-m-d H:i:s', $maintenant));

        if ($connue === null || $connue === '') {
            Db::setSetting(self::CLE_ADRESSE, $lue);

            return ['etat' => 'premier', 'avant' => null, 'apres' => $lue];
        }

        if ($connue === $lue) {
            return ['etat' => 'inchange', 'avant' => $connue, 'apres' => $lue];
        }

        // LA NOUVELLE ADRESSE EST ENREGISTRÉE, et l'ancienne part dans le journal : sans
        // l'ancienne, l'exploitant ne peut pas dire à son hébergeur ce qu'il faut remplacer.
        Db::setSetting(self::CLE_ADRESSE, $lue);
        Db::setSetting('adresse_sortante_changee_le', date('Y-m-d H:i:s', $maintenant));
        // L'ÉVÉNEMENT PORTE LES DEUX ADRESSES. Sans l'ancienne, l'exploitant ne peut pas dire
        // à son hébergeur laquelle remplacer, et c'est tout ce qu'il a à faire.
        Db::insert('events', [
            'monitor_id' => null, 'ts' => date('Y-m-d H:i:s', $maintenant),
            'kind' => 'adresse_sortante',
            'message' => t('Adresse sortante changée : {avant} devient {apres}',
                ['avant' => $connue, 'apres' => $lue]),
            'details' => jenc(['avant' => $connue, 'apres' => $lue]), 'seen' => 0,
        ]);

        return ['etat' => 'change', 'avant' => $connue, 'apres' => $lue];
    }

    /**
     * Ce qu'il faut montrer dans « à prévoir », ou rien.
     *
     * @return array<string,mixed>|null
     */
    public static function aSignaler(): ?array
    {
        $adresse = Db::setting(self::CLE_ADRESSE);
        $change  = Db::setting('adresse_sortante_changee_le');

        if ($adresse === null || $change === null) {
            return null;
        }

        // Sept jours : le temps de faire la demande auprès des hébergeurs. Passé ce délai la
        // remarque s'efface d'elle-même, parce qu'une remarque permanente cesse d'être lue.
        if ((time() - (int) strtotime((string) $change)) > 7 * 86400) {
            return null;
        }

        return [
            'kind' => 'upcoming', 'id' => 0, 'icon' => 'globe', 'urgency' => 'warn', 'days' => 0,
            'title' => t('L\'adresse sortante de ce serveur a changé : {ip}', ['ip' => (string) $adresse]),
            'why'   => t('Les autorisations posées chez les hébergeurs portent sur l\'ancienne adresse : elles ne vous reconnaissent plus. Un refus de leur part ressemblera à une panne chez le client.'),
        ];
    }
}
