<?php
/*
 * Tests for the content import (build/import-content.php), key figures, markers and tables.
 * Included by tests/run.php. Every fixture here is SYNTHETIC: the real content package
 * (source-content/) is never read by the tests.
 */
declare(strict_types=1);

require_once $projectRoot . '/build/lib/Markdown.php';
require_once $projectRoot . '/build/lib/ContentImport.php';

// ---------------------------------------------------------------------------
echo "Markdown conversion\n";
$md = new Markdown();
check('md heading levels', $md->toHtml("# Un\n\n## Deux\n\n### Trois\n\n#### Quatre") === '<h2>Un</h2><h2>Deux</h2><h3>Trois</h3><h3>Quatre</h3>');
check('md paragraph joins lines', $md->toHtml("Ligne une\nligne deux") === '<p>Ligne une ligne deux</p>');
check('md bold and italic', $md->toHtml('**gras** et *italique* et _aussi_') === '<p><strong>gras</strong> et <em>italique</em> et <em>aussi</em></p>');
check('md underscores inside words stay', $md->toHtml('fichier_de_test et l_eau') === '<p>fichier_de_test et l_eau</p>');
check('md link', $md->toHtml('[Voir](/projets)') === '<p><a href="/projets">Voir</a></p>');
check('md HelloAsso placeholder link becomes a button', $md->toHtml('[Je donne]({{HELLOASSO_DON_URL}})') === '<p><a href="{{HELLOASSO_DON_URL}}" class="btn">Je donne</a></p>');
check('md raw HTML is escaped', strpos($md->toHtml('<script>alert(1)</script> & <b>x</b>'), '<script>') === false);
check('md bullet list', $md->toHtml("- un\n- **deux**\n- trois") === '<ul><li>un</li><li><strong>deux</strong></li><li>trois</li></ul>');
check('md ordered list', $md->toHtml("1. un\n2. deux") === '<ol><li>un</li><li>deux</li></ol>');
check('md nested list', $md->toHtml("- un\n  - un.un\n- deux") === '<ul><li>un<ul><li>un.un</li></ul></li><li>deux</li></ul>', $md->toHtml("- un\n  - un.un\n- deux"));
check('md quote', $md->toHtml("> Première phrase.\n>\n> Seconde.") === '<blockquote><p>Première phrase.</p><p>Seconde.</p></blockquote>');
$table = $md->toHtml("| Année | Montant |\n|---|---|\n| 2024 | 1 000 € |\n| 2025 | **2 000 €** |");
check('md table', $table === '<table><thead><tr><th>Année</th><th>Montant</th></tr></thead><tbody><tr><td>2024</td><td>1 000 €</td></tr><tr><td>2025</td><td><strong>2 000 €</strong></td></tr></tbody></table>', $table);
check('md comments removed', $md->toHtml("<!-- note -->\nTexte") === '<p>Texte</p>');

