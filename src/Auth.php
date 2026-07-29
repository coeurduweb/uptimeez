<?php
declare(strict_types=1);

namespace Uptimeez;

final class Auth
{
    private static bool $started = false;

    public static function start(): void
    {
        if (self::$started || PHP_SAPI === 'cli') return;
        self::$started = true;
        if (session_status() === PHP_SESSION_ACTIVE) return;

        $name = (string)Config::get('auth.session_name', 'uptimeez');
        session_name(preg_replace('~[^a-z0-9_]~i', '', $name) ?: 'uptimeez');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')
                          || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
        ]);
        @session_start();
    }

    public static function check(): bool
    {
        self::start();
        if (empty($_SESSION['uptimeez_auth'])) return false;
        $ttl = (int)Config::get('auth.session_ttl', 2592000);
        if ($ttl > 0 && (time() - (int)($_SESSION['uptimeez_auth_at'] ?? 0)) > $ttl) {
            self::logout();
            return false;
        }
        return true;
    }

    public static function attempt(string $password): bool
    {
        self::start();
        $hash = (string)Config::get('auth.password_hash', '');
        // Temporisation constante : limite l'intérêt d'une attaque par essais.
        usleep(random_int(150000, 350000));
        if ($hash === '' || !password_verify($password, $hash)) {
            self::note(false);
            return false;
        }
        // Renouvellement de l'identifiant de session à l'élévation de privilège :
        // sans cela, un identifiant imposé à la victime avant sa connexion
        // resterait valide après (fixation de session, OWASP A07).
        if (session_status() === PHP_SESSION_ACTIVE) {
            $keep = $_SESSION;
            session_regenerate_id(true);
            $_SESSION = $keep;
        }
        $_SESSION['uptimeez_auth'] = true;
        $_SESSION['uptimeez_auth_at'] = time();
        $_SESSION['uptimeez_csrf'] = bin2hex(random_bytes(16));
        self::note(true);
        return true;
    }

    /**
     * Ouvre une session depuis un jeton signé, au lieu d'un mot de passe.
     *
     * À quoi ça sert. Quand plusieurs instances d'UptimeEZ sont pilotées depuis un
     * tableau de bord commun (une agence qui en gère une par client, un service
     * hébergé), demander le mot de passe de chacune à chaque ouverture est
     * intenable. Le tableau de bord signe un jeton très court avec un secret
     * partagé, et l'instance l'accepte à la place du mot de passe.
     *
     * **Désactivé tant que `auth.bridge_secret` est absent de la configuration.**
     * Une installation ordinaire ne voit jamais cette fonctionnalité, et ne
     * l'expose donc pas. C'est aussi pour ça qu'elle vit dans le produit libre
     * plutôt que dans une couche par-dessus : une couche par-dessus devrait
     * modifier index.php, et modifier le moteur c'est le forker.
     *
     * Format : `v1.<charge utile>.<signature>`, les deux en base64 pour URL.
     * La charge utile est un objet JSON `{iat, exp, nonce}`.
     * La signature est un HMAC-SHA256 de « v1.<charge utile> ».
     *
     * Ce qui est refusé, et c'est le cœur du sujet :
     *   - secret absent, ou trop court pour être sérieux (< 32 caractères) ;
     *   - signature invalide, comparée en temps constant ;
     *   - jeton expiré, ou daté du futur (horloges désynchronisées) ;
     *   - durée de vie annoncée supérieure à {@see self::BRIDGE_MAX_TTL} ;
     *   - **jeton déjà employé** : un jeton passe une fois. Sans ça, un jeton
     *     capturé dans un journal d'accès ou un référent rouvrirait la session
     *     pendant toute sa durée de vie.
     */
    public const BRIDGE_MAX_TTL = 120;

    public static function attemptToken(string $token): bool
    {
        self::start();
        $secret = (string)Config::get('auth.bridge_secret', '');
        // Un secret court est un secret cassable : on préfère refuser la
        // fonctionnalité que l'offrir mal.
        if (strlen($secret) < 32) return false;

        $parts = explode('.', $token);
        if (count($parts) !== 3 || $parts[0] !== 'v1') { self::note(false); return false; }
        [$ver, $b64, $sig] = $parts;

        $expected = self::b64url(hash_hmac('sha256', $ver . '.' . $b64, $secret, true));
        if (!hash_equals($expected, $sig)) { self::note(false); return false; }

        $data = json_decode((string)self::b64urlDecode($b64), true);
        if (!is_array($data)) { self::note(false); return false; }

        $now   = time();
        $iat   = (int)($data['iat'] ?? 0);
        $exp   = (int)($data['exp'] ?? 0);
        $nonce = (string)($data['nonce'] ?? '');
        // Une tolérance de 30 s absorbe un décalage d'horloge sans ouvrir la porte.
        if ($nonce === '' || $exp <= $now || $iat > $now + 30) { self::note(false); return false; }
        if ($exp - $iat > self::BRIDGE_MAX_TTL) { self::note(false); return false; }
        if (!self::consumeNonce($nonce, $exp)) { self::note(false); return false; }

        // Même élévation de privilège que par mot de passe : on renouvelle
        // l'identifiant de session (fixation de session, OWASP A07).
        if (session_status() === PHP_SESSION_ACTIVE) {
            $keep = $_SESSION;
            session_regenerate_id(true);
            $_SESSION = $keep;
        }
        $_SESSION['uptimeez_auth']    = true;
        $_SESSION['uptimeez_auth_at'] = $now;
        $_SESSION['uptimeez_csrf']    = bin2hex(random_bytes(16));
        // Tracé : une ouverture par jeton n'est pas une ouverture par mot de passe,
        // et l'exploitant doit pouvoir faire la différence dans son journal.
        $_SESSION['uptimeez_via']     = 'bridge';
        self::note(true);
        return true;
    }

    /**
     * Marque un jeton comme employé. Rend false s'il l'était déjà.
     *
     * Les jetons vivent deux minutes au plus : la table des employés est donc
     * naturellement minuscule, et on la purge à chaque passage. Pas de table
     * dédiée pour ça, un réglage suffit.
     */
    private static function consumeNonce(string $nonce, int $exp): bool
    {
        try {
            // L'usage unique EXIGE un stockage : c'est le contrat de la méthode.
            // Or l'écran de connexion s'affiche avant que le schéma ne soit migré
            // (la migration a lieu après requireLogin). Sur une instance dont la
            // table des réglages n'existe pas encore, l'écriture échouait, le
            // jeton était donc refusé, et le pont ne fonctionnait pas du tout hors
            // du banc d'essai, qui migrait explicitement. On s'assure donc du
            // schéma ici. La migration est idempotente et elle tourne déjà à
            // chaque requête authentifiée.
            Db::migrate();
            $used = jdec(Db::setting('bridge_used'));
            $now  = time();
            foreach ($used as $k => $until) {
                if ((int)$until <= $now) unset($used[$k]);
            }
            if (isset($used[$nonce])) return false;
            $used[$nonce] = $exp;
            // Garde-fou : si une horloge part en arrière, la liste ne gonfle pas.
            if (count($used) > 500) $used = array_slice($used, -500, null, true);
            Db::setSetting('bridge_used', jenc($used));
            return true;
        } catch (\Throwable) {
            // Sans base, on ne peut pas garantir l'usage unique : on refuse.
            return false;
        }
    }

    /** Le jeton voyage dans une URL : base64 pour URL, sans remplissage. */
    public static function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function b64urlDecode(string $s): string
    {
        return (string)base64_decode(strtr($s, '-_', '+/'), true);
    }

    /**
     * Fabrique un jeton. Présent ici pour que le banc d'essai éprouve le même
     * code que l'émetteur, et pour qu'une agence puisse s'en servir sans
     * réimplémenter la signature de travers.
     */
    public static function makeToken(int $ttl = 60): ?string
    {
        $secret = (string)Config::get('auth.bridge_secret', '');
        if (strlen($secret) < 32) return null;
        $ttl  = max(5, min(self::BRIDGE_MAX_TTL, $ttl));
        $now  = time();
        $b64  = self::b64url((string)jenc(['iat' => $now, 'exp' => $now + $ttl,
                                           'nonce' => bin2hex(random_bytes(16))]));
        return 'v1.' . $b64 . '.' . self::b64url(hash_hmac('sha256', 'v1.' . $b64, $secret, true));
    }

    /** Verrou simple après échecs répétés (stocké en base, donc partagé). */
    public static function lockedFor(): int
    {
        $tries = jdec(Db::setting('login_tries'));
        $ip    = self::ip();
        $rec   = $tries[$ip] ?? null;
        if (!$rec) return 0;
        if ((int)$rec['n'] < 6) return 0;
        $until = (int)$rec['at'] + 300;
        return max(0, $until - time());
    }

    private static function note(bool $ok): void
    {
        try {
            $tries = jdec(Db::setting('login_tries'));
            $ip = self::ip();
            if ($ok) unset($tries[$ip]);
            else {
                $rec = $tries[$ip] ?? ['n' => 0, 'at' => 0];
                if (time() - (int)$rec['at'] > 900) $rec = ['n' => 0, 'at' => 0];
                $tries[$ip] = ['n' => (int)$rec['n'] + 1, 'at' => time()];
            }
            // On ne garde que les 20 dernières adresses.
            if (count($tries) > 20) $tries = array_slice($tries, -20, null, true);
            Db::setSetting('login_tries', jenc($tries));
        } catch (\Throwable) {}
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) @session_destroy();
    }

    public static function csrf(): string
    {
        self::start();
        if (empty($_SESSION['uptimeez_csrf'])) $_SESSION['uptimeez_csrf'] = bin2hex(random_bytes(16));
        return (string)$_SESSION['uptimeez_csrf'];
    }

    public static function checkCsrf(?string $token): bool
    {
        self::start();
        $ref = (string)($_SESSION['uptimeez_csrf'] ?? '');
        return $ref !== '' && is_string($token) && hash_equals($ref, $token);
    }

    public static function requireLogin(): void
    {
        if (self::check()) return;
        // Une requête d'API répond en JSON ; une requête de page redirige vers
        // l'écran de connexion. On se fie au script appelé, pas à un en-tête
        // que n'importe quel client peut poser.
        $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $wantsJson = $script === 'api.php'
            || str_starts_with((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
        if ($wantsJson) {
            json_out(['error' => 'auth', 'message' => t('Session expirée')], 401);
        }
        header('Location: ' . u('login'));
        exit;
    }

    public static function ip(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? 'cli');
    }
}
