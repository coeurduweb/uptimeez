<?php

namespace Uptimeez;

/**
 * Ce que l'exploitant dit d'un incident, et qui ne change encore rien.
 *
 * ------------------------------------------------------------------------------
 * POURQUOI CETTE CLASSE N'AGIT SUR RIEN, ET POURQUOI C'EST VOULU
 * ------------------------------------------------------------------------------
 *
 * Le 2026-08-02, sur un parc réel, 43 des 47 sondes non vertes étaient des faux positifs,
 * dont treize annoncées hors service. La boucle de correction existante est « quelqu'un
 * remarque, quelqu'un enquête, quelqu'un modifie le code » : une journée entière pour un
 * seul client, et elle ne tiendra pas à dix.
 *
 * La tentation est un bouton qui fasse taire l'incident. Ce serait AGGRAVER le produit :
 * un défaut visible deviendrait un défaut invisible, et le jour où la règle se trompe
 * vraiment, plus personne ne le saurait. Ce qu'il faut apprendre n'est pas « cet incident
 * était faux » mais QUELLE règle s'est trompée, sur QUEL signal, à QUELLES conditions.
 *
 * On enregistre donc d'abord, on décide ensuite, et on ne décidera qu'après avoir LU le
 * corpus. Apprendre d'un corpus qu'on n'a jamais regardé, c'est automatiser une erreur
 * qu'on n'a pas encore identifiée.
 *
 * ------------------------------------------------------------------------------
 * LA PORTÉE EST LA DÉFENSE CONTRE L'EMPOISONNEMENT, ET ELLE EST À L'ÉCRITURE
 * ------------------------------------------------------------------------------
 *
 * « C'est normal sur cette sonde » et « c'est normal partout » appellent deux gestes
 * opposés : une exception locale, ou un assouplissement de la règle pour tout le monde.
 * Confondre les deux laisse un exploitant dégrader la détection des autres en déclarant
 * normal ce qui ne l'est que chez lui.
 *
 * La portée est donc obligatoire, et surtout elle est PLAFONNÉE ICI plutôt que filtrée
 * plus tard : un retour de portée « parc » venu d'une instance client ne vaut que pour son
 * parc, parce qu'une instance ne voit que ses propres sondes. Le mot est le même, le sens
 * est borné par ce que l'émetteur peut légitimement observer.
 *
 * ------------------------------------------------------------------------------
 * CE QU'ON REFUSE D'ENREGISTRER
 * ------------------------------------------------------------------------------
 *
 * Un motif ou une portée hors liste n'est pas rangé dans une case « autre » : il est
 * refusé. Une case « autre » se remplit toujours, et le jour où on lit le corpus elle est
 * la première en volume sans rien vouloir dire. Mieux vaut quatre motifs qui portent un
 * sens qu'un cinquième qui n'en porte aucun.
 */
final class Retour
{
    /**
     * Les quatre motifs, et ce qu'ils permettent de distinguer.
     *
     * Ils ne sont pas quatre nuances d'un même « c'est faux » : deux d'entre eux disent
     * que la DÉTECTION est en cause, un dit que le CONTEXTE l'est, et le dernier dit que
     * la détection avait raison. Les mélanger rendrait le corpus illisible, puisque la
     * question qu'on lui posera est justement « la règle s'est-elle trompée ».
     */
    public const MOTIFS = [
        // La règle a vu quelque chose de réel, mais qui n'a aucune conséquence visible.
        // Typiquement une police d'icônes absente : le détecteur ne ment pas, il rapporte
        // ce qui ne mérite pas une alerte.
        'sans_effet',
        // La règle s'est trompée : ce qu'elle a cru voir n'existe pas. C'est le motif qui
        // désigne un défaut du produit, et celui qu'on veut voir remonter en premier.
        'controle_errone',
        // La règle a raison dans l'absolu, mais pas ici : un « noindex » délibéré sur une
        // recette, une réponse volontairement lente sur un export. LOCAL par nature, et
        // c'est exactement le motif qu'un exploitant pressé emploiera à tort pour faire
        // taire un vrai défaut. D'où la portée, qui l'oblige à dire jusqu'où ça vaut.
        'normal_ici',
        // La détection avait raison, la panne a été réparée. Ce motif ne corrige rien et
        // c'est le plus précieux du lot : il donne les VRAIS positifs, sans lesquels on ne
        // sait pas si une règle se trompe souvent ou si elle sert souvent.
        'vrai_et_corrige',
    ];

