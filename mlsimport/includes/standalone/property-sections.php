<?php
/**
 * Standalone (theme_id 990) single-property sections.
 *
 * Each section is a global template-tag function that reads only from the
 * Property view model (built once per request by mlsimport_property_data())
 * and RETURNS an HTML string. One function backs every page builder
 * (Shortcode, Gutenberg, Elementor) — the builders are thin wrappers, this is
 * the single source of markup. See docs/adr/0005 and CONTEXT.md
 * (Property section, Property view model).
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-mlsimport-standalone-settings.php';
require_once __DIR__ . '/class-mlsimport-property-field-sections.php';
require_once __DIR__ . '/class-mlsimport-standalone-derive.php';

/**
 * Build the normalized view model for one property, memoized per request.
 *
 * Scalars come from the mlsimport_listings flat row (the canonical source for
 * filterable/sortable fields). Sections read from this array only — never the
 * DB directly — so there is one assembly per property per request.
 *
 * @param int $id Property post ID. 0 = current loop post.
 * @return array View model (empty array when no property resolves).
 */
function mlsimport_property_data( int $id = 0 ): array {
	// Per-request memo keyed by post ID; one assembly per property per request.
	static $cache = array();

	/** Short-circuit the view model (live mode serves post-less listings here). @since 6.4 */
	$pre = apply_filters( 'mlsimport_property_data_pre', null, $id );
	// A filter that returned an array wins outright — no post/DB lookup happens.
	if ( is_array( $pre ) ) {
		return $pre;
	}

	// Fall back to the current loop post when no explicit ID is given.
	$id = $id ? $id : (int) get_the_ID();
	// No resolvable post: nothing to build.
	if ( ! $id ) {
		return array();
	}
	// Return the memoized view model on a repeat call for the same property.
	if ( isset( $cache[ $id ] ) ) {
		return $cache[ $id ];
	}

	global $wpdb;
	// The flat search table: one canonical row of filterable/sortable scalars per post.
	$table = $wpdb->prefix . 'mlsimport_listings';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$row  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d", $id ) );
	// The WP post backs title/permalink/body/excerpt (may be null in live mode).
	$post = get_post( $id );

	// One primed read of all post meta; a closure pulls mlsimport_<key> scalars.
	$all_meta = get_post_meta( $id );
	$meta     = static function ( $key ) use ( $all_meta ) {
		return isset( $all_meta[ 'mlsimport_' . $key ][0] ) ? $all_meta[ 'mlsimport_' . $key ][0] : '';
	};

	// Flat-table column => RESO meta-key fallback. The search row is canonical for
	// filtering/sorting, but listings imported before the row was populated keep
	// their values only in post meta — so each scalar falls back to meta when the
	// row value is absent (one rule that keeps display working whether or not the
	// search row has been (re)built).
	$col_meta = array(
		'price'          => 'ListPrice',
		'bedrooms'       => 'BedroomsTotal',
		'bathrooms'      => 'BathroomsTotalDecimal',
		'living_area'    => 'LivingArea',
		'year_built'     => 'YearBuilt',
		'days_on_market' => 'DaysOnMarket',
		'latitude'       => 'Latitude',
		'longitude'      => 'Longitude',
		'city'           => 'City',
		'state'          => 'StateOrProvince',
		'zip'            => 'PostalCode',
		'subdivision'    => 'SubdivisionName',
		'property_type'  => 'PropertyType',
	);

	// Numeric scalar: flat row first, then the mapped meta key, else null.
	$num = static function ( $col ) use ( $row, $meta, $col_meta ) {
		if ( $row && isset( $row->$col ) && null !== $row->$col && '' !== $row->$col ) {
			return (float) $row->$col;
		}
		if ( isset( $col_meta[ $col ] ) ) {
			$m = $meta( $col_meta[ $col ] );
			if ( '' !== $m ) {
				return (float) $m;
			}
		}
		return null;
	};
	// String scalar: flat row first, then the mapped meta key, else ''.
	$str = static function ( $col ) use ( $row, $meta, $col_meta ) {
		if ( $row && isset( $row->$col ) && '' !== (string) $row->$col ) {
			return (string) $row->$col;
		}
		return isset( $col_meta[ $col ] ) ? (string) $meta( $col_meta[ $col ] ) : '';
	};

	// Assemble the normalized view model — the only shape any section reads.
	$vm = array(
		'id'             => $id,
		'title'          => $post ? get_the_title( $id ) : '',
		'permalink'      => (string) get_permalink( $id ),
		'content'        => $post ? (string) $post->post_content : '',
		'excerpt'        => $post ? (string) $post->post_excerpt : '',

		// Price + variants.
		'price'          => $num( 'price' ),
		'price_per_sqft' => ( null !== $num( 'price' ) && $num( 'living_area' ) ) ? (int) round( $num( 'price' ) / $num( 'living_area' ) ) : null,
		'original_price' => '' !== $meta( 'OriginalListPrice' ) ? (float) $meta( 'OriginalListPrice' ) : null,
		'close_price'    => '' !== $meta( 'ClosePrice' ) ? (float) $meta( 'ClosePrice' ) : null,
		'previous_price' => '' !== $meta( 'PreviousListPrice' ) ? (float) $meta( 'PreviousListPrice' ) : null,
		'hoa_fee'        => $num( 'hoa_fee' ),
		'hoa_frequency'  => (string) $meta( 'AssociationFeeFrequency' ),

		// Structure / facts.
		'bedrooms'       => $num( 'bedrooms' ),
		'bathrooms'      => $num( 'bathrooms' ),
		'living_area'    => $num( 'living_area' ),
		'lot_size'       => $num( 'lot_size' ),
		'year_built'     => null !== $num( 'year_built' ) ? (int) $num( 'year_built' ) : null,
		'garage'         => null !== $num( 'garage_spaces' ) ? (int) $num( 'garage_spaces' ) : null,
		'stories'        => null !== $num( 'stories' ) ? (int) $num( 'stories' ) : null,
		'days_on_market' => null !== $num( 'days_on_market' ) ? (int) $num( 'days_on_market' ) : null,

		// Location.
		'street'         => mlsimport_property_street_line( $meta ),
		'city'           => $str( 'city' ),
		'state'          => $str( 'state' ),
		'zip'            => $str( 'zip' ),
		'subdivision'    => $str( 'subdivision' ),
		'county'         => (string) $meta( 'CountyOrParish' ),
		'country'        => 'US' === (string) $meta( 'Country' ) ? __( 'United States', 'mlsimport' ) : (string) $meta( 'Country' ),
		'latitude'       => $num( 'latitude' ),
		'longitude'      => $num( 'longitude' ),
		'address'        => mlsimport_property_build_address( $meta, $str ),

		// Type / status.
		'property_type'  => $str( 'property_type' ),
		'property_sub_type' => (string) $meta( 'PropertySubType' ),
		'listing_type'   => $str( 'listing_type' ),
		'status'         => '' !== $str( 'status' ) ? $str( 'status' ) : (string) $meta( 'MlsStatus' ),

		// Provenance / freshness (display-only).
		'mls_id'         => '' !== (string) $meta( 'ListingId' ) ? (string) $meta( 'ListingId' ) : (string) $meta( 'ListingKey' ),
		'updated'        => mlsimport_property_format_date( (string) $meta( 'ModificationTimestamp' ) ),
		// Raw (unformatted) listing date for machine consumers such as JSON-LD
		// datePosted. The ListingContractDate/OnMarketDate preference lives in
		// Mlsimport_Standalone_Derive so there is one rule, not two.
		'list_date'      => (string) Mlsimport_Standalone_Derive::derive_list_date(
			array(
				'ListingContractDate' => $meta( 'ListingContractDate' ),
				'OnMarketDate'        => $meta( 'OnMarketDate' ),
			)
		),

		// Media.
		'thumbnail_id'   => (int) get_post_thumbnail_id( $id ),
		'image_url'      => (string) ( get_post_thumbnail_id( $id ) ? wp_get_attachment_image_url( get_post_thumbnail_id( $id ), 'large' ) : '' ),
		'gallery_ids'    => mlsimport_property_gallery_ids( $id ),
		'virtual_tour'   => (string) $meta( 'virtual_tour' ),
		'video_url'      => (string) $meta( 'VideoURL' ),

		// Features (amenity terms).
		'features'       => mlsimport_property_feature_names( $id ),

		// Resolved agent (linked post preferred, property meta fallback).
		'agent'          => mlsimport_property_agent( $id, $meta ),
	);

	/** Filter the property view model — the single value every section reads. @since 6.3 */
	$vm = (array) apply_filters( 'mlsimport_property_data', $vm, $id );

	// Memoize and hand back the assembled model.
	$cache[ $id ] = $vm;
	return $vm;
}

/**
 * Assemble a one-line street address from RESO parts (UnparsedAddress wins).
 *
 * @param callable $meta Meta reader: ( string $key ) => string.
 * @param callable $str  Row string reader: ( string $col ) => string.
 * @return string
 */
function mlsimport_property_build_address( callable $meta, callable $str ): string {
	// RESO's pre-composed UnparsedAddress wins outright when the feed carries it.
	$unparsed = trim( (string) $meta( 'UnparsedAddress' ) );
	if ( '' !== $unparsed ) {
		return $unparsed;
	}

	// Otherwise stitch street + city + state + zip, dropping any empty part.
	$tail  = array_filter( array( mlsimport_property_street_line( $meta ), $str( 'city' ), $str( 'state' ), $str( 'zip' ) ), 'strlen' );
	return implode( ', ', $tail );
}

/**
 * The street line ("123 Main St #4B") from RESO street parts. Shared by the
 * one-line address builder and the Address section's field grid.
 *
 * @param callable $meta Meta reader: ( string $key ) => string.
 * @return string
 */
function mlsimport_property_street_line( callable $meta ): string {
	// Base line is number + name ("123 Main St").
	$street = trim( $meta( 'StreetNumber' ) . ' ' . $meta( 'StreetName' ) );
	// Append the unit as "#4B" only when the feed supplies one.
	$unit   = trim( (string) $meta( 'UnitNumber' ) );
	if ( '' !== $unit ) {
		$street = trim( $street . ' #' . $unit );
	}
	return $street;
}

/**
 * Gallery attachment IDs for a property (mlsimport_gallery meta), capped by the
 * editable Photos Count field.
 *
 * Photos Count (mlsimport_PhotosCount) arrives from the MLS as the feed's own photo
 * count, but the editor may lower it to publish fewer images. It caps every gallery
 * surface — metabox tiles, single-property gallery/slider, print — because this is
 * the one function they all read. An empty or zero count means "no cap"; the stored
 * attachments are never modified.
 *
 * @param int $id Property post ID.
 * @return int[]
 */
function mlsimport_property_gallery_ids( int $id ): array {
	// The gallery meta stores an ordered array of attachment IDs.
	$ids = get_post_meta( $id, 'mlsimport_gallery', true );
	// Nothing usable when the meta is absent or not an array.
	if ( ! is_array( $ids ) ) {
		return array();
	}
	// Cast to ints, drop zeros/empties, and re-index.
	$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );

	// A positive Photos Count trims the list; blank/0/negative leaves it whole.
	$limit = (int) get_post_meta( $id, 'mlsimport_PhotosCount', true );
	if ( $limit > 0 && count( $ids ) > $limit ) {
		$ids = array_slice( $ids, 0, $limit );
	}

	return $ids;
}

/**
 * Amenity feature term names for a property.
 *
 * @param int $id Property post ID.
 * @return string[]
 */
function mlsimport_property_feature_names( int $id ): array {
	// Amenities live in the mlsimport_feature taxonomy.
	$terms = get_the_terms( $id, 'mlsimport_feature' );
	// No terms (or a WP_Error): no features.
	if ( ! is_array( $terms ) ) {
		return array();
	}

	// One chip per term — the importer writes one term per value (#290), so
	// names arrive individual. De-dupe and re-index so each appears once.
	return array_values( array_unique( array_filter( wp_list_pluck( $terms, 'name' ) ) ) );
}

/**
 * Resolve the listing agent: linked mlsimport_agent post meta preferred, with a
 * fallback to the property's own ListAgent* meta.
 *
 * @param int      $id   Property post ID.
 * @param callable $meta Property meta reader.
 * @return array|null { id, name, email, phone, office, feed_name, feed_office, … } or null when unknown.
 */
function mlsimport_property_agent( int $id, callable $meta ): ?array {
	// The agent post the import task linked (0 when none was picked).
	$agent_id = (int) $meta( 'list_agent_id' );
	// Whether the task opted to attribute the MLS feed's own listing agent instead.
	$use_mls  = (bool) intval( $meta( 'use_mls_agent' ) );

	// The agent picked in the import task wins, unless that task opted to use the
	// MLS feed's own listing agent (mlsimport_use_mls_agent). In feed mode the
	// linked agent post is ignored entirely; otherwise it is the only source and
	// the property's own ListAgent* feed meta is not consulted.
	$use_selected = $agent_id > 0 && ! $use_mls;
	$post_id      = $use_selected ? $agent_id : 0;

	// Reader that pulls each agent field from the linked post (selected mode) or
	// from the property's own feed meta (MLS-agent mode).
	$ameta = static function ( $key ) use ( $use_selected, $agent_id, $meta ) {
		if ( $use_selected ) {
			return (string) get_post_meta( $agent_id, 'mlsimport_' . $key, true );
		}
		return (string) $meta( $key );
	};

	// Core contact fields, resolved through the mode-aware reader.
	$name  = $ameta( 'ListAgentFullName' );
	$email = $ameta( 'ListAgentEmail' );
	$phone = $ameta( 'ListAgentPreferredPhone' );
	$office = $ameta( 'ListOfficeName' );

	// A linked agent post's title is its display name when no name meta is set.
	if ( '' === $name && $post_id ) {
		$name = (string) get_the_title( $post_id );
	}

	// No name, email or phone means there is no agent worth rendering.
	if ( '' === $name && '' === $email && '' === $phone ) {
		return null;
	}

	// A feed-sourced agent (no local agent post) may not have their personal
	// contact channels displayed or used — MLS display rules (#181). The company
	// contacts from the Social & Contact settings take their place.
	$is_feed = ! $use_selected;
	if ( $is_feed ) {
		$email = (string) mlsimport_standalone_option( 'lead_recipient', '' );
		$phone = (string) mlsimport_standalone_option( 'company_phone', '' );
	}

	// A linked agent post's body doubles as the bio when no explicit bio meta exists.
	$bio = $ameta( 'ListAgentBio' );
	if ( '' === $bio && $post_id ) {
		$bio = (string) get_post_field( 'post_content', $post_id );
	}

	// Resolved agent shape consumed by the agent card, booking rail and attribution.
	return array(
		'id'           => $post_id,
		'is_feed'      => $is_feed,
		'name'         => $name,
		'email'        => $email,
		'phone'        => $phone,
		'office_phone' => $ameta( 'ListOfficePhone' ),
		'office'       => $office,
		// The property's own feed values, untouched by the manual-agent override —
		// the MLS attribution must always name the FEED listing agent/office (#169).
		'feed_name'    => (string) $meta( 'ListAgentFullName' ),
		'feed_office'  => (string) $meta( 'ListOfficeName' ),
		// RESO ListAgentPreferredPhone: the phone the listing agent asks to be
		// reached on. Printed after the agent name in the attribution line.
		'feed_phone'   => (string) $meta( 'ListAgentPreferredPhone' ),
		'license'      => $ameta( 'ListAgentStateLicense' ),
		'agent_mls_id' => $ameta( 'ListAgentMlsId' ),
		'office_mls_id' => $ameta( 'ListOfficeMlsId' ),
		'bio'          => trim( wp_strip_all_tags( $bio ) ),
		'photo_id'     => $post_id ? (int) get_post_thumbnail_id( $post_id ) : 0,
	);
}

/**
 * Format an ISO/MySQL timestamp to the site's date format plus hour:minute. '' when empty.
 *
 * Pure-ish (uses WP date settings); DB-free so the view model stays cheap.
 * RESO ModificationTimestamp is UTC, so wp_date() converts it to the site's
 * timezone before printing the hour (e.g. "November 4, 2025 1:45 pm").
 *
 * @param string $ts Timestamp string (e.g. RESO ModificationTimestamp).
 * @return string
 */
function mlsimport_property_format_date( string $ts ): string {
	// Empty in, empty out.
	$ts = trim( $ts );
	if ( '' === $ts ) {
		return '';
	}
	// Parse the timestamp to epoch seconds.
	$time = strtotime( $ts );
	if ( false === $time ) {
		// Already a human display string (e.g. "June 5, 2026 at 02:10pm") — keep it.
		return $ts;
	}
	// Site's date format, then hour and minute only (no seconds, whatever the site time format).
	$format = ( function_exists( 'get_option' ) ? (string) get_option( 'date_format', 'F j, Y' ) : 'F j, Y' ) . ' g:i a';
	// Localized, site-timezone date when available; plain UTC gmdate() as the DB-free fallback.
	return function_exists( 'wp_date' ) ? (string) wp_date( $format, $time ) : gmdate( $format, $time );
}

/**
 * Open a section: the single source of the section container + title markup.
 *
 * Emits a stable anchor id (mlsimport-section-<slug>) so the in-page sub-nav can
 * jump to it, and an optional icon chip beside the title to match the design.
 *
 * @param string $slug  Section slug (e.g. 'price'); used in the BEM class.
 * @param string $title Optional heading.
 * @param string $icon  Optional icon name for mlsimport_property_icon().
 * @return string
 */
