<?php
/**
 * Standalone (theme_id 990) property field sections.
 *
 * Groups the imported RESO fields into named sections (Interior, Exterior,
 * Financial, ...) for the single-property page. One function resolves a
 * section's rows; the nine section render fns are thin callers.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves a RESO field to the property-page section it belongs to.
 */
class Mlsimport_Property_Field_Sections {

	/**
	 * RESO field => [ section slug, RESO display label, RESO type ]. Generated
	 * from RESO Data Dictionary 2.0. Fields absent here — every MLS's proprietary
	 * fields — fall through to 'other' and are treated as plain strings.
	 */
	private const MAP = array(
		'AboveGradeFinishedArea'              => array( 'interior', 'Above Grade Finished Area', 'Number' ),
		'AboveGradeFinishedAreaSource'        => array( 'interior', 'Above Grade Finished Area Source', 'String List, Single' ),
		'AboveGradeFinishedAreaUnits'         => array( 'interior', 'Above Grade Finished Area Units', 'String List, Single' ),
		'AboveGradeUnfinishedArea'            => array( 'interior', 'Above Grade Unfinished Area', 'Number' ),
		'AboveGradeUnfinishedAreaSource'      => array( 'interior', 'Above Grade Unfinished Area Source', 'String List, Single' ),
		'AboveGradeUnfinishedAreaUnits'       => array( 'interior', 'Above Grade Unfinished Area Units', 'String List, Single' ),
		'AccessCode'                          => array( 'listing_info', 'Access Code', 'String' ),
		'AccessibilityFeatures'               => array( 'interior', 'Accessibility Features', 'String List, Multi' ),
		'ActivationDate'                      => array( 'listing_info', 'Activation Date', 'Date' ),
		'AdditionalParcelsDescription'        => array( 'financial', 'Additional Parcels Description', 'String' ),
		'AdditionalParcelsYN'                 => array( 'financial', 'Additional Parcels Yes/No', 'Boolean' ),
		'AnchorsCoTenants'                    => array( 'location', 'Anchors & Cotenants', 'String' ),
		'Appliances'                          => array( 'interior', 'Appliances', 'String List, Multi' ),
		'ArchitecturalStyle'                  => array( 'structure', 'Architectural Style', 'String List, Multi' ),
		'AssociationAmenities'                => array( 'financial', 'Association Amenities', 'String List, Multi' ),
		'AssociationFee'                      => array( 'financial', 'Association Fee', 'Number' ),
		'AssociationFee2'                     => array( 'financial', 'Association Fee 2', 'Number' ),
		'AssociationFee2Frequency'            => array( 'financial', 'Association Fee 2 Frequency', 'String List, Single' ),
		'AssociationFeeFrequency'             => array( 'financial', 'Association Fee Frequency', 'String List, Single' ),
		'AssociationFeeIncludes'              => array( 'financial', 'Association Fee Includes', 'String List, Multi' ),
		'AssociationName'                     => array( 'financial', 'Association Name', 'String' ),
		'AssociationName2'                    => array( 'financial', 'Association Name 2', 'String' ),
		'AssociationPhone'                    => array( 'financial', 'Association Phone', 'String' ),
		'AssociationPhone2'                   => array( 'financial', 'Association Phone 2', 'String' ),
		'AssociationYN'                       => array( 'financial', 'Association Yes/No', 'Boolean' ),
		'AttachedGarageYN'                    => array( 'exterior', 'Attached Garage Yes/No', 'Boolean' ),
		'AttributionContact'                  => array( 'listing_info', 'Attribution Contact', 'String' ),
		'AvailabilityDate'                    => array( 'listing_info', 'Availability Date', 'Date' ),
		'AvailableLeaseType'                  => array( 'financial', 'Available Lease Type', 'String List, Multi' ),
		'BackOnMarketDate'                    => array( 'listing_info', 'Back on Market Date', 'Date' ),
		'Basement'                            => array( 'interior', 'Basement', 'String List, Multi' ),
		'BasementYN'                          => array( 'interior', 'Basement Yes/No', 'Boolean' ),
		'BathroomsFull'                       => array( 'interior', 'Bathrooms Full', 'Number' ),
		'BathroomsHalf'                       => array( 'interior', 'Bathrooms Half', 'Number' ),
		'BathroomsOneQuarter'                 => array( 'interior', 'Bathrooms One Quarter', 'Number' ),
		'BathroomsPartial'                    => array( 'interior', 'Bathrooms Partial', 'Number' ),
		'BathroomsThreeQuarter'               => array( 'interior', 'Bathrooms Three Quarter', 'Number' ),
		'BathroomsTotalInteger'               => array( 'interior', 'Bathrooms Total Integer', 'Number' ),
		'BedroomsPossible'                    => array( 'interior', 'Bedrooms Possible', 'Number' ),
		'BedroomsTotal'                       => array( 'interior', 'Bedrooms Total', 'Number' ),
		'BelowGradeFinishedArea'              => array( 'interior', 'Below Grade Finished Area', 'Number' ),
		'BelowGradeFinishedAreaSource'        => array( 'interior', 'Below Grade Finished Area Source', 'String List, Single' ),
		'BelowGradeFinishedAreaUnits'         => array( 'interior', 'Below Grade Finished Area Units', 'String List, Single' ),
		'BelowGradeUnfinishedArea'            => array( 'interior', 'Below Grade Unfinished Area', 'Number' ),
		'BelowGradeUnfinishedAreaSource'      => array( 'interior', 'Below Grade Unfinished Area Source', 'String List, Single' ),
		'BelowGradeUnfinishedAreaUnits'       => array( 'interior', 'Below Grade Unfinished Area Units', 'String List, Single' ),
		'BodyType'                            => array( 'structure', 'Body Type', 'String List, Multi' ),
		'BuilderModel'                        => array( 'structure', 'Builder Model', 'String' ),
		'BuilderName'                         => array( 'structure', 'Builder Name', 'String' ),
		'BuildingAreaSource'                  => array( 'structure', 'Building Area Source', 'String List, Single' ),
		'BuildingAreaTotal'                   => array( 'structure', 'Building Area Total', 'Number' ),
		'BuildingAreaUnits'                   => array( 'structure', 'Building Area Units', 'String List, Single' ),
		'BuildingFeatures'                    => array( 'structure', 'Building Features', 'String List, Multi' ),
		'BuildingName'                        => array( 'structure', 'Building Name', 'String' ),
		'BusinessName'                        => array( 'listing_info', 'Business Name', 'String' ),
		'BusinessType'                        => array( 'listing_info', 'Business Type', 'String List, Multi' ),
		'BuyerAgentAOR'                       => array( 'listing_info', 'Buyer Agent AOR', 'String List, Single' ),
		'BuyerAgentDesignation'               => array( 'listing_info', 'Buyer Agent Designation', 'String List, Multi' ),
		'BuyerAgentDirectPhone'               => array( 'listing_info', 'Buyer Agent Direct Phone', 'String' ),
		'BuyerAgentEmail'                     => array( 'listing_info', 'Buyer Agent Email', 'String' ),
		'BuyerAgentFax'                       => array( 'listing_info', 'Buyer Agent Fax', 'String' ),
		'BuyerAgentFirstName'                 => array( 'listing_info', 'Buyer Agent First Name', 'String' ),
		'BuyerAgentFullName'                  => array( 'listing_info', 'Buyer Agent Full Name', 'String' ),
		'BuyerAgentHomePhone'                 => array( 'listing_info', 'Buyer Agent Home Phone', 'String' ),
		'BuyerAgentKey'                       => array( 'listing_info', 'Buyer Agent Key', 'String' ),
		'BuyerAgentLastName'                  => array( 'listing_info', 'Buyer Agent Last Name', 'String' ),
		'BuyerAgentMiddleName'                => array( 'listing_info', 'Buyer Agent Middle Name', 'String' ),
		'BuyerAgentMlsId'                     => array( 'listing_info', 'Buyer Agent MLS ID', 'String' ),
		'BuyerAgentMobilePhone'               => array( 'listing_info', 'Buyer Agent Mobile Phone', 'String' ),
		'BuyerAgentNamePrefix'                => array( 'listing_info', 'Buyer Agent Name Prefix', 'String' ),
		'BuyerAgentNameSuffix'                => array( 'listing_info', 'Buyer Agent Name Suffix', 'String' ),
		'BuyerAgentNationalAssociationId'     => array( 'listing_info', 'Buyer Agent National Association ID', 'String' ),
		'BuyerAgentOfficePhone'               => array( 'listing_info', 'Buyer Agent Office Phone', 'String' ),
		'BuyerAgentOfficePhoneExt'            => array( 'listing_info', 'Buyer Agent Office Phone Ext', 'String' ),
		'BuyerAgentPager'                     => array( 'listing_info', 'Buyer Agent Pager', 'String' ),
		'BuyerAgentPreferredPhone'            => array( 'listing_info', 'Buyer Agent Preferred Phone', 'String' ),
		'BuyerAgentPreferredPhoneExt'         => array( 'listing_info', 'Buyer Agent Preferred Phone Ext', 'String' ),
		'BuyerAgentStateLicense'              => array( 'listing_info', 'Buyer Agent State License', 'String' ),
		'BuyerAgentTollFreePhone'             => array( 'listing_info', 'Buyer Agent Toll Free Phone', 'String' ),
		'BuyerAgentURL'                       => array( 'listing_info', 'Buyer Agent URL', 'String' ),
		'BuyerAgentVoiceMail'                 => array( 'listing_info', 'Buyer Agent Voice Mail', 'String' ),
		'BuyerAgentVoiceMailExt'              => array( 'listing_info', 'Buyer Agent Voice Mail Ext', 'String' ),
		'BuyerBrokerageCompensation'          => array( 'listing_info', 'Buyer Brokerage Compensation', 'String' ),
		'BuyerBrokerageCompensationType'      => array( 'listing_info', 'Buyer Brokerage Compensation Type', 'String List, Single' ),
		'BuyerFinancing'                      => array( 'listing_info', 'Buyer Financing', 'String List, Multi' ),
		'BuyerOfficeAOR'                      => array( 'listing_info', 'Buyer Office AOR', 'String List, Single' ),
		'BuyerOfficeEmail'                    => array( 'listing_info', 'Buyer Office Email', 'String' ),
		'BuyerOfficeFax'                      => array( 'listing_info', 'Buyer Office Fax', 'String' ),
		'BuyerOfficeKey'                      => array( 'listing_info', 'Buyer Office Key', 'String' ),
		'BuyerOfficeMlsId'                    => array( 'listing_info', 'Buyer Office MLS ID', 'String' ),
		'BuyerOfficeName'                     => array( 'listing_info', 'Buyer Office Name', 'String' ),
		'BuyerOfficeNationalAssociationId'    => array( 'listing_info', 'Buyer Office National Association ID', 'String' ),
		'BuyerOfficePhone'                    => array( 'listing_info', 'Buyer Office Phone', 'String' ),
		'BuyerOfficePhoneExt'                 => array( 'listing_info', 'Buyer Office Phone Ext', 'String' ),
		'BuyerOfficeURL'                      => array( 'listing_info', 'Buyer Office URL', 'String' ),
		'BuyerTeamKey'                        => array( 'listing_info', 'Buyer Team Key', 'String' ),
		'BuyerTeamName'                       => array( 'listing_info', 'Buyer Team Name', 'String' ),
		'CableTvExpense'                      => array( 'financial', 'Cable TV Expense', 'Number' ),
		'CancellationDate'                    => array( 'listing_info', 'Cancellation Date', 'Date' ),
		'CapRate'                             => array( 'financial', 'Cap Rate', 'Number' ),
		'CarportSpaces'                       => array( 'exterior', 'Carport Spaces', 'Number' ),
		'CarportYN'                           => array( 'exterior', 'Carport Yes/No', 'Boolean' ),
		'CarrierRoute'                        => array( 'location', 'Carrier Route', 'String' ),
		'City'                                => array( 'location', 'City', 'String List, Single' ),
		'CityRegion'                          => array( 'location', 'City Region', 'String' ),
		'CloseDate'                           => array( 'listing_info', 'Close Date', 'Date' ),
		'ClosePrice'                          => array( 'listing_info', 'Close Price', 'Number' ),
		'CoBuyerAgentAOR'                     => array( 'listing_info', 'Co-Buyer Agent AOR', 'String List, Single' ),
		'CoBuyerAgentDesignation'             => array( 'listing_info', 'Co-Buyer Agent Designation', 'String List, Multi' ),
		'CoBuyerAgentDirectPhone'             => array( 'listing_info', 'Co-Buyer Agent Direct Phone', 'String' ),
		'CoBuyerAgentEmail'                   => array( 'listing_info', 'Co-Buyer Agent Email', 'String' ),
		'CoBuyerAgentFax'                     => array( 'listing_info', 'Co-Buyer Agent Fax', 'String' ),
		'CoBuyerAgentFirstName'               => array( 'listing_info', 'Co-Buyer Agent First Name', 'String' ),
		'CoBuyerAgentFullName'                => array( 'listing_info', 'Co-Buyer Agent Full Name', 'String' ),
		'CoBuyerAgentHomePhone'               => array( 'listing_info', 'Co-Buyer Agent Home Phone', 'String' ),
		'CoBuyerAgentKey'                     => array( 'listing_info', 'Co-Buyer Agent Key', 'String' ),
		'CoBuyerAgentLastName'                => array( 'listing_info', 'Co-Buyer Agent Last Name', 'String' ),
		'CoBuyerAgentMiddleName'              => array( 'listing_info', 'Co-Buyer Agent Middle Name', 'String' ),
		'CoBuyerAgentMlsId'                   => array( 'listing_info', 'Co-Buyer Agent MLS ID', 'String' ),
		'CoBuyerAgentMobilePhone'             => array( 'listing_info', 'Co-Buyer Agent Mobile Phone', 'String' ),
		'CoBuyerAgentNamePrefix'              => array( 'listing_info', 'Co-Buyer Agent Name Prefix', 'String' ),
		'CoBuyerAgentNameSuffix'              => array( 'listing_info', 'Co-Buyer Agent Name Suffix', 'String' ),
		'CoBuyerAgentNationalAssociationId'   => array( 'listing_info', 'Co-Buyer Agent National Association ID', 'String' ),
		'CoBuyerAgentOfficePhone'             => array( 'listing_info', 'Co-Buyer Agent Office Phone', 'String' ),
		'CoBuyerAgentOfficePhoneExt'          => array( 'listing_info', 'Co-Buyer Agent Office Phone Ext', 'String' ),
		'CoBuyerAgentPager'                   => array( 'listing_info', 'Co-Buyer Agent Pager', 'String' ),
		'CoBuyerAgentPreferredPhone'          => array( 'listing_info', 'Co-Buyer Agent Preferred Phone', 'String' ),
		'CoBuyerAgentPreferredPhoneExt'       => array( 'listing_info', 'Co-Buyer Agent Preferred Phone Ext', 'String' ),
		'CoBuyerAgentStateLicense'            => array( 'listing_info', 'Co-Buyer Agent State License', 'String' ),
		'CoBuyerAgentTollFreePhone'           => array( 'listing_info', 'Co-Buyer Agent Toll Free Phone', 'String' ),
		'CoBuyerAgentURL'                     => array( 'listing_info', 'Co-Buyer Agent URL', 'String' ),
		'CoBuyerAgentVoiceMail'               => array( 'listing_info', 'Co-Buyer Agent Voice Mail', 'String' ),
		'CoBuyerAgentVoiceMailExt'            => array( 'listing_info', 'Co-Buyer Agent Voice Mail Ext', 'String' ),
		'CoBuyerOfficeAOR'                    => array( 'listing_info', 'Co-Buyer Office AOR', 'String List, Single' ),
		'CoBuyerOfficeEmail'                  => array( 'listing_info', 'Co-Buyer Office Email', 'String' ),
		'CoBuyerOfficeFax'                    => array( 'listing_info', 'Co-Buyer Office Fax', 'String' ),
		'CoBuyerOfficeKey'                    => array( 'listing_info', 'Co-Buyer Office Key', 'String' ),
		'CoBuyerOfficeMlsId'                  => array( 'listing_info', 'Co-Buyer Office MLS ID', 'String' ),
		'CoBuyerOfficeName'                   => array( 'listing_info', 'Co-Buyer Office Name', 'String' ),
		'CoBuyerOfficeNationalAssociationId'  => array( 'listing_info', 'Co-Buyer Office National Association ID', 'String' ),
		'CoBuyerOfficePhone'                  => array( 'listing_info', 'Co-Buyer Office Phone', 'String' ),
		'CoBuyerOfficePhoneExt'               => array( 'listing_info', 'Co-Buyer Office Phone Ext', 'String' ),
		'CoBuyerOfficeURL'                    => array( 'listing_info', 'Co-Buyer Office URL', 'String' ),
		'CoListAgentAOR'                      => array( 'listing_info', 'Co-List Agent AOR', 'String List, Single' ),
		'CoListAgentDesignation'              => array( 'listing_info', 'Co-List Agent Designation', 'String List, Multi' ),
		'CoListAgentDirectPhone'              => array( 'listing_info', 'Co-List Agent Direct Phone', 'String' ),
		'CoListAgentEmail'                    => array( 'listing_info', 'Co-List Agent Email', 'String' ),
		'CoListAgentFax'                      => array( 'listing_info', 'Co-List Agent Fax', 'String' ),
		'CoListAgentFirstName'                => array( 'listing_info', 'Co-List Agent First Name', 'String' ),
		'CoListAgentFullName'                 => array( 'listing_info', 'Co-List Agent Full Name', 'String' ),
		'CoListAgentHomePhone'                => array( 'listing_info', 'Co-List Agent Home Phone', 'String' ),
		'CoListAgentKey'                      => array( 'listing_info', 'Co-List Agent Key', 'String' ),
		'CoListAgentLastName'                 => array( 'listing_info', 'Co-List Agent Last Name', 'String' ),
		'CoListAgentMiddleName'               => array( 'listing_info', 'Co-List Agent Middle Name', 'String' ),
		'CoListAgentMlsId'                    => array( 'listing_info', 'Co-List Agent MLS ID', 'String' ),
		'CoListAgentMobilePhone'              => array( 'listing_info', 'Co-List Agent Mobile Phone', 'String' ),
		'CoListAgentNamePrefix'               => array( 'listing_info', 'Co-List Agent Name Prefix', 'String' ),
		'CoListAgentNameSuffix'               => array( 'listing_info', 'Co-List Agent Name Suffix', 'String' ),
		'CoListAgentNationalAssociationId'    => array( 'listing_info', 'Co-List Agent National Association ID', 'String' ),
		'CoListAgentOfficePhone'              => array( 'listing_info', 'Co-List Agent Office Phone', 'String' ),
		'CoListAgentOfficePhoneExt'           => array( 'listing_info', 'Co-List Agent Office Phone Ext', 'String' ),
		'CoListAgentPager'                    => array( 'listing_info', 'Co-List Agent Pager', 'String' ),
		'CoListAgentPreferredPhone'           => array( 'listing_info', 'Co-List Agent Preferred Phone', 'String' ),
		'CoListAgentPreferredPhoneExt'        => array( 'listing_info', 'Co-List Agent Preferred Phone Ext', 'String' ),
		'CoListAgentStateLicense'             => array( 'listing_info', 'Co-List Agent State License', 'String' ),
		'CoListAgentTollFreePhone'            => array( 'listing_info', 'Co-List Agent Toll Free Phone', 'String' ),
		'CoListAgentURL'                      => array( 'listing_info', 'Co-List Agent URL', 'String' ),
		'CoListAgentVoiceMail'                => array( 'listing_info', 'Co-List Agent Voice Mail', 'String' ),
		'CoListAgentVoiceMailExt'             => array( 'listing_info', 'Co-List Agent Voice Mail Ext', 'String' ),
		'CoListOfficeAOR'                     => array( 'listing_info', 'Co-List Office AOR', 'String List, Single' ),
		'CoListOfficeEmail'                   => array( 'listing_info', 'Co-List Office Email', 'String' ),
		'CoListOfficeFax'                     => array( 'listing_info', 'Co-List Office Fax', 'String' ),
		'CoListOfficeKey'                     => array( 'listing_info', 'Co-List Office Key', 'String' ),
		'CoListOfficeMlsId'                   => array( 'listing_info', 'Co-List Office MLS ID', 'String' ),
		'CoListOfficeName'                    => array( 'listing_info', 'Co-List Office Name', 'String' ),
		'CoListOfficeNationalAssociationId'   => array( 'listing_info', 'Co-List Office National Association ID', 'String' ),
		'CoListOfficePhone'                   => array( 'listing_info', 'Co-List Office Phone', 'String' ),
		'CoListOfficePhoneExt'                => array( 'listing_info', 'Co-List Office Phone Ext', 'String' ),
		'CoListOfficeURL'                     => array( 'listing_info', 'Co-List Office URL', 'String' ),
		'CommonInterest'                      => array( 'listing_info', 'Common Interest', 'String List, Single' ),
		'CommonWalls'                         => array( 'structure', 'Common Walls', 'String List, Multi' ),
		'CommunityFeatures'                   => array( 'location', 'Community Features', 'String List, Multi' ),
		'CompSaleYN'                          => array( 'listing_info', 'Comp Sale Yes/No', 'Boolean' ),
		'CompensationComments'                => array( 'listing_info', 'Compensation Comments', 'String' ),
		'Concessions'                         => array( 'listing_info', 'Concessions', 'String List, Single' ),
		'ConcessionsAmount'                   => array( 'listing_info', 'Concessions Amount', 'Number' ),
		'ConcessionsComments'                 => array( 'listing_info', 'Concessions Comments', 'String' ),
		'ConstructionMaterials'               => array( 'structure', 'Construction Materials', 'String List, Multi' ),
		'ContinentRegion'                     => array( 'location', 'Continent Region', 'String' ),
		'Contingency'                         => array( 'listing_info', 'Contingency', 'String' ),
		'ContingentDate'                      => array( 'listing_info', 'Contingent Date', 'Date' ),
		'ContractStatusChangeDate'            => array( 'listing_info', 'Contract Status Change Date', 'Date' ),
		'Cooling'                             => array( 'interior', 'Cooling', 'String List, Multi' ),
		'CoolingYN'                           => array( 'interior', 'Cooling Yes/No', 'Boolean' ),
		'CopyrightNotice'                     => array( 'listing_info', 'Copyright Notice', 'String' ),
		'Country'                             => array( 'location', 'Country', 'String List, Single' ),
		'CountryRegion'                       => array( 'location', 'Country Region', 'String' ),
		'CountyOrParish'                      => array( 'location', 'County Or Parish', 'String List, Single' ),
		'CoveredSpaces'                       => array( 'exterior', 'Covered Spaces', 'Number' ),
		'CropsIncludedYN'                     => array( 'exterior', 'Crops Included Yes/No', 'Boolean' ),
		'CrossStreet'                         => array( 'location', 'Cross Street', 'String' ),
		'CultivatedArea'                      => array( 'exterior', 'Cultivated Area', 'Number' ),
		'CumulativeDaysOnMarket'              => array( 'listing_info', 'Cumulative Days On Market', 'Number' ),
		'CurrentFinancing'                    => array( 'listing_info', 'Current Financing', 'String List, Multi' ),
		'CurrentUse'                          => array( 'structure', 'Current Use', 'String List, Multi' ),
		'DOH1'                                => array( 'structure', 'DOH 1', 'String' ),
		'DOH2'                                => array( 'structure', 'DOH 2', 'String' ),
		'DOH3'                                => array( 'structure', 'DOH 3', 'String' ),
		'DaysInMls'                           => array( 'listing_info', 'Days in MLS', 'Number' ),
		'DaysOnMarket'                        => array( 'listing_info', 'Days On Market', 'Number' ),
		'DaysOnSite'                          => array( 'listing_info', 'Days on Site', 'Number' ),
		'DevelopmentStatus'                   => array( 'structure', 'Development Status', 'String List, Multi' ),
		'DirectionFaces'                      => array( 'exterior', 'Direction Faces', 'String List, Single' ),
		'Directions'                          => array( 'location', 'Directions', 'String' ),
		'Disclaimer'                          => array( 'listing_info', 'Disclaimer', 'String' ),
		'Disclosures'                         => array( 'listing_info', 'Disclosures', 'String List, Multi' ),
		'DistanceToBusComments'               => array( 'location', 'Distance To Bus Comments', 'String' ),
		'DistanceToBusNumeric'                => array( 'location', 'Distance To Bus Numeric', 'Number' ),
		'DistanceToBusUnits'                  => array( 'location', 'Distance To Bus Units', 'String List, Single' ),
		'DistanceToElectricComments'          => array( 'utilities', 'Distance To Electric Comments', 'String' ),
		'DistanceToElectricNumeric'           => array( 'utilities', 'Distance To Electric Numeric', 'Number' ),
		'DistanceToElectricUnits'             => array( 'utilities', 'Distance To Electric Units', 'String List, Single' ),
		'DistanceToFreewayComments'           => array( 'location', 'Distance To Freeway Comments', 'String' ),
		'DistanceToFreewayNumeric'            => array( 'location', 'Distance To Freeway Numeric', 'Number' ),
		'DistanceToFreewayUnits'              => array( 'location', 'Distance To Freeway Units', 'String List, Single' ),
		'DistanceToGasComments'               => array( 'utilities', 'Distance To Gas Comments', 'String' ),
		'DistanceToGasNumeric'                => array( 'utilities', 'Distance To Gas Numeric', 'Number' ),
		'DistanceToGasUnits'                  => array( 'utilities', 'Distance To Gas Units', 'String List, Single' ),
		'DistanceToPhoneServiceComments'      => array( 'utilities', 'Distance To Phone Service Comments', 'String' ),
		'DistanceToPhoneServiceNumeric'       => array( 'utilities', 'Distance To Phone Service Numeric', 'Number' ),
		'DistanceToPhoneServiceUnits'         => array( 'utilities', 'Distance To Phone Service Units', 'String List, Single' ),
		'DistanceToPlaceofWorshipComments'    => array( 'location', 'Distance To Placeof Worship Comments', 'String' ),
		'DistanceToPlaceofWorshipNumeric'     => array( 'location', 'Distance To Placeof Worship Numeric', 'Number' ),
		'DistanceToPlaceofWorshipUnits'       => array( 'location', 'Distance To Placeof Worship Units', 'String List, Single' ),
		'DistanceToSchoolBusComments'         => array( 'location', 'Distance To School Bus Comments', 'String' ),
		'DistanceToSchoolBusNumeric'          => array( 'location', 'Distance To School Bus Numeric', 'Number' ),
		'DistanceToSchoolBusUnits'            => array( 'location', 'Distance To School Bus Units', 'String List, Single' ),
		'DistanceToSchoolsComments'           => array( 'location', 'Distance To Schools Comments', 'String' ),
		'DistanceToSchoolsNumeric'            => array( 'location', 'Distance To Schools Numeric', 'Number' ),
		'DistanceToSchoolsUnits'              => array( 'location', 'Distance To Schools Units', 'String List, Single' ),
		'DistanceToSewerComments'             => array( 'utilities', 'Distance To Sewer Comments', 'String' ),
		'DistanceToSewerNumeric'              => array( 'utilities', 'Distance To Sewer Numeric', 'Number' ),
		'DistanceToSewerUnits'                => array( 'utilities', 'Distance To Sewer Units', 'String List, Single' ),
		'DistanceToShoppingComments'          => array( 'location', 'Distance To Shopping Comments', 'String' ),
		'DistanceToShoppingNumeric'           => array( 'location', 'Distance To Shopping Numeric', 'Number' ),
		'DistanceToShoppingUnits'             => array( 'location', 'Distance To Shopping Units', 'String List, Single' ),
		'DistanceToStreetComments'            => array( 'location', 'Distance To Street Comments', 'String' ),
		'DistanceToStreetNumeric'             => array( 'location', 'Distance To Street Numeric', 'Number' ),
		'DistanceToStreetUnits'               => array( 'location', 'Distance To Street Units', 'String List, Single' ),
		'DistanceToWaterComments'             => array( 'utilities', 'Distance To Water Comments', 'String' ),
		'DistanceToWaterNumeric'              => array( 'utilities', 'Distance To Water Numeric', 'Number' ),
		'DistanceToWaterUnits'                => array( 'utilities', 'Distance To Water Units', 'String List, Single' ),
		'DocumentStatus'                      => array( 'listing_info', 'Document Status', 'String List, Single' ),
		'DocumentsAvailable'                  => array( 'listing_info', 'Documents Available', 'String List, Multi' ),
		'DocumentsChangeTimestamp'            => array( 'listing_info', 'Documents Change Timestamp', 'Timestamp' ),
		'DocumentsCount'                      => array( 'listing_info', 'Documents Count', 'Number' ),
		'DoorFeatures'                        => array( 'interior', 'Door Features', 'String List, Multi' ),
		'DualOrVariableRateCommissionYN'      => array( 'listing_info', 'Dual or Variable Rate Commission Yes/No', 'Boolean' ),
		'Electric'                            => array( 'utilities', 'Electric', 'String List, Multi' ),
		'ElectricExpense'                     => array( 'financial', 'Electric Expense', 'Number' ),
		'ElectricOnPropertyYN'                => array( 'utilities', 'Electric On Property Yes/No', 'Boolean' ),
		'ElementarySchool'                    => array( 'schools', 'Elementary School', 'String List, Single' ),
		'ElementarySchoolDistrict'            => array( 'schools', 'Elementary School District', 'String List, Single' ),
		'Elevation'                           => array( 'location', 'Elevation', 'Number' ),
		'ElevationUnits'                      => array( 'location', 'Elevation Units', 'String List, Single' ),
		'EntryLevel'                          => array( 'interior', 'Entry Level', 'Number' ),
		'EntryLocation'                       => array( 'interior', 'Entry Location', 'String' ),
		'Exclusions'                          => array( 'listing_info', 'Exclusions', 'String' ),
		'ExistingLeaseType'                   => array( 'financial', 'Existing Lease Type', 'String List, Multi' ),
		'ExpirationDate'                      => array( 'listing_info', 'Expiration Date', 'Date' ),
		'ExteriorFeatures'                    => array( 'exterior', 'Exterior Features', 'String List, Multi' ),
		'FarmCreditServiceInclYN'             => array( 'exterior', 'Farm Credit Service Incl Yes/No', 'Boolean' ),
		'FarmLandAreaSource'                  => array( 'exterior', 'Farm Land Area Source', 'String List, Single' ),
		'FarmLandAreaUnits'                   => array( 'exterior', 'Farm Land Area Units', 'String List, Single' ),
		'Fencing'                             => array( 'exterior', 'Fencing', 'String List, Multi' ),
		'FhaEligibility'                      => array( 'listing_info', 'FHA Eligibility', 'String List, Single' ),
		'FinancialDataSource'                 => array( 'financial', 'Financial Data Source', 'String List, Multi' ),
		'FireplaceFeatures'                   => array( 'interior', 'Fireplace Features', 'String List, Multi' ),
		'FireplaceYN'                         => array( 'interior', 'Fireplace Yes/No', 'Boolean' ),
		'FireplacesTotal'                     => array( 'interior', 'Fireplaces Total', 'Number' ),
		'Flooring'                            => array( 'interior', 'Flooring', 'String List, Multi' ),
		'FoundationArea'                      => array( 'structure', 'Foundation Area', 'Number' ),
		'FoundationDetails'                   => array( 'structure', 'Foundation Details', 'String List, Multi' ),
		'FrontageLength'                      => array( 'exterior', 'Frontage Length', 'String' ),
		'FrontageType'                        => array( 'exterior', 'Frontage Type', 'String List, Multi' ),
		'FuelExpense'                         => array( 'financial', 'Fuel Expense', 'Number' ),
		'Furnished'                           => array( 'interior', 'Furnished', 'String List, Single' ),
		'FurnitureReplacementExpense'         => array( 'financial', 'Furniture Replacement Expense', 'Number' ),
		'GarageSpaces'                        => array( 'exterior', 'Garage Spaces', 'Number' ),
		'GarageYN'                            => array( 'exterior', 'Garage Yes/No', 'Boolean' ),
		'GardenerExpense'                     => array( 'financial', 'Gardener Expense', 'Number' ),
		'GrazingPermitsBlmYN'                 => array( 'exterior', 'Grazing Permits BLM Yes/No', 'Boolean' ),
		'GrazingPermitsForestServiceYN'       => array( 'exterior', 'Grazing Permits Forest Service Yes/No', 'Boolean' ),
		'GrazingPermitsPrivateYN'             => array( 'exterior', 'Grazing Permits Private Yes/No', 'Boolean' ),
		'GreenBuildingVerificationType'       => array( 'utilities', 'Green Building Verification Type', 'String List, Multi' ),
		'GreenEnergyEfficient'                => array( 'utilities', 'Green Energy Efficient', 'String List, Multi' ),
		'GreenEnergyGeneration'               => array( 'utilities', 'Green Energy Generation', 'String List, Multi' ),
		'GreenIndoorAirQuality'               => array( 'utilities', 'Green Indoor Air Quality', 'String List, Multi' ),
		'GreenLocation'                       => array( 'utilities', 'Green Location', 'String List, Multi' ),
		'GreenSustainability'                 => array( 'utilities', 'Green Sustainability', 'String List, Multi' ),
		'GreenVerificationYN'                 => array( 'utilities', 'Green Verification Yes/No', 'Boolean' ),
		'GreenWaterConservation'              => array( 'utilities', 'Green Water Conservation', 'String List, Multi' ),
		'GrossIncome'                         => array( 'financial', 'Gross Income', 'Number' ),
		'GrossLivingAreaAnsi'                 => array( 'interior', 'Gross Living Area ANSI', 'Number' ),
		'GrossScheduledIncome'                => array( 'financial', 'Gross Scheduled Income', 'Number' ),
		'HabitableResidenceYN'                => array( 'structure', 'Habitable Residence Yes/No', 'Boolean' ),
		'Heating'                             => array( 'interior', 'Heating', 'String List, Multi' ),
		'HeatingYN'                           => array( 'interior', 'Heating Yes/No', 'Boolean' ),
		'HighSchool'                          => array( 'schools', 'High School', 'String List, Single' ),
		'HighSchoolDistrict'                  => array( 'schools', 'High School District', 'String List, Single' ),
		'HomeWarrantyYN'                      => array( 'listing_info', 'Home Warranty Yes/No', 'Boolean' ),
		'HorseAmenities'                      => array( 'exterior', 'Horse Amenities', 'String List, Multi' ),
		'HorseYN'                             => array( 'exterior', 'Horse Yes/No', 'Boolean' ),
		'HoursDaysOfOperation'                => array( 'listing_info', 'Hours/Days Of Operation', 'String List, Multi' ),
		'HoursDaysOfOperationDescription'     => array( 'listing_info', 'Hours/Days Of Operation Description', 'String' ),
		'Inclusions'                          => array( 'listing_info', 'Inclusions', 'String' ),
		'IncomeIncludes'                      => array( 'financial', 'Income Includes', 'String List, Multi' ),
		'InsuranceExpense'                    => array( 'financial', 'Insurance Expense', 'Number' ),
		'InteriorFeatures'                    => array( 'interior', 'Interior Features', 'String List, Multi' ),
		'InternetAddressDisplayYN'            => array( 'listing_info', 'Internet Address Display Yes/No', 'Boolean' ),
		'InternetAutomatedValuationDisplayYN' => array( 'listing_info', 'Internet Automated Valuation Display Yes/No', 'Boolean' ),
		'InternetConsumerCommentYN'           => array( 'listing_info', 'Internet Consumer Comment Yes/No', 'Boolean' ),
		'InternetEntireListingDisplayYN'      => array( 'listing_info', 'Internet Entire Listing Display Yes/No', 'Boolean' ),
		'IrrigationSource'                    => array( 'utilities', 'Irrigation Source', 'String List, Multi' ),
		'IrrigationWaterRightsAcres'          => array( 'utilities', 'Irrigation Water Rights Acres', 'Number' ),
		'IrrigationWaterRightsYN'             => array( 'utilities', 'Irrigation Water Rights Yes/No', 'Boolean' ),
		'LaborInformation'                    => array( 'listing_info', 'Labor Information', 'String List, Multi' ),
		'LandLeaseAmount'                     => array( 'financial', 'Land Lease Amount', 'Number' ),
		'LandLeaseAmountFrequency'            => array( 'financial', 'Land Lease Amount Frequency', 'String List, Single' ),
		'LandLeaseExpirationDate'             => array( 'financial', 'Land Lease Expiration Date', 'Date' ),
		'LandLeaseYN'                         => array( 'financial', 'Land Lease Yes/No', 'Boolean' ),
		'Latitude'                            => array( 'location', 'Latitude', 'Number' ),
		'LaundryFeatures'                     => array( 'interior', 'Laundry Features', 'String List, Multi' ),
		'LeasableArea'                        => array( 'structure', 'Leasable Area', 'Number' ),
		'LeasableAreaUnits'                   => array( 'structure', 'Leasable Area Units', 'String List, Single' ),
		'LeaseAmount'                         => array( 'listing_info', 'Lease Amount', 'Number' ),
		'LeaseAmountFrequency'                => array( 'listing_info', 'Lease Amount Frequency', 'String List, Single' ),
		'LeaseAssignableYN'                   => array( 'listing_info', 'Lease Assignable Yes/No', 'Boolean' ),
		'LeaseConsideredYN'                   => array( 'listing_info', 'Lease Considered Yes/No', 'Boolean' ),
		'LeaseExpiration'                     => array( 'listing_info', 'Lease Expiration', 'Date' ),
		'LeaseRenewalCompensation'            => array( 'listing_info', 'Lease Renewal Compensation', 'String List, Multi' ),
		'LeaseRenewalOptionYN'                => array( 'listing_info', 'Lease Renewal Option Yes/No', 'Boolean' ),
		'LeaseTerm'                           => array( 'financial', 'Lease Term', 'String List, Single' ),
		'Levels'                              => array( 'interior', 'Levels', 'String List, Multi' ),
		'License1'                            => array( 'structure', 'License 1', 'String' ),
		'License2'                            => array( 'structure', 'License 2', 'String' ),
		'License3'                            => array( 'structure', 'License 3', 'String' ),
		'LicensesExpense'                     => array( 'financial', 'Licenses Expense', 'Number' ),
		'ListAOR'                             => array( 'listing_info', 'List AOR', 'String List, Single' ),
		'ListAgentAOR'                        => array( 'listing_info', 'List Agent AOR', 'String List, Single' ),
		'ListAgentDesignation'                => array( 'listing_info', 'List Agent Designation', 'String List, Multi' ),
		'ListAgentDirectPhone'                => array( 'listing_info', 'List Agent Direct Phone', 'String' ),
		'ListAgentEmail'                      => array( 'listing_info', 'List Agent Email', 'String' ),
		'ListAgentFax'                        => array( 'listing_info', 'List Agent Fax', 'String' ),
		'ListAgentFirstName'                  => array( 'listing_info', 'List Agent First Name', 'String' ),
		'ListAgentFullName'                   => array( 'listing_info', 'List Agent Full Name', 'String' ),
		'ListAgentHomePhone'                  => array( 'listing_info', 'List Agent Home Phone', 'String' ),
		'ListAgentKey'                        => array( 'listing_info', 'List Agent Key', 'String' ),
		'ListAgentLastName'                   => array( 'listing_info', 'List Agent Last Name', 'String' ),
		'ListAgentMiddleName'                 => array( 'listing_info', 'List Agent Middle Name', 'String' ),
		'ListAgentMlsId'                      => array( 'listing_info', 'List Agent MLS ID', 'String' ),
		'ListAgentMobilePhone'                => array( 'listing_info', 'List Agent Mobile Phone', 'String' ),
		'ListAgentNamePrefix'                 => array( 'listing_info', 'List Agent Name Prefix', 'String' ),
		'ListAgentNameSuffix'                 => array( 'listing_info', 'List Agent Name Suffix', 'String' ),
		'ListAgentNationalAssociationId'      => array( 'listing_info', 'List Agent National Association ID', 'String' ),
		'ListAgentOfficePhone'                => array( 'listing_info', 'List Agent Office Phone', 'String' ),
		'ListAgentOfficePhoneExt'             => array( 'listing_info', 'List Agent Office Phone Ext', 'String' ),
		'ListAgentPager'                      => array( 'listing_info', 'List Agent Pager', 'String' ),
		'ListAgentPreferredPhone'             => array( 'listing_info', 'List Agent Preferred Phone', 'String' ),
		'ListAgentPreferredPhoneExt'          => array( 'listing_info', 'List Agent Preferred Phone Ext', 'String' ),
		'ListAgentStateLicense'               => array( 'listing_info', 'List Agent State License', 'String' ),
		'ListAgentTollFreePhone'              => array( 'listing_info', 'List Agent Toll Free Phone', 'String' ),
		'ListAgentURL'                        => array( 'listing_info', 'List Agent URL', 'String' ),
		'ListAgentVoiceMail'                  => array( 'listing_info', 'List Agent Voice Mail', 'String' ),
		'ListAgentVoiceMailExt'               => array( 'listing_info', 'List Agent Voice Mail Ext', 'String' ),
		'ListOfficeAOR'                       => array( 'listing_info', 'List Office AOR', 'String List, Single' ),
		'ListOfficeEmail'                     => array( 'listing_info', 'List Office Email', 'String' ),
		'ListOfficeFax'                       => array( 'listing_info', 'List Office Fax', 'String' ),
		'ListOfficeKey'                       => array( 'listing_info', 'List Office Key', 'String' ),
		'ListOfficeMlsId'                     => array( 'listing_info', 'List Office MLS ID', 'String' ),
		'ListOfficeName'                      => array( 'listing_info', 'List Office Name', 'String' ),
		'ListOfficeNationalAssociationId'     => array( 'listing_info', 'List Office National Association ID', 'String' ),
		'ListOfficePhone'                     => array( 'listing_info', 'List Office Phone', 'String' ),
		'ListOfficePhoneExt'                  => array( 'listing_info', 'List Office Phone Ext', 'String' ),
		'ListOfficeURL'                       => array( 'listing_info', 'List Office URL', 'String' ),
		'ListPrice'                           => array( 'listing_info', 'List Price', 'Number' ),
		'ListPriceLow'                        => array( 'listing_info', 'List Price Low', 'Number' ),
		'ListTeamKey'                         => array( 'listing_info', 'List Team Key', 'String' ),
		'ListTeamName'                        => array( 'listing_info', 'List Team Name', 'String' ),
		'ListingAgreement'                    => array( 'listing_info', 'Listing Agreement', 'String List, Single' ),
		'ListingContractDate'                 => array( 'listing_info', 'Listing Contract Date', 'Date' ),
		'ListingId'                           => array( 'listing_info', 'Listing ID', 'String' ),
		'ListingKey'                          => array( 'listing_info', 'Listing Key', 'String' ),
		'ListingService'                      => array( 'listing_info', 'Listing Service', 'String List, Single' ),
		'ListingTerms'                        => array( 'listing_info', 'Listing Terms', 'String List, Multi' ),
		'ListingURL'                          => array( 'listing_info', 'Listing URL', 'String' ),
		'ListingURLDescription'               => array( 'listing_info', 'Listing URL Description', 'String List, Single' ),
		'LivingArea'                          => array( 'interior', 'Living Area', 'Number' ),
		'LivingAreaSource'                    => array( 'interior', 'Living Area Source', 'String List, Single' ),
		'LivingAreaUnits'                     => array( 'interior', 'Living Area Units', 'String List, Single' ),
		'LockBoxLocation'                     => array( 'listing_info', 'Lock Box Location', 'String' ),
		'LockBoxSerialNumber'                 => array( 'listing_info', 'Lock Box Serial Number', 'String' ),
		'LockBoxType'                         => array( 'listing_info', 'Lock Box Type', 'String List, Multi' ),
		'Longitude'                           => array( 'location', 'Longitude', 'Number' ),
		'LotDimensionsSource'                 => array( 'exterior', 'Lot Dimensions Source', 'String List, Single' ),
		'LotFeatures'                         => array( 'exterior', 'Lot Features', 'String List, Multi' ),
		'LotSizeAcres'                        => array( 'exterior', 'Lot Size Acres', 'Number' ),
		'LotSizeArea'                         => array( 'exterior', 'Lot Size Area', 'Number' ),
		'LotSizeDimensions'                   => array( 'exterior', 'Lot Size Dimensions', 'String' ),
		'LotSizeSource'                       => array( 'exterior', 'Lot Size Source', 'String List, Single' ),
		'LotSizeSquareFeet'                   => array( 'exterior', 'Lot Size Square Feet', 'Number' ),
		'LotSizeUnits'                        => array( 'exterior', 'Lot Size Units', 'String List, Single' ),
		'MLSAreaMajor'                        => array( 'location', 'MLS Area Major', 'String List, Single' ),
		'MLSAreaMinor'                        => array( 'location', 'MLS Area Minor', 'String List, Single' ),
		'MainLevelBathrooms'                  => array( 'interior', 'Main Level Bathrooms', 'Number' ),
		'MainLevelBedrooms'                   => array( 'interior', 'Main Level Bedrooms', 'Number' ),
		'MaintenanceExpense'                  => array( 'financial', 'Maintenance Expense', 'Number' ),
		'MajorChangeTimestamp'                => array( 'listing_info', 'Major Change Timestamp', 'Timestamp' ),
		'MajorChangeType'                     => array( 'listing_info', 'Major Change Type', 'String List, Single' ),
		'Make'                                => array( 'structure', 'Make', 'String' ),
		'ManagerExpense'                      => array( 'financial', 'Manager Expense', 'Number' ),
		'MapCoordinate'                       => array( 'location', 'Map Coordinate', 'String' ),
		'MapCoordinateSource'                 => array( 'location', 'Map Coordinate Source', 'String' ),
		'MapURL'                              => array( 'location', 'Map URL', 'String' ),
		'MiddleOrJuniorSchool'                => array( 'schools', 'Middle Or Junior School', 'String List, Single' ),
		'MiddleOrJuniorSchoolDistrict'        => array( 'schools', 'Middle Or Junior School District', 'String List, Single' ),
		'MlsStatus'                           => array( 'listing_info', 'MLS Status', 'String List, Single' ),
		'MobileDimUnits'                      => array( 'structure', 'Mobile Dimensions Units', 'String List, Single' ),
		'MobileHomeRemainsYN'                 => array( 'structure', 'Mobile Home Remains Yes/No', 'Boolean' ),
		'MobileLength'                        => array( 'structure', 'Mobile Length', 'Number' ),
		'MobileWidth'                         => array( 'structure', 'Mobile Width', 'Number' ),
		'Model'                               => array( 'structure', 'Model', 'String' ),
		'ModificationTimestamp'               => array( 'listing_info', 'Modification Timestamp', 'Timestamp' ),
		'NetOperatingIncome'                  => array( 'financial', 'Net Operating Income', 'Number' ),
		'NewConstructionYN'                   => array( 'structure', 'New Construction Yes/No', 'Boolean' ),
		'NewTaxesExpense'                     => array( 'financial', 'New Taxes Expense', 'Number' ),
		'NumberOfBuildings'                   => array( 'structure', 'Number Of Buildings', 'Number' ),
		'NumberOfFullTimeEmployees'           => array( 'listing_info', 'Number Of Full Time Employees', 'Number' ),
		'NumberOfLots'                        => array( 'structure', 'Number Of Lots', 'Number' ),
		'NumberOfPads'                        => array( 'structure', 'Number Of Pads', 'Number' ),
		'NumberOfPartTimeEmployees'           => array( 'listing_info', 'Number Of Part Time Employees', 'Number' ),
		'NumberOfSeparateElectricMeters'      => array( 'utilities', 'Number Of Separate Electric Meters', 'Number' ),
		'NumberOfSeparateGasMeters'           => array( 'utilities', 'Number Of Separate Gas Meters', 'Number' ),
		'NumberOfSeparateWaterMeters'         => array( 'utilities', 'Number Of Separate Water Meters', 'Number' ),
		'NumberOfUnitsInCommunity'            => array( 'location', 'Number Of Units In Community', 'Number' ),
		'NumberOfUnitsLeased'                 => array( 'financial', 'Number Of Units Leased', 'Number' ),
		'NumberOfUnitsMoMo'                   => array( 'financial', 'Number Of Units Month To Month', 'Number' ),
		'NumberOfUnitsTotal'                  => array( 'structure', 'Number Of Units Total', 'Number' ),
		'NumberOfUnitsVacant'                 => array( 'financial', 'Number Of Units Vacant', 'Number' ),
		'OccupantName'                        => array( 'listing_info', 'Occupant Name', 'String' ),
		'OccupantPhone'                       => array( 'listing_info', 'Occupant Phone', 'String' ),
		'OccupantType'                        => array( 'listing_info', 'Occupant Type', 'String List, Single' ),
		'OffMarketDate'                       => array( 'listing_info', 'Off Market Date', 'Date' ),
		'OffMarketTimestamp'                  => array( 'listing_info', 'Off Market Timestamp', 'Timestamp' ),
		'OnMarketDate'                        => array( 'listing_info', 'On Market Date', 'Date' ),
		'OnMarketTimestamp'                   => array( 'listing_info', 'On Market Timestamp', 'Timestamp' ),
		'OpenHouseModificationTimestamp'      => array( 'listing_info', 'Open House Modification Timestamp', 'Timestamp' ),
		'OpenParkingSpaces'                   => array( 'exterior', 'Open Parking Spaces', 'Number' ),
		'OpenParkingYN'                       => array( 'exterior', 'Open Parking Yes/No', 'Boolean' ),
		'OperatingExpense'                    => array( 'financial', 'Operating Expense', 'Number' ),
		'OperatingExpenseIncludes'            => array( 'financial', 'Operating Expense Includes', 'String List, Multi' ),
		'OriginalEntryTimestamp'              => array( 'listing_info', 'Original Entry Timestamp', 'Timestamp' ),
		'OriginalListPrice'                   => array( 'listing_info', 'Original List Price', 'Number' ),
		'OriginatingSystemID'                 => array( 'listing_info', 'Originating System ID', 'String' ),
		'OriginatingSystemKey'                => array( 'listing_info', 'Originating System Key', 'String' ),
		'OriginatingSystemName'               => array( 'listing_info', 'Originating System Name', 'String' ),
		'OtherEquipment'                      => array( 'interior', 'Other Equipment', 'String List, Multi' ),
		'OtherExpense'                        => array( 'financial', 'Other Expense', 'Number' ),
		'OtherParking'                        => array( 'exterior', 'Other Parking', 'String' ),
		'OtherStructures'                     => array( 'exterior', 'Other Structures', 'String List, Multi' ),
		'OwnerName'                           => array( 'listing_info', 'Owner Name', 'String' ),
		'OwnerPays'                           => array( 'financial', 'Owner Pays', 'String List, Multi' ),
		'OwnerPhone'                          => array( 'listing_info', 'Owner Phone', 'String' ),
		'Ownership'                           => array( 'listing_info', 'Ownership', 'String' ),
		'OwnershipType'                       => array( 'listing_info', 'Ownership Type', 'String List, Single' ),
		'ParcelNumber'                        => array( 'financial', 'Parcel Number', 'String' ),
		'ParkManagerName'                     => array( 'location', 'Park Manager Name', 'String' ),
		'ParkManagerPhone'                    => array( 'location', 'Park Manager Phone', 'String' ),
		'ParkName'                            => array( 'location', 'Park Name', 'String' ),
		'ParkingFeatures'                     => array( 'exterior', 'Parking Features', 'String List, Multi' ),
		'ParkingTotal'                        => array( 'exterior', 'Parking Total', 'Number' ),
		'PastureArea'                         => array( 'exterior', 'Pasture Area', 'Number' ),
		'PatioAndPorchFeatures'               => array( 'exterior', 'Patio And Porch Features', 'String List, Multi' ),
		'PendingTimestamp'                    => array( 'listing_info', 'Pending Timestamp', 'Timestamp' ),
		'PestControlExpense'                  => array( 'financial', 'Pest Control Expense', 'Number' ),
		'PetsAllowed'                         => array( 'financial', 'Pets Allowed', 'String List, Multi' ),
		'PhotosChangeTimestamp'               => array( 'listing_info', 'Photos Change Timestamp', 'Timestamp' ),
		'PhotosCount'                         => array( 'listing_info', 'Photos Count', 'Number' ),
		'PoolExpense'                         => array( 'financial', 'Pool Expense', 'Number' ),
		'PoolFeatures'                        => array( 'exterior', 'Pool Features', 'String List, Multi' ),
		'PoolPrivateYN'                       => array( 'exterior', 'Pool Private Yes/No', 'Boolean' ),
		'Possession'                          => array( 'listing_info', 'Possession', 'String List, Multi' ),
		'PossibleUse'                         => array( 'structure', 'Possible Use', 'String List, Multi' ),
		'PostalCity'                          => array( 'location', 'Postal City', 'String List, Single' ),
		'PostalCode'                          => array( 'location', 'Postal Code', 'String' ),
		'PostalCodePlus4'                     => array( 'location', 'Postal Code Plus4', 'String' ),
		'PowerProductionType'                 => array( 'utilities', 'Power Production Type', 'String List, Multi' ),
		'PowerProductionYN'                   => array( 'utilities', 'Power Production Yes/No', 'Boolean' ),
		'PreviousListPrice'                   => array( 'listing_info', 'Previous List Price', 'Number' ),
		'PriceChangeTimestamp'                => array( 'listing_info', 'Price Change Timestamp', 'Timestamp' ),
		'PrivateOfficeRemarks'                => array( 'listing_info', 'Private Office Remarks', 'String' ),
		'PrivateRemarks'                      => array( 'listing_info', 'Private Remarks', 'String' ),
		'ProfessionalManagementExpense'       => array( 'financial', 'Professional Management Expense', 'Number' ),
		'PropertyAttachedYN'                  => array( 'structure', 'Property Attached Yes/No', 'Boolean' ),
		'PropertyCondition'                   => array( 'structure', 'Property Condition', 'String List, Multi' ),
		'PropertySubType'                     => array( 'listing_info', 'Property Sub Type', 'String List, Single' ),
		'PropertyTimeZoneName'                => array( 'listing_info', 'Property Time Zone Name', 'String List, Single' ),
		'PropertyTimeZoneObservesDstYN'       => array( 'listing_info', 'Property Time Zone Observes DST Yes/No', 'Boolean' ),
		'PropertyTimeZoneStandardOffset'      => array( 'listing_info', 'Property Time Zone Standard Offset', 'Number' ),
		'PropertyType'                        => array( 'listing_info', 'Property Type', 'String List, Single' ),
		'PublicRemarks'                       => array( 'listing_info', 'Public Remarks', 'String' ),
		'PublicSurveyRange'                   => array( 'financial', 'Public Survey Range', 'String' ),
		'PublicSurveySection'                 => array( 'financial', 'Public Survey Section', 'String' ),
		'PublicSurveyTownship'                => array( 'financial', 'Public Survey Township', 'String' ),
		'PurchaseContractDate'                => array( 'listing_info', 'Purchase Contract Date', 'Date' ),
		'RVParkingDimensions'                 => array( 'exterior', 'RV Parking Dimensions', 'String' ),
		'RangeArea'                           => array( 'exterior', 'Range Area', 'Number' ),
		'RentControlYN'                       => array( 'financial', 'Rent Control Yes/No', 'Boolean' ),
		'RentIncludes'                        => array( 'financial', 'Rent Includes', 'String List, Multi' ),
		'RoadFrontageType'                    => array( 'exterior', 'Road Frontage Type', 'String List, Multi' ),
		'RoadResponsibility'                  => array( 'exterior', 'Road Responsibility', 'String List, Multi' ),
		'RoadSurfaceType'                     => array( 'exterior', 'Road Surface Type', 'String List, Multi' ),
		'Roof'                                => array( 'exterior', 'Roof', 'String List, Multi' ),
		'RoomType'                            => array( 'interior', 'Room Type', 'String List, Multi' ),
		'RoomsTotal'                          => array( 'interior', 'Rooms Total', 'Number' ),
		'SeatingCapacity'                     => array( 'listing_info', 'Seating Capacity', 'Number' ),
		'SecurityFeatures'                    => array( 'interior', 'Security Features', 'String List, Multi' ),
		'SeniorCommunityYN'                   => array( 'location', 'Senior Community Yes/No', 'Boolean' ),
		'SerialU'                             => array( 'structure', 'Serial U', 'String' ),
		'SerialX'                             => array( 'structure', 'Serial X', 'String' ),
		'SerialXX'                            => array( 'structure', 'Serial XX', 'String' ),
		'Sewer'                               => array( 'utilities', 'Sewer', 'String List, Multi' ),
		'ShowingAdvanceNotice'                => array( 'listing_info', 'Showing Advance Notice', 'Number' ),
		'ShowingAttendedYN'                   => array( 'listing_info', 'Showing Attended Yes/No', 'Boolean' ),
		'ShowingConsiderations'               => array( 'listing_info', 'Showing Considerations', 'String List, Multi' ),
		'ShowingContactName'                  => array( 'listing_info', 'Showing Contact Name', 'String' ),
		'ShowingContactPhone'                 => array( 'listing_info', 'Showing Contact Phone', 'String' ),
		'ShowingContactPhoneExt'              => array( 'listing_info', 'Showing Contact Phone Ext', 'String' ),
		'ShowingContactType'                  => array( 'listing_info', 'Showing Contact Type', 'String List, Multi' ),
		'ShowingDays'                         => array( 'listing_info', 'Showing Days', 'String List, Multi' ),
		'ShowingEndTime'                      => array( 'listing_info', 'Showing End Time', 'Timestamp' ),
		'ShowingInstructions'                 => array( 'listing_info', 'Showing Instructions', 'String' ),
		'ShowingRequirements'                 => array( 'listing_info', 'Showing Requirements', 'String List, Multi' ),
		'ShowingServiceName'                  => array( 'listing_info', 'Showing Service Name', 'String List, Single' ),
		'ShowingStartTime'                    => array( 'listing_info', 'Showing Start Time', 'Timestamp' ),
		'SignOnPropertyYN'                    => array( 'listing_info', 'Sign On Property Yes/No', 'Boolean' ),
		'SimpleDaysOnMarket'                  => array( 'listing_info', 'Simple Days on Market', 'Number' ),
		'Skirt'                               => array( 'structure', 'Skirt', 'String List, Multi' ),
		'SourceSystemID'                      => array( 'listing_info', 'Source System ID', 'String' ),
		'SourceSystemKey'                     => array( 'listing_info', 'Source System Key', 'String' ),
		'SourceSystemName'                    => array( 'listing_info', 'Source System Name', 'String' ),
		'SpaFeatures'                         => array( 'interior', 'Spa Features', 'String List, Multi' ),
		'SpaYN'                               => array( 'interior', 'Spa Yes/No', 'Boolean' ),
		'SpecialLicenses'                     => array( 'listing_info', 'Special Licenses', 'String List, Multi' ),
		'SpecialListingConditions'            => array( 'listing_info', 'Special Listing Conditions', 'String List, Multi' ),
		'StandardStatus'                      => array( 'listing_info', 'Standard Status', 'String List, Single' ),
		'StartShowingDate'                    => array( 'listing_info', 'Start Showing Date', 'Date' ),
		'StateOrProvince'                     => array( 'location', 'State Or Province', 'String List, Single' ),
		'StateRegion'                         => array( 'location', 'State Region', 'String' ),
		'StatusChangeTimestamp'               => array( 'listing_info', 'Status Change Timestamp', 'Timestamp' ),
		'Stories'                             => array( 'structure', 'Stories', 'Number' ),
		'StoriesTotal'                        => array( 'structure', 'Stories Total', 'Number' ),
		'StreetAdditionalInfo'                => array( 'location', 'Street Additional Info', 'String' ),
		'StreetDirPrefix'                     => array( 'location', 'Street Direction Prefix', 'String List, Single' ),
		'StreetDirSuffix'                     => array( 'location', 'Street Direction Suffix', 'String List, Single' ),
		'StreetName'                          => array( 'location', 'Street Name', 'String' ),
		'StreetNumber'                        => array( 'location', 'Street Number', 'String' ),
		'StreetNumberNumeric'                 => array( 'location', 'Street Number Numeric', 'Number' ),
		'StreetSuffix'                        => array( 'location', 'Street Suffix', 'String List, Single' ),
		'StreetSuffixModifier'                => array( 'location', 'Street Suffix Modifier', 'String' ),
		'StructureType'                       => array( 'structure', 'Structure Type', 'String List, Multi' ),
		'SubAgencyCompensation'               => array( 'listing_info', 'Sub Agency Compensation', 'String' ),
		'SubAgencyCompensationType'           => array( 'listing_info', 'Sub Agency Compensation Type', 'String List, Single' ),
		'SubdivisionName'                     => array( 'location', 'Subdivision Name', 'String' ),
		'SuppliesExpense'                     => array( 'financial', 'Supplies Expense', 'Number' ),
		'SyndicateTo'                         => array( 'listing_info', 'Syndicate To', 'String List, Multi' ),
		'SyndicationRemarks'                  => array( 'listing_info', 'Syndication Remarks', 'String' ),
		'TaxAnnualAmount'                     => array( 'financial', 'Tax Annual Amount', 'Number' ),
		'TaxAnnualAmountPerLivingAreaUnit'    => array( 'financial', 'Tax Annual Amount Per Living Area Unit', 'Number' ),
		'TaxAnnualAmountPerSquareFoot'        => array( 'financial', 'Tax Annual Amount Per Square Foot', 'Number' ),
		'TaxAssessedValue'                    => array( 'financial', 'Tax Assessed Value', 'Number' ),
		'TaxBlock'                            => array( 'financial', 'Tax Block', 'String' ),
		'TaxBookNumber'                       => array( 'financial', 'Tax Book Number', 'String' ),
		'TaxLegalDescription'                 => array( 'financial', 'Tax Legal Description', 'String' ),
		'TaxLot'                              => array( 'financial', 'Tax Lot', 'String' ),
		'TaxMapNumber'                        => array( 'financial', 'Tax Map Number', 'String' ),
		'TaxOtherAnnualAssessmentAmount'      => array( 'financial', 'Tax Other Annual Assessment Amount', 'Number' ),
		'TaxParcelLetter'                     => array( 'financial', 'Tax Parcel Letter', 'String' ),
		'TaxStatusCurrent'                    => array( 'financial', 'Tax Status Current', 'String List, Multi' ),
		'TaxTract'                            => array( 'financial', 'Tax Tract', 'String' ),
		'TaxYear'                             => array( 'financial', 'Tax Year', 'Number' ),
		'TenantPays'                          => array( 'financial', 'Tenant Pays', 'String List, Multi' ),
		'Topography'                          => array( 'exterior', 'Topography', 'String' ),
		'TotalActualRent'                     => array( 'financial', 'Total Actual Rent', 'Number' ),
		'Township'                            => array( 'location', 'Township', 'String' ),
		'TransactionBrokerCompensation'       => array( 'listing_info', 'Transaction Broker Compensation', 'String' ),
		'TransactionBrokerCompensationType'   => array( 'listing_info', 'Transaction Broker Compensation Type', 'String List, Single' ),
		'TrashExpense'                        => array( 'financial', 'Trash Expense', 'Number' ),
		'UnitNumber'                          => array( 'location', 'Unit Number', 'String' ),
		'UnitTypeType'                        => array( 'structure', 'Unit Type Type', 'String List, Multi' ),
		'UnitsFurnished'                      => array( 'interior', 'Units Furnished', 'String List, Single' ),
		'UniversalPropertyId'                 => array( 'listing_info', 'Universal Property ID', 'String' ),
		'UniversalPropertySubId'              => array( 'listing_info', 'Universal Property Sub ID', 'String' ),
		'UnparsedAddress'                     => array( 'location', 'Unparsed Address', 'String' ),
		'Utilities'                           => array( 'utilities', 'Utilities', 'String List, Multi' ),
		'VacancyAllowance'                    => array( 'financial', 'Vacancy Allowance', 'Number' ),
		'VacancyAllowanceRate'                => array( 'financial', 'Vacancy Allowance Rate', 'Number' ),
		'Vegetation'                          => array( 'exterior', 'Vegetation', 'String List, Multi' ),
		'VideosChangeTimestamp'               => array( 'listing_info', 'Videos Change Timestamp', 'Timestamp' ),
		'VideosCount'                         => array( 'listing_info', 'Videos Count', 'Number' ),
		'View'                                => array( 'exterior', 'View', 'String List, Multi' ),
		'ViewYN'                              => array( 'exterior', 'View Yes/No', 'Boolean' ),
		'VirtualTourURLBranded'               => array( 'listing_info', 'Virtual Tour URL Branded', 'String' ),
		'VirtualTourURLUnbranded'             => array( 'listing_info', 'Virtual Tour URL Unbranded', 'String' ),
		'WalkScore'                           => array( 'utilities', 'Walk Score', 'Number' ),
		'WaterBodyName'                       => array( 'exterior', 'Water Body Name', 'String' ),
		'WaterSewerExpense'                   => array( 'financial', 'Water Sewer Expense', 'Number' ),
		'WaterSource'                         => array( 'utilities', 'Water Source', 'String List, Multi' ),
		'WaterfrontFeatures'                  => array( 'exterior', 'Waterfront Features', 'String List, Multi' ),
		'WaterfrontYN'                        => array( 'exterior', 'Waterfront Yes/No', 'Boolean' ),
		'WindowFeatures'                      => array( 'interior', 'Window Features', 'String List, Multi' ),
		'WithdrawnDate'                       => array( 'listing_info', 'Withdrawn Date', 'Date' ),
		'WoodedArea'                          => array( 'exterior', 'Wooded Area', 'Number' ),
		'WorkmansCompensationExpense'         => array( 'financial', 'Workmans Compensation Expense', 'Number' ),
		'YearBuilt'                           => array( 'structure', 'Year Built', 'Number' ),
		'YearBuiltDetails'                    => array( 'structure', 'Year Built Details', 'String' ),
		'YearBuiltEffective'                  => array( 'structure', 'Year Built Effective', 'Number' ),
		'YearBuiltSource'                     => array( 'structure', 'Year Built Source', 'String List, Single' ),
		'YearEstablished'                     => array( 'listing_info', 'Year Established', 'Number' ),
		'YearsCurrentOwner'                   => array( 'listing_info', 'Years Current Owner', 'Number' ),
		'Zoning'                              => array( 'financial', 'Zoning', 'String' ),
		'ZoningDescription'                   => array( 'financial', 'Zoning Description', 'String' ),
	);

