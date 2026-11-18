<?php
// Execute only in the disposable WordPress fixture, using wp eval-file.
if (wp_get_environment_type() !== 'local') {
    throw new RuntimeException('Local fixture required.');
}
$plugin = WP_PLUGIN_DIR . '/digital-xperts-social-meta/digital-xperts-social-meta.php';
if (!is_file($plugin)) {
    throw new RuntimeException('RED: Centrum plugin implementation does not exist.');
}
require_once $plugin;
use DigitalXperts\SocialMeta\Plugin;
$GLOBALS['checks'] = 0;
function check($condition, $message) {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    ++$checks;
}
function metadata($html) {
    $processor = new WP_HTML_Tag_Processor($html);
    $result = array();
    while ($processor->next_tag('META')) {
        $key = $processor->get_attribute('property') ?: $processor->get_attribute('name');
        if (is_string($key)) { $result[strtolower($key)][] = $processor->get_attribute('content'); }
    }
    return $result;
}
$context = array('title' => 'RS2 <test> & "cytat"', 'description' => 'Odbiór odpadów w Zgierzu.',
    'url' => 'https://rs2.pl/uslugi/', 'site_name' => 'RS2 Spółka z o.o.', 'locale' => 'pl_PL',
    'type' => 'website', 'image' => array('url' => 'https://rs2.pl/logo.png', 'width' => 1920,
    'height' => 810, 'type' => 'image/png', 'alt' => 'Logo RS2'));
$original = '<link rel="canonical" href="https://rs2.pl/uslugi/" />'
    . '<script type="application/ld+json">{"@type":"ContactPage"}</script>';
$result = Plugin::complete_head($original, $context);
$meta = metadata($result);
check(strpos($result, $original) === 0, 'Existing canonical and JSON-LD must remain byte-for-byte intact.');
check($meta['og:title'] === array($context['title']), 'Title must be escaped once, retaining Unicode and quotes.');
check($meta['og:site_name'] === array('RS2 Spółka z o.o.'), 'Publisher must be the site.');
check($meta['twitter:card'] === array('summary_large_image'), 'Large local image should produce a large card.');
check(count($meta['description']) === 1, 'Exactly one meta description must be produced.');
check(count(metadata(Plugin::complete_head($result, $context))['og:title']) === 1, 'Repeated processing must not duplicate tags.');
check(substr_count($result, 'application/ld+json') === 1, 'No competing structured-data graph.');
check(strpos($result, '\\n<meta') === false, 'No literal escaped newline text in the document.');
$canonical = metadata(Plugin::complete_head('<link rel="canonical" href="https://rs2.pl/current/" />', $context));
check($canonical['og:url'] === array('https://rs2.pl/current/'), 'Existing canonical must be used in the social URL.');

$existing = '<meta NAME="description" content="Własny &amp; aktualny opis" />'
    . '<meta property="og:title" content="Istniejący tytuł" />'
    . '<meta name="twitter:card" content="summary" />';
$retained = Plugin::complete_head($existing, $context);
$meta = metadata($retained);
check(strpos($retained, $existing) === 0, 'Existing metadata must not be rewritten.');
check($meta['description'] === array('Własny & aktualny opis'), 'Existing SEO description must be preserved.');
check($meta['og:title'] === array('Istniejący tytuł') && !isset($meta['og:url']), 'An existing OG owner must not be mixed with generated OG.');
check($meta['twitter:card'] === array('summary') && !isset($meta['twitter:title']), 'An existing Twitter owner must not be mixed with generated tags.');
$meta = metadata(Plugin::complete_head('<meta name="description" content="Opis istniejący" />', $context));
check($meta['og:description'] === array('Opis istniejący'), 'Social description should reuse existing SEO description.');

$settings = Plugin::sanitize_settings(array('site_name' => '<b>RS2</b>',
    'default_image_url' => 'https://external.invalid/font-or-image.png',
    'default_image_width' => 999999, 'descriptions' => array(12 => 'Opis <script>tekst</script>')));
