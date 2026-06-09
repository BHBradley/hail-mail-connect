/* Hail Mail Connect — front-end self-service subscriptions */
( function ( $ ) {
	'use strict';

	$( function () {
		$( '[data-hmc-subscribe]' ).on( 'submit', function ( e ) {
			e.preventDefault();

			var $form    = $( this );
			var $btn     = $form.find( '.hmc-subscribe__save' );
			var $msg     = $form.find( '.hmc-subscribe__message' );
			var lists    = $form.find( 'input[name="lists[]"]:checked' ).map( function () {
				return this.value;
			} ).get();

			$btn.prop( 'disabled', true );
			$msg.removeClass( 'is-error is-success' ).text( hailMailConnect.savingLabel || 'Saving…' );

			$.post( hailMailConnect.ajaxUrl, {
				action: 'hail_mail_subscribe',
				nonce: hailMailConnect.nonce,
				lists: lists
			} ).done( function ( res ) {
				if ( res && res.success ) {
					$msg.addClass( 'is-success' ).text( res.data.message || 'Saved.' );
				} else {
					$msg.addClass( 'is-error' ).text( ( res && res.data && res.data.message ) ? res.data.message : 'Something went wrong.' );
				}
			} ).fail( function () {
				$msg.addClass( 'is-error' ).text( 'Request failed. Please try again.' );
			} ).always( function () {
				$btn.prop( 'disabled', false );
			} );
		} );
	} );
}( jQuery ) );