// ---------------------------------------------------------------------------
echo "Tables, markers, HelloAsso and key figures in texts\n";
$cleanTable = Sanitizer::html($table . '<table><tr><td>a</td><td onclick="x">b</td></tr></table><td>perdu</td>');
check('sanitiser keeps tables', strpos($cleanTable, '<table><thead><tr><th>Année</th>') !== false && strpos($cleanTable, 'onclick') === false, $cleanTable);
check('sanitiser drops table parts out of place', strpos($cleanTable, '<td>perdu') === false && strpos($cleanTable, 'perdu') !== false, $cleanTable);
$rendered = Renderer::tables('<table><tbody><tr><td>A</td><td>B</td></tr><tr><td>1</td><td>2</td></tr></tbody></table>');
check('first table row is the header row', strpos($rendered, '<tr><th scope="col">A</th><th scope="col">B</th></tr><tr><td>1</td>') !== false, $rendered);
check('tables scroll in their own box', strpos($rendered, '<div class="table-wrap" role="region" aria-label="Tableau" tabindex="0"><table>') === 0);
check('HelloAsso placeholder kept by the sanitiser', strpos(Sanitizer::html('<p><a href="{{HELLOASSO_DON_URL}}" class="btn">Don</a></p>'), 'href="{{HELLOASSO_DON_URL}}"') !== false);
check('other placeholders are not links', Sanitizer::safeHref('{{AUTRE}}') === null);
Settings::set('helloasso_don_url', 'https://www.helloasso.com/associations/exemple/formulaires/1');
check('HelloAsso placeholder replaced when shown', strpos(Renderer::text('<p><a href="{{HELLOASSO_DON_URL}}" class="btn">Don</a></p>'), 'href="https://www.helloasso.com/associations/exemple/formulaires/1"') !== false);
check('card link placeholder replaced', strpos(Renderer::cards([['title' => 'Don', 'url' => '{{HELLOASSO_DON_URL}}']]), 'href="https://www.helloasso.com/associations/exemple/formulaires/1"') !== false);
Settings::set('helloasso_don_url', '');
KeyFigures::put('dons_annee', 'Dons de l\'année', '1 234,56', '€', 1, true);
KeyFigures::put('vide', 'Sans valeur', '', '', 2);
check('fig placeholder replaced with value and unit', Renderer::text('<p>Total : {{fig:dons_annee}}.</p>') === "<p>Total : 1 234,56\u{00A0}€.</p>", Renderer::text('<p>Total : {{fig:dons_annee}}.</p>'));
check('fig value is escaped', (function (): bool {
    KeyFigures::put('piege', 'x', '<b>1</b>', '', 3);
    $ok = strpos(Renderer::text('<p>{{fig:piege}}</p>'), '&lt;b&gt;') !== false;
    Db::run("DELETE FROM key_figures WHERE fig_key = 'piege'");
    KeyFigures::clearCache();
    return $ok;
})());
check('unknown fig key renders nothing', Renderer::text('<p>A{{fig:inconnue}}B</p>') === '<p>AB</p>');
check('unknown fig key is logged', strpos((string) @file_get_contents(DATA_PATH . '/logs/content.log'), '{{fig:inconnue}}') !== false);
check('fig in card text', strpos(Renderer::cards([['title' => 'T {{fig:dons_annee}}', 'text' => 'Soit {{fig:dons_annee}}']]), "Soit 1 234,56\u{00A0}€") !== false);
check('home band lists figures with a value only', array_column(KeyFigures::onHome(), 'fig_key') === ['dons_annee']);
check('figures form refuses a bad key', isset(KeyFigures::saveForm([['key' => 'Mauvaise Clé', 'value' => '1']])['fig']));
check('figures form refuses a duplicate key', isset(KeyFigures::saveForm([['key' => 'a', 'value' => '1'], ['key' => 'a', 'value' => '2']])['fig']));
check('figures form saves and deletes', KeyFigures::saveForm([
    ['key' => 'dons_annee', 'label' => 'Dons', 'value' => '2 000', 'unit' => '€', 'on_home' => '1'],
    ['key' => 'vide', 'delete' => '1'],
    ['key' => '', 'value' => 'ignorée'],
]) === [] && array_keys(KeyFigures::map()) === ['dons_annee'] && KeyFigures::map()['dons_annee']['value'] === '2 000');
$markerBlocks = [
    ['type' => 'text', 'html' => '<p>Texte [À VÉRIFIER : la date] et [À COMPLÉTER : le nom]</p>'],
    ['type' => 'cards', 'cards' => [['title' => 'Carte', 'text' => 'Photo [À COMPLÉTER : photo]', 'url' => '']]],
    ['type' => 'embed', 'url' => '[À COMPLÉTER : lien YouTube ou Vimeo]'],
];
check('markers found in text, cards and videos', Markers::inBlocks($markerBlocks) === ['[À VÉRIFIER : la date]', '[À COMPLÉTER : le nom]', '[À COMPLÉTER : photo]', '[À COMPLÉTER : lien YouTube ou Vimeo]'], json_encode(Markers::inBlocks($markerBlocks), JSON_UNESCAPED_UNICODE));
check('no marker in clean text', Markers::inPage(['title' => 'Titre', 'blocks' => '[{"type":"text","html":"<p>Rien</p>"}]']) === []);
check('old path normalised (accents, encoded or not)', OldPaths::normalize('/maf%C3%A9') === '/mafé' && OldPaths::normalize('/mafé') === '/mafé' && OldPaths::normalize('/spip.php?article7') === '/spip.php?article7');
OldPaths::put('/vid%C3%A9os', null, 'https://www.helloasso.com/associations/exemple', 'test');
$requestUri = $_SERVER['REQUEST_URI'] ?? null;
$found = [];
foreach (["/vid\xC3\xA9os", '/vid%C3%A9os', '/VID%C3%A9OS'] as $uri) {
    $_SERVER['REQUEST_URI'] = $uri; // what the router reads, raw UTF-8 or percent-encoded
    $row = OldPaths::find(Http::path(), '');
    $found[] = $row !== null ? OldPaths::target($row) : null;
}
$_SERVER['REQUEST_URI'] = $requestUri;
check('old accented path found from raw and encoded request', $found[0] === 'https://www.helloasso.com/associations/exemple' && $found[1] === $found[0], json_encode($found));
check('old path match is exact (case-sensitive)', $found[2] === null);
Db::run('DELETE FROM old_paths');

