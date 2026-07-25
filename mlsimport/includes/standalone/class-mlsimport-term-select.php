<?php
/**
 * Reusable searchable multi-select for picking terms from a plugin taxonomy.
 *
 * Anywhere the plugin needs the operator to pick one or more terms from a
 * registered taxonomy (the standalone mlsimport_* taxonomies, or any other),
 * render this instead of hand-rolling a <select>. It emits the same markup the
 * Import Task City/County picker already uses — a chip row, a live filter input,
 * and a native <select multiple> — so the existing mlsimport-searchable-select.js
 * behaviour (live filter, click-to-toggle without Ctrl, removable chips) and the
 * styles in mlsimport-admin.css apply with nothing new to ship.
 *
 * The native <select multiple> is the source of truth: it submits the usual
 * name[]-array payload, so save handlers read $_POST[ name ] as an array.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a searchable, chip-backed multi-select of a taxonomy's terms.
 */
class Mlsimport_Term_Select {

	/**
	 * Handle for the shared dropdown multi-select component (the same one the
	 * standalone front-end search form uses).
	 */
	const SCRIPT_HANDLE = 'mlsimport-multiselect';

	/**
	 * Ensure the dropdown multi-select component (JS + CSS) is enqueued. Idempotent
	 * — call it from any screen that renders a term select.
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		// Plugin base URL + version for cache-busting (constants when the plugin booted).
		$base = defined( 'MLSIMPORT_PLUGIN_URL' ) ? MLSIMPORT_PLUGIN_URL : plugin_dir_url( dirname( __DIR__ ) . '/mlsimport.php' );
		$ver  = defined( 'MLSIMPORT_VERSION' ) ? MLSIMPORT_VERSION : false;

		// Register the shared JS/CSS once (guarded so repeated calls don't re-register).
		if ( ! wp_script_is( self::SCRIPT_HANDLE, 'registered' ) ) {
			wp_register_script( self::SCRIPT_HANDLE, $base . 'public/js/mlsimport-multiselect.js', array(), $ver, true );
		}
		if ( ! wp_style_is( self::SCRIPT_HANDLE, 'registered' ) ) {
			wp_register_style( self::SCRIPT_HANDLE, $base . 'public/css/mlsimport-multiselect.css', array(), $ver );
		}
		// Enqueue the component's assets for this request.
		wp_enqueue_script( self::SCRIPT_HANDLE );
		wp_enqueue_style( self::SCRIPT_HANDLE );
	}

	/**
	 * Build the term multi-select as an escaped HTML string (the caller echoes it).
	 *
	 * @param array $args {
	 *     @type string   $taxonomy    Required. Taxonomy slug to list terms from.
	 *     @type string   $name        Required. Field name; submitted as name[].
	 *     @type array    $selected    Currently-selected values (term IDs, or slugs
	 *                                 when value_field is 'slug'). Default array().
	 *     @type string   $label       Optional label shown above the control.
	 *     @type string   $value_field Option value: 'term_id' (default), 'slug' or 'name'.
	 *     @type string   $placeholder Empty-state text shown on the control.
	 *     @type string   $id          Select id (auto-derived when omitted).
	 * }
	 * @return string Escaped markup, or '' when the taxonomy/name is invalid.
	 */
	public static function render( array $args ): string {
		$args = wp_parse_args(
			$args,
			array(
				'taxonomy'    => '',
				'name'        => '',
				'selected'    => array(),
				'label'       => '',
				'value_field' => 'term_id',
				'placeholder' => __( 'Any', 'mlsimport' ),
				'id'          => '',
			)
		);

		// Required args must be present and the taxonomy real, else render nothing.
		$taxonomy = (string) $args['taxonomy'];
		$name     = (string) $args['name'];
		if ( '' === $taxonomy || '' === $name || ! taxonomy_exists( $taxonomy ) ) {
			return '';
		}

		// Pull every term (including empty ones) to build the option list.
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);
		// No terms (or a lookup error): nothing to select from.
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return '';
		}

		// Which term property becomes the option value (default term_id); coerce the
		// selected values to strings for the in_array comparison below.
		$value_field = in_array( $args['value_field'], array( 'slug', 'name' ), true ) ? $args['value_field'] : 'term_id';
		$selected    = array_map( 'strval', (array) $args['selected'] );
		// Caller-supplied id, or a stable one derived from taxonomy + field name.
		$id          = '' !== $args['id'] ? (string) $args['id'] : 'mlsimport-term-select-' . sanitize_html_class( $taxonomy . '-' . $name );

		// Buffer the markup so the method can return it as a string.
		ob_start();
		?>
		<div class="mlsimport-term-select">
			<?php if ( '' !== (string) $args['label'] ) : ?>
				<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( (string) $args['label'] ); ?></label>
			<?php endif; ?>
			<select
				class="mlsimport-multiselect"
				id="<?php echo esc_attr( $id ); ?>"
				name="<?php echo esc_attr( $name ); ?>[]"
				multiple
				data-placeholder="<?php echo esc_attr( (string) $args['placeholder'] ); ?>"
			>
				<?php
				// One <option> per term; its value comes from the chosen value_field.
				foreach ( $terms as $term ) {
					if ( 'slug' === $value_field ) {
						$value = (string) $term->slug;
					} elseif ( 'name' === $value_field ) {
						$value = (string) $term->name;
					} else {
						$value = (string) $term->term_id;
					}
					// Mark the option selected when its value is in the selected set.
					printf(
						'<option value="%s"%s>%s</option>',
						esc_attr( $value ),
						selected( in_array( $value, $selected, true ), true, false ),
						esc_html( $term->name )
					);
				}
				?>
			</select>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
