<?php
/**
 * Page editor.
 * @var array $page saved page
 * @var array $form values shown in the form
 * @var array $blocks
 * @var array $mediaJson
 * @var array $errors
 * @var array $parentOptions
 * @var int $depth
 */
$isHome = (int) $page['is_home'] === 1;
$published = $form['status'] === 'published';
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$fieldLabels = [
    'title' => 'Titre', 'slug' => 'Adresse', 'parent_id' => 'Page parente', 'status' => 'État',
    'seo_title' => 'Titre pour les moteurs de recherche', 'seo_description' => 'Description',
    'header_media_id' => 'Image d\'en-tête', 'blocks' => 'Contenu', 'make_home' => 'Page d\'accueil', 'conflict' => 'Conflit',
];
?>
<div class="title-row">
  <h1><?= e($page['title']) ?></h1>
  <p class="title-links">
    <a href="/admin/pages">← Toutes les pages</a>
    <a href="/admin/pages/<?= (int) $page['id'] ?>/historique">Historique</a>
  </p>
</div>

<?php if ($errors): ?>
<div class="notice notice-error" role="alert" tabindex="-1" id="error-summary">
  <p><strong>La page n'est pas enregistrée.</strong> Corrigez ces points :</p>
  <ul>
    <?php foreach ($errors as $field => $message): ?>
      <li><?php if ($field !== 'conflict' && $field !== 'blocks'): ?><a href="#f-<?= e($field) ?>"><?= e($fieldLabels[$field] ?? $field) ?></a> : <?php endif; ?><?= e($message) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<noscript><div class="notice notice-error">L'éditeur de contenu a besoin de JavaScript. Activez-le dans votre navigateur.</div></noscript>

