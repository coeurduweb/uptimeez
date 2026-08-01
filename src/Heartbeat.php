<?php
declare(strict_types=1);

namespace Uptimeez;

use Uptimeez\Notify\Notifier;

/**
 * Sonde « battement » (dead-man switch).
 *
 * Le principe est inversé : ce n'est pas UptimeEZ qui interroge le site, c'est le
 * site qui doit se signaler. On surveille ainsi ce qu'aucune requête HTTP ne peut
 * voir : un cron WordPress qui ne tourne plus, une sauvegarde qui ne s'exécute
 * plus, un import nocturne silencieux. L'alerte part quand le signal n'arrive pas.
 *
 * Côté client, une ligne suffit à la fin du script à surveiller :
 *   curl -fsS https://uptimeez.exemple.fr/beat.php?k=LACLE > /dev/null
 */
final class Heartbeat
{
    /** Crée une sonde battement et retourne [id, token]. */
    public static function create(string $name, int $expectEverySec, int $graceSec = 300,
                                  ?int $siteId = null, string $notifyChannels = ''): array
    {
        $token = bin2hex(random_bytes(12));
        $id = Db::insert('monitors', [
            'site_id'       => $siteId,
            'name'          => str_cut($name, 180),
            // L'URL n'est jamais appelée : elle documente l'endroit à instrumenter.
            'url'           => 'heartbeat://' . $token,
            'kind'          => 'heartbeat',
            'role'          => 'secondary',
            'method'        => 'GET',
            'interval_sec'  => max(60, $expectEverySec),
            'timeout_sec'   => 10,
            'retries'       => 0,
            'slow_ms'       => 0,
            'auto_slow'     => 0,
            'expect_status' => '200-299',
            'check_ssl'     => 0, 'check_css' => 0, 'check_db' => 0, 'check_noindex' => 0,
            'ssl_warn_days' => 14, 'css_drop_pct' => 35,
            'enabled'       => 1,
            'status'        => 'unknown',
            'setup_state'   => 'done',
            'setup_note'    => t('Sonde battement : c\'est l\'absence de signal qui déclenche l\'alerte.'),
            'heartbeat_token' => $token,
            'heartbeat_grace' => max(30, $graceSec),
            'notify_channels' => $notifyChannels !== '' ? $notifyChannels : null,
            'created_at'    => now(),
            'next_check_at' => null,
        ]);
        Tune::note($id, t('Sonde battement créée'),
            t('Signal attendu toutes les {every}, tolérance {grace}.',
              ['every' => human_duration(max(60, $expectEverySec)),
               'grace' => human_duration(max(30, $graceSec))]));
        return ['id' => $id, 'token' => $token];
    }

    /**
     * Enregistre un signal reçu. Appelé par beat.php.
     * @return array{ok:bool,message:string,id:?int}
     */
    public static function beat(string $token, string $note = ''): array
    {
        $token = preg_replace('~[^a-f0-9]~i', '', $token) ?? '';
        if (strlen($token) < 16) return ['ok' => false, 'message' => t('Clé invalide'), 'id' => null];

        $mon = Db::one('SELECT * FROM monitors WHERE heartbeat_token = ?', [$token]);
        if (!$mon) return ['ok' => false, 'message' => t('Clé inconnue'), 'id' => null];

        $id  = (int)$mon['id'];
        $now = now();
        $wasDown = $mon['status'] === 'down';

        Db::update('monitors', [
            'heartbeat_at'  => $now,
            'last_check_at' => $now,
            'status'        => 'up',
            'reason_code'   => null,
            'last_message'  => $note !== '' ? str_cut($note, 200) : t('Signal reçu'),
            'status_since'  => $wasDown || $mon['status'] !== 'up' ? $now : $mon['status_since'],
            'consecutive_fail' => 0,
            'consecutive_ok'   => (int)$mon['consecutive_ok'] + 1,
        ], 'id = :__i', ['__i' => $id]);

        Db::insert('checks', [
            'monitor_id' => $id, 'ts' => $now, 'state' => 'up', 'reason_code' => null,
            'status_code' => 200, 'message' => $note !== '' ? str_cut($note, 200) : t('Signal reçu'),
            'total_ms' => 0, 'attempts' => 1,
        ]);

        // Rétablissement : on clôt l'incident et on prévient.
        $open = Db::one('SELECT * FROM incidents WHERE monitor_id = ? AND ended_at IS NULL ORDER BY id DESC LIMIT 1', [$id]);
        if ($open) {
            $dur = max(0, time() - strtotime((string)$open['started_at']));
            Db::update('incidents', ['ended_at' => $now, 'duration_sec' => $dur],
                       'id = :__i', ['__i' => (int)$open['id']]);
            Notifier::sendRecovery($mon, $open + ['duration_sec' => $dur]);
        }
        return ['ok' => true, 'message' => t('Signal enregistré'), 'id' => $id];
    }

