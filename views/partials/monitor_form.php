<?php
/**
 * Formulaire de sonde, partagé entre création et édition.
 *
 * Principe : l'essentiel visible, le reste replié. Chaque champ a un libellé
 * visible et une explication en clair — jamais de réglage sans mode d'emploi.
 * Attend $mon (tableau, éventuellement vide pour une création).
 */
use Uptimer\Config;
use Uptimer\Ui;

$mon = $mon ?? [];
$v = function (string $k, $def = '') use ($mon) {
    $val = $mon[$k] ?? $def;
    return $val === null ? '' : (string)$val;
};
$on = function (string $k, bool $def = true) use ($mon) {
    return array_key_exists($k, $mon) ? ((int)$mon[$k] === 1) : $def;
};
$d  = Config::get('defaults', []);
$uid = 'f' . substr(md5((string)($mon['id'] ?? 'new')), 0, 5);   // identifiants uniques par formulaire

$intervals = [
    30 => 'Toutes les 30 secondes', 60 => 'Toutes les minutes', 120 => 'Toutes les 2 minutes',
    300 => 'Toutes les 5 minutes', 600 => 'Toutes les 10 minutes', 900 => 'Toutes les 15 minutes',
    1800 => 'Toutes les 30 minutes', 3600 => 'Toutes les heures', 21600 => 'Toutes les 6 heures',
    86400 => 'Une fois par jour',
];
$cur = (int)($mon['interval_sec'] ?? $d['interval_sec'] ?? 300);
?>

<!-- ---------------------------------------------------------------- essentiel -->
<fieldset>
  <legend><?= te('L\'essentiel') ?></legend>
  <div class="form-cols">
    <div>
      <div class="field">
        <label for="<?= $uid ?>-name"><?= te('Nom de la sonde') ?><span class="req" aria-hidden="true">*</span></label>
        <input id="<?= $uid ?>-name" type="text" name="name" value="<?= e($v('name')) ?>"
               placeholder="<?= te('Boutique Dupont — Accueil') ?>" required maxlength="180">
        <span class="hint"><?= te('C\'est ce nom qui apparaîtra dans les alertes.') ?></span>
      </div>
      <div class="field">
        <label for="<?= $uid ?>-url"><?= te('Adresse surveillée') ?><span class="req" aria-hidden="true">*</span></label>
        <input id="<?= $uid ?>-url" type="text" name="url" value="<?= e($v('url')) ?>"
               placeholder="<?= te('exemple.fr') ?>" required inputmode="url" autocapitalize="off" spellcheck="false">
        <span class="hint"><?= te('Un domaine seul suffit : {app} complète en {scheme} et suit les redirections.',
        ['scheme' => '<span class="mono">https://</span>']) ?></span>
      </div>
    </div>
    <div>
      <div class="field">
        <label for="<?= $uid ?>-interval"><?= te('Fréquence de vérification') ?></label><?= hint('{app} propose déjà la bonne cadence selon l\'importance de la page : une page tarifs est vérifiée plus souvent que des mentions légales. Ne la changez que si vous avez une raison.') ?>
        <select id="<?= $uid ?>-interval" name="interval_sec">
          <?php foreach ($intervals as $sec => $label): ?>
            <option value="<?= $sec ?>" <?= $cur === $sec ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
          <?php if (!isset($intervals[$cur])): ?>
            <option value="<?= $cur ?>" selected><?= te('Toutes les {interval}', ['interval' => human_duration($cur)]) ?></option>
          <?php endif; ?>
        </select>
        <span class="hint"><?= te('Plus la fréquence est courte, plus la détection est rapide — et plus le serveur est sollicité.') ?></span>
      </div>
      <div class="field">
        <label for="<?= $uid ?>-expect"><?= te('Chaîne de contrôle') ?></label><?= hint('Un texte qui ne peut venir que de la base de données du site — le copyright du pied de page, par exemple. S\'il disparaît alors que la page répond 200, c\'est la base qui a lâché. Laissez vide : {app} le déduit.') ?>
        <input id="<?= $uid ?>-expect" type="text" name="expect_string" value="<?= e($v('expect_string')) ?>"
               placeholder="<?= te('Laisser vide : {app} la déduit du contenu') ?>">
        <span class="hint"><?= te('Un texte qui vient du contenu du site. S\'il disparaît alors que la page répond, c\'est le serveur web ou la base de données qui a lâché. Plusieurs variantes acceptées avec') ?>
          <span class="mono">|</span>.</span>
      </div>
    </div>
  </div>
