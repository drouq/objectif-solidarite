<?php
/**
 * A public page. The home page gets the hero with the HelloAsso buttons.
 * @var array $page
 * @var bool $isHome
 * @var bool $preview
 * @var array $ancestors
 * @var array $children
 * @var string $blocksHtml
 * @var array $meta
 */
$settings = Settings::all();
$header = Renderer::media($page['header_media_id'] !== null ? (int) $page['header_media_id'] : null);
$logo = Renderer::media(Settings::mediaId('logo_media_id'));
echo View::theme('partials/head', ['meta' => $meta]);
?>
<body>
<?= View::theme('partials/header', ['page' => $page, 'ancestors' => $ancestors, 'preview' => $preview]) ?>
<main id="contenu" tabindex="-1">
<?php if ($isHome): ?>
  <section class="hero">
    <div class="container hero-inner">
      <div class="hero-text">
        <h1><?= e($settings['site_name']) ?></h1>
        <p class="lead"><?= $settings['tagline'] !== '' ? e($settings['tagline']) : '[À COMPLÉTER : courte description de l\'association]' ?></p>
        <div class="actions">
          <a class="btn btn-primary btn-large" href="<?= e(Settings::donUrl()) ?>">Faire un don</a>
          <a class="btn btn-secondary btn-large" href="<?= e(Settings::adhesionUrl()) ?>">Adhérer à l'association</a>
        </div>
        <p class="hero-note">Paiement sécurisé sur HelloAsso.</p>
      </div>
      <div class="hero-visual">
        <?php if ($header !== null): ?>
          <?= Renderer::img($header, '(min-width: 64rem) 28rem, 80vw', null, false, 'hero-image') ?>
        <?php elseif ($logo !== null): ?>
          <?= Renderer::img($logo, '15rem', '', false, 'hero-logo') ?>
        <?php else: ?>
          <img class="hero-logo" src="<?= e(asset('img/logo.webp')) ?>" width="800" height="845" alt="" fetchpriority="high">
        <?php endif; ?>
      </div>
    </div>
  </section>
  <div class="container content">
    <?= $blocksHtml ?>
  </div>
<?php else: ?>
  <div class="page-head">
    <div class="container">
      <?php if ($ancestors): ?>
      <nav class="breadcrumb" aria-label="Fil d'Ariane">
        <ol>
          <li><a href="/">Accueil</a></li>
          <?php foreach ($ancestors as $ancestor): ?>
            <li><a href="<?= e(Pages::path($ancestor)) ?>"><?= e($ancestor['title']) ?></a></li>
          <?php endforeach; ?>
          <li aria-current="page"><?= e($page['title']) ?></li>
        </ol>
      </nav>
      <?php endif; ?>
      <h1><?= e($page['title']) ?></h1>
    </div>
  </div>
  <?php if ($header !== null): ?>
  <div class="container page-banner">
    <?= Renderer::img($header, '(min-width: 72rem) 68rem, 100vw', null, false) ?>
  </div>
  <?php endif; ?>
  <div class="container content">
    <?= $blocksHtml ?>
    <?= Renderer::childList($children) ?>
  </div>
<?php endif; ?>
<?= View::theme('partials/support') ?>
</main>
<?= View::theme('partials/footer') ?>
</body>
</html>
