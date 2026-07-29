<?php
/** Historique des incidents, filtrable. */
use Uptimer\Auth;
use Uptimer\Db;
use Uptimer\Notify\Notifier;
use Uptimer\Ui;

$csrf   = Auth::csrf();
$onlyId = (int)($_GET['id'] ?? 0);
$state  = (string)($_GET['s'] ?? 'all');
$range  = (string)($_GET['range'] ?? '30d');
$from   = date('Y-m-d H:i:s', time() - Ui::rangeSeconds($range));

$where  = ['i.started_at >= ?'];
$params = [$from];
if ($onlyId) { $where[] = 'i.monitor_id = ?'; $params[] = $onlyId; }
if ($state === 'open')   $where[] = 'i.ended_at IS NULL';
if ($state === 'closed') $where[] = 'i.ended_at IS NOT NULL';

$rows = Db::all('SELECT i.*, m.name, m.url FROM incidents i JOIN monitors m ON m.id = i.monitor_id
                 WHERE ' . implode(' AND ', $where) . ' ORDER BY i.started_at DESC LIMIT 2000', $params);

$open = 0; $total = 0; $downSec = 0;
foreach ($rows as $r) {
    $total++;
    if (!$r['ended_at']) { $open++; $downSec += max(0, time() - strtotime((string)$r['started_at'])); }
    else $downSec += (int)$r['duration_sec'];
}
?>
<div class="row-between mt">
  <h1><?= te('Incidents') ?></h1>
  <div class="row">
    <div class="segmented">
      <?php foreach (['all' => 'Tous', 'open' => 'En cours', 'closed' => 'Clos'] as $k => $l): ?>
        <a href="<?= e(u('incidents', ['s' => $k, 'range' => $range, 'id' => $onlyId ?: null])) ?>" class="<?= $state === $k ? 'on' : '' ?>"><?= e($l) ?></a>
      <?php endforeach; ?>
    </div>
    <?= Ui::rangePicker($range, ['p' => 'incidents', 's' => $state, 'id' => $onlyId ?: null]) ?>
    <a class="btn btn-sm" href="<?= e(u('incidents', ['s' => $state, 'range' => $range, 'id' => $onlyId ?: null, 'export' => 'csv'])) ?>"
       title="<?= te('Tableur des incidents de la période (rapport client, justificatif de SLA)') ?>"><?= te('Export CSV') ?></a>
  </div>
</div>

<section class="stats mt">
  <div class="stat"><div class="stat-label"><?= te('Incidents') ?></div><div class="stat-value"><?= $total ?></div>
    <div class="stat-hint"><?= te('sur la période') ?></div></div>
  <div class="stat"><div class="stat-label"><?= te('En cours') ?></div>
    <div class="stat-value <?= $open ? 'v-bad' : 'v-ok' ?>"><?= $open ?></div><div class="stat-hint"><?= te('non résolus') ?></div></div>
  <div class="stat"><div class="stat-label"><?= te('Indisponibilité cumulée') ?></div>
    <div class="stat-value"><?= e(human_duration($downSec)) ?></div><div class="stat-hint"><?= te('toutes sondes confondues') ?></div></div>
  <div class="stat"><div class="stat-label"><?= te('Durée moyenne') ?></div>
    <div class="stat-value"><?= e(human_duration($total ? (int)round($downSec / $total) : 0)) ?></div>
    <div class="stat-hint"><?= te('temps de rétablissement') ?></div></div>
</section>

<div class="panel">
  <div class="panel-body tight">
    <div class="table-scroll">
      <table class="tbl">
        <thead><tr><th><?= te('Sonde') ?></th><th><?= te('Cause') ?></th><th><?= te('Début') ?></th><th><?= te('Fin') ?></th><th class="num"><?= te('Durée') ?></th><th class="num"><?= te('Échecs') ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td>
              <?= Ui::dot($r['ended_at'] ? 'up' : (string)$r['severity']) ?>
              <a href="<?= e(u('monitor', ['id' => (int)$r['monitor_id']])) ?>"><?= e((string)$r['name']) ?></a>
              <div class="tiny muted truncate" style="max-width:340px"><?= e(str_cut((string)$r['url'], 70)) ?></div>
            </td>
            <td>
              <?= Ui::reasonBadge((string)$r['reason_code']) ?>
              <div class="tiny muted"><?= e(verdict_text(['last_message' => $r['message'],
                    'last_message_vars' => $r['message_vars'] ?? null], 110)) ?></div>
            </td>
            <td class="small nowrap"><?= e(date('d/m/Y H:i', strtotime((string)$r['started_at']))) ?></td>
            <td class="small nowrap"><?= $r['ended_at'] ? e(date('d/m/Y H:i', strtotime((string)$r['ended_at']))) : '<span class="v-bad">—</span>' ?></td>
            <td class="num small nowrap">
              <?= $r['ended_at'] ? e(human_duration((int)$r['duration_sec']))
                  : '<span class="v-bad">' . e(human_duration(max(0, time() - strtotime((string)$r['started_at'])))) . '</span>' ?>
            </td>
            <td class="num small"><?= (int)$r['checks_failed'] ?></td>
            <td class="num nowrap">
              <?php if (!$r['ended_at']): ?>
                <form method="post" style="display:inline">
                  <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <?php if (!$r['ack_at']): ?>
                    <input type="hidden" name="action" value="ack_incident">
                    <button class="btn btn-sm btn-ghost" title="<?= te('Stoppe les rappels d\'alerte') ?>"><?= te('Pris en compte') ?></button>
                  <?php else: ?>
                    <input type="hidden" name="action" value="close_incident">
                    <button class="btn btn-sm btn-ghost"><?= te('Clore') ?></button>
                  <?php endif; ?>
                </form>
              <?php elseif ((int)$r['notify_count'] > 0): ?>
                <span class="tiny muted"><?= (int)$r['notify_count'] ?> <?= te('alerte(s)') ?></span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <tr><td colspan="7"><div class="empty"><h3><?= te('Aucun incident sur la période') ?></h3>
            <p class="muted"><?= te('C\'est la bonne nouvelle du jour.') ?></p></div></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
