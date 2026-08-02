<?php
/** Gabarit commun. Le thème est appliqué avant peinture pour éviter tout clignotement. */
use Uptimeez\Auth;
use Uptimeez\Config;
use Uptimeez\Demo;
use Uptimeez\I18n;
use Uptimeez\Stats;
use Uptimeez\Ui;

$appName  = (string)Config::get('app.name', I18n::APP);
$isClient = ($view ?? '') === 'client';
$isPublic = ($view ?? '') === 'status' || $isClient;
$isLogin  = ($view ?? '') === 'login';
// L'espace client ne doit rien savoir des autres clients : son compteur de
// pannes est celui de ses sites, fourni par la page, jamais le total global.
// Sans cette distinction, le titre de l'onglet annoncerait « (3) » à un client
// dont les trois sites vont bien.
$summary  = $isClient ? (array)($clientSummary ?? []) : ((!$isLogin) ? Stats::summary() : []);
$titles = [
    'today'    => t('Aujourd\'hui'),      'dashboard' => t('Tableau de bord'),
    'monitor'  => t('Sonde'),             'monitors'  => t('Sondes'),
    'incidents'=> t('Incidents'),         'events'    => t('Journal'),
    'import'   => t('Ajouter des sites'), 'settings'  => t('Réglages'),
    'login'    => t('Connexion'),         'status'    => t('État des services'),
    'report'   => t('Rapport client'),
    'clients'  => t('Clients'),
    'retours'  => t('Retours sur la détection'),
];
$pageTitle = $isClient && ($client['name'] ?? '') !== ''
    ? (string)$client['name']
    : ($titles[$view] ?? $appName);
// Une fiche de sonde appartient à la section « Sondes » : l'onglet reste marqué
// pour ne pas perdre le repère de navigation.
$navCurrent = $view === 'monitor' ? 'monitors' : ($view === 'events' ? 'incidents' : $view);
$down = (int)($summary['down'] ?? 0);
/**
 * Navigation. En mode simple on n'affiche que les trois écrans dont on a
 * besoin tous les jours ; le mur d'écran et le rapport restent accessibles
 * (lien de pied de page, palette) mais ne prennent pas de place en haut.
 */
$nav = [
    'today'     => [t('Aujourd\'hui'), 'check',   true],
    'dashboard' => [t('Mur'),          'grid',    false],
    'monitors'  => [t('Sondes'),       'list',    true],
    'incidents' => [t('Incidents'),    'history', true],
    'report'    => [t('Rapport'),      'file',    false],
    'clients'   => [t('Clients'),      'users',   false],
    // Pas dans la barre du quotidien : on lit le corpus quand on cherche à comprendre
    // pourquoi un contrôle se trompe, pas tous les matins. Accessible par le pied de
    // page et la palette, comme le mur et le rapport.
    'retours'   => [t('Retours'),      'history', false],
    'settings'  => [t('Réglages'),     'sliders', true],
];
// L'onglet Clients n'a de sens que si l'agence en a créé au moins un : sinon
// c'est un écran vide de plus dans une barre déjà chargée.
if (!$isLogin && !$isPublic && !Uptimeez\Db::val('SELECT COUNT(*) FROM clients')) {
    unset($nav['clients']);
}
if (!expert()) {
    // Un écran ouvert explicitement reste dans la barre : on ne fait jamais
    // disparaître l'onglet de la page où l'on se trouve. Le filtrage conserve
    // l'ordre d'origine, pour que l'onglet ne saute pas d'une place à l'autre.
    $nav = array_filter($nav, fn($n, $k) => $n[2] || $k === ($navCurrent ?? ''),
                        ARRAY_FILTER_USE_BOTH);
}
$lang    = I18n::lang();
$dir     = I18n::dir();
$uiMode  = Ui::mode();
?>
<!doctype html>
<html lang="<?= e($lang) ?>" dir="<?= e($dir) ?>" data-theme="light" data-mode="<?= e($uiMode) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light dark">
<title><?= ($down > 0 ? '(' . $down . ') ' : '') . e($pageTitle) . ' · ' . e($appName) ?></title>
<link rel="stylesheet" href="assets/app.css?v=<?= UPTIMEEZ_VERSION ?>">
<?php
/**
 * Le favicon : le sigle du produit, dont la barre qui dépasse porte l'état.
 *
 * Il fait deux choses à la fois, et c'est voulu. La géométrie (quatre barres, une
 * plus haute) est la marque : l'onglet est reconnaissable parmi trente autres. La
 * couleur de cette barre est le seul état qui compte, lisible sans lire :
 * opérationnel, à regarder, hors service.
 *
 * La barre est verte quand tout va bien, alors que le logo statique la met en
 * ambre. Ce n'est pas une incohérence : une barre ambre en permanence apprendrait
 * à l'oeil à ignorer l'ambre, et c'est exactement le réflexe qu'on ne veut pas
 * installer. Ici la couleur ne décore pas, elle alerte.
 *
 * LE PIÈGE, ET IL A COÛTÉ CE FAVICON PENDANT TOUTE LA VIE DU PRODUIT : les
 * couleurs s'écrivent « # » et non « %23 ». Le code précédent pré-encodait le
 * dièse, puis rawurlencode() encodait le « % » en « %25 », si bien que le
 * navigateur lisait « fill="%230d8f56" » — une couleur invalide, silencieusement
 * ignorée. Le favicon était un rond NOIR sur toutes les installations, et l'état
 * ne s'est jamais affiché. Aucune erreur nulle part : une image simplement fausse.
 * L'encodage se fait une seule fois, ici, sur du SVG écrit avec de vrais dièses.
 */
