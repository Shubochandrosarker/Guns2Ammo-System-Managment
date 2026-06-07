<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
// Helper to get option
function vfya_opt( $key, $default = '' ) {
    return get_option( $key, $default );
}
?>
<div class="verifyistic-wrap">

  <!-- Header -->
  <div class="vfya-header">
    <div class="vfya-header-inner">
      <div class="vfya-header-brand">
        <div class="vfya-header-icon">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/>
          </svg>
        </div>
        <div class="vfya-header-text">
          <h1 class="vfya-header-title">Verifyistic<span> · Settings</span></h1>
          <p class="vfya-header-sub">Configure your age verification popup · Built by WordPressistic</p>
        </div>
      </div>
      <span class="vfya-badge vfya-badge-version">v<?php echo esc_html(VERIFYISTIC_VERSION); ?></span>
    </div>
  </div>

  <!-- Navigation -->
  <nav class="vfya-nav">
    <a href="<?php echo admin_url('admin.php?page=verifyistic'); ?>">
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"/></svg>
      Dashboard
    </a>
    <a href="<?php echo admin_url('admin.php?page=verifyistic-settings'); ?>" class="active">
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
      Settings
    </a>
    <a href="<?php echo admin_url('admin.php?page=verifyistic-logs'); ?>">
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>
      Verification Logs
    </a>
  </nav>

  <!-- Body -->
  <div class="vfya-body">

    <form method="POST" action="" id="vfya-settings-form">
      <?php wp_nonce_field( 'verifyistic_save', 'verifyistic_nonce' ); ?>
      <input type="hidden" name="verifyistic_save_settings" value="1">

      <!-- Settings Tabs -->
      <div class="vfya-settings-tabs">
        <button type="button" class="vfya-stab active" data-tab="general">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:14px;height:14px;"><path stroke-linecap="round" stroke-linejoin="round" d="M10.343 3.94c.09-.542.56-.94 1.11-.94h1.093c.55 0 1.02.398 1.11.94l.149.894c.07.424.384.764.78.93.398.164.855.142 1.205-.108l.737-.527a1.125 1.125 0 011.45.12l.773.774c.39.389.44 1.002.12 1.45l-.527.737c-.25.35-.272.806-.107 1.204.165.397.505.71.93.78l.893.15c.543.09.94.56.94 1.109v1.094c0 .55-.397 1.02-.94 1.11l-.893.149c-.425.07-.765.383-.93.78-.165.398-.143.854.107 1.204l.527.738c.32.447.269 1.06-.12 1.45l-.774.773a1.125 1.125 0 01-1.449.12l-.738-.527c-.35-.25-.806-.272-1.203-.107-.397.165-.71.505-.781.929l-.149.894c-.09.542-.56.94-1.11.94h-1.094c-.55 0-1.019-.398-1.11-.94l-.148-.894c-.071-.424-.384-.764-.781-.93-.398-.164-.854-.142-1.204.108l-.738.527c-.447.32-1.06.269-1.45-.12l-.773-.774a1.125 1.125 0 01-.12-1.45l.527-.737c.25-.35.273-.806.108-1.204-.165-.397-.505-.71-.93-.78l-.894-.15c-.542-.09-.94-.56-.94-1.109v-1.094c0-.55.398-1.02.94-1.11l.894-.149c.424-.07.765-.383.93-.78.165-.398.143-.854-.107-1.204l-.527-.738a1.125 1.125 0 01.12-1.45l.773-.773a1.125 1.125 0 011.45-.12l.737.527c.35.25.807.272 1.204.107.397-.165.71-.505.78-.929l.15-.894z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
          General
        </button>
        <button type="button" class="vfya-stab" data-tab="design">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:14px;height:14px;"><path stroke-linecap="round" stroke-linejoin="round" d="M9.53 16.122a3 3 0 00-5.78 1.128 2.25 2.25 0 01-2.4 2.245 4.5 4.5 0 008.4-2.245c0-.399-.078-.78-.22-1.128zm0 0a15.998 15.998 0 003.388-1.62m-5.043-.025a15.994 15.994 0 011.622-3.395m3.42 3.42a15.995 15.995 0 004.764-4.648l3.876-5.814a1.151 1.151 0 00-1.597-1.597L14.146 6.32a15.996 15.996 0 00-4.649 4.763m3.42 3.42a6.776 6.776 0 00-3.42-3.42"/></svg>
          Design
        </button>
        <button type="button" class="vfya-stab" data-tab="advanced">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:14px;height:14px;"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 7.5l3 2.25-3 2.25m4.5 0h3m-9 8.25h13.5A2.25 2.25 0 0021 18V6a2.25 2.25 0 00-2.25-2.25H5.25A2.25 2.25 0 003 6v12a2.25 2.25 0 002.25 2.25z"/></svg>
          Advanced
        </button>
      </div>

      <!-- TAB: General -->
      <div class="vfya-tab-panel active" id="vfya-panel-general">
        <div class="vfya-card">
          <div class="vfya-card-header">
            <h3 class="vfya-card-title">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z"/></svg>
              General Settings
            </h3>
          </div>
          <div class="vfya-card-body">

            <!-- Enable Plugin -->
            <div class="vfya-field">
              <div class="vfya-toggle-wrap">
                <label class="vfya-toggle">
                  <input type="checkbox" name="verifyistic_enabled" id="verifyistic_enabled" value="1" <?php checked(vfya_opt('verifyistic_enabled','1'), '1'); ?>>
                  <span class="vfya-toggle-slider"></span>
                </label>
                <span class="vfya-toggle-label">Enable Age Verification</span>
              </div>
            </div>

            <div class="vfya-section-divider">Core Configuration</div>

            <div class="vfya-field-row">
              <!-- Min Age -->
              <div class="vfya-field">
                <label class="vfya-label" for="verifyistic_min_age">
                  Minimum Age Requirement
                  <span class="vfya-label-sub">Minimum allowed: 18. Default: 21 (US firearms).</span>
                </label>
                <input type="number" class="vfya-input" name="verifyistic_min_age" id="verifyistic_min_age" value="<?php echo esc_attr(vfya_opt('verifyistic_min_age','21')); ?>" min="18" max="99">
              </div>

              <!-- Verification Mode -->
              <div class="vfya-field" id="vfya-mode-field">
                <label class="vfya-label" for="verifyistic_mode">
                  Verification Mode
                  <span class="vfya-label-sub">Overridden by ID & Face toggle below.</span>
                </label>
                <select class="vfya-select" name="verifyistic_mode" id="verifyistic_mode">
                  <option value="dob"    <?php selected(vfya_opt('verifyistic_mode','dob'), 'dob'); ?>>Date of Birth Input</option>
                  <option value="yes_no" <?php selected(vfya_opt('verifyistic_mode','dob'), 'yes_no'); ?>>Yes / No Buttons</option>
                </select>
              </div>
            </div>

            <!-- ID & Face Toggle -->
            <div class="vfya-field" style="background:var(--vfya-bg);border-radius:var(--vfya-radius-sm);padding:16px;border:1.5px solid var(--vfya-border);">
              <div class="vfya-toggle-wrap">
                <label class="vfya-toggle">
                  <input type="checkbox" name="verifyistic_id_verification" id="verifyistic_id_verification" value="1" <?php checked(vfya_opt('verifyistic_id_verification','0'), '1'); ?>>
                  <span class="vfya-toggle-slider"></span>
                </label>
                <div>
                  <span class="vfya-toggle-label">Enable ID &amp; Face Verification Mode</span>
                  <span class="vfya-label-sub" style="display:block;margin-top:2px;">When enabled, users must upload a government ID + selfie. Overrides the mode above. Verified data is saved for <?php echo (int) get_option('verifyistic_cookie_days', 30); ?> days.</span>
                </div>
              </div>
            </div>

            <div class="vfya-section-divider">Popup Text</div>

            <div class="vfya-field-row">
              <div class="vfya-field">
                <label class="vfya-label" for="verifyistic_heading_text">Popup Heading</label>
                <input type="text" class="vfya-input" name="verifyistic_heading_text" id="verifyistic_heading_text" value="<?php echo esc_attr(vfya_opt('verifyistic_heading_text','Age Verification Required')); ?>">
              </div>
              <div class="vfya-field">
                <label class="vfya-label">ID Upload Label <span class="vfya-label-sub">Shown in ID & Face mode.</span></label>
                <input type="text" class="vfya-input" name="verifyistic_id_upload_label" value="<?php echo esc_attr(vfya_opt('verifyistic_id_upload_label','Upload a valid government-issued ID')); ?>">
              </div>
            </div>

            <div class="vfya-field">
              <label class="vfya-label" for="verifyistic_message_text">Popup Message</label>
              <textarea class="vfya-textarea" name="verifyistic_message_text" id="verifyistic_message_text"><?php echo wp_kses_post(vfya_opt('verifyistic_message_text')); ?></textarea>
            </div>

            <div class="vfya-field-row">
              <div class="vfya-field">
                <label class="vfya-label">Yes / I Agree Button Text</label>
                <input type="text" class="vfya-input" name="verifyistic_btn_yes_text" value="<?php echo esc_attr(vfya_opt('verifyistic_btn_yes_text','Yes, I am 21+')); ?>">
              </div>
              <div class="vfya-field">
                <label class="vfya-label">No / Exit Button Text</label>
                <input type="text" class="vfya-input" name="verifyistic_btn_no_text" value="<?php echo esc_attr(vfya_opt('verifyistic_btn_no_text','No, Exit')); ?>">
              </div>
            </div>

            <div class="vfya-field">
              <label class="vfya-label">Selfie Upload Label <span class="vfya-label-sub">ID & Face mode only.</span></label>
              <input type="text" class="vfya-input" name="verifyistic_selfie_label" value="<?php echo esc_attr(vfya_opt('verifyistic_selfie_label','Upload a selfie holding your ID')); ?>">
            </div>

            <div class="vfya-field">
              <label class="vfya-label">Privacy / Terms Text <span class="vfya-label-sub">HTML links allowed.</span></label>
              <input type="text" class="vfya-input" name="verifyistic_privacy_text" value="<?php echo esc_attr(vfya_opt('verifyistic_privacy_text')); ?>">
            </div>

            <div class="vfya-section-divider">Exclusions</div>

            <div class="vfya-field-row">
              <div class="vfya-field">
                <label class="vfya-label" for="verifyistic_exclude_pages">
                  Exclude Page IDs
                  <span class="vfya-label-sub">Comma-separated page IDs where popup is skipped (e.g. 12, 45, 78).</span>
                </label>
                <input type="text" class="vfya-input" name="verifyistic_exclude_pages" id="verifyistic_exclude_pages" placeholder="e.g. 12, 45, 78" value="<?php echo esc_attr(vfya_opt('verifyistic_exclude_pages')); ?>">
              </div>
              <div class="vfya-field">
                <label class="vfya-label" for="verifyistic_redirect_url">
                  Redirect URL (on decline)
                  <span class="vfya-label-sub">Where to send users who click "No". Leave blank to go back.</span>
                </label>
                <input type="url" class="vfya-input" name="verifyistic_redirect_url" id="verifyistic_redirect_url" placeholder="https://example.com" value="<?php echo esc_attr(vfya_opt('verifyistic_redirect_url')); ?>">
              </div>
            </div>

          </div><!-- /card-body -->
          <div class="vfya-save-bar">
            <span class="vfya-save-info">Changes are saved immediately on submit.</span>
            <button type="submit" class="vfya-btn vfya-btn-primary">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
              Save Settings
            </button>
          </div>
        </div>
      </div>

      <!-- TAB: Design -->
      <div class="vfya-tab-panel" id="vfya-panel-design">
        <div class="vfya-card">
          <div class="vfya-card-header">
            <h3 class="vfya-card-title">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.53 16.122a3 3 0 00-5.78 1.128 2.25 2.25 0 01-2.4 2.245 4.5 4.5 0 008.4-2.245c0-.399-.078-.78-.22-1.128zm0 0a15.998 15.998 0 003.388-1.62m-5.043-.025a15.994 15.994 0 011.622-3.395m3.42 3.42a15.995 15.995 0 004.764-4.648l3.876-5.814a1.151 1.151 0 00-1.597-1.597L14.146 6.32a15.996 15.996 0 00-4.649 4.763m3.42 3.42a6.776 6.776 0 00-3.42-3.42"/></svg>
              Design & Appearance
            </h3>
          </div>
          <div class="vfya-card-body">

            <!-- Logo Upload -->
            <div class="vfya-field vfya-logo-field">
              <label class="vfya-label">Client Logo <span class="vfya-label-sub">Displayed at top of the popup. Recommended max width: 300px.</span></label>
              <div class="vfya-input-group">
                <input type="url" class="vfya-input vfya-logo-input" name="verifyistic_logo" id="verifyistic_logo" placeholder="https://..." value="<?php echo esc_attr(vfya_opt('verifyistic_logo')); ?>">
                <button type="button" class="vfya-btn vfya-btn-secondary vfya-upload-logo-btn">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:14px;height:14px;"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/></svg>
                  Upload
                </button>
                <button type="button" class="vfya-btn vfya-btn-ghost vfya-remove-logo-btn">Remove</button>
              </div>
              <div class="vfya-logo-preview" <?php echo vfya_opt('verifyistic_logo') ? '' : 'style="display:none"'; ?>>
                <?php if ($logo = vfya_opt('verifyistic_logo')) : ?>
                  <img src="<?php echo esc_url($logo); ?>" alt="Logo preview">
                <?php endif; ?>
              </div>
            </div>

            <div class="vfya-section-divider">Popup Colors</div>

            <div class="vfya-field-row">
              <div class="vfya-field">
                <label class="vfya-label">Popup Background Color</label>
                <input type="text" class="vfya-input vfya-color-picker" name="verifyistic_popup_bg_color" value="<?php echo esc_attr(vfya_opt('verifyistic_popup_bg_color','#0f172a')); ?>">
              </div>
              <div class="vfya-field">
                <label class="vfya-label">Popup Font / Text Color</label>
                <input type="text" class="vfya-input vfya-color-picker" name="verifyistic_font_color" value="<?php echo esc_attr(vfya_opt('verifyistic_font_color','#f8fafc')); ?>">
              </div>
            </div>

            <div class="vfya-field-row">
              <div class="vfya-field">
                <label class="vfya-label">Accent / Input Focus Color</label>
                <input type="text" class="vfya-input vfya-color-picker" name="verifyistic_accent_color" value="<?php echo esc_attr(vfya_opt('verifyistic_accent_color','#14b8a6')); ?>">
              </div>
              <div class="vfya-field">
                <label class="vfya-label">Overlay Color</label>
                <input type="text" class="vfya-input vfya-color-picker" name="verifyistic_overlay_color" value="<?php echo esc_attr(vfya_opt('verifyistic_overlay_color','#000000')); ?>">
              </div>
            </div>

            <!-- Overlay Opacity Slider -->
            <div class="vfya-field">
              <label class="vfya-label">
                Background Overlay Opacity
                <span class="vfya-label-sub">Controls how much the page is obscured. 0 = transparent, 100 = fully opaque.</span>
              </label>
              <div class="vfya-range-wrap">
                <input type="range" class="vfya-range" name="verifyistic_overlay_opacity" min="0" max="100" value="<?php echo esc_attr(vfya_opt('verifyistic_overlay_opacity','80')); ?>">
                <span class="vfya-range-value"><?php echo esc_attr(vfya_opt('verifyistic_overlay_opacity','80')); ?>%</span>
              </div>
            </div>

            <div class="vfya-section-divider">Button Styles</div>

            <div class="vfya-field-row">
              <div class="vfya-field">
                <label class="vfya-label">Yes Button Color</label>
                <input type="text" class="vfya-input vfya-color-picker" name="verifyistic_btn_yes_color" value="<?php echo esc_attr(vfya_opt('verifyistic_btn_yes_color','#14b8a6')); ?>">
              </div>
              <div class="vfya-field">
                <label class="vfya-label">Yes Button Text Color</label>
                <input type="text" class="vfya-input vfya-color-picker" name="verifyistic_btn_yes_text_color" value="<?php echo esc_attr(vfya_opt('verifyistic_btn_yes_text_color','#ffffff')); ?>">
              </div>
            </div>

            <div class="vfya-field-row">
              <div class="vfya-field">
                <label class="vfya-label">No Button Color</label>
                <input type="text" class="vfya-input vfya-color-picker" name="verifyistic_btn_no_color" value="<?php echo esc_attr(vfya_opt('verifyistic_btn_no_color','#475569')); ?>">
              </div>
              <div class="vfya-field">
                <label class="vfya-label">No Button Text Color</label>
                <input type="text" class="vfya-input vfya-color-picker" name="verifyistic_btn_no_text_color" value="<?php echo esc_attr(vfya_opt('verifyistic_btn_no_text_color','#ffffff')); ?>">
              </div>
            </div>

            <div class="vfya-field">
              <label class="vfya-label" for="verifyistic_btn_style">Button Shape / Style</label>
              <select class="vfya-select" name="verifyistic_btn_style" id="verifyistic_btn_style" style="max-width:280px;">
                <option value="rounded" <?php selected(vfya_opt('verifyistic_btn_style','rounded'),'rounded'); ?>>Rounded (8px)</option>
                <option value="pill"    <?php selected(vfya_opt('verifyistic_btn_style','rounded'),'pill'); ?>>Pill (50px)</option>
                <option value="sharp"   <?php selected(vfya_opt('verifyistic_btn_style','rounded'),'sharp'); ?>>Sharp Corners</option>
              </select>
            </div>

            <div class="vfya-section-divider">Popup Dimensions</div>

            <div class="vfya-field-row">
              <div class="vfya-field">
                <label class="vfya-label">Popup Max Width (px) <span class="vfya-label-sub">Default: 480</span></label>
                <input type="number" class="vfya-input" name="verifyistic_popup_width" value="<?php echo esc_attr(vfya_opt('verifyistic_popup_width','480')); ?>" min="300" max="900">
              </div>
              <div class="vfya-field">
                <label class="vfya-label">Logo Max Width (px) <span class="vfya-label-sub">Default: 160</span></label>
                <input type="number" class="vfya-input" name="verifyistic_logo_max_width" value="<?php echo esc_attr(vfya_opt('verifyistic_logo_max_width','160')); ?>" min="60" max="400">
              </div>
            </div>

            <div class="vfya-section-divider">Custom CSS</div>

            <div class="vfya-field">
              <label class="vfya-label">Custom CSS <span class="vfya-label-sub">Applied after all plugin styles. Use <code>#verifyistic-overlay</code> and <code>#verifyistic-popup</code> selectors.</span></label>
              <textarea class="vfya-textarea" name="verifyistic_custom_css" id="verifyistic_custom_css" rows="6" style="font-family:monospace;font-size:13px;"><?php echo esc_textarea(vfya_opt('verifyistic_custom_css')); ?></textarea>
            </div>

          </div>
          <div class="vfya-save-bar">
            <span class="vfya-save-info">Changes are saved immediately on submit.</span>
            <button type="submit" class="vfya-btn vfya-btn-primary">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
              Save Settings
            </button>
          </div>
        </div>
      </div>

      <!-- TAB: Advanced -->
      <div class="vfya-tab-panel" id="vfya-panel-advanced">
        <div class="vfya-card">
          <div class="vfya-card-header">
            <h3 class="vfya-card-title">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 7.5l3 2.25-3 2.25m4.5 0h3m-9 8.25h13.5A2.25 2.25 0 0021 18V6a2.25 2.25 0 00-2.25-2.25H5.25A2.25 2.25 0 003 6v12a2.25 2.25 0 002.25 2.25z"/></svg>
              Advanced & Integrations
            </h3>
          </div>
          <div class="vfya-card-body">

            <div class="vfya-section-divider">Cookie & Session</div>

            <div class="vfya-field">
              <div class="vfya-toggle-wrap">
                <label class="vfya-toggle">
                  <input type="checkbox" name="verifyistic_remember_me" value="1" <?php checked(vfya_opt('verifyistic_remember_me','1'),'1'); ?>>
                  <span class="vfya-toggle-slider"></span>
                </label>
                <div>
                  <span class="vfya-toggle-label">Show "Remember Me for <?php echo (int) get_option('verifyistic_cookie_days', 30); ?> Days" Checkbox</span>
                  <span class="vfya-label-sub" style="display:block;margin-top:2px;">Shown in DOB mode. If unchecked by the user, verification expires at session end.</span>
                </div>
              </div>
            </div>

            <div class="vfya-field" style="max-width:280px;">
              <label class="vfya-label">Cookie Duration (days) <span class="vfya-label-sub">Default: 30. Applied when user checks "Remember Me".</span></label>
              <input type="number" class="vfya-input" name="verifyistic_cookie_days" value="<?php echo esc_attr(vfya_opt('verifyistic_cookie_days','30')); ?>" min="1" max="365">
            </div>

            <div class="vfya-field">
              <label class="vfya-label" for="verifyistic_cookie_domain">Cookie Domain <span class="vfya-label-sub">Optional. Set to <code>.example.com</code> (with leading dot) to share the verification cookie between www and apex subdomains. Leave empty to scope to the exact current host only.</span></label>
              <input type="text" class="vfya-input" id="verifyistic_cookie_domain" name="verifyistic_cookie_domain" value="<?php echo esc_attr(vfya_opt('verifyistic_cookie_domain','')); ?>" placeholder=".example.com">
            </div>

            <div class="vfya-section-divider">Advanced Security</div>

            <div class="vfya-field">
              <div class="vfya-toggle-wrap">
                <label class="vfya-toggle">
                  <input type="checkbox" name="verifyistic_strict_mode" value="1" <?php checked(vfya_opt('verifyistic_strict_mode','0'),'1'); ?>>
                  <span class="vfya-toggle-slider"></span>
                </label>
                <div>
                  <span class="vfya-toggle-label">Strict Mode</span>
                  <span class="vfya-label-sub" style="display:block;margin-top:2px;">Treats anti-bot timing token failures as hard blocks instead of soft signals. Recommended only when not behind a page cache (WP Rocket, LiteSpeed, Cloudflare, etc.).</span>
                </div>
              </div>
            </div>

            <div class="vfya-field">
              <div class="vfya-toggle-wrap">
                <label class="vfya-toggle">
                  <input type="checkbox" name="verifyistic_skip_bot_detection" value="1" <?php checked(vfya_opt('verifyistic_skip_bot_detection','0'),'1'); ?>>
                  <span class="vfya-toggle-slider"></span>
                </label>
                <div>
                  <span class="vfya-toggle-label">Disable Bot/Crawler Bypass</span>
                  <span class="vfya-label-sub" style="display:block;margin-top:2px;">By default, known search engine crawlers (Googlebot, Bingbot, etc.) skip the popup so content stays indexable. Enable this to force the popup for every visitor, including bots.</span>
                </div>
              </div>
            </div>

            <div class="vfya-section-divider">Webhook / API Integration</div>

            <div class="vfya-field">
              <div class="vfya-toggle-wrap">
                <label class="vfya-toggle">
                  <input type="checkbox" name="verifyistic_webhook_enabled" value="1" <?php checked(vfya_opt('verifyistic_webhook_enabled','0'),'1'); ?>>
                  <span class="vfya-toggle-slider"></span>
                </label>
                <div>
                  <span class="vfya-toggle-label">Enable Webhook</span>
                  <span class="vfya-label-sub" style="display:block;margin-top:2px;">Sends a JSON POST to your URL on every successful verification. Compatible with Zapier, Make, n8n, Google Sheets (Apps Script), and custom CRMs.</span>
                </div>
              </div>
            </div>

            <div class="vfya-field">
              <label class="vfya-label" for="verifyistic_webhook_url">Webhook URL</label>
              <div class="vfya-input-group">
                <input type="url" class="vfya-input" name="verifyistic_webhook_url" id="verifyistic_webhook_url" placeholder="https://hooks.zapier.com/..." value="<?php echo esc_attr(vfya_opt('verifyistic_webhook_url')); ?>">
                <button type="button" id="vfya-test-webhook" class="vfya-btn vfya-btn-secondary">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:14px;height:14px;"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5"/></svg>
                  Test Webhook
                </button>
              </div>
            </div>

            <div class="vfya-field" style="max-width:480px;">
              <label class="vfya-label" for="verifyistic_webhook_secret">
                Webhook Secret (optional)
                <span class="vfya-label-sub">If set, each request includes an <code>X-Verifyistic-Signature</code> HMAC-SHA256 header for verification.</span>
              </label>
              <input type="text" class="vfya-input" name="verifyistic_webhook_secret" id="verifyistic_webhook_secret" value="<?php echo esc_attr(vfya_opt('verifyistic_webhook_secret')); ?>" placeholder="Your secret key">
            </div>

            <div class="vfya-section-divider">Multiple Webhook Connections <span class="vfya-label-sub" style="font-weight:400;">(new)</span></div>
            <p class="vfya-label-sub" style="margin:-6px 0 14px;max-width:680px;">
              Fan verification data out to several platforms at once — Zapier, Make, n8n, a CRM, Google Sheets (Apps Script), or your own endpoint. Each connection has its own URL, HMAC secret, and event filter. Failed deliveries retry automatically with backoff. Leave <em>Events</em> blank to receive every event, or list any of <code>passed,failed,declined</code>.
            </p>

            <?php
            $vfy_connections = class_exists( 'Verifyistic_Webhooks' ) ? Verifyistic_Webhooks::get_connections() : array();
            if ( empty( $vfy_connections ) ) {
                $vfy_connections = array( array( 'id' => '', 'name' => '', 'url' => '', 'secret' => '', 'platform' => '', 'enabled' => 1, 'events' => array() ) );
            }
            ?>
            <div id="vfy-conn-list">
              <?php foreach ( $vfy_connections as $vfy_c ) :
                  $vfy_c  = wp_parse_args( $vfy_c, array( 'id' => '', 'name' => '', 'url' => '', 'secret' => '', 'platform' => '', 'enabled' => 1, 'events' => array() ) );
                  $vfy_ev = is_array( $vfy_c['events'] ) ? implode( ',', $vfy_c['events'] ) : (string) $vfy_c['events'];
              ?>
              <div class="vfy-conn-row" style="border:1px solid rgba(0,0,0,.1);border-radius:8px;padding:14px;margin-bottom:12px;background:rgba(255,255,255,.5);">
                <input type="hidden" name="vfy_conn_id[]" value="<?php echo esc_attr( $vfy_c['id'] ); ?>">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                  <div>
                    <label class="vfya-label">Connection name</label>
                    <input type="text" class="vfya-input" name="vfy_conn_name[]" placeholder="e.g. Zapier, GoHighLevel, CRM" value="<?php echo esc_attr( $vfy_c['name'] ); ?>">
                  </div>
                  <div>
                    <label class="vfya-label">Status</label>
                    <select class="vfya-input" name="vfy_conn_enabled[]">
                      <option value="1" <?php selected( (int) $vfy_c['enabled'], 1 ); ?>>Enabled</option>
                      <option value="0" <?php selected( (int) $vfy_c['enabled'], 0 ); ?>>Disabled</option>
                    </select>
                  </div>
                </div>
                <div style="margin-top:10px;">
                  <label class="vfya-label">Webhook URL</label>
                  <div class="vfya-input-group">
                    <input type="url" class="vfya-input vfy-conn-url" name="vfy_conn_url[]" placeholder="https://hooks.zapier.com/..." value="<?php echo esc_attr( $vfy_c['url'] ); ?>">
                    <button type="button" class="vfya-btn vfya-btn-secondary vfy-conn-test">Test</button>
                  </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px;">
                  <div>
                    <label class="vfya-label">Secret (optional)</label>
                    <input type="text" class="vfya-input vfy-conn-secret" name="vfy_conn_secret[]" placeholder="HMAC signing secret" value="<?php echo esc_attr( $vfy_c['secret'] ); ?>">
                  </div>
                  <div>
                    <label class="vfya-label">Events (optional)</label>
                    <input type="text" class="vfya-input" name="vfy_conn_events[]" placeholder="passed,failed,declined" value="<?php echo esc_attr( $vfy_ev ); ?>">
                  </div>
                </div>
                <div style="margin-top:10px;text-align:right;">
                  <button type="button" class="vfya-btn vfya-btn-secondary vfy-conn-remove" style="color:#b91c1c;">Remove</button>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
            <button type="button" class="vfya-btn vfya-btn-secondary" id="vfy-conn-add" style="margin-bottom:8px;">+ Add Connection</button>

            <script>
            (function(){
              var list = document.getElementById('vfy-conn-list');
              var add  = document.getElementById('vfy-conn-add');
              if (!list || !add) return;
              function rowTemplate(){
                var r = list.querySelector('.vfy-conn-row');
                var clone = r.cloneNode(true);
                clone.querySelectorAll('input').forEach(function(i){ if (i.type !== 'button') i.value=''; });
                var sel = clone.querySelector('select[name="vfy_conn_enabled[]"]'); if (sel) sel.value='1';
                return clone;
              }
              add.addEventListener('click', function(){ list.appendChild(rowTemplate()); });
              list.addEventListener('click', function(e){
                var t = e.target;
                if (t.classList.contains('vfy-conn-remove')) {
                  var rows = list.querySelectorAll('.vfy-conn-row');
                  if (rows.length > 1) { t.closest('.vfy-conn-row').remove(); }
                  else { t.closest('.vfy-conn-row').querySelectorAll('input').forEach(function(i){ if (i.type!=='button') i.value=''; }); }
                }
                if (t.classList.contains('vfy-conn-test')) {
                  var row = t.closest('.vfy-conn-row');
                  var url = row.querySelector('.vfy-conn-url').value;
                  var secret = row.querySelector('.vfy-conn-secret').value;
                  if (!url) { alert('Enter a URL first.'); return; }
                  var orig = t.textContent; t.textContent='Testing…'; t.disabled=true;
                  jQuery.post((window.verifyisticAdmin||{}).ajaxUrl, {
                    action:'verifyistic_test_webhook',
                    nonce:(window.verifyisticAdmin||{}).nonce,
                    webhook_url:url, webhook_secret:secret
                  }).done(function(res){
                    alert(res && res.success ? (res.data.message||'Webhook test sent!') : (res.data && res.data.message ? res.data.message : 'Webhook test failed.'));
                  }).fail(function(){ alert('Webhook test failed.'); }).always(function(){ t.textContent=orig; t.disabled=false; });
                }
              });
            })();
            </script>

            <!-- Payload preview -->
            <div class="vfya-info-box" style="margin-top:4px;">
              <h4 class="vfya-info-box-title" style="font-size:12.5px;">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M17.25 6.75L22.5 12l-5.25 5.25m-10.5 0L1.5 12l5.25-5.25m7.5-3l-4.5 16.5"/></svg>
                Sample Webhook Payload (JSON)
              </h4>
              <pre style="font-size:11.5px;color:#0f4c3a;background:rgba(255,255,255,.6);border-radius:6px;padding:12px;overflow-x:auto;line-height:1.6;">{
  "event": "Age Verification",
  "id": 42,
  "timestamp": "2025-01-15T14:30:00+00:00",
  "site_url": "https://yoursite.com",
  "verify_type": "DOB",
  "first_name": "John",
  "last_name": "Doe",
  "dob": "1990-05-15",
  "age": 34,
  "status": "passed",
  "ip_address": "192.168.1.1",
  "page_url": "https://yoursite.com/firearms-store/"
}</pre>
            </div>

          </div>
          <div class="vfya-save-bar">
            <span class="vfya-save-info">Changes are saved immediately on submit.</span>
            <button type="submit" class="vfya-btn vfya-btn-primary">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
              Save Settings
            </button>
          </div>
        </div>
      </div>

    </form>
  </div><!-- /body -->

  <!-- Footer -->
  <div class="vfya-footer">
    <div class="vfya-footer-brand">
      <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/></svg>
      <span class="vfy-name">Verifyistic</span>
      <span class="sep">·</span>
      <span class="wps-name">WordPressistic</span>
      <span class="sep">·</span>
      <span style="font-weight:400;color:var(--vfya-light);">v<?php echo esc_html(VERIFYISTIC_VERSION); ?></span>
    </div>
    <div class="vfya-footer-links">
      <a href="https://wordpressistic.com" target="_blank">Website</a>
      <a href="https://wordpressistic.com/support" target="_blank">Support</a>
    </div>
  </div>

</div>
