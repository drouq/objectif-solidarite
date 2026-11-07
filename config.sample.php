<?php
/*
 * Réglages du site. Copier ce fichier en "config.php" puis le remplir.
 * Site settings. Copy this file to "config.php", then fill it in.
 *
 * Where to put config.php:
 * - Git deployment (OVH): in the folder "os-private", next to the Git folder "os".
 *   The database ("data") and uploads ("media") are then created in os-private too,
 *   out of reach of any Git deployment.
 * - FileZilla install or your computer: next to the "app" folder.
 *
 * config.php holds secrets. It is never in the repository, in dist/ or in backups.
 */
return [
    // 'production' on the server, 'development' on your computer.
    'env' => 'production',

    // Public address of the site, without a trailing slash.
    // Staging: 'https://test.objectif-solidarite.org'. Locally: 'http://localhost:8000'
    'base_url' => 'https://objectif-solidarite.org',

    // Send every visitor to HTTPS. Turn on only once the SSL certificate works.
    'force_https' => true,

    // Where the database, original images, sessions and logs live.
    // null = the "data" folder next to this config.php. Must NOT be reachable from the web.
    'data_path' => null,

    // Where uploaded images and PDFs live.
    // null = automatic: "media" next to this config.php when it is in os-private (served by the site),
    // otherwise the "media" folder of the web root (served by Apache).
    'media_path' => null,

    // First administrator (Google account). This person is always admin.
    'admin_email' => '',

    // Google OAuth client (Google Cloud console > APIs & Services > Credentials).
    'google_client_id' => '',
    'google_client_secret' => '',

    // Local-only test login, without Google. Works only on localhost AND in development.
    // Keep false on the server.
    'dev_login' => false,

    // Upload limits, in megabytes.
    'max_image_mb' => 10,
    'max_pdf_mb' => 20,

    // Editors are signed out after this many hours without activity.
    'session_idle_hours' => 8,

    // Allow check.php to run. Set to false (or delete check.php) after the first install.
    'check_enabled' => true,
];
