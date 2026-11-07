<?php
/** Site settings form (admin only). @var array $values @var array $errors @var array $mediaJson */
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$groups = [
    'L\'association' => ['site_name', 'tagline', 'site_description', 'contact_email'],
    'Images' => ['logo_media_id', 'favicon_media_id', 'og_media_id'],
    'HelloAsso (dons et adhésions)' => ['helloasso_url', 'helloasso_don_url', 'helloasso_adhesion_url'],
    'Page d\'accueil' => ['home_projects_slug'],
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
  <fieldset class="panel" id="f-fig">
    <legend>Chiffres clés</legend>
    <p class="help">Les chiffres qui changent chaque année (après l'assemblée générale). Dans un texte, <code>{{fig:clé}}</code> affiche la valeur et l'unité. Cochez « Accueil » pour l'afficher dans le bandeau de chiffres de la page d'accueil. Pour supprimer un chiffre, cochez « Supprimer ».</p>
    <?= field_error($errors, 'fig') ?>
    <div class="table-scroll">
    <table class="figures-table">
      <thead><tr><th scope="col">Libellé</th><th scope="col">Clé</th><th scope="col">Valeur</th><th scope="col">Unité</th><th scope="col">Accueil</th><th scope="col">Supprimer</th></tr></thead>
      <tbody>
      <?php $rows = array_merge($figures, array_fill(0, 3, ['fig_key' => '', 'label' => '', 'value' => '', 'unit' => '', 'on_home' => 0])); ?>
      <?php foreach ($rows as $i => $fig): ?>
        <?php $n = 'fig[' . $i . ']'; $isNew = $fig['fig_key'] === ''; ?>
        <tr<?= $isNew ? ' class="figure-new"' : '' ?>>
          <td><input type="text" name="<?= $n ?>[label]" value="<?= e($fig['label']) ?>" maxlength="120" aria-label="Libellé<?= $isNew ? ' (nouveau chiffre)' : ' de ' . e($fig['fig_key']) ?>"></td>
          <td><input type="text" name="<?= $n ?>[key]" value="<?= e($fig['fig_key']) ?>" maxlength="60" pattern="[a-z0-9_]+" aria-label="Clé<?= $isNew ? ' (nouveau chiffre)' : ' de ' . e($fig['fig_key']) ?>"<?= $isNew ? ' placeholder="nouvelle_cle"' : '' ?>></td>
          <td><input type="text" name="<?= $n ?>[value]" value="<?= e($fig['value']) ?>" maxlength="120" aria-label="Valeur<?= $isNew ? ' (nouveau chiffre)' : ' de ' . e($fig['fig_key']) ?>"></td>
          <td><input type="text" name="<?= $n ?>[unit]" value="<?= e($fig['unit']) ?>" maxlength="20" class="input-short" aria-label="Unité<?= $isNew ? ' (nouveau chiffre)' : ' de ' . e($fig['fig_key']) ?>"></td>
          <td><input type="checkbox" name="<?= $n ?>[on_home]" value="1"<?= (int) $fig['on_home'] === 1 ? ' checked' : '' ?> aria-label="Afficher <?= $isNew ? 'le nouveau chiffre' : e($fig['fig_key']) ?> sur l'accueil"></td>
          <td><?php if (!$isNew): ?><input type="checkbox" name="<?= $n ?>[delete]" value="1" aria-label="Supprimer <?= e($fig['fig_key']) ?>"><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <p class="help">Une valeur vide n'est affichée nulle part. Clé : minuscules sans accents, chiffres et _ (elle ne change pas quand la valeur change).</p>
  </fieldset>
  <p class="help">Le logo s'affiche en haut de chaque page. Sans logo choisi ici, le logo fourni avec le site est utilisé. Image de partage : idéalement 1200 × 630 px.</p>
  <button type="submit" class="btn btn-primary">Enregistrer les réglages</button>
</form>
