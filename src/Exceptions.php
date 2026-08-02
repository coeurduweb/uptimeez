<?php

namespace Uptimeez;

use Uptimeez\Regle\Verdict;

/**
 * « Ce signal, sur cette sonde, ne compte pas. »
 *
 * ------------------------------------------------------------------------------
 * LE GESTE QUI REND LE PRODUIT UTILISABLE SUR UN VRAI PARC
 * ------------------------------------------------------------------------------
 *
 * Sur deux cents sites il y a toujours quelques cas particuliers légitimes : un
 * « noindex » délibéré sur une recette, une police d'icônes absente qu'on assume, un
 * export volontairement lent. Sans moyen de les taire, l'exploitant apprend à ignorer
 * l'écran entier, et c'est alors la vraie panne qui passe inaperçue. L'exception n'est pas
 * un aveu de faiblesse du détecteur, c'est ce qui garde son signal lisible.
 *
 * ------------------------------------------------------------------------------
 * ON NE PEUT PAS TAIRE UNE PANNE. LA GARANTIE EST DOUBLE, ET LA SECONDE EST LA VRAIE
 * ------------------------------------------------------------------------------
 *
 * On peut taire une police manquante, jamais un 503. La première garantie est une LISTE :
 * seules les causes d'apparence sont excusables, et la création refuse les autres. Une
 * liste, cependant, se périme dès qu'on ajoute une cause en oubliant de la mettre à jour.
 *
 * La seconde garantie ne peut pas se périmer : au moment d'appliquer, on refuse de masquer
 * tout verdict qui n'est pas « dégradé ». Un verdict « hors service » veut dire que le
 * visiteur n'a pas la page ; aucune exception, aucune configuration, aucun réglage ne doit
 * pouvoir le faire disparaître. Si une cause d'apparence se mettait un jour à produire un
 * « hors service », la liste laisserait passer l'exception et le contrôle d'état
 * l'arrêterait quand même.
 *
 * ------------------------------------------------------------------------------
 * UNE EXCEPTION OUBLIÉE EST UNE PANNE QU'ON NE VERRA PAS
 * ------------------------------------------------------------------------------
 *
 * D'où deux contraintes qui ne se négocient pas. Une DATE DE REVUE obligatoire, six mois
 * par défaut, parce qu'une exception posée pendant une migration survit à la migration. Et
 * un COMPTEUR : chaque alerte tue est comptée, pour qu'on puisse dire « 12 alertes masquées
 * par vos exceptions ce mois-ci ». Une exception silencieuse et éternelle serait pire que
 * le faux positif qu'elle supprime, parce qu'un faux positif, au moins, se voit.
 */
final class Exceptions
{
    /** Six mois : assez long pour ne pas harceler, assez court pour qu'on s'en souvienne. */
    public const REVUE_MOIS = 6;

    /**
     * Les causes qu'on accepte de taire.
     *
     * Ce sont EXACTEMENT les causes d'apparence, celles que le Verdict plafonne déjà à
     * « dégradé ». La liste n'est pas recopiée : elle est dérivée de Verdict, sans quoi les
     * deux divergeraient, et c'est précisément la faute qu'on a passé la journée à réparer
     * ailleurs dans ce moteur.
     */
    public static function estExcusable(?string $cause): bool
    {
        return $cause !== null && $cause !== '' && Verdict::estUneApparence($cause);
    }

    /**
     * Pose une exception, ou refuse.
     *
     * @throws \InvalidArgumentException si la cause n'est pas excusable
     */
    public static function poser(
        int $monitorId,
        string $causeCode,
        string $raison,
        string $motifSignal = '',
        ?string $revoirLe = null,
    ): int {
        if ($monitorId <= 0) {
            throw new \InvalidArgumentException("Sonde invalide : « $monitorId »");
        }

        if (!self::estExcusable($causeCode)) {
            throw new \InvalidArgumentException("Cause non excusable : « $causeCode »");
        }

        // LA RAISON EST OBLIGATOIRE. Dans six mois, à la revue, « pourquoi cette exception »
        // sera la seule question qui compte, et personne ne s'en souviendra. Une exception
        // sans raison sera reconduite par défaut, faute de pouvoir juger.
        //
        // Comme dans src/Retour.php, ces messages sont écrits pour qui lit le code : ils
        // nomment la valeur fautive et restent en français. Ils ne sortent pas — l'appelant
        // les met au journal et rend une phrase traduite.
        if (trim($raison) === '') {
            throw new \InvalidArgumentException(
                "Exception sans raison refusée, sonde « $monitorId », cause « $causeCode »");
        }

        return Db::insert('exceptions', [
            'monitor_id'   => $monitorId,
            'reason_code'  => $causeCode,
            'motif_signal' => str_cut(trim($motifSignal), 300),
            'raison'       => str_cut(trim($raison), 500),
            'cree_le'      => date('Y-m-d H:i:s'),
            'revoir_le'    => $revoirLe ?: date('Y-m-d H:i:s', strtotime('+' . self::REVUE_MOIS . ' months')),
            'actif'        => 1,
        ]);
    }

