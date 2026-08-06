<?php
declare(strict_types=1);

namespace Uptimeez\Notify;

use Uptimeez\Config;
use Uptimeez\Db;

/**
 * Sending alerts: Discord, Slack, e-mail, generic webhook.
 * Guards: quiet hours (critical incidents still get through), repetition control,
 * and a journal entry for every send.
 */
final class Notifier
{
    /**
     * Below this, a recovery is not announced: the incident did not last one pass.
     * See sendRecovery() and the plan of 2026-08-04, defect D4.
     */
    public const PLANCHER_RETABLISSEMENT = 60;

    public const COLORS = [
        'down'     => 0xE5484D,
        'degraded' => 0xF5A524,
        'up'       => 0x30A46C,
        'info'     => 0x5B7FFF,
    ];

    /**
     * AN INCIDENT'S DETAIL, WITH ITS VARIABLES SUBSTITUTED.
     *
     * ------------------------------------------------------------------------------
     * THE DEFECT THIS FUNCTION REPAIRS, AND IT HAD BEEN THERE FROM THE START
     * ------------------------------------------------------------------------------
     *
     * On 2026-08-04 the operator showed his inbox: every e-mail carried the TEMPLATE instead
     * of the value. "Broken layout: {detail}", "Response time high: {seconds} s", "Server
     * error {code}: the site is not answering properly". Hundreds of messages, all unreadable
     * on the one line that says what to do.
     *
     * The cause: the stored message is a SOURCE phrase, and its variables live beside it in
     * "message_vars". The screen goes through verdict_text(), which applies both; the e-mail
     * read "message" on its own. Two paths to one rendering, only one of them right, which is
     * exactly the shape of the defect that had produced two contradictory certificate verdicts
     * in July.
     *
     * NO TEST COULD SEE IT, because none read a RENDERED e-mail: they checked that a send
     * happened, not what it contained. The check added to selftest now refuses a brace inside
     * an incident detail.
     *
     * @param array<string,mixed> $incident
     */
    public static function detailIncident(array $incident): string
    {
        $vars = $incident['message_vars'] ?? null;
        $vars = is_array($vars) ? $vars : (is_string($vars) ? (array) jdec($vars) : []);

        return str_cut(t((string) ($incident['message'] ?? ''), $vars), 300);
    }

    /**
     * AN APPEARANCE CAUSE NO LONGER LEAVES BY E-MAIL. Operator's call, 2026-08-04.
     *
     * ------------------------------------------------------------------------------
     * WHY THIS GATE EXISTS, AND WHAT IT DOES NOT CLOSE
     * ------------------------------------------------------------------------------
     *
     * His words: "at a pinch we can have a remark in the tool without an e-mail". He had
     * opened the reported sites: not one had a stylesheet problem. So the detector was wrong
     * in bulk, and it would keep being wrong until sprint 2 found out why. Its error simply
     * stops reaching an inbox.
     *
     * WHAT STAYS INTACT: the monitor keeps its state, the screen keeps its warning, the
     * journal keeps its line. A remark visible in the tool has never woken anyone at three in
     * the morning, and that is the whole difference.
     *
     * WHAT IS NOT COVERED HERE: recovery. An appearance incident that closes sends nothing
     * either, since its opening sent nothing. See sendRecovery().
     */
    public static function causeSilencieuse(?string $cause): bool
    {
        return \Uptimeez\Regle\Verdict::estUneApparence($cause);
    }

