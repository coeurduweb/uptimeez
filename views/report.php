<?php
/**
 * Rapport client, prêt à imprimer ou à enregistrer en PDF.
 *
 * Volontairement sobre : un client n'a pas besoin de nos réglages, il veut savoir
 * si son site a été disponible, combien de temps il ne l'a pas été, et pourquoi.
 */
use Uptimeez\Auth;
use Uptimeez\Config;
use Uptimeez\Db;
use Uptimeez\Notify\Notifier;
use Uptimeez\Stats;
use Uptimeez\Ui;

$csrf = Auth::csrf();

$sites = Db::all('SELECT s.id, s.name, s.domain FROM sites s
                  WHERE EXISTS (SELECT 1 FROM monitors m WHERE m.site_id = s.id)
                  ORDER BY s.name ASC');
$siteId = (int)($_GET['site'] ?? ($sites[0]['id'] ?? 0));
$range  = array_key_exists((string)($_GET['range'] ?? ''), Ui::RANGES) ? (string)$_GET['range'] : '30d';
$secs   = Ui::rangeSeconds($range);

$site = null;
foreach ($sites as $s) if ((int)$s['id'] === $siteId) $site = $s;

$mons = $site ? Db::all('SELECT * FROM monitors WHERE site_id = ? ORDER BY role DESC, name ASC', [$siteId]) : [];
?>
<div class="row-between mt no-print">
  <div>
    <h1><?= te('Rapport client') ?></h1>
    <p class="muted small mb0"><?= te('Choisissez un site et une période, puis imprimez ou enregistrez en PDF.') ?></p>
  </div>
  <form method="get" class="row" style="gap:8px">
    <input type="hidden" name="p" value="report">
    <label class="sr-only" for="rsite"><?= te('Site') ?></label>
    <select id="rsite" name="site" onchange="this.form.submit()" style="max-width:260px">
      <?php foreach ($sites as $s): ?>
        <option value="<?= (int)$s['id'] ?>" <?= $siteId === (int)$s['id'] ? 'selected' : '' ?>>
          <?= e((string)$s['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <label class="sr-only" for="rrange"><?= te('Période') ?></label>
    <select id="rrange" name="range" onchange="this.form.submit()" style="max-width:140px">
      <?php foreach (Ui::RANGES as $k => $l): ?>
        <option value="<?= $k ?>" <?= $range === $k ? 'selected' : '' ?>><?= e($l) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="button" class="btn btn-primary" onclick="window.print()"><?= Ui::icon('download', 15) ?> <?= te('Imprimer / PDF') ?></button>
  </form>
</div>

<?php if (!$site): ?>
  <div class="panel"><div class="empty">
    <h3><?= te('Aucun site à présenter') ?></h3>
    <p class="muted"><?= te('Ajoutez des sites : leurs rapports seront disponibles dès les premières mesures.') ?></p>
    <a class="btn btn-primary mt" href="<?= e(u('import')) ?>"><?= te('Ajouter des sites') ?></a>
  </div></div>
<?php return; endif; ?>

<article class="report">
  <header class="rep-head">
    <div>
      <div class="rep-kicker"><?= te('Rapport de disponibilité') ?></div>
      <h2 class="rep-title"><?= e((string)$site['name']) ?></h2>
      <div class="rep-sub"><?= e((string)$site['domain']) ?> · <?= te('période :') ?>
        <?= e(mb_strtolower(Ui::rangeLabel($range))) ?>
        (du <?= e(date('d/m/Y', time() - $secs)) ?> au <?= e(date('d/m/Y')) ?>)</div>
    </div>
    <div class="rep-logo"><?= Ui::brand(26) ?> <?= te('{app}') ?></div>
  </header>

  <?php
  // Synthèse pondérée : la page principale porte le chiffre de référence.
  $primary = null;
  foreach ($mons as $m) if ($m['role'] === 'primary') { $primary = $m; break; }
  $primary ??= $mons[0] ?? null;
  $w = $primary ? Stats::window((int)$primary['id'], $secs, $primary) : null;
  ?>

  <?php if ($w): ?>
  <section class="stats" style="margin-top:18px">
    <div class="stat">
      <div class="stat-label"><?= te('Disponibilité') ?></div>
      <div class="stat-value v-<?= Ui::uptimeTone($w['uptime']) ?>"><?= Ui::pct($w['uptime']) ?></div>
      <div class="stat-hint"><?= te('sur la période') ?></div>
    </div>
    <div class="stat">
      <div class="stat-label"><?= te('Indisponibilité cumulée') ?></div>
      <div class="stat-value"><?= e(human_duration($w['downtime_sec'])) ?></div>
      <div class="stat-hint"><?= (int)$w['incidents'] ?> interruption<?= (int)$w['incidents'] > 1 ? 's' : '' ?></div>
    </div>
    <div class="stat">
      <div class="stat-label"><?= te('Temps de chargement moyen') ?></div>
      <div class="stat-value"><?= Ui::ms($w['avg_ms']) ?></div>
      <div class="stat-hint">p95 <?= Ui::ms($w['p95_ms']) ?></div>
    </div>
    <div class="stat">
      <div class="stat-label"><?= te('Pages surveillées') ?></div>
      <div class="stat-value"><?= count($mons) ?></div>
      <div class="stat-hint"><?= te('vérifiées en continu') ?></div>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($primary): ?>
    <h3 class="rep-h3"><?= te('Disponibilité jour par jour') ?></h3>
    <?= Ui::dayStrip((int)$primary['id'], min(90, (int)ceil($secs / 86400))) ?>
    <p class="tiny muted"><?= te('Vert : journée complète en ligne · orange : incident bref · rouge : interruption de plus de 15 minutes.') ?></p>

    <h3 class="rep-h3"><?= te('Temps de réponse') ?></h3>
    <?= Ui::chart(Stats::series((int)$primary['id'], $secs, Ui::rangeBuckets($range)), 1000, 200) ?>
  <?php endif; ?>

  <h3 class="rep-h3"><?= te('Pages surveillées') ?></h3>
  <table class="tbl rep-table">
    <thead><tr><th><?= te('Page') ?></th><th class="num"><?= te('Disponibilité') ?></th><th class="num"><?= te('Chargement') ?></th><th><?= te('État actuel') ?></th></tr></thead>
    <tbody>
    <?php foreach ($mons as $m):
      $mw = Stats::window((int)$m['id'], $secs, $m); ?>
      <tr>
        <td><?= e((string)$m['name']) ?>
          <div class="tiny muted"><?= e(str_cut((string)$m['url'], 80)) ?></div></td>
        <td class="num"><?= Ui::pct($mw['uptime']) ?></td>
        <td class="num"><?= Ui::ms($mw['avg_ms']) ?></td>
        <td><?= e(Ui::statusLabel((string)$m['status'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <?php
  $ids = array_map(fn($m) => (int)$m['id'], $mons);
  $incidents = [];
  if ($ids) {
      $in = implode(',', array_fill(0, count($ids), '?'));
      $incidents = Db::all("SELECT i.*, m.name FROM incidents i JOIN monitors m ON m.id = i.monitor_id
                            WHERE i.monitor_id IN ($in) AND i.started_at >= ? AND i.severity = 'down'
                            ORDER BY i.started_at DESC LIMIT 40",
                           array_merge($ids, [date('Y-m-d H:i:s', time() - $secs)]));
  }
  ?>
  <?php
  // Une panne de mise en page se démontre en montrant la page, pas en la décrivant.
  $sils = $ids ? Db::all('SELECT name, silhouette_ref, silhouette_now, silhouette_drift
                          FROM monitors WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
                            AND silhouette_drift >= 20
                            AND silhouette_ref IS NOT NULL AND silhouette_now IS NOT NULL
                          ORDER BY silhouette_drift DESC LIMIT 2', $ids) : [];
  if ($sils): ?>
    <h3 class="rep-h3"><?= te('Ce que voit le visiteur') ?></h3>
    <?php foreach ($sils as $sl): ?>
      <p class="small soft"><strong><?= e((string)$sl['name']) ?></strong> :
        <?= te('{n} % de différence avec la référence', ['n' => (int)$sl['silhouette_drift']]) ?>.
        <?= te('Silhouette reconstruite depuis le HTML et le CSS chargé, ce n\'est pas une capture d\'écran.') ?></p>
      <div class="sil-pair">
        <figure class="sil"><figcaption><?= te('Référence') ?></figcaption>
          <div class="sil-view"><?= $sl['silhouette_ref'] ?></div></figure>
        <figure class="sil sil-bad"><figcaption><?= te('Maintenant') ?></figcaption>
          <div class="sil-view"><?= $sl['silhouette_now'] ?></div></figure>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <h3 class="rep-h3"><?= te('Interruptions constatées') ?></h3>
  <?php if (!$incidents): ?>
    <p class="rep-good"><?= Ui::icon('check', 16) ?> <?= te('Aucune interruption sur la période.') ?></p>
  <?php else: ?>
    <table class="tbl rep-table">
      <thead><tr><th><?= te('Date') ?></th><th><?= te('Page') ?></th><th><?= te('Cause') ?></th><th class="num"><?= te('Durée') ?></th></tr></thead>
      <tbody>
      <?php foreach ($incidents as $i): ?>
        <tr>
          <td class="nowrap"><?= e(date('d/m/Y H:i', strtotime((string)$i['started_at']))) ?></td>
          <td><?= e((string)$i['name']) ?></td>
          <td><?= e(Notifier::reasonLabel((string)$i['reason_code'])) ?></td>
          <td class="num nowrap"><?= $i['ended_at'] ? e(human_duration((int)$i['duration_sec'])) : 'en cours' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <footer class="rep-foot">
    <?= te('Document produit le {date} par {app}, surveillance continue.', ['date' => date('d/m/Y H:i')]) ?>
    <?= te('Les mesures sont effectuées automatiquement, sans intervention humaine.') ?>
  </footer>
</article>

<style>
.report { background: var(--surface); border: 1px solid var(--border); border-radius: var(--r);
  padding: 28px 32px; margin-top: var(--s4); box-shadow: var(--shadow-sm); }
.rep-head { display: flex; justify-content: space-between; align-items: flex-start; gap: var(--s4);
  border-bottom: 2px solid var(--border); padding-bottom: 16px; }
.rep-kicker { font-size: 11.5px; text-transform: uppercase; letter-spacing: .1em; color: var(--muted); font-weight: 700; }
.rep-title { font-size: 25px; margin-top: 4px; letter-spacing: -.025em; }
.rep-sub { color: var(--text-soft); font-size: 13.5px; margin-top: 5px; }
.rep-logo { display: flex; align-items: center; gap: 7px; font-weight: 700; color: var(--muted); }
.rep-h3 { margin: 26px 0 10px; font-size: 13px; text-transform: uppercase; letter-spacing: .07em; color: var(--muted); }
.rep-table { border: 1px solid var(--border); border-radius: var(--r-sm); overflow: hidden; }
.rep-good { display: flex; gap: 8px; align-items: center; color: var(--ok); font-size: 14px; font-weight: 550; }
.rep-foot { margin-top: 28px; padding-top: 14px; border-top: 1px solid var(--border);
  font-size: 12px; color: var(--muted); }
@media print {
  .no-print, .topbar { display: none !important; }
  .report { border: 0; box-shadow: none; padding: 0; margin: 0; }
  .wrap { padding: 0; max-width: none; }
  .stat { border: 1px solid #ddd; }
  a[href]::after { content: ""; }
}
</style>

<!-- ============ ENVOI AUTOMATIQUE (jamais imprimé) ============ -->
<section class="no-print mt-lg">
  <?= Ui::accOpen('autoreport', 'bell', t('Envoi automatique du rapport'),
        Config::get('report.enabled', false)
          ? t('actif, le {day} de chaque mois', ['day' => (int)Config::get('report.day', 1)])
          : t('inactif'),
        false, Config::get('report.enabled', false) ? 'none' : 'none') ?>
  <?= Ui::accBody() ?>
    <p class="small soft prose"><?= te('Chaque client reçoit le rapport de ses sites, et rien d\'autre. L\'envoi part une fois par mois sur le mois écoulé : un cron qui tourne toutes les minutes n\'expédie rien de plus.') ?></p>

    <form method="post" class="row" data-dirty-watch>
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="save_autoreport">
      <label class="switchrow" style="flex:1 1 260px">
        <input type="checkbox" name="report_enabled" <?= Config::get('report.enabled', false) ? 'checked' : '' ?>>
        <span class="sw-text"><span class="sw-title"><?= te('Envoyer le rapport automatiquement') ?></span>
          <span class="hint"><?= te('Demande que le canal e-mail soit configuré et testé.') ?></span></span>
      </label>
      <div class="field" style="flex:0 0 130px">
        <label for="rday"><?= te('Jour du mois') ?></label>
        <input id="rday" type="number" name="report_day" min="1" max="28"
               value="<?= (int)Config::get('report.day', 1) ?>">
      </div>
      <div class="field" style="flex:1 1 320px">
        <label for="rsubj"><?= te('Objet du message') ?><?= hint('Trois variables sont remplacées : {site}, {month} et {app}.') ?></label>
        <input id="rsubj" type="text" name="report_subject"
               value="<?= e((string)Config::get('report.subject', '')) ?>"
               placeholder="<?= te('Disponibilité de {site} : {month}') ?>">
      </div>
      <div class="field" style="flex:1 1 320px">
        <label for="rfb"><?= te('Destinataires par défaut') ?><?= hint('Utilisés pour les sites qui n\'ont pas de destinataire propre. Laissez vide pour n\'envoyer qu\'aux clients explicitement renseignés.') ?></label>
        <input id="rfb" type="text" name="report_fallback"
               value="<?= e((string)Config::get('report.fallback_to', '')) ?>"
               placeholder="<?= te('vous@agence.fr, astreinte@agence.fr') ?>" spellcheck="false">
      </div>
      <div class="savebar" data-savebar hidden>
        <span class="sb-note"><?= Ui::icon('info', 15) ?> <?= te('Modifications non enregistrées') ?></span>
        <span class="grow"></span>
        <button type="button" class="btn btn-sm" data-reset-form><?= te('Annuler') ?></button>
        <button class="btn btn-primary btn-sm"><?= te('Enregistrer') ?></button>
      </div>
      <div class="row mt" data-static-save style="flex:1 1 100%">
        <button class="btn btn-primary"><?= te('Enregistrer les réglages') ?></button>
      </div>
    </form>

    <?php
    $allSites = Db::all('SELECT s.*, (SELECT COUNT(*) FROM monitors m WHERE m.site_id = s.id) AS n
                         FROM sites s ORDER BY s.name ASC');
    if ($allSites): ?>
      <div class="table-scroll mt"><table class="tbl">
        <thead><tr>
          <th><?= te('Site') ?></th><th><?= te('Destinataires') ?></th>
          <th class="num"><?= te('Envoi') ?></th><th class="num"><?= te('Dernier envoi') ?></th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($allSites as $st): ?>
          <tr>
            <td><strong><?= e((string)$st['name']) ?></strong>
              <div class="tiny muted"><?= e((string)$st['domain']) ?> ·
                <?= tne((int)$st['n'], 'une sonde', '{n} sondes') ?></div></td>
            <td>
              <form method="post" class="row tight">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="save_site_report">
                <input type="hidden" name="site_id" value="<?= (int)$st['id'] ?>">
                <input type="text" name="report_to" style="min-width:220px"
                       value="<?= e((string)($st['report_to'] ?? '')) ?>"
                       placeholder="<?= te('client@exemple.fr') ?>" spellcheck="false"
                       aria-label="<?= te('Destinataires') ?>">
                <label class="switchrow tight">
                  <input type="checkbox" name="site_report_enabled"
                         <?= (int)($st['report_enabled'] ?? 0) === 1 ? 'checked' : '' ?>>
                  <span class="sw-text"><span class="sw-title tiny"><?= te('Actif') ?></span></span>
                </label>
                <button class="btn btn-sm"><?= te('Enregistrer') ?></button>
              </form>
            </td>
            <td class="num small">
              <?= (int)($st['report_enabled'] ?? 0) === 1
                    ? Ui::badge(t('programmé'), 'ok') : Ui::badge(t('désactivé'), 'neutral') ?>
            </td>
            <td class="num small nowrap"><?= $st['report_sent_at']
                  ? e(Notifier::when((string)$st['report_sent_at'])) : '—' ?></td>
            <td class="num">
              <form method="post">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="send_site_report">
                <input type="hidden" name="site_id" value="<?= (int)$st['id'] ?>">
                <button class="btn btn-sm btn-ghost nowrap"
                        title="<?= te('Envoie immédiatement le rapport du mois écoulé, sans attendre la date programmée.') ?>">
                  <?= Ui::icon('bell', 14) ?> <?= te('Envoyer maintenant') ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  <?= Ui::accClose() ?>
</section>
