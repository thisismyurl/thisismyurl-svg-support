<?php
/**
 * WP 7 Abilities API registration for This Is My URL - SVG Support.
 *
 * Exposes the plugin's in-memory SVG sanitizer as a discoverable,
 * REST/AI-invokable READONLY ability: callers pass raw SVG markup (or the ID
 * of an existing SVG attachment) and receive the sanitized markup plus a
 * report of what the allowlist sanitizer stripped. Nothing on disk is ever
 * modified — the attachment path reads a copy into memory and sanitizes that.
 *
 * @package TIMU_SVG_Support
 * @since   1.6148
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

add_action(
	'wp_abilities_api_init',
	static function (): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return; // Abilities API unavailable (WordPress < 6.9).
		}

		wp_register_ability(
			'thisismyurl-svg-support/sanitize-svg',
			array(
				'label'               => __( 'Sanitize SVG (inspect only)', 'thisismyurl-svg-support' ),
				'description'         => __( 'Runs SVG markup through the allowlist sanitizer (enshrined/svg-sanitize) and returns the cleaned result for inspection — without writing anything to disk. Pass either raw SVG markup or the ID of an existing SVG attachment (exactly one). Use this to preview what sanitization would strip before trusting or storing an SVG.', 'thisismyurl-svg-support' ),
				'category'            => 'site',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'markup'        => array(
							'type'        => 'string',
							'description' => __( 'Raw SVG markup to sanitize. Provide this OR attachment_id, not both.', 'thisismyurl-svg-support' ),
						),
						'attachment_id' => array(
							'type'        => 'integer',
							'description' => __( 'ID of an existing SVG attachment to read and sanitize a copy of. Provide this OR markup, not both. The file on disk is never modified.', 'thisismyurl-svg-support' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'sanitized', 'was_modified', 'issues' ),
					'properties'           => array(
						'sanitized'    => array(
							'type'        => 'string',
							'description' => __( 'The sanitized SVG markup.', 'thisismyurl-svg-support' ),
						),
						'was_modified' => array(
							'type'        => 'boolean',
							'description' => __( 'True if the sanitizer changed the input; false if the input was already clean.', 'thisismyurl-svg-support' ),
						),
						'issues'       => array(
							'type'        => 'array',
							'description' => __( 'Report of items the sanitizer flagged or removed (XML parse warnings and cleaned elements/attributes), as exposed by the underlying library.', 'thisismyurl-svg-support' ),
							'items'       => array(
								'type' => 'object',
							),
						),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => 'timu_svg_ability_sanitize',
				'permission_callback' => 'timu_svg_ability_can_sanitize',
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}
);

if ( ! function_exists( 'timu_svg_ability_can_sanitize' ) ) {
	/**
	 * Permission gate for the sanitize-svg ability.
	 *
	 * Matches the capability this plugin gates SVG handling on
	 * ({@see TIMU_SVG_Support::UPLOAD_CAP}, `upload_svg_files`). When an
	 * attachment is targeted, the user must additionally be able to read that
	 * specific attachment.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return bool|WP_Error True if allowed, WP_Error otherwise.
	 */
	function timu_svg_ability_can_sanitize( $input = array() ) {
		$cap = class_exists( 'TIMU_SVG_Support' ) ? TIMU_SVG_Support::UPLOAD_CAP : 'upload_files';
		if ( ! current_user_can( $cap ) ) {
			return new WP_Error(
				'timu_svg_forbidden',
				__( 'You are not allowed to sanitize SVG content.', 'thisismyurl-svg-support' ),
				array( 'status' => 403 )
			);
		}

		$input = is_array( $input ) ? $input : array();
		if ( isset( $input['attachment_id'] ) ) {
			$attachment_id = absint( $input['attachment_id'] );
			if ( $attachment_id > 0 && ! current_user_can( 'read_post', $attachment_id ) ) {
				return new WP_Error(
					'timu_svg_forbidden_attachment',
					__( 'You are not allowed to read that attachment.', 'thisismyurl-svg-support' ),
					array( 'status' => 403 )
				);
			}
		}

		return true;
	}
}

