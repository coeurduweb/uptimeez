<?php
/**
 * Uptimeez - amorçage commun (web + CLI).
 * Aucune dépendance externe : autoload maison, PHP >= 8.2.
 *
 * Le plancher était annoncé à 8.1, sans qu'aucune suite n'ait jamais tourné
 * dessus : la version n'est plus distribuée par les dépôts, et son support de
 * sécurité s'est arrêté le 31 décembre 2025. Une exigence qu'on ne peut pas
 * vérifier n'est pas une exigence, c'est un espoir. Le plancher est donc celui
 * qui est réellement éprouvé, et qui reçoit encore des correctifs.
 *
 * Vérifié en exécutant les dix suites : 8.2.30, 8.3, 8.4.20 et 8.5.5.
 * Le plafond n'existe pas : le code ne déclenche aucune dépréciation sous 8.5
 * avec error_reporting à E_ALL.
 */
declare(strict_types=1);

define('UPTIMEEZ_ROOT', dirname(__DIR__));
define('UPTIMEEZ_VERSION', '1.0.1');

if (PHP_VERSION_ID < 80200) {
    http_response_code(500);
    exit('Uptimeez requires PHP 8.2 or newer. Uptimeez nécessite PHP 8.2 ou plus récent. (' . 'PHP ' . PHP_VERSION . ')');
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
