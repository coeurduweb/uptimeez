<?php
/**
 * Réglages.
 *
 * Un accordéon par domaine, dans l'ordre d'importance réelle : sans tâche
 * planifiée rien ne fonctionne, sans canal d'alerte personne n'est prévenu.
 */
use Uptimeez\Auth;
use Uptimeez\Config;
use Uptimeez\Db;
use Uptimeez\Demo;
use Uptimeez\Ui;

$csrf      = Auth::csrf();
$cronPath  = UPTIMEEZ_ROOT . '/cron.php';
$phpBin    = PHP_BINDIR . '/php';
$lastRun   = Db::setting('last_run_at');
$lastStats = jdec(Db::setting('last_run_stats'));
$baseUrl   = (string)Config::get('app.base_url', '');
// Les valeurs sensibles passent par Demo::hide() : la démonstration est
// publique et son mot de passe est écrit dans la documentation, donc tout ce que
// cet écran affiche est public. Hors démonstration, hide() rend la valeur telle
// quelle et rien ne change pour l'exploitant.
$cronKey   = Demo::hide((string)Config::get('app.cron_key', ''));
$token     = Demo::hide((string)Config::get('app.public_token', ''));
$cronOk    = $lastRun && strtotime((string)$lastRun) > time() - 900;
$channels  = ['discord' => 'Discord', 'slack' => 'Slack', 'mail' => 'E-mail', 'webhook' => 'Webhook'];
$activeCh  = [];
foreach ($channels as $k => $l) if (Config::get("notify.$k.enabled")) $activeCh[] = $l;
?>
<div class="row-between mt">
  <h1><?= te('Réglages') ?></h1>
  <span class="muted small">UptimeEZ <?= UPTIMEEZ_VERSION ?> · PHP <?= PHP_VERSION ?>
    · <?= te('base {driver}', ['driver' => Db::driver()]) ?></span>
</div>

<!-- ============================ TÂCHE PLANIFIÉE ============================ -->
<?php /* L'aide se place dans le corps de l'accordéon : un titre reste un titre. */ ?>
<?= Ui::accOpen('cron', 'clock', t('Tâche planifiée'),
      $cronOk ? t('dernière passe {when}', ['when' => human_since((string)$lastRun)]) : t('à configurer'),
      !$cronOk, $cronOk ? 'none' : 'attn',
      $cronOk ? Ui::badge('active', 'ok') : Ui::badge('inactive', 'bad')) ?>
<?= Ui::accBody() ?>
  <?php if (!$cronOk): ?>
    <div class="alert alert-warn" style="margin-top:0">
      <?= Ui::icon('alert', 18) ?>
      <div><strong><?= te('Rien n\'est surveillé automatiquement pour l\'instant.') ?></strong>
        <?= te('Ajoutez la ligne ci-dessous dans le gestionnaire de tâches cron de votre hébergement (cPanel o2switch : « Tâches cron », fréquence : chaque minute).') ?></div>
    </div>
  <?php endif; ?>
  <div class="field">
    <label for="cronline"><?= te('Ligne cron à copier') ?></label>
    <input id="cronline" type="text" readonly onclick="this.select()"
           value="* * * * * <?= e($phpBin) ?> <?= e($cronPath) ?> &gt;/dev/null 2&gt;&amp;1">
    <span class="hint"><?= te('Une exécution par minute suffit quels que soient vos intervalles : {app} choisit elle-même les sondes dues.') ?></span>
  </div>
  <?php if ($cronKey !== ''): ?>
    <div class="field">
      <label for="cronurl"><?= te('Déclenchement par URL') ?></label>
      <input id="cronurl" type="text" readonly onclick="this.select()"
             value="<?= e(($baseUrl ?: 'https://votre-adresse-uptimeez') . '/cron.php?key=' . $cronKey) ?>">
      <span class="hint"><?= te('Solution de repli si l\'hébergement n\'expose pas crontab : à appeler chaque minute depuis un service externe.') ?><?php if ($baseUrl === ''): ?> <?= te('Renseignez d\'abord l\'adresse de l\'installation ci-dessous pour obtenir l\'URL complète.') ?><?php endif; ?></span>
    </div>
  <?php endif; ?>
  <?php if ($lastStats): ?>
    <p class="small muted"><?= te('Dernière passe : {n} sondes en {sec} s', [
        'n' => (int)($lastStats['ran'] ?? 0), 'sec' => (string)($lastStats['seconds'] ?? '?')]) ?>
      · <?= te('{down} hors service, {degraded} à surveiller.', [
        'down' => (int)($lastStats['down'] ?? 0), 'degraded' => (int)($lastStats['degraded'] ?? 0)]) ?></p>
  <?php endif; ?>
  <form method="post" class="row">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="action" value="maintenance_cron">
    <button class="btn btn-sm"><?= Ui::icon('wrench', 14) ?> <?= te('Lancer l\'entretien maintenant') ?></button>
    <span class="muted small"><?= te('Consolidation journalière, recalcul des uptimes, purge de l\'historique.') ?></span>
  </form>
