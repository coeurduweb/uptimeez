<?php
/**
 * Uptimer : installeur. Vérifie l'environnement, crée la base et le mot de passe.
 * Supprimez ce fichier après l'installation si vous le souhaitez.
 */
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

\Uptimer\I18n::init();   // l'installeur parle déjà la langue du navigateur

use Uptimer\Config;
use Uptimer\Db;

$alreadyInstalled = Config::isInstalled();
$errors = [];
$notice = null;

// --- Diagnostic de l'environnement ---------------------------------------
$checks = [];
$checks[] = [t('PHP 8.1 ou plus récent'), PHP_VERSION_ID >= 80100, PHP_VERSION];
foreach (['curl' => t('requêtes HTTP'), 'pdo' => t('base de données'), 'openssl' => 'certificats SSL',
          'mbstring' => 'texte UTF-8', 'json' => t('échanges JSON')] as $ext => $why) {
    $checks[] = ['Extension ' . $ext . ' (' . $why . ')', extension_loaded($ext), extension_loaded($ext) ? t('présente') : t('absente')];
}
$sqliteOk = in_array('sqlite', PDO::getAvailableDrivers(), true);
$mysqlOk  = in_array('mysql', PDO::getAvailableDrivers(), true);
$checks[] = ['Pilote SQLite ou MySQL', $sqliteOk || $mysqlOk,
             implode(', ', PDO::getAvailableDrivers()) ?: 'aucun'];
$dataDir = UPTIMER_ROOT . '/data';
if (!is_dir($dataDir)) @mkdir($dataDir, 0775, true);
$checks[] = [t('Dossier data/ accessible en écriture'), is_writable($dataDir), $dataDir];
$checks[] = [t('Racine accessible en écriture (config.php)'), is_writable(UPTIMER_ROOT), UPTIMER_ROOT];
$blocking = array_filter($checks, fn($c) => !$c[1]);