    public static function sendIncident(array $mon, array $incident, string $nature = 'nouveau'): void
    {
        // The gate, before any message is built: an appearance cause does not leave.
        if (self::causeSilencieuse($incident['reason_code'] ?? null)) {
            self::log($mon, 'silencieux', (string) ($incident['severity'] ?? 'degraded'), false,
                (string) ($incident['reason_code'] ?? '') . ' · no-mail');

            return;
        }

        $sev   = $incident['severity'] === 'degraded' ? 'degraded' : 'down';

        // THREE NATURES AND NOT TWO, BECAUSE "NOT NEW" IS NOT "NOTHING NEW".
        //
        // This parameter used to be an "isNew" boolean, and anything that was not new went
        // out titled "Still down". But an incident getting WORSE, when a monitor moves from
        // DEGRADED to DOWN, is NEW information: it is the moment a slowdown becomes an
        // outage. It arrived announced as a repetition, therefore among messages the reader
        // has learned not to open. The one reminder worth reading was the best disguised as
        // noise.
        //
        // The periodic reminder, on the other hand, says nothing new by construction: it
        // repeats an incident already announced and already visible on screen. It stays
        // available ("notify.resend_after_min"), but it is now the only one carrying the 🔁.
        $titres = [
            'nouveau' => ($sev === 'down' ? '🔴 ' . t('HORS SERVICE') : '🟠 ' . t('DÉGRADÉ')),
            'aggrave' => '🔴 ' . t('AGGRAVÉ : la panne est maintenant totale'),
            'rappel'  => '🔁 ' . ($sev === 'down' ? t('Toujours hors service') : t('Toujours dégradé')),
        ];
        // The title goes out in an alert: it follows the installation's language, like
        // everything else the collector writes.
        $title = ($titres[$nature] ?? $titres['nouveau']) . ' : ' . $mon['name'];
        $isNew = $nature === 'nouveau';

        $lines = [
            [t('Cause'), self::reasonLabel($incident['reason_code'])],
            [t('Détail'), self::detailIncident($incident)],
            ['URL', $mon['url']],
            [t('Depuis'), self::when($incident['started_at']) . ' ('
                . human_duration(max(0, time() - strtotime((string)$incident['started_at']))) . ')'],
        ];
        if (!$isNew) $lines[] = [t('Échecs consécutifs'), (string)$incident['checks_failed']];

        $sent = self::dispatch($mon, $sev, $title, $lines, $sev === 'down' ? 'critical' : 'warn');

        if ($sent > 0) {
            Db::update('incidents', [
                'last_notified_at' => now(),
                'notify_count'     => (int)$incident['notify_count'] + 1,
            ], 'id = :__i', ['__i' => (int)$incident['id']]);
        }
    }

    /**
     * Escalation: nobody acknowledged, so somebody else gets told.
     *
     * ------------------------------------------------------------------------------
     * WHAT ESCALATION IS, AND WHAT IT IS NOT
     * ------------------------------------------------------------------------------
     *
     * It is not a reminder. A reminder repeats the same alert to the same people, and when
     * nobody is watching its only effect is to lengthen a thread nobody reads. Escalation
     * changes RECIPIENT: it goes to a separate list of channels, after a delay, once. That is
     * the difference between insisting and handing over.
     *
     * ------------------------------------------------------------------------------
     * THREE CONDITIONS, AND EACH ONE COST SOMETHING SOMEWHERE ELSE
     * ------------------------------------------------------------------------------
     *
     * 1. ONCE PER INCIDENT. The "escalated_at" column holds that state. Without it, an
     *    unacknowledged incident would escalate again on every collector pass, and the person
     *    on call would get one alert per minute: the mechanism meant to make someone react
     *    would become the reason they mute their notifications.
     *
     * 2. OUTAGES ONLY. A "worth watching" state does not wake a second team. A slowdown, or a
     *    certificate expiring in ten days, has never justified getting anyone out of bed, and
     *    escalating it would cost escalation the only credit that makes it useful.
     *
     * 3. ACKNOWLEDGEMENT CANCELS IT. If someone said "I am on it", escalation has no purpose
     *    left. It is also the only honest way to close the loop: the acknowledge button
     *    already existed and only served to silence reminders.
     *
     * ------------------------------------------------------------------------------
     * WHY IT GETS THROUGH QUIET HOURS
     * ------------------------------------------------------------------------------
     *
     * It goes out with "critical" urgency, like any real outage. An escalation held back until
     * seven in the morning is not an escalation, it is a report.
     *
     * @param array<string,mixed> $mon
     * @param array<string,mixed> $incident
     * @return bool true if at least one channel received the alert
     */
    public static function sendEscalation(array $mon, array $incident): bool
    {
        $canaux = self::escalationChannelsFor($mon);

        if ($canaux === []) {
            // No channel: we SAY SO in the journal rather than letting anyone believe the
            // escalation happened. An on-call setup half configured is worse than none,
            // because people count on it.
            self::log($mon, 'escalade', 'down', false,
                t('Aucun canal d\'escalade utilisable : rien n\'a été envoyé.'));

            return false;
        }

        $depuis = max(0, time() - strtotime((string) $incident['started_at']));

        $titre = '🚨 ' . t('ESCALADE : personne n\'a acquitté') . ' : ' . $mon['name'];
        $lignes = [
            [t('Cause'), self::reasonLabel($incident['reason_code'])],
            [t('Détail'), self::detailIncident($incident)],
            ['URL', $mon['url']],
            [t('Hors service depuis'), human_duration($depuis)],
            [t('Alertes déjà envoyées'), (string) (int) $incident['notify_count']],
        ];

        $envoyes = self::dispatchVers($canaux, $mon, 'down', $titre, $lignes);

        return $envoyes > 0;
    }

