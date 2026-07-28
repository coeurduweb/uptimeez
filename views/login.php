<?php
/** Écran de connexion. */
use Uptimer\Config;
use Uptimer\Ui;
?>
<div style="max-width:360px;margin:11vh auto 0">
  <div class="row" style="justify-content:center;margin-bottom:20px;gap:9px">
    <?= Ui::icon('pulse', 26) ?>
    <span style="font-size:20px;font-weight:700;letter-spacing:-.02em"><?= e((string)Config::get('app.name', 'Uptimer')) ?></span>
  </div>
  <div class="panel" style="margin-top:0">
    <div class="panel-body">
      <?php if (!empty($error)): ?>
        <div class="alert alert-bad" role="alert" style="margin-top:0">
          <?= Ui::icon('alert', 18) ?><div><?= e($error) ?></div>
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
