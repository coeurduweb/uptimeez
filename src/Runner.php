<?php
declare(strict_types=1);

namespace Uptimer;

use Uptimer\Check\Css;
use Uptimer\Check\Database;
use Uptimer\Check\DomainExpiry;
use Uptimer\Check\Ssl;
use Uptimer\Detect\Discovery;
use Uptimer\Notify\Notifier;

/**
 * Orchestration d'une passe de surveillance.
 *
 * Principe : une passe = récupération parallèle des pages dues, puis évaluation
 * de chaque réponse par les sondes, puis moteur d'incidents et notifications.
 * Les relances (anti faux positif) se font sur un second tour, pas en série.
 */
final class Runner
{
    public const SEVERITY = ['up' => 0, 'degraded' => 1, 'down' => 2];

    /**
     * Une analyse CSS télécharge toutes les feuilles de la page : c'est la sonde
     * la plus coûteuse. On en limite le nombre par passe pour qu'une minute de
     * cron reste une minute de cron, même avec 300 sites. Les sondes non
     * analysées gardent leur dernier verdict et passent au tour suivant.
     */
    public const CSS_AUDITS_PER_PASS = 6;
    private static int $cssAudits = 0;

    /**
     * Pannes détectées pendant la passe en cours, en attente de corrélation.
     * @var array<int,array{monitor:array,incident:array}>
     */
    private static array $pendingAlerts = [];

    /** Au-delà de ce nombre de sites touchés sur un même serveur, on regroupe. */
    public const GROUP_THRESHOLD = 3;

    public static function resetPassCounters(): void
    {
        self::$cssAudits = 0;
        self::$pendingAlerts = [];
    }

    /**
     * Envoie les alertes de la passe en les regroupant par cause commune.
     *
     * Quand un serveur mutualisé tombe, ce ne sont pas quarante sites qui ont un
     * problème : c'est une machine. On envoie donc un message unique qui nomme le
     * serveur et liste les sites touchés, au lieu de quarante alertes illisibles.
     */
    public static function flushAlerts(): int
    {
        $pending = self::$pendingAlerts;
        self::$pendingAlerts = [];
        if (!$pending) return 0;

        // Regroupement par adresse IP contactée, puis par domaine enregistrable :
        // deux signatures fiables d'une infrastructure commune.
        $groups = [];
        foreach ($pending as $item) {
            $mon = $item['monitor'];
            $ip  = (string)($mon['last_ip'] ?? '');
            $key = $ip !== '' ? 'ip:' . $ip : 'dom:' . registrable_domain(host_of((string)$mon['url']));
            $groups[$key][] = $item;
        }

        $sent = 0;
        foreach ($groups as $key => $items) {
            // Un même site ne compte qu'une fois : trois pages d'un seul site en
            // panne, c'est un site en panne, pas trois.
            $sites = [];
            foreach ($items as $it) {
                $sid = $it['monitor']['site_id'] ?: ('m' . $it['monitor']['id']);
                $sites[$sid] = true;
            }
            if (count($items) >= self::GROUP_THRESHOLD && count($sites) >= self::GROUP_THRESHOLD) {
                $scope = str_starts_with($key, 'ip:') ? substr($key, 3) : substr($key, 4);
                Notifier::sendGrouped($items, $scope, str_starts_with($key, 'ip:'));
                $sent++;
                continue;
            }
            foreach ($items as $it) {
                Notifier::sendIncident($it['monitor'], $it['incident'], true);
                $sent++;
            }
        }
        return $sent;
    }

    /** Sondes dues à cet instant. */
    public static function due(int $limit = 60): array
    {
        return Db::all(
            "SELECT * FROM monitors
             WHERE enabled = 1 AND kind <> 'heartbeat'
               AND (next_check_at IS NULL OR next_check_at <= ?)
             ORDER BY (next_check_at IS NULL) DESC, next_check_at ASC
             LIMIT " . max(1, $limit),
            [now()]
        );
    }

    /**
     * Exécute une passe complète.
     * @return array{ran:int,down:int,degraded:int,up:int,seconds:float}
     */
    public static function runDue(int $limit = 60, float $budgetSec = 50.0): array
    {
        $t0    = microtime(true);
        self::resetPassCounters();
        $stats = ['ran' => 0, 'down' => 0, 'degraded' => 0, 'up' => 0, 'seconds' => 0.0];
        $mons  = self::due($limit);
        if (!$mons) { $stats['seconds'] = round(microtime(true) - $t0, 2); return $stats; }

        $parallel = (int)Config::get('defaults.max_parallel', 10);

        foreach (array_chunk($mons, max(1, $parallel)) as $batch) {
            if (microtime(true) - $t0 > $budgetSec) break;
            $results = self::runBatch($batch);
            foreach ($results as $r) {
                $stats['ran']++;
                $key = $r['state'] === 'paused' ? 'up' : $r['state'];
                if (isset($stats[$key])) $stats[$key]++;
            }
        }
        self::flushAlerts();
        $stats['seconds'] = round(microtime(true) - $t0, 2);
        Db::setSetting('last_run_at', now());
        Db::setSetting('last_run_stats', jenc($stats));
        return $stats;
    }

