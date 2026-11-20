<?php
// Import tytułów, opisów i fraz z Yoast SEO do pól Social Meta. Działa także po wyłączeniu Yoasta, bo jego pola zostają w bazie.
namespace DigitalXperts\SocialMeta;

defined('ABSPATH') || exit;

final class YoastImport {
    // Pole Yoasta => pole Social Meta.
    const FIELDS = array(
        '_yoast_wpseo_title'    => '_dxsm_seo_title',
        '_yoast_wpseo_metadesc' => '_dxsm_seo_description',
        '_yoast_wpseo_focuskw'  => '_dxsm_seo_keyphrase',
    );

    public static function boot() {
        add_action('admin_post_dxsm_yoast_import', array(__CLASS__, 'handle'));
    }

    // Zmienne Yoasta (%%title%%, %%sitename%%) zamienione na tekst. Nieznane zmienne znikają razem z osieroconym separatorem.
    public static function expand($template, $post) {
        $values = array('sitename' => get_bloginfo('name'), 'sitedesc' => get_bloginfo('description'), 'sep' => '-', 'currentyear' => wp_date('Y'));
        if ($post instanceof \WP_Post) {
            $categories = get_the_category($post->ID);
            $category = $categories ? $categories[0]->name : '';
            // Kategoria główna wybrana w Yoaście ma pierwszeństwo przed pierwszą z listy.
            $primary = get_term((int) get_post_meta($post->ID, '_yoast_wpseo_primary_category', true), 'category');
            $tags = get_the_tags($post->ID);
            $type = get_post_type_object($post->post_type);
            $author = get_userdata((int) $post->post_author);
            // Tytuł i opis SEO są publiczne, więc treść wpisu chronionego hasłem nie może do nich trafić.
            $protected = '' !== (string) $post->post_password;
            $values += array(
                'title'            => $post->post_title,
                'excerpt_only'     => $protected ? '' : $post->post_excerpt,
                // Bez ręcznej zajawki Yoast bierze początek treści.
                'excerpt'          => $protected ? '' : ('' !== $post->post_excerpt ? $post->post_excerpt : wp_html_excerpt(strip_shortcodes(wp_strip_all_tags($post->post_content)), 156)),
                'category'         => $category,
                'primary_category' => $primary instanceof \WP_Term ? $primary->name : $category,
                'tag'              => is_array($tags) && $tags ? $tags[0]->name : '',
                'date'             => get_the_date('', $post),
                'modified'         => get_the_modified_date('', $post),
                'id'               => (string) $post->ID,
                'name'             => $author ? $author->display_name : '',
                'pt_single'        => $type ? $type->labels->singular_name : '',
                'pt_plural'        => $type ? $type->labels->name : '',
                'parent_title'     => $post->post_parent ? get_the_title($post->post_parent) : '',
            );
        }
        $text = preg_replace_callback('/%%([a-z0-9_-]+)%%/i', function ($match) use ($values) {
            $name = strtolower($match[1]);
            return isset($values[$name]) ? $values[$name] : '';
        }, (string) $template);
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        // Separatory, które zostały bez sąsiada po usunięciu pustej zmiennej.
        $text = preg_replace('/(\s[-|]\s)(?:[-|]\s)+/u', '$1', $text);
        return trim(preg_replace('/^[-|\s]+|[-|\s]+$/u', '', $text));
    }