	/**
	 * A RESO field's type, or '' when the field is not in the dictionary.
	 *
	 * @param string $field RESO field key.
	 * @return string
	 */
	public static function type_of( string $field ): string {
		return isset( self::MAP[ $field ] ) ? self::MAP[ $field ][2] : '';
	}

	/**
	 * Every mapped RESO field, in map order — the curated order the field
	 * selector's drag order is seeded from.
	 *
	 * @return string[]
	 */
	public static function fields_in_map_order(): array {
		return array_keys( self::MAP );
	}

	/**
	 * Whether a field is plumbing: imported because the plugin needs it (sync,
	 * mapping, display rules), but meaningless to a visitor as a label-value row.
	 *
	 * Drives only the admin-only DEFAULT on a fresh install — never a hard rule.
	 * A site owner can make any of these public by un-ticking the box.
	 *
	 * @param string $field RESO field key.
	 * @return bool
	 */
	public static function is_plumbing( string $field ): bool {
		// Record keys: "Listing Key: 3yd-MFRMLSFL-A4512345" tells a buyer nothing.
		if ( 'Key' === substr( $field, -3 ) || 'KeyNumeric' === substr( $field, -10 ) ) {
			return true;
		}
		// Sync bookkeeping — the property page shows "Updated" from the view model.
		if ( 'Timestamp' === self::type_of( $field ) ) {
			return true;
		}
		// Raw URLs; the tour/agent sections render these properly.
		if ( false !== strpos( $field, 'URL' ) ) {
			return true;
		}
		// IDX display-permission flags, and the map/media plumbing.
		if ( 0 === strpos( $field, 'Internet' ) && 'YN' === substr( $field, -2 ) ) {
			return true;
		}

		return in_array(
			$field,
			array(
				'Latitude',
				'Longitude',
				'PhotosCount',
				'DocumentsCount',
				'SourceSystemID',
				'SourceSystemName',
				'OriginatingSystemID',
				'OriginatingSystemName',
			),
			true
		);
	}

