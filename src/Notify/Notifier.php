<?php
declare(strict_types=1);

namespace Uptimeez\Notify;

use Uptimeez\Config;
use Uptimeez\Db;

/**
 * Envoi des alertes : Discord, Slack, e-mail, webhook générique.
 * Garde-fous : heures calmes (les incidents critiques passent quand même),
 * anti-répétition, journalisation de chaque envoi.
 */
final class Notifier
{
    public const COLORS = [
        'down'     => 0xE5484D,
        'degraded' => 0xF5A524,
        'up'       => 0x30A46C,
        'info'     => 0x5B7FFF,
    ];

    /** Nouvelle alerte (ou relance) sur un incident. */
    /**
     * @param  'nouveau'|'aggrave'|'rappel'  $nature  ce qui motive CETTE alerte
     */
    public static function sendIncident(array $mon, array $incident, string $nature = 'nouveau'): void
    {
        $sev   = $incident['severity'] === 'degraded' ? 'degraded' : 'down';

        // TROIS NATURES ET NON DEUX, PARCE QUE « PAS NOUVEAU » N'EST PAS « RIEN DE NEUF ».
        //
        // Ce paramètre était un booléen « isNew », et tout ce qui n'était pas nouveau
        // partait sous le titre « Toujours hors service ». Or l'aggravation d'un incident,
        // quand une sonde passe de DÉGRADÉ à HORS SERVICE, est une information NEUVE :
        // c'est le moment où un ralentissement devient une panne. Elle arrivait annoncée
        // comme une répétition, donc au milieu de messages que le lecteur a appris à ne
        // plus ouvrir. La seule alerte de rappel qui méritait d'être lue était la mieux
        // déguisée en bruit.
        //
        // Le rappel périodique, lui, ne dit rien de neuf par construction : il répète un
        // incident déjà annoncé et déjà visible à l'écran. Il reste possible
        // (« notify.resend_after_min »), mais il est désormais le seul à porter le 🔁.
        $titres = [
            'nouveau' => ($sev === 'down' ? '🔴 ' . t('HORS SERVICE') : '🟠 ' . t('DÉGRADÉ')),
            'aggrave' => '🔴 ' . t('AGGRAVÉ : la panne est maintenant totale'),
            'rappel'  => '🔁 ' . ($sev === 'down' ? t('Toujours hors service') : t('Toujours dégradé')),
        ];
        // Le titre part dans une alerte : il suit la langue de l'installation,
        // comme tout ce que le collecteur écrit.
        $title = ($titres[$nature] ?? $titres['nouveau']) . ' : ' . $mon['name'];
        $isNew = $nature === 'nouveau';

        $lines = [
            [t('Cause'), self::reasonLabel($incident['reason_code'])],
            [t('Détail'), str_cut((string)$incident['message'], 300)],
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
     * L'escalade : personne n'a acquitté, on prévient quelqu'un d'autre.
     *
     * ------------------------------------------------------------------------------
     * CE QUE L'ESCALADE EST, ET CE QU'ELLE N'EST PAS
     * ------------------------------------------------------------------------------
     *
     * Ce n'est pas un rappel. Le rappel répète la même alerte aux mêmes personnes, et son
     * seul effet quand personne ne regarde est d'allonger un fil que personne ne lit.
     * L'escalade change de DESTINATAIRE : elle part sur une liste de canaux distincte,
     * après un délai, et une seule fois. C'est la différence entre insister et passer la
     * main.
     *
     * ------------------------------------------------------------------------------
     * TROIS CONDITIONS, ET CHACUNE A COÛTÉ QUELQUE CHOSE AILLEURS
     * ------------------------------------------------------------------------------
     *
     * 1. UNE SEULE FOIS PAR INCIDENT. La colonne « escalated_at » porte cet état. Sans
     *    elle, un incident non acquitté réescaladerait à chaque passe du collecteur, et
     *    l'astreinte recevrait une alerte par minute : le mécanisme censé faire réagir
     *    quelqu'un deviendrait la raison de couper ses notifications.
     *
     * 2. LES PANNES SEULEMENT. Un état « à surveiller » ne réveille pas une seconde
     *    équipe. Une lenteur ou un certificat qui expire dans dix jours n'a jamais justifié
     *    de sortir quelqu'un du lit, et l'escalader ferait perdre à l'escalade le seul
     *    crédit qui la rend utile.
     *
     * 3. L'ACQUITTEMENT ANNULE. Si quelqu'un a dit « je m'en occupe », l'escalade n'a plus
     *    d'objet. C'est aussi la seule façon honnête de fermer la boucle : le bouton
     *    d'acquittement existait déjà et ne servait qu'à taire les rappels.
     *
     * ------------------------------------------------------------------------------
     * POURQUOI ELLE PASSE LES HEURES CALMES
     * ------------------------------------------------------------------------------
     *
     * Elle part avec l'urgence « critical », comme toute panne réelle. Une escalade
     * retenue jusqu'à sept heures du matin n'est pas une escalade, c'est un rapport.
     *
     * @param array<string,mixed> $mon
     * @param array<string,mixed> $incident
     * @return bool vrai si au moins un canal a reçu l'alerte
     */
    public static function sendEscalation(array $mon, array $incident): bool
    {
        $canaux = self::escalationChannelsFor($mon);

        if ($canaux === []) {
            // Aucun canal : on le DIT dans le journal plutôt que de laisser croire que
            // l'escalade a eu lieu. Une astreinte configurée à moitié est pire qu'aucune,
            // parce qu'on compte dessus.
            self::log($mon, 'escalade', 'down', false,
                t('Aucun canal d\'escalade utilisable : rien n\'a été envoyé.'));

            return false;
        }

        $depuis = max(0, time() - strtotime((string) $incident['started_at']));

        $titre = '🚨 ' . t('ESCALADE : personne n\'a acquitté') . ' : ' . $mon['name'];
        $lignes = [
            [t('Cause'), self::reasonLabel($incident['reason_code'])],
            [t('Détail'), str_cut((string) $incident['message'], 300)],
            ['URL', $mon['url']],
            [t('Hors service depuis'), human_duration($depuis)],
            [t('Alertes déjà envoyées'), (string) (int) $incident['notify_count']],
        ];

        $envoyes = self::dispatchVers($canaux, $mon, 'down', $titre, $lignes);

        return $envoyes > 0;
    }

    /**
     * Les canaux de l'escalade : une liste distincte, sinon tous les canaux actifs.
     *
     * ENVOYER LA MÊME ALERTE DEUX FOIS SUR LE MÊME CANAL NE PRÉVIENT PERSONNE DE PLUS,
     * mais on ne peut pas non plus deviner qui est d'astreinte. Le réglage vide retombe
     * donc sur les canaux actifs, ce qui rend l'escalade utile dès qu'on l'active, sans
     * exiger un second paramétrage complet.
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
     * Alerte groupée : plusieurs sites tombés en même temps sur la même
     * infrastructure. Un seul message, qui nomme le serveur et liste les sites.
     *
     * @param array<int,array{monitor:array,incident:array}> $items
     * @param string $scope IP ou domaine commun
     * @param bool   $isIp  vrai si le regroupement s'appuie sur l'adresse IP
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

    /** Retour à la normale. */
    public static function sendRecovery(array $mon, array $incident): void
    {
        if (!Config::get('notify.notify_recovery', true)) return;
        $dur   = (int)($incident['duration_sec'] ?? 0);
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
     * Envoie sur tous les canaux actifs. Retourne le nombre d'envois réussis.
     * $urgency : critical (passe les heures calmes) | warn | info
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
     * L'envoi sur une liste de canaux IMPOSÉE, sans repasser par la politique d'envoi.
     *
     * Extrait de dispatch() le 2026-08-03 pour l'escalade, qui doit choisir ses propres
     * destinataires. Les heures calmes et le réglage « prévenir sur état à surveiller »
     * restent dans dispatch() : ce sont des décisions sur l'OPPORTUNITÉ d'alerter, et
     * l'escalade les a déjà prises quand elle arrive ici.
     *
     * @param array<int,string>   $canaux
     * @param array<string,mixed> $mon
     * @param array<int,array{0:string,1:string}> $lines
     */
    private static function dispatchVers(array $canaux, array $mon, string $sev, string $title, array $lines): int
    {
        $ok = 0;
        foreach ($canaux as $ch) {
            $res = match ($ch) {
                'discord' => Discord::send($title, $lines, $sev, $mon),
                'slack'   => Slack::send($title, $lines, $sev, $mon),
                'mail'    => Mail::send($title, $lines, $sev, $mon),
                'webhook' => Webhook::send($title, $lines, $sev, $mon),
                default   => ['ok' => false, 'info' => t('Canal inconnu')],
            };
            if (!empty($res['ok'])) $ok++;
            self::log($mon, $ch, $sev, (bool)($res['ok'] ?? false), (string)($res['info'] ?? ''));
        }
        return $ok;
    }

    /** Canaux retenus : réglage de la sonde sinon canaux globaux actifs. */
    public static function channelsFor(array $mon): array
    {
        $perMon = trim((string)($mon['notify_channels'] ?? ''));
        $wanted = $perMon !== '' ? array_filter(array_map('trim', explode(',', $perMon))) : null;

        $out = [];
        foreach (['discord', 'slack', 'mail', 'webhook'] as $ch) {
            if (!Config::get("notify.$ch.enabled", false)) continue;
            if ($wanted !== null && !in_array($ch, $wanted, true)) continue;
            if ($ch === 'mail' ? trim((string)Config::get('notify.mail.to', '')) === ''
                               : trim((string)Config::get("notify.$ch." . ($ch === 'webhook' ? 'url' : 'webhook'), '')) === '') continue;
            $out[] = $ch;
        }
        return $out;
    }

    public static function inQuietHours(): bool
    {
        return self::quietHoursCover((string)Config::get('notify.quiet_hours', ''),
                                     (int)date('H') * 60 + (int)date('i'));
    }

    /**
     * La plage calme couvre-t-elle cette minute de la journée ?
     *
     * Séparée de l'heure courante pour être vérifiable : c'est exactement le
     * genre de calcul dont l'erreur ne se voit qu'une nuit sur deux. Une plage à
     * cheval sur minuit (« 23:00-07:00 ») est reconnue, bornes incluses.
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
     * Une plage horaire écrite « HH:MM-HH:MM », avec des heures qui existent.
     *
     * « 25:00-99:00 » passait l'ancienne expression régulière et désactivait les
     * heures calmes sans le dire : personne ne relie une alerte nocturne à une
     * faute de frappe faite trois mois plus tôt.
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
            default           => t('Évènement'),
        };
    }

    public static function when(?string $ts): string
    {
        return $ts ? date('d/m/Y H:i', strtotime($ts)) : '—';
    }

    /** Lien vers la fiche de la sonde (si base_url est renseignée). */
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
        $res = match ($channel) {
            'discord' => Discord::send('✅ Test UptimeEZ', $lines, 'up', $mon),
            'slack'   => Slack::send('✅ Test UptimeEZ', $lines, 'up', $mon),
            'mail'    => Mail::send('✅ Test UptimeEZ', $lines, 'up', $mon),
            'webhook' => Webhook::send('✅ Test UptimeEZ', $lines, 'up', $mon),
            default   => ['ok' => false, 'info' => t('Canal inconnu')],
        };
        self::log($mon, $channel, 'test', (bool)$res['ok'], (string)($res['info'] ?? ''));
        return $res;
    }
}
