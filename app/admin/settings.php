<?php
/** Site settings form (admin only). @var array $values @var array $errors @var array $mediaJson */
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$groups = [
    'L\'association' => ['site_name', 'tagline', 'site_description', 'contact_email'],
    'Images' => ['logo_media_id', 'favicon_media_id', 'og_media_id'],
    'HelloAsso (dons et adhésions)' => ['helloasso_url', 'helloasso_don_url', 'helloasso_adhesion_url'],
    'Pied de page' => ['footer_text'],
];
?>
<h1>Réglages du site</h1>
<?php if ($errors): ?>
  <div class="notice notice-error" role="alert">Les réglages ne sont pas enregistrés. Corrigez les champs signalés.</div>
<?php endif; ?>
<form method="post" action="/admin/reglages" class="settings-form" data-unsaved-warning>
  <?= csrf_field() ?>
  <script type="application/json" id="media-data"><?= json_encode((object) $mediaJson, $jsonFlags) ?></script>
  <?php foreach ($groups as $groupTitle => $keys): ?>
  <fieldset class="panel">
    <legend><?= e($groupTitle) ?></legend>
    <?php foreach ($keys as $key): ?>
      <?php list($label, $type) = Settings::FIELDS[$key]; $id = 'f-' . $key; $invalid = isset($errors[$key]) ? ' aria-invalid="true" aria-describedby="err-' . e($key) . '"' : ''; ?>
      <div class="field">
        <?php if ($type === 'media'): ?>
          <span class="label" id="<?= e($id) ?>-label"><?= e($label) ?></span>
          <div class="media-field" data-media-field data-label="<?= e(mb_strtolower($label, 'UTF-8')) ?>" aria-labelledby="<?= e($id) ?>-label">
            <input type="hidden" name="<?= e($key) ?>" value="<?= e($values[$key]) ?>">
          </div>
        <?php elseif ($type === 'textarea'): ?>
          <label for="<?= e($id) ?>"><?= e($label) ?></label>
          <textarea id="<?= e($id) ?>" name="<?= e($key) ?>" rows="3" maxlength="1000"<?= $invalid ?>><?= e($values[$key]) ?></textarea>
        <?php else: ?>
          <label for="<?= e($id) ?>"><?= e($label) ?></label>
          <input id="<?= e($id) ?>" name="<?= e($key) ?>" type="<?= $type === 'email' ? 'email' : ($type === 'url' ? 'url' : 'text') ?>" maxlength="300" value="<?= e($values[$key]) ?>"<?= $key === 'site_name' ? ' required' : '' ?><?= $invalid ?>>
        <?php endif; ?>
        <?= field_error($errors, $key) ?>
      </div>
    <?php endforeach; ?>
  </fieldset>
  <?php endforeach; ?>
  <p class="help">Le logo s'affiche en haut de chaque page. Sans logo choisi ici, le logo fourni avec le site est utilisé. Image de partage : idéalement 1200 × 630 px.</p>
  <button type="submit" class="btn btn-primary">Enregistrer les réglages</button>
</form>
