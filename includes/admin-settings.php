<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/schema-preview.php';

const DXSM_PICKER_PAGE_SIZE = 8;

function dxsm_sanitize_editor_settings( $settings ) {
	$settings = is_array( $settings ) ? $settings : array();

	return array(
		'google_mode' => 'phone' === ( $settings['google_mode'] ?? '' ) ? 'phone' : 'desktop',
		'show_social' => in_array( $settings['show_social'] ?? 1, array( 0, '0' ), true ) ? 0 : 1,
	);
}

function dxsm_register_editor_settings() {
	register_setting(
		'dxsm_editor_settings',
		'dxsm_editor_settings',
		array(
			'type'              => 'array',
			'sanitize_callback' => 'dxsm_sanitize_editor_settings',
			'default'           => dxsm_sanitize_editor_settings( array() ),
		)
	);
}
add_action( 'admin_init', 'dxsm_register_editor_settings' );

function dxsm_register_social_meta_menu() {
	$page = add_menu_page( 'Social Meta', 'Social Meta', 'manage_options', 'dxsm-social-meta', 'dxsm_render_social_meta_page', 'dashicons-share' );
	add_action( 'load-' . $page, 'dxsm_enqueue_social_meta_admin' );
}
add_action( 'admin_menu', 'dxsm_register_social_meta_menu' );

function dxsm_social_meta_plugin_links( $links ) {
	if ( current_user_can( 'manage_options' ) ) {
		array_unshift( $links, '<a href="' . esc_url( dxsm_admin_url( array( 'dxsm_view' => 'settings' ) ) ) . '">Ustawienia</a>' );
	}

	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( DXSM_PLUGIN_FILE ), 'dxsm_social_meta_plugin_links' );

// Reklamy i powiadomienia innych wtyczek nie wchodzą do panelu. Zostają ostrzeżenia WordPressa o awarii:
// tryb odzyskiwania, wstrzymana albo wyłączona wtyczka, wstrzymany motyw, niedokończona aktualizacja.
function dxsm_remove_foreign_notices() {
	foreach ( array( 'admin_notices', 'all_admin_notices', 'user_admin_notices', 'network_admin_notices' ) as $hook ) {
		remove_all_actions( $hook );
	}
	foreach ( array( 'wp_recovery_mode_nag' => 1, 'deactivated_plugins_notice' => 5, 'paused_plugins_notice' => 5, 'paused_themes_notice' => 5, 'maintenance_nag' => 10 ) as $callback => $priority ) {
		if ( function_exists( $callback ) ) {
			add_action( 'admin_notices', $callback, $priority );
		}
	}
}

function dxsm_enqueue_social_meta_admin() {
	add_action( 'in_admin_header', 'dxsm_remove_foreign_notices', PHP_INT_MAX );
	dxsm_enqueue_seo_preview();
	wp_enqueue_style( 'dxsm-social-meta-admin', plugins_url( 'assets/social-meta-admin.css', DXSM_PLUGIN_FILE ), array(), DXSM_VERSION );
	wp_enqueue_script( 'dxsm-social-meta-admin', plugins_url( 'assets/social-meta-admin.js', DXSM_PLUGIN_FILE ), array(), DXSM_VERSION, true );
}

function dxsm_admin_url( $args = array(), $fragment = '' ) {
	return add_query_arg( array( 'page' => 'dxsm-social-meta' ) + $args, admin_url( 'admin.php' ) ) . $fragment;
}

