<?php
/**
 * Uptimer : réception d'un battement.
 *
 * À appeler par le script à surveiller (cron, sauvegarde, import nocturne) :
 *   curl -fsS "https://uptimer.exemple.fr/beat.php?k=LACLE" > /dev/null
 *
 * Optionnel : &m=texte pour joindre un mot (nombre de fichiers traités, durée…).
 * Aucune authentification par session : la clé fait office de secret, et une clé
 * inconnue ne révèle rien.
 */
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use Uptimer\Config;
use Uptimer\Db;
use Uptimer\Heartbeat;

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

// Le script appelant lit du texte, pas du HTML. Et la clé de battement n'est pas
// une authentification d'exploitant : aucun détail technique ne sort d'ici.
Uptimer\Fail::asText();

if (!Config::isInstalled()) {
    http_response_code(503);
    exit(t('{app} n\'est pas installé.') . "\n");
}

$token = (string)($_GET['k'] ?? $_POST['k'] ?? '');
$note  = trim((string)($_GET['m'] ?? $_POST['m'] ?? ''));

if ($token === '') {
    http_response_code(400);
    exit(t('Clé manquante.') . "\n");
}

Db::migrate();
$r = Heartbeat::beat($token, $note);

if (!$r['ok']) {
    // Réponse volontairement identique pour une clé absente ou invalide.
    http_response_code(404);
    exit("Inconnu.\n");
}

echo "OK\n";
