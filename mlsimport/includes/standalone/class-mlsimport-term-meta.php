<?php
/**
 * Standalone (theme_id 990) per-term settings: a featured image and an HTML
 * description for every plugin taxonomy term.
 *
 * The id coercion is pure PHP (no WordPress, no DB) so it is unit-testable in
 * isolation; the render / save / enqueue methods are thin WordPress wrappers.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds an image + HTML-description settings area to every plugin taxonomy term.
 */
class Mlsimport_Term_Meta {

	/** Term meta key: featured-image attachment id. */
	const IMAGE_KEY = 'mlsimport_term_image_id';

	/** Term meta key: HTML description. */
	const HTML_KEY = 'mlsimport_term_html';

	/** Nonce action / field name for the term settings save. */
	const NONCE_ACTION = 'mlsimport_save_term_meta';
	const NONCE_FIELD  = 'mlsimport_term_meta_nonce';

	/**
	 * Coerce a posted featured-image value to a non-negative attachment id.
	 * `0` means "no image". Non-integer, negative or non-numeric input all
	 * resolve to 0 so a bad value can never persist as an image reference.
	 *
	 * @param mixed $raw Raw posted value.
	 * @return int Attachment id, or 0 for none.
	 */
	public static function image_id( $raw ): int {
		// Trim string input so " 42 " is treated the same as "42".
		$raw = is_string( $raw ) ? trim( $raw ) : $raw;
		// A real int: keep it only when positive, otherwise 0 (= no image).
		if ( is_int( $raw ) ) {
			return $raw > 0 ? $raw : 0;
		}
		// A string of digits (no sign, no decimals) casts to the id, else 0.
		return ( is_string( $raw ) && ctype_digit( $raw ) ) ? (int) $raw : 0;
	}

	/* --------------------------------------------------------------------- *
	 * WordPress wiring (thin wrappers over the pure core above).
	 * --------------------------------------------------------------------- */

