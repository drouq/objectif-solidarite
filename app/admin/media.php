<?php
/** Media library grid. @var array $items @var array $usage */
?>
<h1>Médiathèque</h1>

<section class="panel" aria-labelledby="upload-title">
  <h2 id="upload-title">Ajouter un fichier</h2>
  <form method="post" action="/admin/medias" enctype="multipart/form-data" class="form-inline">
    <?= csrf_field() ?>
    <div class="field">
      <label for="upload-file">Fichier</label>
      <input id="upload-file" name="file" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" required aria-describedby="upload-help">
    </div>
    <div class="field">
      <label for="upload-alt">Description de l'image <span class="help-inline">(pour les personnes aveugles)</span></label>
      <input id="upload-alt" name="alt" type="text" maxlength="300">
    </div>
    <button type="submit" class="btn btn-primary">Envoyer</button>
  </form>
  <p class="help" id="upload-help">Images JPG, PNG ou WebP jusqu'à <?= e(Media::formatSize(Config::maxBytes('image'))) ?> (elles sont réduites automatiquement pour le web). PDF jusqu'à <?= e(Media::formatSize(Config::maxBytes('pdf'))) ?>. Pas de vidéos : utilisez un bloc « Vidéo » avec un lien YouTube ou Vimeo.</p>
</section>

<?php if (!$items): ?>
  <p>Aucun fichier pour l'instant.</p>
<?php else: ?>
<ul class="media-grid" role="list">
  <?php foreach ($items as $item): ?>
    <?php $count = $usage[(int) $item['id']] ?? 0; ?>
    <li class="media-item">
      <a href="/admin/medias/<?= (int) $item['id'] ?>" class="media-link">
        <span class="media-thumb">
          <?php if ($item['kind'] === 'image'): ?>
            <img src="<?= e(Media::url($item, 480)) ?>" alt="" loading="lazy" width="240" height="160">
          <?php else: ?>
            <span class="media-pdf" aria-hidden="true">PDF</span>
          <?php endif; ?>
        </span>
        <span class="media-name"><?= e($item['original_name'] !== '' ? $item['original_name'] : 'Sans nom') ?></span>
      </a>
      <span class="media-meta">
        <?= e(Media::formatSize((int) $item['size_bytes'])) ?>
        · <?= $count > 0 ? 'utilisé ' . $count . ' fois' : 'non utilisé' ?>
        <?php if ($item['kind'] === 'image' && $item['alt'] === ''): ?> · <span class="warn-text">sans description</span><?php endif; ?>
      </span>
    </li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>
