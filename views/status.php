<?php
/** Page d'état publique (accès par jeton, sans authentification). */
use Uptimeez\I18n;
use Uptimeez\Config;
use Uptimeez\Db;
use Uptimeez\Stats;
use Uptimeez\Ui;

$rows = Db::all("SELECT m.*, s.name AS site_name, s.domain FROM monitors m
                 LEFT JOIN sites s ON s.id = m.site_id
                 WHERE m.enabled = 1 AND m.role = 'primary' ORDER BY m.name ASC");
$down = 0; $deg = 0;
foreach ($rows as $r) {
    if ($r['status'] === 'down') $down++;
    elseif ($r['status'] === 'degraded') $deg++;
}
$ids = array_map(fn($r) => (int)$r['id'], $rows);
$sparks = Stats::sparkBatch($ids, 86400, 48);
?>
<div class="row" style="justify-content:center;gap:9px;margin:26px 0 4px">
  <?= Ui::brand(24) ?>
  <span style="font-size:20px;font-weight:700;letter-spacing:-.02em"><?= e((string)Config::get('app.name', I18n::APP)) ?></span>
</div>
<p class="center muted small"><?= te('État des services · mis à jour') ?> <?= e(human_since((string)Db::setting('last_run_at'))) ?></p>

<section class="band <?= $down ? 'band-bad' : ($deg ? 'band-warn' : 'band-ok') ?>">
  <div class="band-icon"><?= $down ? '✕' : ($deg ? '!' : '✓') ?></div>
  <div class="grow">
    <div class="band-title">
      <?php if ($down): ?><?= tne($down, 'un service indisponible', '{n} services indisponibles') ?>
      <?php elseif ($deg): ?><?= te('Fonctionnement dégradé') ?>
      <?php else: ?><?= te('Tous les services sont opérationnels') ?><?php endif; ?>
    </div>
    <div class="band-sub"><?= tne(count($rows), 'un service surveillé', '{n} services surveillés') ?></div>
  </div>
</section>

<div class="panel">
  <div class="panel-body tight">
    <table class="tbl">
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td style="width:26px"><?= Ui::dot((string)$r['status']) ?></td>
          <td><strong><?= e((string)($r['site_name'] ?: $r['name'])) ?></strong>
            <div class="tiny muted"><?= e((string)($r['domain'] ?: host_of((string)$r['url']))) ?></div></td>
          <td style="width:220px"><?= Ui::sparkline($sparks[(int)$r['id']] ?? [], 220, 26) ?></td>
          <td class="num"><span class="badge badge-<?= Ui::uptimeTone($r['uptime_30d'] !== null ? (float)$r['uptime_30d'] : null) ?>">
            <?= Ui::pct($r['uptime_30d'] !== null ? (float)$r['uptime_30d'] : null, 2) ?></span>
            <div class="tiny muted"><?= te('30 jours') ?></div></td>
          <td class="num small"><?= e(Ui::statusLabel((string)$r['status'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<p class="center tiny muted mt"><?= te('Les barres représentent les 24 dernières heures.') ?></p>
