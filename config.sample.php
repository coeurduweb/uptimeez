<?php
/**
 * Uptimeez - configuration.
 * Copiez ce fichier en config.php (l'installeur le fait pour vous) et adaptez.
 */
return [
    // --- Base de données ---------------------------------------------------
    // 'sqlite' : zéro configuration, recommandé sur mutualisé.
    // 'mysql'  : si vous surveillez beaucoup de cibles (> 300 checks/min).
    'db' => [
        'driver'   => 'sqlite',
        'sqlite'   => __DIR__ . '/data/uptimeez.sqlite',
        'host'     => 'localhost',
        'port'     => 3306,
        'name'     => 'uptimeez',
        'user'     => '',
        'pass'     => '',
        'charset'  => 'utf8mb4',
    ],

    // --- Accès -------------------------------------------------------------
    'auth' => [
        // Généré par l'installeur : password_hash('...', PASSWORD_DEFAULT)
        'password_hash' => '',
        'session_name'  => 'uptimeez',
        'session_ttl'   => 86400 * 30,
    ],

    // --- Application -------------------------------------------------------
    'app' => [
        'name'      => 'Uptimeez',
        'base_url'  => '',        // ex: https://monitoring.example.com  (pour les liens dans les alertes)
        'timezone'  => 'Europe/Paris',
        'locale'    => 'fr',
        // Jeton du status page public : vide = status public désactivé
        'public_token' => '',
        // Clé pour déclencher le cron par URL (si pas d'accès crontab) : /cron.php?key=...
        'cron_key'  => '',
    ],

    // --- Valeurs par défaut des sondes -------------------------------------
    'defaults' => [
        'interval_sec'   => 300,   // 5 min
        'timeout_sec'    => 15,
        'retries'        => 2,     // relances avant de déclarer DOWN (anti faux positif)
        'retry_delay_ms' => 1500,
        'ssl_warn_days'  => 14,
        'slow_ms'        => 3000,  // au-delà : "dégradé"
        'user_agent'     => 'Mozilla/5.0 (compatible; UptimeezBot/1.0; +monitoring)',
        'css_drop_pct'   => 35,    // chute de poids CSS tolérée avant alerte
        'max_parallel'   => 10,    // requêtes simultanées par passe de cron
        'retention_days' => 60,   // purge des checks unitaires au-delà
    ],

    // --- Sécurité ----------------------------------------------------------
    'security' => [
        // Refuse les cibles en boucle locale, en plage privée et l'adresse de
        // métadonnées 169.254.169.254. Désactivé par défaut : surveiller un
        // intranet ou un préprod sur 192.168.x est un usage légitime. À activer
        // si l'interface est accessible à des tiers.
        'block_private_ranges' => false,
    ],

    // --- Notifications -----------------------------------------------------
    'notify' => [
        'discord' => ['enabled' => false, 'webhook' => ''],
        'slack'   => ['enabled' => false, 'webhook' => ''],
        'mail'    => [
            'enabled' => false,
            'to'      => '',                     // séparés par des virgules
            'from'    => '',
            'from_name' => 'Uptimeez',
            // 'mail' = fonction mail() PHP (o2switch OK), 'smtp' = SMTP direct
            'transport' => 'mail',
            'smtp' => ['host' => '', 'port' => 587, 'user' => '', 'pass' => '', 'secure' => 'tls'],
        ],
        'webhook' => ['enabled' => false, 'url' => ''],  // POST JSON générique
        // Anti-spam
        'resend_after_min'  => 60,    // rappel si toujours HS
        'notify_recovery'   => true,
        'notify_degraded'   => true,  // lenteur / SSL bientôt expiré / contenu modifié
        'quiet_hours'       => '',    // ex: '23:00-07:00' (les CRITIQUES passent quand même)
    ],
];
