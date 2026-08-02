<?php

namespace Uptimeez\Regle;

use Uptimeez\Response;

/**
 * Tout ce qu'une règle a le droit de savoir, et rien de plus.
 *
 * ------------------------------------------------------------------------------
 * LA CONTRAINTE QUI DÉFINIT CETTE CLASSE : AUCUNE RÈGLE NE TOUCHE À LA BASE
 * ------------------------------------------------------------------------------
 *
 * Une règle qui interroge Db ne se teste qu'avec une base, donc lentement, donc
 * rarement, donc mal. Les 340 lignes d'evaluate() sont dans ce cas : le contrôle
 * qui garde le plafond de gravité en est réduit à lire le CODE SOURCE, faute de
 * pouvoir appeler la règle.
 *
 * Tout ce dont une règle a besoin est donc rassemblé ici À L'AVANCE par le
 * collecteur, qui, lui, a le droit d'aller le chercher. Une règle devient alors une
 * fonction : un contexte entre, un verdict sort, et le test tient en trois lignes
 * sans base, sans réseau et sans horloge.
 *
 * ------------------------------------------------------------------------------
 * POURQUOI « detecteurs » EST UN TABLEAU LIBRE ET NON DES PROPRIÉTÉS NOMMÉES
 * ------------------------------------------------------------------------------
 *
 * Les détecteurs sont la partie du moteur qui bouge le plus : cinq corrections dans
 * Check/Css.php le 2026-08-02, et un détecteur de certificat qui a changé de forme
 * deux fois. Leur donner une propriété chacun obligerait à modifier CETTE classe à
 * chaque évolution, donc à retoucher un fichier partagé par les vingt-quatre
 * règles, donc à rouvrir la porte des régressions que l'extraction referme.
 *
 * Un tableau clé-valeur laisse chaque règle demander ce qu'elle connaît et ignorer
 * le reste. La contrepartie assumée : une clé absente rend null, et c'est à la
 * règle de le prévoir, ce qui est de toute façon vrai puisqu'un détecteur peut
 * légitimement n'avoir pas tourné.
 */
final class Contexte
{
    /**
     * @param array<string,mixed> $sonde        la ligne « monitors », telle quelle
     * @param Response            $reponse      ce que la cible a répondu
     * @param array<string,mixed> $detecteurs   résultats des Check/, par nom
     * @param bool                $manuel       déclenché par un humain, pas par le planificateur
     * @param string|null         $etatPrecedent l'état de la sonde avant cette passe
     */
    public function __construct(
        public readonly array $sonde,
        public readonly Response $reponse,
        public readonly array $detecteurs = [],
        public readonly bool $manuel = false,
        public readonly ?string $etatPrecedent = null,
    ) {
    }

    /** Un réglage de la sonde, avec son défaut. */
    public function reglage(string $cle, mixed $defaut = null): mixed
    {
        return $this->sonde[$cle] ?? $defaut;
    }

    /** Un réglage booléen : la base stocke des 0 et des 1, pas des booléens. */
    public function actif(string $cle): bool
    {
        return (int) ($this->sonde[$cle] ?? 0) === 1;
    }

    /** Le résultat d'un détecteur, ou null s'il n'a pas tourné. */
    public function detecteur(string $nom): mixed
    {
        return $this->detecteurs[$nom] ?? null;
    }

    public function url(): string
    {
        return (string) ($this->sonde['url'] ?? '');
    }

    public function estEnHttps(): bool
    {
        return str_starts_with(strtolower($this->url()), 'https://');
    }

    /**
     * Le corps a-t-il été reçu ENTIER ?
     *
     * Le drapeau existait et personne ne le lisait, ce qui transformait une page de
     * catalogue trop lourde en « chaîne de contrôle absente », donc en « la base de
     * données ne répond plus », donc en fausse panne. Toute règle qui cherche du
     * texte dans le corps doit interroger cette méthode d'abord : au-delà de la
     * limite, une absence ne prouve rien.
     */
    public function corpsComplet(): bool
    {
        return !$this->reponse->truncated;
    }
}