function mlsimport_property_section_open( string $slug, string $title = '', string $icon = '' ): string {
	// Anchor id uses the first space-delimited token of the slug (drops modifiers).
	$anchor = sanitize_html_class( 'mlsimport-section-' . strtok( $slug, ' ' ) );
	// Open the section wrapper carrying the anchor and the slug-derived BEM class.
	$html   = '<section id="' . esc_attr( $anchor ) . '" class="mlsimport-property-section mlsimport-property-' . esc_attr( $slug ) . '">';
	// Header (icon chip + heading) is emitted only when a title was passed.
	if ( '' !== $title ) {
		$html .= '<div class="mlsimport-property-section__header">';
		// Optional leading icon chip.
		if ( '' !== $icon ) {
			$html .= '<span class="mlsimport-property-section__icon" aria-hidden="true">' . mlsimport_property_icon( $icon ) . '</span>';
		}
		$html .= '<h2 class="mlsimport-property-section__title">' . esc_html( $title ) . '</h2>';
		$html .= '</div>';
	}
	// Open the body wrapper; the caller appends content, then section_close() shuts both.
	$html .= '<div class="mlsimport-property-section__body">';
	return $html;
}

/**
 * Return an inline stroke SVG for a named icon, or '' for an unknown name.
 *
 * Self-contained (no icon-font dependency) so every section/block renders the
 * same glyph wherever it is placed. currentColor is used so CSS theme tokens
 * drive the colour. The SVG inherits sizing from .mlsimport-property-icon CSS.
 *
 * @param string $name Icon name.
 * @return string
 */
function mlsimport_property_icon( string $name ): string {
	// name => inner SVG path/shape markup for a 24×24 stroke icon.
	$paths = array(
		'info'      => '<circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8h.01"/>',
		'text'      => '<path d="M4 6h16M4 12h16M4 18h10"/>',
		'cube'      => '<path d="M12 2 3 7v10l9 5 9-5V7zM3 7l9 5 9-5M12 12v10"/>',
		'pin'       => '<path d="M12 21s-7-6.3-7-11a7 7 0 0 1 14 0c0 4.7-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>',
		'list'      => '<path d="M8 6h12M8 12h12M8 18h12M3.5 6h.01M3.5 12h.01M3.5 18h.01"/>',
		'grid'      => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
		'video'     => '<rect x="3" y="6" width="13" height="12" rx="2"/><path d="m16 10 5-3v10l-5-3z"/>',
		'calc'      => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M8 7h8M8 11h.01M12 11h.01M16 11h.01M8 15h.01M12 15h.01M16 15v4M8 19h4"/>',
		'user'      => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
		'bed'       => '<path d="M3 7v12M3 13h18a0 0 0 0 1 0 0v6M21 19v-6a4 4 0 0 0-4-4H8M3 9a2 2 0 0 1 2-2"/>',
		'bath'      => '<path d="M4 12h16v3a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4zM6 12V6a2 2 0 0 1 2-2 2 2 0 0 1 2 2"/>',
		'ruler'     => '<path d="m3 17 4 4L21 7l-4-4zM7.5 12.5l2 2M11 9l2 2M14.5 5.5l2 2"/>',
		'car'       => '<path d="M5 17h14M3 17v-4l2-5a2 2 0 0 1 1.9-1.4h10.2A2 2 0 0 1 19 8l2 5v4M3 13h18"/><circle cx="7.5" cy="17" r="1.5"/><circle cx="16.5" cy="17" r="1.5"/>',
		'calendar'  => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/>',
		'building'  => '<rect x="4" y="3" width="16" height="18" rx="1"/><path d="M8 7h.01M12 7h.01M16 7h.01M8 11h.01M12 11h.01M16 11h.01M10 21v-4h4v4"/>',
		'hash'      => '<path d="M5 9h14M5 15h14M10 4 8 20M16 4l-2 16"/>',
		'badge'     => '<path d="M12 2 4 5v6c0 5 3.5 8 8 11 4.5-3 8-6 8-11V5z"/><path d="m9 12 2 2 4-4"/>',
		'check'     => '<path d="m5 12 5 5 9-10"/>',
		'phone'     => '<path d="M5 4h4l2 5-3 2a12 12 0 0 0 5 5l2-3 5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/>',
		'mail'      => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
		'whatsapp'  => '<path d="M21 11.5a8.5 8.5 0 0 1-12.6 7.4L3 20.5l1.7-5.2A8.5 8.5 0 1 1 21 11.5z"/><path d="M8.8 8.4c.2-.5.4-.5.6-.5h.5c.2 0 .4 0 .6.5l.7 1.6c.1.3 0 .5-.1.7l-.4.5c-.1.2-.2.3 0 .6a6 6 0 0 0 2.7 2.3c.3.1.4 0 .6-.1l.5-.6c.2-.2.4-.2.6-.1l1.6.8c.2.1.4.2.4.4v.6c-.1.5-.6 1-1.1 1.2-.4.1-1 .2-2.7-.5a9.3 9.3 0 0 1-4.4-4c-.5-.9-.7-1.7-.7-2.3 0-.4.2-.9.6-1.1z"/>',
		'globe'     => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
		'message'   => '<path d="M21 12a8 8 0 0 1-11.4 7.2L3 21l1.8-6.6A8 8 0 1 1 21 12z"/>',
		'share'     => '<circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="6" r="2.5"/><circle cx="18" cy="18" r="2.5"/><path d="m8.2 10.8 7.6-3.6M8.2 13.2l7.6 3.6"/>',
		'heart'     => '<path d="M12 20s-7-4.6-9.3-9A4.7 4.7 0 0 1 12 6a4.7 4.7 0 0 1 9.3 5c-2.3 4.4-9.3 9-9.3 9z"/>',
		'print'     => '<path d="M7 8V3h10v5M7 18H5a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-2M7 14h10v7H7z"/>',
		'clock'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		'arrow-down' => '<path d="M12 5v14M5 12l7 7 7-7"/>',
		'chevron-left'  => '<path d="M15 18l-6-6 6-6"/>',
		'chevron-right' => '<path d="M9 18l6-6-6-6"/>',
		'tour'      => '<rect x="3" y="6" width="18" height="13" rx="2"/><path d="m9 10 5 3-5 3z"/>',
	);
	// Unknown icon name renders nothing rather than a broken glyph.
	if ( ! isset( $paths[ $name ] ) ) {
		return '';
	}
	// Wrap the chosen shape in the shared SVG chrome (currentColor lets CSS tint it).
	return '<svg class="mlsimport-property-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[ $name ] . '</svg>';
}

/**
 * Close a section opened with mlsimport_property_section_open().
 *
 * @return string
 */
function mlsimport_property_section_close(): string {
	return '</div></section>';
}

/**
 * Format a numeric price as a US currency string (no decimals).
 *
 * Pure + DB-free so it can be unit-tested in isolation.
 *
 * @param float|int|string|null $value Raw price.
 * @return string Formatted price, or '' when there is no usable value.
 */
function mlsimport_format_price( $value ): string {
	// No value → no price string (an empty tile/row is dropped upstream).
	if ( null === $value || '' === $value ) {
		return '';
	}
	/** Filter the formatted price string. @since 6.3 */
	// Thousands-separated dollars with no decimals ("$ 1,250,000").
	return (string) apply_filters( 'mlsimport_format_price', '$ ' . number_format( (float) $value ), $value );
}

/**
 * Format a count/measure: whole numbers plain, fractions to one decimal
 * (so 3 beds reads "3", 2.5 baths reads "2.5"). Pure + DB-free.
 *
 * @param float|int|string|null $value Raw amount.
 * @return string
 */
function mlsimport_format_amount( $value ): string {
	// No value → empty string.
	if ( null === $value || '' === $value ) {
		return '';
	}
	$f = (float) $value;
	// Whole numbers print plain; anything with a fraction prints to one decimal.
	return ( $f === (float) (int) $f ) ? number_format( $f ) : number_format( $f, 1 );
}

/**
 * Price section — the listing's list price.
 *
 * @param int   $id   Property post ID (0 = current loop post).
 * @param array $args Reserved for behavioral options.
 * @return string HTML, or '' when the property has no price.
 */
function mlsimport_property_price( int $id = 0, array $args = array() ): string {
	// Load the view model and bail when there is no price to show.
	$data = mlsimport_property_data( $id );
	if ( empty( $data ) || null === $data['price'] ) {
		return '';
	}

	// Section wrapper (no heading) + the formatted price line.
	$html  = mlsimport_property_section_open( 'price' );
	$html .= '<p class="mlsimport-property-price__amount">' . esc_html( mlsimport_format_price( $data['price'] ) ) . '</p>';
	$html .= mlsimport_property_section_close();
	return $html;
}

/**
 * Title section — the listing title as a heading.
 *
 * @param int   $id   Property post ID.
 * @param array $args Behavioral options.
 * @return string
 */
function mlsimport_property_title( int $id = 0, array $args = array() ): string {
	// No title, no section.
	$data = mlsimport_property_data( $id );
	if ( empty( $data ) || '' === $data['title'] ) {
		return '';
	}
	// Wrapper + the title as an <h1>.
	$html  = mlsimport_property_section_open( 'title' );
	$html .= '<h1 class="mlsimport-property-title__heading">' . esc_html( $data['title'] ) . '</h1>';
	$html .= mlsimport_property_section_close();
	return $html;
}

/**
 * Status section — the listing status as a badge.
 *
 * @param int   $id   Property post ID.
 * @param array $args Behavioral options.
 * @return string
 */
function mlsimport_property_status( int $id = 0, array $args = array() ): string {
	// No status, no section.
	$data = mlsimport_property_data( $id );
	if ( empty( $data ) || '' === $data['status'] ) {
		return '';
	}
	// Wrapper + the status as a badge.
	$html  = mlsimport_property_section_open( 'status' );
	$html .= '<span class="mlsimport-property-status__badge">' . esc_html( $data['status'] ) . '</span>';
	$html .= mlsimport_property_section_close();
	return $html;
}

/**
 * Address section — the one-line street address.
 *
 * @param int   $id   Property post ID.
 * @param array $args Behavioral options.
 * @return string
 */
function mlsimport_property_address( int $id = 0, array $args = array() ): string {
	// No address, no section.
	$data = mlsimport_property_data( $id );
	if ( empty( $data ) || '' === $data['address'] ) {
		return '';
	}
	// Wrapper + the one-line address.
	$html  = mlsimport_property_section_open( 'address' );
	$html .= '<p class="mlsimport-property-address__line">' . esc_html( $data['address'] ) . '</p>';
	$html .= mlsimport_property_section_close();
	return $html;
}

/**
 * The tiles the Overview section knows how to draw: slug => [ icon, label ]. This
 * is the catalog behind the Overview "Arrange Fields" control in the design
 * settings — which tiles show, and in what order, is the saved arrangement of
 * these slugs. Values are resolved per property in mlsimport_property_overview_value().
 *
 * @return array<string,array{0:string,1:string}>
 */
function mlsimport_property_overview_fields(): array {
	return array(
		'updated'    => array( 'calendar', __( 'Updated', 'mlsimport' ) ),
		'sub_type'   => array( 'building', __( 'Sub type', 'mlsimport' ) ),
		'mls_id'     => array( 'hash', __( 'MLS #', 'mlsimport' ) ),
		'bedrooms'   => array( 'bed', __( 'Bedrooms', 'mlsimport' ) ),
		'bathrooms'  => array( 'bath', __( 'Bathrooms', 'mlsimport' ) ),
		'size'       => array( 'ruler', __( 'Size', 'mlsimport' ) ),
		'year_built' => array( 'clock', __( 'Year Built', 'mlsimport' ) ),
		'garage'     => array( 'car', __( 'Garage', 'mlsimport' ) ),
	);
}

/**
 * One overview tile's display value for a property, or '' when it has none (an
 * empty tile is skipped, so the grid never shows a blank cell).
 *
 * @param string $slug Overview field slug.
 * @param array  $data Property view model.
 * @return string
 */
function mlsimport_property_overview_value( string $slug, array $data ): string {
	// Map each overview slug to its display value from the view model.
	switch ( $slug ) {
		case 'updated':
			// Last-modified date, already formatted.
			return (string) $data['updated'];
		case 'sub_type':
			// RESO PropertySubType.
			return (string) $data['property_sub_type'];
		case 'mls_id':
			// Listing's MLS number.
			return (string) $data['mls_id'];
		case 'bedrooms':
			// Bed count (null → no tile).
			return null !== $data['bedrooms'] ? mlsimport_format_amount( $data['bedrooms'] ) : '';
		case 'bathrooms':
			// Bath count (fractions allowed, e.g. 2.5).
			return null !== $data['bathrooms'] ? mlsimport_format_amount( $data['bathrooms'] ) : '';
		case 'size':
			// Living area with a ft² suffix.
			return null !== $data['living_area'] ? mlsimport_format_amount( $data['living_area'] ) . ' ' . __( 'ft²', 'mlsimport' ) : '';
		case 'year_built':
			// A year is never thousands-separated, so it bypasses mlsimport_format_amount().
			return null !== $data['year_built'] ? (string) $data['year_built'] : '';
		case 'garage':
			// Garage spaces (RESO GarageSpaces); 0 spaces is "no garage" — no tile.
			return ! empty( $data['garage'] ) ? (string) $data['garage'] : '';
	}
	// Unknown slug carries no value.
	return '';
}

/**
 * Overview section — the headline stat grid (updated · sub type · MLS # · beds ·
 * baths · size). Which tiles appear and their order come from the Overview
 * "Arrange Fields" design setting; only tiles with a value render.
 *
 * @param int   $id   Property post ID.
 * @param array $args Behavioral options.
 * @return string
 */
function mlsimport_property_overview( int $id = 0, array $args = array() ): string {
	$data = mlsimport_property_data( $id );
	if ( empty( $data ) ) {
		return '';
	}

	// Tile catalog (slug => [icon, label]); the saved arrangement drives order.
	$fields = mlsimport_property_overview_fields();
	$cells  = '';
	// Walk the user's chosen tile order.
	foreach ( mlsimport_standalone_active_overview_fields() as $slug ) {
		// Skip a saved slug the catalog no longer knows.
		if ( ! isset( $fields[ $slug ] ) ) {
			continue;
		}
		// Resolve this tile's value; an empty value means no tile.
		$value = mlsimport_property_overview_value( $slug, $data );
		if ( '' === $value ) {
			continue;
		}
		// Icon + label + value tile.
		$cells .= '<div class="mlsimport-property-overview__tile">'
			. '<span class="mlsimport-property-overview__tile-icon" aria-hidden="true">' . mlsimport_property_icon( $fields[ $slug ][0] ) . '</span>'
			. '<span class="mlsimport-property-overview__tile-label">' . esc_html( $fields[ $slug ][1] ) . '</span>'
			. '<span class="mlsimport-property-overview__tile-value">' . esc_html( $value ) . '</span>'
			. '</div>';
	}
	// No populated tiles → skip the whole section.
	if ( '' === $cells ) {
		return '';
	}

	// Titled "Overview" section wrapping the tile grid.
	$html  = mlsimport_property_section_open( 'overview', __( 'Overview', 'mlsimport' ), 'info' );
	$html .= '<div class="mlsimport-property-overview__grid">' . $cells . '</div>';
	$html .= mlsimport_property_section_close();
	return $html;
}

/**
 * The facts grid as a markup string — shared by details, tabs and accordion.
 *
 * @param array $facts [label, value] pairs from mlsimport_property_facts().
 * @return string
 */
function mlsimport_property_facts_grid_html( array $facts, string $modifier = '', int $id = 0 ): string {
	// Nothing to render when there are no fact rows.
	if ( empty( $facts ) ) {
		return '';
	}
	// Grid <ul>, plus the optional column-count/address modifier class.
	$html = '<ul class="mlsimport-property-details__grid' . ( '' !== $modifier ? ' ' . esc_attr( $modifier ) : '' ) . '">';
	// One label/value <li> per fact; a value naming one of this listing's terms links to it.
	foreach ( $facts as $fact ) {
		$html .= '<li class="mlsimport-property-details__item">'
			. '<span class="mlsimport-property-details__label">' . esc_html( $fact[0] ) . '</span>'
			. '<span class="mlsimport-property-details__value">' . mlsimport_property_link_term( $id, (string) $fact[1] ) . '</span>'
			. '</li>';
	}
	$html .= '</ul>';
	return $html;
}

/**
 * The features chip list as a markup string — shared by features, tabs, accordion.
 *
 * @param string[] $names Feature term names.
 * @return string
 */
function mlsimport_property_features_list_html( array $names, int $id = 0 ): string {
	// No amenity names → no chip list.
	if ( empty( $names ) ) {
		return '';
	}
	// A shared check glyph precedes every chip.
	$check = '<span class="mlsimport-property-features__check" aria-hidden="true">' . mlsimport_property_icon( 'check' ) . '</span>';
	// Chip list carries the page-wide column-count class.
	$html  = '<ul class="mlsimport-property-features__list ' . esc_attr( mlsimport_property_columns_class() ) . '">';
	// One chip per amenity, linked to its feature archive.
	foreach ( $names as $name ) {
		$html .= '<li class="mlsimport-property-features__item">' . $check . '<span>' . mlsimport_property_link_term( $id, (string) $name ) . '</span></li>';
	}
	$html .= '</ul>';
	return $html;
}

/**
 * The panes behind the Tabs and Accordion containers: each configured section,
 * rendered through the one dispatcher every builder already uses.
 *
 * A container accepts ANY registered section — so Map can sit as a tab next to
 * Interior. A section that renders nothing is dropped rather than offered as a
 * dead tab, the same "no data, no section" rule the sections themselves obey.
 *
 * Without a 'sections' list (the property template, the shortcode, the block and
 * the Elementor widget all dispatch a section with only the post id) the
 * container holds the nine field sections followed by Features — the "Details"
 * a tabbed or accordion layout is expected to group (#311). Whatever the list,
 * the two containers themselves are never panes, so a container cannot nest
 * itself.
 *
 * The pane carries the heading, so the section inside it is asked to omit its own.
 *
 * @param int   $id   Property post ID.
 * @param array $args Behavioral options; 'sections' is an ordered list of slugs.
 * @return array<string,array{0:string,1:string}> slug => [ title, html ].
 */
