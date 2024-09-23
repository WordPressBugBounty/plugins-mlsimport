<?php 
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of SparkResoClass
 *
 * @author cretu
 */

class SparkResoClass extends ResoBase {


		public $theme_importer;

	public function __construct( $theme_importer ) {
		$this->theme_importer = $theme_importer;
	}
}