check($settings['site_name'] === 'RS2', 'Setting text must be sanitized.');
check($settings['default_image_url'] === '', 'External image URLs must be refused.');
check($settings['default_image_width'] <= 10000, 'Dimensions must be bounded.');
$no_image = $context; $no_image['image'] = array();
$meta = metadata(Plugin::complete_head('', $no_image));
check(!isset($meta['og:image']) && $meta['twitter:card'] === array('summary'), 'Missing image must not emit an empty image URL.');

$page = wp_insert_post(array('post_title' => 'Usługi RS2', 'post_content' => '<p>Wywóz odpadów.</p>',
    'post_status' => 'publish', 'post_type' => 'page'), true);
check(!is_wp_error($page), 'Fixture page must exist.');
update_option('dxsm_settings', array('site_name' => 'RS2 Spółka z o.o.',
    'default_description' => 'Lokalna firma.', 'descriptions' => array($page => 'Opis konkretnej strony.'),
    'default_image_url' => home_url('/local.png'), 'default_image_width' => 1200, 'default_image_height' => 630));
query_posts(array('page_id' => $page));
$before = get_post($page, ARRAY_A);
$ctx = Plugin::context();
check($ctx['description'] === 'Opis konkretnej strony.', 'Per-page configured description must be used.');
check($ctx['image']['url'] === home_url('/local.png'), 'Fallback image must be local.');
check(get_post($page, ARRAY_A) === $before, 'Reading metadata must not mutate the post.');
ob_start(); do_action('wp_head'); $actual = ob_get_clean();
check(count(metadata($actual)['og:title']) === 1, 'Real wp_head hooks should produce one OG title.');
check(count(metadata($actual)['description']) === 1, 'Real wp_head hooks should produce one SEO description.');
check(strpos($actual, 'Digital Xperts tworzy strony') === false, 'No foreign brand description.');
check(strpos($actual, '<iframe') === false, 'No remote social embed.');
wp_update_post(array('ID' => $page, 'post_password' => 'fixture-protection'));
require_once ABSPATH . WPINC . '/class-phpass.php';
$hasher = new PasswordHash(8, true);
$_COOKIE['wp-postpass_' . COOKIEHASH] = $hasher->HashPassword('fixture-protection');
query_posts(array('page_id' => $page));
check(!post_password_required(get_post($page)), 'Fixture must simulate an unlocked password-protected page.');
check(Plugin::context() === null, 'Protected content must remain excluded even when unlocked for a visitor.');
unset($_COOKIE['wp-postpass_' . COOKIEHASH]);
wp_update_post(array('ID' => $page, 'post_password' => ''));
register_post_type('cp_private_fixture', array('public' => false, 'publicly_queryable' => false));
$hidden = wp_insert_post(array('post_title' => 'Internal fixture', 'post_status' => 'publish', 'post_type' => 'cp_private_fixture'));
query_posts(array('post_type' => 'cp_private_fixture', 'p' => $hidden));
check(Plugin::context() === null, 'Published objects of a nonpublic type must not expose metadata.');
wp_delete_post($hidden, true);
wp_update_post(array('ID' => $page, 'post_status' => 'private'));
query_posts(array('page_id' => $page, 'post_status' => 'private'));
check(Plugin::context() === null, 'Private content must not expose metadata.');
wp_delete_post($page, true);