    /**
     * The escalation channels: a separate list, otherwise every active channel.
     *
     * SENDING THE SAME ALERT TWICE ON THE SAME CHANNEL WARNS NOBODY EXTRA, but neither can we
     * guess who is on call. An empty setting therefore falls back to the active channels,
     * which makes escalation useful the moment it is switched on, without demanding a second
     * full configuration.
     *
     * @param array<string,mixed> $mon
     * @return array<int,string>
     */
    public static function escalationChannelsFor(array $mon): array
    {
        $voulus = array_values(array_filter(array_map('trim',
            explode(',', (string) Config::get('notify.escalate_channels', '')))));

        if ($voulus === []) {
            return self::channelsFor($mon);
        }

        return array_values(array_intersect(self::channelsFor($mon), $voulus));
    }

    /**
     * Grouped alert: several sites down at the same time on the same infrastructure. One
     * message, which names the server and lists the sites.
     *
     * @param array<int,array{monitor:array,incident:array}> $items
     * @param string $scope shared IP or domain
     * @param bool   $isIp  true when the grouping is based on the IP address
     */
    public static function sendGrouped(array $items, string $scope, bool $isIp): void
    {
        $sites = [];
        $causes = [];
        $since  = time();
        foreach ($items as $it) {
            $mon = $it['monitor'];
            $sites[(string)($mon['site_id'] ?: 'm' . $mon['id'])] =
                (string)($mon['name'] ?? host_of((string)$mon['url']));
            $causes[self::reasonLabel($it['incident']['reason_code'] ?? null)] = true;
            $since = min($since, strtotime((string)$it['incident']['started_at']));
        }
        $n     = count($sites);
        $title = '🔴 ' . t('PANNE GROUPÉE') . ' : '
               . tn($n, 'un site injoignable', '{n} sites injoignables');

        $names = array_values($sites);
        $liste = implode(', ', array_slice($names, 0, 12));
        if (count($names) > 12) {
            $liste .= ' ' . tn(count($names) - 12, 'et un autre', 'et {n} autres');
        }

        $lines = [
            [$isIp ? t('Serveur concerné') : t('Domaine concerné'), $scope],
            [t('Sites touchés'), $n . ' : ' . $liste],
            [t('Sondes en échec'), (string)count($items)],
            [t('Causes relevées'), implode(' · ', array_keys($causes))],
            [t('Début'), self::when(date('Y-m-d H:i:s', $since))],
            [t('Lecture'), $isIp
                ? t('Toutes ces adresses pointent sur la même machine : le problème est très probablement au niveau du serveur ou de l\'hébergement, pas des sites eux-mêmes.')
                : t('Ces sites partagent le même domaine : vérifiez la configuration commune, DNS, certificat, redirections.')],
        ];

        $mon0 = $items[0]['monitor'];
        $sent = self::dispatch($mon0, 'down', $title, $lines, 'critical');

        if ($sent > 0) {
            foreach ($items as $it) {
                Db::update('incidents', [
                    'last_notified_at' => now(),
                    'notify_count'     => (int)$it['incident']['notify_count'] + 1,
                ], 'id = :__i', ['__i' => (int)$it['incident']['id']]);
            }
        }
        try {
            Db::insert('events', [
                'monitor_id' => (int)($mon0['id'] ?? 0) ?: null,
                'ts' => now(), 'kind' => 'grouped_alert',
                'message' => $isIp
                    ? tn($n, 'un site injoignable sur le serveur {scope}',
                             '{n} sites injoignables sur le serveur {scope}', ['scope' => $scope])
                    : tn($n, 'un site injoignable sur le domaine {scope}',
                             '{n} sites injoignables sur le domaine {scope}', ['scope' => $scope]),
                'details' => jenc(['scope' => $scope, 'sites' => $names]), 'seen' => 0,
            ]);
        } catch (\Throwable) {}
    }

