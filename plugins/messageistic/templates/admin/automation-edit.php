<?php
defined( 'ABSPATH' ) || exit;
/** @var array<string,mixed>|array $automation */
/** @var array<string,string> $triggers */
/** @var array<int,array<string,mixed>> $templates */
$automation = is_array( $automation ?? null ) ? $automation : [];
$id         = (int) ( $automation['id'] ?? 0 );
$conds      = $automation['conditions'] ? json_decode( (string) $automation['conditions'], true ) : [];
$cond_keys  = array_column( (array) $conds, 'kind' );
$actions    = $automation['actions'] ? json_decode( (string) $automation['actions'], true ) : [];
$classification = $pilot_mode ? 'transactional' : (string) ( $actions['classification'] ?? 'transactional' );
?>
<div class="wrap messageistic-wrap">
    <h1 class="messageistic-page-title"><?php echo $id ? esc_html__( 'Edit automation', 'messageistic' ) : esc_html__( 'New automation', 'messageistic' ); ?></h1>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=messageistic-automations' ) ); ?>" class="messageistic-card">
        <?php wp_nonce_field( 'messageistic_save_automation' ); ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>">
        <table class="form-table" role="presentation">
            <tr><th><label><?php esc_html_e( 'Name', 'messageistic' ); ?></label></th><td><input class="regular-text" required type="text" name="name" value="<?php echo esc_attr( (string) ( $automation['name'] ?? '' ) ); ?>"></td></tr>
            <tr><th><?php esc_html_e( 'Location', 'messageistic' ); ?></th><td><select name="location_id"><option value="0"><?php esc_html_e( 'All locations', 'messageistic' ); ?></option><?php foreach ( $locations as $location ) : ?><option value="<?php echo (int) $location['id']; ?>" <?php selected( (int) ( $automation['location_id'] ?? 0 ), (int) $location['id'] ); ?>><?php echo esc_html( $location['name'] ); ?></option><?php endforeach; ?></select></td></tr>
            <tr><th><label><?php esc_html_e( 'Trigger', 'messageistic' ); ?></label></th><td>
                <select name="trigger_key" required>
                    <option value=""><?php esc_html_e( '— Select —', 'messageistic' ); ?></option>
                    <?php foreach ( $triggers as $key => $label ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( (string) ( $automation['trigger_key'] ?? '' ), $key ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </td></tr>
            <tr><th><?php esc_html_e( 'Conditions', 'messageistic' ); ?></th><td>
                <?php
                $available_conds = [
                    'has_consent'   => __( 'Contact has SMS consent', 'messageistic' ),
                    'not_opted_out' => __( 'Contact is not opted out', 'messageistic' ),
                ];
                foreach ( $available_conds as $key => $label ) : ?>
                    <label><input type="checkbox" name="conditions[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $cond_keys, true ) ); ?>> <?php echo esc_html( $label ); ?></label><br>
                <?php endforeach; ?>
            </td></tr>
            <tr><th><label><?php esc_html_e( 'Delay (seconds)', 'messageistic' ); ?></label></th><td><input type="number" min="0" name="delay_seconds" value="<?php echo esc_attr( (string) ( $automation['delay_seconds'] ?? 0 ) ); ?>"></td></tr>
            <tr><th><label><?php esc_html_e( 'Template', 'messageistic' ); ?></label></th><td>
                <select name="template_id">
                    <option value="0"><?php echo esc_html( $pilot_mode ? __( '— Select approved template —', 'messageistic' ) : __( '— Use custom body —', 'messageistic' ) ); ?></option>
                    <?php foreach ( $templates as $t ) : if ( $pilot_mode && ( 'approved' !== ( $t['review_status'] ?? '' ) || 'transactional' !== ( $t['classification'] ?? '' ) ) ) { continue; } ?>
                        <option value="<?php echo (int) $t['id']; ?>" <?php selected( (int) ( $automation['template_id'] ?? 0 ), (int) $t['id'] ); ?>><?php echo esc_html( $t['name'] ); ?></option>
                    <?php endforeach; ?>
                </select>
            </td></tr>
            <tr><th><label><?php esc_html_e( 'Classification', 'messageistic' ); ?></label></th><td><select name="classification" <?php disabled( $pilot_mode ); ?>><option value="transactional" <?php selected( $classification, 'transactional' ); ?>><?php esc_html_e( 'Transactional', 'messageistic' ); ?></option><option value="marketing" <?php selected( $classification, 'marketing' ); ?>><?php esc_html_e( 'Marketing', 'messageistic' ); ?></option><option value="customer_care" <?php selected( $classification, 'customer_care' ); ?>><?php esc_html_e( 'Customer care', 'messageistic' ); ?></option></select><?php if ( $pilot_mode ) : ?><input type="hidden" name="classification" value="transactional"><?php endif; ?></td></tr>
            <tr><th><label><?php esc_html_e( 'Custom body', 'messageistic' ); ?></label></th><td><textarea name="body" rows="4" class="large-text" <?php disabled( $pilot_mode ); ?>><?php echo esc_textarea( (string) ( $actions['body'] ?? '' ) ); ?></textarea></td></tr>
            <tr><th><?php esc_html_e( 'Journey Steps', 'messageistic' ); ?></th><td><p class="description"><?php esc_html_e( 'Steps execute in order. Add rows before saving; waits schedule the next step in the background.', 'messageistic' ); ?></p><div id="messageistic-steps">
            <?php
            $builder_steps = $steps;
            while ( count( $builder_steps ) < 6 ) {
                $builder_steps[] = [ 'step_type' => '', 'delay_seconds' => 0, 'template_id' => 0, 'config' => '{}' ];
            }
            foreach ( $builder_steps as $i => $step ) :
                $cfg = is_array( $step['config'] ?? null ) ? $step['config'] : json_decode( (string) ( $step['config'] ?? '{}' ), true );
                ?>
            <fieldset style="border:1px solid #ccd0d4;padding:10px;margin:8px 0"><legend><?php printf( esc_html__( 'Step %d', 'messageistic' ), $i + 1 ); ?></legend><select name="steps[<?php echo (int) $i; ?>][step_type]"><option value=""><?php esc_html_e( '— Unused —', 'messageistic' ); ?></option><?php foreach ( [ 'send_sms'=>'Send SMS','wait'=>'Wait','condition'=>'Condition: has tag','add_tag'=>'Add tag','assign_inbox'=>'Assign inbox' ] as $key=>$label ) : ?><option value="<?php echo esc_attr($key);?>" <?php selected($step['step_type'],$key);?>><?php echo esc_html($label);?></option><?php endforeach;?></select> Delay <input type="number" min="0" name="steps[<?php echo (int)$i;?>][delay_seconds]" value="<?php echo (int)$step['delay_seconds'];?>"> Template <select name="steps[<?php echo (int)$i;?>][template_id]"><option value="0">—</option><?php foreach($templates as $t):?><option value="<?php echo (int)$t['id'];?>" <?php selected((int)$step['template_id'],(int)$t['id']);?>><?php echo esc_html($t['name']);?></option><?php endforeach;?></select><br>Value/tag <input name="steps[<?php echo (int)$i;?>][value]" value="<?php echo esc_attr((string)($cfg['value']??$cfg['tag']??''));?>"><input type="hidden" name="steps[<?php echo (int)$i;?>][condition]" value="has_tag"> Staff <select name="steps[<?php echo (int)$i;?>][user_id]"><option value="0">—</option><?php foreach($staff as $user):?><option value="<?php echo (int)$user->ID;?>" <?php selected((int)($cfg['user_id']??0),(int)$user->ID);?>><?php echo esc_html($user->display_name);?></option><?php endforeach;?></select> Priority <select name="steps[<?php echo (int)$i;?>][priority]"><?php foreach(['normal','high','urgent'] as $priority):?><option <?php selected(($cfg['priority']??'normal'),$priority);?>><?php echo esc_html($priority);?></option><?php endforeach;?></select></fieldset><?php endforeach;?></div></td></tr>
            <tr><th><?php esc_html_e( 'Active', 'messageistic' ); ?></th><td><label><input type="checkbox" name="is_active" value="1" <?php checked( ! empty( $automation['is_active'] ) ); ?>> <?php esc_html_e( 'Run on trigger', 'messageistic' ); ?></label></td></tr>
        </table>
        <?php submit_button(); ?>
    </form>
    <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=messageistic-automations' ) ); ?>">&larr; <?php esc_html_e( 'Back to automations', 'messageistic' ); ?></a></p>
</div>
