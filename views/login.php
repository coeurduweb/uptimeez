<?php
/** Écran de connexion. */
use Uptimeez\I18n;
use Uptimeez\Config;
use Uptimeez\Ui;
?>
<div style="max-width:360px;margin:11vh auto 0">
  <div class="row" style="justify-content:center;margin-bottom:20px;gap:9px">
    <?= Ui::brand(26) ?>
    <span style="font-size:20px;font-weight:700;letter-spacing:-.02em"><?= e((string)Config::get('app.name', I18n::APP)) ?></span>
  </div>
  <div class="panel" style="margin-top:0">
    <div class="panel-body">
      <?php if (!empty($error)): ?>
        <div class="alert alert-bad" role="alert" style="margin-top:0">
          <?= Ui::icon('alert', 18) ?><div><?= e($error) ?></div>
        </div>
      <?php endif; ?>
      <?php if (Uptimeez\Demo::on()): ?>
        <?php /* Une démo publique doit donner sa clé : la chercher ailleurs fait
                 partir le visiteur. Le mot de passe n'y protège rien, il évite
                 seulement qu'un robot indexe l'intérieur. */ ?>
        <div class="alert alert-warn" role="note" style="margin-top:0">
          <?= Ui::icon('info', 18) ?>
          <div><?= te('Démonstration : le mot de passe est {password}. Tout est remis à zéro toutes les {minutes} minutes.',
                      ['password' => 'demo1234', 'minutes' => (string)\Uptimeez\Demo::cadenceMinutes()]) ?></div>
        </div>
      <?php endif; ?>
      <?php if (!empty($info)): ?>
        <div class="alert alert-ok" role="status" style="margin-top:0">
          <?= Ui::icon('check', 18) ?><div><?= e($info) ?></div>
        </div>
      <?php endif; ?>

      <?php if (!empty($ecranOubli)): ?>
        <?php // On demande l'identifiant et non l'adresse : l'adresse d'un compte n'est
              // pas forcément connue de celui qui l'a oublié, et une adresse saisie ici
              // permettrait de tester quelles adresses sont enregistrées. ?>
        <form method="post">
          <div class="field">
            <label for="ident-oubli"><?= te('Identifiant') ?></label>
            <input id="ident-oubli" type="text" name="identifiant" autofocus required autocomplete="username">
          </div>
          <button class="btn btn-primary" style="width:100%"><?= te('Envoyer le lien') ?></button>
        </form>
        <p class="tiny center mt"><a href="<?= e(u('login')) ?>"><?= te('Revenir à la connexion') ?></a></p>

      <?php elseif (!empty($jetonReinit)): ?>
        <form method="post">
          <div class="field">
            <label for="pw-neuf"><?= te('Nouveau mot de passe') ?></label>
            <input id="pw-neuf" type="password" name="password" autofocus required
                   minlength="<?= (int)Uptimeez\Compte::MDP_MIN ?>" autocomplete="new-password">
          </div>
          <p class="tiny muted"><?= e(t('{n} caractères au minimum.', ['n' => Uptimeez\Compte::MDP_MIN])) ?></p>
          <button class="btn btn-primary" style="width:100%"><?= te('Changer le mot de passe') ?></button>
        </form>

      <?php else: ?>
      <?php
      // DEUX ÉCRANS, SELON QU'IL EXISTE OU NON DES COMPTES.
      //
      // Une instance sans compte garde son écran d'origine, à un seul champ. Montrer un
      // champ « identifiant » là où il n'y a rien à saisir enfermerait dehors, à la
      // première mise à jour du moteur, tous ceux qui n'ont jamais créé de compte — c'est
      //-à-dire toutes les installations existantes.
      //
      // Dès qu'un compte existe, l'écran passe à deux champs, et le mot de passe
      // d'instance devient un accès de SECOURS : il fonctionne toujours, il est nommé, et
      // son usage est consigné. Un accès de secours dont on ne sait pas qu'il a servi
      // n'est pas un secours, c'est une porte dérobée.
      $avecComptes = Uptimeez\Compte::existe();
      ?>
      <form method="post">
        <?php if ($avecComptes): ?>
          <div class="field">
            <label for="ident"><?= te('Identifiant') ?></label>
            <input id="ident" type="text" name="identifiant" autofocus required autocomplete="username">
          </div>
        <?php endif; ?>
        <div class="field">
          <label for="pw"><?= te('Mot de passe') ?></label>
          <input id="pw" type="password" name="password" <?= $avecComptes ? '' : 'autofocus' ?> required autocomplete="current-password">
        </div>
        <button class="btn btn-primary" style="width:100%"><?= te('Entrer') ?></button>
      </form>
      <?php if ($avecComptes): ?>
        <p class="tiny center mt"><a href="<?= e(u('login', ['oublie' => 1])) ?>"><?= te('Mot de passe oublié ?') ?></a></p>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
  <p class="tiny muted center mt"><?= te('Surveillance de sites · accès restreint') ?></p>
</div>
