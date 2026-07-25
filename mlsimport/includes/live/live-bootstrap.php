<?php
/**
 * Live MLS passthrough mode — bootstrap.
 *
 * The only live-mode file required from mlsimport.php (seam #1). Loads the
 * module and registers its hooks; every callback self-guards on
 * mlsimport_live_mode_active(), so with the flag off nothing changes.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Pull in every live-mode file in dependency order. Each only defines
// functions and registers self-guarding hooks; nothing runs until the gate
// below reports active, so an unconditional require here is inert with the
// flag off.
require_once __DIR__ . '/live-settings.php';
require_once __DIR__ . '/live-config.php';
require_once __DIR__ . '/live-connection.php';
require_once __DIR__ . '/live-entitlement.php';
require_once __DIR__ . '/live-params.php';
require_once __DIR__ . '/live-parse.php';
require_once __DIR__ . '/live-cache.php';
require_once __DIR__ . '/live-source.php';
require_once __DIR__ . '/live-map-reso.php';
require_once __DIR__ . '/live-grid.php';
require_once __DIR__ . '/live-single.php';
require_once __DIR__ . '/live-card.php';
require_once __DIR__ . '/live-map.php';
require_once __DIR__ . '/live-search-options.php';
require_once __DIR__ . '/live-selection.php';
require_once __DIR__ . '/live-guards.php';

/**
 * The single live-mode gate. Every live callback and guard checks this and
 * nothing else: standalone (990) ∧ flag on ∧ usable config ∧ credentials ∧
 * subscription entitlement.
 *
 * @return bool
 */
function mlsimport_live_mode_active(): bool {
	// All conditions must hold for live mode to engage. Short-circuit && means
	// each cheap/local check runs before the more expensive ones.
	$active = function_exists( 'mlsimport_is_standalone_mode' )     // standalone helper is loaded
		&& mlsimport_is_standalone_mode()                       // running in standalone (theme_id 990)
		&& mlsimport_live_enabled()                             // the live-mode setting flag is on
		&& array() !== mlsimport_live_config()                  // a usable per-MLS config exists
		&& mlsimport_live_credentials_present()                 // provider credentials are saved
		&& mlsimport_live_entitled();                           // subscription entitlement is active

	/** Filter whether live MLS mode is active. @since 6.4 */
	// Let integrations force the gate on/off; cast keeps the return strictly bool.
	return (bool) apply_filters( 'mlsimport_live_mode_active', $active );
}

// Live Mode is not ready for release — the settings screen is hidden so the
// feature cannot be enabled or used. Re-enable this line to bring it back.
// add_action( 'admin_menu', 'mlsimport_live_settings_menu', 30 );
add_action( 'admin_notices', 'mlsimport_live_entitlement_notice' );
