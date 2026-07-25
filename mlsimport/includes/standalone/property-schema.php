<?php
/**
 * Standalone (theme_id 990) single-property JSON-LD schema.
 *
 * ONE consolidated graph built from the Property view model and emitted once in
 * wp_head — not fragmented per section, so it stays valid no matter which
 * sections were placed (build plan, decision 7). The array builder
 * mlsimport_property_schema_from_vm() is pure (no WordPress, no DB) so it is
 * unit-testable in isolation.
 *
 * The graph has two nodes because RealEstateListing descends from WebPage, not
 * from Place: it is the advert, not the building. Physical facts (address, geo,
 * bed/bath counts, floorSize, yearBuilt) are out of WebPage's domain and belong
 * on a companion Accommodation node, joined to the listing via mainEntity/@id.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/property-sections.php';

/**
 * Build the JSON-LD schema array from a property view model. Pure + DB-free.
 *
 * @param array  $vm       Property view model (mlsimport_property_data()).
 * @param string $currency ISO 4217 currency code for the offer.
 * @return array Schema array (empty when there is nothing to describe).
 */
function mlsimport_property_schema_from_vm( array $vm, string $currency = 'USD' ): array {
	if ( empty( $vm ) ) {
		return array();
	}

	// Node ids are fragments of the listing URL so the two nodes stay linkable
	// even once this graph is merged with markup emitted by a theme or an SEO
	// plugin. Without a permalink they are still unique within the document.
	$base    = ! empty( $vm['permalink'] ) ? (string) $vm['permalink'] : '';
	$listing = array(
		'@type' => 'RealEstateListing',
		'@id'   => $base . '#listing',
	);

	if ( ! empty( $vm['title'] ) ) {
		$listing['name'] = $vm['title'];
	}
	if ( '' !== $base ) {
		$listing['url'] = $base;
	}

	$description = '';
	if ( ! empty( $vm['excerpt'] ) ) {
		$description = trim( (string) $vm['excerpt'] );
	} elseif ( ! empty( $vm['content'] ) ) {
		$description = trim( strip_tags( (string) $vm['content'] ) );
	}
	if ( '' !== $description ) {
		$listing['description'] = $description;
	}

	if ( ! empty( $vm['image_url'] ) ) {
		$listing['image'] = $vm['image_url'];
	}

	// Offer.
	if ( isset( $vm['price'] ) && null !== $vm['price'] ) {
		$listing['offers'] = array(
			'@type'         => 'Offer',
			'price'         => (float) $vm['price'],
			'priceCurrency' => $currency,
		);
	}

	// Listing date. One of only two properties RealEstateListing defines itself,
	// and it wants raw ISO-8601 — not the display-formatted $vm['updated'].
	if ( ! empty( $vm['list_date'] ) ) {
		$listing['datePosted'] = (string) $vm['list_date'];
	}

	// Listing agent. It hangs from `provider` (inherited from CreativeWork:
	// "the party providing this item") and NOT from `broker` — broker is defined
	// on Order/Invoice/Reservation and is invalid on RealEstateListing, which
	// descends from WebPage.
	if ( ! empty( $vm['agent']['name'] ) ) {
		// A feed-sourced agent's vm carries the COMPANY contacts (#181) — those
		// belong to the office, not to this RealEstateAgent node, so the node
		// stays contact-less; only a local agent's own channels are emitted.
		$is_feed = ! empty( $vm['agent']['is_feed'] );
		$listing['provider'] = array_filter(
			array(
				'@type'     => 'RealEstateAgent',
				'name'      => (string) $vm['agent']['name'],
				'email'     => ( ! $is_feed && ! empty( $vm['agent']['email'] ) ) ? (string) $vm['agent']['email'] : '',
				'telephone' => ( ! $is_feed && ! empty( $vm['agent']['phone'] ) ) ? (string) $vm['agent']['phone'] : '',
			),
			'strlen'
		);
	}

	$residence = mlsimport_property_schema_residence_node( $vm, $base );
	if ( ! empty( $residence ) ) {
		$listing['mainEntity'] = array( '@id' => $residence['@id'] );
	}

	$graph = array( $listing );
	if ( ! empty( $residence ) ) {
		$graph[] = $residence;
	}

	return array(
		'@context' => 'https://schema.org',
		'@graph'   => $graph,
	);
}

/**
 * The Accommodation node describing the dwelling itself: everything that is a
 * fact about the building rather than about the advert.
 *
 * @param array  $vm   Property view model.
 * @param string $base Listing URL, used as the @id base.
 * @return array Node array, or empty when the feed said nothing physical.
 */
