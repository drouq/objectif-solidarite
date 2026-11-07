<?php
/**
 * A public page. The home page gets the hero (mission and the two HelloAsso buttons),
 * the key figures band (Réglages > Chiffres clés, "Accueil" ticked) and the current projects.
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
$figures = $isHome ? KeyFigures::onHome() : [];
$projectsPage = null;
$projects = [];
if ($isHome && $settings['home_projects_slug'] !== '') {
    $projectsPage = Pages::findBySlug($settings['home_projects_slug']);
    if ($projectsPage !== null && $projectsPage['status'] === 'published' && $projectsPage['parent_id'] === null) {
        $projects = Pages::children((int) $projectsPage['id'], true);
        Renderer::preloadMedia($projects);
    }
}
// Hero sentence: the slogan from Réglages, else the home page's own description.
$lead = $settings['tagline'] !== '' ? $settings['tagline'] : (string) $page['seo_description'];
echo View::theme('partials/head', ['meta' => $meta]);
?>
<body<?= $isHome ? ' class="is-home"' : '' ?>>
<?= View::theme('partials/header', ['page' => $page, 'ancestors' => $ancestors, 'preview' => $preview]) ?>
<main id="contenu" tabindex="-1">
<?php if ($isHome): ?>
  <section class="hero">
    <div class="container hero-inner">
      <div class="hero-text">
        <h1><?= e($settings['site_name']) ?></h1>
        <p class="lead"><?= $lead !== '' ? e($lead) : '[À COMPLÉTER : courte présentation de l\'association]' ?></p>
        <div class="actions">
          <a class="btn btn-primary btn-large" href="<?= e(Settings::donUrl()) ?>">Faire un don</a>
          <a class="btn btn-secondary btn-large" href="<?= e(Settings::adhesionUrl()) ?>">Adhérer</a>
        </div>
        <p class="hero-note">Paiement sécurisé sur HelloAsso.</p>
      </div>
      <div class="hero-visual">
        <?php if ($header !== null): ?>
          <?= Renderer::img($header, '(min-width: 64rem) 28rem, 80vw', null, false, 'hero-image') ?>
        <?php elseif ($logo !== null): ?>
          <?= Renderer::img($logo, '16rem', '', false, 'hero-logo') ?>
        <?php else: ?>
          <img class="hero-logo" src="<?= e(asset('img/logo.webp')) ?>" width="643" height="633" alt="" fetchpriority="high">
        <?php endif; ?>
      </div>
    </div>
  </section>
  <?php if ($figures): ?>
  <section class="figures" aria-labelledby="figures-title">
    <div class="container">
      <h2 id="figures-title" class="visually-hidden">Chiffres clés</h2>
      <ul class="figures-list" role="list">
        <?php foreach ($figures as $figure): ?>
          <li><span class="figure-value"><?= e(KeyFigures::display($figure)) ?></span><span class="figure-label"><?= e($figure['label'] !== '' ? $figure['label'] : $figure['fig_key']) ?></span></li>
        <?php endforeach; ?>
      </ul>
      <?php if (Pages::findBySlug('transparence') !== null): ?>
        <p class="figures-more"><a href="/transparence">D'où viennent ces chiffres</a></p>
      <?php endif; ?>
    </div>
  </section>
  <?php endif; ?>
  <div class="container content home-content">
    <?= $blocksHtml ?>
  </div>
  <?php if ($projects): ?>
  <section class="home-projects" aria-labelledby="projects-title">
    <div class="container">
      <h2 id="projects-title"><?= e($projectsPage['title']) ?></h2>
      <?php
      $cards = [];
      foreach ($projects as $child) {
          $cards[] = [
              'media_id' => $child['header_media_id'] !== null ? (int) $child['header_media_id'] : null,
              'title' => $child['title'],
              'text' => $child['seo_description'] !== '' ? excerpt((string) $child['seo_description'], 120) : Pages::autoDescription($child),
              'url' => Pages::path($child),
          ];
      }
      echo Renderer::cards($cards, 'h3', true);
      ?>
      <p class="section-more"><a class="btn btn-secondary" href="<?= e(Pages::path($projectsPage)) ?>">Tous les projets</a></p>
    </div>
  </section>
  <?php endif; ?>
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
      <?php if ($page['seo_description'] !== ''): ?>
        <p class="page-intro"><?= e($page['seo_description']) ?></p>
      <?php endif; ?>
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
