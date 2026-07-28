<?php
declare(strict_types=1);

namespace Uptimer;

/**
 * Calculs d'uptime et séries temporelles.
 *
 * L'uptime se calcule sur la durée réelle des incidents (et non sur un ratio de
 * vérifications), pour rester juste quand l'intervalle de contrôle change.
 */
final class Stats
{
    /**
     * @return array{uptime:?float,downtime_sec:int,monitored_sec:int,incidents:int,
     *               avg_ms:?int,p95_ms:?int,checks:int,worst_ms:?int}
     */
    public static function window(int $monitorId, int $seconds, ?array $mon = null): array
    {
        $to   = time();
        $from = $to - $seconds;
        $mon ??= Db::one('SELECT created_at FROM monitors WHERE id = ?', [$monitorId]);
        $createdTs = $mon && !empty($mon['created_at']) ? strtotime((string)$mon['created_at']) : $from;
        $start = max($from, $createdTs ?: $from);
        $monitored = max(0, $to - $start);

        $fromSql = date('Y-m-d H:i:s', $from);
        $rows = Db::all(
            'SELECT started_at, ended_at, severity FROM incidents
             WHERE monitor_id = ? AND severity = ? AND (ended_at IS NULL OR ended_at >= ?)
             ORDER BY started_at ASC',
            [$monitorId, 'down', $fromSql]
        );

        $down = 0; $count = 0;
        foreach ($rows as $r) {
            $s = strtotime((string)$r['started_at']);
            $e = $r['ended_at'] ? strtotime((string)$r['ended_at']) : $to;
            $s = max($s, $start); $e = min($e, $to);
            if ($e > $s) { $down += ($e - $s); $count++; }
        }

        // Longue portée : les mesures unitaires ont été purgées, on lit les
        // agrégats journaliers (l'uptime, lui, vient toujours des incidents).
        if ($seconds > self::RAW_WINDOW_SEC) {
            $d = Db::one('SELECT SUM(checks) AS n, SUM(fails) AS fails,
                                 SUM(avg_ms * checks) AS w, SUM(checks) AS c, MAX(max_ms) AS max_ms,
                                 MAX(p95_ms) AS p95
                          FROM daily_stats WHERE monitor_id = ? AND day >= ?',
                [$monitorId, date('Y-m-d', $from)]) ?: [];
            $n = (int)($d['n'] ?? 0);
            return [
                'uptime'        => $monitored > 0 ? round(max(0, ($monitored - $down)) / $monitored * 100, 3) : null,
                'downtime_sec'  => $down,
                'monitored_sec' => $monitored,
                'incidents'     => $count,
                'checks'        => $n,
                'avg_ms'        => $n > 0 && !empty($d['w']) ? (int)round((float)$d['w'] / max(1, (int)$d['c'])) : null,
                'worst_ms'      => isset($d['max_ms']) && $d['max_ms'] !== null ? (int)$d['max_ms'] : null,
                'ping_ms'       => null,
                'dns_ms'        => null,
                'ttfb_ms'       => null,
                'p95_ms'        => isset($d['p95']) && $d['p95'] !== null ? (int)$d['p95'] : null,
                'source'        => 'daily',
            ];
        }

        // Le « ping » réseau = poignée de main TCP seule, DNS déduit.
        $agg = Db::one(
            'SELECT COUNT(*) AS n, AVG(total_ms) AS avg_ms, MAX(total_ms) AS max_ms,
                    AVG(CASE WHEN connect_ms IS NULL OR connect_ms <= 0 THEN NULL
                             WHEN connect_ms > COALESCE(dns_ms, 0) THEN connect_ms - COALESCE(dns_ms, 0)
                             ELSE 0 END) AS ping_ms,
                    AVG(dns_ms) AS dns_ms, AVG(ttfb_ms) AS ttfb_ms
             FROM checks WHERE monitor_id = ? AND ts >= ?',
            [$monitorId, $fromSql]
        ) ?: [];

        return [
            'uptime'        => $monitored > 0 ? round(max(0, ($monitored - $down)) / $monitored * 100, 3) : null,
            'downtime_sec'  => $down,
            'monitored_sec' => $monitored,
            'incidents'     => $count,
            'checks'        => (int)($agg['n'] ?? 0),
            'avg_ms'        => isset($agg['avg_ms']) && $agg['avg_ms'] !== null ? (int)round((float)$agg['avg_ms']) : null,
            'worst_ms'      => isset($agg['max_ms']) && $agg['max_ms'] !== null ? (int)$agg['max_ms'] : null,
            'ping_ms'       => isset($agg['ping_ms']) && $agg['ping_ms'] !== null ? (int)round((float)$agg['ping_ms']) : null,
            'dns_ms'        => isset($agg['dns_ms']) && $agg['dns_ms'] !== null ? (int)round((float)$agg['dns_ms']) : null,
            'ttfb_ms'       => isset($agg['ttfb_ms']) && $agg['ttfb_ms'] !== null ? (int)round((float)$agg['ttfb_ms']) : null,
            'p95_ms'        => self::percentile($monitorId, $fromSql, 95),
        ];
    }

