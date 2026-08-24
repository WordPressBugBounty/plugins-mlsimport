<?php
/**
 * Helper functions for the MLSImport plugin.
 *
 * Grab-bag of stand-alone utilities used across the admin/import layers:
 *  - Standalone-mode detection (theme_id 990).
 *  - The canonical RESO -> theme field-mapping schema (mlsimport_hardocde_theme_schema),
 *    a large associative array telling the importer where each RESO field lands
 *    (post meta, taxonomy, media, or post content/title).
 *  - Taxonomy discovery for a post type, allowed-HTML whitelists, SaaS "ready to go
 *    MLS" list fetching, recursive array sanitisation, the reconciliation cron entry
 *    point, the Import Tasks admin list-table columns, and a plugin data reset routine.
 *
 * Loaded early in the bootstrap so these helpers are available to both admin and
 * public code paths.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}


/**
 * Whether the site runs in standalone (theme-agnostic) mode — theme_id 990.
 *
 * Drives the standalone write path / front-end gating. Reads the configured
 * theme from mlsimport_admin_options['mlsimport_theme_used'].
 *
 * @return bool
 */
function mlsimport_is_standalone_mode() {
	$options = get_option( 'mlsimport_admin_options' );
	return is_array( $options )
		&& isset( $options['mlsimport_theme_used'] )
		&& 990 === intval( $options['mlsimport_theme_used'] );
}


/**
 * Hard-coded RESO -> theme field-mapping schema (fallback / reference map).
 *
 * Returns a large associative array keyed by RESO Data Dictionary field name
 * (e.g. 'ListPrice', 'BedroomsTotal', 'City'). Each value is a descriptor array
 * describing where that RESO field is written on the WordPress side:
 *   - 'type'   => one of:
 *                   'meta'     - stored as a post meta value ('name' = meta key),
 *                   'taxonomy' - assigned as a term of taxonomy 'name'; an optional
 *                                'insert' gives the fixed term label to add (used for
 *                                boolean *YN flags, e.g. 'Has Garage'),
 *                   'media'    - handled by the media/gallery importer,
 *                   'content'  - written to the post content (PublicRemarks).
 *   - 'name'   => the destination meta key / taxonomy slug / field on the theme side.
 *   - 'insert' => (taxonomy only, optional) literal term label to insert when the
 *                 RESO boolean is truthy.
 *
 * The keys are ordered alphabetically by RESO field name. Section markers below
 * flag the larger logical clusters (agent/office contact blocks, distance-to-*,
 * tax, etc.) but most fields are simple 1:1 meta passthroughs.
 *
 * @return array Field-name => descriptor map.
 */
