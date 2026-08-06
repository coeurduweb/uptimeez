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
use Uptimeez\Auth;
use Uptimeez\Stats;
use Uptimeez\Triage;
use Uptimeez\Ui;

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
// Les cartes de tâches portent aussi une courbe : une seule requête groupée
// pour toutes, comme pour la liste des sondes saines.
$taskIds    = array_map(fn($a) => (int)$a['id'], $actions);
$taskSparks = $taskIds ? Stats::sparkBatch($taskIds, 86400, 40) : [];
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
  <div class="band-pulse">
    <?= Ui::pulse(Stats::pulse(86400, 48)) ?>
    <div class="band-pulse-scale">
      <span><?= te('il y a 24 h') ?></span>
      <span class="grow"></span>
      <span><?= te('maintenant') ?></span>
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
  <?php
  /**
   * Un seul foyer d'attention, puis une file.
   *
   * Le défaut de la version précédente : cinq cartes de même poids, deux
   * colonnes de texte et une image chacune. Rien ne disait où regarder. Ici la
   * panne la plus urgente occupe une carte détaillée, et les suivantes tiennent
   * sur une ligne chacune, lisibles d'un coup d'œil et actionnables sans ouvrir
   * quoi que ce soit. C'est l'ordre de traitement qui devient la mise en page.
   */
  $hero  = $actions[0];
  $queue = array_slice($actions, 1);

  /** Rend la ligne d'actions d'une tâche : un bouton principal, le reste replié. */
  $renderActions = function (array $a, bool $compact) use ($csrf): void {
      $mid = $a['id'];
      $m   = $a['monitor'];
      $primary = null; $rest = [];
      foreach ($a['actions'] as [$act, $label]) {
          if ($primary === null && $act === 'check') { $primary = [$act, $label]; continue; }
          $rest[] = [$act, $label];
      }
      if ($primary === null && $rest) { $primary = array_shift($rest); }
      ?>
      <div class="act">
        <?php if ($primary !== null):
          [$act, $label] = $primary; ?>
          <?php if ($act === 'check'): ?>
            <button class="btn btn-primary<?= $compact ? ' btn-sm' : '' ?> js-check" data-id="<?= $mid ?>">
              <?= Ui::icon('refresh', $compact ? 14 : 15) ?> <?= e($label) ?></button>
          <?php elseif ($act === 'open'): ?>
            <a class="btn btn-primary<?= $compact ? ' btn-sm' : '' ?>" href="<?= e((string)$m['url']) ?>"
               target="_blank" rel="noopener"><?= Ui::icon('external', 14) ?> <?= e($label) ?></a>
          <?php else: ?>
            <button class="btn btn-primary<?= $compact ? ' btn-sm' : '' ?> js-fix" data-id="<?= $mid ?>"
                    data-fix="<?= e($act) ?>"><?= e($label) ?></button>
          <?php endif; ?>
        <?php endif; ?>

        <?php if ($rest || ($a['incident'] && !$a['acked'])): ?>
          <?php /* Le reste est replié : disponible en un clic, absent du regard
                   le reste du temps. Aucun JavaScript, un details suffit. */ ?>
          <details class="act-more">
            <summary class="btn btn-sm btn-ghost" title="<?= te('Autres actions') ?>"
                     aria-label="<?= te('Autres actions') ?>">···</summary>
            <div class="act-menu">
              <?php foreach ($rest as [$act, $label]): ?>
                <?php if ($act === 'open'): ?>
                  <a href="<?= e((string)$m['url']) ?>" target="_blank" rel="noopener">
                    <?= Ui::icon('external', 14) ?> <?= e($label) ?></a>
                <?php elseif ($act === 'copy'): ?>
                  <button type="button" class="js-copy-report" data-id="<?= $mid ?>">
                    <?= Ui::icon('file', 14) ?> <?= e($label) ?></button>
                <?php else: ?>
                  <button type="button" class="js-fix" data-id="<?= $mid ?>" data-fix="<?= e($act) ?>">
                    <?= e($label) ?></button>
                <?php endif; ?>
              <?php endforeach; ?>
              <?php if ($a['incident'] && !$a['acked']): ?>
                <button type="button" class="js-fix" data-id="<?= $mid ?>" data-fix="ack">
                  <?= Ui::icon('check', 14) ?> <?= te('Pris en compte') ?></button>
              <?php endif; ?>
              <?php
              /*
               * TAIRE CE SIGNAL, DEPUIS L'ENDROIT OÙ ON LE LIT.
               *
               * Le geste existait, mais seulement sur l'écran des incidents : il fallait donc
               * quitter la liste de tâches, retrouver la ligne, et déplier. Sur un parc avec
               * quelques cas particuliers légitimes — un « noindex » assumé sur un site
               * technique, une police d'icônes qu'on ne remettra pas — c'est assez de
               * frottement pour qu'on préfère ignorer la carte. Et une carte qu'on apprend à
               * ignorer est le début de l'écran qu'on n'ouvre plus.
               *
               * TROIS GARDES, INCHANGÉES, parce que ce geste éteint une alerte. Il n'apparaît
               * que sur une cause d'apparence, exactement comme sur l'autre écran : proposer
               * puis refuser userait la confiance autant que ne rien proposer. La raison est
               * obligatoire. Et le libellé dit ce qui va se passer plutôt que « ignorer »,
               * qui laisserait croire à un simple masquage.
               *
               * Aucun JavaScript de plus : la classe « exception-form » est déjà écoutée.
               */
              ?>
              <?php if ($a['incident'] && Uptimeez\Exceptions::estExcusable($a['reason'] ?? null)): ?>
                <details class="act-excuse">
                  <summary><?= Ui::icon('shield', 14) ?> <?= te('Ce n\'est pas un problème ici') ?></summary>
                  <form class="exception-form" data-incident="<?= (int)$a['incident'] ?>">
                    <p class="tiny muted"><?= te('L\'alerte n\'apparaîtra plus sur cette page. Elle reste comptée, et l\'exception sera à revoir dans six mois.') ?></p>
                    <label class="tiny"><?= te('Pourquoi (obligatoire)') ?>
                      <input type="text" name="raison" maxlength="500" required>
                    </label>
                    <button class="btn btn-sm btn-ghost"><?= te('Taire ce signal ici') ?></button>
                  </form>
                </details>
              <?php endif; ?>
              <a href="<?= e(u('monitor', ['id' => $mid])) ?>">
                <?= Ui::icon('eye', 14) ?> <?= te('Fiche complète') ?></a>
            </div>
          </details>
        <?php endif; ?>
      </div>
      <?php
  };
  ?>

  <!-- ---------- Le foyer : une seule panne, celle qui compte ---------- -->
  <?php
  $m     = $hero['monitor'];
  $mid   = $hero['id'];
  $tone  = $hero['severity'] === 'down' ? 'bad' : 'warn';
  $sil   = (string)($m['silhouette_now'] ?? '');
  $showS = $sil !== '' && in_array($hero['reason'], ['CSS_BROKEN', 'CSS_DEGRADED', 'DB_DOWN',
                                                     'APP_ERROR', 'STRING_MISSING',
                                                     'STRING_FORBIDDEN', 'NOINDEX'], true);
  ?>
  <div class="section-title">
    <?= te('À traiter d\'abord') ?>
    <?= hint('La panne la plus urgente occupe cette carte : la cause, ce que ça fait au visiteur, quoi faire, et le bouton qui le fait. Les autres suivent en dessous, une ligne chacune.') ?>
  </div>

  <article class="hero-task hero-<?= $tone ?>" data-id="<?= $mid ?>" data-task>
    <div class="hero-body">
      <div class="hero-line">
        <span class="hero-ico"><?= Ui::icon($hero['icon'], 20) ?></span>
        <span class="hero-site"><a href="<?= e(u('monitor', ['id' => $mid])) ?>"><?= e($hero['title']) ?></a></span>
        <span class="hero-dom"><?= e($hero['subtitle']) ?></span>
        <?php if ($hero['since']): ?>
          <span class="hero-since"><?= e(human_duration(max(0, time() - strtotime((string)$hero['since'])))) ?></span>
        <?php endif; ?>
        <?php if ($hero['acked']): ?><?= Ui::badge(t('pris en compte'), 'neutral') ?><?php endif; ?>
      </div>

      <h2 class="hero-cause"><?= e($hero['cause']) ?></h2>
      <p class="hero-why"><?= e($hero['why']) ?></p>

      <p class="hero-fix"><span class="hero-fix-tag"><?= te('À faire') ?></span> <?= e($hero['fix']) ?></p>

      <?php $renderActions($hero, false); ?>

      <?php if (expert() && ($hero['evidence'] !== '' || $hero['fails'] > 1)): ?>
        <details class="hero-tech">
          <summary><?= te('Relevé technique') ?></summary>
          <div>
            <?php if ($hero['fails'] > 1): ?>
              <p class="mb0"><?= e(tn($hero['fails'], 'un échec consécutif', '{n} échecs consécutifs')) ?>
                <?php if ($hero['also'] > 0): ?>
                  · <?= e(tn($hero['also'], 'une autre page du même site touchée',
                                            '{n} autres pages du même site touchées')) ?>
                <?php endif; ?></p>
            <?php endif; ?>
            <?php if ($hero['evidence'] !== ''): ?>
              <div class="task-evidence"><?= e($hero['evidence']) ?></div>
            <?php endif; ?>
          </div>
        </details>
      <?php endif; ?>
    </div>

    <?php if ($showS): ?>
      <figure class="hero-proof">
        <div class="hero-proof-view"><?= $sil ?></div>
        <figcaption><?= te('La page maintenant') ?> ·
          <span class="muted"><?= te('reconstitution, pas une capture') ?></span></figcaption>
      </figure>
    <?php elseif (!empty($taskSparks[$mid])): ?>
      <figure class="hero-proof">
        <div class="hero-proof-spark"><?= Ui::sparkline($taskSparks[$mid], 330, 64) ?></div>
        <figcaption><?= te('24 dernières heures') ?>
          <?php if (($m['last_ms'] ?? null) !== null): ?>
            · <span class="muted"><?= e(Ui::ms((int)$m['last_ms'])) ?></span>
          <?php endif; ?></figcaption>
      </figure>
    <?php endif; ?>
  </article>

  <!-- ---------- La file : une ligne par panne, lisible d'un coup ---------- -->
  <?php if ($queue): ?>
    <div class="section-title">
      <?= tne(count($queue), 'Ensuite · une autre', 'Ensuite · {n} autres') ?>
      <?= hint('Une ligne par site. Le bouton agit sans quitter la page, le nom ouvre la fiche.') ?>
    </div>
    <ul class="queue">
      <?php foreach ($queue as $q):
        $qm = $q['monitor'];
        $qid = $q['id'];
        $qt = $q['severity'] === 'down' ? 'bad' : 'warn'; ?>
        <li class="q-row q-<?= $qt ?>" data-id="<?= $qid ?>" data-task>
          <span class="q-ico"><?= Ui::icon($q['icon'], 17) ?></span>
          <span class="q-site"><a href="<?= e(u('monitor', ['id' => $qid])) ?>"><?= e($q['title']) ?></a></span>
          <span class="q-cause"><?= e($q['cause']) ?></span>
          <span class="q-since"><?php if ($q['since']): ?>
            <?= e(human_duration(max(0, time() - strtotime((string)$q['since'])))) ?>
          <?php endif; ?></span>
          <?php $renderActions($q, true); ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
<?php endif; ?>

<!-- ===================== À PRÉVOIR ===================== -->
<?php if ($upcoming): ?>
  <div class="section-title">
    <?= te('À prévoir · {n}', ['n' => count($upcoming)]) ?>
    <?= hint('Rien n\'est encore cassé. Ces points le seront si personne n\'intervient : certificat qui expire, nom de domaine à renouveler, site qui ralentit.') ?>
  </div>
  <div class="panel">
    <div class="panel-body tight">
      <?php
      // Au-delà de cinq, le reste est replié : la page reste lisible et rien
      // n'est perdu. C'est le même geste que partout ailleurs dans l'outil.
      $foreShown = array_slice($upcoming, 0, 5);
      $foreRest  = array_slice($upcoming, 5);
      foreach ($foreShown as $up): ?>
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
      <?php if ($foreRest): ?>
        <details class="fore-more">
          <summary><?= e(tn(count($foreRest), 'Un autre point à prévoir',
                                              '{n} autres points à prévoir')) ?></summary>
          <?php foreach ($foreRest as $up): ?>
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
        </details>
      <?php endif; ?>
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
