<?php
/*
 * Test suite. Runs on PHP 7.3 and PHP 8.x:
 *   tools/php73/php.exe tests/run.php
 *   tools/php85/php.exe tests/run.php
 *
 * 1. Unit tests in this process (temporary data folder).
 * 2. HTTP tests against a temporary copy of the site served by PHP's built-in server.
 * Any PHP warning, notice or deprecation counts as a failure.
 */
declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$tmp = __DIR__ . '/tmp/php' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION;
rrmdir($tmp);
mkdir($tmp . '/unit/public', 0777, true);
mkdir($tmp . '/unit/data', 0777, true);

set_error_handler(function (int $no, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $no, $file, $line);
});

define('ROOT_PATH', $projectRoot);
define('PUBLIC_PATH', $tmp . '/unit/public');
define('DATA_PATH', $tmp . '/unit/data');
require $projectRoot . '/app/bootstrap.php';
restore_error_handler();
set_error_handler(function (int $no, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $no, $file, $line);
});
ini_set('display_errors', '1');
Config::override([
    'env' => 'development',
    'base_url' => 'http://localhost:8000',
    'admin_email' => 'Admin@Example.org',
    'google_client_id' => 'test-client.apps.googleusercontent.com',
    'google_client_secret' => 'secret',
    'max_image_mb' => 10,
    'max_pdf_mb' => 20,
]);

$passed = 0;
$failed = [];
function check(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
    } else {
        $failed[] = $name . ($detail !== '' ? ' — ' . $detail : '');
        echo "  FAIL: $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}
function throwsUserError(callable $fn): bool
{
    try {
        $fn();
    } catch (UserError $ex) {
        return true;
    }
    return false;
}
function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        is_dir($path) ? rrmdir($path) : unlink($path);
    }
    rmdir($dir);
}
function rcopy(string $from, string $to): void
{
    @mkdir($to, 0777, true);
    foreach (scandir($from) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $a = $from . '/' . $item;
        $b = $to . '/' . $item;
        is_dir($a) ? rcopy($a, $b) : copy($a, $b);
    }
}

echo 'PHP ' . PHP_VERSION . "\n";

// ---------------------------------------------------------------------------
echo "Migrations\n";
Migrations::run();
Migrations::run(); // twice: must be safe
check('schema at latest version', Migrations::currentVersion() === Migrations::latestVersion());
check('home page seeded once', (int) Db::value('SELECT COUNT(*) FROM pages WHERE is_home = 1') === 1);
$homeBlocks = (string) Pages::home()['blocks'];
check('only two migrations (content comes from the import)', Migrations::latestVersion() === 2);
check('home page is a placeholder for this site', strpos($homeBlocks, '[À COMPLÉTER : présentation de l\'association Objectif Solidarité.]') !== false);
check('only the home page is created', (int) Db::value('SELECT COUNT(*) FROM pages') === 1);
check('home history records the creation', Pages::versions((int) Pages::home()['id'])[0]['note'] === 'Création');
check('site name default', Settings::get('site_name') === 'Objectif Solidarité');
check('contact e-mail seeded (the correct spelling)', Settings::get('contact_email') === 'objectifsolidarite1@gmail.com');
check('old paths and key figures tables exist', Db::value("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name IN ('old_paths', 'key_figures')") == 2);
check('data folder protected', is_file(DATA_PATH . '/.htaccess'));
check('media folder protected', is_file(PUBLIC_PATH . '/media/.htaccess'));

// ---------------------------------------------------------------------------
echo "Slugs\n";
check('slug accents', Slug::make('Qui sommes-nous ?') === 'qui-sommes-nous');
check('slug French', Slug::make("L'État & le Droit — Œuvres") === 'l-etat-et-le-droit-oeuvres', Slug::make("L'État & le Droit — Œuvres"));
check('slug empty', Slug::make('!!!') === '');
check('slug reserved', Slug::isReserved('admin'));

// ---------------------------------------------------------------------------
echo "HTML sanitiser\n";
$dirty = '<p style="color:red" class="MsoNormal" onclick="x()">Bonjour <b>gras</b> <i>it</i> <span style="x">été</span></p>'
    . '<script>alert(1)</script><style>p{}</style><iframe src="https://evil"></iframe>'
    . '<p><a href="javascript:alert(1)">bad</a> <a href=" JaVaScRiPt:alert(1)">bad2</a> <a href="https://ok.fr" target="_blank" onmouseover="x">ok</a>'
    . ' <a href="/don" class="btn evil">bouton</a> <a href="data:text/html,x">data</a></p>'
    . '<img src="https://evil.example/x.png" onerror="x"><img src="/media/abc123-960.webp?v=1" alt="Photo" data-media-id="4" onload="y">'
    . '<h1>Titre</h1><div>Texte libre</div><table><tr><td>cellule</td></tr></table>';
$clean = Sanitizer::html($dirty);
check('no script', stripos($clean, 'script') === false && stripos($clean, 'alert') === false, $clean);
check('no style or class junk', strpos($clean, 'style=') === false && strpos($clean, 'Mso') === false);
check('no event handlers', stripos($clean, 'onclick') === false && stripos($clean, 'onerror') === false && stripos($clean, 'onload') === false);
check('no iframe', stripos($clean, 'iframe') === false);
check('javascript: links removed', stripos($clean, 'javascript') === false && strpos($clean, 'data:') === false);
check('good link kept, target dropped', strpos($clean, '<a href="https://ok.fr">ok</a>') !== false, $clean);
check('button style kept alone', strpos($clean, '<a href="/don" class="btn">bouton</a>') !== false, $clean);
check('b and i converted', strpos($clean, '<strong>gras</strong>') !== false && strpos($clean, '<em>it</em>') !== false);
check('UTF-8 kept', strpos($clean, 'été') !== false, $clean);
check('outside image dropped', strpos($clean, 'evil.example') === false);
check('library image kept', strpos($clean, '<img src="/media/abc123-960.webp?v=1" alt="Photo" data-media-id="4">') !== false, $clean);
check('h1 becomes h2', strpos($clean, '<h2>Titre</h2>') !== false);
check('div becomes paragraph', strpos($clean, '<p>Texte libre</p>') !== false, $clean);
check('empty input', Sanitizer::html('') === '');
check('plain text wrapped', Sanitizer::html('juste du texte') === '<p>juste du texte</p>');

// ---------------------------------------------------------------------------
echo "Embeds\n";
$ok = [
    'https://www.youtube.com/watch?v=dQw4w9WgXcQ' => 'youtube',
    'https://youtu.be/dQw4w9WgXcQ?t=10' => 'youtube',
    'youtube.com/shorts/dQw4w9WgXcQ' => 'youtube',
    'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ' => 'youtube',
    'https://m.youtube.com/watch?feature=x&v=dQw4w9WgXcQ' => 'youtube',
    'https://vimeo.com/76979871' => 'vimeo',
    'https://player.vimeo.com/video/76979871?h=abcdef1234' => 'vimeo',
    'https://vimeo.com/channels/staffpicks/76979871' => 'vimeo',
];
foreach ($ok as $url => $provider) {
    $parsed = Embed::parse($url);
    check('embed accepted: ' . $url, $parsed !== null && $parsed['provider'] === $provider);
}
foreach (['https://www.dailymotion.com/video/x7tgad0', 'https://evil.com/youtube.com/watch?v=dQw4w9WgXcQ',
          'https://youtube.com.evil.com/watch?v=dQw4w9WgXcQ', 'https://www.youtube.com/watch?v=bad', 'javascript:alert(1)',
          'https://vimeo.com/about'] as $url) {
    check('embed refused: ' . $url, Embed::parse($url) === null);
}
check('nocookie player', strpos(Embed::playerUrl((array) Embed::parse('https://youtu.be/dQw4w9WgXcQ')), 'https://www.youtube-nocookie.com/embed/') === 0);

