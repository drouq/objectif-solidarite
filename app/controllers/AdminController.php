<?php
declare(strict_types=1);

/** Admin area (/admin). All screens in French. */
final class AdminController
{
    public static function handle(string $path): void
    {
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex, nofollow');
        Session::start();

        if (Http::postTooLarge()) {
            $message = 'Le fichier est trop lourd pour le serveur (limite : '
                . Media::formatSize(Media::iniBytes((string) ini_get('post_max_size'))) . ').';
            if (self::wantsJson()) {
                Http::json(['error' => $message], 413);
            }
            Session::flash('error', $message);
            Http::redirect(self::backPath());
        }
        if (Http::isPost() && !Session::checkCsrf()) {
            if (self::wantsJson()) {
                Http::json(['error' => 'Session expirée. Rechargez la page.'], 400);
            }
            http_response_code(400);
            echo View::admin('message', [
                'title' => 'Session expirée',
                'message' => 'Votre session a expiré ou le formulaire est trop ancien. Revenez en arrière, rechargez la page, puis recommencez.',
            ]);
            return;
        }

        $route = substr($path, strlen('/admin'));
        $route = $route === '' ? '/' : rtrim($route, '/');
        if ($route === '') {
            $route = '/';
        }

        // Sign-in routes: no account needed.
        if ($route === '/auth/login') {
            self::loginPage();
            return;
        }
        if ($route === '/auth/google') {
            self::startGoogle();
            return;
        }
        if ($route === '/auth/callback') {
            self::googleCallback();
            return;
        }
        if ($route === '/auth/dev') {
            self::devLogin();
            return;
        }
        if ($route === '/auth/logout' && Http::isPost()) {
            Auth::logout();
            Session::start();
            Session::flash('info', 'Vous êtes déconnecté.');
            Http::redirect('/admin/auth/login');
        }

        if (Auth::user() === null) {
            if (self::wantsJson()) {
                Http::json(['error' => 'Session expirée. Reconnectez-vous.'], 401);
            }
            Http::redirect('/admin/auth/login');
        }

        $m = [];
        if ($route === '/') {
            Http::redirect('/admin/pages');
        } elseif ($route === '/pages') {
            self::pagesList();
        } elseif ($route === '/pages/nouvelle') {
            self::pageCreate();
        } elseif (preg_match('~^/pages/(\d+)$~', $route, $m)) {
            self::pageEdit((int) $m[1]);
        } elseif (preg_match('~^/pages/(\d+)/(deplacer|statut)$~', $route, $m) && Http::isPost()) {
            self::pageQuickAction((int) $m[1], $m[2]);
        } elseif (preg_match('~^/pages/(\d+)/supprimer$~', $route, $m)) {
            self::pageDelete((int) $m[1]);
        } elseif (preg_match('~^/pages/(\d+)/historique$~', $route, $m)) {
            self::pageHistory((int) $m[1]);
        } elseif (preg_match('~^/pages/(\d+)/historique/(\d+)/restaurer$~', $route, $m) && Http::isPost()) {
            self::pageRestore((int) $m[1], (int) $m[2]);
        } elseif ($route === '/medias') {
            self::mediaList();
        } elseif ($route === '/medias.json') {
            self::mediaJson();
        } elseif (preg_match('~^/medias/(\d+)$~', $route, $m)) {
            self::mediaEdit((int) $m[1]);
        } elseif (preg_match('~^/medias/(\d+)/(remplacer|supprimer)$~', $route, $m) && Http::isPost()) {
            self::mediaAction((int) $m[1], $m[2]);
        } elseif ($route === '/reglages') {
            self::requireAdmin();
            self::settings();
        } elseif ($route === '/utilisateurs') {
            self::requireAdmin();
            self::users();
        } elseif ($route === '/sauvegarde') {
            self::requireAdmin();
            self::backup();
        } else {
            throw new HttpStop(404);
        }
    }

    private static function wantsJson(): bool
    {
        return Http::query('format') === 'json'
            || strpos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;
    }

    private static function backPath(): string
    {
        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $path = (string) parse_url($referer, PHP_URL_PATH);
        return starts_with($path, '/admin') ? $path : '/admin/pages';
    }

    private static function requireAdmin(): void
    {
        if (!Auth::isAdmin()) {
            http_response_code(403);
            echo View::admin('message', [
                'title' => 'Accès réservé',
                'message' => 'Cet écran est réservé à l\'administrateur du site.',
            ]);
            throw new HttpStop(403);
        }
    }

    // ===================== Sign in =====================

