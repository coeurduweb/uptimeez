<?php
declare(strict_types=1);

namespace Uptimeez;

use Uptimeez\Notify\Mail;

/**
 * Rapport mensuel de disponibilité, envoyé tout seul.
 *
 * Ce que ça change. Une agence qui envoie chaque mois un état de disponibilité à
 * ses clients transforme une surveillance invisible en travail visible. C'est ce
 * qui permet de facturer la surveillance au lieu de l'offrir. Le rapport existe
 * déjà à l'écran : il manquait l'envoi, les destinataires par client, et la
 * garantie qu'il ne partira qu'une fois.
 *
 * Trois principes :
 *   1. **un client, ses sites** : chaque destinataire ne reçoit que ce qui le
 *      concerne, jamais le parc entier ;
 *   2. **une fois par mois, pas deux** : l'envoi est marqué par une clé de mois,
 *      donc un cron qui tourne toutes les minutes n'envoie rien de plus ;
 *   3. **rien d'automatique en silence** : l'écran des rapports montre qui reçoit
 *      quoi, quand le dernier envoi a eu lieu, et permet d'envoyer à la demande.
 */
final class Report
{
    /** Jour du mois par défaut pour l'envoi. Le 1er, sur le mois écoulé. */
    public const DEFAULT_DAY = 1;

    // =====================================================================
    // Programmation
    // =====================================================================
    /**
     * Sites dont le rapport est dû aujourd'hui.
     *
     * Dû signifie : l'envoi automatique est actif, des destinataires sont
     * renseignés, le jour du mois est atteint, et le rapport du mois visé n'est
     * pas déjà parti.
     *
     * @return array<int,array>
     */
    public static function dueSites(?string $today = null): array
    {
        if (!Config::get('report.enabled', false)) return [];

        $today = $today ?: date('Y-m-d');
        $day   = (int)Config::get('report.day', self::DEFAULT_DAY);
        $dom   = (int)date('j', strtotime($today));
        // Le dernier jour du mois rattrape une programmation au 29, 30 ou 31
        // dans un mois plus court : sinon février ne recevrait jamais rien.
        $last  = (int)date('t', strtotime($today));
        if ($dom < min($day, $last)) return [];

        $key = self::monthKey($today);
        $out = [];
        foreach (Db::all('SELECT * FROM sites ORDER BY name ASC') as $site) {
            if ((int)($site['report_enabled'] ?? 0) !== 1) continue;
            if (self::recipients($site) === []) continue;
            if ((string)($site['report_sent_key'] ?? '') === $key) continue;
            $out[] = $site;
        }
        return $out;
    }

    /** Clé du mois couvert par le rapport envoyé à cette date : le mois écoulé. */
    public static function monthKey(?string $today = null): string
    {
        $t = strtotime(($today ?: date('Y-m-d')) . ' first day of last month');
        return date('Y-m', $t ?: time());
    }

    /** Bornes du mois couvert : [début, fin]. */
    public static function monthRange(?string $today = null): array
    {
        $key = self::monthKey($today);
        $from = $key . '-01 00:00:00';
        $to   = date('Y-m-t 23:59:59', strtotime($from) ?: time());
        return [$from, $to];
    }

    /**
     * Destinataires d'un site : les siens, sinon ceux du client, sinon ceux des
     * alertes. Cette cascade évite de ressaisir la même adresse sur les huit
     * sites d'un même client.
     */
    public static function recipients(array $site): array
    {
        $raw = trim((string)($site['report_to'] ?? ''));
        if ($raw === '') $raw = Client::reportRecipients($site);
        if ($raw === '') $raw = trim((string)Config::get('report.fallback_to', ''));
        $out = [];
        foreach (preg_split('~[,;\s]+~', $raw) ?: [] as $mail) {
            $mail = trim($mail);
            if ($mail !== '' && filter_var($mail, FILTER_VALIDATE_EMAIL)) $out[$mail] = true;
        }
        return array_keys($out);
    }

