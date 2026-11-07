<?php
/** Friendly "page not found" page. @var array $meta */
echo View::theme('partials/head', ['meta' => $meta]);
?>
<body>
<?= View::theme('partials/header', ['page' => null, 'ancestors' => []]) ?>
<main id="contenu" tabindex="-1">
  <div class="page-head">
    <div class="container">
      <h1>Page introuvable</h1>
    </div>
  </div>
  <div class="container content prose">
    <p>La page que vous cherchez n'existe pas ou a été déplacée.</p>
    <p><a class="btn btn-primary" href="/">Retour à l'accueil</a></p>
  </div>
</main>
<?= View::theme('partials/footer') ?>
</body>
</html>