	/**
	 * Register the admin hooks for every plugin taxonomy. Admin only.
	 *
	 * @return void
	 */
	public static function register(): void {
		// Admin-only screens; nothing to wire on the front end.
		if ( ! is_admin() ) {
			return;
		}
		// The CPT class supplies the taxonomy list; bail if it isn't loaded.
		if ( ! class_exists( 'Mlsimport_Standalone_Cpt' ) ) {
			return;
		}
		// For every plugin taxonomy: render the fields on add/edit, save on create/edit.
		foreach ( Mlsimport_Standalone_Cpt::taxonomy_slugs() as $taxonomy ) {
			add_action( "{$taxonomy}_add_form_fields", array( __CLASS__, 'add_fields' ) );
			add_action( "{$taxonomy}_edit_form_fields", array( __CLASS__, 'edit_fields' ), 10, 2 );
			add_action( "created_{$taxonomy}", array( __CLASS__, 'save' ) );
			add_action( "edited_{$taxonomy}", array( __CLASS__, 'save' ) );
		}
		// Load the media library + picker JS on the term screens.
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * Enqueue the media library + image-picker JS on the term screens of our
	 * taxonomies only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function assets( $hook ): void {
		// Only the term list (edit-tags.php) and single-term (term.php) screens.
		if ( 'edit-tags.php' !== $hook && 'term.php' !== $hook ) {
			return;
		}
		// Which taxonomy's screen this is (read from the query for routing only).
		$taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen routing.
		// Skip taxonomies that aren't ours.
		if ( ! in_array( $taxonomy, Mlsimport_Standalone_Cpt::taxonomy_slugs(), true ) ) {
			return;
		}
		// Load the WP media library, then the picker script that drives the control.
		wp_enqueue_media();
		$base = defined( 'MLSIMPORT_PLUGIN_URL' ) ? MLSIMPORT_PLUGIN_URL : plugin_dir_url( dirname( __DIR__ ) . '/mlsimport.php' );
		$ver  = defined( 'MLSIMPORT_VERSION' ) ? MLSIMPORT_VERSION : false;
		wp_enqueue_script( 'mlsimport-term-meta', $base . 'admin/js/mlsimport-term-meta.js', array( 'jquery' ), $ver, true );
	}

	/**
	 * Fields on the "Add new term" screen: a plain HTML textarea (TinyMCE does
	 * not initialise reliably in the inline add-tag AJAX form) + image picker.
	 *
	 * @param string $taxonomy Current taxonomy slug.
	 * @return void
	 */
	public static function add_fields( $taxonomy ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		?>
		<div class="form-field mlsimport-term-image">
			<label><?php esc_html_e( 'Featured image', 'mlsimport' ); ?></label>
			<?php self::image_control( 0 ); ?>
		</div>
		<div class="form-field mlsimport-term-html">
			<label for="mlsimport_term_html"><?php esc_html_e( 'HTML description', 'mlsimport' ); ?></label>
			<textarea name="<?php echo esc_attr( self::HTML_KEY ); ?>" id="mlsimport_term_html" rows="5" cols="50"></textarea>
			<p class="description"><?php esc_html_e( 'Save the term, then edit it to use the full visual editor.', 'mlsimport' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Fields on the "Edit term" screen: full wp_editor + image picker, pre-filled
	 * from stored meta. Rendered as table rows to match core's edit-term layout.
	 *
	 * @param WP_Term $term     Current term.
	 * @param string  $taxonomy Current taxonomy slug.
	 * @return void
	 */
	public static function edit_fields( $term, $taxonomy ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		$image_id = (int) get_term_meta( $term->term_id, self::IMAGE_KEY, true );
		$html     = (string) get_term_meta( $term->term_id, self::HTML_KEY, true );
		?>
		<tr class="form-field mlsimport-term-image">
			<th scope="row"><label><?php esc_html_e( 'Featured image', 'mlsimport' ); ?></label></th>
			<td><?php self::image_control( $image_id ); ?></td>
		</tr>
		<tr class="form-field mlsimport-term-html">
			<th scope="row"><label for="mlsimport_term_html"><?php esc_html_e( 'HTML description', 'mlsimport' ); ?></label></th>
			<td>
				<?php
				wp_editor(
					$html,
					'mlsimport_term_html',
					array(
						'textarea_name' => self::HTML_KEY,
						'textarea_rows' => 8,
						'media_buttons' => true,
					)
				);
				?>
			</td>
		</tr>
		<?php
	}

	/**
	 * The shared image picker control: a hidden id input, a preview, and the
	 * set/remove buttons the picker JS drives.
	 *
	 * @param int $image_id Currently stored attachment id (0 = none).
	 * @return void
	 */
	private static function image_control( int $image_id ): void {
		$src = $image_id ? wp_get_attachment_image_url( $image_id, 'medium' ) : '';
		?>
		<div class="mlsimport-term-image__wrap">
			<input type="hidden" class="mlsimport-term-image__id" name="<?php echo esc_attr( self::IMAGE_KEY ); ?>" value="<?php echo esc_attr( (string) $image_id ); ?>">
			<img class="mlsimport-term-image__preview" src="<?php echo esc_url( $src ); ?>" alt="" style="max-width:150px;height:auto;display:<?php echo $src ? 'block' : 'none'; ?>;margin-bottom:8px;">
			<button type="button" class="button mlsimport-term-image__set"><?php esc_html_e( 'Set image', 'mlsimport' ); ?></button>
			<button type="button" class="button mlsimport-term-image__remove" style="display:<?php echo $src ? 'inline-block' : 'none'; ?>;"><?php esc_html_e( 'Remove', 'mlsimport' ); ?></button>
		</div>
		<?php
	}

	/**
	 * Persist the term settings: nonce + capability guard, then whitelist-write
	 * the image id and the kses-filtered HTML. An empty/zero image removes the
	 * meta rather than storing 0.
	 *
	 * @param int $term_id Term id.
	 * @return void
	 */
	public static function save( $term_id ): void {
		// Verify our nonce before trusting any posted value.
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		// Require the term-management capability.
		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}

		// Coerce the posted image to an attachment id; store it, or clear on 0/none.
		$image_id = isset( $_POST[ self::IMAGE_KEY ] ) ? self::image_id( wp_unslash( $_POST[ self::IMAGE_KEY ] ) ) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitized.InputNotSanitized -- coerced to int by image_id().
		if ( $image_id > 0 ) {
			update_term_meta( $term_id, self::IMAGE_KEY, $image_id );
		} else {
			delete_term_meta( $term_id, self::IMAGE_KEY );
		}

		// HTML description: kses-filter it, store when non-empty, else remove the meta.
		if ( isset( $_POST[ self::HTML_KEY ] ) ) {
			$html = wp_kses_post( wp_unslash( $_POST[ self::HTML_KEY ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitized.InputNotSanitized -- wp_kses_post sanitizes.
			if ( '' !== $html ) {
				update_term_meta( $term_id, self::HTML_KEY, $html );
			} else {
				delete_term_meta( $term_id, self::HTML_KEY );
			}
		}
	}
}
