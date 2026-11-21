<?php
// Kokpit Social Meta: oceny SEO i czytelności wszystkich treści, braki do uzupełnienia, przeliczanie ocen, kolumna na listach wpisów.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const DXSM_SCORE_KEYS = array( 'seo' => '_dxsm_score_seo', 'readability' => '_dxsm_score_readability' );
const DXSM_RATINGS = array( 'good' => 'Dobrze', 'ok' => 'Do poprawy', 'bad' => 'Problemy', 'none' => 'Bez oceny' );

// Ocena zapisana przy treści: good, ok, bad albo none, gdy analiza jeszcze jej nie wystawiła.
function dxsm_score( $post_id, $kind ) {
	$value = get_post_meta( $post_id, DXSM_SCORE_KEYS[ $kind ], true );
	return in_array( $value, array( 'good', 'ok', 'bad' ), true ) ? $value : 'none';
}

function dxsm_store_score( $post_id, $kind, $value ) {
	if ( in_array( $value, array( 'good', 'ok', 'bad' ), true ) ) {
		update_post_meta( $post_id, DXSM_SCORE_KEYS[ $kind ], $value );
	} else {
		delete_post_meta( $post_id, DXSM_SCORE_KEYS[ $kind ] );
	}
}

// Znacznik oceny. Kształt różni się razem z kolorem (pełny, połówka, obręcz, przerywany), a tekst czyta czytnik ekranu.
function dxsm_score_dot( $rating, $label ) {
	$text = $label . ': ' . DXSM_RATINGS[ $rating ];
	return '<span class="dxsm-dot" data-rating="' . esc_attr( $rating ) . '" title="' . esc_attr( $text ) . '"><span class="screen-reader-text">' . esc_html( $text ) . '</span></span>';
}

// Liczby do kokpitu dla opublikowanych treści bez hasła w typach, które obsługuje panel.
function dxsm_dashboard_counts() {
	global $wpdb;
	$types = dxsm_seo_post_types();
	$empty = array( 'good' => 0, 'ok' => 0, 'bad' => 0, 'none' => 0 );
	$counts = array( 'total' => 0, 'seo' => $empty, 'readability' => $empty, 'missing' => array( 'keyphrase' => 0, 'description' => 0, 'title' => 0 ) );
	if ( ! $types ) {
		return $counts;
	}
	$in_types = implode( ',', array_fill( 0, count( $types ), '%s' ) );
	$content = $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_password = '' AND post_type IN ($in_types)", $types );
	$counts['total'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM ($content) AS content" );
	$rows = $wpdb->get_results(
		"SELECT meta_key, meta_value, COUNT(DISTINCT post_id) AS amount FROM {$wpdb->postmeta}
		WHERE meta_key IN ('_dxsm_score_seo', '_dxsm_score_readability') AND meta_value IN ('good', 'ok', 'bad') AND post_id IN ($content)
		GROUP BY meta_key, meta_value",
		ARRAY_A
	);
	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		$counts[ array_search( $row['meta_key'], DXSM_SCORE_KEYS, true ) ][ $row['meta_value'] ] = (int) $row['amount'];
	}
	foreach ( array( 'seo', 'readability' ) as $kind ) {
		$counts[ $kind ]['none'] = max( 0, $counts['total'] - $counts[ $kind ]['good'] - $counts[ $kind ]['ok'] - $counts[ $kind ]['bad'] );
	}
	$filled = $wpdb->get_results(
		"SELECT meta_key, COUNT(DISTINCT post_id) AS amount FROM {$wpdb->postmeta}
		WHERE meta_key IN ('_dxsm_seo_keyphrase', '_dxsm_seo_description', '_dxsm_seo_title') AND meta_value <> '' AND post_id IN ($content)
		GROUP BY meta_key",
		ARRAY_A
	);
	$have = array();
	foreach ( is_array( $filled ) ? $filled : array() as $row ) {
		$have[ $row['meta_key'] ] = (int) $row['amount'];
	}
	foreach ( array( 'keyphrase', 'description', 'title' ) as $field ) {
		$counts['missing'][ $field ] = max( 0, $counts['total'] - ( $have[ '_dxsm_seo_' . $field ] ?? 0 ) );
	}
	return $counts;
}