// ---------------------------------------------------------------------------
echo "Content import: validation (synthetic package)\n";

/** Write a synthetic package. $pages: file => [front matter array, body]. */
function writeFixture(string $dir, array $pages, array $extra = []): void
{
    rrmdir($dir);
    mkdir($dir . '/pages', 0777, true);
    mkdir($dir . '/media/photos', 0777, true);
    mkdir($dir . '/media/files', 0777, true);
    foreach ($pages as $file => $spec) {
        list($meta, $body) = $spec;
        $front = "---\n";
        foreach ($meta as $k => $v) {
            $front .= $k . ': ' . $v . "\n";
        }
        file_put_contents($dir . '/pages/' . $file, $front . "---\n\n" . $body);
    }
    $files = array_merge([
        '03-architecture.md' => "# Architecture (exemple)\n\n- **Accueil** `accueil` : page d'exemple.\n- **Projets** `projets`\n",
        '04-key-figures.md' => "# Chiffres (exemple)\n\n## Réglages du site proposés\n\n| Clé | Valeur actuelle | Source |\n|---|---|---|\n"
            . "| `dons_annee` | 1 234,56 € | exemple |\n| `part_frais` | 2,5 % | calcul |\n| `total` | 9 999 € (afficher « environ 10 000 € ») | exemple |\n| `prochaine_ag` | [À COMPLÉTER : date] | |\n",
        '05-media.csv' => "\xEF\xBB\xBFfichier,type,texte_alternatif_fr,droits_ou_consentement,page_suggeree,qualite\n"
            . "photos/a.jpg,photo,Une photo d'exemple,aucune,projet-a,usable\n"
            . "photos/b.jpg,photo,Autre photo,enfants identifiables,projet-b,usable\n"
            . "photos/absente.jpg,photo,Photo absente,aucune,projet-a,usable\n"
            . "photos/faux.jpg,photo,Faux,aucune,projet-a,usable\n",
        '07-redirect-seeds.csv' => "ancien_chemin,nouveau_chemin,note\n/,/,accueil\n/ancienne-page,/projets,exemple\n/mafé,/projet-a,accent\n/maf%C3%A9,/projet-a,encodé\n"
            . "/contact-8,/nous-aider,boutons\n/spip.php?article9,/videos,ancien site\n/_files/ugd/x.pdf,/projet-a,pdf\n/supprimee,removed,retirée\n/projets,/projets,même adresse\n",
        'inventaire-prive.csv' => "ne,doit,jamais,etre,importe\n",
    ], $extra);
    foreach ($files as $name => $content) {
        if ($content !== null) {
            file_put_contents($dir . '/' . $name, $content);
        }
    }
    $img = imagecreatetruecolor(1200, 800);
    imagefill($img, 0, 0, imagecolorallocate($img, 30, 120, 180));
    imagejpeg($img, $dir . '/media/photos/a.jpg', 85);
    imagejpeg($img, $dir . '/media/photos/b.jpg', 85);
    imagegif($img, $dir . '/media/photos/faux.jpg');
    $img = null;
    file_put_contents($dir . '/media/files/doc.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
}

function fixturePage(string $title, string $slug, string $parent, int $position, bool $menu, string $header = 'none', string $meta = 'Description d\'exemple.'): array
{
    return [
        'title' => $title, 'slug' => $slug, 'parent' => $parent, 'position' => (string) $position,
        'show_in_menu' => $menu ? 'true' : 'false', 'seo_title' => $title . ' (exemple)', 'meta_description' => $meta,
        'header_image' => $header, 'sources' => '[exemple.pdf]', 'status' => 'draft',
    ];
}

$basePages = [
    '01-accueil.md' => [fixturePage('Accueil exemple', 'accueil', 'none', 1, false), "<!-- block: text -->\n## Bienvenue\n\nTexte d'exemple. Total : {{fig:dons_annee}}.\n\n[Je donne]({{HELLOASSO_DON_URL}})\n\n<!-- block: card_grid -->\n- title: Nos projets\n  text: Voir la liste.\n  image: [À COMPLÉTER : pictogramme]\n  link: projets\n- title: Accueil\n  link: accueil\n"],
    '02-projets.md' => [fixturePage('Projets exemple', 'projets', 'none', 2, true), "<!-- block: text -->\nListe des projets d'exemple.\n\n<!-- block: card_grid -->\n- title: Projet A\n  text: Le premier.\n  image: photos/a.jpg\n  link: projet-a\n- title: Don\n  link: {{HELLOASSO_DON_URL}}\n- title: Rapport\n  link: /files/doc.pdf\n"],
    '03-projet-a.md' => [fixturePage('Projet A', 'projet-a', 'projets', 1, false, 'photos/a.jpg'), "<!-- block: text -->\n## Budget\n\n| Année | Montant |\n|---|---|\n| 2025 | 100 € |\n\n[Le document](/files/doc.pdf) et [la liste](/projets).\n\nDate : [À VÉRIFIER : date exacte]\n"],
    '04-projet-b.md' => [fixturePage('Projet B', 'projet-b', 'projets', 2, false, 'photos/b.jpg'), "<!-- block: text -->\nTexte sans marque.\n"],
    '05-videos.md' => [fixturePage('Vidéos', 'videos', 'none', 3, true), "<!-- block: embed -->\nurl: https://youtu.be/dQw4w9WgXcQ\n\n<!-- block: embed -->\nurl: [À COMPLÉTER : lien YouTube ou Vimeo]\n"],
];
$fixture = $tmp . '/fixture';
$validate = function (array $pages, array $extra = []) use ($fixture): ContentImport {
    writeFixture($fixture, $pages, $extra);
    $import = new ContentImport($fixture);
    $import->validate();
    return $import;
};
$codes = function (ContentImport $import): array {
    return array_values(array_unique(array_column($import->failures(), 0)));
};

$base = $validate($basePages);
check('valid synthetic package passes', $base->failures() === [], json_encode($base->failures(), JSON_UNESCAPED_UNICODE));
check('home page taken from 03-architecture.md', $base->home === 'accueil');
check('markers counted per page', count($base->pages['projet-a']['markers']) === 1 && count($base->pages['videos']['markers']) === 1 && count($base->pages['accueil']['markers']) === 1 && $base->pages['projet-b']['markers'] === []);
check('media flagged for consent is skipped', $base->media['photos/b.jpg']['status'] === 'skipped' && strpos($base->media['photos/b.jpg']['reason'], 'enfants') !== false);
check('referenced media found', $base->media['photos/a.jpg']['status'] === 'ok' && $base->media['/files/doc.pdf']['status'] === 'ok');
check('only referenced media are considered', !isset($base->media['photos/absente.jpg']) && count($base->media) === 3);
check('publish rule: no markers only (home always)', $base->willPublish($base->pages['projet-b'], true) && !$base->willPublish($base->pages['projet-a'], true)
    && !$base->willPublish($base->pages['projet-b'], false) && $base->willPublish($base->pages['accueil'], false));
check('figures read, marker left empty, note kept', $base->figures[0]['value'] === '1 234,56' && $base->figures[0]['unit'] === '€' && $base->figures[1]['unit'] === '%'
    && $base->figures[2]['value'] === '9 999' && strpos($base->figures[2]['note'], 'environ') !== false && $base->figures[3]['value'] === '');
$redirectsByOld = array_column($base->redirects, null, 'old');
check('redirect seeds: encoded and decoded forms merged', count(array_filter($base->redirects, function ($r) {
    return $r['old'] === '/mafé';
})) === 1);
check('/contact-8 goes to HelloAsso (PRD over package)', $redirectsByOld['/contact-8']['action'] === 'helloasso' && $base->differences !== []);
check('removed and same-address seeds understood', $redirectsByOld['/supprimee']['action'] === 'gone' && $redirectsByOld['/projets']['action'] === 'same' && $redirectsByOld['/']['action'] === 'same');
$reportText = $base->report('check', false);
check('report has counts, pages, media and markers', strpos($reportText, '| Pages | 5') !== false && strpos($reportText, 'Media skipped') !== false
    && strpos($reportText, '3 in 3 pages') !== false && strpos($reportText, 'all checks pass') !== false, $reportText);
check('private working files are not in the report', strpos($reportText, 'inventaire') === false && strpos($reportText, 'exemple.pdf') === false);

$rules = [
    'SLUG-FORMAT' => function (array $p): array {
        $p['04-projet-b.md'][0]['slug'] = 'Projet_B';
        return $p;
    },
    'SLUG-DUPLICATE' => function (array $p): array {
        $p['06-double.md'] = [fixturePage('Double', 'projet-b', 'projets', 3, false, 'photos/a.jpg'), "<!-- block: text -->\nx\n"];
        return $p;
    },
    'SLUG-RESERVED' => function (array $p): array {
        $p['04-projet-b.md'][0]['slug'] = 'admin';
        return $p;
    },
    'SLUG-MISSING' => function (array $p): array {
        $p['04-projet-b.md'][0]['slug'] = '';
        return $p;
    },
    'TITLE' => function (array $p): array {
        $p['04-projet-b.md'][0]['title'] = '';
        return $p;
    },
    'PARENT-MISSING' => function (array $p): array {
        $p['04-projet-b.md'][0]['parent'] = 'inexistante';
        return $p;
    },
    'DEPTH' => function (array $p): array {
        $p['06-n3.md'] = [fixturePage('Niveau 3', 'niveau-3', 'projet-a', 1, false, 'photos/a.jpg'), "<!-- block: text -->\nx\n"];
        $p['07-n4.md'] = [fixturePage('Niveau 4', 'niveau-4', 'niveau-3', 1, false, 'photos/a.jpg'), "<!-- block: text -->\nx\n"];
        return $p;
    },
    'PARENT-CYCLE' => function (array $p): array {
        $p['02-projets.md'][0]['parent'] = 'projet-a';
        return $p;
    },
    'MENU-COUNT' => function (array $p): array {
        for ($i = 1; $i <= 6; $i++) {
            $p['1' . $i . '-menu.md'] = [fixturePage('Menu ' . $i, 'menu-' . $i, 'none', 10 + $i, true), "<!-- block: text -->\nx\n"];
        }
        return $p;
    },
    'POSITION' => function (array $p): array {
        $p['04-projet-b.md'][0]['position'] = 'deux';
        return $p;
    },
    'SEO-TITLE-LENGTH' => function (array $p): array {
        $p['04-projet-b.md'][0]['seo_title'] = str_repeat('é', 61);
        return $p;
    },
    'META-LENGTH' => function (array $p): array {
        $p['04-projet-b.md'][0]['meta_description'] = str_repeat('é', 156);
        return $p;
    },
    'CHILD-DESCRIPTION' => function (array $p): array {
        $p['04-projet-b.md'][0]['meta_description'] = '';
        return $p;
    },
    'CHILD-HEADER-IMAGE' => function (array $p): array {
        $p['04-projet-b.md'][0]['header_image'] = 'none';
        return $p;
    },
    'MEDIA-MISSING' => function (array $p): array {
        $p['04-projet-b.md'][0]['header_image'] = 'photos/absente.jpg';
        return $p;
    },
    'MEDIA-TYPE' => function (array $p): array {
        $p['04-projet-b.md'][0]['header_image'] = 'photos/faux.jpg';
        return $p;
    },
    'BLOCK-TYPE' => function (array $p): array {
        $p['04-projet-b.md'][1] .= "\n<!-- block: gallery -->\nx\n";
        return $p;
    },
    'EMBED-HOST' => function (array $p): array {
        $p['05-videos.md'][1] = "<!-- block: embed -->\nurl: https://www.dailymotion.com/video/x7tgad0\n";
        return $p;
    },
    'PLACEHOLDER' => function (array $p): array {
        $p['04-projet-b.md'][1] .= "\n{{AUTRE_LIEN}} et {{fig:inconnue}}\n";
        return $p;
    },
    'CARD-LINK' => function (array $p): array {
        $p['02-projets.md'][1] = "<!-- block: card_grid -->\n- title: Perdue\n  link: page-inexistante\n";
        return $p;
    },
    'FIELD-MISSING' => function (array $p): array {
        unset($p['04-projet-b.md'][0]['seo_title']);
        return $p;
    },
];
foreach ($rules as $code => $mutate) {
    $result = $validate($mutate($basePages));
    check('validation rule ' . $code, in_array($code, $codes($result), true), json_encode($codes($result)));
}
$sizeImport = (function () use ($validate, $basePages): array {
    Config::override(['max_pdf_mb' => 0.00001]);
    $result = $validate($basePages);
    Config::override(['max_pdf_mb' => 20]);
    return array_column($result->failures(), 0);
})();
check('validation rule MEDIA-SIZE', in_array('MEDIA-SIZE', $sizeImport, true));
$noHome = $validate($basePages, ['03-architecture.md' => "# Architecture\n\nPas de page d'accueil désignée.\n"]);
check('validation rule HOME (unclear: ask Daniel)', in_array('HOME', $codes($noHome), true) && strpos(json_encode($noHome->failures(), JSON_UNESCAPED_UNICODE), 'Ask Daniel') !== false);
$homeChild = $basePages;
$homeChild['04-projet-b.md'][0]['parent'] = 'accueil';
check('validation rule HOME (no sub-pages)', in_array('HOME', $codes($validate($homeChild)), true));
$badRedirect = $validate($basePages, ['07-redirect-seeds.csv' => "ancien_chemin,nouveau_chemin,note\n/vieux,/page-inconnue,x\n"]);
check('validation rule REDIRECT (target page)', in_array('REDIRECT', $codes($badRedirect), true));

// ---------------------------------------------------------------------------
echo "Content import: building the seed (separate PHP process)\n";
writeFixture($fixture, $basePages);
$seed = $tmp . '/seed';
$runImport = function (string $args) use ($projectRoot, $fixture, $seed): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($projectRoot . '/build/import-content.php')
        . ' --source=' . escapeshellarg($fixture) . ' --out=' . escapeshellarg($seed) . ' ' . $args . ' 2>&1';
    exec($cmd, $output, $code);
    return [$code, implode("\n", $output)];
};
list($code, $output) = $runImport('--check');
check('--check passes and writes nothing', $code === 0 && !is_dir($seed), $output);
list($code, $output) = $runImport('--import');
check('--import refused without PHP 7.3 (unless allowed)', PHP_VERSION_ID < 70400 ? $code === 0 : ($code === 2 && strpos($output, 'PHP 7.3') !== false), $output);
rrmdir($seed);
list($code, $output) = $runImport('--import --allow-any-php');
check('--import builds the seed', $code === 0 && is_file($seed . '/os-private/data/site.sqlite') && is_file($seed . '/import-report.md'), $output);
$seedDb = new PDO('sqlite:' . $seed . '/os-private/data/site.sqlite');
$seedDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$q = function (string $sql) use ($seedDb) {
    return $seedDb->query($sql)->fetchAll();
};
$seedPages = array_column($q('SELECT * FROM pages ORDER BY id'), null, 'slug');
check('seed: every page imported', count($seedPages) === 5, implode(',', array_keys($seedPages)));
check('seed: pages hidden by default, home published', $seedPages['accueil']['status'] === 'published' && (int) $seedPages['accueil']['is_home'] === 1
    && $seedPages['projet-b']['status'] === 'hidden' && $seedPages['projets']['status'] === 'hidden');
