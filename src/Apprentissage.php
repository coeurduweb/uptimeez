<?php

namespace Uptimeez;

use Uptimeez\Regle\Verdict;

/**
 * Ce que le corpus des retours PROPOSE. Jamais ce qu'il décide.
 *
 * ------------------------------------------------------------------------------
 * LA SEULE PROMESSE QUI COMPTE : AUCUN CHEMIN DE CODE NE CHANGE UNE RÈGLE
 * ------------------------------------------------------------------------------
 *
 * Cette classe LIT. Elle n'écrit rien, elle n'appelle aucune règle, et rien dans le moteur
 * ne l'interroge pendant une passe. Elle rend des phrases à lire, et un humain décide.
 *
 * Ce n'est pas de la prudence de façade. Un corpus de retours est le rêve de qui veut
 * empoisonner un détecteur : il suffit de déclarer faux ce qui est vrai pour rendre un
 * outil de surveillance aveugle, chez soi et chez les autres. Le jour où le corpus pourra
 * modifier une règle tout seul, ce produit aura cessé d'être fiable, et ce fichier est
 * l'endroit où cette limite est écrite.
 *
 * ------------------------------------------------------------------------------
 * QUATRE GARDE-FOUS, ET AUCUN N'EST DÉCORATIF
 * ------------------------------------------------------------------------------
 *
 * 1. L'INDÉPENDANCE AVANT LE NOMBRE. Cinquante retours d'un exploitant sur un hébergeur
 *    pèsent moins que trois retours venus de trois piles différentes. Le compteur est le
 *    nombre de CONTEXTES distincts, pas le nombre de clics. Un parc entier derrière un seul
 *    hébergeur partage ses défauts : compter ses sondes une par une reviendrait à croire
 *    cinquante fois le même témoin.
 *
 * 2. UN PLANCHER DE TROIS CONTEXTES. Même règle de trois qu'ailleurs dans le produit. En
 *    dessous, on n'a pas une tendance, on a une anecdote, et une anecdote ne mérite pas
 *    qu'on touche à une règle qui protège tout le monde.
 *
 * 3. UN RETOUR NE PEUT QU'AFFAIBLIR, JAMAIS CRÉER. On peut proposer de relâcher un signal
 *    qui se trompe ; on ne peut pas proposer d'en durcir un parce que quelqu'un a coché une
 *    case. Marquer cassé ce qui est sain fabriquerait de fausses pannes chez tous les
 *    autres, et c'est la seule direction dans laquelle une erreur de corpus se propage.
 *
 * 4. LE POIDS D'UN CONTEXTE EST BORNÉ. Qui déclare tout faux perd en crédit : au-delà de
 *    PLAFOND_PAR_CONTEXTE retours pour une même cause, un même hôte cesse d'ajouter du
 *    poids. Le corpus ne connaît pas encore l'auteur d'un retour (la table n'a pas de
 *    colonne de compte), donc la borne s'applique sur l'axe disponible, l'hôte, qui est
 *    aussi celui par lequel un parc entier pourrait parler d'une seule voix. Le jour où les
 *    retours porteront un compte, la borne se posera là aussi, et pour la même raison.
 *
 * ------------------------------------------------------------------------------
 * CE QU'ELLE NE PROPOSERA JAMAIS
 * ------------------------------------------------------------------------------
 *
 * Rien sur une cause de disponibilité. Un « DB_DOWN » contesté trois fois reste un
 * « DB_DOWN » : si le visiteur n'obtient pas la page, aucun corpus ne doit pouvoir suggérer
 * de se taire. La liste des causes relâchables est DÉRIVÉE de Verdict, jamais recopiée :
 * deux listes finissent toujours par diverger, et cette faute a déjà été réparée deux fois
 * dans ce moteur le même jour.
 */
final class Apprentissage
{
    /** Contextes indépendants exigés avant de proposer quoi que ce soit. */
    public const PLANCHER_CONTEXTES = 3;

    /**
     * Au-delà, un même hôte n'ajoute plus de poids sur une même cause.
     *
     * Trois, parce que c'est assez pour montrer une répétition et trop peu pour qu'un parc
     * bavard couvre la voix de deux autres. La valeur est délibérément basse : la borne
     * protège contre le cas où quelqu'un clique cinquante fois, et un plafond haut ne
     * protégerait de rien.
     */
    public const PLAFOND_PAR_CONTEXTE = 3;

