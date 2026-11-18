<?php
// Wspólna wersja 2.x: migracja ze starszych wariantów, moduł pełnego SEO, aktualizator z GitHuba.
// Uruchamiać w jednorazowym WordPressie: wp eval-file /tests/unified.php
use DigitalXperts\SocialMeta\Plugin;
use DigitalXperts\SocialMeta\Updater;
use DigitalXperts\SocialMeta\FullSeo\SEO;

if (wp_get_environment_type() !== 'local') {
    throw new RuntimeException('Local fixture required.');
}
$GLOBALS['checks'] = 0;
function check($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
    $GLOBALS['checks']++;
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
function reset_migration() {
    foreach (array('dxsm_legacy_migrated', 'dxsm_settings', 'dxsm_modules', 'dxsm_editor_settings', 'dxsm_rs2_settings', 'dxsm_rs2_seo_settings', 'dxsm_centrum_settings', 'dxsmc_editor_settings') as $option) {
        delete_option($option);
    }
}
$admin = get_users(array('role' => 'administrator', 'number' => 1))[0];
wp_set_current_user($admin->ID);

// 1. Migracja z wariantu Centrum: ustawienia, ustawienia edytora i pola panelu.
reset_migration();
update_option('dxsm_centrum_settings', array('site_name' => 'Centrum', 'default_description' => 'Opis Centrum.'));
update_option('dxsmc_editor_settings', array('google_mode' => 'phone', 'show_social' => 0));
$page = wp_insert_post(array('post_title' => 'Kontakt', 'post_status' => 'publish', 'post_type' => 'page'));
add_post_meta($page, '_dxsmc_seo_title', 'Stary tytuł Centrum');
add_post_meta($page, '_dxsmc_seo_description', wp_slash('Stary opis "z cudzysłowem" \\ ukośnik'));
$other = wp_insert_post(array('post_title' => 'Inna', 'post_status' => 'publish', 'post_type' => 'page'));
add_post_meta($other, '_dxsmc_seo_title', 'Stary');
add_post_meta($other, '_dxsm_seo_title', 'Nowszy');
dxsm_migrate_legacy();
check(Plugin::settings()['site_name'] === 'Centrum' && Plugin::settings()['default_description'] === 'Opis Centrum.', 'Centrum settings are copied.');
check(get_option('dxsm_editor_settings') === array('google_mode' => 'phone', 'show_social' => 0), 'Centrum editor settings are copied.');
check(get_post_meta($page, '_dxsm_seo_title', true) === 'Stary tytuł Centrum' && get_post_meta($page, '_dxsm_seo_description', true) === 'Stary opis "z cudzysłowem" \\ ukośnik', 'Centrum panel fields are copied byte for byte.');
check(get_post_meta($other, '_dxsm_seo_title', true) === 'Nowszy', 'An existing unified field is never overwritten.');
check(get_post_meta($page, '_dxsmc_seo_title', true) === 'Stary tytuł Centrum', 'Legacy fields stay in place.');
check(!Plugin::full_seo() && get_option('dxsm_modules') === array('full_seo' => false), 'Centrum keeps the full SEO module off.');
update_option('dxsm_centrum_settings', array('site_name' => 'Zmienione'));
dxsm_migrate_legacy();
check(Plugin::settings()['site_name'] === 'Centrum', 'Migration runs once.');

// 2. Migracja z wariantu RS2: moduł włączony, dawne reguły mapy i noindex zapisane w bazie.
reset_migration();
update_option('dxsm_rs2_settings', array('site_name' => 'RS2', 'titles' => array($page => 'Tytuł z mapy RS2')));
dxsm_migrate_legacy();
$seo = get_option('dxsm_rs2_seo_settings');
check(Plugin::full_seo(), 'RS2 enables the full SEO module.');
check(is_array($seo) && in_array('cms-service', $seo['sitemap_post_types'], true) && in_array('cms-footer', $seo['noindex_post_types'], true) && $seo['service_post_types'] === array('cms-service'), 'RS2 defaults are persisted, with cms-service as Service.');
check(Plugin::settings()['titles'][$page] === 'Tytuł z mapy RS2', 'RS2 title map is preserved.');
reset_migration();
update_option('dxsm_rs2_seo_settings', array('sitemap_post_types' => array('page'), 'sitemap_taxonomies' => array(), 'noindex_post_types' => array(), 'noindex_taxonomies' => array(), 'business' => array('name' => 'RS2')));
dxsm_migrate_legacy();
$seo = get_option('dxsm_rs2_seo_settings');
check($seo['sitemap_post_types'] === array('page') && $seo['service_post_types'] === array('cms-service') && $seo['business']['name'] === 'RS2', 'Saved RS2 settings are kept and only gain the service type.');

// 2b. RS2 bez zapisanych opcji: rozpoznanie po samych polach SEO wariantu.
reset_migration();
add_post_meta($page, '_dxsm_rs2_canonical', home_url('/'));
dxsm_migrate_legacy();
check(Plugin::full_seo() && is_array(get_option('dxsm_rs2_seo_settings')) && in_array('cms-footer', get_option('dxsm_rs2_seo_settings')['noindex_post_types'], true), 'RS2 is detected from its post fields alone.');
delete_post_meta($page, '_dxsm_rs2_canonical');

// 3. Nowa strona: wszystko wyłączone, ustawienia ogólne.
reset_migration();
dxsm_migrate_legacy();
check(!Plugin::full_seo() && get_option('dxsm_settings', null) === null, 'A fresh site starts with the module off and no copied settings.');
$generic = SEO::sanitize_settings(array());
check($generic['sitemap_post_types'] === array('post', 'page', 'product') && $generic['noindex_post_types'] === array() && $generic['service_post_types'] === array(), 'Module defaults are generic.');

// 4. Moduł pełnego SEO: tytuł z pola modułu, pierwszeństwo panelu, noindex, canonical.
update_option('dxsm_modules', array('full_seo' => true));
SEO::boot();
update_post_meta($page, '_dxsm_rs2_title', 'Tytuł z modułu');
delete_post_meta($page, '_dxsm_seo_title');
query_posts(array('page_id' => $page));
$GLOBALS['wp_query']->is_singular = $GLOBALS['wp_query']->is_page = true;
check(wp_get_document_title() === 'Tytuł z modułu', 'Module title is used, got: ' . wp_get_document_title());
update_post_meta($page, '_dxsm_seo_title', 'Tytuł z panelu & znak');
check(wp_get_document_title() === 'Tytuł z panelu &amp; znak', 'Panel title wins over the module, escaped, got: ' . wp_get_document_title());
// Oba tryby osobno: bez modułu jego hook pre_get_document_title musi być zdjęty, nie tylko wyłączona opcja.
foreach (array('Oferta &copy;' => 'Oferta &amp;copy;', 'Stary &#8211; tytuł' => 'Stary - tytuł') as $stored => $expected) {
    update_post_meta($page, '_dxsm_seo_title', $stored);
    $with_module = wp_get_document_title();
    remove_filter('pre_get_document_title', array(SEO::class, 'title'), 20);
    $without_module = wp_get_document_title();
    add_filter('pre_get_document_title', array(SEO::class, 'title'), 20);
    check($with_module === $without_module && $with_module === $expected, 'Panel title is the same with and without the module, got: ' . $with_module . ' / ' . $without_module);
}
// Wygenerowane karty pokazują ten sam dosłowny tekst co <title>.
update_post_meta($page, '_dxsm_seo_title', 'Oferta &copy;');
ob_start(); do_action('wp_head'); $cards = metadata(ob_get_clean());
check(($cards['og:title'] ?? null) === array('Oferta &copy;') && ($cards['twitter:title'] ?? null) === array('Oferta &copy;'), 'Generated cards keep a literal entity, got: ' . json_encode($cards['og:title'] ?? null));
update_post_meta($page, '_dxsm_seo_title', 'Tytuł z panelu & znak');
$_GET['dxsm_auto'] = '1';
check(wp_get_document_title() === 'Tytuł z modułu', 'Automatic request shows the module title.');
unset($_GET['dxsm_auto']);
update_post_meta($page, '_dxsm_rs2_noindex', '1');
$robots = apply_filters('wp_robots', array('index' => true, 'follow' => true));
check(!empty($robots['noindex']) && !isset($robots['index']), 'Module noindex field adds noindex.');
update_post_meta($page, '_dxsm_rs2_canonical', home_url('/inny/'));
check(apply_filters('get_canonical_url', get_permalink($page), get_post($page)) === home_url('/inny/'), 'Module canonical field is used.');
check(apply_filters('wp_sitemaps_add_provider', 'provider', 'users') === false, 'Users sitemap is disabled by the module.');
wp_reset_query();
$_POST = array('dxsm_rs2_seo_nonce' => wp_create_nonce('dxsm_rs2_save_seo'), 'dxsm_rs2_seo' => array('title' => 'Z ramki', 'description' => 'Z ramki', 'canonical' => '', 'noindex' => '0'));
SEO::save_meta($page);
$_POST = array();
check(get_post_meta($page, '_dxsm_rs2_title', true) === 'Tytuł z modułu' && get_post_meta($page, '_dxsm_rs2_noindex', true) === '0' && get_post_meta($page, '_dxsm_rs2_canonical', true) === '', 'Index box saves only canonical, noindex and nofollow.');
// Ustawienia z włączonym modułem renderują się bez błędu klasy, z polami mapy i panelem importu SEOPress.
ob_start(); Plugin::render_share_settings(); $settings_html = ob_get_clean();
check(strpos($settings_html, 'Typy treści w mapie') !== false && strpos($settings_html, 'Typy treści opisane jako usługa') !== false && strpos($settings_html, 'Migracja danych SEOPress') !== false, 'Settings render module fields and the SEOPress import panel.');
// Panel SEO obsługuje typy treści z mapy modułu (usługi, produkty), także na liście i w ramce edycji.
register_post_type('cms-service', array('public' => true, 'label' => 'Usługi', 'labels' => array('singular_name' => 'Usługa')));
$seo_settings = get_option('dxsm_rs2_seo_settings', array());
$seo_settings['sitemap_post_types'] = array('post', 'page', 'cms-service');
update_option('dxsm_rs2_seo_settings', $seo_settings, false);
$service = wp_insert_post(array('post_title' => 'Kontenery', 'post_status' => 'publish', 'post_type' => 'cms-service'));
check(in_array('cms-service', dxsm_seo_post_types(), true) && dxsm_seo_editor_supports('cms-service') && !dxsm_seo_editor_supports('attachment'), 'Module post types join the SEO panel.');
check(dxsm_store_seo_fields($service, array('_dxsm_seo_title' => 'Kontenery na gruz', '_dxsm_seo_description' => 'Opis usługi')) && dxsm_get_seo_override($service, '_dxsm_seo_title') === 'Kontenery na gruz', 'Service SEO fields can be saved from the panel.');
check(isset(dxsm_admin_type_choices()['cms-service']), 'Panel type filter lists services.');
check(!is_wp_error(dxsm_schema_preview_selection($service)), 'Structured data preview accepts services.');
add_post_meta($service, '_dxsm_rs2_title', 'Kontenery RS2 z modułu');
delete_post_meta($service, '_dxsm_seo_title');
check(dxsm_get_editor_automatic_title(get_post($service), false) === 'Kontenery RS2 z modułu', 'Panel automatic title matches the module title, got: ' . dxsm_get_editor_automatic_title(get_post($service), false));
// Typ dodany filtrem przy wyłączonym module: tytuł z panelu trafia do publicznego <title>.
update_option('dxsm_modules', array('full_seo' => false));
$extra = function ($types) { $types[] = 'cms-service'; return $types; };
add_filter('dxsm_seo_post_types', $extra);
update_post_meta($service, '_dxsm_seo_title', 'Tytuł usługi z panelu');
query_posts(array('p' => $service, 'post_type' => 'cms-service'));
check(wp_get_document_title() === 'Tytuł usługi z panelu', 'Filtered post type uses the panel title publicly, got: ' . wp_get_document_title());
wp_reset_query();
remove_filter('dxsm_seo_post_types', $extra);
update_option('dxsm_modules', array('full_seo' => true));
// Opis awaryjny (bez odczytu strony) pomija pole panelu także przy pełnym SEO.
add_post_meta($service, '_dxsm_rs2_description', 'Automatyczny');
update_post_meta($service, '_dxsm_seo_description', 'Własny');
check(Plugin::fallback_description(get_post($service)) === 'Automatyczny', 'Fallback description skips the panel field, got: ' . Plugin::fallback_description(get_post($service)));
// Obraz z filtra dxsm_social_image trafia do publicznych kart (adres z tej strony, walidowany).
$custom = function ($default, $post_id) { return array(0, array(home_url('/wp-content/uploads/karta-filtr.png'), 1200, 630), 'Z filtra'); };
add_filter('dxsm_social_image', $custom, 10, 2);
query_posts(array('page_id' => $page));
$GLOBALS['wp_query']->is_singular = $GLOBALS['wp_query']->is_page = true;
$ctx = Plugin::context();
check($ctx['image']['url'] === home_url('/wp-content/uploads/karta-filtr.png') && $ctx['image']['type'] === 'image/png' && $ctx['image']['alt'] === 'Z filtra', 'Filtered image reaches public cards.');
remove_filter('dxsm_social_image', $custom, 10);
$foreign = function () { return array(0, array('https://obca-domena.test/x.png', 1200, 630), 'Obca'); };
add_filter('dxsm_social_image', $foreign);
$ctx = Plugin::context();
check(empty($ctx['image']['url']) || $ctx['image']['url'] !== 'https://obca-domena.test/x.png', 'Foreign image from the filter is rejected.');
remove_filter('dxsm_social_image', $foreign);
wp_reset_query();
update_option('dxsm_modules', array('full_seo' => false));
check(!in_array('cms-service', dxsm_seo_post_types(), true), 'Without the module the panel keeps posts and pages only.');
update_option('dxsm_modules', array('full_seo' => true));
wp_delete_post($service, true);

// 4a. Article we wpisie przy pełnym SEO: headline z tym samym dosłownym tekstem co <title>.
$seo_settings = get_option('dxsm_rs2_seo_settings', array());
$seo_settings['business'] = array('name' => 'Firma testowa');
update_option('dxsm_rs2_seo_settings', $seo_settings, false);
$article = wp_insert_post(array('post_title' => 'Artykuł', 'post_content' => '<p>Treść.</p>', 'post_status' => 'publish', 'post_type' => 'post'));
update_post_meta($article, '_dxsm_seo_title', 'Oferta &copy;');
query_posts(array('p' => $article));
ob_start(); do_action('wp_head'); $article_head = ob_get_clean();
preg_match('~<script type="application/ld\+json" id="dxsm-schema">(.*?)</script>~s', $article_head, $schema_match);
$graph = isset($schema_match[1]) ? json_decode($schema_match[1], true) : null;
$headline = '';
foreach ((array) ($graph['@graph'] ?? array()) as $node) { if (($node['@type'] ?? '') === 'Article') { $headline = $node['headline']; } }
check($headline === 'Oferta &copy;', 'Article headline keeps a literal entity, got: ' . $headline);
wp_reset_query();
wp_delete_post($article, true);

// 4a2. Spójne kodowanie: nazwa witryny z &, dosłowna encja w ustawieniach i w opisie automatycznym.
$old_name = get_option('blogname');
update_option('blogname', 'A & B');
$plain = wp_insert_post(array('post_title' => 'Encje', 'post_status' => 'publish', 'post_type' => 'page', 'post_excerpt' => 'Oferta &copy; opis'));
query_posts(array('page_id' => $plain));
$GLOBALS['wp_query']->is_singular = $GLOBALS['wp_query']->is_page = true;
$saved_settings = get_option('dxsm_settings', null);
delete_option('dxsm_settings');
ob_start(); do_action('wp_head'); $site_meta = metadata(ob_get_clean());
check(($site_meta['og:site_name'] ?? null) === array('A & B'), 'Site name with & is encoded once, got: ' . json_encode($site_meta['og:site_name'] ?? null));
wp_reset_query();
update_option('dxsm_settings', array('site_name' => 'Oferta &copy;'));
ob_start(); Plugin::render_share_settings(); $form = ob_get_clean();
check(strpos($form, 'value="Oferta &amp;copy;"') !== false, 'Settings form keeps a literal entity in the field.');
$preview = dxsm_get_seo_preview_data(get_post($plain));
check($preview['automatic_description'] === 'Oferta &copy; opis', 'Automatic description keeps a literal entity, got: ' . $preview['automatic_description']);
if ($saved_settings === null) { delete_option('dxsm_settings'); } else { update_option('dxsm_settings', $saved_settings); }
update_option('blogname', $old_name);
wp_delete_post($plain, true);

// 4b. Strona główna z najnowszymi wpisami, druga strona wyników: og:url z paginacją.
update_option('show_on_front', 'posts');
query_posts(array('paged' => 2));
$GLOBALS['wp_query']->is_home = true;
set_query_var('paged', 2);
check(Plugin::context_url(null) === add_query_arg('paged', 2, home_url('/')), 'Front page pagination reaches og:url, got: ' . Plugin::context_url(null));
wp_reset_query();

// 5. Aktualizator: wersja z przekierowania releases/latest, paczka o stałej nazwie, okno szczegółów.
delete_site_transient(Updater::CACHE);
$fake = function ($pre, $args, $url) {
    if ($url === 'https://github.com/DariuszTrachimowicz/digital-xperts-social-meta/releases/latest') {
        return array('headers' => array('location' => 'https://github.com/DariuszTrachimowicz/digital-xperts-social-meta/releases/tag/v9.8.7'), 'body' => '', 'response' => array('code' => 302, 'message' => 'Found'), 'cookies' => array(), 'filename' => null);
    }
    return $pre;
};
add_filter('pre_http_request', $fake, 10, 3);
$update = apply_filters('update_plugins_d-x.pl', false, array(), 'digital-xperts-social-meta/digital-xperts-social-meta.php');
check(is_array($update) && $update['version'] === '9.8.7' && $update['package'] === 'https://github.com/DariuszTrachimowicz/digital-xperts-social-meta/releases/download/v9.8.7/digital-xperts-social-meta-9.8.7.zip', 'Update offer comes from the latest GitHub release.');
check(apply_filters('update_plugins_d-x.pl', false, array(), 'ai-site-manager/ai-site-manager.php') === false, 'Other d-x.pl plugins are not answered.');
$info = apply_filters('plugins_api', false, 'plugin_information', (object) array('slug' => 'digital-xperts-social-meta'));
check(is_object($info) && $info->version === '9.8.7' && strpos($info->download_link, 'v9.8.7') !== false && !empty($info->sections['description']), 'Details window shows the release and readme.');
// Lista aktualizacji nieodświeżana od miesięcy (blokada zapytań do wordpress.org): wydanie i tak się pojawia.
$stale = (object) array('last_checked' => 1787126880, 'response' => array(), 'no_update' => array(), 'checked' => array());
set_site_transient('update_plugins', $stale);
$listed = get_site_transient('update_plugins');
$file = 'digital-xperts-social-meta/digital-xperts-social-meta.php';
check(isset($listed->response[$file]) && $listed->response[$file]->new_version === '9.8.7' && strpos($listed->response[$file]->package, 'v9.8.7') !== false, 'Release is injected into a stale update list.');
delete_site_transient('update_plugins');
// Na froncie bez zapamiętanego wydania nie ma zapytania do GitHuba.
delete_site_transient(Updater::CACHE);
$requests = 0;
$count = function ($pre) use (&$requests) { $requests++; return $pre; };
add_filter('pre_http_request', $count, 1);
set_site_transient('update_plugins', $stale);
$listed = get_site_transient('update_plugins');
check($requests === 0 && !isset($listed->response[$file]), 'Front-end read of the update list makes no GitHub request.');
remove_filter('pre_http_request', $count, 1);
delete_site_transient('update_plugins');
Updater::release(true);
$core_link = '<a href="plugin-install.php?tab=plugin-information&plugin=digital-xperts-social-meta&section=changelog">Zobacz szczegóły</a>';
check(apply_filters('plugin_row_meta', array($core_link), 'digital-xperts-social-meta/digital-xperts-social-meta.php', array(), 'all') === array($core_link), 'No duplicate details link when WordPress already added one.');
remove_filter('pre_http_request', $fake, 10);
delete_site_transient(Updater::CACHE);
$broken = function () { return new WP_Error('offline', 'offline'); };
add_filter('pre_http_request', $broken);
check(apply_filters('update_plugins_d-x.pl', false, array(), 'digital-xperts-social-meta/digital-xperts-social-meta.php') === false, 'No update offer when GitHub is unreachable.');
remove_filter('pre_http_request', $broken);
delete_site_transient(Updater::CACHE);

wp_delete_post($page, true);
wp_delete_post($other, true);
reset_migration();
update_option('dxsm_legacy_migrated', DXSM_LEGACY_VERSION);
echo 'PASS: ' . $GLOBALS['checks'] . " unified checks on real WordPress\n";
