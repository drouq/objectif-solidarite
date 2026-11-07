<?php
/*
 * Environment check. Open https://your-site/check.php once after installing.
 * Then delete this file, or set 'check_enabled' => false in config.php.
 * Standalone on purpose: it works even if the rest of the site does not.
 */

$root = null;
foreach ([dirname(__DIR__), __DIR__] as $candidate) {
    if (is_file($candidate . '/app/bootstrap.php')) {
        $root = $candidate;
        break;
    }
}
$config = [];
$configFile = null;
if ($root !== null) {
    // Same search as the site: next to app/, or in the sibling "<folder>-private" (Git deployment).
    $configFile = $root . '/config.php';
    $privateConfig = dirname($root) . '/' . basename($root) . '-private/config.php';
    if (!is_file($configFile) && is_file($privateConfig)) {
        $configFile = $privateConfig;
    }
}
$configDir = $configFile !== null ? dirname($configFile) : null;
$privateLayout = $configDir !== null && $root !== null && realpath($configDir) !== realpath($root);
if ($configFile !== null && is_file($configFile)) {
    $loaded = include $configFile;
    $config = is_array($loaded) ? $loaded : [];
    if (array_key_exists('check_enabled', $config) && $config['check_enabled'] === false) {
        http_response_code(404);
        exit('Not found');
    }
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$rows = [];
$stop = false;
function row(string $group, string $label, string $status, string $detail): void
{
    global $rows;
    $rows[] = [$group, $label, $status, $detail];
}
function iniBytes(string $value): int
{
    $value = trim($value);
    if ($value === '' || $value === '-1') {
        return PHP_INT_MAX;
    }
    $unit = strtolower(substr($value, -1));
    $n = (float) $value;
    if ($unit === 'g') {
        $n *= 1073741824;
    } elseif ($unit === 'm') {
        $n *= 1048576;
    } elseif ($unit === 'k') {
        $n *= 1024;
    }
    return (int) $n;
}
function probe(string $url): array
{
    // PHP's built-in server answers one request at a time: calling itself would hang.
    if (PHP_SAPI === 'cli-server' && strpos($url, 'googleapis.com') === false) {
        return [0, 'test impossible avec le serveur de développement'];
    }
    if (!function_exists('curl_init')) {
        return [0, 'cURL absent'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'site-check/1.0',
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = $body === false ? curl_error($ch) : '';
    return [$code, $error !== '' ? $error : (string) $body];
}

$https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443'
    || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
$host = preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', (string) ($_SERVER['HTTP_HOST'] ?? '')) ? $_SERVER['HTTP_HOST'] : 'localhost';
$self = ($https ? 'https://' : 'http://') . $host;

// ---------- PHP ----------
$v = PHP_VERSION;
if (version_compare($v, '7.3.0', '<')) {
    row('PHP', 'Version', 'ERREUR', $v . ' : il faut PHP 7.3 ou plus récent.');
    $stop = true;
} elseif (version_compare($v, '8.1.0', '<')) {
    row('PHP', 'Version', 'ATTENTION', $v . ' fonctionne, mais ne reçoit plus de correctifs de sécurité. Passez à PHP 8.x dès que possible (le site est prêt).');
} else {
    row('PHP', 'Version', 'OK', $v);
}
$extensions = [
    'pdo_sqlite' => ['Base de données SQLite', true],
    'gd' => ['Redimensionnement des images (GD)', true],
    'curl' => ['Connexion Google (cURL)', true],
    'openssl' => ['Vérification Google (OpenSSL)', true],
    'zip' => ['Sauvegarde (ZipArchive)', true],
    'fileinfo' => ['Vérification du type des fichiers', true],
    'mbstring' => ['Textes accentués (mbstring)', true],
    'dom' => ['Nettoyage du texte riche (DOM)', true],
    'session' => ['Sessions', true],
    'json' => ['JSON', true],
    'exif' => ['Rotation des photos de téléphone (EXIF)', false],
];
foreach ($extensions as $ext => $info) {
    $ok = extension_loaded($ext);
    if (!$ok && $info[1]) {
        $stop = $stop || $ext === 'pdo_sqlite';
    }
    $detail = $ok ? 'présente' : ($info[1] ? 'ABSENTE' : 'absente (facultative)');
    if ($ext === 'pdo_sqlite' && !$ok) {
        $detail = 'ABSENTE. Arrêt : SQLite est indispensable. Signalez-le : il faudra passer à la base MySQL d\'OVH (petite adaptation du code).';
    }
    if ($ext === 'pdo_sqlite' && $ok && class_exists('SQLite3')) {
        $detail .= ' (SQLite ' . SQLite3::version()['versionString'] . ')';
    }
    if ($ext === 'gd' && $ok) {
        $gd = gd_info();
        $detail .= ' — JPEG ' . (!empty($gd['JPEG Support']) ? 'oui' : 'NON') . ', PNG ' . (!empty($gd['PNG Support']) ? 'oui' : 'NON')
            . ', WebP ' . (!empty($gd['WebP Support']) ? 'oui' : 'non (les images seront en JPEG)');
    }
    row('PHP', $info[0], $ok ? 'OK' : ($info[1] ? 'ERREUR' : 'ATTENTION'), $detail);
}

// ---------- Limits ----------
$maxImage = (float) ($config['max_image_mb'] ?? 10);
$maxPdf = (float) ($config['max_pdf_mb'] ?? 20);
$needed = (int) (max($maxImage, $maxPdf) * 1048576);
$upload = iniBytes((string) ini_get('upload_max_filesize'));
$post = iniBytes((string) ini_get('post_max_size'));
row('Limites', 'Taille maximale d\'un envoi (upload_max_filesize)', $upload >= $needed ? 'OK' : 'ATTENTION',
    ini_get('upload_max_filesize') . ($upload >= $needed ? '' : ' : inférieure à la limite du site (' . max($maxImage, $maxPdf) . ' Mo). Augmentez-la dans la configuration PHP d\'OVH (fichier .ovhconfig ou espace client), ou baissez max_pdf_mb dans config.php.'));
row('Limites', 'Taille maximale d\'un formulaire (post_max_size)', $post >= $needed ? 'OK' : 'ATTENTION', (string) ini_get('post_max_size'));
$memory = iniBytes((string) ini_get('memory_limit'));
row('Limites', 'Mémoire (memory_limit)', $memory >= 256 * 1048576 ? 'OK' : 'ATTENTION',
    ini_get('memory_limit') . ($memory >= 256 * 1048576 ? '' : ' : les très grandes photos (plus de 20 mégapixels) pourraient échouer. 256M ou plus est conseillé.'));
row('Limites', 'Durée maximale d\'un script', 'OK', ini_get('max_execution_time') . ' s');

// ---------- Folders ----------
if ($root === null) {
    row('Fichiers', 'Dossier « app »', 'ERREUR', 'Introuvable à côté du dossier web ni dedans.');
    $stop = true;
} else {
    row('Fichiers', 'Dossier « app »', 'OK', $root === __DIR__ ? 'dans le dossier web (protégé par .htaccess)' : 'à côté du dossier web (recommandé)');
    row('Fichiers', 'config.php', is_file((string) $configFile) ? 'OK' : 'ERREUR', is_file((string) $configFile) ? 'présent : ' . $configFile . ($privateLayout ? ' (dossier privé, hors Git)' : '') : 'ABSENT : copiez config.sample.php en config.php et remplissez-le.');
    $dataPath = !empty($config['data_path']) ? rtrim((string) $config['data_path'], '/\\') : $configDir . '/data';
    foreach (['' => 'Dossier « data »', '/originals' => 'data/originals', '/sessions' => 'data/sessions', '/logs' => 'data/logs'] as $sub => $label) {
        $dir = $dataPath . $sub;
        if (!is_dir($dir)) {
            if ($config) {
                @mkdir($dir, 0755, true); // only once we know where config.php is
            }
        }
        $ok = is_dir($dir) && is_writable($dir);
        row('Fichiers', $label . ' accessible en écriture', $ok ? 'OK' : 'ERREUR', $ok ? $dir : 'Impossible d\'écrire dans ' . $dir . '. Vérifiez les droits du dossier (755) dans FileZilla.');
    }
    $media = !empty($config['media_path']) ? rtrim((string) $config['media_path'], '/\\') : ($privateLayout ? $configDir . '/media' : __DIR__ . '/media');
    if (!is_dir($media) && $config) {
        @mkdir($media, 0755, true);
    }
    row('Fichiers', 'Dossier « media » accessible en écriture', is_writable($media) ? 'OK' : 'ERREUR', $media);
    if ($privateLayout) {
        row('Fichiers', 'Emplacement des fichiers envoyés', 'OK', 'hors du dossier Git : une mise à jour ne peut pas les effacer');
    } else {
        row('Fichiers', 'Protection du dossier media', is_file($media . '/.htaccess') ? 'OK' : 'ATTENTION', is_file($media . '/.htaccess') ? 'media/.htaccess présent' : 'media/.htaccess manquant : il sera recréé au premier chargement du site.');
    }
}

// ---------- Settings ----------
if ($config) {
    $env = (string) ($config['env'] ?? 'production');
    row('Réglages', 'Mode', $env === 'production' ? 'OK' : 'ATTENTION', $env === 'production' ? 'production' : $env . ' : mettez \'env\' => \'production\' sur le serveur.');
    row('Réglages', 'Administrateur principal', !empty($config['admin_email']) ? 'OK' : 'ERREUR', !empty($config['admin_email']) ? (string) $config['admin_email'] : 'admin_email est vide.');
    $google = !empty($config['google_client_id']) && !empty($config['google_client_secret']);
    row('Réglages', 'Identifiants Google', $google ? 'OK' : 'ERREUR', $google ? 'renseignés' : 'google_client_id ou google_client_secret vide.');
    row('Réglages', 'Connexion de test sans Google', empty($config['dev_login']) ? 'OK' : 'ATTENTION', empty($config['dev_login']) ? 'désactivée' : 'dev_login est à true : mettez false (elle ne marche de toute façon qu\'en local).');
    $base = rtrim((string) ($config['base_url'] ?? ''), '/');
    $baseOk = $base !== '' && strcasecmp((string) parse_url($base, PHP_URL_HOST), preg_replace('/:\d+$/', '', $host)) === 0;
    row('Réglages', 'Adresse du site (base_url)', $baseOk ? 'OK' : 'ATTENTION', $base . ($baseOk ? '' : ' : ne correspond pas à l\'adresse ouverte (' . $host . '). Les liens Google et le sitemap utilisent base_url.'));
}

// ---------- Web server ----------
row('Serveur web', 'HTTPS', $https ? 'OK' : 'ATTENTION', $https ? 'cette page est ouverte en HTTPS' : 'cette page est ouverte en HTTP. Activez le certificat SSL gratuit dans OVH, puis ouvrez https://' . $host . '/check.php');
list($code, $body) = probe($self . '/__rewrite-check');
row('Serveur web', 'Réécriture d\'adresses (mod_rewrite)', $body === 'rewrite-ok' ? 'OK' : 'ERREUR', $body === 'rewrite-ok' ? 'active' : 'pas de réponse attendue (code ' . $code . '). Le fichier .htaccess a-t-il été envoyé ? (FileZilla : afficher les fichiers cachés)');
$private = ['/app/bootstrap.php' => 'Dossier app', '/data/site.sqlite' => 'Base de données', '/config.php' => 'config.php', '/config.sample.php' => 'config.sample.php', '/README.md' => 'README.md', '/tests/run.php' => 'Dossier tests'];
foreach ($private as $path => $label) {
    list($code) = probe($self . $path);
    $blocked = $code === 403 || $code === 404;
    row('Serveur web', $label . ' inaccessible depuis le web', $blocked ? 'OK' : ($code === 0 ? 'ATTENTION' : 'ERREUR'),
        $code === 0 ? 'test impossible depuis le serveur' : 'réponse ' . $code . ($blocked ? '' : ' : CE FICHIER EST VISIBLE. Vérifiez les fichiers .htaccess.'));
}

// ---------- Google ----------
list($code, $body) = probe('https://www.googleapis.com/oauth2/v1/certs');
row('Google', 'Connexion HTTPS vers Google (certificat vérifié)', $code === 200 ? 'OK' : 'ERREUR', $code === 200 ? 'fonctionne' : 'échec : ' . substr($body, 0, 200));

$counts = ['OK' => 0, 'ATTENTION' => 0, 'ERREUR' => 0];
foreach ($rows as $r) {
    $counts[$r[2]]++;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Vérification de l'installation</title>
<style>
body { font: 16px/1.5 system-ui, sans-serif; color: #14183a; max-width: 62rem; margin: 2rem auto; padding: 0 1rem; }
table { border-collapse: collapse; width: 100%; }
th, td { text-align: left; padding: .5rem .6rem; border-bottom: 1px solid #d6dce8; vertical-align: top; }
th { background: #f3f5f9; }
.s { font-weight: 700; white-space: nowrap; }
.OK { color: #0f6b3a; } .ATTENTION { color: #8a4b00; } .ERREUR { color: #a3161a; }
.box { padding: 1rem; border-radius: 6px; margin: 1rem 0; border-left: 5px solid; }
.good { background: #e5f4ea; border-color: #0f6b3a; } .bad { background: #fbe9e9; border-color: #a3161a; }
</style>
</head>
<body>
<h1>Vérification de l'installation</h1>
<?php if ($stop): ?>
  <div class="box bad"><strong>Arrêt :</strong> un élément indispensable manque (voir les lignes ERREUR). Le site ne peut pas fonctionner ainsi.</div>
<?php elseif ($counts['ERREUR'] > 0): ?>
  <div class="box bad"><?= $counts['ERREUR'] ?> point(s) à corriger avant la mise en ligne.</div>
<?php else: ?>
  <div class="box good">Tout est prêt<?= $counts['ATTENTION'] ? ' (' . $counts['ATTENTION'] . ' remarque(s) à lire)' : '' ?>. <strong>Supprimez maintenant ce fichier check.php</strong>, ou mettez <code>'check_enabled' => false</code> dans config.php.</div>
<?php endif; ?>
<table>
  <thead><tr><th scope="col">Domaine</th><th scope="col">Vérification</th><th scope="col">Résultat</th><th scope="col">Détail</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td><?= htmlspecialchars($r[0]) ?></td><td><?= htmlspecialchars($r[1]) ?></td><td class="s <?= $r[2] ?>"><?= $r[2] ?></td><td><?= htmlspecialchars($r[3]) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>
<p>Vérifié le <?= date('d/m/Y H:i') ?> (heure du serveur).</p>
</body>
</html>