if ( ! function_exists( 'timu_svg_ability_sanitize' ) ) {
	/**
	 * Execute the sanitize-svg ability.
	 *
	 * Wraps {@see TIMU_SVG_Sanitizer::sanitize_string()} — the plugin's pure
	 * in-memory sanitizer — and reports issues via
	 * {@see TIMU_SVG_Sanitizer::get_last_issues()}. The attachment path reads
	 * the stored file into memory and sanitizes the copy; the stored file is
	 * never touched.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error Structured result or error.
	 */
	function timu_svg_ability_sanitize( $input = array() ) {
		$input = is_array( $input ) ? $input : array();

		$has_markup     = isset( $input['markup'] ) && '' !== trim( (string) $input['markup'] );
		$has_attachment = isset( $input['attachment_id'] ) && absint( $input['attachment_id'] ) > 0;

		if ( $has_markup === $has_attachment ) {
			return new WP_Error(
				'timu_svg_invalid_input',
				__( 'Provide exactly one of "markup" or "attachment_id".', 'thisismyurl-svg-support' ),
				array( 'status' => 400 )
			);
		}

		if ( $has_attachment ) {
			$raw = timu_svg_ability_read_attachment_svg( absint( $input['attachment_id'] ) );
			if ( is_wp_error( $raw ) ) {
				return $raw;
			}
		} else {
			$raw = (string) $input['markup'];
		}

		if ( ! class_exists( 'TIMU_SVG_Sanitizer' ) ) {
			return new WP_Error(
				'timu_svg_sanitizer_unavailable',
				__( 'The SVG sanitizer is unavailable.', 'thisismyurl-svg-support' ),
				array( 'status' => 500 )
			);
		}

		$sanitized = TIMU_SVG_Sanitizer::sanitize_string( $raw );
		if ( false === $sanitized ) {
			return new WP_Error(
				'timu_svg_sanitize_failed',
				/* translators: %s: short failure code from the sanitizer. */
				sprintf( __( 'Sanitization failed: %s', 'thisismyurl-svg-support' ), TIMU_SVG_Sanitizer::get_last_error() ),
				array( 'status' => 422 )
			);
		}

		return array(
			'sanitized'    => $sanitized,
			'was_modified' => $sanitized !== $raw,
			'issues'       => array_values( TIMU_SVG_Sanitizer::get_last_issues() ),
		);
	}
}

if ( ! function_exists( 'timu_svg_ability_read_attachment_svg' ) ) {
	/**
	 * Read an SVG attachment's raw markup into memory, decoding .svgz transparently.
	 *
	 * Read-only: the stored file is never modified.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return string|WP_Error Raw SVG markup, or error if not a readable SVG.
	 */
	function timu_svg_ability_read_attachment_svg( int $attachment_id ) {
		$post = get_post( $attachment_id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error(
				'timu_svg_not_found',
				__( 'Attachment not found.', 'thisismyurl-svg-support' ),
				array( 'status' => 404 )
			);
		}

		if ( 'image/svg+xml' !== get_post_mime_type( $attachment_id ) ) {
			return new WP_Error(
				'timu_svg_not_svg',
				__( 'That attachment is not an SVG.', 'thisismyurl-svg-support' ),
				array( 'status' => 415 )
			);
		}

		$path = get_attached_file( $attachment_id );
		if ( ! $path || ! is_readable( $path ) ) {
			return new WP_Error(
				'timu_svg_unreadable',
				__( 'The SVG file could not be read.', 'thisismyurl-svg-support' ),
				array( 'status' => 422 )
			);
		}

		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local attachment path on disk, not a remote URL.
		if ( false === $contents || '' === $contents ) {
			return new WP_Error(
				'timu_svg_empty',
				__( 'The SVG file is empty or unreadable.', 'thisismyurl-svg-support' ),
				array( 'status' => 422 )
			);
		}

		// Transparently decode .svgz (gzip) so callers can inspect the markup.
		if ( 0 === strncmp( $contents, "\x1f\x8b\x08", 3 ) && function_exists( 'gzdecode' ) ) {
			$decoded = gzdecode( $contents );
			if ( false !== $decoded ) {
				$contents = $decoded;
			}
		}

		return $contents;
	}
}
