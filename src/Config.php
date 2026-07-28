<?php
declare(strict_types=1);

namespace Uptimer;

final class Config
{
    private static array $data = [];
    private static bool  $loaded = false;
    private static bool  $installed = false;
    private static string $file = '';

    public static function load(): void
    {
        if (self::$loaded) return;
        self::$loaded = true;

        $sample = UPTIMER_ROOT . '/config.sample.php';
        // UPTIMER_CONFIG permet de faire tourner une instance isolée (tests E2E,
        // seconde installation) sans toucher au config.php de production.
        $env    = getenv('UPTIMER_CONFIG');
        $file   = ($env && is_file($env)) ? $env : UPTIMER_ROOT . '/config.php';
        self::$file = $file;

        self::$data = is_file($sample) ? (require $sample) : [];
        if (is_file($file)) {
            self::$installed = true;
            self::$data = self::merge(self::$data, (require $file));
        }
    }

    public static function isInstalled(): bool
    {
        return self::$installed && (string)self::get('auth.password_hash', '') !== '';
    }

    /** Accès par chemin pointé : Config::get('db.driver') */
    public static function get(string $path, mixed $default = null): mixed
    {
        $node = self::$data;
        foreach (explode('.', $path) as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) return $default;
            $node = $node[$key];
        }
        return $node;
    }

    public static function all(): array
    {
        return self::$data;
    }

    /**
     * Surcharge en mémoire, sans écrire config.php.
     * Utilisé par le banc d'essai pour travailler sur une base jetable.
     */
    public static function set(string $path, mixed $value): void
    {
        $keys = explode('.', $path);
        $node = &self::$data;
        foreach ($keys as $k) {
            if (!isset($node[$k]) || !is_array($node[$k])) $node[$k] = [];
            $node = &$node[$k];
        }
        $node = $value;
        unset($node);
    }

    /** Écrit config.php (fusion profonde avec l'existant). */
    public static function file(): string
    {
        self::load();
        return self::$file !== '' ? self::$file : UPTIMER_ROOT . '/config.php';
    }

    public static function save(array $patch): bool
    {
        $file    = self::file();
        $current = is_file($file) ? (require $file) : [];
        $merged  = self::merge(is_array($current) ? $current : [], $patch);

        $php = "<?php\n// Uptimer - configuration générée le " . date('Y-m-d H:i:s') . "\n"
             . "// Ne pas versionner ce fichier.\nreturn " . self::export($merged) . ";\n";

        $ok = @file_put_contents($file, $php, LOCK_EX) !== false;
        if ($ok) {
            @chmod($file, 0640);
            if (function_exists('opcache_invalidate')) @opcache_invalidate($file, true);
            self::$data = self::merge(self::$data, $merged);
            self::$installed = true;
        }
        return $ok;
    }

    private static function merge(array $base, array $over): array
    {
        foreach ($over as $k => $v) {
            $base[$k] = (is_array($v) && isset($base[$k]) && is_array($base[$k]) && !array_is_list($v))
                ? self::merge($base[$k], $v)
                : $v;
        }
        return $base;
    }

    private static function export(mixed $v, int $depth = 1): string
    {
        $pad = str_repeat('    ', $depth);
        if (is_array($v)) {
            if ($v === []) return '[]';
            $list = array_is_list($v);
            $out  = "[\n";
            foreach ($v as $k => $item) {
                $out .= $pad . ($list ? '' : var_export((string)$k, true) . ' => ')
                     . self::export($item, $depth + 1) . ",\n";
            }
            return $out . str_repeat('    ', $depth - 1) . ']';
        }
        if (is_bool($v) || is_int($v) || is_float($v) || $v === null) return var_export($v, true);
        // Les chemins absolus calculés (__DIR__) sont réécrits en dur : acceptable.
        return var_export((string)$v, true);
    }
}
