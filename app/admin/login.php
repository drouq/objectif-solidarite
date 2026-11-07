<?php
/**
 * Sign-in page (standalone, outside the admin layout).
 * @var array $flash
 * @var bool $configured
 * @var bool $httpsProblem
 * @var bool $devLogin
 * @var array $devUsers
 */
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Connexion — Administration</title>
<link rel="icon" href="<?= e(asset('img/favicon.png')) ?>" type="image/png">
<link rel="stylesheet" href="<?= e(asset('admin/admin.css')) ?>">
</head>
<body class="admin admin-login">
<main id="contenu" class="login-box">
  <img src="<?= e(asset('img/logo-mark.webp')) ?>" width="96" height="96" alt="" class="login-logo">
  <h1>Administration du site</h1>
  <p class="login-site"><?= e(Settings::get('site_name')) ?></p>
  <?php foreach ($flash as $message): ?>
    <div class="notice notice-<?= e($message[0]) ?>" role="<?= $message[0] === 'error' ? 'alert' : 'status' ?>"><?= e($message[1]) ?></div>
  <?php endforeach; ?>

  <?php if ($httpsProblem): ?>
    <div class="notice notice-error" role="alert">Connexion impossible : le site doit être ouvert en HTTPS (adresse commençant par https://).</div>
  <?php elseif (!$configured): ?>
    <div class="notice notice-info">La connexion Google n'est pas encore configurée. Renseignez google_client_id et google_client_secret dans config.php.</div>
  <?php else: ?>
    <a class="btn btn-google" href="/admin/auth/google">
      <svg aria-hidden="true" width="20" height="20" viewBox="0 0 48 48"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="m6.3 14.7 6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg>
      Se connecter avec Google
    </a>
    <p class="login-help">Seules les personnes ajoutées par l'administrateur peuvent se connecter.</p>
  <?php endif; ?>

  <?php if ($devLogin): ?>
    <form method="post" action="/admin/auth/dev" class="dev-login">
      <?= csrf_field() ?>
      <p><strong>Connexion de test (ordinateur local uniquement)</strong></p>
      <label for="dev-email">Adresse</label>
      <input id="dev-email" name="email" type="email" list="dev-users" value="<?= e((string) Config::get('admin_email')) ?>" required>
      <datalist id="dev-users">
        <?php foreach ($devUsers as $devUser): ?><option value="<?= e($devUser['email']) ?>"></option><?php endforeach; ?>
      </datalist>
      <button type="submit" class="btn btn-secondary">Entrer sans Google</button>
    </form>
  <?php endif; ?>
  <p class="login-back"><a href="/">← Retour au site</a></p>
</main>
</body>
</html>
