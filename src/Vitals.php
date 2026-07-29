<?php
declare(strict_types=1);

namespace Uptimer;

/**
 * Core Web Vitals mesurés sur de vrais visiteurs.
 *
 * Les trois mesures officielles (LCP, INP, CLS) ne se déduisent pas du HTML :
 * elles viennent de navigateurs Chrome réels, agrégés par Google dans le Chrome
 * UX Report. Il n'existe pas d'autre source de terrain, et il n'existe pas de
 * calcul honnête qui les remplace. Deux conséquences assumées :
 *
 *   1. **Cette partie demande une clé d'API.** Elle est gratuite, elle se crée
 *      en deux minutes, et elle reste optionnelle : sans clé, Uptimer n'affiche
 *      aucun LCP plutôt qu'un LCP inventé. Ce qu'il continue de donner sans
 *      clé, c'est le TTFB mesuré et les causes lues dans la page, ce qui est
 *      déjà ce dont on a besoin pour agir (voir Check\Vitals).
 *   2. **Une page sans trafic n'a pas de données.** Le Chrome UX Report exige
 *      un échantillon suffisant. Dans ce cas, l'origine du site est interrogée
 *      à la place, et l'écran dit laquelle des deux a répondu. Un « pas assez
 *      de trafic pour être mesuré » est une réponse, pas un échec.
 *
 * Coût : une interrogation par page et par jour, mise en cache 24 heures. Le
 * quota gratuit du service est très au-dessus de ce qu'un parc d'agence
 * consomme.
 */
final class Vitals
{
    private const API = 'https://chromeuxreport.googleapis.com/v1/records:queryRecord';

    /** Une mesure de terrain ne bouge pas dans la journée : inutile d'insister. */
    private const CACHE_HOURS = 24;

    /** Pages interrogées par passe d'entretien. */
    private const PER_PASS = 30;

    /**
     * Seuils officiels (web.dev). Le premier est la limite du « bon », le
     * second celle du « à améliorer » : au-delà, c'est mauvais.
     */
    public const THRESHOLDS = [
        'lcp' => [2500, 4000],
        'inp' => [200, 500],
        'cls' => [0.1, 0.25],
    ];

    public static function enabled(): bool
    {
        return (bool)Config::get('vitals.enabled', true) && self::key() !== '';
    }

    public static function key(): string
    {
        return trim((string)Config::get('vitals.crux_key', ''));
    }

    /** phone ou desktop : ce que la plupart des visiteurs utilisent. */
    public static function formFactor(): string
    {
        $f = strtoupper(trim((string)Config::get('vitals.form_factor', 'PHONE')));
        return in_array($f, ['PHONE', 'DESKTOP', 'TABLET'], true) ? $f : 'PHONE';
    }

    // =====================================================================
    // Interrogation
    // =====================================================================
    /**
     * Mesures de terrain pour une adresse.
     *
     * L'URL exacte est demandée d'abord : c'est celle qui intéresse. Quand elle
     * n'a pas assez de trafic, on retombe sur l'origine, et on le dit.
     *
     * @return array{lcp_ms:?int,inp_ms:?int,cls:?float,ttfb_ms:?int,verdict:string,source:string,form_factor:string}|null
     */
    public static function fetch(string $url, ?string $formFactor = null): ?array
    {
        $key = self::key();
        if ($key === '') return null;
        $ff  = strtoupper($formFactor ?? self::formFactor());
        $origin = self::originOf($url);

        foreach ([['url' => $url, 'source' => 'url'], ['origin' => $origin, 'source' => 'origin']] as $attempt) {
            $source = $attempt['source'];
            unset($attempt['source']);
            if (($attempt['origin'] ?? null) === '') continue;
            $body = jenc($attempt + ['formFactor' => $ff]);
            $res = Http::fetch(self::API . '?key=' . rawurlencode($key), [
                'method'  => 'POST',
                'body'    => $body,
                'headers' => ['Content-Type: application/json'],
                'timeout' => (int)Config::get('vitals.timeout_sec', 10),
            ]);
            // 404 : pas assez de trafic pour cette adresse. On essaie l'origine.
            if ($res->status === 404) continue;
            if ($res->status !== 200) return null;
            $parsed = self::parse($res->body);
            if ($parsed === null) continue;
            $parsed['source'] = $source;
            $parsed['form_factor'] = $ff;
            return $parsed;
        }
        return null;
    }

