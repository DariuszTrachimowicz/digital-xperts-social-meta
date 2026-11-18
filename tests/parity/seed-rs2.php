<?php
// Treści podobne do RS2: typ usług cms-service, kategorie, wpis z zajawką, strona z polami RS2 i noindex.
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
update_option('blogname', 'Wywóz odpadów Zgierz');
update_option('blogdescription', 'Odbiór odpadów i kontenery');
update_option('permalink_structure', '');
$img = imagecreatetruecolor(1200, 630); imagefill($img, 0, 0, imagecolorallocate($img, 10, 120, 60)); imagepng($img, '/tmp/rs2.png');
update_option('dxsm_rs2_settings', array('site_name' => 'RS2 Zgierz', 'default_description' => 'Odbiór odpadów w Zgierzu.', 'default_image_url' => '', 'titles' => array(), 'descriptions' => array()));
update_option('dxsm_rs2_seo_settings', array('sitemap_post_types' => array('post', 'page', 'cms-service'), 'sitemap_taxonomies' => array('category'), 'noindex_post_types' => array('cms-footer'), 'noindex_taxonomies' => array('post_tag'), 'business' => array('name' => 'RS2 Sp. z o.o.', 'telephone' => '+48 42 000 00 00', 'city' => 'Zgierz', 'country' => 'PL')));
$cat = wp_insert_term('Sortowanie śmieci', 'category');
$post = wp_insert_post(array('post_title' => 'Jak segregować', 'post_content' => '<p>Treść.</p>', 'post_excerpt' => 'Krótko o segregacji.', 'post_status' => 'publish', 'post_category' => array($cat['term_id'])));
$att = media_handle_sideload(array('name' => 'rs2.png', 'tmp_name' => '/tmp/rs2.png'), $post);
set_post_thumbnail($post, $att);
$page = wp_insert_post(array('post_title' => 'Kontakt', 'post_status' => 'publish', 'post_type' => 'page'));
update_post_meta($page, '_dxsm_rs2_title', 'Kontakt RS2 | Zgierz');
update_post_meta($page, '_dxsm_rs2_description', 'Zadzwoń do RS2.');
$hidden = wp_insert_post(array('post_title' => 'Regulamin', 'post_status' => 'publish', 'post_type' => 'page'));
update_post_meta($hidden, '_dxsm_rs2_noindex', '1');
$canon = wp_insert_post(array('post_title' => 'Stara usługa', 'post_status' => 'publish', 'post_type' => 'page'));
update_post_meta($canon, '_dxsm_rs2_canonical', home_url('/?page_id=' . $page));
update_option('show_on_front', 'posts');
echo json_encode(array('front' => home_url('/'), 'post' => get_permalink($post), 'page' => get_permalink($page), 'hidden' => get_permalink($hidden), 'canon' => get_permalink($canon), 'cat' => get_term_link($cat['term_id']), 'sitemap' => home_url('/wp-sitemap.xml'), 'sm_pages' => home_url('/wp-sitemap-posts-page-1.xml'))) . "\n";