<?= Ui::accClose() ?>

<form method="post" data-dirty-watch>
  <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
  <input type="hidden" name="action" value="save_settings">

  <!-- ============================ ALERTES ============================ -->
  <?= Ui::accOpen('notify', 'bell', 'Alertes',
        $activeCh ? implode(', ', $activeCh) : t('aucun canal actif, personne ne sera prévenu'),
        !$activeCh, $activeCh ? 'none' : 'warn') ?>
  <?= Ui::accBody() ?>
    <div class="form-cols">
      <fieldset>
        <legend><?= te('Discord') ?></legend>
        <label class="switchrow"><input type="checkbox" name="discord_enabled" <?= Config::get('notify.discord.enabled') ? 'checked' : '' ?>>
          <span class="sw-text"><span class="sw-title"><?= te('Envoyer les alertes sur Discord') ?></span></span></label>
        <div class="field">
          <label for="dw"><?= te('URL du webhook') ?></label>
          <input id="dw" type="text" name="discord_webhook" value="<?= e(Demo::hide((string)Config::get('notify.discord.webhook', ''))) ?>"
                 placeholder="<?= te('https://discord.com/api/webhooks/…') ?>" spellcheck="false">
          <span class="hint"><?= te('Dans Discord : salon → Paramètres → Intégrations → Webhooks.') ?></span>
        </div>
      </fieldset>
      <fieldset>
        <legend><?= te('Slack') ?></legend>
        <label class="switchrow"><input type="checkbox" name="slack_enabled" <?= Config::get('notify.slack.enabled') ? 'checked' : '' ?>>
          <span class="sw-text"><span class="sw-title"><?= te('Envoyer les alertes sur Slack') ?></span></span></label>
        <div class="field">
          <label for="sw"><?= te('URL du webhook entrant') ?></label>
          <input id="sw" type="text" name="slack_webhook" value="<?= e(Demo::hide((string)Config::get('notify.slack.webhook', ''))) ?>"
                 placeholder="<?= te('https://hooks.slack.com/services/…') ?>" spellcheck="false">
        </div>
      </fieldset>
    </div>

    <fieldset>
      <legend><?= te('E-mail') ?></legend>
      <label class="switchrow"><input type="checkbox" name="mail_enabled" <?= Config::get('notify.mail.enabled') ? 'checked' : '' ?>>
        <span class="sw-text"><span class="sw-title"><?= te('Envoyer les alertes par e-mail') ?></span></span></label>
      <div class="field-row">
        <div class="field" style="flex:2 1 260px">
          <label for="mto"><?= te('Destinataires') ?></label>
          <input id="mto" type="text" name="mail_to" value="<?= e(Demo::hide((string)Config::get('notify.mail.to', ''))) ?>"
                 placeholder="<?= te('vous@agence.fr, astreinte@agence.fr') ?>" inputmode="email">
          <span class="hint"><?= te('Séparés par des virgules.') ?></span>
        </div>
        <div class="field" style="flex:1 1 200px">
          <label for="mfrom"><?= te('Expéditeur') ?></label>
          <input id="mfrom" type="text" name="mail_from" value="<?= e(Demo::hide((string)Config::get('notify.mail.from', ''))) ?>"
                 placeholder="<?= te('uptimeez@votredomaine.fr') ?>" inputmode="email">
          <span class="hint"><?= te('Une adresse de votre domaine hébergé passe mieux les filtres.') ?></span>
        </div>
        <div class="field" style="flex:1 1 150px">
          <label for="mname"><?= te('Nom affiché') ?></label>
          <input id="mname" type="text" name="mail_from_name" value="<?= e((string)Config::get('notify.mail.from_name', '{app}')) ?>">
        </div>
      </div>
      <div class="field" style="max-width:340px">
        <label for="mail-transport"><?= te('Mode d\'envoi') ?></label>
        <select name="mail_transport" id="mail-transport">
          <option value="mail" <?= Config::get('notify.mail.transport') !== 'smtp' ? 'selected' : '' ?>><?= te('Fonction mail() du serveur (o2switch : OK)') ?></option>
          <option value="smtp" <?= Config::get('notify.mail.transport') === 'smtp' ? 'selected' : '' ?>><?= te('SMTP authentifié') ?></option>
        </select>
      </div>
      <div class="field-row" id="smtp-block" <?= Config::get('notify.mail.transport') === 'smtp' ? '' : 'hidden' ?>>
        <div class="field" style="flex:2 1 220px">
          <label for="sh"><?= te('Serveur SMTP') ?></label>
          <input id="sh" type="text" name="smtp_host" value="<?= e(Demo::hide((string)Config::get('notify.mail.smtp.host', ''))) ?>"
                 placeholder="<?= te('smtp.votredomaine.fr') ?>" spellcheck="false">
        </div>
        <div class="field" style="flex:0 1 110px">
          <label for="sp"><?= te('Port') ?></label>
          <input id="sp" type="number" name="smtp_port" value="<?= (int)Config::get('notify.mail.smtp.port', 587) ?>">
        </div>
        <div class="field" style="flex:1 1 160px">
          <label for="ss"><?= te('Chiffrement') ?></label>
          <select id="ss" name="smtp_secure">
            <?php foreach (['tls' => 'STARTTLS (587)', 'ssl' => 'SSL/TLS (465)', 'none' => 'Aucun'] as $k => $l): ?>
              <option value="<?= $k ?>" <?= Config::get('notify.mail.smtp.secure') === $k ? 'selected' : '' ?>><?= e($l) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field" style="flex:1 1 180px">
          <label for="su"><?= te('Identifiant') ?></label>
          <input id="su" type="text" name="smtp_user" value="<?= e(Demo::hide((string)Config::get('notify.mail.smtp.user', ''))) ?>" autocomplete="off">
        </div>
        <div class="field" style="flex:1 1 180px">
          <label for="spw"><?= te('Mot de passe') ?></label>
          <input id="spw" type="password" name="smtp_pass" autocomplete="new-password"
                 placeholder="<?= Config::get('notify.mail.smtp.pass') ? t('•••••• (inchangé)') : '' ?>">
        </div>
      </div>
    </fieldset>

    <fieldset>
      <legend><?= te('Webhook générique') ?></legend>
      <label class="switchrow"><input type="checkbox" name="webhook_enabled" <?= Config::get('notify.webhook.enabled') ? 'checked' : '' ?>>
        <span class="sw-text"><span class="sw-title"><?= te('Envoyer un POST JSON à chaque alerte') ?></span>
          <span class="hint"><?= te('Pour brancher n8n, Make, Teams, un SMS…') ?></span></span></label>
      <div class="field">
        <label for="wu"><?= te('URL de destination') ?></label>
        <input id="wu" type="text" name="webhook_url" value="<?= e(Demo::hide((string)Config::get('notify.webhook.url', ''))) ?>"
               placeholder="<?= te('https://n8n.exemple.fr/webhook/uptimeez') ?>" spellcheck="false">
      </div>
    </fieldset>

    <fieldset>
      <legend><?= te('Politique d\'envoi') ?></legend>
      <div class="form-cols">
        <div>
          <div class="field">
            <label for="resend"><?= te('Rappel tant que ce n\'est pas résolu') ?></label>
            <div class="field-inline">
              <input id="resend" type="number" name="resend_after" min="0" max="1440"
                     value="<?= (int)Config::get('notify.resend_after_min', 60) ?>">
              <span class="unit"><?= te('minutes (0 = jamais)') ?></span>
            </div>
          </div>
          <div class="field">
            <label for="quiet"><?= te('Heures calmes') ?></label><?= hint('Pendant cette plage, les alertes « à surveiller » sont retenues et regroupées. Une vraie panne passe toujours : on ne dort pas sur un site hors service.') ?>
            <input id="quiet" type="text" name="quiet_hours" value="<?= e((string)Config::get('notify.quiet_hours', '')) ?>"
                   placeholder="23:00-07:00" style="max-width:200px">
            <span class="hint"><?= te('Les pannes réelles passent quand même ; seules les alertes « à surveiller » sont retenues.') ?></span>
          </div>
        </div>
        <div>
          <label class="switchrow"><input type="checkbox" name="notify_recovery" <?= Config::get('notify.notify_recovery', true) ? 'checked' : '' ?>>
            <span class="sw-text"><span class="sw-title"><?= te('Prévenir au rétablissement') ?></span>
              <span class="hint"><?= te('Avec la durée totale de l\'interruption.') ?></span></span></label>
          <label class="switchrow"><input type="checkbox" name="notify_degraded" <?= Config::get('notify.notify_degraded', true) ? 'checked' : '' ?>>
            <span class="sw-text"><span class="sw-title"><?= te('Prévenir sur état « à surveiller »') ?></span>
              <span class="hint"><?= te('Lenteur, certificat bientôt expiré, CSS suspect, noindex.') ?></span></span></label>
        </div>
      </div>
    </fieldset>
  <?= Ui::accClose() ?>

  <!-- ==================== VALEURS PAR DÉFAUT ==================== -->
  <?= Ui::accOpen('defaults', 'sliders', t('Valeurs par défaut des nouvelles sondes'),
        'intervalle ' . human_duration((int)Config::get('defaults.interval_sec', 300))
        . ' · ' . (int)Config::get('defaults.retries', 2) . ' relance(s)') ?>
  <?= Ui::accBody() ?>
    <div class="grid-3">
      <div class="field"><label for="di"><?= te('Intervalle') ?></label>
        <div class="field-inline"><input id="di" type="number" name="def_interval" min="30" value="<?= (int)Config::get('defaults.interval_sec', 300) ?>"><span class="unit">s</span></div></div>
      <div class="field"><label for="dt"><?= te('Délai maximum') ?></label>
        <div class="field-inline"><input id="dt" type="number" name="def_timeout" min="3" max="60" value="<?= (int)Config::get('defaults.timeout_sec', 15) ?>"><span class="unit">s</span></div></div>
      <div class="field"><label for="dr"><?= te('Relances avant alerte') ?></label><?= hint('Un site est déclaré hors service seulement après ce nombre d\'échecs consécutifs. C\'est ce qui évite d\'alerter sur un hoquet réseau de deux secondes.') ?>
        <input id="dr" type="number" name="def_retries" min="0" max="5" value="<?= (int)Config::get('defaults.retries', 2) ?>"></div>
      <div class="field"><label for="ds"><?= te('Alerte certificat') ?></label>
        <div class="field-inline"><input id="ds" type="number" name="def_ssl_days" min="1" max="120" value="<?= (int)Config::get('defaults.ssl_warn_days', 14) ?>"><span class="unit"><?= te('jours avant') ?></span></div></div>
      <div class="field"><label for="dl"><?= te('Seuil de lenteur') ?></label>
        <div class="field-inline"><input id="dl" type="number" name="def_slow" min="200" step="100" value="<?= (int)Config::get('defaults.slow_ms', 3000) ?>"><span class="unit">ms</span></div></div>
      <div class="field"><label for="dc"><?= te('Chute CSS tolérée') ?></label>
        <div class="field-inline"><input id="dc" type="number" name="def_css_drop" min="5" max="90" value="<?= (int)Config::get('defaults.css_drop_pct', 35) ?>"><span class="unit">%</span></div></div>
      <div class="field"><label for="dp"><?= te('Requêtes simultanées') ?></label><?= hint('Nombre de sites interrogés en parallèle à chaque passe. Plus haut = passe plus rapide, mais un hébergeur mutualisé peut brider les connexions sortantes.') ?>
        <input id="dp" type="number" name="def_parallel" min="1" max="20" value="<?= (int)Config::get('defaults.max_parallel', 10) ?>">
        <span class="hint"><?= te('Baissez à 5 si l\'hébergeur bride les connexions sortantes.') ?></span></div>
      <div class="field"><label for="dk"><?= te('Conservation des mesures') ?></label><?= hint('Durée de conservation des mesures détaillées. Les statistiques journalières, elles, sont gardées indéfiniment : les vues 6 mois et 1 an restent justes.') ?>
        <div class="field-inline"><input id="dk" type="number" name="def_retention" min="7" value="<?= (int)Config::get('defaults.retention_days', 60) ?>"><span class="unit"><?= te('jours') ?></span></div>
        <span class="hint"><?= te('Au-delà, seules les statistiques journalières sont conservées : elles alimentent les vues 6 mois et 1 an.') ?></span></div>
    </div>
  <?= Ui::accClose() ?>

  <!-- ==================== VITESSE RESSENTIE ==================== -->
  <?= Ui::accOpen('speed', 'chart', t('Vitesse ressentie par les visiteurs'),
        Uptimeez\Vitals::enabled() ? t('mesures de terrain activées') : t('analyse locale seulement')) ?>
  <?= Ui::accBody() ?>
    <p class="muted small prose"><?= te('Sans rien configurer, {app} mesure le temps de réponse du serveur et lit dans vos pages ce qui retarde l\'affichage : fichiers qui bloquent le rendu, image du haut de page en chargement différé, images sans dimensions. C\'est ce qui permet d\'agir.') ?></p>
    <p class="muted small prose"><?= te('Les trois mesures officielles (LCP, INP, CLS) viennent de vrais navigateurs Chrome, et de nulle part ailleurs. Pour les afficher, il faut une clé du Chrome UX Report : elle est gratuite et se crée en deux minutes. Sans clé, aucun chiffre n\'est inventé.') ?></p>
    <div class="form-cols">
      <div>
        <label class="switchrow"><input type="checkbox" name="vitals_enabled" <?= Config::get('vitals.enabled', true) ? 'checked' : '' ?>>
          <span class="sw-text"><span class="sw-title"><?= te('Récupérer les mesures de terrain') ?></span>
            <span class="hint"><?= te('Une interrogation par page et par jour, gardée 24 heures. Une page sans trafic suffisant n\'a pas de données : c\'est dit tel quel.') ?></span></span></label>
        <div class="field"><label for="ck"><?= te('Clé du Chrome UX Report') ?></label><?= hint('Console Google Cloud, activez « Chrome UX Report API », puis créez une clé d\'API. Elle ne donne accès qu\'à des données publiques d\'audience agrégée.') ?>
          <input id="ck" type="text" name="crux_key" spellcheck="false" class="mono"
                 value="<?= e((string)Config::get('vitals.crux_key', '')) ?>"
                 placeholder="<?= te('vide = analyse locale seulement') ?>">
        </div>
      </div>
      <div>
        <div class="field"><label for="ff"><?= te('Appareil de référence') ?></label><?= hint('Le Chrome UX Report sépare les mesures par type d\'appareil. Le téléphone est le bon défaut : c\'est là que les problèmes se voient, et c\'est ce que Google utilise pour classer.') ?>
          <select id="ff" name="form_factor">
            <?php foreach (['PHONE' => t('Téléphone'), 'DESKTOP' => t('Ordinateur')] as $k => $lbl): ?>
              <option value="<?= e($k) ?>"<?= Uptimeez\Vitals::formFactor() === $k ? ' selected' : '' ?>><?= e($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <p class="muted small"><?= te('Les seuils appliqués sont ceux de Google : 2,5 s pour l\'affichage du contenu principal, 200 ms pour la réaction au premier clic, 0,1 pour la stabilité de la mise en page.') ?></p>
      </div>
    </div>
  <?= Ui::accClose() ?>

  <!-- ==================== VEILLE ET SÉCURITÉ ==================== -->
  <?= Ui::accOpen('watch', 'shield', t('Veille de sécurité'),
        Config::get('vuln.enabled', true) ? t('activée') : t('désactivée')) ?>
  <?= Ui::accBody() ?>
    <p class="muted small"><?= te('Les versions sont lues dans le HTML déjà reçu, sans rien demander de plus au site.') ?>
      <?= te('Pour savoir si une version a une faille publiée, {app} interroge deux sources publiques et sans clé : OSV.dev et api.wordpress.org. Ce qui sort de chez vous se limite au nom du composant et à son numéro de version, jamais une adresse de site.') ?></p>
    <div class="form-cols">
      <div>
        <label class="switchrow"><input type="checkbox" name="vuln_enabled" <?= Config::get('vuln.enabled', true) ? 'checked' : '' ?>>
          <span class="sw-text"><span class="sw-title"><?= te('Croiser les versions avec les avis publiés') ?></span>
            <span class="hint"><?= te('Une interrogation par composant et par version, gardée sept jours. Un parc de cent sites ne produit pas cent requêtes par jour.') ?></span></span></label>
        <label class="switchrow"><input type="checkbox" name="block_private" <?= Config::get('security.block_private_ranges', false) ? 'checked' : '' ?>>
          <span class="sw-text"><span class="sw-title"><?= te('Refuser les adresses locales et privées') ?></span>
            <span class="hint"><?= te('Empêche de surveiller 127.0.0.1, un réseau interne ou l\'adresse de métadonnées de l\'hébergeur. À activer si plusieurs personnes ont accès à cet écran.') ?></span></span></label>
      </div>
      <div>
        <div class="field"><label for="vt"><?= te('Délai maximum des interrogations') ?></label><?= hint('Temps accordé à une source d\'avis pour répondre. Au-delà, la veille passe au composant suivant : elle ne retarde jamais une vérification de site.') ?>
          <div class="field-inline"><input id="vt" type="number" name="vuln_timeout" min="3" max="30"
                 value="<?= (int)Config::get('vuln.timeout_sec', 8) ?>"><span class="unit">s</span></div></div>
        <p class="muted small"><?= te('Deux signaux qui ne se mélangent pas. « Faille publiée » veut dire qu\'un avis de sécurité identifié couvre précisément cette version : l\'identifiant et le lien sont donnés. « Version en retard » veut dire que la version installée est antérieure à la dernière publiée, ce qui est une dette, pas une faille.') ?></p>
      </div>
    </div>
  <?= Ui::accClose() ?>

  <!-- ==================== APPLICATION ==================== -->
  <?= Ui::accOpen('app', 'globe', t('Application et accès'),
        $token !== '' ? t('page d\'état publique activée') : t('nom, adresse, mot de passe')) ?>
  <?= Ui::accBody() ?>
    <div class="form-cols">
      <div>
        <div class="field"><label for="an"><?= te('Nom affiché') ?></label>
          <input id="an" type="text" name="app_name" value="<?= e((string)Config::get('app.name', '{app}')) ?>"></div>
        <div class="field"><label for="bu"><?= te('Adresse de cette installation') ?></label>
          <input id="bu" type="text" name="base_url" value="<?= e($baseUrl) ?>" placeholder="<?= te('https://monitoring.votredomaine.fr') ?>" spellcheck="false">
          <span class="hint"><?= te('Sert à mettre un lien cliquable vers la fiche concernée dans les alertes.') ?></span></div>
        <div class="field"><label for="loc"><?= te('Langue de l\'installation') ?></label><?= hint('Langue des relevés techniques écrits par la tâche planifiée : eux sont enregistrés une fois pour toutes. L\'interface, elle, suit la langue de chaque visiteur. « Automatique » laisse le navigateur décider pour l\'interface, et l\'anglais pour les relevés.') ?>
          <select id="loc" name="locale">
            <option value="auto"<?= in_array((string)Config::get('app.locale', ''), ['', 'auto'], true) ? ' selected' : '' ?>>
              <?= te('Automatique') ?></option>
            <?php foreach (Uptimeez\I18n::available() as $code => $native): ?>
              <option value="<?= e($code) ?>"<?= (string)Config::get('app.locale', '') === $code ? ' selected' : '' ?>>
                <?= e(Uptimeez\I18n::flag($code)) ?> <?= e($native) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="field"><label for="tz"><?= te('Fuseau horaire') ?></label>
          <input id="tz" type="text" name="timezone" value="<?= e((string)Config::get('app.timezone', 'Europe/Paris')) ?>"></div>
      </div>
      <div>
        <div class="field"><label for="np"><?= te('Nouveau mot de passe') ?></label>
          <input id="np" type="password" name="new_password" autocomplete="new-password" placeholder="<?= te('laisser vide pour ne pas changer') ?>">
          <span class="hint"><?= te('8 caractères minimum.') ?></span></div>
        <div class="field"><label for="pt"><?= te('Jeton de la page d\'état publique') ?></label><?= hint('Une chaîne secrète qui ouvre une page d\'état sans mot de passe : vous la donnez à un client pour qu\'il voie ses sites, sans accès à {app}.') ?>
          <input id="pt" type="text" name="public_token" value="<?= e($token) ?>" placeholder="<?= te('vide = désactivée') ?>" spellcheck="false">
          <?php if ($token !== ''): ?>
            <span class="hint"><?= te('Accessible ici :') ?>
              <a href="<?= e(u('status', ['token' => $token])) ?>" target="_blank"><?= te('page d\'état publique') ?></a>
              · <?= te('à partager avec un client sans lui donner d\'accès.') ?></span>
          <?php else: ?>
            <span class="hint"><?= te('Renseignez une chaîne aléatoire pour publier un état public sans authentification.') ?></span>
          <?php endif; ?></div>
        <div class="field"><label for="ck"><?= te('Clé de déclenchement du cron par URL') ?></label>
          <input id="ck" type="text" name="cron_key" value="<?= e($cronKey) ?>" placeholder="<?= te('vide = désactivé') ?>" spellcheck="false"></div>
      </div>
    </div>
  <?= Ui::accClose() ?>

  <div class="savebar" data-savebar hidden>
    <span class="sb-note"><?= Ui::icon('info', 15) ?> <?= te('Modifications non enregistrées') ?></span>
    <span class="grow"></span>
    <button type="button" class="btn btn-sm" data-reset-form><?= te('Annuler') ?></button>
    <button class="btn btn-primary btn-sm"><?= te('Enregistrer les réglages') ?></button>
  </div>
  <div class="row mt" data-static-save>
    <button class="btn btn-primary"><?= te('Enregistrer les réglages') ?></button>
    <span class="muted small"><?= te('Écrit dans {file}.', ['file' => '<span class="mono">config.php</span>']) ?></span>
  </div>
</form>

<?php
// Un index absent ne casse rien tout de suite : il rend l'outil lent des
// semaines plus tard, quand personne ne fait le lien. On le dit maintenant.
$idxIssues = Uptimeez\Db::indexIssues();
if ($idxIssues): ?>
  <div class="alert alert-warn mt">
    <?= Ui::icon('alert', 18) ?>
    <div><strong><?= e(tn(count($idxIssues), 'Un index de base de données n\'a pas pu être créé.',
                                             '{n} index de base de données n\'ont pas pu être créés.')) ?></strong>
      <div class="hint"><?= te('L\'outil fonctionne, mais les écrans ralentiront à mesure que l\'historique grossit. Le détail est ci-dessous : à donner tel quel à votre hébergeur.') ?></div>
      <ul class="tiny mono" style="margin:6px 0 0;padding-inline-start:18px">
        <?php foreach (array_slice($idxIssues, 0, 6) as $iss): ?><li><?= e($iss) ?></li><?php endforeach; ?>
      </ul>
    </div>
  </div>
<?php endif; ?>

<!-- ==================== TESTS ==================== -->
<?= Ui::accOpen('tests', 'wrench', t('Tester les canaux et la détection'), t('utile après chaque changement')) ?>
<?= Ui::accBody() ?>
  <div class="row" style="gap:8px;flex-wrap:wrap">
    <?php foreach ($channels as $ch => $label): ?>
      <form method="post" style="display:inline">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="test_notify">
        <input type="hidden" name="channel" value="<?= $ch ?>">
        <button class="btn btn-sm" <?= Config::get("notify.$ch.enabled") ? '' : 'disabled title="' . te('Canal désactivé') . '"' ?>>
          <?= Ui::icon('bell', 14) ?> <?= te('Tester') ?> <?= e($label) ?>
        </button>
      </form>
    <?php endforeach; ?>
  </div>
  <p class="hint mt"><?= te('Le résultat s\'affiche en haut de page, et reste consultable dans le {journal}.',
        ['journal' => '<a href="' . e(u('events')) . '">' . te('journal') . '</a>']) ?></p>
  <div class="section-title"><?= te('Vérifier la détection sur ce serveur') ?></div>
  <pre class="mono small" style="white-space:pre-wrap;margin:0;color:var(--text-soft)">php <?= e(UPTIMEEZ_ROOT) ?>/bin/selftest.php   # <?= te('logique de détection, hors ligne') ?>
php <?= e(UPTIMEEZ_ROOT) ?>/bin/bench.php      # <?= te('pannes reproduites de bout en bout') ?>
php <?= e(UPTIMEEZ_ROOT) ?>/bin/e2e.php        # <?= te('parcours complet de l\'interface') ?></pre>
<?= Ui::accClose() ?>