    private static function loginPage(): void
    {
        if (Auth::user() !== null) {
            Http::redirect('/admin/pages');
        }
        $httpsProblem = Config::isProduction() && !Http::isHttps();
        echo View::render('admin/login', [
            'flash' => Session::takeFlash(),
            'configured' => GoogleOAuth::isConfigured(),
            'httpsProblem' => $httpsProblem,
            'devLogin' => Auth::isDevLoginAllowed(),
            'devUsers' => Auth::isDevLoginAllowed() ? Users::allUsers() : [],
            'csrf' => Session::csrfToken(),
        ]);
    }

    private static function startGoogle(): void
    {
        if (Config::isProduction() && !Http::isHttps()) {
            // Never start the login over plain HTTP in production.
            Session::flash('error', 'La connexion n\'est possible qu\'en HTTPS. Activez le certificat SSL du site.');
            Http::redirect('/admin/auth/login');
        }
        if (!GoogleOAuth::isConfigured()) {
            Session::flash('error', 'La connexion Google n\'est pas encore configurée (config.php).');
            Http::redirect('/admin/auth/login');
        }
        Http::redirect(GoogleOAuth::authorizationUrl());
    }

    private static function googleCallback(): void
    {
        if (Auth::tooManyFailures()) {
            Session::flash('error', 'Trop de tentatives de connexion. Réessayez dans 15 minutes.');
            Http::redirect('/admin/auth/login');
        }
        try {
            $claims = GoogleOAuth::handleCallback($_GET);
        } catch (UserError $ex) {
            Auth::recordFailure();
            Session::flash('error', $ex->getMessage());
            Http::redirect('/admin/auth/login');
            return;
        } catch (HttpStop $stop) {
            throw $stop;
        } catch (Throwable $ex) {
            Auth::recordFailure();
            App::logException($ex);
            Session::flash('error', 'La connexion avec Google a échoué. Réessayez. Si le problème continue, contactez l\'administrateur.');
            Http::redirect('/admin/auth/login');
            return;
        }
        $email = (string) $claims['email'];
        $user = Users::allowed($email);
        if ($user === null) {
            Auth::recordFailure();
            Session::flash('error', 'Le compte ' . $email . ' n\'est pas autorisé à modifier ce site. '
                . 'Demandez à l\'administrateur de vous ajouter.');
            Http::redirect('/admin/auth/login');
        }
        Auth::login($user, (string) ($claims['name'] ?? ''));
        Session::flash('success', 'Bienvenue' . (!empty($claims['given_name']) ? ' ' . $claims['given_name'] : '') . ' !');
        Http::redirect('/admin/pages');
    }

    /** Local test login without Google: localhost + development + dev_login only. */
    private static function devLogin(): void
    {
        if (!Auth::isDevLoginAllowed() || !Http::isPost()) {
            throw new HttpStop(404);
        }
        $email = Http::postString('email');
        $user = Users::allowed($email);
        if ($user === null) {
            Session::flash('error', 'Adresse absente de la liste des utilisateurs.');
            Http::redirect('/admin/auth/login');
        }
        Auth::login($user, 'Test local');
        Http::redirect('/admin/pages');
    }

    // ===================== Pages =====================

    private static function pagesList(): void
    {
        echo View::admin('pages', [
            'title' => 'Pages',
            'nav' => 'pages',
            'tree' => Pages::tree(),
            'parentOptions' => Pages::parentOptions(null),
        ]);
    }

    private static function pageCreate(): void
    {
        if (!Http::isPost()) {
            Http::redirect('/admin/pages');
        }
        $parent = Http::postString('parent_id');
        try {
            $id = Pages::create(Http::postString('title'), $parent !== '' ? (int) $parent : null, Auth::email());
        } catch (UserError $ex) {
            Session::flash('error', $ex->getMessage());
            Http::redirect('/admin/pages');
            return;
        }
        Session::flash('success', 'Page créée. Elle est masquée tant que vous ne la publiez pas.');
        Http::redirect('/admin/pages/' . $id);
    }