function dxsm_admin_positive_int( $key ) {
	return (int) filter_var( dxsm_schema_preview_get_field( $key ), FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
}

function dxsm_admin_public_content( $post ) {
	return $post && 'publish' === $post->post_status && '' === $post->post_password && dxsm_seo_editor_supports( $post->post_type );
}

function dxsm_admin_type_choices() {
	$choices = array( 'all' => 'Wszystkie', 'post' => 'Wpisy', 'page' => 'Strony' );
	foreach ( dxsm_seo_post_types() as $type ) {
		if ( ! isset( $choices[ $type ] ) ) {
			$object           = get_post_type_object( $type );
			$choices[ $type ] = $object ? $object->labels->name : $type;
		}
	}
	return $choices;
}

function dxsm_admin_picker_filters() {
	$type = dxsm_schema_preview_get_field( 'dxsm_schema_type' );
	$seo = dxsm_schema_preview_get_field( 'dxsm_seo_filter' );
	$score = (string) dxsm_schema_preview_get_field( 'dxsm_score' );
	$year = dxsm_admin_positive_int( 'dxsm_year' );
	$month = dxsm_admin_positive_int( 'dxsm_month' );
	return array(
		'search' => dxsm_schema_preview_get_field( 'dxsm_schema_search' ) ?? '',
		'type'   => in_array( $type, dxsm_seo_post_types(), true ) ? $type : 'all',
		'sort'   => 'title' === dxsm_schema_preview_get_field( 'dxsm_sort' ) ? 'title' : 'recent',
		'seo'    => in_array( $seo, array( 'any', 'title', 'description', 'both', 'keyphrase' ), true ) ? $seo : '',
		'score'  => preg_match( '/^(seo|readability)-(good|ok|bad|none)$/D', $score ) ? $score : '',
		'year'   => $year >= 1000 && $year <= 9999 ? $year : 0,
		'month'  => $month <= 12 ? $month : 0,
		'page'   => max( 1, min( intdiv( PHP_INT_MAX, DXSM_PICKER_PAGE_SIZE ), dxsm_admin_positive_int( 'dxsm_results_page' ) ) ),
	);
}

function dxsm_admin_context( $view, $filters ) {
	$context = array( 'dxsm_view' => $view );
	if ( '' !== $filters['search'] ) {
		$context['dxsm_schema_search'] = rawurlencode( $filters['search'] );
	}
	if ( 'all' !== $filters['type'] ) {
		$context['dxsm_schema_type'] = $filters['type'];
	}
	if ( 'title' === $filters['sort'] ) {
		$context['dxsm_sort'] = 'title';
	}
	foreach ( array( 'seo' => 'dxsm_seo_filter', 'score' => 'dxsm_score', 'year' => 'dxsm_year', 'month' => 'dxsm_month' ) as $key => $param ) {
		if ( $filters[ $key ] ) {
			$context[ $param ] = $filters[ $key ];
		}
	}
	if ( $filters['page'] > 1 ) {
		$context['dxsm_results_page'] = $filters['page'];
	}
	return $context;
}

function dxsm_handle_admin_seo_save() {
	$raw_id = $_POST['dxsm_post_id'] ?? null;
	$post_id = is_string( $raw_id ) ? filter_var( wp_unslash( $raw_id ), FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) ) : false;
	$nonce = $_POST['_dxsm_panel_nonce'] ?? null;
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! $post_id
		|| ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $post_id )
		|| ! is_string( $nonce ) || ! wp_verify_nonce( wp_unslash( $nonce ), 'dxsm_panel_save_' . $post_id ) ) {
		wp_die( 'Brak uprawnień do wykonania tej akcji SEO.', '', array( 'response' => 403 ) );
	}

	$mode = $_POST['dxsm_save_mode'] ?? '';
	$previous = 'previous' === $mode;
	$without_save = $previous || 'skip' === $mode;
	if ( ! dxsm_admin_public_content( get_post( $post_id ) ) ) {
		wp_die( 'Ta treść jest niedostępna w panelu SEO.', '', array( 'response' => 400 ) );
	}

	$filters = dxsm_admin_picker_filters();
	$navigate = $without_save || 'next' === $mode;
	$before = $navigate ? dxsm_admin_picker_ids( $filters ) : array();
	$status = '';
	if ( ! $without_save ) {
		if ( ! is_string( $_POST['_dxsm_seo_title'] ?? null ) || ! is_string( $_POST['_dxsm_seo_description'] ?? null ) ) {
			wp_die( 'Nie można zapisać danych SEO tej treści.', '', array( 'response' => 400 ) );
		}
		$status = dxsm_store_seo_fields( $post_id, $_POST ) ? 'yes' : 'error';
		clean_post_cache( $post_id );
		do_action( 'litespeed_purge_post', $post_id );
	}

	if ( $navigate && 'error' !== $status ) {
		$position = array_search( $post_id, $before, true );
		if ( $previous ) {
			$eligible = false === $position ? array() : array_slice( $before, 0, $position );
			$status = 'previous_end';
		} else {
			$eligible = false === $position ? $before : array_slice( $before, $position + 1 );
			$status = $without_save ? 'skip_end' : 'end';
		}

		if ( $eligible ) {
			$eligible = array_fill_keys( $eligible, true );
			$ordered = $without_save ? $before : dxsm_admin_picker_ids( $filters );
			if ( $previous ) {
				$ordered = array_reverse( $ordered, true );
			}
			foreach ( $ordered as $index => $candidate ) {
				if ( isset( $eligible[ $candidate ] ) && current_user_can( 'edit_post', $candidate ) ) {
					$post_id = $candidate;
					$filters['page'] = intdiv( $index, DXSM_PICKER_PAGE_SIZE ) + 1;
					$status = $previous ? 'previous' : ( $without_save ? 'skipped' : 'next' );
					break;
				}
			}
		}
	}

	$context = dxsm_admin_context(
		'social' === ( $_POST['dxsm_return_view'] ?? '' ) ? 'social' : 'google',
		$filters
	);
	$url = dxsm_admin_url( $context + array( 'dxsm_schema_post' => $post_id, 'dxsm_saved' => $status ), '#dxsm-admin-preview' );
	wp_safe_redirect( $url, 303 );
	exit;
}
add_action( 'admin_post_dxsm_save_seo', 'dxsm_handle_admin_seo_save' );

