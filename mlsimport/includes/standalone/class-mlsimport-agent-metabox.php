<?php
/**
 * Standalone (theme_id 990) "Agent Details" metabox.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds, renders and saves the standalone agent metabox.
 */
class Mlsimport_Agent_Metabox {

	/**
	 * The tabs, in display order: slug => editor-facing label.
	 *
	 * @return array<string,string>
	 */
	public static function tabs(): array {
		return array(
			'identity' => 'Identity',
			'contact'  => 'Contact',
			'office'   => 'Office',
			'profile'  => 'Profile',
			'display'  => 'Display',
		);
	}

	/**
	 * The field catalog — the single source of truth for both render and save.
	 *
	 * Each field: key (meta-key suffix; stored as mlsimport_<key>), tab (a tabs()
	 * slug), label, type (text|textarea|checkbox|...), origin (mls = imported/
	 * RESO-standard, may be re-synced | local = the operator's / portal's own
	 * value). The four core display keys (ListAgentFullName, ListAgentMlsId,
	 * ListAgentEmail, ListAgentPreferredPhone, ListOfficeName) match the meta the
	 * agent profile page reads, so edits show on the front end immediately. The
	 * extra RESO Member/Office fields are forward-looking (populated by import when
	 * the feed carries them); specialties/languages/areas are portal-supplied.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function fields(): array {
		return array(
			// Identity (RESO Member).
			array( 'key' => 'ListAgentFullName', 'tab' => 'identity', 'label' => 'Agent Name', 'type' => 'text', 'origin' => 'mls' ),
			array( 'key' => 'ListAgentMlsId', 'tab' => 'identity', 'label' => 'Agent MLS ID', 'type' => 'text', 'origin' => 'mls' ),
			array( 'key' => 'MemberNationalAssociationId', 'tab' => 'identity', 'label' => 'NRDS ID', 'type' => 'text', 'origin' => 'mls' ),
			array( 'key' => 'JobTitle', 'tab' => 'identity', 'label' => 'Title', 'type' => 'text', 'origin' => 'mls' ),

			// Contact (RESO Member).
			array( 'key' => 'ListAgentEmail', 'tab' => 'contact', 'label' => 'Email', 'type' => 'text', 'origin' => 'mls' ),
			array( 'key' => 'ListAgentPreferredPhone', 'tab' => 'contact', 'label' => 'Preferred Phone', 'type' => 'text', 'origin' => 'mls' ),
			array( 'key' => 'ListAgentDirectPhone', 'tab' => 'contact', 'label' => 'Direct Phone', 'type' => 'text', 'origin' => 'mls' ),
			array( 'key' => 'ListAgentMobilePhone', 'tab' => 'contact', 'label' => 'Mobile Phone', 'type' => 'text', 'origin' => 'mls' ),
			array( 'key' => 'MemberURL', 'tab' => 'contact', 'label' => 'Website', 'type' => 'text', 'origin' => 'mls' ),

			// Office (RESO Office).
			array( 'key' => 'ListOfficeName', 'tab' => 'office', 'label' => 'Office Name', 'type' => 'text', 'origin' => 'mls' ),
			array( 'key' => 'ListOfficePhone', 'tab' => 'office', 'label' => 'Office Phone', 'type' => 'text', 'origin' => 'mls' ),
			array( 'key' => 'ListOfficeMlsId', 'tab' => 'office', 'label' => 'Office MLS ID', 'type' => 'text', 'origin' => 'mls' ),
			array( 'key' => 'OfficeAddress', 'tab' => 'office', 'label' => 'Office Address', 'type' => 'textarea', 'origin' => 'mls' ),

			// Profile (portal-supplied, not RESO-standardized).
			// Short hero blurb shown under the agent name. Separate from the full bio
			// (the post editor / About section); when empty the hero falls back to a
			// trim of that bio, so agents saved before this field still read well.
			array( 'key' => 'teaser', 'tab' => 'profile', 'label' => 'Hero teaser (short blurb; falls back to the bio)', 'type' => 'textarea', 'origin' => 'local' ),
			array( 'key' => 'specialties', 'tab' => 'profile', 'label' => 'Specialties', 'type' => 'textarea', 'origin' => 'local' ),
			array( 'key' => 'languages', 'tab' => 'profile', 'label' => 'Languages', 'type' => 'text', 'origin' => 'local' ),
			array( 'key' => 'areasServed', 'tab' => 'profile', 'label' => 'Areas Served', 'type' => 'textarea', 'origin' => 'local' ),

			// Display & internal (operator-set).
			// Operator-set trust flag. When ticked, the agent's page and the agent block
			// next to a listing show the "Verified Agent" badge, and it drives the Team
			// Directory's "Verified agents only" filter and verified-first sort. Stored as
			// mlsimport_featured — the flag's original name, kept for back-compat.
			array( 'key' => 'featured', 'tab' => 'display', 'label' => 'Verified agent', 'type' => 'checkbox', 'origin' => 'local' ),
			array( 'key' => 'admin_note', 'tab' => 'display', 'label' => 'Internal note (staff only)', 'type' => 'textarea', 'origin' => 'local' ),
		);
	}

	/**
	 * The save whitelist: the meta-key suffix of every catalog field. The save
	 * handler writes only these, so nothing posted outside the catalog persists.
	 *
	 * @return string[]
	 */
	public static function save_keys(): array {
		// Project the catalog down to just its field keys.
		return array_map(
			static function ( $field ) {
				return $field['key'];
			},
			self::fields()
		);
	}