    private static function pageEdit(int $id): void
    {
        $page = Pages::find($id);
        if ($page === null) {
            throw new HttpStop(404);
        }
        $errors = [];
        $form = $page;
        if (Http::isPost()) {
            $errors = Pages::save($id, $_POST, Auth::email());
            if (!$errors) {
                Session::flash('success', 'Page enregistrée.');
                Http::redirect('/admin/pages/' . $id);
            }
            // Show the form again with what was typed.
            foreach (['title', 'slug', 'status', 'seo_title', 'seo_description', 'header_media_id', 'parent_id'] as $field) {
                $form[$field] = Http::postString($field);
            }
            $form['show_in_menu'] = Http::postString('show_in_menu') === '1' ? 1 : 0;
            $form['show_in_footer'] = Http::postString('show_in_footer') === '1' ? 1 : 0;
            $form['make_home'] = Http::postString('make_home');
            $form['blocks'] = Http::postString('blocks_json');
            if (isset($errors['conflict'])) {
                $form['updated_at'] = $page['updated_at'];
            } else {
                $form['updated_at'] = Http::postString('loaded_updated_at');
            }
        }
        $blocks = json_decode((string) $form['blocks'], true);
        $mediaIds = Pages::mediaIds(['header_media_id' => $form['header_media_id'], 'blocks' => is_array($blocks) ? $blocks : []]);
        $mediaJson = [];
        foreach (Media::findMany($mediaIds) as $media) {
            $mediaJson[(int) $media['id']] = Media::toJson($media);
        }
        echo View::admin('page-edit', [
            'title' => 'Modifier « ' . $page['title'] . ' »',
            'nav' => 'pages',
            'page' => $page,
            'form' => $form,
            'blocks' => is_array($blocks) ? $blocks : [],
            'mediaJson' => $mediaJson,
            'errors' => $errors,
            'parentOptions' => Pages::parentOptions($id),
            'depth' => Pages::depth($page),
        ]);
    }

    private static function pageQuickAction(int $id, string $action): void
    {
        try {
            if ($action === 'deplacer') {
                Pages::move($id, Http::postString('direction') === 'up' ? 'up' : 'down');
            } else {
                $status = Http::postString('status') === 'published' ? 'published' : 'hidden';
                Pages::setStatus($id, $status, Auth::email());
                Session::flash('success', $status === 'published' ? 'Page publiée.' : 'Page masquée.');
            }
        } catch (UserError $ex) {
            Session::flash('error', $ex->getMessage());
        }
        Http::redirect('/admin/pages#page-' . $id);
    }

    private static function pageDelete(int $id): void
    {
        $page = Pages::find($id);
        if ($page === null) {
            throw new HttpStop(404);
        }
        if (Http::isPost()) {
            try {
                Pages::delete($id);
                Session::flash('success', 'Page « ' . $page['title'] . ' » supprimée.');
                Http::redirect('/admin/pages');
            } catch (UserError $ex) {
                Session::flash('error', $ex->getMessage());
                Http::redirect('/admin/pages/' . $id . '/supprimer');
            }
        }
        echo View::admin('page-delete', [
            'title' => 'Supprimer une page',
            'nav' => 'pages',
            'page' => $page,
            'childCount' => count(Pages::children($id, false)),
        ]);
    }

    private static function pageHistory(int $id): void
    {
        $page = Pages::find($id);
        if ($page === null) {
            throw new HttpStop(404);
        }
        echo View::admin('history', [
            'title' => 'Historique de « ' . $page['title'] . ' »',
            'nav' => 'pages',
            'page' => $page,
            'versions' => Pages::versions($id),
        ]);
    }

    private static function pageRestore(int $id, int $versionId): void
    {
        try {
            Pages::restoreVersion($id, $versionId, Auth::email());
            Session::flash('success', 'Version restaurée. L\'état précédent reste disponible dans l\'historique.');
        } catch (UserError $ex) {
            Session::flash('error', $ex->getMessage());
        }
        Http::redirect('/admin/pages/' . $id);
    }

    // ===================== Media =====================

    private static function mediaList(): void
    {
        if (Http::isPost()) {
            $file = $_FILES['file'] ?? null;
            try {
                if (!is_array($file) || is_array($file['name'] ?? null)) {
                    throw new UserError('Aucun fichier choisi.');
                }
                $media = Media::upload($file, Auth::email());
                $alt = Http::postString('alt');
                if ($alt !== '') {
                    Media::setAlt((int) $media['id'], $alt);
                    $media = Media::find((int) $media['id']);
                }
                if (self::wantsJson()) {
                    Http::json(['media' => Media::toJson((array) $media)]);
                }
                Session::flash('success', 'Fichier ajouté. Pensez à décrire l\'image (texte alternatif).');
                Http::redirect('/admin/medias/' . $media['id']);
            } catch (UserError $ex) {
                if (self::wantsJson()) {
                    Http::json(['error' => $ex->getMessage()], 422);
                }
                Session::flash('error', $ex->getMessage());
                Http::redirect('/admin/medias');
            }
        }
        echo View::admin('media', [
            'title' => 'Médiathèque',
            'nav' => 'media',
            'items' => Media::allMedia(),
            'usage' => Media::usageCounts(),
        ]);
    }

