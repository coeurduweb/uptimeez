<?php
/** Journal : évènements de contenu et alertes envoyées. */
use Uptimeez\Db;
use Uptimeez\Notify\Notifier;
use Uptimeez\Ui;

// PAGINATION. Mesuré le 2026-08-02 : cet écran faisait 14 685 px de haut pour 320 lignes,
// le plus lourd du produit. Les deux tableaux se paginent ENSEMBLE, sur le même paramètre :
// deux paginations indépendantes sur une même page obligeraient à comprendre laquelle on
// vient de déplacer, ce qui coûte plus cher que ça ne rapporte.
$page = Ui::page();
$saut = Ui::saut($page, Ui::PAR_PAGE_DOUBLE);

$totalEvents = (int) Db::val('SELECT COUNT(*) FROM events');
$totalNotifs = (int) Db::val('SELECT COUNT(*) FROM notifications');

$events = Db::all('SELECT e.*, m.name FROM events e LEFT JOIN monitors m ON m.id = e.monitor_id
                   ORDER BY e.ts DESC LIMIT ' . Ui::PAR_PAGE_DOUBLE . ' OFFSET ' . $saut);
$notifs = Db::all('SELECT n.*, m.name FROM notifications n LEFT JOIN monitors m ON m.id = n.monitor_id
                   ORDER BY n.ts DESC LIMIT ' . Ui::PAR_PAGE_DOUBLE . ' OFFSET ' . $saut);
?>
<?php
// TOUS LES AUTRES ÉCRANS ONT UNE PHRASE SOUS LEUR TITRE, celui-ci n'en avait pas. Un
// visiteur qui arrive sur « Journal » doit savoir en une ligne ce qu'il regarde : ici, ce
// que le moteur a CONSTATÉ (une page modifiée, un CSS redéployé) et ce qu'il a ENVOYÉ.
// Deux choses différentes, et c'est justement pour ça qu'il faut le dire.
?>
<div class="row-between mt"><h1><?= te('Journal') ?></h1></div>
<p class="muted small"><?= te('Ce que le moteur a constaté sur vos sites, et les alertes qu\'il a envoyées. Les constats ne sont pas des pannes : un texte surveillé qui change ou un CSS redéployé s\'inscrivent ici sans déclencher d\'alerte.') ?></p>

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
          <td class="small"><?= (int)$n['ok'] === 1 ? Ui::badge(t('envoyée'), 'ok') : Ui::badge(t('échec'), 'bad') ?>
            <span class="tiny muted"><?= e(str_cut((string)$n['response'], 90)) ?></span></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$notifs): ?><tr><td colspan="5" class="muted small" style="padding:20px"><?= te('Aucune alerte envoyée.') ?>
        <?= t('Testez vos canaux depuis les {link}.',
        ['link' => '<a href="' . e(u('settings')) . '">' . te('réglages') . '</a>']) ?></td></tr><?php endif; ?>
      </tbody>
    </table></div>
    <?= Ui::pagination($page, max($totalEvents, $totalNotifs), 'events', [], Ui::PAR_PAGE_DOUBLE) ?>
  </div>
</div>
