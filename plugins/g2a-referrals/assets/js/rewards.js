/**
 * G2A Referrals — member dashboard Rewards tab.
 *
 * Progressive enhancement only. The tab is fully rendered server-side, so
 * with JavaScript off a member can still read their code, their balances and
 * their history — this file adds copy-to-clipboard, the QR download and a
 * quiet refresh.
 *
 * Authentication is cookie + nonce. Never Bearer: the JWT Authentication
 * plugin intercepts that scheme globally at rest_pre_dispatch and 403s the
 * whole request before it reaches a handler.
 */
( function () {
	'use strict';

	var config = window.g2arRewards || {};
	var root = document.querySelector( '[data-g2ar-rewards]' );

	if ( ! root || ! config.root ) {
		return;
	}

	function request( path ) {
		return fetch( config.root + path, {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': config.nonce }
		} ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( 'HTTP ' + response.status );
			}
			return response.json();
		} );
	}

	/* ── Copy the share link ─────────────────────────────────────── */

	var copyButton = root.querySelector( '[data-g2ar-copy]' );

	if ( copyButton ) {
		copyButton.addEventListener( 'click', function () {
			var value = copyButton.getAttribute( 'data-g2ar-copy' );

			var done = function () {
				copyButton.textContent = config.i18n.copied;
				copyButton.classList.add( 'is-copied' );
				window.setTimeout( function () {
					copyButton.textContent = config.i18n.copy;
					copyButton.classList.remove( 'is-copied' );
				}, 900 );
			};

			if ( navigator.clipboard && window.isSecureContext ) {
				navigator.clipboard.writeText( value ).then( done ).catch( fallbackCopy );
			} else {
				fallbackCopy();
			}

			function fallbackCopy() {
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
					/* Leave the link visible on the page to copy by hand. */
				}
				document.body.removeChild( field );
			}
		} );
	}

	/* ── QR download ─────────────────────────────────────────────── */

	var qrButton = root.querySelector( '[data-g2ar-qr]' );
	var qrTarget = root.querySelector( '[data-g2ar-qr-target]' );

	if ( qrButton && qrTarget ) {
		qrButton.addEventListener( 'click', function () {
			if ( qrTarget.dataset.loaded === '1' ) {
				qrTarget.hidden = ! qrTarget.hidden;
				return;
			}

			qrButton.disabled = true;

			request( 'me/qr' ).then( function ( data ) {
				qrTarget.innerHTML = '';

				var image = document.createElement( 'img' );
				image.src = data.data_uri;
				image.alt = data.code;

				var link = document.createElement( 'a' );
				link.href = data.data_uri;
				link.download = data.filename;
				link.className = 'g2ar-rw__sharebtn';
				link.textContent = data.filename;

				qrTarget.appendChild( image );
				qrTarget.appendChild( document.createElement( 'br' ) );
				qrTarget.appendChild( link );
				qrTarget.hidden = false;
				qrTarget.dataset.loaded = '1';
			} ).catch( function () {
				qrTarget.textContent = config.i18n.failed;
				qrTarget.hidden = false;
			} ).finally( function () {
				qrButton.disabled = false;
			} );
		} );
	}

	/* ── Quiet refresh ───────────────────────────────────────────────
	 * Balances change when a friend's payment confirms or a pass is spent
	 * at the counter, so the tile numbers are re-read periodically. Only
	 * while the tab is actually visible: polling a background tab burns
	 * the member's battery and this host's request budget for nothing. */

	var tiles = root.querySelectorAll( '.g2ar-rw__tiles .g2ar-rw__num' );

	function refresh() {
		if ( document.hidden || tiles.length < 2 ) {
			return;
		}

		request( 'me' ).then( function ( data ) {
			if ( ! data || ! data.balances ) {
				return;
			}
			tiles[0].textContent = format( data.balances.guest_passes );
			tiles[1].textContent = format( data.balances.free_months );
		} ).catch( function () {
			/* A failed poll is not worth telling the member about — the
			 * server-rendered numbers are still on screen. */
		} );
	}

	function format( value ) {
		var number = parseFloat( value );

		if ( isNaN( number ) ) {
			return '0';
		}

		return Math.abs( number - Math.round( number ) ) < 0.005
			? String( Math.round( number ) )
			: number.toFixed( 2 );
	}

	window.setInterval( refresh, 30000 );
	document.addEventListener( 'visibilitychange', function () {
		if ( ! document.hidden ) {
			refresh();
		}
	} );
}() );
