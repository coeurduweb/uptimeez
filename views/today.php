<?php
/**
 * Écran d'accueil : « Aujourd'hui ».
 *
 * Une lecture de haut en bas, on s'arrête quand c'est vert :
 *   1. ce qui demande une action maintenant, avec la conduite à tenir et les boutons
 *   2. ce qui va casser bientôt
 *   3. le reste, replié sur une ligne
 *
 * Aucune de ces actions ne quitte la page.
 */
use Uptimer\Auth;
use Uptimer\Stats;
use Uptimer\Triage;
use Uptimer\Ui;

$csrf     = Auth::csrf();
$actions  = Triage::actions();
$upcoming = Triage::upcoming();
$healthy  = Triage::healthy();
$c        = Triage::counts();

$nAct  = count($actions);
$nDown = 0;
foreach ($actions as $a) if ($a['severity'] === 'down') $nDown++;
$nWarn = $nAct - $nDown;

$sparkIds = array_map(fn($h) => $h['id'], $healthy);
$sparks   = $sparkIds ? Stats::sparkBatch(array_slice($sparkIds, 0, 40), 86400, 32) : [];
?>

<!-- ===================== BANDEAU D'ÉTAT ===================== -->
<section class="band <?= $nDown ? 'band-bad' : ($nWarn ? 'band-warn' : 'band-ok') ?>" id="band">
  <div class="band-icon"><?= Ui::statusIcon($nDown ? 'down' : ($nWarn ? 'degraded' : 'up')) ?></div>
  <div class="grow">
    <h1 class="band-title">
      <?php if ($nDown): ?>
        <?= tne($nDown, 'un site à remettre en ligne', '{n} sites à remettre en ligne') ?><?php
          if ($nWarn) echo ', ' . tne($nWarn, 'un point à surveiller', '{n} points à surveiller'); ?>
      <?php elseif ($nWarn): ?>
        <?= tne($nWarn, 'un point à surveiller', '{n} points à surveiller') ?>
      <?php elseif ($c['up'] > 0): ?>
        <?= te('Rien à faire, tout tourne') ?>
      <?php else: ?>
        <?= te('Aucune mesure pour l\'instant') ?>
      <?php endif; ?>
    </h1>
    <div class="band-sub">
      <?= tne((int)$c['up'], 'une sonde en ligne', '{n} sondes en ligne') ?>
      · <?= te('uptime moyen {pct}', ['pct' => Ui::pct($c['uptime'])]) ?>
      · <?= te('réponse {ms}', ['ms' => Ui::ms($c['avg_ms'] !== null ? (int)$c['avg_ms'] : null)]) ?>
      <?php if ($c['paused']): ?> · <?= te('{n} en pause', ['n' => (int)$c['paused']]) ?><?php endif; ?>
      <?php if (!empty($c['last_run'])): ?>
        · <?= te('dernière passe {when}', ['when' => human_since((string)$c['last_run'])]) ?>
      <?php else: ?>
        · <strong><?= te('la tâche planifiée n\'a jamais tourné') ?></strong> :
        <a href="<?= e(u('settings')) ?>#cron"><?= te('la configurer') ?></a>
      <?php endif; ?>
    </div>
  </div>
  <div class="band-cta row">
    <?php if ($nAct): ?>
      <button class="btn btn-primary" id="check-all"><?= Ui::icon('refresh') ?> <?= te('Tout revérifier') ?></button>
    <?php else: ?>
      <a class="btn" href="<?= e(u('import')) ?>"><?= Ui::icon('plus') ?> <?= te('Ajouter un site') ?></a>
    <?php endif; ?>
  </div>
</section>

<!-- ===================== À TRAITER MAINTENANT ===================== -->
<?php if ($nAct): ?>
  <div class="section-title">
    <?= te('À traiter maintenant · {n}', ['n' => $nAct]) ?>
    <?= hint('Un site par carte, dans l\'ordre d\'urgence. Chaque carte dit ce qui casse, pourquoi c\'est un problème et quoi faire. Les boutons agissent sans quitter la page.') ?>
  </div>

  <?php foreach ($actions as $a):
    $m = $a['monitor'];
    $mid = $a['id'];
    $tone = $a['severity'] === 'down' ? 'bad' : 'warn';
  ?>
  <article class="task task-<?= $tone ?>" data-id="<?= $mid ?>" data-task>
    <div class="task-head">
      <span class="task-icon"><?= Ui::icon($a['icon'], 22) ?></span>
      <div class="grow">
        <div class="task-cause"><?= e($a['cause']) ?></div>
        <div class="task-who">
          <a href="<?= e(u('monitor', ['id' => $mid])) ?>"><strong><?= e($a['title']) ?></strong></a>
          <span class="muted"><?= e($a['subtitle']) ?></span>
          <?php if ($a['since']): ?>
            · <?= te('depuis {duration}', ['duration' => human_duration(max(0, time() - strtotime((string)$a['since'])))]) ?>
          <?php endif; ?>
          <?php if ($a['fails'] > 1): ?>
            · <?= tne($a['fails'], 'un échec', '{n} échecs consécutifs') ?>
          <?php endif; ?>
          <?php if ($a['also'] > 0): ?>
            · <?= Ui::badge(tn($a['also'], '+1 autre page du site', '+{n} autres pages du site'), $tone) ?>
          <?php endif; ?>
          <?php if ($a['acked']): ?> <?= Ui::badge(t('pris en compte'), 'neutral') ?><?php endif; ?>
        </div>
      </div>
    </div>

    <p class="task-why"><?= e($a['why']) ?></p>
    <?php if ($a['evidence'] !== '' && expert()): ?>
      <div class="task-evidence"><?= e($a['evidence']) ?></div>
    <?php endif; ?>
    <p class="task-fix"><?= Ui::icon('wrench', 14) ?> <?= e($a['fix']) ?></p>

    <div class="task-actions">
      <?php foreach ($a['actions'] as [$act, $label]):
        if ($act === 'open'): ?>
          <a class="btn btn-sm" href="<?= e((string)$m['url']) ?>" target="_blank" rel="noopener">
            <?= Ui::icon('external', 14) ?> <?= e($label) ?></a>
        <?php elseif ($act === 'check'): ?>
          <button class="btn btn-sm btn-primary js-check" data-id="<?= $mid ?>">
            <?= Ui::icon('refresh', 14) ?> <?= e($label) ?></button>
        <?php elseif ($act === 'copy'): ?>
          <button class="btn btn-sm js-copy-report" data-id="<?= $mid ?>">
            <?= Ui::icon('file', 14) ?> <?= e($label) ?></button>
        <?php else: ?>
          <button class="btn btn-sm js-fix" data-id="<?= $mid ?>" data-fix="<?= e($act) ?>">
            <?= e($label) ?></button>
        <?php endif;
      endforeach; ?>
      <span class="grow"></span>
      <?php if ($a['incident'] && !$a['acked']): ?>
        <button class="btn btn-sm btn-ghost js-fix" data-id="<?= $mid ?>" data-fix="ack"
                title="<?= te('Stoppe les rappels d\'alerte sans clore l\'incident') ?>"><?= te('Pris en compte') ?></button>
      <?php endif; ?>
      <a class="btn btn-sm btn-ghost" href="<?= e(u('monitor', ['id' => $mid])) ?>"><?= te('Fiche complète →') ?></a>
    </div>
  </article>
  <?php endforeach; ?>
