<?php
/**
 * WP-CLI commands for SVG Support.
 *
 * Provides `wp timu-svg scan-existing` to retroactively sanitize SVG
 * attachments that were uploaded before the allowlist sanitizer was active.
 *
 * @package TIMU_SVG_Support
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage SVG Support from the command line.
 *
 * @when after_wp_load
 */
class TIMU_SVG_CLI {

	/**
	 * Sanitize all existing SVG attachments in the Media Library.
	 *
	 * Queries every attachment with mime_type image/svg+xml, reads each file via
	 * WP_Filesystem, passes the content through TIMU_SVG_Sanitizer::sanitize_file(),
	 * and overwrites the file with the sanitized output if it changed.
	 *
	 * ## OPTIONS
	 *
	 * [--batch-size=<n>]
	 * : Number of attachments to process per WP_Query pass. Default: 25.
	 *
	 * [--dry-run]
	 * : Report what would be sanitized without writing any files.
	 *
	 * ## EXAMPLES
	 *
	 *     wp timu-svg scan-existing
	 *     wp timu-svg scan-existing --dry-run
	 *     wp timu-svg scan-existing --batch-size=50
	 *
	 * @subcommand scan-existing
	 *
	 * @param array<int, string>    $args       Positional arguments (unused).
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function scan_existing( array $args, array $assoc_args ): void {
		unset( $args );

		$batch_size = isset( $assoc_args['batch-size'] ) ? absint( $assoc_args['batch-size'] ) : 25;
		if ( $batch_size < 1 ) {
			$batch_size = 25;
		}

		$dry_run = isset( $assoc_args['dry-run'] );

		if ( $dry_run ) {
			WP_CLI::line( __( 'Dry-run mode — no files will be written.', 'thisismyurl-svg-support' ) );
		}

		$offset     = 0;
		$total      = 0;
		$sanitized  = 0;
		$skipped    = 0;
		$errors     = 0;

		do {
			$query = new WP_Query(
				array(
					'post_type'      => 'attachment',
					'post_mime_type' => 'image/svg+xml',
					'post_status'    => 'inherit',
					'posts_per_page' => $batch_size,
					'offset'         => $offset,
					'fields'         => 'ids',
					'no_found_rows'  => false,
				)
			);

			if ( 0 === $total ) {
				$total = (int) $query->found_posts;
				WP_CLI::line(
					sprintf(
						/* translators: %d: number of SVG attachments found. */
						__( 'Found %d SVG attachment(s) to process.', 'thisismyurl-svg-support' ),
						$total
					)
				);

				if ( 0 === $total ) {
					WP_CLI::success( __( 'No SVG attachments found. Nothing to do.', 'thisismyurl-svg-support' ) );
					return;
				}
			}

			$batch_ids = $query->posts;

			foreach ( $batch_ids as $attachment_id ) {
				$attachment_id = (int) $attachment_id;
				$path          = get_attached_file( $attachment_id );

				if ( ! $path || ! is_readable( $path ) ) {
					WP_CLI::line(
						sprintf(
							/* translators: %d: attachment post ID. */
							__( '  SKIP  ID %d — file not found or unreadable.', 'thisismyurl-svg-support' ),
							$attachment_id
						)
					);
					++$skipped;
					continue;
				}

				if ( $dry_run ) {
					WP_CLI::line(
						sprintf(
							/* translators: 1: attachment post ID, 2: file path. */
							__( '  DRY   ID %1$d — %2$s', 'thisismyurl-svg-support' ),
							$attachment_id,
							$path
						)
					);
					++$sanitized;
					continue;
				}

				$ok = TIMU_SVG_Sanitizer::sanitize_file( $path );

				if ( $ok ) {
					WP_CLI::line(
						sprintf(
							/* translators: 1: attachment post ID, 2: file basename. */
							__( '  OK    ID %1$d — %2$s', 'thisismyurl-svg-support' ),
							$attachment_id,
							basename( $path )
						)
					);
					++$sanitized;
				} else {
					WP_CLI::line(
						sprintf(
							/* translators: 1: attachment post ID, 2: file basename, 3: error message (internal plugin string). */
							__( '  FAIL  ID %1$d — %2$s (%3$s)', 'thisismyurl-svg-support' ),
							$attachment_id,
							basename( $path ),
							TIMU_SVG_Sanitizer::get_last_error() // get_last_error() returns internal plugin-defined string constants only.
						)
					);
					++$errors;
				}
			}

			$offset += count( $batch_ids );

		} while ( count( $batch_ids ) === $batch_size && $offset < $total );

		$summary = sprintf(
			/* translators: 1: processed count, 2: skipped count, 3: failed count. */
			__( 'Done. %1$d processed, %2$d skipped, %3$d failed.', 'thisismyurl-svg-support' ),
			$sanitized,
			$skipped,
			$errors
		);

		if ( $errors > 0 ) {
			WP_CLI::warning( $summary );
		} else {
			WP_CLI::success( $summary );
		}
	}
}