    /** Une exception se révoque, elle ne se supprime pas : la trace de ce qu'on a tu compte. */
    public static function revoquer(int $id): void
    {
        Db::update('exceptions', ['actif' => 0], 'id = :__i', ['__i' => $id]);
    }

    /**
     * Retire d'une liste de verdicts ceux qu'une exception couvre, et les compte.
     *
     * @param list<array<string,mixed>> $findings
     * @return list<array<string,mixed>>
     */
    public static function filtrer(int $monitorId, array $findings): array
    {
        if ($findings === [] || $monitorId <= 0) {
            return $findings;
        }

        $regles = Db::all('SELECT * FROM exceptions WHERE monitor_id = ? AND actif = 1', [$monitorId]);

        if ($regles === []) {
            return $findings;
        }

        $restants = [];

        foreach ($findings as $f) {
            $couvrante = self::couvrante($regles, $f);

            if ($couvrante === null) {
                $restants[] = $f;
                continue;
            }

            self::compter((int) $couvrante['id']);
        }

        return $restants;
    }

    /**
     * L'exception qui couvre ce verdict, s'il en existe une.
     *
     * @param list<array<string,mixed>> $regles
     * @param array<string,mixed> $finding
     */
    private static function couvrante(array $regles, array $finding): ?array
    {
        // LA GARANTIE QUI NE PEUT PAS SE PÉRIMER. On refuse de masquer autre chose qu'un
        // « dégradé », quoi qu'en dise la liste des causes excusables au moment de la
        // création. « Hors service » veut dire que le visiteur n'a pas la page, et aucune
        // configuration ne doit pouvoir le faire disparaître.
        if (($finding['state'] ?? '') !== 'degraded') {
            return null;
        }

        $cause = (string) ($finding['reason'] ?? '');

        if ($cause === '') {
            return null;
        }

        foreach ($regles as $regle) {
            if ((string) $regle['reason_code'] !== $cause) {
                continue;
            }

            $motif = trim((string) ($regle['motif_signal'] ?? ''));

            // Sans motif de signal, l'exception couvre toute la cause : c'est plus large,
            // donc plus risqué, et c'est un choix que l'exploitant fait en connaissance.
            if ($motif === '') {
                return $regle;
            }

            // Avec un motif, elle ne couvre que les verdicts dont le détail le contient :
            // taire « police d'icônes absente » ne doit pas taire « feuille de style
            // introuvable », qui est la même cause et un tout autre problème.
            $detail = '';
            foreach ((array) ($finding['vars'] ?? []) as $valeur) {
                if (is_scalar($valeur)) {
                    $detail .= ' ' . (string) $valeur;
                }
            }
            $detail .= ' ' . (string) ($finding['message'] ?? '');

            if (mb_stripos($detail, $motif) !== false) {
                return $regle;
            }
        }

        return null;
    }

    /**
     * Compte une alerte tue.
     *
     * Le compteur mensuel se remet à zéro au changement de mois plutôt que d'accumuler un
     * historique : la question posée est « combien ce mois-ci », pas « quelle chronologie ».
     */
    private static function compter(int $id): void
    {
        $mois = date('Y-m');
        $ligne = Db::one('SELECT masquees_mois, masquees_ce_mois FROM exceptions WHERE id = ?', [$id]);
        $memeMois = (string) ($ligne['masquees_mois'] ?? '') === $mois;

        // Le total s'incrémente EN BASE et non en PHP : deux passes concurrentes sur des
        // sondes différentes touchent la même exception si elle porte sur un parc partagé,
        // et un « lire puis écrire » en perdrait une. Le compteur mensuel accepte ce risque
        // parce qu'il doit AUSSI arbitrer le changement de mois, ce qu'un simple « +1 » ne
        // sait pas faire — et parce qu'une alerte masquée oubliée dans un compte mensuel
        // n'a pas les conséquences d'une alerte masquée oubliée tout court.
        Db::q('UPDATE exceptions
                  SET masquees_total = masquees_total + 1,
                      masquees_mois = :m,
                      masquees_ce_mois = :n,
                      derniere_masquee_le = :d
                WHERE id = :i', [
            'm' => $mois,
            'n' => $memeMois ? ((int) ($ligne['masquees_ce_mois'] ?? 0)) + 1 : 1,
            'd' => date('Y-m-d H:i:s'),
            'i' => $id,
        ]);
    }

    /**
     * Ce que les exceptions ont tu ce mois-ci, pour le dire à l'exploitant.
     *
     * @return array{total:int, ce_mois:int, actives:int, a_revoir:int}
     */
    public static function bilan(): array
    {
        return [
            'total'   => (int) Db::val('SELECT COALESCE(SUM(masquees_total),0) FROM exceptions'),
            'ce_mois' => (int) Db::val(
                'SELECT COALESCE(SUM(masquees_ce_mois),0) FROM exceptions WHERE masquees_mois = ?',
                [date('Y-m')]),
            'actives'  => (int) Db::val('SELECT COUNT(*) FROM exceptions WHERE actif = 1'),
            'a_revoir' => (int) Db::val(
                'SELECT COUNT(*) FROM exceptions WHERE actif = 1 AND revoir_le <= ?',
                [date('Y-m-d H:i:s')]),
        ];
    }
}
