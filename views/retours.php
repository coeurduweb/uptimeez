<?php
/** Ce que les exploitants disent de la détection, et ce que le corpus en laisse voir. */
use Uptimeez\Db;
use Uptimeez\Retour;
use Uptimeez\Ui;

$divergences = Retour::divergences();
$causes      = Retour::parCause();
$recents     = Db::all('SELECT r.*, m.name FROM retours r LEFT JOIN monitors m ON m.id = r.monitor_id
                        ORDER BY r.ts DESC LIMIT 60');
$total       = (int) Db::val('SELECT COUNT(*) FROM retours');

$motifLisible = [
    'sans_effet'      => t('Réel, mais sans effet visible'),
    'controle_errone' => t('Le contrôle s\'est trompé'),
    'normal_ici'      => t('Normal sur ce site'),
    'vrai_et_corrige' => t('C\'était vrai, c\'est corrigé'),
];
$porteeLisible = [
    'sonde'   => t('Cette page seulement'),
    'serveur' => t('Tout ce serveur'),
    'parc'    => t('Tous mes sites'),
];
?>
<div class="row-between mt"><h1><?= te('Retours sur la détection') ?></h1></div>

<?php if ($total === 0): ?>
  <div class="panel"><div class="panel-body">
    <div class="empty">
      <h3><?= te('Aucun retour pour l\'instant') ?></h3>
      <p class="muted"><?= te('Chaque incident porte un bouton « Donner mon avis ». Ce que vous y déclarez arrive ici, et sert à corriger les contrôles qui se trompent.') ?></p>
    </div>
  </div></div>
<?php else: ?>

<?php
// LES DIVERGENCES EN PREMIER, ET CE N'EST PAS UN CHOIX DE MISE EN PAGE.
//
// Un signal contesté ici et confirmé ailleurs dit que la règle dépend d'un contexte qu'on
// n'a pas nommé. C'est le renseignement le plus utile du corpus, et le plus facile à
// perdre : rangé plus bas, il serait lu après les gros volumes, qui sont presque toujours
// des cas déjà compris.
//
// Trancher en faveur de la majorité ferait disparaître l'information. Ce qu'il faut
// trouver n'est pas qui a raison, mais QUOI diffère entre les deux installations.
?>
<div class="panel">
  <div class="panel-head">
    <h2><?= te('Là où les avis se contredisent') ?></h2>
    <span class="muted small"><?= te('un contrôle jugé faux ici et confirmé ailleurs dépend d\'un contexte qu\'on n\'a pas encore nommé') ?></span>
  </div>
  <div class="panel-body tight">
    <?php if (!$divergences): ?>
      <div class="empty"><h3><?= te('Aucune contradiction dans le corpus') ?></h3>
        <p class="muted"><?= te('Une contradiction demande deux avis explicites et opposés sur le même contrôle. L\'absence de plainte ailleurs n\'en est pas une : la plupart des gens ne signalent jamais rien.') ?></p></div>
    <?php else: ?>
      <div class="table-scroll"><table class="tbl">
        <thead><tr>
          <th><?= te('Contrôle') ?></th>
          <th class="num"><?= te('Jugé faux') ?></th>
          <th class="num"><?= te('Confirmé vrai') ?></th>
          <th class="num"><?= te('Sondes') ?></th>
          <th class="num"><?= te('Serveurs') ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($divergences as $d): ?>
          <tr>
            <td><?= Ui::reasonBadge((string)$d['reason_code']) ?></td>
            <td class="num v-warn"><?= (int)$d['contestes'] ?></td>
            <td class="num v-ok"><?= (int)$d['confirmes'] ?></td>
            <td class="num small"><?= (int)$d['sondes'] ?></td>
            <td class="num small"><?= (int)$d['serveurs'] ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>
</div>

<?php
// LES SERVEURS DISTINCTS SONT LA COLONNE QUI TRANCHE, et le tri s'appuie dessus.
// Trente retours venus d'un seul serveur disent qu'une installation est particulière ;
// trois retours venus de trois serveurs disent que la règle est en cause. Classer par
// volume ferait passer le premier cas pour dix fois plus grave que le second, alors que
// c'est l'inverse.
//
// Les deux colonnes sont montrées ENSEMBLE : un contrôle contesté douze fois n'a pas le
// même sens selon qu'il a été confirmé zéro fois ou quarante.
?>
<div class="panel">
  <div class="panel-head">
    <h2><?= te('Par contrôle') ?></h2>
    <span class="muted small"><?= te('classé par nombre de serveurs distincts, pas par volume : trois retours venus de trois serveurs en disent plus que trente venus d\'un seul') ?></span>
  </div>
  <div class="panel-body tight">
    <div class="table-scroll"><table class="tbl">
      <thead><tr>
        <th><?= te('Contrôle') ?></th>
        <th class="num"><?= te('Jugé faux') ?></th>
        <th class="num"><?= te('Confirmé vrai') ?></th>
        <th class="num"><?= te('Sondes') ?></th>
        <th class="num"><?= te('Serveurs') ?></th>
      </tr></thead>
      <tbody>
      <?php foreach ($causes as $c): ?>
        <tr>
          <td><?= $c['reason_code'] ? Ui::reasonBadge((string)$c['reason_code'])
                : '<span class="muted small">' . te('sans cause enregistrée') . '</span>' ?></td>
          <td class="num<?= (int)$c['contestes'] > 0 ? ' v-warn' : '' ?>"><?= (int)$c['contestes'] ?></td>
          <td class="num<?= (int)$c['confirmes'] > 0 ? ' v-ok' : '' ?>"><?= (int)$c['confirmes'] ?></td>
          <td class="num small"><?= (int)$c['sondes'] ?></td>
          <td class="num small"><?= (int)$c['serveurs'] ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</div>

<div class="panel">
  <div class="panel-head">
    <h2><?= te('Les derniers retours') ?></h2>
    <span class="muted small"><?= te('aucun de ces retours n\'a modifié un verdict : ils décrivent, ils ne décident pas') ?></span>
  </div>
  <div class="panel-body tight">
    <div class="table-scroll"><table class="tbl">
      <thead><tr>
        <th><?= te('Date') ?></th><th><?= te('Sonde') ?></th><th><?= te('Contrôle') ?></th>
        <th><?= te('Ce qui s\'est passé') ?></th><th><?= te('Portée de ce constat') ?></th><th><?= te('Précision') ?></th>
      </tr></thead>
      <tbody>
      <?php foreach ($recents as $r): ?>
        <tr>
          <td class="small nowrap"><?= e(date('d/m/Y H:i', strtotime((string)$r['ts']))) ?></td>
          <td class="small"><a href="<?= e(u('monitor', ['id' => (int)$r['monitor_id']])) ?>"><?= e((string)($r['name'] ?? '—')) ?></a></td>
          <td><?= $r['reason_code'] ? Ui::reasonBadge((string)$r['reason_code']) : '<span class="muted">—</span>' ?></td>
          <td class="small"><?= e($motifLisible[(string)$r['motif']] ?? (string)$r['motif']) ?></td>
          <td class="small"><?= e($porteeLisible[(string)$r['portee']] ?? (string)$r['portee']) ?></td>
          <td class="tiny muted"><?= e(str_cut((string)($r['commentaire'] ?? ''), 90)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</div>

<?php endif; ?>
