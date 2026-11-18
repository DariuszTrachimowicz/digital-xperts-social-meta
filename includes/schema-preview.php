<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dxsm_schema_preview_get_field( $key ) {
	$value = $_GET[ $key ] ?? null;
	return is_scalar( $value ) ? sanitize_text_field( wp_unslash( (string) $value ) ) : null;
}

function dxsm_schema_preview_same_origin( $url, $home_url ) {
	if ( ! is_string( $url ) || '' === $url ) {
		return false;
	}

	$target = wp_parse_url( $url );
	$home = wp_parse_url( $home_url );
	$scheme = strtolower( $target['scheme'] ?? '' );
	if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || $scheme !== strtolower( $home['scheme'] ?? '' )
		|| empty( $target['host'] ) || empty( $home['host'] )
		|| isset( $target['user'] ) || isset( $target['pass'] ) || isset( $home['user'] ) || isset( $home['pass'] ) ) {
		return false;
	}

	$default_port = 'https' === $scheme ? 443 : 80;
	return 0 === strcasecmp( $target['host'], $home['host'] )
		&& ( $target['port'] ?? $default_port ) === ( $home['port'] ?? $default_port );
}

function dxsm_schema_preview_collect_types( $node, &$types ) {
	foreach ( $node as $key => $value ) {
		if ( '@type' === $key ) {
			if ( ! is_string( $value ) && ! is_array( $value ) ) {
				continue;
			}
			foreach ( (array) $value as $type ) {
				if ( is_string( $type ) && '' !== $type ) {
					$types[] = $type;
				}
			}
		} elseif ( '@context' !== $key && '@value' !== $key && ( is_object( $value ) || is_array( $value ) ) ) {
			dxsm_schema_preview_collect_types( $value, $types );
		}
	}
}

function dxsm_schema_preview_parse( $html ) {
	if ( ! class_exists( 'DOMDocument' ) ) {
		return new WP_Error( 'dxsm_schema_dom', 'Podgląd wymaga rozszerzenia DOM w PHP.' );
	}

	$dom = new DOMDocument();
	// Preserve UTF-8 in the legacy HTML parser even without a charset meta tag.
	$dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
	// Ten wariant nie generuje własnego JSON-LD, więc pokazuje wszystkie bloki ze strony (motyw, Elementor, cp-seo).
	$blocks = array();
	foreach ( $dom->getElementsByTagName( 'script' ) as $script ) {
		if ( 'application/ld+json' !== strtolower( trim( $script->getAttribute( 'type' ) ) ) ) {
			continue;
		}

		$data = json_decode( $script->textContent );
		if ( ! is_object( $data ) && ! is_array( $data ) ) {
			return new WP_Error( 'dxsm_schema_json', 'Jeden z bloków danych strukturalnych na stronie zawiera niepoprawny JSON-LD.' );
		}
		$blocks[] = $data;
	}

	if ( ! $blocks ) {
		return new WP_Error( 'dxsm_schema_missing', 'W publicznym HTML tej strony nie ma danych strukturalnych JSON-LD.' );
	}

	$data = 1 === count( $blocks ) ? $blocks[0] : $blocks;
	$json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	if ( false === $json ) {
		return new WP_Error( 'dxsm_schema_json', 'Nie udało się sformatować JSON-LD.' );
	}
	$types = array();
	dxsm_schema_preview_collect_types( $data, $types );
	return array( 'json' => $json, 'types' => array_values( array_unique( $types ) ) );
}

function dxsm_schema_preview_selection( $post_id ) {
	if ( ! current_user_can( 'manage_options' ) || ! is_int( $post_id ) || $post_id < 1 ) {
		return new WP_Error( 'dxsm_schema_post', 'Wybrany wpis lub strona są niedostępne.' );
	}

	$post = get_post( $post_id );
	if ( ! $post || 'publish' !== $post->post_status || '' !== $post->post_password || ! dxsm_seo_editor_supports( $post->post_type ) ) {
		return new WP_Error( 'dxsm_schema_post', 'Wybrany wpis lub strona są niedostępne.' );
	}

	$url = get_permalink( $post );
	if ( ! dxsm_schema_preview_same_origin( $url, home_url( '/' ) ) ) {
		return new WP_Error( 'dxsm_schema_origin', 'Wybrana treść nie ma dostępnego adresu HTTP(S) w tej witrynie.' );
	}

	$response = wp_safe_remote_get(
		$url,
		array(
			'timeout'             => 5,
			'redirection'         => 0,
			'limit_response_size' => 2 * 1024 * 1024,
			'headers'             => array(),
			'cookies'             => array(),
		)
	);
	$status = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
	if ( 200 === $status ) {
		$schema = dxsm_schema_preview_parse( wp_remote_retrieve_body( $response ) );
	} else {
		$message = $status ? sprintf( 'Publiczny adres zwrócił HTTP %d. Podgląd wymaga odpowiedzi HTTP 200.', $status ) : 'Nie udało się odczytać publicznego adresu.';
		$schema = new WP_Error( 'dxsm_schema_http', $message );
	}

	return array( 'post' => $post, 'url' => $url, 'schema' => $schema );
}

function dxsm_render_schema_preview( $post_id ) {
	$selection = dxsm_schema_preview_selection( $post_id );
	$schema = is_wp_error( $selection ) ? $selection : $selection['schema'];
	?>
	<section id="dxsm-schema-preview" aria-labelledby="dxsm-schema-heading">
		<h3 id="dxsm-schema-heading">Dane strukturalne</h3>
		<p>Wszystkie dane strukturalne JSON-LD odczytane z kodu publicznej strony.</p>

		<?php if ( is_wp_error( $schema ) ) : ?>
			<p><?php echo esc_html( $schema->get_error_message() ); ?></p>
		<?php else : ?>
			<?php if ( $schema['types'] ) : ?>
				<ul class="dxsm-admin-types" aria-label="Typy znaczników">
					<?php foreach ( $schema['types'] as $type ) : ?>
						<li><?php echo dxsm_escape_seo_text( $type ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p>Brak typów znaczników w odczytanym kodzie.</p>
			<?php endif; ?>
			<details class="dxsm-admin-code">
				<summary>Pełny kod JSON-LD</summary>
				<p><button type="button" class="button" data-dxsm-copy-json hidden>Kopiuj JSON-LD</button> <span data-dxsm-copy-status role="status"></span></p>
				<pre tabindex="0" aria-label="Kod JSON-LD"><?php echo dxsm_escape_seo_text( $schema['json'] ); ?></pre>
			</details>
		<?php endif; ?>
	</section>
	<?php
}