	/**
	 * The section a RESO field belongs to.
	 *
	 * @param string $field RESO field key.
	 * @return string Section slug; 'other' for fields outside the dictionary.
	 */
	public static function section_of( string $field ): string {
		// Look the field up in the dictionary; unmapped (proprietary) fields fall back to 'other'.
		$section = isset( self::MAP[ $field ] ) ? self::MAP[ $field ][0] : 'other';

		/** Filter the section a RESO field belongs to — the one way to move an MLS's proprietary fields out of Other Details. @since 6.4 */
		return (string) apply_filters( 'mlsimport_property_field_section', $section, $field );
	}

	/**
	 * A RESO field's official display label, or '' when the field is not in the
	 * dictionary.
	 *
	 * @param string $field RESO field key.
	 * @return string
	 */
	public static function label_of( string $field ): string {
		if ( ! isset( self::MAP[ $field ] ) ) {
			return '';
		}

		// RESO labels every flag "<Thing> Yes/No" (all 44 Booleans, exactly). We only
		// ever print a flag when it is true, so the suffix is dead weight: "Spa: Yes",
		// not "Spa Yes/No: Yes".
		$label = self::MAP[ $field ][1];
		$suffix = ' Yes/No';
		if ( substr( $label, -strlen( $suffix ) ) === $suffix ) {
			$label = substr( $label, 0, -strlen( $suffix ) );
		}

		return $label;
	}
}

