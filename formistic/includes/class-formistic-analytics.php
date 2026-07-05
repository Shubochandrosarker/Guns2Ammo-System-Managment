<?php
/**
 * Analytics — KPI tiles, 30-day daily bar chart and top-5 forms breakdown.
 * Renders the dashboard page registered as a submenu of Formistic.
 *
 * Charts are inline SVG so we don't ship a charting library or external
 * dependency.
 *
 * @package Wpistic_Formistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Analytics page renderer.
 */
class Wpistic_Formistic_Analytics {

	/** Capability required. */
	const CAP = 'manage_options';

	/**
	 * Render the analytics page.
	 *
	 * @param callable $header_renderer Brand header renderer from Wpistic_Formistic_Admin.
	 */
	public function render( $header_renderer ) {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$daily      = Wpistic_Formistic_Database::submissions_by_day( 30 );
		$total_30d  = array_sum( $daily );
		$today      = Wpistic_Formistic_Database::today_count();
		$top_forms  = Wpistic_Formistic_Database::top_forms( 5 );
		$avg_secs   = Wpistic_Formistic_Database::avg_reply_time_seconds();
		$p50_secs   = Wpistic_Formistic_Database::p50_reply_time_seconds();
		$overdue    = Wpistic_Formistic_Database::overdue_submissions_count( 24 );
		$rep_rate   = Wpistic_Formistic_Database::replied_rate();
		$counts     = Wpistic_Formistic_Database::status_counts();
		$imp_today  = Wpistic_Formistic_Database::impressions_today_count();
		$conv_rows  = Wpistic_Formistic_Database::conversion_by_form( 30 );
		?>
		<div class="wrap wpistic-formistic-wrap">
			<?php call_user_func( $header_renderer, __( 'Volume, response time and where your submissions are coming from.', 'formistic' ) ); ?>

			<div class="wpistic-formistic-kpis">
				<?php
				$kpis = [
					[
						'label' => __( 'Last 30 days', 'formistic' ),
						'value' => number_format_i18n( $total_30d ),
						'sub'   => __( 'submissions', 'formistic' ),
					],
					[
						'label' => __( 'Today', 'formistic' ),
						'value' => number_format_i18n( $today ),
						'sub'   => __( 'submissions', 'formistic' ),
					],
					[
						'label' => __( 'Replied rate', 'formistic' ),
						'value' => $rep_rate . '%',
						'sub'   => sprintf( __( '%s replied / %s total', 'formistic' ), number_format_i18n( $counts['replied'] ), number_format_i18n( $counts['total'] ) ),
					],
					[
						'label' => __( 'Avg reply time', 'formistic' ),
						'value' => $avg_secs ? self::format_duration( $avg_secs ) : '—',
						'sub'   => __( 'across replied submissions', 'formistic' ),
					],
					[
						'label' => __( 'P50 reply time', 'formistic' ),
						'value' => $p50_secs ? self::format_duration( $p50_secs ) : '—',
						'sub'   => __( 'median team response', 'formistic' ),
					],
					[
						'label' => __( 'SLA overdue (24h)', 'formistic' ),
						'value' => number_format_i18n( $overdue ),
						'sub'   => __( 'open items not replied', 'formistic' ),
					],
					[
						'label' => __( 'Form impressions today', 'formistic' ),
						'value' => number_format_i18n( $imp_today ),
						'sub'   => __( 'frontend form renders', 'formistic' ),
					],
				];
				foreach ( $kpis as $k ) :
					?>
					<div class="wpistic-formistic-kpi">
						<span class="wpistic-formistic-kpi__label"><?php echo esc_html( $k['label'] ); ?></span>
						<span class="wpistic-formistic-kpi__value"><?php echo esc_html( $k['value'] ); ?></span>
						<span class="wpistic-formistic-kpi__sub"><?php echo esc_html( $k['sub'] ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="wpistic-formistic-panel wpistic-formistic-panel--pad">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Submissions — last 30 days', 'formistic' ); ?></h2>
				<?php echo self::render_daily_chart( $daily ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inline SVG built from int values ?>
			</div>

			<div class="wpistic-formistic-panel wpistic-formistic-panel--pad">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Top forms', 'formistic' ); ?></h2>
				<?php if ( ! $top_forms ) : ?>
					<p><em><?php esc_html_e( 'No submissions yet.', 'formistic' ); ?></em></p>
				<?php else : ?>
					<?php echo self::render_top_forms( $top_forms ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside ?>
				<?php endif; ?>
			</div>

			<div class="wpistic-formistic-panel wpistic-formistic-panel--pad">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Cross-form conversion (30 days)', 'formistic' ); ?></h2>
				<?php if ( ! $conv_rows ) : ?>
					<p><em><?php esc_html_e( 'No conversion data yet.', 'formistic' ); ?></em></p>
				<?php else : ?>
					<table class="wpistic-formistic-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Form', 'formistic' ); ?></th>
								<th><?php esc_html_e( 'Impressions', 'formistic' ); ?></th>
								<th><?php esc_html_e( 'Submissions', 'formistic' ); ?></th>
								<th><?php esc_html_e( 'Conversion', 'formistic' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $conv_rows as $r ) : ?>
								<tr>
									<td><?php echo esc_html( $r['form_name'] ?: __( '(unnamed form)', 'formistic' ) ); ?></td>
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
			return '<p><em>' . esc_html__( 'No data yet.', 'formistic' ) . '</em></p>';
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

		$svg  = '<svg class="wpistic-formistic-chart" viewBox="0 0 ' . $width . ' ' . $height . '" preserveAspectRatio="none" role="img" aria-label="' . esc_attr__( '30-day submission volume', 'formistic' ) . '">';
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
		$out = '<ul class="wpistic-formistic-topforms">';
		foreach ( $rows as $r ) {
			$pct = max( 1, (int) round( ( $r['n'] / $max ) * 100 ) );
			$out .= '<li class="wpistic-formistic-topforms__row">';
			$out .= '<span class="wpistic-formistic-topforms__name">' . esc_html( $r['form_name'] ?: __( '(unnamed form)', 'formistic' ) ) . '</span>';
			$out .= '<span class="wpistic-formistic-topforms__bar"><span class="wpistic-formistic-topforms__fill" style="width:' . $pct . '%"></span></span>';
			$out .= '<span class="wpistic-formistic-topforms__num">' . number_format_i18n( (int) $r['n'] ) . '</span>';
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
			return sprintf( _n( '%d second', '%d seconds', $seconds, 'formistic' ), $seconds );
		}
		if ( $seconds < HOUR_IN_SECONDS ) {
			$m = (int) round( $seconds / 60 );
			return sprintf( _n( '%d minute', '%d minutes', $m, 'formistic' ), $m );
		}
		if ( $seconds < DAY_IN_SECONDS ) {
			$h = (int) floor( $seconds / HOUR_IN_SECONDS );
			$m = (int) round( ( $seconds % HOUR_IN_SECONDS ) / 60 );
			return $m ? sprintf( __( '%1$dh %2$dm', 'formistic' ), $h, $m ) : sprintf( _n( '%d hour', '%d hours', $h, 'formistic' ), $h );
		}
		$d = (int) floor( $seconds / DAY_IN_SECONDS );
		$h = (int) round( ( $seconds % DAY_IN_SECONDS ) / HOUR_IN_SECONDS );
		return $h ? sprintf( __( '%1$dd %2$dh', 'formistic' ), $d, $h ) : sprintf( _n( '%d day', '%d days', $d, 'formistic' ), $d );
	}
}
