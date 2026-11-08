# Objectif Solidarité: website

The website of the association Objectif Solidarité (Montpellier), with a simple admin area. Editors sign in with Google and edit pages without touching code.
It runs on OVH shared hosting (PHP + SQLite). It is deployed from GitHub: OVH copies the code each time it changes on GitHub.

- Public site: `https://objectif-solidarite.org` (staging first: `https://test.objectif-solidarite.org`)
- Admin area: `/admin` on the same address

Contents

1. [How the site is laid out on OVH](#1-how-the-site-is-laid-out-on-ovh)
2. [Staging: first installation on test.objectif-solidarite.org](#2-staging-first-installation-on-testobjectif-solidariteorg)
3. [Première mise en ligne du contenu (seed upload)](#3-première-mise-en-ligne-du-contenu-seed-upload)
4. [Google sign-in setup](#4-google-sign-in-setup)
5. [Go-live on objectif-solidarite.org](#5-go-live-on-objectif-solidariteorg)
6. [Updating the site later](#6-updating-the-site-later)
7. [Rolling back](#7-rolling-back)
8. [Backups and restoring](#8-backups-and-restoring)
9. [Working on your computer (tests, content import, preview)](#9-working-on-your-computer-tests-content-import-preview)
10. [Good to know](#10-good-to-know)
11. [Alternative: installing with FileZilla](#11-alternative-installing-with-filezilla)

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

The site finds `os-private/` on its own: it looks for `config.php` next to `app/`, then in a folder with the same name plus `-private` next to it (`os` → `os-private`).

What is in the repository:

| Folder or file | What it is |
|---|---|
| `app/` | The program (PHP). Page templates in `app/theme/`. |
| `public/` | The web folder: front controller, styles, fonts, images, editor bundle, `check.php`. |
| `.htaccess` | Serves only `public/` when the domain points at the repository folder. |
| `config.sample.php` | Settings template. |
| `editor-src/` | Source of the admin editor (Quill) and of the fonts. Built on a computer, the result is in `public/assets/`. |
| `build/` | Tools for your computer: content import, preview, FileZilla package, example site. Never served. |
| `tests/`, `screenshots-src/` | Tests and review tools. Never served. |

Not in the repository: `config.php`, `data/`, `public/media/`, `tools/`, `source-content/`, `build/seed/`, `review/`, `dist/`, the PRD files. The repository is public: it never holds the association's content, settings or secrets.

## 2. Staging: first installation on test.objectif-solidarite.org

Plan about an hour, plus the wait for the SSL certificate. Keep the Wix site live during all of this.

### 2.1 Create the two folders

In FileZilla, at the top of your FTP space (next to the other sites' folders), create two **empty** folders: `os` and `os-private`.

### 2.2 Add the staging subdomain

1. OVH Control Panel: **Web Cloud** > **Hébergements** > your plan > **Multisite** tab > **Ajouter un domaine ou un sous-domaine**.
2. Domain: `test.objectif-solidarite.org`. **Dossier racine**: `os`. Tick **SSL**. Confirm.
3. **The DNS of `objectif-solidarite.org` is managed by Wix** (nameservers `ns2.wixdns.net` and `ns3.wixdns.net`), so OVH's "automatic DNS configuration" has no effect. Add the record at Wix instead:
   - In OVH, note the hosting's addresses: **Hébergements** > your plan > **Informations générales** > **IPv4** and **IPv6** (in November 2026 the other site on the plan answered on `213.186.33.19` and `2001:41d0:1:1b00:213:186:33:19`).
   - In Wix: **Domains** > `objectif-solidarite.org` > **Manage DNS records**. Under **A (Host)**, add host `test` pointing to the IPv4. If Wix offers **AAAA**, add host `test` pointing to the IPv6. Change nothing else.
   - Check that it works with `nslookup test.objectif-solidarite.org 8.8.8.8`: it must show the OVH address.
4. Once the record works, OVH can issue the certificate. If the SSL column of the Multisite line stays inactive, use that line's **...** > **Regénérer le certificat SSL** (or enable SSL). Wait until `https://test.objectif-solidarite.org` opens without a certificate warning (minutes to a few hours). **Informations générales** > **Certificat SSL** should say "Oui".

### 2.3 Create the Google sign-in client

Follow [section 4](#4-google-sign-in-setup). It must be a **new** client for this site, never the one of another site.

### 2.4 Create os-private/config.php

1. On your computer, copy `config.sample.php` to a new file named `config.php`. Open it in Notepad and fill in:
   - `'base_url' => 'https://test.objectif-solidarite.org',`
   - `'admin_email' => 'votre.adresse@gmail.com',` (your Google account: you are always admin)
   - `'google_client_id'` and `'google_client_secret'` from section 4.
   - Leave `'env' => 'production'`, `'dev_login' => false` and `'force_https' => true`.
2. Upload `config.php` into `os-private/`. Keep a copy somewhere safe: it is never in the repository or in backups.

### 2.5 Push to GitHub and link OVH Git

1. Push the repository to GitHub (commands at the end of the final report from Claude, or see [section 9.4](#94-first-push-to-github)).
2. Multisite tab > the `test.objectif-solidarite.org` line > **...** > **Configurer Git**:
   - **Repository**: `https://github.com/drouq/objectif-solidarite.git`
   - **Branch**: `main`
3. Copy the **Webhook URL** OVH shows. On GitHub: repository > **Settings** > **Webhooks** > **Add webhook**:
   - **Payload URL**: the OVH webhook URL
   - **Content type**: `application/json`
   - **Which events**: **Just the push event**. **Active** ticked. **Add webhook**.
4. In OVH, click **Appliquer la configuration**. OVH copies the repository into `os/`. If it fails, the **...** menu > **Informations du dernier déploiement** shows why.
5. A first GitHub `ping` that gets `400 "website is not in status created"` is normal before the first deployment. If later pushes get `401 unauthorized`, OVH changed the webhook URL (it does when the Git settings are re-applied): copy the new one into the GitHub webhook.

The repository is public and read over HTTPS, so OVH needs no key. Never commit `config.php`, passwords or keys.

### 2.5 bis Without OVH Git: upload by SSH (scp)

If OVH answers "Git could not be enabled: quota is reached for VCS websites", the plan has no Git slot left. The site runs the same without Git; only updates are by hand.

1. On your computer, prepare the folders (code from the last commit, plus the seed for the first installation):

   ```bash
   tools/php85/php.exe build/prepare-upload.php --with-content
   ```

   This makes `build/upload/os/` (code) and `build/upload/os-private/` (database, media and a `config.php` for staging). Open `build/upload/os-private/config.php` in Notepad and fill in `admin_email`, `google_client_id` and `google_client_secret` (section 4). Running the command again keeps the `config.php` you filled in.
2. Find your login and server: OVH Control Panel > **Hébergements** > your plan > **FTP - SSH** tab (login, and the SFTP/SSH server, like `ssh.clusterXXX.hosting.ovh.net`).
3. Upload both folders into the top of your space, next to the other sites. The command asks for your OVH password in the terminal:

   ```bash
   scp -r build/upload/os build/upload/os-private LOGIN@SERVER:
   ```

   Replace `LOGIN` and `SERVER` with the values from step 2, for example `monlogin@ssh.cluster031.hosting.ovh.net:` (keep the final `:`, it means "my home folder"). Left as they are, `scp` answers "Could not resolve hostname server". The folders `os` and `os-private` you created in step 2.1 receive the files.
4. Skip section 3, step 5: the content is already uploaded. Go on with step 2.7.

Without `scp` (SSH not enabled on the plan), upload the same two folders with FileZilla: **Server** > **Force showing hidden files** first, so the `.htaccess` files are sent.

**Upload `os-private` only this once.** Later updates send the code only ([section 6](#6-updating-the-site-later)).

### 2.6 Upload the content

Follow [section 3](#3-première-mise-en-ligne-du-contenu-seed-upload).

### 2.7 Check the installation

1. Open `https://test.objectif-solidarite.org/check.php`.
2. Every line should say **OK**. Read the **ATTENTION** lines, fix any **ERREUR** line (the "Détail" column says how).
   - The config.php line should end with "(dossier privé, hors Git)".
   - If **SQLite** is missing, stop: the site would need the plan's MySQL database instead.
3. Then set `'check_enabled' => false` in `config.php` and upload it again. (Deleting `check.php` on the server does not last: the next deployment brings it back.)

### 2.8 Sign in, review, publish

1. Open `https://test.objectif-solidarite.org/admin` and click **Se connecter avec Google**.
2. **Réglages**:
   - **HelloAsso**: the association's HelloAsso page, and if they exist the separate links for donations and memberships. Until one is set, every "Faire un don" and "Adhérer" button leads to the "Nous aider" page, and the admin shows a yellow warning.
   - **Slogan**: the short sentence under the name. While empty, the home page shows the home page's own description.
   - **Chiffres clés**: check the values, write a French label for each figure (the import only gives the key), tick **Accueil** for the 3 or 4 figures to show in the home page band.
   - **Pied de page**: for example the postal address.
3. **Utilisateurs**: add the 2 or 3 editors by their Gmail address, role **Éditeur**.
4. **Pages**: every page except the home page is a draft ("Brouillon"). Open each one. A yellow box above **Enregistrer** lists what is still marked `[À VÉRIFIER : ...]` or `[À COMPLÉTER : ...]`. Fix the text, remove each marker, then choose **Publiée** and **Enregistrer**. A page under a draft page stays hidden until its parent is published.
5. Check a few old addresses: `https://test.objectif-solidarite.org/mafé`, `/copie-de-accueil`, `/contact-8` must lead to the new pages (or HelloAsso for `/contact-8`).

## 3. Première mise en ligne du contenu (seed upload)

The pages, images and PDFs are not in Git. They come from the content package (`source-content/`) through an import that runs on your computer and produces a "seed": a ready database and media folder.

1. On your computer, check the package (writes nothing):

   ```bash
   tools/php73/php.exe build/import-content.php --check
   ```

   It prints a report: pages, blocks, media, markers, skipped media, and every problem. Fix the package until it says "all checks pass".
2. Build the seed (PHP 7.3, so the database is made by the SQLite version OVH uses):

   ```bash
   tools/php73/php.exe build/import-content.php --import
   ```

   - Every page is imported as a draft, except the home page (the site needs it published).
   - `--publish` also publishes the pages that hold no marker.
   - `--allow-missing-media` imports even if a referenced file is missing: its link becomes `[À COMPLÉTER : document non importé]`.
   - `--force` replaces an existing seed.
   - Media flagged in `05-media.csv` for rights or consent are skipped and listed in the report.
3. Read `build/seed/import-report.md`.
4. Optional: look at it on your computer first ([section 9.3](#93-preview-the-imported-content)).
5. In FileZilla, upload the **contents** of `build/seed/os-private/` (the folders `data` and `media`) into the server's `os-private/`, next to `config.php`. Use binary transfer (FileZilla's default for `.sqlite`). The result:

   ```
   os-private/
     config.php
     data/site.sqlite  data/originals/...  data/logs/ ...
     media/<random names>.webp / .pdf
   ```

6. Open the site: the pages, images and PDFs are there.

**Do this only once.** Uploading a seed again replaces the database: every change made in the admin since would be lost. After the first upload, the content lives in the admin; make changes there, not in the package.

The seed holds no users, sessions or secrets. You become admin through `admin_email` in `config.php`.

## 4. Google sign-in setup

About 30 minutes, with the Google account that will own the setup (yours).

1. Open <https://console.cloud.google.com/> and sign in.
2. Project list at the top > **New project**. Name: `Objectif Solidarite site`. **Create**, then select it.
3. Menu > **APIs & Services** > **OAuth consent screen** (the newer console calls it **Google Auth Platform**). **Get started** if asked.
   - **App name**: `Objectif Solidarité`. **User support email**: your address.
   - **Audience**: **External**. **Contact information**: your address. Accept the policy, **Create**.
4. **Data Access** (or **Scopes**): **Add or remove scopes**, tick `openid`, `.../auth/userinfo.email`, `.../auth/userinfo.profile`. **Update**, **Save**. These basic scopes need no Google review.
5. **Audience**: either add each editor's Gmail address under **Test users**, or click **Publish app**. Only the people listed in **Utilisateurs** on the site can get in either way.
6. **Clients** (or **Credentials** > **Create credentials** > **OAuth client ID**):
   - **Application type**: **Web application**. Name: `Site web`.
   - **Authorised redirect URIs**, add both:
     - `https://test.objectif-solidarite.org/admin/auth/callback`
     - `http://localhost:8000/admin/auth/callback` (testing on your computer)
   - **Create**.
7. Copy the **Client ID** and **Client secret** into `config.php` (`google_client_id`, `google_client_secret`).

At go-live you add the real address to the same client ([section 5](#5-go-live-on-objectif-solidariteorg)).

## 5. Go-live on objectif-solidarite.org

Only after you have approved the staging site.

1. **Write down the current DNS records** of `objectif-solidarite.org` (OVH: **Web Cloud** > **Noms de domaine** > `objectif-solidarite.org` > **Zone DNS**, or Wix's DNS screen if the domain is managed there). Take a screenshot. You need them to roll back.
2. **Check mail records first.** If the zone has `MX` records (or `TXT` records such as SPF), e-mail for the domain is in use. Do not touch them, now or later. The association's address is a Gmail address, so it does not depend on this domain.
3. **Detach the domain from `fred`.** In the Multisite tab, `objectif-solidarite.org` is currently attached to another site's folder (`fred`). Open that line > **...** > **Modifier le domaine** and remove it (or delete that line). Do the same for `www.objectif-solidarite.org` if listed. This does not affect the other site's own domain.
4. **Attach it to `os`.** **Ajouter un domaine ou un sous-domaine**: `objectif-solidarite.org`, **Dossier racine** `os`, tick **SSL**, and tick **Créer également le sous-domaine www**. If OVH asks whether to change the DNS, accept only the `A`/`AAAA` (and `CNAME` for `www`) changes.
5. **Git.** If OVH asks for the Git settings on the new line, give the same repository and branch, then copy the new webhook URL into GitHub (section 2.5, step 3).
6. **Google client.** In the Google Cloud console, open the client and add the redirect URI `https://objectif-solidarite.org/admin/auth/callback`. Keep the test one for now.
7. **config.php.** Change `'base_url' => 'https://objectif-solidarite.org',` and upload `config.php` again into `os-private/`.
8. **DNS.** If the domain's DNS still points to Wix, change only the `A` record of `objectif-solidarite.org` and the `www` record to the values OVH shows on the Multisite page. Do not touch `MX`, `TXT` or other records. Changes take from minutes to 24 hours.
9. **SSL.** Wait until `https://objectif-solidarite.org` opens without a warning (Multisite: the SSL column says active). Then visitors on `www.` are sent to the bare address automatically.
10. **Check**, on the real address: the home page, a project page, `/mafé` and `/contact-8` (redirects), `/sitemap.xml`, `/robots.txt`, and signing in at `/admin`.
11. **Search engines.** In Google Search Console, add the property `https://objectif-solidarite.org` and submit `https://objectif-solidarite.org/sitemap.xml`.
12. **Wix.** Keep the Wix subscription for a few days in case you need to roll back, then cancel it.

## 6. Updating the site later

1. Changes are committed and pushed to the `main` branch on GitHub.
2. The webhook tells OVH, which updates `os/` within a minute or so. Without the webhook (or if it fails): Multisite > the domain line > **...** > **Déployer Git**.
3. Open the site once. Database changes (migrations) run on their own on this first visit. They only add things and never delete data.

`os-private/` (settings, content, uploads) is never touched by an update.

**Without OVH Git** (section 2.5 bis): commit the change, then prepare and send the code only. Never send `os-private` again.

```bash
tools/php85/php.exe build/prepare-upload.php
```

```bash
scp -r build/upload/os LOGIN@SERVER:
```

This overwrites the program files in `os/`. A file deleted from the code stays on the server; that is harmless. Then open the site once (step 3 above).

Before a big update: **Admin** > **Sauvegarde** > download a backup.

## 7. Rolling back

**A code update broke something.** On your computer:

```bash
git revert --no-edit HEAD
```

```bash
git push
```

OVH deploys the previous code within a minute. Database changes made by the update stay (migrations only add tables or columns, which older code ignores).

**Content was damaged.** For one page: **Pages** > the page > **Historique** > **Restaurer**. For the whole site: restore a backup ([section 8](#8-backups-and-restoring)).

**Go-live went wrong.** Put back the DNS records you wrote down in section 5, step 1. Wix answers again as soon as DNS has updated (minutes to 24 hours). The staging site keeps working on `test.objectif-solidarite.org` meanwhile.

## 8. Backups and restoring

- **Admin** > **Sauvegarde** > **Télécharger une sauvegarde** gives one zip with the database, all images and PDFs (originals and web versions) and the settings template. Your real `config.php` is not included, on purpose: keep your own copy.
- Automatic backups are not part of version 1. Download one after each important change and keep it on your computer or Google Drive.

To restore:

1. Install the site as in section 2, with your `config.php` in `os-private/`.
2. Unzip the backup. Upload its `data/` and `media/` folders into `os-private/` (replacing what is there).
3. Open the site. Everything is back: pages, history, settings, users, files.

## 9. Working on your computer (tests, content import, preview)

### 9.1 PHP and the local site

The `tools/` folder holds portable PHP 8.5 and PHP 7.3 (official Windows builds). Nothing is installed on Windows. It is not in the repository: after a fresh clone, download them again from `windows.php.net`.

Start the site locally:

```bash
tools/php85/php.exe -S localhost:8000 -t public public/index.php
```

Then open <http://localhost:8000> and <http://localhost:8000/admin>. The last argument (`public/index.php`) makes `/sitemap.xml`, `/robots.txt` and `/media/...` work with PHP's built-in server. On OVH, Apache and `.htaccess` do that job.

Locally, `config.php` sits next to `app/` (not committed) with `'env' => 'development'`, `'base_url' => 'http://localhost:8000'`, `'force_https' => false`. With `'dev_login' => true`, the login page also shows **Entrer sans Google**. It only works on your own computer in development mode, never on the server.

### 9.2 Tests

Run the automatic tests on both PHP versions. Any PHP warning or deprecation fails the run. The import tests use made-up content only, never the real package.

```bash
tools/php73/php.exe tests/run.php
```

```bash
tools/php85/php.exe tests/run.php
```

### 9.3 Preview the imported content

After building the seed (section 3), see it as it will look, without touching the seed:

```bash
tools/php85/php.exe build/preview-seed.php --publish-all
```

```bash
tools/php85/php.exe -S localhost:8300 -t tests/tmp/preview/os/public tests/tmp/preview/os/public/index.php
```

Open <http://localhost:8300>. `--publish-all` publishes every page in the preview copy only. The admin is at `/admin` (**Entrer sans Google**, address `admin@exemple.fr`).

### 9.4 First push to GitHub

Create an empty **public** repository named `objectif-solidarite` on GitHub (no README, no licence), then:

```bash
git remote add origin https://github.com/drouq/objectif-solidarite.git
```

```bash
git push -u origin main
```

### 9.5 Editor, fonts, screenshots

Rebuild the admin editor and the fonts after changing `editor-src/` (needs Node.js), then commit the result in `public/assets/`:

```bash
cd editor-src && npm install && npm run build
```

Screenshots with example content on port 8200 (needs Node.js and Microsoft Edge):

```bash
tools/php85/php.exe build/review-site.php
```

```bash
tools/php85/php.exe -S localhost:8200 -t tests/tmp/review-site/www tests/tmp/review-site/www/index.php
```

```bash
cd screenshots-src && npm install && node shoot.mjs http://localhost:8200 ../review/latest
```

`shoot.mjs` takes an optional third argument, a JSON list of pages (see the top of the file). Keep a list made from the real content out of the repository.

## 10. Good to know

- **PHP 7.3** no longer gets security fixes. The site runs on it because other sites on the plan need it. The code also runs unchanged on PHP 8.x (tested on 8.5). Move to PHP 8 as soon as you can. OVH documents that a `.ovhconfig` file in a site's root folder can set its PHP version in a multisite setup. Ask OVH whether that works on your plan.
- **HelloAsso buttons in texts**: in the editor, use `{{HELLOASSO_DON_URL}}` or `{{HELLOASSO_ADHESION_URL}}` as the link address. The button follows the addresses in Réglages.
- **Key figures in texts**: write `{{fig:key}}` (for example `{{fig:frais_annee}}`). It shows the value and unit from Réglages > Chiffres clés. An unknown key shows nothing and is noted in `os-private/data/logs/content.log`. The editor lists the available keys under the blocks.
- **Markers**: `[À VÉRIFIER : ...]` and `[À COMPLÉTER : ...]` in a page make a yellow warning box appear above **Enregistrer**. It never blocks saving.
- **Tables**: the first row of a table is always shown as its header row. On a phone, a wide table scrolls sideways in its own box.
- **Old addresses** (Wix and the earlier site) are permanent redirects, loaded by the content import. `/contact-8` goes to the HelloAsso page from Réglages (to "Nous aider" while it is empty).
- **Home page**: the cards under the texts show the sub-pages of the page set in Réglages > Page d'accueil (`projets` by default). The figures band shows the key figures ticked **Accueil**.
- **Upload limits** are in `config.php` (`max_image_mb`, `max_pdf_mb`). The server's PHP limits must be at least as high. `check.php` shows them.
- **Images** are resized on the server: web versions at 480, 960 and 1600 px wide (WebP), originals kept in `data/originals`. Phone photos are turned upright and their location data is removed from the web versions.
- **Replacing a file** in the Médiathèque keeps every page that uses it working.
- **Changing a page address** creates a permanent (301) redirect from the old address automatically.
- **History**: each page keeps its last 20 versions. "Restaurer" brings back the text, title, image and search-engine texts, and keeps the current version in the history too.
- **Privacy**: no analytics, no outside fonts or scripts, no cookies for visitors (so no cookie banner). Videos come from `youtube-nocookie.com` or Vimeo and only load when the visitor clicks them. Only signed-in editors get a session cookie.
- **Errors** are written to `os-private/data/logs/php-errors.log`, never shown to visitors.
- **Two editors on the same page**: if someone saved the page while you were editing it, your save is refused with a message, so nobody overwrites the other's work by mistake.
- **Legal pages** (mentions légales, privacy): create a normal page, untick "Afficher dans le menu principal", tick "Afficher dans le pied de page".
- **Never edit files in `os/` by FTP**: the next deployment would overwrite them, or block on a conflict.
- **Fonts**: Fraunces (headings) and Lexend (text), both under the SIL Open Font License, files in `public/assets/fonts/`.

## 11. Alternative: installing with FileZilla

If Git deployment is not available, the site can be uploaded by hand.

1. Build the package: `tools/php85/php.exe build/build.php`. This creates `dist/` with `app/`, `www/`, `config.sample.php`.
2. On OVH, create `os/` with `os/www/` inside, and set the domain's **Dossier racine** to `os/www`.
3. In FileZilla (**Server** > **Force showing hidden files**, so `.htaccess` files are sent): upload `dist/app` into `os/`, the contents of `dist/www` into `os/www/`.
4. Put `config.php` in `os/` next to `app/`. `data/` is created next to it, uploads go to `os/www/media/`. For the content, upload the seed's `data/` next to `config.php` and the seed's `media/` contents into `os/www/media/`.
5. Updates: build again and upload `dist/app` and `dist/www` with **Overwrite**. Never upload `config.php`, `data/` or `www/media/` from your computer. `dist/` never contains them.