/**
 * A RESO field key as a human label — the fallback for proprietary fields, which
 * are not in the dictionary and so have no official label. Splits PascalCase on
 * the lower->upper and acronym->word boundaries, so ViewYN stays "View YN" and
 * MLSAreaMajor becomes "MLS Area Major".
 *
 * @param string $field_key RESO field key.
 * @return string
 */
function mlsimport_humanize_field_key( string $field_key ): string {
	$spaced = preg_replace( '/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', ' ', $field_key );
	return trim( (string) $spaced );
}

/**
 * A field's public label: the user's "Front End Label" when set, else RESO's
 * official label, else the humanized key.
 *
 * @param string $field  RESO field key.
 * @param array  $labels The mls-fields-label option array.
 * @return string
 */
function mlsimport_property_field_label( string $field, array $labels ): string {
	// 1) The operator's own "Front End Label" override, when set, wins.
	$user = isset( $labels[ $field ] ) ? trim( (string) $labels[ $field ] ) : '';
	if ( '' !== $user ) {
		return $user;
	}

	// 2) Else RESO's official label; 3) else the humanized field key.
	$reso = Mlsimport_Property_Field_Sections::label_of( $field );
	return '' !== $reso ? $reso : mlsimport_humanize_field_key( $field );
}

/**
 * One section's rows as [label, value] pairs.
 *
 * A field appears when it is ticked for import, not marked admin-only, and has
 * a value — the one rule that governs every section.
 *
 * @param int    $id      Property post ID.
 * @param string $section Section slug.
 * @return array<int,array{0:string,1:string}>
 */
