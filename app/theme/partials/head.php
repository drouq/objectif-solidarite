<?php
/**
 * <head> of every public page.
 * @var array $meta title, description, canonical, image (media row or null), noindex
 */
$settings = Settings::all();
$favicon = Renderer::media(Settings::mediaId('favicon_media_id'));
$image = $meta['image'] ?? null;
$imageUrl = $image !== null ? absolute_url(Media::url($image, 1600)) : absolute_url(asset('img/og-default.jpg'));
$largeImage = $image === null || Media::displaySize($image)[0] >= 600; // the default share image is 1200 x 630
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($meta['title']) ?></title>
<?php if ($meta['description'] !== ''): ?>
<meta name="description" content="<?= e($meta['description']) ?>">
<?php endif; ?>
<?php if (!empty($meta['noindex'])): ?>
<meta name="robots" content="noindex">
<?php endif; ?>
<?php if ($meta['canonical'] !== ''): ?>
<link rel="canonical" href="<?= e($meta['canonical']) ?>">
<meta property="og:url" content="<?= e($meta['canonical']) ?>">
<?php endif; ?>
<meta property="og:type" content="website">
<meta property="og:locale" content="fr_FR">
<meta property="og:site_name" content="<?= e($settings['site_name']) ?>">
<meta property="og:title" content="<?= e($meta['title']) ?>">
<?php if ($meta['description'] !== ''): ?>
<meta property="og:description" content="<?= e($meta['description']) ?>">
<?php endif; ?>
<meta property="og:image" content="<?= e($imageUrl) ?>">
<meta name="twitter:card" content="<?= $largeImage ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= e($meta['title']) ?>">
<?php if ($meta['description'] !== ''): ?>
<meta name="twitter:description" content="<?= e($meta['description']) ?>">
<?php endif; ?>
<meta name="twitter:image" content="<?= e($imageUrl) ?>">
<meta name="theme-color" content="#0b3a8f">
<?php if ($favicon !== null): ?>
<link rel="icon" href="<?= e(Media::url($favicon, 480)) ?>">
<?php else: ?>
<link rel="icon" href="<?= e(asset('img/favicon.png')) ?>" type="image/png">
<link rel="apple-touch-icon" href="<?= e(asset('img/apple-touch-icon.png')) ?>">
<?php endif; ?>
<link rel="preload" href="<?= e(asset('fonts/source-serif-4-latin-wght-normal.woff2')) ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= e(asset('css/site.css')) ?>">
<?php if (Renderer::$hasEmbed): ?>
<script src="<?= e(asset('js/site.js')) ?>" defer></script>
<?php endif; ?>
</head>