    // Wiersze Yoasta dla treści, które obsługuje panel Social Meta.
    private static function rows() {
        global $wpdb;
        $types = \dxsm_seo_post_types();
        if (!$types) {
            return array();
        }
        $keys = array_keys(self::FIELDS);
        $in_keys = implode(',', array_fill(0, count($keys), '%s'));
        $in_types = implode(',', array_fill(0, count($types), '%s'));
        // shortcut: jeden przebieg do 5000 pól; przy większych serwisach podzielić import na partie.
        $sql = "SELECT m.post_id, m.meta_key, m.meta_value FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
            WHERE m.meta_key IN ($in_keys) AND m.meta_value <> '' AND p.post_type IN ($in_types) AND p.post_status NOT IN ('trash', 'auto-draft', 'inherit')
            ORDER BY m.post_id, m.meta_key LIMIT 5000";
        $rows = $wpdb->get_results($wpdb->prepare($sql, array_merge($keys, $types)), ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    // Wartość gotowa do zapisu w polu Social Meta; pusta, gdy szablon Yoasta po rozwinięciu nic nie zostawia.
    private static function value($row, $post) {
        return $post ? \dxsm_without_dashes(sanitize_text_field(self::expand($row['meta_value'], $post))) : '';
    }

    // Treść wpisu jest potrzebna tylko na czas rozwinięcia zmiennych. Zwalniamy ją od razu, żeby przegląd dużego serwisu
    // nie trzymał w pamięci wszystkich wpisów naraz.
    private static function release($post_id) {
        wp_cache_delete((int) $post_id, 'posts');
        wp_cache_delete((int) $post_id, 'post_meta');
    }

    // Ile pól da się przenieść (puste u nas) i ile zostaje, bo Social Meta ma już własną wartość.
    public static function scan() {
        $result = array('posts' => array(), 'new' => 0, 'kept' => 0);
        foreach (self::rows() as $row) {
            $value = self::value($row, get_post((int) $row['post_id']));
            $own = get_post_meta((int) $row['post_id'], self::FIELDS[$row['meta_key']], true);
            self::release($row['post_id']);
            if ('' === $value) {
                continue;
            }
            $result['posts'][(int) $row['post_id']] = true;
            $result[is_string($own) && '' !== $own ? 'kept' : 'new']++;
        }
        $result['posts'] = count($result['posts']);
        return $result;
    }

    // Przenosi tylko do pustych pól. Niczego nie nadpisuje i nie usuwa danych Yoasta.
    public static function run() {
        $imported = 0;
        foreach (self::rows() as $row) {
            $post = get_post((int) $row['post_id']);
            $target = self::FIELDS[$row['meta_key']];
            $value = $post && '' === (string) get_post_meta($post->ID, $target, true) ? self::value($row, $post) : '';
            if ('' === $value) {
                // Pole już wypełnione albo szablon pusty po rozwinięciu: wpis też schodzi z pamięci.
                self::release($row['post_id']);
                continue;
            }
            // Zapis nie może zgubić wartości, którą ktoś wpisuje w tej samej chwili. Istniejące puste pole jest wypełniane
            // jednym zapytaniem z warunkiem „nadal puste”, bez usuwania wiersza. Gdy pola nie ma, wstawia je jedno zapytanie
            // z warunkiem „nadal nie istnieje”, więc sprawdzenie i zapis nie rozchodzą się w czasie.
            global $wpdb;
            $filled = $wpdb->update($wpdb->postmeta, array('meta_value' => $value), array('post_id' => $post->ID, 'meta_key' => $target, 'meta_value' => ''), array('%s'), array('%d', '%s', '%s'));
            if (!$filled) {
                $filled = $wpdb->query($wpdb->prepare(
                    "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) SELECT %d, %s, %s FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s)",
                    $post->ID, $target, $value, $post->ID, $target
                ));
            }
            if ($filled) {
                $imported++;
                clean_post_cache($post->ID);
            }
            self::release($post->ID);
        }
        return $imported;
    }

    public static function handle() {
        if (!current_user_can('manage_options')) {
            wp_die('Brak uprawnień.', '', array('response' => 403));
        }
        check_admin_referer('dxsm_yoast_import');
        $imported = self::run();
        wp_safe_redirect(add_query_arg(array('page' => 'dxsm-social-meta', 'dxsm_view' => 'settings', 'dxsm_yoast' => $imported), admin_url('admin.php')));
        exit;
    }

    public static function panel() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $scan = self::scan();
        $done = isset($_GET['dxsm_yoast']) ? absint($_GET['dxsm_yoast']) : null;
        if (!$scan['posts'] && null === $done) {
            return;
        }
        echo '<h2>Import z Yoast SEO</h2>';
        if (null !== $done) {
            echo '<div class="notice notice-success inline" role="status"><p>Przeniesiono pól: ' . esc_html((string) $done) . '.</p></div>';
        }
        echo '<p>Tytuły SEO, opisy meta i frazy kluczowe zapisane w Yoast SEO. Import wypełnia tylko puste pola Social Meta i nie zmienia danych Yoasta.</p>';
        echo '<p>Treści z danymi Yoasta: <strong>' . esc_html((string) $scan['posts']) . '</strong>. Pola do przeniesienia: <strong>' . esc_html((string) $scan['new']) . '</strong>. Pola pominięte, bo Social Meta ma już własną wartość: <strong>' . esc_html((string) $scan['kept']) . '</strong>.</p>';
        if ($scan['new']) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('dxsm_yoast_import');
            echo '<input type="hidden" name="action" value="dxsm_yoast_import" />';
            submit_button('Przenieś dane z Yoast SEO', 'secondary');
            echo '</form>';
        }
    }
}
