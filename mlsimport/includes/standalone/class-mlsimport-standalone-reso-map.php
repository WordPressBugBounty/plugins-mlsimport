<?php
/**
 * Standalone (theme_id 990) RESO -> target routing map.
 *
 * The theme-independent analog of help_functions.php: given a RESO field name,
 * return where its raw value should be written. Target kinds:
 *   column:<name>   flat mlsimport_listings column
 *   tax:<taxonomy>  a term in the given taxonomy
 *   meta:<key>      post meta mlsimport_<key>
 *   feature:<label> a term in the mlsimport_feature taxonomy
 *   content         the post body
 *   skip            never written
 *
 * Pure data + lookup. No WordPress, no DB.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves a RESO field name to its standalone-mode write targets.
 */
class Mlsimport_Standalone_Reso_Map {

	/**
	 * RESO field => write targets (§9). Fields absent here pass through to
	 * meta:x_<Field>. Editorial taxonomies (mlsimport_label, featured) are
	 * deliberately NOT in the map — the writer never sets them.
	 */
	private const MAP = array(
		// Identity / sync.
		'ListingKey'                     => array( 'column:listing_key', 'meta:ListingKey' ),
		'ListingId'                      => array( 'meta:ListingId' ),
		'ModificationTimestamp'          => array( 'column:modification_timestamp', 'meta:ModificationTimestamp' ),
		'MlsStatus'                      => array( 'meta:MlsStatus' ),
		'InternetEntireListingDisplayYN' => array( 'meta:idx_display' ),
		'InternetAddressDisplayYN'       => array( 'meta:show_address' ),

		// Price.
		'ListPrice'                      => array( 'column:price', 'meta:ListPrice' ),
		'OriginalListPrice'              => array( 'meta:OriginalListPrice' ),
		'ClosePrice'                     => array( 'meta:ClosePrice' ),
		'PreviousListPrice'              => array( 'meta:PreviousListPrice' ),

		// Beds / baths.
		'BedroomsTotal'                  => array( 'column:bedrooms', 'meta:BedroomsTotal' ),
		'BathroomsTotalDecimal'          => array( 'column:bathrooms', 'meta:BathroomsTotalDecimal' ),
		'BathroomsFull'                  => array( 'meta:BathroomsFull' ),
		'BathroomsHalf'                  => array( 'meta:BathroomsHalf' ),

		// Size / structure.
		'LivingArea'                     => array( 'column:living_area', 'meta:LivingArea' ),
		'LivingAreaUnits'                => array( 'meta:LivingAreaUnits' ),
		'LotSizeSquareFeet'              => array( 'column:lot_size', 'meta:LotSizeSquareFeet' ),
		'LotSizeAcres'                   => array( 'meta:LotSizeAcres' ),
		'YearBuilt'                      => array( 'column:year_built', 'meta:YearBuilt' ),
		'GarageSpaces'                   => array( 'column:garage_spaces', 'meta:GarageSpaces' ),
		'StoriesTotal'                   => array( 'column:stories', 'meta:StoriesTotal' ),

		// Location.
		'City'                           => array( 'column:city', 'tax:mlsimport_city' ),
		'StateOrProvince'                => array( 'column:state', 'tax:mlsimport_state' ),
		'CountyOrParish'                 => array( 'tax:mlsimport_county', 'meta:CountyOrParish' ),
		'PostalCode'                     => array( 'column:zip', 'tax:mlsimport_zip', 'meta:PostalCode' ),
		'CityRegion'                     => array( 'tax:mlsimport_area' ),
		'SubdivisionName'                => array( 'column:subdivision', 'tax:mlsimport_area', 'meta:SubdivisionName' ),
		'Latitude'                       => array( 'column:latitude', 'meta:Latitude' ),
		'Longitude'                      => array( 'column:longitude', 'meta:Longitude' ),
		'UnparsedAddress'                => array( 'meta:UnparsedAddress' ),
		'StreetNumber'                   => array( 'meta:StreetNumber' ),
		'StreetName'                     => array( 'meta:StreetName' ),
		'UnitNumber'                     => array( 'meta:UnitNumber' ),

		// Schools — free-text district name -> its own taxonomy term (+ raw meta).
		'HighSchoolDistrict'             => array( 'tax:mlsimport_high_school_district', 'meta:HighSchoolDistrict' ),

		// Type / status.
		'PropertySubType'                => array( 'column:property_type', 'tax:mlsimport_property_type' ),
		'PropertyType'                   => array( 'column:listing_type', 'tax:mlsimport_listing_type' ),
		'StandardStatus'                 => array( 'column:status', 'tax:mlsimport_status' ),

		// Agent / office (display meta; writer also creates/links the agent CPT).
		'ListAgentFullName'              => array( 'meta:ListAgentFullName' ),
		'ListAgentKey'                   => array( 'meta:ListAgentKey' ),
		'ListAgentMlsId'                 => array( 'meta:ListAgentMlsId' ),
		'ListAgentFirstName'             => array( 'meta:ListAgentFirstName' ),
		'ListAgentLastName'              => array( 'meta:ListAgentLastName' ),
		'ListAgentEmail'                 => array( 'meta:ListAgentEmail' ),
		'ListAgentPreferredPhone'        => array( 'meta:ListAgentPreferredPhone' ),
		'ListOfficeName'                 => array( 'meta:ListOfficeName' ),
		'ListOfficeKey'                  => array( 'meta:ListOfficeKey' ),
		'ListOfficeMlsId'                => array( 'meta:ListOfficeMlsId' ),
		'ListOfficePhone'                => array( 'meta:ListOfficePhone' ),

		// Dates.
		'ListingContractDate'            => array( 'column:list_date', 'meta:ListingContractDate' ),
		'OnMarketDate'                   => array( 'meta:OnMarketDate' ),
		'CloseDate'                      => array( 'meta:CloseDate' ),
		'DaysOnMarket'                   => array( 'column:days_on_market', 'meta:DaysOnMarket' ),

		// Media.
		'PhotosCount'                    => array( 'meta:PhotosCount' ),
		'VirtualTourURLUnbranded'        => array( 'meta:virtual_tour' ),

		// HOA.
		'AssociationFee'                 => array( 'column:hoa_fee', 'meta:AssociationFee' ),
		'AssociationFeeFrequency'        => array( 'meta:AssociationFeeFrequency' ),

		// Body / withheld.
		'PublicRemarks'                  => array( 'content' ),
		'PrivateRemarks'                 => array( 'skip' ),
		'PrivateOfficeRemarks'           => array( 'skip' ),

		// Amenity booleans -> mlsimport_feature term (label from help_functions.php 'insert').
		'PoolPrivateYN'                  => array( 'feature:Private Pool' ),
		'WaterfrontYN'                   => array( 'feature:Has Waterfront' ),

		// Amenity multi-enums -> mlsimport_feature terms (each value becomes a term).
		// Each writes BOTH: the term drives search/filtering, the meta keeps the
		// field's identity. Flattened into the taxonomy alone, "Hardwood" no longer
		// remembers it came from Flooring, and no section could ever place it.
		'Appliances'                     => array( 'tax:mlsimport_feature', 'meta:Appliances' ),
		'InteriorFeatures'               => array( 'tax:mlsimport_feature', 'meta:InteriorFeatures' ),
		'ExteriorFeatures'               => array( 'tax:mlsimport_feature', 'meta:ExteriorFeatures' ),
		'View'                           => array( 'tax:mlsimport_feature', 'meta:View' ),
		'Cooling'                        => array( 'tax:mlsimport_feature', 'meta:Cooling' ),
		'Heating'                        => array( 'tax:mlsimport_feature', 'meta:Heating' ),
		'Flooring'                       => array( 'tax:mlsimport_feature', 'meta:Flooring' ),
		'LaundryFeatures'                => array( 'tax:mlsimport_feature', 'meta:LaundryFeatures' ),
		'LotFeatures'                    => array( 'tax:mlsimport_feature', 'meta:LotFeatures' ),
		'CommunityFeatures'              => array( 'tax:mlsimport_feature', 'meta:CommunityFeatures' ),
		'ParkingFeatures'                => array( 'tax:mlsimport_feature', 'meta:ParkingFeatures' ),
		'PoolFeatures'                   => array( 'tax:mlsimport_feature', 'meta:PoolFeatures' ),
	);

	/**
	 * Write targets for a RESO field. Unmapped fields pass through to
	 * meta:x_<Field> (opt-in is gated upstream by the field selector).
	 *
	 * @param string $field RESO field name (verbatim PascalCase).
	 * @return array List of target strings.
	 */
	public static function targets_for( string $field ): array {
		// Explicit mapping if the field is in the §9 map; else pass through to meta:x_<Field>.
		$targets = self::MAP[ $field ] ?? array( 'meta:x_' . $field );

		// Lets an add-on map a new RESO field (or re-route one) without core edits.
		if ( function_exists( 'apply_filters' ) ) {
			/** Filter the write targets for a RESO field. @since 6.3 */
			$targets = (array) apply_filters( 'mlsimport_reso_targets_for', $targets, $field );
		}

		return $targets;
	}
}
