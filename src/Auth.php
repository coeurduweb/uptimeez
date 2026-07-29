<?php
declare(strict_types=1);

namespace Uptimer;

final class Auth
{
    private static bool $started = false;

    public static function start(): void
    {
        if (self::$started || PHP_SAPI === 'cli') return;
        self::$started = true;
        if (session_status() === PHP_SESSION_ACTIVE) return;

        $name = (string)Config::get('auth.session_name', 'uptimer');
        session_name(preg_replace('~[^a-z0-9_]~i', '', $name) ?: 'uptimer');
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
        if (empty($_SESSION['uptimer_auth'])) return false;
        $ttl = (int)Config::get('auth.session_ttl', 2592000);
        if ($ttl > 0 && (time() - (int)($_SESSION['uptimer_auth_at'] ?? 0)) > $ttl) {
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
        $_SESSION['uptimer_auth'] = true;
        $_SESSION['uptimer_auth_at'] = time();
        $_SESSION['uptimer_csrf'] = bin2hex(random_bytes(16));
        self::note(true);
        return true;
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
        if (empty($_SESSION['uptimer_csrf'])) $_SESSION['uptimer_csrf'] = bin2hex(random_bytes(16));
        return (string)$_SESSION['uptimer_csrf'];
    }

    public static function checkCsrf(?string $token): bool
    {
        self::start();
        $ref = (string)($_SESSION['uptimer_csrf'] ?? '');
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