// Panel SEO przeniesiony z d-x.pl.
// Uwaga sędziego Codexa: znak „>” w atrybucie nie może psuć nagłówka ani blokować nadpisania.
$over = array('description' => 'NOWY', 'description_override' => true) + $context;
foreach (array('<meta content="2 > 1" name="description">', '<meta name="description" content="2 > 1">', '<meta name="description" content="a"><meta name=description content=b>',
    '<meta property="og:locale" content="a > b"><meta NAME="Description" content="x">') as $html) {
    $out = Plugin::complete_head($html, $over);
    check(metadata($out)['description'] === array('NOWY') && strpos($out, '1">') === false && strpos($out, '2 &gt; 1') === false, 'Override must leave exactly one clean description: ' . $html);
}
check(strpos(Plugin::complete_head('<meta property="og:locale" content="a > b"><meta name="description" content="x">', $over), '<meta property="og:locale" content="a > b">') === 0, 'Other meta tags must stay byte-for-byte intact.');
// Runda 3: tekst „<meta …>” wewnątrz skryptu nie jest znacznikiem i zostaje bajt w bajt.
$script = '<script type="application/ld+json">{"description":"<meta name=\'description\' content=\'old\'>"}</script>';
$out = Plugin::complete_head($script . '<meta name="description" content="a"><meta name="description" content="b">', $over);
check(strpos($out, $script) === 0 && metadata($out)['description'] === array('NOWY') && strpos($out, 'data-dxsmc-drop') === false, 'Script text must stay intact while real duplicates go.');
$out = Plugin::complete_head($script, $over);
check(strpos($out, $script) === 0 && metadata($out)['description'] === array('NOWY'), 'With only script text, one real description is added and the script is untouched.');
$admin = get_users(array('role' => 'administrator', 'number' => 1))[0];
wp_set_current_user($admin->ID);
update_option('dxsm_settings', array('site_name' => 'Centrum', 'default_description' => 'Opis domyślny.'));
$seo = wp_insert_post(array('post_title' => 'Psychoterapia', 'post_content' => '<p>Treść.</p>', 'post_status' => 'publish', 'post_type' => 'page'), true);
check(dxsm_store_seo_fields($seo, array('_dxsm_seo_title' => 'Własny <b>tytuł</b> & "znaki"', '_dxsm_seo_description' => 'Własny opis <script>x</script>')), 'SEO fields must be stored.');
check(get_post_meta($seo, '_dxsm_seo_title', true) === 'Własny tytuł & "znaki"', 'SEO title must be stored as sanitized text.');
$cp_seo = function () { echo '<meta name="description" content="Opis z cp-seo">' . "\n"; };
add_action('wp_head', $cp_seo, 1);
query_posts(array('page_id' => $seo));
$GLOBALS['wp_query']->is_singular = $GLOBALS['wp_query']->is_page = true;
ob_start(); do_action('wp_head'); $head = ob_get_clean();
$meta = metadata($head);
check($meta['description'] === array('Własny opis'), 'Panel description must replace the cp-seo description, leaving one.');
check($meta['og:description'] === array('Własny opis') && $meta['twitter:description'] === array('Własny opis'), 'Social cards must use the panel description.');
check(strpos(wp_get_document_title(), 'Własny tytuł &amp; &quot;znaki&quot;') === 0, 'Document title must use the escaped panel title.');
check($meta['og:title'] === array('Własny tytuł & "znaki"'), 'og:title must use the panel title once, decoded.');
$_GET['dxsm_auto'] = 'x';
ob_start(); do_action('wp_head'); $auto = metadata(ob_get_clean());
check($auto['description'] === array('Opis z cp-seo') && strpos($auto['og:title'][0], 'Własny') === false, 'Automatic request must show values without panel overrides.');
unset($_GET['dxsm_auto']);
dxsm_store_seo_fields($seo, array('_dxsm_seo_title' => '', '_dxsm_seo_description' => ''));
check(get_post_meta($seo, '_dxsm_seo_description', true) === '', 'Empty field must delete the override.');
ob_start(); do_action('wp_head'); $plain = metadata(ob_get_clean());
check($plain['description'] === array('Opis z cp-seo'), 'Without override the existing description stays untouched.');
remove_action('wp_head', $cp_seo, 1);
// 1.1.1: bez półpauz i pauz w tytule, opisie i kartach (separator WordPressa daje &#8211;).
$en = "\xE2\x80\x93"; $em = "\xE2\x80\x94";
$cp_dash = function () use ($en) { echo '<meta name="description" content="Centrum Psychologii ' . $en . ' Martyna">' . "\n"; };
add_action('wp_head', $cp_dash, 1);
ob_start(); do_action('wp_head'); $dash_head = ob_get_clean(); $dash = metadata($dash_head);
$doc_title = wp_get_document_title();
check($dash['description'] === array('Centrum Psychologii - Martyna') && $dash['og:description'] === array('Centrum Psychologii - Martyna'), 'Existing description loses the en dash, once.');
check(strpos($doc_title, '&#8211;') === false && strpos($doc_title, ' - ') !== false, 'Document title separator must be a hyphen, got: ' . $doc_title);
check(!preg_match('/\x{2013}|\x{2014}|&#821[12];/u', $dash_head . $doc_title), 'Head must contain no en or em dash.');
remove_action('wp_head', $cp_dash, 1);
dxsm_store_seo_fields($seo, array('_dxsm_seo_title' => "Tytuł {$en} test {$em} koniec", '_dxsm_seo_description' => "Opis {$em} panel"));
check(get_post_meta($seo, '_dxsm_seo_title', true) === 'Tytuł - test - koniec' && get_post_meta($seo, '_dxsm_seo_description', true) === 'Opis - panel', 'Panel fields are stored with hyphens.');
// Codex 1.1.1 r1: adresy bez zmian, obce og:* i drugi opis oczyszczone, stare pole z encją, pre_get_document_title, encje szesnastkowe.
$dash_context = array_merge($context, array('title' => "A {$en} B", 'url' => "https://x.test/a{$en}b/",
    'image' => array_merge($context['image'], array('url' => "https://x.test/logo{$en}c.png", 'alt' => "Logo {$em} C"))));
