<?php
declare(strict_types=1);

namespace Uptimer\Check;

use Uptimer\Http;

/**
 * Date d'expiration du nom de domaine via RDAP (HTTPS).
 * Le port 43 (whois) est souvent fermé sur mutualisé : RDAP passe partout.
 */
final class DomainExpiry
{
    public static function lookup(string $host, int $timeout = 12): ?array
    {
        $domain = registrable_domain($host);
        if ($domain === '' || !str_contains($domain, '.')) return null;

        foreach (["https://rdap.org/domain/{$domain}", "https://rdap.iana.org/domain/{$domain}"] as $url) {
            $res = Http::fetch($url, [
                'timeout' => $timeout, 'maxBody' => 300000,
                'headers' => ['Accept' => 'application/rdap+json,application/json'],
            ]);
            if (!$res->ok || $res->status !== 200) continue;
            $data = json_decode($res->body, true);
            if (!is_array($data)) continue;

            $expire = null; $registrar = null; $status = $data['status'] ?? [];
            foreach (($data['events'] ?? []) as $ev) {
                if (($ev['eventAction'] ?? '') === 'expiration' && !empty($ev['eventDate'])) {
                    $expire = $ev['eventDate'];
                }
            }
            foreach (($data['entities'] ?? []) as $ent) {
                if (in_array('registrar', $ent['roles'] ?? [], true)) {
                    foreach (($ent['vcardArray'][1] ?? []) as $vc) {
                        if (($vc[0] ?? '') === 'fn') { $registrar = (string)($vc[3] ?? ''); break; }
                    }
                }
            }
            if (!$expire) return null;
            $ts = strtotime($expire);
            if (!$ts) return null;

            return [
                'domain'     => $domain,
                'expires_at' => date('Y-m-d H:i:s', $ts),
                'days_left'  => (int)floor(($ts - time()) / 86400),
                'registrar'  => $registrar,
                'status'     => is_array($status) ? implode(', ', $status) : (string)$status,
            ];
        }
        return null;
    }
}
