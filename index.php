<?php
/**
 * Uptimer : point d'entrée web (routeur simple, sans réécriture d'URL requise).
 */
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use Uptimer\Auth;
use Uptimer\Config;
use Uptimer\Db;
use Uptimer\I18n;
use Uptimer\Importer;
use Uptimer\Notify\Notifier;
use Uptimer\Runner;
use Uptimer\Stats;
use Uptimer\Ui;

// --- Installation nécessaire ? -------------------------------------------
if (!Config::isInstalled()) {
    header('Location: install.php');
    exit;
}

$page = (string)($_GET['p'] ?? 'today');

// --- Langue et niveau de détail ------------------------------------------
// Les deux se décident avant toute sortie : ?lang= et ?ui= sont mémorisés
// puis n'apparaissent plus dans les liens. Le nom « ui » et non « mode » :
// le mur d'écran utilise déjà ?mode= pour son propre regroupement.
if (isset($_GET['ui']) && is_string($_GET['ui'])) Ui::setMode($_GET['ui']);

// --- Status page publique (sans session) ---------------------------------
if ($page === 'status') {
    $token = (string)Config::get('app.public_token', '');
    if ($token === '' || !hash_equals($token, (string)($_GET['token'] ?? ''))) {
        http_response_code(404);
        exit('Page de statut non activée.');
    }
    I18n::init();
    Db::migrate();
    $view = 'status';
    require __DIR__ . '/views/layout.php';
    exit;
}

// --- Espace client (sans session, lecture seule) -------------------------
// Le jeton du lien décide de tout ce qui sera affiché. Aucun identifiant fourni
// par le visiteur n'entre dans une requête : il n'y a donc pas de paramètre à
// manipuler pour voir les sites d'un autre client.
if ($page === 'client') {
    I18n::init();
    Db::migrate();
    $client = Uptimer\Client::byToken((string)($_GET['k'] ?? ''));
    if ($client === null) {
        http_response_code(404);
        // Même réponse pour un jeton inconnu, mal formé ou désactivé : rien ne
        // permet de distinguer « ce lien n'existe pas » de « ce lien est coupé ».
        exit('Lien invalide ou expiré.');
    }
    // Le jeton voyage dans l'URL : on empêche l'indexation et la fuite par
    // référent vers les sites que la page met en lien.
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: private, no-store');
    Uptimer\Client::touch((int)$client['id']);
    $clientOverview = Uptimer\Client::overview((int)$client['id']);
    // Le compteur de pannes du titre est celui de ce client, pas le global.
    $clientSummary = ['down' => $clientOverview['down'], 'degraded' => $clientOverview['degraded'],
                      'total' => $clientOverview['sites']];
    $view = 'client';
    require __DIR__ . '/views/layout.php';
    exit;
}

// --- Authentification ----------------------------------------------------
Auth::start();
I18n::init();   // après la session : le choix de langue y est mémorisé

if ($page === 'logout') {
    Auth::logout();
    header('Location: ' . u('login'));
    exit;
}

if ($page === 'login') {
    $error = null;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $wait = Auth::lockedFor();
        if ($wait > 0) {
            $error = 'Trop de tentatives. Réessayez dans ' . human_duration($wait) . '.';
        } elseif (Auth::attempt((string)($_POST['password'] ?? ''))) {
            header('Location: ' . u('today'));
            exit;
        } else {
            $error = 'Mot de passe incorrect.';
        }
    }
    $view = 'login';
    require __DIR__ . '/views/layout.php';
    exit;
}

Auth::requireLogin();
Db::migrate();

// --- Actions POST --------------------------------------------------------
$flash = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Auth::checkCsrf($_POST['csrf'] ?? null)) {
        $flash = ['bad', 'Jeton de sécurité invalide, action annulée. Rechargez la page.'];
    } else {
        $flash = handle_post();
    }
}

// --- Export CSV : doit sortir avant tout HTML ----------------------------
if ($page === 'incidents' && ($_GET['export'] ?? '') === 'csv') {
    export_incidents_csv();
}

// --- Rendu ---------------------------------------------------------------
$allowed = ['today', 'dashboard', 'monitor', 'monitors', 'incidents', 'import', 'settings', 'events',
            'report', 'clients'];
