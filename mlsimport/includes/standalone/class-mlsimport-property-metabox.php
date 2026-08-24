<?php
/**
 * Standalone (theme_id 990) "MLS Listing" property metabox.
 *
 * One editable tabbed metabox on the mlsimport_property CPT. The tabs mirror
 * the field-bearing sections of the front-end Property Page Layout ("Arrange
 * Sections" in Design Settings → Property Page): Price & Financial, Interior,
 * Exterior, Structure, Property Gallery, Utilities, Schools, Location, Listing
 * Info, Agent & Office, Other Details. Every imported field is placed on the
 * tab of the front-end section it renders in (Mlsimport_Property_Field_Sections
 * ::section_of()); fields with no section land on Other Details. Taxonomies
 * stay in their native WordPress sidebar boxes (decided); this box holds the
 * post-meta / flat-column fields only.
 *
 * The catalog + whitelist + coercion + postal rule are pure (no WordPress, no DB)
 * so they are unit-testable in isolation; register/render/save are thin wrappers.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The Media tab reads the gallery through the same Photos Count-capped helper the
// front-end sections use, so both surfaces agree on what is published.
require_once __DIR__ . '/property-sections.php';

/**
 * Builds, renders and saves the standalone property metabox.
 */
class Mlsimport_Property_Metabox {

	/** AJAX action for deleting one gallery image. */
	const DELETE_ACTION = 'mlsimport_delete_gallery_image';

	/**
	 * The tabs, in display order: slug => editor-facing label.
	 *
	 * The list mirrors the field-bearing sections of the front-end Property Page
	 * Layout, in the agreed order: Price first, the gallery right after
	 * Structure. "Agent & Office" also hosts the Lead Agent picker (there is no
	 * separate Lead Routing tab); "Other Details" collects every field with no
	 * section of its own, including the plugin's local display/compliance flags.
	 *
	 * @return array<string,string>
	 */
	public static function tabs(): array {
		return array(
			'price'     => 'Price & Financial',
			'interior'  => 'Interior',
			'exterior'  => 'Exterior',
			'structure' => 'Structure',
			'media'     => 'Property Gallery',
			'utilities' => 'Utilities',
			'schools'   => 'Schools',
			'location'  => 'Location',
			'listing'   => 'Listing Info',
			'agent'     => 'Agent & Office',
			'other'     => 'Other Details',
		);
	}

	/**
	 * Which front-end field section each tab shows, tab slug => section slug
	 * (the slugs of mlsimport_property_field_section_titles()).
	 *
	 * Any imported field whose section maps here renders on that tab, right
	 * after the tab's fixed catalog fields. Tabs absent from this map (media,
	 * agent) carry no dynamic imported fields of their own.
	 *
	 * @return array<string,string>
	 */
	public static function tab_sections(): array {
		return array(
			'price'     => 'financial',
			'interior'  => 'interior',
			'exterior'  => 'exterior',
			'structure' => 'structure',
			'utilities' => 'utilities',
			'schools'   => 'schools',
			'location'  => 'location',
			'listing'   => 'listing_info',
			'other'     => 'other',
		);
	}

