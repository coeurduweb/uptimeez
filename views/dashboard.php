<?php
/**
 * Tableau de bord.
 *
 * Règle de lecture : ce qui demande une action est en haut et en rouge. Les
 * cartes ne portent que l'essentiel : le détail est sur la fiche de la sonde.
 */
use Uptimeez\Db;
use Uptimeez\Stats;
use Uptimeez\Ui;

$mode   = ($_GET['mode'] ?? 'site') === 'probe' ? 'probe' : 'site';
$filter = (string)($_GET['f'] ?? 'all');
$group  = (string)($_GET['g'] ?? '');

$monitors = Db::all(
    'SELECT m.*, s.domain AS site_domain, s.name AS site_name, s.cms AS site_cms, s.group_name
     FROM monitors m LEFT JOIN sites s ON s.id = m.site_id
     ORDER BY m.role DESC, m.name ASC'
);
$groups = array_values(array_filter(array_unique(array_map(fn($m) => (string)$m['group_name'], $monitors))));
sort($groups);

// Priorité d'affichage : l'état d'un site est le plus préoccupant de ses sondes.
// Une sonde en pause ne dégrade pas l'affichage d'un site qui va bien.
$rank = ['down' => 0, 'degraded' => 1, 'unknown' => 2, 'up' => 3, 'paused' => 4];

$units = [];
if ($mode === 'site') {
    foreach ($monitors as $m) {
        $key = $m['site_id'] ? 'S' . $m['site_id'] : 'M' . $m['id'];
        if (!isset($units[$key])) {
            $units[$key] = [
                'title' => $m['site_name'] ?: ($m['site_domain'] ?: $m['name']),
                'sub'   => $m['site_domain'] ?: host_of((string)$m['url']),
                'group' => (string)$m['group_name'], 'cms' => $m['site_cms'],
                'primary' => null, 'monitors' => [], 'status' => null, 'bad' => 0, 'total' => 0,
            ];
        }
        $u = &$units[$key];
        $u['monitors'][] = $m;
        $u['total']++;
        if (in_array($m['status'], ['down', 'degraded'], true)) $u['bad']++;
        if ($m['role'] === 'primary' && !$u['primary']) $u['primary'] = $m;
        $st = isset($rank[$m['status']]) ? (string)$m['status'] : 'unknown';
        if ($u['status'] === null || $rank[$st] < $rank[$u['status']]) $u['status'] = $st;
        unset($u);
    }
    foreach ($units as $k => $u) {
        if (!$u['primary']) $units[$k]['primary'] = $u['monitors'][0];
        if ($u['status'] === null) $units[$k]['status'] = 'unknown';
    }
} else {
    foreach ($monitors as $m) {
        $path = (string)parse_url((string)$m['url'], PHP_URL_PATH);
        $units['M' . $m['id']] = [
            'title' => $m['name'], 'sub' => host_of((string)$m['url']) . ($path !== '/' ? $path : ''),
            'group' => (string)$m['group_name'], 'cms' => $m['site_cms'],
            'primary' => $m, 'monitors' => [$m], 'status' => (string)$m['status'],
            'bad' => in_array($m['status'], ['down', 'degraded'], true) ? 1 : 0, 'total' => 1,
        ];
    }
}

// Comptes avant filtrage : les onglets doivent afficher le total réel.
$all = ['down' => 0, 'degraded' => 0, 'up' => 0, 'unknown' => 0, 'paused' => 0];
foreach ($units as $u) $all[$u['status']]++;

$units = array_filter($units, function ($u) use ($filter, $group) {
    if ($group !== '' && $u['group'] !== $group) return false;
    return match ($filter) {
        'bad'    => in_array($u['status'], ['down', 'degraded'], true),
        'down'   => $u['status'] === 'down',
        'paused' => $u['status'] === 'paused',
        default  => true,
    };
});
uasort($units, fn($a, $b) => [$rank[$a['status']], mb_strtolower($a['title'])] <=> [$rank[$b['status']], mb_strtolower($b['title'])]);

