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
    /**
     * En dessous, un rétablissement ne s'annonce pas : l'incident n'a pas duré une passe.
     * Voir sendRecovery() et le plan du 2026-08-04, défaut D4.
     */
    public const PLANCHER_RETABLISSEMENT = 60;

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
    /**
     * LE DÉTAIL D'UN INCIDENT, VARIABLES SUBSTITUÉES.
     *
     * ------------------------------------------------------------------------------
     * LE DÉFAUT QUE CETTE FONCTION RÉPARE, ET IL DURAIT DEPUIS LE DÉBUT
     * ------------------------------------------------------------------------------
     *
     * Le 2026-08-04, Laurent a montré sa boîte : chaque courriel portait le GABARIT au lieu de
     * la valeur. « Mise en page cassée : {detail} », « Temps de réponse élevé : {seconds} s »,
     * « Erreur serveur {code} : le site ne répond plus correctement ». Des centaines de
     * messages, tous illisibles sur la seule ligne qui explique quoi faire.
     *
     * La cause : le message stocké est une phrase SOURCE, et ses variables vivent à côté, dans
     * « message_vars ». L'écran passe par verdict_text() qui applique les deux ; le courriel
     * lisait « message » tout seul. Deux chemins pour un même rendu, dont un seul était juste,
     * et c'est exactement la forme du défaut qui avait produit deux verdicts de certificat
     * contradictoires en juillet.
     *
     * AUCUN TEST NE POUVAIT LE VOIR, parce qu'aucun ne lit un courriel RENDU : ils vérifiaient
     * qu'un envoi partait, pas ce qu'il contenait. Le contrôle ajouté au selftest refuse
     * désormais une accolade dans un détail d'incident.
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
     * UNE CAUSE D'APPARENCE NE PART PLUS PAR COURRIEL. Arbitrage de Laurent, 2026-08-04.
     *
     * ------------------------------------------------------------------------------
     * POURQUOI CETTE PORTE EXISTE, ET CE QU'ELLE NE FERME PAS
     * ------------------------------------------------------------------------------
     *
     * Ses mots : « à la rigueur on peut avoir une remarque de l'outil sans avoir de mail ». Il
     * avait ouvert les sites signalés : aucun n'avait de problème de feuille de style. Le
     * détecteur se trompe donc en masse, et il continuera de se tromper tant que le sprint 2
     * n'aura pas trouvé pourquoi — mais son erreur cesse d'atteindre une boîte de courriel.
     *
     * CE QUI RESTE ENTIER : la sonde garde son état, l'écran garde son avertissement, le
     * journal garde sa ligne. Une remarque visible dans l'outil n'a jamais réveillé personne à
     * trois heures du matin, et c'est toute la différence.
     *
     * CE QUI N'EST PAS COUVERT ICI : le rétablissement. Un incident d'apparence qui se referme
     * n'envoie rien non plus, puisque son ouverture n'a rien envoyé. Voir sendRecovery().
     */
    public static function causeSilencieuse(?string $cause): bool
    {
        return \Uptimeez\Regle\Verdict::estUneApparence($cause);
    }

    public static function sendIncident(array $mon, array $incident, string $nature = 'nouveau'): void
    {
        // La porte, avant toute construction de message : une cause d'apparence ne part pas.
        if (self::causeSilencieuse($incident['reason_code'] ?? null)) {
            self::log($mon, 'silencieux', (string) ($incident['severity'] ?? 'degraded'), false,
                (string) ($incident['reason_code'] ?? '') . ' · no-mail');

            return;
        }

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
            [t('Détail'), self::detailIncident($incident)],
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

        // D2. Rien n'a été envoyé à l'ouverture, donc rien ne part à la fermeture : un
        // « RÉTABLI » sans alerte préalable annonce la fin d'un problème dont le lecteur
        // n'a jamais entendu parler.
        if (self::causeSilencieuse($incident['reason_code'] ?? null)) {
            return;
        }

        // D4. UN INCIDENT DE ZÉRO SECONDE N'A PAS BESOIN D'ÊTRE ANNONCÉ RÉTABLI.
        //
        // Les captures du 2026-08-04 portaient « Downtime 0 s » sur presque tous les
        // rétablissements : le contrôle voit un défaut, la passe suivante ne le voit plus, et
        // deux courriels partent pour un incident qui n'a jamais duré. Sous ce plancher, le
        // rétablissement reste dans l'outil et dans le journal.
        //
        // Soixante secondes, parce que c'est la cadence minimale d'une passe : en dessous,
        // l'incident n'a pas survécu à un seul intervalle, donc il n'a rien décrit de stable.
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
            // La classe vient du registre : un canal ajouté à CANAUX est envoyé sans qu'on
            // touche ici, et un canal inconnu est journalisé au lieu d'être ignoré.
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
     * LE REGISTRE DES CANAUX : une seule déclaration, là où il y en avait six.
     *
     * ------------------------------------------------------------------------------
     * POURQUOI CETTE CONSTANTE EXISTE
     * ------------------------------------------------------------------------------
     *
     * Avant le 2026-08-03, la liste des canaux était écrite en dur dans six endroits : ici
     * deux fois, dans le sélecteur de l'écran des réglages, dans l'enregistrement de ces
     * réglages, dans le bouton de test, et dans le verrou de la démonstration publique.
     * Ajouter Telegram demandait donc six modifications dont aucune ne signalait l'oubli
     * des cinq autres, et l'oubli le plus probable est le verrou de la démonstration :
     * un canal qui échapperait à Demo::silenced() enverrait de vrais messages depuis une
     * installation publique dont le mot de passe est écrit dans la documentation.
     *
     * « requis » NE DIT PAS « CONFIGURÉ », IL DIT « UTILISABLE ». Un canal activé dont
     * l'URL est vide n'enverra rien : le déclarer utilisable ferait compter un envoi qui
     * n'a pas eu lieu, et l'écran annoncerait une alerte partie. Chaque clé listée doit
     * donc porter une valeur non vide, et c'est la seule condition.
     *
     * L'ORDRE EST CELUI DE L'AFFICHAGE, et il n'est pas alphabétique : les deux canaux
     * historiques d'abord, les trois ajoutés ensuite, le courriel et le webhook générique
     * en dernier parce que ce sont les recours plutôt que les choix.
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

    /** Canaux retenus : réglage de la sonde sinon canaux globaux actifs. */
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

    /** Un canal est utilisable quand tous ses réglages requis portent une valeur. */
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
        // Le registre décide, comme pour l'envoi réel : un canal testable et un canal
        // envoyable doivent être exactement les mêmes, sinon le bouton de test rassure sur
        // un chemin que les alertes n'empruntent pas.
        $classe = self::CANAUX[$channel]['classe'] ?? null;
        $res = $classe === null
            ? ['ok' => false, 'info' => t('Canal inconnu')]
            : $classe::send('✅ Test UptimeEZ', $lines, 'up', $mon);
        self::log($mon, $channel, 'test', (bool)$res['ok'], (string)($res['info'] ?? ''));
        return $res;
    }
}
