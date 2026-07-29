<?php
/**
 * Ajout de sites.
 *
 * L'écran tient en une décision : coller une liste. Tous les réglages ont une
 * valeur par défaut sensée et sont repliés.
 */
use Uptimer\Auth;
use Uptimer\Config;
use Uptimer\Importer;
use Uptimer\Ui;

$csrf    = Auth::csrf();
$pending = Importer::pending(200);
$d       = Config::get('defaults', []);
$preview = $GLOBALS['uptimer_preview'] ?? null;
$opt     = $preview['opt'] ?? [];
$keep    = fn(string $k, $def = null) => $opt[$k] ?? $def;
?>
<div class="row-between mt">
  <div class="prose">
    <h1><?= te('Ajouter des sites') ?></h1>
    <p class="muted small mb0"><?= te('Collez une liste de domaines ou d\'URLs. {app} identifie la technologie, choisit les pages à suivre et déduit la chaîne qui prouve que le serveur web et la base répondent.') ?></p>
  </div>
</div>

<form method="post" class="panel" enctype="multipart/form-data">
  <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
  <input type="hidden" name="action" value="preview">
  <div class="panel-body">

    <div class="field">
      <label for="list"><?= te('Liste à surveiller') ?><span class="req" aria-hidden="true">*</span></label><?= hint('Collez ce que vous avez sous la main : une liste de domaines, un tableau, un e-mail client. {app} y récupère les adresses, écarte les doublons et vous montre un aperçu avant de créer quoi que ce soit.') ?>
      <textarea id="list" name="list" rows="9" autofocus spellcheck="false"
                placeholder="<?= te('exemple.fr https://autre-client.com/ boutique.fr | Boutique Dupont api.exemple.fr/health ; API interne ; &quot;status&quot;:&quot;ok&quot;') ?>"><?= e((string)($preview['raw'] ?? '')) ?></textarea>
      <span class="hint"><?= te('Un élément par ligne. Vous pouvez ajouter un nom et une chaîne de contrôle en séparant par') ?> <span class="mono">|</span>, <span class="mono">;</span> <?= te('ou une tabulation :') ?>
        <span class="mono">URL | nom | <?= te('chaîne de contrôle') ?></span><?= te('. Les lignes commençant par {char} sont ignorées.', ['char' => '#']) ?>
        <span class="mono">#</span> <?= te('sont ignorées, les doublons aussi.') ?></span>
    </div>

    <div class="field">
      <label for="file"><?= te('Ou déposez l\'export de votre outil actuel') ?></label><?= hint('Les exports d\'UptimeRobot, Uptime Kuma, Better Stack, Pingdom et Site24x7 sont reconnus au contenu, sans rien choisir. Un CSV avec une colonne d\'adresses fonctionne aussi. Cadences, mots-clés et sondes en pause sont reprises telles quelles.') ?>
      <input id="file" type="file" name="file" accept=".json,.csv,.txt,.tsv,application/json,text/csv,text/plain">
      <span class="hint"><?= te('{list} sont lus directement. Ce qui n\'a pas d\'équivalent, comme un port TCP ou un ping, est listé sans être créé : rien ne disparaît en silence.',
            ['list' => implode(', ', array_slice(Uptimer\Import\Foreign::SOURCES, 0, 5))]) ?>
        <br><?= te('Seule la configuration est reprise, jamais l\'historique de mesures : il a été pris par un autre outil, avec d\'autres seuils, depuis un autre réseau.') ?></span>
    </div>

    <div class="field-row">
      <div class="field" style="flex:1 1 220px">
        <label for="iv"><?= te('Fréquence de vérification') ?></label>
        <select id="iv" name="interval_sec">
          <?php foreach ([60 => 'Toutes les minutes', 120 => 'Toutes les 2 minutes', 300 => 'Toutes les 5 minutes',
                          600 => 'Toutes les 10 minutes', 900 => 'Toutes les 15 minutes',
                          1800 => 'Toutes les 30 minutes', 3600 => 'Toutes les heures'] as $s => $l): ?>
            <option value="<?= $s ?>" <?= (int)($d['interval_sec'] ?? 300) === $s ? 'selected' : '' ?>><?= e($l) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field" style="flex:1 1 200px">
        <label for="grp"><?= te('Groupe') ?></label>
        <input id="grp" type="text" name="group" placeholder="<?= te('Clients, Interne, Préprod…') ?>">
        <span class="hint"><?= te('Facultatif : sert à filtrer le tableau de bord.') ?></span>
      </div>
      <div class="field" style="flex:1 1 200px">
        <label for="pages"><?= te('Pages suivies par site') ?></label>
        <select id="pages" name="pages">
          <?php foreach ([1 => 'Accueil seulement', 2 => 'Accueil + 1 page', 3 => '3 pages',
                          4 => t('4 pages (conseillé)'), 5 => '5 pages', 8 => '8 pages'] as $n => $l): ?>
            <option value="<?= $n ?>" <?= $n === 4 ? 'selected' : '' ?>><?= e($l) ?></option>
          <?php endforeach; ?>
        </select>
        <span class="hint"><?= te('Choisies dans le sitemap : contact, offre, tarifs, contenu…') ?></span>
      </div>
    </div>

    <details class="acc">
      <summary>
        <span class="acc-icon"><?= Ui::icon('sliders', 18) ?></span>
        <span class="acc-title"><?= te('Ce que {app} fera pour chaque site') ?></span>
        <span class="acc-note"><?= te('tout est activé par défaut') ?></span>
        <span class="chev"><?= Ui::icon('chevron', 16) ?></span>
      </summary>
      <div class="acc-body">
        <div class="form-cols">
          <div>
            <label class="switchrow"><input type="checkbox" name="discover" checked>
              <span class="sw-text"><span class="sw-title"><?= te('Choisir des pages représentatives') ?></span>
                <span class="hint"><?= te('Sitemap déclaré dans robots.txt, sinon sitemaps usuels, sinon liens internes. Une page par famille, panier et connexion écartés.') ?></span></span></label>
            <label class="switchrow"><input type="checkbox" name="extras" checked>
              <span class="sw-text"><span class="sw-title"><?= te('Ajouter les sondes techniques du CMS') ?></span>
                <span class="hint"><?= te('Sur WordPress : l\'API REST (qui traverse réellement la base) et le sitemap.') ?></span></span></label>
            <label class="switchrow"><input type="checkbox" name="check_css" checked>
              <span class="sw-text"><span class="sw-title"><?= te('Contrôler les ressources de la page') ?></span>
                <span class="hint"><?= te('CSS, scripts et polices : c\'est ce qui détecte une mise en page cassée.') ?></span></span></label>
          </div>
          <div>
            <label class="switchrow"><input type="checkbox" name="check_db" checked>
              <span class="sw-text"><span class="sw-title"><?= te('Détecter une base de données hors service') ?></span></span></label>
            <label class="switchrow"><input type="checkbox" name="check_ssl" checked>
              <span class="sw-text"><span class="sw-title"><?= te('Surveiller le certificat SSL') ?></span></span></label>
            <label class="switchrow"><input type="checkbox" name="check_noindex" checked>
              <span class="sw-text"><span class="sw-title"><?= te('Alerter sur un noindex oublié') ?></span></span></label>
            <label class="switchrow"><input type="checkbox" name="check_content">
              <span class="sw-text"><span class="sw-title"><?= te('Signaler toute modification de contenu') ?></span>
                <span class="hint"><?= te('Bavard sur un site qui publie souvent : à réserver aux sites figés.') ?></span></span></label>
          </div>
        </div>
      </div>
    </details>

    <div class="row mt">
      <button class="btn btn-primary"><?= Ui::icon('eye', 15) ?> <?= te('Voir ce qui sera créé') ?></button>
      <span class="muted small"><?= te('Rien n\'est créé à cette étape : vous validez ensuite l\'aperçu. {app} accepte aussi un e-mail ou un tableau collé tel quel, elle y récupère les adresses.') ?></span>
    </div>
  </div>