</fieldset>

<!-- ------------------------------------------------------------- surveillance -->
<fieldset>
  <legend><?= te('Ce que {app} contrôle') ?></legend>
  <div class="form-cols">
    <div>
      <label class="switchrow">
        <input type="checkbox" name="check_css" <?= $on('check_css') ? 'checked' : '' ?>>
        <span class="sw-text">
          <span class="sw-title"><?= te('Ressources de la page') ?></span>
          <span class="hint"><?= te('Feuilles de style, scripts et polices : disponibilité, type MIME, contenu mixte, intégrité SRI, poids, media queries, couverture des classes, contenu masqué par une animation.') ?></span>
        </span>
      </label>
      <label class="switchrow">
        <input type="checkbox" name="check_db" <?= $on('check_db') ? 'checked' : '' ?>>
        <span class="sw-text">
          <span class="sw-title"><?= te('Base de données hors service') ?></span>
          <span class="hint"><?= te('Reconnaît les signatures d\'erreur MySQL, PDO, WordPress, Laravel… même quand le serveur répond 200.') ?></span>
        </span>
      </label>
      <label class="switchrow">
        <input type="checkbox" name="check_ssl" <?= $on('check_ssl') ? 'checked' : '' ?>>
        <span class="sw-text">
          <span class="sw-title"><?= te('Certificat SSL') ?></span>
          <span class="hint"><?= te('Validité, chaîne, correspondance du domaine, date d\'expiration.') ?></span>
        </span>
      </label>
      <label class="switchrow">
        <input type="checkbox" name="check_noindex" <?= $on('check_noindex') ? 'checked' : '' ?>>
        <span class="sw-text">
          <span class="sw-title"><?= te('Alerte noindex') ?></span>
          <span class="hint"><?= te('Repère un blocage d\'indexation oublié après une mise en production.') ?></span>
        </span>
      </label>
    </div>
    <div>
      <div class="field">
        <label for="<?= $uid ?>-slow"><?= te('Seuil de lenteur') ?></label><?= hint('Au-delà de ce temps de réponse, la sonde passe en « à surveiller ». Laissez l\'ajustement automatique : il se cale sur le p95 réel du site, ce qu\'un chiffre rond ne sait pas faire.') ?>
        <div class="field-inline">
          <input id="<?= $uid ?>-slow" type="number" name="slow_ms" min="0" max="60000" step="100"
                 value="<?= e($v('slow_ms', (string)($d['slow_ms'] ?? 3000))) ?>">
          <span class="unit"><?= te('ms') ?></span>
        </div>
        <span class="hint"><?= te('Au-delà, la sonde passe en « à surveiller » sans être déclarée hors service.') ?></span>
      </div>
      <label class="switchrow">
        <input type="checkbox" name="auto_slow" <?= $on('auto_slow') ? 'checked' : '' ?>>
        <span class="sw-text">
          <span class="sw-title"><?= te('Ajuster ce seuil automatiquement') ?></span>
          <span class="hint"><?= te('{app} le recalcule sur le p95 mesuré de cette sonde : un site lent par nature n\'alerte pas en permanence, un site rapide qui se dégrade est repéré tôt.') ?></span>
        </span>
      </label>
      <div class="field">
        <label for="<?= $uid ?>-sslw"><?= te('Prévenir avant l\'expiration du certificat') ?></label>
        <div class="field-inline">
          <input id="<?= $uid ?>-sslw" type="number" name="ssl_warn_days" min="1" max="120"
                 value="<?= e($v('ssl_warn_days', (string)($d['ssl_warn_days'] ?? 14))) ?>">
          <span class="unit"><?= te('jours à l\'avance') ?></span>
        </div>
      </div>
      <div class="field">
        <label for="<?= $uid ?>-drop"><?= te('Chute de CSS tolérée') ?></label><?= hint('Baisse de poids des feuilles de style acceptée avant alerte. Un cache vidé fait varier de quelques pour cent ; un déploiement raté fait chuter de moitié.') ?>
        <div class="field-inline">
          <input id="<?= $uid ?>-drop" type="number" name="css_drop_pct" min="5" max="90"
                 value="<?= e($v('css_drop_pct', (string)($d['css_drop_pct'] ?? 35))) ?>">
          <span class="unit"><?= te('% de perte de poids') ?></span>
        </div>
        <span class="hint"><?= te('Comparé à l\'empreinte de référence apprise sur les états sains.') ?></span>
      </div>
      <label class="switchrow">
        <input type="checkbox" name="css_baseline_locked" <?= $on('css_baseline_locked', false) ? 'checked' : '' ?>>
        <span class="sw-text">
          <span class="sw-title"><?= te('Figer la référence CSS actuelle') ?></span>
          <span class="hint"><?= te('À activer quand le design est stabilisé : la référence n\'évoluera plus toute seule.') ?></span>
        </span>
      </label>
    </div>
  </div>