$sparkIds = [];
foreach ($units as $u) if ($u['primary']) $sparkIds[] = (int)$u['primary']['id'];
$sparks = Stats::sparkBatch($sparkIds, 86400, 48);

$bad   = $all['down'];
$warn  = $all['degraded'];

$total = count($units);
$band  = $bad > 0 ? 'band-bad' : ($warn > 0 ? 'band-warn' : ($all['up'] > 0 ? 'band-ok' : ''));
?>

<!-- ================= SYNTHÈSE, toujours en tête de page ================= -->
<section class="band <?= $band ?>" id="band">
  <div class="band-icon"><?= Ui::statusIcon($bad > 0 ? 'down' : ($warn > 0 ? 'degraded' : 'up')) ?></div>
  <div class="grow">
    <h1 class="band-title">
      <?php if ($bad > 0): ?>
        <?= $mode === 'site'
              ? tne($bad, 'un site hors service', '{n} sites hors service')
              : tne($bad, 'une sonde hors service', '{n} sondes hors service') ?><?php
          if ($warn) echo ', ' . tne($warn, 'un à surveiller', '{n} à surveiller'); ?>
      <?php elseif ($warn > 0): ?>
        <?= $mode === 'site'
              ? tne($warn, 'un site à surveiller', '{n} sites à surveiller')
              : tne($warn, 'une sonde à surveiller', '{n} sondes à surveiller') ?>
      <?php elseif ($all['up'] > 0): ?>
        <?= te('Tout est opérationnel') ?>
      <?php else: ?>
        <?= te('Aucune mesure pour l\'instant') ?>
      <?php endif; ?>
    </h1>
    <div class="band-sub">
      <?= $mode === 'site'
        ? tne(array_sum($all), 'un site suivi', '{n} sites suivis')
        : tne(array_sum($all), 'une sonde suivie', '{n} sondes suivies') ?>
      · <?= te('{n} en ligne', ['n' => $all['up']]) ?><?php
      if ($warn) echo ' · ' . tne($warn, 'un à surveiller', '{n} à surveiller');
      if ($all['unknown']) echo ' · ' . tne($all['unknown'], 'un en attente de mesure', '{n} en attente de mesure');
      if ($all['paused']) echo ' · ' . tne($all['paused'], 'un en pause', '{n} en pause');
      ?>
      <?php if (!empty($summary['last_run_at'])): ?>
        · <?= te('dernière passe {when}', ['when' => human_since((string)$summary['last_run_at'])]) ?>
      <?php else: ?>
        · <strong><?= te('le cron n\'a jamais tourné') ?></strong> :
        <a href="<?= e(u('settings')) ?>"><?= te('le configurer') ?></a>
      <?php endif; ?>
    </div>
    <?php if ($bad > 0 || $warn > 0):
      $chips = [];
      foreach ($units as $u) {
          if (!in_array($u['status'], ['down', 'degraded'], true)) continue;
          $worst = $u['primary'];
          foreach ($u['monitors'] as $mm) { if ($mm['status'] === $u['status']) { $worst = $mm; break; } }
          $chips[] = ['u' => $u, 'id' => (int)($worst['id'] ?? 0)];
      }
      $shown = array_slice($chips, 0, 8);
    ?>
      <div class="band-chips">
        <?php foreach ($shown as $c): ?>
          <a class="chip <?= $c['u']['status'] === 'down' ? 'chip-bad' : 'chip-warn' ?>"
             href="<?= e(u('monitor', ['id' => $c['id']])) ?>">
            <?= Ui::dot($c['u']['status']) ?><span><?= e($c['u']['title']) ?></span>
          </a>
        <?php endforeach; ?>
        <?php if (count($chips) > count($shown)): ?>
          <a class="chip" href="<?= e(u('dashboard', ['f' => 'bad', 'mode' => $mode])) ?>">
            et <?= count($chips) - count($shown) ?> autre<?= count($chips) - count($shown) > 1 ? 's' : '' ?>
          </a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
  <div class="band-pulse">
    <?= Ui::pulse(Stats::pulse(86400, 48)) ?>
    <div class="band-pulse-scale">
      <span><?= te('il y a 24 h') ?></span>
      <span class="grow"></span>
      <span><?= te('maintenant') ?></span>
    </div>
  </div>
  <div class="band-cta">
    <button class="btn" id="check-all"><?= Ui::icon('refresh') ?> <?= te('Tout revérifier') ?></button>
  </div>
