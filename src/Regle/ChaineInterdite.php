<?php

namespace Uptimeez\Regle;

use Uptimeez\Runner;

/**
 * Un texte qui ne devrait jamais apparaître est-il apparu ?
 *
 * ------------------------------------------------------------------------------
 * LA SYMÉTRIE AVEC LA CHAÎNE DE PREUVE EST TROMPEUSE
 * ------------------------------------------------------------------------------
 *
 * Les deux règles cherchent un texte dans la page, et on les croit donc jumelles.
 * Elles ne le sont pas, et la différence tient au corps COUPÉ.
 *
 * Une chaîne de preuve ABSENTE d'une page tronquée ne prouve rien : elle est
 * peut-être juste au-delà de la limite de lecture, et conclure à une panne
 * fabriquerait de fausses alertes sur les pages lourdes. C'est pourquoi
 * ChaineDePreuve rend « je n'ai pas pu vérifier » dans ce cas.
 *
 * Une chaîne interdite PRÉSENTE, elle, reste une certitude quelle que soit la
 * troncature : ce qu'on a lu, on l'a lu. Cette règle n'a donc aucune raison de
 * s'intéresser à corpsComplet(), et son silence sur une page coupée n'est pas un
 * oubli mais la conséquence de la logique : on ne peut affirmer que ce qu'on a vu,
 * et l'absence dans un texte partiel n'est pas une absence.
 *
 * ------------------------------------------------------------------------------
 * À QUOI ÇA SERT, CONCRÈTEMENT
 * ------------------------------------------------------------------------------
 *
 * « Fatal error », « Under construction », le nom d'un ancien prestataire resté dans
 * un pied de page, une bannière de plateforme d'essai qu'un déploiement a ramenée.
 * Ce sont des pannes que le code de statut ne verra jamais, parce que la page
 * répond parfaitement et affiche la mauvaise chose.
 *
 * Deuxième règle extraite de Runner::evaluate(), le 2026-08-02.
 */
final class ChaineInterdite implements Regle
{
    /** Longueur d'aperçu de la chaîne dans le message : au-delà, elle noie la phrase. */
    private const APERCU = 60;

    public function evaluer(Contexte $c): ?Verdict
    {
        $interdite = trim((string) $c->reglage('forbid_string', ''));

        if ($interdite === '') {
            return null;
        }

        if (! Runner::containsAny($c->reponse->body, $interdite)) {
            return null;
        }

        return Verdict::pour(
            'down',
            'STRING_FORBIDDEN',
            'Chaîne interdite détectée : « {string} »',
            ['string' => str_cut($interdite, self::APERCU)]
        );
    }
}
