<?php
// Moduł pełnego SEO z wariantu RS2. Nazwy danych (dxsm_rs2_*, _dxsm_rs2_*) zostają z pierwszego wdrożenia, bo leżą już w bazie RS2.
namespace DigitalXperts\SocialMeta\FullSeo;
defined('ABSPATH') || exit;

final class SEO {
    const OPTION = 'dxsm_rs2_seo_settings';
    const PREFIX = '_dxsm_rs2_';

    public static function boot() {
        add_filter('pre_get_document_title', array(__CLASS__, 'title'), 20);
        add_filter('get_canonical_url', array(__CLASS__, 'canonical'), 20, 2);
        add_filter('wp_robots', array(__CLASS__, 'robots'), 20);
        add_filter('wp_sitemaps_post_types', array(__CLASS__, 'sitemap_post_types'));
        add_filter('wp_sitemaps_taxonomies', array(__CLASS__, 'sitemap_taxonomies'));
        add_filter('wp_sitemaps_add_provider', array(__CLASS__, 'sitemap_provider'), 10, 2);
        add_filter('wp_sitemaps_posts_query_args', array(__CLASS__, 'sitemap_query'), 10, 2);
        add_filter('wp_sitemaps_taxonomies_query_args', array(__CLASS__, 'sitemap_term_query'), 10, 2);
        add_action('add_meta_boxes', array(__CLASS__, 'meta_boxes'));
        add_action('save_post', array(__CLASS__, 'save_meta'));
        add_action('admin_init', array(__CLASS__, 'register_settings'));
        add_action('template_redirect', array(__CLASS__, 'legacy_sitemap'), 0);
    }

    public static function enabled() {
        return !defined('SEOPRESS_VERSION') && !defined('WPSEO_VERSION') && !defined('RANK_MATH_VERSION');
    }

    public static function text($value) {
        return is_scalar($value) ? sanitize_text_field((string) $value) : '';
    }

    private static function slugs($value) {
        $value = is_string($value) ? preg_split('/[\s,]+/', $value) : $value;
        return is_array($value) ? array_values(array_unique(array_filter(array_map(function ($v) {
            return is_string($v) && preg_match('/^[a-z0-9_-]+$/D', $v) ? $v : '';
        }, $value)))) : array();
    }

    public static function sanitize_settings($input) {
        $input = is_array($input) ? $input : array();
        $defaults = array('sitemap_post_types'=>array('post','page','product'), 'sitemap_taxonomies'=>array('category'),
            'noindex_post_types'=>array(), 'noindex_taxonomies'=>array(), 'service_post_types'=>array());
        $out = array();
        foreach ($defaults as $key=>$fallback) { $out[$key] = self::slugs(isset($input[$key]) ? $input[$key] : $fallback); }
        $business = isset($input['business']) && is_array($input['business']) ? $input['business'] : array();
        $out['business'] = array();
        foreach (array('name','telephone','street','city','region','postal_code','country') as $key) {
            $out['business'][$key] = self::text(isset($business[$key]) ? $business[$key] : '');
        }
        $out['business']['email'] = isset($business['email']) && is_string($business['email']) ? sanitize_email($business['email']) : '';
        $out['business']['logo'] = \DigitalXperts\SocialMeta\Plugin::local_image_url(isset($business['logo']) ? $business['logo'] : '');
        return $out;
    }

    public static function settings() { return self::sanitize_settings(get_option(self::OPTION, array())); }

