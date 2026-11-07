<?php
/** One media file: description, replace, usage, delete. @var array $media @var array $usage */
$isImage = $media['kind'] === 'image';
list($w, $h) = Media::displaySize($media);
?>
<div class="title-row">
  <h1><?= e($media['original_name'] !== '' ? $media['original_name'] : 'Fichier') ?></h1>
  <p class="title-links"><a href="/admin/medias">← Médiathèque</a></p>
</div>

<div class="media-detail">
  <div class="media-preview">
    <?php if ($isImage): ?>
      <img src="<?= e(Media::url($media, 960)) ?>" alt="" width="<?= $w ?>" height="<?= $h ?>">
    <?php else: ?>
      <span class="media-pdf media-pdf-large" aria-hidden="true">PDF</span>
    <?php endif; ?>
    <p class="help">
      <?= $isImage ? 'Original : ' . (int) $media['width'] . ' × ' . (int) $media['height'] . ' px, ' : '' ?>
      <?= e(Media::formatSize((int) $media['size_bytes'])) ?>.
      Ajouté le <?= e(format_date_fr((string) $media['created_at'], false)) ?><?= $media['uploaded_by'] !== '' ? ' par ' . e($media['uploaded_by']) : '' ?>.
    </p>
    <p><a href="<?= e(Media::url($media, 1600)) ?>" target="_blank" rel="noopener">Ouvrir le fichier<span class="visually-hidden"> (nouvel onglet)</span></a></p>
    <?php if (!$isImage): ?>
    <div class="field">
      <label for="pdf-url">Adresse du fichier (pour un lien)</label>
      <input id="pdf-url" type="text" readonly value="<?= e(absolute_url('/media/' . $media['token'] . '.pdf')) ?>">
    </div>
    <?php endif; ?>
  </div>

  <div class="media-forms">
    <?php if ($isImage): ?>
    <form method="post" action="/admin/medias/<?= (int) $media['id'] ?>" class="panel">
      <?= csrf_field() ?>
      <h2>Description</h2>
      <div class="field">
        <label for="alt">Texte alternatif</label>
        <input id="alt" name="alt" type="text" maxlength="300" value="<?= e($media['alt']) ?>" aria-describedby="alt-help">
        <p class="help" id="alt-help">Décrivez ce que montre l'image, en une phrase, pour les personnes qui ne la voient pas. Laissez vide si l'image est purement décorative.</p>
      </div>
      <button type="submit" class="btn btn-primary">Enregistrer</button>
    </form>
    <?php endif; ?>

    <form method="post" action="/admin/medias/<?= (int) $media['id'] ?>/remplacer" enctype="multipart/form-data" class="panel">
      <?= csrf_field() ?>
      <h2>Remplacer le fichier</h2>
      <p class="help">Le nouveau fichier prend la place de l'ancien partout où il est utilisé. Les liens restent valables.</p>
      <div class="field">
        <label for="replace-file">Nouveau fichier (<?= $isImage ? 'image JPG, PNG ou WebP' : 'PDF' ?>)</label>
        <input id="replace-file" name="file" type="file" required accept="<?= $isImage ? 'image/jpeg,image/png,image/webp' : 'application/pdf' ?>">
      </div>
      <button type="submit" class="btn btn-secondary">Remplacer</button>
    </form>

    <section class="panel">
      <h2>Utilisé dans</h2>
      <?php if ($usage): ?>
        <ul>
          <?php foreach ($usage as $place): ?>
            <li><a href="<?= e($place['url']) ?>"><?= e($place['label']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p>Ce fichier n'est utilisé nulle part<?= $isImage ? '' : ' dans les pages (un lien peut toutefois exister ailleurs)' ?>.</p>
      <?php endif; ?>
    </section>

    <form method="post" action="/admin/medias/<?= (int) $media['id'] ?>/supprimer" class="panel panel-danger">
      <?= csrf_field() ?>
      <h2>Supprimer</h2>
      <?php if ($usage): ?>
        <p class="warn-text"><strong>Attention :</strong> ce fichier est utilisé dans <?= count($usage) ?> endroit(s). Il disparaîtra de ces pages.</p>
        <label class="checkbox"><input type="checkbox" name="confirm_in_use" value="1" required> Je comprends, supprimer quand même</label>
      <?php else: ?>
        <p>La suppression est définitive.</p>
      <?php endif; ?>
      <button type="submit" class="btn btn-danger">Supprimer le fichier</button>
    </form>
  </div>
</div>