function dxsm_render_dashboard_scores( $kind, $title, $lead, $counts ) {
	$total = max( 1, $counts['total'] );
	$rated = $counts['total'] - $counts[ $kind ]['none'];
	$heading = 'dxsm-dash-' . $kind;
	?>
	<section class="dxsm-admin-panel dxsm-dash-card" aria-labelledby="<?php echo esc_attr( $heading ); ?>">
		<h2 id="<?php echo esc_attr( $heading ); ?>"><?php echo esc_html( $title ); ?></h2>
		<p class="dxsm-dash-lead"><?php echo esc_html( $lead ); ?> Ocenione: <strong><?php echo esc_html( number_format_i18n( $rated ) ); ?></strong> z <?php echo esc_html( number_format_i18n( $counts['total'] ) ); ?>.</p>
		<div class="dxsm-dash-bar" aria-hidden="true">
			<?php foreach ( DXSM_RATINGS as $rating => $label ) : ?>
				<?php if ( $counts[ $kind ][ $rating ] ) : ?>
					<span data-rating="<?php echo esc_attr( $rating ); ?>" style="flex-grow:<?php echo (int) $counts[ $kind ][ $rating ]; ?>"></span>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
		<ul class="dxsm-dash-rows">
			<?php foreach ( DXSM_RATINGS as $rating => $label ) : ?>
				<li>
					<?php echo dxsm_score_dot( $rating, $title ); ?>
					<span class="dxsm-dash-label"><?php echo esc_html( $label ); ?></span>
					<strong><?php echo esc_html( number_format_i18n( $counts[ $kind ][ $rating ] ) ); ?></strong>
					<?php if ( $counts[ $kind ][ $rating ] ) : ?>
						<a href="<?php echo esc_url( dxsm_admin_url( array( 'dxsm_view' => 'google', 'dxsm_score' => $kind . '-' . $rating ), '#dxsm-admin-results' ) ); ?>">Pokaż<span class="screen-reader-text">: <?php echo esc_html( $title . ', ' . $label ); ?></span></a>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>
	<?php
}

function dxsm_render_admin_dashboard() {
	$counts = dxsm_dashboard_counts();
	$missing = array(
		'keyphrase'   => array( 'Bez frazy kluczowej', 'Bez frazy nie ma analizy SEO ani podpowiedzi linków do tej treści.' ),
		'description' => array( 'Bez własnego opisu meta', 'Google i media społecznościowe biorą wtedy opis automatyczny.' ),
		'title'       => array( 'Bez własnego tytułu SEO', 'W wynikach pokazuje się tytuł wpisu z nazwą witryny.' ),
	);
	?>
	<div class="dxsm-dash">
		<?php dxsm_render_dashboard_scores( 'seo', 'Oceny SEO', 'Czy fraza kluczowa jest w tytule, opisie, adresie i treści.', $counts ); ?>
		<?php dxsm_render_dashboard_scores( 'readability', 'Oceny czytelności', 'Długość zdań i akapitów, śródtytuły, mglistość i styl.', $counts ); ?>
		<section class="dxsm-admin-panel dxsm-dash-card" aria-labelledby="dxsm-dash-missing">
			<h2 id="dxsm-dash-missing">Do uzupełnienia</h2>
			<p class="dxsm-dash-lead">Pola, które wpisujesz ręcznie. Kliknij, żeby przejść do listy treści z tym brakiem.</p>
			<ul class="dxsm-dash-rows dxsm-dash-rows--tasks">
				<?php foreach ( $missing as $field => $text ) : ?>
					<li>
						<span class="dxsm-dash-label"><?php echo esc_html( $text[0] ); ?><small><?php echo esc_html( $text[1] ); ?></small></span>
						<strong><?php echo esc_html( number_format_i18n( $counts['missing'][ $field ] ) ); ?></strong>
						<?php if ( $counts['missing'][ $field ] ) : ?>
							<a href="<?php echo esc_url( dxsm_admin_url( array( 'dxsm_view' => 'google', 'dxsm_seo_filter' => $field ), '#dxsm-admin-results' ) ); ?>">Pokaż<span class="screen-reader-text">: <?php echo esc_html( $text[0] ); ?></span></a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
		<section class="dxsm-admin-panel dxsm-dash-card" aria-labelledby="dxsm-dash-recount">
			<h2 id="dxsm-dash-recount">Przelicz oceny</h2>
			<p class="dxsm-dash-lead">Ocena zapisuje się przy każdym zapisie treści. Przeliczenie wystawia ją wszystkim opublikowanym treściom naraz, na przykład po aktualizacji wtyczki albo po dopisaniu fraz. Nie zmienia treści ani strony publicznej.</p>
			<p>
				<button type="button" class="button button-primary" data-dxsm-recount data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'dxsm_scores' ) ); ?>">Przelicz oceny wszystkich treści</button>
			</p>
			<p class="dxsm-dash-progress" role="status" data-dxsm-recount-status></p>
		</section>
	</div>
	<?php
}

