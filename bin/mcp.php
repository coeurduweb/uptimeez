<?php
/**
 * Uptimeez : serveur MCP (Model Context Protocol).
 *
 * Permet à un agent (Claude Code, Claude Desktop, tout client MCP) d'interroger
 * la surveillance et d'agir dessus : « qu'est-ce qui est cassé ce matin ? »,
 * « pourquoi le site du client X ralentit ? », « revérifie tout ».
 *
 * Écrit en PHP pur, sans dépendance, comme le reste du projet : le serveur MCP
 * ne doit pas être la seule pièce qui réclame Node.
 *
 * Transport stdio, JSON-RPC 2.0 en lignes séparées par des sauts de ligne.
 *
 *   php bin/mcp.php              # lecture seule (défaut)
 *   php bin/mcp.php --write      # autorise aussi les outils qui modifient
 *
 * Déclaration dans un client MCP :
 *
 *   {
 *     "mcpServers": {
 *       "uptimeez": {
 *         "command": "php",
 *         "args": ["/chemin/vers/uptimeez/bin/mcp.php"],
 *         "env": { "UPTIMEEZ_CONFIG": "/chemin/vers/uptimeez/config.php" }
 *       }
 *     }
 *   }
 *
 * La lecture seule est le défaut délibérément : un agent qui explore ne doit pas
 * pouvoir mettre une sonde en pause par accident. Ajoutez --write en connaissance
 * de cause.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Uptimeez\Config;
use Uptimeez\Db;
use Uptimeez\Diagnose;
use Uptimeez\Http;
use Uptimeez\Importer;
use Uptimeez\Runner;
use Uptimeez\Stats;
use Uptimeez\Triage;
use Uptimeez\Ui;

if (PHP_SAPI !== 'cli') exit("À lancer en ligne de commande.\n");

const MCP_PROTOCOL = '2024-11-05';
const MCP_VERSION  = UPTIMEEZ_VERSION;

$WRITE = in_array('--write', $argv, true);

if (!Config::isInstalled()) {
    fwrite(STDERR, "Uptimeez n'est pas installé : ouvrez install.php d'abord.\n");
    exit(1);
}
Db::migrate();

// Les réponses d'un serveur MCP sont lues par une machine : les descriptions
// sont en anglais, langue par défaut du produit et des clients MCP.
\Uptimeez\I18n::init('en');

// =========================================================================
// Catalogue d'outils
// =========================================================================
/**
 * Chaque outil : description (ce qu'un agent doit savoir pour choisir), schéma
 * d'entrée, indicateur d'écriture, et exécution.
 */
