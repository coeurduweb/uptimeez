<?php
declare(strict_types=1);

namespace Uptimeez\Check;

/**
 * Does a TCP port answer?
 *
 * ------------------------------------------------------------------------------
 * WHAT THIS PROBE KNOWS, AND WHAT IT DOES NOT CLAIM TO KNOW
 * ------------------------------------------------------------------------------
 *
 * It opens a connection and closes it. That is all, and it is already the only interesting
 * question: is something listening there. It speaks no protocol, so it says nothing about
 * the state of the service behind the port: an SMTP server that accepts the connection and
 * refuses every message will answer "open". The documentation says so, because a check that
 * lets you believe more than it measures is exactly what this product holds against the
 * others.
 *
 * WHY THERE IS NO ICMP PING NEXT TO IT. A ping answers while the service is dead, so it
 * manufactures false calm. A closed port, on the other hand, is a fact you can act on:
 * either the process stopped listening, or a firewall got in between.
 *
 * THE CONNECTION IS CLOSED IMMEDIATELY, reading nothing. Waiting for a banner would make
 * the verdict depend on a protocol, and a service that speaks first is not the rule: HTTP
 * waits for the request, MySQL sends its banner, Redis says nothing.
 */
final class Port
{
    /** Past this, we consider nothing is listening: it is also a browser's default. */
    public const DELAI_PAR_DEFAUT = 10;

    /**
     * @return array{checked:bool,open:bool,ms:int,error:?string}
     */
    public static function probe(string $hote, int $port, int $delaiSec = self::DELAI_PAR_DEFAUT): array
    {
        $hote = trim($hote);

        if ($hote === '' || $port < 1 || $port > 65535) {
            return ['checked' => false, 'open' => false, 'ms' => 0,
                    'error' => t('Hôte ou port invalide')];
        }

        $debut = microtime(true);
        $errno = 0;
        $erreur = '';

        // fsockopen rather than stream_socket_client: the first is enough, exists
        // everywhere, and needs no context. The second would only add TLS, which we do not
        // want here: negotiating TLS would fail a port that listens in clear text.
        $flux = @fsockopen($hote, $port, $errno, $erreur, max(1, $delaiSec));
        $ms = (int) round((microtime(true) - $debut) * 1000);

        if ($flux === false) {
            return ['checked' => true, 'open' => false, 'ms' => $ms,
                    'error' => trim($erreur) !== '' ? trim($erreur) : t('connexion refusée')];
        }

        fclose($flux);

        return ['checked' => true, 'open' => true, 'ms' => $ms, 'error' => null];
    }
}
