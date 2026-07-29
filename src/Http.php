<?php
declare(strict_types=1);

namespace Uptimeez;

/** Client HTTP maison : curl seul, aucune dépendance. */
final class Http
{
    public const MAX_BODY = 3_000_000; // garde-fou mémoire sur mutualisé

    /**
     * Refuse-t-on cette cible ?
     *
     * Un outil de surveillance va chercher les URL qu'on lui donne : c'est sa
     * raison d'être, et surveiller un intranet est un usage légitime. Le
     * garde-fou est donc facultatif (`security.block_private_ranges`, désactivé
     * par défaut) : activé, il refuse la boucle locale, les plages privées et
     * les adresses de métadonnées d'hébergeur, la cible classique d'une SSRF.
     *
     * @return string|null motif du refus, ou null si la cible est autorisée
     */
    public static function blockedReason(string $url): ?string
    {
        if (!Config::get('security.block_private_ranges', false)) return null;

        $host = (string)(parse_url($url, PHP_URL_HOST) ?: '');
        if ($host === '') return 'adresse illisible';

        // Une adresse littérale se juge directement ; un nom se résout d'abord.
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : (array)@gethostbynamel($host);
        if (!$ips) return null;   // non résolu : le collecteur le signalera lui-même

        foreach ($ips as $ip) {
            // 169.254.169.254 sert les métadonnées chez la plupart des hébergeurs :
            // c'est la cible que cherche une SSRF, on la nomme explicitement.
            if ($ip === '169.254.169.254') return t('adresse de métadonnées de l\'hébergeur');
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return t('adresse locale ou privée : {ip}', ['ip' => $ip]);
            }
        }
        return null;
    }

    /**
     * Options : method, body, headers[], timeout, follow, ua, insecure, auth,
     *           maxBody, certinfo, gzip (bool), range.
     */
    public static function fetch(string $url, array $opt = []): Response
    {
        if (($why = self::blockedReason($url)) !== null) return self::refused($url, $why);
        $job = self::prepare($url, $opt);
        curl_exec($job->ch);
        self::finish($job);
        curl_close($job->ch);
        return $job->res;
    }

    /**
     * Requêtes parallèles : [clé => [url, opt]] → [clé => Response].
     * Permet d'auditer 20 feuilles de style en une poignée de secondes.
     */
    public static function fetchMany(array $requests, int $concurrency = 8): array
    {
        if (!$requests) return [];
        $refused = [];
        foreach ($requests as $k => $r) {
            $u = is_array($r) ? (string)($r[0] ?? $r['url'] ?? '') : (string)$r;
            if (($why = self::blockedReason($u)) !== null) {
                $refused[$k] = self::refused($u, $why);
                unset($requests[$k]);
            }
        }
        if (!$requests) return $refused;
        $concurrency = (int)max(1, min($concurrency, 20));
        $mh    = curl_multi_init();
        $jobs  = [];
        $out   = [];
        $queue = $requests;

        $push = static function () use (&$queue, &$jobs, $mh): bool {
            if (!$queue) return false;
            $key = array_key_first($queue);
            $req = $queue[$key];
            unset($queue[$key]);
            $job = self::prepare(is_array($req) ? (string)$req[0] : (string)$req, is_array($req) ? ($req[1] ?? []) : []);
            $job->key = $key;
            $jobs[(int)$job->ch] = $job;
            curl_multi_add_handle($mh, $job->ch);
            return true;
        };

        for ($i = 0; $i < $concurrency; $i++) { if (!$push()) break; }

        do {
            $status = curl_multi_exec($mh, $running);
            if ($running > 0) curl_multi_select($mh, 0.5);
            while ($info = curl_multi_info_read($mh)) {
                $id = (int)$info['handle'];
                if (isset($jobs[$id])) {
                    $job = $jobs[$id];
                    self::finish($job);
                    $out[$job->key] = $job->res;
                    curl_multi_remove_handle($mh, $job->ch);
                    curl_close($job->ch);
                    unset($jobs[$id]);
                }
                $push();
            }
        } while (($running > 0 || $queue || $jobs) && $status === CURLM_OK);

        curl_multi_close($mh);

        $ordered = [];
        foreach (array_keys($requests) as $k) if (isset($out[$k])) $ordered[$k] = $out[$k];
        return $ordered + $refused;
    }

    private static function prepare(string $url, array $opt): HttpJob
    {
        $job = new HttpJob();
        $job->res->url = $url;
        $job->max = (int)($opt['maxBody'] ?? self::MAX_BODY);

        $headers = [];
        foreach (($opt['headers'] ?? []) as $k => $v) {
            $headers[] = is_int($k) ? (string)$v : $k . ': ' . $v;
        }
        if (!self::hasHeader($headers, 'Accept')) {
            $headers[] = 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,application/json;q=0.9,*/*;q=0.8';
        }
        if (!self::hasHeader($headers, 'Accept-Language')) $headers[] = 'Accept-Language: fr-FR,fr;q=0.9,en;q=0.8';
        $headers[] = 'Cache-Control: no-cache';
        $headers[] = 'Pragma: no-cache';

        $timeout = (int)($opt['timeout'] ?? 15);
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_NOSIGNAL       => true,
            CURLOPT_CONNECTTIMEOUT => (int)min(max(3, $timeout), 20),
            CURLOPT_TIMEOUT        => max(3, $timeout),
            CURLOPT_FOLLOWLOCATION => (bool)($opt['follow'] ?? true),
            CURLOPT_MAXREDIRS      => 8,
            // Sans cette restriction, une redirection vers file://, gopher:// ou
            // dict:// serait suivie : un site surveillé pourrait faire lire un
            // fichier local au collecteur. On n'autorise que HTTP et HTTPS, à
            // l'aller comme sur les redirections.
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT      => (string)($opt['ua'] ?? (Config::get('defaults.user_agent') ?: 'UptimeezBot/1.0')),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => !($opt['insecure'] ?? false),
            CURLOPT_SSL_VERIFYHOST => ($opt['insecure'] ?? false) ? 0 : 2,
            CURLOPT_CERTINFO       => (bool)($opt['certinfo'] ?? false),
            // curl décode la réponse : strlen($body) = poids réel non compressé.
            CURLOPT_ENCODING       => ($opt['gzip'] ?? true) ? '' : 'identity',
            CURLOPT_HEADERFUNCTION => function ($ch2, string $line) use ($job) {
                $len = strlen($line);
                $t   = trim($line);
                if ($t === '') return $len;
                if (stripos($t, 'HTTP/') === 0) { $job->res->headers = []; return $len; } // reset à chaque hop
                $pos = strpos($t, ':');
                if ($pos !== false) {
                    $job->res->headers[strtolower(substr($t, 0, $pos))] = trim(substr($t, $pos + 1));
                }
                return $len;
            },
            CURLOPT_WRITEFUNCTION  => function ($ch2, string $chunk) use ($job) {
                $len  = strlen($chunk);
                $room = $job->max - strlen($job->body);
                if ($room <= 0) {
                    $job->truncated = true;
                } elseif ($len <= $room) {
                    $job->body .= $chunk;
                } else {
                    // On coupe À la borne, pas au bloc suivant. L'ancienne version
                    // ajoutait le bloc entier dès que le corps était encore sous la
                    // limite : la longueur finale dépendait donc du découpage réseau,
                    // qui change d'une requête à l'autre. Conséquence sur une page
                    // tronquée : l'empreinte de contenu changeait à chaque passe, et
                    // « le contenu de la page a changé » se déclenchait pour rien,
                    // indéfiniment.
                    $job->body .= substr($chunk, 0, $room);
                    $job->truncated = true;
                }
                return $len;
            },
        ]);

        $method = strtoupper((string)($opt['method'] ?? 'GET'));
        if ($method === 'HEAD') {
            curl_setopt($ch, CURLOPT_NOBODY, true);
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if (isset($opt['body']) && $opt['body'] !== '') curl_setopt($ch, CURLOPT_POSTFIELDS, (string)$opt['body']);
        }
        if (!empty($opt['auth'])) {
            curl_setopt($ch, CURLOPT_USERPWD, (string)$opt['auth']);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
        }
        if (!empty($opt['range'])) curl_setopt($ch, CURLOPT_RANGE, (string)$opt['range']);

        $job->ch = $ch;
        return $job;
    }

    private static function finish(HttpJob $job): void
    {
        $ch    = $job->ch;
        $res   = $job->res;
        $errno = curl_errno($ch);

        $res->error       = $errno ? curl_error($ch) : null;
        $res->status      = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $res->body        = $job->body;
        $res->truncated   = $job->truncated;
        $res->finalUrl    = (string)(curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $res->url);
        $res->redirects   = (int)curl_getinfo($ch, CURLINFO_REDIRECT_COUNT);
        $res->contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: null;
        $res->dnsMs       = self::ms($ch, CURLINFO_NAMELOOKUP_TIME);
        $res->connectMs   = self::ms($ch, CURLINFO_CONNECT_TIME);
        $res->tlsMs       = self::ms($ch, CURLINFO_APPCONNECT_TIME);
        $res->ttfbMs      = self::ms($ch, CURLINFO_STARTTRANSFER_TIME);
        $res->totalMs     = self::ms($ch, CURLINFO_TOTAL_TIME);
        $res->size        = $res->truncated ? (int)curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD) : strlen($job->body);
        $res->ip          = (string)(curl_getinfo($ch, CURLINFO_PRIMARY_IP) ?: '');
        $ci               = curl_getinfo($ch, CURLINFO_CERTINFO);
        $res->certInfo    = is_array($ci) ? $ci : [];
        $res->ok          = $errno === 0 && $res->status > 0;
        $res->errorCode   = $errno ? self::mapError($errno, (string)$res->error) : null;
    }

    private static function ms($ch, int $info): int
    {
        return (int)round(((float)curl_getinfo($ch, $info)) * 1000);
    }

    private static function hasHeader(array $headers, string $name): bool
    {
        foreach ($headers as $h) if (stripos($h, $name . ':') === 0) return true;
        return false;
    }

    private static function mapError(int $errno, string $msg): string
    {
        $m = strtolower($msg);
        return match (true) {
            $errno === 28                        => 'TIMEOUT',
            $errno === 6 || $errno === 5         => 'DNS',
            $errno === 7                         => 'CONNECT',
            $errno === 35 || $errno === 53       => 'SSL_HANDSHAKE',
            in_array($errno, [51, 58, 60, 83, 91], true) => 'SSL_INVALID',
            $errno === 47                        => 'REDIRECT_LOOP',
            in_array($errno, [52, 55, 56, 18], true) => 'CONNECT_RESET',
            str_contains($m, 'certificate')      => 'SSL_INVALID',
            str_contains($m, 'timed out')        => 'TIMEOUT',
            default                              => 'NET_ERROR',
        };
    }

    /** Libellé français d'un code d'erreur réseau. */
    /** Réponse synthétique pour une cible refusée : même forme qu'une erreur réseau. */
    private static function refused(string $url, string $why): Response
    {
        $res = new Response();
        $res->url        = $url;
        $res->status    = 0;
        $res->ok        = false;
        $res->errorCode = 'BLOCKED';
        $res->error     = t('Cible refusée : {reason}', ['reason' => $why]);
        return $res;
    }

    public static function errorLabel(?string $code): string
    {
        return match ($code) {
            'TIMEOUT'        => t('Délai dépassé (timeout)'),
            'DNS'            => t('Nom de domaine non résolu'),
            'CONNECT'        => t('Connexion impossible'),
            'CONNECT_RESET'  => t('Connexion interrompue par le serveur'),
            'SSL_HANDSHAKE'  => t('Échec de la négociation TLS'),
            'SSL_INVALID'    => t('Certificat SSL invalide'),
            'REDIRECT_LOOP'  => t('Boucle de redirection'),
            'NET_ERROR'      => t('Erreur réseau'),
            'BLOCKED'        => t('Cible refusée par la politique de sécurité'),
            default          => t('Erreur'),
        };
    }
}
