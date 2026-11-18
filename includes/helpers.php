<?php
// Funkcje, których panel przeniesiony z d-x.pl oczekuje od głównego pliku wtyczki.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DigitalXperts\SocialMeta\Plugin;

// Półpauza i pauza (także jako encje) zamienione na dywiz. WordPress robi półpauzę z separatora tytułu " - ".
function dxsm_without_dashes( $text ) {
	$text = (string) $text;
	$clean = preg_replace( '/\x{2013}|\x{2014}|&#0*821[12](?![0-9]);?|&#x0*201[34](?![0-9a-f]);?|&[nm]dash;/iu', '-', $text );
	return null === $clean ? $text : $clean;
}

// Znaczniki, które wtyczka oczyszcza z półpauz: opis i każdy og:* oraz twitter:* poza adresami
// (każdy klucz z członem url, image, src, player, stream, video, audio, see_also, referenced, np. twitter:app:url:iphone).
// Tekst alternatywny (og:image:alt, twitter:image:alt) jest oczyszczany.
function dxsm_is_dash_free_meta( $key ) {
	$key = strtolower( trim( (string) $key ) );
	return 1 === preg_match( '/^(description|og:.+|twitter:.+)$/', $key )
		&& ( 1 === preg_match( '/:alt$/', $key ) || 1 !== preg_match( '/(^|:)(url|image|src|player|stream|video|audio|see_also|referenced)(:|$)/', $key ) );
}

// Zapytanie panelu o wartości automatyczne: strona pokazuje je bez własnych pól SEO.
function dxsm_is_automatic_request() {
	return ! is_admin() && isset( $_GET['dxsm_auto'] );
}

function dxsm_get_context_post_id() {
	if ( is_home() ) {
		return (int) get_option( 'page_for_posts' );
	}
	if ( is_front_page() ) {
		return (int) get_option( 'page_on_front' );
	}
	return is_singular( dxsm_seo_post_types() ) ? (int) get_queried_object_id() : 0;
}

/**
 * Opis, który strona pokazuje bez własnego pola SEO. Najpierw czyta go z publicznej strony,
 * bo opisy wypisuje też mu-plugin cp-seo.php; gdy odczyt się nie uda, liczy go jak wtyczka.
 */
function dxsm_get_automatic_description( $post_id, $is_posts_page = false ) {
	static $cache = array();
	$post_id = (int) $post_id;
	if ( isset( $cache[ $post_id ] ) ) {
		return $cache[ $post_id ];
	}
	$post = get_post( $post_id );
	$found = '';
	if ( $post && 'publish' === $post->post_status && '' === $post->post_password ) {
		$url = add_query_arg( 'dxsm_auto', wp_generate_password( 8, false, false ), get_permalink( $post ) );
		$response = wp_safe_remote_get( $url, array( 'timeout' => 4, 'redirection' => 0, 'limit_response_size' => 1024 * 1024 ) );
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$html = (string) wp_remote_retrieve_body( $response );
			$end = stripos( $html, '</head>' );
			$processor = new WP_HTML_Tag_Processor( false === $end ? $html : substr( $html, 0, $end ) );
			while ( $processor->next_tag( 'META' ) ) {
				if ( 'description' === strtolower( trim( (string) $processor->get_attribute( 'name' ) ) ) ) {
					$found = trim( (string) $processor->get_attribute( 'content' ) );
					break;
				}
			}
		}
	}
	return $cache[ $post_id ] = '' !== $found ? $found : Plugin::fallback_description( $post );
}

// Grafika z kart: obraz wyróżniający albo obraz domyślny z ustawień. Zwraca [id, [url, szer., wys.], alt].
function dxsm_get_social_image( $post_id ) {
	// Filtr dxsm_social_image pozwala dodatkom strony podać własny obraz kart (np. ręcznie wybrany dla strony).
	return apply_filters( 'dxsm_social_image', dxsm_default_social_image( $post_id ), $post_id );
}

function dxsm_default_social_image( $post_id ) {
	$image_id = (int) get_post_thumbnail_id( $post_id );
	$image = $image_id ? wp_get_attachment_image_src( $image_id, 'full' ) : false;
	if ( $image ) {
		return array( $image_id, $image, (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ) );
	}
	$settings = Plugin::settings();
	if ( '' === $settings['default_image_url'] ) {
		return false;
	}
	return array( 0, array( $settings['default_image_url'], $settings['default_image_width'], $settings['default_image_height'] ), $settings['default_image_alt'] );
}