	/**
	 * The field catalog — the single source of truth for both render and save.
	 *
	 * Each field: key (meta-key suffix; stored as mlsimport_<key>), tab (a tabs()
	 * slug), label, type (text|textarea|number|int|date|checkbox|select), origin
	 * (mls = imported, may be re-synced | local = the operator's own value), and
	 * for selects an options list. Taxonomy-backed fields (status, type, city,
	 * state, county, zip, area, features, labels) are intentionally absent — they
	 * live in the native WordPress sidebar boxes.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function fields(): array {
		return array(
			// Price & Financial — list prices plus the HOA/tax financial fields.
			array( 'key' => 'ListPrice', 'tab' => 'price', 'label' => 'List Price', 'type' => 'number', 'origin' => 'mls' ),
			array( 'key' => 'currency', 'tab' => 'price', 'label' => 'Currency', 'type' => 'select', 'origin' => 'local', 'options' => array( 'USD' => 'USD ($)', 'CAD' => 'CAD ($)' ) ),
			array( 'key' => 'OriginalListPrice', 'tab' => 'price', 'label' => 'Original Price', 'type' => 'number', 'origin' => 'mls' ),
			array( 'key' => 'PreviousListPrice', 'tab' => 'price', 'label' => 'Previous Price', 'type' => 'number', 'origin' => 'mls' ),
			array( 'key' => 'ClosePrice', 'tab' => 'price', 'label' => 'Sold Price', 'type' => 'number', 'origin' => 'mls' ),
			array( 'key' => 'AssociationFee', 'tab' => 'price', 'label' => 'HOA Fee', 'type' => 'number', 'origin' => 'mls' ),
			array( 'key' => 'AssociationFeeFrequency', 'tab' => 'price', 'label' => 'HOA Frequency', 'type' => 'select', 'origin' => 'mls', 'options' => array( 'Monthly' => 'Monthly', 'Quarterly' => 'Quarterly', 'Annually' => 'Annually' ) ),
			array( 'key' => 'TaxAnnualAmount', 'tab' => 'price', 'label' => 'Annual Tax', 'type' => 'number', 'origin' => 'mls' ),
			array( 'key' => 'TaxYear', 'tab' => 'price', 'label' => 'Tax Year', 'type' => 'int', 'origin' => 'mls' ),

			// Interior — rooms and living space (RESO interior group).
			array( 'key' => 'BedroomsTotal', 'tab' => 'interior', 'label' => 'Bedrooms', 'type' => 'number', 'origin' => 'mls' ),
			array( 'key' => 'BathroomsTotalDecimal', 'tab' => 'interior', 'label' => 'Bathrooms (total)', 'type' => 'number', 'origin' => 'mls' ),
			array( 'key' => 'BathroomsFull', 'tab' => 'interior', 'label' => 'Full Baths', 'type' => 'int', 'origin' => 'mls' ),
			array( 'key' => 'BathroomsHalf', 'tab' => 'interior', 'label' => 'Half Baths', 'type' => 'int', 'origin' => 'mls' ),
			array( 'key' => 'LivingArea', 'tab' => 'interior', 'label' => 'Living Area', 'type' => 'number', 'origin' => 'mls' ),
			array( 'key' => 'LivingAreaUnits', 'tab' => 'interior', 'label' => 'Area Units', 'type' => 'select', 'origin' => 'mls', 'options' => array( 'Square Feet' => 'Square Feet', 'Square Meters' => 'Square Meters' ) ),

			// Exterior — lot and parking (RESO exterior group).
			array( 'key' => 'LotSizeSquareFeet', 'tab' => 'exterior', 'label' => 'Lot Size (sq ft)', 'type' => 'number', 'origin' => 'mls' ),
			array( 'key' => 'LotSizeAcres', 'tab' => 'exterior', 'label' => 'Lot Size (acres)', 'type' => 'number', 'origin' => 'mls' ),
			array( 'key' => 'GarageSpaces', 'tab' => 'exterior', 'label' => 'Garage Spaces', 'type' => 'int', 'origin' => 'mls' ),

			// Structure — the building itself (RESO structure group).
			array( 'key' => 'YearBuilt', 'tab' => 'structure', 'label' => 'Year Built', 'type' => 'int', 'origin' => 'mls' ),
			array( 'key' => 'StoriesTotal', 'tab' => 'structure', 'label' => 'Stories', 'type' => 'int', 'origin' => 'mls' ),

			// Property Gallery — the published-photos cap; the photo strip renders below.
			array( 'key' => 'PhotosCount', 'tab' => 'media', 'label' => 'Photos Count', 'type' => 'int', 'origin' => 'mls' ),

			// Location — address parts and coordinates (covers the Map section's data).
			array( 'key' => 'StreetNumber', 'tab' => 'location', 'label' => 'Street Number', 'type' => 'text', 'origin' => 'mls' ),
			array( 'key' => 'StreetName', 'tab' => 'location', 'label' => 'Street Name', 'type' => 'text', 'origin' => 'mls' ),
			array( 'key' => 'UnitNumber', 'tab' => 'location', 'label' => 'Unit Number', 'type' => 'text', 'origin' => 'mls' ),
			array( 'key' => 'UnparsedAddress', 'tab' => 'location', 'label' => 'Full Address', 'type' => 'text', 'origin' => 'mls' ),
			array( 'key' => 'Latitude', 'tab' => 'location', 'label' => 'Latitude', 'type' => 'number', 'origin' => 'mls' ),
			array( 'key' => 'Longitude', 'tab' => 'location', 'label' => 'Longitude', 'type' => 'number', 'origin' => 'mls' ),

			// Listing Info — MLS identifiers, status and the listing dates.
			array( 'key' => 'ListingId', 'tab' => 'listing', 'label' => 'MLS Number', 'type' => 'text', 'origin' => 'mls' ),
			array( 'key' => 'ListingKey', 'tab' => 'listing', 'label' => 'Listing Key', 'type' => 'text', 'origin' => 'mls' ),
			array( 'key' => 'MlsStatus', 'tab' => 'listing', 'label' => 'MLS Status (raw)', 'type' => 'text', 'origin' => 'mls' ),
			array( 'key' => 'DaysOnMarket', 'tab' => 'listing', 'label' => 'Days on Market', 'type' => 'int', 'origin' => 'mls' ),
			array( 'key' => 'ListingContractDate', 'tab' => 'listing', 'label' => 'Listing Date', 'type' => 'date', 'origin' => 'mls' ),
			array( 'key' => 'OnMarketDate', 'tab' => 'listing', 'label' => 'On-Market Date', 'type' => 'date', 'origin' => 'mls' ),
			array( 'key' => 'CloseDate', 'tab' => 'listing', 'label' => 'Close Date', 'type' => 'date', 'origin' => 'mls' ),
			array( 'key' => 'ModificationTimestamp', 'tab' => 'listing', 'label' => 'Last Updated', 'type' => 'text', 'origin' => 'mls' ),

			// Agent & Office — the MLS feed's attribution; the Lead Agent picker
			// (who actually receives enquiries) renders above these on the same tab.
			array( 'key' => 'ListAgentFullName', 'tab' => 'agent', 'label' => 'Agent Name', 'type' => 'text', 'origin' => 'mls' ),
			array( 'key' => 'ListAgentEmail', 'tab' => 'agent', 'label' => 'Agent Email', 'type' => 'text', 'origin' => 'mls' ),
			array( 'key' => 'ListAgentPreferredPhone', 'tab' => 'agent', 'label' => 'Agent Phone', 'type' => 'text', 'origin' => 'mls' ),
			array( 'key' => 'ListAgentMlsId', 'tab' => 'agent', 'label' => 'Agent MLS ID', 'type' => 'text', 'origin' => 'mls' ),
			array( 'key' => 'ListOfficeName', 'tab' => 'agent', 'label' => 'Office Name', 'type' => 'text', 'origin' => 'mls' ),
			array( 'key' => 'ListOfficePhone', 'tab' => 'agent', 'label' => 'Office Phone', 'type' => 'text', 'origin' => 'mls' ),

			// Other Details — fields with no front-end section of their own: the
			// media URLs and the plugin's local display/compliance flags.
			array( 'key' => 'virtual_tour', 'tab' => 'other', 'label' => 'Virtual Tour URL', 'type' => 'text', 'origin' => 'mls' ),
			array( 'key' => 'VideoURL', 'tab' => 'other', 'label' => 'Video URL', 'type' => 'text', 'origin' => 'mls' ),
			array( 'key' => 'idx_display', 'tab' => 'other', 'label' => 'Show on website (IDX)', 'type' => 'checkbox', 'origin' => 'local' ),
			array( 'key' => 'show_address', 'tab' => 'other', 'label' => 'Show street address', 'type' => 'checkbox', 'origin' => 'local' ),
			array( 'key' => 'featured', 'tab' => 'other', 'label' => 'Featured listing', 'type' => 'checkbox', 'origin' => 'local' ),
			array( 'key' => 'admin_note', 'tab' => 'other', 'label' => 'Internal note (staff only)', 'type' => 'textarea', 'origin' => 'local' ),
			array( 'key' => 'attribution', 'tab' => 'other', 'label' => 'MLS / REALTOR attribution & disclaimer', 'type' => 'textarea', 'origin' => 'mls' ),
		);
	}

	/**
	 * The save whitelist: the meta-key suffix of every catalog field. The save
	 * handler writes only these, so nothing posted outside the catalog persists.
	 *
	 * @return string[]
	 */
	public static function save_keys(): array {
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
	 * @param string $type Field type (number|int|checkbox|...).
	 * @param mixed  $raw  Raw posted value.
	 * @return mixed Typed value, or null when an empty/invalid numeric is given.
	 */
	public static function coerce( string $type, $raw ) {
		// Numeric fields: cast to float, or null when the input is empty/non-numeric.
		if ( 'number' === $type ) {
			return is_numeric( $raw ) ? (float) $raw : null;
		}
		// Integer fields: cast to int, or null when empty/non-numeric.
		if ( 'int' === $type ) {
			return is_numeric( $raw ) ? (int) $raw : null;
		}
		// Checkbox: absent/empty means unchecked (0), anything present means checked (1).
		if ( 'checkbox' === $type ) {
			return ( '' === $raw || null === $raw ) ? 0 : 1;
		}

		// Everything else is text: trim strings, pass other types through untouched.
		return is_string( $raw ) ? trim( $raw ) : $raw;
	}

	/**
	 * Build the persist map from a raw posted field array (the inner
	 * $_POST['mlsimport_meta']). Iterating the catalog — not the input — is what
	 * enforces the whitelist: keys absent from the catalog can never appear, and
	 * unchecked checkboxes (absent from POST) still resolve to 0.
	 *
	 * @param array $input Raw posted values keyed by catalog field key.
	 * @return array<string,mixed> Catalog key => coerced value.
	 */
	public static function extract( array $input ): array {
		$out = array();
		// Iterate the catalog (not the input) so only known keys can ever be produced.
		foreach ( self::fields() as $field ) {
			$key         = $field['key'];
			// A field missing from POST (e.g. an unchecked checkbox) coerces from null.
			$raw         = array_key_exists( $key, $input ) ? $input[ $key ] : null;
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
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'add_meta_boxes_mlsimport_property', array( __CLASS__, 'add_box' ) );
		add_action( 'save_post_mlsimport_property', array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_' . self::DELETE_ACTION, array( __CLASS__, 'ajax_delete_image' ) );
	}

	/**
	 * Delete one gallery image: drop it from the gallery meta and delete the
	 * attachment. When it was the featured image, the next survivor takes over.
	 *
	 * @return void
	 */
	public static function ajax_delete_image(): void {
		check_ajax_referer( self::DELETE_ACTION, 'nonce' );

		$post_id  = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		$attach_id = isset( $_POST['attach_id'] ) ? absint( wp_unslash( $_POST['attach_id'] ) ) : 0;

		// Both ids are required, and the caller must be allowed to edit this listing.
		if ( ! $post_id || ! $attach_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'mlsimport' ) ), 403 );
		}

