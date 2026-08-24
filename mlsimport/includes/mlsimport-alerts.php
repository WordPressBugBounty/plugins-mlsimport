<?php
/**
 * Internal incident alerts (issue #208).
 *
 * File role: gives the plugin one small way to tell the MLSImport SaaS that
 * something on a customer site broke (a stalled import, permanently invalid
 * credentials) and one way to say it recovered — so support sees the broken
 * site before the customer cancels, instead of relying on the once-a-day
 * telemetry heartbeat.
 *
 * How it works, step by step:
 *   1. A caller opens an incident with mlsimport_alert_open( key, class, ctx ).
 *   2. Open incidents are remembered in the mlsimport_open_alerts option as
 *      incident-key => opened-at; a key that is already open is deduplicated,
 *      so each incident produces exactly one alert no matter how often the
 *      detecting code runs (hourly cron, polled progress screens).
 *   3. mlsimport_alert_resolve( key ) sends a matching resolution event and
 *      forgets the key, re-arming the alert for a future incident.
 *   4. Payloads travel over ThemeImport's fire-and-forget SaaS POST to the
 *      'alert' endpoint: non-blocking, response ignored, so alerting can never
 *      slow down or break a customer request.
 *   5. Credentials never leave the site: context keys that look like secrets
 *      are stripped from every payload before dispatch.
 *
 * @package MLSImport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Open an incident and send its alert, once.
 *
 * The incident key is the dedup unit: the first open for a key dispatches an
 * 'alert_opened' event and records the key; every later open for the same key
 * is silent until mlsimport_alert_resolve() forgets it. Keys older than 30
 * days are pruned on the way in, so a resolution that never happened (site
 * deactivated mid-incident, lost option) cannot suppress alerts forever.
 *
 * @param string $key     Unique incident identifier (e.g. "import_stalled:12").
 * @param string $class   Incident class keyword for grouping on the SaaS side.
 * @param array  $context Sanitized diagnostic context (no credentials).
 * @return bool True when a new alert was dispatched, false when deduplicated.
 */
function mlsimport_alert_open( $key, $class, array $context = array() ) {
	$open = get_option( 'mlsimport_open_alerts', array() );
	if ( ! is_array( $open ) ) {
		$open = array();
	}

	// Prune forgotten incidents so one lost resolution cannot mute a key forever.
	foreach ( $open as $open_key => $opened_at ) {
		if ( time() - intval( $opened_at ) > 30 * 86400 ) {
			unset( $open[ $open_key ] );
		}
	}

	// Already open → this exact incident was alerted before. Stay silent.
	if ( isset( $open[ $key ] ) ) {
		update_option( 'mlsimport_open_alerts', $open, false );
		return false;
	}

	// New incident: remember it, then dispatch the single alert event.
	$open[ $key ] = time();
	update_option( 'mlsimport_open_alerts', $open, false );
	mlsimport_alert_dispatch( 'alert_opened', $key, $class, $context );
	return true;
}

/**
 * Resolve an open incident: send the recovery event and re-arm the key.
 *
 * Only a key that is currently open sends anything — resolution of an unknown
 * or already-resolved incident is silent, so recovery checks can also run on
 * every cron pass without spamming. After resolution the key is forgotten and
 * a future mlsimport_alert_open() for it alerts again (a new incident).
 *
 * @param string $key     Incident identifier used at open time.
 * @param array  $context Sanitized recovery context (e.g. final counts).
 * @return bool True when a resolution event was dispatched.
 */
function mlsimport_alert_resolve( $key, array $context = array() ) {
	$open = get_option( 'mlsimport_open_alerts', array() );
	if ( ! is_array( $open ) || ! isset( $open[ $key ] ) ) {
		return false;
	}

	// Forget the incident first, then announce the recovery.
	$context['open_seconds'] = time() - intval( $open[ $key ] );
	unset( $open[ $key ] );
	update_option( 'mlsimport_open_alerts', $open, false );
	mlsimport_alert_dispatch( 'alert_resolved', $key, '', $context );
	return true;
}

/**
 * Build one alert payload and hand it to the fire-and-forget SaaS sender.
 *
 * Shared by open and resolve so both events have the same shape. Strips any
 * secret-looking context keys (#208: token and credential values must never
 * appear in alerts), stamps site and time, and lets integrations adjust the
 * payload through the mlsimport_alert_payload filter before sending.
 *
 * @param string $event   'alert_opened' or 'alert_resolved'.
 * @param string $key     Incident identifier.
 * @param string $class   Incident class keyword.
 * @param array  $context Diagnostic context.
 * @return void
 */
function mlsimport_alert_dispatch( $event, $key, $class, array $context ) {
	// Hard rule: no credential material in any alert payload.
	foreach ( array( 'token', 'password', 'secret', 'username', 'authorization', 'api_key' ) as $secret_key ) {
		unset( $context[ $secret_key ] );
	}

	$payload = array(
		'event'    => $event,
		'incident' => (string) $key,
		'class'    => (string) $class,
		'context'  => $context,
		'time'     => time(),
	);

	/** Filter an outgoing incident-alert payload. @since 7.2 */
	$payload = apply_filters( 'mlsimport_alert_payload', $payload );

	// Fire-and-forget: never blocks, response never inspected.
	if ( class_exists( 'ThemeImport' ) ) {
		ThemeImport::globalApiRequestSaasFireAndForget( 'alert', $payload );
	}
}
