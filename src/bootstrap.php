<?php
/**
 * Uptimer - amorçage commun (web + CLI).
 * Aucune dépendance externe : autoload maison, PHP >= 8.1.
 */
declare(strict_types=1);

define('UPTIMER_ROOT', dirname(__DIR__));
define('UPTIMER_VERSION', '1.0.1');

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('Uptimer requires PHP 8.1 or newer. Uptimer nécessite PHP 8.1 ou plus récent. (' . 'PHP ' . PHP_VERSION . ')');
}

spl_autoload_register(function (string $class): void {
    $prefix = 'Uptimer\\';
    if (!str_starts_with($class, $prefix)) return;
    $rel = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = UPTIMER_ROOT . '/src/' . $rel . '.php';
    if (is_file($file)) require $file;
});

require_once UPTIMER_ROOT . '/src/helpers.php';

use Uptimer\Config;
use Uptimer\Fail;

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
