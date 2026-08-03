<?php
declare(strict_types=1);

namespace Uptimeez;

use PDO;
use PDOException;

final class Db
{
    private static ?PDO $pdo = null;
    private static string $driver = 'sqlite';

    /**
     * Ferme la connexion courante.
     *
     * La connexion est mise en cache pour la durée du processus, ce qui est le
     * bon comportement en production : une requête web ouvre une connexion, pas
     * dix. Mais changer « db.sqlite » en cours de route ne reconnectait pas, et
     * l'appelant écrivait sans le savoir dans la base précédente. Les bancs
     * d'essai qui basculent de base appellent donc ceci.
     */
    public static function disconnect(): void
    {
        self::$pdo = null;
        self::$driver = 'sqlite';
        self::$indexErrors = [];
    }

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) return self::$pdo;

        $cfg = Config::get('db', []);
        self::$driver = $cfg['driver'] ?? 'sqlite';

        if (self::$driver === 'mysql') {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $cfg['host'] ?: 'localhost', (int)($cfg['port'] ?: 3306), $cfg['name'], $cfg['charset'] ?: 'utf8mb4');
            self::$pdo = new PDO($dsn, (string)$cfg['user'], (string)$cfg['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } else {
            $path = $cfg['sqlite'] ?: (UPTIMEEZ_ROOT . '/data/uptimeez.sqlite');
            $dir  = dirname($path);
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            self::$pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            // L'ORDRE COMPTE, et il a longtemps été le mauvais.
            //
            // « auto_vacuum » ne se règle qu'avant que l'en-tête du fichier ne
            // soit écrit : sur une base déjà créée, le PRAGMA est accepté sans
            // effet et sans erreur. Or « journal_mode = WAL » écrit justement
            // cet en-tête. Placé après lui, auto_vacuum restait donc à 0 sur
            // TOUTES les bases jamais créées par UptimeEZ, et compact() ne
            // rendait pas un octet : après une purge de 4,5 millions de mesures,
            // le fichier gardait 585 Mo de pages libres. Il passe donc en
            // premier, avant toute écriture.
            self::$pdo->exec('PRAGMA auto_vacuum = INCREMENTAL');
            self::$pdo->exec('PRAGMA journal_mode = WAL');
            self::$pdo->exec('PRAGMA synchronous = NORMAL');
            self::$pdo->exec('PRAGMA busy_timeout = 8000');
            self::$pdo->exec('PRAGMA foreign_keys = ON');
        }
        return self::$pdo;
    }

    public static function driver(): string
    {
        self::pdo();
        return self::$driver;
    }

    public static function q(string $sql, array $params = []): \PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::q($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $r = self::q($sql, $params)->fetch();
        return $r === false ? null : $r;
    }

    public static function val(string $sql, array $params = [], mixed $default = null): mixed
    {
        $v = self::q($sql, $params)->fetchColumn();
        return $v === false ? $default : $v;
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql  = 'INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES ('
              . implode(',', array_map(fn($c) => ':' . $c, $cols)) . ')';
        self::q($sql, $data);
        return (int)self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $params = []): int
    {
        $set = implode(',', array_map(fn($c) => $c . ' = :' . $c, array_keys($data)));
        $st  = self::pdo()->prepare("UPDATE {$table} SET {$set} WHERE {$where}");
        $st->execute($data + $params);
        return $st->rowCount();
    }


    public static function setting(string $key, mixed $default = null): mixed
    {
        try {
            $v = self::val('SELECT v FROM settings WHERE k = ?', [$key]);
        } catch (PDOException) {
            return $default;
        }
        return $v === null ? $default : $v;
    }

    public static function setSetting(string $key, mixed $value): void
    {
        $exists = self::val('SELECT 1 FROM settings WHERE k = ?', [$key]);
        if ($exists) self::q('UPDATE settings SET v = ? WHERE k = ?', [(string)$value, $key]);
        else self::q('INSERT INTO settings (k, v) VALUES (?, ?)', [$key, (string)$value]);
    }

    /** Crée / met à jour le schéma. Idempotent. */
    public static function migrate(): void
    {
        $pdo = self::pdo();
        $my  = self::driver() === 'mysql';

        $pk   = $my ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $txt  = 'TEXT';
        $eng  = $my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $ts   = $my ? 'DATETIME' : 'TEXT';
        $str  = fn(int $n) => $my ? "VARCHAR($n)" : 'TEXT';
        $int  = 'INTEGER';
        $bool = $my ? 'TINYINT(1)' : 'INTEGER';
        $flt  = $my ? 'DOUBLE' : 'REAL';

        $tables = [];

        $tables['settings'] = "CREATE TABLE IF NOT EXISTS settings (
            k {$str(120)} NOT NULL PRIMARY KEY,
            v {$txt}
        ){$eng}";

        $tables['sites'] = "CREATE TABLE IF NOT EXISTS sites (
            id {$pk},
            name {$str(190)} NOT NULL,
            domain {$str(190)} NOT NULL,
            cms {$str(60)} DEFAULT NULL,
            cms_detail {$txt},
            group_name {$str(120)} DEFAULT NULL,
            /* Client propriétaire, quand l'agence a créé des accès clients. */
            client_id {$int} DEFAULT NULL,
            expect_string {$str(255)} DEFAULT NULL,
            notes {$txt},

            /* Rapport mensuel : destinataires du client, et trace du dernier
               envoi pour ne jamais l'expédier deux fois le même mois. */
            report_enabled {$bool} NOT NULL DEFAULT 0,
            report_to {$str(500)} DEFAULT NULL,
            report_sent_key {$str(10)} DEFAULT NULL,
            report_sent_at {$ts} DEFAULT NULL,

            created_at {$ts} NOT NULL
        ){$eng}";

        /* Clients de l'agence. Un client regroupe des sites et possède un jeton
           qui ouvre un espace en lecture seule : il voit l'état de ses sites,
           rien d'autre, et il ne peut rien modifier. Le jeton se change sans
           toucher aux données, ce qui permet de couper un accès en un clic. */
        $tables['clients'] = "CREATE TABLE IF NOT EXISTS clients (
            id {$pk},
            name {$str(190)} NOT NULL,
            token {$str(64)} NOT NULL,
            contact_email {$str(255)} DEFAULT NULL,
            notes {$txt},
            enabled {$bool} NOT NULL DEFAULT 1,
            created_at {$ts} NOT NULL,
            last_seen_at {$ts} DEFAULT NULL,
            views {$int} NOT NULL DEFAULT 0
        ){$eng}";

        /* Inventaire logiciel : ce que chaque site exécute, et ce que les avis
           de sécurité publics en disent. La version vient du HTML déjà reçu,
           donc rien de plus n'est demandé au site surveillé. */
        $tables['components'] = "CREATE TABLE IF NOT EXISTS components (
            id {$pk},
            site_id {$int} NOT NULL,
            monitor_id {$int} DEFAULT NULL,
            kind {$str(12)} NOT NULL,          /* core | plugin | theme */
            slug {$str(80)} NOT NULL,
            name {$str(120)} NOT NULL,
            version {$str(40)} DEFAULT NULL,
            source {$str(12)} DEFAULT NULL,    /* generator | asset | path */
            latest {$str(40)} DEFAULT NULL,
            outdated {$bool} NOT NULL DEFAULT 0,
            vuln_count {$int} NOT NULL DEFAULT 0,
            worst {$str(12)} DEFAULT NULL,
            advisories {$txt},
            checked_at {$ts} DEFAULT NULL,
            first_seen_at {$ts} DEFAULT NULL,
            seen_at {$ts} DEFAULT NULL
        ){$eng}";

        $tables['monitors'] = "CREATE TABLE IF NOT EXISTS monitors (
            id {$pk},
            site_id {$int} DEFAULT NULL,
            name {$str(190)} NOT NULL,
            url {$txt} NOT NULL,
            kind {$str(20)} NOT NULL DEFAULT 'page',      /* page | api | asset | keyword */
            role {$str(20)} NOT NULL DEFAULT 'secondary',  /* primary | secondary */
            method {$str(10)} NOT NULL DEFAULT 'GET',
            request_body {$txt},
            request_headers {$txt},
            auth_user {$str(120)} DEFAULT NULL,
            auth_pass {$str(190)} DEFAULT NULL,
            user_agent {$str(255)} DEFAULT NULL,

            interval_sec {$int} NOT NULL DEFAULT 300,
            timeout_sec {$int} NOT NULL DEFAULT 15,
            retries {$int} NOT NULL DEFAULT 2,
            slow_ms {$int} NOT NULL DEFAULT 3000,
            follow_redirects {$bool} NOT NULL DEFAULT 1,
            ignore_ssl_errors {$bool} NOT NULL DEFAULT 0,

            expect_status {$str(60)} NOT NULL DEFAULT '200-299',
            expect_string {$txt},          /* preuve web+BDD OK */
            forbid_string {$txt},
            json_path {$str(190)} DEFAULT NULL,
            json_expect {$str(255)} DEFAULT NULL,

            watch_string {$txt},
            watch_mode {$str(20)} DEFAULT 'appear',       /* appear | disappear */
            watch_state {$str(20)} DEFAULT NULL,          /* present | absent */
            watch_seen_at {$ts} DEFAULT NULL,

            check_ssl {$bool} NOT NULL DEFAULT 1,
            check_css {$bool} NOT NULL DEFAULT 1,
            check_db {$bool} NOT NULL DEFAULT 1,
            check_content {$bool} NOT NULL DEFAULT 0,     /* alerte si la page change */
            check_noindex {$bool} NOT NULL DEFAULT 1,
            ssl_warn_days {$int} NOT NULL DEFAULT 14,
            css_drop_pct {$int} NOT NULL DEFAULT 35,
            auto_slow {$bool} NOT NULL DEFAULT 1,          /* seuil de lenteur auto-ajusté */
            tuned_at {$ts} DEFAULT NULL,
            decisions {$txt},                              /* journal des décisions de UptimeEZ */
            heartbeat_token {$str(40)} DEFAULT NULL,       /* sonde battement : clé du signal */
            heartbeat_at {$ts} DEFAULT NULL,               /* dernier signal reçu */
            heartbeat_grace {$int} NOT NULL DEFAULT 300,   /* tolérance avant alerte */

            css_baseline {$txt},
            css_baseline_at {$ts} DEFAULT NULL,
            css_baseline_locked {$bool} NOT NULL DEFAULT 0,
            css_state {$str(20)} DEFAULT NULL,
            css_checked_at {$ts} DEFAULT NULL,
            css_detail {$txt},

            /* Silhouette : la page telle qu'un visiteur la verrait, reconstruite
               sans navigateur. La référence est prise sur un état sain, l'actuelle
               à chaque analyse, et l'écart entre les deux se lit en pourcentage. */
            silhouette_ref {$txt},
            silhouette_ref_sig {$txt},
            silhouette_ref_at {$ts} DEFAULT NULL,
            silhouette_now {$txt},
            silhouette_now_sig {$txt},
            silhouette_at {$ts} DEFAULT NULL,
            silhouette_drift {$int} NOT NULL DEFAULT 0,

            /* Vitesse ressentie : ce que le HTML déjà reçu explique du LCP, du
               CLS et de l'INP, plus les mesures de terrain quand une clé CrUX
               est fournie. Rien n'est estimé : voir Check\Vitals. */
            vitals_level {$str(10)} DEFAULT NULL,
            vitals_detail {$txt},
            vitals_at {$ts} DEFAULT NULL,
            field_lcp_ms {$int} DEFAULT NULL,
            field_inp_ms {$int} DEFAULT NULL,
            field_cls {$flt} DEFAULT NULL,
            field_verdict {$str(10)} DEFAULT NULL,
            field_source {$str(10)} DEFAULT NULL,   /* url | origin */
            field_at {$ts} DEFAULT NULL,

            ssl_checked_at {$ts} DEFAULT NULL,
            content_hash {$str(40)} DEFAULT NULL,
            content_hash_at {$ts} DEFAULT NULL,
            content_changed_at {$ts} DEFAULT NULL,

            notify_channels {$str(190)} DEFAULT NULL,     /* vide = canaux globaux */
            setup_state {$str(20)} NOT NULL DEFAULT 'done', /* pending | done | failed */
            setup_note {$txt},
            enabled {$bool} NOT NULL DEFAULT 1,
            paused_until {$ts} DEFAULT NULL,
            maintenance {$str(120)} DEFAULT NULL,         /* ex: 'mon-fri 02:00-03:00' */

            status {$str(20)} NOT NULL DEFAULT 'unknown', /* up | degraded | down | paused | unknown */
            reason_code {$str(40)} DEFAULT NULL,
            last_message {$txt},
            /* Variables du dernier verdict, en JSON. Le message est stocké comme
               msgid (« Le certificat expire dans {n} jours ») et traduit à
               l'affichage : sans cela, la langue du cron déciderait de la langue
               lue par tout le monde. */
            last_message_vars {$txt},
            status_since {$ts} DEFAULT NULL,
            last_check_at {$ts} DEFAULT NULL,
            next_check_at {$ts} DEFAULT NULL,
            last_ms {$int} DEFAULT NULL,
            last_status_code {$int} DEFAULT NULL,
            last_ip {$str(45)} DEFAULT NULL,
            ssl_days_left {$int} DEFAULT NULL,
            ssl_issuer {$str(190)} DEFAULT NULL,
            ssl_expires_at {$ts} DEFAULT NULL,
            domain_expires_at {$ts} DEFAULT NULL,
            consecutive_fail {$int} NOT NULL DEFAULT 0,
            consecutive_ok {$int} NOT NULL DEFAULT 0,
            /* agrégats dénormalisés : le tableau de bord doit s'afficher sans calcul */
            uptime_24h {$flt} DEFAULT NULL,
            uptime_7d {$flt} DEFAULT NULL,
            uptime_30d {$flt} DEFAULT NULL,
            avg_ms_24h {$int} DEFAULT NULL,
            stats_at {$ts} DEFAULT NULL,
            created_at {$ts} NOT NULL
        ){$eng}";

        $tables['checks'] = "CREATE TABLE IF NOT EXISTS checks (
            id {$pk},
            monitor_id {$int} NOT NULL,
            ts {$ts} NOT NULL,
            state {$str(20)} NOT NULL,          /* up | degraded | down */
            reason_code {$str(40)} DEFAULT NULL,
            status_code {$int} DEFAULT NULL,
            message {$txt},
            dns_ms {$int} DEFAULT NULL,
            connect_ms {$int} DEFAULT NULL,
            tls_ms {$int} DEFAULT NULL,
            ttfb_ms {$int} DEFAULT NULL,
            total_ms {$int} DEFAULT NULL,
            size_bytes {$int} DEFAULT NULL,
            redirects {$int} DEFAULT NULL,
            final_url {$txt},
            ssl_days_left {$int} DEFAULT NULL,
            css_state {$str(20)} DEFAULT NULL,
            details {$txt},
            attempts {$int} DEFAULT 1
        ){$eng}";

        $tables['incidents'] = "CREATE TABLE IF NOT EXISTS incidents (
            id {$pk},
            monitor_id {$int} NOT NULL,
            severity {$str(20)} NOT NULL DEFAULT 'down',   /* down | degraded */
            reason_code {$str(40)} DEFAULT NULL,
            message {$txt},
            /* Variables du verdict au moment de l'incident : le message est une
               phrase source, traduite à la lecture par verdict_text(). */
            message_vars {$txt},
            started_at {$ts} NOT NULL,
            ended_at {$ts} DEFAULT NULL,
            duration_sec {$int} DEFAULT NULL,
            checks_failed {$int} NOT NULL DEFAULT 1,
            ack_at {$ts} DEFAULT NULL,
            last_notified_at {$ts} DEFAULT NULL,
            notify_count {$int} NOT NULL DEFAULT 0,
            /* L'escalade est partie : une seule fois par incident, et jamais deux.
               La colonne EST l'état : sans elle, un incident non acquitté qui traverse
               plusieurs passes réescaladerait à chaque tour, ce qui est le contraire du
               but. Voir Notify\Notifier::sendEscalation(). */
            escalated_at {$ts} DEFAULT NULL
        ){$eng}";

        $tables['events'] = "CREATE TABLE IF NOT EXISTS events (
            id {$pk},
            monitor_id {$int} DEFAULT NULL,
            ts {$ts} NOT NULL,
            kind {$str(40)} NOT NULL,
            message {$txt},
            details {$txt},
            seen {$bool} NOT NULL DEFAULT 0
        ){$eng}";

        // ------------------------------------------------------------------
        // CE QUE L'EXPLOITANT CORRIGE, ET QUI NE CORRIGE ENCORE RIEN
        // ------------------------------------------------------------------
        //
        // Le 2026-08-02, sur un parc réel, 43 des 47 sondes non vertes étaient des FAUX
        // POSITIFS, dont treize annoncées hors service. La seule boucle de correction
        // existante est « quelqu'un remarque, quelqu'un enquête, quelqu'un modifie le
        // code » : elle a demandé une journée entière pour un seul client, et elle ne
        // tiendra pas à dix.
        //
        // LE PIÈGE EST LE SUJET ENTIER. Un bouton qui MASQUE l'incident aggrave le
        // produit : il transforme un défaut visible en défaut invisible, et le jour où la
        // règle se trompe vraiment plus personne ne le sait. Ce qu'il faut apprendre n'est
        // donc pas « cet incident était faux » mais « QUELLE RÈGLE s'est trompée, sur QUEL
        // SIGNAL, à QUELLES CONDITIONS ».
        //
        // Cette table est délibérément INERTE : elle enregistre, elle ne décide de rien, et
        // aucun verdict ne change parce qu'elle se remplit. On regarde d'abord ce que les
        // gens déclarent avant d'en tirer quoi que ce soit, sinon on apprend d'un corpus
        // qu'on n'a jamais lu.
        //
        // LA PORTÉE EST LA COLONNE QUI COMPTE. « Normal sur cette sonde » et « normal
        // partout » appellent deux gestes opposés : une exception locale ou un assouplissement
        // général. Sans elle, un exploitant qui déclare normal quelque chose qui ne l'est
        // que chez lui dégrade la détection de tous les autres — c'est la porte
        // d'empoisonnement du corpus, et elle se ferme ici, à l'écriture.
        $tables['retours'] = "CREATE TABLE IF NOT EXISTS retours (
            id {$pk},
            incident_id {$int} DEFAULT NULL,
            monitor_id {$int} NOT NULL,
            /* La cause mise en question, telle qu'elle a été rendue. */
            reason_code {$str(40)} DEFAULT NULL,
            /* Le signal précis, quand la cause en couvre plusieurs (un message CSS). */
            signal {$txt},
            /* sans_effet | controle_errone | normal_ici | vrai_et_corrige */
            motif {$str(30)} NOT NULL,
            /* sonde | serveur | parc — voir le commentaire ci-dessus. */
            portee {$str(20)} NOT NULL DEFAULT 'sonde',
            /* L'hôte au moment du retour : une portée « serveur » n'a de sens
               qu'accompagnée du serveur dont on parle, et l'IP peut changer après. */
            hote {$str(255)} DEFAULT NULL,
            commentaire {$txt},
            ts {$ts} NOT NULL
        ){$eng}";

        // ------------------------------------------------------------------
        // « CE SIGNAL, SUR CETTE SONDE, NE COMPTE PAS » — ET ON LE COMPTE QUAND MÊME
        // ------------------------------------------------------------------
        //
        // Une exception éteint un signal là où l'exploitant sait qu'il n'a pas de sens : un
        // « noindex » délibéré sur une recette, une police d'icônes absente qu'on assume.
        // C'est le geste souverain qui rend le produit utilisable sur un vrai parc, où il y
        // a toujours quelques cas particuliers légitimes.
        //
        // TROIS COLONNES EXISTENT PARCE QU'UNE EXCEPTION OUBLIÉE EST UNE PANNE QU'ON NE
        // VERRA PAS. « revoir_le » force une date de revue ; « masquees_total » et le
        // couple mois/compteur permettent de dire « 12 alertes masquées par vos exceptions
        // ce mois-ci ». Une exception muette et éternelle serait pire que le faux positif
        // qu'elle supprime : le faux positif, au moins, se voit.
        //
        // Le compteur mensuel tient en deux colonnes plutôt qu'en une table d'historique :
        // on veut répondre à « combien ce mois-ci », pas reconstituer une chronologie. Une
        // table de plus se paierait à chaque passe pour une question qu'on ne pose pas.
        $tables['exceptions'] = "CREATE TABLE IF NOT EXISTS exceptions (
            id {$pk},
            monitor_id {$int} NOT NULL,
            reason_code {$str(40)} NOT NULL,
            /* Facultatif : ne taire que les signaux dont le détail contient ce texte.
               Vide veut dire « toute la cause », ce qui est plus large et plus risqué. */
            motif_signal {$txt},
            /* Pourquoi, en clair. Obligatoire à la relecture, six mois plus tard. */
            raison {$txt},
            cree_le {$ts} NOT NULL,
            revoir_le {$ts} NOT NULL,
            actif {$bool} NOT NULL DEFAULT 1,
            masquees_total {$int} NOT NULL DEFAULT 0,
            masquees_mois {$str(7)} DEFAULT NULL,
            masquees_ce_mois {$int} NOT NULL DEFAULT 0,
            derniere_masquee_le {$ts} DEFAULT NULL
        ){$eng}";

        // ------------------------------------------------------------------
        // DES COMPTES, PARCE QU'UN SECRET PARTAGÉ N'EST PAS UN ACCÈS
        // ------------------------------------------------------------------
        //
        // Une instance n'avait AUCUN compte : un seul mot de passe dans son config.php,
        // tiré au hasard à la création et affiché une seule fois. Dès qu'un client a deux
        // personnes, ce mot de passe circule par courriel ou par message, et il n'existe
        // alors aucun moyen de savoir qui est entré ni de retirer l'accès à une seule
        // personne. Le seul geste possible est de changer le secret pour tout le monde.
        //
        // L'IDENTIFIANT EST UNIQUE, ET C'EST LA BASE QUI LE GARANTIT. Une unicité vérifiée
        // seulement en PHP se perd sur deux créations simultanées, et deux comptes de même
        // identifiant rendraient la connexion non déterministe.
        $tables['comptes'] = "CREATE TABLE IF NOT EXISTS comptes (
            id {$pk},
            identifiant {$str(190)} NOT NULL,
            courriel {$str(255)} DEFAULT NULL,
            nom {$str(190)} DEFAULT NULL,
            mot_de_passe {$txt},
            actif {$bool} NOT NULL DEFAULT 1,
            cree_le {$ts} NOT NULL,
            dernier_acces_le {$ts} DEFAULT NULL,
            /* Réinitialisation : le jeton est stocké HACHÉ. Une base lue par un tiers ne
               doit pas lui offrir les comptes en clair, et un jeton en clair vaut un mot
               de passe tant qu'il n'a pas expiré. */
            jeton_reinit {$str(64)} DEFAULT NULL,
            jeton_expire_le {$ts} DEFAULT NULL
        ){$eng}";

        // ------------------------------------------------------------------
        // SANS TRACE, AJOUTER DES COMPTES NE CHANGE PRESQUE RIEN
        // ------------------------------------------------------------------
        //
        // Savoir QUI est entré est la moitié de l'intérêt des comptes ; l'autre moitié est
        // de voir qui a ESSAYÉ. Les échecs sont donc consignés au même endroit que les
        // succès, parce qu'une série d'échecs sur un identifiant valide est le seul signal
        // qui distingue une tentative d'intrusion d'un mot de passe oublié.
        //
        // On enregistre l'identifiant SAISI, même inconnu, et jamais le mot de passe : une
        // faute de frappe met parfois le mot de passe dans le champ identifiant, et ce
        // journal deviendrait alors une liste de secrets en clair.
        $tables['connexions'] = "CREATE TABLE IF NOT EXISTS connexions (
            id {$pk},
            compte_id {$int} DEFAULT NULL,
            identifiant {$str(190)} DEFAULT NULL,
            /* mot_de_passe | jeton_pont | secours */
            voie {$str(20)} NOT NULL,
            reussie {$bool} NOT NULL DEFAULT 0,
            ip {$str(45)} DEFAULT NULL,
            agent {$txt},
            ts {$ts} NOT NULL
        ){$eng}";

        $tables['notifications'] = "CREATE TABLE IF NOT EXISTS notifications (
            id {$pk},
            incident_id {$int} DEFAULT NULL,
            monitor_id {$int} DEFAULT NULL,
            channel {$str(30)} NOT NULL,
            kind {$str(30)} NOT NULL,
            ts {$ts} NOT NULL,
            ok {$bool} NOT NULL DEFAULT 0,
            response {$txt}
        ){$eng}";

        $tables['daily_stats'] = "CREATE TABLE IF NOT EXISTS daily_stats (
            monitor_id {$int} NOT NULL,
            day {$str(10)} NOT NULL,
            checks {$int} NOT NULL DEFAULT 0,
            fails {$int} NOT NULL DEFAULT 0,
            degraded {$int} NOT NULL DEFAULT 0,
            downtime_sec {$int} NOT NULL DEFAULT 0,
            avg_ms {$flt} DEFAULT NULL,
            p95_ms {$int} DEFAULT NULL,
            min_ms {$int} DEFAULT NULL,
            max_ms {$int} DEFAULT NULL,
            PRIMARY KEY (monitor_id, day)
        ){$eng}";

        foreach ($tables as $table => $sql) {
            // Les commentaires /* */ sont acceptés par SQLite comme par MySQL.
            $pdo->exec($sql);
            // Une base créée par une version antérieure n'a pas les colonnes
            // ajoutées depuis : CREATE TABLE IF NOT EXISTS ne les ajoute pas.
            self::syncColumns($table, $sql);
        }

        $idx = [
            // [nom, table, colonnes, unique]
            ['idx_checks_mon_ts',  'checks',     'monitor_id, ts',          false],
            ['idx_checks_ts',      'checks',     'ts',                      false],
            ['idx_inc_mon',        'incidents',  'monitor_id, started_at',  false],
            ['idx_inc_open',       'incidents',  'ended_at',                false],
            ['idx_mon_next',       'monitors',   'enabled, next_check_at',  false],
            ['idx_mon_site',       'monitors',   'site_id',                 false],
            // Un composant est unique par site : l'index le garantit, ce qui
            // évite d'accumuler des doublons à chaque analyse de page.
            ['idx_comp_uniq',      'components', 'site_id, kind, slug',     true],
            ['idx_comp_scan',      'components', 'checked_at',              false],
            ['idx_comp_vuln',      'components', 'vuln_count',              false],
            ['idx_events_ts',      'events',     'ts',                      false],
            // Le jeton client est cherché à chaque ouverture de l'espace : il
            // doit être unique et indexé.
            ['idx_clients_token',  'clients',    'token',                   true],
            ['idx_sites_client',   'sites',      'client_id',               false],
            // L'IDENTIFIANT EST UNIQUE, ET C'EST LA BASE QUI LE GARANTIT. Vérifier
            // l'unicité en PHP seulement la perd sur deux créations simultanées, et deux
            // comptes de même identifiant rendraient la connexion non déterministe : on ne
            // saurait pas lequel des deux mots de passe ouvre la session.
            ['idx_comptes_ident',  'comptes',    'identifiant',             true],
            ['idx_connexions_ts',  'connexions', 'ts',                      false],
        ];
        // MySQL ne connaît pas « CREATE INDEX IF NOT EXISTS » : la requête y est
        // une erreur de syntaxe, et l'attraper silencieusement revenait à ne
        // créer AUCUN index. Sur une table de mesures d'un million de lignes,
        // c'est la différence entre un tableau de bord instantané et un balayage
        // complet à chaque affichage. On interroge donc le catalogue.
        $existing = $my ? self::indexNames() : [];
        foreach ($idx as [$name, $table, $cols, $unique]) {
            if ($my && isset($existing[$name])) continue;
            $sql = 'CREATE ' . ($unique ? 'UNIQUE ' : '') . 'INDEX '
                 . ($my ? '' : 'IF NOT EXISTS ') . $name . " ON $table ($cols)";
            try {
                $pdo->exec($sql);
            } catch (PDOException $e) {
                // Un index déjà présent sous un autre nom, ou une colonne encore
                // absente sur une très vieille base : on continue, mais sans
                // faire croire que tout va bien.
                self::$indexErrors[] = $name . ' : ' . str_cut($e->getMessage(), 120);
            }
        }

        self::setSetting('schema_version', '1');
    }

    /**
     * Expression SQL portable donnant l'indice d'intervalle d'une date.
     *
     * La différence est calculée entre deux dates lues par la base dans le même
     * fuseau : les décalages s'annulent, y compris au changement d'heure. Un
     * calcul en horodatage Unix brut serait faux, SQLite lisant les dates en UTC
     * alors qu'elles sont stockées en heure locale.
     *
     * Le paramètre de la date de départ doit être passé AVANT ceux du WHERE.
     */
    public static function bucketExpr(string $column, int $step): string
    {
        $step = max(1, $step);
        return self::driver() === 'mysql'
            ? "FLOOR(TIMESTAMPDIFF(SECOND, ?, $column) / $step)"
            : "CAST((CAST(strftime('%s', $column) AS INTEGER) - CAST(strftime('%s', ?) AS INTEGER)) / $step AS INTEGER)";
    }

    /** Index dont la création a échoué : lu par l'écran des réglages. */
    private static array $indexErrors = [];

    /** @return array<string,true> Noms d'index existants, pour MySQL. */
    private static function indexNames(): array
    {
        try {
            $rows = self::all('SELECT DISTINCT INDEX_NAME AS n FROM information_schema.STATISTICS
                               WHERE TABLE_SCHEMA = DATABASE()');
        } catch (\PDOException) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) $out[(string)$r['n']] = true;
        return $out;
    }

    /**
     * Index manquants après une migration.
     *
     * Un index absent ne casse rien tout de suite : il rend l'outil lent quand
     * la table des mesures grossit, c'est-à-dire des semaines plus tard, quand
     * personne ne fait le lien. L'écran des réglages le dit donc tout de suite.
     *
     * @return array<int,string>
     */
    public static function indexIssues(): array
    {
        return self::$indexErrors;
    }

    /**
     * Découpe une liste d'identifiants en paquets pour les requêtes « IN (…) ».
     *
     * SQLite compilé avant la version 3.32 refuse au-delà de 999 paramètres liés,
     * et c'est exactement le SQLite qu'on trouve sur un hébergement mutualisé un
     * peu ancien, la cible principale de cet outil. Un parc de mille cinq cents
     * sondes cassait donc la page d'accueil, sur la machine où ça compte le plus.
     *
     * Le rappel reçoit chaque paquet et son résultat est concaténé.
     *
     * @param callable(array):array $fn
     */
    public static function chunk(array $ids, callable $fn, int $size = 400): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
        if (!$ids) return [];
        if (count($ids) <= $size) return (array)$fn($ids);
        $out = [];
        foreach (array_chunk($ids, $size) as $part) {
            foreach ((array)$fn($part) as $k => $row) {
                if (is_int($k)) $out[] = $row; else $out[$k] = $row;
            }
        }
        return $out;
    }

    /**
     * Supprime des sondes et tout ce qui n'a plus de raison d'exister après.
     *
     * Une suppression partielle laisse des traces qui coûtent cher plus tard :
     * des notifications orphelines qui gonflent la table pour rien, et surtout
     * un site vidé de ses sondes dont l'inventaire logiciel continue d'être
     * interrogé chaque jour auprès des sources d'avis. La veille annonçait alors
     * « six composants vulnérables » sur des sites que l'utilisateur croyait
     * avoir supprimés.
     *
     * Un site n'est retiré que s'il ne lui reste aucune sonde : supprimer une
     * page d'un site n'emporte jamais le site.
     *
     * @return array{monitors:int,sites:int,components:int}
     */
    public static function deleteMonitors(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
        $out = ['monitors' => 0, 'sites' => 0, 'components' => 0];
        if (!$ids) return $out;

        // Sites concernés, relevés avant la suppression : après, le lien est perdu.
        $siteIds = array_values(array_unique(array_filter(array_map(
            fn(array $r): int => (int)$r['site_id'],
            self::chunk($ids, function (array $part): array {
                $in = implode(',', array_fill(0, count($part), '?'));
                return self::all("SELECT DISTINCT site_id FROM monitors
                                  WHERE id IN ($in) AND site_id IS NOT NULL", $part);
            })
        ))));

        self::chunk($ids, function (array $part) use (&$out): array {
            $in = implode(',', array_fill(0, count($part), '?'));
            foreach (['checks', 'incidents', 'events', 'daily_stats', 'notifications'] as $t) {
                self::q("DELETE FROM $t WHERE monitor_id IN ($in)", $part);
            }
            $out['monitors'] += self::q("DELETE FROM monitors WHERE id IN ($in)", $part)->rowCount();
            return [];
        });

        foreach ($siteIds as $sid) {
            if ((int)self::val('SELECT COUNT(*) FROM monitors WHERE site_id = ?', [$sid]) > 0) continue;
            $out['components'] += self::q('DELETE FROM components WHERE site_id = ?', [$sid])->rowCount();
            $out['sites']      += self::q('DELETE FROM sites WHERE id = ?', [$sid])->rowCount();
        }
        return $out;
    }

    /**
     * Répare ce qu'une version antérieure a pu laisser derrière elle.
     *
     * Appelée par l'entretien quotidien. Sans elle, une installation qui a
     * supprimé des sondes avant cette correction garderait ses orphelins pour
     * toujours, et la veille continuerait de les compter.
     *
     * @return array{orphans:int,sites:int,components:int}
     */
    public static function repairOrphans(): array
    {
        $out = ['orphans' => 0, 'sites' => 0, 'components' => 0];
        foreach (['checks', 'incidents', 'daily_stats'] as $t) {
            $out['orphans'] += self::q("DELETE FROM $t
                WHERE monitor_id NOT IN (SELECT id FROM monitors)")->rowCount();
        }
        foreach (['events', 'notifications'] as $t) {
            $out['orphans'] += self::q("DELETE FROM $t
                WHERE monitor_id IS NOT NULL AND monitor_id NOT IN (SELECT id FROM monitors)")->rowCount();
        }
        $out['components'] = self::q('DELETE FROM components
            WHERE site_id NOT IN (SELECT id FROM sites)
               OR site_id NOT IN (SELECT site_id FROM monitors WHERE site_id IS NOT NULL)')->rowCount();
        $out['sites'] = self::q('DELETE FROM sites
            WHERE id NOT IN (SELECT site_id FROM monitors WHERE site_id IS NOT NULL)')->rowCount();
        return $out;
    }

    /**
     * Rend au disque l'espace libéré par une purge. SQLite ne le fait pas seul.
     *
     * Trois cas, et il a fallu mesurer pour les distinguer :
     *
     *   - le journal WAL grossit pendant une grosse suppression et n'est pas
     *     rendu à la fin de la transaction. Un point de contrôle tronquant le
     *     règle, et c'est de loin le plus rentable : c'est lui qui expliquait
     *     l'essentiel des 350 Mo gagnés par un fichier censé maigrir ;
     *   - une base créée avec auto_vacuum incrémental rend ses pages libres par
     *     tranches, sans verrou long : c'est le régime normal ;
     *   - une base créée par une version antérieure a auto_vacuum à 0, et là
     *     seul un VACUUM complet rend la place. Il réécrit tout le fichier, il
     *     lui faut autant d'espace libre que la base pèse, et il verrouille
     *     pendant tout ce temps. On ne le déclenche donc jamais tout seul : il
     *     faut le demander (bin/cron.php --vacuum ou le bouton des réglages).
     *
     * L'ORDRE des deux opérations compte, et l'inverse ne rend presque rien : en
     * mode WAL, les pages libérées par un vacuum incrémental atterrissent
     * d'abord dans le journal. Faire le point de contrôle avant ne rendait donc
     * que 5 Mo là où l'ordre correct en rend 480. Vacuum d'abord, journal
     * ensuite.
     *
     * @param  float $budgetSec  temps maximal passé ici (le reste attend)
     * @return array{freed_bytes:int,free_pages:int,needs_vacuum:bool}
     */
    public static function compact(float $budgetSec = 1.5): array
    {
        $out = ['freed_bytes' => 0, 'free_pages' => 0, 'needs_vacuum' => false];
        if (self::driver() !== 'sqlite') return $out;

        $file   = (string)(Config::get('db.sqlite') ?: UPTIMEEZ_ROOT . '/data/uptimeez.sqlite');
        $weight = function () use ($file): int {
            clearstatcache(true, $file);
            clearstatcache(true, $file . '-wal');
            return (int)@filesize($file) + (int)@filesize($file . '-wal');
        };
        $before = $weight();
        $t0     = microtime(true);

        try {
            $mode = (int)self::pdo()->query('PRAGMA auto_vacuum')->fetchColumn();
            $free = (int)self::pdo()->query('PRAGMA freelist_count')->fetchColumn();
            $out['free_pages'] = $free;

            if ($mode === 2) {
                // Par petites tranches : chacune est sa propre transaction, donc
                // le verrou d'écriture est rendu entre deux. Une page de
                // l'interface qui écrit attend quelques dizaines de
                // millisecondes, pas dix secondes.
                while ($free > 0 && microtime(true) - $t0 < $budgetSec) {
                    self::pdo()->exec('PRAGMA incremental_vacuum(500)');
                    $now = (int)self::pdo()->query('PRAGMA freelist_count')->fetchColumn();
                    if ($now >= $free) break;              // ne descend plus
                    $free = $now;
                }
            } elseif ($free > 2000) {
                // Plus de 8 Mo de pages libres inatteignables : seul un VACUUM
                // complet les rend, et c'est à l'exploitant de choisir son
                // moment. Le cas n'existe que pour une base créée avant la
                // correction de l'ordre des PRAGMA de connexion.
                $out['needs_vacuum'] = true;
            }

            // Le journal ne se rend qu'à un point de contrôle. Tronquer coûte
            // proportionnellement à sa taille : on ne le fait que s'il pèse.
            $wal = (int)@filesize($file . '-wal');
            self::pdo()->exec($wal > 8 * 1024 * 1024
                ? 'PRAGMA wal_checkpoint(TRUNCATE)'
                : 'PRAGMA wal_checkpoint(PASSIVE)');
        } catch (PDOException) {
            return $out;
        }

        $out['freed_bytes'] = max(0, $before - $weight());
        return $out;
    }

    /**
     * VACUUM complet : la seule façon de récupérer la place sur une base créée
     * avant la correction de l'ordre des PRAGMA. Il passe aussi la base en
     * auto_vacuum incrémental, donc il n'est à faire qu'une fois.
     *
     * Long et verrouillant par nature : il n'est jamais appelé par une passe
     * planifiée ordinaire.
     *
     * @return array{ok:bool,freed_bytes:int,seconds:float,error:string}
     */
    public static function vacuum(): array
    {
        $out = ['ok' => false, 'freed_bytes' => 0, 'seconds' => 0.0, 'error' => ''];
        if (self::driver() !== 'sqlite') { $out['ok'] = true; return $out; }

        $file   = (string)(Config::get('db.sqlite') ?: UPTIMEEZ_ROOT . '/data/uptimeez.sqlite');
        $weight = function () use ($file): int {
            clearstatcache(true, $file);
            clearstatcache(true, $file . '-wal');
            return (int)@filesize($file) + (int)@filesize($file . '-wal');
        };
        // Le journal compte dans ce que l'exploitant voit sur son disque : le
        // mesurer sans lui annonçait 6 Mo rendus pour 480 Mo réellement libérés.
        $before = $weight();
        $size   = (int)@filesize($file);
        $free   = (int)@disk_free_space(dirname($file));
        // VACUUM réécrit le fichier à côté : sans la place pour deux copies, il
        // échoue en cours de route, ce qui est la pire façon d'échouer.
        if ($free > 0 && $free < $size * 1.2) {
            $out['error'] = t('Espace disque insuffisant : {free} libres pour une base de {size}.',
                              ['free' => human_bytes($free), 'size' => human_bytes($size)]);
            return $out;
        }

        $t0 = microtime(true);
        try {
            self::pdo()->exec('PRAGMA auto_vacuum = INCREMENTAL');
            self::pdo()->exec('VACUUM');           // applique le nouveau réglage
            self::pdo()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        } catch (PDOException $e) {
            $out['error'] = $e->getMessage();
            return $out;
        }
        $out['ok'] = true;
        $out['seconds'] = microtime(true) - $t0;
        $out['freed_bytes'] = max(0, $before - $weight());
        return $out;
    }

    /**
     * Aligne les colonnes d'une table existante sur sa définition courante.
     *
     * C'est le chemin de mise à jour : on relit la définition qui vient d'être
     * exécutée et on ajoute ce qui manque. Aucune colonne n'est jamais
     * supprimée ni modifiée : seulement ajoutée.
     */
    private static function syncColumns(string $table, string $createSql): void
    {
        $existing = self::columns($table);
        if (!$existing) return;

        $inner = substr($createSql, (int)strpos($createSql, '(') + 1);
        $inner = (string)preg_replace('~/\*.*?\*/~s', '', $inner);   // commentaires
        $depth = 0; $buf = ''; $defs = [];
        for ($i = 0, $n = strlen($inner); $i < $n; $i++) {
            $c = $inner[$i];
            if ($c === '(') $depth++;
            if ($c === ')') { if ($depth === 0) break; $depth--; }
            if ($c === ',' && $depth === 0) { $defs[] = trim($buf); $buf = ''; continue; }
            $buf .= $c;
        }
        if (trim($buf) !== '') $defs[] = trim($buf);

        foreach ($defs as $def) {
            $def = trim(preg_replace('~\s+~', ' ', $def) ?? $def);
            if ($def === '' || preg_match('~^(PRIMARY|UNIQUE|KEY|FOREIGN|CONSTRAINT|INDEX)\b~i', $def)) continue;
            if (!preg_match('~^([a-z_][a-z0-9_]*)\s+(.+)$~i', $def, $m)) continue;
            [$col, $type] = [$m[1], $m[2]];
            if (in_array($col, $existing, true)) continue;
            if (stripos($type, 'PRIMARY KEY') !== false || stripos($type, 'AUTOINCREMENT') !== false) continue;
            // SQLite refuse d'ajouter une colonne NOT NULL sans valeur par défaut.
            if (stripos($type, 'DEFAULT') === false) $type = (string)preg_replace('~\s*NOT NULL~i', '', $type);
            try {
                self::pdo()->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$type}");
            } catch (PDOException) { /* déjà présente ou type refusé : on continue */ }
        }
    }


    public static function columns(string $table): array
    {
        try {
            if (self::driver() === 'mysql') {
                return array_column(self::all("SHOW COLUMNS FROM {$table}"), 'Field');
            }
            return array_column(self::all("PRAGMA table_info({$table})"), 'name');
        } catch (PDOException) {
            return [];
        }
    }

    public static function tableExists(string $table): bool
    {
        try {
            if (self::driver() === 'mysql') {
                return (bool)self::val('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]);
            }
            return (bool)self::val("SELECT 1 FROM sqlite_master WHERE type='table' AND name = ?", [$table]);
        } catch (PDOException) {
            return false;
        }
    }
}
