/**
 * Retroactive SVG sanitization scan — admin UI driver.
 *
 * Wired to the "Sanitize Existing SVGs" button on the SVG Support settings
 * page. Batches AJAX requests to timu_svg_scan_existing until the server
 * reports no more files remain.
 *
 * Globals injected via wp_localize_script():
 *   timuSvgScan.ajaxUrl  — admin-ajax.php URL
 *   timuSvgScan.nonce    — wp_create_nonce( 'timu_svg_scan_existing' )
 *   timuSvgScan.i18n     — translated UI strings
 */
/* global timuSvgScan, jQuery */
( function ( $ ) {
	'use strict';

	$( function () {
		var btn      = $( '#timu-svg-scan-btn' );
		var progress = $( '#timu-svg-scan-progress' );
		var errList  = $( '#timu-svg-scan-errors' );

		if ( ! btn.length ) {
			return;
		}

		btn.on( 'click', function () {
			btn.prop( 'disabled', true );
			progress.text( timuSvgScan.i18n.scanning );
			errList.empty();
			runBatch( 0, 0 );
		} );

		/**
		 * Send one batch request.
		 *
		 * @param {number} offset     Current page offset.
		 * @param {number} processed  Running total of processed files.
		 */
		function runBatch( offset, processed ) {
			$.ajax( {
				url:    timuSvgScan.ajaxUrl,
				method: 'POST',
				data: {
					action: 'timu_svg_scan_existing',
					nonce:  timuSvgScan.nonce,
					offset: offset
				}
			} )
			.done( function ( response ) {
				if ( ! response.success ) {
					var msg = ( response.data && response.data.message )
						? response.data.message
						: timuSvgScan.i18n.error;
					progress.text( msg );
					btn.prop( 'disabled', false );
					return;
				}

				var data       = response.data;
				var newTotal   = processed + data.processed;
				var total      = data.total;

				progress.text(
					timuSvgScan.i18n.processed + ' ' +
					newTotal + ' ' +
					timuSvgScan.i18n.of + ' ' +
					total + ' ' +
					timuSvgScan.i18n.files
				);

				// Append any per-file errors returned from this batch.
				if ( data.errors && data.errors.length ) {
					$.each( data.errors, function ( i, errMsg ) {
						errList.append( $( '<li>' ).text( errMsg ) );
					} );
				}

				if ( data.has_more ) {
					runBatch( data.next_offset, newTotal );
				} else {
					progress.text( timuSvgScan.i18n.done );
					btn.prop( 'disabled', false );
				}
			} )
			.fail( function () {
				progress.text( timuSvgScan.i18n.error );
				btn.prop( 'disabled', false );
			} );
		}
	} );
}( jQuery ) );
