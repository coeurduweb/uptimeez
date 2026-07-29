<?php
/**
 * UptimeEZ : points d'entrée JSON pour l'interface (vérification à la demande,
 * mise en pause, préparation d'un site, rafraîchissement du tableau de bord).
 */
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use Uptimeez\Auth;
use Uptimeez\Config;
use Uptimeez\Db;
use Uptimeez\I18n;
use Uptimeez\Importer;
use Uptimeez\Runner;
use Uptimeez\Stats;
use Uptimeez\Ui;

// Un appelant JSON qui reçoit une page HTML d'erreur n'affiche rien du tout.
Uptimeez\Fail::asJson();

if (!Config::isInstalled()) json_out(['error' => 'not_installed'], 503);

Auth::start();
I18n::init();
if (!Auth::check()) json_out(['error' => 'auth', 'message' => t('Session expirée, rechargez la page.')], 401);
Uptimeez\Fail::trusted();
Db::migrate();

$action  = (string)($_REQUEST['action'] ?? '');
$isWrite = in_array($action, ['check', 'toggle', 'setup', 'fix', 'undo'], true);
if ($isWrite) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') json_out(['error' => 'method'], 405);
    if (!Auth::checkCsrf($_POST['csrf'] ?? null)) json_out(['error' => 'csrf', 'message' => t('Jeton invalide')], 403);
}

$id = (int)($_REQUEST['id'] ?? 0);