    /** Lance une sonde à la demande (bouton « vérifier maintenant »). */
    public static function runOne(int $monitorId): ?array
    {
        $mon = Db::one('SELECT * FROM monitors WHERE id = ?', [$monitorId]);
        if (!$mon) return null;
        $r = self::runBatch([$mon], true);
        self::flushAlerts();   // vérification unitaire : rien à corréler
        return $r[0] ?? null;
    }

    /**
     * Traite un lot de sondes : fetch parallèle, relances, évaluation, persistance.
     * @return array<int,array>
     */
    public static function runBatch(array $monitors, bool $manual = false): array
    {
        $out      = [];
        $requests = [];
        $byKey    = [];

        foreach ($monitors as $mon) {
            $id = (int)$mon['id'];
            // Une sonde battement ne s'interroge pas : c'est elle qui nous appelle.
            if (($mon['kind'] ?? '') === 'heartbeat') continue;
            if (!$manual && self::isPaused($mon)) {
                self::persistPaused($mon);
                $out[] = ['monitor_id' => $id, 'state' => 'paused', 'reason' => null, 'message' => 'En pause'];
                continue;
            }
            $requests['m' . $id] = [$mon['url'], self::requestOptions($mon)];
            $byKey['m' . $id]    = $mon;
        }
        if (!$requests) return $out;

        $responses = Http::fetchMany($requests, (int)Config::get('defaults.max_parallel', 10));

        // --- Relances ciblées sur les échecs réseau / 5xx --------------------
        $attempts = array_fill_keys(array_keys($requests), 1);
        for ($round = 0; $round < 3; $round++) {
            $retry = [];
            foreach ($byKey as $key => $mon) {
                $res = $responses[$key] ?? null;
                $max = (int)$mon['retries'] + 1;
                if ($attempts[$key] >= $max) continue;
                if ($res === null || self::worthRetrying($res)) {
                    $retry[$key] = [$mon['url'], self::requestOptions($mon)];
                }
            }
            if (!$retry) break;
            usleep((int)Config::get('defaults.retry_delay_ms', 1500) * 1000);
            foreach (Http::fetchMany($retry, (int)Config::get('defaults.max_parallel', 10)) as $key => $res) {
                $attempts[$key]++;
                // On garde la meilleure des tentatives : un succès annule l'échec.
                $prev = $responses[$key] ?? null;
                if ($prev === null || self::worthRetrying($prev)) $responses[$key] = $res;
            }
        }

        // --- Évaluation ----------------------------------------------------
        foreach ($byKey as $key => $mon) {
            $res     = $responses[$key] ?? new Response();
            $verdict = self::evaluate($mon, $res, $manual);
            self::persist($mon, $res, $verdict, $attempts[$key] ?? 1);
            $out[] = $verdict + ['monitor_id' => (int)$mon['id']];
        }
        return $out;
    }

    // =====================================================================
    // Évaluation
    // =====================================================================