    public static function percentile(int $monitorId, string $fromSql, int $p = 95): ?int
    {
        $n = (int)Db::val('SELECT COUNT(*) FROM checks WHERE monitor_id = ? AND ts >= ? AND total_ms IS NOT NULL',
            [$monitorId, $fromSql], 0);
        if ($n < 5) return null;
        $offset = (int)floor($n * ($p / 100)) - 1;
        $offset = max(0, min($n - 1, $offset));
        $v = Db::val('SELECT total_ms FROM checks WHERE monitor_id = ? AND ts >= ? AND total_ms IS NOT NULL
                      ORDER BY total_ms ASC LIMIT 1 OFFSET ' . $offset, [$monitorId, $fromSql]);
        return $v === null ? null : (int)$v;
    }

    /**
     * Série pour la courbe : N intervalles réguliers sur la fenêtre demandée.
     * @return array{buckets:array<int,array>,from:string,to:string,step:int}
     */
    /** Au-delà de ce seuil on lit les agrégats journaliers, pas les mesures unitaires. */
    public const RAW_WINDOW_SEC = 40 * 86400;

    public static function series(int $monitorId, int $seconds, int $buckets = 60): array
    {
        // Les mesures unitaires sont purgées après quelques semaines : sur les
        // longues périodes, la source de vérité devient daily_stats (conservé).
        if ($seconds > self::RAW_WINDOW_SEC) {
            return self::seriesFromDaily($monitorId, $seconds);
        }

        $to    = time();
        $from  = $to - $seconds;
        $step  = (int)max(60, floor($seconds / max(1, $buckets)));
        $slots = [];
        for ($t = $from; $t < $to; $t += $step) {
            $slots[] = ['t' => $t, 'avg_ms' => null, 'max_ms' => null, 'n' => 0, 'fails' => 0,
                        'degraded' => 0, 'state' => 'none', 'down_sec' => 0];
        }
        if (!$slots) return ['buckets' => [], 'from' => date('c', $from), 'to' => date('c', $to), 'step' => $step];

        // Agrégation côté base : une fenêtre de 90 jours représente des dizaines
        // de milliers de mesures qu'il serait absurde de traverser en PHP.
        $fromSql = date('Y-m-d H:i:s', $from);
        $bucket  = Db::bucketExpr('ts', $step);
        $rows    = Db::all(
            "SELECT $bucket AS b,
                    COUNT(*) AS n, SUM(total_ms) AS sum_ms, MAX(total_ms) AS max_ms,
                    SUM(CASE WHEN state = 'down' THEN 1 ELSE 0 END) AS fails,
                    SUM(CASE WHEN state = 'degraded' THEN 1 ELSE 0 END) AS degraded
             FROM checks WHERE monitor_id = ? AND ts >= ? GROUP BY b ORDER BY b ASC",
            [$fromSql, $monitorId, $fromSql]
        );
        $nb  = count($slots);
        $sum = array_fill(0, $nb, 0);
        foreach ($rows as $r) {
            // Une mesure horodatée à la seconde courante retombe sur l'indice
            // suivant le dernier intervalle : on la rattache au plus récent.
            $i = min((int)$r['b'], $nb - 1);
            if ($i < 0) continue;
            $slots[$i]['n']        = (int)$r['n'];
            $slots[$i]['max_ms']   = $r['max_ms'] !== null ? (int)$r['max_ms'] : null;
            $slots[$i]['fails']    = (int)$r['fails'];
            $slots[$i]['degraded'] = (int)$r['degraded'];
            $sum[$i]               = (int)$r['sum_ms'];
        }
        foreach ($slots as $i => $s) {
            // 0 ms est une moyenne valide (site local, réponse en cache).
            if ($s['n'] > 0) $slots[$i]['avg_ms'] = (int)round($sum[$i] / max(1, $s['n']));
            $slots[$i]['state'] = $s['fails'] > 0 ? 'down' : ($s['degraded'] > 0 ? 'degraded' : ($s['n'] > 0 ? 'up' : 'none'));
        }

        // Répartition de la durée d'indisponibilité (source : incidents)
        $incidents = Db::all(
            'SELECT started_at, ended_at FROM incidents
             WHERE monitor_id = ? AND severity = ? AND (ended_at IS NULL OR ended_at >= ?)',
            [$monitorId, 'down', date('Y-m-d H:i:s', $from)]
        );
        foreach ($incidents as $inc) {
            $s = max(strtotime((string)$inc['started_at']), $from);
            $e = min($inc['ended_at'] ? strtotime((string)$inc['ended_at']) : $to, $to);
            for ($i = 0; $i < count($slots) && $e > $s; $i++) {
                $bs = $slots[$i]['t']; $be = $bs + $step;
                $ov = min($e, $be) - max($s, $bs);
                if ($ov > 0) {
                    $slots[$i]['down_sec'] += $ov;
                    if ($slots[$i]['state'] === 'none' || $slots[$i]['state'] === 'up') $slots[$i]['state'] = 'down';
                }
            }
        }

        return ['buckets' => $slots, 'from' => date('c', $from), 'to' => date('c', $to), 'step' => $step];
    }

    /**
     * Série longue portée, reconstruite depuis les agrégats journaliers.
     * Un intervalle = un jour (ou un groupe de jours au-delà de 120 jours).
     */
    private static function seriesFromDaily(int $monitorId, int $seconds): array
    {
        $days  = (int)max(1, round($seconds / 86400));
        $group = $days > 200 ? 5 : ($days > 120 ? 3 : 1);   // ~73 points sur un an
        $from  = strtotime(date('Y-m-d', time() - ($days - 1) * 86400) . ' 00:00:00');
        $step  = 86400 * $group;
        $nb    = (int)ceil($days / $group);

        $slots = [];
        for ($i = 0; $i < $nb; $i++) {
            $slots[$i] = ['t' => $from + $i * $step, 'avg_ms' => null, 'max_ms' => null, 'n' => 0,
                          'fails' => 0, 'degraded' => 0, 'state' => 'none', 'down_sec' => 0];
        }

        $rows = Db::all('SELECT day, checks, fails, degraded, downtime_sec, avg_ms, max_ms
                         FROM daily_stats WHERE monitor_id = ? AND day >= ? ORDER BY day ASC',
            [$monitorId, date('Y-m-d', $from)]);
        $sum = array_fill(0, $nb, 0.0);
        foreach ($rows as $r) {
            $i = (int)floor((strtotime((string)$r['day'] . ' 00:00:00') - $from) / $step);
            if ($i < 0 || $i >= $nb) continue;
            $slots[$i]['n']        += (int)$r['checks'];
            $slots[$i]['fails']    += (int)$r['fails'];
            $slots[$i]['degraded'] += (int)$r['degraded'];
            $slots[$i]['down_sec'] += (int)$r['downtime_sec'];
            $slots[$i]['max_ms']    = max((int)$slots[$i]['max_ms'], (int)$r['max_ms']);
            $sum[$i]               += (float)$r['avg_ms'] * max(1, (int)$r['checks']);
        }
        foreach ($slots as $i => $s) {
            if ($s['n'] > 0) $slots[$i]['avg_ms'] = (int)round($sum[$i] / $s['n']);
            $slots[$i]['state'] = ($s['down_sec'] > 0 || $s['fails'] > 0) ? 'down'
                : ($s['degraded'] > 0 ? 'degraded' : ($s['n'] > 0 ? 'up' : 'none'));
        }

        return ['buckets' => array_values($slots), 'from' => date('c', $from),
                'to' => date('c', time()), 'step' => $step, 'source' => 'daily'];
    }

    /**
     * Séries pour plusieurs sondes en 2 requêtes (tableau de bord).
     * @return array<int,array<int,array>>
     */
    public static function sparkBatch(array $monitorIds, int $seconds = 86400, int $buckets = 48): array
    {
        $ids = array_values(array_unique(array_map('intval', $monitorIds)));
        if (!$ids) return [];
        $to   = time();
        $from = $to - $seconds;
        $step = (int)max(60, floor($seconds / max(1, $buckets)));
        $slots = [];
        for ($t = $from; $t < $to; $t += $step) $slots[] = $t;
        $nb = count($slots);

        $tpl = [];
        for ($i = 0; $i < $nb; $i++) {
            $tpl[$i] = ['t' => $slots[$i], 'avg_ms' => null, 'max_ms' => null, 'n' => 0,
                        'fails' => 0, 'degraded' => 0, 'state' => 'none', 'down_sec' => 0];
        }
        $out = array_fill_keys($ids, $tpl);
        $sum = array_fill_keys($ids, array_fill(0, $nb, 0));

        // Agrégation faite par la base : avec 300 sondes, remonter chaque mesure
        // en PHP coûterait des dizaines de milliers de lignes par affichage.
        $in      = implode(',', array_fill(0, count($ids), '?'));
        $fromSql = date('Y-m-d H:i:s', $from);
        $bucket  = Db::bucketExpr('ts', $step);
        $rows    = Db::all(
            "SELECT monitor_id, $bucket AS b,
                    COUNT(*) AS n,
                    SUM(total_ms) AS sum_ms,
                    MAX(total_ms) AS max_ms,
                    SUM(CASE WHEN state = 'down' THEN 1 ELSE 0 END) AS fails,
                    SUM(CASE WHEN state = 'degraded' THEN 1 ELSE 0 END) AS degraded
             FROM checks
             WHERE monitor_id IN ($in) AND ts >= ?
             GROUP BY monitor_id, b",
            array_merge([$fromSql], $ids, [$fromSql])
        );
        foreach ($rows as $r) {
            $mid = (int)$r['monitor_id'];
            $i   = min((int)$r['b'], $nb - 1);   // cf. series() : mesure de la seconde courante
            if (!isset($out[$mid]) || $i < 0) continue;
            $out[$mid][$i]['n']        = (int)$r['n'];
            $out[$mid][$i]['max_ms']   = $r['max_ms'] !== null ? (int)$r['max_ms'] : null;
            $out[$mid][$i]['fails']    = (int)$r['fails'];
            $out[$mid][$i]['degraded'] = (int)$r['degraded'];
            $sum[$mid][$i]             = (int)$r['sum_ms'];
        }

        $incs = Db::all(
            "SELECT monitor_id, started_at, ended_at FROM incidents
             WHERE monitor_id IN ($in) AND severity = 'down' AND (ended_at IS NULL OR ended_at >= ?)",
            array_merge($ids, [date('Y-m-d H:i:s', $from)])
        );
        foreach ($incs as $inc) {
            $mid = (int)$inc['monitor_id'];
            if (!isset($out[$mid])) continue;
            $s = max(strtotime((string)$inc['started_at']), $from);
            $e = min($inc['ended_at'] ? strtotime((string)$inc['ended_at']) : $to, $to);
            for ($i = 0; $i < $nb && $e > $s; $i++) {
                $bs = $slots[$i]; $be = $bs + $step;
                $ov = min($e, $be) - max($s, $bs);
                if ($ov > 0) $out[$mid][$i]['down_sec'] += $ov;
            }
        }

        foreach ($out as $mid => $b) {
            foreach ($b as $i => $slot) {
                if ($slot['n'] > 0) {
                    $out[$mid][$i]['avg_ms'] = (int)round($sum[$mid][$i] / max(1, $slot['n']));
                }
                $out[$mid][$i]['state'] = ($slot['fails'] > 0 || $slot['down_sec'] > 0) ? 'down'
                    : ($slot['degraded'] > 0 ? 'degraded' : ($slot['n'] > 0 ? 'up' : 'none'));
            }
        }
        return $out;
    }

    /** Met à jour les agrégats dénormalisés d'une sonde. */
    public static function refresh(int $monitorId): void
    {
        $mon = Db::one('SELECT id, created_at FROM monitors WHERE id = ?', [$monitorId]);
        if (!$mon) return;
        $d1  = self::window($monitorId, 86400, $mon);
        $d7  = self::window($monitorId, 7 * 86400, $mon);
        $d30 = self::window($monitorId, 30 * 86400, $mon);
        Db::update('monitors', [
            'uptime_24h' => $d1['uptime'],
            'uptime_7d'  => $d7['uptime'],
            'uptime_30d' => $d30['uptime'],
            'avg_ms_24h' => $d1['avg_ms'],
            'stats_at'   => now(),
        ], 'id = :__i', ['__i' => $monitorId]);
    }

    /** Rafraîchit les sondes dont les agrégats sont périmés. */
    public static function refreshStale(int $olderThanSec = 300, int $limit = 60): int
    {
        $rows = Db::all(
            'SELECT id FROM monitors WHERE stats_at IS NULL OR stats_at < ? ORDER BY (stats_at IS NULL) DESC, stats_at ASC LIMIT ' . max(1, $limit),
            [date('Y-m-d H:i:s', time() - $olderThanSec)]
        );
        foreach ($rows as $r) self::refresh((int)$r['id']);
        return count($rows);
    }

    /** Synthèse globale pour l'en-tête du tableau de bord. */
    public static function summary(): array
    {
        $rows = Db::all("SELECT status, COUNT(*) AS n FROM monitors WHERE enabled = 1 GROUP BY status");
        $out = ['down' => 0, 'degraded' => 0, 'up' => 0, 'unknown' => 0, 'paused' => 0, 'total' => 0];
        foreach ($rows as $r) {
            $s = (string)$r['status'];
            if (!isset($out[$s])) $s = 'unknown';
            $out[$s] += (int)$r['n'];
            $out['total'] += (int)$r['n'];
        }
        $out['paused'] += (int)Db::val('SELECT COUNT(*) FROM monitors WHERE enabled = 0', [], 0);
        $out['sites']   = (int)Db::val('SELECT COUNT(*) FROM sites', [], 0);
        $out['open_incidents'] = (int)Db::val('SELECT COUNT(*) FROM incidents WHERE ended_at IS NULL', [], 0);
        $out['incidents_24h']  = (int)Db::val('SELECT COUNT(*) FROM incidents WHERE started_at >= ?',
            [date('Y-m-d H:i:s', time() - 86400)], 0);

        $avg = Db::val('SELECT AVG(uptime_24h) FROM monitors WHERE enabled = 1 AND uptime_24h IS NOT NULL');
        $out['uptime_24h'] = $avg === null ? null : round((float)$avg, 3);
        $ms = Db::val('SELECT AVG(avg_ms_24h) FROM monitors WHERE enabled = 1 AND avg_ms_24h IS NOT NULL');
        $out['avg_ms'] = $ms === null ? null : (int)round((float)$ms);
        $out['last_run_at'] = Db::setting('last_run_at');
        return $out;
    }

    /** Consolidation quotidienne (allège les requêtes longues portées). */
    public static function rollup(?string $day = null): int
    {
        $day  = $day ?: date('Y-m-d', time() - 3600);
        $from = $day . ' 00:00:00';
        $to   = $day . ' 23:59:59';
        $rows = Db::all(
            'SELECT monitor_id, COUNT(*) AS checks,
                    SUM(CASE WHEN state = ? THEN 1 ELSE 0 END) AS fails,
                    SUM(CASE WHEN state = ? THEN 1 ELSE 0 END) AS degraded,
                    AVG(total_ms) AS avg_ms, MIN(total_ms) AS min_ms, MAX(total_ms) AS max_ms
             FROM checks WHERE ts BETWEEN ? AND ? GROUP BY monitor_id',
            ['down', 'degraded', $from, $to]
        );
        $n = 0;
        foreach ($rows as $r) {
            $mid = (int)$r['monitor_id'];
            $downtime = 0;
            $incs = Db::all(
                'SELECT started_at, ended_at FROM incidents
                 WHERE monitor_id = ? AND severity = ? AND started_at <= ? AND (ended_at IS NULL OR ended_at >= ?)',
                [$mid, 'down', $to, $from]
            );
            $fromTs = strtotime($from); $toTs = strtotime($to);
            foreach ($incs as $inc) {
                $s = max(strtotime((string)$inc['started_at']), $fromTs);
                $e = min($inc['ended_at'] ? strtotime((string)$inc['ended_at']) : time(), $toTs);
                if ($e > $s) $downtime += ($e - $s);
            }
            $p95 = self::percentile($mid, $from, 95);
            $data = [
                'checks' => (int)$r['checks'], 'fails' => (int)$r['fails'], 'degraded' => (int)$r['degraded'],
                'downtime_sec' => $downtime,
                'avg_ms' => $r['avg_ms'] !== null ? round((float)$r['avg_ms'], 1) : null,
                'p95_ms' => $p95,
                'min_ms' => $r['min_ms'] !== null ? (int)$r['min_ms'] : null,
                'max_ms' => $r['max_ms'] !== null ? (int)$r['max_ms'] : null,
            ];
            $exists = Db::val('SELECT 1 FROM daily_stats WHERE monitor_id = ? AND day = ?', [$mid, $day]);
            if ($exists) Db::update('daily_stats', $data, 'monitor_id = :__m AND day = :__d', ['__m' => $mid, '__d' => $day]);
            else Db::insert('daily_stats', $data + ['monitor_id' => $mid, 'day' => $day]);
            $n++;
        }
        return $n;
    }

    /** Purge des vérifications unitaires trop anciennes. */
    public static function purge(?int $days = null): int
    {
        $days = $days ?? (int)Config::get('defaults.retention_days', 60);
        if ($days <= 0) return 0;
        $cut = date('Y-m-d H:i:s', time() - $days * 86400);
        $st  = Db::q('DELETE FROM checks WHERE ts < ?', [$cut]);
        Db::q('DELETE FROM notifications WHERE ts < ?', [$cut]);
        Db::q('DELETE FROM events WHERE ts < ?', [$cut]);
        Db::compact();
        return $st->rowCount();
    }

    /** Classement des sondes les plus fragiles sur 30 jours. */
    public static function worst(int $limit = 5): array
    {
        return Db::all(
            'SELECT m.id, m.name, m.url, m.uptime_30d, m.status,
                    (SELECT COUNT(*) FROM incidents i WHERE i.monitor_id = m.id AND i.started_at >= ?) AS inc30
             FROM monitors m WHERE m.enabled = 1 AND m.uptime_30d IS NOT NULL
             ORDER BY m.uptime_30d ASC, inc30 DESC LIMIT ' . max(1, $limit),
            [date('Y-m-d H:i:s', time() - 30 * 86400)]
        );
    }
}