    /**
     * Lit une réponse du Chrome UX Report.
     *
     * Chaque métrique absente reste nulle : une valeur manquante ne devient
     * jamais un zéro, qui se lirait comme un score parfait.
     */
    public static function parse(string $json): ?array
    {
        $d = jdec($json);
        $m = $d['record']['metrics'] ?? null;
        if (!is_array($m)) return null;
        $p75 = static function (array $m, string $key): ?float {
            $v = $m[$key]['percentiles']['p75'] ?? null;
            return is_numeric($v) ? (float)$v : null;
        };
        $lcp = $p75($m, 'largest_contentful_paint');
        $inp = $p75($m, 'interaction_to_next_paint');
        $cls = $p75($m, 'cumulative_layout_shift');
        $ttfb = $p75($m, 'experimental_time_to_first_byte');
        if ($lcp === null && $inp === null && $cls === null) return null;
        $out = [
            'lcp_ms'  => $lcp !== null ? (int)round($lcp) : null,
            'inp_ms'  => $inp !== null ? (int)round($inp) : null,
            'cls'     => $cls !== null ? round($cls, 3) : null,
            'ttfb_ms' => $ttfb !== null ? (int)round($ttfb) : null,
            'source'  => 'url',
            'form_factor' => 'PHONE',
        ];
        $out['verdict'] = self::verdict($out);
        return $out;
    }

    /**
     * Verdict d'ensemble : le plus mauvais des trois décide.
     *
     * C'est la règle de Google, et c'est aussi la seule honnête : une page dont
     * le CLS est catastrophique n'est pas « globalement bonne » parce que son
     * LCP va bien.
     */
    public static function verdict(array $m): string
    {
        $worst = 'good';
        foreach (['lcp' => $m['lcp_ms'] ?? null, 'inp' => $m['inp_ms'] ?? null,
                  'cls' => $m['cls'] ?? null] as $name => $value) {
            if ($value === null) continue;
            $v = self::rate($name, (float)$value);
            if ($v === 'poor') return 'poor';
            if ($v === 'improve') $worst = 'improve';
        }
        return $worst;
    }

    /** good, improve ou poor pour une métrique et une valeur. */
    public static function rate(string $metric, float $value): string
    {
        [$good, $poor] = self::THRESHOLDS[$metric] ?? [0, 0];
        if ($good === 0) return 'unknown';
        if ($value <= $good) return 'good';
        return $value <= $poor ? 'improve' : 'poor';
    }