    private static function mediaJson(): void
    {
        $list = [];
        foreach (Media::allMedia() as $media) {
            $list[] = Media::toJson($media);
        }
        Http::json([
            'media' => $list,
            'limits' => ['image' => Config::maxBytes('image'), 'pdf' => Config::maxBytes('pdf')],
        ]);
    }

    private static function mediaEdit(int $id): void
    {
        $media = Media::find($id);
        if ($media === null) {
            throw new HttpStop(404);
        }
        if (Http::isPost()) {
            Media::setAlt($id, Http::postString('alt'));
            Session::flash('success', 'Description enregistrée.');
            Http::redirect('/admin/medias/' . $id);
        }
        echo View::admin('media-edit', [
            'title' => $media['original_name'] !== '' ? $media['original_name'] : 'Fichier',
            'nav' => 'media',
            'media' => $media,
            'usage' => Media::usage($id),
        ]);
    }

    private static function mediaAction(int $id, string $action): void
    {
        $media = Media::find($id);
        if ($media === null) {
            throw new HttpStop(404);
        }
        if ($action === 'remplacer') {
            try {
                $file = $_FILES['file'] ?? null;
                if (!is_array($file) || is_array($file['name'] ?? null)) {
                    throw new UserError('Aucun fichier choisi.');
                }
                Media::upload($file, Auth::email(), $id);
                Session::flash('success', 'Fichier remplacé. Les pages qui l\'utilisent affichent maintenant le nouveau fichier.');
            } catch (UserError $ex) {
                Session::flash('error', $ex->getMessage());
            }
            Http::redirect('/admin/medias/' . $id);
        }
        $usage = Media::usage($id);
        if ($usage && Http::postString('confirm_in_use') !== '1') {
            Session::flash('error', 'Ce fichier est utilisé. Cochez la case de confirmation pour le supprimer quand même.');
            Http::redirect('/admin/medias/' . $id);
        }
        Media::delete($id);
        Session::flash('success', 'Fichier supprimé.');
        Http::redirect('/admin/medias');
    }

    // ===================== Admin only =====================

    private static function settings(): void
    {
        $errors = [];
        $values = Settings::all();
        if (Http::isPost()) {
            $errors = Settings::saveForm($_POST);
            if (!$errors) {
                Session::flash('success', 'Réglages enregistrés.');
                Http::redirect('/admin/reglages');
            }
            foreach (array_keys(Settings::FIELDS) as $key) {
                $values[$key] = Http::postString($key);
            }
        }
        $mediaJson = [];
        foreach (['logo_media_id', 'favicon_media_id', 'og_media_id'] as $key) {
            $media = Media::find((int) $values[$key]);
            if ($media !== null) {
                $mediaJson[(int) $media['id']] = Media::toJson($media);
            }
        }
        echo View::admin('settings', [
            'title' => 'Réglages',
            'nav' => 'settings',
            'values' => $values,
            'errors' => $errors,
            'mediaJson' => $mediaJson,
        ]);
    }

    private static function users(): void
    {
        if (Http::isPost()) {
            $action = Http::postString('action');
            $current = (int) Auth::user()['id'];
            try {
                if ($action === 'add') {
                    Users::add(Http::postString('email'), Http::postString('role'));
                    Session::flash('success', 'Personne ajoutée. Elle peut se connecter avec son compte Google.');
                } elseif ($action === 'role') {
                    Users::setRole((int) Http::postString('id'), Http::postString('role'), $current);
                    Session::flash('success', 'Rôle modifié.');
                } elseif ($action === 'remove') {
                    Users::remove((int) Http::postString('id'), $current);
                    Session::flash('success', 'Accès retiré.');
                }
            } catch (UserError $ex) {
                Session::flash('error', $ex->getMessage());
            }
            Http::redirect('/admin/utilisateurs');
        }
        echo View::admin('users', [
            'title' => 'Utilisateurs',
            'nav' => 'users',
            'users' => Users::allUsers(),
            'currentId' => (int) Auth::user()['id'],
        ]);
    }

    private static function backup(): void
    {
        if (Http::isPost()) {
            try {
                $zip = Backup::create();
            } catch (UserError $ex) {
                Session::flash('error', $ex->getMessage());
                Http::redirect('/admin/sauvegarde');
                return;
            }
            Backup::send($zip);
            throw new HttpStop(200);
        }
        $mediaCount = (int) Db::value('SELECT COUNT(*) FROM media');
        echo View::admin('backup', [
            'title' => 'Sauvegarde',
            'nav' => 'backup',
            'dbSize' => is_file(Db::file()) ? (int) filesize(Db::file()) : 0,
            'mediaCount' => $mediaCount,
        ]);
    }
}