    /**
     * Vérifie les battements manquants. Appelé à chaque passe de cron.
     * @return int nombre de sondes passées hors service
     */
    public static function sweep(): int
    {
        $rows = Db::all("SELECT * FROM monitors WHERE kind = 'heartbeat' AND enabled = 1");
        $late = 0;

        foreach ($rows as $mon) {
            if (Runner::isPaused($mon)) continue;
            $id      = (int)$mon['id'];
            $expect  = max(60, (int)$mon['interval_sec']);
            $grace   = max(30, (int)$mon['heartbeat_grace']);
            $lastRaw = $mon['heartbeat_at'] ?: $mon['created_at'];
            $last    = strtotime((string)$lastRaw) ?: time();
            $overdue = time() - $last - $expect - $grace;

            if ($overdue <= 0) continue;   // dans les temps

            $msg = t('Aucun signal depuis {since}, alors qu\'il est attendu toutes les {every}.',
                     ['since' => human_duration(time() - $last), 'every' => human_duration($expect)]);

            if ($mon['status'] !== 'down') {
                Db::update('monitors', [
                    'status' => 'down', 'reason_code' => 'HEARTBEAT_LATE', 'last_message' => $msg,
                    'status_since' => now(), 'last_check_at' => now(),
                    'consecutive_fail' => (int)$mon['consecutive_fail'] + 1,
                ], 'id = :__i', ['__i' => $id]);

                Db::insert('checks', [
                    'monitor_id' => $id, 'ts' => now(), 'state' => 'down',
                    'reason_code' => 'HEARTBEAT_LATE', 'message' => $msg, 'attempts' => 1,
                ]);

                $incId = Db::insert('incidents', [
                    'monitor_id' => $id, 'severity' => 'down', 'reason_code' => 'HEARTBEAT_LATE',
                    'message' => $msg, 'started_at' => now(), 'checks_failed' => 1,
                ]);
                $inc = Db::one('SELECT * FROM incidents WHERE id = ?', [$incId]);
                if ($inc) Notifier::sendIncident($mon, $inc, 'nouveau');
                $late++;
            } else {
                $open = Db::one('SELECT * FROM incidents WHERE monitor_id = ? AND ended_at IS NULL ORDER BY id DESC LIMIT 1', [$id]);
                if ($open) {
                    Db::update('incidents', ['checks_failed' => (int)$open['checks_failed'] + 1, 'message' => $msg],
                               'id = :__i', ['__i' => (int)$open['id']]);
                }
                Db::update('monitors', ['last_message' => $msg, 'last_check_at' => now()], 'id = :__i', ['__i' => $id]);
            }
        }
        return $late;
    }

    /** URL à copier dans le script du client. */
    public static function url(array $mon): string
    {
        $base = rtrim((string)Config::get('app.base_url', ''), '/');
        return ($base !== '' ? $base : 'https://votre-adresse-uptimeez') . '/beat.php?k=' . (string)$mon['heartbeat_token'];
    }

    /** Ligne prête à coller à la fin d'un script surveillé. */
    public static function snippet(array $mon): string
    {
        return 'curl -fsS --max-time 10 "' . self::url($mon) . '" > /dev/null';
    }
}