</fieldset>

<!-- ------------------------------------------------------- mise à jour de page -->
<details class="acc">
  <summary>
    <span class="acc-icon"><?= Ui::icon('eye', 18) ?></span>
    <span class="acc-title"><?= te('Surveiller une mise à jour de contenu') ?></span>
    <span class="acc-note"><?= $v('watch_string') !== '' || $on('check_content', false) ? te('activée') : te('facultatif') ?></span>
    <span class="chev"><?= Ui::icon('chevron', 16) ?></span>
  </summary>
  <div class="acc-body">
    <div class="form-cols">
      <div>
        <div class="field">
          <label for="<?= $uid ?>-watch"><?= te('Texte à surveiller') ?></label>
          <input id="<?= $uid ?>-watch" type="text" name="watch_string" value="<?= e($v('watch_string')) ?>"
                 placeholder="<?= te('Soldes 2026, Nouveau tarif…') ?>">
          <span class="hint"><?= te('Utile pour confirmer qu\'une publication est bien passée en ligne.') ?></span>
        </div>
        <div class="field">
          <label for="<?= $uid ?>-watchmode"><?= te('Me prévenir quand ce texte…') ?></label>
          <select id="<?= $uid ?>-watchmode" name="watch_mode">
            <option value="appear" <?= $v('watch_mode', 'appear') === 'appear' ? 'selected' : '' ?>><?= te('apparaît (mise en ligne confirmée)') ?></option>
            <option value="disappear" <?= $v('watch_mode') === 'disappear' ? 'selected' : '' ?>><?= te('disparaît (contenu retiré)') ?></option>
          </select>
        </div>
      </div>
      <div>
        <label class="switchrow">
          <input type="checkbox" name="check_content" <?= $on('check_content', false) ? 'checked' : '' ?>>
          <span class="sw-text">
            <span class="sw-title"><?= te('Signaler toute modification du contenu') ?></span>
            <span class="hint"><?= te('Empreinte du texte visible, hors scripts et jetons. Repère une publication comme une défiguration de page.') ?></span>
          </span>
        </label>
        <div class="field">
          <label for="<?= $uid ?>-forbid"><?= te('Chaîne interdite') ?></label>
          <input id="<?= $uid ?>-forbid" type="text" name="forbid_string" value="<?= e($v('forbid_string')) ?>"
                 placeholder="<?= te('Site en maintenance, Erreur de connexion…') ?>">
          <span class="hint"><?= te('Sa présence déclenche une alerte immédiate.') ?></span>
        </div>
      </div>
    </div>
  </div>
</details>

