<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/admin-settings.php';

// Wpisy i strony, a przy włączonym pełnym SEO także typy treści z jego mapy witryny (np. usługi, produkty).
function dxsm_seo_post_types() {
	$types = array( 'post', 'page' );
	if ( \DigitalXperts\SocialMeta\Plugin::full_seo() ) {
		foreach ( \DigitalXperts\SocialMeta\FullSeo\SEO::settings()['sitemap_post_types'] as $type ) {
			$object = get_post_type_object( $type );
			if ( $object && $object->public && 'attachment' !== $type ) {
				$types[] = $type;
			}
		}
	}
	return array_values( array_unique( (array) apply_filters( 'dxsm_seo_post_types', $types ) ) );
}

function dxsm_seo_editor_supports( $post_type ) {
	return in_array( $post_type, dxsm_seo_post_types(), true );
}

function dxsm_post_type_label( $post_type ) {
	$object = get_post_type_object( $post_type );
	return $object ? $object->labels->singular_name : $post_type;
}

function dxsm_get_seo_override( $post_id, $key ) {
	if ( ! $post_id || ! dxsm_seo_editor_supports( get_post_type( $post_id ) ) ) {
		return '';
	}

	$value = get_post_meta( $post_id, $key, true );
	return is_string( $value ) ? $value : '';
}

function dxsm_escape_seo_text( $value ) {
	// Preserve literal entity spellings in editor input and public metadata.
	return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}

function dxsm_filter_seo_document_title( $title ) {
	if ( is_admin() || is_feed() || is_404() || ( is_home() && is_front_page() ) || dxsm_is_automatic_request() ) {
		return $title;
	}

	$override = dxsm_get_seo_override( dxsm_get_context_post_id(), '_dxsm_seo_title' );
	// Core echoes the final document_title value directly inside the title element.
	return '' === $override ? $title : dxsm_escape_seo_text( dxsm_without_dashes( $override ) );
}
add_filter( 'document_title', 'dxsm_filter_seo_document_title', PHP_INT_MAX );
add_filter( 'document_title', 'dxsm_without_dashes', PHP_INT_MAX );
// Tytuł z pre_get_document_title omija filtr document_title.
add_filter( 'pre_get_document_title', 'dxsm_without_dashes', PHP_INT_MAX );

function dxsm_register_seo_metabox( $post_type, $post ) {
	if ( dxsm_seo_editor_supports( $post_type ) && current_user_can( 'edit_post', $post->ID ) ) {
		add_meta_box(
			'dxsm-seo-editor',
			'Podgląd SEO',
			'dxsm_render_seo_metabox',
			$post_type,
			'normal',
			'default',
			array( '__block_editor_compatible_meta_box' => true )
		);
	}
}
add_action( 'add_meta_boxes', 'dxsm_register_seo_metabox', 10, 2 );

function dxsm_enqueue_seo_editor( $hook_suffix ) {
	if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
		return;
	}

	$screen = get_current_screen();
	if ( $screen && dxsm_seo_editor_supports( $screen->post_type ) ) {
		dxsm_enqueue_seo_preview();
	}
}
add_action( 'admin_enqueue_scripts', 'dxsm_enqueue_seo_editor' );

function dxsm_enqueue_seo_preview() {
	wp_enqueue_style( 'dxsm-seo-editor', plugins_url( 'assets/seo-editor.css', DXSM_PLUGIN_FILE ), array(), DXSM_VERSION );
	wp_enqueue_script( 'dxsm-keyphrase', plugins_url( 'assets/keyphrase.js', DXSM_PLUGIN_FILE ), array(), DXSM_VERSION, true );
	wp_enqueue_script( 'dxsm-seo-editor', plugins_url( 'assets/seo-editor.js', DXSM_PLUGIN_FILE ), array( 'dxsm-keyphrase' ), DXSM_VERSION, true );
}