function mlsimport_hardocde_theme_schema(){
	// Build and return the full descriptor map in one literal array.
	$theme_schema=  array(
		// ---- Above/Below-grade area, access & accessibility (A) ----
		'AboveGradeFinishedArea' => array(
			'type' => 'meta',
			'name' => 'abovegradefinishedarea',
		),
		'AboveGradeFinishedAreaSource' => array(
			'type' => 'meta',
			'name' => 'abovegradefinishedareasource',
		),
		'AboveGradeFinishedAreaUnits' => array(
			'type' => 'meta',
			'name' => 'abovegradefinishedareaunits',
		),
		'AboveGradeUnfinishedArea' => array(
			'type' => 'meta',
			'name' => 'abovegradeunfinishedarea',
		),
		'AboveGradeUnfinishedAreaSource' => array(
			'type' => 'meta',
			'name' => 'abovegradeunfinishedareasource',
		),
		'AboveGradeUnfinishedAreaUnits' => array(
			'type' => 'meta',
			'name' => 'abovegradeunfinishedareaunits',
		),
		'AccessCode' => array(
			'type' => 'meta',
			'name' => 'accesscode',
		),
		'AccessibilityFeatures' => array(
			'type' => 'meta',
			'name' => 'accessibilityfeatures',
		),
		'ActivationDate' => array(
			'type' => 'meta',
			'name' => 'activationdate',
		),
		'AdditionalParcelsDescription' => array(
			'type' => 'meta',
			'name' => 'additionalparcelsdescription',
		),
		'AdditionalParcelsYN' => array(
			'type' => 'taxonomy',        
			'name' => 'property_features',
			'insert' => 'Has Additional Parcels',
		),
		'AnchorsCoTenants' => array(
			'type' => 'meta',
			'name' => 'anchorscotenants',
		),
		'Appliances' => array(
			'type' => 'taxonomy',
			'name' => 'property_features',
		),
		'ArchitecturalStyle' => array(
			'type' => 'meta',
			'name' => 'architecturalstyle',
		),
		// ---- HOA / association fees & names ----
		'AssociationAmenities' => array(
			'type' => 'meta',
			'name' => 'associationamenities',
		),
		'AssociationFee' => array(
			'type' => 'meta',
			'name' => 'associationfee',
		),
		'AssociationFee2' => array(
			'type' => 'meta',
			'name' => 'associationfee2',
		),
		'AssociationFee2Frequency' => array(
			'type' => 'meta',
			'name' => 'associationfee2frequency',
		),
		'AssociationFeeFrequency' => array(
			'type' => 'meta',
			'name' => 'associationfeefrequency',
		),
		'AssociationFeeIncludes' => array(
			'type' => 'meta',
			'name' => 'associationfeeincludes',
		),
		'AssociationName' => array(
			'type' => 'meta',
			'name' => 'associationname',
		),
		'AssociationName2' => array(
			'type' => 'meta',
			'name' => 'associationname2',
		),
		'AssociationPhone' => array(
			'type' => 'meta',
			'name' => 'associationphone',
		),
		'AssociationPhone2' => array(
			'type' => 'meta',
			'name' => 'associationphone2',
		),
		'AssociationYN' => array(
			'type' => 'taxonomy',
			'name' => 'property_features',
			'insert' => 'Home Owners Association',
		),
		'AttachedGarageYN' => array(
			'type' => 'taxonomy',
			'name' => 'property_features',
			'insert' => 'Garage Attached',
		),
		'AttributionContact' => array(
			'type' => 'meta',
			'name' => 'attributioncontact',
		),
		'AvailabilityDate' => array(
			'type' => 'meta',
			'name' => 'availabilitydate',
		),
		'AvailableLeaseType' => array(
			'type' => 'meta',
			'name' => 'availableleasetype',
		),
		'BackOnMarketDate' => array(
			'type' => 'meta',
			'name' => 'backonmarketdate',
		),
		'Basement' => array(
			'type' => 'meta',
			'name' => 'basement',
		),
		'BasementYN' => array(
			'type' => 'taxonomy',        
			'name' => 'property_features',
			'insert' => 'Has Basement',
		),
		// ---- Bath / bedroom counts (BathroomsTotalInteger & BedroomsTotal map to theme core fields) ----
		'BathroomsFull' => array(
			'type' => 'meta',
			'name' => 'bathroomsfull',
		),
		'BathroomsHalf' => array(
			'type' => 'meta',
			'name' => 'bathroomshalf',
		),
		'BathroomsOneQuarter' => array(
			'type' => 'meta',
			'name' => 'bathroomsonequarter',
		),
		'BathroomsPartial' => array(
			'type' => 'meta',
			'name' => 'bathroomspartial',
		),
		'BathroomsThreeQuarter' => array(
			'type' => 'meta',
			'name' => 'bathroomsthreequarter',
		),
		'BathroomsTotalInteger' => array(
			'type' => 'meta',
			'name' => 'property_bathrooms',
		),
		'BedroomsPossible' => array(
			'type' => 'meta',
			'name' => 'bedroomspossible',
		),
		'BedroomsTotal' => array(
			'type' => 'meta',
			'name' => 'property_bedrooms',
		),
		'BelowGradeFinishedArea' => array(
			'type' => 'meta',
			'name' => 'belowgradefinishedarea',
		),
		'BelowGradeFinishedAreaSource' => array(
			'type' => 'meta',
			'name' => 'belowgradefinishedareasource',
		),
		'BelowGradeFinishedAreaUnits' => array(
			'type' => 'meta',
			'name' => 'belowgradefinishedareaunits',
		),
		'BelowGradeUnfinishedArea' => array(
			'type' => 'meta',
			'name' => 'belowgradeunfinishedarea',
		),
		'BelowGradeUnfinishedAreaSource' => array(
			'type' => 'meta',
			'name' => 'belowgradeunfinishedareasource',
		),
		'BelowGradeUnfinishedAreaUnits' => array(
			'type' => 'meta',
			'name' => 'belowgradeunfinishedareaunits',
		),
		'BodyType' => array(
			'type' => 'meta',
			'name' => 'bodytype',
		),
		'BuilderModel' => array(
			'type' => 'meta',
			'name' => 'buildermodel',
		),
		'BuilderName' => array(
			'type' => 'meta',
			'name' => 'buildername',
		),
		'BuildingAreaSource' => array(
			'type' => 'meta',
			'name' => 'buildingareasource',
		),
		'BuildingAreaTotal' => array(
			'type' => 'meta',
			'name' => 'buildingareatotal',
		),
		'BuildingAreaUnits' => array(
			'type' => 'meta',
			'name' => 'buildingareaunits',
		),
		'BuildingFeatures' => array(
			'type' => 'meta',
			'name' => 'buildingfeatures',
		),
		'BuildingName' => array(
			'type' => 'meta',
			'name' => 'buildingname',
		),
		'BusinessName' => array(
			'type' => 'meta',
			'name' => 'businessname',
		),
		'BusinessType' => array(
			'type' => 'meta',
			'name' => 'businesstype',
		),
		// ---- Buyer agent contact block (name/phone/email/license variants) ----
		'BuyerAgent' => array(
			'type' => 'meta',
			'name' => 'buyeragent',
		),
		'BuyerAgentAOR' => array(
			'type' => 'meta',
			'name' => 'buyeragentaor',
		),
		'BuyerAgentDesignation' => array(
			'type' => 'meta',
			'name' => 'buyeragentdesignation',
		),
		'BuyerAgentDirectPhone' => array(
			'type' => 'meta',
			'name' => 'buyeragentdirectphone',
		),
		'BuyerAgentEmail' => array(
			'type' => 'meta',
			'name' => 'buyeragentemail',
		),
		'BuyerAgentFax' => array(
			'type' => 'meta',
			'name' => 'buyeragentfax',
		),
		'BuyerAgentFirstName' => array(
			'type' => 'meta',
			'name' => 'buyeragentfirstname',
		),
		'BuyerAgentFullName' => array(
			'type' => 'meta',
			'name' => 'buyeragentfullname',
		),
		'BuyerAgentHomePhone' => array(
			'type' => 'meta',
			'name' => 'buyeragenthomephone',
		),
		'BuyerAgentKey' => array(
			'type' => 'meta',
			'name' => 'buyeragentkey',
		),
		'BuyerAgentLastName' => array(
			'type' => 'meta',
			'name' => 'buyeragentlastname',
		),
		'BuyerAgentMiddleName' => array(
			'type' => 'meta',
			'name' => 'buyeragentmiddlename',
		),
		'BuyerAgentMlsId' => array(
			'type' => 'meta',
			'name' => 'buyeragentmlsid',
		),
		'BuyerAgentMobilePhone' => array(
			'type' => 'meta',
			'name' => 'buyeragentmobilephone',
		),
		'BuyerAgentNamePrefix' => array(
			'type' => 'meta',
			'name' => 'buyeragentnameprefix',
		),
		'BuyerAgentNameSuffix' => array(
			'type' => 'meta',
			'name' => 'buyeragentnamesuffix',
		),
		'BuyerAgentNationalAssociationId' => array(
			'type' => 'meta',
			'name' => 'buyeragentnationalassociationid',
		),
		'BuyerAgentOfficePhone' => array(
			'type' => 'meta',
			'name' => 'buyeragentofficephone',
		),
		'BuyerAgentOfficePhoneExt' => array(
			'type' => 'meta',
			'name' => 'buyeragentofficephoneext',
		),
		'BuyerAgentPager' => array(
			'type' => 'meta',
			'name' => 'buyeragentpager',
		),
		'BuyerAgentPreferredPhone' => array(
			'type' => 'meta',
			'name' => 'buyeragentpreferredphone',
		),
		'BuyerAgentPreferredPhoneExt' => array(
			'type' => 'meta',
			'name' => 'buyeragentpreferredphoneext',
		),
		'BuyerAgentStateLicense' => array(
			'type' => 'meta',
			'name' => 'buyeragentstatelicense',
		),
		'BuyerAgentTollFreePhone' => array(
			'type' => 'meta',
			'name' => 'buyeragenttollfreephone',
		),
		'BuyerAgentURL' => array(
			'type' => 'meta',
			'name' => 'buyeragenturl',
		),
		'BuyerAgentVoiceMail' => array(
			'type' => 'meta',
			'name' => 'buyeragentvoicemail',
		),
		'BuyerAgentVoiceMailExt' => array(
			'type' => 'meta',
			'name' => 'buyeragentvoicemailext',
		),
		'BuyerBrokerageCompensation' => array(
			'type' => 'meta',
			'name' => 'buyerbrokeragecompensation',
		),
		'BuyerBrokerageCompensationType' => array(
			'type' => 'meta',
			'name' => 'buyerbrokeragecompensationtype',
		),
		'BuyerFinancing' => array(
			'type' => 'meta',
			'name' => 'buyerfinancing',
		),
		// ---- Buyer office / brokerage block ----
		'BuyerOffice' => array(
			'type' => 'meta',
			'name' => 'buyeroffice',
		),
		'BuyerOfficeAOR' => array(
			'type' => 'meta',
			'name' => 'buyerofficeaor',
		),
		'BuyerOfficeEmail' => array(
			'type' => 'meta',
			'name' => 'buyerofficeemail',
		),
		'BuyerOfficeFax' => array(
			'type' => 'meta',
			'name' => 'buyerofficefax',
		),
		'BuyerOfficeKey' => array(
			'type' => 'meta',
			'name' => 'buyerofficekey',
		),
		'BuyerOfficeMlsId' => array(
			'type' => 'meta',
			'name' => 'buyerofficemlsid',
		),
		'BuyerOfficeName' => array(
			'type' => 'meta',
			'name' => 'buyerofficename',
		),
		'BuyerOfficeNationalAssociationId' => array(
			'type' => 'meta',
			'name' => 'buyerofficenationalassociationid',
		),
		'BuyerOfficePhone' => array(
			'type' => 'meta',
			'name' => 'buyerofficephone',
		),
		'BuyerOfficePhoneExt' => array(
			'type' => 'meta',
			'name' => 'buyerofficephoneext',
		),
		'BuyerOfficeURL' => array(
			'type' => 'meta',
			'name' => 'buyerofficeurl',
		),
		'BuyerTeam' => array(
			'type' => 'meta',
			'name' => 'buyerteam',
		),
		'BuyerTeamKey' => array(
			'type' => 'meta',
			'name' => 'buyerteamkey',
		),
		'BuyerTeamName' => array(
			'type' => 'meta',
			'name' => 'buyerteamname',
		),
		'CableTvExpense' => array(
			'type' => 'meta',
			'name' => 'cabletvexpense',
		),
		'CancellationDate' => array(
			'type' => 'meta',
			'name' => 'cancellationdate',
		),
		'CapRate' => array(
			'type' => 'meta',
			'name' => 'caprate',
		),
		'CarportSpaces' => array(
			'type' => 'meta',
			'name' => 'carportspaces',
		),
		'CarportYN' => array(
			'type' => 'taxonomy',
			'name' => 'property_features',
			'insert' => 'Has Carport',
		),
		'CarrierRoute' => array(
			'type' => 'meta',
			'name' => 'carrierroute',
		),
		// ---- Location taxonomies: City -> property_city, CityRegion -> property_area, etc. ----
		'City' => array(
			'type' => 'taxonomy',
			'name' => 'property_city',
		),
		'CityRegion' => array(
			'type' => 'taxonomy',
			'name' => 'property_area',
		),
		'CloseDate' => array(
			'type' => 'meta',
			'name' => 'closedate',
		),
		'ClosePrice' => array(
			'type' => 'meta',
			'name' => 'closeprice',
		),
		// ---- Co-buyer agent contact block ----
		'CoBuyerAgent' => array(
			'type' => 'meta',
			'name' => 'cobuyeragent',
		),
		'CoBuyerAgentAOR' => array(
			'type' => 'meta',
			'name' => 'cobuyeragentaor',
		),
		'CoBuyerAgentDesignation' => array(
			'type' => 'meta',
			'name' => 'cobuyeragentdesignation',
		),
		'CoBuyerAgentDirectPhone' => array(
			'type' => 'meta',
			'name' => 'cobuyeragentdirectphone',
		),
		'CoBuyerAgentEmail' => array(
			'type' => 'meta',
			'name' => 'cobuyeragentemail',
		),
		'CoBuyerAgentFax' => array(
			'type' => 'meta',
			'name' => 'cobuyeragentfax',
		),
		'CoBuyerAgentFirstName' => array(
			'type' => 'meta',
			'name' => 'cobuyeragentfirstname',
		),
		'CoBuyerAgentFullName' => array(
			'type' => 'meta',
			'name' => 'cobuyeragentfullname',
		),
		'CoBuyerAgentHomePhone' => array(
			'type' => 'meta',
			'name' => 'cobuyeragenthomephone',
		),
		'CoBuyerAgentKey' => array(
			'type' => 'meta',
			'name' => 'cobuyeragentkey',
		),
		'CoBuyerAgentLastName' => array(
			'type' => 'meta',
			'name' => 'cobuyeragentlastname',
		),
		'CoBuyerAgentMiddleName' => array(
			'type' => 'meta',
			'name' => 'cobuyeragentmiddlename',
		),
		'CoBuyerAgentMlsId' => array(
			'type' => 'meta',
			'name' => 'cobuyeragentmlsid',
		),
		'CoBuyerAgentMobilePhone' => array(
			'type' => 'meta',
			'name' => 'cobuyeragentmobilephone',
		),
		'CoBuyerAgentNamePrefix' => array(
			'type' => 'meta',
			'name' => 'cobuyeragentnameprefix',
		),
		'CoBuyerAgentNameSuffix' => array(
			'type' => 'meta',
			'name' => 'cobuyeragentnamesuffix',
		),
		'CoBuyerAgentNationalAssociationId' => array(
			'type' => 'meta',
			'name' => 'cobuyeragentnationalassociationid',
		),
		'CoBuyerAgentOfficePhone' => array(
			'type' => 'meta',
			'name' => 'cobuyeragentofficephone',
		),
		'CoBuyerAgentOfficePhoneExt' => array(
			'type' => 'meta',
			'name' => 'cobuyeragentofficephoneext',
		),
		'CoBuyerAgentPager' => array(
			'type' => 'meta',
			'name' => 'cobuyeragentpager',
		),
		'CoBuyerAgentPreferredPhone' => array(
			'type' => 'meta',
			'name' => 'cobuyeragentpreferredphone',
		),
		'CoBuyerAgentPreferredPhoneExt' => array(
			'type' => 'meta',
			'name' => 'cobuyeragentpreferredphoneext',
		),
		'CoBuyerAgentStateLicense' => array(
			'type' => 'meta',
			'name' => 'cobuyeragentstatelicense',
		),
		'CoBuyerAgentTollFreePhone' => array(
			'type' => 'meta',
			'name' => 'cobuyeragenttollfreephone',
		),
		'CoBuyerAgentURL' => array(
			'type' => 'meta',
			'name' => 'cobuyeragenturl',
		),
		'CoBuyerAgentVoiceMail' => array(
			'type' => 'meta',
			'name' => 'cobuyeragentvoicemail',
		),
		'CoBuyerAgentVoiceMailExt' => array(
			'type' => 'meta',
			'name' => 'cobuyeragentvoicemailext',
		),
		// ---- Co-buyer office / brokerage block ----
		'CoBuyerOffice' => array(
			'type' => 'meta',
			'name' => 'cobuyeroffice',
		),
		'CoBuyerOfficeAOR' => array(
			'type' => 'meta',
			'name' => 'cobuyerofficeaor',
		),
		'CoBuyerOfficeEmail' => array(
			'type' => 'meta',
			'name' => 'cobuyerofficeemail',
		),
		'CoBuyerOfficeFax' => array(
			'type' => 'meta',
			'name' => 'cobuyerofficefax',
		),
		'CoBuyerOfficeKey' => array(
			'type' => 'meta',
			'name' => 'cobuyerofficekey',
		),
		'CoBuyerOfficeMlsId' => array(
			'type' => 'meta',
			'name' => 'cobuyerofficemlsid',
		),
		'CoBuyerOfficeName' => array(
			'type' => 'meta',
			'name' => 'cobuyerofficename',
		),
		'CoBuyerOfficeNationalAssociationId' => array(
			'type' => 'meta',
			'name' => 'cobuyerofficenationalassociationid',
		),
		'CoBuyerOfficePhone' => array(
			'type' => 'meta',
			'name' => 'cobuyerofficephone',
		),
		'CoBuyerOfficePhoneExt' => array(
			'type' => 'meta',
			'name' => 'cobuyerofficephoneext',
		),
		'CoBuyerOfficeURL' => array(
			'type' => 'meta',
			'name' => 'cobuyerofficeurl',
		),
		// ---- Co-listing agent contact block ----
		'CoListAgent' => array(
			'type' => 'meta',
			'name' => 'colistagent',
		),
		'CoListAgentAOR' => array(
			'type' => 'meta',
			'name' => 'colistagentaor',
		),
		'CoListAgentDesignation' => array(
			'type' => 'meta',
			'name' => 'colistagentdesignation',
		),
		'CoListAgentDirectPhone' => array(
			'type' => 'meta',
			'name' => 'colistagentdirectphone',
		),
		'CoListAgentEmail' => array(
			'type' => 'meta',
			'name' => 'colistagentemail',
		),
		'CoListAgentFax' => array(
			'type' => 'meta',
			'name' => 'colistagentfax',
		),
		'CoListAgentFirstName' => array(
			'type' => 'meta',
			'name' => 'colistagentfirstname',
		),
		'CoListAgentFullName' => array(
			'type' => 'meta',
			'name' => 'colistagentfullname',
		),
		'CoListAgentHomePhone' => array(
			'type' => 'meta',
			'name' => 'colistagenthomephone',
		),
		'CoListAgentKey' => array(
			'type' => 'meta',
			'name' => 'colistagentkey',
		),
		'CoListAgentLastName' => array(
			'type' => 'meta',
			'name' => 'colistagentlastname',
		),
		'CoListAgentMiddleName' => array(
			'type' => 'meta',
			'name' => 'colistagentmiddlename',
		),
		'CoListAgentMlsId' => array(
			'type' => 'meta',
			'name' => 'colistagentmlsid',
		),
		'CoListAgentMobilePhone' => array(
			'type' => 'meta',
			'name' => 'colistagentmobilephone',
		),
		'CoListAgentNamePrefix' => array(
			'type' => 'meta',
			'name' => 'colistagentnameprefix',
		),
		'CoListAgentNameSuffix' => array(
			'type' => 'meta',
			'name' => 'colistagentnamesuffix',
		),
		'CoListAgentNationalAssociationId' => array(
			'type' => 'meta',
			'name' => 'colistagentnationalassociationid',
		),
		'CoListAgentOfficePhone' => array(
			'type' => 'meta',
			'name' => 'colistagentofficephone',
		),
		'CoListAgentOfficePhoneExt' => array(
			'type' => 'meta',
			'name' => 'colistagentofficephoneext',
		),
		'CoListAgentPager' => array(
			'type' => 'meta',
			'name' => 'colistagentpager',
		),
		'CoListAgentPreferredPhone' => array(
			'type' => 'meta',
			'name' => 'colistagentpreferredphone',
		),
		'CoListAgentPreferredPhoneExt' => array(
			'type' => 'meta',
			'name' => 'colistagentpreferredphoneext',
		),
		'CoListAgentStateLicense' => array(
			'type' => 'meta',
			'name' => 'colistagentstatelicense',
		),
		'CoListAgentTollFreePhone' => array(
			'type' => 'meta',
			'name' => 'colistagenttollfreephone',
		),
		'CoListAgentURL' => array(
			'type' => 'meta',
			'name' => 'colistagenturl',
		),
		'CoListAgentVoiceMail' => array(
			'type' => 'meta',
			'name' => 'colistagentvoicemail',
		),
		'CoListAgentVoiceMailExt' => array(
			'type' => 'meta',
			'name' => 'colistagentvoicemailext',
		),
		// ---- Co-listing office / brokerage block ----
		'CoListOffice' => array(
			'type' => 'meta',
			'name' => 'colistoffice',
		),
		'CoListOfficeAOR' => array(
			'type' => 'meta',
			'name' => 'colistofficeaor',
		),
		'CoListOfficeEmail' => array(
			'type' => 'meta',
			'name' => 'colistofficeemail',
		),
		'CoListOfficeFax' => array(
			'type' => 'meta',
			'name' => 'colistofficefax',
		),
		'CoListOfficeKey' => array(
			'type' => 'meta',
			'name' => 'colistofficekey',
		),
		'CoListOfficeMlsId' => array(
			'type' => 'meta',
			'name' => 'colistofficemlsid',
		),
		'CoListOfficeName' => array(
			'type' => 'meta',
			'name' => 'colistofficename',
		),
		'CoListOfficeNationalAssociationId' => array(
			'type' => 'meta',
			'name' => 'colistofficenationalassociationid',
		),
		'CoListOfficePhone' => array(
			'type' => 'meta',
			'name' => 'colistofficephone',
		),
		'CoListOfficePhoneExt' => array(
			'type' => 'meta',
			'name' => 'colistofficephoneext',
		),
		'CoListOfficeURL' => array(
			'type' => 'meta',
			'name' => 'colistofficeurl',
		),
		'CommonInterest' => array(
			'type' => 'meta',
			'name' => 'commoninterest',
		),
		'CommonWalls' => array(
			'type' => 'meta',
			'name' => 'commonwalls',
		),
		'CommunityFeatures' => array(
			'type' => 'meta',
			'name' => 'communityfeatures',
		),
		'CompensationComments' => array(
			'type' => 'meta',
			'name' => 'compensationcomments',
		),
		'CompSaleYN' => array(
			'type' => 'taxonomy',        
			'name' => 'property_features',
			'insert' => 'Comparative Purposes',
		),
		'Concessions' => array(
			'type' => 'meta',
			'name' => 'concessions',
		),
		'ConcessionsAmount' => array(
			'type' => 'meta',
			'name' => 'concessionsamount',
		),
		'ConcessionsComments' => array(
			'type' => 'meta',
			'name' => 'concessionscomments',
		),
		'ConstructionMaterials' => array(
			'type' => 'meta',
			'name' => 'constructionmaterials',
		),
		'ContinentRegion' => array(
			'type' => 'meta',
			'name' => 'continentregion',
		),
		'Contingency' => array(
			'type' => 'meta',
			'name' => 'contingency',
		),
		'ContingentDate' => array(
			'type' => 'meta',
			'name' => 'contingentdate',
		),
		'ContractStatusChangeDate' => array(
			'type' => 'meta',
			'name' => 'contractstatuschangedate',
		),
		'Cooling' => array(
			'type' => 'meta',
			'name' => 'cooling',
		),
		'CoolingYN' => array(
			'type' => 'taxonomy',
			'name' => 'property_features',
			'insert' => 'Has Cooling System',
		),
		'CopyrightNotice' => array(
			'type' => 'meta',
			'name' => 'copyrightnotice',
		),
		'Country' => array(
			'type' => 'meta',
			'name' => 'property_country',
		),
		'CountryRegion' => array(
			'type' => 'meta',
			'name' => 'countryregion',
		),
		'CountyOrParish' => array(
			'type' => 'taxonomy',
			'name' => 'property_county_state',
		),
		'CoveredSpaces' => array(
			'type' => 'meta',
			'name' => 'coveredspaces',
		),
		'CropsIncludedYN' => array(
			'type' => 'taxonomy',        
			'name' => 'property_features',
			'insert' => 'Crops Included',
		),
		'CrossStreet' => array(
			'type' => 'meta',
			'name' => 'crossstreet',
		),
		'CultivatedArea' => array(
			'type' => 'meta',
			'name' => 'cultivatedarea',
		),
		'CumulativeDaysOnMarket' => array(
			'type' => 'meta',
			'name' => 'cumulativedaysonmarket',
		),
		'CurrentFinancing' => array(
			'type' => 'meta',
			'name' => 'currentfinancing',
		),
		'CurrentUse' => array(
			'type' => 'meta',
			'name' => 'currentuse',
		),
		'DaysInMls' => array(
			'type' => 'meta',
			'name' => 'daysinmls',
		),
		'DaysOnMarket' => array(
			'type' => 'meta',
			'name' => 'daysonmarket',
		),
		'DaysOnSite' => array(
			'type' => 'meta',
			'name' => 'daysonsite',
		),
		'DevelopmentStatus' => array(
			'type' => 'meta',
			'name' => 'developmentstatus',
		),
		'DirectionFaces' => array(
			'type' => 'meta',
			'name' => 'directionfaces',
		),
		'Directions' => array(
			'type' => 'meta',
			'name' => 'directions',
		),
		'Disclaimer' => array(
			'type' => 'meta',
			'name' => 'disclaimer',
		),
		'Disclosures' => array(
			'type' => 'meta',
			'name' => 'disclosures',
		),
		// ---- DistanceTo* family: comments/numeric/units triplets for each amenity ----
		'DistanceToBusComments' => array(
			'type' => 'meta',
			'name' => 'distancetobuscomments',
		),
		'DistanceToBusNumeric' => array(
			'type' => 'meta',
			'name' => 'distancetobusnumeric',
		),
		'DistanceToBusUnits' => array(
			'type' => 'meta',
			'name' => 'distancetobusunits',
		),
		'DistanceToElectricComments' => array(
			'type' => 'meta',
			'name' => 'distancetoelectriccomments',
		),
		'DistanceToElectricNumeric' => array(
			'type' => 'meta',
			'name' => 'distancetoelectricnumeric',
		),
		'DistanceToElectricUnits' => array(
			'type' => 'meta',
			'name' => 'distancetoelectricunits',
		),
		'DistanceToFreewayComments' => array(
			'type' => 'meta',
			'name' => 'distancetofreewaycomments',
		),
		'DistanceToFreewayNumeric' => array(
			'type' => 'meta',
			'name' => 'distancetofreewaynumeric',
		),
		'DistanceToFreewayUnits' => array(
			'type' => 'meta',
			'name' => 'distancetofreewayunits',
		),
		'DistanceToGasComments' => array(
			'type' => 'meta',
			'name' => 'distancetogascomments',
		),
		'DistanceToGasNumeric' => array(
			'type' => 'meta',
			'name' => 'distancetogasnumeric',
		),
		'DistanceToGasUnits' => array(
			'type' => 'meta',
			'name' => 'distancetogasunits',
		),
		'DistanceToPhoneServiceComments' => array(
			'type' => 'meta',
			'name' => 'distancetophoneservicecomments',
		),
		'DistanceToPhoneServiceNumeric' => array(
			'type' => 'meta',
			'name' => 'distancetophoneservicenumeric',
		),
		'DistanceToPhoneServiceUnits' => array(
			'type' => 'meta',
			'name' => 'distancetophoneserviceunits',
		),
		'DistanceToPlaceofWorshipComments' => array(
			'type' => 'meta',
			'name' => 'distancetoplaceofworshipcomments',
		),
		'DistanceToPlaceofWorshipNumeric' => array(
			'type' => 'meta',
			'name' => 'distancetoplaceofworshipnumeric',
		),
		'DistanceToPlaceofWorshipUnits' => array(
			'type' => 'meta',
			'name' => 'distancetoplaceofworshipunits',
		),
		'DistanceToSchoolBusComments' => array(
			'type' => 'meta',
			'name' => 'distancetoschoolbuscomments',
		),
		'DistanceToSchoolBusNumeric' => array(
			'type' => 'meta',
			'name' => 'distancetoschoolbusnumeric',
		),
		'DistanceToSchoolBusUnits' => array(
			'type' => 'meta',
			'name' => 'distancetoschoolbusunits',
		),
		'DistanceToSchoolsComments' => array(
			'type' => 'meta',
			'name' => 'distancetoschoolscomments',
		),
		'DistanceToSchoolsNumeric' => array(
			'type' => 'meta',
			'name' => 'distancetoschoolsnumeric',
		),
		'DistanceToSchoolsUnits' => array(
			'type' => 'meta',
			'name' => 'distancetoschoolsunits',
		),
		'DistanceToSewerComments' => array(
			'type' => 'meta',
			'name' => 'distancetosewercomments',
		),
		'DistanceToSewerNumeric' => array(
			'type' => 'meta',
			'name' => 'distancetosewernumeric',
		),
		'DistanceToSewerUnits' => array(
			'type' => 'meta',
			'name' => 'distancetosewerunits',
		),
		'DistanceToShoppingComments' => array(
			'type' => 'meta',
			'name' => 'distancetoshoppingcomments',
		),
		'DistanceToShoppingNumeric' => array(
			'type' => 'meta',
			'name' => 'distancetoshoppingnumeric',
		),
		'DistanceToShoppingUnits' => array(
			'type' => 'meta',
			'name' => 'distancetoshoppingunits',
		),
		'DistanceToStreetComments' => array(
			'type' => 'meta',
			'name' => 'distancetostreetcomments',
		),
		'DistanceToStreetNumeric' => array(
			'type' => 'meta',
			'name' => 'distancetostreetnumeric',
		),
		'DistanceToStreetUnits' => array(
			'type' => 'meta',
			'name' => 'distancetostreetunits',
		),
		'DistanceToWaterComments' => array(
			'type' => 'meta',
			'name' => 'distancetowatercomments',
		),
		'DistanceToWaterNumeric' => array(
			'type' => 'meta',
			'name' => 'distancetowaternumeric',
		),
		'DistanceToWaterUnits' => array(
			'type' => 'meta',
			'name' => 'distancetowaterunits',
		),
		'DocumentsAvailable' => array(
			'type' => 'meta',
			'name' => 'documentsavailable',
		),
		'DocumentsChangeTimestamp' => array(
			'type' => 'meta',
			'name' => 'documentschangetimestamp',
		),
		'DocumentsCount' => array(
			'type' => 'meta',
			'name' => 'documentscount',
		),
		'DocumentStatus' => array(
			'type' => 'meta',
			'name' => 'documentstatus',
		),
		'DOH1' => array(
			'type' => 'meta',
			'name' => 'doh1',
		),
		'DOH2' => array(
			'type' => 'meta',
			'name' => 'doh2',
		),
		'DOH3' => array(
			'type' => 'meta',
			'name' => 'doh3',
		),
		'DoorFeatures' => array(
			'type' => 'meta',
			'name' => 'doorfeatures',
		),
		'DualOrVariableRateCommissionYN' => array(
			'type' => 'meta',
			'name' => 'dualorvariableratecommissionyn',
		),
		'Electric' => array(
			'type' => 'meta',
			'name' => 'electric',
		),
		'ElectricExpense' => array(
			'type' => 'meta',
			'name' => 'electricexpense',
		),
		'ElectricOnPropertyYN' => array(
			'type' => 'taxonomy',
			'name' => 'property_features',
			'insert' => 'Electrical Utility Available',
		),
		'ElementarySchool' => array(
			'type' => 'meta',
			'name' => 'elementaryschool',
		),
		'ElementarySchoolDistrict' => array(
			'type' => 'meta',
			'name' => 'elementaryschooldistrict',
		),
		'Elevation' => array(
			'type' => 'meta',
			'name' => 'elevation',
		),
		'ElevationUnits' => array(
			'type' => 'meta',
			'name' => 'elevationunits',
		),
		'EntryLevel' => array(
			'type' => 'meta',
			'name' => 'entrylevel',
		),
		'EntryLocation' => array(
			'type' => 'meta',
			'name' => 'entrylocation',
		),
		'Exclusions' => array(
			'type' => 'meta',
			'name' => 'exclusions',
		),
		'ExistingLeaseType' => array(
			'type' => 'meta',
			'name' => 'existingleasetype',
		),
		'ExpirationDate' => array(
			'type' => 'meta',
			'name' => 'expirationdate',
		),
		'ExteriorFeatures' => array(
			'type' => 'taxonomy',
			'name' => 'property_features',
		),
		'FarmCreditServiceInclYN' => array(
			'type' => 'taxonomy',        
			'name' => 'property_features',
			'insert' => 'Farm Credit Service shares are included',
		),
		'FarmLandAreaSource' => array(
			'type' => 'meta',
			'name' => 'farmlandareasource',
		),
		'FarmLandAreaUnits' => array(
			'type' => 'meta',
			'name' => 'farmlandareaunits',
		),
		'Fencing' => array(
			'type' => 'meta',
			'name' => 'fencing',
		),
		'FhaEligibility' => array(
			'type' => 'meta',
			'name' => 'fhaeligibility',
		),
		'FinancialDataSource' => array(
			'type' => 'meta',
			'name' => 'financialdatasource',
		),
		'FireplaceFeatures' => array(
			'type' => 'meta',
			'name' => 'fireplacefeatures',
		),
		'FireplacesTotal' => array(
			'type' => 'meta',
			'name' => 'fireplacestotal',
		),
		'FireplaceYN' => array(
			'type' => 'taxonomy',
			'name' => 'property_features',
			'insert' => 'Has Fireplace',
		),
		'Flooring' => array(
			'type' => 'meta',
			'name' => 'flooring',
		),
		'FoundationArea' => array(
			'type' => 'meta',
			'name' => 'foundationarea',
		),
		'FoundationDetails' => array(
			'type' => 'meta',
			'name' => 'foundationdetails',
		),
		'FrontageLength' => array(
			'type' => 'meta',
			'name' => 'frontagelength',
		),
		'FrontageType' => array(
			'type' => 'meta',
			'name' => 'frontagetype',
		),
		'FuelExpense' => array(
			'type' => 'meta',
			'name' => 'fuelexpense',
		),
		'Furnished' => array(
			'type' => 'meta',
			'name' => 'furnished',
		),
		'FurnitureReplacementExpense' => array(
			'type' => 'meta',
			'name' => 'furniturereplacementexpense',
		),
		'GarageSpaces' => array(
			'type' => 'meta',
			'name' => 'garagespaces',
		),
		'GarageYN' => array(
			'type' => 'taxonomy',
			'name' => 'property_features',
			'insert' => 'Has Garage',
		),
		'GardenerExpense' => array(
			'type' => 'meta',
			'name' => 'gardenerexpense',
		),
		'GrazingPermitsBlmYN' => array(
			'type' => 'taxonomy',        
			'name' => 'property_features',
			'insert' => 'Has Grazing Permits -  Bureau of Land Management',
		),
		'GrazingPermitsForestServiceYN' => array(
			'type' => 'taxonomy',        
			'name' => 'property_features',
			'insert' => 'Has Grazing Permits - Forestry Service',
		),
		'GrazingPermitsPrivateYN' => array(
			'type' => 'taxonomy',        
			'name' => 'property_features',
			'insert'=>'Private Grazing Permits'
		),
		'GreenBuildingVerification' => array(
			'type' => 'meta',
			'name' => 'greenbuildingverification',
		),
		'GreenBuildingVerificationType' => array(
			'type' => 'meta',
			'name' => 'greenbuildingverificationtype',
		),
		'GreenEnergyEfficient' => array(
			'type' => 'meta',
			'name' => 'greenenergyefficient',
		),
		'GreenEnergyGeneration' => array(
			'type' => 'meta',
			'name' => 'greenenergygeneration',
		),
		'GreenIndoorAirQuality' => array(
			'type' => 'meta',
			'name' => 'greenindoorairquality',
		),
		'GreenLocation' => array(
			'type' => 'meta',
			'name' => 'greenlocation',
		),
		'GreenSustainability' => array(
			'type' => 'meta',
			'name' => 'greensustainability',
		),
		'GreenVerificationYN' => array(
			'type' => 'taxonomy',        
			'name' => 'property_features',
			'insert'=>'Green Verification'
		),
		'GreenWaterConservation' => array(
			'type' => 'meta',
			'name' => 'greenwaterconservation',
		),
		'GrossIncome' => array(
			'type' => 'meta',
			'name' => 'grossincome',
		),
		'GrossLivingAreaAnsi' => array(
			'type' => 'meta',
			'name' => 'grosslivingareaansi',
		),
		'GrossScheduledIncome' => array(
			'type' => 'meta',
			'name' => 'grossscheduledincome',
		),
		'HabitableResidenceYN' => array(
			'type' => 'taxonomy',        
			'name' => 'property_features',
			 'insert'=>'Habitable Residence'
		),
		'Heating' => array(
			'type' => 'meta',
			'name' => 'heating',
		),
		'HeatingYN' => array(
			'type' => 'taxonomy',
			'name' => 'property_features',
			'insert' => 'Heating System',
		),
		'HighSchool' => array(
			'type' => 'meta',
			'name' => 'highschool',
		),
		'HighSchoolDistrict' => array(
			'type' => 'meta',
			'name' => 'highschooldistrict',
		),
		'HistoryTransactional' => array(
			'type' => 'meta',
			'name' => 'historytransactional',
		),
		'HomeWarrantyYN' => array(
			'type' => 'taxonomy',        
			'name' => 'property_features',
			'insert'=>'Home Warranty'
		),
		'HorseAmenities' => array(
			'type' => 'meta',
			'name' => 'horseamenities',
		),
		'HorseYN' => array(
			'type' => 'taxonomy',        
			'name' => 'property_features',
			'insert' => 'Can Raise Horses',
		),
		'HoursDaysOfOperation' => array(
			'type' => 'meta',
			'name' => 'hoursdaysofoperation',
		),
		'HoursDaysOfOperationDescription' => array(
			'type' => 'meta',
			'name' => 'hoursdaysofoperationdescription',
		),
		'Inclusions' => array(
			'type' => 'meta',
			'name' => 'inclusions',
		),
		'IncomeIncludes' => array(
			'type' => 'meta',
			'name' => 'incomeincludes',
		),
		'InsuranceExpense' => array(
			'type' => 'meta',
			'name' => 'insuranceexpense',
		),
		'InteriorFeatures' => array(
			'type' => 'taxonomy',
			'name' => 'property_features',
		),
		'InternetAddressDisplayYN' => array(
			'type' => 'meta',
			'name' => 'internetaddressdisplayyn',
			
		),
		'InternetAutomatedValuationDisplayYN' => array(
			'type' => 'meta',
			'name' => 'internetautomatedvaluationdisplayyn',
		),
		'InternetConsumerCommentYN' => array(
			'type' => 'meta',
			'name' => 'internetconsumercommentyn',
		),
		'InternetEntireListingDisplayYN' => array(
			'type' => 'meta',
			'name' => 'internetentirelistingdisplayyn',
		),
		'IrrigationSource' => array(
			'type' => 'meta',
			'name' => 'irrigationsource',
		),
		'IrrigationWaterRightsAcres' => array(
			'type' => 'meta',
			'name' => 'irrigationwaterrightsacres',
		),
		'IrrigationWaterRightsYN' => array(
			'type' => 'taxonomy',        
			'name' => 'property_features',
			'insert'=>'Irrigation Water Rights'
		),
		'LaborInformation' => array(
			'type' => 'meta',
			'name' => 'laborinformation',
		),
		'LandLeaseAmount' => array(
			'type' => 'meta',
			'name' => 'landleaseamount',
		),
		'LandLeaseAmountFrequency' => array(
			'type' => 'meta',
			'name' => 'landleaseamountfrequency',
		),
		'LandLeaseExpirationDate' => array(
			'type' => 'meta',
			'name' => 'landleaseexpirationdate',
		),
		'LandLeaseYN' => array(
			'type' => 'taxonomy',        
			'name' => 'property_features',
			'insert'=> 'Land Lease'
		),
		'Latitude' => array(
			'type' => 'meta',
			'name' => 'property_latitude',
		),
		'LaundryFeatures' => array(
			'type' => 'meta',
			'name' => 'laundryfeatures',
		),
		'LeasableArea' => array(
			'type' => 'meta',
			'name' => 'leasablearea',
		),
		'LeasableAreaUnits' => array(
			'type' => 'meta',
			'name' => 'leasableareaunits',
		),
		'LeaseAmount' => array(
			'type' => 'meta',
			'name' => 'leaseamount',
		),
		'LeaseAmountFrequency' => array(
			'type' => 'meta',
			'name' => 'leaseamountfrequency',
		),
		'LeaseAssignableYN' => array(
			'type' => 'taxonomy',        
			'name' => 'property_features',
			'insert'=> 'Lease Assignable'
		),
		'LeaseConsideredYN' => array(
			'type' => 'taxonomy',        
			'name' => 'property_features',
			'insert'=> 'Will Consider Lease'
		),
		'LeaseExpiration' => array(
			'type' => 'meta',
			'name' => 'leaseexpiration',
		),
		'LeaseRenewalCompensation' => array(
			'type' => 'meta',
			'name' => 'leaserenewalcompensation',
		),
		'LeaseRenewalOptionYN' => array(
			'type' => 'taxonomy',        
			'name' => 'property_features',
			'insert'=> 'Lease Renewal Option'
		),
		'LeaseTerm' => array(
			'type' => 'meta',
			'name' => 'leaseterm',
		),
		'Levels' => array(
			'type' => 'meta',
			'name' => 'levels',
		),
		'License1' => array(
			'type' => 'meta',
			'name' => 'license1',
		),
		'License2' => array(
			'type' => 'meta',
			'name' => 'license2',
		),
		'License3' => array(
			'type' => 'meta',
			'name' => 'license3',
		),
		'LicensesExpense' => array(
			'type' => 'meta',
			'name' => 'licensesexpense',
		),
		// ---- Listing agent contact block ----
		'ListAgent' => array(
			'type' => 'meta',
			'name' => 'listagent',
		),
		'ListAgentAOR' => array(
			'type' => 'meta',
			'name' => 'listagentaor',
		),
		'ListAgentDesignation' => array(
			'type' => 'meta',
			'name' => 'listagentdesignation',
		),
		'ListAgentDirectPhone' => array(
			'type' => 'meta',
			'name' => 'listagentdirectphone',
		),
		'ListAgentEmail' => array(
			'type' => 'meta',
			'name' => 'listagentemail',
		),
		'ListAgentFax' => array(
			'type' => 'meta',
			'name' => 'listagentfax',
		),
		'ListAgentFirstName' => array(
			'type' => 'meta',
			'name' => 'listagentfirstname',
		),
		'ListAgentFullName' => array(
			'type' => 'meta',
			'name' => 'listagentfullname',
		),
		'ListAgentHomePhone' => array(
			'type' => 'meta',
			'name' => 'listagenthomephone',
		),
		'ListAgentKey' => array(
			'type' => 'meta',
			'name' => 'listagentkey',
		),
		'ListAgentLastName' => array(
			'type' => 'meta',
			'name' => 'listagentlastname',
		),
		'ListAgentMiddleName' => array(
			'type' => 'meta',
			'name' => 'listagentmiddlename',
		),
		'ListAgentMlsId' => array(
			'type' => 'meta',
			'name' => 'listagentmlsid',
		),
		'ListAgentMobilePhone' => array(
			'type' => 'meta',
			'name' => 'listagentmobilephone',
		),
		'ListAgentNamePrefix' => array(
			'type' => 'meta',
			'name' => 'listagentnameprefix',
		),
		'ListAgentNameSuffix' => array(
			'type' => 'meta',
			'name' => 'listagentnamesuffix',
		),
		'ListAgentNationalAssociationId' => array(
			'type' => 'meta',
			'name' => 'listagentnationalassociationid',
		),
		'ListAgentOfficePhone' => array(
			'type' => 'meta',
			'name' => 'listagentofficephone',
		),
		'ListAgentOfficePhoneExt' => array(
			'type' => 'meta',
			'name' => 'listagentofficephoneext',
		),
		'ListAgentPager' => array(
			'type' => 'meta',
			'name' => 'listagentpager',
		),
		'ListAgentPreferredPhone' => array(
			'type' => 'meta',
			'name' => 'listagentpreferredphone',
		),
		'ListAgentPreferredPhoneExt' => array(
			'type' => 'meta',
			'name' => 'listagentpreferredphoneext',
		),
		'ListAgentStateLicense' => array(
			'type' => 'meta',
			'name' => 'listagentstatelicense',
		),
		'ListAgentTollFreePhone' => array(
			'type' => 'meta',
			'name' => 'listagenttollfreephone',
		),
		'ListAgentURL' => array(
			'type' => 'meta',
			'name' => 'listagenturl',
		),
		'ListAgentVoiceMail' => array(
			'type' => 'meta',
			'name' => 'listagentvoicemail',
		),
		'ListAgentVoiceMailExt' => array(
			'type' => 'meta',
			'name' => 'listagentvoicemailext',
		),
		'ListAOR' => array(
			'type' => 'meta',
			'name' => 'listaor',
		),
		'ListingAgreement' => array(
			'type' => 'meta',
			'name' => 'listingagreement',
		),
		'ListingContractDate' => array(
			'type' => 'meta',
			'name' => 'listingcontractdate',
		),
		'ListingId' => array(
			'type' => 'meta',
			'name' => 'listingid',
		),
		'ListingKey' => array(
			'type' => 'meta',
			'name' => 'listingkey',
		),
		'ListingService' => array(
			'type' => 'meta',
			'name' => 'listingservice',
		),
		'ListingTerms' => array(
			'type' => 'meta',
			'name' => 'listingterms',
		),
		'ListingURL' => array(
			'type' => 'meta',
			'name' => 'listingurl',
		),
		'ListingURLDescription' => array(
			'type' => 'meta',
			'name' => 'listingurldescription',
		),
		// ---- Listing office / brokerage block ----
		'ListOffice' => array(
			'type' => 'meta',
			'name' => 'listoffice',
		),
		'ListOfficeAOR' => array(
			'type' => 'meta',
			'name' => 'listofficeaor',
		),
		'ListOfficeEmail' => array(
			'type' => 'meta',
			'name' => 'listofficeemail',
		),
		'ListOfficeFax' => array(
			'type' => 'meta',
			'name' => 'listofficefax',
		),
		'ListOfficeKey' => array(
			'type' => 'meta',
			'name' => 'listofficekey',
		),
		'ListOfficeMlsId' => array(
			'type' => 'meta',
			'name' => 'listofficemlsid',
		),
		'ListOfficeName' => array(
			'type' => 'meta',
			'name' => 'listofficename',
		),
		'ListOfficeNationalAssociationId' => array(
			'type' => 'meta',
			'name' => 'listofficenationalassociationid',
		),
		'ListOfficePhone' => array(
			'type' => 'meta',
			'name' => 'listofficephone',
		),
		'ListOfficePhoneExt' => array(
			'type' => 'meta',
			'name' => 'listofficephoneext',
		),
		'ListOfficeURL' => array(
			'type' => 'meta',
			'name' => 'listofficeurl',
		),
		// ---- Core theme fields: ListPrice/LivingArea/LotSize/Lat-Long map to theme meta keys ----
		'ListPrice' => array(
			'type' => 'meta',
			'name' => 'property_price',
		),
		'ListPriceLow' => array(
			'type' => 'meta',
			'name' => 'listpricelow',
		),
		'ListTeam' => array(
			'type' => 'meta',
			'name' => 'listteam',
		),
		'ListTeamKey' => array(
			'type' => 'meta',
			'name' => 'listteamkey',
		),
		'ListTeamName' => array(
			'type' => 'meta',
			'name' => 'listteamname',
		),
		'LivingArea' => array(
			'type' => 'meta',
			'name' => 'property_size',
		),
		'LivingAreaSource' => array(
			'type' => 'meta',
			'name' => 'livingareasource',
		),
		'LivingAreaUnits' => array(
			'type' => 'meta',
			'name' => 'livingareaunits',
		),
		'LockBoxLocation' => array(
			'type' => 'meta',
			'name' => 'lockboxlocation',
		),
		'LockBoxSerialNumber' => array(
			'type' => 'meta',
			'name' => 'lockboxserialnumber',
		),
		'LockBoxType' => array(
			'type' => 'meta',
			'name' => 'lockboxtype',
		),
		'Longitude' => array(
			'type' => 'meta',
			'name' => 'property_longitude',
		),
		'LotDimensionsSource' => array(
			'type' => 'meta',
			'name' => 'lotdimensionssource',
		),
		'LotFeatures' => array(
			'type' => 'meta',
			'name' => 'lotfeatures',
		),
		'LotSizeAcres' => array(
			'type' => 'meta',
			'name' => 'property_lot_size',
		),
		'LotSizeArea' => array(
			'type' => 'meta',
			'name' => 'lotsizearea',
		),
		'LotSizeDimensions' => array(
			'type' => 'meta',
			'name' => 'lotsizedimensions',
		),
		'LotSizeSource' => array(
			'type' => 'meta',
			'name' => 'lotsizesource',
		),
		'LotSizeSquareFeet' => array(
			'type' => 'meta',
			'name' => 'lotsizesquarefeet',
		),
		'LotSizeUnits' => array(
			'type' => 'meta',
			'name' => 'lotsizeunits',
		),
		'MainLevelBathrooms' => array(
			'type' => 'meta',
			'name' => 'mainlevelbathrooms',
		),
		'MainLevelBedrooms' => array(
			'type' => 'meta',
			'name' => 'mainlevelbedrooms',
		),
		'MaintenanceExpense' => array(
			'type' => 'meta',
			'name' => 'maintenanceexpense',
		),
		'MajorChangeTimestamp' => array(
			'type' => 'meta',
			'name' => 'majorchangetimestamp',
		),
		'MajorChangeType' => array(
			'type' => 'meta',
			'name' => 'majorchangetype',
		),
		'Make' => array(
			'type' => 'meta',
			'name' => 'make',
		),
		'ManagerExpense' => array(
			'type' => 'meta',
			'name' => 'managerexpense',
		),
		'MapCoordinate' => array(
			'type' => 'meta',
			'name' => 'mapcoordinate',
		),
		'MapCoordinateSource' => array(
			'type' => 'meta',
			'name' => 'mapcoordinatesource',
		),
		'MapURL' => array(
			'type' => 'meta',
			'name' => 'mapurl',
		),
		// ---- Media: routed to the gallery/attachment importer rather than a meta key ----
		'Media' => array(
			'type' => 'media',
			'name' => 'media',
		),
		'MiddleOrJuniorSchool' => array(
			'type' => 'meta',
			'name' => 'middleorjuniorschool',
		),
		'MiddleOrJuniorSchoolDistrict' => array(
			'type' => 'meta',
			'name' => 'middleorjuniorschooldistrict',
		),
		'MLSAreaMajor' => array(
			'type' => 'meta',
			'name' => 'mlsareamajor',
		),
		'MLSAreaMinor' => array(
			'type' => 'meta',
			'name' => 'mlsareaminor',
		),
		'MlsStatus' => array(
			'type' => 'meta',
			'name' => 'mlsstatus',
		),
		'MobileDimUnits' => array(
			'type' => 'meta',
			'name' => 'mobiledimunits',
		),
		'MobileHomeRemainsYN' => array(
			'type' => 'taxonomy',        
			'name' => 'property_features',
			'insert'=> 'Mobile Home Remains'
		),
		'MobileLength' => array(
			'type' => 'meta',
			'name' => 'mobilelength',
		),
		'MobileWidth' => array(
			'type' => 'meta',
			'name' => 'mobilewidth',
		),
		'Model' => array(
			'type' => 'meta',
			'name' => 'model',
		),
		'ModificationTimestamp' => array(
			'type' => 'meta',
			'name' => 'modificationtimestamp',
		),
		'NetOperatingIncome' => array(
			'type' => 'meta',
			'name' => 'netoperatingincome',
		),
		'NewConstructionYN' => array(
			'type' => 'taxonomy',        
			'name' => 'property_features',
			'insert' => 'New Construction',
		),
		'NewTaxesExpense' => array(
			'type' => 'meta',
			'name' => 'newtaxesexpense',
		),
		'NumberOfBuildings' => array(
			'type' => 'meta',
			'name' => 'numberofbuildings',
		),
		'NumberOfFullTimeEmployees' => array(
			'type' => 'meta',
			'name' => 'numberoffulltimeemployees',
		),
		'NumberOfLots' => array(
			'type' => 'meta',
			'name' => 'numberoflots',
		),
		'NumberOfPads' => array(
			'type' => 'meta',
			'name' => 'numberofpads',
		),
		'NumberOfPartTimeEmployees' => array(
			'type' => 'meta',
			'name' => 'numberofparttimeemployees',
		),
		'NumberOfSeparateElectricMeters' => array(
			'type' => 'meta',
			'name' => 'numberofseparateelectricmeters',
		),
		'NumberOfSeparateGasMeters' => array(
			'type' => 'meta',
			'name' => 'numberofseparategasmeters',
		),
		'NumberOfSeparateWaterMeters' => array(
			'type' => 'meta',
			'name' => 'numberofseparatewatermeters',
		),
		'NumberOfUnitsInCommunity' => array(
			'type' => 'meta',
			'name' => 'numberofunitsincommunity',
		),
		'NumberOfUnitsLeased' => array(
			'type' => 'meta',
			'name' => 'numberofunitsleased',
		),
		'NumberOfUnitsMoMo' => array(
			'type' => 'meta',
			'name' => 'numberofunitsmomo',
		),
		'NumberOfUnitsTotal' => array(
			'type' => 'meta',
			'name' => 'numberofunitstotal',
		),
		'NumberOfUnitsVacant' => array(
			'type' => 'meta',
			'name' => 'numberofunitsvacant',
		),
		'OccupantName' => array(
			'type' => 'meta',
			'name' => 'occupantname',
		),
		'OccupantPhone' => array(
			'type' => 'meta',
			'name' => 'occupantphone',
		),
		'OccupantType' => array(
			'type' => 'meta',
			'name' => 'occupanttype',
		),
		'OffMarketDate' => array(
			'type' => 'meta',
			'name' => 'offmarketdate',
		),
		'OffMarketTimestamp' => array(
			'type' => 'meta',
			'name' => 'offmarkettimestamp',
		),
		'OnMarketDate' => array(
			'type' => 'meta',
			'name' => 'onmarketdate',
		),
		'OnMarketTimestamp' => array(
			'type' => 'meta',
			'name' => 'onmarkettimestamp',
		),
		'OpenHouse' => array(
			'type' => 'meta',
			'name' => 'openhouse',
		),
		'OpenHouseModificationTimestamp' => array(
			'type' => 'meta',
			'name' => 'openhousemodificationtimestamp',
		),
		'OpenParkingSpaces' => array(
			'type' => 'meta',
			'name' => 'openparkingspaces',
		),
		'OpenParkingYN' => array(
			'type' => 'taxonomy',
			'name' => 'property_features',
			'insert' => 'Open Parking',
		),
		'OperatingExpense' => array(
			'type' => 'meta',
			'name' => 'operatingexpense',
		),
		'OperatingExpenseIncludes' => array(
			'type' => 'meta',
			'name' => 'operatingexpenseincludes',
		),
		'OriginalEntryTimestamp' => array(
			'type' => 'meta',
			'name' => 'originalentrytimestamp',
		),
		'OriginalListPrice' => array(
			'type' => 'meta',
			'name' => 'originallistprice',
		),
		'OriginatingSystem' => array(
			'type' => 'meta',
			'name' => 'originatingsystem',
		),
		'OriginatingSystemID' => array(
			'type' => 'meta',
			'name' => 'originatingsystemid',
		),
		'OriginatingSystemKey' => array(
			'type' => 'meta',
			'name' => 'originatingsystemkey',
		),
		'OriginatingSystemName' => array(
			'type' => 'meta',
			'name' => 'originatingsystemname',
		),
		'OtherEquipment' => array(
			'type' => 'meta',
			'name' => 'otherequipment',
		),
		'OtherExpense' => array(
			'type' => 'meta',
			'name' => 'otherexpense',
		),
		'OtherParking' => array(
			'type' => 'meta',
			'name' => 'otherparking',
		),
		'OtherStructures' => array(
			'type' => 'meta',
			'name' => 'otherstructures',
		),
		'OwnerName' => array(
			'type' => 'meta',
			'name' => 'ownername',
		),
		'OwnerPays' => array(
			'type' => 'meta',
			'name' => 'ownerpays',
		),
		'OwnerPhone' => array(
			'type' => 'meta',
			'name' => 'ownerphone',
		),
		'Ownership' => array(
			'type' => 'meta',
			'name' => 'ownership',
		),
		'OwnershipType' => array(
			'type' => 'meta',
			'name' => 'ownershiptype',
		),
		'ParcelNumber' => array(
			'type' => 'meta',
			'name' => 'parcelnumber',
		),
		'ParkingFeatures' => array(
			'type' => 'meta',
			'name' => 'parkingfeatures',
		),
		'ParkingTotal' => array(
			'type' => 'meta',
			'name' => 'parkingtotal',
		),
		'ParkManagerName' => array(
			'type' => 'meta',
			'name' => 'parkmanagername',
		),
		'ParkManagerPhone' => array(
			'type' => 'meta',
			'name' => 'parkmanagerphone',
		),
		'ParkName' => array(
			'type' => 'meta',
			'name' => 'parkname',
		),
		'PastureArea' => array(
			'type' => 'meta',
			'name' => 'pasturearea',
		),
		'PatioAndPorchFeatures' => array(
			'type' => 'meta',
			'name' => 'patioandporchfeatures',
		),
		'PendingTimestamp' => array(
			'type' => 'meta',
			'name' => 'pendingtimestamp',
		),
		'PestControlExpense' => array(
			'type' => 'meta',
			'name' => 'pestcontrolexpense',
		),
		'PetsAllowed' => array(
			'type' => 'meta',
			'name' => 'petsallowed',
		),
		'PhotosChangeTimestamp' => array(
			'type' => 'meta',
			'name' => 'photoschangetimestamp',
		),
		'PhotosCount' => array(
			'type' => 'meta',
			'name' => 'photoscount',
		),
		'PoolExpense' => array(
			'type' => 'meta',
			'name' => 'poolexpense',
		),
		'PoolFeatures' => array(
			'type' => 'meta',
			'name' => 'poolfeatures',
		),
		'PoolPrivateYN' => array(
			'type' => 'taxonomy',
			'name' => 'property_features',
			'insert' => 'Private Pool',
		),
		'Possession' => array(
			'type' => 'meta',
			'name' => 'possession',
		),
		'PossibleUse' => array(
			'type' => 'meta',
			'name' => 'possibleuse',
		),
		'PostalCity' => array(
			'type' => 'meta',
			'name' => 'postalcity',
		),
		'PostalCode' => array(
			'type' => 'meta',
			'name' => 'property_zip',
		),
		'PostalCodePlus4' => array(
			'type' => 'meta',
			'name' => 'postalcodeplus4',
		),
		'PowerProduction' => array(
			'type' => 'meta',
			'name' => 'powerproduction',
		),
		'PowerProductionType' => array(
			'type' => 'meta',
			'name' => 'powerproductiontype',
		),
		'PowerProductionYN' => array(
			'type' => 'taxonomy',        
			'name' => 'property_features',
			'insert'=> 'Power Production System'
		),
		'PreviousListPrice' => array(
			'type' => 'meta',
			'name' => 'previouslistprice',
		),
		'PriceChangeTimestamp' => array(
			'type' => 'meta',
			'name' => 'pricechangetimestamp',
		),
		'PrivateOfficeRemarks' => array(
			'type' => 'meta',
			'name' => 'privateofficeremarks',
		),
		'PrivateRemarks' => array(
			'type' => 'meta',
			'name' => 'owner_notes',
		),
		'ProfessionalManagementExpense' => array(
			'type' => 'meta',
			'name' => 'professionalmanagementexpense',
		),
		'PropertyAttachedYN' => array(
			'type' => 'taxonomy',
			'name' => 'property_features',
			'insert' => 'Property Attached',
		),
		'PropertyCondition' => array(
			'type' => 'meta',
			'name' => 'propertycondition',
		),
		'PropertySubType' => array(
			'type' => 'taxonomy',
			'name' => 'property_category',
		),
		'PropertyTimeZoneName' => array(
			'type' => 'meta',
			'name' => 'propertytimezonename',
		),
		'PropertyTimeZoneObservesDstYN' => array(
			'type' => 'taxonomy',        
			'name' => 'property_features',
			'insert'=> 'Property Time Zone Observes DST'
		),
		'PropertyTimeZoneStandardOffset' => array(
			'type' => 'meta',
			'name' => 'propertytimezonestandardoffset',
		),
		'PropertyType' => array(
			'type' => 'taxonomy',
			'name' => 'property_action_category',
		),
		// ---- PublicRemarks -> the post content body (the public listing description) ----
		'PublicRemarks' => array(
			'type' => 'content',
			'name' => 'content',
		),
		'PublicSurveyRange' => array(
			'type' => 'meta',
			'name' => 'publicsurveyrange',
		),
		'PublicSurveySection' => array(
			'type' => 'meta',
			'name' => 'publicsurveysection',
		),
		'PublicSurveyTownship' => array(
			'type' => 'meta',
			'name' => 'publicsurveytownship',
		),
		'PurchaseContractDate' => array(
			'type' => 'meta',
			'name' => 'purchasecontractdate',
		),
		'RangeArea' => array(
			'type' => 'meta',
			'name' => 'rangearea',
		),
		'RentControlYN' => array(
			'type' => 'taxonomy',        
			'name' => 'property_features',
			'insert'=> 'Rent Control Area'
		),
		'RentIncludes' => array(
			'type' => 'meta',
			'name' => 'rentincludes',
		),
		'RoadFrontageType' => array(
			'type' => 'meta',
			'name' => 'roadfrontagetype',
		),
		'RoadResponsibility' => array(
			'type' => 'meta',
			'name' => 'roadresponsibility',
		),
		'RoadSurfaceType' => array(
			'type' => 'meta',
			'name' => 'roadsurfacetype',
		),
		'Roof' => array(
			'type' => 'meta',
			'name' => 'roof',
		),
		'Rooms' => array(
			'type' => 'meta',
			'name' => 'rooms',
		),
		'RoomsTotal' => array(
			'type' => 'meta',
			'name' => 'property_rooms',
		),
		'RoomType' => array(
			'type' => 'meta',
			'name' => 'roomtype',
		),
		'RVParkingDimensions' => array(
			'type' => 'meta',
			'name' => 'rvparkingdimensions',
		),
		'SeatingCapacity' => array(
			'type' => 'meta',
			'name' => 'seatingcapacity',
		),
		'SecurityFeatures' => array(
			'type' => 'meta',
			'name' => 'securityfeatures',
		),
		'SeniorCommunityYN' => array(
			'type' => 'taxonomy',
			'name' => 'property_features',
			'insert' => 'Senior Community',
		),
		'SerialU' => array(
			'type' => 'meta',
			'name' => 'serialu',
		),
		'SerialX' => array(
			'type' => 'meta',
			'name' => 'serialx',
		),
		'SerialXX' => array(
			'type' => 'meta',
			'name' => 'serialxx',
		),
		'Sewer' => array(
			'type' => 'meta',
			'name' => 'sewer',
		),
		'ShowingAdvanceNotice' => array(
			'type' => 'meta',
			'name' => 'showingadvancenotice',
		),
		'ShowingAttendedYN' => array(
			'type' => 'taxonomy',        
			'name' => 'property_features',
			'insert'=> 'Requires An Attended Showing'
		),
		'ShowingConsiderations' => array(
			'type' => 'meta',
			'name' => 'showingconsiderations',
		),
		'ShowingContactName' => array(
			'type' => 'meta',
			'name' => 'showingcontactname',
		),
		'ShowingContactPhone' => array(
			'type' => 'meta',
			'name' => 'showingcontactphone',
		),
		'ShowingContactPhoneExt' => array(
			'type' => 'meta',
			'name' => 'showingcontactphoneext',
		),
		'ShowingContactType' => array(
			'type' => 'meta',
			'name' => 'showingcontacttype',
		),
		'ShowingDays' => array(
			'type' => 'meta',
			'name' => 'showingdays',
		),
		'ShowingEndTime' => array(
			'type' => 'meta',
			'name' => 'showingendtime',
		),
		'ShowingInstructions' => array(
			'type' => 'meta',
			'name' => 'showinginstructions',
		),
		'ShowingRequirements' => array(
			'type' => 'meta',
			'name' => 'showingrequirements',
		),
		'ShowingServiceName' => array(
			'type' => 'meta',
			'name' => 'showingservicename',
		),
		'ShowingStartTime' => array(
			'type' => 'meta',
			'name' => 'showingstarttime',
		),
		'SignOnPropertyYN' => array(
				'type' => 'taxonomy',        
			'name' => 'property_features',
			'insert'=> 'Sign On Property'
		),
		'SimpleDaysOnMarket' => array(
			'type' => 'meta',
			'name' => 'simpledaysonmarket',
		),
		'Skirt' => array(
			'type' => 'meta',
			'name' => 'skirt',
		),
		'SocialMedia' => array(
			'type' => 'meta',
			'name' => 'socialmedia',
		),
		'SourceSystem' => array(
			'type' => 'meta',
			'name' => 'sourcesystem',
		),
		'SourceSystemID' => array(
			'type' => 'meta',
			'name' => 'sourcesystemid',
		),
		'SourceSystemKey' => array(
			'type' => 'meta',
			'name' => 'sourcesystemkey',
		),
		'SourceSystemName' => array(
			'type' => 'meta',
			'name' => 'sourcesystemname',
		),
		'SpaFeatures' => array(
			'type' => 'meta',
			'name' => 'spafeatures',
		),
		'SpaYN' => array(
			'type' => 'taxonomy',
			'name' => 'property_features',
			'insert' => 'Spa',
		),
		'SpecialLicenses' => array(
			'type' => 'meta',
			'name' => 'speciallicenses',
		),
		'SpecialListingConditions' => array(
			'type' => 'meta',
			'name' => 'speciallistingconditions',
		),
		'StandardStatus' => array(
			'type' => 'taxonomy',
			'name' => 'property_status',
		),
		'StartShowingDate' => array(
			'type' => 'meta',
			'name' => 'startshowingdate',
		),
		'StateOrProvince' => array(
			'type' => 'meta',
			'name' => 'stateorprovince',
		),
		'StateRegion' => array(
			'type' => 'meta',
			'name' => 'stateregion',
		),
		'StatusChangeTimestamp' => array(
			'type' => 'meta',
			'name' => 'statuschangetimestamp',
		),
		'Stories' => array(
			'type' => 'meta',
			'name' => 'stories',
		),
		'StoriesTotal' => array(
			'type' => 'meta',
			'name' => 'storiestotal',
		),
		'StreetAdditionalInfo' => array(
			'type' => 'meta',
			'name' => 'streetadditionalinfo',
		),
		'StreetDirPrefix' => array(
			'type' => 'meta',
			'name' => 'streetdirprefix',
		),
		'StreetDirSuffix' => array(
			'type' => 'meta',
			'name' => 'streetdirsuffix',
		),
		'StreetName' => array(
			'type' => 'meta',
			'name' => 'streetname',
		),
		'StreetNumber' => array(
			'type' => 'meta',
			'name' => 'streetnumber',
		),
		'StreetNumberNumeric' => array(
			'type' => 'meta',
			'name' => 'streetnumbernumeric',
		),
		'StreetSuffix' => array(
			'type' => 'meta',
			'name' => 'streetsuffix',
		),
		'StreetSuffixModifier' => array(
			'type' => 'meta',
			'name' => 'streetsuffixmodifier',
		),
		'StructureType' => array(
			'type' => 'meta',
			'name' => 'structuretype',
		),
		'SubAgencyCompensation' => array(
			'type' => 'meta',
			'name' => 'subagencycompensation',
		),
		'SubAgencyCompensationType' => array(
			'type' => 'meta',
			'name' => 'subagencycompensationtype',
		),
		'SubdivisionName' => array(
			'type' => 'taxonomy',
			'name' => 'property_area',
		),
		'SuppliesExpense' => array(
			'type' => 'meta',
			'name' => 'suppliesexpense',
		),
		'SyndicateTo' => array(
			'type' => 'meta',
			'name' => 'syndicateto',
		),
		'SyndicationRemarks' => array(
			'type' => 'meta',
			'name' => 'syndicationremarks',
		),
		// ---- Tax / assessment block ----
		'TaxAnnualAmount' => array(
			'type' => 'meta',
			'name' => 'taxannualamount',
		),
		'TaxAnnualAmountPerLivingAreaUnit' => array(
			'type' => 'meta',
			'name' => 'taxannualamountperlivingareaunit',
		),
		'TaxAnnualAmountPerSquareFoot' => array(
			'type' => 'meta',
			'name' => 'taxannualamountpersquarefoot',
		),
		'TaxAssessedValue' => array(
			'type' => 'meta',
			'name' => 'taxassessedvalue',
		),
		'TaxBlock' => array(
			'type' => 'meta',
			'name' => 'taxblock',
		),
		'TaxBookNumber' => array(
			'type' => 'meta',
			'name' => 'taxbooknumber',
		),
		'TaxLegalDescription' => array(
			'type' => 'meta',
			'name' => 'taxlegaldescription',
		),
		'TaxLot' => array(
			'type' => 'meta',
			'name' => 'taxlot',
		),
		'TaxMapNumber' => array(
			'type' => 'meta',
			'name' => 'taxmapnumber',
		),
		'TaxOtherAnnualAssessmentAmount' => array(
			'type' => 'meta',
			'name' => 'taxotherannualassessmentamount',
		),
		'TaxParcelLetter' => array(
			'type' => 'meta',
			'name' => 'taxparcelletter',
		),
		'TaxStatusCurrent' => array(
			'type' => 'meta',
			'name' => 'taxstatuscurrent',
		),
		'TaxTract' => array(
			'type' => 'meta',
			'name' => 'taxtract',
		),
		'TaxYear' => array(
			'type' => 'meta',
			'name' => 'taxyear',
		),
		'TenantPays' => array(
			'type' => 'meta',
			'name' => 'tenantpays',
		),
		'Topography' => array(
			'type' => 'meta',
			'name' => 'topography',
		),
		'TotalActualRent' => array(
			'type' => 'meta',
			'name' => 'totalactualrent',
		),
		'Township' => array(
			'type' => 'meta',
			'name' => 'township',
		),
		'TransactionBrokerCompensation' => array(
			'type' => 'meta',
			'name' => 'transactionbrokercompensation',
		),
		'TransactionBrokerCompensationType' => array(
			'type' => 'meta',
			'name' => 'transactionbrokercompensationtype',
		),
		'TrashExpense' => array(
			'type' => 'meta',
			'name' => 'trashexpense',
		),
		'UnitNumber' => array(
			'type' => 'meta',
			'name' => 'unitnumber',
		),
		'UnitsFurnished' => array(
			'type' => 'meta',
			'name' => 'unitsfurnished',
		),
		'UnitTypes' => array(
			'type' => 'meta',
			'name' => 'unittypes',
		),
		'UnitTypeType' => array(
			'type' => 'meta',
			'name' => 'unittypetype',
		),
		'UniversalPropertyId' => array(
			'type' => 'meta',
			'name' => 'universalpropertyid',
		),
		'UniversalPropertySubId' => array(
			'type' => 'meta',
			'name' => 'universalpropertysubid',
		),
		'UnparsedAddress' => array(
			'type' => 'meta',
			'name' => 'property_address',
		),
		'Utilities' => array(
			'type' => 'meta',
			'name' => 'utilities',
		),
		'VacancyAllowance' => array(
			'type' => 'meta',
			'name' => 'vacancyallowance',
		),
		'VacancyAllowanceRate' => array(
			'type' => 'meta',
			'name' => 'vacancyallowancerate',
		),
		'Vegetation' => array(
			'type' => 'meta',
			'name' => 'vegetation',
		),
		'VideosChangeTimestamp' => array(
			'type' => 'meta',
			'name' => 'videoschangetimestamp',
		),
		'VideosCount' => array(
			'type' => 'meta',
			'name' => 'videoscount',
		),
		'View' => array(
			'type' => 'meta',
			'name' => 'view',
		),
		'ViewYN' => array(
			'type' => 'taxonomy',
			'name' => 'property_features',
			'insert' => 'Has a View',
		),
		'VirtualTourURLBranded' => array(
			'type' => 'meta',
			'name' => 'virtualtoururlbranded',
		),
		'VirtualTourURLUnbranded' => array(
			'type' => 'meta',
			'name' => 'embed_virtual_tour',
		),
		'WalkScore' => array(
			'type' => 'meta',
			'name' => 'walkscore',
		),
		'WaterBodyName' => array(
			'type' => 'meta',
			'name' => 'waterbodyname',
		),
		'WaterfrontFeatures' => array(
			'type' => 'meta',
			'name' => 'waterfrontfeatures',
		),
		'WaterfrontYN' => array(
			'type' => 'taxonomy',
			'name' => 'property_features',
			'insert' => 'Has Waterfront',
		),
		'WaterSewerExpense' => array(
			'type' => 'meta',
			'name' => 'watersewerexpense',
		),
		'WaterSource' => array(
			'type' => 'meta',
			'name' => 'watersource',
		),
		'WindowFeatures' => array(
			'type' => 'meta',
			'name' => 'windowfeatures',
		),
		'WithdrawnDate' => array(
			'type' => 'meta',
			'name' => 'withdrawndate',
		),
		'WoodedArea' => array(
			'type' => 'meta',
			'name' => 'woodedarea',
		),
		'WorkmansCompensationExpense' => array(
			'type' => 'meta',
			'name' => 'workmanscompensationexpense',
		),
		// ---- Year built / establishment & zoning (tail of the map) ----
		'YearBuilt' => array(
			'type' => 'meta',
			'name' => 'yearbuilt',
		),
		'YearBuiltDetails' => array(
			'type' => 'meta',
			'name' => 'yearbuiltdetails',
		),
		'YearBuiltEffective' => array(
			'type' => 'meta',
			'name' => 'yearbuilteffective',
		),
		'YearBuiltSource' => array(
			'type' => 'meta',
			'name' => 'yearbuiltsource',
		),
		'YearEstablished' => array(
			'type' => 'meta',
			'name' => 'yearestablished',
		),
		'YearsCurrentOwner' => array(
			'type' => 'meta',
			'name' => 'yearscurrentowner',
		),
		'Zoning' => array(
			'type' => 'meta',
			'name' => 'zoning',
		),
		'ZoningDescription' => array(
			'type' => 'meta',
			'name' => 'zoningdescription',
		),
	);
	// Hand the completed map back to the caller.
	return $theme_schema;
}


