<?php
/**
 * Pure viewport grid-clustering engine for the standalone/half-map map.
 *
 * Given the in-view listing coordinates and the map zoom, buckets the points
 * into fixed-pixel grid cells and returns one entry per non-empty cell with its
 * centroid, member count and member post IDs. The map payload then renders a
 * single-member cell as a price pin and a multi-member cell as a count bubble —
 * so listings cluster at every zoom and split apart as the cells shrink.
 *
 * PURE — no WordPress calls, so it is unit-testable in isolation
 * (tests/unit/clusterer-test.php).
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Grid-clusters {lat,lng,post_id} points into zoom-sized cells.
 */
class Mlsimport_Map_Clusterer {

	/**
	 * Grid-cluster coordinate points into cells sized to the zoom level.
	 *
	 * @param array $coords Objects/arrays with ->lat / ->lng (and optional ->post_id).
	 * @param int   $zoom   Map zoom; higher zoom => smaller cells => more, tighter cells.
	 * @return array<int,array{lat:float,lng:float,count:int,ids:int[]}>
	 */
	public static function grid( array $coords, int $zoom ): array {
		// Clamp the zoom to the Web-Mercator tile range so the cell math is sane.
		$zoom = max( 0, min( 22, $zoom ) );

		// Web-Mercator world width in pixels at this zoom is 256 * 2^zoom for 360°.
		// A ~70px cell keeps clusters visually separated at every zoom level.
		$world_px = 256.0 * pow( 2, $zoom );
		$cell_deg = ( 70.0 / $world_px ) * 360.0;
		if ( $cell_deg <= 0 ) {
			$cell_deg = 0.0001;
		}

		// Bucket every point into its grid cell, accumulating a running centroid sum.
		$cells = array();
		foreach ( $coords as $c ) {
			// Read lat/lng/post_id from either an object or an array shape.
			$lat = is_object( $c ) ? (float) $c->lat : (float) $c['lat'];
			$lng = is_object( $c ) ? (float) $c->lng : (float) $c['lng'];
			$id  = is_object( $c )
				? ( isset( $c->post_id ) ? (int) $c->post_id : 0 )
				: ( isset( $c['post_id'] ) ? (int) $c['post_id'] : 0 );

			// Cell key = the integer grid coordinate "col:row" the point falls in.
			$key = ( (int) floor( $lng / $cell_deg ) ) . ':' . ( (int) floor( $lat / $cell_deg ) );
			// First point in a cell seeds its accumulator.
			if ( ! isset( $cells[ $key ] ) ) {
				$cells[ $key ] = array(
					'sum_lat' => 0.0,
					'sum_lng' => 0.0,
					'count'   => 0,
					'ids'     => array(),
				);
			}
			// Fold this point into the cell: sum coords (for the centroid), bump count,
			// remember the member id.
			$cells[ $key ]['sum_lat'] += $lat;
			$cells[ $key ]['sum_lng'] += $lng;
			$cells[ $key ]['count']++;
			$cells[ $key ]['ids'][] = $id;
		}

		// Emit one entry per non-empty cell: the centroid (sum / count), count and ids.
		$out = array();
		foreach ( $cells as $cell ) {
			$out[] = array(
				'lat'   => $cell['sum_lat'] / $cell['count'],
				'lng'   => $cell['sum_lng'] / $cell['count'],
				'count' => (int) $cell['count'],
				'ids'   => $cell['ids'],
			);
		}

		return $out;
	}
}
