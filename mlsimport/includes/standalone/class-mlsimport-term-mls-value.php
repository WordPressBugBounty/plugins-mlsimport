<?php
/**
 * Standalone (theme_id 990): the MLS value a category term stands for (issue #355).
 *
 * The sync creates a term for every value the MLS sends in PropertyType,
 * PropertySubType, StandardStatus, City, StateOrProvince and PostalCode, named
 * like the value. Site owners want their own names: "For Sale" instead of
 * "Residential", "Condos" instead of "Condominium". Before this class the sync
 * looked the term up by name, so a renamed term (name and slug) was lost and
 * a second term named like the MLS value appeared on the next run.
 *
 * The rule: every term the sync creates remembers the MLS value it came from,
 * in term meta. The sync and the search use that stored value and never the
 * name or the slug, so the owner may rename a term freely.
 *
 *   term_id()        the term holding an MLS value, for the sync.
 *   column_values()  the stored MLS values behind submitted term names or
 *                    slugs, for the fast-column IN of the listings query.
 *
 * A term that predates the feature carries no stored value yet: it is found by
 * its name (the MLS value, as the sync named it) and stamped on first use, so
 * nothing changes on upgrade and no migration runs.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stored MLS value of a term: lookup for the sync, translation for the query.
 */
class Mlsimport_Term_Mls_Value {

	/** Term meta key holding the MLS value the term was created from. */
	const META = 'mlsimport_mls_value';

	/**
	 * The taxonomies whose terms are matched through the stored MLS value: the
	 * ones backed by a fast listings column (§7). The other taxonomies (area,
	 * county, features, ...) are matched by term relationship, so a rename
	 * never breaks them and they need no stored value.
	 */
	const TAXONOMIES = array(
		'mlsimport_listing_type',
		'mlsimport_property_type',
		'mlsimport_status',
		'mlsimport_city',
		'mlsimport_state',
		'mlsimport_zip',
	);

	/**
	 * Whether a taxonomy's terms are matched through the stored MLS value.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return bool
	 */
	public static function applies( string $taxonomy ): bool {
		return in_array( $taxonomy, self::TAXONOMIES, true );
	}

	/**
	 * The term that stands for one MLS value, created when none does.
	 *
	 * Step by step:
	 * 1. A term already stamped with this value wins, whatever its name is now.
	 * 2. Else a term named (or slugged) like the value, the way the sync named
	 *    it before this class existed: it is stamped so step 1 finds it next time.
	 * 3. Else a new term named like the value, stamped at birth.
	 *
	 * @param string $taxonomy  Taxonomy slug.
	 * @param string $mls_value The raw value the MLS sent.
	 * @return int Term id, or 0 when the value is empty or the term cannot be made.
	 */
	public static function term_id( string $taxonomy, string $mls_value ): int {
		$mls_value = trim( $mls_value );
		if ( '' === $mls_value ) {
			return 0;
		}

		// Step 1: the term stamped with this value.
		$stamped = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'ids',
				'number'     => 1,
				'meta_key'   => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $mls_value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		if ( is_array( $stamped ) && ! empty( $stamped ) ) {
			return (int) $stamped[0];
		}

		// Step 2: a term named like the value (pre-feature term), stamped now.
		$existing = term_exists( $mls_value, $taxonomy );
		$term_id  = is_array( $existing ) ? (int) $existing['term_id'] : (int) $existing;

		// Step 3: no term at all, make one named like the value.
		if ( 0 === $term_id ) {
			$created = wp_insert_term( $mls_value, $taxonomy );
			if ( is_wp_error( $created ) ) {
				return 0;
			}
			$term_id = (int) $created['term_id'];
		}

		update_term_meta( $term_id, self::META, $mls_value );
		return $term_id;
	}

	/**
	 * One-time upgrade sweep: stamp every term of the fast-column taxonomies
	 * that has no stored value yet with its current name, the MLS value the
	 * sync named it after. Without it an owner who renames a term right after
	 * the upgrade, before any sync touched it, would lose it on the next run.
	 *
	 * @return int Number of terms stamped (0 on a stamped site).
	 */
	public static function stamp_existing(): int {
		$stamped = 0;
		foreach ( self::TAXONOMIES as $taxonomy ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
				)
			);
			foreach ( ( is_array( $terms ) ? $terms : array() ) as $term ) {
				// A term that already remembers its value is left alone.
				if ( '' !== (string) get_term_meta( $term->term_id, self::META, true ) ) {
					continue;
				}
				update_term_meta( $term->term_id, self::META, (string) $term->name );
				++$stamped;
			}
		}
		return $stamped;
	}

	/**
	 * Run stamp_existing() once per site, guarded by an option so the term
	 * sweep does not repeat on every admin_init.
	 *
	 * @return void
	 */
	public static function maybe_stamp_existing(): void {
		if ( get_option( 'mlsimport_term_mls_values_stamped' ) ) {
			return;
		}
		self::stamp_existing();
		update_option( 'mlsimport_term_mls_values_stamped', '1' );
	}

	/**
	 * The stored MLS values behind submitted term names or slugs, for the fast
	 * column IN of the listings query.
	 *
	 * Step by step:
	 * 1. Load the taxonomy's terms once (WordPress caches them) and index the
	 *    stored value of each by its name and by its slug. A term with no
	 *    stored value (pre-feature) stands for its own name.
	 * 2. A submitted value that is a term name or slug becomes that term's
	 *    stored value; anything else passes through unchanged, so a block
	 *    preset or saved search holding the raw MLS value keeps working.
	 *
	 * @param string   $taxonomy  Taxonomy slug.
	 * @param string[] $submitted Term names or slugs, as the form or a block sent them.
	 * @return string[] The values to match the fast column against, in the same order.
	 */
	public static function column_values( string $taxonomy, array $submitted ): array {
		// Step 1: name/slug => stored MLS value, for the whole taxonomy.
		$by_key = array();
		$terms  = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);
		foreach ( ( is_array( $terms ) ? $terms : array() ) as $term ) {
			$stored = (string) get_term_meta( $term->term_id, self::META, true );
			$value  = '' !== $stored ? $stored : (string) $term->name;

			$by_key[ (string) $term->name ] = $value;
			$by_key[ (string) $term->slug ] = $value;
		}

		// Step 2: translate what is known, keep the rest.
		$out = array();
		foreach ( $submitted as $value ) {
			$value = (string) $value;
			$out[] = isset( $by_key[ $value ] ) ? $by_key[ $value ] : $value;
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Translate every fast-column taxonomy filter of a listings query (listing
	 * type, property type, status, city, state, zip) from term names or slugs to
	 * stored MLS values. The one place every search surface passes through:
	 * form, term archive, block presets, pagination, saved searches.
	 *
	 * @param array $params Consumer filter params.
	 * @return array The params with those filters translated.
	 */
	public static function translate_params( array $params ): array {
		if ( ! class_exists( 'Mlsimport_Page_Block_Search_Fields' ) ) {
			return $params;
		}
		// Walk the search-field catalog: a taxonomy field matched by the fast
		// column carries term names; term-join fields carry slugs and are left alone.
		foreach ( Mlsimport_Page_Block_Search_Fields::catalog() as $key ) {
			$def = Mlsimport_Page_Block_Search_Fields::definition( $key );
			if ( null === $def || 'taxonomy' !== $def['group'] || 'column-in' !== $def['match'] || ! isset( $params[ $key ] ) ) {
				continue;
			}
			$params[ $key ] = self::column_values( (string) $def['tax'], array_values( (array) $params[ $key ] ) );
		}
		return $params;
	}
}
