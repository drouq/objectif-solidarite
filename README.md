# Objectif Solidarité: website

The website of the association Objectif Solidarité (Montpellier), with a simple admin area. Editors sign in with Google and edit pages without touching code.
It runs on OVH shared hosting (PHP + SQLite). It is deployed from GitHub: OVH copies the code each time it changes on GitHub.

- Public site: `https://objectif-solidarite.org` (staging first: `https://test.objectif-solidarite.org`)
- Admin area: `/admin` on the same address

Contents

1. [How the site is laid out on OVH](#1-how-the-site-is-laid-out-on-ovh)
2. [Working on your computer](#2-working-on-your-computer)
3. [Updating the site later](#3-updating-the-site-later)
4. [Backups and restoring](#4-backups-and-restoring)
5. [Good to know](#5-good-to-know)
6. [Alternative: installing with FileZilla](#6-alternative-installing-with-filezilla)

Staging, the first upload of the content and go-live are described in sections added at milestone M4.

---

## 1. How the site is laid out on OVH

Two folders at the top of your OVH FTP space:

```
os/                <- the Git folder. OVH fills it from GitHub. The domain points here.
  .htaccess        <- sends every visitor into public/ (nothing else is reachable)
  app/             <- the program
  public/          <- what visitors see: index.php, styles, images
  ...              <- tests, README... (in the repository, never served)
os-private/        <- created by you once, by FTP. Git never touches it.
  config.php       <- your real settings and secrets
  data/            <- database, original images, sessions, logs
  media/           <- uploaded images and PDFs
```

Why two folders: everything that exists only on the server (settings, content, uploads) stays out of the Git folder. A deployment, a forced update or a re-link of Git can never delete it.

The site finds `os-private/` on its own: it looks for `config.php` next to `app/`, then in a folder with the same name plus `-private` next to it.

What is in the repository:

| Folder or file | What it is |
|---|---|
| `app/` | The program (PHP). Page templates in `app/theme/`. |
| `public/` | The web folder: front controller, styles, fonts, images, editor bundle, `check.php`. |
| `.htaccess` | Serves only `public/` when the domain points at the repository folder. |
| `config.sample.php` | Settings template. |
| `editor-src/` | Source of the admin editor (Quill). Built on a computer, the result is in `public/assets/admin/`. |
| `build/` | Build tools: FileZilla package, review site, content import. Never served. |
| `tests/`, `screenshots-src/` | Tests and review tools. Never served. |

Not in the repository: `config.php`, `data/`, `public/media/`, `tools/`, `source-content/`, `build/seed/`, `review/`, `dist/`, the PRD files. The repository is public: it never holds the association's content, settings or secrets.

## 2. Working on your computer

The `tools/` folder holds portable PHP 8.5 and PHP 7.3 (official Windows builds). Nothing is installed on Windows. It is not in the repository: after a fresh clone, download them again from `windows.php.net`.

Start the site locally:

```bash
tools/php85/php.exe -S localhost:8000 -t public public/index.php
```

Then open <http://localhost:8000> and <http://localhost:8000/admin>. The last argument (`public/index.php`) makes `/sitemap.xml`, `/robots.txt` and `/media/...` work with PHP's built-in server. On OVH, Apache and `.htaccess` do that job.

Locally, `config.php` sits next to `app/` (not committed) with `'env' => 'development'`, `'base_url' => 'http://localhost:8000'`, `'force_https' => false`. With `'dev_login' => true`, the login page also shows **Entrer sans Google**. It only works on your own computer in development mode, never on the server.

Run the automatic tests on both PHP versions. Any PHP warning or deprecation fails the run.

```bash
tools/php73/php.exe tests/run.php
```

```bash
tools/php85/php.exe tests/run.php
```

Rebuild the admin editor after changing `editor-src/src/admin.js` (needs Node.js), then commit the result in `public/assets/admin/`:

```bash
cd editor-src && npm install && npm run build
```

Screenshots for review, with example content on port 8200 (needs Node.js and Microsoft Edge):

```bash
tools/php85/php.exe build/review-site.php
```

```bash
tools/php85/php.exe -S localhost:8200 -t tests/tmp/review-site/www tests/tmp/review-site/www/index.php
```

```bash
cd screenshots-src && npm install && node shoot.mjs http://localhost:8200 ../review/latest
```

## 3. Updating the site later

1. Changes are committed and pushed to the `main` branch on GitHub.
2. The webhook tells OVH, which updates `os/` within a minute or so. Without the webhook (or if it fails): Multisite > the domain line > **...** > **Déployer Git**.
3. Open the site once. Database changes (migrations) run on their own on this first visit. They only add things and never delete data.

`os-private/` (settings, content, uploads) is never touched by an update.

Optional but wise before a big update: **Admin** > **Sauvegarde** > download a backup.

## 4. Backups and restoring

- **Admin** > **Sauvegarde** > **Télécharger une sauvegarde** gives one zip with the database, all images and PDFs (originals and web versions) and the settings template. Your real `config.php` is not included, on purpose: keep your own copy.
- Automatic backups are not part of version 1. Download one after each important change and keep it on your computer or Google Drive.

To restore on a clean installation:

1. Install the site, with your `config.php` in `os-private/`.
2. Unzip the backup. Upload its `data/` and `media/` folders into `os-private/` (replacing what is there).
3. Open the site. Everything is back: pages, history, settings, users, files.

## 5. Good to know

- **PHP 7.3** no longer gets security fixes. The site runs on it because other sites on the plan need it. The code also runs unchanged on PHP 8.x (tested on 8.5). Move to PHP 8 as soon as you can. OVH documents that a `.ovhconfig` file in a site's root folder can set its PHP version in a multisite setup. Ask OVH whether that works on your plan.
- **Upload limits** are in `config.php` (`max_image_mb`, `max_pdf_mb`). The server's PHP limits must be at least as high. `check.php` shows them.
- **Images** are resized on the server: web versions at 480, 960 and 1600 px wide (WebP), originals kept in `data/originals`. Phone photos are turned upright and their location data is removed.
- **Replacing a file** in the Médiathèque keeps every page that uses it working.
- **Changing a page address** creates a permanent (301) redirect from the old address automatically.
- **History**: each page keeps its last 20 versions. "Restaurer" brings back the text, title, image and search-engine texts, and keeps the current version in the history too.
- **Privacy**: no analytics, no outside fonts or scripts, no cookies for visitors (so no cookie banner). Videos come from `youtube-nocookie.com` or Vimeo and only load when the visitor clicks them. Only signed-in editors get a session cookie.
- **Errors** are written to `os-private/data/logs/php-errors.log`, never shown to visitors.
- **Two editors on the same page**: if someone saved the page while you were editing it, your save is refused with a message, so nobody overwrites the other's work by mistake.
- **Legal pages** (mentions légales, privacy): create a normal page, untick "Afficher dans le menu principal", tick "Afficher dans le pied de page".
- **Never edit files in `os/` by FTP**: the next deployment would overwrite them, or block on a conflict.

## 6. Alternative: installing with FileZilla

If Git deployment is not available, the site can be uploaded by hand.

1. Build the package: `tools/php85/php.exe build/build.php`. This creates `dist/` with `app/`, `www/`, `config.sample.php`.
2. On OVH, create `os/` with `os/www/` inside, and set the domain's **Dossier racine** to `os/www`.
3. In FileZilla (**Server** > **Force showing hidden files**, so `.htaccess` files are sent): upload `dist/app` into `os/`, the contents of `dist/www` into `os/www/`.
4. Put `config.php` in `os/` next to `app/`. `data/` is created next to it, uploads go to `os/www/media/`.
5. Updates: build again and upload `dist/app` and `dist/www` with **Overwrite**. Never upload `config.php`, `data/` or `www/media/` from your computer. `dist/` never contains them.
