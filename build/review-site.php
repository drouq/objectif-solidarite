<?php
/*
 * Creates a throw-away copy of the site with EXAMPLE content, for screenshots and checks.
 *   tools/php85/php.exe build/review-site.php
 *   tools/php85/php.exe -S localhost:8200 -t tests/tmp/review-site/www tests/tmp/review-site/www/index.php
 * Your real local data (data/) is not touched. All example pages say "exemple".
 */
declare(strict_types=1);

$project = dirname(__DIR__);
$site = $project . '/tests/tmp/review-site';

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $item) {
        if ($item !== '.' && $item !== '..') {
            $path = $dir . '/' . $item;
            is_dir($path) ? rrmdir($path) : unlink($path);
        }
    }
    rmdir($dir);
}
function rcopy(string $from, string $to): void
{
    @mkdir($to, 0777, true);
    foreach (scandir($from) ?: [] as $item) {
        if ($item !== '.' && $item !== '..') {
            is_dir($from . '/' . $item) ? rcopy($from . '/' . $item, $to . '/' . $item) : copy($from . '/' . $item, $to . '/' . $item);
        }
    }
}

rrmdir($site);
rcopy($project . '/app', $site . '/app');
rcopy($project . '/public', $site . '/www');
rrmdir($site . '/www/media');
copy($project . '/config.sample.php', $site . '/config.sample.php');
file_put_contents($site . '/config.php', '<?php return ' . var_export([
    'env' => 'development', 'base_url' => 'http://localhost:8200', 'force_https' => false,
    'admin_email' => 'admin@exemple.fr', 'google_client_id' => 'exemple.apps.googleusercontent.com',
    'google_client_secret' => 'exemple', 'dev_login' => true,
], true) . ';');

define('ROOT_PATH', $site);
define('PUBLIC_PATH', $site . '/www');
require $site . '/app/bootstrap.php';
Migrations::run();

/** A soft abstract picture in the site's blues, to stand in for real photos. */
function examplePicture(string $file, int $w, int $h, int $seed): void
{
    mt_srand($seed);
    $im = imagecreatetruecolor($w, $h);
    for ($y = 0; $y < $h; $y++) {
        $t = $y / $h;
        $c = imagecolorallocate($im, (int) (11 + 40 * $t), (int) (58 + 90 * $t), (int) (143 + 80 * $t));
        imageline($im, 0, $y, $w, $y, $c);
    }
    for ($i = 0; $i < 6; $i++) {
        $c = imagecolorallocatealpha($im, 255, 255, 255, mt_rand(95, 115));
        $r = mt_rand((int) ($h / 4), $h);
        imagefilledellipse($im, mt_rand(0, $w), mt_rand(0, $h), $r * 2, $r * 2, $c);
    }
    imagejpeg($im, $file, 88);
}

