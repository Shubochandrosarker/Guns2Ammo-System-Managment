<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
$logo        = get_option( 'verifyistic_logo', '' );
$heading     = get_option( 'verifyistic_heading_text', 'Age Verification Required' );
$message     = get_option( 'verifyistic_message_text', 'You must be 21 years of age or older to enter this website.' );
$min_age     = (int) get_option( 'verifyistic_min_age', 21 );
$mode        = get_option( 'verifyistic_mode', 'dob' );
$id_mode     = get_option( 'verifyistic_id_verification', '0' );
$remember_me = get_option( 'verifyistic_remember_me', '1' );
$btn_yes     = get_option( 'verifyistic_btn_yes_text', 'Yes, I am 21+' );
$btn_no      = get_option( 'verifyistic_btn_no_text', 'No, Exit' );
$privacy     = get_option( 'verifyistic_privacy_text', '' );
$id_label    = get_option( 'verifyistic_id_upload_label', 'Upload a valid government-issued ID' );
$selfie_label= get_option( 'verifyistic_selfie_label', 'Upload a selfie holding your ID' );

// If ID verification is on, override mode
if ( $id_mode ) $mode = 'id_face';

// Format min_age label
$age_label = $min_age . '+';
?>

<div id="verifyistic-overlay" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr($heading); ?>" style="display:none;">
  <div id="verifyistic-popup">

    <div class="vfy-inner">

      <!-- Anti-bot guard: honeypot (must stay empty) + signed timing token.
           Shared across all modes; the JS appends them to every request. -->
      <input type="text" id="vfy-hp" name="vfy_hp" class="vfy-hp" tabindex="-1" autocomplete="off" aria-hidden="true" value="">
      <input type="hidden" id="vfy-form-token" value="<?php echo esc_attr( Verifyistic_Security::issue_form_token() ); ?>">

      <!-- Logo or Shield Icon -->
      <?php if ( $logo ) : ?>
        <div class="vfy-logo-wrap">
          <img src="<?php echo esc_url($logo); ?>" alt="<?php echo esc_attr(get_bloginfo('name')); ?> Logo">
        </div>
      <?php else : ?>
        <div class="vfy-shield-icon" aria-hidden="true">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/>
          </svg>
        </div>
      <?php endif; ?>

      <!-- Age Badge -->
      <div class="vfy-age-badge">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" style="width:11px;height:11px;"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
        Age <?php echo esc_html($age_label); ?> Required
      </div>

      <!-- Heading & Message -->
      <h2 class="vfy-heading"><?php echo esc_html($heading); ?></h2>
      <p class="vfy-message"><?php echo wp_kses_post($message); ?></p>

      <div class="vfy-divider"></div>

      <!-- ── Mode: Yes / No ────────────────────────────────── -->
      <?php if ( $mode === 'yes_no' ) : ?>
        <div id="vfy-yn-content">
          <div class="vfy-btn-row" role="group" aria-label="Age confirmation">
            <button type="button" id="vfy-btn-yes" class="vfy-btn-yes" aria-label="<?php echo esc_attr($btn_yes); ?>">
              <svg class="vfy-btn-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
              <?php echo esc_html($btn_yes); ?>
            </button>
            <button type="button" id="vfy-btn-no" class="vfy-btn-no" aria-label="<?php echo esc_attr($btn_no); ?>">
              <svg class="vfy-btn-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
              <?php echo esc_html($btn_no); ?>
            </button>
          </div>
        </div>
        <div class="vfy-success-state" id="vfy-success-yn">
          <div class="vfy-success-icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div>
          <div class="vfy-success-title">Welcome!</div>
          <p class="vfy-success-msg">Thank you for verifying. Enjoy your visit.</p>
        </div>

      <?php elseif ( $mode === 'dob' ) : ?>
      <!-- ── Mode: Date of Birth ──────────────────────────── -->
      <form id="vfy-verify-form" novalidate>
        <div class="vfy-error" role="alert">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
          <span class="vfy-error-text"></span>
        </div>

        <div class="vfy-form-row">
          <div class="vfy-form-group">
            <label class="vfy-form-label" for="vfy-first-name">First Name</label>
            <input type="text" class="vfy-form-input" id="vfy-first-name" name="first_name" placeholder="John" autocomplete="given-name">
          </div>
          <div class="vfy-form-group">
            <label class="vfy-form-label" for="vfy-last-name">Last Name</label>
            <input type="text" class="vfy-form-input" id="vfy-last-name" name="last_name" placeholder="Doe" autocomplete="family-name">
          </div>
        </div>

        <div class="vfy-form-group">
          <label class="vfy-form-label" for="vfy-dob-month">Date of Birth <span class="vfy-required">*</span></label>
          <?php
          $vfy_now_year = (int) date( 'Y' );
          $vfy_months   = array( 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec' );
          ?>
          <div class="vfy-dob-row">
            <select class="vfy-form-input vfy-dob-part" id="vfy-dob-month" aria-label="Birth month" autocomplete="bday-month">
              <option value="">Month</option>
              <?php foreach ( $vfy_months as $vfy_i => $vfy_m ) : ?>
                <option value="<?php echo esc_attr( $vfy_i + 1 ); ?>"><?php echo esc_html( $vfy_m ); ?></option>
              <?php endforeach; ?>
            </select>
            <select class="vfy-form-input vfy-dob-part" id="vfy-dob-day" aria-label="Birth day" autocomplete="bday-day">
              <option value="">Day</option>
              <?php for ( $vfy_d = 1; $vfy_d <= 31; $vfy_d++ ) : ?>
                <option value="<?php echo esc_attr( $vfy_d ); ?>"><?php echo esc_html( $vfy_d ); ?></option>
              <?php endfor; ?>
            </select>
            <select class="vfy-form-input vfy-dob-part" id="vfy-dob-year" aria-label="Birth year" autocomplete="bday-year">
              <option value="">Year</option>
              <?php for ( $vfy_y = $vfy_now_year; $vfy_y >= $vfy_now_year - 100; $vfy_y-- ) : ?>
                <option value="<?php echo esc_attr( $vfy_y ); ?>"><?php echo esc_html( $vfy_y ); ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <input type="hidden" id="vfy-dob" name="dob" value="">
        </div>

        <?php if ( $remember_me ) : ?>
        <label class="vfy-remember">
          <input type="checkbox" class="vfy-remember-checkbox" checked>
          <span class="vfy-remember-label">Remember me for <?php echo esc_html(get_option('verifyistic_cookie_days', 30)); ?> days</span>
        </label>
        <?php endif; ?>

        <button type="submit" class="vfy-btn-submit">
          <svg class="vfy-btn-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          Verify My Age &amp; Enter
        </button>

        <div class="vfy-success-state">
          <div class="vfy-success-icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div>
          <div class="vfy-success-title">Age Verified!</div>
          <p class="vfy-success-msg">Welcome. You'll be redirected shortly.</p>
        </div>
      </form>

      <?php else : ?>
      <!-- ── Mode: ID & Face ──────────────────────────────── -->
      <form id="vfy-verify-form" novalidate enctype="multipart/form-data">
        <div class="vfy-error" role="alert">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
          <span class="vfy-error-text"></span>
        </div>

        <div class="vfy-form-row">
          <div class="vfy-form-group">
            <label class="vfy-form-label" for="vfy-id-first">First Name</label>
            <input type="text" class="vfy-form-input" id="vfy-id-first" name="first_name" placeholder="John" autocomplete="given-name">
          </div>
          <div class="vfy-form-group">
            <label class="vfy-form-label" for="vfy-id-last">Last Name</label>
            <input type="text" class="vfy-form-input" id="vfy-id-last" name="last_name" placeholder="Doe" autocomplete="family-name">
          </div>
        </div>

        <div class="vfy-form-group">
          <label class="vfy-form-label"><?php echo esc_html($id_label); ?> <span class="vfy-required">*</span></label>
          <div class="vfy-upload-area" role="button" tabindex="0" aria-label="Upload ID document">
            <input type="file" class="vfy-file-input" name="id_file" accept="image/jpeg,image/png,image/gif,image/webp" required aria-required="true">
            <svg class="vfy-upload-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z"/></svg>
            <div class="vfy-upload-text"><span>Click to upload</span> or drag here</div>
            <div class="vfy-upload-preview"></div>
          </div>
        </div>

        <div class="vfy-form-group">
          <label class="vfy-form-label"><?php echo esc_html($selfie_label); ?> <span class="vfy-required">*</span></label>
          <div class="vfy-upload-area" role="button" tabindex="0" aria-label="Upload selfie with ID">
            <input type="file" class="vfy-file-input" name="selfie_file" accept="image/jpeg,image/png,image/gif" required aria-required="true">
            <svg class="vfy-upload-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z"/><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0zM18.75 10.5h.008v.008h-.008V10.5z"/></svg>
            <div class="vfy-upload-text"><span>Click to upload</span> photo with ID visible</div>
            <div class="vfy-upload-preview"></div>
          </div>
        </div>

        <?php if ( $remember_me ) : ?>
        <label class="vfy-remember">
          <input type="checkbox" class="vfy-remember-checkbox" checked>
          <span class="vfy-remember-label">Remember me for <?php echo esc_html(get_option('verifyistic_cookie_days', 30)); ?> days</span>
        </label>
        <?php endif; ?>

        <button type="submit" class="vfy-btn-submit">
          <svg class="vfy-btn-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/></svg>
          Submit for Verification
        </button>

        <div class="vfy-success-state">
          <div class="vfy-success-icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div>
          <div class="vfy-success-title">Documents Received!</div>
          <p class="vfy-success-msg">Your ID is being reviewed. You may now enter.</p>
        </div>
      </form>
      <?php endif; ?>

      <!-- Privacy Text -->
      <?php if ( $privacy ) : ?>
      <p class="vfy-privacy"><?php echo wp_kses_post($privacy); ?></p>
      <?php endif; ?>

    </div><!-- /vfy-inner -->

    <!-- Powered By -->
    <a href="https://wordpressistic.com" class="vfy-powered" target="_blank" rel="noopener" tabindex="-1" aria-hidden="true">
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/></svg>
      Protected by Verifyistic · WordPressistic
    </a>

  </div><!-- /verifyistic-popup -->
</div><!-- /verifyistic-overlay -->
