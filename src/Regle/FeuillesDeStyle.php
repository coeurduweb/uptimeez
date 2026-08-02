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

        return $etat === 'broken'
            ? Verdict::pour('degraded', 'CSS_BROKEN', 'Mise en page cassée : {detail}', ['detail' => $detail])
            : Verdict::pour('degraded', 'CSS_DEGRADED', 'CSS dégradé : {detail}', ['detail' => $detail]);
    }
}
