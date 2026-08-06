<?php

namespace Uptimeez\Regle;

/**
 * La mise en page est-elle cassée, ou seulement abîmée ?
 *
 * ------------------------------------------------------------------------------
 * UNE MISE EN PAGE CASSÉE N'EST PAS UN SITE HORS SERVICE
 * ------------------------------------------------------------------------------
 *
 * Ce cas rendait « down », c'est-à-dire le MÊME état qu'un serveur qui ne répond plus,
 * qu'un 500 ou qu'une base de données morte. Or la page répond, le serveur va bien, le
 * contenu est là : c'est l'apparence qui souffre. Confondre les deux coûte deux fois.
 *
 * Pour le lecteur des alertes, « hors service » perd son sens : il finit par ouvrir un
 * courriel rouge en s'attendant à un problème de style, et le jour où le serveur tombe
 * vraiment, il ouvre avec la même nonchalance. Pour les statistiques, une panne de style
 * entre dans le taux de disponibilité et le fausse : on annonce au client un site
 * indisponible alors qu'il servait ses pages.
 *
 * La règle est donc : « hors service » veut dire que le visiteur n'obtient pas la page.
 * Tout ce qui touche à l'apparence plafonne à « dégradé », quelle que soit sa gravité
 * interne, et cette gravité reste lisible dans le message et dans la cause, qui distingue
 * toujours CSS_BROKEN de CSS_DEGRADED. Le plafond est appliqué par le Verdict lui-même,
 * CSS_ faisant partie des causes d'apparence.
 *
 * ------------------------------------------------------------------------------
 * DEUX PROVENANCES, UN SEUL VERDICT, ET C'EST CE QUE L'EXTRACTION CORRIGE
 * ------------------------------------------------------------------------------
 *
 * L'analyse des feuilles de style coûte cher : elle télécharge des fichiers. Elle ne
 * tourne donc pas à chaque passe, et entre deux analyses le dernier verdict connu reste
 * valable, sans quoi une mise en page cassée « guérirait » toute seule à la vérification
 * suivante alors que rien n'a été corrigé.
 *
 * Ces deux provenances portaient chacune leur copie des verdicts, exactement comme le
 * certificat, et comme lui elles avaient déjà divergé : la branche reportée tronquait le
 * détail à deux messages là où la fraîche en montrait trois, et elle seule prévoyait un
 * texte de repli quand il n'y avait aucun message à citer. Deux lecteurs recevaient donc
 * deux alertes différentes pour le même défaut, selon l'heure.
 *
 * Le nombre de messages cités dépend maintenant de la GRAVITÉ, ce qui est la seule raison
 * défendable d'en montrer plus ou moins : une mise en page cassée a plusieurs causes
 * qu'il faut voir ensemble, un simple avertissement se résume.
 */
final class FeuillesDeStyle implements Regle
{
    /** Le nom sous lequel le collecteur dépose l'état des feuilles de style. */
    public const DETECTEUR = 'css';

    /** Messages cités selon la gravité : une casse se diagnostique, un avertissement se résume. */
    private const MESSAGES_CITES = ['broken' => 3, 'warn' => 2];

    /**
     * Écart de silhouette en dessous duquel on refuse de dire « cassée ».
     *
     * ------------------------------------------------------------------------------
     * DEUX MESURES ONT FIXÉ CE NOMBRE, PAS UNE INTUITION
     * ------------------------------------------------------------------------------
     *
     * Le 2026-08-06, deux sites du parc étaient annoncés « mise en page cassée » et Laurent,
     * en les ouvrant, n'a vu aucun problème. Mesures faites depuis le serveur de
     * supervision :
     *
     *   jetfunevasion.com  sa SEULE feuille répond 404, poids CSS tombé de 655 Ko à 36 Ko,
     *                      couverture 71 % contre 91 % en référence. Et l'écart de
     *                      silhouette : 9 %. Le site est entièrement stylé en ligne, la
     *                      feuille manquante était devenue redondante.
     *   La vraie casse du 2026-08-02, pour comparer : couverture 31 % contre 96 %, poids en
     *                      chute de 71 %, et un écart de silhouette de 71 %.
     *
     * Les indices (feuilles manquantes, poids en chute) disaient la même chose dans les deux
     * cas. Seul l'écart mesuré les séparait. On tranche donc sur lui.
     *
     * CE QUE CE PLAFOND NE PRÉTEND PAS. Un écart faible dit que la STRUCTURE de la page n'a
     * pas bougé, pas que les couleurs ou les polices sont intactes : la silhouette est faite
     * de blocs. C'est pourquoi le défaut reste SIGNALÉ, avec sa cause et ses messages, et
     * seulement requalifié en « dégradé ». Rien ne disparaît, et surtout pas le fichier en
     * échec, qui reste à réparer.
     */
    private const ECART_APPARENCE_INCHANGEE = 20;

    public function evaluer(Contexte $c): ?Verdict
    {
        if (! $c->actif('check_css') || ! $c->htmlExploitable()) {
            return null;
        }

        $css = $c->detecteur(self::DETECTEUR);

        if (! is_array($css)) {
            return null;
        }

        $etat = (string) ($css['state'] ?? 'ok');

        if (! isset(self::MESSAGES_CITES[$etat])) {
            return null;
        }

        $messages = is_array($css['messages'] ?? null) ? $css['messages'] : [];
        $detail = implode(' ', array_slice($messages, 0, self::MESSAGES_CITES[$etat]));

        // Un verdict sans détail envoie chercher un défaut sans dire lequel. Quand il n'y
        // a rien à citer, on le dit plutôt que de rendre une phrase qui s'arrête net.
        if (trim($detail) === '') {
            $detail = t('anomalie détectée à la dernière analyse');
        }

        // LA DATE N'EST PRÉSENTE QUE SUR UN VERDICT REPORTÉ, et elle change ce qu'on lit :
        // sans elle, un défaut corrigé il y a dix minutes semble constaté à l'instant.
        $analyseLe = (string) ($css['analyse_le'] ?? '');
        $horodatage = $analyseLe !== '' ? strtotime($analyseLe) : false;

        if ($horodatage !== false) {
            $detail .= ' ' . t('(analyse du {date})', ['date' => date('d/m H:i', $horodatage)]);
        }

        // LA MESURE PASSE DEVANT LES INDICES. Une feuille en échec est un indice ; l'écart
        // entre la page telle qu'elle est et la page telle qu'elle était est une mesure.
        // Quand la seconde dit que rien n'a bougé, on ne garde pas le mot de la première.
        $ecart = $css['silhouette_drift'] ?? null;

        if ($etat === 'broken' && is_int($ecart) && $ecart <= self::ECART_APPARENCE_INCHANGEE) {
            return Verdict::pour('degraded', 'CSS_DEGRADED',
                'Ressource de style en échec, mais la page n\'a pas changé d\'aspect ({ecart} % d\'écart mesuré) : {detail}',
                ['ecart' => (string)$ecart, 'detail' => $detail]);
        }

        return $etat === 'broken'
            ? Verdict::pour('degraded', 'CSS_BROKEN', 'Mise en page cassée : {detail}', ['detail' => $detail])
            : Verdict::pour('degraded', 'CSS_DEGRADED', 'CSS dégradé : {detail}', ['detail' => $detail]);
    }
}
