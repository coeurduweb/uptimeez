<?php

namespace Uptimeez\Regle;

/**
 * Ce qu'une règle conclut : un état, une cause, une phrase et ses variables.
 *
 * ------------------------------------------------------------------------------
 * POURQUOI CETTE CLASSE EXISTE PLUTÔT QU'UN TABLEAU
 * ------------------------------------------------------------------------------
 *
 * Runner::evaluate() fabriquait ses conclusions par une fermeture, $note(), qui
 * empilait des tableaux ['state' => …, 'reason' => …]. Ça marche, et ça a deux
 * défauts qui coûtent cher au moment où l'on veut rendre les règles modulables.
 *
 * Un tableau ne peut RIEN garantir. N'importe quelle ligne des 340 que comptait
 * evaluate() pouvait écrire 'down' à côté d'une cause d'apparence, et c'est
 * exactement ce qui est arrivé : treize sites annoncés hors service le 2026-08-02
 * pour un défaut de feuille de style, au même rang qu'un serveur muet. La
 * correction a consisté à changer deux appels à la main, et rien n'empêchait le
 * troisième d'apparaître le lendemain.
 *
 * Et un tableau ne se teste pas. Le contrôle qui garde aujourd'hui le plafond de
 * gravité LIT LE CODE SOURCE à la recherche de « note('down', 'CSS_ ». C'est
 * ingénieux, et c'est l'aveu qu'il n'y avait pas d'autre prise.
 *
 * ------------------------------------------------------------------------------
 * LE PLAFOND DE GRAVITÉ EST APPLIQUÉ ICI, ET NULLE PART AILLEURS
 * ------------------------------------------------------------------------------
 *
 * Une règle déclare la gravité qu'elle CONSTATE. Le Verdict décide de celle qui
 * SORT. La distinction est tout l'intérêt : une règle d'apparence peut légitimement
 * juger qu'une mise en page est ruinée, sans que le produit ait le droit d'en
 * conclure que le site est hors service.
 *
 * La règle du produit, écrite une fois : « hors service » veut dire que le visiteur
 * n'obtient pas la page. Pas de réponse, code d'erreur, certificat mort, base
 * absente, chaîne de preuve manquante. Tout ce qui touche à l'APPARENCE plafonne à
 * « dégradé », quelle que soit sa gravité interne, qui reste lisible dans la cause.
 *
 * Deux coûts justifiaient ce plafond, et le second est le moins visible. Le mot
 * « hors service » perd son sens à force de désigner un problème de style, et le
 * jour où le serveur tombe vraiment on ouvre l'alerte avec la même nonchalance. Et
 * une panne d'apparence entrait dans le taux de disponibilité, si bien qu'on
 * annonçait au client un site indisponible alors qu'il servait ses pages.
 */
final class Verdict
{
    /** Le seul ordre de gravité du produit. Toute comparaison passe par lui. */
    public const GRAVITE = ['up' => 0, 'degraded' => 1, 'down' => 2];

    /**
     * Les préfixes de cause qui décrivent l'APPARENCE et jamais la disponibilité.
     *
     * La liste est courte et le restera : y ajouter une entrée revient à déclarer
     * qu'une nouvelle famille de défauts ne prive jamais le visiteur de sa page, ce
     * qui est une décision de produit et non un détail d'implémentation. Le selftest
     * la relit pour vérifier qu'aucune de ces causes ne sort en « down ».
     */
    public const CAUSES_D_APPARENCE = ['CSS_', 'NOINDEX', 'SLOW'];

    private function __construct(
        public readonly string $etat,
        public readonly ?string $cause,
        public readonly string $message,
        public readonly array $variables,
        /** La gravité que la règle a constatée, avant plafonnement. Sert au diagnostic. */
        public readonly string $etatConstate,
    ) {
    }

    /**
     * Le verdict d'une règle, plafonné s'il porte sur l'apparence.
     *
     * @param  'up'|'degraded'|'down'  $etat     ce que la règle constate
     * @param  string|null             $cause    code de cause, ou null
     * @param  string                  $message  phrase SOURCE, traduite à l'affichage
     *                                           et non ici : le collecteur ne connaît pas
     *                                           la langue de celui qui lira
     */
    public static function pour(string $etat, ?string $cause, string $message, array $variables = []): self
    {
        if (!isset(self::GRAVITE[$etat])) {
            throw new \InvalidArgumentException("État inconnu : « $etat »");
        }

        $sortie = $etat === 'down' && self::estUneApparence($cause) ? 'degraded' : $etat;

        return new self($sortie, $cause, $message, $variables, $etat);
    }

    /** Une cause qui décrit ce que le visiteur VOIT, pas ce qu'il obtient. */
    public static function estUneApparence(?string $cause): bool
    {
        if ($cause === null) return false;

        foreach (self::CAUSES_D_APPARENCE as $prefixe) {
            if (str_starts_with($cause, $prefixe)) return true;
        }

        return false;
    }

    /** Le verdict a-t-il été ramené sous la gravité constatée par la règle ? */
    public function aEtePlafonne(): bool
    {
        return $this->etat !== $this->etatConstate;
    }

    /** Plus grave que l'autre, au sens du seul ordre du produit. */
    public function plusGraveQue(?self $autre): bool
    {
        return $autre === null || self::GRAVITE[$this->etat] > self::GRAVITE[$autre->etat];
    }

    /**
     * La forme attendue par le collecteur, tant qu'il travaille en tableaux.
     *
     * Cette méthode est un PONT, et elle disparaîtra quand la dernière règle sera
     * extraite. Elle existe pour que l'extraction se fasse une règle à la fois sans
     * jamais laisser le moteur à moitié converti : chaque règle sortie rend un
     * Verdict, que le collecteur remet immédiatement au format qu'il connaît.
     *
     * @return array{state:string, reason:?string, message:string, vars:array}
     */
    public function enTableau(): array
    {
        return [
            'state'   => $this->etat,
            'reason'  => $this->cause,
            'message' => $this->message,
            'vars'    => $this->variables,
        ];
    }
}
