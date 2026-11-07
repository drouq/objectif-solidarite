<?php
/** Backup download (admin only). @var int $dbSize @var int $mediaCount */
?>
<h1>Sauvegarde</h1>
<div class="panel">
  <p>Téléchargez une copie complète du site : toutes les pages et leur historique, les réglages, la liste des utilisateurs et tous les fichiers de la médiathèque (<?= $mediaCount ?> fichier(s)).</p>
  <p>Le fichier secret <strong>config.php</strong> (identifiants Google) n'est pas inclus, par sécurité. Gardez-en une copie à part.</p>
  <form method="post" action="/admin/sauvegarde">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-primary btn-large">Télécharger une sauvegarde</button>
  </form>
  <p class="help">Base de données : <?= e(Media::formatSize($dbSize)) ?>. Conseil : faites une sauvegarde après chaque série de modifications importantes, et gardez-la sur votre ordinateur ou votre Google Drive. Les instructions de restauration sont dans le fichier RESTAURATION.txt du zip.</p>
</div>