$view = in_array($page, $allowed, true) ? $page : 'today';
require __DIR__ . '/views/layout.php';


// =========================================================================
// Traitement des formulaires
// =========================================================================
function handle_post(): ?array
{
    $action = (string)($_POST['action'] ?? '');

    switch ($action) {
        // ---- Aperçu avant création : on ne crée rien à l'aveugle ---------
        case 'preview':
            $list = import_payload();
            $opt  = ['interval_sec' => max(30, (int)($_POST['interval_sec'] ?? 300)),
                     'pages'        => max(1, min(12, (int)($_POST['pages'] ?? 4)))];
            // Un export d'outil concurrent est reconnu à son contenu : l'aperçu
            // est le même, avec en plus ce qui n'a pas pu être repris.
            $foreign = Uptimer\Import\Foreign::detect($list);
            if ($foreign !== null) {
                $f = Uptimer\Import\Foreign::parse($list, $foreign);
                $prev = Importer::previewRows($f['rows'], $opt);
                $prev['errors']  = $f['errors'];
                $prev['skipped'] = $f['skipped'];
                $prev['source']  = $f['source'];
                $prev['label']   = $f['label'];
            } else {
                $prev = Importer::preview($list, $opt);
            }
            $prev['raw']  = $list;
            $prev['opt']  = $_POST;
            $GLOBALS['uptimer_preview'] = $prev;
            if (!$prev['rows']) {
                if (trim($list) === '') {
                    return ['bad', t('Collez une liste d\'adresses, ou déposez l\'export de votre outil actuel.')];
                }
                return ['bad', 'Aucune adresse exploitable dans ce texte. '
                    . implode(' ', array_slice($prev['errors'], 0, 2))];
            }
            return null;

        // ---- Import de masse -------------------------------------------
        case 'import':
            $payload = import_payload();
            $src = Uptimer\Import\Foreign::detect($payload);
            if ($src !== null) {
                $f = Uptimer\Import\Foreign::parse($payload, $src);
                $parsed = ['rows' => $f['rows'], 'errors' => $f['errors']];
                $foreignSkipped = count($f['skipped']);
                $foreignLabel = $f['label'];
            } else {
                $parsed = Importer::parse($payload);
                $foreignSkipped = 0;
                $foreignLabel = '';
            }
            if (!$parsed['rows']) {
                return ['bad', 'Aucune URL exploitable. ' . implode(' ', array_slice($parsed['errors'], 0, 3))];
            }
            $r = Importer::createMonitors($parsed['rows'], [
                'group'         => trim((string)($_POST['group'] ?? '')),
                'interval_sec'  => max(30, (int)($_POST['interval_sec'] ?? 300)),
                'discover'      => isset($_POST['discover']) ? 1 : 0,
                'pages'         => max(1, min(12, (int)($_POST['pages'] ?? 4))),
                'extras'        => isset($_POST['extras']) ? 1 : 0,
                'check_css'     => isset($_POST['check_css']) ? 1 : 0,
                'check_db'      => isset($_POST['check_db']) ? 1 : 0,
                'check_ssl'     => isset($_POST['check_ssl']) ? 1 : 0,
                'check_noindex' => isset($_POST['check_noindex']) ? 1 : 0,
                'check_content' => isset($_POST['check_content']) ? 1 : 0,
            ]);
            $msg = ($foreignLabel !== '' ? 'Reprise depuis ' . $foreignLabel . ' : ' : '')
                 . $r['created'] . ' sonde(s) créée(s)'
                 . ($r['skipped'] ? ', ' . $r['skipped'] . ' déjà présente(s)' : '')
                 . ($foreignSkipped ? ', ' . $foreignSkipped . ' non reprise(s) faute d\'équivalent' : '')
                 . ($parsed['errors'] ? ', ' . count($parsed['errors']) . ' ligne(s) ignorée(s)' : '') . '.';
            $_SESSION['uptimer_setup_queue'] = $r['ids'];
            return [$r['created'] ? 'ok' : 'warn', $msg];

        // ---- Création / édition d'une sonde -----------------------------
        case 'save_monitor':
            $id   = (int)($_POST['id'] ?? 0);
            $url  = normalize_url((string)($_POST['url'] ?? ''));
            if (!$url) return ['bad', 'URL invalide.'];

            $data = [
                'name'            => str_cut(trim((string)($_POST['name'] ?? '')) ?: host_of($url), 180),
                'url'             => $url,
                'kind'            => in_array($_POST['kind'] ?? '', ['page', 'api', 'asset', 'keyword'], true) ? $_POST['kind'] : 'page',
                'method'          => in_array(strtoupper((string)($_POST['method'] ?? 'GET')), ['GET', 'POST', 'HEAD', 'PUT'], true) ? strtoupper((string)$_POST['method']) : 'GET',
                'request_body'    => trim((string)($_POST['request_body'] ?? '')) ?: null,
                'interval_sec'    => max(30, min(86400, (int)($_POST['interval_sec'] ?? 300))),
                'timeout_sec'     => max(3, min(60, (int)($_POST['timeout_sec'] ?? 15))),
                'retries'         => max(0, min(5, (int)($_POST['retries'] ?? 2))),
                'slow_ms'         => max(0, min(60000, (int)($_POST['slow_ms'] ?? 3000))),
                'expect_status'   => trim((string)($_POST['expect_status'] ?? '200-299')) ?: '200-299',
                'expect_string'   => trim((string)($_POST['expect_string'] ?? '')) ?: null,
                'forbid_string'   => trim((string)($_POST['forbid_string'] ?? '')) ?: null,
                'json_path'       => trim((string)($_POST['json_path'] ?? '')) ?: null,
                'json_expect'     => trim((string)($_POST['json_expect'] ?? '')) ?: null,
                'watch_string'    => trim((string)($_POST['watch_string'] ?? '')) ?: null,
                'watch_mode'      => ($_POST['watch_mode'] ?? 'appear') === 'disappear' ? 'disappear' : 'appear',
                'check_ssl'       => isset($_POST['check_ssl']) ? 1 : 0,
                'check_css'       => isset($_POST['check_css']) ? 1 : 0,
                'check_db'        => isset($_POST['check_db']) ? 1 : 0,
                'check_content'   => isset($_POST['check_content']) ? 1 : 0,
                'check_noindex'   => isset($_POST['check_noindex']) ? 1 : 0,
                'ssl_warn_days'   => max(1, min(120, (int)($_POST['ssl_warn_days'] ?? 14))),
                'css_drop_pct'    => max(5, min(90, (int)($_POST['css_drop_pct'] ?? 35))),
                'maintenance'     => trim((string)($_POST['maintenance'] ?? '')) ?: null,
                'notify_channels' => trim((string)($_POST['notify_channels'] ?? '')) ?: null,
                'auth_user'       => trim((string)($_POST['auth_user'] ?? '')) ?: null,
                'user_agent'      => trim((string)($_POST['user_agent'] ?? '')) ?: null,
                'follow_redirects'=> isset($_POST['follow_redirects']) ? 1 : 0,
                'ignore_ssl_errors' => isset($_POST['ignore_ssl_errors']) ? 1 : 0,
                'css_baseline_locked' => isset($_POST['css_baseline_locked']) ? 1 : 0,
                'auto_slow'       => isset($_POST['auto_slow']) ? 1 : 0,
                'enabled'         => isset($_POST['enabled']) ? 1 : 0,
            ];
            $headers = trim((string)($_POST['request_headers'] ?? ''));
            if ($headers !== '') {
                $map = [];
                foreach (preg_split('~\R~', $headers) ?: [] as $line) {
                    if (!str_contains($line, ':')) continue;
                    [$k, $v] = explode(':', $line, 2);
                    $map[trim($k)] = trim($v);
                }
                $data['request_headers'] = $map ? jenc($map) : null;
            } else {
                $data['request_headers'] = null;
            }
            // Le mot de passe HTTP n'étant jamais réaffiché, un champ vide
            // signifie « inchangé », pas « effacé ».
            $newAuthPass = (string)($_POST['auth_pass'] ?? '');
            if ($newAuthPass !== '')                              $data['auth_pass'] = $newAuthPass;
            elseif (($data['auth_user'] ?? null) === null)         $data['auth_pass'] = null;

            if ($id > 0) {
                Db::update('monitors', $data, 'id = :__i', ['__i' => $id]);
                return ['ok', 'Sonde enregistrée.'];
            }
            $data['role']        = 'primary';
            $data['status']      = 'unknown';
            $data['setup_state'] = isset($_POST['autodetect']) ? 'pending' : 'done';
            $data['created_at']  = now();
            $data['next_check_at'] = now();
            $host = host_of($url);
            $data['site_id'] = (int)(Db::val('SELECT id FROM sites WHERE domain = ?', [registrable_domain($host)])
                ?: Db::insert('sites', ['name' => registrable_domain($host), 'domain' => registrable_domain($host), 'created_at' => now()]));
            $newId = Db::insert('monitors', $data);
            $_SESSION['uptimer_setup_queue'] = $data['setup_state'] === 'pending' ? [$newId] : [];
            header('Location: ' . u('monitor', ['id' => $newId, 'created' => 1]));
            exit;

        case 'reset_baseline':
            $id = (int)($_POST['id'] ?? 0);
            Db::update('monitors', ['css_baseline' => null, 'css_baseline_at' => null, 'css_checked_at' => null],
                'id = :__i', ['__i' => $id]);
            return ['ok', 'Empreinte CSS de référence effacée : elle sera réapprise à la prochaine analyse.'];

        case 'delete_monitor':
            $id = (int)($_POST['id'] ?? 0);
            Db::q('DELETE FROM checks WHERE monitor_id = ?', [$id]);
            Db::q('DELETE FROM incidents WHERE monitor_id = ?', [$id]);
            Db::q('DELETE FROM events WHERE monitor_id = ?', [$id]);
            Db::q('DELETE FROM daily_stats WHERE monitor_id = ?', [$id]);
            Db::q('DELETE FROM monitors WHERE id = ?', [$id]);
            header('Location: ' . u('monitors', ['deleted' => 1]));
            exit;

        case 'bulk':
            $ids = array_map('intval', (array)($_POST['ids'] ?? []));
            $ids = array_values(array_filter($ids));
            if (!$ids) return ['warn', 'Aucune sonde sélectionnée.'];
            $in = implode(',', array_fill(0, count($ids), '?'));
            switch ((string)($_POST['bulk_action'] ?? '')) {
                case 'enable':
                    Db::q("UPDATE monitors SET enabled = 1, paused_until = NULL WHERE id IN ($in)", $ids);
                    return ['ok', count($ids) . ' sonde(s) réactivée(s).'];
                case 'disable':
                    Db::q("UPDATE monitors SET enabled = 0, status = 'paused' WHERE id IN ($in)", $ids);
                    return ['ok', count($ids) . ' sonde(s) mise(s) en pause.'];
                case 'delete':
                    foreach (['checks', 'incidents', 'events', 'daily_stats'] as $t) {
                        Db::q("DELETE FROM $t WHERE monitor_id IN ($in)", $ids);
                    }
                    Db::q("DELETE FROM monitors WHERE id IN ($in)", $ids);
                    return ['ok', count($ids) . ' sonde(s) supprimée(s).'];
                case 'check':
                    // Au-delà de quelques sondes, on programme au lieu d'exécuter :
                    // une requête web n'a pas le temps de vérifier 100 sites.
                    if (count($ids) > 8) {
                        Db::q("UPDATE monitors SET next_check_at = ? WHERE id IN ($in)", array_merge([now()], $ids));
                        return ['ok', count($ids) . ' sonde(s) programmée(s) : elles seront vérifiées à la passe suivante (moins d\'une minute).'];
                    }
                    foreach ($ids as $i) { Runner::runOne($i); Stats::refresh($i); }
                    return ['ok', count($ids) . ' sonde(s) vérifiée(s) à l\'instant.'];
                case 'interval':
                    $iv = max(30, (int)($_POST['bulk_interval'] ?? 300));
                    Db::q("UPDATE monitors SET interval_sec = ? WHERE id IN ($in)", array_merge([$iv], $ids));
                    return ['ok', 'Intervalle mis à jour sur ' . count($ids) . ' sonde(s).'];
                case 'setup':
                    Db::q("UPDATE monitors SET setup_state = 'pending' WHERE id IN ($in)", $ids);
                    $_SESSION['uptimer_setup_queue'] = $ids;
                    return ['ok', count($ids) . ' sonde(s) en attente de réanalyse (détection CMS, pages, preuve).'];
            }
            return ['warn', 'Action de masse inconnue.'];

        // ---- Réglages ---------------------------------------------------
        case 'save_settings':
            $patch = [
                'app' => [
                    'name'         => trim((string)($_POST['app_name'] ?? 'Uptimer')) ?: 'Uptimer',
                    'base_url'     => rtrim(trim((string)($_POST['base_url'] ?? '')), '/'),
                    'timezone'     => trim((string)($_POST['timezone'] ?? 'Europe/Paris')) ?: 'Europe/Paris',
                    'public_token' => trim((string)($_POST['public_token'] ?? '')),
                    'cron_key'     => trim((string)($_POST['cron_key'] ?? '')),
                ],
                'defaults' => [
                    'interval_sec'   => max(30, (int)($_POST['def_interval'] ?? 300)),
                    'timeout_sec'    => max(3, (int)($_POST['def_timeout'] ?? 15)),
                    'retries'        => max(0, min(5, (int)($_POST['def_retries'] ?? 2))),
                    'ssl_warn_days'  => max(1, (int)($_POST['def_ssl_days'] ?? 14)),
                    'slow_ms'        => max(200, (int)($_POST['def_slow'] ?? 3000)),
                    'css_drop_pct'   => max(5, min(90, (int)($_POST['def_css_drop'] ?? 35))),
                    'max_parallel'   => max(1, min(20, (int)($_POST['def_parallel'] ?? 10))),
                    'retention_days' => max(7, (int)($_POST['def_retention'] ?? 60)),
                ],
                'vitals' => [
                    'enabled'     => isset($_POST['vitals_enabled']),
                    'crux_key'    => trim((string)($_POST['crux_key'] ?? '')),
                    'form_factor' => in_array($_POST['form_factor'] ?? 'PHONE', ['PHONE', 'DESKTOP'], true)
                        ? (string)$_POST['form_factor'] : 'PHONE',
                ],
                'vuln' => [
                    'enabled'     => isset($_POST['vuln_enabled']),
                    'timeout_sec' => max(3, min(30, (int)($_POST['vuln_timeout'] ?? 8))),
                ],
                'security' => [
                    'block_private_ranges' => isset($_POST['block_private']),
                ],
                'notify' => [
                    'discord' => ['enabled' => isset($_POST['discord_enabled']), 'webhook' => trim((string)($_POST['discord_webhook'] ?? ''))],
                    'slack'   => ['enabled' => isset($_POST['slack_enabled']),   'webhook' => trim((string)($_POST['slack_webhook'] ?? ''))],
                    'webhook' => ['enabled' => isset($_POST['webhook_enabled']), 'url' => trim((string)($_POST['webhook_url'] ?? ''))],
                    'mail'    => [
                        'enabled'   => isset($_POST['mail_enabled']),
                        'to'        => trim((string)($_POST['mail_to'] ?? '')),
                        'from'      => trim((string)($_POST['mail_from'] ?? '')),
                        'from_name' => trim((string)($_POST['mail_from_name'] ?? 'Uptimer')),
                        'transport' => ($_POST['mail_transport'] ?? 'mail') === 'smtp' ? 'smtp' : 'mail',
                        'smtp'      => [
                            'host'   => trim((string)($_POST['smtp_host'] ?? '')),
                            'port'   => (int)($_POST['smtp_port'] ?? 587),
                            'user'   => trim((string)($_POST['smtp_user'] ?? '')),
                            'pass'   => (string)($_POST['smtp_pass'] ?? ''),
                            'secure' => in_array($_POST['smtp_secure'] ?? 'tls', ['tls', 'ssl', 'none'], true) ? $_POST['smtp_secure'] : 'tls',
                        ],
                    ],
                    'resend_after_min' => max(0, (int)($_POST['resend_after'] ?? 60)),
                    'notify_recovery'  => isset($_POST['notify_recovery']),
                    'notify_degraded'  => isset($_POST['notify_degraded']),
                    'quiet_hours'      => trim((string)($_POST['quiet_hours'] ?? '')),
                ],
            ];
            if (($_POST['smtp_pass'] ?? '') === '') {
                $patch['notify']['mail']['smtp']['pass'] = (string)Config::get('notify.mail.smtp.pass', '');
            }
            $newPass = (string)($_POST['new_password'] ?? '');
            if ($newPass !== '') {
                if (strlen($newPass) < 8) return ['bad', 'Le mot de passe doit faire au moins 8 caractères.'];
                $patch['auth'] = ['password_hash' => password_hash($newPass, PASSWORD_DEFAULT)];
            }
            return Config::save($patch)
                ? ['ok', 'Réglages enregistrés.' . ($newPass !== '' ? ' Nouveau mot de passe actif.' : '')]
                : ['bad', 'Impossible d\'écrire config.php (droits en écriture ?).'];

        // ---- Rapport mensuel automatique ---------------------------------
        case 'save_autoreport':
            Config::save(['report' => [
                'enabled'     => isset($_POST['report_enabled']),
                'day'         => max(1, min(28, (int)($_POST['report_day'] ?? 1))),
                'subject'     => str_cut(trim((string)($_POST['report_subject'] ?? '')), 180),
                'fallback_to' => str_cut(trim((string)($_POST['report_fallback'] ?? '')), 400),
            ]]);
            return ['ok', t('Envoi automatique enregistré.')];

        case 'save_site_report':
            $sid = (int)($_POST['site_id'] ?? 0);
            if (!Db::one('SELECT id FROM sites WHERE id = ?', [$sid])) return ['bad', t('Site inconnu')];
            Db::update('sites', [
                'report_to'      => str_cut(trim((string)($_POST['report_to'] ?? '')), 400) ?: null,
                'report_enabled' => isset($_POST['site_report_enabled']) ? 1 : 0,
            ], 'id = :__i', ['__i' => $sid]);
            return ['ok', t('Destinataires enregistrés.')];

        case 'send_site_report':
            $sid = (int)($_POST['site_id'] ?? 0);
            $r   = Uptimer\Report::sendFor($sid);
            return [$r['ok'] ? 'ok' : 'bad', $r['info']];

        // ---- Clients de l'agence -----------------------------------------
        case 'client_create':
            $cid = Uptimer\Client::create((string)($_POST['client_name'] ?? ''),
                                          (string)($_POST['client_email'] ?? ''),
                                          (string)($_POST['client_notes'] ?? ''));
            return ['ok', t('Client créé. Son lien est prêt à être envoyé.')];

        case 'client_save':
            $cid = (int)($_POST['client_id'] ?? 0);
            if (!Db::one('SELECT id FROM clients WHERE id = ?', [$cid])) return ['bad', t('Client inconnu')];
            Db::update('clients', [
                'name'          => str_cut(trim((string)($_POST['client_name'] ?? '')), 190) ?: t('Client sans nom'),
                'contact_email' => str_cut(trim((string)($_POST['client_email'] ?? '')), 255) ?: null,
                'notes'         => str_cut(trim((string)($_POST['client_notes'] ?? '')), 2000) ?: null,
                'enabled'       => isset($_POST['client_enabled']) ? 1 : 0,
            ], 'id = :__i', ['__i' => $cid]);
            $n = Uptimer\Client::setSites($cid, (array)($_POST['sites'] ?? []));
            return ['ok', tn($n, 'Client enregistré : un site rattaché.',
                                'Client enregistré : {n} sites rattachés.')];

        case 'client_rotate':
            $cid = (int)($_POST['client_id'] ?? 0);
            if (!Db::one('SELECT id FROM clients WHERE id = ?', [$cid])) return ['bad', t('Client inconnu')];
            Uptimer\Client::rotate($cid);
            return ['ok', t('Nouveau lien généré. L\'ancien ne fonctionne plus.')];

        case 'client_delete':
            $cid = (int)($_POST['client_id'] ?? 0);
            if (!Db::one('SELECT id FROM clients WHERE id = ?', [$cid])) return ['bad', t('Client inconnu')];
            Uptimer\Client::delete($cid);
            return ['ok', t('Client supprimé. Ses sites sont conservés, simplement détachés.')];

        case 'client_from_groups':
            $r = Uptimer\Client::fromGroups();
            return [$r['created'] || $r['linked'] ? 'ok' : 'warn',
                    t('{c} client(s) créé(s), {l} site(s) rattaché(s) depuis les groupes existants.',
                      ['c' => $r['created'], 'l' => $r['linked']])];

        case 'test_notify':
            $ch  = (string)($_POST['channel'] ?? '');
            $res = Notifier::test($ch);
            return [$res['ok'] ? 'ok' : 'bad',
                    'Test ' . $ch . ' : ' . ($res['ok'] ? 'envoyé' : 'échec') . ' : ' . str_cut((string)$res['info'], 200)];

        case 'ack_incident':
            Db::update('incidents', ['ack_at' => now()], 'id = :__i', ['__i' => (int)($_POST['id'] ?? 0)]);
            return ['ok', 'Incident pris en compte : les rappels sont stoppés.'];

        case 'close_incident':
            $id  = (int)($_POST['id'] ?? 0);
            $inc = Db::one('SELECT * FROM incidents WHERE id = ?', [$id]);
            if ($inc && !$inc['ended_at']) {
                Db::update('incidents', ['ended_at' => now(),
                    'duration_sec' => max(0, time() - strtotime((string)$inc['started_at']))], 'id = :__i', ['__i' => $id]);
            }
            return ['ok', 'Incident clos manuellement.'];

        case 'maintenance_cron':
            $done = ['purge' => Stats::purge(), 'rollup' => Stats::rollup(), 'stats' => Stats::refreshStale(0, 500)];
            return ['ok', 'Entretien exécuté : ' . $done['purge'] . ' mesure(s) purgée(s), '
                . $done['rollup'] . ' jour(s) consolidé(s), ' . $done['stats'] . ' agrégat(s) recalculé(s).'];
    }
    return null;
}