check('seed: one home page (the installation placeholder replaced)', count($q('SELECT id FROM pages WHERE is_home = 1')) === 1 && strpos((string) $seedPages['accueil']['blocks'], 'À COMPLÉTER : présentation') === false);
check('seed: one version per page, by "import"', (int) $q('SELECT COUNT(*) AS n FROM page_versions')[0]['n'] === 5 && $q("SELECT COUNT(*) AS n FROM page_versions WHERE user_email <> 'import'")[0]['n'] == 0);
check('seed: tree and menu', (int) $seedPages['projet-a']['parent_id'] === (int) $seedPages['projets']['id'] && (int) $seedPages['projets']['show_in_menu'] === 1 && (int) $seedPages['accueil']['show_in_menu'] === 0);
check('seed: search-engine texts', $seedPages['projet-a']['seo_description'] === 'Description d\'exemple.' && $seedPages['projet-a']['seo_title'] === 'Projet A (exemple)');
$blocksA = (string) $seedPages['projet-a']['blocks'];
check('seed: Markdown converted and sanitised (table, heading)', strpos($blocksA, '<h2>Budget</h2>') !== false && strpos($blocksA, '<table><thead><tr><th>Année</th>') !== false, $blocksA);
check('seed: PDF link points to the library file', preg_match('~href=\\\\?"/media/[a-f0-9]{20}\.pdf\\\\?"~', $blocksA) === 1, $blocksA);
check('seed: page link kept', strpos($blocksA, 'href=\"/projets\"') !== false || strpos($blocksA, 'href="/projets"') !== false);
$homeBlocks = json_decode((string) $seedPages['accueil']['blocks'], true);
check('seed: placeholders kept for render time', strpos($homeBlocks[0]['html'], '{{fig:dons_annee}}') !== false && strpos($homeBlocks[0]['html'], 'href="{{HELLOASSO_DON_URL}}" class="btn"') !== false, $homeBlocks[0]['html']);
check('seed: card link slug resolved, home link is /', $homeBlocks[1]['cards'][0]['url'] === '/projets' && $homeBlocks[1]['cards'][1]['url'] === '/');
check('seed: card image marker kept visible in the card text', strpos($homeBlocks[1]['cards'][0]['text'], '[À COMPLÉTER : pictogramme]') !== false && $homeBlocks[1]['cards'][0]['media_id'] === null);
$projetsBlocks = json_decode((string) $seedPages['projets']['blocks'], true);
check('seed: card image and HelloAsso card link', !empty($projetsBlocks[1]['cards'][0]['media_id']) && $projetsBlocks[1]['cards'][1]['url'] === '{{HELLOASSO_DON_URL}}');
check('seed: card link to a file opens the library PDF', preg_match('~^/media/[a-f0-9]{20}\.pdf$~', (string) $projetsBlocks[1]['cards'][2]['url']) === 1, (string) $projetsBlocks[1]['cards'][2]['url']);
check('seed: PDF used by a card is tracked', (int) $q("SELECT COUNT(*) AS n FROM media_usage u JOIN media m ON m.id = u.media_id WHERE m.kind = 'pdf'")[0]['n'] === 2);
$videoBlocks = json_decode((string) $seedPages['videos']['blocks'], true);
check('seed: video kept, missing video address becomes a visible marker', $videoBlocks[0]['type'] === 'embed' && $videoBlocks[1]['type'] === 'text' && strpos($videoBlocks[1]['html'], 'À COMPLÉTER') !== false);
$seedMedia = $q('SELECT * FROM media ORDER BY id');
check('seed: only cleared, referenced media imported', count($seedMedia) === 2 && $seedMedia[0]['kind'] === 'image' && $seedMedia[1]['kind'] === 'pdf');
check('seed: alt text from 05-media.csv', $seedMedia[0]['alt'] === 'Une photo d\'exemple');
check('seed: media by the admin upload path (web versions, private original)', $seedMedia[0]['variants'] === '[480,960,1200]'
    && is_file($seed . '/os-private/media/' . $seedMedia[0]['token'] . '-960.' . $seedMedia[0]['web_ext'])
    && is_file($seed . '/os-private/data/originals/' . $seedMedia[0]['token'] . '.jpg'), $seedMedia[0]['variants']);
