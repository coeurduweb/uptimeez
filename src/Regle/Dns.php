<?php

namespace Uptimeez\Regle;

/**
 * Is the DNS record there, and does it say what it should?
 *
 * ------------------------------------------------------------------------------
 * TWO VERDICTS, AND THEY ARE NOT EQUIVALENT
 * ------------------------------------------------------------------------------
 *
 * "No answer" is down: the record is gone, and everything that depended on it is broken.
 * "Unexpected answer" is down too, and for a reason worth stating: an A record pointing
 * somewhere else answers perfectly, so nothing else in this engine will ever see it. That is
 * precisely the case where DNS monitoring earns its existence, since the page itself will
 * display very well from the new address.
 *
 * ------------------------------------------------------------------------------
 * THE COMPARISON IS AN INCLUSION, NOT AN EQUALITY
 * ------------------------------------------------------------------------------
 *
 * An MX returns "10 mx.example.com", a CAA returns three fields. Demanding equality would
 * force the operator to copy a syntax they have no reason to know, and the first alert would
 * be a false positive over a space. So we look for the expected value INSIDE one of the
 * answers, which is what a human does when reading the output of dig.
 */
final class Dns implements Regle
{
    /** The name under which the collector files the probe's result. */
    public const DETECTEUR = 'dns';

    /** Past this, the list of values found is truncated in the message. */
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
