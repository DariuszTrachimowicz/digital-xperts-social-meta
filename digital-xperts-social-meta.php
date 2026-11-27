<?php
/**
 * Plugin Name: Digital Xperts Social Meta
 * Plugin URI: https://github.com/DariuszTrachimowicz/digital-xperts-social-meta
 * Description: Tytuły i opisy SEO z podglądem Google, znaczniki Open Graph i karty X oraz opcjonalne pełne SEO dla każdej strony WordPress.
 * Version: 2.6.1
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: Dariusz Trachimowicz
 * Author URI: https://d-x.pl/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: digital-xperts-social-meta
 * Update URI: https://d-x.pl/wtyczki/social-meta/
 */

// Wspólna wersja dla wszystkich stron: rdzeń z wariantów RS2 i Centrum Psychologii, panel SEO z d-x.pl,
// pełne SEO z wariantu RS2 jako moduł. Aktualizacje z wydań GitHuba (includes/updater.php).

namespace DigitalXperts\SocialMeta;

defined('ABSPATH') || exit;

const VERSION = '2.6.1';

// Panel SEO jest kodem proceduralnym i oczekuje tych stałych w globalnej przestrzeni nazw.
\define('DXSM_VERSION', VERSION);
\define('DXSM_PLUGIN_FILE', __FILE__);

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/legacy.php';
require_once __DIR__ . '/includes/seo-editor.php';
require_once __DIR__ . '/includes/updater.php';
require_once __DIR__ . '/includes/yoast-import.php';
require_once __DIR__ . '/includes/redirects.php';
require_once __DIR__ . '/modules/full-seo/migration.php';
require_once __DIR__ . '/modules/full-seo/seo.php';
require_once __DIR__ . '/modules/full-seo/schema.php';

final class Plugin {
    const OPTION = 'dxsm_settings';
    const MODULES = 'dxsm_modules';
    const SLUG = 'digital-xperts-social-meta';
    private static $buffer_level = null;

    public static function boot() {
        // Przed sprawdzeniem modułów: pierwsze żądanie po zamianie wariantu RS2 musi mieć już reguły SEO.
        \dxsm_migrate_legacy();
        add_action('wp_head', array(__CLASS__, 'start_head'), -PHP_INT_MAX);
        add_action('wp_head', array(__CLASS__, 'finish_head'), PHP_INT_MAX);
        add_action('admin_init', array(__CLASS__, 'register_settings'));
        add_filter('plugin_row_meta', array(__CLASS__, 'row_meta'), 10, 2);
        Updater::hooks();
        YoastImport::boot();
        Redirects::boot();
        if (self::full_seo()) {
            FullSeo\SEO::boot();
            FullSeo\Migration::boot();
        }
    }

    // Moduł pełnego SEO (mapa witryny, robots, canonical, dane firmy, import SEOPress). Domyślnie wyłączony.
    public static function full_seo() {
        $modules = get_option(self::MODULES, array());
        return is_array($modules) && !empty($modules['full_seo']);
    }

    public static function settings() {
        return self::sanitize_settings(get_option(self::OPTION, array()));
    }

    // Opis bez własnego pola SEO (panel pominięty): opis ze starych ustawień strony, pole modułu SEO, zajawka, opis domyślny, opis witryny.
    public static function fallback_description($post) {
        $settings = self::settings();
        $description = '';
        if ($post instanceof \WP_Post) {
            $description = isset($settings['descriptions'][$post->ID]) ? $settings['descriptions'][$post->ID] : '';
            if (self::full_seo()) {
                $description = FullSeo\SEO::description($post, $description, true);
            }
            if ($description === '' && $post->post_excerpt !== '') {
                $description = self::text($post->post_excerpt);
            }
        }
        return $description !== '' ? $description : ($settings['default_description'] ?: self::site_text('description'));
    }

    private static function text($value) {
        return is_scalar($value) ? sanitize_text_field((string) $value) : '';
    }