<!-- ------------------------------------------------------------- API / requête -->
<details class="acc">
  <summary>
    <span class="acc-icon"><?= Ui::icon('layers', 18) ?></span>
    <span class="acc-title"><?= te('Type de sonde, API et requête') ?></span>
    <span class="acc-note"><?= e(match ($v('kind', 'page')) {
        'api' => t('API JSON'), 'asset' => t('fichier'),
        'keyword' => t('mot-clé'), default => t('page web') }) ?></span>
    <span class="chev"><?= Ui::icon('chevron', 16) ?></span>
  </summary>
  <div class="acc-body">
    <div class="form-cols">
      <div>
        <div class="field-row">
          <div class="field">
            <label for="<?= $uid ?>-kind"><?= te('Type') ?></label>
            <select id="<?= $uid ?>-kind" name="kind">
              <?php foreach (['page' => 'Page web', 'api' => 'API / JSON',
                              'asset' => 'Fichier (sitemap, robots…)', 'keyword' => t('Mot-clé seul'),
                              'heartbeat' => t('Battement (le site nous appelle)')] as $k => $l): ?>
                <option value="<?= $k ?>" <?= $v('kind', 'page') === $k ? 'selected' : '' ?>><?= e($l) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="<?= $uid ?>-method"><?= te('Méthode') ?></label>
            <select id="<?= $uid ?>-method" name="method">
              <?php foreach (['GET', 'HEAD', 'POST', 'PUT'] as $mth): ?>
                <option value="<?= $mth ?>" <?= $v('method', 'GET') === $mth ? 'selected' : '' ?>><?= $mth ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="field">
          <label for="<?= $uid ?>-status"><?= te('Codes HTTP acceptés') ?></label><?= hint('Codes considérés comme normaux, séparés par des virgules. Utile pour une page qui répond légitimement 401 ou 403.') ?>
          <input id="<?= $uid ?>-status" type="text" name="expect_status" value="<?= e($v('expect_status', '200-299')) ?>"
                 placeholder="200-299">
          <span class="hint"><?= te('Exemples :') ?> <span class="mono">200</span>, <span class="mono">200-299</span>,
            <span class="mono">2xx</span>, <span class="mono">200,301</span>.</span>
        </div>
        <div class="field-row">
          <div class="field">
            <label for="<?= $uid ?>-timeout"><?= te('Délai maximum') ?></label>
            <div class="field-inline">
              <input id="<?= $uid ?>-timeout" type="number" name="timeout_sec" min="3" max="60"
                     value="<?= e($v('timeout_sec', (string)($d['timeout_sec'] ?? 15))) ?>">
              <span class="unit">s</span>
            </div>
          </div>
          <div class="field">
            <label for="<?= $uid ?>-retries"><?= te('Relances avant alerte') ?></label>
            <input id="<?= $uid ?>-retries" type="number" name="retries" min="0" max="5"
                   value="<?= e($v('retries', (string)($d['retries'] ?? 2))) ?>">
            <span class="hint"><?= te('Évite les fausses alertes sur un incident réseau passager.') ?></span>
          </div>
        </div>
      </div>
      <div>
        <div class="field-row">
          <div class="field">
            <label for="<?= $uid ?>-jpath"><?= te('Champ JSON attendu') ?></label>
            <input id="<?= $uid ?>-jpath" type="text" name="json_path" value="<?= e($v('json_path')) ?>" placeholder="<?= te('data.status') ?>">
            <span class="hint"><?= te('Chemin pointé dans la réponse.') ?></span>
          </div>
          <div class="field">
            <label for="<?= $uid ?>-jval"><?= te('Valeur attendue') ?></label>
            <input id="<?= $uid ?>-jval" type="text" name="json_expect" value="<?= e($v('json_expect')) ?>" placeholder="ok">
          </div>
        </div>
        <div class="field">
          <label for="<?= $uid ?>-body"><?= te('Corps de la requête') ?></label>
          <textarea id="<?= $uid ?>-body" name="request_body" rows="2" placeholder='{"ping":1}'><?= e($v('request_body')) ?></textarea>
        </div>
        <div class="field">
          <label for="<?= $uid ?>-headers"><?= te('En-têtes personnalisés') ?></label>
          <textarea id="<?= $uid ?>-headers" name="request_headers" rows="2"
                    placeholder="<?= te('X-Api-Key: 123&#10;Authorization: Bearer …') ?>"><?php
            foreach (jdec($mon['request_headers'] ?? null) as $k => $val) echo e($k . ': ' . $val) . "\n";
          ?></textarea>
          <span class="hint"><?= te('Une par ligne, au format') ?> <span class="mono"><?= te('Nom: valeur') ?></span>.</span>
        </div>
      </div>
    </div>
  </div>
</details>

<!-- ---------------------------------------------------------------- avancé -->
<?php /* En mode simple le bloc est masqué mais reste dans le formulaire :
         retirer ses champs les enverrait vides et désactiverait la sonde. */ ?>