</section>

<!-- ================= TROIS CHIFFRES, PAS PLUS ================= -->
<section class="stats">
  <div class="stat">
    <div class="stat-label"><?= te('Uptime moyen 24 h') ?><?= hint('Part du temps où les sondes ont répondu correctement sur les 24 dernières heures. Une sonde en pause ne compte pas.') ?></div>
    <div class="stat-value v-<?= Ui::uptimeTone($summary['uptime_24h'] ?? null) ?>"><?= Ui::pct($summary['uptime_24h'] ?? null) ?></div>
    <div class="stat-hint"><?= te('sur l\'ensemble des sondes actives') ?></div>
  </div>
  <div class="stat">
    <div class="stat-label"><?= te('Temps de réponse') ?></div>
    <div class="stat-value"><?= Ui::ms($summary['avg_ms'] ?? null) ?></div>
    <div class="stat-hint"><?= te('moyenne des 24 dernières heures') ?></div>
  </div>
  <div class="stat">
    <div class="stat-label"><?= te('Incidents ouverts') ?></div>
    <div class="stat-value <?= !empty($summary['open_incidents']) ? 'v-bad' : 'v-ok' ?>"><?= (int)($summary['open_incidents'] ?? 0) ?></div>
    <div class="stat-hint"><?= tne((int)($summary['incidents_24h'] ?? 0), '1 ouvert sur 24 h', '{n} ouverts sur 24 h') ?> ·
      <a href="<?= e(u('incidents')) ?>">historique</a></div>
  </div>
</section>

<!-- ================= FILTRES ================= -->
<div class="toolbar">
  <div class="search">
    <span class="ico-l"><?= Ui::icon('search', 16) ?></span>
    <label class="sr-only" for="q"><?= te('Filtrer la liste') ?></label>
    <input type="search" id="q" placeholder="<?= te('Filtrer par nom, domaine, technologie…') ?>" autocomplete="off">
    <kbd>/</kbd>
  </div>

  <div class="segmented" role="tablist" aria-label="<?= te('Filtre d\'état') ?>">
    <?php foreach ([
        'all'    => ['Tout', array_sum($all)],
        'bad'    => [t('À traiter'), $bad + $warn],
        'down'   => ['Hors service', $bad],
        'paused' => ['En pause', $all['paused']],
      ] as $k => [$label, $n]): ?>
      <a role="tab" aria-selected="<?= $filter === $k ? 'true' : 'false' ?>"
         href="<?= e(u('dashboard', ['f' => $k, 'mode' => $mode, 'g' => $group ?: null])) ?>">
        <?= e($label) ?><?php if ($n): ?><span class="n"><?= (int)$n ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="segmented" role="group" aria-label="<?= te('Regroupement') ?>">
    <a href="<?= e(u('dashboard', ['mode' => 'site', 'f' => $filter, 'g' => $group ?: null])) ?>"
       class="<?= $mode === 'site' ? 'on' : '' ?>" title="<?= te('Un bloc par site') ?>"
       aria-label="<?= te('Grouper par site') ?>"><?= Ui::icon('grid', 15) ?> <?= te('Par site') ?></a>
    <a href="<?= e(u('dashboard', ['mode' => 'probe', 'f' => $filter, 'g' => $group ?: null])) ?>"
       class="<?= $mode === 'probe' ? 'on' : '' ?>" title="<?= te('Une ligne par sonde') ?>"
       aria-label="<?= te('Lister chaque sonde') ?>"><?= Ui::icon('list', 15) ?> <?= te('Par sonde') ?></a>
  </div>

  <?php if ($groups): ?>
    <label class="sr-only" for="grp"><?= te('Groupe') ?></label>
    <select id="grp" onchange="location.href=this.value" style="max-width:180px">
      <option value="<?= e(u('dashboard', ['mode' => $mode, 'f' => $filter])) ?>"><?= te('Tous les groupes') ?></option>
      <?php foreach ($groups as $g): ?>
        <option value="<?= e(u('dashboard', ['mode' => $mode, 'f' => $filter, 'g' => $g])) ?>" <?= $group === $g ? 'selected' : '' ?>>
          <?= e($g) ?></option>
      <?php endforeach; ?>
    </select>
  <?php endif; ?>

  <div class="row" style="margin-left:auto">
    <span class="tiny muted" id="filter-count"></span>
    <button class="btn btn-ghost btn-sm btn-icon" id="autorefresh" aria-pressed="true"
            title="<?= te('Rafraîchissement automatique toutes les 30 s') ?>"><?= Ui::icon('refresh', 15) ?></button>
  </div>