function dxsm_admin_post_title( $post ) {
	$title = get_the_title( $post );
	return '' === $title ? '(bez tytułu)' : $title;
}

function dxsm_render_social_meta_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Nie masz uprawnień do zarządzania ustawieniami Social Meta.', '', array( 'response' => 403 ) );
	}

	$views = array( 'google' => 'Google', 'social' => 'Media społecznościowe', 'schema' => 'Dane strukturalne' );
	$view = dxsm_schema_preview_get_field( 'dxsm_view' );
	$settings_view = 'settings' === $view;
	// Kokpit jest ekranem startowym. Lista treści otwiera się z własnego odnośnika (dxsm_open), z wybraną treścią albo z filtrem.
	$dashboard_view = ! $settings_view && ! isset( $views[ $view ] ) && ! isset( $_GET['dxsm_schema_post'] ) && ! isset( $_GET['dxsm_open'] ) && ! isset( $_GET['dxsm_resume'] );
	if ( ! $settings_view && ! isset( $views[ $view ] ) ) {
		$view = isset( $_GET['dxsm_schema_post'] ) ? 'schema' : 'google';
	}
	$settings = dxsm_sanitize_editor_settings( get_option( 'dxsm_editor_settings' ) );
	?>
	<div class="wrap dxsm-admin">
		<header class="dxsm-admin-header">
			<h1>Social Meta</h1>
			<nav class="dxsm-admin-nav" aria-label="Social Meta">
				<a href="<?php echo esc_url( dxsm_admin_url() ); ?>"<?php echo $dashboard_view ? ' aria-current="page"' : ''; ?>>Kokpit</a>
				<a href="<?php echo esc_url( dxsm_admin_url( array( 'dxsm_open' => 1 ) ) ); ?>"<?php echo $settings_view || $dashboard_view ? '' : ' aria-current="page"'; ?>>Treści</a>
				<a href="<?php echo esc_url( dxsm_admin_url( array( 'dxsm_view' => 'settings' ) ) ); ?>"<?php echo $settings_view ? ' aria-current="page"' : ''; ?>>Ustawienia</a>
			</nav>
		</header>
		<?php // WordPress przenosi komunikaty za ten znacznik, czyli pod nagłówek, a nie między tytuł a zakładki. ?>
		<hr class="wp-header-end">
		<?php
		if ( $settings_view ) {
			dxsm_render_admin_settings( $settings );
		} elseif ( $dashboard_view ) {
			dxsm_render_admin_dashboard();
		} else {
			dxsm_render_admin_workspace( $view, $views, $settings );
		}
		?>
	</div>
	<?php
}