/**
 * Build a slug => label map of the taxonomies registered for a post type.
 *
 * @param string $post_type Post type name to inspect.
 * @return array Associative array of taxonomy slug => human-readable label.
 */
function mlsimport_get_custom_post_type_taxonomies($post_type) {
    // Get the taxonomies associated with the custom post type
	$taxonomies = get_object_taxonomies($post_type , 'objects');
    
    // Initialize an array to hold the slug => label pairs
    $taxonomy_array = array();
    
    // Loop through the taxonomies and fill the array
    foreach ($taxonomies as $taxonomy_slug => $taxonomy) {
        // Key by slug, store the taxonomy's display label.
        $taxonomy_array[$taxonomy_slug] = $taxonomy->label;
    }
    
    return $taxonomy_array;
}


/*
 *
 * Request list of ready to go MLS
 *
 *
 */

/**
 * Whitelist of HTML tags/attributes allowed in MLSImport-rendered content.
 *
 * Intended for use with wp_kses() when echoing plugin-generated markup: keeps a
 * small set of formatting tags plus the form controls (select/option/input/
 * fieldset/label) the admin UI emits. (The preceding block comment is a stale
 * copy/paste and does not describe this function.)
 *
 * @return array Tag => allowed-attributes map in wp_kses() format.
 */
 function mlsimport_allowed_html_tags_content() {
    // Define the allowable HTML tags and their attributes
    $allowed_tags = array(
        'a' => array(
            'href' => array(),
            'title' => array(),
            'rel' => array(),
            'target' => array(),
        ),
        'b' => array(),
        'i' => array(),
        'p' => array(),
        'br' => array(),
        'ul' => array(),
        'ol' => array(),
        'li' => array(),
        'strong' => array(),
        'em' => array(),
        'blockquote' => array(),
        'code' => array(),
        'pre' => array(),
        'select' => array( // Allow <select> and its attributes
            'name' => array(),
            'id' => array(),
            'class' => array(),
            'multiple' => array(),
            'required' => array(),
        ),
        'option' => array( // Allow <option> and its attributes
            'value' => array(),
            'selected' => array(),
        ),
		'div' => array( // Allow <div> and its attributes
            'id' => array(),
            'class' => array(),
            'style' => array(), // Use with caution, considering inline CSS security implications
        ),
		'h4' => array( 
            'id' => array(),
            'class' => array(),
           
        ),
		'label' => array( 
            'id' => array(),
            'class' => array(),
           
        ),
		'fieldset' => array( 
            'id' => array(),
            'class' => array(),
           
        ),
		'input' => array( 
            'id' => array(),
            'class' => array(),
            'type' => array(), 
			'name' => array(), 
			'value' => array(), 
			'checked'=>array()
			
			
        ),
    );


    return $allowed_tags;
}