check('seed: skipped image leaves no header', $seedPages['projet-b']['header_media_id'] === null && (int) $seedPages['projet-a']['header_media_id'] === (int) $seedMedia[0]['id']);
check('seed: media usage recorded', (int) $q('SELECT COUNT(*) AS n FROM media_usage')[0]['n'] >= 3);
$seedPaths = array_column($q('SELECT * FROM old_paths'), null, 'old_path');
check('seed: old addresses loaded (decoded, with query string)', isset($seedPaths['/mafé'], $seedPaths['/spip.php?article9'], $seedPaths['/_files/ugd/x.pdf']) && !isset($seedPaths['/maf%C3%A9']) && !isset($seedPaths['/projets']) && !isset($seedPaths['/']), implode(', ', array_keys($seedPaths)));
check('seed: /contact-8 to HelloAsso, removed page gone', $seedPaths['/contact-8']['target_url'] === '{{HELLOASSO_URL}}' && $seedPaths['/contact-8']['page_id'] === null
    && $seedPaths['/supprimee']['page_id'] === null && $seedPaths['/supprimee']['target_url'] === '');
$seedFigures = array_column($q('SELECT * FROM key_figures'), null, 'fig_key');
check('seed: key figures (verified values only)', $seedFigures['dons_annee']['value'] === '1 234,56' && $seedFigures['dons_annee']['unit'] === '€' && $seedFigures['prochaine_ag']['value'] === '');
check('seed: no users, sessions or logs', (int) $q('SELECT COUNT(*) AS n FROM users')[0]['n'] === 0
    && count(glob($seed . '/os-private/data/sessions/*') ?: []) === 0 && count(glob($seed . '/os-private/data/logs/*') ?: []) === 0);