function mlsimport_property_section_fields( int $id, string $section ): array {
	$options = get_option( 'mlsimport_admin_fields_select' );
	if ( ! is_array( $options ) || empty( $options['mls-fields'] ) || ! is_array( $options['mls-fields'] ) ) {
		return array();
	}

	$labels = isset( $options['mls-fields-label'] ) && is_array( $options['mls-fields-label'] ) ? $options['mls-fields-label'] : array();
	$hidden = isset( $options['mls-fields-admin'] ) && is_array( $options['mls-fields-admin'] ) ? $options['mls-fields-admin'] : array();
	$rows   = array();

	$fields = mlsimport_property_fields_in_order( $options );

	foreach ( $fields as $field => $is_imported ) {
		if ( 1 !== (int) $is_imported || ! empty( $hidden[ $field ] ) ) {
			continue;
		}
		if ( Mlsimport_Property_Field_Sections::section_of( $field ) !== $section ) {
			continue;
		}

		$value = mlsimport_property_field_value( $id, $field );
		if ( '' === $value ) {
			continue;
		}

		$value = mlsimport_property_field_display( $field, $value );
		if ( '' === $value ) {
			continue; // A false flag: suppressed rather than printed as "No".
		}

		$rows[] = array( mlsimport_property_field_label( $field, $labels ), $value );
	}

	return $rows;
}