function mlsimport_property_container_panes( int $id, array $args ): array {
	// Step 1: the ordered slug list the container was told to hold, or the default
	// "Details" set when the caller passed none.
	$slugs = ! empty( $args['sections'] )
		? (array) $args['sections']
		: array_merge( array_keys( mlsimport_property_field_section_titles() ), array( 'features' ) );
	// Step 2: a container never holds a container (no recursion).
	$slugs = array_diff( $slugs, array( 'tabs', 'accordion' ) );

	// The section registry maps each slug to its render fn + label.
	$registry = mlsimport_get_property_sections();
	$panes    = array();

	foreach ( $slugs as $slug ) {
		$slug = (string) $slug;
		// Skip a slug that isn't a registered section.
		if ( ! isset( $registry[ $slug ] ) ) {
			continue;
		}

		// Render through the shared dispatcher, asking the section to omit its heading.
		$html = mlsimport_render_property_section( $slug, $id, array( 'hide_title' => true ) );
		// A section that produced nothing is dropped, never offered as a dead tab.
		if ( '' === trim( $html ) ) {
			continue;
		}

		// Pane = [ registry label, rendered html ].
		$panes[ $slug ] = array( (string) $registry[ $slug ]['label'], $html );
	}

	return $panes;
}

/**
 * The nine field sections — Interior, Exterior, Structure, Utilities, Financial,
 * Schools, Location, Listing Info, Other Details.
 *
 * One render fn backs all nine; the registry bakes the slug into each. The rows
 * come from mlsimport_property_section_fields(), which owns the one rule that
 * governs every section: a field shows when it is ticked for import, not marked
 * admin-only, and has a value.
 *
 * A section with no populated field renders '' — never a bare heading.
 *
 * @param int   $id   Property post ID (0 = current loop post).
 * @param array $args Behavioral options; 'section' is the section slug.
 * @return string
 */
function mlsimport_property_field_section( int $id = 0, array $args = array() ): string {
	// Resolve the post and which of the nine sections this call renders.
	$id      = $id ? $id : (int) get_the_ID();
	$section = isset( $args['section'] ) ? (string) $args['section'] : '';
	if ( ! $id || '' === $section ) {
		return '';
	}

	// Build the facts grid from the section's importable, populated fields.
	$grid = mlsimport_property_facts_grid_html(
		mlsimport_property_section_fields( $id, $section ),
		mlsimport_property_columns_class(),
		$id
	);
	// No populated field → render '' rather than a bare heading.
	if ( '' === $grid ) {
		return '';
	}

	// Inside a tab or an accordion panel the container already shows the heading.
	$titles = mlsimport_property_field_section_titles();
	$title  = ( isset( $titles[ $section ] ) && empty( $args['hide_title'] ) ) ? $titles[ $section ] : '';

	// Section wrapper + the facts grid.
	$html  = mlsimport_property_section_open( $section, $title, 'list' );
	$html .= $grid;
	$html .= mlsimport_property_section_close();
	return $html;
}

/**
 * The sub-nav jump links for the nine field sections: label => anchor id.
 *
 * A section earns a link only when it has a populated field — the same "no data,
 * no section" rule the sections themselves obey — so the nav never points at an
 * anchor that isn't on the page.
 *
 * @param int $id Property post ID.
 * @return array<string,string>
 */
function mlsimport_property_subnav_field_items( int $id ): array {
	$items = array();
	// Offer a jump link only for a section that has at least one populated field.
	foreach ( mlsimport_property_field_section_titles() as $slug => $title ) {
		if ( ! empty( mlsimport_property_section_fields( $id, $slug ) ) ) {
			$items[ $title ] = 'mlsimport-section-' . $slug;
		}
	}
	return $items;
}

/**
 * How many columns every field section's details grid runs — the Property Page
 * "Details Columns" setting. 2 or 3; anything else is 3.
 *
 * @return int
 */
function mlsimport_property_details_columns(): int {
	// Read the "Details Columns" setting; only 2 is honored, everything else is 3.
	$cols = (int) mlsimport_standalone_option( 'details_columns', 3 );
	return 2 === $cols ? 2 : 3;
}

/**
 * The column-count class every grid inside a section carries — the details grids,
 * the Address grid and the amenity list alike. One class rather than a per-block
 * modifier, because "two columns" is a page-wide choice: a page set to two that
 * printed its amenities three-up would just look broken.
 *
 * @return string
 */
function mlsimport_property_columns_class(): string {
	// e.g. "mlsimport-cols-3" — one page-wide column class for every grid.
	return 'mlsimport-cols-' . mlsimport_property_details_columns();
}

/**
 * The nine field sections, slug => public heading.
 *
 * @return array<string,string>
 */
function mlsimport_property_field_section_titles(): array {
	return array(
		'interior'     => __( 'Interior', 'mlsimport' ),
		'exterior'     => __( 'Exterior', 'mlsimport' ),
		'structure'    => __( 'Structure', 'mlsimport' ),
		'utilities'    => __( 'Utilities', 'mlsimport' ),
		'financial'    => __( 'Financial', 'mlsimport' ),
		'schools'      => __( 'Schools', 'mlsimport' ),
		'location'     => __( 'Location', 'mlsimport' ),
		'listing_info' => __( 'Listing Info', 'mlsimport' ),
		'other'        => __( 'Other Details', 'mlsimport' ),
	);
}

/**
 * Features section — amenity feature terms as chips.
 *
 * @param int   $id   Property post ID.
 * @param array $args Behavioral options.
 * @return string
 */
function mlsimport_property_features( int $id = 0, array $args = array() ): string {
	// Build the chip list from the view model's feature names.
	$data = mlsimport_property_data( $id );
	$list = $data ? mlsimport_property_features_list_html( $data['features'], (int) ( $data['id'] ?? 0 ) ) : '';
	// No chips → no section.
	if ( '' === $list ) {
		return '';
	}

	// Titled section wrapping the amenity chips; inside a Tabs/Accordion pane the
	// pane carries the heading, so 'hide_title' drops this one.
	$title = empty( $args['hide_title'] ) ? __( 'Features & Amenities', 'mlsimport' ) : '';
	$html  = mlsimport_property_section_open( 'features', $title, 'grid' );
	$html .= $list;
	$html .= mlsimport_property_section_close();
	return $html;
}

/**
 * Details as Tabs — the container's panes (field sections + Features by default,
 * or the 'sections' list) in a tabbed panel (mlsimport-property-tabs.js).
 *
 * @param int   $id   Property post ID.
 * @param array $args Behavioral options.
 * @return string
 */
function mlsimport_property_tabs( int $id = 0, array $args = array() ): string {
	// Resolve the configured section panes; nothing to tab means no section.
	$panes = mlsimport_property_container_panes( $id, $args );
	if ( empty( $panes ) ) {
		return '';
	}

	// Build the tab buttons and their panels; the first pane is the open one.
	$nav    = '';
	$panels = '';
	$first  = true;
	foreach ( $panes as $key => $pane ) {
		// Tab button (aria-selected on the first).
		$nav    .= '<button type="button" class="mlsimport-property-tabs__tab" role="tab" data-tab="' . esc_attr( $key ) . '" aria-selected="' . ( $first ? 'true' : 'false' ) . '">' . esc_html( $pane[0] ) . '</button>';
		// Matching panel (hidden on all but the first).
		$panels .= '<div class="mlsimport-property-tabs__panel" role="tabpanel" data-panel="' . esc_attr( $key ) . '"' . ( $first ? '' : ' hidden' ) . '>' . $pane[1] . '</div>';
		$first   = false;
	}

	// Titled "Details" section wrapping the tablist + panels.
	$html  = mlsimport_property_section_open( 'tabs', __( 'Details', 'mlsimport' ), 'list' );
	$html .= '<div class="mlsimport-property-tabs" data-mlsimport-tabs>';
	$html .= '<div class="mlsimport-property-tabs__nav" role="tablist">' . $nav . '</div>';
	$html .= $panels;
	$html .= '</div>';
	$html .= mlsimport_property_section_close();
	return $html;
}

/**
 * Details as Accordion — the container's panes (field sections + Features by
 * default, or the 'sections' list) in native <details> panels (no JS).
 *
 * @param int   $id   Property post ID.
 * @param array $args Behavioral options.
 * @return string
 */
function mlsimport_property_accordion( int $id = 0, array $args = array() ): string {
	// Resolve the configured section panes; none means no section.
	$panes = mlsimport_property_container_panes( $id, $args );
	if ( empty( $panes ) ) {
		return '';
	}

	// Native <details> per pane; only the first starts open.
	$items = '';
	$open  = ' open';
	foreach ( $panes as $pane ) {
		$items .= '<details class="mlsimport-property-accordion__item"' . $open . '>'
			. '<summary class="mlsimport-property-accordion__summary">' . esc_html( $pane[0] ) . '</summary>'
			. '<div class="mlsimport-property-accordion__body">' . $pane[1] . '</div>'
			. '</details>';
		// Subsequent panels render collapsed.
		$open   = '';
	}

	// Titled "Details" section wrapping the accordion.
	$html  = mlsimport_property_section_open( 'accordion', __( 'Details', 'mlsimport' ), 'list' );
	$html .= '<div class="mlsimport-property-accordion">' . $items . '</div>';
	$html .= mlsimport_property_section_close();
	return $html;
}

/**
 * Description section — the listing's public remarks (post body) with a heading.
 *
 * @param int   $id   Property post ID.
 * @param array $args Behavioral options.
 * @return string
 */
function mlsimport_property_description( int $id = 0, array $args = array() ): string {
	// No body content, no section.
	$data = mlsimport_property_data( $id );
	if ( empty( $data ) || '' === trim( $data['content'] ) ) {
		return '';
	}
	// Titled "Description" section; body is paragraph-wrapped and sanitized.
	$html  = mlsimport_property_section_open( 'description', __( 'Description', 'mlsimport' ), 'text' );
	$html .= '<div class="mlsimport-property-description__body">' . wp_kses_post( wpautop( $data['content'] ) ) . '</div>';
	$html .= mlsimport_property_section_close();
	return $html;
}

/**
 * Content section — the raw listing body, no heading.
 *
 * @param int   $id   Property post ID.
 * @param array $args Behavioral options.
 * @return string
 */
function mlsimport_property_content( int $id = 0, array $args = array() ): string {
	// No body content, no section.
	$data = mlsimport_property_data( $id );
	if ( empty( $data ) || '' === trim( $data['content'] ) ) {
		return '';
	}
	// Headingless wrapper + the paragraph-wrapped, sanitized body.
	$html  = mlsimport_property_section_open( 'content' );
	$html .= '<div class="mlsimport-property-content__body">' . wp_kses_post( wpautop( $data['content'] ) ) . '</div>';
	$html .= mlsimport_property_section_close();
	return $html;
}

/**
 * Excerpt section — a short summary (post excerpt, or trimmed content).
 *
 * @param int   $id   Property post ID.
 * @param array $args Behavioral options.
 * @return string
 */
function mlsimport_property_excerpt( int $id = 0, array $args = array() ): string {
	// Need a resolved property to have anything to summarize.
	$data = mlsimport_property_data( $id );
	if ( empty( $data ) ) {
		return '';
	}
	// Prefer the explicit excerpt; else trim the body to 40 words.
	$text = '' !== trim( $data['excerpt'] ) ? $data['excerpt'] : wp_trim_words( wp_strip_all_tags( $data['content'] ), 40 );
	// Nothing to summarize → no section.
	if ( '' === trim( $text ) ) {
		return '';
	}
	// Headingless wrapper + the summary paragraph.
	$html  = mlsimport_property_section_open( 'excerpt' );
	$html .= '<p class="mlsimport-property-excerpt__text">' . esc_html( $text ) . '</p>';
	$html .= mlsimport_property_section_close();
	return $html;
}

/**
 * Additional price info — original / previous / close price + HOA.
 *
 * @param int   $id   Property post ID.
 * @param array $args Behavioral options.
 * @return string
 */
function mlsimport_property_price_info( int $id = 0, array $args = array() ): string {
	$data = mlsimport_property_data( $id );
	if ( empty( $data ) ) {
		return '';
	}

	// Collect label => price rows, each added only when its value is present.
	$rows = array();
	if ( null !== $data['original_price'] ) {
		$rows[ __( 'Original price', 'mlsimport' ) ] = mlsimport_format_price( $data['original_price'] );
	}
	if ( null !== $data['previous_price'] ) {
		$rows[ __( 'Previous price', 'mlsimport' ) ] = mlsimport_format_price( $data['previous_price'] );
	}
	if ( null !== $data['close_price'] ) {
		$rows[ __( 'Sold price', 'mlsimport' ) ] = mlsimport_format_price( $data['close_price'] );
	}
	if ( null !== $data['hoa_fee'] ) {
		// HOA fee, optionally suffixed with its billing frequency ("... / Monthly").
		$hoa = mlsimport_format_price( $data['hoa_fee'] );
		if ( '' !== $data['hoa_frequency'] ) {
			$hoa .= ' / ' . $data['hoa_frequency'];
		}
		$rows[ __( 'HOA fee', 'mlsimport' ) ] = $hoa;
	}
	// No price rows → no section.
	if ( empty( $rows ) ) {
		return '';
	}

	// Titled "Price details" section wrapping a plain facts grid.
	$html = mlsimport_property_section_open( 'price-info', __( 'Price details', 'mlsimport' ), 'list' );
	$html .= '<ul class="mlsimport-property-details__grid">';
	// One label/value <li> per collected row.
	foreach ( $rows as $label => $value ) {
		$html .= '<li class="mlsimport-property-details__item">'
			. '<span class="mlsimport-property-details__label">' . esc_html( $label ) . '</span>'
			. '<span class="mlsimport-property-details__value">' . esc_html( $value ) . '</span>'
			. '</li>';
	}
	$html .= '</ul>';
	$html .= mlsimport_property_section_close();
	return $html;
}

/**
 * The breadcrumb trail: Home > County > City > Area > this listing — broadest
 * geography first, narrowing down to the listing. A rung with no value is
 * simply skipped; there are no stand-ins.
 *
 * Each place links to its taxonomy archive, which is where a visitor climbing the
 * trail expects to land — "Sarasota" should list Sarasota, not search for it. A
 * listing imported before the location taxonomies were assigned still names its
 * places from the flat columns; they just have nowhere to link.
 *
 * @param int   $id   Property post ID.
 * @param array $data Property view model (mlsimport_property_data()).
 * @return array<int,array{0:string,1:string}> [ label, url ] pairs; url '' = not a link.
 */
function mlsimport_property_breadcrumb_items( int $id, array $data ): array {
	// A place is its term (linkable) or, failing that, the flat column text.
	$place = static function ( $taxonomy, $fallback ) use ( $id ) {
		$term = mlsimport_property_first_term( $id, $taxonomy );
		$name = $term ? (string) $term->name : (string) $fallback;
		if ( '' === $name ) {
			return null;
		}
		$link = $term ? get_term_link( $term ) : '';
		return array( $name, is_string( $link ) ? $link : '' );
	};

	// The trail always starts at Home.
	$items = array( array( __( 'Home', 'mlsimport' ), (string) home_url( '/' ) ) );

	// Geography rungs, broadest first: county → city → area/subdivision.
	$rungs = array(
		$place( 'mlsimport_county', $data['county'] ?? '' ),
		$place( 'mlsimport_city', $data['city'] ?? '' ),
		$place( 'mlsimport_area', $data['subdivision'] ?? '' ),
	);
	// Append only the rungs that resolved to a value.
	foreach ( $rungs as $rung ) {
		if ( $rung ) {
			$items[] = $rung;
		}
	}

	// The listing itself is the final, non-linking rung.
	if ( '' !== (string) ( $data['title'] ?? '' ) ) {
		$items[] = array( (string) $data['title'], '' );
	}

	/** Filter the breadcrumb trail. @since 6.4 */
	return (array) apply_filters( 'mlsimport_property_breadcrumb_items', $items, $id, $data );
}

/**
 * Every term this listing carries, indexed by lower-cased display text, mapped
 * to its public term archive — the lookup behind mlsimport_property_link_term().
 *
 * A term whose link can't be built is left out, so it simply renders as
 * plain text.
 *
 * Built once per post per request: the property page asks for these links from
 * the chips, the facts grid and the amenity list.
 *
 * @param int $id Property post ID.
 * @return array<string,string> lower-cased term text => term archive URL.
 */
function mlsimport_property_term_link_map( int $id ): array {
	static $cache = array();
	if ( isset( $cache[ $id ] ) ) {
		return $cache[ $id ];
	}

	$map = array();
	// Walk every taxonomy attached to the property CPT.
	foreach ( get_object_taxonomies( 'mlsimport_property' ) as $taxonomy ) {
		$terms = get_the_terms( $id, $taxonomy );
		// get_the_terms() returns false/WP_Error when the post has no terms — skip.
		if ( ! is_array( $terms ) ) {
			continue;
		}
		foreach ( $terms as $term ) {
			$url = get_term_link( $term );
			// An unresolvable link means this term stays plain text.
			if ( is_wp_error( $url ) ) {
				continue;
			}
			// Index the term by its display text.
			$key = strtolower( trim( $term->name ) );
			if ( '' !== $key ) {
				$map[ $key ] = $url;
			}
		}
	}

	$cache[ $id ] = $map;
	return $map;
}

/**
 * A displayed value as a link to its term archive, when the listing actually
 * carries a term by that name — otherwise the value as plain escaped text.
 *
 * Matching on the listing's own terms is what keeps this honest: "Ashland" links
 * because this listing is filed under Ashland, while "2025" or a street number
 * matches nothing and is left alone. Nothing is guessed from the text itself.
 *
 * @param int    $id   Property post ID.
 * @param string $text Display value.
 * @return string Escaped text, linked when a term matches.
 */