    /** Back to normal. */
    public static function sendRecovery(array $mon, array $incident): void
    {
        if (!Config::get('notify.notify_recovery', true)) return;
        $dur   = (int)($incident['duration_sec'] ?? 0);

        // D2. Nothing was sent when it opened, so nothing leaves when it closes: a
        // "RECOVERED" with no prior alert announces the end of a problem the reader never
        // heard about.
        if (self::causeSilencieuse($incident['reason_code'] ?? null)) {
            return;
        }

        // D4. A ZERO-SECOND INCIDENT DOES NOT NEED TO BE ANNOUNCED AS RECOVERED.
        //
        // The screenshots of 2026-08-04 carried "Downtime 0 s" on almost every recovery: the
        // check sees a defect, the next pass no longer sees it, and two e-mails go out for an
        // incident that never lasted. Below this floor, the recovery stays in the tool and in
        // the journal.
        //
        // Sixty seconds, because that is the minimum cadence of a pass: below it the incident
        // did not survive a single interval, so it described nothing stable.
        if ($dur < self::PLANCHER_RETABLISSEMENT) {
            self::log($mon, 'silencieux', 'up', false, $dur . 's · no-mail');

            return;
        }
        $title = '🟢 ' . t('RÉTABLI') . ' : ' . $mon['name'];
        $lines = [
            [t('Indisponibilité'), human_duration($dur)],
            [t('Cause initiale'), self::reasonLabel($incident['reason_code'] ?? null)],
            ['URL', $mon['url']],
        ];
        self::dispatch($mon, 'up', $title, $lines, 'info');
    }

    /** Évènement informatif (mot surveillé, contenu modifié, domaine qui expire…). */
    public static function sendEvent(array $mon, string $kind, string $message): void
    {
        $icons = ['watch_hit' => '👀', 'content_changed' => '📝', 'domain_soon' => '📆',
                  'css_changed' => '🎨', 'watch_change' => '👀'];
        $title = ($icons[$kind] ?? 'ℹ️') . ' ' . self::eventLabel($kind) . ' : ' . $mon['name'];
        self::dispatch($mon, 'info', $title, [['Détail', $message], ['URL', $mon['url']]], 'info');
    }

    /**
     * Sends on every active channel. Returns the number of successful sends.
     * $urgency: critical (gets through quiet hours) | warn | info
     */
    public static function dispatch(array $mon, string $sev, string $title, array $lines, string $urgency = 'warn'): int
    {
        if ($urgency !== 'critical' && self::inQuietHours()) {
            self::log($mon, 'quiet', $sev, false, t('Heures calmes : envoi différé ou ignoré'));
            return 0;
        }
        if ($urgency === 'warn' && !Config::get('notify.notify_degraded', true)) return 0;

        return self::dispatchVers(self::channelsFor($mon), $mon, $sev, $title, $lines);
    }

