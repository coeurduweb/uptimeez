<?php
/**
 * Espace client, en lecture seule.
 *
 * Ce que le client veut savoir, dans cet ordre : est-ce que mon site marche,
 * est-ce qu'il a marché ce mois-ci, et quand est-ce qu'il ne marchait pas. Rien
 * d'autre n'a sa place ici : aucun réglage, aucun bouton, aucun vocabulaire de
 * supervision, et surtout aucun site qui ne lui appartient pas.
 *
 * Les variables $client et $clientOverview sont fournies par index.php après
 * validation du jeton. Cette page ne lit jamais un identifiant de l'URL : elle
 * ne peut donc pas être détournée pour afficher les sites d'un autre.
 */
use Uptimer\Client;
use Uptimer\Config;
use Uptimer\Db;
use Uptimer\Notify\Notifier;
use Uptimer\Stats;
use Uptimer\Ui;

/** @var array $client */
/** @var array $clientOverview */
$cid   = (int)$client['id'];
$ov    = $clientOverview;
$sites = Client::sites($cid);
$monIds = array_values(array_filter(array_map(fn($s) => (int)($s['monitor_id'] ?? 0), $sites)));
$sparks = $monIds ? Stats::sparkBatch($monIds, 86400, 48) : [];
$incidents = Client::incidents($cid, 12);
$appName = (string)Config::get('app.name', 'Uptimer');
?>

<div class="cli-head">
  <h1><?= e((string)$client['name']) ?></h1>
  <p><?= te('État de vos sites · mis à jour {when}',
        ['when' => human_since((string)Db::setting('last_run_at'))]) ?></p>
</div>

<section class="band <?= $ov['down'] ? 'band-bad' : ($ov['degraded'] ? 'band-warn' : 'band-ok') ?>">
  <div class="band-icon"><?= $ov['down'] ? '✕' : ($ov['degraded'] ? '!' : '✓') ?></div>
  <div class="grow">
    <div class="band-title">
      <?php if ($ov['down']): ?>
        <?= tne((int)$ov['down'], 'Un de vos sites ne répond pas', '{n} de vos sites ne répondent pas') ?>
      <?php elseif ($ov['degraded']): ?>
        <?= te('Vos sites répondent, avec un point à surveiller') ?>
      <?php else: ?>
        <?= te('Tout fonctionne') ?>
      <?php endif; ?>
    </div>
    <div class="band-sub">
      <?= tne(count($sites), 'un site surveillé', '{n} sites surveillés') ?>
      <?php if ($ov['uptime'] !== null): ?>
        · <?= te('disponibilité moyenne {pct} sur 30 jours', ['pct' => Ui::pct($ov['uptime'], 2)]) ?>
      <?php endif; ?>
    </div>
  </div>
</section>

<div class="panel">
  <div class="panel-body tight">
    <?php foreach ($sites as $s):
      $mid = (int)($s['monitor_id'] ?? 0);
      $paused = (int)($s['enabled'] ?? 1) !== 1; ?>
      <div class="cli-site">
        <div><?= Ui::dot($paused ? 'paused' : (string)($s['status'] ?? 'unknown')) ?></div>
        <div>
          <div class="cli-name"><?= e((string)$s['name']) ?></div>
          <div class="cli-dom"><?= e((string)($s['domain'] ?: host_of((string)($s['url'] ?? '')))) ?></div>
        </div>
        <div><?= $mid ? Ui::sparkline($sparks[$mid] ?? [], 220, 26) : '' ?></div>
        <div class="num">
          <span class="badge badge-<?= Ui::uptimeTone($s['uptime_30d'] !== null ? (float)$s['uptime_30d'] : null) ?>">
            <?= Ui::pct($s['uptime_30d'] !== null ? (float)$s['uptime_30d'] : null, 2) ?></span>
          <div class="tiny muted"><?= te('30 jours') ?></div>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$sites): ?>
      <div class="empty">
        <p class="soft"><?= te('Aucun site n\'est encore rattaché à votre espace.') ?></p>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($incidents): ?>
  <h2 class="mt"><?= te('Interruptions récentes') ?></h2>
  <p class="soft small"><?= te('Une interruption est comptée quand le site n\'a pas répondu à plusieurs vérifications consécutives. Les à-coups d\'une seule mesure ne figurent pas ici.') ?></p>
  <div class="panel">
    <div class="panel-body tight">
      <div class="table-scroll">
        <table class="tbl">
          <thead><tr>
            <th><?= te('Site') ?></th>
            <th><?= te('Début') ?></th>
            <th class="num"><?= te('Durée') ?></th>
            <th><?= te('État') ?></th>
          </tr></thead>
          <tbody>
          <?php foreach ($incidents as $i):
            $start = strtotime((string)$i['started_at']) ?: time();
            $end   = $i['ended_at'] ? (strtotime((string)$i['ended_at']) ?: time()) : null; ?>
            <tr>
              <td><?= e((string)($i['site_name'] ?: $i['monitor_name'])) ?></td>
              <td class="small nowrap"><?= e(Notifier::when((string)$i['started_at'])) ?></td>
              <td class="num small nowrap"><?= e(human_duration(($end ?? time()) - $start)) ?></td>
              <td class="small">
                <?= $end === null
                      ? Ui::badge(t('en cours'), 'bad')
                      : Ui::badge(t('rétabli'), 'ok') ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
<?php endif; ?>

<p class="cli-foot">
  <?= te('Les barres représentent les 24 dernières heures.') ?>
  <?= te('Cette page se consulte, elle ne se modifie pas : personne ne peut rien changer à votre surveillance depuis ce lien.') ?>
  <br><?= te('Surveillance assurée avec {app}.', ['app' => $appName]) ?>
</p>