function mlsimport_property_link_term( int $id, string $text ): string {
	$map = $id ? mlsimport_property_term_link_map( $id ) : array();
	$key = strtolower( trim( $text ) );
	// No matching term → the value renders exactly as before.
	if ( ! isset( $map[ $key ] ) ) {
		return esc_html( $text );
	}
	return '<a class="mlsimport-property-term-link" href="' . esc_url( $map[ $key ] ) . '">' . esc_html( $text ) . '</a>';
}

/**
 * The first term a listing carries in a taxonomy, or null.
 *
 * @param int    $id       Property post ID.
 * @param string $taxonomy Taxonomy slug.
 * @return object|null
 */
function mlsimport_property_first_term( int $id, string $taxonomy ) {
	// No usable terms (missing, empty, or a WP_Error) → null.
	$terms = get_the_terms( $id, $taxonomy );
	if ( ! is_array( $terms ) || empty( $terms ) || is_wp_error( $terms ) ) {
		return null;
	}
	// The first term is the one the trail uses.
	return reset( $terms );
}

/**
 * The breadcrumb trail as markup.
 *
 * @param array $items [ label, url ] pairs from mlsimport_property_breadcrumb_items().
 * @return string
 */
function mlsimport_property_breadcrumbs_html( array $items ): string {
	// No rungs → no breadcrumb nav.
	if ( empty( $items ) ) {
		return '';
	}

	// Open the breadcrumb <nav>/<ol>.
	$html = '<nav class="mlsimport-property-breadcrumbs" aria-label="' . esc_attr__( 'Breadcrumb', 'mlsimport' ) . '"><ol class="mlsimport-property-breadcrumbs__list">';
	foreach ( $items as $item ) {
		// A rung with a URL is a link; the URL-less final rung is the current page.
		$label = '' !== (string) $item[1]
			? '<a href="' . esc_url( $item[1] ) . '">' . esc_html( $item[0] ) . '</a>'
			: '<span aria-current="page">' . esc_html( $item[0] ) . '</span>';

		$html .= '<li class="mlsimport-property-breadcrumbs__item">' . $label . '</li>';
	}
	$html .= '</ol></nav>';
	return $html;
}

/**
 * The sticky mobile agent bar as markup — the listing agent kept one tap away at
 * the bottom of a phone screen (WPResidence's mobile_agent_area, rebuilt here).
 *
 * Call, email and WhatsApp are the three things a visitor on a phone actually does
 * from a listing, so each is a single tap. An action with nothing behind it is not
 * drawn, and an agent with no name and no way to reach them draws no bar at all —
 * an empty bar would just eat the bottom of the screen. Hidden on desktop by CSS,
 * where the agent card and its contact rail are already in view.
 *
 * @param array $data Property view model (mlsimport_property_data()).
 * @return string
 */
function mlsimport_property_mobile_agent_bar_html( array $data ): string {
	// Resolve the agent shape; no agent means no bar.
	$a = isset( $data['agent'] ) && is_array( $data['agent'] ) ? $data['agent'] : array();
	if ( empty( $a ) ) {
		return '';
	}

	// Pull the three contact fields; $tel is the dial-able form of the phone.
	$name  = (string) ( $a['name'] ?? '' );
	$email = (string) ( $a['email'] ?? '' );
	$phone = (string) ( $a['phone'] ?? '' );
	$tel   = preg_replace( '/[^0-9+]/', '', $phone );
	// No name and no way to reach them → draw no bar at all.
	if ( '' === $name && '' === $email && '' === $tel ) {
		return '';
	}

	// Build only the actions that have something behind them.
	$actions = '';
	if ( '' !== $email ) {
		$actions .= mlsimport_property_mobile_agent_action( 'email', 'mailto:' . $email, 'mail', __( 'Email the agent', 'mlsimport' ) );
	}
	if ( '' !== $tel ) {
		// A phone enables both a tel: call and a WhatsApp chat.
		$actions .= mlsimport_property_mobile_agent_action( 'phone', 'tel:' . $tel, 'phone', __( 'Call the agent', 'mlsimport' ) );
		$actions .= mlsimport_property_mobile_agent_action(
			'whatsapp',
			mlsimport_property_whatsapp_link( $tel, (string) ( $data['title'] ?? '' ), (string) ( $data['permalink'] ?? '' ) ),
			'whatsapp',
			__( 'WhatsApp the agent', 'mlsimport' )
		);
	}

	// Avatar: the agent photo when present, else initials in a chip.
	$avatar = ! empty( $a['photo_id'] )
		? wp_get_attachment_image( (int) $a['photo_id'], 'thumbnail', false, array( 'class' => 'mlsimport-property-mobile-agent__img' ) )
		: '<span class="mlsimport-property-mobile-agent__initials">' . esc_html( mlsimport_property_initials( $name ) ) . '</span>';

	// Name links to the agent profile when there is a linked post.
	$profile   = ! empty( $a['id'] ) ? (string) get_permalink( (int) $a['id'] ) : '';
	$name_html = '' !== $profile
		? '<a class="mlsimport-property-mobile-agent__name" href="' . esc_url( $profile ) . '">' . esc_html( $name ) . '</a>'
		: '<span class="mlsimport-property-mobile-agent__name">' . esc_html( $name ) . '</span>';

	// Compose the bar: identity (avatar + name) on the left, actions on the right.
	$html  = '<div class="mlsimport-property-mobile-agent">';
	$html .= '<div class="mlsimport-property-mobile-agent__identity">'
		. '<span class="mlsimport-property-mobile-agent__photo">' . $avatar . '</span>'
		. $name_html
		. '</div>';
	$html .= '<div class="mlsimport-property-mobile-agent__actions">' . $actions . '</div>';
	$html .= '</div>';
	return $html;
}

/**
 * One round action button in the mobile agent bar.
 *
 * @param string $type  Action slug (email|phone|whatsapp).
 * @param string $href  Link target.
 * @param string $icon  Icon name.
 * @param string $label Accessible label.
 * @return string
 */
function mlsimport_property_mobile_agent_action( string $type, string $href, string $icon, string $label ): string {
	return '<a class="mlsimport-property-mobile-agent__action mlsimport-property-mobile-agent__action--' . esc_attr( $type ) . '"'
		. ' href="' . esc_attr( $href ) . '" aria-label="' . esc_attr( $label ) . '">'
		. mlsimport_property_icon( $icon )
		. '</a>';
}

/**
 * A wa.me link that opens a chat already talking about this listing — the agent
 * gets "Hello, I'm interested in [title] <url>" instead of a bare "hi".
 *
 * @param string $tel       Phone, digits (and possibly a leading +).
 * @param string $title     Listing title.
 * @param string $permalink Listing URL.
 * @return string
 */
function mlsimport_property_whatsapp_link( string $tel, string $title, string $permalink ): string {
	// wa.me wants the number bare: digits only, no +, no spaces.
	$number = preg_replace( '/[^0-9]/', '', $tel );
	// No digits → no link.
	if ( '' === $number ) {
		return '';
	}

	// Pre-fill the chat with the listing title + URL.
	$message = sprintf(
		/* translators: 1: listing title, 2: listing URL. */
		__( 'Hello, I\'m interested in [%1$s] %2$s', 'mlsimport' ),
		$title,
		$permalink
	);

	/** Filter the WhatsApp message a visitor sends from a listing. @since 6.4 */
	$message = (string) apply_filters( 'mlsimport_property_whatsapp_message', $message, $title, $permalink );

	// wa.me deep link with the pre-filled, URL-encoded message.
	return 'https://wa.me/' . $number . '?text=' . rawurlencode( $message );
}

/**
 * Mobile agent bar section — fixed to the bottom of the viewport on phones.
 *
 * @param int   $id   Property post ID.
 * @param array $args Behavioral options.
 * @return string
 */
function mlsimport_property_mobile_agent_bar( int $id = 0, array $args = array() ): string {
	// Resolve the post and its view model; nothing to show without one.
	$id   = $id ? $id : (int) get_the_ID();
	$data = mlsimport_property_data( $id );
	if ( empty( $data ) ) {
		return '';
	}

	// Build the bar markup; empty when there is no reachable agent.
	$bar = mlsimport_property_mobile_agent_bar_html( $data );
	if ( '' === $bar ) {
		return '';
	}

	// Deliberately NOT a mlsimport_property_section_open() panel: the bar is fixed
	// to the viewport, so the section chrome (card, padding, heading) would only
	// wrap a thing that has left the document flow. The spacer is the in-flow
	// stand-in that keeps the bar from covering whatever ends the page.
	return '<div class="mlsimport-property-mobile-agent-spacer" aria-hidden="true"></div>'
		. '<div class="mlsimport-property-mobile-agent-bar" id="mlsimport-section-mobile_agent_bar">' . $bar . '</div>';
}

/**
 * Breadcrumbs section — the trail pinned above the gallery, under the site header.
 *
 * @param int   $id   Property post ID.
 * @param array $args Behavioral options.
 * @return string
 */
function mlsimport_property_breadcrumbs( int $id = 0, array $args = array() ): string {
	// Resolve the post and its view model.
	$id   = $id ? $id : (int) get_the_ID();
	$data = mlsimport_property_data( $id );
	if ( empty( $data ) ) {
		return '';
	}

	// Wrapper + the built breadcrumb trail markup.
	$html  = mlsimport_property_section_open( 'breadcrumbs' );
	$html .= mlsimport_property_breadcrumbs_html( mlsimport_property_breadcrumb_items( $id, $data ) );
	$html .= mlsimport_property_section_close();
	return $html;
}

/**
 * Render a property photo as a CSS background-image div (never an <img>) — the
 * standalone convention for listing photos. Remote MLS attachments often report a
 * 1x1 intrinsic size, so a background fills its container reliably where an <img>
 * would collapse. Callers supply the box sizing via the element class.
 *
 * @param int|string $aid   Attachment ID, or a bare image URL (live mode's
 *                          galleries carry MLS CDN URLs instead of attachments).
 * @param string     $size  Registered image size used for the source URL.
 * @param string     $class Element class (defines the box; .mlsimport-property-photo fills it).
 * @param string     $label Accessible label for the role="img" element ('' = none).
 * @return string '' when the attachment resolves to no URL.
 */
function mlsimport_property_photo_bg( $aid, string $size, string $class, string $label = '' ): string {
	// A non-numeric string is already a bare URL (live mode); else resolve the attachment.
	$url = is_string( $aid ) && ! is_numeric( $aid ) ? $aid : wp_get_attachment_image_url( (int) $aid, $size );
	// No URL → render nothing.
	if ( ! $url ) {
		return '';
	}
	// Add an aria-label only when one was supplied.
	$aria = '' !== $label ? ' aria-label="' . esc_attr( $label ) . '"' : '';
	// A role="img" div carrying the photo as a background-image.
	return '<div class="' . esc_attr( $class ) . '" role="img"' . $aria . ' style="background-image:url(\'' . esc_url( $url ) . '\')"></div>';
}

/**
 * Featured image section — the post thumbnail.
 *
 * @param int   $id   Property post ID.
 * @param array $args Behavioral options.
 * @return string
 */
function mlsimport_property_featured_image( int $id = 0, array $args = array() ): string {
	// Need either a thumbnail attachment or a live image URL.
	$data = mlsimport_property_data( $id );
	if ( empty( $data ) || ( ! $data['thumbnail_id'] && '' === (string) $data['image_url'] ) ) {
		return '';
	}
	// Prefer the attachment id; fall back to the raw image URL.
	$photo = mlsimport_property_photo_bg( $data['thumbnail_id'] ? (int) $data['thumbnail_id'] : (string) $data['image_url'], 'large', 'mlsimport-property-photo', $data['address'] );
	// The image resolved to no URL → no section.
	if ( '' === $photo ) {
		return '';
	}
	// Headingless wrapper + the background-image photo.
	$html  = mlsimport_property_section_open( 'featured' );
	$html .= '<div class="mlsimport-property-featured__image">' . $photo . '</div>';
	$html .= mlsimport_property_section_close();
	return $html;
}

/**
 * Property Gallery section — the single, user-facing media section. It renders
 * whichever gallery/slider variant the user picked in the "Media Section Type"
 * plugin setting (media_section_type), delegating to mlsimport_property_gallery().
 *
 * @param int   $id   Property post ID.
 * @param array $args Unused (the variant comes from the setting).
 * @return string
 */
function mlsimport_property_media( int $id = 0, array $args = array() ): string {
	// The user's chosen media variant from the "Media Section Type" setting.
	$type = (string) mlsimport_standalone_option( 'media_section_type', 'classic' );

	// Map each setting value to the gallery layout/variant args.
	$map = array(
		'classic'    => array( 'layout' => 'slider', 'variant' => 'classic' ),
		'vertical'   => array( 'layout' => 'slider', 'variant' => 'vertical' ),
		'v4'         => array( 'layout' => 'slider', 'variant' => 'full' ),
		'multi'      => array( 'layout' => 'slider', 'variant' => 'multi' ),
		'masonry1'   => array( 'layout' => 'masonry' ),
		'masonry2'   => array( 'layout' => 'masonry_v2' ),
	);

	// Delegate to the one gallery renderer; unknown types fall back to classic.
	return mlsimport_property_gallery( $id, isset( $map[ $type ] ) ? $map[ $type ] : $map['classic'] );
}

/**
 * Gallery / slider section — one render fn for every media layout. The manifest
 * bakes a layout (grid|masonry|slider) and, for sliders, a variant; the eight
 * WpResidence slider widgets and the masonry/grid galleries all route here (DRY).
 *
 * @param int   $id   Property post ID.
 * @param array $args { layout: grid|masonry|slider, variant: string, columns: int }
 * @return string
 */
function mlsimport_property_gallery( int $id = 0, array $args = array() ): string {
	$data = mlsimport_property_data( $id );
	if ( empty( $data ) ) {
		return '';
	}

	// Prefer the real gallery; fall back to the single featured image when empty.
	$ids = $data['gallery_ids'];
	if ( empty( $ids ) && $data['thumbnail_id'] ) {
		$ids = array( $data['thumbnail_id'] );
	}
	// Live mode: no attachments — the gallery items are the MLS CDN URLs.
	if ( empty( $ids ) && ! empty( $data['gallery_urls'] ) ) {
		$ids = $data['gallery_urls'];
	}
	// No images at all → no gallery.
	if ( empty( $ids ) ) {
		return '';
	}

	// Layout defaults to a static grid.
	$layout = isset( $args['layout'] ) ? (string) $args['layout'] : 'grid';

	// The "Featured listing" checkbox (issue #288), the same flag the list cards
	// badge; every layout shows it over the first photo.
	$featured = '1' === (string) get_post_meta( (int) $data['id'], 'mlsimport_featured', true );

	// Slider layouts route to the Splide builder.
	if ( 'slider' === $layout ) {
		return mlsimport_property_gallery_slider( $ids, array_merge( $args, array( 'featured' => $featured ) ) );
	}

	// Grid/masonry overlay: featured + status chips + photo count.
	$overlay = array(
		'status'   => '' !== (string) $data['status'] ? (string) $data['status'] : ( '' !== (string) $data['property_sub_type'] ? (string) $data['property_sub_type'] : '' ),
		'count'    => count( $ids ),
		'featured' => $featured,
	);
	return mlsimport_property_gallery_grid( $ids, $layout, $overlay );
}

/**
 * A fresh data-gallery group id, unique per gallery instance, so two galleries on
 * one page don't merge into a single lightbox set.
 *
 * @return string
 */
function mlsimport_property_gallery_group_id(): string {
	// Per-request counter so each gallery gets its own lightbox group.
	static $n = 0;
	return 'mlsimport-gallery-' . ( ++$n );
}

/**
 * Wrap a gallery image in a GLightbox link to its full-size file, so a click opens
 * the lightbox slider. Falls back to the bare image when no full URL exists.
 *
 * @param int|string $aid   Attachment ID, or a bare image URL (its own full size).
 * @param string     $img   Pre-rendered <img> markup.
 * @param string     $group data-gallery group shared across one gallery instance.
 * @return string
 */
function mlsimport_property_lightbox_link( $aid, string $img, string $group ): string {
	// A non-numeric string is its own full-size URL; else resolve the 'full' size.
	$is_url = is_string( $aid ) && ! is_numeric( $aid );
	$full   = $is_url ? $aid : wp_get_attachment_image_url( (int) $aid, 'full' );
	// No full URL → return the bare image, unlinked.
	if ( ! $full ) {
		return $img;
	}
	// Attachment captions become the lightbox title (URLs carry none).
	$caption  = $is_url ? '' : trim( (string) wp_get_attachment_caption( (int) $aid ) );
	$cap_attr = '' !== $caption ? ' data-title="' . esc_attr( $caption ) . '"' : '';
	// Wrap the image in the GLightbox anchor, tagged with the shared group id.
	return '<a class="mlsimport-glightbox" href="' . esc_url( $full ) . '" data-gallery="' . esc_attr( $group ) . '"' . $cap_attr . '>' . $img . '</a>';
}

/**
 * Static grid / masonry gallery markup.
 *
 * @param int[]  $ids    Attachment IDs.
 * @param string $layout 'grid', 'masonry' (column flow) or 'masonry_v2' (hero + strip).
 * @return string
 */
