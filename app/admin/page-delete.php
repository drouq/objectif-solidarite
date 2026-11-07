<?php
/** Delete confirmation. @var array $page @var int $childCount */
?>
<h1>Supprimer « <?= e($page['title']) ?> » ?</h1>
<?php if ($childCount > 0): ?>
  <div class="notice notice-error" role="alert">Cette page a <?= $childCount ?> sous-page(s). Supprimez-les ou placez-les sous une autre page avant de supprimer celle-ci.</div>
  <p><a href="/admin/pages">← Retour aux pages</a></p>
<?php elseif ((int) $page['is_home'] === 1): ?>
  <div class="notice notice-error" role="alert">La page d'accueil ne peut pas être supprimée.</div>
  <p><a href="/admin/pages">← Retour aux pages</a></p>
<?php else: ?>
  <div class="panel">
    <p>La page et tout son historique seront supprimés définitivement. Son adresse <strong><?= e(Pages::path($page)) ?></strong> ne fonctionnera plus.</p>
    <p>Pour la retirer du site sans la perdre, vous pouvez plutôt la <strong>masquer</strong>.</p>
    <form method="post" action="/admin/pages/<?= (int) $page['id'] ?>/supprimer" class="button-row">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-danger">Oui, supprimer définitivement</button>
      <a class="btn btn-secondary" href="/admin/pages">Annuler</a>
    </form>
  </div>
<?php endif; ?>