check('seed: no development settings', (int) $q("SELECT COUNT(*) AS n FROM settings WHERE key NOT IN ('contact_email')")[0]['n'] === 0);
check('seed: SQLite journal mode DELETE, integrity ok', strtolower((string) $q('PRAGMA journal_mode')[0]['journal_mode']) === 'delete' && $q('PRAGMA integrity_check')[0]['integrity_check'] === 'ok'
    && !is_file($seed . '/os-private/data/site.sqlite-wal'));
check('seed: nothing from the private working files', strpos((string) file_get_contents($seed . '/os-private/data/site.sqlite'), 'inventaire') === false
    && strpos((string) file_get_contents($seed . '/os-private/data/site.sqlite'), 'exemple.pdf') === false);
$q = null; // the closure holds the connection too
$seedDb = null;

list($code, $output) = $runImport('--import --allow-any-php');
check('existing seed is not overwritten without --force', $code === 3 && strpos($output, '--force') !== false, $output);
list($code, $output) = $runImport('--import --publish --force --allow-any-php');
check('--force replaces the seed', $code === 0, $output);
$seedDb = new PDO('sqlite:' . $seed . '/os-private/data/site.sqlite');
$status = $seedDb->query('SELECT slug, status FROM pages')->fetchAll(PDO::FETCH_KEY_PAIR);
$seedDb = null;
check('--publish publishes only pages without markers', $status['projet-b'] === 'published' && $status['projets'] === 'published'
    && $status['projet-a'] === 'hidden' && $status['videos'] === 'hidden' && $status['accueil'] === 'published', json_encode($status));
check('--publish without --import refused', $runImport('--publish')[0] === 2);
$broken = $basePages;
$broken['04-projet-b.md'][0]['slug'] = 'Mauvais Slug';
writeFixture($fixture, $broken);
rrmdir($seed);
list($code, $output) = $runImport('--import --allow-any-php');
check('validation failure stops the import, no seed left', $code === 1 && !is_dir($seed) && strpos($output, 'SLUG-FORMAT') !== false, $output);
rrmdir($fixture);
