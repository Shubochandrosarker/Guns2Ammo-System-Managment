/*!
 * Advanced FFL Checkout — Dealer Selector Widget (v1.1.1)
 * Multi-filter search (ZIP / name / license / phone) + Google Maps view + recommended badges.
 * Vanilla JS. Compatible with WC Classic Checkout.
 *
 * @package WpisticFFL
 */
( function () {
	'use strict';

	const cfg   = window.wpistic_ffl || {};
	const i18n  = cfg.i18n || {};
	const apiUrl = cfg.api_url || '';

	let selectedDealer = null;
	let currentResults = [];
	let mapInstance    = null;
	let mapMarkers     = [];
	let mapInfo        = null;
	let activeTab      = 'zip';
	let activeView     = 'list';

	function el( id ) { return document.getElementById( id ); }
	function qs( sel, root ) { return ( root || document ).querySelector( sel ); }
	function qsa( sel, root ) { return Array.prototype.slice.call( ( root || document ).querySelectorAll( sel ) ); }

	document.addEventListener( 'DOMContentLoaded', boot );

	function boot() {
		const widget = el( 'wpistic-ffl-widget' );
		if ( ! widget ) return;

		// Tab switching
		qsa( '.wpistic-ffl-tab', widget ).forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				setActiveTab( tab.dataset.tab );
			} );
		} );

		// View toggle
		qsa( '.wpistic-ffl-view-btn', widget ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				setActiveView( btn.dataset.view );
			} );
		} );

		// Search buttons / enter key
		const searchBtn = el( 'wpistic-ffl-search-btn' );
		if ( searchBtn ) {
			searchBtn.addEventListener( 'click', handleSearch );
		}

		qsa( '.wpistic-ffl-input', widget ).forEach( function ( inp ) {
			inp.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Enter' ) { e.preventDefault(); handleSearch(); }
			} );
		} );

		// ZIP: auto-format + auto-search
		const zipInput = el( 'wpistic-ffl-zip' );
		if ( zipInput ) {
			zipInput.addEventListener( 'input', function () {
				this.value = this.value.replace( /\D/g, '' ).slice( 0, 5 );
			} );

			// Auto-trigger if ZIP pre-filled from billing
			if ( zipInput.value && zipInput.value.length === 5 ) {
				handleSearch();
			}
		}

		// Change-dealer button
		const changeBtn = el( 'wpistic-ffl-change-btn' );
		if ( changeBtn ) {
			changeBtn.addEventListener( 'click', resetSelection );
		}

		// Sync billing ZIP → FFL ZIP
		watchBillingZip();
	}

	// ── Tab + View switching ──────────────────────────────────────────────────

	function setActiveTab( tab ) {
		activeTab = tab;
		qsa( '.wpistic-ffl-tab' ).forEach( function ( t ) {
			t.classList.toggle( 'is-active', t.dataset.tab === tab );
			t.setAttribute( 'aria-selected', t.dataset.tab === tab ? 'true' : 'false' );
		} );
		qsa( '.wpistic-ffl-filter-panel' ).forEach( function ( p ) {
			p.classList.toggle( 'is-active', p.dataset.panel === tab );
		} );
	}

	function setActiveView( view ) {
		activeView = view;
		qsa( '.wpistic-ffl-view-btn' ).forEach( function ( b ) {
			b.classList.toggle( 'is-active', b.dataset.view === view );
		} );
		const listEl = el( 'wpistic-ffl-results' );
		const mapEl  = el( 'wpistic-ffl-map' );
		if ( view === 'map' ) {
			if ( listEl ) listEl.style.display = 'none';
			if ( mapEl )  mapEl.style.display  = 'block';
			renderMap();
		} else {
			if ( listEl ) listEl.style.display = '';
			if ( mapEl )  mapEl.style.display  = 'none';
		}
	}

	// ── Search ────────────────────────────────────────────────────────────────

	function collectFilters() {
		const zip     = ( el( 'wpistic-ffl-zip' )     || {} ).value || '';
		const name    = ( el( 'wpistic-ffl-name' )    || {} ).value || '';
		const license = ( el( 'wpistic-ffl-license' ) || {} ).value || '';
		const phone   = ( el( 'wpistic-ffl-phone' )   || {} ).value || '';
		const state   = ( el( 'wpistic-ffl-state' )   || {} ).value || '';
		const radius  = ( el( 'wpistic-ffl-radius' )  || {} ).value || cfg.radius || 50;
		return { zip: zip.trim(), name: name.trim(), license: license.trim(), phone: phone.trim(), state: state.trim(), radius };
	}

	function handleSearch() {
		const f = collectFilters();

		// At least one filter
		if ( ! f.zip && ! f.name && ! f.license && ! f.phone && ! f.state ) {
			showError( i18n.min_filter || 'Enter at least one filter to search.' );
			return;
		}

		// ZIP validity only enforced when ZIP tab is active
		if ( activeTab === 'zip' && f.zip && ! /^\d{5}$/.test( f.zip ) ) {
			showError( i18n.invalid_zip );
			return;
		}

		setLoading( true );
		clearResults();

		const params = new URLSearchParams();
		if ( f.zip )     params.set( 'zip',     f.zip );
		if ( f.name )    params.set( 'name',    f.name );
		if ( f.license ) params.set( 'license', f.license );
		if ( f.phone )   params.set( 'phone',   f.phone );
		if ( f.state )   params.set( 'state',   f.state );
		if ( f.radius )  params.set( 'radius',  f.radius );
		params.set( 'limit', '30' );

		fetch( apiUrl + '?' + params.toString(), {
			headers: { 'X-WP-Nonce': cfg.nonce || '' },
			credentials: 'same-origin'
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( json ) {
				setLoading( false );
				if ( json && json.code && json.message ) {
					showError( json.message );
					return;
				}
				currentResults = Array.isArray( json.dealers ) ? json.dealers : [];
				renderResults( currentResults, json.origin );
			} )
			.catch( function () {
				setLoading( false );
				showError( i18n.error );
			} );
	}

	// ── Render ────────────────────────────────────────────────────────────────

	function renderResults( dealers, origin ) {
		const resultsEl = el( 'wpistic-ffl-results' );
		const bar       = qs( '.wpistic-ffl-resultsbar' );
		const countEl   = el( 'wpistic-ffl-count' );
		const viewBar   = qs( '.wpistic-ffl-viewtoggle' );

		if ( ! resultsEl ) return;

		if ( ! dealers || ! dealers.length ) {
			resultsEl.innerHTML = '<div class="wpistic-ffl-empty">' + escapeHtml( i18n.no_results || 'No dealers found.' ) + '</div>';
			if ( bar ) bar.style.display = 'none';
			if ( viewBar ) viewBar.style.display = 'none';
			return;
		}

		if ( bar ) {
			bar.style.display = 'flex';
			const word = dealers.length === 1 ? ( i18n.count_one || 'dealer found' ) : ( i18n.count_many || 'dealers found' );
			countEl.textContent = dealers.length + ' ' + word + ( origin ? ' near ' + ( origin.city || origin.zip ) + ', ' + origin.state : '' );
		}

		if ( viewBar && cfg.gmaps_key ) viewBar.style.display = 'inline-flex';

		resultsEl.innerHTML = dealers.map( function ( d, i ) { return cardHtml( d, i ); } ).join( '' );

		// Wire select buttons
		qsa( '.js-select-dealer', resultsEl ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				const idx = parseInt( btn.dataset.idx, 10 );
				handleSelect( dealers[ idx ] );
			} );
		} );

		// If user is in map view, re-render markers
		if ( activeView === 'map' ) {
			renderMap();
		}
	}

	function cardHtml( d, idx ) {
		const recommended = !! d.is_preferred;
		const addr = [ d.premise_street, d.premise_city, d.premise_state, d.premise_zip ].filter( Boolean ).join( ', ' );
		const distance = ( d.distance_miles != null ) ? ( d.distance_miles + ' ' + ( i18n.miles_away || 'mi' ) ) : '';
		const feeLabel = d.transfer_fee > 0
			? '<strong>' + (i18n.fee_label || 'Transfer Fee') + ':</strong> $' + parseFloat(d.transfer_fee).toFixed(2)
			: '<strong>' + (i18n.fee_free || 'Contact dealer for fee') + '</strong>';

		return '' +
			'<div class="wpistic-ffl-card ' + ( recommended ? 'is-recommended' : '' ) + '">' +
				'<div class="wpistic-ffl-card__main">' +
					'<h4 class="wpistic-ffl-card__title">' +
						escapeHtml( d.business_name || 'Unnamed FFL' ) +
						( recommended ? recommendedBadge() : '' ) +
					'</h4>' +
					'<p class="wpistic-ffl-card__address">' + escapeHtml( addr ) + '</p>' +
					'<div class="wpistic-ffl-card__meta">' +
						( distance ? '<span class="wpistic-ffl-card__distance">📍 ' + escapeHtml( distance ) + '</span>' : '' ) +
						( d.phone ? '<span>📞 ' + escapeHtml( d.phone ) + '</span>' : '' ) +
						'<span><strong>FFL:</strong> ' + escapeHtml( d.license_number || '—' ) + '</span>' +
						'<span>' + feeLabel + '</span>' +
						( ! d.has_geo ? '<span style="color:#F59E0B;">' + escapeHtml( i18n.no_geo || '' ) + '</span>' : '' ) +
					'</div>' +
				'</div>' +
				'<div class="wpistic-ffl-card__actions">' +
					'<button type="button" class="wpistic-ffl-btn wpistic-ffl-btn--primary wpistic-ffl-btn--small js-select-dealer" data-idx="' + idx + '">' +
						escapeHtml( i18n.select_btn || 'Select' ) +
					'</button>' +
				'</div>' +
			'</div>';
	}

	function recommendedBadge() {
		return '<span class="wpistic-ffl-recommended">' +
			'<svg viewBox="0 0 24 24"><path d="M12 .587l3.668 7.568L24 9.75l-6 5.852 1.416 8.284L12 19.771l-7.416 4.115L6 15.602 0 9.75l8.332-1.595z"/></svg>' +
			escapeHtml( i18n.preferred || 'Recommended' ) +
		'</span>';
	}

	// ── Selection ─────────────────────────────────────────────────────────────

	function handleSelect( dealer ) {
		selectedDealer = dealer;

		const hidId   = el( 'wpistic_ffl_dealer_id' );
		const hidName = el( 'wpistic_ffl_dealer_name' );
		const hidZip  = el( 'wpistic_ffl_dealer_zip' );
		if ( hidId )   hidId.value   = dealer.id;
		if ( hidName ) hidName.value = dealer.business_name || '';
		if ( hidZip )  hidZip.value  = dealer.premise_zip || '';

		const addr = [ dealer.premise_street, dealer.premise_city, dealer.premise_state, dealer.premise_zip ].filter( Boolean ).join( ', ' );
		const info = el( 'wpistic-ffl-selected-info' );
		if ( info ) {
			info.innerHTML =
				'<strong>' + escapeHtml( dealer.business_name || '' ) + ( dealer.is_preferred ? ' ' + recommendedBadge() : '' ) + '</strong>' +
				escapeHtml( addr ) +
				( dealer.phone ? '<br>📞 ' + escapeHtml( dealer.phone ) : '' ) +
				'<br>FFL: ' + escapeHtml( dealer.license_number || '—' );
		}

		hideResultsArea();
		const selected = el( 'wpistic-ffl-selected' );
		if ( selected ) selected.style.display = 'block';

		// Smooth scroll to confirmation
		if ( selected && selected.scrollIntoView ) {
			selected.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
		}

		// Let WC refresh the checkout review block (fees, totals, etc.)
		if ( window.jQuery ) { jQuery( 'body' ).trigger( 'update_checkout' ); }
	}

	function resetSelection() {
		selectedDealer = null;
		[ 'wpistic_ffl_dealer_id', 'wpistic_ffl_dealer_name', 'wpistic_ffl_dealer_zip' ].forEach( function ( id ) {
			const e = el( id ); if ( e ) e.value = '';
		} );
		const sel = el( 'wpistic-ffl-selected' );
		if ( sel ) sel.style.display = 'none';
		showResultsArea();
	}

	function hideResultsArea() {
		qsa( '.wpistic-ffl-tabs, .wpistic-ffl-filters, .wpistic-ffl-resultsbar, .wpistic-ffl-results, .wpistic-ffl-map, .wpistic-ffl-viewtoggle' ).forEach( function ( e ) { e.style.display = 'none'; } );
	}
	function showResultsArea() {
		const tabs = qs( '.wpistic-ffl-tabs' );     if ( tabs )    tabs.style.display = 'flex';
		const flt  = qs( '.wpistic-ffl-filters' );  if ( flt )     flt.style.display  = 'flex';
		const rb   = qs( '.wpistic-ffl-resultsbar' ); if ( rb && currentResults.length ) rb.style.display = 'flex';
		const res  = el( 'wpistic-ffl-results' );   if ( res )     res.style.display  = 'grid';
		const vb   = qs( '.wpistic-ffl-viewtoggle' ); if ( vb && cfg.gmaps_key && currentResults.length ) vb.style.display = 'inline-flex';
	}

	// ── Google Maps ───────────────────────────────────────────────────────────

	function renderMap() {
		const mapEl = el( 'wpistic-ffl-map' );
		if ( ! mapEl || ! cfg.gmaps_key ) return;
		if ( ! window.google || ! window.google.maps ) {
			// GMaps JS still loading — retry shortly
			setTimeout( renderMap, 250 );
			return;
		}

		const withGeo = currentResults.filter( function ( d ) { return d.has_geo && d.lat && d.lng; } );
		if ( ! withGeo.length ) {
			mapEl.innerHTML = '<div class="wpistic-ffl-empty" style="padding:40px 16px;">No dealers with map coordinates. Switch back to List view.</div>';
			return;
		}

		// Clear previous markers
		mapMarkers.forEach( function ( m ) { m.setMap( null ); } );
		mapMarkers = [];

		if ( ! mapInstance ) {
			mapInstance = new google.maps.Map( mapEl, {
				center: { lat: withGeo[0].lat, lng: withGeo[0].lng },
				zoom: 9,
				mapTypeControl: false,
				streetViewControl: false,
				fullscreenControl: true,
				styles: [
					{ featureType: 'poi', stylers: [ { visibility: 'off' } ] },
					{ featureType: 'transit', stylers: [ { visibility: 'off' } ] }
				]
			} );
			mapInfo = new google.maps.InfoWindow();
		}

		const bounds = new google.maps.LatLngBounds();

		withGeo.forEach( function ( d, i ) {
			const pos = { lat: d.lat, lng: d.lng };
			const icon = d.is_preferred ? starIcon() : pinIcon();

			const marker = new google.maps.Marker( {
				position: pos,
				map: mapInstance,
				animation: google.maps.Animation.DROP,
				title: d.business_name,
				icon: icon,
				zIndex: d.is_preferred ? 999 : ( 100 - i )
			} );

			marker.addListener( 'click', function () {
				mapInfo.setContent( infoWindowHtml( d, i ) );
				mapInfo.open( mapInstance, marker );
				// Bounce once to draw attention
				marker.setAnimation( google.maps.Animation.BOUNCE );
				setTimeout( function () { marker.setAnimation( null ); }, 1500 );
			} );

			mapMarkers.push( marker );
			bounds.extend( pos );
		} );

		if ( withGeo.length === 1 ) {
			mapInstance.setCenter( { lat: withGeo[0].lat, lng: withGeo[0].lng } );
			mapInstance.setZoom( 12 );
		} else {
			mapInstance.fitBounds( bounds );
		}

		// Wire in-InfoWindow select buttons via delegation
		mapEl.addEventListener( 'click', function ( e ) {
			const t = e.target;
			if ( t && t.classList && t.classList.contains( 'wpistic-ffl-iw__select' ) ) {
				const idx = parseInt( t.dataset.idx, 10 );
				if ( ! isNaN( idx ) ) handleSelect( currentResults[ idx ] );
			}
		} );
	}

	function pinIcon() {
		return {
			path: 'M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z',
			fillColor: '#C8102E',
			fillOpacity: 1,
			strokeColor: '#7F0A1D',
			strokeWeight: 1.5,
			scale: 1.6,
			anchor: new google.maps.Point( 12, 22 ),
			labelOrigin: new google.maps.Point( 12, 9 )
		};
	}

	function starIcon() {
		return {
			path: 'M12 2l2.9 6.9L22 10l-5.6 4.6L18 22l-6-4-6 4 1.6-7.4L2 10l7.1-1.1L12 2z',
			fillColor: '#FBBF24',
			fillOpacity: 1,
			strokeColor: '#B45309',
			strokeWeight: 1.5,
			scale: 1.5,
			anchor: new google.maps.Point( 12, 22 )
		};
	}

	function infoWindowHtml( d, idx ) {
		const addr = [ d.premise_street, d.premise_city, d.premise_state, d.premise_zip ].filter( Boolean ).join( ', ' );
		const star = d.is_preferred ? ' ⭐' : '';
		return '<div class="wpistic-ffl-iw">' +
			'<p class="wpistic-ffl-iw__name">' + escapeHtml( d.business_name || '' ) + star + '</p>' +
			'<p class="wpistic-ffl-iw__addr">' + escapeHtml( addr ) + ( d.phone ? '<br>📞 ' + escapeHtml( d.phone ) : '' ) + '</p>' +
			'<button type="button" class="wpistic-ffl-iw__select" data-idx="' + idx + '">Select this dealer</button>' +
		'</div>';
	}

	// ── Utilities ─────────────────────────────────────────────────────────────

	function setLoading( loading ) {
		const btn = el( 'wpistic-ffl-search-btn' );
		if ( ! btn ) return;
		if ( loading ) {
			btn.disabled = true;
			btn.dataset.orig = btn.dataset.orig || btn.innerHTML;
			btn.innerHTML = '<span class="wpistic-ffl-loading" style="background:transparent;padding:0;color:#fff;"></span>' + escapeHtml( i18n.searching || 'Searching…' );
			const res = el( 'wpistic-ffl-results' );
			if ( res ) res.innerHTML = '<div class="wpistic-ffl-loading">' + escapeHtml( i18n.searching || 'Searching…' ) + '</div>';
		} else {
			btn.disabled = false;
			if ( btn.dataset.orig ) btn.innerHTML = btn.dataset.orig;
		}
	}

	function clearResults() {
		currentResults = [];
		const bar = qs( '.wpistic-ffl-resultsbar' ); if ( bar ) bar.style.display = 'none';
		const vb  = qs( '.wpistic-ffl-viewtoggle' ); if ( vb )  vb.style.display  = 'none';
	}

	function showError( msg ) {
		const res = el( 'wpistic-ffl-results' );
		if ( res ) res.innerHTML = '<div class="wpistic-ffl-msg-error">' + escapeHtml( msg || 'Error' ) + '</div>';
	}

	function escapeHtml( s ) {
		return String( s || '' ).replace( /[&<>"']/g, function ( c ) {
			return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[ c ];
		} );
	}

	function watchBillingZip() {
		const billing = document.getElementById( 'billing_postcode' );
		if ( ! billing ) return;
		let t;
		billing.addEventListener( 'change', function () {
			const v = ( billing.value || '' ).replace( /\D/g, '' );
			if ( v.length === 5 ) {
				const zip = el( 'wpistic-ffl-zip' );
				if ( zip && ! zip.value ) {
					zip.value = v;
					clearTimeout( t );
					t = setTimeout( handleSearch, 400 );
				}
			}
		} );
	}

} )();
