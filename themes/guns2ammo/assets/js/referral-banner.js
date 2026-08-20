/**
 * Top promotional banner.
 *
 * The banner ships as an empty, height-reserved placeholder because AirLift
 * caches the page — a member variant in the HTML would be served to every
 * anonymous visitor behind it. This fetches /wp-json/g2ar/v1/context once
 * and fills the banner in for whoever is actually looking at it.
 *
 * Dismissal is a 7-day COOKIE, not localStorage: the dismissed state has to
 * be readable before the fetch resolves so a dismissed banner never flashes
 * back in, and a cookie is the one store that also travels to the server.
 */
( function () {
	'use strict';

	var strings = window.g2aRefBanner || { copy: 'Copy', copied: 'Copied', close: 'Dismiss' };
	var COOKIE = 'g2a_refbanner_dismissed';

	function readCookie( name ) {
		var parts = document.cookie ? document.cookie.split( ';' ) : [];

		for ( var i = 0; i < parts.length; i++ ) {
			var pair = parts[ i ].trim();
			if ( pair.indexOf( name + '=' ) === 0 ) {
				return decodeURIComponent( pair.slice( name.length + 1 ) );
			}
		}

		return '';
	}

	function writeCookie( name, value, days ) {
		var expires = new Date( Date.now() + ( days * 86400000 ) ).toUTCString();
		var secure = 'https:' === window.location.protocol ? '; Secure' : '';

		document.cookie = name + '=' + encodeURIComponent( value ) +
			'; expires=' + expires + '; path=/; SameSite=Lax' + secure;
	}

	function element( tag, className, text ) {
		var node = document.createElement( tag );

		if ( className ) {
			node.className = className;
		}

		if ( text ) {
			node.textContent = text;
		}

		return node;
	}

	var banner = document.querySelector( '[data-g2a-refbanner]' );

	if ( ! banner ) {
		return;
	}

	var dismissDays = parseInt( banner.getAttribute( 'data-dismiss-days' ), 10 ) || 7;

	// Checked before the fetch so a dismissed banner never reappears, even
	// for the moment the request is in flight.
	if ( '1' === readCookie( COOKIE ) ) {
		banner.classList.add( 'is-dismissed' );
		return;
	}

	var endpoint = banner.getAttribute( 'data-endpoint' );

	if ( ! endpoint ) {
		return;
	}

	fetch( endpoint, { credentials: 'same-origin' } )
		.then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( 'HTTP ' + response.status );
			}
			return response.json();
		} )
		.then( render )
		.catch( function () {
			// A failed context call leaves the reserved strip empty rather
			// than guessing a variant. Guessing wrong would show a member
			// the "become a member" pitch.
			banner.classList.add( 'is-dismissed' );
		} );

	function render( data ) {
		if ( ! data || 'none' === data.banner_variant ) {
			banner.classList.add( 'is-dismissed' );
			return;
		}

		var copy = data.copy || {};
		var inner = element( 'div', 'g2a-refbanner__inner' );

		inner.appendChild( element( 'span', 'g2a-refbanner__lead', copy.lead || '' ) );
		inner.appendChild( element( 'span', 'g2a-refbanner__body', copy.body || '' ) );

		if ( data.is_member && data.referral_code ) {
			var code = element( 'span', 'g2a-refbanner__code', data.referral_code );
			inner.appendChild( code );

			var copyButton = element( 'button', 'g2a-refbanner__copy', strings.copy );
			copyButton.type = 'button';
			copyButton.addEventListener( 'click', function () {
				copyToClipboard( data.share_url || data.referral_code, copyButton );
			} );
			inner.appendChild( copyButton );
		}

		if ( copy.cta && copy.href ) {
			var cta = element( 'a', 'g2a-refbanner__cta', copy.cta );
			cta.href = copy.href;
			inner.appendChild( cta );
		}

		var dismiss = element( 'button', 'g2a-refbanner__dismiss', '×' );
		dismiss.type = 'button';
		dismiss.setAttribute( 'aria-label', strings.close );
		dismiss.addEventListener( 'click', function () {
			writeCookie( COOKIE, '1', dismissDays );
			banner.classList.add( 'is-dismissed' );
		} );
		inner.appendChild( dismiss );

		banner.appendChild( inner );
	}

	function copyToClipboard( value, button ) {
		var done = function () {
			button.textContent = strings.copied;
			button.classList.add( 'is-copied' );
			window.setTimeout( function () {
				button.textContent = strings.copy;
				button.classList.remove( 'is-copied' );
			}, 900 );
		};

		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( value ).then( done ).catch( fallback );
		} else {
			fallback();
		}

		function fallback() {
			var field = document.createElement( 'textarea' );
			field.value = value;
			field.setAttribute( 'readonly', '' );
			field.style.position = 'absolute';
			field.style.left = '-9999px';
			document.body.appendChild( field );
			field.select();
			try {
				document.execCommand( 'copy' );
				done();
			} catch ( error ) {
				/* The code is printed on the banner; it can be copied by hand. */
			}
			document.body.removeChild( field );
		}
	}
}() );
