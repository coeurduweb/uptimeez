<?php
declare(strict_types=1);

namespace Uptimeez;

/**
 * Moteur de triage : transforme un parc de sondes en liste de choses à faire.
 *
 * C'est le cœur du parti pris de UptimeEZ. Les outils du marché affichent des états
 * et laissent l'interprétation à l'utilisateur ; ici on répond à trois questions,
 * dans cet ordre :
 *
 *   1. Qu'est-ce qui demande une action MAINTENANT ?
 *   2. Qu'est-ce qui va casser bientôt, et que je peux éviter ?
 *   3. Le reste va bien : une ligne suffit.
 *
 * Chaque élément retourné porte sa cause, son impact, la conduite à tenir et la
 * liste des actions réalisables sur place. Rien ne renvoie vers un écran de
 * réglages.
 */
final class Triage
{
    /** Seuils des alertes anticipées. */
    public const SSL_SOON_DAYS    = 30;
    public const DOMAIN_SOON_DAYS = 45;
    public const SLOWDOWN_PCT     = 50;    // ralentissement durable à signaler
    public const SLOWDOWN_MIN_MS  = 400;   // en deçà, une hausse relative n'a pas d'effet perçu

    /**
     * Éléments à traiter maintenant, du plus grave au moins grave.
     * Une seule entrée par site : trois pages d'un même site en panne, c'est un
     * site en panne.
     *
     * @return array<int,array>
     */
    public static function actions(): array
    {
        $rows = Db::all(
            "SELECT m.*, s.name AS site_name, s.domain AS site_domain, s.cms AS site_cms,
                    s.group_name,
                    (SELECT COUNT(*) FROM monitors x WHERE x.site_id = m.site_id
                       AND x.status IN ('down','degraded') AND x.enabled = 1) AS site_bad,
                    (SELECT i.started_at FROM incidents i WHERE i.monitor_id = m.id
                       AND i.ended_at IS NULL ORDER BY i.id DESC LIMIT 1) AS inc_started,
                    (SELECT i.id FROM incidents i WHERE i.monitor_id = m.id
                       AND i.ended_at IS NULL ORDER BY i.id DESC LIMIT 1) AS inc_id,
                    (SELECT i.checks_failed FROM incidents i WHERE i.monitor_id = m.id
                       AND i.ended_at IS NULL ORDER BY i.id DESC LIMIT 1) AS inc_fails,
                    (SELECT i.ack_at FROM incidents i WHERE i.monitor_id = m.id
                       AND i.ended_at IS NULL ORDER BY i.id DESC LIMIT 1) AS inc_ack
             FROM monitors m LEFT JOIN sites s ON s.id = m.site_id
             WHERE m.enabled = 1 AND m.status IN ('down', 'degraded')
             ORDER BY CASE m.status WHEN 'down' THEN 0 ELSE 1 END, m.role DESC, m.id ASC"
        );

        $out = []; $seenSite = [];
        foreach ($rows as $m) {
            $siteKey = $m['site_id'] ? 'S' . $m['site_id'] : 'M' . $m['id'];
            if (isset($seenSite[$siteKey])) { $out[$seenSite[$siteKey]]['also']++; continue; }

            $diag = Diagnose::explain((string)$m['reason_code'], $m);
            $item = [
                'kind'      => 'action',
                'severity'  => (string)$m['status'],
                'monitor'   => $m,
                'id'        => (int)$m['id'],
                'title'     => (string)($m['site_name'] ?: $m['name']),
                'subtitle'  => (string)($m['site_domain'] ?: host_of((string)$m['url'])),
                'cause'     => $diag['title'],
                'why'       => $diag['why'],
                'fix'       => $diag['fix'],
                'icon'      => $diag['icon'],
                'evidence'  => verdict_text($m, 240),
                'reason'    => (string)$m['reason_code'],
                'since'     => $m['inc_started'] ?: $m['status_since'],
                'fails'     => (int)($m['inc_fails'] ?? 0),
                'incident'  => $m['inc_id'] ? (int)$m['inc_id'] : null,
                'acked'     => !empty($m['inc_ack']),
                'also'      => max(0, (int)$m['site_bad'] - 1),
                'actions'   => self::actionsFor($m),
            ];
            $seenSite[$siteKey] = count($out);
            $out[] = $item;
        }
        return $out;
    }

