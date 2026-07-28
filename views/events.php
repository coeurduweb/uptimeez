<?php
/** Journal : évènements de contenu et alertes envoyées. */
use Uptimer\Db;
use Uptimer\Notify\Notifier;
use Uptimer\Ui;

$events = Db::all('SELECT e.*, m.name FROM events e LEFT JOIN monitors m ON m.id = e.monitor_id
                   ORDER BY e.ts DESC LIMIT 200');
$notifs = Db::all('SELECT n.*, m.name FROM notifications n LEFT JOIN monitors m ON m.id = n.monitor_id
                   ORDER BY n.ts DESC LIMIT 120');
?>
<div class="row-between mt"><h1>Journal</h1></div>

<div class="panel">
  <div class="panel-head"><h2><?= te('Évènements de contenu') ?></h2>
    <span class="muted small"><?= te('mots surveillés, pages modifiées, CSS redéployé, domaines qui expirent') ?></span></div>
  <div class="panel-body tight">
    <div class="table-scroll"><table class="tbl">
      <thead><tr><th><?= te('Date') ?></th><th><?= te('Type') ?></th><th><?= te('Sonde') ?></th><th><?= te('Détail') ?></th></tr></thead>
      <tbody>
      <?php foreach ($events as $ev): ?>
        <tr>
          <td class="small nowrap"><?= e(date('d/m/Y H:i', strtotime((string)$ev['ts']))) ?></td>
          <td><?= Ui::badge(Notifier::eventLabel((string)$ev['kind']), 'info') ?></td>
          <td class="small"><?php if ($ev['monitor_id']): ?>
            <a href="<?= e(u('monitor', ['id' => (int)$ev['monitor_id']])) ?>"><?= e((string)($ev['name'] ?? '—')) ?></a>
          <?php else: ?>—<?php endif; ?></td>
          <td class="small"><?= e((string)$ev['message']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$events): ?><tr><td colspan="4" class="muted small" style="padding:20px"><?= te('Rien à signaler pour l\'instant.') ?></td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </div>
</div>

<div class="panel">
  <div class="panel-head"><h2><?= te('Alertes envoyées') ?></h2>
    <span class="muted small"><?= te('utile pour vérifier qu\'un canal fonctionne vraiment') ?></span></div>
  <div class="panel-body tight">
    <div class="table-scroll"><table class="tbl">
      <thead><tr><th><?= te('Date') ?></th><th><?= te('Canal') ?></th><th><?= te('Type') ?></th><th><?= te('Sonde') ?></th><th><?= te('Résultat') ?></th></tr></thead>
      <tbody>
      <?php foreach ($notifs as $n): ?>
        <tr>
          <td class="small nowrap"><?= e(date('d/m/Y H:i:s', strtotime((string)$n['ts']))) ?></td>
          <td class="small"><?= e((string)$n['channel']) ?></td>
          <td class="small"><?= e((string)$n['kind']) ?></td>
          <td class="small"><?php if ($n['monitor_id']): ?>
            <a href="<?= e(u('monitor', ['id' => (int)$n['monitor_id']])) ?>"><?= e((string)($n['name'] ?? '—')) ?></a>
          <?php else: ?>—<?php endif; ?></td>
          <td class="small"><?= (int)$n['ok'] === 1 ? Ui::badge('envoyée', 'ok') : Ui::badge('échec', 'bad') ?>
            <span class="tiny muted"><?= e(str_cut((string)$n['response'], 90)) ?></span></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$notifs): ?><tr><td colspan="5" class="muted small" style="padding:20px">Aucune alerte envoyée.
        <?= t('Testez vos canaux depuis les {link}.',
        ['link' => '<a href="' . e(u('settings')) . '">' . te('réglages') . '</a>']) ?></td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </div>
</div>