    /**
     * Sending to an IMPOSED list of channels, without going through the sending policy again.
     *
     * Extracted from dispatch() on 2026-08-03 for escalation, which has to choose its own
     * recipients. Quiet hours and the "warn on a watch state" setting stay in dispatch(): those
     * are decisions about WHETHER to alert, and escalation has already made them by the time it
     * gets here.
     *
     * @param array<int,string>   $canaux
     * @param array<string,mixed> $mon
     * @param array<int,array{0:string,1:string}> $lines
     */
    private static function dispatchVers(array $canaux, array $mon, string $sev, string $title, array $lines): int
    {
        // ------------------------------------------------------------------------------
        // A TEST SUITE NEVER SENDS A REAL ALERT. Added on 2026-08-04, after sending the
        // operator two false alerts.
        // ------------------------------------------------------------------------------
        //
        // WHAT HAPPENED, AND IT IS ENTIRELY MY DOING. I ran bin/selftest.php with
        // UPTIMEEZ_CONFIG pointed at the live portfolio's configuration, to check the engine
        // was fine after an update. But the suite creates a heartbeat monitor named "Nightly
        // backup", backdates its last signal by two hours, and calls Heartbeat::sweep() to
        // exercise silence detection. That sweep did exactly what it should: it opened an
        // incident and SENT THE ALERT, through the installation's real e-mail channel. Two
        // runs, two e-mails, about a monitor that does not exist.
        //
        // The suite cleans its fixtures up behind it: the monitors and incidents were gone by
        // the time I looked. The e-mail had left. That is the worst state of all, because
        // nothing remains to understand it from.
        //
        // WHY SILENT AND NOT REFUSED. The README asks you to run the suite after every update,
        // on the installation you have just updated: refusing to run when a channel is
        // configured would make that advice impossible to follow. So the whole path stays, the
        // journal included, and only the sending does not happen.
        if (defined('UPTIMEEZ_SUITE') && UPTIMEEZ_SUITE) {
            foreach ($canaux as $ch) {
                self::log($mon, $ch, $sev, false, 'suite · no-mail');
            }

            return 0;
        }

        $ok = 0;
        foreach ($canaux as $ch) {
            // The class comes from the registry: a channel added to CANAUX is sent without
            // touching anything here, and an unknown channel is journalled rather than
            // silently skipped.
            $classe = self::CANAUX[$ch]['classe'] ?? null;
            $res = $classe === null
                ? ['ok' => false, 'info' => t('Canal inconnu')]
                : $classe::send($title, $lines, $sev, $mon);
            if (!empty($res['ok'])) $ok++;
            self::log($mon, $ch, $sev, (bool)($res['ok'] ?? false), (string)($res['info'] ?? ''));
        }
        return $ok;
    }

    /**
     * THE CHANNEL REGISTRY: one declaration where there used to be six.
     *
     * ------------------------------------------------------------------------------
     * WHY THIS CONSTANT EXISTS
     * ------------------------------------------------------------------------------
     *
     * Before 2026-08-03 the list of channels was hard-coded in six places: here twice, in the
     * settings screen's selector, in the saving of those settings, in the test button, and in
     * the public demo's lock. Adding Telegram therefore took six edits, none of which would
     * report the five others being forgotten, and the likeliest omission is the demo lock: a
     * channel escaping Demo::silenced() would send real messages from a public installation
     * whose password is printed in the documentation.
     *
     * "requis" DOES NOT MEAN "CONFIGURED", IT MEANS "USABLE". An enabled channel with an empty
     * URL will send nothing: declaring it usable would count a send that never happened, and
     * the screen would announce an alert as gone out. Every listed key must therefore hold a
     * non-empty value, and that is the only condition.
     *
     * THE ORDER IS THE DISPLAY ORDER, and it is not alphabetical: the two original channels
     * first, the three later ones next, e-mail and the generic webhook last because they are
     * fallbacks rather than choices.
     *
     * @var array<string, array{classe: class-string, libelle: string, requis: array<int, string>}>
     */
    public const CANAUX = [
        'discord'  => ['classe' => Discord::class,  'libelle' => 'Discord',
                       'requis' => ['notify.discord.webhook']],
        'slack'    => ['classe' => Slack::class,    'libelle' => 'Slack',
                       'requis' => ['notify.slack.webhook']],
        'telegram' => ['classe' => Telegram::class, 'libelle' => 'Telegram',
                       'requis' => ['notify.telegram.token', 'notify.telegram.chat_id']],
        'teams'    => ['classe' => Teams::class,    'libelle' => 'Microsoft Teams',
                       'requis' => ['notify.teams.webhook']],
        'sms'      => ['classe' => Sms::class,      'libelle' => 'SMS',
                       'requis' => ['notify.sms.sid', 'notify.sms.token',
                                    'notify.sms.from', 'notify.sms.to']],
        'mail'     => ['classe' => Mail::class,     'libelle' => 'E-mail',
                       'requis' => ['notify.mail.to']],
        'webhook'  => ['classe' => Webhook::class,  'libelle' => 'Webhook',
                       'requis' => ['notify.webhook.url']],
    ];