// SUR LA DÉMONSTRATION, le favicon porte la marque et non l'état.
//
// Ce n'est pas une exception de confort : la démo est une surface de vitrine, ses
// données sont fictives et remises à zéro chaque heure. L'état qu'elle afficherait
// n'appelle aucune action, alors que la reconnaissance de la marque, elle, compte.
// Signalé le 2026-07-29 : l'onglet de la démo montrait une barre verte quand le
// site et le back-office en montraient une ambre, ce qui se lit comme deux
// produits différents.
$etatCouleur = Demo::on()
    ? '#f0ad3c'                                             // la marque, telle quelle
    : ($down > 0
        ? '#f0555f'                                         // hors service
        : (((int)($summary['degraded'] ?? 0)) > 0
            ? '#f0ad3c'                                     // à regarder
            : '#34c785'));                                  // opérationnel
$sigle = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
    . '<rect width="64" height="64" rx="14" fill="#080b12"/>'
    . '<g fill="#34c785">'
    . '<rect x="9" y="16" width="8" height="32" rx="4"/>'
    . '<rect x="20.5" y="16" width="8" height="32" rx="4"/>'
    . '<rect x="43.5" y="16" width="8" height="32" rx="4"/>'
    . '</g>'
    . '<rect x="32" y="10" width="8" height="44" rx="4" fill="' . $etatCouleur . '"/>'
    . '</svg>';
?>
<link rel="icon" href="data:image/svg+xml,<?= rawurlencode($sigle) ?>">
<script>
(function () {
  try {
    var t = localStorage.getItem('uptimeez-theme');
    if (!t) t = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    document.documentElement.dataset.theme = t;
  } catch (e) {}
})();
</script>
</head>
<body>

<?php if (!$isLogin && !$isPublic): ?>
<header class="topbar">
  <div class="topbar-in">
    <a class="brand" href="<?= e(u('today')) ?>"><?= Ui::brand(21) ?> <?= e($appName) ?></a>
    <nav class="nav" aria-label="<?= te('Navigation principale') ?>">
      <?php foreach ($nav as $key => [$label, $icon, $always]): ?>
        <a href="<?= e(u($key)) ?>"<?= $navCurrent === $key ? ' aria-current="page"' : '' ?>>
          <?= Ui::icon($icon, 15) ?> <?= e($label) ?><?php
          if ($key === 'incidents' && !empty($summary['open_incidents'])): ?>
            <span class="badge badge-bad"><?= (int)$summary['open_incidents'] ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="topbar-actions">
      <button class="btn btn-sm" id="palette-open" title="<?= te('Palette de commandes (Ctrl+K)') ?>">
        <?= Ui::icon('search', 15) ?> <span class="pal-hint"><?= te('Rechercher') ?></span> <kbd><?= te('Ctrl K') ?></kbd>
      </button>
      <a class="btn btn-primary btn-sm" href="<?= e(u('import')) ?>"><?= Ui::icon('plus', 15) ?> <?= te('Ajouter') ?></a>

      <!-- Niveau de détail : un clic, pas un réglage à aller chercher -->
      <div class="seg" role="group" aria-label="<?= te('Niveau de détail de l\'interface') ?>">
        <?php foreach ([['simple', t('Simple'), t('L\'essentiel : ce qui casse et quoi faire.')],
                        ['expert', t('Complet'), t('Tous les réglages, toutes les mesures, tous les écrans.')]] as [$mk, $ml, $mt]): ?>
          <a class="seg-i<?= $uiMode === $mk ? ' seg-on' : '' ?>"
             href="<?= e(u($view ?? 'today', ['ui' => $mk] + (isset($_GET['id']) ? ['id' => (int)$_GET['id']] : []))) ?>"
             title="<?= e($mt) ?>"<?= $uiMode === $mk ? ' aria-current="true"' : '' ?>><?= e($ml) ?></a>
        <?php endforeach; ?>
      </div>

      <button class="btn btn-ghost btn-icon btn-sm" id="theme-toggle" title="<?= te('Basculer clair / sombre') ?>"
              aria-label="<?= te('Basculer entre thème clair et sombre') ?>"><?= Ui::icon('moon', 16) ?></button>

      <!-- Langue : la liste complète tient dans un select, sans écran de réglage -->
      <form method="get" class="lang-pick" action="index.php">
        <?php foreach ($_GET as $gk => $gv):
          if ($gk === 'lang' || !is_scalar($gv)) continue; ?>
          <input type="hidden" name="<?= e((string)$gk) ?>" value="<?= e((string)$gv) ?>">
        <?php endforeach; ?>
        <?php if (!isset($_GET['p'])): ?><input type="hidden" name="p" value="<?= e($view ?? 'today') ?>"><?php endif; ?>
        <label class="sr-only" for="lang-pick"><?= te('Langue') ?></label>
        <select id="lang-pick" name="lang" onchange="this.form.submit()" title="<?= te('Langue') ?>">
          <?php foreach (I18n::available() as $code => $native): ?>
            <option value="<?= e($code) ?>"<?= $code === $lang ? ' selected' : '' ?>>
              <?= e(I18n::flag($code)) ?> <?= e($native) ?></option>
          <?php endforeach; ?>
        </select>
        <noscript><button class="btn btn-sm" type="submit">OK</button></noscript>
      </form>

      <a class="btn btn-ghost btn-sm" href="<?= e(u('logout')) ?>" title="<?= te('Se déconnecter') ?>"><?= te('Quitter') ?></a>
    </div>
  </div>