/**
 * Seed the field selector's defaults from the section map, once — the drag order,
 * and which fields are hidden from visitors.
 *
 * Order: the MLS's own field order is arbitrary (BrightMLS opens with Location,
 * BuyerAgentMlsId, BuyerAgentCellPhone), so an unseeded install would print
 * Interior with flooring before bedrooms. The seed groups fields by section and
 * orders them within it, putting proprietary fields last (they render in Other
 * Details anyway).
 *
 * Visibility: the plumbing fields (keys, timestamps, coordinates, URLs, display
 * flags) are ticked for import because the plugin needs them, but they are
 * gibberish in a details grid — "Listing Key: 3yd-MFRMLSFL-A4512345". They start
 * admin-only so a new client never has to think about them. This is a default,
 * not a rule: un-tick the box and the field is public.
 *
 * Runs only on the first save, when there is no order yet. A configured site is
 * never rewritten — not by a later, better map, and not over a field the user
 * deliberately made public.
 *
 * @param array $options The mlsimport_admin_fields_select option.
 * @return array The option, seeded when it had not been before.
 */
function mlsimport_seed_field_defaults( array $options ): array {
	if ( ! empty( $options['field_order'] ) || empty( $options['mls-fields'] ) || ! is_array( $options['mls-fields'] ) ) {
		return $options;
	}

	$admin = isset( $options['mls-fields-admin'] ) && is_array( $options['mls-fields-admin'] ) ? $options['mls-fields-admin'] : array();
	foreach ( array_keys( $options['mls-fields'] ) as $field ) {
		$admin[ $field ] = Mlsimport_Property_Field_Sections::is_plumbing( (string) $field ) ? 1 : 0;
	}
	$options['mls-fields-admin'] = $admin;

	$sections = array_keys( mlsimport_property_field_section_titles() );
	$known    = Mlsimport_Property_Field_Sections::fields_in_map_order();

	$ranked = array();
	foreach ( array_keys( $options['mls-fields'] ) as $field ) {
		$section = Mlsimport_Property_Field_Sections::section_of( (string) $field );
		$bucket  = array_search( $section, $sections, true );
		$within  = array_search( $field, $known, true );

		// A proprietary field is in no section and no map: it sorts to the very end.
		$ranked[ $field ] = array(
			false === $bucket ? PHP_INT_MAX : $bucket,
			false === $within ? PHP_INT_MAX : $within,
		);
	}

	uasort(
		$ranked,
		static function ( $a, $b ) {
			return $a[0] === $b[0] ? $a[1] <=> $b[1] : $a[0] <=> $b[0];
		}
	);

	$options['field_order'] = array_combine(
		array_keys( $ranked ),
		range( 0, count( $ranked ) - 1 )
	);

	return $options;
}