    // WordPress zapisuje nazwę i opis witryny przez esc_html(); kontekst trzyma zwykły tekst, więc dekodujemy raz.
    public static function site_text($field) {
        return self::text(html_entity_decode((string) get_bloginfo($field, 'raw'), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    // Przed WordPressem 6.9 set_attribute nie kodował ponownie istniejących encji: podajemy wtedy tekst już zakodowany.
    private static function set_text_attribute($processor, $name, $value) {
        $value = (string) $value;
        if (version_compare((string) get_bloginfo('version'), '6.9', '<')) {
            $value = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true);
        }
        $processor->set_attribute($name, $value);
    }

    public static function local_image_url($url) {
        $url = is_string($url) ? esc_url_raw($url) : '';
        $parts = wp_parse_url($url);
        $home = wp_parse_url(home_url('/'));
        if (!$parts || !$home || empty($parts['host']) || empty($parts['scheme'])
            || strcasecmp($parts['host'], $home['host']) !== 0
            || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) ? $parts['port'] : null) !== (isset($home['port']) ? $home['port'] : null)
            || !in_array(strtolower($parts['scheme']), array('https', 'http'), true)
            || ($parts['scheme'] !== 'https' && !in_array(wp_get_environment_type(), array('local', 'development'), true))) {
            return '';
        }
        $ext = strtolower(pathinfo(isset($parts['path']) ? $parts['path'] : '', PATHINFO_EXTENSION));
        return in_array($ext, array('png', 'jpg', 'jpeg', 'webp'), true) ? $url : '';
    }

    public static function sanitize_settings($input) {
        $input = is_array($input) ? $input : array();
        $old = get_option(self::OPTION, array());
        $old = is_array($old) ? $old : array();
        $out = array();
        foreach (array('site_name', 'default_description', 'default_image_alt') as $field) {
            $out[$field] = self::text(isset($input[$field]) ? $input[$field] : '');
        }
        // Numer aplikacji z Meta for Developers (same cyfry); Facebook pokazuje bez niego ostrzeżenie w debugerze.
        $app_id = isset($input['fb_app_id']) && is_scalar($input['fb_app_id']) ? trim((string) $input['fb_app_id']) : '';
        $out['fb_app_id'] = preg_match('/^\d{5,20}$/D', $app_id) === 1 ? $app_id : '';
        $out['default_image_url'] = self::local_image_url(isset($input['default_image_url']) ? $input['default_image_url'] : '');
        foreach (array('default_image_width', 'default_image_height') as $field) {
            $out[$field] = min(10000, max(0, isset($input[$field]) && is_scalar($input[$field]) ? (int) $input[$field] : 0));
        }
        // Mapy tytułów i opisów ze starszych wariantów zostają po zapisie formularza ustawień.
        foreach (array('descriptions', 'titles') as $key) {
            $values = isset($input[$key]) ? $input[$key] : (isset($old[$key]) ? $old[$key] : array());
            $out[$key] = array();
            foreach (is_array($values) ? $values : array() as $id => $value) {
                if (ctype_digit((string) $id) && (int) $id > 0) {
                    $out[$key][(int) $id] = self::text($value);
                }
            }
        }
        return $out;
    }