// --- Traitement ----------------------------------------------------------
// Une installation déjà faite ne doit jamais pouvoir être réécrite depuis le web :
// sinon n'importe qui pourrait redéfinir le mot de passe d'accès.
if ($alreadyInstalled && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    http_response_code(403);
    exit(t('{app} est déjà installé. Supprimez config.php par FTP/SSH pour réinstaller.'));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !$blocking) {
    $driver = ($_POST['driver'] ?? 'sqlite') === 'mysql' ? 'mysql' : 'sqlite';
    $pass   = (string)($_POST['password'] ?? '');
    $pass2  = (string)($_POST['password2'] ?? '');

    if (strlen($pass) < 8) $errors[] = t('Le mot de passe doit faire au moins 8 caractères.');
    if ($pass !== $pass2)  $errors[] = t('Les deux mots de passe ne correspondent pas.');

    $patch = [
        'db' => ['driver' => $driver],
        'auth' => ['password_hash' => password_hash($pass, PASSWORD_DEFAULT)],
        'app' => [
            'base_url' => rtrim(trim((string)($_POST['base_url'] ?? '')), '/'),
            'timezone' => trim((string)($_POST['timezone'] ?? 'Europe/Paris')) ?: 'Europe/Paris',
            'cron_key' => bin2hex(random_bytes(12)),
        ],
    ];
    if ($driver === 'mysql') {
        $patch['db'] += [
            'host' => trim((string)($_POST['db_host'] ?? 'localhost')),
            'port' => (int)($_POST['db_port'] ?? 3306),
            'name' => trim((string)($_POST['db_name'] ?? '')),
            'user' => trim((string)($_POST['db_user'] ?? '')),
            'pass' => (string)($_POST['db_pass'] ?? ''),
        ];
        if ($patch['db']['name'] === '') $errors[] = t('Indiquez le nom de la base MySQL.');
    } else {
        $patch['db']['sqlite'] = $dataDir . '/uptimer.sqlite';
    }

    if (!$errors) {
        if (!Config::save($patch)) {
            $errors[] = t('Impossible d\'écrire config.php. Vérifiez les droits du dossier.');
        } else {
            try {
                Db::migrate();
                // Protection du dossier de données (Apache / LiteSpeed)
                @file_put_contents($dataDir . '/.htaccess',
                    "Require all denied\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n");
                @file_put_contents($dataDir . '/index.html', '');
                header('Location: index.php?p=login&installed=1');
                exit;
            } catch (Throwable $e) {
                $errors[] = t('Connexion à la base impossible :') . $e->getMessage();
            }
        }
    }
}
?>
<!doctype html>
<html lang="fr" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Installation · Uptimer</title>
<link rel="stylesheet" href="assets/app.css">
<script>(function(){try{var t=localStorage.getItem('uptimer-theme')||(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');document.documentElement.dataset.theme=t;}catch(e){}})();</script>
</head>
<body>
<main class="wrap" style="max-width:760px">
  <div class="row mt-lg" style="justify-content:center;gap:9px">
    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M2 12h4l3 8 4-16 3 8h6"/></svg>
    <h1>Installation de Uptimer</h1>
  </div>
  <p class="center muted small"><?= te('Surveillance de sites : fonctionne sur un hébergement mutualisé, sans Docker.') ?></p>

  <?php if ($alreadyInstalled): ?>
    <div class="alert alert-warn mt">Uptimer est déjà installé : le formulaire est désactivé.
      <a href="index.php"><?= te('Accéder à l\'application') ?></a>.
      <?= te('Pour repartir de zéro, supprimez {file} par FTP ou SSH.',
             ['file' => '<span class="mono">config.php</span>']) ?>
      <?= te('Vous pouvez aussi supprimer {file} : il n\'est plus nécessaire.',
             ['file' => '<span class="mono">install.php</span>']) ?></div>
  <?php endif; ?>

  <div class="panel">
    <div class="panel-head"><h2>Environnement</h2>
      <span class="badge badge-<?= $blocking ? 'bad' : 'ok' ?>"><?= $blocking
        ? e(tn(count($blocking), 'un point à corriger', '{n} points à corriger'))
        : te('tout est bon') ?></span></div>
    <div class="panel-body tight">
      <table class="tbl"><tbody>
        <?php foreach ($checks as [$label, $ok, $detail]): ?>
          <tr><td style="width:26px"><?= $ok ? '✅' : '❌' ?></td>
              <td><?= e($label) ?></td>
              <td class="tiny muted"><?= e((string)$detail) ?></td></tr>
        <?php endforeach; ?>
      </tbody></table>
    </div>
  </div>

  <?php if ($errors): ?>
    <div class="alert alert-bad"><?php foreach ($errors as $er): ?><div>• <?= e($er) ?></div><?php endforeach; ?></div>
  <?php endif; ?>

  <?php if (!$blocking && !$alreadyInstalled): ?>
  <form method="post" class="panel">
    <div class="panel-head"><h2>Configuration</h2></div>
    <div class="panel-body">
      <label class="f"><span><?= te('Mot de passe d\'accès') ?></span>
        <input type="password" name="password" required minlength="8" autocomplete="new-password" autofocus>
        <span class="hint"><?= te('8 caractères minimum. C\'est le seul identifiant : il n\'y a pas de nom d\'utilisateur.') ?></span></label>
      <label class="f"><span>Confirmation</span>
        <input type="password" name="password2" required minlength="8" autocomplete="new-password"></label>

      <label class="f"><span>Adresse publique de Uptimer (facultatif)</span>
        <input type="text" name="base_url" placeholder="https://exemple.fr/uptimer"
               value="<?= e((($_SERVER['HTTPS'] ?? '') && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://'
                    . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')), '/')) ?>">
        <span class="hint"><?= te('Pour que les alertes contiennent un lien direct vers la fiche concernée.') ?></span></label>

      <label class="f" style="max-width:280px"><span>Fuseau horaire</span>
        <input type="text" name="timezone" value="Europe/Paris"></label>

      <fieldset>
        <legend><?= te('Base de données') ?></legend>
        <label class="check"><input type="radio" name="driver" value="sqlite" checked onclick="document.getElementById('mysql').hidden=true">
          <span><?= te('SQLite (recommandé)') ?><span class="hint"><?= te('Aucune configuration : un fichier dans {dir}. Parfait jusqu\'à quelques centaines de sondes.',
                ['dir' => '<span class="mono">data/</span>']) ?></span></span></label>
        <label class="check"><input type="radio" name="driver" value="mysql" onclick="document.getElementById('mysql').hidden=false" <?= $mysqlOk ? '' : 'disabled' ?>>
          <span>MySQL / MariaDB<span class="hint"><?= te('Pour un gros parc ou un historique très long.') ?></span></span></label>
        <div id="mysql" hidden class="grid-3 mt">
          <label class="f"><span><?= te('Hôte') ?></span><input type="text" name="db_host" value="localhost"></label>
          <label class="f"><span>Port</span><input type="number" name="db_port" value="3306"></label>
          <label class="f"><span>Base</span><input type="text" name="db_name" placeholder="user_uptimer"></label>
          <label class="f"><span>Utilisateur</span><input type="text" name="db_user"></label>
          <label class="f"><span>Mot de passe</span><input type="password" name="db_pass" autocomplete="new-password"></label>
        </div>
      </fieldset>

      <button class="btn btn-primary">Installer</button>
    </div>
  </form>
  <?php endif; ?>

  <?php if (!$blocking): ?>
  <div class="panel">
    <div class="panel-head"><h2><?= te('Après l\'installation') ?></h2></div>
    <div class="panel-body small soft">
      <p><strong>1.</strong> <?= te('Ajoutez la tâche planifiée, à lancer chaque minute. Sur cPanel o2switch : « Tâches cron ».') ?></p>
      <pre class="mono small" style="background:var(--surface-2);padding:10px;border-radius:8px;overflow:auto">* * * * * <?= e(PHP_BINDIR . '/php') ?> <?= e(UPTIMER_ROOT . '/cron.php') ?> &gt;/dev/null 2&gt;&amp;1</pre>
      <p><strong>2.</strong> Collez votre liste de domaines dans « Ajouter des sites » : le CMS, les pages à suivre
        et la chaîne de contrôle sont déduits automatiquement.</p>
      <p><strong>3.</strong> <?= te('Renseignez au moins un canal d\'alerte dans les réglages : Discord, Slack, e-mail ou webhook.') ?></p>
    </div>
  </div>
  <?php endif; ?>
</main>
</body>
</html>
