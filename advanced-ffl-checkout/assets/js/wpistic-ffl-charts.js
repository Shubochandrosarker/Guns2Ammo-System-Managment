/**
 * Advanced FFL Checkout — vendored, dependency-free canvas charts.
 *
 * There is no charting library anywhere in this plugin's admin — every
 * "dashboard" was HTML KPI tiles and tables. Rather than pull in Chart.js
 * (or any package) from a CDN — unreliable offline, and this plugin never
 * loads third-party JS from anywhere but its own assets/ — this is a small,
 * self-contained renderer for exactly the two chart shapes the admin pages
 * need: a time-series line and a categorical bar chart. Not a general
 * charting library; add a shape here only when an admin page needs it.
 *
 * @package WpisticFFL
 */
( function ( global ) {
	'use strict';

	var COLORS = [ '#3b82f6', '#16a34a', '#f59e0b', '#8b5cf6', '#ec4899', '#0ea5e9' ];

	function devicePixelScale( canvas, cssWidth, cssHeight ) {
		var ratio = global.devicePixelRatio || 1;
		canvas.width  = cssWidth * ratio;
		canvas.height = cssHeight * ratio;
		canvas.style.width  = cssWidth + 'px';
		canvas.style.height = cssHeight + 'px';
		var ctx = canvas.getContext( '2d' );
		ctx.scale( ratio, ratio );
		return ctx;
	}

	function niceMax( value ) {
		if ( value <= 0 ) {
			return 4;
		}
		var magnitude = Math.pow( 10, Math.floor( Math.log10( value ) ) );
		var steps     = [ 1, 2, 2.5, 5, 10 ];
		for ( var i = 0; i < steps.length; i++ ) {
			var candidate = steps[ i ] * magnitude;
			if ( candidate >= value ) {
				return candidate;
			}
		}
		return value;
	}

	function drawAxesAndGrid( ctx, x0, y0, width, height, max, gridLines ) {
		ctx.strokeStyle = '#e5e7eb';
		ctx.fillStyle   = '#6b7280';
		ctx.font        = '11px system-ui, sans-serif';
		ctx.lineWidth   = 1;

		for ( var i = 0; i <= gridLines; i++ ) {
			var y     = y0 - ( height * i / gridLines );
			var value = Math.round( max * i / gridLines );
			ctx.beginPath();
			ctx.moveTo( x0, y );
			ctx.lineTo( x0 + width, y );
			ctx.stroke();
			ctx.textAlign    = 'right';
			ctx.textBaseline = 'middle';
			ctx.fillText( String( value ), x0 - 8, y );
		}
	}

	/**
	 * One or more series over the same set of date labels, drawn as lines.
	 *
	 * @param {HTMLCanvasElement} canvas
	 * @param {{labels: string[], series: {label:string, data:number[], color?:string}[]}} data
	 */
	function renderTimeSeries( canvas, data ) {
		var labels = data.labels || [];
		var series = data.series || [];
		var cssWidth  = canvas.parentElement ? canvas.parentElement.clientWidth : 600;
		var cssHeight = canvas.dataset.height ? parseInt( canvas.dataset.height, 10 ) : 220;
		var ctx = devicePixelScale( canvas, cssWidth, cssHeight );

		var padLeft = 36, padRight = 16, padTop = 16, padBottom = 28;
		var plotW = cssWidth - padLeft - padRight;
		var plotH = cssHeight - padTop - padBottom;
		var x0 = padLeft, y0 = cssHeight - padBottom;

		var max = 0;
		series.forEach( function ( s ) {
			s.data.forEach( function ( v ) { if ( v > max ) { max = v; } } );
		} );
		max = niceMax( max );

		ctx.clearRect( 0, 0, cssWidth, cssHeight );
		drawAxesAndGrid( ctx, x0, y0, plotW, plotH, max, 4 );

		var n = labels.length;
		var stepX = n > 1 ? plotW / ( n - 1 ) : 0;

		series.forEach( function ( s, si ) {
			var color = s.color || COLORS[ si % COLORS.length ];
			ctx.strokeStyle = color;
			ctx.fillStyle   = color;
			ctx.lineWidth   = 2;
			ctx.beginPath();
			s.data.forEach( function ( v, i ) {
				var x = x0 + stepX * i;
				var y = y0 - ( plotH * ( v / max ) );
				if ( 0 === i ) {
					ctx.moveTo( x, y );
				} else {
					ctx.lineTo( x, y );
				}
			} );
			ctx.stroke();
			s.data.forEach( function ( v, i ) {
				var x = x0 + stepX * i;
				var y = y0 - ( plotH * ( v / max ) );
				ctx.beginPath();
				ctx.arc( x, y, 2.5, 0, 2 * Math.PI );
				ctx.fill();
			} );
		} );

		// X-axis labels: first, middle, last only -- avoids overlap on 30+ points.
		ctx.fillStyle    = '#6b7280';
		ctx.textAlign    = 'center';
		ctx.textBaseline = 'top';
		[ 0, Math.floor( ( n - 1 ) / 2 ), n - 1 ].forEach( function ( i ) {
			if ( i >= 0 && i < n ) {
				ctx.fillText( labels[ i ], x0 + stepX * i, y0 + 8 );
			}
		} );

		renderLegend( canvas, series );
	}

	/**
	 * Single-series categorical bars (e.g. transfer counts by status).
	 *
	 * @param {HTMLCanvasElement} canvas
	 * @param {{labels: string[], data: number[], colors?: string[]}} data
	 */
	function renderBars( canvas, data ) {
		var labels = data.labels || [];
		var values = data.data || [];
		var cssWidth  = canvas.parentElement ? canvas.parentElement.clientWidth : 600;
		var cssHeight = canvas.dataset.height ? parseInt( canvas.dataset.height, 10 ) : 220;
		var ctx = devicePixelScale( canvas, cssWidth, cssHeight );

		var padLeft = 36, padRight = 16, padTop = 16, padBottom = 46;
		var plotW = cssWidth - padLeft - padRight;
		var plotH = cssHeight - padTop - padBottom;
		var x0 = padLeft, y0 = cssHeight - padBottom;

		var max = niceMax( Math.max.apply( null, values.concat( [ 0 ] ) ) );

		ctx.clearRect( 0, 0, cssWidth, cssHeight );
		drawAxesAndGrid( ctx, x0, y0, plotW, plotH, max, 4 );

		var n = Math.max( 1, labels.length );
		var slot = plotW / n;
		var barW = Math.min( 40, slot * 0.6 );

		ctx.textAlign    = 'center';
		ctx.textBaseline = 'top';
		labels.forEach( function ( label, i ) {
			var v = values[ i ] || 0;
			var barH = plotH * ( v / max );
			var x = x0 + slot * i + ( slot - barW ) / 2;
			var y = y0 - barH;

			ctx.fillStyle = ( data.colors && data.colors[ i ] ) || COLORS[ i % COLORS.length ];
			ctx.fillRect( x, y, barW, barH );

			ctx.fillStyle = '#374151';
			ctx.fillText( String( v ), x + barW / 2, y - 14 );

			ctx.save();
			ctx.fillStyle = '#6b7280';
			ctx.font = '10px system-ui, sans-serif';
			ctx.translate( x + barW / 2, y0 + 6 );
			ctx.rotate( -0.35 );
			ctx.textAlign = 'right';
			ctx.fillText( label, 0, 0 );
			ctx.restore();
		} );
	}

	function renderLegend( canvas, series ) {
		if ( series.length < 2 ) {
			return;
		}
		var wrap = canvas.parentElement && canvas.parentElement.querySelector( '.wpistic-ffl-chart-legend' );
		if ( ! wrap ) {
			wrap = document.createElement( 'div' );
			wrap.className = 'wpistic-ffl-chart-legend';
			wrap.style.cssText = 'display:flex;gap:14px;flex-wrap:wrap;margin-top:6px;font-size:12px;color:#374151;';
			canvas.insertAdjacentElement( 'afterend', wrap );
		}
		wrap.innerHTML = '';
		series.forEach( function ( s, i ) {
			var color = s.color || COLORS[ i % COLORS.length ];
			var item = document.createElement( 'span' );
			item.style.cssText = 'display:inline-flex;align-items:center;gap:5px;';
			item.innerHTML = '<span style="width:10px;height:10px;border-radius:2px;background:' + color + ';display:inline-block;"></span>' + s.label;
			wrap.appendChild( item );
		} );
	}

	global.WpisticFFLCharts = {
		renderTimeSeries: renderTimeSeries,
		renderBars: renderBars,
	};
} )( window );
