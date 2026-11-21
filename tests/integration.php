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
check(dxsm_store_seo_fields($seo, array('_dxsm_seo_keyphrase' => ' usługi "księgowe" <b>x</b> ')) && get_post_meta($seo, '_dxsm_seo_keyphrase', true) === 'usługi "księgowe" x', 'Keyphrase must be stored as plain text.');
ob_start(); dxsm_render_seo_metabox(get_post($seo)); $box = ob_get_clean();
check(strpos($box, 'name="_dxsm_seo_keyphrase"') !== false && strpos($box, 'value="usługi &quot;księgowe&quot; x"') !== false, 'Editor metabox must render the saved keyphrase.');
check(strpos($box, 'data-slug') === false && strpos($box, esc_textarea(get_post($seo)->post_content) . '</textarea>') !== false, 'Plain permalinks have no post name in the address, so the address check is skipped.');
$plain_structure = get_option('permalink_structure');
$GLOBALS['wp_rewrite']->set_permalink_structure('/%postname%/');
ob_start(); dxsm_render_seo_metabox(get_post($seo)); $pretty_box = ob_get_clean();
$GLOBALS['wp_rewrite']->set_permalink_structure($plain_structure);
check(strpos($pretty_box, 'data-slug="' . get_post($seo)->post_name . '"') !== false, 'Keyphrase checks need the saved address.');
$_GET = array('page' => 'dxsm-social-meta', 'dxsm_view' => 'google', 'dxsm_schema_post' => (string) $seo);
ob_start(); dxsm_render_social_meta_page(); $panel = ob_get_clean();
check(strpos($panel, 'name="_dxsm_seo_keyphrase"') !== false && strpos($panel, 'data-seo-keyphrase-checks') !== false, 'Content workspace must offer the keyphrase field.');
update_option('show_on_front', 'page'); update_option('page_on_front', $seo);
ob_start(); dxsm_render_seo_metabox(get_post($seo)); $front_box = ob_get_clean();
update_option('show_on_front', 'posts'); delete_option('page_on_front');
check(strpos($front_box, 'data-seo-content data-url="' . esc_url(home_url('/')) . '" data-post-title="Psychoterapia">') !== false && strpos($front_box, 'data-slug') === false, 'Front page must skip the address check.');
$linked = wp_insert_post(array('post_title' => 'Terapia par &amp; rodzin', 'post_content' => '<p>Treść.</p>', 'post_status' => 'publish', 'post_type' => 'page'));
$silent = wp_insert_post(array('post_title' => 'Bez frazy', 'post_content' => '<p>Treść.</p>', 'post_status' => 'publish', 'post_type' => 'page'));
$draft = wp_insert_post(array('post_title' => 'Szkic', 'post_content' => '<p>Treść.</p>', 'post_status' => 'draft', 'post_type' => 'page'));
update_post_meta($linked, '_dxsm_seo_keyphrase', 'terapia </script> par');
update_post_meta($draft, '_dxsm_seo_keyphrase', 'szkic');
$candidates = dxsm_link_candidates($seo);
check(count($candidates) === 1 && $candidates[0]['title'] === 'Terapia par & rodzin' && $candidates[0]['url'] === get_permalink($linked) && $candidates[0]['phrase'] === 'terapia </script> par', 'Link candidates are other published content with a keyphrase.');
ob_start(); dxsm_render_seo_metabox(get_post($seo)); $links_box = ob_get_clean();
check(strpos($links_box, 'data-seo-section="reading"') !== false && strpos($links_box, 'data-seo-readability') !== false && strpos($links_box, 'data-seo-links') !== false && strpos($links_box, 'data-seo-pill="seo"') !== false && strpos($links_box, 'data-seo-meter="title"') !== false && strpos($links_box, 'data-seo-link-candidates>[{') !== false && substr_count($links_box, '</script>') === 1 && strpos($links_box, 'terapia \\u003C\\/script\\u003E par') !== false, 'Link candidates must be embedded as safe JSON.');
register_post_type('dxsm_internal', array('public' => false, 'label' => 'Internal'));
$internal = wp_insert_post(array('post_title' => 'Wewnętrzna notatka', 'post_status' => 'publish', 'post_type' => 'dxsm_internal'));
update_post_meta($internal, '_dxsm_seo_keyphrase', 'notatka');
$add_internal = function ($types) { $types[] = 'dxsm_internal'; return $types; };
add_filter('dxsm_seo_post_types', $add_internal);
check(count(dxsm_link_candidates($seo)) === 1, 'Content of a non-public post type must not become a link candidate.');
remove_filter('dxsm_seo_post_types', $add_internal);
wp_delete_post($internal, true); unregister_post_type('dxsm_internal');
wp_delete_post($linked, true); wp_delete_post($silent, true); wp_delete_post($draft, true);
dxsm_store_seo_fields($seo, array('_dxsm_seo_keyphrase' => ''));
check(!metadata_exists('post', $seo, '_dxsm_seo_keyphrase'), 'Empty keyphrase must remove the field.');
// Oceny: zapis razem z polami, nieznana wartość kasuje ocenę, kokpit liczy stany i braki.
$before_counts = dxsm_dashboard_counts();
check(dxsm_score($seo, 'seo') === 'none' && dxsm_score($seo, 'readability') === 'none', 'Content without analysis has no score.');
dxsm_store_seo_fields($seo, array('_dxsm_score_seo' => 'bad', '_dxsm_score_readability' => 'ok'));
check(dxsm_score($seo, 'seo') === 'bad' && dxsm_score($seo, 'readability') === 'ok', 'Scores must be stored with the fields.');
$after_counts = dxsm_dashboard_counts();
check($after_counts['seo']['bad'] === $before_counts['seo']['bad'] + 1 && $after_counts['seo']['none'] === $before_counts['seo']['none'] - 1 && $after_counts['readability']['ok'] === $before_counts['readability']['ok'] + 1 && $after_counts['total'] === $before_counts['total'], 'Dashboard must count the new scores.');
check($after_counts['missing']['keyphrase'] >= 1 && array_sum($after_counts['seo']) === $after_counts['total'], 'Dashboard totals must add up and count missing keyphrases.');
dxsm_store_seo_fields($seo, array('_dxsm_score_seo' => '<script>', '_dxsm_score_readability' => 'none'));
check(!metadata_exists('post', $seo, '_dxsm_score_seo') && !metadata_exists('post', $seo, '_dxsm_score_readability'), 'Unknown score values must clear the score.');
check(strpos(dxsm_score_dot('ok', 'SEO'), 'data-rating="ok"') !== false && strpos(dxsm_score_dot('ok', 'SEO'), 'SEO: Do poprawy') !== false, 'Score marker must carry the rating as text.');
$_GET = array('page' => 'dxsm-social-meta');
ob_start(); dxsm_render_social_meta_page(); $dash = ob_get_clean();
check(strpos($dash, 'Oceny SEO') !== false && strpos($dash, 'data-dxsm-recount') !== false && strpos($dash, 'Wybierz wpis lub stronę') === false && strpos($dash, '>Kokpit</a>') !== false, 'The plugin page must open on the dashboard.');
dxsm_store_seo_fields($seo, array('_dxsm_score_seo' => 'good'));
$_GET = array('page' => 'dxsm-social-meta', 'dxsm_view' => 'google', 'dxsm_score' => 'seo-good');
$score_filters = dxsm_admin_picker_filters();
check($score_filters['score'] === 'seo-good' && dxsm_admin_picker_ids($score_filters) === array((int) $seo), 'Content list must filter by score.');
$_GET['dxsm_score'] = 'seo-none'; $none_filters = dxsm_admin_picker_filters();
check(!in_array((int) $seo, dxsm_admin_picker_ids($none_filters), true), 'Rated content must leave the unrated list.');
$_GET['dxsm_score'] = 'zly'; check(dxsm_admin_picker_filters()['score'] === '', 'Unknown score filter must be ignored.');
$_GET = array('page' => 'dxsm-social-meta', 'dxsm_view' => 'google', 'dxsm_seo_filter' => 'keyphrase');
check(in_array((int) $seo, dxsm_admin_picker_ids(dxsm_admin_picker_filters()), true), 'Content without a keyphrase must match the keyphrase filter.');
dxsm_store_seo_fields($seo, array('_dxsm_score_seo' => ''));
$_GET = array();
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
// install_plugin_information() ostrzega, gdy pełna wersja WordPressa jest nowsza niż "tested" (stan 7.1.5).
check(version_compare(get_bloginfo('version'), $info->tested, '<=') && version_compare('7.1.5', $info->tested, '<='), 'Details window must not warn about an untested WordPress version.');
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