<details class="acc"<?= expert() ? '' : ' hidden' ?>>
  <summary>
    <span class="acc-icon"><?= Ui::icon('wrench', 18) ?></span>
    <span class="acc-title"><?= te('Accès, maintenance et alertes') ?></span>
    <span class="acc-note"><?= te('authentification, fenêtre de silence, canaux') ?></span>
    <span class="chev"><?= Ui::icon('chevron', 16) ?></span>
  </summary>
  <div class="acc-body">
    <div class="form-cols">
      <div>
        <div class="field-row">
          <div class="field">
            <label for="<?= $uid ?>-auser"><?= te('Identifiant HTTP') ?></label>
            <input id="<?= $uid ?>-auser" type="text" name="auth_user" value="<?= e($v('auth_user')) ?>" autocomplete="off">
          </div>
          <div class="field">
            <label for="<?= $uid ?>-apass"><?= te('Mot de passe HTTP') ?></label>
            <input id="<?= $uid ?>-apass" type="password" name="auth_pass" autocomplete="new-password"
                   placeholder="<?= $v('auth_pass') !== '' ? '•••••• (inchangé)' : '' ?>">
            <?php if ($v('auth_pass') !== ''): ?>
              <span class="hint"><?= te('Laissez vide pour conserver le mot de passe actuel.') ?></span>
            <?php endif; ?>
          </div>
        </div>
        <div class="field">
          <label for="<?= $uid ?>-maint"><?= te('Fenêtre de maintenance') ?></label><?= hint('Plage pendant laquelle on ne veut pas être dérangé : sauvegarde nocturne, déploiement hebdomadaire. Les mesures continuent, seules les alertes se taisent.') ?>
          <input id="<?= $uid ?>-maint" type="text" name="maintenance" value="<?= e($v('maintenance')) ?>"
                 placeholder="<?= te('mar 02:00-04:00') ?>">
          <span class="hint"><?= te('Aucune alerte pendant cette plage. Formats acceptés :') ?>
            <span class="mono">02:00-04:00</span> ou <span class="mono"><?= te('lun-ven 22:00-23:30') ?></span>.</span>
        </div>
        <div class="field">
          <label for="<?= $uid ?>-chan"><?= te('Canaux d\'alerte de cette sonde') ?></label>
          <input id="<?= $uid ?>-chan" type="text" name="notify_channels" value="<?= e($v('notify_channels')) ?>"
                 placeholder="<?= te('discord,mail') ?>">
          <span class="hint">Vide = tous les canaux actifs dans les réglages généraux.</span>
        </div>
      </div>
      <div>
        <div class="field">
          <label for="<?= $uid ?>-ua"><?= te('User-Agent') ?></label>
          <input id="<?= $uid ?>-ua" type="text" name="user_agent" value="<?= e($v('user_agent')) ?>"
                 placeholder="<?= e(str_cut((string)($d['user_agent'] ?? ''), 50)) ?>">
          <span class="hint"><?= te('À personnaliser si un pare-feu bloque le robot de surveillance.') ?></span>
        </div>
        <label class="switchrow">
          <input type="checkbox" name="follow_redirects" <?= $on('follow_redirects') ? 'checked' : '' ?>>
          <span class="sw-text"><span class="sw-title"><?= te('Suivre les redirections') ?></span>
            <span class="hint"><?= te('Décochez pour vérifier qu\'une URL répond bien elle-même.') ?></span></span>
        </label>
        <label class="switchrow">
          <input type="checkbox" name="ignore_ssl_errors" <?= $on('ignore_ssl_errors', false) ? 'checked' : '' ?>>
          <span class="sw-text"><span class="sw-title"><?= te('Ignorer les erreurs de certificat') ?></span>
            <span class="hint"><?= te('Réservé à un préprod en certificat auto-signé.') ?></span></span>
        </label>
        <label class="switchrow">
          <input type="checkbox" name="enabled" <?= $on('enabled') ? 'checked' : '' ?>>
          <span class="sw-text"><span class="sw-title"><?= te('Sonde active') ?></span>
            <span class="hint"><?= te('Décochée, la sonde reste enregistrée mais n\'est plus vérifiée.') ?></span></span>
        </label>
      </div>
    </div>
  </div>
</details>
