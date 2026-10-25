/* global ipscannerConnect */
( function () {
	'use strict';

	var button = document.querySelector( '[data-ipscanner-connect]' );
	if ( ! button || ! window.ipscannerConnect || ! window.fetch ) {
		return;
	}
	var wait = document.querySelector( '.ipscanner-connect-wait' );
	var timer = null;
	var started = 0;

	function finish( result ) {
		window.clearInterval( timer );
		timer = null;
		button.classList.remove( 'is-busy' );
		button.removeAttribute( 'aria-disabled' );
		if ( wait ) {
			wait.textContent = '';
		}
		if ( 'connected' === result || 'failed' === result ) {
			window.location.reload();
		}
	}

	function poll() {
		if ( Date.now() - started > 15 * 60 * 1000 ) {
			finish( '' );
			return;
		}
		window.fetch( ipscannerConnect.status, { credentials: 'same-origin' } )
			.then( function ( res ) {
				return res.json();
			} )
			.then( function ( body ) {
				var result = body && body.success && body.data ? body.data.result : '';
				if ( result ) {
					finish( result );
				}
			} )
			.catch( function () {} );
	}

	window.addEventListener( 'message', function ( event ) {
		if ( event.origin === window.location.origin && event.data && 'ipscanner-connect' === event.data.source && timer ) {
			finish( event.data.result );
		}
	} );

	button.addEventListener( 'click', function ( event ) {
		var w = 520;
		var h = 720;
		var left = window.screenX + Math.max( 0, ( window.outerWidth - w ) / 2 );
		var top = window.screenY + Math.max( 0, ( window.outerHeight - h ) / 3 );
		var popup = window.open( button.href + '&popup=1', 'ipscanner-connect', 'popup,width=' + w + ',height=' + h + ',left=' + left + ',top=' + top );
		if ( ! popup ) {
			return;
		}
		event.preventDefault();
		popup.focus();
		button.classList.add( 'is-busy' );
		button.setAttribute( 'aria-disabled', 'true' );
		if ( wait ) {
			wait.textContent = ipscannerConnect.waiting;
		}
		started = Date.now();
		window.clearInterval( timer );
		timer = window.setInterval( poll, 2000 );
	} );
}() );

( function () {
	'use strict';

	var notice = document.querySelector( '.ipscanner-review' );
	if ( ! notice ) {
		return;
	}
	notice.addEventListener( 'click', function ( event ) {
		if ( event.target.closest( '[data-ipscanner-review]' ) ) {
			window.setTimeout( function () {
				notice.remove();
			}, 0 );
		} else if ( event.target.closest( '.notice-dismiss' ) && window.fetch ) {
			window.fetch( notice.getAttribute( 'data-later' ), { credentials: 'same-origin' } ).catch( function () {} );
		}
	} );
}() );
