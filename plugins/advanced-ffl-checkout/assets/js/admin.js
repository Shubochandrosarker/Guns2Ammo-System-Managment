/**
 * Advanced FFL Checkout — Admin JS v1.0.2
 *
 * Handles:
 *  1. ATF CSV upload — reads file in browser, sends 200 rows/chunk to PHP via AJAX
 *  2. Live progress bar during import (no page reload needed)
 *  3. Settings save
 *
 * No WP Cron dependency. No external downloads. Works on any hosting.
 *
 * @package WpisticFFL
 */
( function () {
	'use strict';

	const cfg   = window.wpistic_ffl_admin || {};
	const i18n  = cfg.i18n || {};
	const CHUNK = 200; // rows per AJAX request

	document.addEventListener( 'DOMContentLoaded', function () {

		// ── CSV Upload ────────────────────────────────────────────────────────
		const fileInput   = document.getElementById( 'wpistic-ffl-csv-input' );
		const uploadBtn   = document.getElementById( 'wpistic-ffl-upload-btn' );
		const uploadZone  = document.getElementById( 'wpistic-ffl-upload-zone' );

		if ( fileInput ) {
			fileInput.addEventListener( 'change', function () {
				if ( this.files && this.files[0] ) {
					startCsvImport( this.files[0] );
				}
			} );
		}

		if ( uploadBtn ) {
			uploadBtn.addEventListener( 'click', function () {
				if ( fileInput ) fileInput.click();
			} );
		}

		// Drag-and-drop
		if ( uploadZone ) {
			uploadZone.addEventListener( 'dragover', function ( e ) {
				e.preventDefault();
				this.classList.add( 'wpistic-ffl-drag-over' );
			} );
			uploadZone.addEventListener( 'dragleave', function () {
				this.classList.remove( 'wpistic-ffl-drag-over' );
			} );
			uploadZone.addEventListener( 'drop', function ( e ) {
				e.preventDefault();
				this.classList.remove( 'wpistic-ffl-drag-over' );
				const file = e.dataTransfer.files[0];
				if ( file && file.name.match( /\.csv$/i ) ) {
					startCsvImport( file );
				} else {
					showNotice( 'Please drop a .csv file.', 'error' );
				}
			} );
		}

		// ── Settings save ─────────────────────────────────────────────────────
		const saveBtn = document.getElementById( 'wpistic-ffl-save-settings' );
		if ( saveBtn ) {
			saveBtn.addEventListener( 'click', saveSettings );
		}

		// ── Refresh dealer count on page load ─────────────────────────────────
		refreshDealerCount();
	} );

	// ── CSV Import Engine ─────────────────────────────────────────────────────

	function startCsvImport( file ) {
		showProgress( 0, 'Reading file…' );

		const reader = new FileReader();
		reader.onload = function ( e ) {
			const text = e.target.result;
			let rows   = text.split( /\r?\n/ );

			// Strip header row
			if ( rows.length && rows[0].trim().toUpperCase().startsWith( 'LIC_REGN' ) ) {
				rows = rows.slice( 1 );
			}

			// Remove blank lines
			rows = rows.filter( function ( r ) { return r.trim() !== ''; } );

			if ( rows.length === 0 ) {
				showProgress( 0, '⚠️ No data rows found in file.' );
				return;
			}

			const total = rows.length;
			showProgress( 0, 'Clearing old data…' );

			// Reset dealer table first, then send chunks
			post( 'wpistic_ffl_import_csv_reset', {} )
				.then( function () {
					showProgress( 0, '0 / ' + fmt( total ) + ' rows imported…' );
					sendChunk( rows, 0, total, 0 );
				} )
				.catch( handleError );
		};

		reader.onerror = function () {
			showNotice( 'Failed to read the CSV file.', 'error' );
		};

		reader.readAsText( file );
	}

	function sendChunk( allRows, offset, total, cumulativeImported ) {
		if ( offset >= allRows.length ) {
			// All chunks done
			showProgress( 100, '✅ Import complete! ' + fmt( cumulativeImported ) + ' dealers imported.' );
			setProgressColor( 'green' );
			refreshDealerCount();

			const notice = document.getElementById( 'wpistic-ffl-import-done' );
			if ( notice ) notice.style.display = 'block';
			return;
		}

		const chunk = allRows.slice( offset, offset + CHUNK );

		const fd = new FormData();
		fd.append( 'action', 'wpistic_ffl_import_csv_chunk' );
		fd.append( 'nonce',  cfg.nonce || '' );
		fd.append( 'total',  total );
		fd.append( 'imported', cumulativeImported );
		chunk.forEach( function ( row ) {
			fd.append( 'rows[]', row );
		} );

		fetch( cfg.ajax_url || '/wp-admin/admin-ajax.php', {
			method: 'POST',
			body:   fd,
		} )
			.then( function ( r ) {
				if ( ! r.ok ) throw new Error( 'HTTP ' + r.status );
				return r.json();
			} )
			.then( function ( data ) {
				if ( ! data.success ) {
					showNotice( data.data || 'Import error.', 'error' );
					return;
				}

				const d           = data.data;
				const newImported = cumulativeImported + ( d.inserted || 0 );
				const pct         = total > 0 ? Math.round( ( ( offset + chunk.length ) / total ) * 100 ) : 0;

				showProgress(
					Math.min( pct, 99 ),
					fmt( newImported ) + ' / ' + fmt( total ) + ' dealers imported (' + Math.min( pct, 99 ) + '%)'
				);

				// Send next chunk
				setTimeout( function () {
					sendChunk( allRows, offset + CHUNK, total, newImported );
				}, 50 );
			} )
			.catch( handleError );
	}

	// ── Settings ──────────────────────────────────────────────────────────────

	function saveSettings() {
		const fields = [
			'default_radius', 'notification_email', 'from_name',
			'customer_emails', 'admin_emails', 'disable_emails',
			'delete_data_on_uninstall', 'google_maps_key',
		];

		const payload = {};
		fields.forEach( function ( field ) {
			const el = document.querySelector( '[name="' + field + '"]' );
			if ( ! el ) return;
			payload[ field ] = el.type === 'checkbox' ? ( el.checked ? 1 : 0 ) : el.value;
		} );

		const btn = document.getElementById( 'wpistic-ffl-save-settings' );
		if ( btn ) {
			btn.disabled     = true;
			btn.textContent  = 'Saving…';
		}

		post( 'wpistic_ffl_save_settings', payload )
			.then( function ( data ) {
				if ( data.success ) {
					const notice = document.getElementById( 'wpistic-ffl-settings-saved' );
					if ( notice ) {
						notice.style.display = 'block';
						setTimeout( function () { notice.style.display = 'none'; }, 3000 );
					}
				} else {
					showNotice( data.data || 'Error saving settings.', 'error' );
				}
			} )
			.catch( handleError )
			.finally( function () {
				if ( btn ) {
					btn.disabled    = false;
					btn.textContent = 'Save Settings';
				}
			} );
	}

	// ── Dealer count refresh ──────────────────────────────────────────────────

	function refreshDealerCount() {
		post( 'wpistic_ffl_get_dealer_count', {} )
			.then( function ( data ) {
				if ( ! data.success ) return;
				const d           = data.data;
				const dealerEl    = document.getElementById( 'wpistic-ffl-dealer-count' );
				const zipEl       = document.getElementById( 'wpistic-ffl-zip-count' );
				if ( dealerEl ) dealerEl.textContent = fmt( d.dealers || 0 );
				if ( zipEl )    zipEl.textContent    = fmt( d.zips    || 0 );

				// Update KPI card if present
				const kpiDealer = document.querySelector( '.wpistic-kpi-dealer-count' );
				if ( kpiDealer ) kpiDealer.textContent = fmt( d.dealers || 0 );
			} )
			.catch( function () {} ); // silent
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	function showProgress( pct, msg ) {
		const bar    = document.querySelector( '.wpistic-ffl-csv-progress-bar' );
		const label  = document.querySelector( '.wpistic-ffl-csv-progress-label' );
		const wrap   = document.querySelector( '.wpistic-ffl-csv-progress' );

		if ( wrap )  wrap.style.display  = 'block';
		if ( bar )   bar.style.width     = pct + '%';
		if ( label ) label.textContent   = msg;
	}

	function setProgressColor( color ) {
		const bar = document.querySelector( '.wpistic-ffl-csv-progress-bar' );
		if ( bar ) {
			bar.style.background = color === 'green' ? '#16A34A' : '#DCB45F';
		}
	}

	function post( action, data ) {
		const fd = new FormData();
		fd.append( 'action', action );
		fd.append( 'nonce',  cfg.nonce || '' );
		Object.entries( data ).forEach( ( [ k, v ] ) => fd.append( k, v ) );

		return fetch( cfg.ajax_url || '/wp-admin/admin-ajax.php', {
			method: 'POST',
			body:   fd,
		} ).then( function ( r ) {
			if ( ! r.ok ) throw new Error( 'HTTP ' + r.status );
			return r.json();
		} );
	}

	function showNotice( msg, type ) {
		const wrap = document.querySelector( '.wrap.wpistic-ffl-admin' );
		if ( ! wrap ) return;

		const old = wrap.querySelector( '.wpistic-ffl-inline-notice' );
		if ( old ) old.remove();

		const div     = document.createElement( 'div' );
		div.className = 'notice notice-' + ( type === 'error' ? 'error' : 'success' ) + ' is-dismissible wpistic-ffl-inline-notice';
		div.innerHTML = '<p>' + escHtml( msg ) + '</p>';
		wrap.insertBefore( div, wrap.childNodes[1] || wrap.firstChild );
		setTimeout( function () { div.remove(); }, 8000 );
	}

	function handleError( err ) {
		console.error( 'FFL Admin error:', err );
		showNotice( 'An error occurred: ' + err.message, 'error' );
		setProgressColor( 'red' );
	}

	function fmt( n ) { return ( n || 0 ).toLocaleString(); }

	function escHtml( str ) {
		const d = document.createElement( 'div' );
		d.appendChild( document.createTextNode( String( str || '' ) ) );
		return d.innerHTML;
	}

} )();