function dxsm_save_seo_metabox( $post_id, $post ) {
	if ( ! dxsm_seo_editor_supports( $post->post_type )
		|| ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
		|| wp_is_post_autosave( $post_id )
		|| wp_is_post_revision( $post_id )
		|| ! current_user_can( 'edit_post', $post_id )
		|| ! isset( $_POST['_dxsm_seo_nonce'] )
		|| ! is_string( $_POST['_dxsm_seo_nonce'] )
		|| ! wp_verify_nonce( wp_unslash( $_POST['_dxsm_seo_nonce'] ), 'dxsm_seo_' . $post_id ) ) {
		return;
	}

	dxsm_store_seo_fields( $post_id, $_POST );
}
add_action( 'save_post', 'dxsm_save_seo_metabox', 10, 2 );

function dxsm_store_seo_fields( $post_id, $fields ) {
	$saved = true;
	foreach ( array( '_dxsm_seo_title', '_dxsm_seo_description', '_dxsm_seo_keyphrase' ) as $key ) {
		if ( ! isset( $fields[ $key ] ) || ! is_string( $fields[ $key ] ) ) {
			continue;
		}

		$value = dxsm_without_dashes( sanitize_text_field( wp_unslash( $fields[ $key ] ) ) );
		if ( '' === $value ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, wp_slash( $value ) );
		}
		$saved = $value === get_post_meta( $post_id, $key, true ) && $saved;
	}
	return $saved;
}

function dxsm_get_editor_automatic_title( $post, $is_static_front ) {
	// Pełne SEO ustawia tytuł przez pre_get_document_title (pole modułu, mapa tytułów, SEOPress): ten sam tytuł w podglądzie.
	if ( \DigitalXperts\SocialMeta\Plugin::full_seo() && \DigitalXperts\SocialMeta\FullSeo\SEO::enabled() ) {
		$module = \DigitalXperts\SocialMeta\FullSeo\SEO::expand( \DigitalXperts\SocialMeta\FullSeo\SEO::post_value( $post->ID, 'title', true ), $post );
		if ( '' !== $module ) {
			// Wynik przechodzi dalej przez html_entity_decode jak tytuł WordPressa, więc oddajemy go zakodowany.
			return dxsm_escape_seo_text( dxsm_without_dashes( $module ) );
		}
	}
	$site_name = get_bloginfo( 'name', 'display' );
	$parts = $is_static_front
		? array( 'title' => $site_name, 'tagline' => get_bloginfo( 'description', 'display' ) )
		: array( 'title' => apply_filters( 'single_post_title', $post->post_title, $post ), 'site' => $site_name );
	$separator = apply_filters( 'document_title_separator', '-' );
	$parts = apply_filters( 'document_title_parts', $parts );

	return apply_filters( 'document_title', implode( " $separator ", array_filter( $parts ) ) );
}