    private static function mime_from_url($url) {
        $types = array('png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp');
        $extension = strtolower(pathinfo((string) wp_parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        return isset($types[$extension]) ? $types[$extension] : '';
    }

    public static function sanitize_modules($input) {
        return array('full_seo' => is_array($input) && !empty($input['full_seo']));
    }

    // Wpis lub strona dla bieżącego widoku, także strona wpisów i strona sklepu WooCommerce.
    public static function queried_post() {
        $post = get_queried_object();
        if ($post instanceof \WP_Post) {
            return $post;
        }
        if (is_home() && (int) get_option('page_for_posts') > 0) {
            return get_post((int) get_option('page_for_posts'));
        }
        if (function_exists('is_post_type_archive') && is_post_type_archive('product') && (int) get_option('woocommerce_shop_page_id') > 0) {
            return get_post((int) get_option('woocommerce_shop_page_id'));
        }
        return $post;
    }

    public static function context_url($post) {
        if (is_front_page()) {
            $url = home_url('/');
        } elseif ($post instanceof \WP_Term) {
            $url = get_term_link($post);
            $url = is_wp_error($url) ? home_url('/') : $url;
        } else {
            $url = $post instanceof \WP_Post ? get_permalink($post) : home_url('/');
        }
        $page = (int) get_query_var('paged');
        if (!is_singular() && $page > 1) {
            global $wp_rewrite;
            $url = get_option('permalink_structure') ? user_trailingslashit(trailingslashit($url) . $wp_rewrite->pagination_base . '/' . $page, 'paged') : add_query_arg('paged', $page, $url);
        }
        return $url;
    }

    public static function context() {
        if (is_admin() || is_feed() || is_404() || is_search() || is_preview()
            || (!is_singular() && !is_front_page() && !is_home() && !is_post_type_archive('product') && !is_category() && !is_tag() && !is_tax())) {
            return null;
        }
        $post = self::queried_post();
        if ($post instanceof \WP_Post && ($post->post_status !== 'publish' || !is_post_publicly_viewable($post) || $post->post_password !== '')) {
            return null;
        }
        $settings = self::settings();
        // Opis wpisany w panelu SEO zastępuje opis z innych źródeł (np. mu-plugin, motyw, inna wtyczka).
        $override = $post instanceof \WP_Post && !\dxsm_is_automatic_request() ? \dxsm_get_seo_override($post->ID, '_dxsm_seo_description') : '';
        $description = $override !== '' ? $override : self::fallback_description($post instanceof \WP_Post ? $post : null);
        $image = array();
        if ($post instanceof \WP_Post) {
            // Ten sam wybór co w podglądzie panelu: obraz wyróżniający albo obraz z filtra dxsm_social_image.
            $social = \dxsm_get_social_image($post->ID);
            if (is_array($social) && isset($social[1][0]) && self::local_image_url($social[1][0])) {
                $id = (int) $social[0];
                $image = array('url' => $social[1][0], 'width' => (int) $social[1][1], 'height' => (int) $social[1][2],
                    'type' => $id > 0 ? get_post_mime_type($id) : self::mime_from_url($social[1][0]), 'alt' => self::text(isset($social[2]) ? $social[2] : ''));
            }
        }
        if (!$image && $settings['default_image_url']) {
            $extension = strtolower(pathinfo(wp_parse_url($settings['default_image_url'], PHP_URL_PATH), PATHINFO_EXTENSION));
            $types = array('png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp');
            $image = array('url' => $settings['default_image_url'], 'width' => $settings['default_image_width'],
                'height' => $settings['default_image_height'], 'type' => $types[$extension], 'alt' => $settings['default_image_alt']);
        }
        return array('title' => html_entity_decode(wp_get_document_title(), ENT_QUOTES | ENT_HTML5, get_bloginfo('charset')),
            'description' => $description, 'description_override' => $override !== '', 'site_name' => $settings['site_name'] ?: self::site_text('name'),
            'url' => self::context_url($post), 'locale' => get_locale(),
            'type' => is_singular('post') ? 'article' : 'website', 'image' => $image);
    }

    public static function start_head() {
        if (self::$buffer_level !== null || self::context() === null) {
            return;
        }
        ob_start();
        self::$buffer_level = ob_get_level();
    }

    public static function finish_head() {
        if (self::$buffer_level === null) {
            return;
        }
        // Never close a buffer owned by another component.
        if (ob_get_level() !== self::$buffer_level) {
            self::$buffer_level = null;
            return;
        }
        $head = ob_get_clean();
        self::$buffer_level = null;
        $context = self::context();
        if ($context === null) {
            echo $head; // Existing HTML is deliberately preserved.
            return;
        }
        $head = self::complete_head($head, $context);
        echo self::full_seo() ? FullSeo\Schema::complete_head($head, $context) : $head;
    }

    public static function complete_head($head, $context) {
        // Tytuł wypisany przez inny generator (np. Yoast) też traci półpauzy; set_modifiable_text jest od WordPressa 6.7.
        $titles = new \WP_HTML_Tag_Processor($head);
        if (method_exists($titles, 'set_modifiable_text')) {
            while ($titles->next_tag('TITLE')) {
                $text = $titles->get_modifiable_text();
                if (\dxsm_without_dashes($text) !== $text) {
                    $titles->set_modifiable_text(\dxsm_without_dashes($text));
                }
            }
            $head = $titles->get_updated_html();
        }
        $override = !empty($context['description_override']);
        // Własny opis z panelu SEO: pierwszy opis z innych źródeł dostaje nową treść, kolejne znikają.
        // Tekst opisów, tytułów i kart z innych źródeł traci półpauzy i pauzy (adresy zostają).
        // Prawdziwe znaczniki rozpoznaje parser w całym nagłówku (pomija treść <script>); nadmiarowe
        // dostają losowy znacznik i tylko tak oznaczone usuwa wyrażenie regularne.
        $token = wp_generate_password(16, false, false);
        $tags = new \WP_HTML_Tag_Processor($head);
        $first = true;
        $drop = false;
        while ($tags->next_tag('META')) {
            $name = $tags->get_attribute('name');
            $key = is_string($name) ? $name : $tags->get_attribute('property');
            $is_description = is_string($name) && strtolower(trim($name)) === 'description';
            if ($is_description && $override && !$first) {
                $tags->set_attribute('data-dxsm-drop', $token);
                $drop = true;
                continue;
            }
            if ($is_description && $override) {
                self::set_text_attribute($tags, 'content', \dxsm_without_dashes($context['description']));
                $first = false;
                continue;
            }
            $content = $tags->get_attribute('content');
            if (\dxsm_is_dash_free_meta($key) && is_string($content) && \dxsm_without_dashes($content) !== $content) {
                self::set_text_attribute($tags, 'content', \dxsm_without_dashes($content));
            }
        }
        $head = $tags->get_updated_html();
        if ($drop) {
            $updated = preg_replace('~<meta\b[^>]*?\sdata-dxsm-drop="' . $token . '"(?:[^>"\']|"[^"]*"|\'[^\']*\')*>\n?~i', '', $head);
            $head = is_string($updated) ? $updated : $head;
        }
        $processor = new \WP_HTML_Tag_Processor($head);
        $description = null;
        $og = false;
        $twitter = false;
        $fb_app = false;
        while ($processor->next_tag()) {
            if ($processor->get_tag() === 'META') {
                foreach (array('name', 'property') as $attribute) {
                    $key = $processor->get_attribute($attribute);
                    if (!is_string($key)) {
                        continue;
                    }
                    $key = strtolower(trim($key));
                    if ($key === 'description') {
                        $description = (string) $processor->get_attribute('content');
                    }
                    $og = $og || strpos($key, 'og:') === 0;
                    $twitter = $twitter || strpos($key, 'twitter:') === 0;
                    $fb_app = $fb_app || $key === 'fb:app_id';
                }
            } elseif ($processor->get_tag() === 'LINK') {
                $rel = $processor->get_attribute('rel');
                $href = $processor->get_attribute('href');
                if (is_string($rel) && is_string($href) && in_array('canonical', preg_split('/\s+/', strtolower(trim($rel))), true)) {
                    $context['url'] = $href;
                }
            }
        }
        $tags = array();
        $app_id = self::settings()['fb_app_id'];
        if (!$fb_app && $app_id !== '') {
            $tags[] = array('property', 'fb:app_id', $app_id);
        }
        if ($description === null && $context['description'] !== '') {
            $tags[] = array('name', 'description', $context['description']);
        }
        $social_description = $description !== null ? $description : $context['description'];
        $image = $context['image'];
        if (!$og) {
            foreach (array('type' => $context['type'], 'title' => $context['title'], 'description' => $social_description,
                'url' => $context['url'], 'site_name' => $context['site_name'], 'locale' => $context['locale']) as $key => $value) {
                $tags[] = array('property', 'og:' . $key, $value);
            }
            if (!empty($image['url'])) {
                $tags[] = array('property', 'og:image', $image['url']);
                foreach (array('width', 'height', 'type', 'alt') as $field) {
                    if (!empty($image[$field])) {
                        $tags[] = array('property', 'og:image:' . $field, $image[$field]);
                    }
                }
            }
        }
        if (!$twitter) {
            $large = !empty($image['url']) && !empty($image['width']) && !empty($image['height']) && $image['width'] >= 300 && $image['height'] >= 157;
            $tags[] = array('name', 'twitter:card', $large ? 'summary_large_image' : 'summary');
            $tags[] = array('name', 'twitter:title', $context['title']);
            $tags[] = array('name', 'twitter:description', $social_description);
            if (!empty($image['url'])) {
                $tags[] = array('name', 'twitter:image', $image['url']);
                if (!empty($image['alt'])) {
                    $tags[] = array('name', 'twitter:image:alt', $image['alt']);
                }
            }
        }
        foreach ($tags as $tag) {
            // Wartości kontekstu to zwykły tekst (tytuł już zdekodowany), więc kodujemy każdy znak &, także w dosłownej encji.
            $value = (string) (dxsm_is_dash_free_meta($tag[1]) ? dxsm_without_dashes($tag[2]) : $tag[2]);
            $head .= "\n<meta " . $tag[0] . '="' . esc_attr($tag[1]) . '" content="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true) . '" />';
        }
        return $head . ($tags ? "\n" : '');
    }

    public static function register_settings() {
        register_setting('dxsm_settings', self::OPTION, array('type' => 'array', 'sanitize_callback' => array(__CLASS__, 'sanitize_settings')));
        register_setting('dxsm_settings', self::MODULES, array('type' => 'array', 'sanitize_callback' => array(__CLASS__, 'sanitize_modules')));
    }

    // Sekcja „Udostępnianie linków” w zakładce Ustawienia panelu Social Meta.
    public static function render_share_settings() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $settings = self::settings();
        $fields = array('site_name' => 'Nazwa strony w linkach', 'default_description' => 'Domyślny opis', 'default_image_url' => 'Adres obrazu z tej strony (PNG, JPG lub WebP)',
            'default_image_width' => 'Szerokość obrazu (px)', 'default_image_height' => 'Wysokość obrazu (px)', 'default_image_alt' => 'Opis obrazu',
            'fb_app_id' => 'Facebook App ID (opcjonalnie)');
        echo '<section class="dxsm-admin-settings dxsm-admin-panel" style="margin-bottom:20px" aria-labelledby="dxsm-share-heading"><h2 id="dxsm-share-heading">Udostępnianie linków</h2>';
        echo '<p>Tak wyglądają linki do tej strony po wklejeniu ich na Facebooku, LinkedInie, w X i komunikatorach. Obraz musi pochodzić z tej strony. Wpis lub strona z obrazem wyróżniającym używa tego obrazu zamiast domyślnego.</p>';
        echo '<form method="post" action="options.php">';
        settings_fields('dxsm_settings');
        echo '<table class="form-table" role="presentation">';
        foreach ($fields as $field => $label) {
            $type = in_array($field, array('default_image_width', 'default_image_height'), true) ? 'number' : ($field === 'default_image_url' ? 'url' : 'text');
            echo '<tr><th scope="row"><label for="' . esc_attr($field) . '">' . esc_html($label) . '</label></th><td><input class="regular-text" id="' . esc_attr($field) . '" type="' . esc_attr($type) . '" name="' . esc_attr(self::OPTION . '[' . $field . ']') . '" value="' . \dxsm_escape_seo_text((string) $settings[$field]) . '" /></td></tr>';
        }
        echo '</table>';
        echo '<p class="description">Facebook App ID to numer aplikacji z Meta for Developers. Bez niego debuger udostępniania pokazuje ostrzeżenie o braku fb:app_id, ale podgląd linku działa normalnie.</p>';
        $full = self::full_seo();
        echo '<h2>Moduły</h2><table class="form-table" role="presentation"><tr><th scope="row">Pełne SEO</th><td><label><input type="hidden" name="' . esc_attr(self::MODULES . '[present]') . '" value="1" /><input type="checkbox" name="' . esc_attr(self::MODULES . '[full_seo]') . '" value="1" ' . checked($full, true, false) . ' /> Mapa witryny, robots, canonical, noindex, dane firmy w wyszukiwarce i import danych z SEOPress</label>';
        echo '<p class="description">Włącz tylko wtedy, gdy na stronie nie działa Yoast, Rank Math ani SEOPress. Moduł sam się wycofuje, gdy któraś z nich jest aktywna.</p></td></tr></table>';
        if ($full) {
            FullSeo\SEO::settings_fields();
        }
        submit_button('Zapisz ustawienia');
        echo '</form>';
        Redirects::panel();
        YoastImport::panel();
        if ($full) {
            FullSeo\Migration::panel();
        }
        echo '</section>';
    }

    // Okno „Zobacz szczegóły”, wzorzec: d-x.pl/wordpress/dx-openai-leads/details.php. Treść z readme.txt.
    public static function row_meta($links, $file) {
        if ($file !== self::SLUG . '/' . self::SLUG . '.php') {
            return $links;
        }
        // WordPress sam dodaje ten link, gdy aktualizator poda dane wydania; drugi byłby zdublowany.
        foreach ($links as $link) {
            if (strpos((string) $link, 'plugin=' . self::SLUG . '&') !== false) {
                return $links;
            }
        }
        $url = self_admin_url('plugin-install.php?tab=plugin-information&plugin=' . self::SLUG . '&TB_iframe=true&width=772&height=600');
        $links[] = '<a href="' . esc_url($url) . '" class="thickbox open-plugin-details-modal" aria-label="' . esc_attr('Więcej informacji o Digital Xperts Social Meta') . '">Zobacz szczegóły</a>';
        return $links;
    }

    // Dane okna „Zobacz szczegóły”; wersję i paczkę podaje Updater z wydania na GitHubie.
    public static function details($release) {
        return (object) array(
            'name' => 'Digital Xperts Social Meta',
            'slug' => self::SLUG,
            'version' => isset($release['version']) ? $release['version'] : VERSION,
            'author' => '<a href="https://d-x.pl/">Dariusz Trachimowicz</a>',
            'homepage' => 'https://github.com/' . Updater::repo(),
            'requires' => '6.2',
            'tested' => '7.2',
            'requires_php' => '7.4',
            'last_updated' => '2026-11-18',
            'download_link' => isset($release['package']) ? $release['package'] : '',
            'sections' => self::readme_sections((string) file_get_contents(__DIR__ . '/readme.txt')),
            'banners' => array(
                'low' => 'https://d-x.pl/wp-content/uploads/2026/09/social-meta-social.jpg',
                'high' => 'https://d-x.pl/wp-content/uploads/2026/09/social-meta-social.jpg',
            ),
        );
    }

    private static function readme_sections($text) {
        $text = str_replace("\r\n", "\n", $text);
        $map = array('Description' => 'description', 'Installation' => 'installation', 'Frequently Asked Questions' => 'faq', 'Privacy' => 'Prywatność', 'Changelog' => 'changelog');
        $parts = preg_split('/^== (.+?) ==$/m', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: array();
        $sections = array();
        for ($i = 1; $i + 1 < count($parts); $i += 2) {
            $key = isset($map[trim($parts[$i])]) ? $map[trim($parts[$i])] : null;
            if ($key) {
                $sections[$key] = self::readme_html($parts[$i + 1]);
            }
        }
        return $sections;
    }

    // Podzbiór składni readme WordPressa: nagłówki = x =, listy, kod i adresy.
    private static function readme_html($text) {
        $html = '';
        $list = '';
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if (preg_match('/^(?:(\d+)\. |[*-] )(.*)$/', $line, $m)) {
                $kind = $m[1] !== '' ? 'ol' : 'ul';
                if ($list !== $kind) {
                    $html .= ($list !== '' ? '</' . $list . '>' : '') . '<' . $kind . '>';
                    $list = $kind;
                }
                $html .= '<li>' . self::readme_inline($m[2]) . '</li>';
                continue;
            }
            if ($list !== '') {
                $html .= '</' . $list . '>';
                $list = '';
            }
            if (preg_match('/^= (.+) =$/', $line, $m)) {
                $html .= '<h4>' . esc_html($m[1]) . '</h4>';
            } elseif ($line !== '') {
                $html .= '<p>' . self::readme_inline($line) . '</p>';
            }
        }
        return $html . ($list !== '' ? '</' . $list . '>' : '');
    }

    private static function readme_inline($text) {
        $text = esc_html($text);
        $text = (string) preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
        return (string) preg_replace('~https?://[^\s<]+[^\s<.,)]~', '<a href="$0">$0</a>', $text);
    }
}

Plugin::boot();
