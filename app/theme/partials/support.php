<?php
/** "Nous aider" band at the bottom of every page, with the two HelloAsso calls to action. */
?>
<section class="support" aria-labelledby="support-title">
  <div class="container">
    <h2 id="support-title">Aider nos partenaires au Bénin</h2>
    <div class="support-grid">
      <div class="support-item">
        <h3>Faire un don</h3>
        <p>Votre don finance les projets de nos partenaires au Bénin.</p>
        <a class="btn btn-primary btn-large" href="<?= e(Settings::donUrl()) ?>">Faire un don</a>
      </div>
      <div class="support-item">
        <h3>Adhérer</h3>
        <p>Devenez membre de l'association : vous votez à l'assemblée générale et suivez les projets.</p>
        <a class="btn btn-secondary btn-large" href="<?= e(Settings::adhesionUrl()) ?>">Adhérer</a>
      </div>
    </div>
    <p class="support-note">Le paiement se fait sur HelloAsso, la plateforme de paiement des associations. Ce site ne traite aucun paiement.</p>
  </div>
</section>