$dash_out = metadata(DigitalXperts\SocialMeta\Plugin::complete_head("<meta name=\"description\" content=\"Jeden {$en} a\">\n<meta name=\"description\" content=\"Dwa &#x2013; b\">\n", $dash_context));
check($dash_out['og:url'] === array("https://x.test/a{$en}b/") && $dash_out['og:image'] === array("https://x.test/logo{$en}c.png") && $dash_out['twitter:image'] === array("https://x.test/logo{$en}c.png"), 'URLs must keep their characters.');
check($dash_out['description'] === array('Jeden - a', 'Dwa - b') && $dash_out['og:title'] === array('A - B') && $dash_out['og:image:alt'] === array('Logo - C'), 'All descriptions, titles and alt lose dashes, hex entities included.');
$foreign = metadata(DigitalXperts\SocialMeta\Plugin::complete_head("<meta property=\"og:title\" content=\"Obcy {$en} tytuł\">\n<meta property=\"og:url\" content=\"https://x.test/{$en}/\">\n", $context));
check($foreign['og:title'] === array('Obcy - tytuł') && $foreign['og:url'] === array("https://x.test/{$en}/"), 'Foreign og:title is cleaned, foreign og:url is not.');
$extra = metadata(DigitalXperts\SocialMeta\Plugin::complete_head("<meta name=\"twitter:data1\" content=\"5 &ndash; 7 minut\">\n<meta name=\"twitter:label1\" content=\"Czas {$en} czytania\">\n<meta property=\"og:image:secure_url\" content=\"https://x.test/{$en}.png\">\n<meta name=\"twitter:player:stream\" content=\"https://x.test/{$en}.mp4\">\n", $context));
check($extra['twitter:data1'] === array('5 - 7 minut') && $extra['twitter:label1'] === array('Czas - czytania') && $extra['og:image:secure_url'] === array("https://x.test/{$en}.png") && $extra['twitter:player:stream'] === array("https://x.test/{$en}.mp4"), 'Text twitter:* fields are cleaned, URL fields are not.');
check(dxsm_without_dashes('A &#8211 B &#x2014 C') === 'A - B - C' && dxsm_without_dashes('&#82110') === '&#82110', 'Semicolon-less references cleaned, longer numbers kept.');
foreach (array('og:see_also', 'og:referenced') as $url_key) { check(!dxsm_is_dash_free_meta($url_key), 'URL key must stay untouched: ' . $url_key); }
$titled = DigitalXperts\SocialMeta\Plugin::complete_head("<title>Yoast &mdash; tytuł {$en} X</title>\n", $context);
check(strpos($titled, '<title>Yoast - tytuł - X</title>') !== false, 'Foreign title element loses dashes, got: ' . $titled);
foreach (array('twitter:app:url:iphone', 'twitter:app:url:ipad', 'twitter:app:url:googleplay', 'twitter:image:src', 'og:video:secure_url', 'og:audio') as $url_key) {
    check(!dxsm_is_dash_free_meta($url_key), 'URL key must stay untouched: ' . $url_key);
}
foreach (array('description', 'og:title', 'og:image:alt', 'twitter:image:alt', 'twitter:app:name:iphone', 'twitter:data1') as $text_key) {
    check(dxsm_is_dash_free_meta($text_key), 'Text key must be cleaned: ' . $text_key);
}
update_post_meta($seo, '_dxsm_seo_title', 'Stary &#8211; tytuł');
query_posts(array('page_id' => $seo));
$GLOBALS['wp_query']->is_singular = $GLOBALS['wp_query']->is_page = true;
check(wp_get_document_title() === 'Stary - tytuł', 'Legacy stored title with an entity must print a hyphen, got: ' . wp_get_document_title());
$pre = function () use ($em) { return "Z innej wtyczki {$em} tytuł"; };
add_filter('pre_get_document_title', $pre, 5);
check(wp_get_document_title() === 'Z innej wtyczki - tytuł', 'pre_get_document_title result must lose the dash.');
remove_filter('pre_get_document_title', $pre, 5);
wp_reset_query();
dxsm_store_seo_fields($seo, array('_dxsm_seo_title' => '', '_dxsm_seo_description' => ''));
wp_reset_query();
check(dxsm_get_automatic_description($seo) === 'Opis domyślny.', 'Automatic description falls back to settings when the public page cannot be read.');
check(dxsm_get_social_image($seo) === false, 'No featured or default image means no social image.');
ob_start(); dxsm_render_seo_metabox(get_post($seo)); $box = ob_get_clean();
check(strpos($box, 'name="_dxsm_seo_title"') !== false && strpos($box, '_dxsm_seo_nonce') !== false, 'Editor metabox must render fields and nonce.');
$_GET = array('page' => 'dxsm-social-meta', 'dxsm_view' => 'google', 'dxsm_schema_post' => (string) $seo);
ob_start(); dxsm_render_social_meta_page(); $panel = ob_get_clean();
check(strpos($panel, 'Wybierz wpis lub stronę') !== false && strpos($panel, 'Psychoterapia') !== false && strpos($panel, 'Zapisz i przejdź do następnego') !== false, 'Content workspace must list content and editing actions.');
check(strpos($panel, 'Do uzupełnienia SEO') !== false, 'Workspace must show the incomplete SEO counter.');
$_GET = array('page' => 'dxsm-social-meta', 'dxsm_view' => 'settings');
ob_start(); dxsm_render_social_meta_page(); $panel = ob_get_clean();
check(strpos($panel, 'Udostępnianie linków') !== false && strpos($panel, 'Ustawienia edytora') !== false && strpos($panel, 'value="Centrum"') !== false, 'Settings view must hold both settings forms.');
$_GET = array();
$schema = dxsm_schema_preview_parse('<html><head><script type="application/ld+json">{"@type":"FAQPage"}</script><script type="application/ld+json">{"@type":"Organization"}</script></head></html>');
check(!is_wp_error($schema) && $schema['types'] === array('FAQPage', 'Organization'), 'Schema preview must read every JSON-LD block on the page.');
check(is_wp_error(dxsm_schema_preview_parse('<html><head></head></html>')), 'Missing JSON-LD must be reported.');
// Runda 4: strona wpisów (page_for_posts) dostaje tytuł i opis z panelu.
$front = wp_insert_post(array('post_title' => 'Start', 'post_status' => 'publish', 'post_type' => 'page'));
$blog = wp_insert_post(array('post_title' => 'Blog', 'post_status' => 'publish', 'post_type' => 'page'));
update_option('show_on_front', 'page'); update_option('page_on_front', $front); update_option('page_for_posts', $blog);
dxsm_store_seo_fields($blog, array('_dxsm_seo_title' => 'Blog o psychoterapii', '_dxsm_seo_description' => 'Opis bloga z panelu'));
query_posts(array('page_id' => $blog));
check(is_home() && !is_singular() && !is_front_page(), 'Fixture must be the posts page.');
ob_start(); do_action('wp_head'); $meta = metadata(ob_get_clean());
check($meta['description'] === array('Opis bloga z panelu') && strpos($meta['og:title'][0], 'Blog o psychoterapii') === 0 && $meta['og:url'] === array(get_permalink($blog)), 'Posts page must use panel title, description and its own URL.');
wp_reset_query();
update_option('show_on_front', 'posts'); update_option('page_on_front', 0); update_option('page_for_posts', 0);
wp_delete_post($front, true); wp_delete_post($blog, true);
wp_delete_post($seo, true);
wp_set_current_user(0);