<form method="post" action="/admin/pages/<?= (int) $page['id'] ?>" id="page-form" class="editor-layout" data-unsaved-warning>
  <?= csrf_field() ?>
  <input type="hidden" name="loaded_updated_at" value="<?= e($form['updated_at']) ?>">
  <input type="hidden" name="blocks_json" id="blocks-json" value="">

  <div class="editor-main">
    <div class="field">
      <label for="f-title">Titre de la page</label>
      <input id="f-title" name="title" type="text" required maxlength="200" value="<?= e($form['title']) ?>" class="input-large"<?= isset($errors['title']) ? ' aria-invalid="true" aria-describedby="err-title"' : '' ?>>
      <?= field_error($errors, 'title') ?>
      <?php if ($isHome): ?><p class="help">Sur la page d'accueil, le grand titre affiché est le nom du site (Réglages).</p><?php endif; ?>
    </div>

    <h2 class="section-title">Contenu</h2>
    <div id="block-editor" class="block-editor" aria-describedby="blocks-help"></div>
    <p id="blocks-help" class="help">Ajoutez des blocs de texte, des grilles de cartes ou des vidéos. Utilisez les flèches pour changer leur ordre.</p>
    <script type="application/json" id="blocks-data"><?= json_encode($blocks, $jsonFlags) ?></script>
    <script type="application/json" id="media-data"><?= json_encode((object) $mediaJson, $jsonFlags) ?></script>
  </div>

  <aside class="editor-side" aria-label="Réglages de la page">
    <div class="panel sticky-panel">
      <fieldset class="field">
        <legend>État</legend>
        <?php if ($isHome): ?>
          <input type="hidden" name="status" value="published">
          <p><span class="badge badge-ok">Publiée</span> <span class="badge badge-home">Page d'accueil</span></p>
        <?php else: ?>
          <label class="radio"><input type="radio" name="status" value="hidden" <?= $published ? '' : 'checked' ?>> Brouillon (masquée)</label>
          <label class="radio"><input type="radio" name="status" value="published" <?= $published ? 'checked' : '' ?>> Publiée</label>
        <?php endif; ?>
        <?= field_error($errors, 'status') ?>
      </fieldset>
      <div class="button-row">
        <button type="submit" class="btn btn-primary">Enregistrer</button>
        <a class="btn btn-secondary" href="<?= e(Pages::path($page)) ?>" target="_blank" rel="noopener">Voir la page<span class="visually-hidden"> (nouvel onglet)</span></a>
      </div>
      <p class="help">Dernière modification : <?= e(format_date_fr((string) $page['updated_at'])) ?><?= $page['updated_by'] !== '' ? ' par ' . e($page['updated_by']) : '' ?>.</p>
    </div>

    <details class="panel" open>
      <summary>Adresse et place dans le site</summary>
      <div class="field">
        <label for="f-slug">Adresse de la page</label>
        <div class="input-prefix"><span><?= e(preg_replace('~^https?://~', '', Config::baseUrl())) ?>/</span><input id="f-slug" name="slug" type="text" maxlength="80" value="<?= e($form['slug']) ?>"<?=isset($errors['slug']) ? ' aria-invalid="true" aria-describedby="err-slug"' : '' ?>></div>
        <?= field_error($errors, 'slug') ?>
        <p class="help">Minuscules, sans accents, mots séparés par des tirets. Si vous la changez, l'ancienne adresse redirige vers la nouvelle.</p>
      </div>
      <?php if (!$isHome): ?>
      <div class="field">
        <label for="f-parent_id">Page parente</label>
        <select id="f-parent_id" name="parent_id">
          <option value="">— Aucune (page principale) —</option>
          <?php foreach ($parentOptions as $option): ?>
            <option value="<?= (int) $option['id'] ?>" <?= (string) $form['parent_id'] === (string) $option['id'] ? 'selected' : '' ?>><?= str_repeat('— ', (int) $option['depth'] - 1) . e($option['title']) ?></option>
          <?php endforeach; ?>
        </select>
        <?= field_error($errors, 'parent_id') ?>
      </div>
      <label class="checkbox"><input type="checkbox" name="show_in_menu" value="1" <?= (int) $form['show_in_menu'] === 1 ? 'checked' : '' ?>> <span>Afficher dans le menu principal <span class="help-inline">(pages principales seulement)</span></span></label>
      <?php else: ?>
        <input type="hidden" name="show_in_menu" value="<?= (int) $form['show_in_menu'] ?>">
      <?php endif; ?>
      <label class="checkbox"><input type="checkbox" name="show_in_footer" value="1" <?= (int) $form['show_in_footer'] === 1 ? 'checked' : '' ?>> Afficher dans le pied de page</label>
      <?php if (!$isHome): ?>
      <label class="checkbox" id="f-make_home"><input type="checkbox" name="make_home" value="1" <?= ($form['make_home'] ?? '') === '1' ? 'checked' : '' ?>> En faire la page d'accueil</label>
      <?= field_error($errors, 'make_home') ?>
      <?php endif; ?>
    </details>

    <details class="panel" open>
      <summary>Image d'en-tête</summary>
      <div class="field" id="f-header_media_id">
        <div class="media-field" data-media-field data-label="image d'en-tête">
          <input type="hidden" name="header_media_id" value="<?= e((string) ($form['header_media_id'] ?? '')) ?>">
        </div>
        <?= field_error($errors, 'header_media_id') ?>
        <p class="help">Facultative. Affichée en haut de la page et lors des partages sur les réseaux sociaux.</p>
      </div>
    </details>

    <details class="panel"<?= isset($errors['seo_title']) || isset($errors['seo_description']) ? ' open' : '' ?>>
      <summary>Moteurs de recherche et partage</summary>
      <div class="field">
        <label for="f-seo_title">Titre (onglet du navigateur, Google)</label>
        <input id="f-seo_title" name="seo_title" type="text" maxlength="120" value="<?= e($form['seo_title']) ?>" data-counter="60" placeholder="<?= e($page['title'] . ' — ' . Settings::get('site_name')) ?>">
        <?= field_error($errors, 'seo_title') ?>
        <p class="help">Facultatif. Idéalement moins de 60 caractères.</p>
      </div>
      <div class="field">
        <label for="f-seo_description">Description</label>
        <textarea id="f-seo_description" name="seo_description" rows="4" maxlength="300" data-counter="160"><?= e($form['seo_description']) ?></textarea>
        <?= field_error($errors, 'seo_description') ?>
        <p class="help">Facultative. Une ou deux phrases qui résument la page (moins de 160 caractères). Sinon, le début du texte est utilisé.</p>
      </div>
    </details>
  </aside>
</form>