<?php endif; ?>

<!-- ===================== À PRÉVOIR ===================== -->
<?php if ($upcoming): ?>
  <div class="section-title">
    <?= te('À prévoir · {n}', ['n' => count($upcoming)]) ?>
    <?= hint('Rien n\'est encore cassé. Ces points le seront si personne n\'intervient : certificat qui expire, nom de domaine à renouveler, site qui ralentit.') ?>
  </div>
  <div class="panel">
    <div class="panel-body tight">
      <?php foreach ($upcoming as $up): ?>
        <div class="fore fore-<?= e($up['urgency']) ?>">
          <span class="fore-icon"><?= Ui::icon($up['icon'], 17) ?></span>
          <div class="grow">
            <div class="fore-title"><?= e($up['title']) ?></div>
            <div class="fore-why"><?= e($up['why']) ?></div>
          </div>
          <?php if ($up['id']): ?>
            <a class="btn btn-sm btn-ghost nowrap" href="<?= e(u('monitor', ['id' => (int)$up['id']])) ?>"><?= te('Voir') ?></a>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<!-- ===================== TOUT VA BIEN ===================== -->
<?php if ($healthy): ?>
  <?= Ui::accOpen('healthy', 'check',
        tn(count($healthy), 'un site sans rien à signaler', '{n} sites sans rien à signaler'),
        t('uptime et temps de réponse des dernières 24 h'), false, 'none', Ui::badge(t('tout va bien'), 'ok')) ?>
  <?= Ui::accBody(true) ?>
    <div class="table-scroll"><table class="tbl">
      <thead><tr><th></th><th><?= te('Site') ?></th><th style="width:180px"><?= te('24 dernières heures') ?></th>
        <th class="num"><?= te('Uptime') ?></th><th class="num"><?= te('Réponse') ?></th><th></th></tr></thead>
      <tbody>
      <?php foreach ($healthy as $h): ?>
        <tr>
          <td style="width:26px"><?= Ui::dot('up') ?></td>
          <td><a href="<?= e(u('monitor', ['id' => $h['id']])) ?>"><?= e($h['name']) ?></a></td>
          <td><?= Ui::sparkline($sparks[$h['id']] ?? [], 180, 24) ?></td>
          <td class="num"><span class="badge badge-<?= Ui::uptimeTone($h['uptime']) ?>"><?= Ui::pct($h['uptime'], 1) ?></span></td>
          <td class="num small"><?= Ui::ms($h['ms']) ?></td>
          <td class="num"><button class="btn btn-sm btn-ghost btn-icon js-check" data-id="<?= $h['id'] ?>"
                title="<?= te('Revérifier {name}', ['name' => $h['name']]) ?>"
                aria-label="<?= te('Revérifier') ?>"><?= Ui::icon('refresh', 14) ?></button></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?= Ui::accClose() ?>
<?php endif; ?>

<?php if (!$nAct && !$upcoming && !$healthy): ?>
  <div class="panel"><div class="empty">
    <h3><?= te('Rien à surveiller pour l\'instant') ?></h3>
    <p class="muted prose" style="margin:0 auto"><?= te('Collez une liste de domaines : {app} détecte la technologie, choisit les pages à suivre, déduit la chaîne de preuve et règle les seuils toute seule.') ?></p>
    <a class="btn btn-primary mt" href="<?= e(u('import')) ?>"><?= Ui::icon('plus') ?> <?= te('Ajouter des sites') ?></a>
  </div></div>
<?php endif; ?>

<p class="tiny muted center mt-lg">
  <?= Ui::icon('info', 13) ?>
  <?= te('Vue mur d\'écran :') ?> <a href="<?= e(u('dashboard')) ?>"><?= te('tableau de bord') ?></a>
  · <?= te('Palette de commandes :') ?> <kbd>Ctrl</kbd> + <kbd>K</kbd>
</p>