    public static function post_value($id, $field, $skip_panel = false) {
        // Tytuł i opis z panelu SEO mają pierwszeństwo przed polami modułu i danymi SEOPress (poza odczytem wartości automatycznych).
        if (!$skip_panel && in_array($field, array('title', 'description'), true) && !\dxsm_is_automatic_request()) {
            $panel = \dxsm_get_seo_override((int) $id, '_dxsm_seo_' . $field);
            if ($panel !== '') { return $panel; }
        }
        if (metadata_exists('post', $id, self::PREFIX.$field)) {
            return self::text(get_post_meta($id, self::PREFIX.$field, true));
        }
        $map = $field==='title' ? 'titles' : ($field==='description' ? 'descriptions' : '');
        if ($map) {
            $settings=\DigitalXperts\SocialMeta\Plugin::settings();
            if (isset($settings[$map][$id])) { return $settings[$map][$id]; }
        }
        $legacy = array('title'=>'_seopress_titles_title','description'=>'_seopress_titles_desc',
            'canonical'=>'_seopress_robots_canonical','noindex'=>'_seopress_robots_index','nofollow'=>'_seopress_robots_follow');
        $value = isset($legacy[$field]) ? Migration::post_meta($id,$legacy[$field],true) : '';
        if (in_array($field,array('noindex','nofollow'),true)) {
            return in_array($value,array('yes','1',1,true,'on'),true) ? '1' : '0';
        }
        return self::text($value);
    }

    public static function expand($text, $post = null) {
        $post = $post instanceof \WP_Post ? $post : null;
        $values = array('%%sitetitle%%'=>\DigitalXperts\SocialMeta\Plugin::site_text('name'), '%%tagline%%'=>\DigitalXperts\SocialMeta\Plugin::site_text('description'),
            '%%sep%%'=>'|', '%%post_title%%'=>$post ? $post->post_title : '',
            '%%post_excerpt%%'=>$post ? $post->post_excerpt : '');
        $text = strtr(self::text($text),$values);
        return strpos($text,'%%') === false ? $text : '';
    }

    public static function public_post($post) {
        return $post instanceof \WP_Post && $post->post_status === 'publish' && is_post_publicly_viewable($post) && $post->post_password === '';
    }

    public static function queried_post() { return \DigitalXperts\SocialMeta\Plugin::queried_post(); }

    public static function context_url($post) { return \DigitalXperts\SocialMeta\Plugin::context_url($post); }

    public static function title($title) {
        if (!self::enabled() || is_admin() || is_preview() || (!is_singular() && !is_home() && !is_post_type_archive('product'))) { return $title; }
        $post = self::queried_post();
        if (!self::public_post($post)) { return $title; }
        // Tytuł z panelu escapujemy jak panel przy wyłączonym module (encje wpisane dosłownie zostają widoczne).
        $panel = \dxsm_is_automatic_request() ? '' : \dxsm_get_seo_override($post->ID, '_dxsm_seo_title');
        if ($panel !== '') { return \dxsm_escape_seo_text(\dxsm_without_dashes($panel)); }
        $value = self::post_value($post->ID,'title',true);
        $value = self::expand($value,$post);
        return $value !== '' ? $value : $title;
    }

    public static function description($post, $fallback, $skip_panel = false) {
        if (!self::enabled() || !self::public_post($post)) { return $fallback; }
        if (metadata_exists('post',$post->ID,self::PREFIX.'description')) {
            return self::expand(self::post_value($post->ID,'description',$skip_panel),$post);
        }
        if ($fallback !== '') { return $fallback; }
        return self::expand(self::post_value($post->ID,'description',$skip_panel),$post);
    }

    public static function canonical_url($value) {
        $url = is_string($value) ? esc_url_raw($value) : '';
        $parts = wp_parse_url($url);
        $home = wp_parse_url(home_url('/'));
        if (!$parts || empty($parts['scheme']) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || strcasecmp($parts['host'],$home['host']) !== 0
            || (isset($parts['port']) ? $parts['port'] : null) !== (isset($home['port']) ? $home['port'] : null)
            || !in_array(strtolower($parts['scheme']),array('http','https'),true)
            || ($parts['scheme'] !== 'https' && !in_array(wp_get_environment_type(),array('local','development'),true))) { return ''; }
        return $url;
    }

