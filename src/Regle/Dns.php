<?php

namespace Uptimeez\Regle;

/**
 * L'enregistrement DNS est-il là, et dit-il ce qu'il devrait ?
 *
 * ------------------------------------------------------------------------------
 * DEUX VERDICTS, ET ILS NE SE VALENT PAS
 * ------------------------------------------------------------------------------
 *
 * « Aucune réponse » est hors service : l'enregistrement a disparu, et tout ce qui en
 * dépendait est cassé. « Réponse inattendue » est hors service aussi, et pour une raison
 * qu'il faut dire : un A qui pointe ailleurs répond parfaitement, donc rien d'autre dans ce
 * moteur ne le verra. C'est précisément le cas où la surveillance DNS gagne son existence,
 * puisque la page, elle, s'affichera très bien depuis la nouvelle adresse.
 *
 * ------------------------------------------------------------------------------
 * LA COMPARAISON EST UNE INCLUSION, PAS UNE ÉGALITÉ
 * ------------------------------------------------------------------------------
 *
 * Un MX rend « 10 mx.exemple.fr », un CAA rend trois champs. Exiger l'égalité obligerait
 * l'exploitant à recopier une syntaxe qu'il n'a aucune raison de connaître, et la première
 * alerte serait un faux positif sur un espace. On cherche donc la valeur attendue DANS l'une
 * des réponses, ce qui est ce qu'un humain fait en lisant le résultat de dig.
 */
final class Dns implements Regle
{
    /** Le nom sous lequel le collecteur dépose le résultat de la sonde. */
    public const DETECTEUR = 'dns';

    /** Au-delà, la liste des valeurs trouvées est coupée dans le message. */
    private const APERCU = 120;

    public function evaluer(Contexte $c): ?Verdict
    {
        $sonde = $c->detecteur(self::DETECTEUR);

        if (! is_array($sonde) || ($sonde['checked'] ?? false) !== true) {
            return null;
        }

        $type = strtoupper((string) ($sonde['type'] ?? ''));
        $nom = (string) ($sonde['name'] ?? '');

        if (($sonde['found'] ?? false) !== true) {
            return Verdict::pour('down', 'DNS_MISSING',
                'Aucun enregistrement {type} pour {name}',
                ['type' => $type, 'name' => $nom]);
        }

        $attendue = trim((string) $c->reglage('dns_expect', ''));

        if ($attendue === '') {
            return null;
        }

        $valeurs = array_map('strval', (array) ($sonde['values'] ?? []));

        foreach ($valeurs as $valeur) {
            if (stripos($valeur, $attendue) !== false) {
                return null;
            }
        }

        return Verdict::pour('down', 'DNS_VALUE',
            'L\'enregistrement {type} de {name} ne contient plus « {expected} » : {found}',
            [
                'type' => $type,
                'name' => $nom,
                'expected' => str_cut($attendue, 60),
                'found' => str_cut(implode(', ', $valeurs), self::APERCU),
            ]);
    }
}
