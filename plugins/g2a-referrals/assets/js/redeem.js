/**
 * "Use 1 Guest Pass" opt-in on the booking form.
 *
 * Two jobs:
 *   1. Put an opt-in checkbox on the booking summary. Redemption is never
 *      automatic — a pass is the member's to spend, not ours.
 *   2. Attach use_guest_pass to the outgoing booking request so the server
 *      sees the choice. The booking engine builds that request internally,
 *      so this wraps fetch and XHR rather than reaching into its code.
 *
 * The server is the authority on whether a pass is actually spent. In
 * particular the "$0 total means don't consume" rule lives in PHP, not
 * here: this checkbox only expresses intent.
 */
( function () {
	'use strict';

	var config = window.g2arRedeem || {};

	if ( ! config.bookingNamespace ) {
		return;
	}

	var BOOKINGS = '/' + config.bookingNamespace + '/bookings';
	var checkbox = null;

	function wanted() {
		return !! ( checkbox && checkbox.checked );
	}

	function isBookingCreate( url, method ) {
		return 'POST' === String( method || '' ).toUpperCase() &&
			String( url || '' ).indexOf( BOOKINGS ) !== -1;
	}

	/* ── Attach the flag to whichever transport the form uses ────────── */

	var nativeFetch = window.fetch;

	if ( typeof nativeFetch === 'function' ) {
		window.fetch = function ( input, init ) {
			var url = ( typeof input === 'string' ) ? input : ( input && input.url );
			var options = init || {};
			var method = options.method || ( input && input.method );

			if ( wanted() && isBookingCreate( url, method ) && typeof options.body === 'string' ) {
				options.body = addFlag( options.body );
				return nativeFetch.call( this, input, options );
			}

			return nativeFetch.call( this, input, init );
		};
	}

	var nativeOpen = window.XMLHttpRequest && window.XMLHttpRequest.prototype.open;
	var nativeSend = window.XMLHttpRequest && window.XMLHttpRequest.prototype.send;

	if ( nativeOpen && nativeSend ) {
		window.XMLHttpRequest.prototype.open = function ( method, url ) {
			this.__g2arMethod = method;
			this.__g2arUrl = url;
			return nativeOpen.apply( this, arguments );
		};

		window.XMLHttpRequest.prototype.send = function ( body ) {
			if ( wanted() && isBookingCreate( this.__g2arUrl, this.__g2arMethod ) && typeof body === 'string' ) {
				return nativeSend.call( this, addFlag( body ) );
			}

			return nativeSend.apply( this, arguments );
		};
	}

	function addFlag( body ) {
		// JSON payload.
		try {
			var parsed = JSON.parse( body );

			if ( parsed && typeof parsed === 'object' ) {
				parsed.use_guest_pass = 1;
				return JSON.stringify( parsed );
			}
		} catch ( error ) {
			/* Not JSON — fall through to form encoding. */
		}

		return body + ( body ? '&' : '' ) + 'use_guest_pass=1';
	}

	/* ── The checkbox ────────────────────────────────────────────────── */

	function mount() {
		if ( checkbox ) {
			return true;
		}

		var host = document.querySelector( '.g2ab-aside__card' ) ||
			document.querySelector( '.g2ab-aside' ) ||
			document.querySelector( '.g2ab-shell' );

		if ( ! host ) {
			return false;
		}

		var wrap = document.createElement( 'div' );
		wrap.className = 'g2ar-redeem';
		wrap.style.marginTop = '14px';
		wrap.style.paddingTop = '14px';
		wrap.style.borderTop = '1px solid var(--color-hairline)';

		var label = document.createElement( 'label' );
		label.style.display = 'flex';
		label.style.alignItems = 'flex-start';
		label.style.gap = '8px';
		label.style.cursor = 'pointer';
		label.style.fontSize = '13px';
		label.style.lineHeight = '1.4';
		label.style.color = 'var(--color-fog)';

		checkbox = document.createElement( 'input' );
		checkbox.type = 'checkbox';
		checkbox.name = 'use_guest_pass';
		checkbox.value = '1';
		checkbox.style.marginTop = '2px';

		var text = document.createElement( 'span' );
		text.textContent = config.i18n.label;

		label.appendChild( checkbox );
		label.appendChild( text );

		var note = document.createElement( 'p' );
		note.style.margin = '6px 0 0 24px';
		note.style.fontSize = '12px';
		note.style.color = 'var(--color-silver)';
		note.textContent = config.i18n.have.replace( '%s', formatBalance( config.balance ) );

		wrap.appendChild( label );
		wrap.appendChild( note );
		host.appendChild( wrap );

		return true;
	}

	function formatBalance( value ) {
		var number = parseFloat( value );

		if ( isNaN( number ) ) {
			return '0';
		}

		return Math.abs( number - Math.round( number ) ) < 0.005
			? String( Math.round( number ) )
			: number.toFixed( 2 );
	}

	function boot() {
		if ( mount() ) {
			return;
		}

		// The booking shell renders after its own scripts settle, so retry
		// briefly rather than giving up on the first miss. Bounded: ten
		// tries over five seconds, then stop — an unbounded observer on a
		// page that never shows the form is a leak.
		var tries = 0;
		var timer = window.setInterval( function () {
			tries++;

			if ( mount() || tries >= 10 ) {
				window.clearInterval( timer );
			}
		}, 500 );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