function dxsm_ajax_scores_guard() {
	if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'dxsm_scores', 'nonce', false ) ) {
		wp_send_json_error( null, 403 );
	}
}

// Kolejka do przeliczenia: identyfikatory opublikowanych treści bez hasła.
function dxsm_ajax_score_queue() {
	dxsm_ajax_scores_guard();
	$ids = get_posts(
		array(
			'post_type'      => dxsm_seo_post_types(),
			'post_status'    => 'publish',
			'has_password'   => false,
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		)
	);
	wp_send_json_success( array_map( 'intval', $ids ) );
}
add_action( 'wp_ajax_dxsm_score_queue', 'dxsm_ajax_score_queue' );

// Dane jednej treści do analizy w przeglądarce: te same, które dostaje edycja wpisu.
function dxsm_ajax_score_source() {
	dxsm_ajax_scores_guard();
	$post = get_post( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 );
	if ( ! dxsm_admin_public_content( $post ) || ! current_user_can( 'edit_post', $post->ID ) ) {
		wp_send_json_error( null, 404 );
	}
	$preview = dxsm_get_seo_preview_data( $post, false );
	wp_send_json_success(
		array(
			'phrase'      => $preview['keyphrase'],
			'title'       => '' === $preview['title'] ? $preview['automatic_title'] : $preview['title'],
			'description' => '' === $preview['description'] ? $preview['automatic_description'] : $preview['description'],
			'slug'        => $preview['slug'],
			'url'         => $preview['url'],
			'html'        => $preview['content'],
		)
	);
}
add_action( 'wp_ajax_dxsm_score_source', 'dxsm_ajax_score_source' );

function dxsm_ajax_score_save() {
	dxsm_ajax_scores_guard();
	$post = get_post( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 );
	if ( ! dxsm_admin_public_content( $post ) || ! current_user_can( 'edit_post', $post->ID ) ) {
		wp_send_json_error( null, 404 );
	}
	foreach ( array_keys( DXSM_SCORE_KEYS ) as $kind ) {
		dxsm_store_score( $post->ID, $kind, isset( $_POST[ $kind ] ) && is_string( $_POST[ $kind ] ) ? sanitize_key( wp_unslash( $_POST[ $kind ] ) ) : '' );
	}
	wp_send_json_success();
}
add_action( 'wp_ajax_dxsm_score_save', 'dxsm_ajax_score_save' );

// Kolumna z ocenami na listach wpisów i stron.
function dxsm_register_score_columns() {
	foreach ( dxsm_seo_post_types() as $type ) {
		add_filter( 'manage_' . $type . '_posts_columns', 'dxsm_add_score_column' );
		add_action( 'manage_' . $type . '_posts_custom_column', 'dxsm_render_score_column', 10, 2 );
	}
}
add_action( 'admin_init', 'dxsm_register_score_columns' );

function dxsm_add_score_column( $columns ) {
	$columns['dxsm_scores'] = 'SEO i czytelność';
	return $columns;
}

function dxsm_render_score_column( $column, $post_id ) {
	if ( 'dxsm_scores' === $column ) {
		echo dxsm_score_dot( dxsm_score( $post_id, 'seo' ), 'SEO' ) . ' ' . dxsm_score_dot( dxsm_score( $post_id, 'readability' ), 'Czytelność' );
	}
}

function dxsm_score_dot_css() {
	return '.dxsm-dot{display:inline-block;width:12px;height:12px;box-sizing:border-box;border-radius:50%;border:2px solid #8c8f94;vertical-align:middle}'
		. '.dxsm-dot[data-rating="good"]{border-color:#00a32a;background:#00a32a}'
		. '.dxsm-dot[data-rating="ok"]{border-color:#996800;background:linear-gradient(90deg,#dba617 50%,transparent 50%)}'
		. '.dxsm-dot[data-rating="bad"]{border-color:#d63638;border-width:3px}'
		. '.dxsm-dot[data-rating="none"]{border-style:dashed}'
		. '.column-dxsm_scores{width:8em}';
}

function dxsm_enqueue_score_column_style( $hook_suffix ) {
	if ( 'edit.php' === $hook_suffix ) {
		wp_add_inline_style( 'common', dxsm_score_dot_css() );
	}
}
add_action( 'admin_enqueue_scripts', 'dxsm_enqueue_score_column_style' );