    public static function canonical($url, $post) {
        if (!self::enabled() || !self::public_post($post)) { return $url; }
        $value = self::canonical_url(self::post_value($post->ID,'canonical'));
        return $value ?: $url;
    }

    private static function shop_closed() { return get_option('woocommerce_coming_soon') === 'yes'; }

    private static function utility_ids() {
        $ids = array();
        foreach (array('woocommerce_cart_page_id','woocommerce_checkout_page_id','woocommerce_myaccount_page_id') as $key) {
            if ((int)get_option($key)>0) { $ids[]=(int)get_option($key); }
        }
        if (self::shop_closed() && (int)get_option('woocommerce_shop_page_id')>0) { $ids[]=(int)get_option('woocommerce_shop_page_id'); }
        return $ids;
    }

    public static function robots($robots) {
        if (!self::enabled() || is_admin()) { return $robots; }
        $settings = self::settings(); $post=self::queried_post();
        $noindex = is_search() || is_author() || is_date() || is_attachment();
        if (self::shop_closed()) {
            $noindex = $noindex || get_option('woocommerce_store_pages_only') !== 'yes' || is_singular('product')
                || is_post_type_archive('product') || is_tax(get_object_taxonomies('product'));
        }
        if ($post instanceof \WP_Post) {
            $noindex = $noindex || !self::public_post($post) || in_array($post->post_type,$settings['noindex_post_types'],true)
                || in_array($post->ID,self::utility_ids(),true) || self::post_value($post->ID,'noindex') === '1';
            if (self::post_value($post->ID,'nofollow') === '1') { unset($robots['follow']); $robots['nofollow']=true; }
        }
        if (is_post_type_archive() && array_intersect((array)get_query_var('post_type'),$settings['noindex_post_types'])) { $noindex=true; }
        if ($post instanceof \WP_Term) {
            $noindex = $noindex || in_array($post->taxonomy,$settings['noindex_taxonomies'],true)
                || in_array(Migration::term_meta($post->term_id,'_seopress_robots_index',true),array('yes','1'),true);
        }
        if ($noindex) { unset($robots['index']); $robots['noindex']=true; }
        return $robots;
    }

    public static function sitemap_post_types($types) {
        if (!self::enabled()) { return $types; }
        $allowed=self::settings()['sitemap_post_types'];
        if (self::shop_closed()) {
            $allowed = get_option('woocommerce_store_pages_only') !== 'yes' ? array() : array_diff($allowed,array('product'));
        }
        $allowed=array_diff($allowed,self::settings()['noindex_post_types']);
        return array_intersect_key($types,array_flip($allowed));
    }

    public static function sitemap_taxonomies($types) {
        if (!self::enabled()) { return $types; }
        $allowed=array_diff(self::settings()['sitemap_taxonomies'],self::settings()['noindex_taxonomies']);
        if (self::shop_closed()) {
            $allowed=get_option('woocommerce_store_pages_only') !== 'yes' ? array() : array_diff($allowed,get_object_taxonomies('product'));
        }
        return array_intersect_key($types,array_flip($allowed));
    }

    public static function sitemap_provider($provider,$name) { return self::enabled() && $name==='users' ? false : $provider; }

