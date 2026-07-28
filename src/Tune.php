<?php
declare(strict_types=1);

namespace Uptimer;

/**
 * Réglage automatique et journal des décisions.
 *
 * Principe : un seuil ne doit jamais être un chiffre rond choisi au hasard. Le
 * seuil de lenteur d'un site est déduit de ce que ce site fait réellement, et
 * chaque décision prise par Uptimer est écrite en clair pour être relisible : * personne ne doit avoir à deviner pourquoi une sonde est réglée comme elle l'est.
 */
final class Tune
{
    /** Nombre de mesures avant d'oser calculer un seuil. */
    public const MIN_SAMPLES = 20;
    /** Intervalle minimum entre deux réajustements. */
    public const COOLDOWN_SEC = 6 * 3600;
    public const SLOW_FLOOR_MS = 1200;
    public const SLOW_CEIL_MS  = 20000;

    /** Marge appliquée au p95 : au-delà, le visiteur ressent la lenteur. */
    public const SLOW_FACTOR = 1.8;

    /**
     * Recalcule le seuil de lenteur d'une sonde d'après ses propres mesures.
     * @return array{changed:bool,from:int,to:int,why:string}|null
     */
    public static function slowThreshold(array $mon): ?array
    {
        $id = (int)$mon['id'];
        if ((int)($mon['auto_slow'] ?? 1) !== 1) return null;
        if (!empty($mon['tuned_at']) && strtotime((string)$mon['tuned_at']) > time() - self::COOLDOWN_SEC) return null;

        $from = date('Y-m-d H:i:s', time() - 7 * 86400);
        $n = (int)Db::val('SELECT COUNT(*) FROM checks WHERE monitor_id = ? AND ts >= ? AND total_ms IS NOT NULL',
            [$id, $from], 0);
        if ($n < self::MIN_SAMPLES) return null;

        $p95 = Stats::percentile($id, $from, 95);
        if ($p95 === null || $p95 <= 0) return null;

        $target  = (int)round($p95 * self::SLOW_FACTOR / 100) * 100;
        $target  = (int)max(self::SLOW_FLOOR_MS, min(self::SLOW_CEIL_MS, $target));
        $current = (int)$mon['slow_ms'];

        // On ne bouge que si l'écart est significatif : sinon le réglage oscille.
        if ($current > 0 && abs($target - $current) < max(300, $current * 0.2)) {
            Db::update('monitors', ['tuned_at' => now()], 'id = :__i', ['__i' => $id]);
            return null;
        }

        $why = sprintf('p95 mesuré à %s sur %d mesures ; seuil placé %.0f × au-dessus',
            Ui::ms($p95), $n, self::SLOW_FACTOR);
        Db::update('monitors', ['slow_ms' => $target, 'tuned_at' => now()], 'id = :__i', ['__i' => $id]);
        self::note($id, 'Seuil de lenteur réglé à ' . Ui::ms($target), $why);

        return ['changed' => true, 'from' => $current, 'to' => $target, 'why' => $why];
    }

    /**
     * Cadence proposée selon l'importance réelle de la page.
     * Une page d'accueil mérite une minute, des mentions légales non.
     */
    public static function intervalFor(string $url, int $base, ?string $family = null, bool $primary = false): int
    {
        $path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?: '/'));
        if ($primary || $path === '/' || $path === '') return $base;

        return match (true) {
            $family === 'legal' || (bool)preg_match('~(mentions|cgv|cgu|privacy|confidentialite|politique)~', $path)
                => min(86400, $base * 4),
            $family === 'contenu' || (bool)preg_match('~/(blog|actualites?|news|articles?)/~', $path)
                => min(86400, $base * 2),
            $family === 'contact' || $family === 'offre' || $family === 'tarifs'
                => $base,
            default => min(86400, (int)round($base * 1.5)),
        };
    }

    /** Ajoute une décision au journal de la sonde (les 12 dernières sont conservées). */
    public static function note(int $monitorId, string $what, string $why): void
    {
        $mon = Db::one('SELECT decisions FROM monitors WHERE id = ?', [$monitorId]);
        if (!$mon) return;
        $list = jdec($mon['decisions'] ?? null);
        $list[] = ['at' => now(), 'what' => str_cut($what, 160), 'why' => str_cut($why, 240)];
        if (count($list) > 12) $list = array_slice($list, -12);
        Db::update('monitors', ['decisions' => jenc($list)], 'id = :__i', ['__i' => $monitorId]);
    }

    /** Décisions d'une sonde, de la plus récente à la plus ancienne. */
    public static function decisions(array $mon): array
    {
        $list = jdec($mon['decisions'] ?? null);
        return array_reverse($list);
    }

    /**
     * Traite les sondes dont le seuil mérite un réajustement.
     * Appelé par le cron, quelques sondes à la fois.
     */
    public static function run(int $limit = 8): int
    {
        $rows = Db::all(
            "SELECT * FROM monitors
             WHERE enabled = 1 AND auto_slow = 1
               AND (tuned_at IS NULL OR tuned_at < ?)
             ORDER BY (tuned_at IS NULL) DESC, tuned_at ASC LIMIT " . max(1, $limit),
            [date('Y-m-d H:i:s', time() - self::COOLDOWN_SEC)]
        );
        $n = 0;
        foreach ($rows as $m) if (self::slowThreshold($m)) $n++;
        return $n;
    }
}