/*
 *
 * Request list of ready to go MLS
 *
 *
 */
/**
 * Fetch the SaaS list of "ready to go" MLS systems, formatted for an autocomplete.
 *
 * Result is cached in a 24h transient. On a fresh fetch the raw MLS list is turned
 * into an array of {label, value} pairs (with a "My MLS is not on this list" entry
 * prepended) and JSON-encoded; on API failure a single-element error array is returned.
 *
 * @return string|array JSON string of autofill pairs on success, or an array
 *                      carrying an error message under key '0' on failure.
 */
function mlsimport_saas_request_list() {

	// Serve from the cached transient when present.
	$mls_data = get_transient( 'mlsimport_ready_to_go_mlsimport_data' );

	// Cache miss: hit the SaaS API for the current MLS list.
	if (  false === $mls_data  ) {
		$theme_Start = new ThemeImport();
		$values      = array();

		// GET the MLS catalogue from the SaaS endpoint.
		$answer = $theme_Start::globalApiRequestSaas( 'mls', $values, 'GET' );

		// Success: reshape the list into autocomplete {label,value} pairs.
		if ( isset( $answer['success'] ) &&  true === $answer['success']  ) {
			$mls_data      = $answer['mls_list'];
			// Prepend the "not listed" opt-out choice.
			$mls_data['0'] = esc_html__( 'My MLS is not on this list', 'mlsimport' );

			$autofill_array = array();
			// Convert each key => label into a label/value pair.
			foreach ( $mls_data as $key => $value ) {
					$temp_array       = array(
						'label' => $value,
						'value' => $key,
					);
					$autofill_array[] = $temp_array;
			}

			// Encode the pairs and cache for 24 hours.
			$mls_data = wp_json_encode( $autofill_array );

			set_transient( 'mlsimport_ready_to_go_mlsimport_data', $mls_data, 60 * 60 * 24 );
                } else {
                        // Failure: return a single-entry array carrying the error message.
                        $mls_data = array();
                        $error_message = isset( $answer['error_message'] ) && ! empty( $answer['error_message'] )
                                ? $answer['error_message']
                                : esc_html__( 'We could not connect to MLSimport Api', 'mlsimport' );
                        $mls_data['0'] = esc_html( $error_message );
                }
        }

	return $mls_data;
}