function mlsimport_property_gallery_grid( array $ids, string $layout, array $overlay = array() ): string {
	// Masonry v1 ('masonry') is the hero + 2×2 mosaic — the base grid already is
	// that (WpResidence's masonry gallery 1). Only v2 needs a modifier.
	$modifier = ( 'masonry_v2' === $layout ) ? ' mlsimport-property-gallery--masonry-v2' : '';
	$status   = isset( $overlay['status'] ) ? (string) $overlay['status'] : '';
	$count    = isset( $overlay['count'] ) ? (int) $overlay['count'] : 0;
	$featured = ! empty( $overlay['featured'] );
	// Every layout shows the first 5 tiles only (the rest are hidden in CSS, the
	// WpResidence pattern); put the count chip on the last VISIBLE tile so it
	// reads as "see all N photos" rather than sitting on a hidden overflow image.
	$count_index = min( count( $ids ) - 1, 4 );

	// One lightbox group for this gallery instance.
	$group = mlsimport_property_gallery_group_id();
	// Section wrapper + the grid container (with any masonry-v2 modifier).
	$html  = mlsimport_property_section_open( 'gallery' );
	$html .= '<div class="mlsimport-property-gallery__grid' . esc_attr( $modifier ) . '">';
	$i = 0;
	foreach ( $ids as $aid ) {
		// Resolve this photo; a photo that produced no URL still advances the index.
		$img = mlsimport_property_photo_bg( $aid, 'large', 'mlsimport-property-photo' );
		if ( '' === $img ) {
			++$i;
			continue;
		}
		$over = '';
		// Featured + status chips ride the first tile, side by side in one row.
		if ( 0 === $i && ( $featured || '' !== $status ) ) {
			$over .= '<span class="mlsimport-property-gallery__chips">';
			if ( $featured ) {
				$over .= '<span class="mlsimport-property-gallery__chip mlsimport-property-gallery__chip--featured">' . esc_html__( 'Featured', 'mlsimport' ) . '</span>';
			}
			if ( '' !== $status ) {
				$over .= '<span class="mlsimport-property-gallery__chip mlsimport-property-gallery__chip--status">' . esc_html( $status ) . '</span>';
			}
			$over .= '</span>';
		}
		// Count chip rides the last visible tile.
		if ( $i === $count_index && $count > 1 ) {
			/* translators: %d: number of photos. */
			$over .= '<span class="mlsimport-property-gallery__chip mlsimport-property-gallery__chip--count">' . esc_html( sprintf( _n( '%d photo', '%d photos', $count, 'mlsimport' ), $count ) ) . '</span>';
		}
		// Each tile is a lightbox-linked figure carrying any overlay chip.
		$html .= '<figure class="mlsimport-property-gallery__item">' . mlsimport_property_lightbox_link( $aid, $img, $group ) . $over . '</figure>';
		++$i;
	}
	$html .= '</div>';
	$html .= mlsimport_property_section_close();
	return $html;
}

/**
 * Splide slider gallery markup. Variant tunes the Splide options consumed by
 * mlsimport-property-slider.js (the assets are enqueued by the dispatcher).
 *
 * @param int[] $ids  Attachment IDs.
 * @param array $args { variant: classic|vertical|multi|full }
 * @return string
 */
function mlsimport_property_gallery_slider( array $ids, array $args ): string {
	// Resolve the variant, clamping any unknown value back to 'classic'.
	$variant  = isset( $args['variant'] ) ? (string) $args['variant'] : 'classic';
	$allowed  = array( 'classic', 'vertical', 'multi', 'full' );
	$variant  = in_array( $variant, $allowed, true ) ? $variant : 'classic';

	// Classic and vertical pair the main carousel with a synced thumbnail strip
	// (the WpResidence pattern); multi and full are plain.
	$thumbs = in_array( $variant, array( 'classic', 'vertical' ), true );

	// One lightbox group for this slider instance.
	$group = mlsimport_property_gallery_group_id();
	$html  = mlsimport_property_section_open( 'gallery' );

	// Open the wrap that pairs the main carousel with its thumbnail strip.
	if ( $thumbs ) {
		$html .= '<div class="mlsimport-property-slider-wrap mlsimport-property-slider-wrap--' . esc_attr( $variant ) . '" data-mlsimport-slider-wrap>';
	}

	// Main carousel: one lightbox-linked slide per resolvable photo.
	$html .= '<div class="mlsimport-property-slider splide" data-mlsimport-slider="' . esc_attr( $variant ) . '">';
	$html .= '<div class="splide__track"><ul class="splide__list">';
	foreach ( $ids as $aid ) {
		$img = mlsimport_property_photo_bg( $aid, 'large', 'mlsimport-property-photo' );
		if ( '' !== $img ) {
			$html .= '<li class="splide__slide">' . mlsimport_property_lightbox_link( $aid, $img, $group ) . '</li>';
		}
	}
	$html .= '</ul></div>';
	// Featured chip: pinned over the carousel (not inside a slide), so it stays put
	// while the photos move.
	if ( ! empty( $args['featured'] ) ) {
		$html .= '<span class="mlsimport-property-gallery__chips"><span class="mlsimport-property-gallery__chip mlsimport-property-gallery__chip--featured">' . esc_html__( 'Featured', 'mlsimport' ) . '</span></span>';
	}
	$html .= '</div>';

	// Synced thumbnail strip (classic/vertical only): a medium tile per photo.
	if ( $thumbs ) {
		$html .= '<div class="mlsimport-property-slider-thumbs splide" data-mlsimport-slider-thumbs>';
		$html .= '<div class="splide__track"><ul class="splide__list">';
		foreach ( $ids as $aid ) {
			$thumb = mlsimport_property_photo_bg( $aid, 'medium', 'mlsimport-property-photo' );
			if ( '' !== $thumb ) {
				$html .= '<li class="splide__slide">' . $thumb . '</li>';
			}
		}
		$html .= '</ul></div></div>';
		$html .= '</div>'; // .mlsimport-property-slider-wrap
	}

	$html .= mlsimport_property_section_close();
	return $html;
}

/**
 * Agent card section — reuses the theme-overridable parts/agent-box.php partial.
 *
 * @param int   $id   Property post ID.
 * @param array $args Behavioral options.
 * @return string
 */
function mlsimport_property_agent_card( int $id = 0, array $args = array() ): string {
	// Need a resolved property that carries an agent.
	$data = mlsimport_property_data( $id );
	if ( empty( $data ) || empty( $data['agent'] ) ) {
		return '';
	}

	// A nameless agent draws no card.
	$a = $data['agent'];
	if ( '' === (string) $a['name'] ) {
		return '';
	}

	// Profile link (when a linked post exists) and a dial-able phone.
	$permalink = ! empty( $a['id'] ) ? (string) get_permalink( (int) $a['id'] ) : '';
	$tel        = preg_replace( '/[^0-9+]/', '', (string) $a['phone'] );
	// The "Verified agent" check shows only when the linked agent post is ticked verified
	// (mlsimport_featured). An MLS-only agent with no post is never marked.
	$verified   = ! empty( $a['id'] ) && '1' === (string) get_post_meta( (int) $a['id'], 'mlsimport_featured', true );

	// Avatar: agent photo when present, otherwise initials in a tinted circle.
	if ( ! empty( $a['photo_id'] ) ) {
		$avatar = wp_get_attachment_image( (int) $a['photo_id'], 'thumbnail', false, array( 'class' => 'mlsimport-property-agent__photo-img' ) );
	} else {
		$avatar = '<span class="mlsimport-property-agent__initials">' . esc_html( mlsimport_property_initials( $a['name'] ) ) . '</span>';
	}

	// Credential tiles — only those with a value render.
	$creds = array(
		array( 'building', __( 'Brokerage', 'mlsimport' ), $a['office'] ),
		array( 'badge', __( 'License', 'mlsimport' ), $a['license'] ),
		array( 'hash', __( 'Agent MLS ID', 'mlsimport' ), $a['agent_mls_id'] ),
		array( 'building', __( 'Office MLS ID', 'mlsimport' ), $a['office_mls_id'] ),
	);
	$cred_html = '';
	foreach ( $creds as $c ) {
		// Skip a credential with no value.
		if ( '' === (string) $c[2] ) {
			continue;
		}
		// Icon + label + value tile.
		$cred_html .= '<div class="mlsimport-property-agent__cred">'
			. '<span class="mlsimport-property-agent__cred-icon" aria-hidden="true">' . mlsimport_property_icon( $c[0] ) . '</span>'
			. '<span class="mlsimport-property-agent__cred-text">'
			. '<span class="mlsimport-property-agent__cred-label">' . esc_html( $c[1] ) . '</span>'
			. '<span class="mlsimport-property-agent__cred-value">' . esc_html( $c[2] ) . '</span>'
			. '</span></div>';
	}

	// Name links to the profile when one exists, else plain text.
	$name_html = '' !== $permalink
		? '<a href="' . esc_url( $permalink ) . '">' . esc_html( $a['name'] ) . '</a>'
		: esc_html( $a['name'] );

	// Titled "Meet your agent" section wrapping a two-column grid.
	$html  = mlsimport_property_section_open( 'agent', __( 'Meet your agent', 'mlsimport' ), 'user' );
	$html .= '<div class="mlsimport-property-agent__grid">';

	// Identity + credentials + bio.
	$html .= '<div class="mlsimport-property-agent__main">';
	$html .= '<div class="mlsimport-property-agent__identity">';
	$html .= '<span class="mlsimport-property-agent__photo">' . $avatar
		. ( $verified ? '<span class="mlsimport-property-agent__verified" title="' . esc_attr__( 'Verified agent', 'mlsimport' ) . '" aria-hidden="true">' . mlsimport_property_icon( 'check' ) . '</span>' : '' )
		. '</span>';
	$html .= '<div class="mlsimport-property-agent__id-text">'
		. '<h3 class="mlsimport-property-agent__name">' . $name_html . '</h3>'
		. '<p class="mlsimport-property-agent__role">' . esc_html__( 'Listing Agent', 'mlsimport' ) . ( '' !== (string) $a['office'] ? ' · ' . esc_html( $a['office'] ) : '' ) . '</p>'
		. '</div>';
	$html .= '</div>'; // identity.
	if ( '' !== $cred_html ) {
		$html .= '<div class="mlsimport-property-agent__creds">' . $cred_html . '</div>';
	}
	$html .= '</div>'; // main.

	// Contact rail.
	$html .= '<div class="mlsimport-property-agent__contact">';
	$html .= '<p class="mlsimport-property-agent__contact-title">' . esc_html__( 'Get in touch', 'mlsimport' ) . '</p>';
	// Primary call button when a phone exists.
	if ( '' !== $tel ) {
		$html .= '<a class="mlsimport-property-agent__btn mlsimport-property-agent__btn--primary" href="tel:' . esc_attr( $tel ) . '">' . mlsimport_property_icon( 'phone' ) . '<span>' . esc_html( $a['phone'] ) . '</span></a>';
	}
	// Outline message button when an email exists.
	if ( '' !== (string) $a['email'] ) {
		$html .= '<a class="mlsimport-property-agent__btn mlsimport-property-agent__btn--outline" href="mailto:' . esc_attr( $a['email'] ) . '">' . mlsimport_property_icon( 'message' ) . '<span>' . esc_html__( 'Send a message', 'mlsimport' ) . '</span></a>';
	}
	// Office phone line, when present.
	if ( '' !== (string) $a['office_phone'] ) {
		$html .= '<p class="mlsimport-property-agent__office-line">' . mlsimport_property_icon( 'building' ) . '<span>' . esc_html__( 'Office', 'mlsimport' ) . ' · ' . esc_html( $a['office_phone'] ) . '</span></p>';
	}
	// License line, when present.
	if ( '' !== (string) $a['license'] ) {
		$html .= '<p class="mlsimport-property-agent__license">' . esc_html( $a['license'] ) . '</p>';
	}
	$html .= '</div>'; // contact.

	$html .= '</div>'; // grid.
	$html .= mlsimport_property_section_close();
	return $html;
}

/**
 * First-two-word initials for an avatar fallback, uppercased.
 *
 * @param string $name Full name.
 * @return string
 */
function mlsimport_property_initials( string $name ): string {
	// Split the name on whitespace into words.
	$parts = preg_split( '/\s+/', trim( $name ) );
	$out   = '';
	foreach ( (array) $parts as $word ) {
		// Take the first letter of each non-empty word.
		if ( '' !== $word ) {
			$out .= mb_substr( $word, 0, 1 );
		}
		// Stop once two initials are collected.
		if ( mb_strlen( $out ) >= 2 ) {
			break;
		}
	}
	// Uppercase for the avatar chip.
	return mb_strtoupper( $out );
}

/**
 * Lead form section — one render fn for all four lead forms. The manifest bakes
 * a variant (contact|form|sidebar|tour); they all post to the single
 * mlsimport_property_lead AJAX endpoint. See decision 5.
 *
 * @param int   $id   Property post ID.
 * @param array $args { variant: contact|form|sidebar|tour }
 * @return string
 */
function mlsimport_property_lead_form( int $id = 0, array $args = array() ): string {
	$data = mlsimport_property_data( $id );
	if ( empty( $data ) ) {
		return '';
	}

	// Which of the four lead-form variants this call renders.
	$variant = isset( $args['variant'] ) ? (string) $args['variant'] : 'contact';
	// Per-variant heading; unknown variants fall back to the contact title.
	$titles  = array(
		'contact' => __( 'Contact Agent', 'mlsimport' ),
		'form'    => __( 'Request Information', 'mlsimport' ),
		'sidebar' => __( 'Contact Agent', 'mlsimport' ),
		'tour'    => __( 'Schedule a Tour', 'mlsimport' ),
	);
	$title = isset( $titles[ $variant ] ) ? $titles[ $variant ] : $titles['contact'];

	// The shared field set (variant tweaks which inputs appear).
	$fields = mlsimport_property_lead_fields( $data, $variant );

	// Titled section wrapping the form that posts to the lead endpoint.
	$html  = mlsimport_property_section_open( 'lead lead--' . $variant, $title, 'message' );
	$html .= '<form class="mlsimport-property-lead-form" data-mlsimport-lead method="post">';
	$html .= $fields;
	$html .= '<button type="submit">' . esc_html__( 'Send', 'mlsimport' ) . '</button>';
	$html .= '<div class="mlsimport-property-lead-form__status" role="status" aria-live="polite"></div>';
	$html .= '</form>';
	$html .= mlsimport_property_section_close();
	return $html;
}

/**
 * Mortgage calculator section — browser-only, seeded with the list price.
 *
 * @param int   $id   Property post ID.
 * @param array $args Behavioral options.
 * @return string
 */
function mlsimport_property_calculator( int $id = 0, array $args = array() ): string {
	// A calculator needs a list price to seed itself.
	$data = mlsimport_property_data( $id );
	if ( empty( $data ) || null === $data['price'] ) {
		return '';
	}

	// Seed values: list price, an estimated monthly tax, and any known HOA fee.
	$price = (float) $data['price'];
	$tax   = (int) round( $price * 0.0125 / 12 ); // ~1.25%/yr property tax, monthly.
	$hoa   = null !== $data['hoa_fee'] ? (int) round( (float) $data['hoa_fee'] ) : 0;

	// Payment-breakdown segments: key, label (the bar + legend share this list).
	$segments = array(
		'pi'  => __( 'Principal & interest', 'mlsimport' ),
		'tax' => __( 'Property tax', 'mlsimport' ),
		'ins' => __( 'Home insurance', 'mlsimport' ),
		'pmi' => __( 'PMI', 'mlsimport' ),
		'hoa' => __( 'HOA dues', 'mlsimport' ),
	);

	// Build the stacked bar and its legend from the same segment list.
	$bar    = '';
	$legend = '';
	foreach ( $segments as $key => $label ) {
		// Bar segment (JS sizes it) + legend row (JS fills the amount).
		$bar    .= '<span class="mlsimport-property-calculator__seg mlsimport-property-calculator__seg--' . esc_attr( $key ) . '" data-seg="' . esc_attr( $key ) . '"></span>';
		$legend .= '<li class="mlsimport-property-calculator__legend-item" data-legend="' . esc_attr( $key ) . '">'
			. '<span class="mlsimport-property-calculator__dot mlsimport-property-calculator__dot--' . esc_attr( $key ) . '"></span>'
			. '<span class="mlsimport-property-calculator__legend-label">' . esc_html( $label ) . '</span>'
			. '<b class="mlsimport-property-calculator__legend-amount" data-amt="' . esc_attr( $key ) . '"></b>'
			. '</li>';
	}

	// label, data-calc key, value, step (controls grid).
	$controls = array(
		array( __( 'Home price ($)', 'mlsimport' ), 'price', (string) (int) $price, '1000' ),
		array( __( 'Down payment (%)', 'mlsimport' ), 'down', '20', '1' ),
		array( __( 'Interest rate (%)', 'mlsimport' ), 'rate', '6.5', '0.01' ),
		array( __( 'Loan term (years)', 'mlsimport' ), 'term', '30', '1' ),
		array( __( 'Property tax ($/mo)', 'mlsimport' ), 'tax', (string) $tax, '1' ),
		array( __( 'Home insurance ($/mo)', 'mlsimport' ), 'ins', '120', '1' ),
		array( __( 'HOA ($/mo)', 'mlsimport' ), 'hoa', (string) $hoa, '1' ),
	);
	// One numeric input per control row.
	$fields = '';
	foreach ( $controls as $c ) {
		$fields .= '<label class="mlsimport-property-calculator__field">'
			. '<span class="mlsimport-property-calculator__field-label">' . esc_html( $c[0] ) . '</span>'
			. '<input type="number" inputmode="decimal" step="' . esc_attr( $c[3] ) . '" min="0" data-calc="' . esc_attr( $c[1] ) . '" value="' . esc_attr( $c[2] ) . '" />'
			. '</label>';
	}

	// Titled section; the summary (headline + bar + legend), then the controls.
	$html  = mlsimport_property_section_open( 'calculator', __( 'Mortgage Calculator', 'mlsimport' ), 'calc' );
	$html .= '<div class="mlsimport-property-calculator" data-mlsimport-calculator>';
	$html .= '<div class="mlsimport-property-calculator__summary">';
	$html .= '<div class="mlsimport-property-calculator__headline">'
		. '<span class="mlsimport-property-calculator__headline-label">' . esc_html__( 'Estimated monthly payment', 'mlsimport' ) . '</span>'
		. '<span class="mlsimport-property-calculator__amount" data-calc="result">—</span>'
		. '</div>';
	$html .= '<div class="mlsimport-property-calculator__bar" aria-hidden="true">' . $bar . '</div>';
	$html .= '<ul class="mlsimport-property-calculator__legend">' . $legend . '</ul>';
	$html .= '</div>';
	$html .= '<div class="mlsimport-property-calculator__controls">' . $fields . '</div>';
	$html .= '<p class="mlsimport-property-calculator__note">' . esc_html__( 'Estimates only and not a loan offer. Taxes, insurance and rates vary — confirm with a lender.', 'mlsimport' ) . '</p>';
	$html .= '</div>';
	$html .= mlsimport_property_section_close();
	return $html;
}

