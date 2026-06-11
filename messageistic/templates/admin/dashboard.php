<?php
/**
 * Dashboard view.
 *
 * @var array<string,mixed> $stats
 * @var array<int,array<string,string>> $recent
 * @var array<string,mixed> $health
 * @var array<int,array{day:string,outbound:int,inbound:int}> $series
 *
 * @package Messageistic
 */

defined( 'ABSPATH' ) || exit;

$status_label = [
    'simulation'       => __( 'Simulation Only', 'messageistic' ),
    'missing_settings' => __( 'Needs Setup', 'messageistic' ),
    'configured'       => __( 'Connected', 'messageistic' ),
];
$status_class = [
    'simulation'       => 'is-warning',
    'missing_settings' => 'is-danger',
    'configured'       => 'is-ok',
];
$mode_label = 'testing' === $health['key']
    ? __( 'No real SMS sent', 'messageistic' )
    : __( 'Live API', 'messageistic' );

$trend       = (float) $stats['trend_sent_pct'];
$trend_dir   = abs( $trend ) < 0.05 ? 'flat' : ( $trend > 0 ? 'up' : 'down' );
$trend_arrow = 'up' === $trend_dir ? '▲' : ( 'down' === $trend_dir ? '▼' : '—' );

// Chart geometry.
$chart_w     = 800;
$chart_h     = 220;
$pad_l       = 32;
$pad_r       = 12;
$pad_t       = 16;
$pad_b       = 28;
$plot_w      = $chart_w - $pad_l - $pad_r;
$plot_h      = $chart_h - $pad_t - $pad_b;
$max_value   = 1;
foreach ( $series as $row ) {
    $max_value = max( $max_value, (int) $row['outbound'], (int) $row['inbound'] );
}
$bar_group_w = $plot_w / max( 1, count( $series ) );
$bar_w       = max( 6, min( 26, $bar_group_w / 2.6 ) );
?>
<div class="wrap messageistic-wrap">
    <header class="messageistic-header">
        <div>
            <h1 class="messageistic-page-title"><?php esc_html_e( 'Dashboard', 'messageistic' ); ?></h1>
            <p class="messageistic-subtitle"><?php esc_html_e( 'Premium communication engine — built by WordPressistic.', 'messageistic' ); ?></p>
        </div>
        <div style="display:flex;gap:8px;">
            <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=messageistic-import' ) ); ?>"><?php esc_html_e( 'Import CSV', 'messageistic' ); ?></a>
            <a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=messageistic-settings' ) ); ?>"><?php esc_html_e( 'Settings', 'messageistic' ); ?></a>
        </div>
    </header>

    <?php if ( ! empty( $pilot_settings['pilot_mode_enabled'] ) ) : ?>
        <div class="notice notice-info inline"><p><strong><?php esc_html_e( 'Controlled Pilot Active:', 'messageistic' ); ?></strong> <?php echo esc_html( (string) $pilot_settings['business_name'] ); ?> — <?php echo esc_html( (string) $pilot_settings['pilot_location_name'] ); ?>, <?php echo esc_html( (string) $pilot_settings['pilot_location_state'] ); ?>. <?php printf( esc_html__( 'Provider: %1$s; sender: %2$s; daily cap: %3$d; transactional templates only.', 'messageistic' ), esc_html( (string) $pilot_settings['pilot_provider'] ), esc_html( (string) $pilot_settings['pilot_sender'] ), (int) $pilot_settings['pilot_daily_limit'] ); ?> <?php if ( ! empty( $pilot_snapshot['requires_review'] ) ) : ?><a href="<?php echo esc_url( admin_url( 'admin.php?page=messageistic-reports' ) ); ?>"><?php esc_html_e( 'Daily monitoring review required', 'messageistic' ); ?></a><?php endif; ?></p></div>
    <?php endif; ?>

    <section class="messageistic-provider-card messageistic-card">
        <div class="messageistic-provider-card__left">
            <span class="messageistic-eyebrow"><?php esc_html_e( 'Active SMS Provider', 'messageistic' ); ?></span>
            <h2><?php echo esc_html( $health['label'] ); ?></h2>
            <p class="messageistic-provider-card__message"><?php echo esc_html( $health['message'] ); ?></p>
        </div>
        <div class="messageistic-provider-card__right">
            <span class="messageistic-badge <?php echo esc_attr( $status_class[ $health['status'] ] ?? '' ); ?>">
                <?php echo esc_html( $status_label[ $health['status'] ] ?? $health['status'] ); ?>
            </span>
            <span class="messageistic-mode"><?php echo esc_html( $mode_label ); ?></span>
        </div>
    </section>

    <?php if ( 'testing' === $health['key'] ) : ?>
        <div class="messageistic-banner messageistic-banner--testing">
            <strong><?php esc_html_e( 'Testing Provider Active', 'messageistic' ); ?></strong>
            <span><?php esc_html_e( 'No real SMS will be sent. Switch the active provider in Settings when ready.', 'messageistic' ); ?></span>
        </div>
    <?php endif; ?>

    <section class="messageistic-grid messageistic-grid--stats">
        <?php
        $cards = [
            [ 'label' => __( 'Messages Sent', 'messageistic' ),  'value' => number_format_i18n( $stats['total_sent'] ),                  'delta' => $trend,                  'delta_dir' => $trend_dir ],
            [ 'label' => __( 'Delivered', 'messageistic' ),      'value' => number_format_i18n( $stats['delivered'] ),                   'delta' => (float) $stats['delivery_rate'], 'delta_dir' => $stats['delivery_rate'] >= 90 ? 'up' : ( $stats['delivery_rate'] >= 70 ? 'flat' : 'down' ), 'delta_suffix' => '%' ],
            [ 'label' => __( 'Failed', 'messageistic' ),         'value' => number_format_i18n( $stats['failed'] ),                      'delta' => (float) $stats['fail_rate'],     'delta_dir' => $stats['fail_rate'] === 0.0 ? 'flat' : 'down', 'delta_suffix' => '%' ],
            [ 'label' => __( 'Replies', 'messageistic' ),        'value' => number_format_i18n( $stats['replies'] ),                     'delta' => null ],
            [ 'label' => __( 'Active Contacts', 'messageistic' ),'value' => number_format_i18n( $stats['contacts'] ),                    'delta' => null ],
            [ 'label' => __( 'Opted-out', 'messageistic' ),      'value' => number_format_i18n( $stats['opted_out'] ),                   'delta' => null ],
            [ 'label' => __( 'Active Campaigns', 'messageistic' ),'value' => number_format_i18n( $stats['active_camps'] ),               'delta' => null ],
            [ 'label' => __( 'Est. SMS Cost', 'messageistic' ),  'value' => '$' . number_format_i18n( (float) $stats['cost_estimate'], 2 ), 'delta' => null ],
        ];
        foreach ( $cards as $card ) :
            $delta_dir = $card['delta_dir'] ?? null;
            ?>
            <article class="mi-stat-card">
                <span class="mi-stat-card__label"><?php echo esc_html( $card['label'] ); ?></span>
                <strong class="mi-stat-card__value"><?php echo esc_html( $card['value'] ); ?></strong>
                <?php if ( null !== $card['delta'] && null !== $delta_dir ) :
                    $arrow = 'up' === $delta_dir ? '▲' : ( 'down' === $delta_dir ? '▼' : '—' );
                    ?>
                    <span class="mi-stat-card__delta mi-stat-card__delta--<?php echo esc_attr( $delta_dir ); ?>">
                        <?php echo esc_html( $arrow ); ?>
                        <?php echo esc_html( number_format_i18n( abs( (float) $card['delta'] ), 1 ) . ( $card['delta_suffix'] ?? '%' ) ); ?>
                    </span>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>

    <section class="messageistic-grid messageistic-grid--split">
        <article class="messageistic-card">
            <div class="mi-card__head">
                <div>
                    <span class="mi-eyebrow"><?php esc_html_e( 'Last 7 days', 'messageistic' ); ?></span>
                    <h3 class="mi-card__title"><?php esc_html_e( 'Messages by day', 'messageistic' ); ?></h3>
                </div>
            </div>
            <?php if ( 0 === $max_value || empty( $series ) ) : ?>
                <p class="messageistic-empty"><?php esc_html_e( 'No message activity yet. Send your first SMS to see the chart fill in here.', 'messageistic' ); ?></p>
            <?php else : ?>
                <svg class="mi-chart" viewBox="0 0 <?php echo (int) $chart_w; ?> <?php echo (int) $chart_h; ?>" preserveAspectRatio="xMidYMid meet" role="img" aria-label="Messages by day">
                    <?php for ( $g = 0; $g <= 4; $g++ ) :
                        $y = $pad_t + ( $plot_h / 4 ) * $g;
                        ?>
                        <line x1="<?php echo (int) $pad_l; ?>" y1="<?php echo (int) $y; ?>" x2="<?php echo (int) ( $chart_w - $pad_r ); ?>" y2="<?php echo (int) $y; ?>" stroke="#E5E7EB" stroke-width="1"></line>
                    <?php endfor; ?>

                    <?php foreach ( $series as $i => $row ) :
                        $group_x = $pad_l + ( $bar_group_w * $i );
                        $cx      = $group_x + ( $bar_group_w / 2 );
                        $out_h   = ( $row['outbound'] / $max_value ) * $plot_h;
                        $in_h    = ( $row['inbound']  / $max_value ) * $plot_h;
                        $out_x   = $cx - $bar_w - 2;
                        $in_x    = $cx + 2;
                        $out_y   = $pad_t + ( $plot_h - $out_h );
                        $in_y    = $pad_t + ( $plot_h - $in_h );
                        $label   = gmdate( 'M j', strtotime( $row['day'] ) );
                        ?>
                        <rect x="<?php echo (float) $out_x; ?>" y="<?php echo (float) $out_y; ?>" width="<?php echo (float) $bar_w; ?>" height="<?php echo (float) max( 0, $out_h ); ?>" rx="3" fill="#2563EB"></rect>
                        <rect x="<?php echo (float) $in_x; ?>"  y="<?php echo (float) $in_y; ?>"  width="<?php echo (float) $bar_w; ?>" height="<?php echo (float) max( 0, $in_h ); ?>"  rx="3" fill="#10B981"></rect>
                        <text x="<?php echo (float) $cx; ?>" y="<?php echo (int) ( $chart_h - 8 ); ?>" font-size="10" fill="#6B7280" text-anchor="middle"><?php echo esc_html( $label ); ?></text>
                    <?php endforeach; ?>
                </svg>
                <div class="mi-chart-legend">
                    <span><i style="background:#2563EB"></i> <?php esc_html_e( 'Outbound', 'messageistic' ); ?></span>
                    <span><i style="background:#10B981"></i> <?php esc_html_e( 'Inbound', 'messageistic' ); ?></span>
                </div>
            <?php endif; ?>
        </article>

        <article class="messageistic-card">
            <div class="mi-card__head">
                <div>
                    <span class="mi-eyebrow"><?php esc_html_e( 'Live', 'messageistic' ); ?></span>
                    <h3 class="mi-card__title"><?php esc_html_e( 'Recent Activity', 'messageistic' ); ?></h3>
                </div>
            </div>
            <?php if ( empty( $recent ) ) : ?>
                <p class="messageistic-empty"><?php esc_html_e( 'No activity yet. Activity will appear here as messages are sent and webhooks arrive.', 'messageistic' ); ?></p>
            <?php else : ?>
                <ul class="messageistic-activity">
                    <?php foreach ( $recent as $row ) : ?>
                        <li>
                            <span class="messageistic-activity__time"><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $row['created_at'] ) ); ?></span>
                            <span class="messageistic-activity__event"><?php echo esc_html( $row['event_type'] ); ?></span>
                            <span class="messageistic-pill"><?php echo esc_html( $row['provider_key'] ?: '—' ); ?></span>
                            <span class="messageistic-activity__level is-<?php echo esc_attr( $row['level'] ); ?>"><?php echo esc_html( $row['level'] ); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </article>
    </section>

    <section class="messageistic-card">
        <div class="mi-card__head">
            <div>
                <span class="mi-eyebrow"><?php esc_html_e( 'Provider', 'messageistic' ); ?></span>
                <h3 class="mi-card__title"><?php esc_html_e( 'Capabilities', 'messageistic' ); ?></h3>
            </div>
            <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=messageistic-provider-health' ) ); ?>"><?php esc_html_e( 'Provider Health & Sync', 'messageistic' ); ?></a>
        </div>
        <ul class="messageistic-caps">
            <?php foreach ( $health['capabilities'] as $cap => $value ) :
                $display = is_bool( $value ) ? ( $value ? __( 'Yes', 'messageistic' ) : __( 'No', 'messageistic' ) ) : ucwords( str_replace( '_', ' ', (string) $value ) );
                ?>
                <li>
                    <span><?php echo esc_html( ucwords( str_replace( '_', ' ', $cap ) ) ); ?></span>
                    <em><?php echo esc_html( $display ); ?></em>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <footer class="messageistic-footer">
        <?php
        printf(
            /* translators: %s: WordPressistic homepage link. */
            esc_html__( 'Built by %s — Build Your Business Automation with AI.', 'messageistic' ),
            '<a href="https://www.wordpressistic.com" target="_blank" rel="noopener">WordPressistic</a>'
        );
        ?>
    </footer>
</div>