    /**
     * Applique toutes les sondes à une réponse et retient le verdict le plus grave.
     * @return array{state:string,reason:?string,message:string,details:array,events:array}
     */
    public static function evaluate(array $mon, Response $res, bool $manual = false): array
    {
        $findings = [];   // [state, reason, message]
        $details  = [];
        $events   = [];
        $https    = str_starts_with(strtolower((string)$mon['url']), 'https://');

        $note = function (string $state, ?string $reason, string $message) use (&$findings) {
            $findings[] = ['state' => $state, 'reason' => $reason, 'message' => $message];
        };

        // ---- 1. Couche réseau ---------------------------------------------
        if (!$res->ok || $res->status === 0) {
            $code = $res->errorCode ?: 'NET_ERROR';

            // Échec lié au certificat : on interroge le serveur en TLS permissif
            // pour donner la vraie cause, et on n'affiche qu'elle.
            $sslDiag = null;
            if ($https && in_array($code, ['SSL_INVALID', 'SSL_HANDSHAKE'], true) && (int)$mon['check_ssl'] === 1) {
                $ssl = Ssl::inspect(host_of($mon['url']), self::portOf($mon['url']), (int)$mon['timeout_sec']);
                $details['ssl'] = $ssl;
                if ($ssl['checked'] && $ssl['error']) {
                    $sslDiag = ['code' => $ssl['code'] ?: 'SSL_INVALID', 'msg' => $ssl['error']];
                }
            }

            if ($sslDiag) {
                $note('down', $sslDiag['code'], $sslDiag['msg']
                    . (isset($details['ssl']['expires_at']) && $details['ssl']['expires_at']
                        ? ' (échéance ' . date('d/m/Y', strtotime((string)$details['ssl']['expires_at'])) . ')' : ''));
            } else {
                // Le message reste en français ; la trace curl brute part dans les
                // détails techniques, consultables sur la fiche.
                $note('down', $code, Http::errorLabel($code));
                if ($res->error) $details['net_error'] = str_cut((string)$res->error, 200);
            }
            return self::verdict($findings, $details, $events, $res);
        }

        // ---- 2. Code HTTP -------------------------------------------------
        $expect = (string)($mon['expect_status'] ?: '200-299');
        if (!self::statusMatches($res->status, $expect)) {
            $c = $res->status;
            $reason = match (true) {
                $c >= 500 => 'HTTP_5XX',
                $c === 429 => 'HTTP_429',
                $c === 404 => 'HTTP_404',
                $c === 403 => 'HTTP_403',
                $c === 401 => 'HTTP_401',
                $c >= 400 => 'HTTP_4XX',
                $c >= 300 => 'HTTP_3XX',
                default   => 'HTTP_UNEXPECTED',
            };
            $label = match ($reason) {
                'HTTP_5XX' => 'Erreur serveur ' . $c . ' (le site ne répond plus correctement)',
                'HTTP_404' => 'Page introuvable (404)',
                'HTTP_403' => 'Accès interdit (403)',
                'HTTP_401' => 'Authentification requise (401)',
                'HTTP_429' => 'Trop de requêtes (429) — quota serveur atteint',
                'HTTP_4XX' => 'Erreur client ' . $c,
                'HTTP_3XX' => 'Redirection inattendue (' . $c . ') vers ' . str_cut($res->finalUrl, 80),
                default    => 'Code HTTP inattendu : ' . $c . ' (attendu : ' . $expect . ')',
            };
            $note('down', $reason, $label);
        }

        // ---- 3. Base de données (signatures + chaîne de preuve) -----------
        if ((int)$mon['check_db'] === 1 && $res->body !== '') {
            $db = Database::audit($res, $mon);
            if ($db['state'] !== 'ok') {
                $details['db'] = $db;
                $note('down', $db['reason'], $db['message'] . ($db['evidence'] ? ' — « ' . $db['evidence'] . ' »' : ''));
            }
        }

        // ---- 4. Chaîne attendue / interdite -------------------------------
        $expectStr = trim((string)($mon['expect_string'] ?? ''));
        if ($expectStr !== '') {
            $found = self::containsAny($res->body, $expectStr);
            if (!$found) {
                $note('down', 'STRING_MISSING',
                    'La chaîne de contrôle « ' . str_cut($expectStr, 60) . ' » est absente de la page : '
                    . 'le contenu n\'est plus servi (serveur web ou base de données).');
            }
        }
        $forbid = trim((string)($mon['forbid_string'] ?? ''));
        if ($forbid !== '' && self::containsAny($res->body, $forbid)) {
            $note('down', 'STRING_FORBIDDEN', 'Chaîne interdite détectée : « ' . str_cut($forbid, 60) . ' »');
        }

        // ---- 5. API JSON ---------------------------------------------------
        if ($mon['kind'] === 'api') {
            $json = json_decode($res->body, true);
            if ($res->body !== '' && $json === null && json_last_error() !== JSON_ERROR_NONE) {
                $note('down', 'JSON_INVALID', 'Réponse non JSON valide (' . json_last_error_msg() . ')');
            } elseif (!empty($mon['json_path'])) {
                $val = self::jsonPath($json, (string)$mon['json_path']);
                $exp = (string)($mon['json_expect'] ?? '');
                if ($val === null) {
                    $note('down', 'JSON_PATH', 'Champ « ' . $mon['json_path'] . ' » absent de la réponse');
                } elseif ($exp !== '' && (string)$val !== $exp) {
                    $note('down', 'JSON_VALUE',
                        'Champ « ' . $mon['json_path'] . ' » = « ' . str_cut((string)$val, 40) . ' » (attendu « ' . $exp . ' »)');
                }
            }
        }

        // ---- 6. Certificat SSL --------------------------------------------
        if ($https && (int)$mon['check_ssl'] === 1) {
            $stale = !$mon['ssl_checked_at'] || strtotime((string)$mon['ssl_checked_at']) < time() - 21600;
            if ($stale || $manual) {
                $ssl = Ssl::inspect(host_of($mon['url']), self::portOf($mon['url']), (int)$mon['timeout_sec']);
                $details['ssl'] = $ssl;
                if ($ssl['checked']) {
                    if ($ssl['code'] === 'SSL_EXPIRED') {
                        $note('down', 'SSL_EXPIRED', 'Certificat SSL expiré' .
                            ($ssl['expires_at'] ? ' le ' . date('d/m/Y', strtotime($ssl['expires_at'])) : ''));
                    } elseif (!$ssl['valid']) {
                        $note('down', 'SSL_INVALID', 'Certificat SSL invalide : ' . ($ssl['error'] ?: 'refusé'));
                    } elseif ($ssl['days_left'] !== null && $ssl['days_left'] <= (int)$mon['ssl_warn_days']) {
                        $note('degraded', 'SSL_SOON',
                            'Certificat SSL expire dans ' . $ssl['days_left'] . ' jour(s)'
                            . ($ssl['issuer'] ? ' (' . $ssl['issuer'] . ')' : ''));
                    }
                }
            } else {
                $d = $mon['ssl_days_left'];
                if ($d !== null && (int)$d < 0) $note('down', 'SSL_EXPIRED', 'Certificat SSL expiré');
                elseif ($d !== null && (int)$d <= (int)$mon['ssl_warn_days']) {
                    $note('degraded', 'SSL_SOON', 'Certificat SSL expire dans ' . (int)$d . ' jour(s)');
                }
            }
        }

        // ---- 7. Feuilles de style -----------------------------------------
        $htmlOk = $res->status >= 200 && $res->status < 300 && $res->isHtml();
        if ((int)$mon['check_css'] === 1 && $htmlOk) {
            $cadence = max((int)$mon['interval_sec'], 900);
            $stale   = !$mon['css_checked_at'] || strtotime((string)$mon['css_checked_at']) < time() - $cadence;
            $budget  = $manual || self::$cssAudits < self::CSS_AUDITS_PER_PASS;
            if ($stale && $budget) self::$cssAudits++;
            if (($stale && $budget) || $manual) {
                $baseline = jdec($mon['css_baseline'] ?? null);
                $css = Css::audit($mon['url'], $res->body, $res, $baseline, [
                    'drop_pct' => (int)$mon['css_drop_pct'],
                    'timeout'  => (int)$mon['timeout_sec'],
                    'insecure' => (bool)$mon['ignore_ssl_errors'],
                    'ua'       => $mon['user_agent'] ?: null,
                ]);
                $details['css'] = $css;
                if ($css['state'] === 'broken') {
                    $note('down', 'CSS_BROKEN', 'Mise en page cassée : ' . implode(' ', array_slice($css['messages'], 0, 3)));
                } elseif ($css['state'] === 'warn') {
                    $note('degraded', 'CSS_DEGRADED', 'CSS dégradé : ' . implode(' ', array_slice($css['messages'], 0, 2)));
                }
                if ($css['changed']) {
                    $events[] = ['kind' => 'css_changed', 'message' => 'Les fichiers CSS ont changé (déploiement ?)'];
                }
            } elseif (in_array($mon['css_state'] ?? '', ['broken', 'warn'], true)) {
                // Entre deux analyses, le dernier verdict CSS reste valable :
                // sans cela une mise en page cassée « guérirait » toute seule
                // à la vérification suivante alors que rien n'a été corrigé.
                $prev = jdec($mon['css_detail'] ?? null);
                $why  = implode(' ', array_slice($prev['messages'] ?? [], 0, 2)) ?: 'anomalie détectée à la dernière analyse';
                $when = !empty($mon['css_checked_at']) ? ' (analyse du ' . date('d/m H:i', strtotime((string)$mon['css_checked_at'])) . ')' : '';
                if ($mon['css_state'] === 'broken') {
                    $note('down', 'CSS_BROKEN', 'Mise en page cassée : ' . $why . $when);
                } else {
                    $note('degraded', 'CSS_DEGRADED', 'CSS dégradé : ' . $why . $when);
                }
            }
        }

        // ---- 8. Indexabilité (utile en agence : un noindex oublié) --------
        if ((int)($mon['check_noindex'] ?? 0) === 1 && $htmlOk) {
            $ni = Discovery::noindex($res);
            if ($ni) $note('degraded', 'NOINDEX', 'Page en noindex : ' . $ni);
        }

        // ---- 9. Lenteur ----------------------------------------------------
        $slow = (int)($mon['slow_ms'] ?: 3000);
        if ($slow > 0 && $res->totalMs > $slow) {
            $note('degraded', 'SLOW', 'Temps de réponse élevé : ' . number_format($res->totalMs / 1000, 2, ',', ' ') . ' s');
        }

        // ---- 10. Mot surveillé (mise à jour de page) ----------------------
        $watch = trim((string)($mon['watch_string'] ?? ''));
        if ($watch !== '' && $res->body !== '') {
            $present = self::containsAny($res->body, $watch);
            $prev    = $mon['watch_state'] ?? null;
            $state   = $present ? 'present' : 'absent';
            if ($prev !== null && $prev !== $state) {
                $mode = $mon['watch_mode'] ?: 'appear';
                $wanted = ($mode === 'appear' && $present) || ($mode === 'disappear' && !$present);
                $events[] = [
                    'kind'    => $wanted ? 'watch_hit' : 'watch_change',
                    'message' => 'Le texte « ' . str_cut($watch, 50) . ' » '
                        . ($present ? 'est apparu' : 'a disparu') . ' sur ' . str_cut($mon['url'], 60),
                    'notify'  => $wanted,
                ];
            }
            $details['watch_state'] = $state;
        }

        // ---- 11. Modification de contenu ---------------------------------
        if ((int)($mon['check_content'] ?? 0) === 1 && $htmlOk) {
            $hash = self::contentHash($res->body);
            $details['content_hash'] = $hash;
            if (!empty($mon['content_hash']) && $mon['content_hash'] !== $hash) {
                $events[] = ['kind' => 'content_changed',
                             'message' => 'Le contenu de la page a changé', 'notify' => true];
            }
        }

        return self::verdict($findings, $details, $events, $res);
    }