    /** Channels kept: the monitor's own setting, otherwise the active global channels. */
    public static function channelsFor(array $mon): array
    {
        $perMon = trim((string)($mon['notify_channels'] ?? ''));
        $wanted = $perMon !== '' ? array_filter(array_map('trim', explode(',', $perMon))) : null;

        $out = [];
        foreach (self::CANAUX as $ch => $def) {
            if (!Config::get("notify.$ch.enabled", false)) continue;
            if ($wanted !== null && !in_array($ch, $wanted, true)) continue;
            if (!self::utilisable($ch)) continue;
            $out[] = $ch;
        }
        return $out;
    }

    /** A channel is usable when every setting it requires holds a value. */
    public static function utilisable(string $canal): bool
    {
        foreach (self::CANAUX[$canal]['requis'] ?? [] as $cle) {
            if (trim((string) Config::get($cle, '')) === '') {
                return false;
            }
        }

        return isset(self::CANAUX[$canal]);
    }

    public static function inQuietHours(): bool
    {
        return self::quietHoursCover((string)Config::get('notify.quiet_hours', ''),
                                     (int)date('H') * 60 + (int)date('i'));
    }

    /**
     * Does the quiet range cover this minute of the day?
     *
     * Separated from the current time so it can be checked: this is exactly the kind of
     * arithmetic whose error only shows on one night out of two. A range straddling midnight
     * ("23:00-07:00") is recognised, bounds included.
     */
    public static function quietHoursCover(string $spec, int $minutes): bool
    {
        if (!self::validQuietHours($spec)) return false;
        preg_match('~^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$~', trim($spec), $m);
        $a = (int)$m[1] * 60 + (int)$m[2];
        $b = (int)$m[3] * 60 + (int)$m[4];
        return $a <= $b ? ($minutes >= $a && $minutes <= $b) : ($minutes >= $a || $minutes <= $b);
    }

    /**
     * A time range written "HH:MM-HH:MM", with hours that exist.
     *
     * "25:00-99:00" passed the old regular expression and silently disabled quiet hours:
     * nobody connects a 3 a.m. alert to a typo made three months earlier.
     */
    public static function validQuietHours(string $spec): bool
    {
        $spec = trim($spec);
        if ($spec === '') return false;
        if (!preg_match('~^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$~', $spec, $m)) return false;
        foreach ([[(int)$m[1], (int)$m[2]], [(int)$m[3], (int)$m[4]]] as [$h, $min]) {
            if ($h > 23 || $min > 59) return false;
        }
        return true;
    }

    private static function log(array $mon, string $channel, string $kind, bool $ok, string $info): void
    {
        try {
            Db::insert('notifications', [
                'incident_id' => null,
                'monitor_id'  => isset($mon['id']) ? (int)$mon['id'] : null,
                'channel'     => $channel,
                'kind'        => $kind,
                'ts'          => now(),
                'ok'          => $ok ? 1 : 0,
                'response'    => str_cut($info, 400),
            ]);
        } catch (\Throwable) { /* la journalisation ne doit jamais bloquer une alerte */ }
    }

