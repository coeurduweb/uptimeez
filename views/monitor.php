<?php
/**
 * Fiche d'une sonde.
 *
 * Lecture en trois temps : on voit d'abord l'état et la cause, puis les chiffres
 * et la courbe, puis le détail, replié dans des accordéons pour que la page
 * reste courte tant qu'on n'a rien à y chercher.
 */
use Uptimeez\Auth;
use Uptimeez\Db;
use Uptimeez\Diagnose;
use Uptimeez\Notify\Notifier;
use Uptimeez\Heartbeat;
use Uptimeez\Stats;
use Uptimeez\Tune;
use Uptimeez\Ui;

$id  = (int)($_GET['id'] ?? 0);
$mon = Db::one('SELECT m.*, s.domain AS site_domain, s.name AS site_name, s.cms AS site_cms, s.cms_detail
                FROM monitors m LEFT JOIN sites s ON s.id = m.site_id WHERE m.id = ?', [$id]);
if (!$mon) {
    echo '<div class="panel"><div class="empty"><h3>' . te('Cette sonde n\'existe plus') . '</h3>'
       . '<a class="btn mt" href="' . e(u('dashboard')) . '">' . te('Retour au tableau de bord')
       . '</a></div></div>';
    return;
}

$range   = array_key_exists((string)($_GET['range'] ?? ''), Ui::RANGES) ? (string)$_GET['range'] : '24h';
$series  = Stats::series($id, Ui::rangeSeconds($range), Ui::rangeBuckets($range));
$win     = Stats::window($id, Ui::rangeSeconds($range), $mon);
$w24     = Stats::window($id, 86400, $mon);
$w30     = Stats::window($id, 2592000, $mon);

$incidents = Db::all('SELECT * FROM incidents WHERE monitor_id = ? ORDER BY started_at DESC LIMIT 15', [$id]);
$recent    = Db::all('SELECT * FROM checks WHERE monitor_id = ? ORDER BY ts DESC LIMIT 12', [$id]);
$events    = Db::all('SELECT * FROM events WHERE monitor_id = ? ORDER BY ts DESC LIMIT 8', [$id]);
$siblings  = $mon['site_id'] ? Db::all('SELECT id, name, url, status, last_ms, uptime_24h, role FROM monitors
                                        WHERE site_id = ? AND id <> ? ORDER BY role DESC, name ASC', [(int)$mon['site_id'], $id]) : [];
$openInc   = Db::one('SELECT * FROM incidents WHERE monitor_id = ? AND ended_at IS NULL ORDER BY id DESC LIMIT 1', [$id]);
$cssDetail = jdec($mon['css_detail'] ?? null);
$cssBase   = jdec($mon['css_baseline'] ?? null);
$cmsDetail = jdec($mon['cms_detail'] ?? null);
$cm        = $cssDetail['metrics'] ?? [];
$st        = (string)$mon['status'];
$csrf      = Auth::csrf();
$isUp      = $st === 'up';
$diag      = $isUp ? null : Diagnose::explain((string)$mon['reason_code'], $mon);
$sslDays   = $mon['ssl_days_left'] !== null ? (int)$mon['ssl_days_left'] : null;
$failedRes = 0;
foreach (($cm['assets'] ?? []) as $a) if (!empty($a['issue'])) $failedRes++;
?>

<!-- ====================== EN-TÊTE : état et cause ====================== -->
<section class="band <?= $st === 'down' ? 'band-bad' : ($st === 'degraded' ? 'band-warn' : ($isUp ? 'band-ok' : '')) ?>">
  <div class="band-icon"><?= Ui::statusIcon($st) ?></div>
  <div class="grow">
    <div class="band-title"><?= e((string)$mon['name']) ?></div>
    <div class="band-sub">
      <?= Ui::dot($st) ?> <strong><?= e(Ui::statusLabel($st)) ?></strong>
      <?php if ($mon['status_since']): ?> depuis <?= e(human_since((string)$mon['status_since'])) ?><?php endif; ?>
      · <a href="<?= e((string)$mon['url']) ?>" target="_blank" rel="noopener"><?= e(str_cut((string)$mon['url'], 70)) ?></a>
      <?php if ($mon['last_check_at']): ?> · <?= te('vérifié {when}', ['when' => human_since((string)$mon['last_check_at'])]) ?><?php endif; ?>
    </div>
  </div>
  <div class="band-cta row">
    <button class="btn btn-primary js-check" data-id="<?= $id ?>"><?= Ui::icon('refresh') ?> <?= te('Vérifier') ?></button>
    <button class="btn js-toggle" data-id="<?= $id ?>" title="<?= (int)$mon['enabled'] === 1 ? te('Suspendre la surveillance') : te('Reprendre la surveillance') ?>">
      <?= (int)$mon['enabled'] === 1 ? Ui::icon('pause') . ' Pause' : Ui::icon('play') . ' Reprendre' ?>
    </button>
  </div>
</section>

<?php if ($diag): ?>
<!-- ====================== DIAGNOSTIC ====================== -->
<div class="acc <?= $st === 'down' ? 'acc-attn' : 'acc-warn' ?>" open>
  <div class="acc-body">
    <div class="diag">
      <span class="acc-icon"><?= Ui::icon($diag['icon'], 26) ?></span>
      <div class="diag-body grow">
        <div class="diag-cause"><?= e($diag['title']) ?></div>
        <div class="diag-detail"><?= e($diag['why']) ?></div>
        <?php
        // Message retenu + trace technique de la dernière mesure (texte brut de curl,
        // extrait de la page d'erreur…) : c'est ce qu'on veut copier dans un ticket.
        $evidence = [];
        if ($mon['last_message']) $evidence[] = verdict_text($mon);
        $lastDet = jdec($recent[0]['details'] ?? null);
        // Les autres causes relevées à la même mesure : elles ne tiennent pas
        // dans le verdict, mais elles sont là quand on ouvre la fiche.
        foreach ((array)($lastDet['findings'] ?? []) as $i => $f) {
            if ($i === 0) continue;                       // déjà dit par le verdict
            $txt = t((string)($f[2] ?? ''), (array)($f[3] ?? []));
            if ($txt !== '' && !in_array($txt, $evidence, true)) $evidence[] = $txt;
        }
        if (!empty($lastDet['net_error'])) $evidence[] = (string)$lastDet['net_error'];
        if ($evidence): ?>
          <div class="diag-evidence"><?= e(implode("\n", $evidence)) ?></div>
        <?php endif; ?>
        <div class="diag-fix"><strong><?= te('Que faire :') ?></strong> <?= e($diag['fix']) ?></div>
        <?php if ($openInc): ?>
          <div class="row mt" style="flex-wrap:wrap">
            <span class="small muted">
              <?= te('Incident ouvert depuis') ?> <?= e(human_duration(max(0, time() - strtotime((string)$openInc['started_at'])))) ?>
              · <?= tne((int)$openInc['checks_failed'], 'un échec', '{n} échecs') ?>
              · <?= tne((int)$openInc['notify_count'], 'une alerte envoyée', '{n} alertes envoyées') ?>
            </span>
            <form method="post" class="row" style="margin-left:auto;gap:6px">
              <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
              <input type="hidden" name="id" value="<?= (int)$openInc['id'] ?>">
              <?php if (!$openInc['ack_at']): ?>
                <input type="hidden" name="action" value="ack_incident">
                <button class="btn btn-sm" title="<?= te('Stoppe les rappels d\'alerte, l\'incident reste ouvert') ?>"><?= te('Pris en compte') ?></button>
              <?php else: ?>
                <input type="hidden" name="action" value="close_incident">
                <button class="btn btn-sm"><?= te('Clore l\'incident') ?></button>
              <?php endif; ?>
            </form>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ====================== CHIFFRES CLÉS ====================== -->
<section class="stats mt">
  <div class="stat">
    <div class="stat-label"><?= te('Uptime 24 h') ?></div>
    <div class="stat-value v-<?= Ui::uptimeTone($w24['uptime']) ?>"><?= Ui::pct($w24['uptime']) ?></div>
    <div class="stat-hint"><?= $w24['downtime_sec'] > 0
        ? te('{duration} hors service', ['duration' => human_duration($w24['downtime_sec'])])
        : te('aucune interruption') ?></div>
  </div>
  <div class="stat">
    <div class="stat-label"><?= te('Uptime 30 j') ?></div>
    <div class="stat-value v-<?= Ui::uptimeTone($w30['uptime']) ?>"><?= Ui::pct($w30['uptime']) ?></div>
    <div class="stat-hint"><?= tne((int)$w30['incidents'], '1 incident', '{n} incidents') ?></div>
  </div>
  <div class="stat">
    <div class="stat-label"><?= te('Chargement') ?><?= hint('Temps total de la requête, DNS et TLS compris : ce que vit réellement un visiteur, pas seulement le temps serveur.') ?></div>
    <div class="stat-value"><?= Ui::ms($win['avg_ms']) ?></div>
    <div class="stat-hint"><?= te('p95 {p95} · pire {worst}',
        ['p95' => Ui::ms($win['p95_ms']), 'worst' => Ui::ms($win['worst_ms'])]) ?></div>
  </div>
  <div class="stat">
    <div class="stat-label"><?= te('Ping réseau') ?></div>
    <div class="stat-value"><?= Ui::ms($win['ping_ms']) ?></div>
    <div class="stat-hint"><?= te('DNS {dns} · premier octet {ttfb}',
        ['dns' => Ui::ms($win['dns_ms']), 'ttfb' => Ui::ms($win['ttfb_ms'])]) ?></div>
  </div>
</section>

<!-- ====================== COURBE ====================== -->
<div class="panel">
  <div class="panel-head">
    <h2><?= Ui::icon('chart', 15) ?> <?= te('Temps de réponse et pannes') ?></h2>
    <?= Ui::rangePicker($range, ['p' => 'monitor', 'id' => $id]) ?>
  </div>
  <div class="panel-body">
    <?= Ui::chart($series) ?>
    <div class="legend">
      <span><i style="background:var(--accent)"></i><?= te('temps de réponse') ?></span>
      <span><i style="background:color-mix(in srgb,var(--bad) 30%,transparent)"></i><?= te('hors service') ?></span>
      <span><i style="background:color-mix(in srgb,var(--warn) 24%,transparent)"></i><?= te('dégradé') ?></span>
      <span class="grow"></span>
      <span><?= tne((int)$win['checks'], 'une mesure sur {range}', '{n} mesures sur {range}',
        ['range' => mb_strtolower(Ui::rangeLabel($range))]) ?><?php
        if (($series['source'] ?? '') === 'daily'): ?> · <?= te('agrégats journaliers') ?><?php endif; ?></span>
    </div>
    <div class="mt-lg">
      <div class="section-title" style="margin-top:0"><?= te('Disponibilité jour par jour · 30 derniers jours') ?></div>
      <?= Ui::dayStrip($id, 30) ?>
    </div>
  </div>
</div>

<div class="section-title"><?= te('Détail') ?></div>

<!-- ====================== RESSOURCES DE LA PAGE ====================== -->
<?php
$cs = (string)($mon['css_state'] ?? '');
$resTone = $cs === 'broken' ? 'attn' : ($cs === 'warn' ? 'warn' : 'none');
$resBadge = !$cssDetail
    ? Ui::badge(t('pas encore analysé'), 'neutral')
    : match ($cs) {
        'ok'     => Ui::badge(t('conforme'), 'ok'),
        'warn'   => Ui::badge($failedRes ? tn($failedRes, '1 à vérifier', '{n} à vérifier') : t('à vérifier'), 'warn'),
        'broken' => Ui::badge($failedRes ? tn($failedRes, '1 en échec', '{n} en échec') : t('cassé'), 'bad'),
        default  => Ui::badge(t('pas encore analysé'), 'neutral'),
    };
$resTone = $cssDetail ? $resTone : 'none';
echo Ui::accOpen('res', 'layers', t('Ressources de la page'),
    $cssDetail ? t('CSS, scripts et polices · analyse {when}',
                   ['when' => Notifier::when($cssDetail['at'] ?? $mon['css_checked_at'])]) : '',
    $cs === 'broken' || $cs === 'warn', $resTone, $resBadge);
echo Ui::accBody();

// La silhouette avant tout le reste : une image se comprend sans lecture, et
// c'est elle qu'on montre au client. Le détail technique vient après.
$silRef  = (string)($mon['silhouette_ref'] ?? '');
$silNow  = (string)($mon['silhouette_now'] ?? '');
$drift   = (int)($mon['silhouette_drift'] ?? 0);
?>
  <?php if ($silNow !== ''): ?>
    <div class="sil-wrap">
      <div class="sil-head">
        <strong><?= te('La page telle qu\'un visiteur la voit') ?></strong>
        <?= hint('Silhouette reconstruite depuis le HTML et le CSS réellement chargé. Ce n\'est pas une capture d\'écran : c\'est la structure que le navigateur pourrait mettre en page. Quand une feuille de style tombe, la silhouette change exactement comme la page change.') ?>
        <span class="grow"></span>
        <?php if ($silRef !== '' && $drift > 0): ?>
          <span class="badge badge-<?= $drift >= 35 ? 'bad' : 'warn' ?>">
            <?= te('{n} % de différence avec la référence', ['n' => $drift]) ?></span>
        <?php elseif ($silRef !== ''): ?>
          <span class="badge badge-ok"><?= te('conforme à la référence') ?></span>
        <?php endif; ?>
      </div>
      <div class="sil-pair<?= $silRef === '' ? ' sil-solo' : '' ?>">
        <?php if ($silRef !== ''): ?>
          <figure class="sil">
            <figcaption><?= te('Référence') ?>
              <span class="muted"><?= e(Notifier::when((string)($mon['silhouette_ref_at'] ?? ''))) ?></span>
            </figcaption>
            <div class="sil-view"><?= $silRef /* SVG produit par nous, jamais par le site */ ?></div>
          </figure>
        <?php endif; ?>
        <figure class="sil<?= $drift >= 35 ? ' sil-bad' : '' ?>">
          <figcaption><?= te('Maintenant') ?>
            <span class="muted"><?= e(Notifier::when((string)($mon['silhouette_at'] ?? ''))) ?></span>
          </figcaption>
          <div class="sil-view"><?= $silNow ?></div>
        </figure>
      </div>
      <?php if ($silRef === ''): ?>
        <p class="tiny muted mb0"><?= te('La référence sera mémorisée à la première analyse d\'une page saine.') ?></p>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (!$cssDetail): ?>
    <p class="muted small mb0">
      <?= (int)$mon['check_css'] === 1
        ? t('Analyse en attente : elle a lieu à la prochaine vérification d\'une page HTML répondant en 200.')
        : t('Le contrôle des ressources est désactivé pour cette sonde.') ?>
    </p>
  <?php else: ?>
    <?php if (!empty($cssDetail['messages'])): ?>
      <div class="alert alert-<?= $cs === 'broken' ? 'bad' : 'warn' ?>" style="margin-top:0">
        <?= Ui::icon($cs === 'broken' ? 'alert' : 'clock', 18) ?>
        <div>
          <?php foreach (array_slice($cssDetail['messages'], 0, 6) as $msg): ?>
            <div><?= e($msg) ?></div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="grid-3 mt">
      <div>
        <div class="stat-label"><?= te('Feuilles de style') ?></div>
        <div class="gauge"><b><?= (int)($cm['sheets_ok'] ?? 0) ?>/<?= (int)($cm['sheets_declared'] ?? 0) ?></b>
          <span class="small muted"><?= te('chargées') ?></span></div>
      </div>
      <div>
        <div class="stat-label"><?= te('Scripts') ?></div>
        <div class="gauge"><b><?= (int)($cm['js_ok'] ?? 0) ?>/<?= (int)($cm['js_declared'] ?? 0) ?></b>
          <span class="small muted"><?= te('chargés') ?></span></div>
      </div>
      <div>
        <div class="stat-label"><?= te('Poids CSS') ?></div>
        <div class="gauge"><b><?= e(human_bytes((int)($cm['css_bytes'] ?? 0))) ?></b>
          <?php if (!empty($cssBase['css_bytes'])):
            $d = (int)round((1 - ($cm['css_bytes'] ?? 0) / max(1, (int)$cssBase['css_bytes'])) * 100); ?>
            <span class="small muted"><?= $d > 0 ? '−' . $d . ' %' : ($d < 0 ? '+' . abs($d) . ' %' : 'stable') ?> <?= te('vs référence') ?></span>
          <?php endif; ?>
        </div>
      </div>
      <div>
        <div class="stat-label" title="<?= te('Part des classes utilisées dans la page qui trouvent une règle CSS') ?>"><?= te('Couverture des classes') ?></div>
        <?php $cov = $cm['coverage'] ?? null; ?>
        <?php if ($cov === null): ?><div class="gauge"><b>—</b></div><?php else:
          $pv = (float)$cov * 100; $tone = $pv >= 70 ? '' : ($pv >= 45 ? 'warn' : 'bad'); ?>
          <div class="gauge"><b><?= number_format($pv, 0, ',', ' ') ?> %</b>
            <?php if (($cssBase['coverage'] ?? null) !== null): ?>
              <span class="small muted"><?= te('réf.') ?> <?= number_format((float)$cssBase['coverage'] * 100, 0, ',', ' ') ?> %</span>
            <?php endif; ?></div>
          <div class="meter <?= $tone ?>"><i style="width:<?= (int)min(100, $pv) ?>%"></i></div>
        <?php endif; ?>
      </div>
      <div>
        <div class="stat-label"><?= te('Responsive') ?></div>
        <div class="gauge"><b><?= (int)($cm['media_queries'] ?? 0) ?></b> <span class="small muted"><?= te('media queries') ?></span></div>
      </div>
      <div>
        <div class="stat-label"><?= te('Mise en page') ?></div>
        <div class="gauge"><b><?= (int)($cm['layout_score'] ?? 0) ?></b><span class="small muted">/ 100</span></div>
      </div>
    </div>

    <?php if (!empty($cm['classes_missing'])): ?>
      <p class="tiny muted mt"><?= te('Classes sans règle CSS :') ?> <span class="mono"><?= e(implode(', ', array_slice($cm['classes_missing'], 0, 8))) ?></span></p>
    <?php endif; ?>

    <?php if (!empty($cssDetail['console'])): ?>
      <div class="section-title"><?= te('Ce que le navigateur écrirait dans sa console') ?></div>
      <div class="console"><?php foreach ($cssDetail['console'] as $c): ?><div class="<?= ($c['level'] ?? 'err') === 'err' ? 'c-err' : 'c-warn' ?>"><?= e((string)($c['text'] ?? '')) ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <?php if (!empty($cm['assets'])): ?>
      <div class="section-title"><?= count($cm['assets']) ?> <?= te('ressource(s) analysée(s)') ?></div>
      <div class="res-list">
        <?php foreach ($cm['assets'] as $a):
          $cls = empty($a['issue']) ? 'res-ok' : (!empty($a['soft']) ? 'res-warn' : 'res-bad'); ?>
          <div class="res <?= $cls ?>">
            <span class="res-ico"><?= Ui::icon(empty($a['issue']) ? 'check' : ($a['soft'] ? 'clock' : 'x'), 15) ?></span>
            <span class="grow">
              <span class="badge"><?= e(strtoupper((string)($a['kind'] ?? 'css'))) ?></span>
              <span class="res-url"><?= e(str_cut((string)$a['url'], 110)) ?></span>
              <?php if (!empty($a['issue'])): ?><div class="res-note"><?= e((string)($a['note'] ?? $a['issue'])) ?></div><?php endif; ?>
            </span>
            <span class="res-meta"><?= (int)$a['status'] ?: '—' ?> · <?= e(human_bytes((int)$a['bytes'])) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="row mt small muted" style="flex-wrap:wrap">
      <?php if ($mon['css_baseline_at']): ?>
        <span><?= te('Référence apprise le {date}', ['date' => Notifier::when((string)$mon['css_baseline_at'])]) ?></span>
      <?php endif; ?>
      <form method="post" style="margin-left:auto">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="reset_baseline">
        <input type="hidden" name="id" value="<?= $id ?>">
        <button class="btn btn-sm" title="<?= te('À faire après une refonte volontaire du design') ?>"><?= Ui::icon('refresh', 14) ?> <?= te('Réapprendre la référence') ?></button>
      </form>
    </div>
  <?php endif; ?>
<?= Ui::accClose() ?>

<!-- ====================== SONDE BATTEMENT ====================== -->
<?php if (($mon['kind'] ?? '') === 'heartbeat'):
  echo Ui::accOpen('beat', 'clock', t('Signal attendu'),
        t('à appeler par le script surveillé'), true, 'none',
        $mon['heartbeat_at'] ? Ui::badge(t('dernier signal {when}',
                                   ['when' => human_since((string)$mon['heartbeat_at'])]), 'ok')
                             : Ui::badge(t('aucun signal reçu'), 'warn'));
  echo Ui::accBody();
?>
  <p class="small soft prose" style="margin-top:0"><?= te('Cette sonde n\'interroge rien : elle attend que le script surveillé se signale. Le silence déclenche l\'alerte. C\'est ainsi qu\'on surveille un cron, une sauvegarde ou un import nocturne, ce qu\'aucune requête HTTP ne peut voir.') ?></p>
  <div class="field">
    <label for="beatline"><?= te('Ligne à ajouter à la fin du script surveillé') ?></label>
    <input id="beatline" type="text" readonly onclick="this.select()" value="<?= e(Heartbeat::snippet($mon)) ?>">
    <span class="hint"><?= te('Signal attendu toutes les {interval}, avec une tolérance de {grace}.',
        ['interval' => human_duration((int)$mon['interval_sec']),
         'grace'    => human_duration((int)$mon['heartbeat_grace'])]) ?>
      <?= te('Ajoutez {param} pour joindre un mot (nombre de fichiers, durée…).',
        ['param' => '<span class="mono">&amp;m=texte</span>']) ?></span>
  </div>
<?= Ui::accClose() ?>
<?php endif; ?>

<!-- ====================== CE QUE UPTIMEEZ A DÉCIDÉ ====================== -->
<?php $decisions = Tune::decisions($mon);
if ($decisions && expert()):
  echo Ui::accOpen('decisions', 'info', t('Ce que {app} a décidé toute seule'),
        tn(count($decisions), '1 décision · et pourquoi', '{n} décisions · et pourquoi'));
  echo Ui::accBody(true);
?>
  <table class="tbl"><tbody>
  <?php foreach ($decisions as $d): ?>
    <tr>
      <td class="tiny muted nowrap" style="width:110px"><?= e(date('d/m H:i', strtotime((string)$d['at']))) ?></td>
      <td><strong class="small"><?= e((string)$d['what']) ?></strong>
        <div class="tiny muted"><?= e((string)$d['why']) ?></div></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table>
  <p class="hint" style="padding:10px 16px"><?= te('Ces réglages restent modifiables : toute valeur saisie à la main prend le pas sur la décision automatique.') ?></p>
<?= Ui::accClose() ?>
<?php endif; ?>

<!-- ====================== VITESSE RESSENTIE ====================== -->
<?php
$vit = jdec($mon['vitals_detail'] ?? null);
$fieldVerdict = $mon['field_verdict'] ?? null;
if ($vit || $fieldVerdict !== null):
    $vLevel = (string)($mon['vitals_level'] ?? 'ok');
    $tone = $fieldVerdict === 'poor' || $vLevel === 'bad' ? 'attn'
          : ($fieldVerdict === 'improve' || $vLevel === 'watch' ? 'warn' : 'none');
    // Le badge porte la mesure de terrain quand elle existe, sinon le TTFB
    // mesuré : dans les deux cas un chiffre réel, jamais une estimation.
    if ($fieldVerdict !== null) {
        [$wMetric, $wValue] = Uptimeez\Vitals::worstOf($mon);
        $vBadge = Ui::badge(Uptimeez\Vitals::format((string)$wMetric, $wValue),
            $fieldVerdict === 'poor' ? 'bad' : ($fieldVerdict === 'improve' ? 'warn' : 'ok'));
    } else {
        $tv = (string)($vit['ttfb_verdict'] ?? 'unknown');
        $vBadge = $vit['ttfb_ms'] ?? null
            ? Ui::badge(Ui::ms((int)$vit['ttfb_ms']),
                        $tv === 'poor' ? 'bad' : ($tv === 'improve' ? 'warn' : 'ok'))
            : '';
    }
    echo Ui::accOpen('speed', 'chart', t('Vitesse ressentie par les visiteurs'),
        count((array)($vit['findings'] ?? [])) > 0
            ? tn(count((array)$vit['findings']), 'une cause identifiée', '{n} causes identifiées')
            : t('rien à signaler'),
        $tone === 'attn', $tone, $vBadge);
    echo Ui::accBody();
?>
  <?php if ($fieldVerdict !== null): ?>
    <p class="small soft prose"><?= te('Mesuré sur les visiteurs réels de cette page par le Chrome UX Report, sur les 28 derniers jours.') ?>
      <?php if (($mon['field_source'] ?? '') === 'origin'): ?>
        <?= te('Cette page n\'a pas assez de trafic pour être mesurée seule : les chiffres portent sur l\'ensemble du site.') ?>
      <?php endif; ?></p>
    <dl class="kv vit-kv">
      <?php foreach ([
            ['lcp', t('Affichage du contenu principal'), $mon['field_lcp_ms'] !== null ? (float)$mon['field_lcp_ms'] : null,
             t('Le temps au bout duquel la page paraît chargée au visiteur.')],
            ['inp', t('Réaction au premier clic'), $mon['field_inp_ms'] !== null ? (float)$mon['field_inp_ms'] : null,
             t('Le délai entre le geste du visiteur et la réponse visible de la page.')],
            ['cls', t('Stabilité de la mise en page'), $mon['field_cls'] !== null ? (float)$mon['field_cls'] : null,
             t('À quel point le contenu saute pendant le chargement.')],
          ] as [$key, $label, $value, $help]): ?>
        <dt><?= e($label) ?></dt>
        <dd>
          <?php if ($value === null): ?>
            <span class="muted"><?= te('pas assez de données') ?></span>
          <?php else: $r = Uptimeez\Vitals::rate($key, $value); ?>
            <?= Ui::badge(Uptimeez\Vitals::format($key, $value),
                  $r === 'good' ? 'ok' : ($r === 'improve' ? 'warn' : 'bad')) ?>
            <span class="muted small"><?= te('seuil visé {v}',
                  ['v' => Uptimeez\Vitals::format($key, (float)Uptimeez\Vitals::THRESHOLDS[$key][0])]) ?></span>
          <?php endif; ?>
          <span class="hint"><?= e($help) ?></span>
        </dd>
      <?php endforeach; ?>
    </dl>
  <?php elseif (Uptimeez\Vitals::key() === ''): ?>
    <p class="small soft prose"><?= te('Les trois mesures officielles (LCP, INP, CLS) ne peuvent venir que de vrais navigateurs. Sans clé du Chrome UX Report, {app} n\'affiche aucun chiffre plutôt que d\'en inventer un. Ce qui suit est mesuré sur cette page, et suffit pour agir.') ?>
      <a href="<?= e(u('settings')) ?>#speed"><?= te('Ajouter une clé') ?></a></p>
  <?php endif; ?>

  <?php if (($vit['ttfb_ms'] ?? null) !== null):
    $tv = (string)($vit['ttfb_verdict'] ?? 'unknown'); ?>
    <dl class="kv">
      <dt><?= te('Réponse du serveur') ?></dt>
      <dd><?= Ui::badge(Ui::ms((int)$vit['ttfb_ms']),
                $tv === 'good' ? 'ok' : ($tv === 'improve' ? 'warn' : 'bad')) ?>
        <span class="muted small"><?= te('seuil visé 800 ms') ?></span>
        <span class="hint"><?= te('Mesuré à chaque vérification. Aucun affichage ne peut commencer avant : c\'est le plancher de toutes les autres mesures.') ?></span></dd>
      <?php if ((int)($vit['blocking']['css'] ?? 0) + (int)($vit['blocking']['js'] ?? 0) > 0): ?>
        <dt><?= te('Bloquent le premier affichage') ?></dt>
        <dd><?= e(tn((int)$vit['blocking']['css'], 'une feuille de style', '{n} feuilles de style')) ?>
          · <?= e(tn((int)$vit['blocking']['js'], 'un script', '{n} scripts')) ?>
          <?php if ((int)($vit['blocking']['bytes'] ?? 0) > 0): ?>
            · <?= e(human_bytes((int)$vit['blocking']['bytes'])) ?>
          <?php endif; ?>
          <?php if (!empty($vit['blocking']['items'])): ?>
            <span class="hint"><?php
              $names = array_map(fn($i) => Uptimeez\Check\Vitals::shortUrl((string)$i['url']),
                                 array_slice((array)$vit['blocking']['items'], 0, 4));
              echo e(implode(' · ', $names)); ?></span>
          <?php endif; ?></dd>
      <?php endif; ?>
      <?php if (!empty($vit['lcp_image']['url'])): ?>
        <dt><?= te('Image du haut de page') ?></dt>
        <dd><?= e(Uptimeez\Check\Vitals::shortUrl((string)$vit['lcp_image']['url'])) ?>
          <?php if (!empty($vit['lcp_image']['bytes'])): ?>
            · <?= e(human_bytes((int)$vit['lcp_image']['bytes'])) ?>
          <?php endif; ?>
          <?php if (!empty($vit['lcp_image']['lazy'])): ?>
            <?= Ui::badge(t('chargement différé'), 'bad') ?>
          <?php endif; ?>
          <span class="hint"><?= te('C\'est très probablement l\'élément que la mesure d\'affichage principal retient.') ?></span></dd>
      <?php endif; ?>
    </dl>
  <?php endif; ?>

  <?php if (!empty($vit['findings'])): ?>
    <h3 class="mt"><?= te('Ce qui ralentit cette page') ?></h3>
    <p class="small soft prose"><?= te('Lu dans le HTML et les fichiers déjà téléchargés. Ce sont des causes probables, classées par impact : rien ici n\'est une mesure de navigateur.') ?></p>
    <ul class="vit-list">
      <?php foreach ((array)$vit['findings'] as $f): ?>
        <li class="vit-f vit-<?= e((string)$f['severity']) ?>">
          <strong><?= e(t((string)$f['what'], (array)($f['vars'] ?? []))) ?></strong>
          <span class="vit-why"><?= e(t((string)$f['why'])) ?></span>
          <span class="vit-fix"><?= Ui::icon('wrench', 14) ?> <?= e(t((string)$f['fix'])) ?></span>
          <?php if (($f['evidence'] ?? '') !== ''): ?>
            <span class="vit-ev mono"><?= e((string)$f['evidence']) ?></span>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
<?php
    echo Ui::accClose();
endif;
?>

<!-- ====================== INVENTAIRE ET FAILLES ====================== -->
<?php
$comps = !empty($mon['site_id']) ? Uptimeez\Vuln::forSite((int)$mon['site_id']) : [];
if ($comps):
    $nVuln = 0; $nOld = 0; $worst = null;
    foreach ($comps as $c) {
        if ((int)$c['vuln_count'] > 0) $nVuln++;
        if ((int)$c['outdated'] === 1) $nOld++;
        if (($c['worst'] ?? '') === 'high') $worst = 'high';
        elseif ($c['worst'] !== null && $worst === null) $worst = (string)$c['worst'];
    }
    $stackBadge = $nVuln > 0
        ? Ui::badge(tn($nVuln, 'une faille publiée', '{n} failles publiées'), $worst === 'high' ? 'bad' : 'warn')
        : ($nOld > 0 ? Ui::badge(tn($nOld, 'une version en retard', '{n} versions en retard'), 'warn')
                     : Ui::badge(t('rien à signaler'), 'ok'));
    echo Ui::accOpen('stack', 'shield', t('Logiciels et failles connues'),
        tn(count($comps), 'un composant détecté', '{n} composants détectés'),
        $nVuln > 0, $nVuln > 0 ? ($worst === 'high' ? 'attn' : 'warn') : 'none', $stackBadge);
    echo Ui::accBody();
?>
  <p class="small soft prose mb0">
    <?= te('Les versions sont lues dans le HTML déjà reçu, sans rien demander de plus au site.') ?><?= hint('Deux signaux qui ne se mélangent pas. « Faille publiée » veut dire qu\'un avis de sécurité identifié couvre précisément cette version : l\'identifiant et le lien sont donnés. « Version en retard » veut dire que la version installée est antérieure à la dernière publiée, ce qui est une dette, pas une faille.') ?>
  </p>
  <div class="table-scroll mt"><table class="tbl">
    <thead><tr>
      <th><?= te('Composant') ?></th><th><?= te('Version') ?></th>
      <th><?= te('Dernière') ?></th><th><?= te('Sécurité') ?></th><th><?= te('Vérifié') ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($comps as $c): ?>
      <tr>
        <td>
          <strong><?= e((string)$c['name']) ?></strong>
          <?= Ui::badge(match ((string)$c['kind']) {
                'core' => t('cœur'), 'theme' => t('thème'), default => t('extension') },
                (string)$c['kind'] === 'core' ? 'info' : 'neutral') ?>
          <div class="tiny muted mono"><?= e((string)$c['slug']) ?></div>
        </td>
        <td class="mono small"><?= $c['version'] !== null ? e((string)$c['version'])
              : '<span class="muted">' . te('non lisible') . '</span>' ?></td>
        <td class="mono small">
          <?php if ($c['latest'] === null): ?><span class="muted">—</span>
          <?php elseif ((int)$c['outdated'] === 1): ?>
            <span class="v-warn"><?= e((string)$c['latest']) ?></span>
          <?php else: ?><span class="v-ok"><?= e((string)$c['latest']) ?></span><?php endif; ?>
        </td>
        <td>
          <?php $adv = jdec($c['advisories'] ?? null);
          if ($adv): ?>
            <?= Ui::badge(tn((int)$c['vuln_count'], 'une faille', '{n} failles')
                  . ' · ' . Uptimeez\Vuln::severityLabel($c['worst'] !== null ? (string)$c['worst'] : null),
                  ($c['worst'] ?? '') === 'high' ? 'bad' : 'warn') ?>
            <ul class="adv-list">
              <?php foreach (array_slice($adv, 0, 4) as $a): ?>
                <li>
                  <?php if (!empty($a['url'])): ?>
                    <a href="<?= e((string)$a['url']) ?>" target="_blank" rel="noopener noreferrer">
                      <?= e((string)($a['id'] ?? '')) ?></a>
                  <?php else: ?><span class="mono"><?= e((string)($a['id'] ?? '')) ?></span><?php endif; ?>
                  <?php if (!empty($a['published'])): ?>
                    <span class="muted tiny"><?= e((string)$a['published']) ?></span><?php endif; ?>
                  <?php if (!empty($a['summary'])): ?>
                    <div class="tiny muted clamp2"><?= e(str_cut((string)$a['summary'], 130)) ?></div>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php elseif ((int)$c['outdated'] === 1): ?>
            <?= Ui::badge(t('version en retard'), 'warn') ?>
          <?php elseif ($c['checked_at'] !== null): ?>
            <span class="v-ok small"><?= te('aucun avis connu') ?></span>
          <?php else: ?>
            <span class="muted small"><?= te('pas encore vérifié') ?></span>
          <?php endif; ?>
        </td>
        <td class="small nowrap muted"><?= $c['checked_at']
              ? e(Notifier::when((string)$c['checked_at'])) : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
<?php
    echo Ui::accClose();
endif;
?>

<!-- ====================== CERTIFICAT, DOMAINE, SERVEUR ====================== -->
<?php
$sslNote = $sslDays === null ? t('non mesuré')
    : ($sslDays < 0 ? t('certificat expiré') : tn($sslDays, 'certificat valable un jour', 'certificat valable {n} jours'));
$sslTone = $sslDays !== null && $sslDays < 0 ? 'attn' : ($sslDays !== null && $sslDays <= (int)$mon['ssl_warn_days'] ? 'warn' : 'none');
echo Ui::accOpen('infra', 'shield', t('Certificat, domaine et serveur'), $sslNote, false, $sslTone);
echo Ui::accBody();
?>
  <dl class="kv">
    <dt><?= te('Certificat SSL') ?></dt>
    <dd><?php
      if ($sslDays === null) echo '<span class="muted">' . te('non mesuré') . '</span>';
      else {
          $tone = $sslDays < 0 ? 'bad' : ($sslDays <= (int)$mon['ssl_warn_days'] ? 'warn' : 'ok');
          echo Ui::badge($sslDays < 0 ? t('expiré') : $sslDays . ' jours restants', $tone);
          if ($mon['ssl_expires_at']) echo ' <span class="muted small">'
              . te('échéance {date}', ['date' => date('d/m/Y', strtotime((string)$mon['ssl_expires_at']))]) . '</span>';
      }
      if ($mon['ssl_issuer']) echo '<span class="hint">'
              . te('Émis par {issuer}', ['issuer' => (string)$mon['ssl_issuer']]) . '</span>';
    ?></dd>

    <dt><?= te('Nom de domaine') ?></dt>
    <dd><?php if ($mon['domain_expires_at']):
          $dd = (int)floor((strtotime((string)$mon['domain_expires_at']) - time()) / 86400);
          echo Ui::badge($dd . ' jours', $dd < 30 ? 'warn' : 'neutral')
             . ' <span class="muted small">'
                . te('expire le {date}', ['date' => date('d/m/Y', strtotime((string)$mon['domain_expires_at']))])
                . '</span>';
        else: ?><span class="muted"><?= te('vérification RDAP quotidienne, pas encore effectuée') ?></span><?php endif; ?></dd>

    <dt><?= te('Technologie') ?></dt>
    <dd><?= $mon['site_cms'] ? e((string)$mon['site_cms']) : '<span class="muted">' . te('non identifiée') . '</span>' ?><?php
      if (!empty($cmsDetail['builder'])) echo ' · ' . e((string)$cmsDetail['builder']);
      if (!empty($cmsDetail['theme']))   echo ' <span class="muted small">'
              . te('thème {name}', ['name' => (string)$cmsDetail['theme']]) . '</span>';
      if (!empty($cmsDetail['server']) || !empty($cmsDetail['cache'])) {
          echo '<span class="hint">' . e(trim((string)($cmsDetail['server'] ?? '')))
             . (!empty($cmsDetail['cache']) ? ' · cache ' . e((string)$cmsDetail['cache']) : '') . '</span>';
      }
    ?></dd>

    <dt><?= te('Chaîne de contrôle') ?></dt>
    <dd><?php if ($mon['expect_string']): ?>
          <span class="mono small">« <?= e(str_cut((string)$mon['expect_string'], 70)) ?> »</span>
          <span class="hint"><?= te('Sa présence prouve que le serveur web') ?> <em>et</em> <?= te('la base répondent.') ?></span>
        <?php else: ?><?= Ui::badge(t('aucune'), 'warn') ?>
          <span class="hint"><?= te('Sans elle, une page vide renvoyant 200 passerait pour valide.') ?></span>
        <?php endif; ?></dd>

    <?php if ($mon['watch_string']): ?>
      <dt><?= te('Texte surveillé') ?></dt>
      <dd><span class="mono small">« <?= e(str_cut((string)$mon['watch_string'], 60)) ?> »</span>
        <?= Ui::badge($mon['watch_state'] === 'present' ? t('présent') : t('absent'),
                        $mon['watch_state'] === 'present' ? 'ok' : 'neutral') ?>
        <span class="hint"><?= te('Alerte quand le texte') ?> <?= $mon['watch_mode'] === 'disappear' ? 'disparaît' : 'apparaît' ?>.</span></dd>
    <?php endif; ?>

    <dt><?= te('Seuil de lenteur') ?></dt>
    <dd><?= Ui::ms((int)$mon['slow_ms']) ?>
      <?php if ((int)($mon['auto_slow'] ?? 1) === 1): ?>
        <?= Ui::badge(t('ajusté automatiquement'), 'info') ?>
        <span class="hint"><?= empty($mon['tuned_at'])
          ? te('Recalculé sur le p95 mesuré de cette sonde.')
          : te('Recalculé sur le p95 mesuré de cette sonde, dernière fois {when}.',
               ['when' => human_since((string)$mon['tuned_at'])]) ?></span>
      <?php endif; ?></dd>

    <dt><?= te('Cadence') ?></dt>
    <dd><?= te('toutes les {interval}', ['interval' => human_duration((int)$mon['interval_sec'])]) ?>,
        <?= tne((int)$mon['retries'], 'une relance avant alerte', '{n} relances avant alerte') ?>
      <?php if ($mon['maintenance']): ?><span class="hint"><?= te('Fenêtre de maintenance :') ?> <?= e((string)$mon['maintenance']) ?></span><?php endif; ?>
      <span class="hint"><?= te('Prochaine passe') ?> <?= $mon['next_check_at'] ? e(date(t('d/m à H:i:s'), strtotime((string)$mon['next_check_at']))) : '—' ?></span></dd>

    <?php if ($mon['setup_note']): ?>
      <dt><?= te('Détection auto') ?></dt><dd class="small soft"><?= e((string)$mon['setup_note']) ?></dd>
    <?php endif; ?>
  </dl>
<?= Ui::accClose() ?>

<!-- ====================== INCIDENTS ====================== -->
<?php
$openCount = 0;
foreach ($incidents as $i) if (!$i['ended_at']) $openCount++;
echo Ui::accOpen('incidents', 'history', t('Incidents'),
    $incidents ? tn(count($incidents), '1 sur les dernières semaines', '{n} sur les dernières semaines')
               : t('aucun incident enregistré'),
    false, 'none', $openCount ? Ui::badge(tn($openCount, '1 en cours', '{n} en cours'), 'bad') : '');
echo Ui::accBody(true);
?>
  <?php if (!$incidents): ?>
    <div class="empty small"><?= te('Aucun incident enregistré pour cette sonde.') ?></div>
  <?php else: ?>
    <div class="table-scroll"><table class="tbl">
      <thead><tr><th><?= te('Début') ?></th><th><?= te('Cause') ?></th><th class="num"><?= te('Durée') ?></th><th class="num"><?= te('Alertes') ?></th><th></th></tr></thead>
      <tbody>
      <?php foreach ($incidents as $inc): ?>
        <tr>
          <td class="small nowrap"><?= e(date('d/m/y H:i', strtotime((string)$inc['started_at']))) ?></td>
          <td><?= Ui::reasonBadge((string)$inc['reason_code']) ?>
            <div class="tiny muted clamp2"><?= e(str_cut((string)$inc['message'], 120)) ?></div></td>
          <td class="num small nowrap"><?= $inc['ended_at'] ? e(human_duration((int)$inc['duration_sec']))
              : '<span class="v-bad">' . e(human_duration(max(0, time() - strtotime((string)$inc['started_at'])))) . '</span>' ?></td>
          <td class="num small"><?= (int)$inc['notify_count'] ?></td>
          <td class="num"><?php if (!$inc['ended_at']): ?>
            <form method="post" style="display:inline">
              <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
              <input type="hidden" name="action" value="<?= $inc['ack_at'] ? 'close_incident' : 'ack_incident' ?>">
              <input type="hidden" name="id" value="<?= (int)$inc['id'] ?>">
              <button class="btn btn-sm btn-ghost"><?= $inc['ack_at'] ? 'Clore' : 'Pris en compte' ?></button>
            </form>
          <?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="row" style="padding:10px 16px">
      <a class="small" href="<?= e(u('incidents', ['id' => $id])) ?>"><?= te('Voir tout l\'historique de cette sonde') ?></a>
    </div>
  <?php endif; ?>
<?= Ui::accClose() ?>

<!-- ====================== DERNIÈRES VÉRIFICATIONS ====================== -->
<?php if (expert()):

echo Ui::accOpen('checks', 'clock', t('Dernières vérifications'),
    $recent ? tn(count($recent), 'la dernière mesure, en détail', 'les {n} dernières mesures, en détail')
            : t('aucune mesure'));
echo Ui::accBody(true);
?>
  <div class="table-scroll"><table class="tbl">
    <thead><tr><th><?= te('Heure') ?></th><th><?= te('État') ?></th><th class="num"><?= te('Code') ?></th><th class="num"><?= te('Total') ?></th>
      <th class="num"><?= te('DNS') ?></th><th class="num"><?= te('TLS') ?></th><th class="num"><?= te('1er octet') ?></th><th><?= te('Message') ?></th></tr></thead>
    <tbody>
    <?php foreach ($recent as $c): ?>
      <tr>
        <td class="small nowrap"><?= e(date('d/m H:i:s', strtotime((string)$c['ts']))) ?></td>
        <td><?= Ui::dot((string)$c['state']) ?></td>
        <td class="num small"><?= (int)$c['status_code'] ?: '—' ?></td>
        <td class="num small"><?= Ui::ms($c['total_ms'] !== null ? (int)$c['total_ms'] : null) ?></td>
        <td class="num tiny muted"><?= Ui::ms($c['dns_ms'] !== null ? (int)$c['dns_ms'] : null) ?></td>
        <td class="num tiny muted"><?= Ui::ms($c['tls_ms'] !== null ? (int)$c['tls_ms'] : null) ?></td>
        <td class="num tiny muted"><?= Ui::ms($c['ttfb_ms'] !== null ? (int)$c['ttfb_ms'] : null) ?></td>
        <td class="tiny muted clamp2" style="max-width:320px"><?= e(str_cut((string)$c['message'], 90)) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$recent): ?><tr><td colspan="8" class="muted small" style="padding:18px">
      <?= te('Aucune mesure. Cliquez sur « Vérifier » en haut de page.') ?></td></tr><?php endif; ?>
    </tbody>
  </table></div>
<?= Ui::accClose() ?>

<!-- ====================== ÉVÈNEMENTS ====================== -->
<?php if ($events): ?>
<?= Ui::accOpen('events', 'bell', t('Évènements de contenu'),
      tn(count($events), '1 récent', '{n} récents')) ?>
<?= Ui::accBody(true) ?>
  <table class="tbl"><tbody>
    <?php foreach ($events as $ev): ?>
      <tr><td class="small nowrap" style="width:130px"><?= e(date('d/m H:i', strtotime((string)$ev['ts']))) ?></td>
          <td style="width:190px"><?= Ui::badge(Notifier::eventLabel((string)$ev['kind']), 'info') ?></td>
          <td class="small"><?= e((string)$ev['message']) ?></td></tr>
    <?php endforeach; ?>
  </tbody></table>
<?= Ui::accClose() ?>
<?php endif; ?>

<!-- ====================== AUTRES SONDES DU SITE ====================== -->
<?php if ($siblings): ?>
<?php
$badSib = 0;
foreach ($siblings as $s) if (in_array($s['status'], ['down', 'degraded'], true)) $badSib++;
echo Ui::accOpen('siblings', 'grid', t('Autres sondes du même site'),
    tn(count($siblings), '1 sonde', '{n} sondes'),
    false, 'none', $badSib ? Ui::badge(tn($badSib, '1 en défaut', '{n} en défaut'), 'bad') : '');
echo Ui::accBody(true);
?>
  <table class="tbl"><tbody>
  <?php foreach ($siblings as $s): ?>
    <tr>
      <td style="width:26px"><?= Ui::dot((string)$s['status']) ?></td>
      <td><a href="<?= e(u('monitor', ['id' => (int)$s['id']])) ?>"><?= e((string)$s['name']) ?></a>
        <?php if ($s['role'] === 'primary'): ?> <?= Ui::badge(t('principale'), 'info') ?><?php endif; ?>
        <div class="tiny muted truncate"><?= e(str_cut((string)$s['url'], 80)) ?></div></td>
      <td class="num small"><?= Ui::ms($s['last_ms'] !== null ? (int)$s['last_ms'] : null) ?></td>
      <td class="num"><span class="badge badge-<?= Ui::uptimeTone($s['uptime_24h'] !== null ? (float)$s['uptime_24h'] : null) ?>">
        <?= Ui::pct($s['uptime_24h'] !== null ? (float)$s['uptime_24h'] : null, 1) ?></span></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table>
<?= Ui::accClose() ?>
<?php endif; ?>
<?php endif; /* expert */ ?>

<!-- ====================== RÉGLAGES ====================== -->
<?= Ui::accOpen('settings', 'sliders', t('Réglages de la sonde'), t('nom, cadence, contrôles, alertes')) ?>
<?= Ui::accBody() ?>
  <form method="post" data-dirty-watch>
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="action" value="save_monitor">
    <input type="hidden" name="id" value="<?= $id ?>">
    <?php require __DIR__ . '/partials/monitor_form.php'; ?>
    <div class="savebar" data-savebar hidden>
      <span class="sb-note"><?= Ui::icon('info', 15) ?> <?= te('Modifications non enregistrées') ?></span>
      <span class="grow"></span>
      <button type="button" class="btn btn-sm" data-reset-form><?= te('Annuler') ?></button>
      <button class="btn btn-primary btn-sm"><?= te('Enregistrer') ?></button>
    </div>
    <div class="row mt" data-static-save>
      <button class="btn btn-primary"><?= te('Enregistrer') ?></button>
      <a class="btn" href="<?= e(u('dashboard')) ?>"><?= te('Retour') ?></a>
    </div>
  </form>
<?= Ui::accClose() ?>

<!-- ====================== ZONE SENSIBLE ====================== -->
<?= Ui::accOpen('danger', 'trash', t('Supprimer cette sonde'), t('action irréversible')) ?>
<?= Ui::accBody() ?>
  <p class="small soft prose"><?= te('La sonde, son historique de mesures, ses incidents et ses évènements seront définitivement supprimés. Si vous voulez seulement arrêter la surveillance, utilisez le bouton') ?>
    <strong><?= te('Pause') ?></strong> <?= te('en haut de page.') ?></p>
  <form method="post" onsubmit="return confirm('<?= e(addslashes(t('Supprimer définitivement « {name} » et tout son historique ?', ['name' => (string)$mon['name']]))) ?>')">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="action" value="delete_monitor">
    <input type="hidden" name="id" value="<?= $id ?>">
    <button class="btn btn-danger btn-sm"><?= Ui::icon('trash', 14) ?> <?= te('Supprimer définitivement') ?></button>
  </form>
<?= Ui::accClose() ?>