</header>
<?php endif; ?>

<?php if ($isLogin || $isPublic): ?>
<form method="get" class="lang-pick lang-float" action="index.php">
  <?php foreach ($_GET as $gk => $gv):
    if ($gk === 'lang' || !is_scalar($gv)) continue; ?>
    <input type="hidden" name="<?= e((string)$gk) ?>" value="<?= e((string)$gv) ?>">
  <?php endforeach; ?>
  <label class="sr-only" for="lang-pick-2"><?= te('Langue') ?></label>
  <select id="lang-pick-2" name="lang" onchange="this.form.submit()">
    <?php foreach (I18n::available() as $code => $native): ?>
      <option value="<?= e($code) ?>"<?= $code === $lang ? ' selected' : '' ?>>
        <?= e(I18n::flag($code)) ?> <?= e($native) ?></option>
    <?php endforeach; ?>
  </select>
  <noscript><button class="btn btn-sm" type="submit">OK</button></noscript>
</form>
<?php endif; ?>

<main class="wrap" id="main">
<?php if (Config::get('app.demo', false)): ?>
  <?php /* Le jeu de démonstration porte des noms de services réels : ce
           bandeau dit sans ambiguïté que les mesures sont inventées. Il reste
           visible sur les captures d'écran, c'est le but. */ ?>
  <div class="demo-flag" role="note">
    <?= Ui::icon('info', 16) ?>
    <span><strong><?= te('Mode démonstration') ?></strong> :
      <?= te('les sites affichés sont réels, toutes les mesures sont fictives. Les pannes portent sur des sous-domaines de préproduction qui n\'existent pas.') ?></span>
    <code class="mono"><?= te('php bin/demo.php --purge') ?></code>
  </div>
<?php endif; ?>
<?php if (!empty($flash)): ?>
  <div class="alert alert-<?= e($flash[0]) ?>" role="status">
    <?= Ui::icon($flash[0] === 'ok' ? 'check' : ($flash[0] === 'bad' ? 'alert' : 'info'), 18) ?>
    <div><?= e($flash[1]) ?></div>
  </div>
<?php endif; ?>
<?php
$file = __DIR__ . '/' . preg_replace('~[^a-z_]~', '', $view) . '.php';
if (is_file($file)) require $file; else require __DIR__ . '/today.php';
?>
</main>

<div id="toasts" aria-live="polite" aria-atomic="false"></div>
<?php if (!$isLogin && !$isPublic): ?>
<script>window.UPTIMEEZ = { csrf: <?= json_encode(Auth::csrf()) ?>, view: <?= json_encode($view) ?>,
  queue: <?= json_encode(array_values((array)($_SESSION['uptimeez_setup_queue'] ?? []))) ?> };</script>
<?php unset($_SESSION['uptimeez_setup_queue']); ?>
<script src="assets/app.js?v=<?= UPTIMEEZ_VERSION ?>"></script>
<?php endif; ?>
</body>
</html>