    public static function reasonLabel(?string $code): string
    {
        return match ($code) {
            'TIMEOUT'         => t('Pas de réponse (timeout)'),
            'DNS'             => t('Nom de domaine non résolu'),
            'CONNECT'         => 'Connexion impossible',
            'CONNECT_RESET'   => t('Connexion coupée par le serveur'),
            'SSL_INVALID'     => t('Certificat SSL invalide'),
            'SSL_EXPIRED'     => t('Certificat SSL expiré'),
            'SSL_NOT_YET'     => t('Certificat pas encore valide'),
            'SSL_SOON'        => t('Certificat SSL bientôt expiré'),
            'SSL_HANDSHAKE'   => t('Échec de la négociation TLS'),
            'HTTP_5XX'        => t('Erreur serveur (5xx)'),
            'HTTP_4XX'        => t('Erreur client (4xx)'),
            'HTTP_404'        => t('Page introuvable (404)'),
            'HTTP_403'        => t('Accès interdit (403)'),
            'HTTP_401'        => t('Authentification requise (401)'),
            'HTTP_429'        => t('Quota de requêtes atteint (429)'),
            'HTTP_3XX'        => t('Redirection inattendue'),
            'REDIRECT_LOOP'   => t('Boucle de redirection'),
            'DB_DOWN'         => t('Base de données injoignable'),
            'DB_ERROR_VISIBLE'  => t('Erreur de base affichée sur le site'),
            'APP_ERROR_VISIBLE' => t('Erreur PHP affichée sur le site'),
            'APP_ERROR'       => t('Erreur applicative (PHP)'),
            'CSS_BROKEN'      => t('Mise en page cassée (CSS)'),
            'CSS_DEGRADED'    => t('CSS dégradé'),
            'STRING_MISSING'  => t('Chaîne de contrôle absente'),
            'STRING_FORBIDDEN'=> t('Chaîne interdite présente'),
            'JSON_INVALID'    => t('Réponse JSON invalide'),
            'JSON_PATH'       => t('Champ JSON absent'),
            'JSON_VALUE'      => t('Valeur JSON inattendue'),
            'NOINDEX'         => t('Page en noindex'),
            'SLOW'            => t('Temps de réponse élevé'),
            'BODY_TRUNCATED'  => t('Page trop volumineuse pour être vérifiée'),
            'HEARTBEAT_LATE'  => t('Signal attendu non reçu'),
            'PORT_CLOSED'     => t('Port fermé'),
            'DNS_MISSING'     => t('Enregistrement DNS absent'),
            'DNS_VALUE'        => t('Enregistrement DNS inattendu'),
            'NET_ERROR'       => t('Erreur réseau'),
            null              => t('Anomalie'),
            default           => $code,
        };
    }

    public static function eventLabel(string $kind): string
    {
        return match ($kind) {
            'watch_hit'       => t('Texte surveillé détecté'),
            'watch_change'    => t('Texte surveillé modifié'),
            'content_changed' => t('Contenu de page modifié'),
            'css_changed'     => t('Fichiers CSS modifiés'),
            'domain_soon'     => t('Domaine bientôt expiré'),
            'grouped_alert'   => t('Panne groupée'),
            // WRITTEN BY WHOEVER OPERATES THE INSTANCE, not by the engine, and named here anyway.
            //
            // A self-hosted install that updates itself — by git, by a package, by hand — has
            // every reason to leave a trace in the journal, and the journal is the only place the
            // operator actually looks. Without these two lines the entry still appears, but under
            // the generic "Évènement" badge, which is the one thing the eye skips.
            //
            // The FAILED one matters more than the successful one: an update that refused itself
            // and rolled back is the moment when someone needs to know that the code is
            // deliberately older than the repository.
            'moteur_maj'         => t('Moteur mis à jour'),
            'moteur_maj_echouee' => t('Mise à jour refusée et annulée'),
            default           => t('Évènement'),
        };
    }

    public static function when(?string $ts): string
    {
        return $ts ? date('d/m/Y H:i', strtotime($ts)) : '—';
    }

    /** Link to the monitor's page (when base_url is set). */
    public static function monitorLink(array $mon): ?string
    {
        $base = rtrim((string)Config::get('app.base_url', ''), '/');
        if ($base === '' || empty($mon['id'])) return null;
        return $base . '/index.php?p=monitor&id=' . (int)$mon['id'];
    }

    /** Test manuel depuis la page de réglages. */
    public static function test(string $channel): array
    {
        $mon = ['id' => 0, 'name' => t('Test de configuration'), 'url' => (string)Config::get('app.base_url', 'https://exemple.fr'),
                'notify_channels' => $channel];
        $lines = [
            [t('Message'), t('Ceci est un test envoyé depuis {app}.')],
            [t('Date'), date('d/m/Y H:i:s')],
        ];
        // The registry decides, as it does for real sending: a testable channel and a
        // sendable channel must be exactly the same, or the test button reassures about a path
        // the alerts never take.
        $classe = self::CANAUX[$channel]['classe'] ?? null;
        $res = $classe === null
            ? ['ok' => false, 'info' => t('Canal inconnu')]
            : $classe::send('✅ Test UptimeEZ', $lines, 'up', $mon);
        self::log($mon, $channel, 'test', (bool)$res['ok'], (string)($res['info'] ?? ''));
        return $res;
    }
}
