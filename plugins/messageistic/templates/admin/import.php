<?php
defined( 'ABSPATH' ) || exit;
/** @var string $notice */
/** @var string $current_kind */
/** @var array<string,array<string,mixed>> $jobs */
/** @var array{path:string,name:string}|null $upload */
/** @var array{headers:array<int,string>,preview:array<int,array<string,string>>,suggested_mapping:array<string,string>}|null $preview */

$kinds = [
    'contacts'  => __( 'Contacts', 'messageistic' ),
    'templates' => __( 'Templates', 'messageistic' ),
    'campaigns' => __( 'Campaigns', 'messageistic' ),
];
$base = admin_url( 'admin.php?page=messageistic-import' );
?>
<div class="wrap messageistic-wrap">
    <header class="mi-page-header">
        <div>
            <h1 class="mi-page-title"><?php esc_html_e( 'Bulk Import', 'messageistic' ); ?></h1>
            <p class="mi-subtitle"><?php esc_html_e( 'Import contacts, templates, or campaigns from a CSV file.', 'messageistic' ); ?></p>
        </div>
    </header>

    <?php if ( $notice ) : ?>
        <div class="mi-alert mi-alert--info"><?php echo esc_html( $notice ); ?></div>
    <?php endif; ?>

    <nav class="mi-tabs">
        <?php foreach ( $kinds as $key => $label ) :
            $url   = add_query_arg( [ 'page' => 'messageistic-import', 'kind' => $key ], admin_url( 'admin.php' ) );
            $class = 'mi-tab' . ( $current_kind === $key ? ' is-active' : '' );
            ?>
            <a class="<?php echo esc_attr( $class ); ?>" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a>
        <?php endforeach; ?>
    </nav>

    <?php
    // In-progress / last job for current kind.
    $job = $jobs[ $current_kind ] ?? null;
    if ( $job ) :
        $running = in_array( $job['status'], [ 'queued', 'running' ], true );
        ?>
        <section class="mi-card">
            <div class="mi-card__head">
                <div>
                    <span class="mi-eyebrow"><?php esc_html_e( 'Current job', 'messageistic' ); ?></span>
                    <h3 class="mi-card__title"><?php echo esc_html( $job['file_name'] ); ?></h3>
                </div>
                <span class="mi-badge mi-badge--<?php echo esc_attr( in_array( $job['status'], [ 'completed' ], true ) ? 'ok' : ( $running ? 'info' : 'danger' ) ); ?>"><?php echo esc_html( ucfirst( $job['status'] ) ); ?></span>
            </div>
            <div class="mi-stat-row">
                <div class="mi-stat"><span class="mi-stat__label"><?php esc_html_e( 'Fetched', 'messageistic' ); ?></span><strong class="mi-stat__value"><?php echo number_format_i18n( (int) $job['fetched'] ); ?></strong></div>
                <div class="mi-stat"><span class="mi-stat__label"><?php esc_html_e( 'Imported', 'messageistic' ); ?></span><strong class="mi-stat__value mi-stat__value--ok"><?php echo number_format_i18n( (int) $job['imported'] ); ?></strong></div>
                <div class="mi-stat"><span class="mi-stat__label"><?php esc_html_e( 'Updated', 'messageistic' ); ?></span><strong class="mi-stat__value"><?php echo number_format_i18n( (int) $job['updated'] ); ?></strong></div>
                <div class="mi-stat"><span class="mi-stat__label"><?php esc_html_e( 'Skipped', 'messageistic' ); ?></span><strong class="mi-stat__value mi-stat__value--muted"><?php echo number_format_i18n( (int) $job['skipped'] ); ?></strong></div>
                <div class="mi-stat"><span class="mi-stat__label"><?php esc_html_e( 'Failed', 'messageistic' ); ?></span><strong class="mi-stat__value mi-stat__value--danger"><?php echo number_format_i18n( (int) $job['failed'] ); ?></strong></div>
            </div>
            <?php if ( $job['total_bytes'] ) :
                $progress = min( 100, round( ( (int) $job['offset'] / max( 1, (int) $job['total_bytes'] ) ) * 100 ) );
                ?>
                <div class="mi-progress"><div class="mi-progress__bar" style="width:<?php echo esc_attr( (string) $progress ); ?>%;"></div></div>
                <p class="mi-help"><?php echo esc_html( $progress . '%' ); ?> &nbsp;·&nbsp; <?php echo esc_html( $job['updated_at'] ); ?></p>
            <?php endif; ?>
            <?php if ( ! empty( $job['errors'] ) ) : ?>
                <details class="mi-errors">
                    <summary><?php printf( esc_html__( '%d row errors', 'messageistic' ), count( (array) $job['errors'] ) ); ?></summary>
                    <ul>
                        <?php foreach ( array_slice( (array) $job['errors'], 0, 25 ) as $e ) : ?>
                            <li><?php echo esc_html( (string) $e ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>
            <?php if ( $running ) : ?>
                <form method="post" action="<?php echo esc_url( $base ); ?>" class="mi-mt-12">
                    <?php wp_nonce_field( 'messageistic_import_cancel' ); ?>
                    <input type="hidden" name="action" value="cancel">
                    <input type="hidden" name="kind" value="<?php echo esc_attr( $current_kind ); ?>">
                    <button type="submit" class="button"><?php esc_html_e( 'Cancel import', 'messageistic' ); ?></button>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ( $preview && $upload ) :
        $targets = \Messageistic\Import\Import_Service::target_fields( $current_kind );
        $mapping = (array) $preview['suggested_mapping'];
        ?>
        <section class="mi-card">
            <div class="mi-card__head">
                <div>
                    <span class="mi-eyebrow"><?php esc_html_e( 'Step 2 of 2', 'messageistic' ); ?></span>
                    <h3 class="mi-card__title"><?php esc_html_e( 'Map columns', 'messageistic' ); ?></h3>
                    <p class="mi-help"><?php echo esc_html( $upload['name'] ); ?> · <?php printf( esc_html__( '%d headers detected.', 'messageistic' ), count( (array) $preview['headers'] ) ); ?></p>
                </div>
            </div>

            <form method="post" action="<?php echo esc_url( $base ); ?>">
                <?php wp_nonce_field( 'messageistic_import_start' ); ?>
                <input type="hidden" name="action" value="start">
                <input type="hidden" name="kind" value="<?php echo esc_attr( $current_kind ); ?>">
                <input type="hidden" name="file_path" value="<?php echo esc_attr( $upload['path'] ); ?>">
                <input type="hidden" name="file_name" value="<?php echo esc_attr( $upload['name'] ); ?>">

                <div class="mi-table-wrap">
                    <table class="mi-mapping">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'CSV column', 'messageistic' ); ?></th>
                                <th><?php esc_html_e( 'Sample', 'messageistic' ); ?></th>
                                <th><?php esc_html_e( 'Maps to', 'messageistic' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( (array) $preview['headers'] as $header ) :
                                $sample = $preview['preview'][0][ $header ] ?? '';
                                $picked = $mapping[ $header ] ?? '';
                                ?>
                                <tr>
                                    <td><strong><?php echo esc_html( $header ); ?></strong></td>
                                    <td class="mi-mapping__sample"><?php echo esc_html( (string) $sample ); ?></td>
                                    <td>
                                        <select name="mapping[<?php echo esc_attr( $header ); ?>]">
                                            <?php foreach ( $targets as $value => $label ) : ?>
                                                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $picked, $value ); ?>><?php echo esc_html( $label ); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <p class="mi-mt-16"><button type="submit" class="button button-primary"><?php esc_html_e( 'Start import', 'messageistic' ); ?></button></p>
            </form>
        </section>
    <?php else : ?>
        <section class="mi-card">
            <div class="mi-card__head">
                <div>
                    <span class="mi-eyebrow"><?php esc_html_e( 'Step 1 of 2', 'messageistic' ); ?></span>
                    <h3 class="mi-card__title"><?php printf( esc_html__( 'Upload %s CSV', 'messageistic' ), esc_html( $kinds[ $current_kind ] ?? '' ) ); ?></h3>
                    <p class="mi-help">
                        <?php esc_html_e( 'Need a starting point?', 'messageistic' ); ?>
                        <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( [ 'action' => 'sample', 'kind' => $current_kind ], $base ), 'messageistic_sample' ) ); ?>"><?php esc_html_e( 'Download sample CSV', 'messageistic' ); ?></a>
                    </p>
                </div>
            </div>

            <form method="post" action="<?php echo esc_url( $base ); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field( 'messageistic_import_upload' ); ?>
                <input type="hidden" name="action" value="upload">
                <input type="hidden" name="kind" value="<?php echo esc_attr( $current_kind ); ?>">
                <div class="mi-dropzone">
                    <input type="file" name="csv" accept=".csv,text/csv" required>
                    <p class="mi-help"><?php esc_html_e( 'CSV only. The first row must contain column names.', 'messageistic' ); ?></p>
                </div>
                <p class="mi-mt-16"><button type="submit" class="button button-primary"><?php esc_html_e( 'Upload & preview', 'messageistic' ); ?></button></p>
            </form>

            <div class="mi-help-block mi-mt-16">
                <h4><?php esc_html_e( 'Expected columns', 'messageistic' ); ?></h4>
                <?php
                $fields = \Messageistic\Import\Import_Service::target_fields( $current_kind );
                unset( $fields[''] );
                ?>
                <ul class="mi-cols">
                    <?php foreach ( $fields as $f => $label ) : ?>
                        <li><code><?php echo esc_html( $f ); ?></code> — <?php echo esc_html( $label ); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>
    <?php endif; ?>
</div>