		// Only ever touch an attachment that belongs to THIS property's gallery —
		// otherwise a forged id could delete any attachment on the site.
		$ids = get_post_meta( $post_id, 'mlsimport_gallery', true );
		$ids = is_array( $ids ) ? array_values( array_filter( array_map( 'intval', $ids ) ) ) : array();
		if ( ! in_array( $attach_id, $ids, true ) ) {
			wp_send_json_error( array( 'message' => __( 'That image is not in this gallery.', 'mlsimport' ) ), 400 );
		}

		// Note this BEFORE deleting: wp_delete_attachment() clears every _thumbnail_id
		// row pointing at the attachment, so afterwards there is no way to tell that
		// this image was the featured one.
		$was_featured = (int) get_post_thumbnail_id( $post_id ) === $attach_id;

		// Drop it from the gallery, then delete the attachment itself.
		$remaining = array_values( array_diff( $ids, array( $attach_id ) ) );
		update_post_meta( $post_id, 'mlsimport_gallery', $remaining );
		wp_delete_attachment( $attach_id, true );

		// Losing the featured image would leave the listing thumbnail-less, so hand
		// the role to the first remaining photo.
		if ( $was_featured && $remaining ) {
			set_post_thumbnail( $post_id, $remaining[0] );
		}

		/** Fires after a gallery image is deleted from the metabox. @since 6.3 */
		do_action( 'mlsimport_property_gallery_image_deleted', $attach_id, $post_id, $remaining );