</div>

<!-- ================= CARTES ================= -->
<?php if (!$units): ?>
  <div class="panel"><div class="empty">
    <h3><?= $filter === 'all' ? te('Aucun site surveillé pour l\'instant') : te('Rien dans ce filtre') ?></h3>
    <p class="muted prose" style="margin:0 auto">
      <?= $filter === 'all'
        ? t('Collez une liste de domaines : {app} détecte le CMS, choisit les pages à suivre et déduit la chaîne de contrôle.')
        : t('Bonne nouvelle, il n\'y a rien à traiter dans cette catégorie.') ?></p>
    <?php if ($filter === 'all'): ?>
      <a class="btn btn-primary mt" href="<?= e(u('import')) ?>"><?= Ui::icon('plus') ?> <?= te('Ajouter des sites') ?></a>
    <?php else: ?>
      <a class="btn mt" href="<?= e(u('dashboard')) ?>"><?= te('Voir tout') ?></a>
    <?php endif; ?>
  </div></div>
<?php else: ?>
<section class="cards" id="cards">
  <?php foreach ($units as $u):
      $m   = $u['primary'];
      $mid = (int)$m['id'];
      $st  = $u['status'];
      $hay = fold($u['title'] . ' ' . $u['sub'] . ' ' . (string)$u['cms'] . ' '
                . (string)$m['url'] . ' ' . (string)$u['group']);
      $upt = $m['uptime_24h'] !== null ? (float)$m['uptime_24h'] : null;

      // Message : la cause de l'état, prise sur la sonde réellement en défaut.
      $msg = '';
      if ($st !== 'up' && $st !== 'unknown') {
          $msg = verdict_text($m);
          if ($mode === 'site' && $u['bad'] > 0) {
              foreach ($u['monitors'] as $mm) {
                  if ($mm['status'] === $st) { $msg = verdict_text($mm); break; }
              }
          }
      }

      // Deux badges maximum, et seulement s'ils réclament de l'attention.
      $flags = [];
      if ($m['css_state'] === 'broken')    $flags[] = [t('CSS cassé'), 'bad'];
      elseif ($m['css_state'] === 'warn')  $flags[] = [t('CSS à vérifier'), 'warn'];
      $sd = $m['ssl_days_left'] !== null ? (int)$m['ssl_days_left'] : null;
      if ($sd !== null && $sd < 0)                                 $flags[] = [t('SSL expiré'), 'bad'];
      elseif ($sd !== null && $sd <= (int)$m['ssl_warn_days'])     $flags[] = ['SSL ' . $sd . ' j', 'warn'];
      if ($m['setup_state'] === 'pending') $flags[] = [t('préparation…'), 'info'];
      $flags = array_slice($flags, 0, 2);
  ?>
  <article class="card s-<?= e($st) ?>" data-hay="<?= e($hay) ?>" data-id="<?= $mid ?>" data-status="<?= e($st) ?>">
    <div class="card-head">
      <?= Ui::dot($st) ?>
      <a class="card-name grow truncate" href="<?= e(u('monitor', ['id' => $mid])) ?>"><?= e($u['title']) ?></a>
      <span class="badge badge-<?= Ui::uptimeTone($upt) ?>" title="<?= te('Disponibilité sur 24 heures') ?>"><?= Ui::pct($upt, 2) ?></span>
    </div>
    <div class="card-sub truncate">
      <?php
      $bits = [];
      if (mb_strtolower(trim($u['sub'])) !== mb_strtolower(trim($u['title']))) $bits[] = e($u['sub']);
      if ($u['cms']) $bits[] = e((string)$u['cms']);
      if ($mode === 'site' && $u['total'] > 1) {
          $bits[] = tn($u['total'], '1 sonde', '{n} sondes')
                  . ($u['bad'] ? ' <span class="v-bad">(' . tn($u['bad'], '1 en défaut', '{n} en défaut') . ')</span>' : '');
      }
      echo $bits ? implode(' · ', $bits) : '&nbsp;';
      ?>
    </div>

    <div class="card-msg clamp2"><?= $msg !== ''
        ? e(str_cut($msg, 130))
        : '<span class="muted">' . e(Ui::statusLabel($st))
          . ($m['last_check_at']
              ? ' · ' . e(t('vérifié {when}', ['when' => human_since((string)$m['last_check_at'])]))
              : '') . '</span>' ?></div>

    <?= Ui::sparkline($sparks[$mid] ?? [], 320, 34) ?>

    <div class="card-foot">
      <?php if ($m['last_ms'] !== null): ?>
        <span class="card-num"><?= Ui::ms((int)$m['last_ms']) ?><small><?= te('réponse') ?></small></span>
      <?php endif; ?>
      <?php foreach ($flags as [$txt, $tone]): ?><?= Ui::badge($txt, $tone) ?><?php endforeach; ?>
      <div class="card-actions">
        <button class="btn btn-sm btn-ghost btn-icon js-check" data-id="<?= $mid ?>"
                title="<?= te('Vérifier maintenant') ?>" aria-label="<?= te('Vérifier') ?> <?= e($u['title']) ?> maintenant"><?= Ui::icon('refresh', 15) ?></button>
        <button class="btn btn-sm btn-ghost btn-icon js-toggle" data-id="<?= $mid ?>"
                title="<?= (int)$m['enabled'] === 1 ? 'Mettre en pause' : 'Réactiver' ?>"
                aria-label="<?= (int)$m['enabled'] === 1 ? 'Mettre en pause' : 'Réactiver' ?>"><?=
                Ui::icon((int)$m['enabled'] === 1 ? 'pause' : 'play', 15) ?></button>
        <a class="btn btn-sm btn-ghost btn-icon" href="<?= e((string)$m['url']) ?>" target="_blank" rel="noopener"
           title="<?= te('Ouvrir le site dans un onglet') ?>" aria-label="<?= te('Ouvrir le site') ?>"><?= Ui::icon('external', 15) ?></a>
      </div>
    </div>
  </article>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<?php