</form>

<?php if ($preview && $preview['rows']):
  $toCreate = 0;
  foreach ($preview['rows'] as $r) if (empty($r['exists'])) $toCreate++;
?>
<div class="panel" id="preview" style="border-color:color-mix(in srgb,var(--accent) 40%,var(--border))">
  <div class="panel-head">
    <h2><?= Ui::icon('eye', 15) ?> <?= te('Aperçu') ?> : <?= tne($toCreate, 'une sonde principale à créer', '{n} sondes principales à créer') ?>
      <?php if (!empty($preview['label'])): ?>
        <?= Ui::badge(t('repris de {tool}', ['tool' => (string)$preview['label']]), 'info') ?>
      <?php endif; ?></h2>
    <span class="muted small">
      <?= count($preview['rows']) ?> <?= te('adresse(s) reconnue(s)') ?><?php
      if ($preview['existing']) echo ' · ' . (int)$preview['existing'] . t('déjà surveillée(s)');
      if ($preview['errors']) echo ' · ' . count($preview['errors']) . t('ligne(s) écartée(s)'); ?>
    </span>
  </div>
  <div class="panel-body tight">
    <div class="table-scroll"><table class="tbl">
      <thead><tr><th><?= te('Site') ?></th><th><?= te('Adresse') ?></th><th class="num"><?= te('Cadence') ?></th>
        <th class="num"><?= te('Pages suivies') ?></th>
        <th><?= te('Chaîne de preuve') ?><?= hint('Le texte qui prouvera que le serveur web ET la base de données répondent. Déduit du contenu du site, jamais d\'une page d\'erreur.') ?></th>
        <th></th></tr></thead>
      <tbody>
      <?php foreach ($preview['rows'] as $r): ?>
        <tr<?= !empty($r['exists']) ? ' style="opacity:.5"' : '' ?>>
          <td><strong><?= e($r['name'] !== '' ? $r['name'] : $r['domain']) ?></strong>
            <?php if ((int)($preview['groups'][$r['domain']] ?? 1) > 1): ?>
              <div class="tiny muted"><?= te('regroupé avec') ?> <?= (int)$preview['groups'][$r['domain']] - 1 ?> <?= te('autre(s) du même domaine') ?></div>
            <?php endif; ?></td>
          <td class="tiny mono"><?= e(str_cut($r['url'], 70)) ?></td>
          <td class="num small"><?= e(human_duration((int)$r['interval'])) ?>
            <?php if (!empty($r['kept'])): ?>
              <div class="tiny muted"><?= te('reprise de l\'export') ?></div>
            <?php endif; ?></td>
          <td class="num small"><?= !empty($r['exists']) ? '—' : te('jusqu\'à {n}', ['n' => (int)$r['pages']]) ?></td>
          <td class="small"><?= $r['proof'] ? e(str_cut((string)$r['proof'], 30))
              : '<span class="muted">déduite du contenu</span>' ?></td>
          <td class="num nowrap"><?php
            if (!empty($r['exists'])) echo Ui::badge(t('déjà présente'), 'neutral');
            elseif (isset($r['enabled']) && (int)$r['enabled'] === 0)
                echo Ui::badge(t('à créer, en pause'), 'warn');
            else echo Ui::badge(t('à créer'), 'ok'); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if (!empty($preview['skipped'])): ?>
      <?php /* Ce qui n'a pas d'équivalent est montré, jamais escamoté : un
               import qui perd six sondes sur quarante sans le dire est pire
               qu'un import qui refuse. */ ?>
      <div class="imp-skip">
        <strong><?= e(tn(count($preview['skipped']), 'Une sonde ne peut pas être reprise',
                                                     '{n} sondes ne peuvent pas être reprises')) ?></strong>
        <ul>
          <?php foreach (array_slice($preview['skipped'], 0, 12) as $sk): ?>
            <li><span class="imp-skip-name"><?= e((string)$sk['name']) ?></span>
              <span class="muted"><?= e((string)$sk['why']) ?></span></li>
          <?php endforeach; ?>
        </ul>
        <?php if (count($preview['skipped']) > 12): ?>
          <p class="tiny muted mb0"><?= te('et {n} autre(s).', ['n' => count($preview['skipped']) - 12]) ?></p>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <?php if ($preview['errors']): ?>
      <div style="padding:12px 16px">
        <details class="small">
          <summary class="muted" style="cursor:pointer"><?= count($preview['errors']) ?> <?= te('ligne(s) écartée(s)') ?></summary>
          <ul class="tiny muted" style="margin:8px 0 0;padding-left:20px">
            <?php foreach (array_slice($preview['errors'], 0, 8) as $er): ?><li><?= e($er) ?></li><?php endforeach; ?>
          </ul>
        </details>
      </div>
    <?php endif; ?>
    <form method="post" class="row" style="padding:14px 16px;gap:10px;border-top:1px solid var(--border)">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="import">
      <input type="hidden" name="list" value="<?= e((string)$preview['raw']) ?>">
      <input type="hidden" name="interval_sec" value="<?= (int)$keep('interval_sec', 300) ?>">
      <input type="hidden" name="pages" value="<?= (int)$keep('pages', 4) ?>">
      <input type="hidden" name="group" value="<?= e((string)$keep('group', '')) ?>">
      <?php foreach (['discover', 'extras', 'check_css', 'check_db', 'check_ssl', 'check_noindex', 'check_content'] as $f):
        if ($keep($f) !== null): ?><input type="hidden" name="<?= $f ?>" value="1"><?php endif;
      endforeach; ?>
      <button class="btn btn-primary" <?= $toCreate ? '' : 'disabled' ?>>
        <?= Ui::icon('check', 15) ?> <?= te('Créer') ?> <?= $toCreate ?> <?= te('sonde') ?><?= $toCreate > 1 ? 's' : '' ?> <?= te('et préparer') ?>
      </button>
      <span class="muted small"><?= te('La technologie, les pages représentatives et la chaîne de preuve sont déterminées juste après, site par site.') ?></span>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="panel" id="setup-panel" <?= $pending ? '' : 'hidden' ?>>
  <div class="panel-head">
    <h2><?= Ui::icon('layers', 15) ?> <?= te('Préparation automatique') ?></h2>
    <span class="muted small" id="setup-status"><?= count($pending) ?> <?= te('sonde(s) en attente') ?></span>
  </div>
  <div class="panel-body">
    <div class="prog"><i id="setup-bar"></i></div>
    <div class="row">
      <button class="btn btn-sm btn-primary" id="setup-start" data-ids="<?= e(implode(',', array_column($pending, 'id'))) ?>">
        <?= te('Lancer la préparation') ?>
      </button>
      <span class="muted small"><?= te('Détection de la technologie · pages à suivre · chaîne de contrôle · première mesure') ?></span>
    </div>
    <div class="setup-log mt" id="setup-log"></div>
  </div>