    /**
     * Priorité d'affichage à gravité égale : on montre la cause la plus
     * actionnable, pas la première rencontrée.
     */
    private const REASON_PRIORITY = [
        'DNS' => 100, 'CONNECT' => 99, 'CONNECT_RESET' => 98, 'TIMEOUT' => 97,
        'SSL_EXPIRED' => 95, 'SSL_INVALID' => 94, 'SSL_HANDSHAKE' => 93, 'REDIRECT_LOOP' => 92,
        'DB_DOWN' => 90, 'APP_ERROR' => 89, 'HTTP_5XX' => 88,
        'STRING_MISSING' => 80, 'STRING_FORBIDDEN' => 79,
        'HTTP_404' => 75, 'HTTP_403' => 74, 'HTTP_401' => 73, 'HTTP_429' => 72,
        'HTTP_4XX' => 71, 'HTTP_3XX' => 70, 'HTTP_UNEXPECTED' => 69,
        'JSON_INVALID' => 65, 'JSON_PATH' => 64, 'JSON_VALUE' => 63,
        'CSS_BROKEN' => 60,
        'SSL_SOON' => 50, 'CSS_DEGRADED' => 45, 'NOINDEX' => 40, 'SLOW' => 30,
    ];

    private static function verdict(array $findings, array $details, array $events, Response $res): array
    {
        $state = 'up';
        foreach ($findings as $f) {
            if (self::SEVERITY[$f['state']] > self::SEVERITY[$state]) $state = $f['state'];
        }
        // Causes de la gravité retenue, triées par priorité d'affichage.
        $primary = array_values(array_filter($findings, fn($f) => $f['state'] === $state));
        usort($primary, fn($a, $b) => (self::REASON_PRIORITY[$b['reason'] ?? ''] ?? 0)
                                   <=> (self::REASON_PRIORITY[$a['reason'] ?? ''] ?? 0));
        $reason = $state === 'up' ? null : ($primary[0]['reason'] ?? null);
        $msg = $state === 'up'
            ? 'Tout va bien'
            : implode(' · ', array_slice(array_column($primary, 'message'), 0, 3));

        return [
            'state'    => $state,
            'reason'   => $reason,
            'message'  => str_cut($msg, 400),
            'findings' => $findings,
            'details'  => $details,
            'events'   => $events,
            'response' => [
                'status' => $res->status, 'total_ms' => $res->totalMs, 'size' => $res->size,
                'final_url' => $res->finalUrl, 'redirects' => $res->redirects,
            ],
        ];
    }