// ---------------------------------------------------------------------------
echo "Google ID token checks\n";
$opensslConf = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
$sslArgs = is_file($opensslConf) ? ['config' => $opensslConf] : [];
$key = openssl_pkey_new($sslArgs + ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$csr = openssl_csr_new(['commonName' => 'test'], $key, $sslArgs + ['digest_alg' => 'sha256']);
$cert = openssl_csr_sign($csr, null, $key, 1, $sslArgs + ['digest_alg' => 'sha256']);
openssl_x509_export($cert, $certPem);
$otherKey = openssl_pkey_new($sslArgs + ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$b64 = function (string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
};
$makeToken = function (array $claims, $signKey = null, string $kid = 'k1') use ($key, $b64): string {
    $head = $b64((string) json_encode(['alg' => 'RS256', 'kid' => $kid, 'typ' => 'JWT']));
    $body = $b64((string) json_encode($claims));
    openssl_sign($head . '.' . $body, $signature, $signKey ?: $key, OPENSSL_ALGO_SHA256);
    return $head . '.' . $body . '.' . $b64($signature);
};
$now = time();
$good = [
    'iss' => 'https://accounts.google.com', 'aud' => 'test-client.apps.googleusercontent.com',
    'exp' => $now + 3600, 'iat' => $now, 'nonce' => 'n123', 'email' => 'Ed@Gmail.com', 'email_verified' => true,
];
$certs = ['k1' => $certPem];
$verify = function (array $claims, $signKey = null, string $kid = 'k1', string $nonce = 'n123') use ($makeToken, $certs): string {
    try {
        GoogleOAuth::verifyIdToken($makeToken($claims, $signKey, $kid), $nonce, $certs);
        return 'ok';
    } catch (Throwable $ex) {
        return $ex->getMessage();
    }
};
check('valid token accepted', $verify($good) === 'ok', $verify($good));
check('wrong audience refused', $verify(array_merge($good, ['aud' => 'other'])) !== 'ok');
check('wrong issuer refused', $verify(array_merge($good, ['iss' => 'https://evil.example'])) !== 'ok');
check('expired refused', $verify(array_merge($good, ['exp' => $now - 3600])) !== 'ok');
check('wrong nonce refused', $verify($good, null, 'k1', 'other') !== 'ok');
check('unverified e-mail refused', $verify(array_merge($good, ['email_verified' => false])) !== 'ok');
check('bad signature refused', $verify($good, $otherKey) !== 'ok');
check('unknown key refused', $verify($good, null, 'k2') !== 'ok');
check('alg none refused', (function () use ($b64, $good, $certs): bool {
    $token = $b64('{"alg":"none","kid":"k1"}') . '.' . $b64((string) json_encode($good)) . '.';
    try {
        GoogleOAuth::verifyIdToken($token, 'n123', $certs);
        return false;
    } catch (Throwable $ex) {
        return true;
    }
})());

// ---------------------------------------------------------------------------
echo "Users and roles\n";
check('unknown address refused', Users::allowed('stranger@gmail.com') === null);
$admin = Users::allowed('admin@EXAMPLE.org');
check('config admin created as admin (case-insensitive)', $admin !== null && $admin['role'] === 'admin');
Users::add('Editor@Gmail.com', 'editor');
$editor = Users::allowed('editor@gmail.com');
check('editor allowed, case-insensitive', $editor !== null && $editor['role'] === 'editor');
check('duplicate refused', throwsUserError(function () {
    Users::add('EDITOR@gmail.com', 'editor');
}));
check('config admin cannot be removed', throwsUserError(function () use ($admin, $editor) {
    Users::remove((int) $admin['id'], (int) $editor['id']);
}));

// ---------------------------------------------------------------------------
echo "Pages\n";
$top = Pages::create('À propos de nous', null, 'test');
$child = Pages::create('Notre équipe', $top, 'test');
$grandChild = Pages::create('Le bureau', $child, 'test');
check('slug from title', Pages::find($top)['slug'] === 'a-propos-de-nous');
check('new page hidden', Pages::find($top)['status'] === 'hidden');
check('fourth level refused', throwsUserError(function () use ($grandChild) {
    Pages::create('Trop profond', $grandChild, 'test');
}));
$dup = Pages::create('À propos de nous', null, 'test');
check('duplicate slug numbered', Pages::find($dup)['slug'] === 'a-propos-de-nous-2');
$reserved = Pages::create('Admin', null, 'test');
check('reserved slug avoided', Pages::find($reserved)['slug'] === 'admin-page');
check('tree depth', count(array_filter(Pages::tree(), function ($p) {
    return $p['depth'] === 3;
})) === 1);

$page = Pages::find($top);
$form = [
    'title' => 'À propos', 'slug' => 'qui-sommes-nous', 'status' => 'published', 'parent_id' => '',
    'show_in_menu' => '1', 'seo_title' => '', 'seo_description' => '', 'header_media_id' => '',
    'blocks_json' => json_encode([
        ['type' => 'text', 'html' => '<p>Bonjour<script>x</script></p>'],
        ['type' => 'embed', 'url' => 'https://www.dailymotion.com/video/x7tgad0'],
    ]),
    'loaded_updated_at' => $page['updated_at'],
];
$errors = Pages::save($top, $form, 'test');
check('embed from other site refused', isset($errors['blocks']));
$form['blocks_json'] = json_encode([
    ['type' => 'text', 'html' => '<p>Bonjour<script>x</script></p>'],
    ['type' => 'cards', 'cards' => [['title' => 'Carte', 'text' => '', 'url' => 'javascript:alert(1)', 'media_id' => null]]],
]);
$errors = Pages::save($top, $form, 'test');
check('bad card link refused', isset($errors['blocks']));
$form['blocks_json'] = json_encode([
    ['type' => 'text', 'html' => '<p>Bonjour<script>x</script></p>'],
    ['type' => 'cards', 'cards' => [['title' => 'Carte', 'text' => 'Texte', 'url' => '/notre-equipe', 'media_id' => null]]],
    ['type' => 'embed', 'url' => 'https://youtu.be/dQw4w9WgXcQ'],
]);
$errors = Pages::save($top, $form, 'test');
check('valid save', $errors === [], json_encode($errors));
$saved = Pages::find($top);
check('slug changed', $saved['slug'] === 'qui-sommes-nous');
check('old slug redirects', Pages::redirectTarget('a-propos-de-nous') !== null && (int) Pages::redirectTarget('a-propos-de-nous')['id'] === $top);
check('saved HTML sanitised', strpos((string) $saved['blocks'], 'script') === false);
$stale = $form;
$stale['loaded_updated_at'] = $page['updated_at'];
check('conflict detected', isset(Pages::save($top, $stale, 'other')['conflict']));
$form['slug'] = 'a-propos-de-nous';
$form['loaded_updated_at'] = $saved['updated_at'];
Pages::save($top, $form, 'test');
check('taking back an old slug removes its redirect', Pages::redirectTarget('a-propos-de-nous') === null && Pages::redirectTarget('qui-sommes-nous') !== null);

// History: more than 20 saves keeps only 20, restore adds a version.
for ($i = 1; $i <= 24; $i++) {
    $current = Pages::find($top);
    $form['title'] = 'Version ' . $i;
    $form['loaded_updated_at'] = $current['updated_at'];
    $err = Pages::save($top, $form, 'test');
    if ($err) {
        check('history save ' . $i, false, json_encode($err));
    }
}
$versions = Pages::versions($top);
check('only 20 versions kept', count($versions) === 20, (string) count($versions));
$oldest = end($versions);
Pages::restoreVersion($top, (int) $oldest['id'], 'test');
$restored = Pages::find($top);
$oldData = json_decode((string) $oldest['data'], true);
check('restore puts old title back', $restored['title'] === $oldData['title'], $restored['title'] . ' vs ' . $oldData['title']);
check('restore adds a version', Pages::versions($top)[0]['note'] !== '' && strpos(Pages::versions($top)[0]['note'], 'Restauration') === 0);
check('still 20 versions after restore', count(Pages::versions($top)) === 20);

// Order and delete
$before = array_map(function ($p) {
    return (int) $p['id'];
}, Pages::children(0, false) ?: Db::all('SELECT id FROM pages WHERE parent_id IS NULL ORDER BY position'));
Pages::move($dup, 'up');
$after = array_map(function ($p) {
    return (int) $p['id'];
}, Db::all('SELECT id FROM pages WHERE parent_id IS NULL ORDER BY position'));
$idx = array_search($dup, $after, true);
check('move up', $idx !== false && $idx < array_search($dup, $before, true));
check('delete with children refused', throwsUserError(function () use ($child) {
    Pages::delete($child);
}));
check('home cannot be hidden', throwsUserError(function () {
    Pages::setStatus((int) Pages::home()['id'], 'hidden', 'test');
}));
Pages::delete($grandChild);
check('delete leaf', Pages::find($grandChild) === null && Pages::versions($grandChild) === []);
check('parent options exclude self and children', !in_array($top, array_map(function ($p) {
    return (int) $p['id'];
}, Pages::parentOptions($top)), true));

// ---------------------------------------------------------------------------
echo "Media\n";
$work = $tmp . '/files';
mkdir($work, 0777, true);
// A phone-like photo: 4032 x 3024 with noise, saved at high quality.
$photo = imagecreatetruecolor(4032, 3024);
for ($y = 0; $y < 3024; $y += 4) {
    for ($x = 0; $x < 4032; $x += 4) {
        $c = imagecolorallocate($photo, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255));
        imagefilledrectangle($photo, $x, $y, $x + 3, $y + 3, $c);
    }
}
imagejpeg($photo, $work . '/photo.jpg', 92);
$photo = null;
$photoSize = filesize($work . '/photo.jpg');
check('test photo is a few MB', $photoSize > 2 * 1024 * 1024 && $photoSize < 10 * 1024 * 1024, Media::formatSize($photoSize));
$t = microtime(true);
$media = Media::store($work . '/photo.jpg', 'Photo téléphone.jpg', 'test');
$elapsed = microtime(true) - $t;
check('photo processed', $media['kind'] === 'image' && Media::variants($media) === [480, 960, 1600], $media['variants']);
$webFile = PUBLIC_PATH . '/media/' . $media['token'] . '-1600.' . $media['web_ext'];
check('web version exists and is lighter', is_file($webFile) && filesize($webFile) < $photoSize / 2, is_file($webFile) ? Media::formatSize((int) filesize($webFile)) : 'missing');
$info = getimagesize($webFile);
check('web version max 1600 px wide', $info[0] === 1600 && $info[1] === 1200);
check('original kept privately', is_file(DATA_PATH . '/originals/' . $media['token'] . '.jpg'));
check('original not in public folder', !is_file(PUBLIC_PATH . '/media/' . $media['token'] . '.jpg'));
check('random file name', preg_match('/^[a-f0-9]{20}$/', (string) $media['token']) === 1);
echo '  (photo ' . Media::formatSize($photoSize) . ' resized in ' . round($elapsed, 1) . " s)\n";

// Too big: a JPEG padded to 10.5 MB.
copy($work . '/photo.jpg', $work . '/big.jpg');
$fh = fopen($work . '/big.jpg', 'ab');
fwrite($fh, str_repeat("\0", (int) (10.5 * 1024 * 1024) - $photoSize));
fclose($fh);
check('image over 10 MB refused', throwsUserError(function () use ($work) {
    Media::store($work . '/big.jpg', 'big.jpg', 'test');
}));
// Wrong types
file_put_contents($work . '/shell.jpg', "<?php echo 'hacked'; ?>");
check('PHP disguised as JPG refused', throwsUserError(function () use ($work) {
    Media::store($work . '/shell.jpg', 'shell.jpg', 'test');
}));
$gif = imagecreatetruecolor(10, 10);
imagegif($gif, $work . '/anim.gif');
check('GIF refused', throwsUserError(function () use ($work) {
    Media::store($work . '/anim.gif', 'anim.gif', 'test');
}));
file_put_contents($work . '/fake.pdf', 'not a pdf');
check('fake PDF refused', throwsUserError(function () use ($work) {
    Media::store($work . '/fake.pdf', 'fake.pdf', 'test');
}));
file_put_contents($work . '/doc.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
$pdf = Media::store($work . '/doc.pdf', 'Statuts.pdf', 'test');
check('PDF accepted', $pdf['kind'] === 'pdf' && is_file(PUBLIC_PATH . '/media/' . $pdf['token'] . '.pdf'));
check('image cannot replace PDF', throwsUserError(function () use ($work, $pdf) {
    Media::store($work . '/photo.jpg', 'x.jpg', 'test', (int) $pdf['id']);
}));
// PNG with transparency
$png = imagecreatetruecolor(800, 600);
imagesavealpha($png, true);
imagefill($png, 0, 0, imagecolorallocatealpha($png, 0, 0, 0, 127));
imagepng($png, $work . '/logo.png');
$pngMedia = Media::store($work . '/logo.png', 'logo.png', 'test');
check('small PNG keeps real width', Media::variants($pngMedia) === [480, 800], $pngMedia['variants']);

// Use in a page, then replace: same id and token, new version in the URL.
$current = Pages::find($top);
$form['loaded_updated_at'] = $current['updated_at'];
$form['header_media_id'] = (string) $media['id'];
$form['blocks_json'] = json_encode([
    ['type' => 'text', 'html' => '<p>Image <img src="' . Media::url($media) . '" data-media-id="' . $media['id'] . '" alt="Ma photo"> et <a href="/media/' . $pdf['token'] . '.pdf">statuts</a></p>'],
    ['type' => 'cards', 'cards' => [['title' => 'Carte', 'media_id' => $pngMedia['id']]]],
]);
check('save with media', Pages::save($top, $form, 'test') === []);
$usage = Media::usage((int) $media['id']);
check('usage shows the page', count($usage) === 1 && strpos($usage[0]['label'], 'Page') === 0);
check('PDF link tracked', count(Media::usage((int) $pdf['id'])) === 1);
check('card image tracked', count(Media::usage((int) $pngMedia['id'])) === 1);
$oldUrl = Media::url($media);
$replacement = imagecreatetruecolor(2000, 1000);
imagejpeg($replacement, $work . '/new.jpg', 80);
$replaced = Media::store($work . '/new.jpg', 'Nouvelle.jpg', 'test', (int) $media['id']);
check('replace keeps id and token', (int) $replaced['id'] === (int) $media['id'] && $replaced['token'] === $media['token']);
check('replace changes the URL version', Media::url($replaced) !== $oldUrl);
$html = Renderer::text((string) json_decode((string) Pages::find($top)['blocks'], true)[0]['html']);
check('page shows the new file', strpos($html, Media::url($replaced)) !== false && strpos($html, 'width="1600" height="800"') !== false, $html);
check('alt from editor kept', strpos($html, 'alt="Ma photo"') !== false);

// ---------------------------------------------------------------------------
echo "Rendering\n";
check('video loads only on click', strpos(Renderer::embed('https://youtu.be/dQw4w9WgXcQ'), '<iframe') === false);
check('cards with link', strpos(Renderer::cards([['title' => 'A', 'url' => '/x']]), '<a href="/x">A</a>') !== false);
check('auto description skips placeholders', Pages::autoDescription(['blocks' => json_encode([['type' => 'text', 'html' => '<p>[À COMPLÉTER : x]</p>']])]) === '');

// ---------------------------------------------------------------------------
echo "Settings\n";
check('no HelloAsso address is guessed', Settings::DEFAULT_HELLOASSO === '' && Settings::helloAssoMissing());
check('without HelloAsso address, buttons lead to "Nous aider"', Settings::donUrl() === '/nous-aider' && Settings::adhesionUrl() === '/nous-aider');
Settings::set('helloasso_url', 'https://www.helloasso.com/associations/exemple');
check('main HelloAsso page is the fallback', Settings::donUrl() === 'https://www.helloasso.com/associations/exemple' && !Settings::helloAssoMissing());
Settings::set('helloasso_url', '');
$values = Settings::all();
$values['helloasso_don_url'] = 'https://www.helloasso.com/associations/x/formulaires/1';
$values['contact_email'] = 'pas-un-email';
check('invalid e-mail refused', isset(Settings::saveForm($values)['contact_email']));
$values['contact_email'] = 'contact@example.org';
$values['logo_media_id'] = (string) $pngMedia['id'];
check('settings saved', Settings::saveForm($values) === []);
check('don URL used', Settings::donUrl() === 'https://www.helloasso.com/associations/x/formulaires/1');
check('logo usage tracked', in_array('Réglages du site', array_column(Media::usage((int) $pngMedia['id']), 'label'), true));
$values['helloasso_adhesion_url'] = 'javascript:alert(1)';
check('bad URL refused', isset(Settings::saveForm($values)['helloasso_adhesion_url']));

// ---------------------------------------------------------------------------
echo "Delete media\n";
Media::delete((int) $pngMedia['id']);
check('deleted media removed from settings', Settings::get('logo_media_id') === '');
check('deleted media files removed', !is_file(PUBLIC_PATH . '/media/' . $pngMedia['token'] . '-480.webp') && !is_file(PUBLIC_PATH . '/media/' . $pngMedia['token'] . '-480.jpg'));

// ---------------------------------------------------------------------------
echo "Backup\n";
$zipPath = Backup::create();
$zip = new ZipArchive();
check('backup zip opens', $zip->open($zipPath) === true);
$names = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $names[] = $zip->getNameIndex($i);
}
check('backup has database', in_array('data/site.sqlite', $names, true));
check('backup has originals and media', count(preg_grep('~^data/originals/~', $names)) >= 1 && count(preg_grep('~^media/~', $names)) >= 3);
check('backup has config template, no secrets', in_array('config.sample.php', $names, true) && !in_array('config.php', $names, true));
$restoreDir = $tmp . '/restore';
$zip->extractTo($restoreDir);
$zip->close();
$restoredDb = new PDO('sqlite:' . $restoreDir . '/data/site.sqlite');
check('restored database has the pages', (int) $restoredDb->query('SELECT COUNT(*) FROM pages')->fetchColumn() === (int) Db::value('SELECT COUNT(*) FROM pages'));
$restoredDb = null;
@unlink($zipPath);

// ---------------------------------------------------------------------------
echo "Private folder lookup (os/ and os-private/)\n";
$layout = $tmp . '/layout';
@mkdir($layout . '/os', 0777, true);
@mkdir($layout . '/os-private', 0777, true);
file_put_contents($layout . '/os-private/config.php', '<?php return [];');
$found = str_replace('\\', '/', Config::locate($layout . '/os'));
check('Config::locate finds os-private/config.php for the Git folder "os"', $found === str_replace('\\', '/', $layout) . '/os-private/config.php', $found);
file_put_contents($layout . '/os/config.php', '<?php return [];');
$found = str_replace('\\', '/', Config::locate($layout . '/os'));
check('a config.php next to app/ wins over os-private', $found === str_replace('\\', '/', $layout) . '/os/config.php', $found);
Config::locate($projectRoot); // back to the test setup

// ---------------------------------------------------------------------------
echo "No leftovers from the site this one was copied from\n";
// Patterns are cut in two so this file does not match itself.
$leftoverPatterns = [
    '~France\s+[ÉE]tat\s+de\s+Droit~iu' => 'the other association\'s name',
    '~[ÉE]tat de ' . 'Droit~u' => 'the other association\'s name',
    '~franceetat' . 'dedroit~i' => 'the other site\'s domain',
    '~fred' . '/~' => 'the other site\'s Git folder',
    '~fred' . '-private~' => 'the other site\'s private folder',
];
$trackedFiles = [];
$gitList = is_dir($projectRoot . '/.git') ? shell_exec('git -C ' . escapeshellarg($projectRoot) . ' ls-files 2>' . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null')) : null;
if (is_string($gitList) && trim($gitList) !== '') {
    foreach (preg_split('/\R/', trim($gitList)) ?: [] as $relative) {
        $trackedFiles[] = $relative;
    }
} else {
    // No Git yet: every file Git would track (same exclusions as .gitignore).
    $skip = '~^(\.git|tools|dist|source-content|review|data|build/seed|tests/tmp|editor-src/node_modules|screenshots-src/node_modules|server-private)(/|$)|^(config\.php|PRD-[^/]*\.md|HANDOFF\.md)$|^public/media/(?!\.htaccess$)~';
    $walk = function (string $dir) use (&$walk, &$trackedFiles, $projectRoot, $skip): void {
        foreach (scandir($projectRoot . ($dir === '' ? '' : '/' . $dir)) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $relative = ($dir === '' ? '' : $dir . '/') . $item;
            if (preg_match($skip, $relative)) {
                continue;
            }
            if (is_dir($projectRoot . '/' . $relative)) {
                $walk($relative);
            } else {
                $trackedFiles[] = $relative;
            }
        }
    };
    $walk('');
}
$leftovers = [];
foreach ($trackedFiles as $relative) {
    $text = (string) @file_get_contents($projectRoot . '/' . $relative);
    foreach ($leftoverPatterns as $pattern => $what) {
        if (preg_match($pattern, $text)) {
            $leftovers[] = $relative . ' (' . $what . ')';
        }
    }
}
check('tracked files list is not empty', count($trackedFiles) > 50, (string) count($trackedFiles));
check('no name, domain or folder of the other site in tracked files', $leftovers === [], implode(', ', $leftovers));

// ---------------------------------------------------------------------------
echo "Videos from the media library\n";
/** A tiny file with the structure of an MP4 (ftyp box, then data). */
function writeTinyMp4(string $file, int $dataBytes = 2000): void
{
    $data = str_repeat("\x01\x02\x03\x04", (int) ceil($dataBytes / 4));
    file_put_contents($file, pack('N', 24) . 'ftypisom' . pack('N', 512) . 'isommp41' . pack('N', 8 + strlen($data)) . 'mdat' . $data);
}
writeTinyMp4($work . '/clip.mp4');
$video = Media::store($work . '/clip.mp4', 'clip.mp4', 'test');
check('MP4 accepted as a video', $video['kind'] === 'video' && $video['web_ext'] === 'mp4');
check('video kept as sent, in the public media folder', is_file(PUBLIC_PATH . '/media/' . $video['token'] . '.mp4')
    && filesize(PUBLIC_PATH . '/media/' . $video['token'] . '.mp4') === filesize($work . '/clip.mp4'));
check('video address', strpos(Media::url($video), '/media/' . $video['token'] . '.mp4?v=') === 0);
file_put_contents($work . '/fake.mp4', str_repeat('x', 100));
check('fake MP4 refused', throwsUserError(function () use ($work) {
    Media::store($work . '/fake.mp4', 'fake.mp4', 'test');
}));
Config::override(['max_video_mb' => 0.0001]);
check('video over the limit refused', throwsUserError(function () use ($work) {
    Media::store($work . '/clip.mp4', 'clip.mp4', 'test');
}));
Config::override(['max_video_mb' => 50]);
check('video limit is its own setting', Config::maxBytes('video') === 50 * 1024 * 1024);
check('image cannot replace a video', throwsUserError(function () use ($work, $video) {
    Media::store($work . '/new.jpg', 'x.jpg', 'test', (int) $video['id']);
}));
$poster = Media::store($work . '/new.jpg', 'couverture.jpg', 'test');
list($vblocks, $verrors) = Pages::cleanBlocks((string) json_encode([
    ['type' => 'video', 'media_id' => $video['id'], 'poster_id' => $poster['id']],
    ['type' => 'video', 'media_id' => 0],
]));
check('video block kept, empty video block dropped', $verrors === [] && count($vblocks) === 1 && $vblocks[0] === ['type' => 'video', 'media_id' => (int) $video['id'], 'poster_id' => (int) $poster['id']], json_encode($vblocks));
list(, $verrors) = Pages::cleanBlocks((string) json_encode([['type' => 'video', 'media_id' => $poster['id']]]));
check('video block refuses an image', $verrors !== []);
check('video and cover counted as used', array_values(array_intersect([(int) $video['id'], (int) $poster['id']], Pages::mediaIds(['header_media_id' => null, 'blocks' => $vblocks]))) === [(int) $video['id'], (int) $poster['id']]);
Media::setAlt((int) $video['id'], 'Des élèves dans la cour');
$vhtml = Renderer::blocks($vblocks);
check('video shown with the browser player, nothing loaded before Play', strpos($vhtml, '<video controls playsinline preload="none" aria-label="Des élèves dans la cour" poster="/media/' . $poster['token']) !== false
    && strpos($vhtml, '<source src="/media/' . $video['token'] . '.mp4?v=') !== false && strpos($vhtml, 'type="video/mp4"') !== false, $vhtml);
check('video block falls back to a download link', strpos($vhtml, 'Télécharger la vidéo (MP4,') !== false);
Media::delete((int) $video['id']);
check('deleted video file removed', !is_file(PUBLIC_PATH . '/media/' . $video['token'] . '.mp4'));
// The renderer keeps media in memory for one page request: start a fresh one, as a new visit does.
$cacheProp = new ReflectionProperty('Renderer', 'mediaCache');
if (PHP_VERSION_ID < 80100) {
    $cacheProp->setAccessible(true); // needed before PHP 8.1, deprecated in 8.5
}
$cacheProp->setValue(null, []);
check('deleted video no longer shown', strpos(Renderer::blocks($vblocks), '<video') === false);

// ---------------------------------------------------------------------------
// Content import, key figures, markers, tables (synthetic fixtures only, never the real package).
require __DIR__ . '/import-tests.php';
restore_error_handler();

// ===========================================================================
echo "HTTP tests (built-in server, temporary copy of the site)\n";
$site = $tmp . '/site';
rcopy($projectRoot . '/app', $site . '/app');
rcopy($projectRoot . '/public', $site . '/www');
rrmdir($site . '/www/media');
copy($projectRoot . '/config.sample.php', $site . '/config.sample.php');
$port = 8100 + PHP_MINOR_VERSION + 10 * (PHP_MAJOR_VERSION - 7);
$writeConfig = function (array $values) use ($site, $port): void {
    $base = [
        'env' => 'development', 'base_url' => 'http://localhost:' . $port, 'force_https' => false,
        'admin_email' => 'admin@example.org', 'google_client_id' => 'test-client.apps.googleusercontent.com',
        'google_client_secret' => 'secret', 'dev_login' => true, 'session_idle_hours' => 8,
        'max_image_mb' => 10, 'max_pdf_mb' => 20,
    ];
    file_put_contents($site . '/config.php', '<?php return ' . var_export(array_merge($base, $values), true) . ';');
};
$writeConfig([]);
// opcache off so config.php changes made by the tests are seen at once.
$command = escapeshellarg(PHP_BINARY) . ' -d opcache.enable=0 -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($site . '/www') . ' ' . escapeshellarg($site . '/www/index.php');
$server = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $tmp . '/server.log', 'w'], 2 => ['file', $tmp . '/server.log', 'a']], $pipes, $site, null, ['bypass_shell' => true]);
usleep(800000);

