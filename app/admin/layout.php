<?php
/**
 * Admin layout.
 * @var string $title
 * @var string $content
 * @var string $nav current section
 */
$user = Auth::user();
$flash = $user !== null ? Session::takeFlash() : [];
$nav = $nav ?? '';
$links = [
    'pages' => ['/admin/pages', 'Pages'],
    'media' => ['/admin/medias', 'Médiathèque'],
];
if (Auth::isAdmin()) {
    $links['settings'] = ['/admin/reglages', 'Réglages'];
    $links['users'] = ['/admin/utilisateurs', 'Utilisateurs'];
    $links['backup'] = ['/admin/sauvegarde', 'Sauvegarde'];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="<?= e(Session::csrfToken()) ?>">
<title><?= e($title) ?> — Administration</title>
<link rel="icon" href="<?= e(asset('img/favicon.png')) ?>" type="image/png">
<link rel="stylesheet" href="<?= e(asset('admin/quill.snow.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('admin/admin.css')) ?>">
<script src="<?= e(asset('admin/admin.js')) ?>" defer></script>
</head>
<body class="admin">
<a class="skip-link" href="#contenu">Aller au contenu</a>
<header class="admin-header">
  <div class="admin-bar">
    <a class="admin-site" href="/admin/pages"><?= e(Settings::get('site_name')) ?> <span>Administration</span></a>
    <div class="admin-user">
      <a href="/" target="_blank" rel="noopener">Voir le site<span class="visually-hidden"> (nouvel onglet)</span></a>
      <?php if ($user !== null): ?>
      <span class="admin-who"><?= e($user['email']) ?> · <?= e(Users::ROLES[$user['role']] ?? $user['role']) ?></span>
      <form method="post" action="/admin/auth/logout" class="inline-form">
        <?= csrf_field() ?>
        <button type="submit" class="button-link">Se déconnecter</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($user !== null): ?>
  <nav class="admin-nav" aria-label="Administration">
    <ul>
      <?php foreach ($links as $key => $link): ?>
        <li><a href="<?= e($link[0]) ?>"<?= $nav === $key ? ' aria-current="page"' : '' ?>><?= e($link[1]) ?></a></li>
      <?php endforeach; ?>
    </ul>
  </nav>
  <?php endif; ?>
</header>
<main id="contenu" class="admin-main" tabindex="-1">
  <?php foreach ($flash as $message): ?>
    <div class="notice notice-<?= e($message[0]) ?>" role="<?= $message[0] === 'error' ? 'alert' : 'status' ?>"><?= e($message[1]) ?></div>
  <?php endforeach; ?>
  <?= $content ?>
</main>
</body>
</html>
