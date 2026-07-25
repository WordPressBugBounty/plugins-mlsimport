<?php
/**
 * ResoBase — base class for the RESO-standard MLS provider adapters.
 *
 * Provider adapters in the enviroment/ directory (SparkResoClass, TresleResoClass,
 * BridgeResoClass, MlsgridResoClass, etc.) extend this class. It currently defines no
 * shared behavior and serves as a common parent/type for the provider adapters.
 *
 * @package MLSImport
 */
// Abort if the file is accessed directly outside of WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of ResoBase
 *
 * @author cretu
 */
// Empty base class; provider adapters extend this for a shared type.
class ResoBase {


}
