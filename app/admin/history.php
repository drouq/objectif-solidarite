<?php
/** Version history of a page. @var array $page @var array $versions */
$typeNames = ['text' => 'texte', 'cards' => 'grille de cartes', 'embed' => 'vidéo'];
?>
<div class="title-row">
  <h1>Historique de « <?= e($page['title']) ?> »</h1>
  <p class="title-links"><a href="/admin/pages/<?= (int) $page['id'] ?>">← Retour à la page</a></p>
</div>
<p>Les <?= Pages::MAX_VERSIONS ?> dernières versions sont gardées. Restaurer une version remet son titre, son contenu, son image et ses textes pour les moteurs de recherche. L'adresse, l'état et la place dans le menu ne changent pas. La version actuelle reste dans l'historique : rien n'est perdu.</p>
<div class="table-wrap">
<table>
  <caption class="visually-hidden">Versions de la page, de la plus récente à la plus ancienne</caption>
  <thead>
    <tr><th scope="col">Date</th><th scope="col">Par</th><th scope="col">Action</th><th scope="col">Contenu</th><th scope="col"><span class="visually-hidden">Restaurer</span></th></tr>
  </thead>
  <tbody>
  <?php foreach ($versions as $index => $version): ?>
    <?php
      $data = json_decode((string) $version['data'], true);
      $data = is_array($data) ? $data : [];
      $blocks = json_decode((string) ($data['blocks'] ?? '[]'), true);
      $blocks = is_array($blocks) ? $blocks : [];
      $summary = [];
      $preview = '';
      foreach ($blocks as $block) {
          $summary[] = $typeNames[$block['type'] ?? ''] ?? '?';
          if ($preview === '' && ($block['type'] ?? '') === 'text') {
              $preview = excerpt(Sanitizer::toText((string) $block['html']), 140);
          }
      }
    ?>
    <tr>
      <td><?= e(format_date_fr((string) $version['created_at'])) ?><?= $index === 0 ? ' <span class="badge badge-info">actuelle</span>' : '' ?></td>
      <td><?= e($version['user_email']) ?></td>
      <td><?= e($version['note']) ?></td>
      <td>
        <strong><?= e((string) ($data['title'] ?? '')) ?></strong><br>
        <span class="help"><?= count($blocks) ?> bloc(s)<?= $summary ? ' : ' . e(implode(', ', $summary)) : '' ?></span>
        <?php if ($preview !== ''): ?><br><span class="help">« <?= e($preview) ?> »</span><?php endif; ?>
      </td>
      <td>
        <?php if ($index > 0): ?>
        <form method="post" action="/admin/pages/<?= (int) $page['id'] ?>/historique/<?= (int) $version['id'] ?>/restaurer" class="inline-form">
          <?= csrf_field() ?>
          <button type="submit" class="btn btn-secondary btn-small">Restaurer<span class="visually-hidden"> la version du <?= e(format_date_fr((string) $version['created_at'])) ?></span></button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