$worst = Stats::worst(6);
if (count($worst) > 1):
  echo Ui::accOpen('fragile', 'chart', t('Les plus fragiles sur 30 jours'), t('là où il faut agir en priorité'));
  echo Ui::accBody(true);
?>
  <div class="table-scroll"><table class="tbl">
    <thead><tr><th><?= te('Sonde') ?></th><th class="num"><?= te('Uptime 30 j') ?></th><th class="num"><?= te('Incidents') ?></th><th><?= te('État') ?></th></tr></thead>
    <tbody>
    <?php foreach ($worst as $w): ?>
      <tr>
        <td><a href="<?= e(u('monitor', ['id' => (int)$w['id']])) ?>"><?= e((string)$w['name']) ?></a>
            <div class="tiny muted truncate"><?= e(str_cut((string)$w['url'], 70)) ?></div></td>
        <td class="num"><span class="badge badge-<?= Ui::uptimeTone($w['uptime_30d'] !== null ? (float)$w['uptime_30d'] : null) ?>">
          <?= Ui::pct($w['uptime_30d'] !== null ? (float)$w['uptime_30d'] : null) ?></span></td>
        <td class="num"><?= (int)$w['inc30'] ?></td>
        <td><?= Ui::dot((string)$w['status']) ?> <span class="small"><?= e(Ui::statusLabel((string)$w['status'])) ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
<?= Ui::accClose() ?>
<?php endif; ?>