    /**
     * Les propositions, de la plus étayée à la moins étayée.
     *
     * Chaque proposition dit : ce qu'on a vu, sur combien de contextes indépendants, ce
     * qu'on suggère, et ce qui la retient d'être une décision.
     *
     * @return list<array<string,mixed>>
     */
    public static function propositions(): array
    {
        $out = [];

        foreach (self::corpusParCause() as $ligne) {
            $cause = (string) $ligne['reason_code'];

            // Garde-fou 3 et « jamais sur une panne » : seules les causes qu'une exception
            // pourrait taire sont relâchables. La liste vient de Verdict.
            if (! Verdict::estUneApparence($cause)) {
                continue;
            }

            $contextes = (int) $ligne['contextes'];
            $contestes = (int) $ligne['contestes_bornes'];
            $confirmes = (int) $ligne['confirmes'];

            if ($contextes < self::PLANCHER_CONTEXTES || $contestes === 0) {
                continue;
            }

            // UNE CONTESTATION MAJORITAIRE N'EST PAS UNE CERTITUDE. Quand la cause est aussi
            // confirmée ailleurs, la proposition change de nature : ce n'est plus « la règle
            // se trompe » mais « la règle dépend d'un contexte qu'on n'a pas nommé ». Les
            // deux méritent d'être lues, et les confondre ferait relâcher une règle qui sert.
            $divergente = $confirmes > 0;

            $out[] = [
                'cause'       => $cause,
                'contextes'   => $contextes,
                'contestes'   => $contestes,
                'confirmes'   => $confirmes,
                'divergente'  => $divergente,
                'proposition' => $divergente
                    ? t('Chercher ce qui diffère entre les installations avant de toucher à la règle : elle est contestée sur {c} contexte(s) et confirmée {n} fois ailleurs.',
                        ['c' => (string) $contextes, 'n' => (string) $confirmes])
                    : t('Envisager de relâcher ce signal, ou de le sortir des alertes : contesté sur {c} contexte(s) indépendant(s), jamais confirmé.',
                        ['c' => (string) $contextes]),
                // La retenue est écrite DANS la proposition, pas dans la documentation à
                // côté : c'est ce qui empêche de la lire comme un ordre.
                'retenue'     => t('Proposition à lire, pas à appliquer : aucun réglage n\'a changé, et aucun ne changera sans votre geste.'),
            ];
        }

        // Le plus étayé d'abord, et « étayé » veut dire indépendant : le nombre de contextes
        // passe devant le volume de retours, comme partout dans ce fichier.
        usort($out, static fn (array $a, array $b): int => [$b['contextes'], $b['contestes']]
                                                      <=> [$a['contextes'], $a['contestes']]);

        return $out;
    }

    /**
     * Le corpus par cause, avec le poids de chaque hôte DÉJÀ borné.
     *
     * ------------------------------------------------------------------------------
     * POURQUOI LA BORNE EST DANS LA REQUÊTE ET PAS APRÈS
     * ------------------------------------------------------------------------------
     *
     * Borner après avoir compté demanderait de ramener tous les retours en mémoire pour les
     * regrouper à la main : sur un parc de trois cents sondes suivi depuis deux ans, c'est
     * un tableau que personne n'a besoin de charger. La sous-requête compte par hôte, la
     * requête extérieure additionne des comptes déjà plafonnés.
     *
     * UN HÔTE VIDE COMPTE COMME UN CONTEXTE À PART, et c'est voulu : un retour dont l'hôte
     * n'était pas connu au moment du clic ne doit pas se fondre dans le contexte du voisin.
     * Il forme son propre seau, plafonné comme les autres.
     *
     * @return list<array<string,mixed>>
     */
    private static function corpusParCause(): array
    {
        $contestations = implode(',', array_map(
            static fn (string $m): string => "'" . $m . "'", Retour::MOTIFS_CONTESTATION));
        $plafond = (int) self::PLAFOND_PAR_CONTEXTE;

        // LE FILTRE DES HÔTES CONTESTATAIRES EST À L'EXTÉRIEUR, ET LA PREMIÈRE VERSION S'EST
        // TROMPÉE ICI. Elle écartait, dans la sous-requête, tout hôte qui n'avait pas contesté :
        // les CONFIRMATIONS venues d'ailleurs disparaissaient donc du calcul, et une cause
        // contestée sur quatre installations et confirmée sur une cinquième sortait « jamais
        // confirmée ». C'est exactement l'information qui sépare « la règle se trompe » de « la
        // règle dépend d'un contexte qu'on n'a pas nommé », c'est-à-dire tout l'intérêt du
        // corpus. Vérifié sur un jeu d'essai où la confirmation était rendue invisible.
        return Db::all(
            "SELECT reason_code,
                    SUM(CASE WHEN contestes > 0 THEN 1 ELSE 0 END) AS contextes,
                    -- DEUX PIÈGES ÉVITÉS DANS CETTE SEULE LIGNE, ET LE SECOND NE SE VOYAIT
                    -- QU'À LA MESURE.
                    --
                    -- « MIN(a, b) » n'est pas portable : fonction scalaire en SQLite, fonction
                    -- d'agrégat en MySQL, où cette écriture est une erreur de syntaxe. D'où le
                    -- CASE.
                    --
                    -- Et le plafond est ÉCRIT DANS LA REQUÊTE, pas passé en paramètre. Lié par
                    -- PDO, il arrivait en TEXTE : SQLite compare alors un entier à du texte en
                    -- rangeant tous les entiers avant, si bien que « contestes > '3' » était
                    -- toujours faux et que la borne ne bornait rien. Seize clics posés sur
                    -- quatre hôtes rendaient seize au lieu de douze. C'est une constante de
                    -- classe, entière, donc l'interpoler n'ouvre aucune injection.
                    SUM(CASE WHEN contestes > $plafond THEN $plafond ELSE contestes END) AS contestes_bornes,
                    SUM(confirmes) AS confirmes
             FROM (
                SELECT reason_code,
                       COALESCE(hote, '') AS contexte,
                       SUM(CASE WHEN motif IN ($contestations) THEN 1 ELSE 0 END) AS contestes,
                       SUM(CASE WHEN motif = 'vrai_et_corrige' THEN 1 ELSE 0 END) AS confirmes
                  FROM retours
                 WHERE reason_code IS NOT NULL AND reason_code <> ''
                 GROUP BY reason_code, COALESCE(hote, '')
             ) AS par_hote
             GROUP BY reason_code
             HAVING contestes_bornes > 0"
        );
    }
}