/**
 * Export CSV des incidents. Appelé avant tout rendu HTML : un export doit être
 * un fichier propre, pas une page web avec des en-têtes déjà envoyés.
 */
function export_incidents_csv(): never
{
    $onlyId = (int)($_GET['id'] ?? 0);
    $state  = (string)($_GET['s'] ?? 'all');
    $range  = (string)($_GET['range'] ?? '30d');
    $from   = date('Y-m-d H:i:s', time() - Ui::rangeSeconds($range));

    $where  = ['i.started_at >= ?'];
    $params = [$from];
    if ($onlyId)             { $where[] = 'i.monitor_id = ?'; $params[] = $onlyId; }
    if ($state === 'open')   { $where[] = 'i.ended_at IS NULL'; }
    if ($state === 'closed') { $where[] = 'i.ended_at IS NOT NULL'; }

    $rows = Db::all('SELECT i.*, m.name, m.url FROM incidents i JOIN monitors m ON m.id = i.monitor_id
                     WHERE ' . implode(' AND ', $where) . ' ORDER BY i.started_at DESC LIMIT 5000', $params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="uptimer-incidents-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");  // BOM : Excel ouvre l'UTF-8 correctement
    fputcsv($out, ['Sonde', 'URL', 'Gravité', 'Cause', 'Détail', 'Début', 'Fin',
                   'Durée (s)', 'Durée', 'Échecs', 'Alertes envoyées'], ';');
    foreach ($rows as $r) {
        $dur = $r['ended_at'] ? (int)$r['duration_sec'] : max(0, time() - strtotime((string)$r['started_at']));
        // Chaque cellule d'origine utilisateur passe par csv_cell() : le fichier
        // part chez un client, il ne doit pas contenir de formule exécutable.
        fputcsv($out, [
            csv_cell($r['name']), csv_cell($r['url']),
            $r['severity'] === 'down' ? t('Hors service') : t('Dégradé'),
            csv_cell(Notifier::reasonLabel($r['reason_code'] !== null ? (string)$r['reason_code'] : null)),
            csv_cell(str_cut((string)$r['message'], 300)),
            $r['started_at'], $r['ended_at'] ?: t('en cours'),
            $dur, human_duration($dur), (int)$r['checks_failed'], (int)$r['notify_count'],
        ], ';');
    }
    fclose($out);
    exit;
}
