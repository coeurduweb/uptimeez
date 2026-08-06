<?php

namespace Uptimeez\Regle;

/**
 * Does the port answer? The shortest rule in the engine, and the only one without nuance.
 *
 * ------------------------------------------------------------------------------
 * NO "DEGRADED" HERE, AND THAT IS THE POINT
 * ------------------------------------------------------------------------------
 *
 * A port is open or closed. There is no intermediate state to invent: a port that is slow to
 * accept the connection is an open port, and measuring that serves to display a duration,
 * not to manufacture a verdict. The other rules have nuances because a page can arrive and
 * be wrong; a port cannot be "half open".
 *
 * WHAT NOT TO CONCLUDE FROM IT, AND THE MONITOR PAGE SAYS SO TOO: an open port does not
 * prove that the service behind it works. An SMTP server that accepts the connection and
 * refuses every message answers "open". That is the limit of the check, not a defect to
 * fix: adding a per-protocol conversation would make this engine something other than what
 * it is.
 */
final class Port implements Regle
{
    /** The name under which the collector files the probe's result. */
    public const DETECTEUR = 'port';

    public function evaluer(Contexte $c): ?Verdict
    {
        $sonde = $c->detecteur(self::DETECTEUR);

        // No probe, or a probe that did not complete: saying nothing is the only honest
        // verdict. Saying "closed" about a probe we failed to run would blame the port for
        // a defect that is ours.
        if (! is_array($sonde) || ($sonde['checked'] ?? false) !== true) {
            return null;
        }

        if (($sonde['open'] ?? false) === true) {
            return null;
        }

        return Verdict::pour('down', 'PORT_CLOSED',
            'Port {port} fermé sur {host} : {reason}',
            [
                'port' => (string) ($sonde['port'] ?? '?'),
                'host' => (string) ($sonde['host'] ?? '?'),
                'reason' => ((string) ($sonde['error'] ?? '')) ?: t('rien n\'écoute'),
            ]);
    }
}