/*
 *  sanitize multidimensional array
 *
 *
 * */
/**
 * Recursively sanitise a value (scalar or nested array) for safe storage.
 *
 * Walks arrays depth-first, applying sanitize_text_field( wp_unslash() ) to every
 * leaf; a scalar input is sanitised directly.
 *
 * @param mixed $data Array or scalar to sanitise.
 * @return mixed Sanitised value of the same shape as the input.
 */
function mlsimport_sanitize_multi_dimensional_array($data){
	// Arrays: recurse into each element.
	if ( is_array( $data ) ) {
        foreach ( $data as $key => $value ) {
            // Nested array -> recurse; otherwise sanitise the leaf value.
            if ( is_array( $value ) ) {
                $data[ $key ] = mlsimport_sanitize_multi_dimensional_array( $value );
            } else {
                
                $data[ $key ] = sanitize_text_field( wp_unslash( $value ));
            }
        }
    } else {
        // Scalar input: sanitise directly.
        $data = sanitize_text_field( wp_unslash( $data) );
    }

    return $data;
}


 /*
 *  Lopp troght the listings and get Listing key
 *
 *
 *
 *
 *
 * */

/**
 * Cron entry point for the daily SaaS reconciliation pass.
 *
 * Bails out early unless at least one non-trashed import task has a title, then
 * calls the one deep reconciliation interface when an MLS name is configured.
 *
 * The same function handles the deduplicated one-hour retry hook. Trigger code
 * intentionally owns no snapshot, status, batching, or deletion decisions.
 *
 * @return array<string, int|string>|null Reconciliation Outcome, or null when ineligible.
 */
