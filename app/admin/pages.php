<?php
/**
 * Page tree.
 * @var array $tree
 * @var array $parentOptions
 */
?>
<div class="title-row">
  <h1>Pages</h1>
</div>

<section class="panel" aria-labelledby="new-page-title">
  <h2 id="new-page-title">Nouvelle page</h2>
  <form method="post" action="/admin/pages/nouvelle" class="form-inline">
    <?= csrf_field() ?>
    <div class="field">
      <label for="new-title">Titre de la page</label>
      <input id="new-title" name="title" type="text" required maxlength="200">
    </div>
    <div class="field">
      <label for="new-parent">Placer sous</label>
      <select id="new-parent" name="parent_id">
        <option value="">— Aucune (page principale, dans le menu) —</option>
        <?php foreach ($parentOptions as $option): ?>
          <option value="<?= (int) $option['id'] ?>"><?= str_repeat('— ', (int) $option['depth'] - 1) . e($option['title']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn btn-primary">Créer la page</button>
  </form>
</section>

<div class="table-wrap">
<table class="pages-table">
  <caption class="visually-hidden">Toutes les pages du site, dans l'ordre du menu</caption>
  <thead>
    <tr>
      <th scope="col">Page</th>
      <th scope="col">État</th>
      <th scope="col">Ordre</th>
      <th scope="col">Actions</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($tree as $p): ?>
    <?php $published = $p['status'] === 'published'; $isHome = (int) $p['is_home'] === 1; ?>
    <tr id="page-<?= (int) $p['id'] ?>" class="depth-<?= (int) $p['depth'] ?>">
      <td class="page-cell">
        <span class="page-indent" aria-hidden="true"><?= $p['depth'] > 1 ? str_repeat('<span class="indent"></span>', (int) $p['depth'] - 1) . '↳ ' : '' ?></span>
        <a class="page-title" href="/admin/pages/<?= (int) $p['id'] ?>"><?= e($p['title']) ?></a>
        <span class="page-path"><?= e(Pages::path($p)) ?></span>
        <?php if ($p['depth'] > 1): ?><span class="visually-hidden">(niveau <?= (int) $p['depth'] ?>)</span><?php endif; ?>
      </td>
      <td>
        <span class="badge <?= $published ? 'badge-ok' : 'badge-muted' ?>"><?= $published ? 'Publiée' : 'Masquée' ?></span>
        <?php if ($isHome): ?><span class="badge badge-home">Accueil</span><?php endif; ?>
        <?php if ((int) $p['show_in_menu'] === 1 && $p['parent_id'] === null && !$isHome): ?><span class="badge badge-info">Menu</span><?php endif; ?>
        <?php if ((int) $p['show_in_footer'] === 1): ?><span class="badge badge-info">Pied de page</span><?php endif; ?>
      </td>
      <td class="order-cell">
        <form method="post" action="/admin/pages/<?= (int) $p['id'] ?>/deplacer" class="inline-form">
          <?= csrf_field() ?>
          <button type="submit" name="direction" value="up" class="icon-button" <?= $p['is_first'] ? 'disabled' : '' ?> aria-label="Monter « <?= e($p['title']) ?> »" title="Monter">↑</button>
          <button type="submit" name="direction" value="down" class="icon-button" <?= $p['is_last'] ? 'disabled' : '' ?> aria-label="Descendre « <?= e($p['title']) ?> »" title="Descendre">↓</button>
        </form>
      </td>
      <td class="actions-cell">
        <a href="/admin/pages/<?= (int) $p['id'] ?>">Modifier</a>
        <a href="<?= e(Pages::path($p)) ?>" target="_blank" rel="noopener">Voir<span class="visually-hidden"> « <?= e($p['title']) ?> » (nouvel onglet)</span></a>
        <?php if (!$isHome): ?>
        <form method="post" action="/admin/pages/<?= (int) $p['id'] ?>/statut" class="inline-form">
          <?= csrf_field() ?>
          <input type="hidden" name="status" value="<?= $published ? 'hidden' : 'published' ?>">
          <button type="submit" class="button-link"><?= $published ? 'Masquer' : 'Publier' ?><span class="visually-hidden"> « <?= e($p['title']) ?> »</span></button>
        </form>
        <?php endif; ?>
        <a href="/admin/pages/<?= (int) $p['id'] ?>/historique">Historique</a>
        <?php if (!$isHome): ?>
        <a href="/admin/pages/<?= (int) $p['id'] ?>/supprimer" class="danger-link">Supprimer<span class="visually-hidden"> « <?= e($p['title']) ?> »</span></a>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<p class="help">Le menu du site montre les pages principales publiées. Les sous-pages s'affichent automatiquement en liste sur leur page parente. Trois niveaux au maximum.</p>