/**
 * The selected fields in display order — the field selector's drag order, which
 * the user owns. Fields the user never dragged keep their existing position,
 * after the ones they did.
 *
 * @param array $options The mlsimport_admin_fields_select option.
 * @return array<string,mixed> field => imported flag, in display order.
 */
function mlsimport_property_fields_in_order( array $options ): array {
	$fields = $options['mls-fields'];
	// No saved drag order yet: return the fields in their stored order untouched.
	if ( empty( $options['field_order'] ) || ! is_array( $options['field_order'] ) ) {
		return $fields;
	}

	// Sort the field => position map by position (ascending) to get drag order.
	$order = $options['field_order'];
	asort( $order );

	// Emit the ordered fields first, keeping only those still selected for import.
	$sorted = array();
	foreach ( $order as $field => $index ) {
		if ( isset( $fields[ $field ] ) ) {
			$sorted[ $field ] = $fields[ $field ];
		}
	}

	// Union appends any never-dragged field after the ordered ones (first key wins).
	return $sorted + $fields;
}

/**
 * Whether a stored RESO boolean reads as true. MLSes serve these as 1, "true",
 * "Y" or "Yes"; anything else is false.
 *
 * @param string $value Stored value.
 * @return bool
 */
function mlsimport_property_field_is_true( string $value ): bool {
	return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'y', 'yes' ), true );
}

