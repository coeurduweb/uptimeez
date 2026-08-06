<?php
declare(strict_types=1);

namespace Uptimeez\Check;

/**
 * Does a DNS record exist, and is it worth what it should be?
 *
 * ------------------------------------------------------------------------------
 * WHY THIS CHECK DESERVES TO EXIST NEXT TO WATCHING A PAGE
 * ------------------------------------------------------------------------------
 *
 * A page that answers proves the DNS zone works. The reverse is not true, and that is where
 * the value is: the records that serve NO page are watched by nobody. An MX deleted by
 * mistake does not break the site, it makes the mail disappear without a single visitor
 * noticing. A validation TXT removed breaks a signature. An NS changed at the registrar
 * moves the whole zone.
 *
 * ------------------------------------------------------------------------------
 * TWO QUESTIONS, AND THE SECOND ONE IS OPTIONAL
 * ------------------------------------------------------------------------------
 *
 * "Is there an answer" is checked without configuring anything. "Is it this value" needs an
 * expected value, and that is the one that catches the silent change: an A record pointing
 * somewhere else answers perfectly.
 *
 * THE COMPARISON IS AN INCLUSION AND NOT AN EQUALITY, because a record returns several
 * fields depending on its type: an MX carries a target and a priority, an SOA about ten
 * values. Demanding equality would force the operator to copy an exact syntax they have no
 * reason to know.
 */
final class Dns
{
    /** The types we know how to query, and nothing more: the rest is refused, not guessed. */
    public const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'NS', 'TXT', 'SOA', 'CAA'];

    /**
     * @return array{checked:bool,found:bool,values:array<int,string>,ms:int,error:?string}
     */
    public static function probe(string $nom, string $type): array
    {
        $nom = trim(rtrim($nom, '.'));
        $type = strtoupper(trim($type));

        if ($nom === '' || ! in_array($type, self::TYPES, true)) {
            return ['checked' => false, 'found' => false, 'values' => [], 'ms' => 0,
                    'error' => t('Nom ou type d\'enregistrement invalide')];
        }

        $constantes = [
            'A' => DNS_A, 'AAAA' => DNS_AAAA, 'CNAME' => DNS_CNAME, 'MX' => DNS_MX,
            'NS' => DNS_NS, 'TXT' => DNS_TXT, 'SOA' => DNS_SOA, 'CAA' => DNS_CAA,
        ];

        $debut = microtime(true);
        $reponses = @dns_get_record($nom, $constantes[$type]);
        $ms = (int) round((microtime(true) - $debut) * 1000);

        if ($reponses === false) {
            return ['checked' => true, 'found' => false, 'values' => [], 'ms' => $ms,
                    'error' => t('la résolution a échoué')];
        }

        // VALUES ARE FLATTENED TO TEXT, one per record, because that is the form an operator
        // recognises: "203.0.113.10", "10 mx.example.com". Returning PHP's raw array would
        // force every caller to know which field carries the value for which type, and that
        // knowledge belongs here.
        $valeurs = [];

        foreach ($reponses as $r) {
            $valeurs[] = match ($type) {
                'A' => (string) ($r['ip'] ?? ''),
                'AAAA' => (string) ($r['ipv6'] ?? ''),
                'CNAME', 'NS' => rtrim((string) ($r['target'] ?? ''), '.'),
                'MX' => trim(((string) ($r['pri'] ?? '')) . ' ' . rtrim((string) ($r['target'] ?? ''), '.')),
                'TXT' => (string) ($r['txt'] ?? ''),
                'SOA' => rtrim((string) ($r['mname'] ?? ''), '.'),
                'CAA' => trim(((string) ($r['flags'] ?? '')) . ' ' . ((string) ($r['tag'] ?? ''))
                              . ' ' . ((string) ($r['value'] ?? ''))),
                default => '',
            };
        }

        $valeurs = array_values(array_filter(array_map('trim', $valeurs), static fn (string $v): bool => $v !== ''));

        return ['checked' => true, 'found' => $valeurs !== [], 'values' => $valeurs, 'ms' => $ms,
                'error' => null];
    }
}