function mlsimport_saas_reconciliation_event_function() {

	// Pull the API token (side effect: ensures a fresh token) and plugin options.
	global $mlsimport, $wpdb;
	$token   = $mlsimport->admin->mlsimport_saas_get_mls_api_token_from_transient();
	$options = get_option( 'mlsimport_admin_options' );

	// Only run reconciliation if at least one import task has a title.
	$has_titled_task = $wpdb->get_var(
		"SELECT ID FROM {$wpdb->posts}
		 WHERE post_type = 'mlsimport_item'
		   AND post_status != 'trash'
		   AND post_title != ''
		 LIMIT 1"
	);
	// No titled task -> nothing to reconcile, abort.
	if ( ! $has_titled_task ) {
		return null;
	}

	// Only reconcile when an MLS name is configured.
	if ( isset( $options['mlsimport_mls_name'] ) && '' !==  $options['mlsimport_mls_name']  ) {
		$environment = new Mlsimport_Reconciliation_WordPress_Environment(
			static function () use ( $mlsimport ): array {
				return $mlsimport->admin->mlsimport_saas_get_mls_reconciliation_data();
			}
		);

		$outcome = ( new Mlsimport_Reconciliation( $environment ) )->reconcile_current_listings();
		error_log( 'MLSImport reconciliation outcome: ' . wp_json_encode( $outcome ) );
		return $outcome;
	}

	return null;
}