    // =====================================================================
    // Persistance + moteur d'incidents
    // =====================================================================

    private static function persist(array $mon, Response $res, array $verdict, int $attempts): void
    {
        $id    = (int)$mon['id'];
        $state = $verdict['state'];
        $ts    = now();
        $det   = $verdict['details'];

        $cssState = $det['css']['state'] ?? null;
        $sslDays  = $det['ssl']['days_left'] ?? ($mon['ssl_days_left'] !== null ? (int)$mon['ssl_days_left'] : null);

        Db::insert('checks', [
            'monitor_id'    => $id,
            'ts'            => $ts,
            'state'         => $state,
            'reason_code'   => $verdict['reason'],
            'status_code'   => $res->status ?: null,
            'message'       => $verdict['message'],
            // Attention : une réponse en 0 ms (serveur local, cache) est une
            // mesure valide — la convertir en NULL la ferait disparaître des stats.
            'dns_ms'        => $res->status > 0 || $res->dnsMs > 0 ? $res->dnsMs : null,
            'connect_ms'    => $res->status > 0 || $res->connectMs > 0 ? $res->connectMs : null,
            'tls_ms'        => $res->tlsMs > 0 ? $res->tlsMs : null,
            'ttfb_ms'       => $res->status > 0 || $res->ttfbMs > 0 ? $res->ttfbMs : null,
            'total_ms'      => $res->status > 0 || $res->totalMs > 0 ? $res->totalMs : null,
            'size_bytes'    => $res->size ?: null,
            'redirects'     => $res->redirects,
            'final_url'     => $res->finalUrl !== $mon['url'] ? $res->finalUrl : null,
            'ssl_days_left' => $sslDays,
            'css_state'     => $cssState,
            'details'       => ($verdict['findings'] || isset($det['net_error'])) ? jenc([
                                   'findings' => array_map(
                                       fn($f) => [$f['state'], $f['reason'], str_cut($f['message'], 200)],
                                       $verdict['findings']),
                                   'net_error' => $det['net_error'] ?? null,
                               ]) : null,
            'attempts'      => $attempts,
        ]);

        // --- Mise à jour de la sonde ---------------------------------------
        $jitter   = random_int(0, (int)max(1, min(45, ((int)$mon['interval_sec']) / 8)));
        $upd = [
            'last_check_at'    => $ts,
            'next_check_at'    => date('Y-m-d H:i:s', time() + (int)$mon['interval_sec'] + $jitter),
            'last_ms'          => $res->status > 0 || $res->totalMs > 0 ? $res->totalMs : null,
            'last_status_code' => $res->status ?: null,
            'last_ip'          => $res->ip !== '' ? $res->ip : ($mon['last_ip'] ?? null),
            'reason_code'      => $verdict['reason'],
            'last_message'     => $verdict['message'],
        ];
        if ($mon['status'] !== $state) {
            $upd['status']       = $state;
            $upd['status_since'] = $ts;
        }
        $upd['consecutive_fail'] = $state === 'down' ? ((int)$mon['consecutive_fail'] + 1) : 0;
        $upd['consecutive_ok']   = $state === 'up'   ? ((int)$mon['consecutive_ok'] + 1) : 0;

        if (isset($det['ssl']) && $det['ssl']['checked']) {
            $upd['ssl_checked_at'] = $ts;
            $upd['ssl_days_left']  = $det['ssl']['days_left'];
            $upd['ssl_issuer']     = $det['ssl']['issuer'] ? str_cut($det['ssl']['issuer'], 120) : null;
            $upd['ssl_expires_at'] = $det['ssl']['expires_at'];
        }
        if (isset($det['css'])) {
            $upd['css_checked_at'] = $ts;
            $upd['css_state']      = $det['css']['state'];
            $upd['css_detail']     = jenc([
                'messages' => $det['css']['messages'],
                'console'  => $det['css']['console'] ?? [],
                'metrics'  => self::slimCssMetrics($det['css']['metrics'] ?? []),
                'at'       => $ts,
            ]);
            // L'empreinte de référence n'est mémorisée que sur un état sain.
            $lock = (int)($mon['css_baseline_locked'] ?? 0) === 1;
            if ($det['css']['state'] === 'ok' && !$lock && !empty($det['css']['baseline'])) {
                $upd['css_baseline']    = jenc($det['css']['baseline']);
                $upd['css_baseline_at'] = $ts;
            }
        }
        if (isset($det['watch_state'])) {
            $upd['watch_state'] = $det['watch_state'];
            if ($det['watch_state'] === 'present' && ($mon['watch_state'] ?? null) !== 'present') {
                $upd['watch_seen_at'] = $ts;
            }
        }
        if (isset($det['content_hash'])) {
            if (($mon['content_hash'] ?? null) !== $det['content_hash']) {
                $upd['content_changed_at'] = $ts;
            }
            $upd['content_hash']    = $det['content_hash'];
            $upd['content_hash_at'] = $ts;
        }

        Db::update('monitors', $upd, 'id = :__id', ['__id' => $id]);

        // Le seuil de lenteur se recale sur les mesures réelles de cette sonde.
        if ($state !== 'down') Tune::slowThreshold(array_merge($mon, ['slow_ms' => $mon['slow_ms']]));

        // --- Incidents & notifications -------------------------------------
        if ($res->ip !== '') $mon['last_ip'] = $res->ip;
        self::applyIncident($mon, $state, $verdict);

        foreach ($verdict['events'] as $ev) {
            Db::insert('events', [
                'monitor_id' => $id, 'ts' => $ts, 'kind' => $ev['kind'],
                'message' => str_cut($ev['message'], 300), 'details' => null, 'seen' => 0,
            ]);
            if (!empty($ev['notify'])) {
                Notifier::sendEvent($mon, $ev['kind'], $ev['message']);
            }
        }
    }