function dxsm_render_admin_settings( $settings ) {
	?>
	<?php // Własne potwierdzenie po powrocie z options.php: settings_errors() wypisałoby też komunikaty ustawień innych wtyczek. ?>
	<?php if ( isset( $_GET['settings-updated'] ) && 'true' === $_GET['settings-updated'] ) : ?>
		<?php
		// options.php zgłasza nieudany zapis tym samym kodem co udany, tylko z typem „error”.
		$dxsm_save_failed = false;
		foreach ( get_settings_errors( 'general' ) as $dxsm_settings_error ) {
			if ( 'settings_updated' === $dxsm_settings_error['code'] && 'error' === $dxsm_settings_error['type'] ) {
				$dxsm_save_failed = true;
			}
		}
		?>
		<?php if ( $dxsm_save_failed ) : ?>
			<div class="notice notice-error inline" role="alert"><p>Nie udało się zapisać ustawień.</p></div>
		<?php else : ?>
			<div class="notice notice-success inline" role="status"><p>Zapisano ustawienia.</p></div>
		<?php endif; ?>
	<?php endif; ?>
	<?php \DigitalXperts\SocialMeta\Plugin::render_share_settings(); ?>
	<section class="dxsm-admin-settings dxsm-admin-panel" aria-labelledby="dxsm-admin-settings-heading">
		<h2 id="dxsm-admin-settings-heading">Ustawienia edytora</h2>
		<p>Ustaw domyślny podgląd w sekcji „Podgląd SEO” podczas edycji wpisu lub strony.</p>
		<form method="post" action="options.php">
			<?php settings_fields( 'dxsm_editor_settings' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="dxsm-editor-google-mode">Domyślny widok Google</label></th>
					<td>
						<select id="dxsm-editor-google-mode" name="dxsm_editor_settings[google_mode]">
							<option value="desktop"<?php selected( $settings['google_mode'], 'desktop' ); ?>>Komputer</option>
							<option value="phone"<?php selected( $settings['google_mode'], 'phone' ); ?>>Telefon</option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row">Media społecznościowe</th>
					<td>
						<input type="hidden" name="dxsm_editor_settings[show_social]" value="0">
						<label><input type="checkbox" name="dxsm_editor_settings[show_social]" value="1"<?php checked( $settings['show_social'], 1 ); ?>> Pokaż podglądy kart w edytorze</label>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Zapisz ustawienia' ); ?>
		</form>
	</section>
	<?php
}

function dxsm_query_admin_picker( &$filters, $ids_only = false ) {
	$search = $filters['search'];
	$query_args = array(
		'post_type'           => 'all' === $filters['type'] ? dxsm_seo_post_types() : $filters['type'],
		'post_status'         => 'publish',
		'has_password'        => false,
		'ignore_sticky_posts' => true,
		'posts_per_page'      => DXSM_PICKER_PAGE_SIZE,
		'paged'               => $filters['page'],
		'search_columns'      => array( 'post_title' ),
		'orderby'             => 'title' === $filters['sort'] ? array( 'title' => 'ASC', 'ID' => 'ASC' ) : array( 'date' => 'DESC', 'ID' => 'DESC' ),
		'year'                => $filters['year'],
		'monthnum'            => $filters['month'],
	);
	$meta_filters = array();
	if ( $filters['seo'] ) {
		$meta_query = array( 'relation' => 'any' === $filters['seo'] ? 'OR' : 'AND' );
		foreach ( array( 'title' => '_dxsm_seo_title', 'description' => '_dxsm_seo_description', 'keyphrase' => '_dxsm_seo_keyphrase' ) as $field => $key ) {
			// Fraza kluczowa ma własny filtr i nie wchodzi do „brakuje tytułu lub opisu”.
			if ( $filters['seo'] === $field || ( 'keyphrase' !== $field && in_array( $filters['seo'], array( 'any', 'both' ), true ) ) ) {
				$meta_query[] = array(
					'relation' => 'OR',
					array( 'key' => $key, 'compare' => 'NOT EXISTS' ),
					array( 'key' => $key, 'value' => '', 'compare' => '=' ),
				);
			}
		}
		$meta_filters[] = $meta_query;
	}
	if ( $filters['score'] ) {
		list( $kind, $rating ) = explode( '-', $filters['score'] );
		$meta_filters[] = 'none' === $rating
			? array(
				'relation' => 'OR',
				array( 'key' => DXSM_SCORE_KEYS[ $kind ], 'compare' => 'NOT EXISTS' ),
				array( 'key' => DXSM_SCORE_KEYS[ $kind ], 'value' => array( 'good', 'ok', 'bad' ), 'compare' => 'NOT IN' ),
			)
			: array( array( 'key' => DXSM_SCORE_KEYS[ $kind ], 'value' => $rating ) );
	}
	if ( $meta_filters ) {
		$query_args['meta_query'] = array_merge( array( 'relation' => 'AND' ), $meta_filters );
	}
	$id = ctype_digit( $search ) ? (int) $search : 0;
	if ( preg_match( '~^https?://~i', $search ) ) {
		$query_args['post__in'] = array( dxsm_schema_preview_same_origin( $search, home_url( '/' ) ) ? url_to_postid( $search ) : 0 );
	} elseif ( $id && dxsm_admin_public_content( get_post( $id ) ) ) {
		$query_args['post__in'] = array( $id );
	} else {
		$query_args['s'] = wp_slash( $search );
	}

	if ( $ids_only ) {
		$query_args['fields'] = 'ids';
		$query_args['nopaging'] = true;
		$query_args['no_found_rows'] = true;
		return new WP_Query( $query_args );
	}

	$query = new WP_Query( $query_args );
	if ( $filters['page'] > 1 && ! $query->posts ) {
		$filters['page'] = 1;
		$query_args['paged'] = 1;
		$query = new WP_Query( $query_args );
	}
	return $query;
}

function dxsm_admin_picker_ids( $filters ) {
	return array_map( 'intval', dxsm_query_admin_picker( $filters, true )->posts );
}

function dxsm_ajax_admin_picker() {
	if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'dxsm_filter_content', 'nonce', false ) ) {
		wp_send_json_error( null, 403 );
	}
	$filters = dxsm_admin_picker_filters();
	$view = dxsm_schema_preview_get_field( 'dxsm_view' );
	$view = in_array( $view, array( 'social', 'schema' ), true ) ? $view : 'google';
	$query = dxsm_query_admin_picker( $filters );
	$context = dxsm_admin_context( $view, $filters );
	ob_start();
	dxsm_render_admin_picker_results( $query, $filters, $context, dxsm_admin_positive_int( 'dxsm_schema_post' ) );
	unset( $context['dxsm_view'] );
	if ( isset( $context['dxsm_schema_search'] ) ) {
		$context['dxsm_schema_search'] = $filters['search'];
	}
	wp_send_json_success( array( 'html' => ob_get_clean(), 'filters' => $context, 'page' => $filters['page'], 'total' => (int) $query->found_posts ) );
}
add_action( 'wp_ajax_dxsm_filter_content', 'dxsm_ajax_admin_picker' );