    /** Boutons proposés pour une anomalie donnée : les plus utiles d'abord. */
    private static function actionsFor(array $m): array
    {
        $acts = [['check', t('Revérifier')], ['open', t('Ouvrir le site')]];
        switch ((string)$m['reason_code']) {
            case 'CSS_BROKEN':
            case 'CSS_DEGRADED':
                $acts[] = ['relearn', t('Réapprendre la référence')];
                break;
            case 'SLOW':
                $acts[] = ['raise_slow', t('Relever le seuil de lenteur')];
                break;
            case 'NOINDEX':
                $acts[] = ['ignore_noindex', t('Ne plus surveiller le noindex')];
                break;
            case 'HTTP_404':
            case 'HTTP_3XX':
                $acts[] = ['adopt_url', t('Adopter l\'URL actuelle')];
                break;
        }
        $acts[] = ['copy', t('Copier le rapport')];
        $acts[] = ['snooze', t('Mettre en pause 1 h')];
        return $acts;
    }

    /**
     * Ce qui va casser : on prévient avant l'incident.
     * @return array<int,array>
     */
    public static function upcoming(): array
    {
        $out = [];

        // --- Certificats et domaines qui arrivent à échéance ------------------
        $rows = Db::all(
            "SELECT m.id, m.name, m.url, m.ssl_days_left, m.ssl_expires_at, m.domain_expires_at,
                    s.name AS site_name
             FROM monitors m LEFT JOIN sites s ON s.id = m.site_id
             WHERE m.enabled = 1 AND m.status NOT IN ('down')
               AND (m.ssl_days_left IS NOT NULL OR m.domain_expires_at IS NOT NULL)"
        );
        foreach ($rows as $m) {
            $name = (string)($m['site_name'] ?: $m['name']);
            $d = $m['ssl_days_left'] !== null ? (int)$m['ssl_days_left'] : null;
            if ($d !== null && $d >= 0 && $d <= self::SSL_SOON_DAYS) {
                $out[] = [
                    'kind' => 'upcoming', 'id' => (int)$m['id'], 'icon' => 'lock',
                    'urgency' => $d <= 7 ? 'warn' : 'info', 'days' => $d,
                    'title' => I18n::n($d, 'Certificat SSL de {name} : 1 jour restant',
                                          'Certificat SSL de {name} : {n} jours restants', ['name' => $name]),
                    'why'   => $d <= 7
                        ? t('Passé cette date, tous les navigateurs afficheront un avertissement plein écran.')
                        : t('Le renouvellement automatique devrait s\'en occuper, vérifiez qu\'il fonctionne.'),
                ];
            }
            if (!empty($m['domain_expires_at'])) {
                $dd = (int)floor((strtotime((string)$m['domain_expires_at']) - time()) / 86400);
                if ($dd >= 0 && $dd <= self::DOMAIN_SOON_DAYS) {
                    $out[] = [
                        'kind' => 'upcoming', 'id' => (int)$m['id'], 'icon' => 'globe',
                        'urgency' => $dd <= 15 ? 'warn' : 'info', 'days' => $dd,
                        'title' => I18n::n($dd, 'Nom de domaine de {name} : expire dans 1 jour',
                                              'Nom de domaine de {name} : expire dans {n} jours', ['name' => $name]),
                        'why'   => t('Un domaine expiré coupe le site, les e-mails, et peut être racheté par un tiers.'),
                    ];
                }
            }
        }

        // --- Failles publiées sur les versions détectées ----------------------
        // Une faille ne casse pas le site : elle le mettra en danger si personne
        // n'intervient. Sa place est donc ici, pas dans ce qui est déjà arrivé.
        foreach (Vuln::findings(6) as $v) $out[] = $v;

        // --- Vitesse ressentie par les visiteurs -----------------------------
        // Une page lente n'est pas une panne, mais c'est ce que le client vit et
        // ce que Google classe. Chaque entrée porte la cause lue dans le HTML,
        // parce qu'un chiffre sans cause ne fait agir personne.
        foreach (Vitals::findings(4) as $v) $out[] = $v;

        // --- Ralentissement durable ------------------------------------------
        foreach (self::slowdowns() as $s) $out[] = $s;

        // --- Sondes jamais mesurées ------------------------------------------
        $never = (int)Db::val("SELECT COUNT(*) FROM monitors WHERE enabled = 1 AND last_check_at IS NULL", [], 0);
        if ($never > 0) {
            $out[] = [
                'kind' => 'upcoming', 'id' => 0, 'icon' => 'clock', 'urgency' => 'info', 'days' => 0,
                'title' => tn($never, 'Une sonde n\'a jamais été mesurée', '{n} sondes n\'ont jamais été mesurées'),
                'why'   => t('La première mesure a lieu à la prochaine passe. Si rien ne bouge, la tâche planifiée ne tourne pas.'),
            ];
        }

        // --- Préparation automatique inachevée -------------------------------
        $pending = (int)Db::val("SELECT COUNT(*) FROM monitors WHERE setup_state = 'pending'", [], 0);
        if ($pending > 0) {
            $out[] = [
                'kind' => 'upcoming', 'id' => 0, 'icon' => 'layers', 'urgency' => 'info', 'days' => 0,
                'title' => tn($pending, 'Un site en attente de préparation automatique',
                                        '{n} sites en attente de préparation automatique'),
                'why'   => t('Technologie, pages à suivre et chaîne de preuve seront déduits à la prochaine passe.'),
            ];
        }

        // --- Sondes sans chaîne de preuve ------------------------------------
        $noProof = Db::all("SELECT id, name FROM monitors
                            WHERE enabled = 1 AND (expect_string IS NULL OR expect_string = '')
                              AND kind = 'page' AND setup_state = 'done' LIMIT 5");
        if (count($noProof) > 0) {
            $out[] = [
                'kind' => 'upcoming', 'id' => (int)$noProof[0]['id'], 'icon' => 'eye', 'urgency' => 'info', 'days' => 0,
                'title' => tn(count($noProof), 'Une sonde sans chaîne de preuve', '{n} sondes sans chaîne de preuve'),
                'why'   => t('Sans elle, une page vide qui répond 200 passerait pour valide. {app} n\'a pas trouvé de texte assez identifiant : renseignez-le à la main sur la fiche.'),
            ];
        }

        usort($out, fn($a, $b) => [$a['urgency'] === 'info' ? 1 : 0, $a['days']]
                              <=> [$b['urgency'] === 'info' ? 1 : 0, $b['days']]);
        return $out;
    }

    /**
     * Détection d'un ralentissement installé : 24 h comparées aux 7 jours
     * précédents, sur les agrégats journaliers pour rester peu coûteux.
     */
    public static function slowdowns(int $limit = 6): array
    {
        $today = date('Y-m-d');
        $rows = Db::all(
            "SELECT m.id, m.name, m.slow_ms, s.name AS site_name,
                    (SELECT AVG(d.avg_ms) FROM daily_stats d
                       WHERE d.monitor_id = m.id AND d.day >= ? AND d.avg_ms IS NOT NULL) AS recent,
                    (SELECT AVG(d.avg_ms) FROM daily_stats d
                       WHERE d.monitor_id = m.id AND d.day < ? AND d.day >= ? AND d.avg_ms IS NOT NULL) AS earlier
             FROM monitors m LEFT JOIN sites s ON s.id = m.site_id
             WHERE m.enabled = 1 AND m.status <> 'down'",
            [date('Y-m-d', time() - 3 * 86400), date('Y-m-d', time() - 3 * 86400), date('Y-m-d', time() - 10 * 86400)]
        );
        $out = [];
        foreach ($rows as $m) {
            $recent = $m['recent'] !== null ? (float)$m['recent'] : null;
            $before = $m['earlier'] !== null ? (float)$m['earlier'] : null;
            if ($recent === null || $before === null || $before <= 0) continue;
            if ($recent < self::SLOWDOWN_MIN_MS) continue;
            $pct = (int)round(($recent / $before - 1) * 100);
            if ($pct < self::SLOWDOWN_PCT) continue;
            $out[] = [
                'kind' => 'upcoming', 'id' => (int)$m['id'], 'icon' => 'chart',
                'urgency' => $recent > (int)$m['slow_ms'] * 0.8 ? 'warn' : 'info', 'days' => 1,
                'title' => t('{name} ralentit : +{pct} % en trois jours',
                             ['name' => (string)($m['site_name'] ?: $m['name']), 'pct' => $pct]),
                'why'   => t('Passé de {before} à {after} en moyenne. Souvent un cache expiré, une base qui gonfle ou un voisin bruyant sur le mutualisé.',
                             ['before' => Ui::ms((int)round($before)), 'after' => Ui::ms((int)round($recent))]),
            ];
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    /** Synthèse de ce qui va bien, pour la ligne repliée. */
    public static function healthy(): array
    {
        $rows = Db::all(
            "SELECT m.id, m.name, m.status, m.last_ms, m.uptime_24h, s.name AS site_name, s.id AS sid
             FROM monitors m LEFT JOIN sites s ON s.id = m.site_id
             WHERE m.enabled = 1 AND m.status = 'up'
             ORDER BY m.role DESC, m.name ASC"
        );
        $sites = [];
        foreach ($rows as $m) {
            $key = $m['sid'] ? 'S' . $m['sid'] : 'M' . $m['id'];
            if (isset($sites[$key])) continue;
            $sites[$key] = [
                'id' => (int)$m['id'],
                'name' => (string)($m['site_name'] ?: $m['name']),
                'ms' => $m['last_ms'] !== null ? (int)$m['last_ms'] : null,
                'uptime' => $m['uptime_24h'] !== null ? (float)$m['uptime_24h'] : null,
            ];
        }
        return array_values($sites);
    }

    /** Rapport texte prêt à coller dans un ticket ou un e-mail client. */
    public static function report(int $monitorId): string
    {
        $m = Db::one('SELECT m.*, s.name AS site_name, s.cms AS site_cms
                      FROM monitors m LEFT JOIN sites s ON s.id = m.site_id WHERE m.id = ?', [$monitorId]);
        if (!$m) return '';

        $diag = Diagnose::explain((string)$m['reason_code'], $m);
        $w24  = Stats::window($monitorId, 86400, $m);
        $inc  = Db::one('SELECT * FROM incidents WHERE monitor_id = ? ORDER BY id DESC LIMIT 1', [$monitorId]);
        $css  = jdec($m['css_detail'] ?? null);

        $L = [];
        $L[] = '# ' . ($m['site_name'] ?: $m['name']) . ' : ' . Ui::statusLabel((string)$m['status']);
        $L[] = '';
        $L[] = t('URL surveillée : {url}', ['url' => $m['url']]);
        $L[] = t('Constat le {date} (fuseau {tz})',
                 ['date' => date('d/m/Y H:i'), 'tz' => date_default_timezone_get()]);
        if ($m['site_cms']) $L[] = t('Technologie : {cms}', ['cms' => $m['site_cms']]);
        $L[] = '';
        $L[] = '## ' . t('Diagnostic');
        $L[] = $diag['title'];
        $L[] = $diag['why'];
        if ($m['last_message']) { $L[] = ''; $L[] = t('Relevé technique : {msg}', ['msg' => verdict_text($m)]); }
        if (!empty($css['console'])) {
            $L[] = '';
            $L[] = t('Erreurs que le navigateur signale :');
            foreach (array_slice($css['console'], 0, 6) as $c) $L[] = '  ' . ($c['text'] ?? '');
        }
        $L[] = '';
        $L[] = '## ' . t('Conduite à tenir');
        $L[] = $diag['fix'];
        if ($inc) {
            $L[] = '';
            $L[] = '## ' . t('Chronologie');
            $L[] = t('Début : {date}', ['date' => date('d/m/Y H:i', strtotime((string)$inc['started_at']))]);
            $L[] = $inc['ended_at']
                ? t('Fin : {date} (durée {duration})', [
                    'date'     => date('d/m/Y H:i', strtotime((string)$inc['ended_at'])),
                    'duration' => human_duration((int)$inc['duration_sec'])])
                : t('En cours depuis {duration}', [
                    'duration' => human_duration(max(0, time() - strtotime((string)$inc['started_at'])))]);
            $L[] = t('Vérifications en échec : {n}', ['n' => (int)$inc['checks_failed']]);
        }
        $L[] = '';
        $L[] = '## ' . t('Disponibilité');
        $L[] = t('24 heures : {uptime} ({downtime} hors service)', [
            'uptime' => Ui::pct($w24['uptime']), 'downtime' => human_duration($w24['downtime_sec'])]);
        $L[] = t('Temps de réponse moyen : {avg} · p95 {p95}', [
            'avg' => Ui::ms($w24['avg_ms']), 'p95' => Ui::ms($w24['p95_ms'])]);
        $L[] = '';
        $L[] = t('Rapport produit par {app}');
        return implode("\n", $L);
    }

    /** Compteurs pour le bandeau. */
    public static function counts(): array
    {
        $s = Stats::summary();
        return [
            'down'     => (int)($s['down'] ?? 0),
            'degraded' => (int)($s['degraded'] ?? 0),
            'up'       => (int)($s['up'] ?? 0),
            'unknown'  => (int)($s['unknown'] ?? 0),
            'paused'   => (int)($s['paused'] ?? 0),
            'total'    => (int)($s['total'] ?? 0),
            'uptime'   => $s['uptime_24h'] ?? null,
            'avg_ms'   => $s['avg_ms'] ?? null,
            'last_run' => $s['last_run_at'] ?? null,
        ];
    }
}