switch ($action) {

    // ---- Vérifier une sonde immédiatement -------------------------------
    case 'check':
        $res = Runner::runOne($id);
        if (!$res) json_out(['error' => 'not_found'], 404);
        Stats::refresh($id);
        json_out(['ok' => true, 'monitor' => monitor_payload($id), 'result' => [
            'state'   => $res['state'],
            'reason'  => $res['reason'] ?? null,
            'message' => $res['message'] ?? '',
        ]]);

    // ---- Activer / mettre en pause --------------------------------------
    case 'toggle':
        $mon = Db::one('SELECT id, enabled FROM monitors WHERE id = ?', [$id]);
        if (!$mon) json_out(['error' => 'not_found'], 404);
        $enable = (int)$mon['enabled'] !== 1;
        Db::update('monitors', [
            'enabled'      => $enable ? 1 : 0,
            'status'       => $enable ? 'unknown' : 'paused',
            'paused_until' => null,
            'next_check_at'=> $enable ? now() : null,
            // On efface le dernier verdict : sinon la carte affiche un message
            // périmé à côté d'un état « en attente de vérification ».
            'last_message' => $enable ? null : 'Surveillance suspendue',
            'last_message_vars' => null,
            'reason_code'  => null,
            'status_since' => now(),
        ], 'id = :__i', ['__i' => $id]);
        json_out(['ok' => true, 'enabled' => $enable, 'monitor' => monitor_payload($id)]);

    // ---- Préparation automatique d'un site ------------------------------
    case 'setup':
        $r = Importer::setup($id);
        // Première mesure immédiate : la carte n'affiche pas « jamais vérifié ».
        if ($r['ok']) { Runner::runOne($id); Stats::refresh($id); }
        json_out(['ok' => $r['ok']] + $r + ['monitor' => monitor_payload($id)]);

    // ---- Rafraîchissement du tableau de bord ----------------------------
    case 'summary':
        $mons = Db::all('SELECT id, name, status, reason_code, last_message, last_message_vars,
                               last_ms, last_check_at,
                                uptime_24h, css_state, ssl_days_left, ssl_warn_days, enabled, setup_state
                         FROM monitors');
        $out = [];
        foreach ($mons as $m) {
            $out[] = [
                'id'      => (int)$m['id'],
                'status'  => (string)$m['status'],
                'label'   => Ui::statusLabel((string)$m['status']),
                'message' => verdict_text($m, 150),
                'ms'      => $m['last_ms'] !== null ? (int)$m['last_ms'] : null,
                'ms_h'    => Ui::ms($m['last_ms'] !== null ? (int)$m['last_ms'] : null),
                'uptime'  => $m['uptime_24h'] !== null ? (float)$m['uptime_24h'] : null,
                'uptime_h'=> Ui::pct($m['uptime_24h'] !== null ? (float)$m['uptime_24h'] : null),
                'checked' => human_since((string)$m['last_check_at']),
                'css'     => $m['css_state'],
                'setup'   => $m['setup_state'],
            ];
        }
        json_out(['ok' => true, 'summary' => Stats::summary(), 'monitors' => $out, 'at' => now()]);

    // ---- Série pour la courbe -------------------------------------------
    case 'series':
        $range = (string)($_GET['range'] ?? '24h');
        json_out(['ok' => true, 'series' => Stats::series($id, Ui::rangeSeconds($range), Ui::rangeBuckets($range))]);

    // ---- Sondes en attente de préparation -------------------------------
    case 'pending':
        json_out(['ok' => true, 'pending' => Importer::pending(200)]);

    // ---- Correctifs appliqués depuis la liste de tâches ------------------
    // Chaque correctif renvoie un jeton d'annulation : une action de trop se répare.
    case 'fix':
        $mon = Db::one('SELECT * FROM monitors WHERE id = ?', [$id]);
        if (!$mon) json_out(['error' => 'not_found'], 404);
        $what = (string)($_POST['fix'] ?? '');
        $undo = [];
        $msg  = '';

        switch ($what) {
            case 'relearn':
                $undo = ['css_baseline' => $mon['css_baseline'], 'css_baseline_at' => $mon['css_baseline_at'],
                         'css_state' => $mon['css_state'], 'css_checked_at' => $mon['css_checked_at'],
                         'silhouette_ref' => $mon['silhouette_ref'] ?? null,
                         'silhouette_ref_sig' => $mon['silhouette_ref_sig'] ?? null,
                         'silhouette_drift' => (int)($mon['silhouette_drift'] ?? 0)];
                Db::update('monitors', ['css_baseline' => null, 'css_baseline_at' => null,
                                        'css_checked_at' => null, 'css_state' => null,
                                        'silhouette_ref' => null, 'silhouette_ref_sig' => null,
                                        'silhouette_ref_at' => null, 'silhouette_drift' => 0],
                           'id = :__i', ['__i' => $id]);
                $msg = t('Référence CSS effacée : elle sera réapprise à la prochaine analyse.');
                break;

            case 'raise_slow':
                // On se cale sur ce que le site fait réellement, pas sur un chiffre rond.
                $w    = Stats::window($id, 7 * 86400, $mon);
                $base = max((int)($w['p95_ms'] ?? 0), (int)($mon['last_ms'] ?? 0), (int)$mon['slow_ms']);
                $new  = (int)min(60000, max(1000, ceil($base * 1.4 / 100) * 100));
                $undo = ['slow_ms' => (int)$mon['slow_ms']];
                Db::update('monitors', ['slow_ms' => $new], 'id = :__i', ['__i' => $id]);
                $msg  = t('Seuil de lenteur porté à {value}, d\'après le p95 mesuré sur 7 jours.',
                          ['value' => Ui::ms($new)]);
                break;

            case 'ignore_noindex':
                $undo = ['check_noindex' => (int)$mon['check_noindex']];
                Db::update('monitors', ['check_noindex' => 0], 'id = :__i', ['__i' => $id]);
                $msg = t('Surveillance du noindex désactivée sur cette sonde.');
                break;

            case 'adopt_url':
                $last = (string)Db::val('SELECT final_url FROM checks WHERE monitor_id = ? AND final_url IS NOT NULL
                                         ORDER BY id DESC LIMIT 1', [$id], '');
                if ($last === '' || $last === $mon['url']) {
                    json_out(['ok' => false, 'message' => t('Aucune URL de destination relevée pour l\'instant.')]);
                }
                $undo = ['url' => (string)$mon['url']];
                Db::update('monitors', ['url' => $last], 'id = :__i', ['__i' => $id]);
                $msg = t('Sonde alignée sur {url}', ['url' => str_cut($last, 60)]);
                break;

            case 'snooze':
                $undo = ['paused_until' => $mon['paused_until']];
                Db::update('monitors', ['paused_until' => date('Y-m-d H:i:s', time() + 3600)],
                           'id = :__i', ['__i' => $id]);
                $msg = t('Sonde en pause pendant une heure.');
                break;

            case 'ack':
                $inc = Db::one('SELECT id FROM incidents WHERE monitor_id = ? AND ended_at IS NULL
                                ORDER BY id DESC LIMIT 1', [$id]);
                if (!$inc) json_out(['ok' => false, 'message' => 'Aucun incident ouvert.']);
                Db::update('incidents', ['ack_at' => now()], 'id = :__i', ['__i' => (int)$inc['id']]);
                $msg = t('Incident pris en compte : les rappels d\'alerte sont stoppés.');
                break;

            default:
                json_out(['error' => 'unknown_fix'], 400);
        }

        $token = null;
        if ($undo) {
            $token = bin2hex(random_bytes(8));
            $_SESSION['uptimeez_undo'][$token] = ['id' => $id, 'data' => $undo, 'at' => time()];
            // On ne garde que les cinq dernières annulations possibles.
            if (count($_SESSION['uptimeez_undo']) > 5) {
                $_SESSION['uptimeez_undo'] = array_slice($_SESSION['uptimeez_undo'], -5, null, true);
            }
        }
        json_out(['ok' => true, 'message' => $msg, 'undo' => $token, 'monitor' => monitor_payload($id)]);

    case 'undo':
        $token = (string)($_POST['token'] ?? '');
        $rec   = $_SESSION['uptimeez_undo'][$token] ?? null;
        if (!$rec || time() - (int)$rec['at'] > 600) json_out(['ok' => false, 'message' => t('Annulation expirée.')]);
        unset($_SESSION['uptimeez_undo'][$token]);
        Db::update('monitors', $rec['data'], 'id = :__i', ['__i' => (int)$rec['id']]);
        json_out(['ok' => true, 'message' => t('Modification annulée.'), 'monitor' => monitor_payload((int)$rec['id'])]);

    // ---- Rapport prêt à coller dans un ticket ---------------------------
    case 'report':
        $txt = Uptimeez\Triage::report($id);
        if ($txt === '') json_out(['error' => 'not_found'], 404);
        json_out(['ok' => true, 'report' => $txt]);

    // ---- Palette de commandes : recherche de sondes ----------------------
    case 'search':
        // Le filtrage se fait en PHP pour être insensible aux accents : SQLite ne
        // sait pas comparer « cassé » et « casse », et l'utilisateur tape sans accent.
        $q    = fold((string)($_GET['q'] ?? ''));
        $rows = Db::all("SELECT m.id, m.name, m.url, m.status, m.kind, s.name AS site_name, s.domain
                         FROM monitors m LEFT JOIN sites s ON s.id = m.site_id
                         ORDER BY CASE m.status WHEN 'down' THEN 0 WHEN 'degraded' THEN 1
                                  WHEN 'unknown' THEN 2 ELSE 3 END, m.role DESC, m.name ASC LIMIT 400");
        $out = [];
        foreach ($rows as $r) {
            if ($q !== '') {
                $hay = fold((string)$r['name'] . ' ' . (string)$r['url'] . ' '
                          . (string)($r['site_name'] ?? '') . ' ' . (string)($r['domain'] ?? ''));
                if (!str_contains($hay, $q)) continue;
            }
            $out[] = ['id' => (int)$r['id'], 'name' => (string)($r['site_name'] ?: $r['name']),
                      'sub' => ($r['kind'] === 'heartbeat' ? 'battement' : host_of((string)$r['url'])),
                      'status' => (string)$r['status'], 'label' => Ui::statusLabel((string)$r['status'])];
            if (count($out) >= 12) break;
        }
        json_out(['ok' => true, 'results' => $out]);

    default:
        json_out(['error' => 'unknown_action'], 400);
}

function monitor_payload(int $id): array
{
    $m = Db::one('SELECT * FROM monitors WHERE id = ?', [$id]);
    if (!$m) return [];
    return [
        'id'       => (int)$m['id'],
        'name'     => (string)$m['name'],
        'status'   => (string)$m['status'],
        'label'    => Ui::statusLabel((string)$m['status']),
        'message'  => verdict_text($m, 200),
        'reason'   => $m['reason_code'],
        'ms'       => $m['last_ms'] !== null ? (int)$m['last_ms'] : null,
        'ms_h'     => Ui::ms($m['last_ms'] !== null ? (int)$m['last_ms'] : null),
        'uptime_h' => Ui::pct($m['uptime_24h'] !== null ? (float)$m['uptime_24h'] : null),
        'css'      => $m['css_state'],
        'enabled'  => (int)$m['enabled'] === 1,
        'checked'  => human_since((string)$m['last_check_at']),
    ];
}