/**
 * Map section — a pin at the listing's coordinates, drawn with Leaflet +
 * OpenStreetMap. The section enqueues the map assets itself and renders through
 * mlsimport-property-map.js. No geocoding — uses lat/lng.
 *
 * @param int   $id   Property post ID.
 * @param array $args Behavioral options.
 * @return string
 */
function mlsimport_property_map( int $id = 0, array $args = array() ): string {
	$data = mlsimport_property_data( $id );
	if ( empty( $data ) ) {
		return '';
	}

	// The address rows ride the same facts grid as every other section, so they obey
	// the column setting and carry the same hairline dividers.
	$rows = array(
		array( __( 'Street', 'mlsimport' ), $data['street'] ),
		array( __( 'City', 'mlsimport' ), $data['city'] ),
		array( __( 'Subdivision', 'mlsimport' ), $data['subdivision'] ),
		array( __( 'County', 'mlsimport' ), $data['county'] ),
		array( __( 'State', 'mlsimport' ), $data['state'] ),
		array( __( 'Zip', 'mlsimport' ), $data['zip'] ),
		array( __( 'Country', 'mlsimport' ), $data['country'] ),
		array( __( 'MLS #', 'mlsimport' ), $data['mls_id'] ),
	);
	// Keep only address rows that carry a value.
	$facts = array();
	foreach ( $rows as $row ) {
		if ( '' !== (string) $row[1] ) {
			$facts[] = array( $row[0], (string) $row[1] );
		}
	}
	$grid = mlsimport_property_facts_grid_html( $facts, mlsimport_property_columns_class() . ' mlsimport-property-details__grid--address', (int) ( $data['id'] ?? 0 ) );

	// A map only draws when both coordinates are present.
	$has_map = ( null !== $data['latitude'] && null !== $data['longitude'] );
	// Neither address rows nor a map → no section.
	if ( '' === $grid && ! $has_map ) {
		return '';
	}

	// Titled "Address" section: the address grid, then optionally the map canvas.
	$html  = mlsimport_property_section_open( 'map', __( 'Address', 'mlsimport' ), 'pin' );
	$html .= $grid;
	// Address-only listing (no coordinates): close and return here.
	if ( ! $has_map ) {
		$html .= mlsimport_property_section_close();
		return $html;
	}

	// Enqueue the map assets.
	mlsimport_property_map_enqueue();

	// Price-pin + info-card payload: the marker is a price pill, clicking it opens
	// a card (image, title, price, beds/baths/area).
	$thumb_id  = (int) $data['thumbnail_id'];
	$pin_image = $thumb_id ? (string) wp_get_attachment_image_url( $thumb_id, 'medium' ) : '';
	if ( '' === $pin_image && '' !== ( $data['image_url'] ?? '' ) ) {
		// Live listings carry media as URLs, not attachments.
		$pin_image = (string) $data['image_url'];
	}
	// Formatted price + bed/bath counts for the info-card.
	$price_fmt = null !== $data['price'] ? mlsimport_format_price( $data['price'] ) : '';
	$beds      = null !== $data['bedrooms'] ? mlsimport_format_amount( $data['bedrooms'] ) : '';
	$baths     = null !== $data['bathrooms'] ? mlsimport_format_amount( $data['bathrooms'] ) : '';
	// Whole-number ft², matching the listing card's spec line (which uses
	// number_format_i18n at 0 decimals) rather than leaking a fractional area.
	$area      = null !== $data['living_area'] ? number_format_i18n( (float) $data['living_area'] ) : '';

	// The map canvas: JS reads the coords + info-card payload from these data attrs.
	$html .= '<div class="mlsimport-property-map__canvas" data-mlsimport-map'
		. ' data-lat="' . esc_attr( (string) $data['latitude'] ) . '"'
		. ' data-lng="' . esc_attr( (string) $data['longitude'] ) . '"'
		. ' data-title="' . esc_attr( $data['title'] ) . '"'
		. ' data-url="' . esc_url( $data['permalink'] ) . '"'
		. ' data-image="' . esc_attr( $pin_image ) . '"'
		. ' data-price="' . esc_attr( $price_fmt ) . '"'
		. ' data-price-raw="' . esc_attr( null !== $data['price'] ? (string) $data['price'] : '' ) . '"'
		. ' data-beds="' . esc_attr( $beds ) . '"'
		. ' data-baths="' . esc_attr( $baths ) . '"'
		. ' data-area="' . esc_attr( $area ) . '"'
		. ' data-zoom="' . esc_attr( (string) mlsimport_standalone_map_zoom() ) . '"'
		. ' data-tile="' . esc_attr( (string) apply_filters( 'mlsimport_map_tile_url', 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png' ) ) . '"'
		. '></div>';
	$html .= mlsimport_property_section_close();
	return $html;
}

/**
 * Enqueue the Leaflet + OpenStreetMap assets the map section needs.
 *
 * @return void
 */
function mlsimport_property_map_enqueue(): void {
	// Nothing to do outside a WP front-end request.
	if ( ! function_exists( 'wp_enqueue_script' ) ) {
		return;
	}
	// Register the plugin's map scripts/styles once.
	Mlsimport_Property_Section_Assets::ensure_registered();

	wp_enqueue_style( 'mlsimport-leaflet' );
	wp_enqueue_script( 'mlsimport-leaflet' );
	wp_enqueue_script( 'mlsimport-property-map' );
}

/**
 * Embed an external media URL (virtual tour or video) as a responsive iframe.
 * One render fn for both; the manifest bakes which view-model field to read.
 *
 * @param int   $id   Property post ID.
 * @param array $args { source: virtual_tour|video, title: string }
 * @return string
 */
function mlsimport_property_embed( int $id = 0, array $args = array() ): string {
	$data = mlsimport_property_data( $id );
	if ( empty( $data ) ) {
		return '';
	}

	// Which media field this call renders, and its URL from the view model.
	$source = isset( $args['source'] ) ? (string) $args['source'] : 'virtual_tour';
	$url    = 'video' === $source ? $data['video_url'] : $data['virtual_tour'];
	// No URL → no section.
	if ( '' === trim( (string) $url ) ) {
		return '';
	}

	// Per-source slug, heading, icon and badge label.
	$slug  = 'video' === $source ? 'video' : 'virtual-tour';
	$title = 'video' === $source ? __( 'Video', 'mlsimport' ) : __( 'Virtual Tour', 'mlsimport' );
	$icon  = 'video' === $source ? 'video' : 'cube';
	$badge = 'video' === $source ? __( 'Video', 'mlsimport' ) : __( '3D Walkthrough', 'mlsimport' );

	// Poster + play that opens the tour/video. A live iframe is avoided on purpose:
	// most providers (Zillow, Matterport, YouTube privacy mode) block framing, so a
	// poster that launches the URL is both robust and matches the design.
	// Poster attachment: the thumbnail, else the first gallery image.
	$poster_id = $data['thumbnail_id'];
	if ( ! $poster_id && ! empty( $data['gallery_ids'] ) ) {
		$poster_id = (int) $data['gallery_ids'][0];
	}
	$poster_img = $poster_id ? wp_get_attachment_image( $poster_id, 'large', false, array( 'class' => 'mlsimport-property-poster__img' ) ) : '';
	// No attachment poster (e.g. live mode): fall back to a raw image URL.
	if ( '' === $poster_img ) {
		// Live listings carry media as URLs, not attachments.
		$poster_url = '' !== ( $data['image_url'] ?? '' ) ? (string) $data['image_url'] : (string) ( $data['gallery_urls'][0] ?? '' );
		if ( '' !== $poster_url ) {
			$poster_img = '<img class="mlsimport-property-poster__img" src="' . esc_url( $poster_url ) . '" alt="" />';
		}
	}

	// Titled section: a poster link that opens the external tour/video in a new tab.
	$html  = mlsimport_property_section_open( $slug, $title, $icon );
	$html .= '<a class="mlsimport-property-poster mlsimport-property-' . esc_attr( $slug ) . '__poster" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">';
	$html .= $poster_img;
	$html .= '<span class="mlsimport-property-poster__badge">' . mlsimport_property_icon( $icon ) . '<span>' . esc_html( $badge ) . '</span></span>';
	$html .= '<span class="mlsimport-property-poster__play" aria-hidden="true"><svg viewBox="0 0 24 24" fill="currentColor" focusable="false"><path d="M9 7l9 5-9 5z"/></svg></span>';
	$html .= '</a>';
	$html .= mlsimport_property_section_close();
	return $html;
}

/**
 * Outbound share targets for a listing, shared by the Share section and the
 * title-bar share popup so both offer the same networks.
 *
 * @param string $url   Listing permalink.
 * @param string $title Listing title.
 * @return array<string,string> Label => href.
 */
function mlsimport_property_share_targets( string $url, string $title ): array {
	// Both the URL and the title travel inside query strings / mailto parts.
	$enc  = rawurlencode( $url );
	$enct = rawurlencode( $title );

	/**
	 * Filters the outbound share targets offered for a listing.
	 *
	 * @param array<string,string> $targets Label => href.
	 * @param string               $url     Listing permalink.
	 * @param string               $title   Listing title.
	 */
	return apply_filters(
		'mlsimport_property_share_targets',
		array(
			'Facebook' => 'https://www.facebook.com/sharer/sharer.php?u=' . $enc,
			'X'        => 'https://twitter.com/intent/tweet?url=' . $enc . '&text=' . $enct,
			'WhatsApp' => 'https://api.whatsapp.com/send?text=' . $enct . '%20' . $enc,
			'Email'    => 'mailto:?subject=' . $enct . '&body=' . $enc,
		),
		$url,
		$title
	);
}

/**
 * Share / print section — social share links + copy-link + print.
 *
 * @param int   $id   Property post ID.
 * @param array $args Behavioral options.
 * @return string
 */
function mlsimport_property_share( int $id = 0, array $args = array() ): string {
	// A share section needs a permalink to point at.
	$data = mlsimport_property_data( $id );
	if ( empty( $data ) || '' === $data['permalink'] ) {
		return '';
	}

	// Outbound share targets.
	$url   = $data['permalink'];
	$links = mlsimport_property_share_targets( $url, $data['title'] );

	// Titled section: one button per share target, then copy-link + print.
	$html  = mlsimport_property_section_open( 'share', __( 'Share', 'mlsimport' ), 'share' );
	$html .= '<div class="mlsimport-property-share__actions">';
	foreach ( $links as $label => $href ) {
		$html .= '<a class="mlsimport-property-share__button" href="' . esc_url( $href ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label ) . '</a>';
	}
	$html .= '<button type="button" class="mlsimport-property-share__button" data-mlsimport-copy="' . esc_attr( $url ) . '">' . esc_html__( 'Copy link', 'mlsimport' ) . '</button>';
	$html .= '<button type="button" class="mlsimport-property-share__button" data-mlsimport-print="' . esc_attr( mlsimport_property_print_url( $url ) ) . '">' . esc_html__( 'Print', 'mlsimport' ) . '</button>';
	$html .= '</div>';
	$html .= mlsimport_property_section_close();
	return $html;
}

/**
 * Similar listings section — other listings that share the current property's
 * taxonomy terms, the way WpResidence computes related properties (city / type /
 * action), rendered with the existing card grid.
 *
 * Matching is taxonomy-driven (not the flat city column): import always assigns
 * mlsimport_city / mlsimport_property_type / mlsimport_listing_type terms, so
 * this finds siblings even when the flat city column is empty. Like WpResidence,
 * the term sets are ANDed; if that is too narrow to return anything, it relaxes
 * to the city term alone so the section still populates.
 *
 * @param int   $id   Property post ID.
 * @param array $args { limit: int }
 * @return string
 */
function mlsimport_property_similar( int $id = 0, array $args = array() ): string {
	// The card renderer supplies the listing-card markup reused here.
	require_once __DIR__ . '/class-mlsimport-standalone-render.php';

	// Need a resolved current property to find siblings of.
	$data = mlsimport_property_data( $id );
	if ( empty( $data ) ) {
		return '';
	}
	// Current post id + how many similar listings to show (settings, args win).
	$pid   = (int) $data['id'];
	$limit = isset( $args['limit'] ) ? (int) $args['limit'] : (int) mlsimport_standalone_option( 'similar_count', 3 );
	$limit = max( 1, $limit );

	/** Filter the taxonomies used to match similar listings (WpResidence parity). @since 6.4 */
	$taxes = (array) apply_filters(
		'mlsimport_similar_taxonomies',
		array( 'mlsimport_city', 'mlsimport_property_type', 'mlsimport_listing_type' ),
		$pid
	);

	// Build a tax_query clause per taxonomy the current listing has terms in,
	// remembering the city clause so it can serve as the relaxed fallback.
	$clauses = array();
	$city_cl = null;
	foreach ( $taxes as $tax ) {
		$terms = get_the_terms( $pid, $tax );
		// Skip a taxonomy the listing carries no terms in.
		if ( ! is_array( $terms ) || empty( $terms ) ) {
			continue;
		}
		$clause    = array( 'taxonomy' => $tax, 'field' => 'term_id', 'terms' => wp_list_pluck( $terms, 'term_id' ) );
		$clauses[] = $clause;
		// Keep the city clause aside for the fallback query.
		if ( 'mlsimport_city' === $tax ) {
			$city_cl = $clause;
		}
	}
	// No matchable terms → no section.
	if ( empty( $clauses ) ) {
		return '';
	}

	// First try the full AND match across every clause.
	$ids = mlsimport_property_similar_ids( $pid, $clauses, $limit );
	// Relax to the city anchor when the full AND match is too narrow to fill out.
	if ( empty( $ids ) && $city_cl && count( $clauses ) > 1 ) {
		$ids = mlsimport_property_similar_ids( $pid, array( $city_cl ), $limit );
	}
	// Still nothing → no section.
	if ( empty( $ids ) ) {
		return '';
	}

	// Render the matched listings as cards; empty markup means no section.
	$cards = Mlsimport_Standalone_Render::cards_for_posts( $ids );
	if ( '' === trim( $cards ) ) {
		return '';
	}

	// Cards per row: --mli-cols drives the grid, so the narrow-screen media queries
	// (2 then 1 across) still override it.
	$per_row = (int) mlsimport_standalone_option( 'similar_per_row', 3 );
	$per_row = max( 2, min( 4, $per_row ) );

	// Titled section wrapping the card grid.
	$html  = mlsimport_property_section_open( 'similar', __( 'Similar Listings', 'mlsimport' ), 'grid' );
	$html .= '<div class="mlsimport-results__grid" style="--mli-cols:' . esc_attr( (string) $per_row ) . '">' . $cards . '</div>';
	$html .= mlsimport_property_section_close();
	return $html;
}

/**
 * Run the similar-listings query for a set of tax_query clauses and return the
 * matching property IDs (newest first), excluding the current listing.
 *
 * @param int   $exclude Current property ID to exclude.
 * @param array $clauses tax_query clauses (each taxonomy/field/terms).
 * @param int   $limit   Max results.
 * @return int[]
 */
function mlsimport_property_similar_ids( int $exclude, array $clauses, int $limit ): array {
	// Multiple clauses are ANDed together (all terms must match).
	$tax_query = $clauses;
	if ( count( $clauses ) > 1 ) {
		$tax_query['relation'] = 'AND';
	}
	// IDs-only query for the newest matching listings, excluding the current one.
	$query = new WP_Query(
		array(
			'post_type'           => 'mlsimport_property',
			'post_status'         => 'publish',
			'posts_per_page'      => $limit,
			'post__not_in'        => array( $exclude ),
			'tax_query'           => $tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			'orderby'             => 'date',
			'order'               => 'DESC',
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			'fields'              => 'ids',
		)
	);
	return array_map( 'intval', (array) $query->posts );
}

/**
 * Header section — compact identity block: title, address, status, price.
 *
 * @param int   $id   Property post ID.
 * @param array $args Behavioral options.
 * @return string
 */
function mlsimport_property_header( int $id = 0, array $args = array() ): string {
	$data = mlsimport_property_data( $id );
	if ( empty( $data ) ) {
		return '';
	}

	// Build the identity block, appending each part only when it has a value.
	$inner = '';
	if ( '' !== $data['title'] ) {
		$inner .= '<h1 class="mlsimport-property-title__heading">' . esc_html( $data['title'] ) . '</h1>';
	}
	if ( '' !== $data['address'] ) {
		$inner .= '<p class="mlsimport-property-address__line">' . esc_html( $data['address'] ) . '</p>';
	}
	if ( '' !== $data['status'] ) {
		$inner .= '<span class="mlsimport-property-status__badge">' . esc_html( $data['status'] ) . '</span>';
	}
	if ( null !== $data['price'] ) {
		$inner .= '<p class="mlsimport-property-price__amount">' . esc_html( mlsimport_format_price( $data['price'] ) ) . '</p>';
	}
	// Nothing populated → no section.
	if ( '' === $inner ) {
		return '';
	}

	// Headingless wrapper around the identity block.
	$html  = mlsimport_property_section_open( 'header' );
	$html .= '<div class="mlsimport-property-header__inner">' . $inner . '</div>';
	$html .= mlsimport_property_section_close();
	return $html;
}

/**
 * The shared lead-form fields markup (name / email / phone [/ tour date] /
 * message [/ interest dropdown] + mandatory privacy consent + honeypot +
 * hidden property_id + nonce). One source for the four lead-form section
 * variants; the booking sidebar builds its own panels but shares the consent
 * checkbox via mlsimport_property_lead_consent_field().
 *
 * @param array  $data    Property view model.
 * @param string $variant contact|form|sidebar|tour.
 * @return string
 */
function mlsimport_property_lead_fields( array $data, string $variant ): string {
	// CSRF nonce checked by the lead handler.
	$nonce = wp_create_nonce( Mlsimport_Property_Lead::NONCE );

	// Core contact inputs: name + email required, phone optional.
	$fields  = '<input type="text" name="mlsimport_name" required placeholder="' . esc_attr__( 'Your name', 'mlsimport' ) . '" />';
	$fields .= '<input type="email" name="mlsimport_email" required placeholder="' . esc_attr__( 'Your email', 'mlsimport' ) . '" />';
	$fields .= '<input type="tel" name="mlsimport_phone" placeholder="' . esc_attr__( 'Your phone', 'mlsimport' ) . '" />';
	// The tour variant adds a preferred-date picker.
	if ( 'tour' === $variant ) {
		$fields .= '<input type="date" name="mlsimport_tour_date" aria-label="' . esc_attr__( 'Preferred tour date', 'mlsimport' ) . '" />';
	}
	$fields .= '<textarea name="mlsimport_message" rows="4" placeholder="' . esc_attr__( 'Message', 'mlsimport' ) . '"></textarea>';

	// Optional interest dropdown + mandatory consent checkbox.
	$fields .= mlsimport_property_lead_interest_field();
	$fields .= mlsimport_property_lead_consent_field();

	// Honeypot: real users leave it blank; bots fill it.
	// Hidden plumbing: honeypot, the property id, and the nonce.
	$fields .= '<input type="text" name="mlsimport_hp" class="mlsimport-property-lead-form__hp" tabindex="-1" autocomplete="off" aria-hidden="true" />';
	$fields .= '<input type="hidden" name="property_id" value="' . esc_attr( (string) $data['id'] ) . '" />';
	// Live listings have no post id (0) — the ListingKey URL is the listing
	// context, picked up by the lead handler's generic mlsimport_* collector.
	if ( '' !== ( $data['listing_key'] ?? '' ) ) {
		$fields .= '<input type="hidden" name="mlsimport_listing" value="' . esc_attr( mlsimport_live_url( (string) $data['listing_key'] ) ) . '" />';
	}
	$fields .= '<input type="hidden" name="nonce" value="' . esc_attr( $nonce ) . '" />';

	/** Filter the lead form fields markup. @since 6.3 */
	return (string) apply_filters( 'mlsimport_property_lead_fields', $fields, $variant, $data['id'] );
}

/**
 * The "I'm interested in" intent dropdown (Buy / Rent / Sell by default), or ''
 * when the show_looking_dropdown setting gates it off or looking_options is
 * empty. Shared by the lead-form sections and the booking sidebar's Ask a
 * Question panel; the mlsimport_interest value reaches the lead email via the
 * generic collector ("Interest: X").
 *
 * @return string
 */
function mlsimport_property_lead_interest_field(): string {
	// Gated off unless the setting explicitly enables the dropdown.
	if ( 'yes' !== mlsimport_standalone_option( 'show_looking_dropdown' ) ) {
		return '';
	}
	// Parse the comma-separated options; empty list → no dropdown.
	$choices = array_filter( array_map( 'trim', explode( ',', (string) mlsimport_standalone_option( 'looking_options' ) ) ) );
	if ( empty( $choices ) ) {
		return '';
	}

	// A real <select> inside a wrapper: mlsimport-property-interest.js swaps in a
	// styled button + listbox and writes every pick back here, because a native
	// option list is OS-drawn and cannot be made to match the rest of the site.
	// With JS off the select is simply the control, so nothing is lost.
	$html  = '<div class="mlsimport-interest">';
	$html .= '<select name="mlsimport_interest" aria-label="' . esc_attr__( "I'm interested in", 'mlsimport' ) . '">';
	$html .= '<option value="">' . esc_html__( "I'm interested in…", 'mlsimport' ) . '</option>';
	foreach ( $choices as $choice ) {
		$html .= '<option value="' . esc_attr( $choice ) . '">' . esc_html( $choice ) . '</option>';
	}
	return $html . '</select></div>';
}

/**
 * The mandatory privacy-consent checkbox. Every form that submits a property
 * lead (the lead-form sections AND the booking sidebar panels) renders it —
 * Mlsimport_Property_Lead::process() rejects a property submission without it,
 * so the browser `required` here is the courtesy layer, not the gate. Label
 * and link text come from the consent_label / terms_link_text settings; the
 * link target is the site's WP privacy page (plain text when none is set).
 *
 * @return string
 */
function mlsimport_property_lead_consent_field(): string {
	// Consent lead-in text (settings override, else a default phrase).
	$text = (string) mlsimport_standalone_option( 'consent_label' );
	if ( '' === $text ) {
		$text = __( 'I have read and agree to the', 'mlsimport' );
	}
	// Link text from settings; the target is the site's privacy page when set.
	$link_text  = (string) mlsimport_standalone_option( 'terms_link_text' );
	$policy_url = get_privacy_policy_url();
	$link       = '' !== $policy_url
		? '<a href="' . esc_url( $policy_url ) . '" target="_blank" rel="noopener">' . esc_html( $link_text ) . '</a>'
		: esc_html( $link_text );

	// Required checkbox + label; the server also enforces consent.
	return '<label class="mlsimport-property-lead-form__consent">'
		. '<input type="checkbox" name="mlsimport_consent" value="yes" required />'
		. '<span>' . esc_html( $text ) . ' ' . $link . '</span>'
		. '</label>';
}

/**
 * The badges beside the listing title: its Status, then what the listing IS —
 * the listing type (Residential), the property type (Single Family Residence) and
 * the sub-type when the feed carries one.
 *
 * Status is the loud badge; the type badges are quiet, because a visitor scanning
 * the page wants "is it still for sale" before "what kind of building is it". Feeds
 * routinely repeat themselves — Stellar sends "Residential" as BOTH the listing type
 * and the property type — so a value already shown is not shown twice.
 *
 * @param array $data Property view model (mlsimport_property_data()).
 * @return string
 */
function mlsimport_property_title_bar_chips( array $data ): string {
	$chips = '';
	// Case-insensitive de-dupe set so a repeated value is shown once.
	$seen  = array();

	// Status is the loud "accent" chip; the type chips are quiet "soft" ones.
	$badges = array(
		array( (string) ( $data['status'] ?? '' ), 'accent' ),
		array( (string) ( $data['listing_type'] ?? '' ), 'soft' ),
		array( (string) ( $data['property_type'] ?? '' ), 'soft' ),
		array( (string) ( $data['property_sub_type'] ?? '' ), 'soft' ),
	);

	/** Filter the title-bar badges: [ label, 'accent'|'soft' ] pairs. @since 6.4 */
	$badges = (array) apply_filters( 'mlsimport_property_title_bar_chips', $badges, $data );

	foreach ( $badges as $badge ) {
		$label = trim( (string) $badge[0] );
		$key   = strtolower( $label );
		// Skip blanks and any value already emitted.
		if ( '' === $label || isset( $seen[ $key ] ) ) {
			continue;
		}
		$seen[ $key ] = true;

		// Chip carrying its accent/soft modifier; the label links to its term archive.
		$chips .= '<span class="mlsimport-property-title-bar__chip mlsimport-property-title-bar__chip--' . esc_attr( $badge[1] ) . '">'
			. mlsimport_property_link_term( (int) ( $data['id'] ?? 0 ), $label )
			. '</span>';
	}

	return $chips;
}

/**
 * Title bar — the listing hero: status/type chips, title, address, the MLS#
 * /days-on-market/updated meta row, and the price block with share/save/print.
 *
 * @param int   $id   Property post ID.
 * @param array $args Behavioral options.
 * @return string
 */
function mlsimport_property_title_bar( int $id = 0, array $args = array() ): string {
	// Need at least a title or a price to justify the hero.
	$data = mlsimport_property_data( $id );
	if ( empty( $data ) || ( '' === $data['title'] && null === $data['price'] ) ) {
		return '';
	}

	// Left column: chips, title, address, meta.
	$left = '';

	// Status/type chips, when any resolve.
	$chips = mlsimport_property_title_bar_chips( $data );
	if ( '' !== $chips ) {
		$left .= '<div class="mlsimport-property-title-bar__chips">' . $chips . '</div>';
	}

	// Title heading, when present.
	if ( '' !== $data['title'] ) {
		$left .= '<h1 class="mlsimport-property-title-bar__title">' . esc_html( $data['title'] ) . '</h1>';
	}
	// Address line with a pin icon, when present.
	if ( '' !== $data['address'] ) {
		$left .= '<p class="mlsimport-property-title-bar__address">' . mlsimport_property_icon( 'pin' ) . '<span>' . esc_html( $data['address'] ) . '</span></p>';
	}

	// Meta row: MLS#, days-on-market, last-updated — each added only when present.
	$meta = '';
	if ( '' !== (string) $data['mls_id'] ) {
		$meta .= '<span class="mlsimport-property-title-bar__meta-item">' . mlsimport_property_icon( 'hash' ) . '<span>' . esc_html( sprintf( /* translators: %s: MLS id. */ __( 'MLS# %s', 'mlsimport' ), $data['mls_id'] ) ) . '</span></span>';
	}
	if ( null !== $data['days_on_market'] ) {
		/* translators: %d: days on market. */
		$meta .= '<span class="mlsimport-property-title-bar__meta-item">' . mlsimport_property_icon( 'clock' ) . '<span>' . esc_html( sprintf( _n( '%d day on market', '%d days on market', $data['days_on_market'], 'mlsimport' ), $data['days_on_market'] ) ) . '</span></span>';
	}
	if ( '' !== (string) $data['updated'] ) {
		$meta .= '<span class="mlsimport-property-title-bar__meta-item">' . mlsimport_property_icon( 'calendar' ) . '<span>' . esc_html( sprintf( /* translators: %s: date. */ __( 'Updated %s', 'mlsimport' ), $data['updated'] ) ) . '</span></span>';
	}
	// Wrap the meta items only when at least one exists.
	if ( '' !== $meta ) {
		$left .= '<div class="mlsimport-property-title-bar__meta">' . $meta . '</div>';
	}

	// Right column: price + actions.
	$right = '';
	if ( null !== $data['price'] ) {
		// Optional price-per-sqft line above the headline price.
		if ( null !== $data['price_per_sqft'] ) {
			$right .= '<p class="mlsimport-property-title-bar__psf">' . esc_html( mlsimport_format_price( $data['price_per_sqft'] ) ) . ' <span>/ ' . esc_html__( 'sqft', 'mlsimport' ) . '</span></p>';
		}
		$right .= '<p class="mlsimport-property-title-bar__price">' . esc_html( mlsimport_format_price( $data['price'] ) ) . '</p>';
	}

	// Action buttons: share (toggles a popup of share targets), favorite, print.
	$url = $data['permalink'];

	// Popup content: one link per network, then copy-link, as siblings of the button.
	$menu = '';
	foreach ( mlsimport_property_share_targets( $url, $data['title'] ) as $label => $href ) {
		$menu .= '<a class="mlsimport-property-share-menu__item" href="' . esc_url( $href ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label ) . '</a>';
	}
	$menu .= '<button type="button" class="mlsimport-property-share-menu__item" data-mlsimport-copy="' . esc_attr( $url ) . '"><span data-copy-label>' . esc_html__( 'Copy link', 'mlsimport' ) . '</span></button>';

	$actions  = '<div class="mlsimport-property-title-bar__share">';
	$actions .= '<button type="button" class="mlsimport-property-title-bar__action" data-mlsimport-share-toggle aria-expanded="false">' . mlsimport_property_icon( 'share' ) . '<span>' . esc_html__( 'Share', 'mlsimport' ) . '</span></button>';
	$actions .= '<div class="mlsimport-property-share-menu">' . $menu . '</div>';
	$actions .= '</div>';
	// Real, persisted favorite (shared store with the listing-card hearts). The
	// ListingKey is the durable identity: live/passthrough mode carries it on $data
	// (post id 0), stored mode resolves it from the post meta.
	$fav_key  = '' !== (string) ( $data['listing_key'] ?? '' ) ? (string) $data['listing_key'] : Mlsimport_Favorites::listing_key_for( (int) $data['id'] );
	$actions .= Mlsimport_Favorites::single_button_html( (int) $data['id'], $fav_key );
	$actions .= '<button type="button" class="mlsimport-property-title-bar__action" data-mlsimport-print="' . esc_attr( mlsimport_property_print_url( $url ) ) . '">' . mlsimport_property_icon( 'print' ) . '<span>' . esc_html__( 'Print', 'mlsimport' ) . '</span></button>';
	$right   .= '<div class="mlsimport-property-title-bar__actions">' . $actions . '</div>';

	// "Reduced from" line only when the original price was strictly higher.
	if ( null !== $data['original_price'] && null !== $data['price'] && (float) $data['original_price'] > (float) $data['price'] ) {
		$right .= '<p class="mlsimport-property-title-bar__reduced">' . mlsimport_property_icon( 'arrow-down' ) . '<span>' . esc_html( sprintf( /* translators: %s: original price. */ __( 'Reduced from %s', 'mlsimport' ), mlsimport_format_price( $data['original_price'] ) ) ) . '</span></p>';
	}

	// Two-column hero wrapper.
	$html  = mlsimport_property_section_open( 'title-bar' );
	$html .= '<div class="mlsimport-property-title-bar">';
	$html .= '<div class="mlsimport-property-title-bar__left">' . $left . '</div>';
	$html .= '<div class="mlsimport-property-title-bar__right">' . $right . '</div>';
	$html .= '</div>';
	$html .= mlsimport_property_section_close();
	return $html;
}

/**
 * In-page sub-navigation — a sticky bar of anchor links that jump to the
 * sections present on the page (only links whose target section exists render).
 *
 * @param int   $id   Property post ID.
 * @param array $args Behavioral options.
 * @return string
 */
function mlsimport_property_subnav( int $id = 0, array $args = array() ): string {
	$data = mlsimport_property_data( $id );
	if ( empty( $data ) ) {
		return '';
	}

	// label => anchor target id (the section's sanitized anchor). The field
	// sections are asked what they actually render, so the nav never offers a jump
	// link to a section that isn't on the page.
	$items = array_merge(
		array(
			__( 'Overview', 'mlsimport' )    => 'mlsimport-section-overview',
			__( 'Description', 'mlsimport' ) => 'mlsimport-section-description',
			__( 'Tour', 'mlsimport' )        => 'mlsimport-section-virtual-tour',
			__( 'Map', 'mlsimport' )         => 'mlsimport-section-map',
		),
		mlsimport_property_subnav_field_items( (int) $data['id'] ),
		array(
			__( 'Features', 'mlsimport' ) => 'mlsimport-section-features',
			__( 'Mortgage', 'mlsimport' ) => 'mlsimport-section-calculator',
			__( 'Agent', 'mlsimport' )    => 'mlsimport-section-agent',
		)
	);
	/** Filter the sub-nav items (label => anchor id). @since 6.4 */
	$items = (array) apply_filters( 'mlsimport_property_subnav_items', $items, $id );

	// One jump link per item (target is the section's anchor id).
	$links = '';
	foreach ( $items as $label => $target ) {
		$links .= '<a class="mlsimport-property-subnav__link" href="#' . esc_attr( $target ) . '" data-target="' . esc_attr( $target ) . '">' . esc_html( $label ) . '</a>';
	}
	// No links → no sub-nav.
	if ( '' === $links ) {
		return '';
	}

	// The strip carries the arrows. Fifteen section chips do not fit on a phone, so
	// the nav scrolls — and a scroll strip with no visible scrollbar needs a way to
	// say "there is more this way". The buttons are hidden (and the whole strip is
	// inert) whenever the chips already fit; see mlsimport-property-subnav.js.
	$html  = mlsimport_property_section_open( 'subnav' );
	$html .= '<div class="mlsimport-property-subnav__strip" data-subnav-strip>';
	$html .= '<button type="button" class="mlsimport-property-subnav__arrow mlsimport-property-subnav__arrow--prev" data-subnav-prev aria-label="' . esc_attr__( 'Scroll sections left', 'mlsimport' ) . '">' . mlsimport_property_icon( 'chevron-left' ) . '</button>';
	$html .= '<nav class="mlsimport-property-subnav" data-mlsimport-subnav aria-label="' . esc_attr__( 'Property sections', 'mlsimport' ) . '">' . $links . '</nav>';
	$html .= '<button type="button" class="mlsimport-property-subnav__arrow mlsimport-property-subnav__arrow--next" data-subnav-next aria-label="' . esc_attr__( 'Scroll sections right', 'mlsimport' ) . '">' . mlsimport_property_icon( 'chevron-right' ) . '</button>';
	$html .= '</div>';
	$html .= mlsimport_property_section_close();
	return $html;
}

/**
 * Booking sidebar — the sticky rail: agent mini, a Schedule-a-Tour / Ask-a-
 * Question tab pair (both post to the one lead endpoint), and a beds/baths/sqft
 * mini-stats card.
 *
 * @param int   $id   Property post ID.
 * @param array $args Behavioral options.
 * @return string
 */
function mlsimport_property_booking( int $id = 0, array $args = array() ): string {
	$data = mlsimport_property_data( $id );
	if ( empty( $data ) ) {
		return '';
	}

	// Resolved agent for the mini card and the "Call" shortcut (may be null).
	$agent = ! empty( $data['agent'] ) ? $data['agent'] : null;

	// Nonce + hidden inputs shared by both booking forms.
	$nonce  = wp_create_nonce( Mlsimport_Property_Lead::NONCE );
	$hidden = '<input type="text" name="mlsimport_hp" class="mlsimport-property-lead-form__hp" tabindex="-1" autocomplete="off" aria-hidden="true" />'
		. '<input type="hidden" name="property_id" value="' . esc_attr( (string) $data['id'] ) . '" />'
		. '<input type="hidden" name="nonce" value="' . esc_attr( $nonce ) . '" />';
	// Live listings have no post id — carry the ListingKey URL as the context.
	if ( '' !== ( $data['listing_key'] ?? '' ) ) {
		$hidden .= '<input type="hidden" name="mlsimport_listing" value="' . esc_attr( mlsimport_live_url( (string) $data['listing_key'] ) ) . '" />';
	}
	// Shared contact inputs reused by both panels.
	$contact = '<input type="text" name="mlsimport_name" required placeholder="' . esc_attr__( 'Full name', 'mlsimport' ) . '" />'
		. '<input type="email" name="mlsimport_email" required placeholder="' . esc_attr__( 'Email', 'mlsimport' ) . '" />'
		. '<input type="tel" name="mlsimport_phone" placeholder="' . esc_attr__( 'Phone', 'mlsimport' ) . '" />';

	// --- Tab nav ---
	$nav  = '<button type="button" class="mlsimport-property-booking__tab is-active" data-tab="tour" aria-selected="true">' . esc_html__( 'Schedule a Tour', 'mlsimport' ) . '</button>';
	$nav .= '<button type="button" class="mlsimport-property-booking__tab" data-tab="ask" aria-selected="false">' . esc_html__( 'Ask a Question', 'mlsimport' ) . '</button>';

	// --- Tour panel: day strip, time slots ---
	$base = function_exists( 'current_time' ) ? (int) current_time( 'timestamp' ) : time(); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- local-day strip for display.
	$days = '';
	// A month of days; the strip is a slider, so the arrows page through them.
	for ( $i = 0; $i < 30; $i++ ) {
		// This day's timestamp and its display parts (dow / day-num / month).
		$ts     = $base + $i * DAY_IN_SECONDS;
		$iso    = date_i18n( 'Y-m-d', $ts );
		$dow    = date_i18n( 'D', $ts );
		$dnum   = date_i18n( 'd', $ts );
		$mon    = date_i18n( 'M', $ts );
		$days  .= '<button type="button" class="mlsimport-property-booking__day' . ( 0 === $i ? ' is-active' : '' ) . '" data-day="' . esc_attr( $iso ) . '" data-label="' . esc_attr( $dow . ' ' . $dnum . ' ' . $mon ) . '">'
			. '<span class="mlsimport-property-booking__day-dow">' . esc_html( $dow ) . '</span>'
			. '<span class="mlsimport-property-booking__day-num">' . esc_html( $dnum ) . '</span>'
			. '<span class="mlsimport-property-booking__day-mon">' . esc_html( $mon ) . '</span>'
			. '</button>';
	}

	// Time slots are admin-configurable (Standalone settings → Property Page → Tour
	// Details), stored as a comma-separated list. Blank means the admin does not
	// offer fixed slots, so the whole picker is left out.
	$slots = array_filter( array_map( 'trim', explode( ',', (string) mlsimport_standalone_option( 'tour_times' ) ) ), 'strlen' );
	// One time-slot button per slot; the first starts active.
	$times = '';
	foreach ( array_values( $slots ) as $i => $slot ) {
		$times .= '<button type="button" class="mlsimport-property-booking__time' . ( 0 === $i ? ' is-active' : '' ) . '" data-time="' . esc_attr( $slot ) . '">' . esc_html( $slot ) . '</button>';
	}

	// --- Tour panel: day/time pickers + contact form ---
	$tour  = '<div class="mlsimport-property-booking__panel is-active" data-panel="tour">';
	$tour .= '<form class="mlsimport-property-lead-form" data-mlsimport-lead data-success-title="' . esc_attr__( 'Tour requested', 'mlsimport' ) . '" method="post">';
	// The day strip scrolls as a slider; the arrows sit on the label's own line.
	$tour .= '<div class="mlsimport-property-booking__picker-head">';
	$tour .= '<p class="mlsimport-property-booking__field-label">' . esc_html__( 'Select a day', 'mlsimport' ) . '</p>';
	$tour .= '<div class="mlsimport-property-booking__days-nav">';
	$tour .= '<button type="button" class="mlsimport-property-booking__arrow" data-days-prev aria-label="' . esc_attr__( 'Previous days', 'mlsimport' ) . '">' . mlsimport_property_icon( 'chevron-left' ) . '</button>';
	$tour .= '<button type="button" class="mlsimport-property-booking__arrow" data-days-next aria-label="' . esc_attr__( 'Next days', 'mlsimport' ) . '">' . mlsimport_property_icon( 'chevron-right' ) . '</button>';
	$tour .= '</div>';
	$tour .= '</div>';
	$tour .= '<div class="mlsimport-property-booking__days" data-days>' . $days . '</div>';
	if ( '' !== $times ) {
		$tour .= '<p class="mlsimport-property-booking__field-label">' . esc_html__( 'Preferred time', 'mlsimport' ) . '</p>';
		$tour .= '<div class="mlsimport-property-booking__times">' . $times . '</div>';
	}
	$tour .= $contact;
	// JS composes "<day> at <time> (<mode>)" into this field; the lead email reads it.
	$tour .= '<input type="hidden" name="mlsimport_tour_date" data-tour-summary value="" />';
	$tour .= mlsimport_property_lead_consent_field();
	$tour .= $hidden;
	$tour .= '<button type="submit">' . esc_html__( 'Request This Tour', 'mlsimport' ) . '</button>';
	$tour .= '<p class="mlsimport-property-booking__note">' . mlsimport_property_icon( 'badge' ) . '<span>' . esc_html__( 'Free tour, no obligation — cancel anytime.', 'mlsimport' ) . '</span></p>';
	$tour .= '<div class="mlsimport-property-lead-form__status" role="status" aria-live="polite"></div>';
	$tour .= '</form>';
	$tour .= mlsimport_property_booking_success( __( 'Tour requested', 'mlsimport' ), __( 'The agent will confirm your tour time shortly.', 'mlsimport' ) );
	$tour .= '</div>';

	// --- Ask panel ---
	$ask  = '<div class="mlsimport-property-booking__panel" data-panel="ask" hidden>';
	$ask .= '<form class="mlsimport-property-lead-form" data-mlsimport-lead data-success-title="' . esc_attr__( 'Message sent', 'mlsimport' ) . '" method="post">';
	$ask .= $contact;
	$ask .= '<textarea name="mlsimport_message" rows="3" placeholder="' . esc_attr__( 'Message', 'mlsimport' ) . '">' . esc_textarea( sprintf( /* translators: %s: listing title. */ __( "Hello, I'm interested in %s", 'mlsimport' ), $data['title'] ) ) . '</textarea>';
	$ask .= mlsimport_property_lead_interest_field();
	$ask .= mlsimport_property_lead_consent_field();
	$ask .= $hidden;
	$ask .= '<button type="submit">' . esc_html__( 'Send Message', 'mlsimport' ) . '</button>';
	// A one-tap call shortcut when the agent has a phone.
	if ( $agent && '' !== (string) $agent['phone'] ) {
		$tel  = preg_replace( '/[^0-9+]/', '', (string) $agent['phone'] );
		$ask .= '<a class="mlsimport-property-booking__call" href="tel:' . esc_attr( $tel ) . '">' . mlsimport_property_icon( 'phone' ) . '<span>' . esc_html__( 'Call', 'mlsimport' ) . '</span></a>';
	}
	$ask .= '<div class="mlsimport-property-lead-form__status" role="status" aria-live="polite"></div>';
	$ask .= '</form>';
	$ask .= mlsimport_property_booking_success( __( 'Message sent', 'mlsimport' ), __( 'The agent will get back to you shortly.', 'mlsimport' ) );
	$ask .= '</div>';

	// Both panels sit inside the sticky card.
	$panels = $tour . $ask;

	// Headingless wrapper + the booking card.
	$html  = mlsimport_property_section_open( 'booking' );
	$html .= '<div class="mlsimport-property-booking" data-mlsimport-booking>';
	$html .= '<div class="mlsimport-property-booking__card">';

	// Agent mini (avatar + name), only when an agent with a name resolved. A
	// feed-sourced agent's name may not appear on the contact form (#181) — the
	// company name from Social & Contact fronts the card instead.
	$card_name = $agent ? ( ! empty( $agent['is_feed'] ) ? (string) mlsimport_standalone_option( 'company_name', '' ) : (string) $agent['name'] ) : '';
	$card_role = ( $agent && ! empty( $agent['is_feed'] ) ) ? __( 'Contact', 'mlsimport' ) : __( 'Listing Agent', 'mlsimport' );
	if ( $agent && '' !== $card_name ) {
		$avatar = ( empty( $agent['is_feed'] ) && ! empty( $agent['photo_id'] ) )
			? wp_get_attachment_image( (int) $agent['photo_id'], 'thumbnail', false, array( 'class' => 'mlsimport-property-booking__avatar-img' ) )
			: '<span class="mlsimport-property-booking__initials">' . esc_html( mlsimport_property_initials( $card_name ) ) . '</span>';
		$html  .= '<div class="mlsimport-property-booking__agent">'
			. '<span class="mlsimport-property-booking__avatar">' . $avatar . '</span>'
			. '<span class="mlsimport-property-booking__agent-text">'
			. '<span class="mlsimport-property-booking__agent-name">' . esc_html( $card_name ) . '</span>'
			. '<span class="mlsimport-property-booking__agent-role">' . esc_html( $card_role ) . '</span>'
			. '</span></div>';
	}

	$html .= '<div class="mlsimport-property-booking__tabs" role="tablist">' . $nav . '</div>';
	$html .= $panels;
	$html .= '</div>'; // card.

	$html .= '</div>'; // booking.
	$html .= mlsimport_property_section_close();
	return $html;
}

/**
 * The success confirmation block a booking form swaps in after a sent lead
 * (revealed by mlsimport-property-lead.js when the form's request succeeds).
 *
 * @param string $title Headline (e.g. "Tour requested").
 * @param string $text  Sub-text.
 * @return string
 */
function mlsimport_property_booking_success( string $title, string $text ): string {
	return '<div class="mlsimport-property-booking__success" data-lead-success hidden>'
		. '<span class="mlsimport-property-booking__success-icon" aria-hidden="true">' . mlsimport_property_icon( 'check' ) . '</span>'
		. '<p class="mlsimport-property-booking__success-title">' . esc_html( $title ) . '</p>'
		. '<p class="mlsimport-property-booking__success-text">' . esc_html( $text ) . '</p>'
		. '</div>';
}

/**
 * The admin's MLS disclaimer, resolved for one listing.
 *
 * The wording is mandated by the MLS and identical on every property, so it is
 * authored once in Property Page -> MLS Attribution. Variables make the one text
 * serve every listing: %mls_id% (this listing's MLS number), %year% (so a
 * copyright line never goes stale), %agent_phone% / %agent_email% for the boards
 * that require the listing agent to be reachable here, and %office_phone% /
 * %office_email% / %attribution_contact% for the ones that require the listing
 * office instead. The agent's name and the office name are not variables — the
 * block prints those itself. Blank text prints nothing; to drop the whole block,
 * disable the MLS Attribution section in the page layout.
 *
 * @param string $mls_id This listing's MLS number.
 * @param int    $id     Property post ID; 0 leaves the contact variables empty.
 * @return string Paragraph markup, already sanitized. Safe to echo unescaped.
 */
function mlsimport_property_attribution_text( string $mls_id = '', int $id = 0 ): string {
	// The admin-authored template; blank means print nothing.
	$raw = (string) mlsimport_standalone_option( 'attribution_text' );
	if ( '' === trim( $raw ) ) {
		return '';
	}

	// Contact channels only: the agent's name and the office name already print
	// in the block's own courtesy and facts lines, so offering them as variables
	// too would just let a disclaimer repeat what is directly above it.
	// These read the property's OWN feed meta, exactly like that courtesy line
	// (#169) — never the company contacts #181 substitutes on the agent card, and
	// never a manually picked agent, so a board's mandated wording always reaches
	// the agent the MLS actually sent. A field that was never ticked for import
	// resolves to '', the same way %mls_id% already does for a listing with no
	// MLS number.
	$feed = static function ( string $key ) use ( $id ) {
		return $id ? (string) get_post_meta( $id, 'mlsimport_' . $key, true ) : '';
	};

	// Substitute every placeholder the admin may have used. The office channels
	// carry no #181 privacy question — a brokerage line is a business contact, not
	// a person's — and AttributionContact is the RESO field a board names when it
	// mandates one specific display contact, so it gets its own token rather than
	// silently standing in for an empty %office_phone%.
	$text = strtr(
		$raw,
		array(
			'%mls_id%'              => $mls_id,
			'%year%'                => date_i18n( 'Y' ),
			'%agent_phone%'         => $feed( 'ListAgentPreferredPhone' ),
			'%agent_email%'         => $feed( 'ListAgentEmail' ),
			'%office_phone%'        => $feed( 'ListOfficePhone' ),
			'%office_email%'        => $feed( 'ListOfficeEmail' ),
			'%attribution_contact%' => $feed( 'AttributionContact' ),
		)
	);

	/** Filter the resolved MLS disclaimer text (pre-markup). @since 6.4 */
	$text = (string) apply_filters( 'mlsimport_property_attribution_text', $text, $mls_id );

	// Sanitize then paragraph-wrap for output.
	return wpautop( wp_kses_post( $text ) );
}

/**
 * MLS / IDX attribution — the listing-courtesy line plus the admin's mandated
 * disclaimer.
 *
 * @param int   $id   Property post ID.
 * @param array $args Behavioral options.
 * @return string
 */
function mlsimport_property_attribution( int $id = 0, array $args = array() ): string {
	$data = mlsimport_property_data( $id );
	if ( empty( $data ) ) {
		return '';
	}

	// Legal attribution names the FEED listing office/agent, never the (possibly
	// manually overridden) display agent — feed_* is the property's own meta (#169).
	$agent  = ! empty( $data['agent'] ) ? $data['agent'] : array();
	$office = isset( $agent['feed_office'] ) ? (string) $agent['feed_office'] : '';
	$name   = isset( $agent['feed_name'] ) ? (string) $agent['feed_name'] : '';
	$phone  = isset( $agent['feed_phone'] ) ? (string) $agent['feed_phone'] : '';

	// "Listing courtesy of <office>" line, when the feed office is known.
	$courtesy = '';
	if ( '' !== $office ) {
		$courtesy = sprintf( /* translators: %s: listing office name. */ __( 'Listing courtesy of %s', 'mlsimport' ), $office );
	}

	// Meta facts: MLS#, feed listing agent, agent preferred phone, last-updated —
	// each added when present.
	$facts = array();
	if ( '' !== (string) $data['mls_id'] ) {
		$facts[] = sprintf( /* translators: %s: MLS id. */ __( 'MLS# %s', 'mlsimport' ), $data['mls_id'] );
	}
	if ( '' !== $name ) {
		$facts[] = sprintf( /* translators: %s: listing agent name. */ __( 'Listing agent %s', 'mlsimport' ), $name );
	}
	// The feed agent's preferred phone (RESO ListAgentPreferredPhone), straight
	// after the name so the line reads "Listing agent <name> · Phone <phone>".
	if ( '' !== $phone ) {
		$facts[] = sprintf( /* translators: %s: listing agent phone number. */ __( 'Phone %s', 'mlsimport' ), $phone );
	}
	if ( '' !== (string) $data['updated'] ) {
		$facts[] = sprintf( /* translators: %s: date. */ __( 'Data last updated %s', 'mlsimport' ), $data['updated'] );
	}

	// Neither a courtesy line nor any facts → no section.
	if ( '' === $courtesy && empty( $facts ) ) {
		return '';
	}

	// The mandated disclaimer (already markup) and the optional MLS logo.
	$disclaimer = mlsimport_property_attribution_text( (string) $data['mls_id'], (int) $data['id'] );
	$logo       = function_exists( 'mlsimport_standalone_mls_logo_url' ) ? mlsimport_standalone_mls_logo_url() : '';

	// The section wrapper already carries the .mlsimport-property-attribution
	// class (via the 'attribution' slug), which supplies the box chrome. A
	// second inner wrapper with the same class produced a border-in-a-border,
	// so the content sits directly in the section body.
	$html = mlsimport_property_section_open( 'attribution' );
	// Head row (logo + courtesy line), only when either is present.
	if ( '' !== $logo || '' !== $courtesy ) {
		$html .= '<div class="mlsimport-property-attribution__head">';
		if ( '' !== $logo ) {
			$html .= '<img class="mlsimport-property-attribution__logo" src="' . esc_url( $logo ) . '" alt="' . esc_attr__( 'MLS', 'mlsimport' ) . '" />';
		}
		if ( '' !== $courtesy ) {
			$html .= '<span class="mlsimport-property-attribution__courtesy">' . esc_html( $courtesy ) . '</span>';
		}
		$html .= '</div>';
	}
	// The facts joined into one meta line.
	$html .= '<p class="mlsimport-property-attribution__meta">' . esc_html( implode( ' · ', $facts ) ) . '</p>';
	// The mandated disclaimer, when set.
	if ( '' !== $disclaimer ) {
		// Already wp_kses_post'd in the resolver; escaping again would print the tags.
		$html .= '<div class="mlsimport-property-attribution__disclaimer">' . $disclaimer . '</div>';
	}
	$html .= mlsimport_property_section_close();
	return $html;
}
