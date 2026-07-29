<?php
declare(strict_types=1);

namespace Uptimer;

use PDO;
use PDOException;

final class Db
{
    private static ?PDO $pdo = null;
    private static string $driver = 'sqlite';

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
            $path = $cfg['sqlite'] ?: (UPTIMER_ROOT . '/data/uptimer.sqlite');
            $dir  = dirname($path);
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            self::$pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            self::$pdo->exec('PRAGMA journal_mode = WAL');
            self::$pdo->exec('PRAGMA synchronous = NORMAL');
            self::$pdo->exec('PRAGMA busy_timeout = 8000');
            self::$pdo->exec('PRAGMA foreign_keys = ON');
            // Permet de rendre l'espace disque après purge, sans VACUUM complet.
            self::$pdo->exec('PRAGMA auto_vacuum = INCREMENTAL');
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
            decisions {$txt},                              /* journal des décisions de Uptimer */
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
            started_at {$ts} NOT NULL,
            ended_at {$ts} DEFAULT NULL,
            duration_sec {$int} DEFAULT NULL,
            checks_failed {$int} NOT NULL DEFAULT 1,
            ack_at {$ts} DEFAULT NULL,
            last_notified_at {$ts} DEFAULT NULL,
            notify_count {$int} NOT NULL DEFAULT 0
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
            'CREATE INDEX IF NOT EXISTS idx_checks_mon_ts ON checks (monitor_id, ts)',
            'CREATE INDEX IF NOT EXISTS idx_checks_ts ON checks (ts)',
            'CREATE INDEX IF NOT EXISTS idx_inc_mon ON incidents (monitor_id, started_at)',
            'CREATE INDEX IF NOT EXISTS idx_inc_open ON incidents (ended_at)',
            'CREATE INDEX IF NOT EXISTS idx_mon_next ON monitors (enabled, next_check_at)',
            'CREATE INDEX IF NOT EXISTS idx_mon_site ON monitors (site_id)',
            // Un composant est unique par site : l'index le garantit, ce qui
            // évite d'accumuler des doublons à chaque analyse de page.
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_comp_uniq ON components (site_id, kind, slug)',
            'CREATE INDEX IF NOT EXISTS idx_comp_scan ON components (checked_at)',
            'CREATE INDEX IF NOT EXISTS idx_comp_vuln ON components (vuln_count)',
            'CREATE INDEX IF NOT EXISTS idx_events_ts ON events (ts)',
            // Le jeton client est cherché à chaque ouverture de l'espace : il
            // doit être unique et indexé.
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_clients_token ON clients (token)',
            'CREATE INDEX IF NOT EXISTS idx_sites_client ON sites (client_id)',
        ];
        foreach ($idx as $sql) {
            try { $pdo->exec($sql); } catch (PDOException) { /* MySQL < 8 : index déjà là */ }
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

    /** Récupère l'espace libéré après une purge (SQLite ne rend pas la place tout seul). */
    public static function compact(): void
    {
        if (self::driver() !== 'sqlite') return;
        try { self::pdo()->exec('PRAGMA incremental_vacuum'); } catch (PDOException) {}
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
