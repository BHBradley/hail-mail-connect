/* Hail Mail Connect — admin JS */
( function ( $ ) {
	'use strict';

	$( function () {
		var $testBtn = $( '#hail-mail-connect-test' );
		if ( ! $testBtn.length ) {
			return;
		}

		$testBtn.on( 'click', function () {
			var $result = $( '#hail-mail-connect-test-result' );
			$result.text( 'Testing…' );
			$.post( hailMailConnectAdmin.ajaxUrl, {
				action: 'hail_mail_connect_test',
				nonce: hailMailConnectAdmin.nonce
			} ).done( function ( res ) {
				if ( res && res.success ) {
					$result.html( '<span style="color:#46b450;">✓ ' + ( res.data.message || 'OK' ) + '</span>' );
				} else {
					$result.html( '<span style="color:#dc3232;">✕ ' + ( res.data && res.data.message ? res.data.message : 'Failed' ) + '</span>' );
				}
			} ).fail( function () {
				$result.html( '<span style="color:#dc3232;">✕ Request failed</span>' );
			} );
		} );
	} );
}( jQuery ) );
