<?php
/**
 * UptimeEZ - configuration.
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

        /* Ouverture par jeton signé, pour piloter plusieurs instances depuis un
           tableau de bord commun. Laisser vide désactive complètement la
           fonctionnalité : une installation ordinaire n'en a pas besoin et ne
           l'expose pas. 32 caractères minimum, tirés au hasard :
              php -r 'echo bin2hex(random_bytes(32));'
           Le même secret doit être connu de l'émetteur des jetons. */
        'bridge_secret' => '',
        'session_ttl'   => 86400 * 30,
    ],

    // --- Application -------------------------------------------------------
    'app' => [
        'name'      => 'UptimeEZ',
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
        'user_agent'     => 'Mozilla/5.0 (compatible; UptimeEZBot/1.0; +monitoring)',
        'css_drop_pct'   => 35,    // chute de poids CSS tolérée avant alerte
        'max_parallel'   => 10,    // requêtes simultanées par passe de cron
        // PLAFOND PAR HÔTE, né d'un incident et pas d'une intuition. Le 2026-08-04,
        // dix requêtes simultanées vers la MÊME machine lui ont fait répondre « trop
        // de requêtes », et le collecteur lisait cette réponse comme un fichier
        // disparu : 47 alertes en une matinée, 43 fausses. Ce plafond s'applique à
        // tout ce qui passe par le client HTTP, donc aussi aux feuilles de style
        // d'un audit, qui sont ce qui fait le volume. Le baisser à 2 sur un
        // mutualisé strict ; le monter n'a d'intérêt que si la cible est à vous.
        'max_parallel_host' => 4,
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
            'from_name' => 'UptimeEZ',
            // 'mail' = fonction mail() PHP (o2switch OK), 'smtp' = SMTP direct
            'transport' => 'mail',
            'smtp' => ['host' => '', 'port' => 587, 'user' => '', 'pass' => '', 'secure' => 'tls'],
        ],
        // Telegram demande DEUX valeurs et non une URL : le jeton du robot (@BotFather) et
        // l'identifiant de la conversation, qui n'est écrit nulle part dans l'interface et se
        // lit sur api.telegram.org/bot{jeton}/getUpdates après avoir écrit au robot.
        'telegram' => ['enabled' => false, 'token' => '', 'chat_id' => ''],
        // Teams : connecteur classique OU flux Power Automate, les deux formes d'URL sont
        // acceptées, la charge utile porte de quoi satisfaire l'une et l'autre.
        'teams'   => ['enabled' => false, 'webhook' => ''],
        // SMS par Twilio, le seul canal facturé à l'envoi : message court, et destiné à
        // l'escalade plutôt qu'aux alertes ordinaires.
        'sms'     => ['enabled' => false, 'sid' => '', 'token' => '', 'from' => '', 'to' => ''],
        'webhook' => ['enabled' => false, 'url' => ''],  // POST JSON générique
        // Anti-spam
        // ESCALADE : prévenir un SECOND destinataire quand personne n'a acquitté.
        //
        // À zéro, rien ne change : c'est un mécanisme d'astreinte, et une escalade
        // involontaire réveille quelqu'un qui n'avait rien demandé. Elle ne concerne que
        // les pannes réelles, jamais les états « à surveiller » : escaladeur une lenteur
        // vers une seconde équipe à trois heures du matin est exactement le bruit que ce
        // produit refuse de produire.
        //
        // Les canaux d'escalade sont une LISTE SÉPARÉE : envoyer la même alerte deux fois
        // sur le même canal ne prévient personne de plus. Vide = tous les canaux actifs.
        'escalate_after_min' => 0,    // 0 = pas d'escalade
        'escalate_channels'  => '',   // ex: 'mail,webhook' ; vide = tous les canaux actifs
        // LE RAPPEL EST ÉTEINT PAR DÉFAUT DEPUIS LE 2026-08-04, et le motif est une boîte
        // pleine. À 60 minutes, chaque incident ouvert réémettait toutes les heures : les
        // captures de Laurent montraient « Consecutive failures 36 » sur des « Still down »
        // que personne ne lisait plus. Un rappel utile est un rappel qu'on a demandé.
        'resend_after_min'  => 0,     // 0 = pas de rappel ; le mécanisme reste disponible
        'notify_recovery'   => true,
        'notify_degraded'   => true,  // lenteur / SSL bientôt expiré / contenu modifié
        'quiet_hours'       => '',    // ex: '23:00-07:00' (les CRITIQUES passent quand même)
    ],
];