// Okno „Zobacz szczegóły”.
$file = 'digital-xperts-social-meta/digital-xperts-social-meta.php';
$row = apply_filters('plugin_row_meta', array(), $file, array(), 'all');
check(count($row) === 1 && strpos($row[0], 'plugin=digital-xperts-social-meta') !== false, 'Plugin row must link to the details window.');
check(apply_filters('plugin_row_meta', array(), 'other/other.php', array(), 'all') === array(), 'Other plugins must stay untouched.');
// Bez sieci: okno pokazuje wersję zainstalowaną (wersję z GitHuba sprawdza unified.php).
set_site_transient('dxsm_update_release', array(), HOUR_IN_SECONDS);
$info = apply_filters('plugins_api', false, 'plugin_information', (object) array('slug' => 'digital-xperts-social-meta'));
check(is_object($info) && $info->version === DigitalXperts\SocialMeta\VERSION, 'Details window must report the version.');
foreach (array('description', 'installation', 'faq', 'Prywatność', 'changelog') as $section) {
    check(!empty($info->sections[$section]), 'Details window needs section ' . $section . '.');
}
check(strpos($info->sections['description'], '<h4>Co zyskujesz</h4><ul><li>') !== false, 'Readme headings and lists must render as HTML.');
check(apply_filters('plugins_api', false, 'plugin_information', (object) array('slug' => 'akismet')) === false, 'Other plugin info must pass through.');
// Wtyczka jak CMS Portal: na priorytecie 20 zwraca false dla każdego obcego sluga.
$greedy = function () { return false; };
add_filter('plugins_api', $greedy, 20, 3);
$info = apply_filters('plugins_api', false, 'plugin_information', (object) array('slug' => 'digital-xperts-social-meta'));
check(is_object($info) && $info->slug === 'digital-xperts-social-meta', 'Details window survives a later plugins_api filter returning false.');
remove_filter('plugins_api', $greedy, 20);
$data = get_file_data(WP_PLUGIN_DIR . '/' . $file, array('v' => 'Version', 'u' => 'Update URI', 'd' => 'Description'));
check($data['v'] === DigitalXperts\SocialMeta\VERSION && $data['u'] === 'https://d-x.pl/wtyczki/social-meta/' && mb_strlen($data['d']) < 140, 'Header: version matches constant, Update URI d-x.pl, description under 140 chars.');
echo 'PASS: ' . $GLOBALS['checks'] . " integration checks on real WordPress\n";