	/**
	 * Coerce a raw posted value to the typed value to persist (native PHP only;
	 * WordPress string sanitization is layered on by the save wrapper).
	 *
	 * @param string $type Field type (checkbox|text|textarea|...).
	 * @param mixed  $raw  Raw posted value.
	 * @return mixed Typed value.
	 */
	public static function coerce( string $type, $raw ) {
		// Checkbox: empty/absent => 0, anything else => 1.
		if ( 'checkbox' === $type ) {
			return ( '' === $raw || null === $raw ) ? 0 : 1;
		}

		// Everything else: trim strings, pass non-strings through untouched.
		return is_string( $raw ) ? trim( $raw ) : $raw;
	}

	/**
	 * Build the persist map from a raw posted field array (the inner
	 * $_POST['mlsimport_agent']). Iterating the catalog — not the input — is what
	 * enforces the whitelist: keys absent from the catalog can never appear, and
	 * unchecked checkboxes (absent from POST) still resolve to 0.
	 *
	 * @param array $input Raw posted values keyed by catalog field key.
	 * @return array<string,mixed> Catalog key => coerced value.
	 */
	public static function extract( array $input ): array {
		// Iterate the catalog (not the input) so only whitelisted keys can appear.
		$out = array();
		foreach ( self::fields() as $field ) {
			// Pull the raw posted value for this key, or null when absent.
			$key         = $field['key'];
			$raw         = array_key_exists( $key, $input ) ? $input[ $key ] : null;
			// Coerce to the field's typed value.
			$out[ $key ] = self::coerce( $field['type'], $raw );
		}
		return $out;
	}

	/* --------------------------------------------------------------------- *
	 * WordPress wiring (thin wrappers over the pure core above).
	 * --------------------------------------------------------------------- */

	/**
	 * The post meta key for a catalog field key.
	 *
	 * @param string $key Catalog field key.
	 * @return string
	 */
	public static function meta_key( string $key ): string {
		return 'mlsimport_' . $key;
	}

