<?php
/**
 * Mode agence : les clients, et le lien en lecture seule de chacun.
 *
 * Un écran, une idée : à qui appartient quoi, et quel lien envoyer. Tout le
 * reste (états, incidents, rapports) vit déjà ailleurs et n'est pas redit ici.
 */
use Uptimer\Auth;
use Uptimer\Client;
use Uptimer\Db;
use Uptimer\Ui;

$csrf    = Auth::csrf();
$clients = Client::all();
$orphans = Client::orphanSites();
$allSites = Db::all('SELECT s.id, s.name, s.domain, s.client_id,
                            (SELECT COUNT(*) FROM monitors m WHERE m.site_id = s.id) AS n
                     FROM sites s ORDER BY s.name ASC');
$hasGroups = (int)Db::val("SELECT COUNT(*) FROM sites
                           WHERE client_id IS NULL AND group_name IS NOT NULL AND group_name <> ''");
?>

<div class="page-head">
  <div>
    <h1><?= te('Clients') ?></h1>
    <p class="soft"><?= te('Chaque client reçoit un lien qui montre ses sites, et rien d\'autre. Pas de compte à créer, pas de mot de passe à transmettre, et le lien se coupe en un clic.') ?></p>
  </div>
</div>

<?php if (!$clients): ?>
  <section class="panel">
    <div class="panel-body">
      <div class="empty">
        <?= Ui::icon('users', 30) ?>
        <h2><?= te('Aucun client pour l\'instant') ?></h2>
        <p class="soft"><?= te('Un client, c\'est un nom et une liste de sites. Il obtient une page en lecture seule sur laquelle il voit l\'état de ses sites, leur disponibilité et leurs incidents. Il ne peut rien modifier, et il ne voit jamais les sites des autres.') ?></p>
        <?php if ($hasGroups): ?>
          <form method="post" class="mt">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="client_from_groups">
            <button class="btn btn-primary"><?= Ui::icon('layers', 16) ?>
              <?= tne($hasGroups, 'Créer les clients depuis le groupe existant',
                                  'Créer les clients depuis les {n} sites déjà groupés') ?></button>
            <span class="hint"><?= te('Reprend le groupe saisi à l\'import : rien n\'est écrasé, et vous pouvez tout renommer ensuite.') ?></span>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </section>
<?php endif; ?>

<?php if ($clients): ?>
  <div class="table-scroll">
    <table class="tbl">
      <thead><tr>
        <th><?= te('Client') ?></th>
        <th><?= te('Sites') ?></th>
        <th class="num"><?= te('Disponibilité 30 j') ?></th>
        <th class="num"><?= te('Lien consulté') ?></th>
        <th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($clients as $c): $ov = $c['overview']; ?>
        <tr>
          <td>
            <?= Ui::dot($ov['worst']) ?>
            <strong><?= e((string)$c['name']) ?></strong>
            <?php if ((int)$c['enabled'] !== 1): ?>
              <?= Ui::badge(t('accès fermé'), 'neutral') ?>
            <?php endif; ?>
            <?php if (($c['contact_email'] ?? '') !== ''): ?>
              <div class="tiny muted"><?= e((string)$c['contact_email']) ?></div>
            <?php endif; ?>
          </td>
          <td class="small">
            <?= tne((int)$ov['sites'], 'un site', '{n} sites') ?>
            <?php if ($ov['down']): ?>
              · <span class="txt-bad"><?= tne((int)$ov['down'], 'un hors service', '{n} hors service') ?></span>
            <?php elseif ($ov['degraded']): ?>
              · <span class="txt-warn"><?= te('à surveiller') ?></span>
            <?php endif; ?>
          </td>
          <td class="num">
            <span class="badge badge-<?= Ui::uptimeTone($ov['uptime']) ?>"><?= Ui::pct($ov['uptime'], 2) ?></span>
          </td>
          <td class="num small nowrap">
            <?= $c['last_seen_at'] ? e(human_since((string)$c['last_seen_at'])) : '<span class="muted">' . te('jamais') . '</span>' ?>
            <?php if ((int)$c['views'] > 0): ?>
              <div class="tiny muted"><?= tne((int)$c['views'], 'une visite', '{n} visites') ?></div>
            <?php endif; ?>
          </td>
          <td class="num nowrap">
            <a class="btn btn-sm" href="<?= e(u('client', ['k' => (string)$c['token']])) ?>" target="_blank"
               rel="noreferrer"><?= Ui::icon('eye', 15) ?> <?= te('Voir son espace') ?></a>
          </td>
        </tr>
        <tr>
          <td colspan="5" class="cell-form">
            <?= Ui::accOpen('client-' . (int)$c['id'], 'sliders', t('Réglages de {name}', ['name' => (string)$c['name']]),
                  tn((int)$ov['sites'], 'un site rattaché', '{n} sites rattachés')) ?>
            <?= Ui::accBody() ?>
              <form method="post" data-dirty-watch>
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="client_save">
                <input type="hidden" name="client_id" value="<?= (int)$c['id'] ?>">
                <div class="grid-3">
                  <div class="field">
                    <label for="cn<?= (int)$c['id'] ?>"><?= te('Nom du client') ?></label>
                    <input id="cn<?= (int)$c['id'] ?>" type="text" name="client_name"
                           value="<?= e((string)$c['name']) ?>">
                  </div>
                  <div class="field">
                    <label for="ce<?= (int)$c['id'] ?>"><?= te('Adresse de contact') ?></label><?= hint('Sert de destinataire au rapport mensuel des sites de ce client qui n\'ont pas d\'adresse propre. Aucune alerte n\'y est envoyée.') ?>
                    <input id="ce<?= (int)$c['id'] ?>" type="text" name="client_email" spellcheck="false"
                           value="<?= e((string)($c['contact_email'] ?? '')) ?>"
                           placeholder="<?= te('client@exemple.fr') ?>">
                  </div>
                  <div class="field">
                    <label for="cnt<?= (int)$c['id'] ?>"><?= te('Notes internes') ?></label><?= hint('Pour vous seulement : le client ne voit jamais ce champ.') ?>
                    <input id="cnt<?= (int)$c['id'] ?>" type="text" name="client_notes"
                           value="<?= e((string)($c['notes'] ?? '')) ?>">
                  </div>
                </div>

                <label class="switchrow"><input type="checkbox" name="client_enabled"
                       <?= (int)$c['enabled'] === 1 ? 'checked' : '' ?>>
                  <span class="sw-text"><span class="sw-title"><?= te('Accès ouvert') ?></span>
                    <span class="hint"><?= te('Décoché, le lien renvoie une page introuvable sans rien perdre : ni le lien, ni l\'historique.') ?></span></span></label>

                <fieldset class="fs">
                  <legend><?= te('Sites de ce client') ?></legend>
                  <p class="hint"><?= te('Un site appartient à un seul client. Le décocher ici le laisse simplement sans client.') ?></p>
                  <div class="checkgrid">
                    <?php foreach ($allSites as $st):
                      $mine  = (int)($st['client_id'] ?? 0) === (int)$c['id'];
                      $taken = !$mine && (int)($st['client_id'] ?? 0) > 0; ?>
                      <label class="checkrow<?= $taken ? ' is-taken' : '' ?>">
                        <input type="checkbox" name="sites[]" value="<?= (int)$st['id'] ?>"
                               <?= $mine ? 'checked' : '' ?> <?= $taken ? 'disabled' : '' ?>>
                        <span><?= e((string)$st['name']) ?>
                          <span class="tiny muted"><?= e((string)$st['domain']) ?></span>
                          <?php if ($taken): ?><span class="tiny muted"> · <?= te('déjà rattaché ailleurs') ?></span><?php endif; ?>
                        </span>
                      </label>
                    <?php endforeach; ?>
                  </div>
                </fieldset>

                <div class="savebar" data-savebar hidden>
                  <span class="sb-note"><?= Ui::icon('info', 15) ?> <?= te('Modifications non enregistrées') ?></span>
                  <span class="grow"></span>
                  <button type="button" class="btn btn-sm" data-reset-form><?= te('Annuler') ?></button>
                  <button class="btn btn-primary btn-sm"><?= te('Enregistrer') ?></button>
                </div>
                <div class="row mt" data-static-save>
                  <button class="btn btn-primary"><?= te('Enregistrer') ?></button>
                </div>
              </form>

              <div class="field mt">
                <label for="cl<?= (int)$c['id'] ?>"><?= te('Lien à envoyer au client') ?></label><?= hint('Ce lien vaut mot de passe : il ouvre l\'espace sans authentification. La page interdit son indexation et n\'envoie aucun référent aux sites qu\'elle met en lien.') ?>
                <input id="cl<?= (int)$c['id'] ?>" type="text" class="mono" readonly
                       value="<?= e(Client::url($c)) ?>" onclick="this.select()" spellcheck="false">
              </div>

              <div class="row mt">
                <form method="post" onsubmit="return confirm(<?= e(json_encode(t('Changer le lien coupera immédiatement celui que le client possède. Continuer ?'))) ?>)">
                  <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                  <input type="hidden" name="action" value="client_rotate">
                  <input type="hidden" name="client_id" value="<?= (int)$c['id'] ?>">
                  <button class="btn btn-sm"><?= Ui::icon('key', 15) ?> <?= te('Changer le lien') ?></button>
                </form>
                <span class="hint grow"><?= te('À faire si le lien a circulé plus loin que prévu.') ?></span>
                <form method="post" onsubmit="return confirm(<?= e(json_encode(t('Supprimer ce client ? Ses sites et tout leur historique sont conservés.'))) ?>)">
                  <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                  <input type="hidden" name="action" value="client_delete">
                  <input type="hidden" name="client_id" value="<?= (int)$c['id'] ?>">
                  <button class="btn btn-sm btn-danger"><?= Ui::icon('trash', 15) ?> <?= te('Supprimer') ?></button>
                </form>
              </div>
            <?= Ui::accClose() ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<section class="panel mt">
  <?= Ui::accOpen('client-new', 'plus', t('Ajouter un client'),
        t('un nom suffit, le lien est généré')) ?>
  <?= Ui::accBody() ?>
    <form method="post" class="row">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="client_create">
      <div class="field" style="flex:1 1 260px">
        <label for="ncn"><?= te('Nom du client') ?></label>
        <input id="ncn" type="text" name="client_name" required
               placeholder="<?= te('Mairie de Fréjus') ?>">
      </div>
      <div class="field" style="flex:1 1 260px">
        <label for="nce"><?= te('Adresse de contact') ?></label>
        <input id="nce" type="text" name="client_email" spellcheck="false"
               placeholder="<?= te('client@exemple.fr') ?>">
      </div>
      <div class="field" style="flex:0 0 auto">
        <label>&nbsp;</label>
        <button class="btn btn-primary"><?= Ui::icon('plus', 16) ?> <?= te('Créer le client') ?></button>
      </div>
    </form>
    <?php if ($hasGroups): ?>
      <form method="post" class="row mt">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="client_from_groups">
        <button class="btn btn-sm"><?= Ui::icon('layers', 15) ?>
          <?= te('Reprendre les groupes existants') ?></button>
        <span class="hint"><?= tne($hasGroups, 'Un site porte déjà un groupe et aucun client.',
                                              '{n} sites portent déjà un groupe et aucun client.') ?></span>
      </form>
    <?php endif; ?>
  <?= Ui::accClose() ?>
</section>

<?php if ($orphans): ?>
  <p class="soft small mt">
    <?= tne(count($orphans), 'Un site n\'est rattaché à aucun client : il reste visible pour vous seulement.',
                             '{n} sites ne sont rattachés à aucun client : ils restent visibles pour vous seulement.') ?>
  </p>
<?php endif; ?>