$tmp = $site . '/tmp-files';
@mkdir($tmp, 0777, true);
$images = [];
foreach ([[2400, 1350, 1, 'Image d\'exemple 1'], [1600, 1000, 2, 'Image d\'exemple 2'], [1600, 1000, 3, 'Image d\'exemple 3'], [1600, 1000, 4, 'Image d\'exemple 4']] as $spec) {
    $file = $tmp . '/exemple-' . $spec[2] . '.jpg';
    examplePicture($file, $spec[0], $spec[1], $spec[2]);
    $media = Media::store($file, 'exemple-' . $spec[2] . '.jpg', 'admin@exemple.fr');
    Media::setAlt((int) $media['id'], $spec[3]);
    $images[] = Media::find((int) $media['id']);
}
file_put_contents($tmp . '/doc.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
$pdf = Media::store($tmp . '/doc.pdf', 'Document exemple.pdf', 'admin@exemple.fr');

$user = 'admin@exemple.fr';
$save = function (int $id, array $fields, array $blocks) use ($user): void {
    $page = Pages::find($id);
    $input = array_merge([
        'title' => $page['title'], 'slug' => $page['slug'], 'status' => 'published', 'parent_id' => (string) ($page['parent_id'] ?? ''),
        'show_in_menu' => (string) $page['show_in_menu'], 'show_in_footer' => '0', 'seo_title' => '', 'seo_description' => '',
        'header_media_id' => '', 'loaded_updated_at' => $page['updated_at'],
    ], $fields);
    $input['blocks_json'] = json_encode($blocks);
    $errors = Pages::save($id, $input, $user);
    if ($errors) {
        throw new RuntimeException(json_encode($errors));
    }
};
$lorem = 'Texte d\'exemple pour vérifier la mise en page. Il sera remplacé par le vrai contenu de l\'association. '
    . 'Une ligne de texte confortable fait entre soixante et quatre-vingts caractères, pour une lecture facile.';

$actions = Pages::create('Nos actions (exemple)', null, $user);
$a1 = Pages::create('Première action (exemple)', $actions, $user);
$a2 = Pages::create('Deuxième action (exemple)', $actions, $user);
$a3 = Pages::create('Troisième action (exemple)', $actions, $user);
$detail = Pages::create('Détail d\'une action (exemple)', $a1, $user);
$docs = Pages::create('Documents (exemple)', null, $user);
$draft = Pages::create('Brouillon (exemple)', null, $user);
$legal = Pages::create('Mentions légales (exemple)', null, $user);

$save($actions, ['slug' => 'nos-actions', 'seo_description' => 'Page d\'exemple : une rubrique avec ses sous-pages.'], [
    ['type' => 'text', 'html' => '<p>' . $lorem . '</p>'],
    ['type' => 'cards', 'cards' => [
        ['media_id' => $images[1]['id'], 'title' => 'Carte avec lien', 'text' => 'Une carte peut mener vers une page.', 'url' => '/premiere-action-exemple'],
        ['media_id' => $images[2]['id'], 'title' => 'Carte sans lien', 'text' => 'Le lien est facultatif.', 'url' => ''],
        ['media_id' => $images[3]['id'], 'title' => 'Troisième carte', 'text' => '', 'url' => '/deuxieme-action-exemple'],
    ]],
]);
$save($a1, ['header_media_id' => (string) $images[0]['id'], 'show_in_menu' => '0', 'seo_description' => 'Exemple de page de niveau 2 avec image d\'en-tête.'], [
    ['type' => 'text', 'html' => '<h2>Un titre de section</h2><p>' . $lorem . '</p><ul><li>Premier point de la liste</li><li>Deuxième point, un peu plus long pour voir le retour à la ligne</li></ul>'
        . '<blockquote><p>Une citation mise en valeur, pour un texte important.</p></blockquote>'
        . '<h3>Un sous-titre</h3><p>' . $lorem . ' <a href="/documents-exemple">Un lien vers une autre page</a>.</p>'],
    ['type' => 'embed', 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
]);
$save($a2, ['show_in_menu' => '0', 'header_media_id' => (string) $images[2]['id']], [['type' => 'text', 'html' => '<p>' . $lorem . '</p>']]);
$save($a3, ['show_in_menu' => '0', 'header_media_id' => (string) $images[3]['id']], [['type' => 'text', 'html' => '<p>' . $lorem . '</p>']]);
$save($detail, ['show_in_menu' => '0'], [['type' => 'text', 'html' => '<p>Page de niveau 3 : le fil d\'Ariane montre le chemin depuis l\'accueil.</p><p>' . $lorem . '</p>']]);
$save($docs, [], [['type' => 'text', 'html' => '<p>' . $lorem . '</p><p><a href="/media/' . $pdf['token'] . '.pdf">Télécharger le document d\'exemple (PDF)</a></p>'
    . '<p><a class="btn" href="https://www.helloasso.com/associations/exemple">Un lien en forme de bouton</a></p>'
    . '<p><img src="' . Media::url($images[1]) . '" data-media-id="' . $images[1]['id'] . '" alt="Image d\'exemple dans le texte"></p>']]);
$save($draft, ['status' => 'hidden'], [['type' => 'text', 'html' => '<p>Cette page est masquée.</p>']]);
$save($legal, ['show_in_menu' => '0', 'show_in_footer' => '1'], [['type' => 'text', 'html' => '<p>Exemple de page affichée dans le pied de page.</p>']]);

// A few versions for the history screen.
for ($i = 1; $i <= 3; $i++) {
    $save($actions, ['slug' => 'nos-actions', 'seo_description' => 'Page d\'exemple, version ' . $i . '.'], json_decode((string) Pages::find($actions)['blocks'], true));
}
Users::allowed('admin@exemple.fr');
Users::add('editeur.exemple@gmail.com', 'editor');
Settings::set('contact_email', 'contact@exemple.fr');
Settings::set('footer_text', '[Exemple] Association loi 1901.');

rrmdir($tmp);
echo "Review site ready in tests/tmp/review-site (example content).\n";