	/**
	 * Register the admin hooks. Hooked on init; only wires up in the admin.
	 *
	 * @return void
	 */
	public static function register(): void {
		// Admin-only: nothing to wire on the front end.
		if ( ! is_admin() ) {
			return;
		}
		// Metabox registration, save handler, and edit-screen asset enqueue.
		add_action( 'add_meta_boxes_mlsimport_agent', array( __CLASS__, 'add_box' ) );
		add_action( 'save_post_mlsimport_agent', array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * Register the single tabbed metabox.
	 *
	 * @return void
	 */
	public static function add_box(): void {
		add_meta_box(
			'mlsimport_agent_details',
			esc_html__( 'Agent Details', 'mlsimport' ),
			array( __CLASS__, 'render' ),
			'mlsimport_agent',
			'normal',
			'high'
		);
	}

	/**
	 * Enqueue the shared tab JS/CSS on the agent edit screen only. Reuses the
	 * property metabox assets so the tabs look and behave identically.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function assets( $hook ): void {
		// Only on the post edit / new-post screens.
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		// And only for the agent CPT.
		if ( 'mlsimport_agent' !== get_post_type() ) {
			return;
		}
		// Resolve the plugin base URL and cache-busting version.
		$base = defined( 'MLSIMPORT_PLUGIN_URL' ) ? MLSIMPORT_PLUGIN_URL : plugin_dir_url( dirname( __DIR__ ) . '/mlsimport.php' );
		$ver  = defined( 'MLSIMPORT_VERSION' ) ? MLSIMPORT_VERSION : false;
		// Reuse the property metabox stylesheet so tabs look identical.
		wp_enqueue_style( 'mlsimport-property-metabox', $base . 'admin/css/mlsimport-property-metabox.css', array(), $ver );
		// Re-theme the metabox accent from the "Main Color" design setting.
		if ( function_exists( 'mlsimport_standalone_attach_brand_color_metabox' ) ) {
			mlsimport_standalone_attach_brand_color_metabox( 'mlsimport-property-metabox' );
		}
		// Reuse the shared tab-switching script.
		wp_enqueue_script( 'mlsimport-property-metabox', $base . 'admin/js/mlsimport-property-tabs.js', array(), $ver, true );
	}

	/**
	 * Render the tabbed metabox. Every field is an editable input named
	 * mlsimport_agent[<key>]; values come from mlsimport_<key> post meta.
	 *
	 * @param WP_Post $post Current agent post.
	 * @return void
	 */
	public static function render( $post ): void {
		// CSRF nonce paired with the save handler's check.
		wp_nonce_field( 'mlsimport_save_agent', 'mlsimport_agent_nonce' );

		// Tab list and field catalog.
		$tabs   = self::tabs();
		$fields = self::fields();

		echo '<div class="mlsimport-mb">';

		// Tab rail — one button per tab, the first marked active.
		echo '<div class="mlsimport-mb__rail">';
		$first = true;
		foreach ( $tabs as $slug => $label ) {
			printf(
				'<button type="button" class="mlsimport-mb__tab%s" data-tab="%s">%s</button>',
				$first ? ' is-active' : '',
				esc_attr( $slug ),
				esc_html( $label )
			);
			$first = false;
		}
		echo '</div>';

		// Panes — one pane per tab, the first marked active.
		echo '<div class="mlsimport-mb__panes">';
		$first = true;
		foreach ( $tabs as $slug => $label ) {
			printf( '<div class="mlsimport-mb__pane%s" data-pane="%s">', $first ? ' is-active' : '', esc_attr( $slug ) );
			echo '<div class="mlsimport-mb__grid">';
			// Emit only the fields that belong to this tab.
			foreach ( $fields as $field ) {
				// Skip fields assigned to a different tab.
				if ( $field['tab'] !== $slug ) {
					continue;
				}
				// Read the stored meta and render the field control.
				$value = get_post_meta( $post->ID, self::meta_key( $field['key'] ), true );
				echo self::render_field( $field, $value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped within.
			}
			echo '</div></div>';
			$first = false;
		}
		echo '</div></div>';
	}

	/**
	 * One field control as an HTML string (all inputs editable).
	 *
	 * @param array $field Catalog field def.
	 * @param mixed $value Current stored value.
	 * @return string
	 */
	private static function render_field( array $field, $value ): string {
		// Input name (mlsimport_agent[<key>]) and DOM id.
		$name   = 'mlsimport_agent[' . esc_attr( $field['key'] ) . ']';
		$id     = 'mlsimport_' . esc_attr( $field['key'] );
		// Origin badge — local vs mls (defaults to mls).
		$origin = isset( $field['origin'] ) && 'local' === $field['origin'] ? 'local' : 'mls';
		$tag    = '<span class="mlsimport-mb__tag mlsimport-mb__tag--' . $origin . '">' . esc_html( strtoupper( $origin ) ) . '</span>';
		$label  = '<label for="' . $id . '">' . esc_html( $field['label'] ) . ' ' . $tag . '</label>';

		// Emit the control markup for the field's type.
		switch ( $field['type'] ) {
			case 'checkbox':
				$control = '<label class="mlsimport-mb__check"><input type="hidden" name="' . $name . '" value=""><input type="checkbox" id="' . $id . '" name="' . $name . '" value="1"' . checked( '1', (string) $value, false ) . '> ' . esc_html( $field['label'] ) . ' ' . $tag . '</label>';
				return '<div class="mlsimport-mb__f mlsimport-mb__f--check">' . $control . '</div>';

			case 'textarea':
				return '<div class="mlsimport-mb__f mlsimport-mb__f--full">' . $label
					. '<textarea id="' . $id . '" name="' . $name . '" rows="3">' . esc_textarea( (string) $value ) . '</textarea></div>';

			case 'select':
				// Build one <option> per choice, marking the stored value selected.
				$options = '';
				foreach ( (array) ( $field['options'] ?? array() ) as $opt_val => $opt_label ) {
					$options .= '<option value="' . esc_attr( $opt_val ) . '"' . selected( (string) $value, (string) $opt_val, false ) . '>' . esc_html( $opt_label ) . '</option>';
				}
				return '<div class="mlsimport-mb__f">' . $label
					. '<select id="' . $id . '" name="' . $name . '"><option value=""></option>' . $options . '</select></div>';

			case 'number':
			case 'int':
				// 'number' allows decimals (step=any); 'int' stays whole.
				$step = 'number' === $field['type'] ? ' step="any"' : '';
				return '<div class="mlsimport-mb__f">' . $label
					. '<input type="number"' . $step . ' id="' . $id . '" name="' . $name . '" value="' . esc_attr( (string) $value ) . '"></div>';

			case 'date':
				return '<div class="mlsimport-mb__f">' . $label
					. '<input type="date" id="' . $id . '" name="' . $name . '" value="' . esc_attr( substr( (string) $value, 0, 10 ) ) . '"></div>';

			default: // text.
				return '<div class="mlsimport-mb__f">' . $label
					. '<input type="text" id="' . $id . '" name="' . $name . '" value="' . esc_attr( (string) $value ) . '"></div>';
		}
	}

	/**
	 * Persist the metabox: guard, extract (whitelist + coerce), write meta.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public static function save( $post_id, $post ): void {
		// Skip autosaves — they carry no metabox input.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		// Verify the CSRF nonce.
		if ( ! isset( $_POST['mlsimport_agent_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['mlsimport_agent_nonce'] ) ), 'mlsimport_save_agent' ) ) {
			return;
		}
		// Capability check for this specific post.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Grab the raw posted field array (slashes stripped; sanitized per-field below).
		$input = isset( $_POST['mlsimport_agent'] ) && is_array( $_POST['mlsimport_agent'] )
			? wp_unslash( $_POST['mlsimport_agent'] ) // phpcs:ignore WordPress.Security.ValidatedSanitized.InputNotSanitized -- sanitized per-field below.
			: array();

		// Build a key => type lookup to drive per-field sanitization.
		$types = array();
		foreach ( self::fields() as $field ) {
			$types[ $field['key'] ] = $field['type'];
		}

		// Whitelist + coerce, then sanitize and persist each value.
		foreach ( self::extract( $input ) as $key => $value ) {
			// String values: textarea vs single-line sanitizer by field type.
			if ( is_string( $value ) ) {
				$value = 'textarea' === $types[ $key ] ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
			}
			// Write to the mlsimport_<key> post meta.
			update_post_meta( $post_id, self::meta_key( $key ), $value );
		}
	}
}