/**
 * A stored RESO value as it reads on the page, formatted by its type.
 *
 * Returns '' for a false flag — the caller drops the row, because "Has Spa: No"
 * is noise. Multi-value lists arrive already comma-joined by the importer, and
 * proprietary fields (no type) print as they were stored.
 *
 * @param string $field RESO field key.
 * @param string $value Stored value.
 * @return string Display value, or '' when the field earns no row.
 */
function mlsimport_property_field_display( string $field, string $value ): string {
	switch ( Mlsimport_Property_Field_Sections::type_of( $field ) ) {
		case 'Boolean':
			return mlsimport_property_field_is_true( $value ) ? __( 'Yes', 'mlsimport' ) : '';

		case 'Number':
			if ( ! is_numeric( $value ) ) {
				return $value;
			}
			// A year is a label, not a quantity: 1998, never 1,998.
			if ( false !== strpos( $field, 'Year' ) ) {
				return $value;
			}
			return number_format_i18n( (float) $value + 0 );

		case 'Date':
		case 'Timestamp':
			$stamp = strtotime( $value );
			return $stamp ? date_i18n( (string) get_option( 'date_format' ), $stamp ) : $value;
	}

	return $value;
}

/**
 * A RESO field's stored value for one property.
 *
 * Code-mapped fields land in mlsimport_<Field>; fields the routing map does not
 * recognise pass through to mlsimport_x_<Field>. One lookup covers both.
 *
 * @param int    $id    Property post ID.
 * @param string $field RESO field key.
 * @return string
 */
function mlsimport_property_field_value( int $id, string $field ): string {
	$value = (string) get_post_meta( $id, 'mlsimport_' . $field, true );
	if ( '' === $value ) {
		$value = (string) get_post_meta( $id, 'mlsimport_x_' . $field, true );
	}
	return $value;
}