function dxsm_get_seo_preview_data( $post ) {
	if ( ! $post ) {
		return array(
			'post_id'               => 0,
			'title'                 => '',
			'description'           => '',
			'automatic_title'       => 'Przykładowy tytuł wpisu',
			'automatic_description' => 'Tak może wyglądać opis wpisu w wynikach Google i w karcie udostępniania.',
			'url'                   => home_url( '/' ),
			'keyphrase'             => '',
			'slug'                  => '',
			'content'               => '',
			'post_title'            => '',
		);
	}

	$post_id = (int) $post->ID;
	$uses_static_pages = 'page' === get_option( 'show_on_front' );
	$is_static_front = $uses_static_pages && $post_id === (int) get_option( 'page_on_front' );
	$is_posts_page = $uses_static_pages && $post_id === (int) get_option( 'page_for_posts' );

	return array(
		'post_id'               => $post_id,
		'title'                 => dxsm_get_seo_override( $post_id, '_dxsm_seo_title' ),
		'description'           => dxsm_get_seo_override( $post_id, '_dxsm_seo_description' ),
		'automatic_title'       => html_entity_decode( wp_strip_all_tags( dxsm_get_editor_automatic_title( $post, $is_static_front ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
		// Opis automatyczny jest już zwykłym tekstem (parser zwraca zdekodowany atrybut).
		'automatic_description' => dxsm_get_automatic_description( $post_id, $is_posts_page ),
		'url'                   => get_permalink( $post ),
		'keyphrase'             => dxsm_get_seo_override( $post_id, '_dxsm_seo_keyphrase' ),
		// Strona główna, szkice i proste odnośniki (?p=123) nie mają nazwy wpisu w adresie: kontrola adresu ich nie dotyczy.
		'slug'                  => $is_static_front || '' === $post->post_name || false === strpos( (string) get_permalink( $post ), '/' . $post->post_name ) ? null : $post->post_name,
		'content'               => $post->post_content,
		'post_title'            => $post->post_title,
	);
}

function dxsm_render_seo_metabox( $post ) {
	$preview = dxsm_get_seo_preview_data( $post );
	$settings = dxsm_sanitize_editor_settings( get_option( 'dxsm_editor_settings' ) );

	wp_nonce_field( 'dxsm_seo_' . $preview['post_id'], '_dxsm_seo_nonce', false );
	?>
	<div class="dxsm-seo-editor" data-dxsm-seo>
		<?php dxsm_render_seo_fields( $preview ); ?>
		<p class="description">Automatyczne dane z treści i grafika w podglądzie odświeżą się po zapisaniu i ponownym otwarciu edycji.</p>
		<p class="description">Symulacje podglądu</p>
		<?php dxsm_render_seo_previews( $preview, $settings['google_mode'], $settings['show_social'] ); ?>
	</div>
	<?php
}

function dxsm_render_seo_fields( $preview ) {
	?>
		<label for="dxsm-seo-title">Tytuł SEO</label>
		<input type="text" id="dxsm-seo-title" name="_dxsm_seo_title" data-seo-input="title"
			value="<?php echo dxsm_escape_seo_text( $preview['title'] ); ?>"
			data-automatic="<?php echo dxsm_escape_seo_text( $preview['automatic_title'] ); ?>"
			placeholder="<?php echo dxsm_escape_seo_text( $preview['automatic_title'] ); ?>"
			aria-describedby="dxsm-seo-help">
		<label for="dxsm-seo-description">Opis meta</label>
		<textarea id="dxsm-seo-description" name="_dxsm_seo_description" data-seo-input="description"
			rows="3" data-automatic="<?php echo dxsm_escape_seo_text( $preview['automatic_description'] ); ?>"
			placeholder="<?php echo dxsm_escape_seo_text( $preview['automatic_description'] ); ?>"
			aria-describedby="dxsm-seo-help"><?php echo dxsm_escape_seo_text( $preview['description'] ); ?></textarea>
		<p id="dxsm-seo-help" class="description">Puste pole używa wartości automatycznej.</p>
		<?php if ( $preview['post_id'] ) : ?>
			<label for="dxsm-seo-keyphrase">Fraza kluczowa</label>
			<input type="text" id="dxsm-seo-keyphrase" name="_dxsm_seo_keyphrase" data-seo-keyphrase
				value="<?php echo dxsm_escape_seo_text( $preview['keyphrase'] ); ?>" aria-describedby="dxsm-seo-keyphrase-help">
			<p id="dxsm-seo-keyphrase-help" class="description">Wpisz słowa, pod które piszesz tę treść. Odmiana liczy się sama: „usługa” znajdzie też „usługi” i „usłudze”.</p>
			<ul class="dxsm-seo-keyphrase" data-seo-keyphrase-checks aria-live="polite"></ul>
			<textarea hidden data-seo-content data-post-title="<?php echo esc_attr( $preview['post_title'] ); ?>"<?php echo null === $preview['slug'] ? '' : ' data-slug="' . esc_attr( $preview['slug'] ) . '"'; ?>><?php echo esc_textarea( $preview['content'] ); ?></textarea>
		<?php endif; ?>
	<?php
}

function dxsm_render_seo_previews( $preview, $google_mode, $show_social, $view = 'all' ) {
	$preview_title = dxsm_escape_seo_text( '' === $preview['title'] ? $preview['automatic_title'] : $preview['title'] );
	$preview_description = dxsm_escape_seo_text( '' === $preview['description'] ? $preview['automatic_description'] : $preview['description'] );
	$url = $preview['url'];
	?>
	<?php if ( 'social' !== $view ) : ?>
		<fieldset class="dxsm-seo-google">
			<legend>Google</legend>
			<div class="dxsm-seo-modes">
				<label><input type="radio" name="dxsm_seo_google_mode" value="phone" data-seo-mode<?php checked( $google_mode, 'phone' ); ?>> Telefon</label>
				<label><input type="radio" name="dxsm_seo_google_mode" value="desktop" data-seo-mode<?php checked( $google_mode, 'desktop' ); ?>> Komputer</label>
			</div>
			<div class="dxsm-seo-result" data-seo-google data-mode="<?php echo esc_attr( $google_mode ); ?>">
				<?php if ( 'google' === $view ) : ?>
					<?php $site_icon = get_site_icon_url( 32 ); ?>
					<div class="dxsm-admin-google-source">
						<?php if ( $site_icon ) : ?>
							<img src="<?php echo esc_url( $site_icon ); ?>" width="24" height="24" alt="">
						<?php endif; ?>
						<div>
							<p><?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>
							<p class="dxsm-seo-url"><?php echo esc_url( $url ); ?></p>
						</div>
					</div>
				<?php else : ?>
					<p class="dxsm-seo-url"><?php echo esc_url( $url ); ?></p>
				<?php endif; ?>
				<p class="dxsm-seo-title" data-seo-preview="title"><?php echo $preview_title; ?></p>
				<p data-seo-preview="description"><?php echo $preview_description; ?></p>
			</div>
		</fieldset>
	<?php
	endif;
	if ( ! $show_social || 'google' === $view ) {
		return;
	}

	$domain = wp_parse_url( $url ?: home_url( '/' ), PHP_URL_HOST );
	$social_image = $preview['post_id'] ? dxsm_get_social_image( $preview['post_id'] ) : false;
	if ( ! $social_image && 'social' === $view ) {
		echo '<p>Brak grafiki udostępniania. Ustaw obrazek wyróżniający w edycji tej treści, aby Social Meta mogła wygenerować karty.</p>';
		return;
	}
	if ( $social_image ) {
		list( , $image, $image_alt ) = $social_image;
	}
	?>
		<div class="dxsm-seo-cards">
			<?php foreach ( array( 'facebook' => 'Facebook', 'linkedin' => 'LinkedIn', 'x' => 'X' ) as $platform => $label ) : ?>
				<section class="dxsm-seo-card dxsm-seo-card--<?php echo esc_attr( $platform ); ?>">
					<h3><?php echo esc_html( $label ); ?></h3>
					<?php if ( $social_image ) : ?>
						<img src="<?php echo esc_url( $image[0] ); ?>" alt="<?php echo esc_attr( $image_alt ); ?>">
					<?php else : ?>
						<p class="dxsm-seo-no-image">Brak grafiki udostępniania</p>
					<?php endif; ?>
					<div class="dxsm-seo-card-text">
						<p class="dxsm-seo-domain"><?php echo esc_html( $domain ); ?></p>
						<p class="dxsm-seo-title" data-seo-preview="title"><?php echo $preview_title; ?></p>
						<p data-seo-preview="description"><?php echo $preview_description; ?></p>
					</div>
				</section>
			<?php endforeach; ?>
		</div>
	<?php
}