		wp_send_json_success(
			array(
				'remaining' => count( $remaining ),
				'featured'  => (int) get_post_thumbnail_id( $post_id ),
			)
		);
	}

	/**
	 * Register the single tabbed metabox.
	 *
	 * @return void
	 */
	public static function add_box(): void {
		add_meta_box(
			'mlsimport_listing',
			esc_html__( 'MLS Listing', 'mlsimport' ),
			array( __CLASS__, 'render' ),
			'mlsimport_property',
			'normal',
			'high'
		);
	}

	/**
	 * Enqueue the tab JS/CSS on the property edit screen only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function assets( $hook ): void {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		if ( 'mlsimport_property' !== get_post_type() ) {
			return;
		}
		$base = defined( 'MLSIMPORT_PLUGIN_URL' ) ? MLSIMPORT_PLUGIN_URL : plugin_dir_url( dirname( __DIR__ ) . '/mlsimport.php' );
		$ver  = defined( 'MLSIMPORT_VERSION' ) ? MLSIMPORT_VERSION : false;
		wp_enqueue_style( 'mlsimport-property-metabox', $base . 'admin/css/mlsimport-property-metabox.css', array(), $ver );
		// Re-theme the metabox accent from the "Main Color" design setting.
		if ( function_exists( 'mlsimport_standalone_attach_brand_color_metabox' ) ) {
			mlsimport_standalone_attach_brand_color_metabox( 'mlsimport-property-metabox' );
		}
		wp_enqueue_script( 'mlsimport-property-metabox', $base . 'admin/js/mlsimport-property-tabs.js', array(), $ver, true );
		wp_localize_script(
			'mlsimport-property-metabox',
			'mlsimportMetabox',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'action'    => self::DELETE_ACTION,
				'nonce'     => wp_create_nonce( self::DELETE_ACTION ),
				'confirm'   => __( 'Delete this image permanently? This cannot be undone.', 'mlsimport' ),
				'failed'    => __( 'Could not delete the image.', 'mlsimport' ),
				'featured'  => __( 'Featured', 'mlsimport' ),
			)
		);
	}

	/**
	 * Render the tabbed metabox. Every field is an editable input named
	 * mlsimport_meta[<key>]; values come from mlsimport_<key> post meta.
	 *
	 * @param WP_Post $post Current property post.
	 * @return void
	 */
	public static function render( $post ): void {
		wp_nonce_field( 'mlsimport_save_listing', 'mlsimport_listing_nonce' );

		$tabs = self::tabs();
		// The fixed catalog fields, and the per-listing dynamic imported fields
		// (field => front-end section) that get distributed across the tabs.
		$fields   = self::fields();
		$dynamic  = self::dynamic_field_keys( (int) $post->ID );
		$sections = self::tab_sections();

		echo '<div class="mlsimport-mb">';

		// Tab rail.
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

		// Panes.
		echo '<div class="mlsimport-mb__panes">';
		$first = true;
		foreach ( $tabs as $slug => $label ) {
			printf( '<div class="mlsimport-mb__pane%s" data-pane="%s">', $first ? ' is-active' : '', esc_attr( $slug ) );

			// The Agent & Office tab owns lead assignment too: the editable "Lead
			// Agent" picker plus a read-only summary of who actually gets
			// enquiries, rendered above the MLS feed's own attribution fields —
			// everything agent-related lives on this one tab.
			if ( 'agent' === $slug ) {
				echo self::render_lead_agent_field( (int) $post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped within.
				echo self::render_lead_routing( (int) $post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped within.
			}

			// A pointer on Other Details: hidden-from-public fields are edited in
			// the separate "Mls Import Hidden Fields" box, never here.
			if ( 'other' === $slug ) {
				echo '<p class="mlsimport-mb__other-note">'
					. esc_html__( 'Everything imported for this listing that has no section of its own. Fields hidden from the public page are in the “Mls Import Hidden Fields” box below.', 'mlsimport' )
					. '</p>';
			}

			// One grid per tab: the fixed catalog fields first, then every imported
			// field whose front-end section belongs to this tab (tab_sections()).
			$grid = '';
			foreach ( $fields as $field ) {
				if ( $field['tab'] !== $slug ) {
					continue;
				}
				$value = get_post_meta( $post->ID, self::meta_key( $field['key'] ), true );
				$grid .= self::render_field( $field, $value );
			}
			if ( isset( $sections[ $slug ] ) ) {
				$grid .= self::render_dynamic_fields( (int) $post->ID, $dynamic, $sections[ $slug ] );
			}

			if ( '' === $grid ) {
				// A section tab with no catalog fields and nothing imported (e.g.
				// Schools on a listing without school data) says so, not an empty pane.
				echo '<p class="mlsimport-mb__other-empty">'
					. esc_html__( 'No fields imported for this listing in this section.', 'mlsimport' )
					. '</p>';
			} else {
				echo '<div class="mlsimport-mb__grid">' . $grid . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped within.
			}

			// The Media tab also shows the imported photos themselves, below its fields.
			if ( 'media' === $slug ) {
				echo self::render_gallery( (int) $post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped within.
			}

			echo '</div>';
			$first = false;
		}
		echo '</div></div>';
	}

	/** Posted field name carrying the Lead Agent choice from the Agent & Office tab. */
	const LEAD_AGENT_FIELD = 'mlsimport_lead_agent';

	/** Lead Agent select value meaning "use the MLS feed's own listing agent". */
	const LEAD_AGENT_FEED = '__feed__';

	/**
	 * Every mlsimport_agent post as id => display name, for the Lead Agent picker.
	 * Ordered by title so the dropdown reads alphabetically.
	 *
	 * @return array<int,string>
	 */
	private static function agent_choices(): array {
		$agents = get_posts(
			array(
				'post_type'      => 'mlsimport_agent',
				'post_status'    => 'publish',
				'numberposts'    => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'suppress_filters' => false,
			)
		);
		$out = array();
		foreach ( (array) $agents as $aid ) {
			$out[ (int) $aid ] = get_the_title( (int) $aid );
		}
		return $out;
	}

	/**
	 * The editable "Lead Agent" picker: choose a local agent post to receive this
	 * listing's enquiries, or fall back to the MLS feed's own listing agent (which
	 * routes leads to the site recipient). Written by save() to
	 * mlsimport_list_agent_id + mlsimport_use_mls_agent — the same two meta keys the
	 * Import Task sets — so the front end and lead endpoint need no changes.
	 *
	 * @param int $post_id Property post ID.
	 * @return string
	 */
	private static function render_lead_agent_field( int $post_id ): string {
		$agent_id = (int) get_post_meta( $post_id, 'mlsimport_list_agent_id', true );
		$use_feed = (bool) intval( get_post_meta( $post_id, 'mlsimport_use_mls_agent', true ) );
		// The current selection mirrors the display resolver: a local agent wins only
		// when one is picked AND feed mode is off; anything else means feed.
		$current  = ( ! $use_feed && $agent_id > 0 ) ? (string) $agent_id : self::LEAD_AGENT_FEED;

		$name = self::LEAD_AGENT_FIELD;
		$id   = 'mlsimport_lead_agent';

		// The always-present "feed / no local agent" option — this is the state of a
		// listing imported without an agent assigned.
		$options = '<option value="' . esc_attr( self::LEAD_AGENT_FEED ) . '"'
			. selected( $current, self::LEAD_AGENT_FEED, false ) . '>'
			. esc_html__( 'Company Email (no agent for this property)', 'mlsimport' )
			. '</option>';

		// Every agent post, grouped so the names sit apart from the company-email option.
		$choices = self::agent_choices();
		if ( $choices ) {
			$options .= '<optgroup label="' . esc_attr__( 'Agents', 'mlsimport' ) . '">';
			foreach ( $choices as $aid => $label ) {
				$options .= '<option value="' . esc_attr( (string) $aid ) . '"'
					. selected( $current, (string) $aid, false ) . '>'
					. esc_html( '' !== $label ? $label : sprintf( /* translators: %d: agent post id. */ __( 'Agent #%d', 'mlsimport' ), $aid ) )
					. '</option>';
			}
			$options .= '</optgroup>';
		}

		// A hint when there are no agents to pick yet, so the empty dropdown is not a mystery.
		$hint = $choices
			? esc_html__( 'Choose who gets the leads for this property. Your choice here replaces the agent set when the property was imported.', 'mlsimport' )
			: esc_html__( 'You don’t have any agents yet. Add one to pick it here — until then, leads go to your Company Email.', 'mlsimport' );

		return '<div class="mlsimport-mb__leadedit">'
			. '<div class="mlsimport-mb__f mlsimport-mb__f--full">'
			. '<label for="' . $id . '">' . esc_html__( 'Who receives leads for this property?', 'mlsimport' ) . '</label>'
			. '<select id="' . $id . '" name="' . esc_attr( $name ) . '">' . $options . '</select>'
			. '<p class="mlsimport-mb__leadedit-hint">' . $hint . '</p>'
			. '</div></div>';
	}

	/**
	 * Read-only "Lead Routing" summary for the Agent & Office tab.
	 *
	 * States who receives enquiries for this listing and why, right under the
	 * editable Lead Agent picker, so the operator can confirm their choice took.
	 * The recipient is resolved exactly as the live lead endpoint resolves it
	 * (linked agent post → settings recipient → site admin), so the panel can never
	 * disagree with where a real enquiry would actually land.
	 *
	 * @param int $post_id Property post ID.
	 * @return string
	 */
	private static function render_lead_routing( int $post_id ): string {
		// The property view model carries the resolved agent (linked post or feed).
		$vm    = function_exists( 'mlsimport_property_data' ) ? mlsimport_property_data( $post_id ) : array();
		$agent = isset( $vm['agent'] ) && is_array( $vm['agent'] ) ? $vm['agent'] : array();

		// The exact address the live lead endpoint would email for this listing.
		$to = class_exists( 'Mlsimport_Property_Lead' ) ? (string) Mlsimport_Property_Lead::recipient( $post_id ) : '';

		// Whether that address is the assigned agent's own email, or the company /
		// fallback email used when a property has no assignable agent.
		$agent_email = ! empty( $agent['id'] ) && ! empty( $agent['email'] ) && is_email( (string) $agent['email'] ) ? (string) $agent['email'] : '';
		$to_is_agent = '' !== $agent_email && strtolower( $agent_email ) === strtolower( $to );

		// Headline: where a lead for this property actually lands.
		$headline = '' !== $to
			? sprintf(
				/* translators: %s: recipient email address. */
				esc_html__( 'Leads for this property are emailed to %s', 'mlsimport' ),
				'<strong>' . esc_html( $to ) . '</strong>'
			)
			: esc_html__( 'No email is set yet — leads would go to your website’s admin email.', 'mlsimport' );

		// Explanation of the source + where the operator can change it.
		if ( $to_is_agent ) {
			$name    = '' !== (string) $agent['name'] ? (string) $agent['name'] : (string) get_the_title( (int) $agent['id'] );
			$explain = sprintf(
				/* translators: %s: assigned agent name. */
				esc_html__( 'That’s the agent for this property (%s). Change it with the dropdown above.', 'mlsimport' ),
				'<strong>' . esc_html( $name ) . '</strong>'
			);
		} else {
			// Company / fallback email — this is the "no assigned agent" case.
			$settings_url  = admin_url( 'admin.php?page=mlsimport_standalone_settings' );
			$company_email = (string) mlsimport_standalone_option( 'lead_recipient', '' );
			$explain       = sprintf(
				/* translators: %s: link to the settings page. */
				esc_html__( 'This property has no agent, so leads go to your Company Email. You can change that email under %s.', 'mlsimport' ),
				'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Design Settings → Social & Contact → Email', 'mlsimport' ) . '</a>'
			);
			if ( '' === $company_email ) {
				$explain .= ' ' . esc_html__( 'You haven’t set a Company Email yet, so leads go to your website’s admin email for now.', 'mlsimport' );
			}
		}

		return '<div class="mlsimport-mb__lead">'
			. '<h4 class="mlsimport-mb__lead-title">' . esc_html__( 'Where do leads go?', 'mlsimport' ) . '</h4>'
			. '<p class="mlsimport-mb__lead-to">' . $headline . '</p>'
			. '<p class="mlsimport-mb__lead-src">' . $explain . '</p>'
			. '</div>';
	}

	/** POST namespace carrying the dynamic imported-field values (all tabs). */
	const OTHER_FIELD_NS = 'mlsimport_other';

	/**
	 * The dynamic imported fields for THIS listing, field => front-end section
	 * slug: ticked for import in the field selector, NOT a catalog field, public,
	 * AND actually carrying a value on this property. In the field selector's own
	 * drag order. Each renders on the tab its section maps to (tab_sections());
	 * unmapped/proprietary fields resolve to 'other' → the Other Details tab.
	 *
	 * The value filter is what keeps the tabs usable: a site can tick hundreds of
	 * RESO fields, but any one listing only fills a fraction of them — the box
	 * mirrors "what is imported for this listing" (the same set the front end
	 * renders), never a wall of empty inputs.
	 *
	 * @param int $post_id Property post ID.
	 * @return array<string,string>
	 */
	private static function dynamic_field_keys( int $post_id ): array {
		$options = mlsimport_active_field_configuration();
		if ( ! is_array( $options ) || empty( $options['mls-fields'] ) || ! is_array( $options['mls-fields'] ) ) {
			return array();
		}
		// The catalog keys already have their own editable inputs; never render
		// them twice, so a field can only ever be edited in one place.
		$covered = array_fill_keys( self::save_keys(), true );

		// Fields hidden from the public page (mls-fields-admin) live in the separate
		// "Mls Import Hidden Fields" box — never here, so a field appears in exactly
		// one place: public fields on these tabs, hidden fields in that box.
		$hidden = isset( $options['mls-fields-admin'] ) && is_array( $options['mls-fields-admin'] ) ? $options['mls-fields-admin'] : array();

		$ordered = function_exists( 'mlsimport_property_fields_in_order' )
			? mlsimport_property_fields_in_order( $options )
			: $options['mls-fields'];

		$out = array();
		foreach ( $ordered as $field => $is_imported ) {
			$field = (string) $field;
			if ( 1 !== (int) $is_imported || isset( $covered[ $field ] ) || ! empty( $hidden[ $field ] ) ) {
				continue;
			}
			$value = function_exists( 'mlsimport_property_field_value' )
				? mlsimport_property_field_value( $post_id, $field )
				: (string) get_post_meta( $post_id, 'mlsimport_' . $field, true );
			if ( '' === $value ) {
				continue; // this listing has no value for it → not "imported" here.
			}
			// The front-end section decides the tab; unmapped fields fall to 'other'.
			$out[ $field ] = class_exists( 'Mlsimport_Property_Field_Sections' )
				? Mlsimport_Property_Field_Sections::section_of( $field )
				: 'other';
		}
		return $out;
	}

	/**
	 * The dynamic imported-field controls for one tab: every field whose front-end
	 * section is $section, editable, in field-selector order. Values save straight
	 * to the same mlsimport_<Field> meta the importer wrote.
	 *
	 * @param int    $post_id Property post ID.
	 * @param array  $dynamic dynamic_field_keys() result (field => section slug).
	 * @param string $section The front-end section slug this tab shows.
	 * @return string Concatenated field controls ('' when none match).
	 */
	private static function render_dynamic_fields( int $post_id, array $dynamic, string $section ): string {
		$options = mlsimport_active_field_configuration();
		$labels  = is_array( $options ) && isset( $options['mls-fields-label'] ) && is_array( $options['mls-fields-label'] ) ? $options['mls-fields-label'] : array();

		$rows = '';
		foreach ( $dynamic as $field => $field_section ) {
			if ( $field_section !== $section ) {
				continue;
			}
			// Raw stored value (mlsimport_<Field>, with the importer's _x_ fallback).
			$value = function_exists( 'mlsimport_property_field_value' )
				? mlsimport_property_field_value( $post_id, $field )
				: (string) get_post_meta( $post_id, 'mlsimport_' . $field, true );

			$label = function_exists( 'mlsimport_property_field_label' )
				? mlsimport_property_field_label( $field, $labels )
				: $field;

			$rows .= self::render_dynamic_field( $field, $label, $value );
		}
		return $rows;
	}

	/**
	 * One dynamic imported-field control: an editable text input named
	 * mlsimport_other[<Field>], labelled.
	 *
	 * @param string $field RESO field key.
	 * @param string $label Display label.
	 * @param string $value Current stored value.
	 * @return string
	 */
	private static function render_dynamic_field( string $field, string $label, string $value ): string {
		$name = self::OTHER_FIELD_NS . '[' . esc_attr( $field ) . ']';
		$id   = 'mlsimport_other_' . esc_attr( $field );

		return '<div class="mlsimport-mb__f">'
			. '<label for="' . $id . '">' . esc_html( $label ) . '</label>'
			. '<input type="text" id="' . $id . '" name="' . $name . '" value="' . esc_attr( $value ) . '"></div>';
	}

	/**
	 * The imported gallery as a read-only thumbnail strip for the Media tab.
	 *
	 * @param int $post_id Property post ID.
	 * @return string
	 */
	private static function render_gallery( int $post_id ): string {
		// The stored attachments, and the subset Photos Count actually publishes.
		$stored = get_post_meta( $post_id, 'mlsimport_gallery', true );
		$stored = is_array( $stored ) ? array_values( array_filter( array_map( 'intval', $stored ) ) ) : array();
		$ids    = mlsimport_property_gallery_ids( $post_id );

		/** Filter the gallery attachment ids shown in the metabox Media tab. @since 6.3 */
		$ids = (array) apply_filters( 'mlsimport_property_metabox_gallery_ids', $ids, $post_id );

		// Nothing imported (or every id gone) — say so instead of an empty strip.
		if ( ! $stored ) {
			return '<div class="mlsimport-mb__gallery"><p class="mlsimport-mb__gallery-empty">'
				. esc_html__( 'No images imported for this listing.', 'mlsimport' ) . '</p></div>';
		}

		$featured = (int) get_post_thumbnail_id( $post_id );
		// Every stored tile is rendered; the ones Photos Count excludes are hidden
		// rather than dropped, so raising the number back reveals them without a save.
		$shown = count( $ids );

		$thumbs = '';
		foreach ( $stored as $index => $id ) {
			// Imported photos live on the MLS CDN with no local file and no stored
			// dimensions, so wp_get_attachment_image() would emit width="1" height="1"
			// and collapse to a dot. Take the URL and let the CSS tile size it.
			$src = wp_get_attachment_image_url( $id, 'thumbnail' );
			// An attachment that no longer resolves is skipped, not rendered broken.
			if ( ! $src ) {
				continue;
			}

			$badge = $id === $featured
				? '<span class="mlsimport-mb__thumb-badge">' . esc_html__( 'Featured', 'mlsimport' ) . '</span>'
				: '';

			// Click opens the photo itself: these attachments have no local file, so
			// the attachment edit screen would show nothing the tile doesn't already.
			$full = wp_get_attachment_image_url( $id, 'full' );

			$classes = 'mlsimport-mb__thumb';
			$classes .= $id === $featured ? ' is-featured' : '';
			$classes .= $index >= $shown ? ' is-beyond-count' : '';

			$thumbs .= sprintf(
				'<div class="%s" data-id="%d"><a href="%s" target="_blank" rel="noopener"><img src="%s" alt="" loading="lazy"></a>%s'
					. '<button type="button" class="mlsimport-mb__thumb-del" data-id="%d" aria-label="%s" title="%s">&times;</button></div>',
				esc_attr( $classes ),
				$id,
				esc_url( (string) ( $full ? $full : $src ) ),
				esc_url( $src ),
				$badge, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				$id,
				esc_attr__( 'Delete this image', 'mlsimport' ),
				esc_attr__( 'Delete this image', 'mlsimport' )
			);
		}

		// Say "10 of 26" whenever Photos Count is holding images back, so the editor
		// can tell a capped gallery from a short one.
		$title = count( $stored ) > $shown
			? sprintf(
				/* translators: 1: images published, 2: images stored. */
				esc_html__( 'Gallery (%1$d of %2$d images shown)', 'mlsimport' ),
				$shown,
				count( $stored )
			)
			: sprintf(
				/* translators: %d: number of imported images. */
				esc_html( _n( 'Gallery (%d image)', 'Gallery (%d images)', count( $stored ), 'mlsimport' ) ),
				count( $stored )
			);

		// The JS rewrites the heading as Photos Count is typed, so hand it the
		// translated "%1$d of %2$d" pattern rather than let it build English.
		$template = esc_attr__( 'Gallery (%1$d of %2$d images shown)', 'mlsimport' );

		return '<div class="mlsimport-mb__gallery" data-post="' . esc_attr( (string) $post_id ) . '">'
			. '<h4 class="mlsimport-mb__gallery-title" data-template="' . $template . '">' . $title . '</h4>'
			. '<p class="mlsimport-mb__gallery-note">'
			. esc_html__( 'Photos Count controls how many of these are published. Deleting an image removes it permanently.', 'mlsimport' )
			. '</p>'
			. '<div class="mlsimport-mb__thumbs">' . $thumbs . '</div></div>';
	}

	/**
	 * One field control as an HTML string (all inputs editable).
	 *
	 * @param array  $field Catalog field def.
	 * @param mixed  $value Current stored value.
	 * @return string
	 */
	private static function render_field( array $field, $value ): string {
		$name   = 'mlsimport_meta[' . esc_attr( $field['key'] ) . ']';
		$id     = 'mlsimport_' . esc_attr( $field['key'] );
		$label = '<label for="' . $id . '">' . esc_html( $field['label'] ) . '</label>';

		switch ( $field['type'] ) {
			case 'checkbox':
				$control = '<label class="mlsimport-mb__check"><input type="hidden" name="' . $name . '" value=""><input type="checkbox" id="' . $id . '" name="' . $name . '" value="1"' . checked( '1', (string) $value, false ) . '> ' . esc_html( $field['label'] ) . '</label>';
				return '<div class="mlsimport-mb__f mlsimport-mb__f--check">' . $control . '</div>';

			case 'textarea':
				return '<div class="mlsimport-mb__f mlsimport-mb__f--full">' . $label
					. '<textarea id="' . $id . '" name="' . $name . '" rows="3">' . esc_textarea( (string) $value ) . '</textarea></div>';

			case 'select':
				$options = '';
				foreach ( (array) ( $field['options'] ?? array() ) as $opt_val => $opt_label ) {
					$options .= '<option value="' . esc_attr( $opt_val ) . '"' . selected( (string) $value, (string) $opt_val, false ) . '>' . esc_html( $opt_label ) . '</option>';
				}
				return '<div class="mlsimport-mb__f">' . $label
					. '<select id="' . $id . '" name="' . $name . '"><option value=""></option>' . $options . '</select></div>';

			case 'number':
			case 'int':
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
	 * Persist the metabox: guard, extract (whitelist + coerce), write meta, then
	 * resync the flat search row so edits show in listings/search immediately.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public static function save( $post_id, $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST['mlsimport_listing_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['mlsimport_listing_nonce'] ) ), 'mlsimport_save_listing' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$input = isset( $_POST['mlsimport_meta'] ) && is_array( $_POST['mlsimport_meta'] )
			? wp_unslash( $_POST['mlsimport_meta'] ) // phpcs:ignore WordPress.Security.ValidatedSanitized.InputNotSanitized -- sanitized per-field below.
			: array();

		$types = array();
		foreach ( self::fields() as $field ) {
			$types[ $field['key'] ] = $field['type'];
		}

		foreach ( self::extract( $input ) as $key => $value ) {
			if ( is_string( $value ) ) {
				$value = 'textarea' === $types[ $key ] ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
			}
			update_post_meta( $post_id, self::meta_key( $key ), $value );
		}

		// Lead Agent picker (Agent & Office tab): writes the same two meta keys the
		// Import Task sets. A real agent post id assigns that local agent; the
		// "feed" sentinel (or an id that is not a published mlsimport_agent) means
		// "use the MLS feed's listing agent", which routes leads to the recipient.
		if ( isset( $_POST[ self::LEAD_AGENT_FIELD ] ) ) {
			$choice   = sanitize_text_field( wp_unslash( $_POST[ self::LEAD_AGENT_FIELD ] ) );
			$agent_id = ctype_digit( $choice ) ? (int) $choice : 0;
			// Only trust an id that is an existing published agent post.
			$is_agent = $agent_id > 0 && 'mlsimport_agent' === get_post_type( $agent_id ) && 'publish' === get_post_status( $agent_id );
			if ( $is_agent ) {
				update_post_meta( $post_id, 'mlsimport_list_agent_id', $agent_id );
				update_post_meta( $post_id, 'mlsimport_use_mls_agent', 0 );
			} else {
				// Feed / no local agent: clear the link and flag feed mode explicitly.
				update_post_meta( $post_id, 'mlsimport_list_agent_id', 0 );
				update_post_meta( $post_id, 'mlsimport_use_mls_agent', 1 );
			}
		}

		// Dynamic imported fields (distributed across the section tabs). The
		// whitelist is dynamic_field_keys() (ticked-for-import AND not a catalog
		// key), so a forged key — or a catalog field slipped in here — can never
		// be written.
		if ( isset( $_POST[ self::OTHER_FIELD_NS ] ) && is_array( $_POST[ self::OTHER_FIELD_NS ] ) ) {
			$other   = wp_unslash( $_POST[ self::OTHER_FIELD_NS ] ); // phpcs:ignore WordPress.Security.ValidatedSanitized.InputNotSanitized -- sanitized per-field below.
			$allowed = self::dynamic_field_keys( $post_id );
			foreach ( $other as $field => $raw ) {
				if ( empty( $allowed[ (string) $field ] ) ) {
					continue;
				}
				$val = is_array( $raw )
					? implode( ', ', array_map( 'sanitize_text_field', $raw ) )
					: sanitize_text_field( (string) $raw );
				update_post_meta( $post_id, 'mlsimport_' . (string) $field, $val );
			}
		}

		// Keep the flat search/sort row consistent with the edited meta.
		if ( class_exists( 'Mlsimport_Standalone_Reindex' ) ) {
			Mlsimport_Standalone_Reindex::rebuild_post( $post_id );
		}
	}
}