    /** Ouverture / mise à jour / clôture d'incident. */
    private static function applyIncident(array $mon, string $state, array $verdict): void
    {
        $id   = (int)$mon['id'];
        $open = Db::one('SELECT * FROM incidents WHERE monitor_id = ? AND ended_at IS NULL ORDER BY id DESC LIMIT 1', [$id]);
        $ts   = now();

        if ($state === 'up') {
            if ($open) {
                $dur = max(0, time() - strtotime((string)$open['started_at']));
                Db::update('incidents', ['ended_at' => $ts, 'duration_sec' => $dur], 'id = :__i', ['__i' => (int)$open['id']]);
                Notifier::sendRecovery($mon, $open + ['duration_sec' => $dur]);
            }
            return;
        }

        if (!$open) {
            $incidentId = Db::insert('incidents', [
                'monitor_id'    => $id,
                'severity'      => $state,
                'reason_code'   => $verdict['reason'],
                'message'       => str_cut($verdict['message'], 400),
                'started_at'    => $ts,
                'checks_failed' => 1,
            ]);
            $inc = Db::one('SELECT * FROM incidents WHERE id = ?', [$incidentId]);
            // On n'alerte pas tout de suite : les pannes de la passe sont mises en
            // file puis corrélées, pour qu'un serveur entier ne génère qu'un seul
            // message au lieu d'un par site.
            if ($inc) self::$pendingAlerts[] = ['monitor' => $mon, 'incident' => $inc];
            return;
        }

        // Incident en cours : on aggrave si besoin, on relance l'alerte à intervalle.
        $upd = ['checks_failed' => (int)$open['checks_failed'] + 1];
        $escalated = false;
        if (self::SEVERITY[$state] > self::SEVERITY[$open['severity']]) {
            $upd['severity']    = $state;
            $upd['reason_code'] = $verdict['reason'];
            $upd['message']     = str_cut($verdict['message'], 400);
            $escalated = true;
        } elseif ($open['reason_code'] !== $verdict['reason']) {
            $upd['message'] = str_cut($verdict['message'], 400);
        }
        Db::update('incidents', $upd, 'id = :__i', ['__i' => (int)$open['id']]);

        $resend = (int)Config::get('notify.resend_after_min', 60);
        // Si la première alerte n'est jamais partie (canal HS, heures calmes),
        // on repart de l'ouverture de l'incident pour ne pas rester muet.
        $last = $open['last_notified_at']
            ? strtotime((string)$open['last_notified_at'])
            : strtotime((string)$open['started_at']);
        if ($escalated || ($resend > 0 && $last > 0 && time() - $last >= $resend * 60)) {
            $inc = Db::one('SELECT * FROM incidents WHERE id = ?', [(int)$open['id']]);
            if ($inc && !$inc['ack_at']) Notifier::sendIncident($mon, $inc, false);
        }
    }

    private static function persistPaused(array $mon): void
    {
        Db::update('monitors', [
            'status'        => 'paused',
            'last_check_at' => now(),
            'next_check_at' => date('Y-m-d H:i:s', time() + max(60, (int)$mon['interval_sec'])),
        ], 'id = :__id', ['__id' => (int)$mon['id']]);
    }

    // =====================================================================
    // Utilitaires
    // =====================================================================