    // =====================================================================
    // Entretien
    // =====================================================================
    /**
     * Rafraîchit les mesures de terrain des sondes qui le méritent.
     * Appelée par l'entretien quotidien.
     *
     * @return array{checked:int,measured:int,poor:int}
     */
    public static function refresh(int $limit = self::PER_PASS): array
    {
        $out = ['checked' => 0, 'measured' => 0, 'poor' => 0];
        if (!self::enabled()) return $out;
        $cut = date('Y-m-d H:i:s', time() - self::CACHE_HOURS * 3600);
        $rows = Db::all("SELECT id, url FROM monitors
                         WHERE enabled = 1 AND kind = 'page'
                           AND (field_at IS NULL OR field_at < ?)
                         ORDER BY role = 'primary' DESC, field_at IS NOT NULL, id ASC
                         LIMIT ?", [$cut, max(1, $limit)]);
        foreach ($rows as $m) {
            $out['checked']++;
            $f = self::fetch((string)$m['url']);
            // L'horodatage est écrit même sans données : sans cela, une page sans
            // trafic serait réinterrogée à chaque passe, tous les jours.
            $upd = ['field_at' => now()];
            if ($f !== null) {
                $upd += [
                    'field_lcp_ms' => $f['lcp_ms'], 'field_inp_ms' => $f['inp_ms'],
                    'field_cls' => $f['cls'], 'field_verdict' => $f['verdict'],
                    'field_source' => $f['source'],
                ];
                $out['measured']++;
                if ($f['verdict'] === 'poor') $out['poor']++;
            } else {
                $upd += ['field_verdict' => null, 'field_source' => null];
            }
            Db::update('monitors', $upd, 'id = :__i', ['__i' => (int)$m['id']]);
        }
        return $out;
    }

    /**
     * Ce qu'il y a à signaler, pour la liste de tâches.
     *
     * Une seule entrée par sonde, sur la métrique la plus mauvaise, avec la
     * cause locale la plus probable quand elle a été trouvée. Un chiffre sans
     * cause n'aiderait personne à agir.
     */
    public static function findings(int $limit = 6): array
    {
        $rows = Db::all("SELECT m.id, m.name, m.url, m.field_lcp_ms, m.field_inp_ms, m.field_cls,
                                m.field_verdict, m.field_source, m.vitals_detail, s.name AS site_name
                         FROM monitors m LEFT JOIN sites s ON s.id = m.site_id
                         WHERE m.enabled = 1 AND m.field_verdict = 'poor'
                         ORDER BY m.field_lcp_ms DESC
                         LIMIT ?", [max(1, min(50, $limit))]);
        $out = [];
        foreach ($rows as $m) {
            [$metric, $value] = self::worstOf($m);
            if ($metric === null) continue;
            $detail = jdec($m['vitals_detail'] ?? null);
            $cause  = $detail['findings'][0] ?? null;
            $out[] = [
                'kind' => 'vitals',
                'id' => (int)$m['id'],
                'icon' => 'chart',
                'urgency' => 'info',
                'days' => 0,
                'title' => t('{name} : {metric} à {value} sur le terrain', [
                    'name' => (string)($m['site_name'] ?: $m['name']),
                    'metric' => self::metricLabel($metric),
                    'value' => self::format($metric, $value),
                ]),
                'why' => $cause !== null
                    ? t((string)$cause['what'], (array)($cause['vars'] ?? [])) . ' ' . t((string)$cause['fix'])
                    : t('Mesuré sur les visiteurs réels de cette page. La cause n\'a pas été trouvée dans le HTML : elle est probablement côté serveur ou dans un script tiers.'),
                'metric' => $metric,
            ];
        }
        return $out;
    }

    /** @return array{0:?string,1:float} La métrique la plus mauvaise, et sa valeur. */
    public static function worstOf(array $m): array
    {
        $best = [null, 0.0]; $rank = -1;
        foreach (['lcp' => $m['field_lcp_ms'] ?? null, 'inp' => $m['field_inp_ms'] ?? null,
                  'cls' => $m['field_cls'] ?? null] as $name => $value) {
            if ($value === null) continue;
            $r = self::rate($name, (float)$value);
            $score = $r === 'poor' ? 2 : ($r === 'improve' ? 1 : 0);
            if ($score > $rank) { $rank = $score; $best = [$name, (float)$value]; }
        }
        return $best;
    }

    public static function metricLabel(string $metric): string
    {
        return match ($metric) {
            'lcp' => t('affichage du contenu principal'),
            'inp' => t('réaction au premier clic'),
            'cls' => t('stabilité de la mise en page'),
            default => $metric,
        };
    }

    /** Une valeur lisible : des secondes pour un temps, un nombre pour le CLS. */
    public static function format(string $metric, ?float $value): string
    {
        if ($value === null) return '—';
        if ($metric === 'cls') return Ui::num($value, 2);
        return $value >= 1000
            ? Ui::num($value / 1000, 1) . ' s'
            : (string)(int)round($value) . ' ms';
    }

    /** Compte ce qui est mesuré et ce qui va mal, pour les écrans de synthèse. */
    public static function counts(): array
    {
        return [
            'measured' => (int)Db::val("SELECT COUNT(*) FROM monitors WHERE field_verdict IS NOT NULL"),
            'poor'     => (int)Db::val("SELECT COUNT(*) FROM monitors WHERE field_verdict = 'poor'"),
            'improve'  => (int)Db::val("SELECT COUNT(*) FROM monitors WHERE field_verdict = 'improve'"),
            'local'    => (int)Db::val("SELECT COUNT(*) FROM monitors WHERE vitals_level IN ('bad','watch')"),
        ];
    }

    private static function originOf(string $url): string
    {
        $p = parse_url($url);
        if (!isset($p['scheme'], $p['host'])) return '';
        return $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    }
}
