<?php
/** Écran de connexion. */
use Uptimeez\Config;
use Uptimeez\Ui;
?>
<div style="max-width:360px;margin:11vh auto 0">
  <div class="row" style="justify-content:center;margin-bottom:20px;gap:9px">
    <?= Ui::icon('pulse', 26) ?>
    <span style="font-size:20px;font-weight:700;letter-spacing:-.02em"><?= e((string)Config::get('app.name', 'Uptimeez')) ?></span>
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
          <div><?= te('Démonstration : le mot de passe est {password}. Tout est remis à zéro chaque heure.',
                      ['password' => 'demo1234']) ?></div>
        </div>
      <?php endif; ?>
      <form method="post">
        <div class="field">
          <label for="pw"><?= te('Mot de passe') ?></label>
          <input id="pw" type="password" name="password" autofocus required autocomplete="current-password">
        </div>
        <button class="btn btn-primary" style="width:100%"><?= te('Entrer') ?></button>
      </form>
    </div>
  </div>
  <p class="tiny muted center mt"><?= te('Surveillance de sites · accès restreint') ?></p>
</div>
