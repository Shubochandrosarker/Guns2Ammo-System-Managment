<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
$per_page    = 20;
$current_page = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
$offset       = ( $current_page - 1 ) * $per_page;
$search       = sanitize_text_field( $_GET['s'] ?? '' );
$status_filter= sanitize_text_field( $_GET['status'] ?? '' );

$args = array(
    'limit'  => $per_page,
    'offset' => $offset,
    'search' => $search,
    'status' => $status_filter,
);

$logs      = Verifyistic_DB::get_logs( $args );
$total     = Verifyistic_DB::get_count( $status_filter );
$total_pages = ceil( $total / $per_page );

$mode_labels = array(
    'dob'     => 'Date of Birth',
    'yes_no'  => 'Yes / No',
    'id_face' => 'ID & Face',
);

$base_url = admin_url('admin.php?page=verifyistic-logs');
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
          <h1 class="vfya-header-title">Verifyistic<span> · Verification Logs</span></h1>
          <p class="vfya-header-sub">Full audit trail of all age verification attempts &nbsp;·&nbsp; Built by WordPressistic</p>
        </div>
      </div>
      <div class="vfya-header-badges">
        <span class="vfya-badge vfya-badge-version"><?php echo number_format($total); ?> total records</span>
      </div>
    </div>
  </div>

  <!-- Navigation -->
  <nav class="vfya-nav">
    <a href="<?php echo admin_url('admin.php?page=verifyistic'); ?>">
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"/></svg>
      Dashboard
    </a>
    <a href="<?php echo admin_url('admin.php?page=verifyistic-settings'); ?>">
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
      Settings
    </a>
    <a href="<?php echo admin_url('admin.php?page=verifyistic-logs'); ?>" class="active">
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>
      Verification Logs
    </a>
  </nav>

  <!-- Body -->
  <div class="vfya-body">

    <!-- Toolbar -->
    <div class="vfya-card" style="margin-bottom:20px;">
      <div class="vfya-card-body" style="padding:16px 20px;">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">

          <!-- Search & Filter -->
          <form method="GET" action="" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <input type="hidden" name="page" value="verifyistic-logs">
            <input type="text" class="vfya-input" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search name or IP…" style="min-width:180px;width:200px;">
            <select class="vfya-select" name="status" style="width:auto;">
              <option value="">All Statuses</option>
              <option value="passed"   <?php selected($status_filter,'passed'); ?>>Passed</option>
              <option value="failed"   <?php selected($status_filter,'failed'); ?>>Failed</option>
              <option value="declined" <?php selected($status_filter,'declined'); ?>>Declined</option>
            </select>
            <button type="submit" class="vfya-btn vfya-btn-secondary">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:14px;height:14px;"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
              Filter
            </button>
            <?php if ($search || $status_filter) : ?>
              <a href="<?php echo esc_url($base_url); ?>" class="vfya-btn vfya-btn-ghost">Clear</a>
            <?php endif; ?>
          </form>

          <!-- Actions -->
          <div class="vfya-btn-group">
            <button type="button" id="vfya-export-csv" class="vfya-btn vfya-btn-secondary">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:14px;height:14px;"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
              Export CSV
            </button>
            <button type="button" id="vfya-clear-logs" class="vfya-btn vfya-btn-danger">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:14px;height:14px;"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>
              Clear All Logs
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Table -->
    <div class="vfya-card">
      <div class="vfya-table-wrap">
        <table class="vfya-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Name</th>
              <th>Type</th>
              <th>Age</th>
              <th>DOB</th>
              <th>Status</th>
              <th>IP Address</th>
              <th>Webhook</th>
              <th>Date</th>
              <th>Time</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="vfya-logs-tbody">
            <?php if ( empty( $logs ) ) : ?>
            <tr>
              <td colspan="11" class="vfya-table-empty">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/>
                </svg>
                <?php echo $search || $status_filter ? 'No logs match your search.' : 'No verification logs yet. They will appear here once users verify their age.'; ?>
              </td>
            </tr>
            <?php else : foreach ($logs as $log) :
              $name = trim($log->first_name . ' ' . $log->last_name) ?: '—';
            ?>
            <tr>
              <td style="color:var(--vfya-light);font-size:12px;"><?php echo esc_html($log->id); ?></td>
              <td>
                <strong style="font-size:13px;"><?php echo esc_html($name); ?></strong>
              </td>
              <td><span class="vfya-pill vfya-pill-<?php echo esc_attr($log->verify_type); ?>"><?php echo esc_html($mode_labels[$log->verify_type] ?? $log->verify_type); ?></span></td>
              <td><?php echo $log->age_at_verify ? esc_html($log->age_at_verify) . ' yrs' : '—'; ?></td>
              <td style="font-size:12px;color:var(--vfya-muted);"><?php echo $log->dob ? esc_html(date('M j, Y', strtotime($log->dob))) : '—'; ?></td>
              <td><span class="vfya-pill vfya-pill-<?php echo esc_attr($log->status); ?>"><?php echo esc_html(ucfirst($log->status)); ?></span></td>
              <td style="font-size:12px;color:var(--vfya-muted);font-family:monospace;"><?php echo esc_html($log->ip_address); ?></td>
              <td>
                <?php if ($log->webhook_sent) : ?>
                  <span style="color:var(--vfya-success);font-size:12px;font-weight:600;">✓ Sent</span>
                <?php else : ?>
                  <span style="color:var(--vfya-light);font-size:12px;">—</span>
                <?php endif; ?>
              </td>
              <td style="font-size:12px;color:var(--vfya-muted);white-space:nowrap;"><?php echo esc_html( date( 'M j, Y', strtotime( $log->verified_at ) ) ); ?></td>
              <td style="font-size:12px;color:var(--vfya-muted);white-space:nowrap;"><?php echo esc_html( date( 'g:i a', strtotime( $log->verified_at ) ) ); ?></td>
              <td>
                <button type="button" class="vfya-btn vfya-btn-ghost vfya-delete-log" data-id="<?php echo esc_attr($log->id); ?>" style="padding:5px 10px;color:var(--vfya-danger);" title="Delete">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:14px;height:14px;"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>
                </button>
              </td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <?php if ($total_pages > 1) : ?>
      <div class="vfya-pagination">
        <div class="vfya-pagination-info">
          Showing <?php echo ($offset + 1); ?>–<?php echo min($offset + $per_page, $total); ?> of <?php echo number_format($total); ?> records
        </div>
        <div class="vfya-pagination-pages">
          <?php if ($current_page > 1) : ?>
            <a href="<?php echo esc_url( add_query_arg( array('paged' => $current_page - 1, 's' => $search, 'status' => $status_filter), $base_url ) ); ?>">‹</a>
          <?php endif; ?>
          <?php for ($p = max(1, $current_page - 2); $p <= min($total_pages, $current_page + 2); $p++) : ?>
            <?php if ($p === $current_page) : ?>
              <span class="current"><?php echo $p; ?></span>
            <?php else : ?>
              <a href="<?php echo esc_url( add_query_arg( array('paged' => $p, 's' => $search, 'status' => $status_filter), $base_url ) ); ?>"><?php echo $p; ?></a>
            <?php endif; ?>
          <?php endfor; ?>
          <?php if ($current_page < $total_pages) : ?>
            <a href="<?php echo esc_url( add_query_arg( array('paged' => $current_page + 1, 's' => $search, 'status' => $status_filter), $base_url ) ); ?>">›</a>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>

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
