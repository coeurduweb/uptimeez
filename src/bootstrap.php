<?php
/**
 * Uptimeez - amorçage commun (web + CLI).
 * Aucune dépendance externe : autoload maison, PHP >= 8.1.
 */
declare(strict_types=1);

define('UPTIMEEZ_ROOT', dirname(__DIR__));
define('UPTIMEEZ_VERSION', '1.0.1');

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('Uptimeez requires PHP 8.1 or newer. Uptimeez nécessite PHP 8.1 ou plus récent. (' . 'PHP ' . PHP_VERSION . ')');
}

spl_autoload_register(function (string $class): void {
    $prefix = 'Uptimeez\\';
    if (!str_starts_with($class, $prefix)) return;
    $rel = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = UPTIMEEZ_ROOT . '/src/' . $rel . '.php';
    if (is_file($file)) require $file;
});

require_once UPTIMEEZ_ROOT . '/src/helpers.php';

use Uptimeez\Config;
use Uptimeez\Fail;

// Avant Config::load() : un config.php cassé est justement une des pannes que
// cette garde doit savoir expliquer.
Fail::install();

Config::load();
date_default_timezone_set(Config::get('app.timezone', 'Europe/Paris'));
mb_internal_encoding('UTF-8');

if (PHP_SAPI === 'cli') {
    set_time_limit(0);
    ini_set('memory_limit', '256M');
}
