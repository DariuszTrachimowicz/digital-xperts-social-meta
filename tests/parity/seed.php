<?php
// Treści do porównania d-x.pl 1.16.1 z rdzeniem 2.0.0 i dodatkami (wp eval-file /tests/seed.php).
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
update_option('blogname', 'Digital Xperts');
update_option('blogdescription', 'Strony i SEO');
update_option('permalink_structure', '/%postname%/');
$img = imagecreatetruecolor(1200, 630); imagefill($img, 0, 0, imagecolorallocate($img, 30, 60, 90));
$tmp = '/tmp/karta.png'; imagepng($img, $tmp);
$file = array('name' => 'karta.png', 'tmp_name' => $tmp);
$cat = wp_insert_term('Poradniki', 'category');
$post = wp_insert_post(array('post_title' => 'Wpis testowy - SEO', 'post_content' => '<p>Treść wpisu o SEO - krótko.</p>', 'post_excerpt' => 'Zajawka - wpis o SEO.', 'post_status' => 'publish', 'post_category' => array($cat['term_id'])));
$att = media_handle_sideload($file, $post);
update_post_meta($att, '_wp_attachment_image_alt', 'Karta - grafika');
set_post_thumbnail($post, $att);
$page = wp_insert_post(array('post_title' => 'Kontakt', 'post_content' => '<p>Napisz do nas.</p>', 'post_status' => 'publish', 'post_type' => 'page'));
set_post_thumbnail($page, $att);
update_post_meta($page, '_dxsm_seo_title', 'Kontakt z nami');
update_post_meta($page, '_dxsm_seo_description', 'Opis kontaktu z panelu.');
$front = wp_insert_post(array('post_title' => 'Start', 'post_content' => '<p>Strona główna.</p>', 'post_status' => 'publish', 'post_type' => 'page'));
set_post_thumbnail($front, $att);
update_option('show_on_front', 'page'); update_option('page_on_front', $front);
flush_rewrite_rules();
echo json_encode(array('post' => get_permalink($post), 'page' => get_permalink($page), 'cat' => get_term_link($cat['term_id']), 'front' => home_url('/'))) . "\n";