function mlsimport_property_schema_residence_node( array $vm, string $base ): array {
	$node = array(
		'@type' => mlsimport_property_schema_accommodation_type( $vm ),
		'@id'   => $base . '#residence',
	);

	// The street line only — addressLocality/Region/postalCode carry the rest,
	// so reusing the composed one-line address here would duplicate them. Feeds
	// with no parsed street parts fall back to whatever address we could build.
	$street = '';
	if ( ! empty( $vm['street'] ) ) {
		$street = (string) $vm['street'];
	} elseif ( ! empty( $vm['address'] ) ) {
		$street = (string) $vm['address'];
	}

	$address = array_filter(
		array(
			'@type'           => 'PostalAddress',
			'streetAddress'   => $street,
			'addressLocality' => ! empty( $vm['city'] ) ? (string) $vm['city'] : '',
			'addressRegion'   => ! empty( $vm['state'] ) ? (string) $vm['state'] : '',
			'postalCode'      => ! empty( $vm['zip'] ) ? (string) $vm['zip'] : '',
		),
		'strlen'
	);
	if ( count( $address ) > 1 ) {
		$node['address'] = $address;
	}

	if ( isset( $vm['latitude'] ) && null !== $vm['latitude'] && isset( $vm['longitude'] ) && null !== $vm['longitude'] ) {
		$node['geo'] = array(
			'@type'     => 'GeoCoordinates',
			'latitude'  => (float) $vm['latitude'],
			'longitude' => (float) $vm['longitude'],
		);
	}

	if ( isset( $vm['bedrooms'] ) && null !== $vm['bedrooms'] ) {
		$node['numberOfBedrooms'] = (float) $vm['bedrooms'];
	}
	if ( isset( $vm['bathrooms'] ) && null !== $vm['bathrooms'] ) {
		$node['numberOfBathroomsTotal'] = (float) $vm['bathrooms'];
	}
	if ( isset( $vm['living_area'] ) && null !== $vm['living_area'] ) {
		$node['floorSize'] = array(
			'@type'    => 'QuantitativeValue',
			'value'    => (float) $vm['living_area'],
			'unitCode' => 'FTK', // Square foot.
		);
	}
	if ( isset( $vm['year_built'] ) && null !== $vm['year_built'] ) {
		$node['yearBuilt'] = (int) $vm['year_built'];
	}

	// Only @type and @id: the feed gave us nothing about the building.
	return count( $node ) > 2 ? $node : array();
}

/**
 * Pick the schema.org Accommodation subtype for a listing. Anything we cannot
 * confidently place stays on the generic parent rather than being mislabelled
 * as a house.
 *
 * @param array $vm Property view model.
 * @return string Schema.org type name.
 */
function mlsimport_property_schema_accommodation_type( array $vm ): string {
	$haystack = strtolower( (string) ( $vm['property_sub_type'] ?? '' ) . ' ' . (string) ( $vm['property_type'] ?? '' ) );

	foreach ( array(
		'Apartment'             => array( 'condo', 'apartment', 'co-op', 'coop', 'flat' ),
		'House'                 => array( 'townhouse', 'town house', 'duplex', 'villa' ),
		'SingleFamilyResidence' => array( 'single family', 'singlefamily', 'residential', 'house', 'detached' ),
	) as $type => $needles ) {
		foreach ( $needles as $needle ) {
			if ( false !== strpos( $haystack, $needle ) ) {
				return $type;
			}
		}
	}

	return 'Accommodation';
}

/**
 * Build the schema array for a property by id.
 *
 * @param int $id Property post ID (0 = current loop post).
 * @return array
 */
function mlsimport_property_schema( int $id = 0 ): array {
	$vm     = mlsimport_property_data( $id );
	$schema = mlsimport_property_schema_from_vm( $vm );

	/**
	 * Filter the assembled single-property JSON-LD schema array.
	 *
	 * @param array $schema Schema array.
	 * @param array $vm     Property view model.
	 */
	return (array) apply_filters( 'mlsimport_property_schema', $schema, $vm );
}

/**
 * The schema as a ready-to-print <script type="application/ld+json"> tag.
 *
 * @param int $id Property post ID.
 * @return string Script tag, or '' when there is nothing to emit.
 */
function mlsimport_property_schema_script( int $id = 0 ): string {
	$schema = mlsimport_property_schema( $id );
	if ( empty( $schema ) ) {
		return '';
	}
	return '<script type="application/ld+json">'
		. wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		. '</script>' . "\n";
}

/**
 * wp_head hook: emit the consolidated schema on a single property view.
 *
 * Covers both modes. In live passthrough there is no post — the route has
 * already stashed the fetched RESO record, and id 0 makes
 * mlsimport_property_data() short-circuit to it through the
 * mlsimport_property_data_pre seam.
 *
 * @return void
 */
function mlsimport_property_print_schema(): void {
	if ( is_singular( 'mlsimport_property' ) ) {
		$id = (int) get_the_ID();
	} elseif ( function_exists( 'mlsimport_live_current_record' ) && null !== mlsimport_live_current_record() ) {
		$id = 0;
	} else {
		return;
	}

	echo mlsimport_property_schema_script( $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-LD; wp_json_encode escapes content.
}
