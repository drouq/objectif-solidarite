<?php
/** Site footer: name, contact, footer text, footer pages (for legal pages later). */
$settings = Settings::all();
$footerPages = Pages::footerPages();
?>
<footer class="site-footer">
  <div class="container footer-inner">
    <div class="footer-about">
      <p class="footer-name"><?= e($settings['site_name']) ?></p>
      <?php if ($settings['footer_text'] !== ''): ?>
        <p><?= nl2br(e($settings['footer_text']), false) ?></p>
      <?php endif; ?>
      <?php if ($settings['contact_email'] !== ''): ?>
        <p>Contact : <a href="mailto:<?= e($settings['contact_email']) ?>"><?= e($settings['contact_email']) ?></a></p>
      <?php endif; ?>
    </div>
    <div class="footer-support">
      <p class="footer-heading">Soutenir l'association</p>
      <ul>
        <li><a href="<?= e(Settings::donUrl()) ?>">Faire un don</a></li>
        <li><a href="<?= e(Settings::adhesionUrl()) ?>">Adhérer</a></li>
      </ul>
    </div>
    <?php if ($footerPages): ?>
    <nav class="footer-links" aria-label="Liens du pied de page">
      <ul>
        <?php foreach ($footerPages as $item): ?>
          <li><a href="<?= e(Pages::path($item)) ?>"><?= e($item['title']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </nav>
    <?php endif; ?>
  </div>
  <div class="container footer-bottom">
    <p>Dons et adhésions : paiement sécurisé sur HelloAsso. Ce site ne dépose aucun cookie de suivi.</p>
  </div>
</footer>