$jar = $tmp . '/cookies.txt';
$request = function (string $method, string $path, array $data = [], bool $useJar = true, array $headers = []) use ($port, $jar): array {
    $ch = curl_init('http://localhost:' . $port . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_RESOLVE => ['localhost:' . $port . ':127.0.0.1'],
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($useJar) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
    }
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        $hasFile = false;
        foreach ($data as $value) {
            if ($value instanceof CURLFile) {
                $hasFile = true;
            }
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $hasFile ? $data : http_build_query($data));
    }
    $raw = (string) curl_exec($ch);
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return ['status' => $status, 'headers' => substr($raw, 0, $size), 'body' => (string) substr($raw, $size)];
};
$header = function (array $response, string $name): string {
    return preg_match('/^' . preg_quote($name, '/') . ':\s*(.+)$/mi', $response['headers'], $m) ? trim($m[1]) : '';
};
$csrfFrom = function (string $html): string {
    return preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $m) ? $m[1] : '';
};

try {
    $home = $request('GET', '/', [], false);
    check('home 200', $home['status'] === 200, (string) $home['status'] . ' ' . substr($home['body'], 0, 300));
    check('no cookie for visitors', stripos($home['headers'], 'Set-Cookie') === false);
    check('CSP header', strpos($header($home, 'Content-Security-Policy'), "frame-src https://www.youtube-nocookie.com https://player.vimeo.com") !== false);
    check('nosniff header', $header($home, 'X-Content-Type-Options') === 'nosniff');
    check('support buttons (no HelloAsso address yet: "Nous aider")', substr_count($home['body'], 'href="/nous-aider"') >= 3);
    check('one h1', substr_count($home['body'], '<h1') === 1);
    check('lang fr', strpos($home['body'], '<html lang="fr">') !== false);
    check('canonical and Open Graph', strpos($home['body'], 'rel="canonical"') !== false && strpos($home['body'], 'og:title') !== false && strpos($home['body'], 'twitter:card') !== false);
    check('no outside scripts, styles or fonts', preg_match('~<script[^>]+src="https?://|<link[^>]+rel="(stylesheet|preload|icon)"[^>]+href="https?://|<iframe~', $home['body']) === 0);
    $robots = $request('GET', '/robots.txt', [], false);
    check('robots.txt points to sitemap', $robots['status'] === 200 && strpos($robots['body'], 'Sitemap: http://localhost:' . $port . '/sitemap.xml') !== false);
    $sitemap = $request('GET', '/sitemap.xml', [], false);
    check('sitemap lists home', $sitemap['status'] === 200 && strpos($sitemap['body'], '<loc>http://localhost:' . $port . '/</loc>') !== false);
    $missing = $request('GET', '/cette-page-n-existe-pas', [], false);
    check('404 page in French', $missing['status'] === 404 && strpos($missing['body'], 'Page introuvable') !== false);
    check('home slug redirects to /', $request('GET', '/accueil', [], false)['status'] === 301);
    check('rewrite check route', $request('GET', '/__rewrite-check', [], false)['body'] === 'rewrite-ok');

    // Admin needs a session
    $r = $request('GET', '/admin/pages');
    check('admin redirects to login', $r['status'] === 302 && $header($r, 'Location') === '/admin/auth/login');
    check('admin cookie flags', stripos($r['headers'], 'HttpOnly') !== false && stripos($r['headers'], 'SameSite=Lax') !== false, $r['headers']);
    $login = $request('GET', '/admin/auth/login');
    check('login page', $login['status'] === 200 && strpos($login['body'], 'Se connecter avec Google') !== false);
    $google = $request('GET', '/admin/auth/google');
    $location = $header($google, 'Location');
    check('Google redirect with state and nonce', strpos($location, 'https://accounts.google.com/o/oauth2/v2/auth?') === 0
        && strpos($location, 'state=') !== false && strpos($location, 'nonce=') !== false
        && strpos($location, rawurlencode('http://localhost:' . $port . '/admin/auth/callback')) !== false, $location);
    $bad = $request('GET', '/admin/auth/callback?state=wrong&code=abc');
    check('callback with wrong state refused', $bad['status'] === 302 && $header($bad, 'Location') === '/admin/auth/login');
    $after = $request('GET', '/admin/auth/login');
    check('wrong state shows French message', strpos($after['body'], 'pas valide') !== false || strpos($after['body'], 'expiré') !== false);

    // CSRF
    $r = $request('POST', '/admin/auth/dev', ['email' => 'admin@example.org']);
    check('POST without CSRF refused', $r['status'] === 400);

    // Dev login as editor (not on list) → refused
    $token = $csrfFrom($request('GET', '/admin/auth/login')['body']);
    $r = $request('POST', '/admin/auth/dev', ['csrf' => $token, 'email' => 'nobody@gmail.com']);
    check('unlisted account refused', $header($r, 'Location') === '/admin/auth/login');

    // Admin login
    $token = $csrfFrom($request('GET', '/admin/auth/login')['body']);
    $r = $request('POST', '/admin/auth/dev', ['csrf' => $token, 'email' => 'admin@example.org']);
    check('admin login', $header($r, 'Location') === '/admin/pages');
    $pagesScreen = $request('GET', '/admin/pages');
    check('pages screen', $pagesScreen['status'] === 200 && strpos($pagesScreen['body'], 'Nouvelle page') !== false);
    check('admin noindex', strpos($header($pagesScreen, 'X-Robots-Tag'), 'noindex') !== false);
    $token = $csrfFrom($pagesScreen['body']);
    foreach (['/admin/reglages', '/admin/utilisateurs', '/admin/sauvegarde', '/admin/medias'] as $screen) {
        check('admin can open ' . $screen, $request('GET', $screen)['status'] === 200);
    }
    // Add an editor
    $request('POST', '/admin/utilisateurs', ['csrf' => $token, 'action' => 'add', 'email' => 'Editrice@Gmail.com', 'role' => 'editor']);
    check('editor added', strpos($request('GET', '/admin/utilisateurs')['body'], 'editrice@gmail.com') !== false);

    // Create a page, publish, change its address
    $r = $request('POST', '/admin/pages/nouvelle', ['csrf' => $token, 'title' => 'Nos actions', 'parent_id' => '']);
    $editUrl = $header($r, 'Location');
    check('page created', preg_match('~^/admin/pages/(\d+)$~', $editUrl, $pm) === 1, $editUrl);
    $pageId = (int) ($pm[1] ?? 0);
    check('hidden page is 404 for visitors', $request('GET', '/nos-actions', [], false)['status'] === 404);
    check('hidden page preview for editors', $request('GET', '/nos-actions')['status'] === 200);
    $editor = $request('GET', $editUrl);
    preg_match('/name="loaded_updated_at" value="([^"]+)"/', $editor['body'], $um);
    $blocks = json_encode([['type' => 'text', 'html' => '<h2>Titre</h2><p>Contenu</p>'], ['type' => 'embed', 'url' => 'https://vimeo.com/76979871']]);
    $r = $request('POST', $editUrl, [
        'csrf' => $token, 'title' => 'Nos actions', 'slug' => 'actions', 'status' => 'published', 'parent_id' => '',
        'show_in_menu' => '1', 'seo_title' => '', 'seo_description' => 'Ce que nous faisons.', 'header_media_id' => '',
        'blocks_json' => $blocks, 'loaded_updated_at' => $um[1] ?? '',
    ]);
    check('page saved', $r['status'] === 302 && $header($r, 'Location') === $editUrl, $r['status'] . ' ' . substr(strip_tags($r['body']), 0, 400));
    $public = $request('GET', '/actions', [], false);
    check('published page visible', $public['status'] === 200 && strpos($public['body'], 'Ce que nous faisons.') !== false);
    check('video script only with an embed', strpos($public['body'], 'js/site.js') !== false && strpos($home['body'], 'js/site.js') === false);
    $old = $request('GET', '/nos-actions', [], false);
    check('old address 301', $old['status'] === 301 && $header($old, 'Location') === '/actions');
    check('menu shows page', strpos($request('GET', '/', [], false)['body'], '<a href="/actions"') !== false);
    check('sitemap lists page', strpos($request('GET', '/sitemap.xml', [], false)['body'], '/actions</loc>') !== false);

    // Old website addresses (synthetic rows, as the content import writes them).
    $siteDb = new PDO('sqlite:' . $site . '/data/site.sqlite');
    $siteDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $actionsId = (int) $siteDb->query("SELECT id FROM pages WHERE slug = 'actions'")->fetchColumn();
    $addOld = $siteDb->prepare("INSERT INTO old_paths (old_path, page_id, target_url, note, created_at) VALUES (?, ?, ?, '', '2026-11-07T00:00:00Z')");
    foreach ([['/mafé', $actionsId, ''], ['/copie-de-mafé', $actionsId, ''], ['/_files/ugd/exemple.pdf', $actionsId, ''], ['/spip.php?article7', $actionsId, ''],
              ['/contact-8', null, '{{HELLOASSO_URL}}'], ['/externe', null, 'https://www.helloasso.com/associations/exemple'], ['/retiree', null, '']] as $row) {
        $addOld->execute($row);
    }
    $addOld = null;
    $r = $request('GET', '/maf%C3%A9', [], false);
    check('old accented address, encoded form: 301', $r['status'] === 301 && $header($r, 'Location') === '/actions', $r['status'] . ' ' . $header($r, 'Location'));
    $r = $request('GET', '/copie-de-maf%C3%A9', [], false);
    check('old accented address with hyphens: 301', $r['status'] === 301 && $header($r, 'Location') === '/actions');
    // (The raw UTF-8 form "/mafé" is tested in import-tests.php: PHP's built-in server drops such request lines.)
    check('old file path with slashes: 301', $request('GET', '/_files/ugd/exemple.pdf', [], false)['status'] === 301);
    $r = $request('GET', '/spip.php?article7', [], false);
    check('old path with a query string: 301', $r['status'] === 301 && $header($r, 'Location') === '/actions');
    check('other query string is not matched', $request('GET', '/spip.php?article8', [], false)['status'] === 404);
    $r = $request('GET', '/contact-8', [], false);
    check('/contact-8 without HelloAsso address goes to "Nous aider"', $r['status'] === 301 && $header($r, 'Location') === '/nous-aider');
    $siteDb->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('helloasso_url', 'https://www.helloasso.com/associations/exemple')");
    $r = $request('GET', '/contact-8', [], false);
    check('/contact-8 goes to the HelloAsso page (external 301)', $r['status'] === 301 && $header($r, 'Location') === 'https://www.helloasso.com/associations/exemple');
    $r = $request('GET', '/externe', [], false);
    check('external redirect target', $r['status'] === 301 && $header($r, 'Location') === 'https://www.helloasso.com/associations/exemple');
    $r = $request('GET', '/retiree', [], false);
    check('removed old page: friendly 404', $r['status'] === 404 && strpos($r['body'], 'Page introuvable') !== false);
    check('HelloAsso address used by the buttons once set', strpos($request('GET', '/', [], false)['body'], 'href="https://www.helloasso.com/associations/exemple"') !== false);

    // Key figures and tables on a public page, marker warning in the editor.
    $siteDb->exec("INSERT INTO key_figures (fig_key, label, value, unit, on_home, position) VALUES ('frais_annee', 'Frais', '186,60', '€', 1, 1)");
    $siteDb = null;
    $editor = $request('GET', $editUrl);
    preg_match('/name="loaded_updated_at" value="([^"]+)"/', $editor['body'], $um);
    check('editor: no marker warning on a clean page', strpos($editor['body'], 'marker-warning') === false);
    check('editor: key figure keys listed in the help', strpos($editor['body'], '{{fig:frais_annee}}') !== false);
    $blocks = json_encode([['type' => 'text', 'html' => '<p>Frais : {{fig:frais_annee}}. Inconnu : [{{fig:absente}}].</p>'
        . '<table><tbody><tr><td>Année</td><td>Montant</td></tr><tr><td>2025</td><td>10 €</td></tr></tbody></table>'
        . '<p>Date [À VÉRIFIER : date exacte] et photo [À COMPLÉTER : photo].</p>']]);
    $r = $request('POST', $editUrl, [
        'csrf' => $token, 'title' => 'Nos actions', 'slug' => 'actions', 'status' => 'published', 'parent_id' => '',
        'show_in_menu' => '1', 'seo_title' => '', 'seo_description' => 'Ce que nous faisons.', 'header_media_id' => '',
        'blocks_json' => $blocks, 'loaded_updated_at' => $um[1] ?? '',
    ]);
    check('page with markers can still be saved', $r['status'] === 302, (string) $r['status']);
    $editor = $request('GET', $editUrl);
    check('editor: marker warning above the save buttons, listing the markers', preg_match('~id="marker-warning".*\[À VÉRIFIER : date exacte\].*\[À COMPLÉTER : photo\].*</div>\s*<div class="button-row">~s', $editor['body']) === 1);
    $public = $request('GET', '/actions', [], false);
    check('public page shows the key figure', strpos($public['body'], "Frais : 186,60\u{00A0}€.") !== false);
    check('unknown key figure shows nothing', strpos($public['body'], 'Inconnu : [].') !== false);
    check('table rendered with a header row', strpos($public['body'], '<th scope="col">Année</th>') !== false && strpos($public['body'], 'class="table-wrap"') !== false);
    // Settings: key figures group
    $settingsPage = $request('GET', '/admin/reglages');
    check('settings: key figures group', strpos($settingsPage['body'], 'Chiffres clés') !== false && strpos($settingsPage['body'], 'value="frais_annee"') !== false);
    $settingsForm = [
        'csrf' => $token, 'site_name' => 'Objectif Solidarité', 'contact_email' => 'objectifsolidarite1@gmail.com',
        'helloasso_url' => 'https://www.helloasso.com/associations/exemple',
        'fig[0][key]' => 'frais_annee', 'fig[0][label]' => 'Frais', 'fig[0][value]' => '200', 'fig[0][unit]' => '€', 'fig[0][on_home]' => '1',
        'fig[1][key]' => 'Clé Invalide', 'fig[1][value]' => '1',
    ];
    $r = $request('POST', '/admin/reglages', $settingsForm);
    check('settings: bad figure key refused, nothing saved', $r['status'] === 200 && strpos($r['body'], 'invalide') !== false
        && strpos($request('GET', '/actions', [], false)['body'], "186,60\u{00A0}€") !== false);
    unset($settingsForm['fig[1][key]'], $settingsForm['fig[1][value]']);
    $settingsForm['fig[1][key]'] = 'membres_annee';
    $settingsForm['fig[1][value]'] = '67';
    $r = $request('POST', '/admin/reglages', $settingsForm);
    check('settings: figures saved', $r['status'] === 302 && strpos($request('GET', '/actions', [], false)['body'], "Frais : 200\u{00A0}€.") !== false);
    $contentLog = $site . '/data/logs/content.log';
    check('unknown key figure logged in content.log', is_file($contentLog) && strpos((string) file_get_contents($contentLog), '{{fig:absente}}') !== false);

    // Upload through the form (JSON, like the media picker)
    $upload = $request('POST', '/admin/medias?format=json', ['csrf' => $token, 'alt' => 'Une photo', 'file' => new CURLFile($work . '/new.jpg', 'image/jpeg', 'photo.jpg')], true, ['Accept: application/json']);
    $json = json_decode($upload['body'], true);
    check('upload by HTTP', $upload['status'] === 200 && isset($json['media']['id']), $upload['body']);
    if (isset($json['media']['thumb'])) {
        $img = $request('GET', (string) $json['media']['thumb'], [], false);
        check('web version served', $img['status'] === 200 && strlen($img['body']) > 100);
    }
    $wrong = $request('POST', '/admin/medias?format=json', ['csrf' => $token, 'file' => new CURLFile($work . '/shell.jpg', 'image/jpeg', 'shell.php')], true, ['Accept: application/json']);
    check('upload of wrong type refused', $wrong['status'] === 422);

    // Backup download
    $zipResponse = $request('POST', '/admin/sauvegarde', ['csrf' => $token]);
    check('backup download', $zipResponse['status'] === 200 && $header($zipResponse, 'Content-Type') === 'application/zip' && substr($zipResponse['body'], 0, 2) === 'PK');

    // Logout
    $request('POST', '/admin/auth/logout', ['csrf' => $token]);
    check('logout works', $request('GET', '/admin/pages')['status'] === 302);

    // Editor role: no settings, users, backup
    $token = $csrfFrom($request('GET', '/admin/auth/login')['body']);
    $request('POST', '/admin/auth/dev', ['csrf' => $token, 'email' => 'editrice@gmail.com']);
    check('editor can open pages', $request('GET', '/admin/pages')['status'] === 200);
    check('editor can open media', $request('GET', '/admin/medias')['status'] === 200);
    foreach (['/admin/reglages', '/admin/utilisateurs', '/admin/sauvegarde'] as $screen) {
        check('editor blocked from ' . $screen, $request('GET', $screen)['status'] === 403);
    }
    $editorPages = $request('GET', '/admin/pages');
    check('editor menu hides admin screens', strpos($editorPages['body'], '/admin/reglages') === false);
    $token = $csrfFrom($editorPages['body']);
    check('editor cannot POST backup', $request('POST', '/admin/sauvegarde', ['csrf' => $token])['status'] === 403);

    // Idle timeout: 2 seconds
    $writeConfig(['session_idle_hours' => 0.0005]);
    sleep(3);
    $r = $request('GET', '/admin/pages');
    check('session expires after inactivity', $r['status'] === 302 && $header($r, 'Location') === '/admin/auth/login');

    // Production over plain HTTP: login refused (sent to HTTPS)
    $writeConfig(['env' => 'production', 'force_https' => false, 'dev_login' => true]);
    $r = $request('GET', '/admin/auth/google', [], false);
    check('production admin over HTTP goes to HTTPS', $r['status'] === 301 && strpos($header($r, 'Location'), 'https://') === 0);
    check('public site still works over HTTP when force_https is off', $request('GET', '/', [], false)['status'] === 200);
    $r = $request('POST', '/admin/auth/dev', ['email' => 'admin@example.org'], false);
    check('dev login impossible in production', $r['status'] === 301 || $r['status'] === 400 || $r['status'] === 404);
    $writeConfig(['env' => 'production', 'force_https' => true]);
    $r = $request('GET', '/', [], false);
    check('force_https redirects visitors', $r['status'] === 301 && strpos($header($r, 'Location'), 'https://') === 0);

    // No config.php: friendly message
    unlink($site . '/config.php');
    $r = $request('GET', '/', [], false);
    check('missing config.php explained', $r['status'] === 503 && strpos($r['body'], 'config.php') !== false);
} finally {
    proc_terminate($server);
    proc_close($server);
}