    public static function requestOptions(array $mon): array
    {
        $headers = [];
        foreach (jdec($mon['request_headers'] ?? null) as $k => $v) $headers[(string)$k] = (string)$v;
        return [
            'method'   => $mon['method'] ?: 'GET',
            'body'     => $mon['request_body'] ?? null,
            'headers'  => $headers,
            'timeout'  => (int)($mon['timeout_sec'] ?: 15),
            'follow'   => (int)$mon['follow_redirects'] === 1,
            'insecure' => (int)$mon['ignore_ssl_errors'] === 1,
            'ua'       => $mon['user_agent'] ?: null,
            'auth'     => ($mon['auth_user'] ?? '') !== '' ? $mon['auth_user'] . ':' . ($mon['auth_pass'] ?? '') : null,
            'maxBody'  => $mon['kind'] === 'api' ? 500000 : Http::MAX_BODY,
        ];
    }

    private static function worthRetrying(Response $res): bool
    {
        if (!$res->ok || $res->status === 0) return true;
        return $res->status >= 500 || $res->status === 429;
    }

    public static function isPaused(array $mon): bool
    {
        if ((int)$mon['enabled'] !== 1) return true;
        if (!empty($mon['paused_until']) && strtotime((string)$mon['paused_until']) > time()) return true;
        return self::inMaintenance((string)($mon['maintenance'] ?? ''));
    }

