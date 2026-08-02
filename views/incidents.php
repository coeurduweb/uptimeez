<?php
/** Historique des incidents, filtrable. */
use Uptimeez\Auth;
use Uptimeez\Db;
use Uptimeez\Notify\Notifier;
use Uptimeez\Ui;

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

// PAGINATION. Mesuré le 2026-08-02 : cet écran faisait 12 144 px de haut pour 175 lignes.
//
// LES STATISTIQUES DU HAUT PORTENT SUR LA PÉRIODE ENTIÈRE, PAS SUR LA PAGE, et c'est le
// piège de cette modification : compter sur les lignes affichées ferait dire « 3 incidents
// sur la période » à quelqu'un qui en a cent soixante-quinze. Le total se calcule donc en
// base, sur le même filtre, indépendamment de la tranche affichée.
$filtre = implode(' AND ', $where);

$agg = Db::one('SELECT COUNT(*) AS n,
                       SUM(CASE WHEN i.ended_at IS NULL THEN 1 ELSE 0 END) AS ouverts,
                       SUM(CASE WHEN i.ended_at IS NULL THEN 0 ELSE i.duration_sec END) AS clos_sec
                FROM incidents i JOIN monitors m ON m.id = i.monitor_id
                WHERE ' . $filtre, $params) ?: [];

$total = (int)($agg['n'] ?? 0);
$open  = (int)($agg['ouverts'] ?? 0);
$downSec = (int)($agg['clos_sec'] ?? 0);

// Les incidents encore ouverts n'ont pas de durée en base : elle se compte jusqu'à
// maintenant, et il faut donc lire leurs dates de début.
foreach (Db::all('SELECT i.started_at FROM incidents i JOIN monitors m ON m.id = i.monitor_id
                  WHERE ' . $filtre . ' AND i.ended_at IS NULL', $params) as $ouvert) {
    $downSec += max(0, time() - strtotime((string)$ouvert['started_at']));
}

$page = Ui::page();
$rows = Db::all('SELECT i.*, m.name, m.url FROM incidents i JOIN monitors m ON m.id = i.monitor_id
                 WHERE ' . $filtre . ' ORDER BY i.started_at DESC
                 LIMIT ' . Ui::PAR_PAGE_DENSE . ' OFFSET ' . Ui::saut($page, Ui::PAR_PAGE_DENSE), $params);
?>
<div class="row-between mt">
  <h1><?= te('Incidents') ?></h1>
  <div class="row">
    <div class="segmented">
      <?php foreach (['all' => t('Tous'), 'open' => t('En cours'), 'closed' => t('Clos')] as $k => $l): ?>
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

              <?php
              // CE FORMULAIRE NE FAIT RIEN TAIRE, et le dire est la moitié du travail.
              // Un bouton « fausse alerte » qui masque l'incident transforme un défaut
              // visible en défaut invisible. Il est donc nommé « Donner mon avis » et non
              // « Ignorer », et la réponse rappelle que l'alerte reste affichée.
              //
              // LA PORTÉE EST DEMANDÉE PARCE QU'ELLE CHANGE TOUT : « normal ici » et
              // « normal partout » appellent deux gestes opposés. La laisser deviner
              // reviendrait à laisser un exploitant dégrader la détection des autres.
              ?>
              <details class="retour">
                <summary class="btn btn-sm btn-ghost"><?= te('Donner mon avis') ?></summary>
                <form class="retour-form" data-incident="<?= (int)$r['id'] ?>">
                  <p class="tiny muted"><?= te('Ceci ne masque pas l\'alerte : cela nous dit où le contrôle se trompe.') ?></p>
                  <label class="tiny"><?= te('Ce qui s\'est passé') ?>
                    <select name="motif">
                      <option value="controle_errone"><?= te('Le contrôle s\'est trompé') ?></option>
                      <option value="sans_effet"><?= te('Réel, mais sans effet visible') ?></option>
                      <option value="normal_ici"><?= te('Normal sur ce site') ?></option>
                      <option value="vrai_et_corrige"><?= te('C\'était vrai, c\'est corrigé') ?></option>
                    </select>
                  </label>
                  <label class="tiny"><?= te('Portée de ce constat') ?>
                    <select name="portee">
                      <option value="sonde"><?= te('Cette page seulement') ?></option>
                      <option value="serveur"><?= te('Tout ce serveur') ?></option>
                      <option value="parc"><?= te('Tous mes sites') ?></option>
                    </select>
                  </label>
                  <label class="tiny"><?= te('Précision (facultatif)') ?>
                    <input type="text" name="commentaire" maxlength="500">
                  </label>
                  <button class="btn btn-sm"><?= te('Envoyer') ?></button>
                </form>

                <?php
                // ICI, ET SEULEMENT ICI, ON AGIT. Le formulaire du dessus décrit sans rien
                // changer ; celui-ci tait le signal pour de bon. Les deux partent du même
                // incident, et les confondre serait le pire défaut possible de cet écran :
                // quelqu'un croirait avoir seulement donné son avis alors qu'il vient
                // d'éteindre une alerte, ou l'inverse. D'où le trait de séparation, le
                // libellé qui dit ce qui va se passer, et la raison obligatoire.
                //
                // Le bloc n'apparaît QUE sur les causes d'apparence. Sur un 503, il ne sert
                // à rien de proposer un geste que le moteur refusera : une action offerte
                // puis refusée use la confiance autant qu'une action absente.
                ?>
                <?php if (Uptimeez\Exceptions::estExcusable((string)$r['reason_code'])): ?>
                  <hr class="retour-sep">
                  <form class="exception-form" data-incident="<?= (int)$r['id'] ?>">
                    <p class="tiny"><strong><?= te('Ou taire ce signal sur cette page') ?></strong></p>
                    <p class="tiny muted"><?= te('Celui-ci agit vraiment : l\'alerte n\'apparaîtra plus. Elle restera comptée, et l\'exception sera à revoir dans six mois.') ?></p>
                    <label class="tiny"><?= te('Pourquoi (obligatoire)') ?>
                      <input type="text" name="raison" maxlength="500" required>
                    </label>
                    <button class="btn btn-sm btn-ghost"><?= te('Taire ce signal ici') ?></button>
                  </form>
                <?php endif; ?>
              </details>
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
    <?= Ui::pagination($page, $total, 'incidents',
          ['s' => $state !== 'all' ? $state : null, 'range' => $range,
           'id' => $onlyId ?: null], Ui::PAR_PAGE_DENSE) ?>
  </div>
</div>