/*
 *  Admin extra columns for MlsImport Items
 *
 *
 *
 *
 *
 * */

// Register the custom admin list-table columns for the Import Tasks post type.
add_filter( 'manage_edit-mlsimport_item_columns', 'mlsimport_items_columns_admin' );

// Guard against redeclaration when the filter/file is loaded more than once.
if ( ! function_exists( 'mlsimport_items_columns_admin' ) ) :

	/**
	 * Add MLSImport-specific columns to the Import Tasks admin list table.
	 *
	 * Drops the default comments column and appends Import Parameters, Last action
	 * and Auto Update Enabled columns.
	 *
	 * @param array $columns Existing column id => label map.
	 * @return array Modified column map.
	 */
	function mlsimport_items_columns_admin( $columns ) {
		// Keep a copy of two columns starting at offset 2 (to re-append later).
		$slice = array_slice( $columns, 2, 2 );
		// Remove the comments column from both the full set and the slice.
		unset( $columns['comments'] );
		unset( $slice['comments'] );
		// Trim the original column set down to the first two entries.
		$splice = array_splice( $columns, 2 );

		// Append the plugin's own columns.
		$columns['mlsimport_items_params'] = esc_html__( 'Import Parameters', 'mlsimport' );
		$columns['mlsimport_task_health']  = esc_html__( 'Status', 'mlsimport' );
		$columns['mlsimport_last_action']  = esc_html__( 'Last action', 'mlsimport' );
		$columns['mlsimport_autoupdates']  = esc_html__( 'Auto Update Enabled', 'mlsimport' );

		// Return the plugin columns followed by the preserved slice (reversed).
		return array_merge( $columns, array_reverse( $slice ) );
	}