    /** Fenêtre de maintenance : « 02:00-04:00 » ou « mon-fri 02:00-04:00 » ou « sat,sun 01:00-06:00 ». */
    public static function inMaintenance(string $spec): bool
    {
        $spec = strtolower(trim($spec));
        if ($spec === '') return false;
        $days = null;
        if (preg_match('~^([a-z,\-]+)\s+(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})$~', $spec, $m)) {
            $days = $m[1]; $from = $m[2]; $to = $m[3];
        } elseif (preg_match('~^(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})$~', $spec, $m)) {
            $from = $m[1]; $to = $m[2];
        } else {
            return false;
        }
        if ($days !== null) {
            $map = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7,
                    'lun' => 1, 'mar' => 2, 'mer' => 3, 'jeu' => 4, 'ven' => 5, 'sam' => 6, 'dim' => 7];
            $today = (int)date('N');
            $ok = false;
            foreach (explode(',', $days) as $chunk) {
                if (preg_match('~^([a-z]{3})-([a-z]{3})$~', $chunk, $mm)) {
                    $a = $map[$mm[1]] ?? null; $b = $map[$mm[2]] ?? null;
                    if ($a && $b && $today >= $a && $today <= $b) { $ok = true; break; }
                } elseif (isset($map[$chunk]) && $map[$chunk] === $today) { $ok = true; break; }
            }
            if (!$ok) return false;
        }
        $nowMin  = (int)date('H') * 60 + (int)date('i');
        [$fh, $fm] = array_map('intval', explode(':', $from));
        [$th, $tm] = array_map('intval', explode(':', $to));
        $a = $fh * 60 + $fm; $b = $th * 60 + $tm;
        return $a <= $b ? ($nowMin >= $a && $nowMin <= $b) : ($nowMin >= $a || $nowMin <= $b);
    }

    /** « 200 », « 200-299 », « 2xx », « 200,301,302 ». */
    public static function statusMatches(int $status, string $spec): bool
    {
        $spec = trim($spec);
        if ($spec === '') return $status >= 200 && $status < 400;
        foreach (preg_split('~[,\s]+~', $spec) ?: [] as $part) {
            $part = strtolower(trim($part));
            if ($part === '') continue;
            if (preg_match('~^(\d)xx$~', $part, $m)) {
                if (intdiv($status, 100) === (int)$m[1]) return true;
            } elseif (preg_match('~^(\d{3})\s*-\s*(\d{3})$~', $part, $m)) {
                if ($status >= (int)$m[1] && $status <= (int)$m[2]) return true;
            } elseif (ctype_digit($part)) {
                if ($status === (int)$part) return true;
            }
        }
        return false;
    }

    /**
     * Recherche insensible à la casse ; « a|b » = l'une des deux suffit.
     * Tolère les entités HTML (&eacute;) et les apostrophes typographiques,
     * dans les deux sens : la chaîne saisie n'a pas à être encodée comme la page.
     */
    public static function containsAny(string $haystack, string $needles): bool
    {
        $decoded = null;   // décodage du corps calculé au besoin seulement

        foreach (explode('|', $needles) as $n) {
            $n = trim($n);
            if ($n === '') continue;
            if (stripos($haystack, $n) !== false) return true;

            // Variantes de la chaîne recherchée
            $variants = [
                str_replace(["'", '&nbsp;'], ['’', ' '], $n),
                html_entity_decode($n, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                htmlspecialchars($n, ENT_QUOTES, 'UTF-8'),
            ];
            foreach ($variants as $alt) {
                if ($alt !== $n && $alt !== '' && stripos($haystack, $alt) !== false) return true;
            }

            // Page encodée en entités : on décode le corps une seule fois.
            if (str_contains($haystack, '&')) {
                $decoded ??= html_entity_decode($haystack, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (stripos($decoded, $n) !== false) return true;
                $soft = str_replace("'", '’', $n);
                if ($soft !== $n && stripos($decoded, $soft) !== false) return true;
            }
        }
        return false;
    }

    /** Chemin simple type « data.status » ou « 0.name ». */
    public static function jsonPath(mixed $json, string $path): mixed
    {
        $node = $json;
        foreach (explode('.', trim($path, '. ')) as $seg) {
            if ($seg === '') continue;
            if (is_array($node) && array_key_exists($seg, $node)) $node = $node[$seg];
            elseif (is_array($node) && ctype_digit($seg) && array_key_exists((int)$seg, $node)) $node = $node[(int)$seg];
            else return null;
        }
        return is_scalar($node) || $node === null ? $node : jenc($node);
    }

    /** Empreinte du contenu utile (sans scripts, styles, jetons ni horodatages). */
    public static function contentHash(string $html): string
    {
        $s = preg_replace('~<script\b.*?</script>~is', '', $html) ?? $html;
        $s = preg_replace('~<style\b.*?</style>~is', '', $s) ?? $s;
        $s = preg_replace('~<!--.*?-->~s', '', $s) ?? $s;
        $s = preg_replace('~<(link|meta)\b[^>]*>~i', '', $s) ?? $s;
        $s = preg_replace('~(nonce|csrf|token|_wpnonce|ver)=["\']?[\w\-]+~i', '', $s) ?? $s;
        $s = strip_tags($s);
        $s = preg_replace('~\d{1,2}[:/]\d{2}(?::\d{2})?~', '', $s) ?? $s; // heures
        $s = preg_replace('~\s+~u', ' ', $s) ?? $s;
        return sha1(trim($s));
    }

    private static function portOf(string $url): int
    {
        $p = parse_url($url);
        return (int)($p['port'] ?? (($p['scheme'] ?? 'https') === 'https' ? 443 : 80));
    }

    /** On ne stocke pas tout le détail CSS : juste ce qui sert à l'affichage. */
    private static function slimCssMetrics(array $m): array
    {
        $assets = [];
        foreach (($m['assets'] ?? []) as $a) {
            $assets[] = [
                'url' => $a['url'], 'kind' => $a['kind'] ?? 'css', 'status' => $a['status'],
                'bytes' => $a['bytes'], 'issue' => $a['issue'], 'note' => $a['note'],
                'soft' => $a['soft'] ?? false,
            ];
        }
        // Les ressources en échec d'abord : c'est ce qu'on veut lire en premier.
        usort($assets, fn($a, $b) => (empty($a['issue']) <=> empty($b['issue'])) ?: ($a['kind'] <=> $b['kind']));

        return [
            'sheets_declared' => $m['sheets_declared'] ?? 0,
            'sheets_ok'       => $m['sheets_ok'] ?? 0,
            'sheets_failed'   => $m['sheets_failed'] ?? 0,
            'js_declared'     => $m['js_declared'] ?? 0,
            'js_ok'           => $m['js_ok'] ?? 0,
            'js_failed'       => $m['js_failed'] ?? 0,
            'fonts_checked'   => $m['fonts_checked'] ?? 0,
            'fonts_failed'    => $m['fonts_failed'] ?? 0,
            'css_bytes'       => $m['css_bytes'] ?? 0,
            'rules'           => $m['rules'] ?? 0,
            'media_queries'   => $m['media_queries'] ?? 0,
            'layout_score'    => $m['layout_score'] ?? 0,
            'coverage'        => $m['coverage'] ?? null,
            'classes_missing' => array_slice($m['classes_missing'] ?? [], 0, 8),
            'inline_bytes'    => $m['inline_bytes'] ?? 0,
            'hidden_nodes'    => $m['hidden_nodes'] ?? 0,
            'hidden_risk'     => $m['hidden_risk'] ?? false,
            'assets'          => array_slice($assets, 0, 30),
        ];
    }

    /** Rafraîchit expiration de domaine (une fois par jour, sondes principales). */
    public static function refreshDomains(int $limit = 10): int
    {
        $rows = Db::all(
            "SELECT id, url, domain_expires_at FROM monitors
             WHERE enabled = 1 AND role = 'primary'
             ORDER BY (domain_expires_at IS NULL) DESC, domain_expires_at ASC LIMIT " . max(1, $limit)
        );
        $n = 0;
        foreach ($rows as $r) {
            $info = DomainExpiry::lookup(host_of((string)$r['url']));
            if (!$info) continue;
            Db::update('monitors', ['domain_expires_at' => $info['expires_at']], 'id = :__i', ['__i' => (int)$r['id']]);
            if ($info['days_left'] <= 30) {
                $exists = Db::val("SELECT 1 FROM events WHERE monitor_id = ? AND kind = 'domain_soon' AND ts > ?",
                    [(int)$r['id'], date('Y-m-d H:i:s', time() - 7 * 86400)]);
                if (!$exists) {
                    Db::insert('events', ['monitor_id' => (int)$r['id'], 'ts' => now(), 'kind' => 'domain_soon',
                        'message' => 'Le domaine ' . $info['domain'] . ' expire dans ' . $info['days_left'] . ' jour(s)',
                        'details' => jenc($info), 'seen' => 0]);
                    $mon = Db::one('SELECT * FROM monitors WHERE id = ?', [(int)$r['id']]);
                    if ($mon) Notifier::sendEvent($mon, 'domain_soon',
                        'Le domaine ' . $info['domain'] . ' expire dans ' . $info['days_left'] . ' jour(s)');
                }
            }
            $n++;
        }
        return $n;
    }
}