    /**
     * Envoie les rapports dus. Appelée par l'entretien quotidien du cron.
     *
     * @return array{sent:int,failed:int,skipped:int,detail:array}
     */
    public static function runMonthly(?string $today = null): array
    {
        $res = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'detail' => []];
        foreach (self::dueSites($today) as $site) {
            $r = self::sendFor((int)$site['id'], $today);
            $res[$r['ok'] ? 'sent' : 'failed']++;
            $res['detail'][] = ['site' => $site['name'], 'ok' => $r['ok'], 'info' => $r['info']];
        }
        return $res;
    }

    /**
     * Compose et envoie le rapport d'un site.
     *
     * L'envoi est marqué avant d'être tenté ? Non : après, et seulement s'il a
     * réussi. Un serveur de mail momentanément indisponible doit laisser une
     * seconde chance le lendemain, pas faire sauter le mois.
     */
    public static function sendFor(int $siteId, ?string $today = null): array
    {
        $site = Db::one('SELECT * FROM sites WHERE id = ?', [$siteId]);
        if (!$site) return ['ok' => false, 'info' => 'Site inconnu'];

        $to = self::recipients($site);
        if (!$to) return ['ok' => false, 'info' => t('Aucun destinataire configuré')];

        [$from, $until] = self::monthRange($today);
        $data = self::data($siteId, $from, $until);
        if ($data['checks'] === 0) {
            return ['ok' => false, 'info' => t('Aucune mesure sur la période : rapport non envoyé.')];
        }

        $subject = self::subject($site, $from);
        $html    = self::html($site, $data, $from, $until);
        $text    = self::text($site, $data, $from, $until);

        $r = Mail::sendDocument($to, $subject, $html, $text);
        if ($r['ok']) {
            Db::update('sites', [
                'report_sent_key' => self::monthKey($today),
                'report_sent_at'  => now(),
            ], 'id = :__i', ['__i' => $siteId]);
        }
        return ['ok' => (bool)$r['ok'], 'info' => (string)$r['info'],
                'recipients' => count($to), 'subject' => $subject];
    }

    private static function subject(array $site, string $from): string
    {
        $tpl = trim((string)Config::get('report.subject', ''));
        if ($tpl === '') $tpl = t('Disponibilité de {site} : {month}');
        return str_replace(
            ['{site}', '{month}', '{app}'],
            [(string)$site['name'], self::monthLabel($from), I18n::APP],
            $tpl
        );
    }

    public static function monthLabel(string $from): string
    {
        static $months = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
                          'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
        $ts = strtotime($from) ?: time();
        return t($months[(int)date('n', $ts)]) . ' ' . date('Y', $ts);
    }

    // =====================================================================
    // Données
    // =====================================================================
    /**
     * Chiffres du mois pour un site. Une seule agrégation SQL par famille :
     * un rapport ne doit pas coûter plus qu'une page.
     */
    public static function data(int $siteId, string $from, string $until): array
    {
        $mons = Db::all('SELECT id, name, url, role FROM monitors WHERE site_id = ? ORDER BY role DESC, name ASC',
                        [$siteId]);
        $ids = array_map(fn($m) => (int)$m['id'], $mons);
        if (!$ids) return ['checks' => 0, 'monitors' => [], 'incidents' => [], 'days' => []];

        $in   = implode(',', array_fill(0, count($ids), '?'));
        $args = array_merge($ids, [substr($from, 0, 10), substr($until, 0, 10)]);

        // Les agrégats journaliers couvrent n'importe quel mois passé, même
        // au-delà de la fenêtre de conservation des mesures détaillées.
        $rows = Db::all("SELECT monitor_id, day, checks, fails, degraded, downtime_sec, avg_ms, p95_ms
                         FROM daily_stats WHERE monitor_id IN ($in) AND day >= ? AND day <= ?
                         ORDER BY day ASC", $args);

        $perMon = []; $days = []; $checks = 0; $up = 0; $downSec = 0; $msSum = 0; $msN = 0; $worstP95 = 0;
        foreach ($rows as $r) {
            $mid = (int)$r['monitor_id'];
            // La table compte les échecs, pas les succès : on en déduit les seconds.
            $okChecks = max(0, (int)$r['checks'] - (int)$r['fails']);
            $perMon[$mid]['checks'] = ($perMon[$mid]['checks'] ?? 0) + (int)$r['checks'];
            $perMon[$mid]['up']     = ($perMon[$mid]['up'] ?? 0) + $okChecks;
            $perMon[$mid]['down']   = ($perMon[$mid]['down'] ?? 0) + (int)$r['downtime_sec'];
            if ($r['avg_ms'] !== null) {
                $perMon[$mid]['ms'][] = (int)$r['avg_ms'];
                $msSum += (int)$r['avg_ms'] * max(1, (int)$r['checks']);
                $msN   += max(1, (int)$r['checks']);
            }
            $worstP95 = max($worstP95, (int)$r['p95_ms']);
            $checks  += (int)$r['checks'];
            $up      += $okChecks;
            $downSec += (int)$r['downtime_sec'];

            $d = (string)$r['day'];
            $days[$d]['down'] = ($days[$d]['down'] ?? 0) + (int)$r['downtime_sec'];
            $days[$d]['deg']  = ($days[$d]['deg'] ?? 0) + (int)$r['degraded'];
        }

        $incidents = Db::all("SELECT i.*, m.name FROM incidents i JOIN monitors m ON m.id = i.monitor_id
                              WHERE i.monitor_id IN ($in) AND i.started_at >= ? AND i.started_at <= ?
                                AND i.severity = 'down'
                              ORDER BY i.started_at ASC LIMIT 60",
                             array_merge($ids, [$from, $until]));

        $list = [];
        foreach ($mons as $m) {
            $mid = (int)$m['id'];
            $c   = $perMon[$mid] ?? [];
            $ck  = (int)($c['checks'] ?? 0);
            $list[] = [
                'id' => $mid, 'name' => $m['name'], 'url' => $m['url'],
                'primary' => ($m['role'] ?? '') === 'primary',
                'uptime' => $ck > 0 ? round(((int)($c['up'] ?? 0) / $ck) * 100, 2) : null,
                'down_sec' => (int)($c['down'] ?? 0),
                'avg_ms' => !empty($c['ms']) ? (int)round(array_sum($c['ms']) / count($c['ms'])) : null,
            ];
        }

        ksort($days);
        return [
            'checks'    => $checks,
            'uptime'    => $checks > 0 ? round(($up / $checks) * 100, 2) : null,
            'down_sec'  => $downSec,
            'avg_ms'    => $msN > 0 ? (int)round($msSum / $msN) : null,
            'p95_ms'    => $worstP95 ?: null,
            'monitors'  => $list,
            'incidents' => $incidents,
            'days'      => $days,
        ];
    }

    // =====================================================================
    // Mise en forme
    // =====================================================================
    /**
     * Corps HTML de l'e-mail.
     *
     * Contraintes propres au courrier : tableaux et styles en ligne, aucune
     * feuille externe, aucune image distante, aucun SVG (Gmail ne le rend pas).
     * La silhouette reste sur le rapport en ligne, l'e-mail dit son écart et
     * renvoie vers la page.
     */
    public static function html(array $site, array $data, string $from, string $until): string
    {
        $month = self::monthLabel($from);
        $up    = $data['uptime'] !== null ? Ui::pct((float)$data['uptime']) : '—';
        $tone  = ($data['uptime'] ?? 100) >= 99.9 ? '#0d8f56' : (($data['uptime'] ?? 0) >= 99 ? '#b26a00' : '#ce2233');
        $link  = self::onlineLink($site);

        $h = '<div style="font:15px/1.55 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1f2430;'
           . 'max-width:640px;margin:0 auto;padding:8px">';
        $h .= '<h1 style="font-size:20px;margin:0 0 2px">' . e((string)$site['name']) . '</h1>';
        $h .= '<p style="margin:0 0 18px;color:#5b6472;font-size:14px">'
            . e(t('Rapport de disponibilité')) . ' · ' . e($month) . '</p>';

        // Le chiffre qui compte, tout de suite.
        $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"'
            . ' style="border:1px solid #e4e8ee;border-radius:8px;margin:0 0 18px"><tr>'
            . '<td style="padding:16px 18px">'
            . '<div style="font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:#5b6472">'
            . e(t('Disponibilité')) . '</div>'
            . '<div style="font-size:30px;font-weight:700;color:' . $tone . '">' . e($up) . '</div>'
            . '<div style="font-size:13px;color:#5b6472">'
            . e(t('{duration} hors service sur le mois', ['duration' => human_duration((int)$data['down_sec'])]))
            . '</div></td>'
            . '<td style="padding:16px 18px;border-left:1px solid #e4e8ee">'
            . '<div style="font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:#5b6472">'
            . e(t('Temps de réponse')) . '</div>'
            . '<div style="font-size:30px;font-weight:700">' . e(Ui::ms($data['avg_ms'])) . '</div>'
            . '<div style="font-size:13px;color:#5b6472">p95 ' . e(Ui::ms($data['p95_ms'])) . '</div>'
            . '</td></tr></table>';

        // Une bande de jours, en cellules de tableau : ça passe partout.
        if ($data['days']) {
            $h .= '<div style="font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:#5b6472;'
                . 'margin:0 0 6px">' . e(t('Jour par jour')) . '</div>';
            $h .= '<table role="presentation" cellpadding="0" cellspacing="2"><tr>';
            foreach ($data['days'] as $day => $d) {
                $c = ((int)$d['down'] > 900) ? '#ce2233' : (((int)$d['down'] > 0 || (int)$d['deg'] > 0) ? '#e8a33d' : '#0d8f56');
                $h .= '<td title="' . e($day) . '" style="width:10px;height:22px;background:' . $c
                    . ';border-radius:2px">&nbsp;</td>';
            }
            $h .= '</tr></table>';
            $h .= '<p style="font-size:12px;color:#5b6472;margin:6px 0 18px">'
                . e(t('Vert : journée complète en ligne · orange : incident bref · rouge : interruption de plus de 15 minutes.'))
                . '</p>';
        }

        // Les pages suivies, pour que « le site » ne soit pas une boîte noire.
        $h .= '<div style="font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:#5b6472;'
            . 'margin:0 0 6px">' . e(t('Pages surveillées')) . '</div>';
        $h .= '<table role="presentation" width="100%" cellpadding="6" cellspacing="0"'
            . ' style="border-collapse:collapse;font-size:14px;margin:0 0 18px">';
        foreach ($data['monitors'] as $m) {
            $h .= '<tr style="border-top:1px solid #eef1f5">'
                . '<td>' . e((string)$m['name']) . '</td>'
                . '<td align="right" style="white-space:nowrap">'
                . ($m['uptime'] !== null ? e(Ui::pct((float)$m['uptime'], 1)) : '—') . '</td>'
                . '<td align="right" style="white-space:nowrap;color:#5b6472">'
                . e(Ui::ms($m['avg_ms'])) . '</td></tr>';
        }
        $h .= '</table>';

        // Les interruptions, ou leur absence : c'est ce qu'un client cherche.
        $h .= '<div style="font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:#5b6472;'
            . 'margin:0 0 6px">' . e(t('Interruptions constatées')) . '</div>';
        if (!$data['incidents']) {
            $h .= '<p style="margin:0 0 18px;color:#0d8f56">' . e(t('Aucune interruption sur la période.')) . '</p>';
        } else {
            $h .= '<table role="presentation" width="100%" cellpadding="6" cellspacing="0"'
                . ' style="border-collapse:collapse;font-size:14px;margin:0 0 18px">';
            foreach (array_slice($data['incidents'], 0, 20) as $i) {
                $dur = $i['ended_at'] ? (int)$i['duration_sec']
                                      : max(0, time() - strtotime((string)$i['started_at']));
                $h .= '<tr style="border-top:1px solid #eef1f5">'
                    . '<td style="white-space:nowrap">' . e(date('d/m H:i', strtotime((string)$i['started_at']))) . '</td>'
                    . '<td>' . e((string)$i['name']) . '</td>'
                    . '<td>' . e(\Uptimeez\Notify\Notifier::reasonLabel($i['reason_code'] !== null ? (string)$i['reason_code'] : null)) . '</td>'
                    . '<td align="right" style="white-space:nowrap">' . e(human_duration($dur)) . '</td></tr>';
            }
            $h .= '</table>';
        }

        // Une dérive visuelle se signale, avec renvoi vers l'image en ligne.
        $drift = Db::all('SELECT name, silhouette_drift FROM monitors
                          WHERE site_id = ? AND silhouette_drift >= 20 ORDER BY silhouette_drift DESC LIMIT 3',
                         [(int)$site['id']]);
        if ($drift) {
            $h .= '<div style="border:1px solid #f0d9a8;background:#fdf8ec;border-radius:8px;padding:12px 14px;'
                . 'margin:0 0 18px;font-size:14px">';
            $h .= '<strong>' . e(t('Mise en page à vérifier')) . '</strong><br>';
            foreach ($drift as $d) {
                $h .= e((string)$d['name']) . ' : '
                    . e(t('{n} % de différence avec la référence', ['n' => (int)$d['silhouette_drift']])) . '<br>';
            }
            $h .= '<span style="color:#5b6472">' . e(t('La comparaison visuelle est sur le rapport en ligne.'))
                . '</span></div>';
        }

        if ($link !== null) {
            $h .= '<p style="margin:0 0 18px"><a href="' . e($link)
                . '" style="background:#3b5bdb;color:#fff;text-decoration:none;padding:10px 16px;'
                . 'border-radius:6px;display:inline-block">' . e(t('Voir le rapport détaillé')) . '</a></p>';
        }
        $h .= '<p style="font-size:12px;color:#8a93a1;border-top:1px solid #eef1f5;padding-top:12px;margin:0">'
            . e(t('Document produit le {date} par {app}, surveillance continue.', ['date' => date('d/m/Y H:i')]))
            . ' ' . e(t('Les mesures sont effectuées automatiquement, sans intervention humaine.')) . '</p>';
        return $h . '</div>';
    }

    /** Version texte, pour les clients de messagerie qui la préfèrent. */
    public static function text(array $site, array $data, string $from, string $until): string
    {
        $L   = [];
        $L[] = (string)$site['name'] . ' : ' . t('Rapport de disponibilité') . ' ' . self::monthLabel($from);
        $L[] = '';
        $L[] = t('Disponibilité') . ' : '
             . ($data['uptime'] !== null ? Ui::pct((float)$data['uptime']) : '—')
             . ' (' . human_duration((int)$data['down_sec']) . ' ' . t('hors service') . ')';
        $L[] = t('Temps de réponse moyen : {avg} · p95 {p95}',
                 ['avg' => Ui::ms($data['avg_ms']), 'p95' => Ui::ms($data['p95_ms'])]);
        $L[] = '';
        $L[] = t('Pages surveillées') . ' :';
        foreach ($data['monitors'] as $m) {
            $L[] = '  ' . $m['name'] . ' : '
                 . ($m['uptime'] !== null ? Ui::pct((float)$m['uptime'], 1) : '—')
                 . ' · ' . Ui::ms($m['avg_ms']);
        }
        $L[] = '';
        if (!$data['incidents']) {
            $L[] = t('Aucune interruption sur la période.');
        } else {
            $L[] = t('Interruptions constatées') . ' :';
            foreach (array_slice($data['incidents'], 0, 20) as $i) {
                $dur = $i['ended_at'] ? (int)$i['duration_sec']
                                      : max(0, time() - strtotime((string)$i['started_at']));
                $L[] = '  ' . date('d/m H:i', strtotime((string)$i['started_at'])) . ' · ' . $i['name']
                     . ' · ' . \Uptimeez\Notify\Notifier::reasonLabel($i['reason_code'] !== null ? (string)$i['reason_code'] : null)
                     . ' · ' . human_duration($dur);
            }
        }
        $link = self::onlineLink($site);
        if ($link !== null) { $L[] = ''; $L[] = t('Voir le rapport détaillé') . ' : ' . $link; }
        $L[] = '';
        $L[] = t('Document produit le {date} par {app}, surveillance continue.', ['date' => date('d/m/Y H:i')]);
        return implode("\n", $L);
    }

    /**
     * Lien vers le rapport en ligne. Il n'existe que si une page d'état publique
     * est activée : on ne met jamais dans un e-mail client un lien qui demande le
     * mot de passe de l'agence.
     */
    private static function onlineLink(array $site): ?string
    {
        $base  = rtrim((string)Config::get('app.base_url', ''), '/');
        $token = (string)Config::get('app.public_token', '');
        if ($base === '' || $token === '') return null;
        return $base . '/index.php?p=status&token=' . rawurlencode($token);
    }
}