function dxsm_render_admin_workspace( $view, $views, $settings ) {
	$filters = dxsm_admin_picker_filters();
	$query = dxsm_query_admin_picker( $filters );
	$context = dxsm_admin_context( $view, $filters );

	$has_selection = isset( $_GET['dxsm_schema_post'] );
	$post_id = dxsm_admin_positive_int( 'dxsm_schema_post' );
	$post = $has_selection ? ( $post_id ? get_post( $post_id ) : null ) : ( $query->posts[0] ?? null );
	if ( ! dxsm_admin_public_content( $post ) ) {
		$post = '1' === dxsm_schema_preview_get_field( 'dxsm_resume' ) ? ( $query->posts[0] ?? null ) : null;
	}
	$selected_id = $post ? (int) $post->ID : 0;
	$reset_args = array( 'dxsm_view' => $view ) + ( $selected_id ? array( 'dxsm_schema_post' => $selected_id ) : array() );
	?>
	<div class="dxsm-admin-workspace">
		<aside class="dxsm-admin-picker" aria-labelledby="dxsm-admin-picker-heading">
			<h2 id="dxsm-admin-picker-heading">Wybierz wpis lub stronę</h2>
			<form method="get" class="dxsm-admin-search" action="<?php echo esc_url( admin_url( 'admin.php' ) . '#dxsm-admin-results' ); ?>"
				data-resume-key="<?php echo esc_attr( 'dxsm-workspace:' . home_url( '/' ) . ':' . get_current_user_id() ); ?>" data-results-page="<?php echo esc_attr( $filters['page'] ); ?>"
				data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'dxsm_filter_content' ) ); ?>">
				<input type="hidden" name="page" value="dxsm-social-meta">
				<input type="hidden" name="dxsm_view" value="<?php echo esc_attr( $view ); ?>">
				<?php if ( $selected_id ) : ?>
					<input type="hidden" name="dxsm_schema_post" value="<?php echo esc_attr( $selected_id ); ?>">
				<?php endif; ?>
				<div class="dxsm-admin-search-fields">
					<div>
						<label for="dxsm-schema-search">Szukaj treści</label>
						<input type="search" id="dxsm-schema-search" name="dxsm_schema_search" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="Tytuł, ID lub adres URL">
					</div>
					<fieldset class="dxsm-admin-filter">
						<legend class="screen-reader-text">Rodzaj treści</legend>
						<div>
							<?php foreach ( dxsm_admin_type_choices() as $value => $label ) : ?>
								<label><input type="radio" name="dxsm_schema_type" value="<?php echo esc_attr( $value ); ?>"<?php checked( $filters['type'], $value ); ?>><span><?php echo esc_html( $label ); ?></span></label>
							<?php endforeach; ?>
						</div>
					</fieldset>
					<div class="dxsm-admin-sort">
						<label for="dxsm-sort">Sortuj</label>
						<select id="dxsm-sort" name="dxsm_sort">
							<option value="recent"<?php selected( $filters['sort'], 'recent' ); ?>>Od najnowszych</option>
							<option value="title"<?php selected( $filters['sort'], 'title' ); ?>>Tytuł A-Z</option>
						</select>
					</div>
					<?php dxsm_render_admin_extra_filters( $filters ); ?>
					<div class="dxsm-admin-search-actions">
						<button type="submit" class="button">Szukaj</button>
						<a href="<?php echo esc_url( dxsm_admin_url( $reset_args, '#dxsm-admin-results' ) ); ?>" data-dxsm-clear>Wyczyść filtry</a>
					</div>
				</div>
			</form>
			<p class="dxsm-admin-search-status" role="status" data-dxsm-search-status></p>
			<div data-dxsm-results>
				<?php dxsm_render_admin_picker_results( $query, $filters, $context, $selected_id ); ?>
			</div>
		</aside>
		<section class="dxsm-admin-preview" aria-labelledby="dxsm-admin-preview">
			<?php if ( $post ) : ?>
				<?php dxsm_render_admin_preview( $post, $view, $views, $context, $settings ); ?>
			<?php else : ?>
				<div class="dxsm-admin-panel dxsm-admin-empty">
					<h2 id="dxsm-admin-preview" tabindex="-1">Podgląd treści</h2>
					<p><?php echo $has_selection ? 'Wybrana treść jest niedostępna. Wybierz opublikowany wpis lub stronę bez hasła.' : 'Wyszukaj opublikowany wpis lub stronę, aby otworzyć podgląd.'; ?></p>
				</div>
			<?php endif; ?>
		</section>
	</div>
	<?php
}

