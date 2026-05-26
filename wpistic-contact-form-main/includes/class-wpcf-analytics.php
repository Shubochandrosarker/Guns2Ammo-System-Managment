<?php
/**
 * Analytics — KPI tiles, 30-day daily bar chart and top-5 forms breakdown.
 * Renders the dashboard page registered as a submenu of WPistic Contact.
 *
 * Charts are inline SVG so we don't ship a charting library or external
 * dependency.
 *
 * @package WPISTIC_CF
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Analytics page renderer.
 */
class WPISTIC_CF_Analytics {

	/** Capability required. */
	const CAP = 'manage_options';

	/**
	 * Render the analytics page.
	 *
	 * @param callable $header_renderer Brand header renderer from WPISTIC_CF_Admin.
	 */
	public function render( $header_renderer ) {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$daily      = WPISTIC_CF_Database::submissions_by_day( 30 );
		$total_30d  = array_sum( $daily );
		$today      = WPISTIC_CF_Database::today_count();
		$top_forms  = WPISTIC_CF_Database::top_forms( 5 );
		$avg_secs   = WPISTIC_CF_Database::avg_reply_time_seconds();
		$p50_secs   = WPISTIC_CF_Database::p50_reply_time_seconds();
		$overdue    = WPISTIC_CF_Database::overdue_submissions_count( 24 );
		$rep_rate   = WPISTIC_CF_Database::replied_rate();
		$counts     = WPISTIC_CF_Database::status_counts();
		$imp_today  = WPISTIC_CF_Database::impressions_today_count();
		$conv_rows  = WPISTIC_CF_Database::conversion_by_form( 30 );
		?>
		<div class="wrap WPISTIC_CF-wrap">
			<?php call_user_func( $header_renderer, __( 'Volume, response time and where your submissions are coming from.', 'wpistic-contact-form' ) ); ?>

			<div class="WPISTIC_CF-kpis">
				<?php
				$kpis = [
					[
						'label' => __( 'Last 30 days', 'wpistic-contact-form' ),
						'value' => number_format_i18n( $total_30d ),
						'sub'   => __( 'submissions', 'wpistic-contact-form' ),
					],
					[
						'label' => __( 'Today', 'wpistic-contact-form' ),
						'value' => number_format_i18n( $today ),
						'sub'   => __( 'submissions', 'wpistic-contact-form' ),
					],
					[
						'label' => __( 'Replied rate', 'wpistic-contact-form' ),
						'value' => $rep_rate . '%',
						'sub'   => sprintf( __( '%s replied / %s total', 'wpistic-contact-form' ), number_format_i18n( $counts['replied'] ), number_format_i18n( $counts['total'] ) ),
					],
					[
						'label' => __( 'Avg reply time', 'wpistic-contact-form' ),
						'value' => $avg_secs ? self::format_duration( $avg_secs ) : '—',
						'sub'   => __( 'across replied submissions', 'wpistic-contact-form' ),
					],
					[
						'label' => __( 'P50 reply time', 'wpistic-contact-form' ),
						'value' => $p50_secs ? self::format_duration( $p50_secs ) : '—',
						'sub'   => __( 'median team response', 'wpistic-contact-form' ),
					],
					[
						'label' => __( 'SLA overdue (24h)', 'wpistic-contact-form' ),
						'value' => number_format_i18n( $overdue ),
						'sub'   => __( 'open items not replied', 'wpistic-contact-form' ),
					],
					[
						'label' => __( 'Form impressions today', 'wpistic-contact-form' ),
						'value' => number_format_i18n( $imp_today ),
						'sub'   => __( 'frontend form renders', 'wpistic-contact-form' ),
					],
				];
				foreach ( $kpis as $k ) :
					?>
					<div class="WPISTIC_CF-kpi">
						<span class="WPISTIC_CF-kpi__label"><?php echo esc_html( $k['label'] ); ?></span>
						<span class="WPISTIC_CF-kpi__value"><?php echo esc_html( $k['value'] ); ?></span>
						<span class="WPISTIC_CF-kpi__sub"><?php echo esc_html( $k['sub'] ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="WPISTIC_CF-panel WPISTIC_CF-panel--pad">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Submissions — last 30 days', 'wpistic-contact-form' ); ?></h2>
				<?php echo self::render_daily_chart( $daily ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inline SVG built from int values ?>
			</div>

			<div class="WPISTIC_CF-panel WPISTIC_CF-panel--pad">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Top forms', 'wpistic-contact-form' ); ?></h2>
				<?php if ( ! $top_forms ) : ?>
					<p><em><?php esc_html_e( 'No submissions yet.', 'wpistic-contact-form' ); ?></em></p>
				<?php else : ?>
					<?php echo self::render_top_forms( $top_forms ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside ?>
				<?php endif; ?>
			</div>

			<div class="WPISTIC_CF-panel WPISTIC_CF-panel--pad">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Cross-form conversion (30 days)', 'wpistic-contact-form' ); ?></h2>
				<?php if ( ! $conv_rows ) : ?>
					<p><em><?php esc_html_e( 'No conversion data yet.', 'wpistic-contact-form' ); ?></em></p>
				<?php else : ?>
					<table class="WPISTIC_CF-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Form', 'wpistic-contact-form' ); ?></th>
								<th><?php esc_html_e( 'Impressions', 'wpistic-contact-form' ); ?></th>
								<th><?php esc_html_e( 'Submissions', 'wpistic-contact-form' ); ?></th>
								<th><?php esc_html_e( 'Conversion', 'wpistic-contact-form' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $conv_rows as $r ) : ?>
								<tr>
									<td><?php echo esc_html( $r['form_name'] ?: __( '(unnamed form)', 'wpistic-contact-form' ) ); ?></td>
									<td><?php echo esc_html( number_format_i18n( (int) $r['impressions'] ) ); ?></td>
									<td><?php echo esc_html( number_format_i18n( (int) $r['submissions'] ) ); ?></td>
									<td><?php echo esc_html( (float) $r['conversion'] . '%' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------
	 * Chart renderers
	 * ------------------------------------------------------------------ */

	/**
	 * Inline SVG bar chart for the 30-day daily series.
	 *
	 * @param array<string,int> $daily Date => count.
	 * @return string
	 */
	public static function render_daily_chart( array $daily ) {
		if ( ! $daily ) {
			return '<p><em>' . esc_html__( 'No data yet.', 'wpistic-contact-form' ) . '</em></p>';
		}
		$values = array_values( $daily );
		$labels = array_keys( $daily );
		$max    = max( max( $values ), 1 );

		$width   = 880;
		$height  = 220;
		$pad_l   = 36;
		$pad_b   = 28;
		$pad_t   = 12;
		$pad_r   = 12;
		$plot_w  = $width - $pad_l - $pad_r;
		$plot_h  = $height - $pad_t - $pad_b;
		$count   = count( $values );
		$bar_w   = $plot_w / $count - 3;

		$svg  = '<svg class="WPISTIC_CF-chart" viewBox="0 0 ' . $width . ' ' . $height . '" preserveAspectRatio="none" role="img" aria-label="' . esc_attr__( '30-day submission volume', 'wpistic-contact-form' ) . '">';
		// Y axis baseline.
		$svg .= '<line x1="' . $pad_l . '" y1="' . ( $pad_t + $plot_h ) . '" x2="' . ( $width - $pad_r ) . '" y2="' . ( $pad_t + $plot_h ) . '" stroke="#e4e5ee"/>';
		// Y-axis max label.
		$svg .= '<text x="6" y="' . ( $pad_t + 8 ) . '" font-size="10" fill="#6b7088">' . (int) $max . '</text>';
		$svg .= '<text x="6" y="' . ( $pad_t + $plot_h ) . '" font-size="10" fill="#6b7088">0</text>';

		foreach ( $values as $i => $v ) {
			$h = ( $v / $max ) * $plot_h;
			$x = $pad_l + ( $i * ( $plot_w / $count ) ) + 1;
			$y = $pad_t + ( $plot_h - $h );
			$svg .= '<rect x="' . $x . '" y="' . $y . '" width="' . max( 1, $bar_w ) . '" height="' . max( 0, $h ) . '" fill="#5B4FD6" rx="2">';
			$svg .= '<title>' . esc_attr( $labels[ $i ] . ' — ' . (int) $v ) . '</title>';
			$svg .= '</rect>';
		}

		// X-axis labels — first, middle, last.
		$ticks = [ 0, (int) floor( $count / 2 ), $count - 1 ];
		foreach ( $ticks as $t ) {
			$x = $pad_l + ( $t * ( $plot_w / $count ) ) + ( $bar_w / 2 );
			$svg .= '<text x="' . $x . '" y="' . ( $height - 8 ) . '" font-size="10" fill="#6b7088" text-anchor="middle">' . esc_html( substr( $labels[ $t ], 5 ) ) . '</text>';
		}
		$svg .= '</svg>';
		return $svg;
	}

	/**
	 * Horizontal bar list of top forms by volume.
	 *
	 * @param array $rows Array of [ form_name, n ].
	 * @return string
	 */
	public static function render_top_forms( array $rows ) {
		$max  = 1;
		foreach ( $rows as $r ) {
			$max = max( $max, (int) $r['n'] );
		}
		$out = '<ul class="WPISTIC_CF-topforms">';
		foreach ( $rows as $r ) {
			$pct = max( 1, (int) round( ( $r['n'] / $max ) * 100 ) );
			$out .= '<li class="WPISTIC_CF-topforms__row">';
			$out .= '<span class="WPISTIC_CF-topforms__name">' . esc_html( $r['form_name'] ?: __( '(unnamed form)', 'wpistic-contact-form' ) ) . '</span>';
			$out .= '<span class="WPISTIC_CF-topforms__bar"><span class="WPISTIC_CF-topforms__fill" style="width:' . $pct . '%"></span></span>';
			$out .= '<span class="WPISTIC_CF-topforms__num">' . number_format_i18n( (int) $r['n'] ) . '</span>';
			$out .= '</li>';
		}
		$out .= '</ul>';
		return $out;
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Format a duration in seconds as e.g. "2h 15m" or "45s".
	 *
	 * @param int $seconds Duration in seconds.
	 * @return string
	 */
	public static function format_duration( $seconds ) {
		$seconds = max( 0, (int) $seconds );
		if ( $seconds < 60 ) {
			return sprintf( _n( '%d second', '%d seconds', $seconds, 'wpistic-contact-form' ), $seconds );
		}
		if ( $seconds < HOUR_IN_SECONDS ) {
			$m = (int) round( $seconds / 60 );
			return sprintf( _n( '%d minute', '%d minutes', $m, 'wpistic-contact-form' ), $m );
		}
		if ( $seconds < DAY_IN_SECONDS ) {
			$h = (int) floor( $seconds / HOUR_IN_SECONDS );
			$m = (int) round( ( $seconds % HOUR_IN_SECONDS ) / 60 );
			return $m ? sprintf( __( '%1$dh %2$dm', 'wpistic-contact-form' ), $h, $m ) : sprintf( _n( '%d hour', '%d hours', $h, 'wpistic-contact-form' ), $h );
		}
		$d = (int) floor( $seconds / DAY_IN_SECONDS );
		$h = (int) round( ( $seconds % DAY_IN_SECONDS ) / HOUR_IN_SECONDS );
		return $h ? sprintf( __( '%1$dd %2$dh', 'wpistic-contact-form' ), $d, $h ) : sprintf( _n( '%d day', '%d days', $d, 'wpistic-contact-form' ), $d );
	}
}
