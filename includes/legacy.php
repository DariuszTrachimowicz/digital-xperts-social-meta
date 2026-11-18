<?php
// Jednorazowe przejście ze starszych wariantów (d-x.pl, RS2, Centrum Psychologii) na wspólną wersję.
// Stare dane zostają nietknięte, kopie powstają tylko tam, gdzie wspólnego klucza jeszcze nie ma.

if (!defined('ABSPATH')) {
    exit;
}

const DXSM_LEGACY_VERSION = '1';

function dxsm_migrate_legacy() {
    if (get_option('dxsm_legacy_migrated') === DXSM_LEGACY_VERSION) {
        return;
    }
    // Ustawienia udostępniania: wariant RS2 i Centrum miały ten sam układ pól.
    if (get_option('dxsm_settings', null) === null) {
        foreach (array('dxsm_rs2_settings', 'dxsm_centrum_settings') as $legacy) {
            $value = get_option($legacy, null);
            if (is_array($value)) {
                add_option('dxsm_settings', $value);
                break;
            }
        }
    }
    if (get_option('dxsm_editor_settings', null) === null && is_array(get_option('dxsmc_editor_settings', null))) {
        add_option('dxsm_editor_settings', get_option('dxsmc_editor_settings'));
    }
    // Strona po wariancie RS2 dostaje włączony moduł pełnego SEO z dotychczasowymi regułami mapy i noindex.
    // Wariant RS2 działał też bez zapisanych opcji: rozpoznajemy go po polach SEO, archiwum SEOPress albo plikach wtyczki.
    global $wpdb;
    $rs2 = get_option('dxsm_rs2_settings', null) !== null || get_option('dxsm_rs2_seo_settings', null) !== null
        || get_option('dxsm_rs2_sp_report', null) !== null || get_option('dxsm_rs2_sp_archive', null) !== null
        || is_file(WP_PLUGIN_DIR . '/digital-xperts-social-meta-rs2/digital-xperts-social-meta-rs2.php')
        || (bool) $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key LIKE %s LIMIT 1", $wpdb->esc_like('_dxsm_rs2_') . '%'));
    if (get_option('dxsm_modules', null) === null) {
        add_option('dxsm_modules', array('full_seo' => $rs2));
    }
    if ($rs2) {
        $seo = get_option('dxsm_rs2_seo_settings', null);
        if (!is_array($seo)) {
            // Wartości domyślne wariantu RS2 1.2.x (wcześniej wpisane w kod, nigdy nie zapisane w bazie).
            $seo = array(
                'sitemap_post_types' => array('post', 'page', 'cms-service', 'product'),
                'sitemap_taxonomies' => array('category'),
                'noindex_post_types' => array('cms-header-top', 'cms-sidenav', 'cms-footer', 'cms-mega-menu', 'e-floating-buttons', 'cms-career'),
                'noindex_taxonomies' => array('post_tag', 'case-category', 'product_brand', 'product_tag'),
                'business' => array(),
            );
        }
        if (!isset($seo['service_post_types'])) {
            $seo['service_post_types'] = array('cms-service');
        }
        update_option('dxsm_rs2_seo_settings', $seo, false);
    }
    // Pola panelu SEO wariantu Centrum: _dxsmc_seo_* na wspólne _dxsm_seo_* (jak na d-x.pl).
    $rows = $wpdb->get_results("SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ('_dxsmc_seo_title', '_dxsmc_seo_description')");
    foreach ((array) $rows as $row) {
        $target = '_dxsm_seo_' . substr($row->meta_key, strlen('_dxsmc_seo_'));
        if (!metadata_exists('post', (int) $row->post_id, $target)) {
            add_post_meta((int) $row->post_id, $target, wp_slash($row->meta_value), true);
        }
    }
    update_option('dxsm_legacy_migrated', DXSM_LEGACY_VERSION, true);
}