$log = $site . '/data/logs/php-errors.log';
$logText = is_file($log) ? trim((string) file_get_contents($log)) : '';
check('no PHP errors, warnings or deprecations logged by the server', $logText === '', $logText);

// ===========================================================================
echo "HTTP tests, Git deployment layout (os/ = repository, os-private/ = config, data, media)\n";
$gitRoot = $tmp . '/git/os';
$private = $tmp . '/git/os-private';
rcopy($projectRoot . '/app', $gitRoot . '/app');
rcopy($projectRoot . '/public', $gitRoot . '/public');
rrmdir($gitRoot . '/public/media');
copy($projectRoot . '/.htaccess', $gitRoot . '/.htaccess');
@mkdir($private, 0777, true);
$gitPort = $port + 50;
file_put_contents($private . '/config.php', '<?php return ' . var_export([
    'env' => 'development', 'base_url' => 'http://localhost:' . $gitPort, 'force_https' => false,
    'admin_email' => 'admin@example.org', 'google_client_id' => 'x', 'google_client_secret' => 'y', 'dev_login' => true,
], true) . ';');
$gitServer = proc_open(
    escapeshellarg(PHP_BINARY) . ' -d opcache.enable=0 -S 127.0.0.1:' . $gitPort . ' -t ' . escapeshellarg($gitRoot . '/public') . ' ' . escapeshellarg($gitRoot . '/public/index.php'),
    [0 => ['pipe', 'r'], 1 => ['file', $tmp . '/git-server.log', 'w'], 2 => ['file', $tmp . '/git-server.log', 'a']],
    $gitPipes, $gitRoot, null, ['bypass_shell' => true]
);
usleep(800000);
$gitJar = $tmp . '/git-cookies.txt';
$gitRequest = function (string $method, string $path, array $data = [], array $headers = []) use ($gitPort, $gitJar): array {
    $ch = curl_init('http://localhost:' . $gitPort . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_RESOLVE => ['localhost:' . $gitPort . ':127.0.0.1'], CURLOPT_HTTPHEADER => $headers,
        CURLOPT_COOKIEJAR => $gitJar, CURLOPT_COOKIEFILE => $gitJar,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    }
    $raw = (string) curl_exec($ch);
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    return ['status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'headers' => substr($raw, 0, $size), 'body' => (string) substr($raw, $size)];
};
try {
    check('git layout: home 200', $gitRequest('GET', '/')['status'] === 200);
    check('git layout: database in os-private/data', is_file($private . '/data/site.sqlite') && !is_dir($gitRoot . '/data'));
    $loginPage = $gitRequest('GET', '/admin/auth/login');
    $gitToken = $csrfFrom($loginPage['body']);
    $gitRequest('POST', '/admin/auth/dev', ['csrf' => $gitToken, 'email' => 'admin@example.org']);
    $gitToken = $csrfFrom($gitRequest('GET', '/admin/pages')['body']);
    $up = $gitRequest('POST', '/admin/medias?format=json', ['csrf' => $gitToken, 'alt' => '', 'file' => new CURLFile($work . '/new.jpg', 'image/jpeg', 'p.jpg')], ['Accept: application/json']);
    $upJson = json_decode($up['body'], true);
    check('git layout: upload works', isset($upJson['media']['thumb']), $up['body']);
    $mediaFiles = array_diff(scandir($private . '/media') ?: [], ['.', '..', '.htaccess']);
    check('git layout: files stored in os-private/media', count($mediaFiles) >= 2 && !is_dir($gitRoot . '/public/media'));
    if (isset($upJson['media']['thumb'])) {
        $img = $gitRequest('GET', (string) $upJson['media']['thumb']);
        check('git layout: image served by the site', $img['status'] === 200 && strlen($img['body']) > 100
            && stripos($img['headers'], 'Content-Type: image/') !== false && stripos($img['headers'], 'max-age=31536000') !== false
            && stripos($img['headers'], 'Set-Cookie') === false);
        $lm = preg_match('/^Last-Modified:\s*(.+)$/mi', $img['headers'], $lmm) ? trim($lmm[1]) : '';
        check('git layout: unchanged image answers 304', $gitRequest('GET', (string) $upJson['media']['thumb'], [], ['If-Modified-Since: ' . $lm])['status'] === 304);
    }
    // A video uploaded like any file, then read in parts (byte ranges), as browsers and iPhones do.
    writeTinyMp4($work . '/range.mp4', 5000);
    $vSize = filesize($work . '/range.mp4');
    $vUp = json_decode($gitRequest('POST', '/admin/medias?format=json', ['csrf' => $gitToken, 'alt' => 'Une vidéo', 'file' => new CURLFile($work . '/range.mp4', 'video/mp4', 'clip.mp4')], ['Accept: application/json'])['body'], true);
    check('git layout: video uploaded by HTTP', ($vUp['media']['kind'] ?? '') === 'video', json_encode($vUp));
    $vUrl = (string) preg_replace('/\?v=\d+$/', '', (string) ($vUp['media']['url'] ?? ''));
    $full = $gitRequest('GET', $vUrl);
    check('git layout: video served whole, ranges announced', $full['status'] === 200 && strlen($full['body']) === $vSize
        && stripos($full['headers'], 'Content-Type: video/mp4') !== false && stripos($full['headers'], 'Accept-Ranges: bytes') !== false);
    $part = $gitRequest('GET', $vUrl, [], ['Range: bytes=0-99']);
    check('git layout: first 100 bytes (206)', $part['status'] === 206 && strlen($part['body']) === 100
        && stripos($part['headers'], 'Content-Range: bytes 0-99/' . $vSize) !== false && substr($part['body'], 4, 4) === 'ftyp', $part['headers']);
    $tail = $gitRequest('GET', $vUrl, [], ['Range: bytes=-10']);
    check('git layout: last 10 bytes (206)', $tail['status'] === 206 && strlen($tail['body']) === 10
        && stripos($tail['headers'], 'Content-Range: bytes ' . ($vSize - 10) . '-' . ($vSize - 1) . '/' . $vSize) !== false);
    $open = $gitRequest('GET', $vUrl, [], ['Range: bytes=100-']);
    check('git layout: from byte 100 to the end (206)', $open['status'] === 206 && strlen($open['body']) === $vSize - 100);
    check('git layout: impossible range refused (416)', $gitRequest('GET', $vUrl, [], ['Range: bytes=' . ($vSize + 10) . '-'])['status'] === 416);
    check('git layout: bad media name is 404', $gitRequest('GET', '/media/../config.php')['status'] === 404 && $gitRequest('GET', '/media/abc.php')['status'] === 404);
    $zipResponse = $gitRequest('POST', '/admin/sauvegarde', ['csrf' => $gitToken]);
    check('git layout: backup includes media', $zipResponse['status'] === 200 && strpos($zipResponse['body'], 'media/') !== false);
} finally {
    proc_terminate($gitServer);
    proc_close($gitServer);
}
$gitLog = $private . '/data/logs/php-errors.log';
$gitLogText = is_file($gitLog) ? trim((string) file_get_contents($gitLog)) : '';
check('git layout: no PHP errors logged', $gitLogText === '', $gitLogText);

echo "\n" . $passed . ' passed, ' . count($failed) . " failed (PHP " . PHP_VERSION . ")\n";
exit($failed ? 1 : 0);
