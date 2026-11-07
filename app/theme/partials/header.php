<?php
/**
 * Site header and main menu (top-level pages only, no dropdowns).
 * @var array|null $page current page (null on the 404 page)
 * @var array $ancestors
 */
$settings = Settings::all();
$logo = Renderer::media(Settings::mediaId('logo_media_id'));
$menu = Pages::menu();
$currentTop = null;
if (!empty($page)) {
    $currentTop = !empty($ancestors) ? (int) $ancestors[0]['id'] : (int) $page['id'];
}
$isHomePage = !empty($page) && (int) $page['is_home'] === 1;
?>
<a class="skip-link" href="#contenu">Aller au contenu</a>
<?php if (!empty($preview)): ?>
<div class="preview-bar" role="status">Aperçu : cette page est masquée. Les visiteurs ne la voient pas. <a href="/admin/pages/<?= (int) $page['id'] ?>">Retour à l'édition</a></div>
<?php endif; ?>
<header class="site-header">
  <div class="container header-inner">
    <a class="brand" href="/"<?= $isHomePage ? ' aria-current="page"' : '' ?>>
      <?php if ($logo !== null): ?>
        <?= Renderer::img($logo, '64px', '', false, 'brand-logo') ?>
      <?php else: ?>
        <img class="brand-logo" src="<?= e(asset('img/logo-mark.webp')) ?>" width="192" height="192" alt="">
      <?php endif; ?>
      <span class="brand-text">
        <span class="brand-name"><?= e($settings['site_name']) ?></span>
        <?php if ($settings['tagline'] !== ''): ?><span class="brand-tagline"><?= e($settings['tagline']) ?></span><?php endif; ?>
      </span>
    </a>
    <a class="btn btn-primary header-cta" href="<?= e(Settings::donUrl()) ?>">Faire un don</a>
  </div>
  <?php if ($menu): ?>
  <nav class="main-nav" aria-label="Menu principal">
    <div class="container">
      <ul>
        <?php foreach ($menu as $item): ?>
          <?php $active = $currentTop === (int) $item['id']; ?>
          <li><a href="<?= e(Pages::path($item)) ?>"<?= $active ? ' class="is-active"' : '' ?><?= !empty($page) && (int) $page['id'] === (int) $item['id'] ? ' aria-current="page"' : '' ?>><?= e($item['title']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </nav>
  <?php endif; ?>
</header>
