<?php
defined( 'ABSPATH' ) || exit;
/** @var \Messageistic\Providers\Provider_Manager $providers */
/** @var array<string,array<string,mixed>> $jobs */
/** @var array<string,int> $contacts_total */
/** @var string $notice */

$active_key = $providers->get_active_key();
$base       = admin_url( 'admin.php?page=messageistic-provider-health' );
?>
<div class="wrap messageistic-wrap">
    <h1 class="messageistic-page-title"><?php esc_html_e( 'Provider Health & Sync', 'messageistic' ); ?></h1>

    <?php if ( $notice ) : ?>
        <div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div>
    <?php endif; ?>

    <?php foreach ( $providers->all() as $key => $provider ) :
        $settings    = $provider instanceof \Messageistic\Providers\Abstract_Provider ? $provider->get_settings() : [];
        $validation  = $provider->validate_settings( $settings );
        $is_active   = $key === $active_key;
        $caps        = $provider->get_capabilities();
        $can_import  = in_array( $key, [ 'ottertext', 'twilio' ], true );
        $job         = $jobs[ $key ] ?? null;
        $imported    = (int) ( $contacts_total[ $key ] ?? 0 );
        ?>
        <section class="messageistic-card" style="margin-bottom:16px;">
            <header class="messageistic-header" style="margin-bottom:12px;">
                <div>
                    <span class="messageistic-eyebrow"><?php echo esc_html( strtoupper( $key ) ); ?></span>
                    <h2 style="margin:2px 0;color:var(--mi-text);"><?php echo esc_html( $provider->get_label() ); ?></h2>
                    <p style="margin:0;color:var(--mi-muted);font-size:12px;">
                        <?php echo $is_active ? esc_html__( 'Active provider', 'messageistic' ) : esc_html__( 'Inactive', 'messageistic' ); ?>
                        ·
                        <?php
                        if ( is_wp_error( $validation ) ) {
                            echo '<span class="messageistic-badge is-danger">' . esc_html__( 'Settings incomplete', 'messageistic' ) . '</span>';
                        } else {
                            echo '<span class="messageistic-badge is-ok">' . esc_html__( 'Settings OK', 'messageistic' ) . '</span>';
                        }
                        ?>
                    </p>
                </div>
                <div style="text-align:right;">
                    <strong style="color:var(--mi-text);font-size:22px;"><?php echo number_format_i18n( $imported ); ?></strong>
                    <div style="color:var(--mi-muted);font-size:12px;"><?php esc_html_e( 'contacts attributed', 'messageistic' ); ?></div>
                </div>
            </header>

            <div class="messageistic-grid messageistic-grid--split">
                <div>
                    <h3 style="margin-top:0;"><?php esc_html_e( 'Capabilities', 'messageistic' ); ?></h3>
                    <ul class="messageistic-caps">
                        <?php foreach ( $caps as $cap_name => $cap_value ) :
                            $display = is_bool( $cap_value )
                                ? ( $cap_value ? __( 'Yes', 'messageistic' ) : __( 'No', 'messageistic' ) )
                                : ucwords( str_replace( '_', ' ', (string) $cap_value ) );
                            ?>
                            <li>
                                <span><?php echo esc_html( ucwords( str_replace( '_', ' ', $cap_name ) ) ); ?></span>
                                <em><?php echo esc_html( $display ); ?></em>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <div>
                    <h3 style="margin-top:0;"><?php esc_html_e( 'Sync from provider', 'messageistic' ); ?></h3>
                    <?php if ( ! $can_import ) : ?>
                        <p class="messageistic-empty"><?php esc_html_e( 'This provider does not support contact sync.', 'messageistic' ); ?></p>
                    <?php elseif ( is_wp_error( $validation ) ) : ?>
                        <p class="messageistic-empty"><?php echo esc_html( $validation->get_error_message() ); ?></p>
                    <?php else : ?>
                        <?php if ( $job && in_array( $job['status'], [ 'queued', 'running' ], true ) ) :
                            $progress = $job['total'] ? min( 100, round( ( (int) $job['fetched'] / max( 1, (int) $job['total'] ) ) * 100 ) ) : null;
                            ?>
                            <p>
                                <strong><?php echo esc_html( ucfirst( $job['status'] ) ); ?></strong> ·
                                <?php
                                printf(
                                    /* translators: 1: fetched 2: total */
                                    esc_html__( 'Fetched %1$d / %2$s', 'messageistic' ),
                                    (int) $job['fetched'],
                                    null === $job['total'] ? '?' : number_format_i18n( (int) $job['total'] )
                                );
                                ?>
                                · <?php printf( esc_html__( 'Imported %d', 'messageistic' ), (int) $job['imported'] ); ?>
                                · <?php printf( esc_html__( 'Updated %d', 'messageistic' ), (int) $job['updated'] ); ?>
                                · <?php printf( esc_html__( 'Skipped %d', 'messageistic' ), (int) $job['skipped'] ); ?>
                            </p>
                            <?php if ( null !== $progress ) : ?>
                                <progress value="<?php echo esc_attr( (string) $progress ); ?>" max="100" style="width:100%;"></progress>
                            <?php endif; ?>
                            <form method="post" action="<?php echo esc_url( $base ); ?>" style="margin-top:8px;">
                                <?php wp_nonce_field( 'messageistic_sync' ); ?>
                                <input type="hidden" name="action" value="sync_cancel">
                                <input type="hidden" name="provider" value="<?php echo esc_attr( $key ); ?>">
                                <button type="submit" class="button"><?php esc_html_e( 'Cancel sync', 'messageistic' ); ?></button>
                            </form>
                        <?php else : ?>
                            <?php if ( $job ) : ?>
                                <p style="color:var(--mi-muted);font-size:12px;">
                                    <?php
                                    printf(
                                        /* translators: 1: status 2: timestamp 3: imported 4: updated */
                                        esc_html__( 'Last run: %1$s on %2$s — %3$d imported, %4$d updated.', 'messageistic' ),
                                        esc_html( $job['status'] ),
                                        esc_html( $job['updated_at'] ),
                                        (int) $job['imported'],
                                        (int) $job['updated']
                                    );
                                    ?>
                                    <?php if ( ! empty( $job['last_error'] ) ) : ?>
                                        <br><span class="messageistic-badge is-danger"><?php echo esc_html( $job['last_error'] ); ?></span>
                                    <?php endif; ?>
                                </p>
                            <?php endif; ?>

                            <form method="post" action="<?php echo esc_url( $base ); ?>">
                                <?php wp_nonce_field( 'messageistic_sync' ); ?>
                                <input type="hidden" name="action" value="sync_start">
                                <input type="hidden" name="provider" value="<?php echo esc_attr( $key ); ?>">
                                <?php if ( 'ottertext' === $key ) : ?>
                                    <input type="hidden" name="kind" value="contacts">
                                    <p>
                                        <label style="display:block;margin-bottom:4px;color:var(--mi-muted);font-size:12px;"><?php esc_html_e( 'List ID (optional — defaults to setting)', 'messageistic' ); ?></label>
                                        <input type="text" name="list_id" class="regular-text" placeholder="<?php echo esc_attr( (string) ( $settings['default_list_id'] ?? '' ) ); ?>">
                                    </p>
                                    <p>
                                        <label style="display:block;margin-bottom:4px;color:var(--mi-muted);font-size:12px;"><?php esc_html_e( 'Campaign ID (optional)', 'messageistic' ); ?></label>
                                        <input type="text" name="campaign_id" class="regular-text" placeholder="<?php echo esc_attr( (string) ( $settings['default_campaign_id'] ?? '' ) ); ?>">
                                    </p>
                                <?php else : ?>
                                    <input type="hidden" name="kind" value="inbound_contacts">
                                    <p style="color:var(--mi-muted);font-size:12px;"><?php esc_html_e( 'Twilio sync scans inbound Messages and imports each unique sender as a Messageistic contact.', 'messageistic' ); ?></p>
                                <?php endif; ?>
                                <p>
                                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Start sync', 'messageistic' ); ?></button>
                                </p>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    <?php endforeach; ?>
</div>