    public static function legacy_sitemap() {
        if (!self::enabled() || !in_array(strtoupper($_SERVER['REQUEST_METHOD']??'GET'),array('GET','HEAD'),true)) { return; }
        $path=wp_parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);
        if ($path===wp_parse_url(home_url('/sitemaps.xml'),PHP_URL_PATH)) {
            wp_safe_redirect(home_url('/wp-sitemap.xml'),301,'Digital Xperts Social Meta');
            exit;
        }
    }

    public static function sitemap_query($args,$type) {
        if (!self::enabled()) { return $args; }
        $index_key=Migration::query_key('_seopress_robots_index');
        $args['has_password']=false;
        $args['meta_query']=array('relation'=>'AND',isset($args['meta_query']) ? $args['meta_query'] : array(),array('relation'=>'OR',
            array('key'=>self::PREFIX.'noindex','value'=>'0'),
            array('relation'=>'AND',array('key'=>self::PREFIX.'noindex','compare'=>'NOT EXISTS'),
                array('relation'=>'OR',array('key'=>$index_key,'compare'=>'NOT EXISTS'),
                    array('key'=>$index_key,'value'=>array('yes','1','on','true'),'compare'=>'NOT IN')))));
        $exclude=self::utility_ids();
        // Canonical overrides are uncommon; query only posts that actually have one.
        $canonical_posts=get_posts(array('post_type'=>$type,'post_status'=>'publish','posts_per_page'=>-1,'fields'=>'ids',
            'meta_query'=>array('relation'=>'OR',array('key'=>self::PREFIX.'canonical','value'=>'','compare'=>'!='),
                array('key'=>Migration::query_key('_seopress_robots_canonical'),'value'=>'','compare'=>'!='))));
        foreach ($canonical_posts as $id) {
            $canonical=self::canonical_url(self::post_value($id,'canonical'));
            if ($canonical && $canonical!==get_permalink($id)) { $exclude[]=(int)$id; }
        }
        $args['post__not_in']=array_values(array_unique(array_merge(isset($args['post__not_in']) ? $args['post__not_in'] : array(),$exclude)));
        return $args;
    }

    public static function sitemap_term_query($args,$taxonomy) {
        if (!self::enabled()) { return $args; }
        $index_key=Migration::query_key('_seopress_robots_index');
        $args['meta_query']=array('relation'=>'AND',isset($args['meta_query']) ? $args['meta_query'] : array(),
            array('relation'=>'OR',array('key'=>$index_key,'compare'=>'NOT EXISTS'),
                array('key'=>$index_key,'value'=>array('yes','1'),'compare'=>'NOT IN')));
        return $args;
    }

    public static function sanitize_editor($input) {
        $input=is_array($input) ? $input : array(); $out=array();
        foreach (array('title','description') as $field) { $out[$field]=\dxsm_without_dashes(self::text(isset($input[$field]) ? $input[$field] : '')); }
        $out['canonical']=self::canonical_url(isset($input['canonical']) ? $input['canonical'] : '');
        foreach (array('noindex','nofollow') as $field) { $out[$field]=isset($input[$field]) && $input[$field]==='1' ? '1' : '0'; }
        return $out;
    }

    public static function meta_boxes() {
        foreach (self::settings()['sitemap_post_types'] as $type) {
            if (post_type_exists($type)) { add_meta_box('dxsm-rs2-seo','SEO: indeksowanie',array(__CLASS__,'meta_box'),$type,'normal','default'); }
        }
    }

    public static function meta_box($post) {
        wp_nonce_field('dxsm_rs2_save_seo','dxsm_rs2_seo_nonce');
        echo '<p>Tytuł i opis ustawisz w sekcji „Podgląd SEO” albo w menu Social Meta. Pola odczytują również zachowane dane SEOPress. Pusty canonical oznacza standardowy adres WordPressa.</p>';
        foreach (array('canonical'=>'Adres canonical (opcjonalny, w tej domenie)') as $field=>$label) {
            $value=self::post_value($post->ID,$field);
            echo '<p><label for="dxsm-rs2-'.esc_attr($field).'"><strong>'.esc_html($label).'</strong></label><br />';
            if ($field==='description') { echo '<textarea class="widefat" rows="3" id="dxsm-rs2-description" name="dxsm_rs2_seo[description]">'.esc_textarea($value).'</textarea>'; }
            else { echo '<input class="widefat" type="'.($field==='canonical'?'url':'text').'" id="dxsm-rs2-'.esc_attr($field).'" name="dxsm_rs2_seo['.esc_attr($field).']" value="'.esc_attr($value).'" />'; }
            echo '</p>';
        }
        foreach (array('noindex'=>'Wyklucz tę stronę z indeksowania (noindex)','nofollow'=>'Nie śledź linków na tej stronie (nofollow)') as $field=>$label) {
            echo '<p><label><input type="checkbox" name="dxsm_rs2_seo['.esc_attr($field).']" value="1" '.checked(self::post_value($post->ID,$field),'1',false).' /> '.esc_html($label).'</label></p>';
        }
        echo '<p>Reguły systemowe nadal wykluczają strony prywatne, koszyk, płatności, konto i techniczne elementy motywu.</p>';
    }

    public static function save_meta($id) {
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($id) || !current_user_can('edit_post',$id)
            || !isset($_POST['dxsm_rs2_seo_nonce']) || !is_string($_POST['dxsm_rs2_seo_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dxsm_rs2_seo_nonce'])),'dxsm_rs2_save_seo')
            || !isset($_POST['dxsm_rs2_seo']) || !is_array($_POST['dxsm_rs2_seo'])) { return; }
        // Tytuł i opis zapisuje panel SEO; ta ramka zapisuje tylko pola indeksowania.
        $values=self::sanitize_editor(wp_unslash($_POST['dxsm_rs2_seo']));
        foreach (array('canonical','noindex','nofollow') as $field) { update_post_meta($id,self::PREFIX.$field,$values[$field]); }
    }

    public static function register_settings() {
        register_setting('dxsm_settings',self::OPTION,array('type'=>'array','sanitize_callback'=>array(__CLASS__,'sanitize_settings')));
    }

    public static function settings_fields() {
        $settings=self::settings();
        echo '<h2>SEO i mapa strony</h2><p>Mapa: <a href="'.esc_url(home_url('/wp-sitemap.xml')).'">'.esc_html(home_url('/wp-sitemap.xml')).'</a>. Tytuły i opisy ustawisz w panelu SEO, a canonical i noindex w edycji strony w sekcji „SEO: indeksowanie”.</p>';
        echo '<table class="form-table" role="presentation">';
        foreach (array('sitemap_post_types'=>'Typy treści w mapie','sitemap_taxonomies'=>'Taksonomie w mapie','noindex_post_types'=>'Typy treści z noindex','noindex_taxonomies'=>'Taksonomie z noindex','service_post_types'=>'Typy treści opisane jako usługa (Service)') as $field=>$label) {
            echo '<tr><th><label for="dxsm-seo-'.esc_attr($field).'">'.esc_html($label).'</label></th><td><input class="regular-text" id="dxsm-seo-'.esc_attr($field).'" name="'.esc_attr(self::OPTION.'['.$field.']').'" value="'.esc_attr(implode(', ',$settings[$field])).'" /><p class="description">Nazwy techniczne, rozdzielone przecinkami.</p></td></tr>';
        }
        echo '</table><h2>Dane firmy w wyszukiwarce</h2><p>Uzupełniają brakujące schematy firmy, usług i artykułów. Istniejące schematy FAQ, breadcrumbs i inne generatory są zachowywane.</p><table class="form-table" role="presentation">';
        foreach (array('name'=>'Nazwa firmy','telephone'=>'Telefon','email'=>'E-mail','logo'=>'Lokalny adres logo','street'=>'Ulica','city'=>'Miasto','region'=>'Województwo','postal_code'=>'Kod pocztowy','country'=>'Kod kraju (np. PL)') as $field=>$label) {
            echo '<tr><th><label for="dxsm-business-'.esc_attr($field).'">'.esc_html($label).'</label></th><td><input class="regular-text" id="dxsm-business-'.esc_attr($field).'" name="'.esc_attr(self::OPTION.'[business]['.$field.']').'" value="'.\dxsm_escape_seo_text((string) $settings['business'][$field]).'" /></td></tr>';
        }
        echo '</table>';
    }
}