function dxsm_render_admin_extra_filters( $filters ) {
	global $wpdb, $wp_locale;

	$years = array_map( 'intval', $wpdb->get_col( "SELECT DISTINCT YEAR(post_date) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('" . implode( "', '", array_map( 'esc_sql', dxsm_seo_post_types() ) ) . "') AND post_password = '' AND YEAR(post_date) > 0 ORDER BY YEAR(post_date) DESC" ) );
	if ( $filters['year'] && ! in_array( $filters['year'], $years, true ) ) {
		$years[] = $filters['year'];
		rsort( $years );
	}
	?>
	<div>
		<label for="dxsm-seo-filter">Własne pola SEO</label>
		<select id="dxsm-seo-filter" name="dxsm_seo_filter">
			<?php foreach ( array( '' => 'Dowolny stan SEO', 'any' => 'Brakuje tytułu lub opisu', 'title' => 'Brakuje tytułu SEO', 'description' => 'Brakuje opisu meta', 'both' => 'Brakuje obu pól', 'keyphrase' => 'Brakuje frazy kluczowej' ) as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>"<?php selected( $filters['seo'], $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
	</div>
	<div>
		<label for="dxsm-score">Ocena</label>
		<select id="dxsm-score" name="dxsm_score">
			<option value="">Dowolna ocena</option>
			<?php foreach ( array( 'seo' => 'SEO', 'readability' => 'Czytelność' ) as $kind => $kind_label ) : ?>
				<?php foreach ( DXSM_RATINGS as $rating => $label ) : ?>
					<option value="<?php echo esc_attr( $kind . '-' . $rating ); ?>"<?php selected( $filters['score'], $kind . '-' . $rating ); ?>><?php echo esc_html( $kind_label . ': ' . $label ); ?></option>
				<?php endforeach; ?>
			<?php endforeach; ?>
		</select>
	</div>
	<div class="dxsm-admin-date-filters">
		<div>
			<label for="dxsm-year">Rok publikacji</label>
			<select id="dxsm-year" name="dxsm_year">
				<option value="">Dowolny</option>
				<?php foreach ( $years as $year ) : ?>
					<option value="<?php echo esc_attr( $year ); ?>"<?php selected( $filters['year'], $year ); ?>><?php echo esc_html( $year ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<div>
			<label for="dxsm-month">Miesiąc publikacji</label>
			<select id="dxsm-month" name="dxsm_month">
				<option value="">Dowolny</option>
				<?php for ( $month = 1; $month <= 12; $month++ ) : ?>
					<option value="<?php echo esc_attr( $month ); ?>"<?php selected( $filters['month'], $month ); ?>><?php echo esc_html( $wp_locale->get_month( $month ) ); ?></option>
				<?php endfor; ?>
			</select>
		</div>
	</div>
	<?php
}

function dxsm_render_admin_picker_results( $query, $filters, $context, $selected_id ) {
	$page_number = $filters['page'];
	$remaining = (int) $query->found_posts;
	if ( ! $filters['seo'] && $remaining > 0 ) {
		$incomplete = array( 'seo' => 'any', 'page' => 1 ) + $filters;
		$remaining = (int) dxsm_query_admin_picker( $incomplete )->found_posts;
	}
	?>
	<h3 id="dxsm-admin-results" tabindex="-1">Wyniki <span><?php echo (int) $query->found_posts; ?></span></h3>
	<p class="dxsm-admin-remaining">Do uzupełnienia SEO: <strong><?php echo esc_html( number_format_i18n( $remaining ) ); ?></strong></p>
	<?php if ( ! $query->posts ) : ?>
		<p>Brak pasujących opublikowanych wpisów lub stron.</p>
		<?php return; ?>
	<?php endif; ?>
	<ul class="dxsm-admin-results">
		<?php foreach ( $query->posts as $result ) : ?>
			<?php
			$missing_title = '' === dxsm_get_seo_override( $result->ID, '_dxsm_seo_title' );
			$missing_description = '' === dxsm_get_seo_override( $result->ID, '_dxsm_seo_description' );
			?>
			<li><a href="<?php echo esc_url( dxsm_admin_url( $context + array( 'dxsm_schema_post' => $result->ID ), '#dxsm-admin-preview' ) ); ?>"<?php echo $selected_id === (int) $result->ID ? ' aria-current="true"' : ''; ?>>
				<strong><?php echo esc_html( dxsm_admin_post_title( $result ) ); ?></strong>
				<span class="dxsm-admin-scores"><?php echo dxsm_score_dot( dxsm_score( $result->ID, 'seo' ), 'SEO' ) . ' SEO ' . dxsm_score_dot( dxsm_score( $result->ID, 'readability' ), 'Czytelność' ) . ' czytelność'; ?></span>
				<span><?php echo esc_html( 'page' === $result->post_type ? 'Strona' : ( 'post' === $result->post_type ? 'Wpis' : dxsm_post_type_label( $result->post_type ) ) ); ?> · <?php echo esc_html( get_the_date( 'd.m.Y', $result ) ); ?></span>
				<?php if ( $missing_title || $missing_description ) : ?>
					<span class="dxsm-admin-missing"><?php echo $missing_title && $missing_description ? 'Brak własnego tytułu i opisu' : ( $missing_title ? 'Brak własnego tytułu' : 'Brak własnego opisu' ); ?></span>
				<?php endif; ?>
			</a></li>
		<?php endforeach; ?>
	</ul>
	<?php if ( $query->max_num_pages > 1 ) : ?>
		<?php $pagination = $selected_id ? array( 'dxsm_schema_post' => $selected_id ) + $context : $context; ?>
		<nav class="dxsm-admin-pagination" aria-label="Strony wyników">
			<?php if ( $page_number > 1 ) : ?>
				<a class="button" href="<?php echo esc_url( dxsm_admin_url( array( 'dxsm_results_page' => $page_number - 1 ) + $pagination, '#dxsm-admin-results' ) ); ?>">Wstecz</a>
			<?php endif; ?>
			<span><?php printf( '%d z %d', $page_number, $query->max_num_pages ); ?></span>
			<?php if ( $page_number < $query->max_num_pages ) : ?>
				<a class="button" href="<?php echo esc_url( dxsm_admin_url( array( 'dxsm_results_page' => $page_number + 1 ) + $pagination, '#dxsm-admin-results' ) ); ?>">Dalej</a>
			<?php endif; ?>
		</nav>
	<?php endif; ?>
	<?php
}

function dxsm_render_admin_preview( $post, $view, $views, $context, $settings ) {
	$url = get_permalink( $post );
	$edit_url = get_edit_post_link( $post->ID, 'raw' );
	?>
	<header class="dxsm-admin-selected">
		<div class="dxsm-admin-identity">
			<p><?php echo esc_html( 'page' === $post->post_type ? 'Strona' : ( 'post' === $post->post_type ? 'Wpis' : dxsm_post_type_label( $post->post_type ) ) ); ?></p>
			<h2 id="dxsm-admin-preview" tabindex="-1"><?php echo esc_html( dxsm_admin_post_title( $post ) ); ?></h2>
			<p class="dxsm-admin-url"><?php echo esc_html( $url ); ?></p>
		</div>
		<div class="dxsm-admin-actions">
			<?php if ( $edit_url ) : ?>
				<a class="button" href="<?php echo esc_url( $edit_url ); ?>">Edytuj w WordPressie</a>
			<?php endif; ?>
			<a class="button" href="<?php echo esc_url( $url ); ?>">Otwórz publiczny adres</a>
		</div>
	</header>
	<nav class="dxsm-admin-views" aria-label="Rodzaj podglądu">
		<?php foreach ( $views as $key => $label ) : ?>
			<a href="<?php echo esc_url( dxsm_admin_url( array( 'dxsm_view' => $key, 'dxsm_schema_post' => $post->ID ) + $context, '#dxsm-admin-preview' ) ); ?>"<?php echo $view === $key ? ' aria-current="page"' : ''; ?><?php if ( 'schema' !== $key ) : ?> data-dxsm-preview-tab="<?php echo esc_attr( $key ); ?>"<?php elseif ( 'schema' !== $view ) : ?> id="dxsm-schema-preview"<?php endif; ?>><?php echo esc_html( $label ); ?></a>
		<?php endforeach; ?>
	</nav>
	<div class="dxsm-admin-panel">
		<?php if ( 'schema' === $view ) : ?>
			<?php dxsm_render_schema_preview( (int) $post->ID ); ?>
		<?php else : ?>
			<?php dxsm_render_admin_seo_editor( $post, $view, $context, $settings['google_mode'] ); ?>
		<?php endif; ?>
	</div>
	<?php
}

function dxsm_render_admin_seo_editor( $post, $view, $context, $google_mode ) {
	$preview = dxsm_get_seo_preview_data( $post );
	$saved = dxsm_schema_preview_get_field( 'dxsm_saved' );
	?>
	<div class="dxsm-seo-editor" data-dxsm-seo data-dxsm-live-previews>
		<?php if ( current_user_can( 'edit_post', $post->ID ) ) : ?>
			<?php if ( 'yes' === $saved ) : ?>
				<div class="notice notice-success inline" role="status"><p>Zapisano pola SEO i oceny.</p></div>
			<?php elseif ( 'next' === $saved ) : ?>
				<div class="notice notice-success inline" role="status"><p>Zapisano poprzednią pozycję. Otworzono kolejną.</p></div>
			<?php elseif ( 'end' === $saved ) : ?>
				<div class="notice notice-success inline" role="status"><p>Zapisano zmiany. To ostatnia pozycja do edycji w tych wynikach.</p></div>
			<?php elseif ( 'skipped' === $saved ) : ?>
				<div class="notice notice-info inline" role="status"><p>Pominięto poprzednią pozycję bez zapisu. Otwarto kolejną.</p></div>
			<?php elseif ( 'skip_end' === $saved ) : ?>
				<div class="notice notice-info inline" role="status"><p>Pominięto zmiany bez zapisu. To ostatnia pozycja do edycji w tych wynikach.</p></div>
			<?php elseif ( 'previous' === $saved ) : ?>
				<div class="notice notice-info inline" role="status"><p>Otwarto poprzednią pozycję bez zapisywania zmian.</p></div>
			<?php elseif ( 'previous_end' === $saved ) : ?>
				<div class="notice notice-info inline" role="status"><p>Brak wcześniejszej pozycji do edycji w tych wynikach. Zmian nie zapisano.</p></div>
			<?php elseif ( 'error' === $saved ) : ?>
				<div class="notice notice-error inline" role="alert"><p>Nie udało się zapisać wszystkich zmian. Pola pokazują aktualne wartości.</p></div>
			<?php endif; ?>
			<form class="dxsm-seo-editor dxsm-admin-edit" method="post" action="<?php echo esc_url( add_query_arg( $context, admin_url( 'admin-post.php' ) ) ); ?>">
				<input type="hidden" name="action" value="dxsm_save_seo">
				<input type="hidden" name="dxsm_post_id" value="<?php echo esc_attr( $post->ID ); ?>">
				<input type="hidden" name="dxsm_return_view" value="<?php echo esc_attr( $view ); ?>">
				<?php wp_nonce_field( 'dxsm_panel_save_' . $post->ID, '_dxsm_panel_nonce', false ); ?>
				<?php dxsm_render_seo_fields( $preview ); ?>
				<div class="dxsm-admin-actions dxsm-admin-savebar">
					<button type="submit" class="button button-primary">Zapisz SEO</button>
					<button type="submit" class="button" name="dxsm_save_mode" value="next">Zapisz i przejdź do następnego</button>
					<button type="submit" class="button" name="dxsm_save_mode" value="skip">Pomiń i przejdź do następnego</button>
					<button type="submit" class="button" name="dxsm_save_mode" value="previous">Wróć do poprzedniego</button>
				</div>
			</form>
		<?php endif; ?>
		<div data-dxsm-preview-pane="google"<?php echo 'google' === $view ? '' : ' hidden'; ?>>
			<p class="description">Symulacja wyniku w Google</p>
			<?php dxsm_render_seo_previews( $preview, $google_mode, false, 'google' ); ?>
		</div>
		<div data-dxsm-preview-pane="social"<?php echo 'social' === $view ? '' : ' hidden'; ?>>
			<p class="description">Symulacje kart udostępniania</p>
			<?php dxsm_render_seo_previews( $preview, $google_mode, true, 'social' ); ?>
		</div>
	</div>
	<?php
}