</div>

<?= Ui::accOpen('help-proof', 'info', t('Comment {app} devine la chaîne de contrôle'), t('et pourquoi elle est décisive')) ?>
<?= Ui::accBody() ?>
  <div class="prose small soft">
    <p><?= te('La chaîne de contrôle est le cœur de la détection « serveur web + base de données OK » : un site peut renvoyer un code 200 impeccable et n\'afficher qu\'une page d\'erreur.') ?></p>
    <p><?= te('Par ordre de préférence, {app} retient :') ?></p>
    <ol style="padding-left:20px">
      <li><?= te('la mention de copyright du pied de page, issue des réglages du site, donc de la base ;') ?></li>
      <li><?= te('le nom du site déclaré en Open Graph ;') ?></li>
      <li><?= te('le nom du site déduit du titre de la page ;') ?></li>
      <li><?= te('la première entrée du menu de navigation ;') ?></li>
      <li><?= te('le titre H1.') ?></li>
    </ol>
    <p><?= te('La chaîne retenue est vérifiée : elle doit réellement figurer dans le HTML, elle est débarrassée des formulations passe-partout (« tous droits réservés », « accueil ») et n\'est') ?> <strong>jamais</strong>
      <?= te('déduite d\'une page d\'erreur. Vous pouvez l\'imposer dès l\'import en 3') ?><sup>e</sup> <?= te('colonne.') ?></p>
  </div>
<?= Ui::accClose() ?>

<?= Ui::accOpen('help-format', 'file', t('Exemples de lignes acceptées')) ?>
<?= Ui::accBody() ?>
  <pre class="mono small" style="white-space:pre-wrap;margin:0;color:var(--text-soft)">exemple.fr
www.exemple.fr/tarifs
https://boutique.fr | Boutique Dupont
client.com ; Client SA ; Mentions légales
api.client.com/health ; Santé API ; "status":"ok"
preprod.client.com|Préprod|Bienvenue</pre>
  <p class="hint mt"><?= te('Un domaine nu devient') ?> <span class="mono"><?= te('https://…') ?></span><?= te('. Si le site redirige (www, http→https), la sonde s\'aligne sur la cible. Si HTTPS ne répond pas du tout, {app} retente en HTTP, le signale, et surveille quand même.') ?></p>
<?= Ui::accClose() ?>