endif; // end   wpestate_my_columns




/*
 *  Admin extra columns for MlsImport Items - Populate with data display value
 *
 *
 *
 *
 *
 * */
/**
 * Flatten an import-parameter value into a comma-separated display string.
 *
 * Arrays are joined with commas (trailing comma trimmed); scalars pass through.
 *
 * @param mixed $value Array of values or a single scalar.
 * @return string Comma-separated (or scalar) display string.
 */
function mlsimport_populate_columns_params_display_value( $value ) {
	$display_value = '';
	// Array: concatenate each element with a comma separator.
	if ( is_array( $value ) ) {
		foreach ( $value as $key_item => $item_name ) :
			$display_value .= $item_name . ',';
		endforeach;
		// Drop the trailing comma left by the loop.
		$display_value = rtrim( $display_value, ',' );
	} else {
		// Scalar: use as-is.
		$display_value = $value;
	}

	return $display_value;
}
/*
 *  Admin extra columns for MlsImport Items - Populate with data
 *
 *
 *
 *
 *
 * */

/**
 * Echo the configured import parameters for one Import Task into the admin column.
 *
 * Iterates the MLS field definitions and, for each non-hidden field, prints the
 * task's stored value. Fields NOT in the $select_all_none list show "ALL" when
 * their per-field checkbox meta is set; the rest always show the raw stored value.
 *
 * @param int $postID Import Task post ID.
 * @return void Output is echoed directly.
 */
function mlsimport_populate_columns_params_display( $postID ) {
	// Field definitions come from the admin class.
	global $mlsimport;
	$field_import = $mlsimport->admin->mlsimport_saas_return_mls_fields();

        // Fields whose stored value is always shown verbatim (never collapsed to "ALL").
        $select_all_none = array(
                'InternetAddressDisplayYN',
                'InternetEntireListingDisplayYN',
                'PostalCode',
               'ListAgentKey',
               'ListAgentMlsId',
               'BuyerAgentMlsId',
               'ListOfficeKey',
               'ListOfficeMlsId',
                'ListingID',
                'StandardStatus',
                'extraCity',
                'extraCounty',
                'Exclude_ListOfficeKey',
                'Exclude_ListOfficeMlsId',
                'Exclude_ListAgentKey',
                'Exclude_ListAgentMlsId',
                'CustomParameters',
                'MLSAreaMajor',
                'SubdivisionName',

        );
	// Walk every defined MLS field for this task.
	foreach ( $field_import as $key => $field ) :
		// Skip fields flagged hidden.
		if ( ! empty( $field['hidden'] ) ) {
			continue;
		}
		$display_value = '';
		// Derive the per-field meta keys (value + its "check" companion).
		$name_check    = strtolower( 'mlsimport_item_' . $key . '_check' );
		$name          = strtolower( 'mlsimport_item_' . $key );

		// Read the stored value and its checkbox flag.
		$value       = get_post_meta( $postID, $name, true );
		$value_check = get_post_meta( $postID, $name_check, true );

		// Normalise the checkbox flag to 0/1.
		$is_checkbox_admin = 0;
		if ( 1 ===  intval($value_check)  ) {
			$is_checkbox_admin = 1;
		}

		// For collapsible fields a set checkbox means "ALL"; otherwise show the value.
		if ( ! in_array( $key, $select_all_none ) ) {
			if ( 1 === intval($is_checkbox_admin)  ) {
				$display_value = esc_html__( 'ALL', 'mlsimport' );
			} else {
				$display_value = mlsimport_populate_columns_params_display_value( $value );
			}
		} else {
			// Always-verbatim fields: print the stored value.
			$display_value = mlsimport_populate_columns_params_display_value( $value );
		}

		// Render "<Label> : <value>" only when there is something to show.
		if ( '' !==  $display_value  ) { ?>
			<strong>
				<?php
				// Field label with the leading "Select " prefix stripped.
				print esc_html(  ucfirst( str_replace( 'Select ', '', $field['label'] ) ) );
				?>
			 :</strong>
			<?php
			print esc_html($display_value).'</br>';
			
		}
	endforeach;
}



// Render cell contents for the custom Import Tasks columns.
add_action( 'manage_posts_custom_column', 'mlsimport_populate_columns' );
// Guard against redeclaration.
if ( ! function_exists( 'mlsimport_populate_columns' ) ) :

	/**
	 * Output the value for each custom Import Tasks admin column.
	 *
	 * Handles the three plugin columns: import parameters (price range + field
	 * params), last action date, and whether auto-update (cron) is enabled.
	 *
	 * @param string $column Column id being rendered.
	 * @return void Output is echoed directly.
	 */
	function mlsimport_populate_columns( $column ) {

		// Current row's post is available via the global.
		global $post;

		// Import Parameters column: price range then the field parameter list.
		if ( 'mlsimport_items_params' === $column ) {
			// Read the configured min/max price filters as floats.
			$mlsimport_item_min_price = floatval( get_post_meta( $post->ID, 'mlsimport_item_min_price', true ) );
			$mlsimport_item_max_price = floatval( get_post_meta( $post->ID, 'mlsimport_item_max_price', true ) );
			?>

	
			<!-- Strong tag for emphasizing the label -->
			<strong>
				<?php esc_html_e('Minimum price:', 'mlsimport'); ?>
			</strong>

			<?php echo esc_html($mlsimport_item_min_price); ?><br>

			<strong>
				<?php esc_html_e('Maximum price:', 'mlsimport'); ?>
			</strong>
		
			<?php echo esc_html($mlsimport_item_max_price); ?><br>
			<?php
	
			// Then the per-field import parameters.
			mlsimport_populate_columns_params_display( $post->ID );
		} elseif ( 'mlsimport_task_health' === $column ) {
			// Status column (GitHub issue #200): surface stuck, failed, and
			// sync-overdue tasks directly in the list. All inputs are already
			// recorded — the run status meta (state, progress, heartbeat, error),
			// the sync watermark, and the auto-sync flag. The badge decision
			// itself lives in the pure, unit-tested mlsimport_task_health().
			$health_status = get_post_meta( $post->ID, 'mlsimport_import_run_status', true );
			$health        = mlsimport_task_health(
				is_array( $health_status ) ? $health_status : array(),
				(string) get_post_meta( $post->ID, 'mlsimport_last_date', true ),
				1 === (int) get_post_meta( $post->ID, 'mlsimport_item_stat_cron', true ),
				time(),
				// Watermarks are stored with wp_date() in site-local time, so
				// the overdue cutoff must be built the same way to compare.
				wp_date( 'Y-m-d\TH:i', time() - MLSIMPORT_TASK_HEALTH_OVERDUE_AFTER )
			);
			?>
			<span class="mlsimport-task-health mlsimport-task-health--<?php echo esc_attr( $health['level'] ); ?>">
				<?php echo esc_html( $health['label'] ); ?>
			</span>
			<p class="mlsimport-task-health__message"><?php echo esc_html( $health['message'] ); ?></p>
			<?php
		} elseif ( 'mlsimport_last_action' === $column ) {
			// Last action column: date of the most recent import activity.
			$last_date = get_post_meta( $post->ID, 'mlsimport_last_date', true );
			if ( '' !==  $last_date  ) {
				// Have a date: show it with an explanatory note.
				print esc_html($last_date) . ' </br>';
				esc_html_e( 'On this date we found new or edited listings.', 'mlsimport' );
			} else {
				// No date recorded (sync likely disabled).
				esc_html_e( 'Not available - sync option may be off.', 'mlsimport' );
			}
		} elseif ( 'mlsimport_autoupdates' === $column ) {
			// Auto Update column: reflects the cron-enabled meta flag.
			$mlsimport_item_stat_cron = esc_html( get_post_meta( $post->ID, 'mlsimport_item_stat_cron', true ) );

			// Positive flag -> "yes", otherwise "no".
			if ( intval( $mlsimport_item_stat_cron ) > 0 ) {
				?>
				yes
				<?php
			} else {
				?>
				no
				<?php
			}
		}
	}
endif;



/**
 * Wipe all MLSImport-created options and transients from the database.
 *
 * Deletes every option whose name begins with "mlsimport_" (both single-site and
 * network variants) and every matching transient (site and normal). Does NOT touch
 * imported posts/terms — options and transients only.
 *
 * @return bool Always true.
 */
function mlsimport_reset_plugin_data() {
    

    global $wpdb;

    // Remove all options stored by MLSImport
    // Collect every option name prefixed with "mlsimport_".
    $option_names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'mlsimport_' ) . '%' ) );
    // Delete each as both a normal and a network option.
    foreach ( $option_names as $option_name ) {
        delete_option( $option_name );
        delete_site_option( $option_name );
    }

    // Remove transients created by MLSImport
    // Match both normal and site transient row-name prefixes.
    $transient_patterns = array( '_transient_mlsimport_%', '_site_transient_mlsimport_%' );
    foreach ( $transient_patterns as $pattern ) {
        // Find the raw option rows backing these transients.
        $names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern ) );
        foreach ( $names as $name ) {
            // Strip the storage prefix and delete via the matching transient API.
            if ( strpos( $name, '_site_transient_' ) === 0 ) {
                $transient = substr( $name, strlen( '_site_transient_' ) );
                delete_site_transient( $transient );
            } else {
                $transient = substr( $name, strlen( '_transient_' ) );
                delete_transient( $transient );
            }
        }
    }

    return true;
}
