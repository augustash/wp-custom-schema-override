<?php
/**
 * Plugin Name: WP Custom Schema Override
 * Description: Adds a per-page JSON-LD field that, when populated, replaces all Yoast SEO structured data output with the custom value.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WPCSO_META_KEY' ) ) {
	define( 'WPCSO_META_KEY', '_custom_schema_json_override' );
}
if ( ! defined( 'WPCSO_NONCE_KEY' ) ) {
	define( 'WPCSO_NONCE_KEY', 'wpcso_nonce' );
}

// ---------------------------------------------------------------------------
// Admin: meta box
// ---------------------------------------------------------------------------

add_action( 'add_meta_boxes', 'wpcso_add_meta_box' );

function wpcso_add_meta_box(): void {
	$post_types = get_post_types( [ 'public' => true ] );

	foreach ( $post_types as $post_type ) {
		add_meta_box(
			'wpcso_schema_override',
			'Custom Schema JSON-LD Override',
			'wpcso_render_meta_box',
			$post_type,
			'side',
			'default'
		);
	}
}

function wpcso_render_meta_box( WP_Post $post ): void {
	$value = get_post_meta( $post->ID, WPCSO_META_KEY, true );
	wp_nonce_field( 'wpcso_save', WPCSO_NONCE_KEY );
	?>
	<p style="margin-bottom:6px;color:#555;">
		Enter raw JSON-LD to replace <strong>all</strong> Yoast SEO structured data on this page.
		Must be a valid JSON object or array. Leave empty to use the default Yoast output.
	</p>
	<textarea
		id="wpcso_schema_json"
		name="wpcso_schema_json"
		rows="14"
		style="width:100%;font-family:monospace;font-size:0.85em;"
	><?php echo esc_textarea( $value ); ?></textarea>
	<p id="wpcso_json_error" style="color:#c00;display:none;margin-top:4px;"></p>
	<script>
	(function () {
		var textarea = document.getElementById('wpcso_schema_json');
		var error    = document.getElementById('wpcso_json_error');
		textarea.addEventListener('blur', function () {
			var val = textarea.value.trim();
			if ( ! val ) { error.style.display = 'none'; return; }
			try {
				JSON.parse(val);
				error.style.display = 'none';
			} catch (e) {
				error.textContent   = 'Invalid JSON: ' + e.message;
				error.style.display = 'block';
			}
		});
	}());
	</script>
	<?php
}

add_action( 'save_post', 'wpcso_save_meta' );

function wpcso_save_meta( int $post_id ): void {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( wp_is_post_revision( $post_id ) ) {
		return;
	}
	if ( ! isset( $_POST[ WPCSO_NONCE_KEY ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ WPCSO_NONCE_KEY ] ) ), 'wpcso_save' ) ) {
		return;
	}
	if ( ! current_user_can( 'unfiltered_html' ) ) {
		return;
	}

	if ( ! isset( $_POST['wpcso_schema_json'] ) ) {
		return;
	}

	$raw = trim( wp_unslash( $_POST['wpcso_schema_json'] ) );

	if ( $raw === '' ) {
		delete_post_meta( $post_id, WPCSO_META_KEY );
		return;
	}

	// Reject invalid JSON server-side.
	json_decode( $raw );
	if ( json_last_error() !== JSON_ERROR_NONE ) {
		return;
	}

	update_post_meta( $post_id, WPCSO_META_KEY, $raw );
}

// ---------------------------------------------------------------------------
// Front end: replace Yoast schema when the override is set
// ---------------------------------------------------------------------------

add_action( 'wp', 'wpcso_maybe_hook_frontend' );

function wpcso_maybe_hook_frontend(): void {
	if ( ! is_singular() ) {
		return;
	}

	$post_id = get_queried_object_id();
	$raw     = get_post_meta( $post_id, WPCSO_META_KEY, true );

	if ( empty( $raw ) ) {
		return;
	}

	$decoded = json_decode( $raw );
	if ( $decoded === null ) {
		return;
	}

	// Encode with the same safety flags used in the Drupal module.
	$safe_json = wp_json_encode(
		$decoded,
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
	);

	if ( $safe_json === false ) {
		return;
	}

	// Disable Yoast's schema output entirely.
	add_filter( 'wpseo_json_ld_output', '__return_false' );

	// Output our custom JSON-LD in its place.
	add_action( 'wp_head', static function () use ( $safe_json ): void {
		echo '<script type="application/ld+json">' . "\n" . $safe_json . "\n" . '</script>' . "\n";
	}, 90 );
}
