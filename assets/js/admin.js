/* Hail Mail Connect — admin JS */
( function ( $ ) {
	'use strict';

	$( function () {
		// Test connection (no-op if the button isn't on the page).
		$( '#hail-mail-connect-test' ).on( 'click', function () {
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

		// Click-to-copy ID chips (delegated).
		$( document ).on( 'click', '.hmc-copy', function () {
			var $btn = $( this );
			var text = String( $btn.data( 'copy' ) );
			copyText( text, function () {
				var orig = $btn.html();
				$btn.addClass( 'is-copied' ).text( 'Copied!' );
				setTimeout( function () {
					$btn.removeClass( 'is-copied' ).html( orig );
				}, 1200 );
			} );
		} );
	} );

	function copyText( text, done ) {
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( done, function () {
				fallbackCopy( text, done );
			} );
		} else {
			fallbackCopy( text, done );
		}
	}

	function fallbackCopy( text, done ) {
		var ta = document.createElement( 'textarea' );
		ta.value = text;
		ta.style.position = 'fixed';
		ta.style.opacity = '0';
		document.body.appendChild( ta );
		ta.focus();
		ta.select();
		try { document.execCommand( 'copy' ); } catch ( e ) {}
		document.body.removeChild( ta );
		done();
	}
}( jQuery ) );