    /** Jusqu'où l'exploitant prétend que son observation vaut. */
    public const PORTEES = ['sonde', 'serveur', 'parc'];

    /** Longueur retenue d'un commentaire libre : au-delà, c'est un rapport, pas un motif. */
    public const COMMENTAIRE_MAX = 500;

    /**
     * Enregistre un retour, ou refuse.
     *
     * @throws \InvalidArgumentException si le motif ou la portée sort de la liste
     */
    public static function enregistrer(
        int $monitorId,
        string $motif,
        string $portee = 'sonde',
        ?int $incidentId = null,
        ?string $causeCode = null,
        string $signal = '',
        string $commentaire = '',
        ?string $hote = null,
    ): int {
        if (!in_array($motif, self::MOTIFS, true)) {
            throw new \InvalidArgumentException("Motif inconnu : « $motif »");
        }

        if (!in_array($portee, self::PORTEES, true)) {
            throw new \InvalidArgumentException("Portée inconnue : « $portee »");
        }

        // LES TROIS MESSAGES CI-DESSUS ET CELUI-CI SONT ÉCRITS POUR QUI LIT LE CODE, pas
        // pour un utilisateur : ils nomment la valeur fautive et restent en français.
        // C'est légitime parce qu'ils ne sortent pas — api.php les met au journal et rend
        // une phrase traduite. Ils l'ont fait un moment, et c'est l'audit
        // d'internationalisation qui l'a signalé, pas moi : la première version renvoyait
        // getMessage() au client.
        if ($monitorId <= 0) {
            throw new \InvalidArgumentException("Sonde invalide pour un retour : « $monitorId »");
        }

        // Une portée « serveur » sans serveur nommé ne veut rien dire : on ne saurait ni
        // à qui l'appliquer, ni la relire dans six mois. On la RAMÈNE à la sonde plutôt
        // que de la refuser, parce que perdre le retour serait pire que le rétrécir.
        if ($portee === 'serveur' && ($hote === null || trim($hote) === '')) {
            $portee = 'sonde';
        }

        return Db::insert('retours', [
            'incident_id' => $incidentId,
            'monitor_id'  => $monitorId,
            'reason_code' => $causeCode,
            'signal'      => str_cut($signal, 500),
            'motif'       => $motif,
            'portee'      => $portee,
            'hote'        => $hote !== null ? str_cut(trim($hote), 255) : null,
            'commentaire' => str_cut(trim($commentaire), self::COMMENTAIRE_MAX),
            'ts'          => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Les motifs qui METTENT LA DÉTECTION EN CAUSE, par opposition à celui qui la confirme.
     *
     * « Normal ici » n'accuse pas la règle : il dit qu'elle a raison dans l'absolu et tort
     * dans ce contexte. Il est pourtant rangé du côté des contestations, parce que du point
     * de vue du corpus la question est la même — la règle a produit une alerte que
     * quelqu'un a jugée non pertinente. Ce qui les sépare est le GESTE qui suivra :
     * corriger la règle pour « contrôle erroné », poser une exception pour « normal ici ».
     */
    public const MOTIFS_CONTESTATION = ['sans_effet', 'controle_errone', 'normal_ici'];

    /**
     * Les signaux sur lesquels deux exploitants ne sont pas d'accord.
     *
     * ------------------------------------------------------------------------------
     * CE N'EST PAS DU BRUIT À TRANCHER, C'EST LE RENSEIGNEMENT LE PLUS UTILE DU CORPUS
     * ------------------------------------------------------------------------------
     *
     * Un signal contesté ici et confirmé ailleurs dit que la règle dépend d'un contexte
     * qu'on n'a pas nommé. Trancher en faveur de la majorité ferait disparaître
     * l'information : ce qu'il faut trouver n'est pas qui a raison, mais QUOI diffère entre
     * les deux installations.
     *
     * ------------------------------------------------------------------------------
     * LE SILENCE N'EST PAS UNE CONFIRMATION, ET C'EST LE PIÈGE DE CETTE REQUÊTE
     * ------------------------------------------------------------------------------
     *
     * On serait tenté de compter comme « confirmé » tout signal qui se déclenche ailleurs
     * sans que personne ne proteste. Ce serait faux : la quasi-totalité des gens ne
     * cliquent jamais sur rien. On mesurerait alors la propension à se plaindre, pas la
     * justesse de la règle, et le résultat donnerait systématiquement raison au silence.
     *
     * Une divergence exige donc DEUX AVIS EXPLICITES et opposés : quelqu'un a contesté, et
     * quelqu'un d'autre a confirmé que c'était vrai. C'est plus rare, et c'est le prix
     * d'une information qui veut dire quelque chose.
     *
     * @return list<array<string,mixed>>
     */
    public static function divergences(): array
    {
        $contestations = implode(',', array_map(
            static fn (string $m): string => "'" . $m . "'", self::MOTIFS_CONTESTATION));

        return Db::all(
            "SELECT reason_code,
                    SUM(CASE WHEN motif IN ($contestations) THEN 1 ELSE 0 END) AS contestes,
                    SUM(CASE WHEN motif = 'vrai_et_corrige' THEN 1 ELSE 0 END) AS confirmes,
                    COUNT(DISTINCT monitor_id) AS sondes,
                    COUNT(DISTINCT hote) AS serveurs
             FROM retours
             WHERE reason_code IS NOT NULL AND reason_code <> ''
             GROUP BY reason_code
             HAVING contestes > 0 AND confirmes > 0
             ORDER BY serveurs DESC, (contestes + confirmes) DESC"
        );
    }

    /**
     * Ce que le corpus dit de chaque cause, contestations et confirmations côte à côte.
     *
     * LES DEUX COLONNES SONT MONTRÉES ENSEMBLE, DÉLIBÉRÉMENT. Un signal contesté douze fois
     * n'a pas le même sens selon qu'il a été confirmé zéro fois ou quarante : dans le
     * premier cas la règle est probablement mauvaise, dans le second elle sert, et douze
     * installations ont une particularité. N'afficher que les contestations ferait
     * condamner la seconde règle sur le même relevé que la première.
     *
     * @return list<array<string,mixed>>
     */
    public static function parCause(int $limite = 50): array
    {
        $contestations = implode(',', array_map(
            static fn (string $m): string => "'" . $m . "'", self::MOTIFS_CONTESTATION));

        return Db::all(
            "SELECT reason_code,
                    COUNT(*) AS retours,
                    SUM(CASE WHEN motif IN ($contestations) THEN 1 ELSE 0 END) AS contestes,
                    SUM(CASE WHEN motif = 'vrai_et_corrige' THEN 1 ELSE 0 END) AS confirmes,
                    COUNT(DISTINCT monitor_id) AS sondes,
                    COUNT(DISTINCT hote) AS serveurs
             FROM retours
             GROUP BY reason_code
             ORDER BY serveurs DESC, contestes DESC
             LIMIT " . max(1, $limite)
        );
    }

    /**
     * Ce que le corpus dit d'un signal, sans rien en conclure.
     *
     * LES SERVEURS DISTINCTS SONT LA COLONNE QUI TRANCHE. Trente retours venus d'un seul
     * serveur disent qu'une installation est particulière ; trois retours venus de trois
     * serveurs disent que la règle est en cause. Compter les retours sans compter les
     * serveurs ferait passer le premier cas pour dix fois plus grave que le second, alors
     * que c'est l'inverse.
     *
     * @return list<array<string,mixed>>
     */
    public static function parSignal(int $limite = 50): array
    {
        return Db::all(
            'SELECT reason_code, motif, COUNT(*) AS retours,
                    COUNT(DISTINCT monitor_id) AS sondes,
                    COUNT(DISTINCT hote) AS serveurs
             FROM retours
             GROUP BY reason_code, motif
             ORDER BY serveurs DESC, retours DESC
             LIMIT ' . max(1, $limite)
        );
    }
}
