<?php
/** Liste complète des sondes, avec recherche, tri et actions de masse. */
use Uptimeez\Auth;
use Uptimeez\Db;
use Uptimeez\Ui;

$csrf = Auth::csrf();
$sort = (string)($_GET['sort'] ?? 'status');
$q    = trim((string)($_GET['q'] ?? ''));
$order = match ($sort) {
    'name'   => 'm.name ASC',
    'uptime' => '(m.uptime_24h IS NULL) ASC, m.uptime_24h ASC',
    'ms'     => '(m.last_ms IS NULL) ASC, m.last_ms DESC',
    'last'   => '(m.last_check_at IS NULL) ASC, m.last_check_at DESC',
    default  => "CASE m.status WHEN 'down' THEN 0 WHEN 'degraded' THEN 1 WHEN 'unknown' THEN 2 WHEN 'up' THEN 3 ELSE 4 END, m.name ASC",
};
$params = [];
$where  = '1=1';
if ($q !== '') {
    $where .= ' AND (m.name LIKE ? OR m.url LIKE ? OR s.domain LIKE ?)';
    $like = '%' . $q . '%';
    $params = [$like, $like, $like];
}
$rows = Db::all("SELECT m.*, s.domain AS site_domain, s.cms AS site_cms
                 FROM monitors m LEFT JOIN sites s ON s.id = m.site_id
                 WHERE $where ORDER BY $order LIMIT 500", $params);
?>
<div class="row-between mt">
  <h1><?= te('Sondes') ?> <span class="muted" style="font-weight:400"><?= count($rows) ?></span></h1>
  <a class="btn btn-primary btn-sm" href="<?= e(u('import')) ?>"><?= Ui::icon('plus', 15) ?> <?= te('Ajouter des sites') ?></a>
</div>

<form method="get" class="toolbar">
  <input type="hidden" name="p" value="monitors">
  <div class="search">
    <span class="ico-l"><?= Ui::icon('search', 16) ?></span>
    <label class="sr-only" for="qq"><?= te('Rechercher une sonde') ?></label>
    <input type="search" id="qq" name="q" value="<?= e($q) ?>" placeholder="<?= te('Nom, URL ou domaine…') ?>">
  </div>
  <label class="sr-only" for="sort"><?= te('Trier') ?></label>
  <select id="sort" name="sort" onchange="this.form.submit()" style="max-width:210px">
    <?php foreach (['status' => t('Les problèmes d\'abord'), 'name' => t('Par nom'), 'uptime' => t('Uptime croissant'),
                    'ms' => t('Les plus lentes'), 'last' => t('Vérifiées récemment')] as $k => $l): ?>
      <option value="<?= $k ?>" <?= $sort === $k ? 'selected' : '' ?>><?= e($l) ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn btn-sm"><?= te('Filtrer') ?></button>
</form>

<form method="post" id="bulk-form">
  <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
  <input type="hidden" name="action" value="bulk">
  <div class="panel">
    <div class="panel-head">
      <label class="check"><input type="checkbox" id="check-all-rows"> <span class="small"><?= te('Tout cocher') ?></span></label>
      <span class="muted small grow" id="sel-count"></span>
      <div class="row">
        <label class="sr-only" for="ba"><?= te('Action de masse') ?></label>
        <select name="bulk_action" id="ba" style="max-width:210px">
          <option value="check"><?= te('Vérifier maintenant') ?></option>
          <option value="setup"><?= te('Relancer la détection auto') ?></option>
          <option value="disable"><?= te('Mettre en pause') ?></option>
          <option value="enable"><?= te('Réactiver') ?></option>
          <option value="interval"><?= te('Changer l\'intervalle') ?></option>
          <option value="delete"><?= te('Supprimer') ?></option>
        </select>
        <label class="sr-only" for="bi"><?= te('Nouvel intervalle') ?></label>
        <select name="bulk_interval" id="bi" style="max-width:130px">
          <?php foreach ([30 => '30 s', 60 => '1 min', 300 => '5 min', 600 => '10 min',
                          1800 => '30 min', 3600 => '1 h', 21600 => '6 h'] as $s => $l): ?>
            <option value="<?= $s ?>" <?= $s === 300 ? 'selected' : '' ?>><?= e($l) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-sm" id="bulk-apply" disabled onclick="return UptimeezConfirmBulk(this.form)"><?= te('Appliquer') ?></button>
      </div>
    </div>
    <div class="table-scroll">
      <table class="tbl">
        <thead><tr>
          <th style="width:34px"><span class="sr-only"><?= te('Sélection') ?></span></th>
          <th style="width:26px"><span class="sr-only"><?= te('État') ?></span></th>
          <th><?= te('Sonde') ?></th><th><?= te('Situation') ?></th>
          <th class="num"><?= te('Uptime 24 h') ?></th><th class="num"><?= te('Réponse') ?></th>
          <th><?= te('Contrôles') ?></th><th class="num"><?= te('Vérifiée') ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $m): ?>
          <tr>
            <td><input type="checkbox" name="ids[]" value="<?= (int)$m['id'] ?>" class="row-check"
                       aria-label="Sélectionner <?= e((string)$m['name']) ?>"></td>
            <td><?= Ui::dot((string)$m['status']) ?></td>
            <td>
              <a href="<?= e(u('monitor', ['id' => (int)$m['id']])) ?>"><?= e((string)$m['name']) ?></a>
              <?php if ($m['role'] === 'primary'): ?> <?= Ui::badge('site', 'info') ?><?php endif; ?>
              <?php if ($m['kind'] === 'api'): ?> <?= Ui::badge('API') ?><?php endif; ?>
              <div class="tiny muted truncate" style="max-width:380px"><?= e(str_cut((string)$m['url'], 90)) ?></div>
            </td>
            <td class="small">
              <?= e(Ui::statusLabel((string)$m['status'])) ?>
              <?php if ($m['status'] !== 'up' && $m['reason_code']): ?>
                <div class="tiny"><?= Ui::reasonBadge((string)$m['reason_code']) ?></div>
              <?php endif; ?>
            </td>
            <td class="num"><span class="badge badge-<?= Ui::uptimeTone($m['uptime_24h'] !== null ? (float)$m['uptime_24h'] : null) ?>">
              <?= Ui::pct($m['uptime_24h'] !== null ? (float)$m['uptime_24h'] : null, 1) ?></span></td>
            <td class="num small"><?= Ui::ms($m['last_ms'] !== null ? (int)$m['last_ms'] : null) ?></td>
            <td class="small">
              <?php
              $flags = [];
              if ((int)$m['check_css'] === 1) {
                  $flags[] = 'CSS' . ($m['css_state'] === 'broken' ? ' ✗' : ($m['css_state'] === 'warn' ? ' !' : ''));
              }
              if ((int)$m['check_db'] === 1)  $flags[] = 'BDD';
              if ((int)$m['check_ssl'] === 1) $flags[] = 'SSL' . ($m['ssl_days_left'] !== null ? ' ' . (int)$m['ssl_days_left'] . 'j' : '');
              if ($m['expect_string']) $flags[] = 'preuve';
              if ($m['watch_string'])  $flags[] = t('mot-clé');
              echo '<span class="tiny muted">' . e(implode(' · ', $flags)) . '</span>';
              ?>
            </td>
            <td class="num tiny muted nowrap"><?= e(human_since((string)$m['last_check_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <tr><td colspan="8"><div class="empty"><h3><?= $q !== '' ? te('Aucun résultat') : te('Aucune sonde') ?></h3>
            <?php if ($q !== ''): ?><a class="btn mt" href="<?= e(u('monitors')) ?>"><?= te('Effacer la recherche') ?></a>
            <?php else: ?><a class="btn btn-primary mt" href="<?= e(u('import')) ?>"><?= te('Ajouter des sites') ?></a><?php endif; ?>
          </div></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</form>

<?= Ui::accOpen('manual', 'plus', t('Créer une sonde à la main'), t('API, page protégée, fichier particulier…')) ?>
<?= Ui::accBody() ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="action" value="save_monitor">
    <?php $mon = []; require __DIR__ . '/partials/monitor_form.php'; ?>
    <label class="switchrow"><input type="checkbox" name="autodetect" checked>
      <span class="sw-text"><span class="sw-title"><?= te('Détecter la technologie et déduire la chaîne de contrôle après création') ?></span>
        <span class="hint"><?= te('Ajoute aussi les pages représentatives si la sonde pointe sur une racine de site.') ?></span></span></label>
    <button class="btn btn-primary mt"><?= Ui::icon('plus', 15) ?> <?= te('Créer la sonde') ?></button>
  </form>
<?= Ui::accClose() ?>
