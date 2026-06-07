<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
$total_verif  = Verifyistic_DB::get_count();
$total_passed = Verifyistic_DB::get_count( 'passed' );
$total_failed = Verifyistic_DB::get_count( 'failed' );
$today_count  = Verifyistic_DB::get_today_count();
$enabled      = get_option( 'verifyistic_enabled', '1' );
$mode         = get_option( 'verifyistic_mode', 'dob' );
$id_mode      = (int) get_option( 'verifyistic_id_verification', 0 );
if ( $id_mode ) { $mode = 'id_face'; }
$min_age      = get_option( 'verifyistic_min_age', '21' );
$recent_logs  = Verifyistic_DB::get_logs( array( 'limit' => 8 ) );

$mode_labels = array(
    'dob'     => 'Date of Birth',
    'yes_no'  => 'Yes / No',
    'id_face' => 'ID & Face',
);
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
          <h1 class="vfya-header-title">Verifyistic<span> · Age Verification</span></h1>
          <p class="vfya-header-sub">Advanced age verification for firearms, alcohol, cannabis & adult websites &nbsp;·&nbsp; Built by WordPressistic</p>
        </div>
      </div>
      <div class="vfya-header-badges">
        <span class="vfya-badge vfya-badge-version">v<?php echo esc_html( VERIFYISTIC_VERSION ); ?></span>
        <span class="vfya-badge vfya-badge-by">
          <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418"/>
          </svg>
          WordPressistic
        </span>
      </div>
    </div>
  </div>

  <!-- Navigation -->
  <nav class="vfya-nav">
    <a href="<?php echo admin_url('admin.php?page=verifyistic'); ?>" class="active">
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
      Dashboard
    </a>
    <a href="<?php echo admin_url('admin.php?page=verifyistic-settings'); ?>">
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

    <!-- Stats Row -->
    <div class="vfya-stats">
      <div class="vfya-stat-card teal">
        <div class="vfya-stat-icon teal">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
        <div class="vfya-stat-data">
          <div class="vfya-stat-number"><?php echo number_format($total_verif); ?></div>
          <div class="vfya-stat-label">Total Verifications</div>
        </div>
      </div>
      <div class="vfya-stat-card indigo">
        <div class="vfya-stat-icon indigo">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>
        </div>
        <div class="vfya-stat-data">
          <div class="vfya-stat-number"><?php echo number_format($today_count); ?></div>
          <div class="vfya-stat-label">Verified Today</div>
        </div>
      </div>
      <div class="vfya-stat-card green">
        <div class="vfya-stat-icon green">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/></svg>
        </div>
        <div class="vfya-stat-data">
          <div class="vfya-stat-number"><?php echo number_format($total_passed); ?></div>
          <div class="vfya-stat-label">Passed</div>
        </div>
      </div>
      <div class="vfya-stat-card red">
        <div class="vfya-stat-icon red">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
        </div>
        <div class="vfya-stat-data">
          <div class="vfya-stat-number"><?php echo number_format($total_failed); ?></div>
          <div class="vfya-stat-label">Failed / Declined</div>
        </div>
      </div>
    </div>

    <!-- Content Grid -->
    <div class="vfya-grid">

      <!-- Recent Logs -->
      <div class="vfya-card">
        <div class="vfya-card-header">
          <h3 class="vfya-card-title">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            Recent Verifications
          </h3>
          <a href="<?php echo admin_url('admin.php?page=verifyistic-logs'); ?>" class="vfya-btn vfya-btn-ghost" style="font-size:12.5px;padding:6px 12px;">View All →</a>
        </div>
        <div class="vfya-table-wrap">
          <table class="vfya-table">
            <thead>
              <tr>
                <th>Name / IP</th>
                <th>Type</th>
                <th>Age</th>
                <th>Status</th>
                <th>Date</th>
              </tr>
            </thead>
            <tbody>
              <?php if ( empty( $recent_logs ) ) : ?>
              <tr>
                <td colspan="5" class="vfya-table-empty">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z"/></svg>
                  No verifications yet. Your logs will appear here.
                </td>
              </tr>
              <?php else : foreach ( $recent_logs as $log ) :
                $name = trim( $log->first_name . ' ' . $log->last_name ) ?: $log->ip_address;
              ?>
              <tr>
                <td><strong><?php echo esc_html( $name ); ?></strong></td>
                <td><span class="vfya-pill vfya-pill-<?php echo esc_attr($log->verify_type); ?>"><?php echo esc_html( $mode_labels[$log->verify_type] ?? $log->verify_type ); ?></span></td>
                <td><?php echo $log->age_at_verify ? esc_html($log->age_at_verify) . ' yrs' : '—'; ?></td>
                <td><span class="vfya-pill vfya-pill-<?php echo esc_attr($log->status); ?>"><?php echo esc_html(ucfirst($log->status)); ?></span></td>
                <td style="color:var(--vfya-muted);font-size:12px;"><?php echo esc_html( date( 'M j, Y g:i a', strtotime($log->verified_at) ) ); ?></td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Right Sidebar -->
      <div style="display:flex;flex-direction:column;gap:20px;">

        <!-- Status Panel -->
        <div class="vfya-card">
          <div class="vfya-card-header">
            <h3 class="vfya-card-title">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"/></svg>
              Plugin Status
            </h3>
          </div>
          <div class="vfya-card-body">
            <div class="vfya-status-panel">
              <div class="vfya-status-row">
                <span class="vfya-status-label">Status</span>
                <?php if ($enabled) : ?>
                  <span class="vfya-status-val" style="color:var(--vfya-success);display:flex;align-items:center;gap:5px;"><span class="vfya-dot vfya-dot-green"></span>Active</span>
                <?php else : ?>
                  <span class="vfya-status-val" style="color:var(--vfya-danger);display:flex;align-items:center;gap:5px;"><span class="vfya-dot vfya-dot-red"></span>Disabled</span>
                <?php endif; ?>
              </div>
              <div class="vfya-status-row">
                <span class="vfya-status-label">Minimum Age</span>
                <span class="vfya-status-val"><?php echo esc_html($min_age); ?>+</span>
              </div>
              <div class="vfya-status-row">
                <span class="vfya-status-label">Verify Mode</span>
                <span class="vfya-status-val"><?php echo esc_html($mode_labels[$mode] ?? $mode); ?></span>
              </div>
              <div class="vfya-status-row">
                <span class="vfya-status-label">Webhook</span>
                <?php $wh = get_option('verifyistic_webhook_enabled','0'); ?>
                <span class="vfya-status-val" style="display:flex;align-items:center;gap:5px;"><span class="vfya-dot <?php echo $wh ? 'vfya-dot-green' : 'vfya-dot-yellow'; ?>"></span><?php echo $wh ? 'Enabled' : 'Off'; ?></span>
              </div>
              <div class="vfya-status-row">
                <span class="vfya-status-label">Cookie Days</span>
                <span class="vfya-status-val"><?php echo esc_html(get_option('verifyistic_cookie_days', 30)); ?> days</span>
              </div>
            </div>
            <div style="margin-top:16px;">
              <a href="<?php echo admin_url('admin.php?page=verifyistic-settings'); ?>" class="vfya-btn vfya-btn-primary" style="width:100%;justify-content:center;">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:14px;height:14px;"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10"/></svg>
                Edit Settings
              </a>
            </div>
          </div>
        </div>

        <!-- Quick Start -->
        <div class="vfya-info-box">
          <h4 class="vfya-info-box-title">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18v-5.25m0 0a6.01 6.01 0 001.5-.189m-1.5.189a6.01 6.01 0 01-1.5-.189m3.75 7.478a12.06 12.06 0 01-4.5 0m3.75 2.383a14.406 14.406 0 01-3 0M14.25 18v-.192c0-.983.658-1.823 1.508-2.316a7.5 7.5 0 10-7.517 0c.85.493 1.509 1.333 1.509 2.316V18"/></svg>
            Quick Start Guide
          </h4>
          <div class="vfya-steps">
            <div class="vfya-step"><span class="vfya-step-num">1</span><span>Go to <strong>Settings</strong> and configure your minimum age requirement.</span></div>
            <div class="vfya-step"><span class="vfya-step-num">2</span><span>Upload your client's logo and customize popup colors to match their brand.</span></div>
            <div class="vfya-step"><span class="vfya-step-num">3</span><span>Choose a verification mode: <em>Date of Birth</em>, <em>Yes/No</em>, or <em>ID &amp; Face</em>.</span></div>
            <div class="vfya-step"><span class="vfya-step-num">4</span><span>Optionally add a Webhook URL for CRM/Google Sheets integration.</span></div>
            <div class="vfya-step"><span class="vfya-step-num">5</span><span>Enable the plugin and test on your frontend. All done!</span></div>
          </div>
        </div>

      </div><!-- /right sidebar -->
    </div><!-- /grid -->
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
      <a href="https://wordpress.org/plugins/verifyistic/" target="_blank">Rate Plugin ★</a>
    </div>
  </div>

</div>