function mcp_tools(): array
{
    return [
        // ---------------------------------------------------------- lecture
        'status' => [
            'title' => 'Overall monitoring status',
            'desc'  => 'Current state of the whole portfolio: how many sites are down, degraded, up or paused, '
                     . 'average uptime and response time over 24 hours, and when the collector last ran. '
                     . 'Start here to answer "is everything fine?".',
            'schema' => ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
            'write' => false,
            'run' => function (array $a): array {
                $s = Stats::summary();
                $c = Triage::counts();
                return [
                    'down' => $c['down'], 'degraded' => $c['degraded'], 'up' => $c['up'],
                    'paused' => $c['paused'], 'unknown' => $c['unknown'], 'total' => $c['total'],
                    'uptime_24h_pct' => $c['uptime'] !== null ? round((float)$c['uptime'], 2) : null,
                    'avg_response_ms' => $c['avg_ms'] !== null ? (int)$c['avg_ms'] : null,
                    'open_incidents' => (int)($s['open_incidents'] ?? 0),
                    'last_pass_at' => $c['last_run'],
                    'collector_has_run' => !empty($c['last_run']),
                ];
            },
        ],

        'tasks' => [
            'title' => 'What needs attention now',
            'desc'  => 'The to-do list, most urgent first. Each entry carries the cause in plain words, why it '
                     . 'matters, what to do about it, and the raw technical reading. Also returns what is about '
                     . 'to break: expiring certificates, domains to renew, sites slowing down. This is the tool '
                     . 'to call for "what should I fix today?".',
            'schema' => ['type' => 'object', 'properties' => [
                'include_upcoming' => ['type' => 'boolean', 'description' => 'Include predicted problems (default true)'],
            ], 'additionalProperties' => false],
            'write' => false,
            'run' => function (array $a): array {
                $out = ['now' => [], 'upcoming' => []];
                foreach (Triage::actions() as $t) {
                    $out['now'][] = [
                        'monitor_id' => $t['id'], 'site' => $t['title'], 'address' => $t['subtitle'],
                        'severity' => $t['severity'], 'reason_code' => $t['reason'],
                        'cause' => $t['cause'], 'why_it_matters' => $t['why'], 'what_to_do' => $t['fix'],
                        'evidence' => $t['evidence'], 'since' => $t['since'],
                        'consecutive_failures' => $t['fails'],
                        'other_pages_affected' => $t['also'],
                        'acknowledged' => $t['acked'],
                        'available_fixes' => array_values(array_map(fn($x) => $x[0], $t['actions'])),
                    ];
                }
                if ($a['include_upcoming'] ?? true) {
                    foreach (Triage::upcoming() as $u) {
                        $out['upcoming'][] = [
                            'monitor_id' => (int)$u['id'] ?: null, 'urgency' => $u['urgency'],
                            'days_left' => (int)$u['days'], 'title' => $u['title'], 'why' => $u['why'],
                        ];
                    }
                }
                $out['nothing_to_do'] = $out['now'] === [] && $out['upcoming'] === [];
                return $out;
            },
        ],

        'list_monitors' => [
            'title' => 'List or search monitors',
            'desc'  => 'Every monitor with its state, uptime and response time. The search is '
                     . 'accent-insensitive in any language, so "casse" finds "cassé" and "munchen" finds '
                     . '"München". Filter by state to answer "which sites are down?".',
            'schema' => ['type' => 'object', 'properties' => [
                'search' => ['type' => 'string', 'description' => 'Match a name, domain, URL or technology'],
                'state'  => ['type' => 'string', 'enum' => ['all', 'down', 'degraded', 'up', 'paused', 'problem'],
                             'description' => '"problem" means down or degraded (default all)'],
                'limit'  => ['type' => 'integer', 'description' => 'Maximum rows, 1 to 200 (default 50)'],
            ], 'additionalProperties' => false],
            'write' => false,
            'run' => function (array $a): array {
                $limit = (int)max(1, min(200, (int)($a['limit'] ?? 50)));
                $rows = Db::all('SELECT m.id, m.name, m.url, m.kind, m.status, m.reason_code,
                                        m.last_message, m.last_message_vars,
                                        m.last_ms, m.uptime_24h, m.enabled, m.last_check_at, m.interval_sec,
                                        s.name AS site_name, s.domain, s.cms, s.group_name
                                 FROM monitors m LEFT JOIN sites s ON s.id = m.site_id
                                 ORDER BY CASE m.status WHEN \'down\' THEN 0 WHEN \'degraded\' THEN 1
                                          WHEN \'unknown\' THEN 2 ELSE 3 END, m.role DESC, m.name ASC');
                $needle = fold((string)($a['search'] ?? ''));
                $state  = (string)($a['state'] ?? 'all');
                $out = [];
                foreach ($rows as $r) {
                    if ($state === 'problem' && !in_array($r['status'], ['down', 'degraded'], true)) continue;
                    if ($state !== 'all' && $state !== 'problem' && $r['status'] !== $state) continue;
                    if ($needle !== '') {
                        $hay = fold(implode(' ', [$r['name'], $r['url'], (string)$r['site_name'],
                                                  (string)$r['domain'], (string)$r['cms'], (string)$r['group_name']]));
                        if (!str_contains($hay, $needle)) continue;
                    }
                    $out[] = [
                        'monitor_id' => (int)$r['id'], 'name' => $r['name'], 'url' => $r['url'],
                        'kind' => $r['kind'], 'state' => $r['status'], 'reason_code' => $r['reason_code'],
                        'message' => $r['last_message'] !== null ? verdict_text($r, 200) : null,
                        'response_ms' => $r['last_ms'] !== null ? (int)$r['last_ms'] : null,
                        'uptime_24h_pct' => $r['uptime_24h'] !== null ? round((float)$r['uptime_24h'], 2) : null,
                        'site' => $r['site_name'], 'domain' => $r['domain'], 'technology' => $r['cms'],
                        'group' => $r['group_name'] ?: null,
                        'interval_sec' => (int)$r['interval_sec'],
                        'enabled' => (int)$r['enabled'] === 1,
                        'last_check_at' => $r['last_check_at'],
                    ];
                    if (count($out) >= $limit) break;
                }
                return ['count' => count($out), 'monitors' => $out];
            },
        ],

        'monitor_detail' => [
            'title' => 'Everything known about one monitor',
            'desc'  => 'Full picture for a single monitor: diagnosis with remedy, availability over several '
                     . 'ranges, timings broken down (DNS, TLS, first byte), certificate and domain expiry, '
                     . 'the page-resource audit that detects a broken layout, recent incidents, and what '
                     . 'Uptimeez decided on its own. Use it to explain a problem in depth.',
            'schema' => ['type' => 'object', 'properties' => [
                'monitor_id' => ['type' => 'integer', 'description' => 'Identifier returned by list_monitors'],
            ], 'required' => ['monitor_id'], 'additionalProperties' => false],
            'write' => false,
            'run' => function (array $a): array {
                $id = (int)($a['monitor_id'] ?? 0);
                $m = Db::one('SELECT m.*, s.name AS site_name, s.domain, s.cms, s.group_name
                              FROM monitors m LEFT JOIN sites s ON s.id = m.site_id WHERE m.id = ?', [$id]);
                if (!$m) return ['error' => 'No monitor with id ' . $id];
                $diag = Diagnose::explain($m['reason_code'] !== null ? (string)$m['reason_code'] : null, $m);
                $w24  = Stats::window($id, 86400, $m);
                $w30  = Stats::window($id, 30 * 86400, $m);
                $css  = jdec($m['css_detail'] ?? null);
                $inc  = Db::all('SELECT started_at, ended_at, duration_sec, severity, reason_code, checks_failed
                                 FROM incidents WHERE monitor_id = ? ORDER BY id DESC LIMIT 10', [$id]);
                return [
                    'monitor_id' => $id, 'name' => $m['name'], 'url' => $m['url'], 'kind' => $m['kind'],
                    'site' => $m['site_name'], 'domain' => $m['domain'], 'technology' => $m['cms'],
                    'state' => $m['status'], 'enabled' => (int)$m['enabled'] === 1,
                    'since' => $m['status_since'], 'last_check_at' => $m['last_check_at'],
                    'diagnosis' => [
                        'reason_code' => $m['reason_code'],
                        'cause' => $diag['title'], 'why_it_matters' => $diag['why'], 'what_to_do' => $diag['fix'],
                        'technical_reading' => verdict_text($m),
                    ],
                    'availability' => [
                        '24h' => ['uptime_pct' => $w24['uptime'] !== null ? round((float)$w24['uptime'], 2) : null,
                                  'downtime_sec' => (int)$w24['downtime_sec'],
                                  'avg_ms' => $w24['avg_ms'], 'p95_ms' => $w24['p95_ms'],
                                  'checks' => (int)$w24['checks']],
                        '30d' => ['uptime_pct' => $w30['uptime'] !== null ? round((float)$w30['uptime'], 2) : null,
                                  'downtime_sec' => (int)$w30['downtime_sec'],
                                  'incidents' => (int)$w30['incidents']],
                    ],
                    'timings_ms' => ['dns' => $w24['dns_ms'], 'tls' => $w24['tls_ms'],
                                     'first_byte' => $w24['ttfb_ms'], 'worst' => $w24['worst_ms']],
                    'thresholds' => ['slow_ms' => (int)$m['slow_ms'], 'auto_tuned' => (int)($m['auto_slow'] ?? 0) === 1,
                                     'timeout_sec' => (int)$m['timeout_sec'], 'retries' => (int)$m['retries'],
                                     'interval_sec' => (int)$m['interval_sec']],
                    'certificate' => ['days_left' => $m['ssl_days_left'] !== null ? (int)$m['ssl_days_left'] : null,
                                      'issuer' => $m['ssl_issuer'] ?? null, 'expires_at' => $m['ssl_expires_at'] ?? null],
                    'domain_expires_at' => $m['domain_expires_at'] ?? null,
                    'proof_string' => $m['expect_string'],
                    'page_resources' => $css ? [
                        'verdict' => $m['css_state'],
                        'analysed_at' => $css['at'] ?? $m['css_checked_at'],
                        'messages' => array_slice($css['messages'] ?? [], 0, 8),
                        'browser_console' => array_map(fn($c) => $c['text'] ?? '',
                                                       array_slice($css['console'] ?? [], 0, 8)),
                        'stylesheets_loaded' => $css['sheets_ok'] ?? null,
                        'stylesheets_total' => $css['sheets_total'] ?? null,
                        'class_coverage_pct' => $css['coverage'] ?? null,
                    ] : null,
                    'silhouette' => [
                        'drift_pct' => (int)($m['silhouette_drift'] ?? 0),
                        'reference_at' => $m['silhouette_ref_at'] ?? null,
                        'current_at' => $m['silhouette_at'] ?? null,
                        'note' => 'A reconstruction of the page layout from HTML and loaded CSS, not a '
                                . 'screenshot. A drift above 35 % means a visitor sees a different page. '
                                . 'The SVG images are visible on the monitor page in the web interface.',
                    ],
                    'software' => array_map(fn($c) => [
                        'kind' => $c['kind'], 'name' => $c['name'], 'slug' => $c['slug'],
                        'version' => $c['version'], 'latest' => $c['latest'],
                        'behind_latest' => (int)$c['outdated'] === 1,
                        'published_vulnerabilities' => (int)$c['vuln_count'],
                        'worst_severity' => $c['worst'],
                        'advisories' => array_map(fn($a) => [
                            'id' => $a['id'] ?? null, 'severity' => $a['severity'] ?? null,
                            'published' => $a['published'] ?? null, 'url' => $a['url'] ?? null,
                            'summary' => $a['summary'] ?? null,
                        ], jdec($c['advisories'] ?? null)),
                        'checked_at' => $c['checked_at'],
                    ], !empty($m['site_id']) ? \Uptimeez\Vuln::forSite((int)$m['site_id']) : []),
                    'recent_incidents' => $inc,
                    'automatic_decisions' => \Uptimeez\Tune::decisions($m),
                ];
            },
        ],

        'security_advisories' => [
            'title' => 'Published vulnerabilities across the portfolio',
            'desc'  => 'Software detected on the monitored sites whose exact version is covered by a '
                     . 'published security advisory, worst severity first. Versions are read from the HTML '
                     . 'already fetched, so nothing extra is asked of the sites. Two signals are kept '
                     . 'strictly apart: "published vulnerability" means an identified advisory covers this '
                     . 'exact version, "behind latest" only means the version is older than the latest '
                     . 'release, which is a debt and not a vulnerability.',
            'schema' => ['type' => 'object', 'properties' => [
                'include_outdated' => ['type' => 'boolean',
                    'description' => 'Also list components merely behind the latest version (default false)'],
                'limit' => ['type' => 'integer', 'description' => 'Maximum rows, 1 to 100 (default 30)'],
            ], 'additionalProperties' => false],
            'write' => false,
            'run' => function (array $a): array {
                $limit = (int)max(1, min(100, (int)($a['limit'] ?? 30)));
                $where = (bool)($a['include_outdated'] ?? false)
                    ? 'c.vuln_count > 0 OR c.outdated = 1' : 'c.vuln_count > 0';
                $rows = Db::all("SELECT c.*, s.name AS site_name FROM components c
                                 JOIN sites s ON s.id = c.site_id WHERE $where
                                 ORDER BY c.vuln_count DESC,
                                          CASE c.worst WHEN 'high' THEN 0 WHEN 'medium' THEN 1
                                               WHEN 'low' THEN 2 ELSE 3 END, s.name ASC
                                 LIMIT " . $limit);
                $out = [];
                foreach ($rows as $c) {
                    $out[] = [
                        'site' => $c['site_name'], 'component' => $c['name'], 'kind' => $c['kind'],
                        'version' => $c['version'], 'latest' => $c['latest'],
                        'behind_latest' => (int)$c['outdated'] === 1,
                        'published_vulnerabilities' => (int)$c['vuln_count'],
                        'worst_severity' => $c['worst'],
                        'advisories' => jdec($c['advisories'] ?? null),
                    ];
                }
                $c = \Uptimeez\Vuln::counts();
                return ['summary' => $c, 'count' => count($out), 'components' => $out];
            },
        ],

        'web_vitals' => [
            'title' => 'Perceived speed, measured and explained',
            'desc'  => 'How fast the monitored pages feel, and why. Two clearly separated layers: field '
                     . 'measurements from real Chrome users (LCP, INP, CLS from the Chrome UX Report, only '
                     . 'when an API key is configured), and causes read from the HTML and files Uptimeez '
                     . 'already downloaded (server response time, render-blocking files, the top image and '
                     . 'its weight, images without dimensions, fonts without font-display, third-party '
                     . 'scripts). Nothing is estimated: if there is no field data, none is reported, and the '
                     . 'causes are labelled as causes, never as measurements.',
            'schema' => ['type' => 'object', 'properties' => [
                'monitor_id' => ['type' => 'integer', 'description' => 'Restrict to one monitor'],
                'poor_only' => ['type' => 'boolean',
                    'description' => 'Only pages with a poor field verdict or a high-severity cause (default false)'],
                'limit' => ['type' => 'integer', 'description' => 'Maximum rows, 1 to 60 (default 20)'],
            ], 'additionalProperties' => false],
            'write' => false,
            'run' => function (array $a): array {
                $limit = (int)max(1, min(60, (int)($a['limit'] ?? 20)));
                $where = ["m.enabled = 1", "(m.vitals_level IS NOT NULL OR m.field_verdict IS NOT NULL)"];
                $args  = [];
                if (!empty($a['monitor_id'])) { $where[] = 'm.id = ?'; $args[] = (int)$a['monitor_id']; }
                if (!empty($a['poor_only'])) $where[] = "(m.field_verdict = 'poor' OR m.vitals_level = 'bad')";
                $args[] = $limit;
                $rows = Db::all('SELECT m.id, m.name, m.url, m.vitals_level, m.vitals_detail, m.vitals_at,
                                        m.field_lcp_ms, m.field_inp_ms, m.field_cls, m.field_verdict,
                                        m.field_source, m.field_at, s.name AS site_name
                                 FROM monitors m LEFT JOIN sites s ON s.id = m.site_id
                                 WHERE ' . implode(' AND ', $where) . '
                                 ORDER BY CASE m.field_verdict WHEN \'poor\' THEN 0 WHEN \'improve\' THEN 1 ELSE 2 END,
                                          CASE m.vitals_level WHEN \'bad\' THEN 0 WHEN \'watch\' THEN 1 ELSE 2 END,
                                          m.id ASC
                                 LIMIT ?', $args);
                $out = [];
                foreach ($rows as $m) {
                    $d = jdec($m['vitals_detail'] ?? null);
                    $causes = [];
                    foreach ((array)($d['findings'] ?? []) as $f) {
                        $causes[] = [
                            'code' => $f['code'] ?? null, 'severity' => $f['severity'] ?? null,
                            'metric' => $f['metric'] ?? null,
                            'what' => t((string)($f['what'] ?? ''), (array)($f['vars'] ?? [])),
                            'fix' => $f['fix'] ?? null, 'evidence' => $f['evidence'] ?? null,
                        ];
                    }
                    $out[] = [
                        'monitor_id' => (int)$m['id'],
                        'site' => $m['site_name'] ?: $m['name'],
                        'url' => $m['url'],
                        'field' => $m['field_verdict'] === null ? null : [
                            'verdict' => $m['field_verdict'],
                            'lcp_ms' => $m['field_lcp_ms'] !== null ? (int)$m['field_lcp_ms'] : null,
                            'inp_ms' => $m['field_inp_ms'] !== null ? (int)$m['field_inp_ms'] : null,
                            'cls' => $m['field_cls'] !== null ? (float)$m['field_cls'] : null,
                            'scope' => $m['field_source'],   // url = cette page, origin = tout le site
                            'sampled_at' => $m['field_at'],
                            'source' => 'Chrome UX Report, real users, trailing 28 days',
                        ],
                        'server_response_ms' => $d['ttfb_ms'] ?? null,
                        'server_response_verdict' => $d['ttfb_verdict'] ?? null,
                        'render_blocking' => [
                            'stylesheets' => (int)($d['blocking']['css'] ?? 0),
                            'scripts' => (int)($d['blocking']['js'] ?? 0),
                            'bytes' => (int)($d['blocking']['bytes'] ?? 0),
                        ],
                        'top_image' => $d['lcp_image'] ?? null,
                        'local_level' => $m['vitals_level'],
                        'analysed_at' => $m['vitals_at'],
                        'causes' => $causes,
                    ];
                }
                return [
                    'field_data_available' => \Uptimeez\Vitals::enabled(),
                    'thresholds' => \Uptimeez\Vitals::THRESHOLDS,
                    'summary' => \Uptimeez\Vitals::counts(),
                    'count' => count($out), 'pages' => $out,
                ];
            },
        ],

        'list_clients' => [
            'title' => 'Clients and their read-only spaces',
            'desc'  => 'Agency view: every client, how many sites they own, the worst state across '
                     . 'those sites, their average 30-day uptime, and whether their read-only link is '
                     . 'open and being consulted. Use it for "which client should I call first?" or '
                     . '"who has not looked at their space in a month?". The link itself is not '
                     . 'returned: it opens a page without authentication, so it does not belong in a '
                     . 'transcript.',
            'schema' => ['type' => 'object', 'properties' => [
                'with_sites' => ['type' => 'boolean',
                    'description' => 'Also list each client\'s sites with their state (default false)'],
            ], 'additionalProperties' => false],
            'write' => false,
            'run' => function (array $a): array {
                $withSites = (bool)($a['with_sites'] ?? false);
                $out = [];
                foreach (\Uptimeez\Client::all() as $c) {
                    $ov  = $c['overview'];
                    $row = [
                        'id' => (int)$c['id'],
                        'name' => $c['name'],
                        'contact' => $c['contact_email'] ?: null,
                        'access_open' => (int)$c['enabled'] === 1,
                        'sites' => $ov['sites'],
                        'down' => $ov['down'],
                        'degraded' => $ov['degraded'],
                        'worst_state' => $ov['worst'],
                        'uptime_30d' => $ov['uptime'] !== null ? round($ov['uptime'], 3) : null,
                        'space_views' => (int)$c['views'],
                        'space_last_seen' => $c['last_seen_at'],
                    ];
                    if ($withSites) {
                        $row['site_list'] = array_map(fn(array $s): array => [
                            'name' => $s['name'], 'domain' => $s['domain'],
                            'state' => $s['status'] ?? 'unknown',
                            'uptime_30d' => $s['uptime_30d'] !== null ? round((float)$s['uptime_30d'], 3) : null,
                        ], \Uptimeez\Client::sites((int)$c['id']));
                    }
                    $out[] = $row;
                }
                $orphans = \Uptimeez\Client::orphanSites();
                return ['count' => count($out), 'clients' => $out,
                        'sites_without_client' => count($orphans)];
            },
        ],

        'incidents' => [
            'title' => 'Incident history',
            'desc'  => 'Outages over a period, with start, end, duration and cause. Use it for "how much '
                     . 'downtime did this client have last month?" or to justify an SLA.',
            'schema' => ['type' => 'object', 'properties' => [
                'monitor_id' => ['type' => 'integer', 'description' => 'Restrict to one monitor'],
                'days' => ['type' => 'integer', 'description' => 'How far back, 1 to 365 (default 30)'],
                'open_only' => ['type' => 'boolean', 'description' => 'Only incidents still open'],
                'limit' => ['type' => 'integer', 'description' => 'Maximum rows, 1 to 200 (default 50)'],
            ], 'additionalProperties' => false],
            'write' => false,
            'run' => function (array $a): array {
                $days  = (int)max(1, min(365, (int)($a['days'] ?? 30)));
                $limit = (int)max(1, min(200, (int)($a['limit'] ?? 50)));
                $where = ['i.started_at >= ?'];
                $args  = [date('Y-m-d H:i:s', time() - $days * 86400)];
                if (!empty($a['monitor_id'])) { $where[] = 'i.monitor_id = ?'; $args[] = (int)$a['monitor_id']; }
                if (!empty($a['open_only']))  { $where[] = 'i.ended_at IS NULL'; }
                $rows = Db::all('SELECT i.*, m.name, m.url FROM incidents i JOIN monitors m ON m.id = i.monitor_id
                                 WHERE ' . implode(' AND ', $where) . '
                                 ORDER BY i.started_at DESC LIMIT ' . $limit, $args);
                $total = 0;
                $out = [];
                foreach ($rows as $r) {
                    $dur = $r['ended_at'] ? (int)$r['duration_sec'] : max(0, time() - strtotime((string)$r['started_at']));
                    $total += $dur;
                    $out[] = [
                        'monitor_id' => (int)$r['monitor_id'], 'monitor' => $r['name'], 'url' => $r['url'],
                        'severity' => $r['severity'], 'reason_code' => $r['reason_code'],
                        'message' => str_cut((string)$r['message'], 200),
                        'started_at' => $r['started_at'], 'ended_at' => $r['ended_at'],
                        'duration_sec' => $dur, 'still_open' => $r['ended_at'] === null,
                        'failed_checks' => (int)$r['checks_failed'], 'alerts_sent' => (int)$r['notify_count'],
                    ];
                }
                return ['period_days' => $days, 'count' => count($out),
                        'total_downtime_sec' => $total, 'incidents' => $out];
            },
        ],

        'report' => [
            'title' => 'Ready-to-send report for one monitor',
            'desc'  => 'A plain-text report with diagnosis, remedy, timeline and availability figures, meant to '
                     . 'be pasted into a ticket or an e-mail to a client. Already contains the evidence.',
            'schema' => ['type' => 'object', 'properties' => [
                'monitor_id' => ['type' => 'integer'],
            ], 'required' => ['monitor_id'], 'additionalProperties' => false],
            'write' => false,
            'run' => function (array $a): array {
                $txt = Triage::report((int)($a['monitor_id'] ?? 0));
                if ($txt === '') return ['error' => 'No monitor with id ' . (int)($a['monitor_id'] ?? 0)];
                return ['report' => $txt];
            },
        ],

        'response_time_series' => [
            'title' => 'Response time and outages over time',
            'desc'  => 'Time series for one monitor: response time per bucket, plus how much of each bucket was '
                     . 'down or degraded. Use it to tell a one-off spike from a real trend.',
            'schema' => ['type' => 'object', 'properties' => [
                'monitor_id' => ['type' => 'integer'],
                'range' => ['type' => 'string', 'enum' => ['1h', '24h', '7d', '30d', '90d', '180d', '365d'],
                            'description' => 'Default 24h'],
            ], 'required' => ['monitor_id'], 'additionalProperties' => false],
            'write' => false,
            'run' => function (array $a): array {
                static $map = ['1h' => '1h', '24h' => '24h', '7d' => '7j', '30d' => '30j',
                               '90d' => '90j', '180d' => '180j', '365d' => '365j'];
                $range = $map[(string)($a['range'] ?? '24h')] ?? '24h';
                $id = (int)($a['monitor_id'] ?? 0);
                if (!Db::one('SELECT id FROM monitors WHERE id = ?', [$id])) {
                    return ['error' => 'No monitor with id ' . $id];
                }
                $s = Stats::series($id, Ui::rangeSeconds($range), Ui::rangeBuckets($range));
                return ['monitor_id' => $id, 'range' => $a['range'] ?? '24h',
                        'source' => $s['source'] ?? 'raw', 'points' => $s['points'] ?? $s];
            },
        ],

        // ---------------------------------------------------------- écriture
        'check_now' => [
            'title' => 'Check one monitor or everything immediately',
            'desc'  => 'Runs a real check right now instead of waiting for the next pass, and returns the fresh '
                     . 'verdict. Use it after a fix to confirm the site is back.',
            'schema' => ['type' => 'object', 'properties' => [
                'monitor_id' => ['type' => 'integer', 'description' => 'Omit to check every monitor that is due'],
            ], 'additionalProperties' => false],
            'write' => true,
            'run' => function (array $a): array {
                if (!empty($a['monitor_id'])) {
                    $id = (int)$a['monitor_id'];
                    $r = Runner::runOne($id);
                    if (!$r) return ['error' => 'No monitor with id ' . $id];
                    Stats::refresh($id);
                    return ['monitor_id' => $id, 'state' => $r['state'],
                            'reason_code' => $r['reason'] ?? null, 'message' => $r['message'] ?? ''];
                }
                $r = Runner::runDue(300, 600);
                return ['checked' => (int)$r['ran'], 'down' => (int)$r['down'],
                        'degraded' => (int)$r['degraded'], 'up' => (int)$r['up']];
            },
        ],

        'apply_fix' => [
            'title' => 'Apply one of the offered fixes',
            'desc'  => 'Applies a remedy that the tasks tool listed under available_fixes. '
                     . '"relearn" forgets the CSS reference after an intentional redesign, "raise_slow" retunes '
                     . 'the slowness threshold on the measured p95, "ignore_noindex" stops watching noindex, '
                     . '"adopt_url" points the monitor at the address it now redirects to, "snooze" pauses for '
                     . 'an hour, "ack" stops alert reminders without closing the incident.',
            'schema' => ['type' => 'object', 'properties' => [
                'monitor_id' => ['type' => 'integer'],
                'fix' => ['type' => 'string',
                          'enum' => ['relearn', 'raise_slow', 'ignore_noindex', 'adopt_url', 'snooze', 'ack']],
            ], 'required' => ['monitor_id', 'fix'], 'additionalProperties' => false],
            'write' => true,
            'run' => function (array $a): array {
                $id  = (int)($a['monitor_id'] ?? 0);
                $fix = (string)($a['fix'] ?? '');
                $m = Db::one('SELECT * FROM monitors WHERE id = ?', [$id]);
                if (!$m) return ['error' => 'No monitor with id ' . $id];
                switch ($fix) {
                    case 'relearn':
                        Db::update('monitors', ['css_baseline' => null, 'css_baseline_at' => null,
                                                'css_checked_at' => null, 'css_state' => null,
                                                'silhouette_ref' => null, 'silhouette_ref_sig' => null,
                                                'silhouette_ref_at' => null, 'silhouette_drift' => 0],
                                   'id = :__i', ['__i' => $id]);
                        return ['done' => true,
                                'effect' => 'CSS reference and page silhouette cleared, relearned on next analysis'];
                    case 'raise_slow':
                        $w = Stats::window($id, 7 * 86400, $m);
                        $base = max((int)($w['p95_ms'] ?? 0), (int)($m['last_ms'] ?? 0), (int)$m['slow_ms']);
                        $new  = (int)min(60000, max(1000, ceil($base * 1.4 / 100) * 100));
                        Db::update('monitors', ['slow_ms' => $new], 'id = :__i', ['__i' => $id]);
                        return ['done' => true, 'effect' => 'Slowness threshold set to ' . $new . ' ms'];
                    case 'ignore_noindex':
                        Db::update('monitors', ['check_noindex' => 0], 'id = :__i', ['__i' => $id]);
                        return ['done' => true, 'effect' => 'Noindex monitoring disabled on this monitor'];
                    case 'adopt_url':
                        $last = (string)Db::val('SELECT final_url FROM checks WHERE monitor_id = ?
                                                 AND final_url IS NOT NULL ORDER BY id DESC LIMIT 1', [$id], '');
                        if ($last === '' || $last === $m['url']) {
                            return ['done' => false, 'effect' => 'No destination URL recorded yet'];
                        }
                        Db::update('monitors', ['url' => $last], 'id = :__i', ['__i' => $id]);
                        return ['done' => true, 'effect' => 'Monitor now points at ' . $last];
                    case 'snooze':
                        Db::update('monitors', ['paused_until' => date('Y-m-d H:i:s', time() + 3600)],
                                   'id = :__i', ['__i' => $id]);
                        return ['done' => true, 'effect' => 'Paused for one hour'];
                    case 'ack':
                        $inc = Db::one('SELECT id FROM incidents WHERE monitor_id = ? AND ended_at IS NULL
                                        ORDER BY id DESC LIMIT 1', [$id]);
                        if (!$inc) return ['done' => false, 'effect' => 'No open incident'];
                        Db::update('incidents', ['ack_at' => now()], 'id = :__i', ['__i' => (int)$inc['id']]);
                        return ['done' => true, 'effect' => 'Incident acknowledged, reminders stopped'];
                }
                return ['error' => 'Unknown fix: ' . $fix];
            },
        ],

        'set_enabled' => [
            'title' => 'Pause or resume a monitor',
            'desc'  => 'Pausing keeps the monitor and its history but stops checking it. Use it for a site '
                     . 'being rebuilt, rather than deleting anything.',
            'schema' => ['type' => 'object', 'properties' => [
                'monitor_id' => ['type' => 'integer'],
                'enabled' => ['type' => 'boolean'],
            ], 'required' => ['monitor_id', 'enabled'], 'additionalProperties' => false],
            'write' => true,
            'run' => function (array $a): array {
                $id = (int)($a['monitor_id'] ?? 0);
                if (!Db::one('SELECT id FROM monitors WHERE id = ?', [$id])) {
                    return ['error' => 'No monitor with id ' . $id];
                }
                $on = (bool)($a['enabled'] ?? true);
                Db::update('monitors', [
                    'enabled' => $on ? 1 : 0,
                    'status' => $on ? 'unknown' : 'paused',
                    'paused_until' => null,
                    'next_check_at' => $on ? now() : null,
                    'last_message' => $on ? null : 'Monitoring suspended',
                    'reason_code' => null, 'status_since' => now(),
                ], 'id = :__i', ['__i' => $id]);
                return ['monitor_id' => $id, 'enabled' => $on];
            },
        ],

        'add_sites' => [
            'title' => 'Add sites from a pasted list',
            'desc'  => 'Accepts anything: a list of domains, a spreadsheet column, an e-mail with addresses in '
                     . 'it. Uptimeez extracts the addresses, drops duplicates, then detects the technology, picks '
                     . 'representative pages, infers the proof string and tunes the thresholds by itself. '
                     . 'Call it with dry_run first: it returns exactly what would be created without creating it.',
            'schema' => ['type' => 'object', 'properties' => [
                'list' => ['type' => 'string', 'description' => 'One entry per line, or free text containing addresses. '
                                                              . 'Explicit form: url | name | proof string'],
                'dry_run' => ['type' => 'boolean', 'description' => 'Preview only, create nothing (default true)'],
                'follow_pages' => ['type' => 'boolean', 'description' => 'Also add representative pages (default true)'],
            ], 'required' => ['list'], 'additionalProperties' => false],
            'write' => true,
            'run' => function (array $a): array {
                $list = (string)($a['list'] ?? '');
                $dry  = (bool)($a['dry_run'] ?? true);
                if (trim($list) === '') return ['error' => 'Empty list'];
                $pages = (bool)($a['follow_pages'] ?? true) ? 4 : 1;
                if ($dry) {
                    $p = Importer::preview($list, ['pages' => $pages]);
                    $rows = [];
                    foreach ($p['rows'] as $r) {
                        $rows[] = ['url' => $r['url'], 'name' => $r['name'] ?? null,
                                   'domain' => $r['domain'] ?? null,
                                   'already_present' => (bool)($r['exists'] ?? false),
                                   'interval_sec' => (int)($r['interval'] ?? 0),
                                   'pages_to_follow' => (int)($r['pages'] ?? 0),
                                   'proof_string' => $r['proof'] ?? '(inferred on first pass)'];
                    }
                    $new = 0;
                    foreach ($rows as $r) if (!$r['already_present']) $new++;
                    return ['dry_run' => true, 'would_create' => $new,
                            'already_present' => (int)($p['existing'] ?? 0),
                            'rows' => $rows, 'lines_ignored' => $p['errors'] ?? []];
                }
                $parsed = Importer::parse($list);
                if (!$parsed['rows']) {
                    return ['error' => 'No usable address in that text',
                            'lines_ignored' => array_slice($parsed['errors'], 0, 5)];
                }
                $r = Importer::createMonitors($parsed['rows'], [
                    'discover' => 1, 'extras' => 1, 'pages' => $pages,
                    'check_css' => 1, 'check_db' => 1, 'check_ssl' => 1, 'check_noindex' => 1,
                ]);
                return ['dry_run' => false, 'created' => (int)$r['created'],
                        'already_present' => (int)$r['skipped'],
                        'monitor_ids' => array_values($r['ids']),
                        'lines_ignored' => $r['errors'],
                        'note' => 'Technology, pages and proof strings are inferred on the next collector pass.'];
            },
        ],

        'security_target_check' => [
            'title' => 'Would this URL be refused?',
            'desc'  => 'Tells whether an address would be rejected before any request: a non-HTTP scheme, or a '
                     . 'private range when the optional SSRF guard is on. Useful before adding a target.',
            'schema' => ['type' => 'object', 'properties' => [
                'url' => ['type' => 'string'],
            ], 'required' => ['url'], 'additionalProperties' => false],
            'write' => false,
            'run' => function (array $a): array {
                $raw = (string)($a['url'] ?? '');
                $norm = normalize_url($raw);
                if ($norm === null) {
                    return ['allowed' => false, 'normalized' => null,
                            'reason' => 'Not an http(s) address, or malformed host'];
                }
                $why = Http::blockedReason($norm);
                return ['allowed' => $why === null, 'normalized' => $norm, 'reason' => $why,
                        'private_range_guard' => (bool)Config::get('security.block_private_ranges', false)];
            },
        ],
    ];
}

// =========================================================================
// Boucle JSON-RPC sur stdio
// =========================================================================
function mcp_send(array $msg): void
{
    echo json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    flush();
}
function mcp_result(mixed $id, array $result): void
{
    mcp_send(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
}
function mcp_error(mixed $id, int $code, string $message): void
{
    mcp_send(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]);
}
/** Un résultat d'outil MCP : du texte que le modèle lit, et la donnée structurée. */
function mcp_content(array $data): array
{
    return [
        'content' => [['type' => 'text',
                       'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                                                  | JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR)]],
        'isError' => isset($data['error']),
    ];
}

$tools = mcp_tools();

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') continue;

    $req = json_decode($line, true);
    if (!is_array($req)) { mcp_error(null, -32700, 'Parse error'); continue; }

    $id     = $req['id'] ?? null;
    $method = (string)($req['method'] ?? '');
    $params = is_array($req['params'] ?? null) ? $req['params'] : [];

    // Une notification n'attend pas de réponse.
    $isNotification = !array_key_exists('id', $req);

    switch ($method) {
        case 'initialize':
            mcp_result($id, [
                'protocolVersion' => MCP_PROTOCOL,
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'uptimeez', 'version' => MCP_VERSION],
                'instructions' =>
                    "Uptimeez watches websites and says what to do about them.\n"
                  . "Call \"tasks\" first: it returns the to-do list with, for each problem, the cause in plain "
                  . "words, why it matters, what to do, and the evidence.\n"
                  . "\"status\" answers \"is everything fine?\" in one call. \"monitor_detail\" explains one site "
                  . "in depth, including the page-resource audit that catches a broken layout behind an HTTP 200.\n"
                  . ($GLOBALS['WRITE']
                        ? "Writing tools are enabled: check_now, apply_fix, set_enabled, add_sites. "
                        . "Always call add_sites with dry_run first and show the preview before creating anything."
                        : "This server is read-only. Restart it with --write to allow checks, fixes and imports."),
            ]);
            break;

        case 'notifications/initialized':
            break;   // rien à répondre

        case 'ping':
            mcp_result($id, []);
            break;

        case 'tools/list':
            $list = [];
            foreach ($tools as $name => $t) {
                if ($t['write'] && !$WRITE) continue;
                $list[] = [
                    'name' => $name,
                    'title' => $t['title'],
                    'description' => $t['desc'],
                    'inputSchema' => $t['schema'],
                    'annotations' => [
                        'readOnlyHint' => !$t['write'],
                        'destructiveHint' => false,
                        'idempotentHint' => !$t['write'],
                        'openWorldHint' => $t['write'],
                    ],
                ];
            }
            mcp_result($id, ['tools' => $list]);
            break;

        case 'tools/call':
            $name = (string)($params['name'] ?? '');
            $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
            if (!isset($tools[$name])) {
                mcp_error($id, -32602, 'Unknown tool: ' . $name);
                break;
            }
            if ($tools[$name]['write'] && !$WRITE) {
                mcp_result($id, mcp_content(['error' => 'This server runs read-only. '
                    . 'Restart it with --write to allow "' . $name . '".']));
                break;
            }
            try {
                mcp_result($id, mcp_content(($tools[$name]['run'])($args)));
            } catch (Throwable $e) {
                // Un outil qui échoue ne doit pas tuer le serveur : l'agent doit
                // pouvoir enchaîner sur un autre appel.
                mcp_result($id, mcp_content(['error' => get_class($e) . ': ' . $e->getMessage()]));
            }
            break;

        case 'resources/list':
            mcp_result($id, ['resources' => []]);
            break;

        case 'prompts/list':
            mcp_result($id, ['prompts' => []]);
            break;

        default:
            if (!$isNotification) mcp_error($id, -32601, 'Method not found: ' . $method);
    }
}
