<?php
declare(strict_types=1);

namespace Uptimer\Check;

/**
 * Inspection du certificat TLS.
 * Deux passes : une sans vérification (pour lire le certificat même s'il est mauvais),
 * une avec vérification (pour savoir si un navigateur l'accepterait).
 */
final class Ssl
{
    /** @return array{checked:bool,valid:bool,error:?string,code:?string,days_left:?int,expires_at:?string,issuer:?string,subject:?string,self_signed:bool,host_match:bool,protocol:?string,alt_names:array} */
    public static function inspect(string $host, int $port = 443, int $timeout = 10): array
    {
        $out = [
            'checked' => false, 'valid' => false, 'error' => null, 'code' => null,
            'days_left' => null, 'expires_at' => null, 'issuer' => null, 'subject' => null,
            'self_signed' => false, 'host_match' => true, 'protocol' => null, 'alt_names' => [],
        ];
        if (!function_exists('openssl_x509_parse') || !function_exists('stream_socket_client')) return $out;

        $out['checked'] = true;

        // Passe 1 : lecture du certificat sans validation
        $ctx = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
            'SNI_enabled'       => true,
            'peer_name'         => $host,
        ]]);
        $errno = 0; $errstr = '';
        $sock = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, $timeout,
            STREAM_CLIENT_CONNECT, $ctx);

        if (!$sock) {
            $out['error'] = $errstr !== '' ? $errstr : 'Connexion TLS impossible';
            $out['code']  = str_contains(strtolower($errstr), 'timed out') ? 'TIMEOUT' : 'SSL_HANDSHAKE';
            return $out;
        }

        $params = stream_context_get_params($sock);
        $meta   = stream_get_meta_data($sock);
        if (isset($meta['crypto']['protocol'])) $out['protocol'] = (string)$meta['crypto']['protocol'];
        @fclose($sock);

        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        if ($cert) {
            $info = @openssl_x509_parse($cert) ?: [];
            $validTo = isset($info['validTo_time_t']) ? (int)$info['validTo_time_t'] : 0;
            if ($validTo > 0) {
                $out['expires_at'] = date('Y-m-d H:i:s', $validTo);
                $out['days_left']  = (int)floor(($validTo - time()) / 86400);
            }
            $out['issuer']  = self::name($info['issuer'] ?? []);
            $out['subject'] = self::name($info['subject'] ?? []);
            $sanRaw = $info['extensions']['subjectAltName'] ?? '';
            foreach (explode(',', (string)$sanRaw) as $piece) {
                $piece = trim($piece);
                if (stripos($piece, 'DNS:') === 0) $out['alt_names'][] = strtolower(substr($piece, 4));
            }
            $out['self_signed'] = $out['issuer'] !== null && $out['issuer'] === $out['subject'];
            $out['host_match']  = self::hostMatches($host, $out['alt_names'], (string)($info['subject']['CN'] ?? ''));
        }

        // Passe 2 : validation complète (chaîne + nom d'hôte), comme un navigateur
        $ctx2 = stream_context_create(['ssl' => [
            'verify_peer'       => true,
            'verify_peer_name'  => true,
            'allow_self_signed' => false,
            'SNI_enabled'       => true,
            'peer_name'         => $host,
        ]]);
        $errno2 = 0; $errstr2 = '';
        $sock2 = @stream_socket_client("ssl://{$host}:{$port}", $errno2, $errstr2, $timeout,
            STREAM_CLIENT_CONNECT, $ctx2);
        if ($sock2) {
            $out['valid'] = true;
            @fclose($sock2);
        } else {
            $out['valid'] = false;
            $out['error'] = self::humanError($errstr2, $out);
            $out['code']  = 'SSL_INVALID';
        }

        if ($out['days_left'] !== null && $out['days_left'] < 0) {
            $out['valid'] = false;
            $out['code']  = 'SSL_EXPIRED';
            $out['error'] = 'Certificat expiré depuis ' . abs($out['days_left']) . ' jour(s)';
        }

        return $out;
    }

    private static function name(array $dn): ?string
    {
        if (!$dn) return null;
        foreach (['CN', 'O', 'OU'] as $k) {
            if (!empty($dn[$k])) return is_array($dn[$k]) ? (string)reset($dn[$k]) : (string)$dn[$k];
        }
        return null;
    }

    private static function hostMatches(string $host, array $sans, string $cn): bool
    {
        $host = strtolower($host);
        $cands = $sans;
        if ($cn !== '') $cands[] = strtolower($cn);
        foreach ($cands as $c) {
            if ($c === $host) return true;
            if (str_starts_with($c, '*.')) {
                $suffix = substr($c, 1); // ".example.com"
                if (str_ends_with($host, $suffix)
                    && substr_count($host, '.') === substr_count($c, '.')) return true;
            }
        }
        return $cands === [];
    }

    private static function humanError(string $raw, array $ctx): string
    {
        $r = strtolower($raw);
        if ($ctx['self_signed'])                       return 'Certificat auto-signé';
        if (!$ctx['host_match'])                       return 'Le certificat ne couvre pas ce domaine';
        if (str_contains($r, 'expired'))               return 'Certificat expiré';
        if (str_contains($r, 'self signed'))           return 'Certificat auto-signé';
        if (str_contains($r, 'unable to get local issuer') || str_contains($r, 'unable to verify'))
                                                        return 'Chaîne de certification incomplète';
        if (str_contains($r, 'self signed certificate in certificate chain')
            || str_contains($r, 'unable to get issuer'))  return 'Autorité de certification inconnue du système';
        if (str_contains($r, 'certificate has expired'))   return 'Certificat expiré';
        if (str_contains($r, 'certificate verify failed')) return 'Vérification du certificat échouée';
        if ($raw === '') {
            // Certificat lisible mais refusé : dans la quasi-totalité des cas
            // l'autorité qui l'a signé n'est pas reconnue.
            return 'Certificat refusé : autorité de certification non reconnue ou chaîne incomplète';
        }
        return trim(preg_replace('~^stream_socket_client\(\):\s*~i', '', $raw) ?: $raw);
    }
}
