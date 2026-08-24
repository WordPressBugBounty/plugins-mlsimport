<?php
/**
 * Stage and finalize media for one Stored Listing Write.
 *
 * New remote-image attachment rows are created before the active theme gallery
 * changes. The old MLSImport attachments remain intact until the surrounding
 * required database write commits, which prevents a failed refresh from wiping
 * an existing listing's photos.
 *
 * @package MLSImport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Own remote-photo comparison, staging, activation, and cleanup.
 */
final class Mlsimport_Stored_Listing_Media {

	/**
	 * Create replacement attachments only when the incoming URL set changed.
	 *
	 * @param int               $listing_id Managed Listing post ID.
	 * @param array<int, mixed> $media      Feed-ordered media rows.
	 * @return array<string, mixed> Changed flag, old/new IDs, featured ID, warnings.
	 */
	public function stage( int $listing_id, array $media ): array {
		$old_ids  = $this->existing_attachment_ids( $listing_id );
		$old_urls = array_map(
			static function ( $attachment_id ): string {
				return (string) get_post_field( 'guid', $attachment_id );
			},
			$old_ids
		);
		$photos = array_values(
			array_filter(
				$media,
				static function ( $item ): bool {
					if ( ! is_array( $item ) || empty( $item['MediaURL'] ) ) {
						return false;
					}
					$category = (string) ( $item['MediaCategory'] ?? 'Property Photo' );
					return in_array( $category, array( 'Property Photo', 'Photo' ), true );
				}
			)
		);
		$new_urls = array_map(
			static function ( array $item ): string {
				return (string) $item['MediaURL'];
			},
			$photos
		);
		$old_compare = $old_urls;
		$new_compare = $new_urls;
		sort( $old_compare );
		sort( $new_compare );
		if ( $old_compare === $new_compare ) {
			return array(
				'changed'        => false,
				'old_ids'        => $old_ids,
				'attachment_ids' => array(),
				'featured_id'    => 0,
				'warnings'       => array(),
			);
		}

		$attachment_ids = array();
		$warnings       = array();
		$featured_id    = 0;
		$order_one_id   = 0;
		foreach ( $photos as $photo ) {
			$url = (string) $photo['MediaURL'];
			$attachment_id = wp_insert_attachment(
				array(
					'guid'           => $url,
					'post_status'    => 'inherit',
					'post_content'   => '',
					'post_parent'    => $listing_id,
					'post_mime_type' => (string) ( $photo['MimeType'] ?? 'image/jpeg' ),
					'post_title'     => (string) ( $photo['MediaKey'] ?? '' ),
				),
				$url,
				$listing_id,
				true
			);
			if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
				$warnings[] = 'Photo ' . $url . ' could not be saved.';
				continue;
			}

			$attachment_id   = (int) $attachment_id;
			$attachment_ids[] = $attachment_id;
			update_post_meta( $attachment_id, 'is_mlsimport', 1 );
			if ( 0 === $featured_id && $this->is_truthy( $photo['PreferredPhotoYN'] ?? false ) ) {
				$featured_id = $attachment_id;
			}
			if ( 0 === $order_one_id && 1 === (int) ( $photo['Order'] ?? 0 ) ) {
				$order_one_id = $attachment_id;
			}
		}

		if ( 0 === $featured_id ) {
			$featured_id = $order_one_id ?: (int) ( $attachment_ids[0] ?? 0 );
		}
		return array(
			'changed'        => true,
			'old_ids'        => $old_ids,
			'attachment_ids' => $attachment_ids,
			'featured_id'    => $featured_id,
			'warnings'       => $warnings,
		);
	}

	/**
	 * Point the listing thumbnail at the staged featured attachment.
	 *
	 * @param int                  $listing_id Managed Listing post ID.
	 * @param array<string, mixed> $stage      Successful stage result.
	 * @return void
	 */
	public function activate( int $listing_id, array $stage ): void {
		$featured_id = (int) ( $stage['featured_id'] ?? 0 );
		if ( $featured_id > 0 ) {
			set_post_thumbnail( $listing_id, $featured_id );
		}
	}

	/**
	 * Delete the old MLSImport attachments after the gallery commit succeeds.
	 *
	 * @param array<string, mixed> $stage Successful stage result.
	 * @return void
	 */
	public function finish( array $stage ): void {
		$new_ids = array_map( 'intval', (array) ( $stage['attachment_ids'] ?? array() ) );
		foreach ( (array) ( $stage['old_ids'] ?? array() ) as $attachment_id ) {
			if ( ! in_array( (int) $attachment_id, $new_ids, true ) ) {
				wp_delete_attachment( (int) $attachment_id, true );
			}
		}
	}

	/**
	 * Delete staged rows/files when the surrounding required write rolls back.
	 *
	 * @param array<string, mixed> $stage Failed stage result.
	 * @return void
	 */
	public function discard( array $stage ): void {
		foreach ( (array) ( $stage['attachment_ids'] ?? array() ) as $attachment_id ) {
			wp_delete_attachment( (int) $attachment_id, true );
		}
	}

	/**
	 * Return only attachments created and owned by MLSImport for this listing.
	 *
	 * @param int $listing_id Managed Listing post ID.
	 * @return array<int, int> Existing MLSImport attachment IDs.
	 */
	private function existing_attachment_ids( int $listing_id ): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'   => 'attachment',
					'post_parent' => $listing_id,
					'post_status' => 'inherit',
					'meta_key'    => 'is_mlsimport',
					'meta_value'  => 1,
					'fields'      => 'ids',
					'numberposts' => -1,
				)
			)
		);
	}

	/**
	 * Interpret RESO PreferredPhotoYN without treating the string "false" as true.
	 *
	 * Provider payloads may use booleans, numeric flags, or YN strings. Only the
	 * explicit affirmative forms select a preferred image; all other values let
	 * Order=1 or the first successful attachment provide the fallback.
	 *
	 * @param mixed $value Raw PreferredPhotoYN value.
	 * @return bool Whether the feed explicitly selected this photo.
	 */
	private function is_truthy( $value ): bool {
		if ( true === $value || 1 === $value ) {
			return true;
		}
		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'yes', 'y' ), true );
	}
}
