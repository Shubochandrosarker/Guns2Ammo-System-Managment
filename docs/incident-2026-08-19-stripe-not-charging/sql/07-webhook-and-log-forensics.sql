-- 07 — Webhook + log forensics since 2026-07-19. READ ONLY.

SELECT '--- webhook health options (both Stripe consumers) ---' AS section;
SELECT option_name, option_value
  FROM wp_options
 WHERE option_name LIKE '%webhook_last_%'
 ORDER BY option_name;

SELECT '--- durable webhook event table (exists only if the 2026-07-20 migration ran) ---' AS section;
SELECT gateway, event_type, status, COUNT(*) AS n,
       MIN(received_at) AS first_seen, MAX(received_at) AS last_seen
  FROM wp_g2ab_webhook_events
 GROUP BY gateway, event_type, status
 ORDER BY last_seen DESC;

SELECT '--- failed webhook events, most recent first ---' AS section;
SELECT id, gateway, event_id, event_type, status, attempts,
       LEFT(last_error, 300) AS last_error, received_at, processed_at
  FROM wp_g2ab_webhook_events
 WHERE status <> 'processed'
 ORDER BY id DESC
 LIMIT 100;

SELECT '--- payment-relevant log entries since the incident window opened ---' AS section;
SELECT id, booking_id, event_type, severity, LEFT(message, 300) AS message,
       LEFT(context, 300) AS context, created_at
  FROM wp_g2ab_logs
 WHERE created_at >= '2026-07-19 00:00:00'
   AND (event_type LIKE 'webhook_%'
        OR event_type IN ('payment_manual_review','payment_intent_failed',
                          'payment_amount_mismatch','status_changed','created')
        OR message LIKE '%stripe%' OR message LIKE '%gateway%'
        OR message LIKE '%pay_in_store%')
 ORDER BY id DESC
 LIMIT 500;

SELECT '--- log volume by day and type (find the exact behaviour change date) ---' AS section;
SELECT DATE(created_at) AS day, event_type, severity, COUNT(*) AS n
  FROM wp_g2ab_logs
 WHERE created_at >= '2026-07-10 00:00:00'
 GROUP BY day, event_type, severity
 ORDER BY day, n DESC;
