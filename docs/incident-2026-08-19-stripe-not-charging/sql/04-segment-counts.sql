-- 04 — Counts since 2026-07-20, segmented. READ ONLY.

SELECT '--- by day / status / payment_mode ---' AS section;
SELECT DATE(b.created_at) AS day,
       b.status,
       b.payment_mode,
       b.source,
       COUNT(*)                       AS bookings,
       ROUND(SUM(b.total_amount), 2)  AS total_amount,
       ROUND(SUM(b.paid_amount), 2)   AS paid_amount
  FROM wp_g2ab_bookings b
 WHERE b.created_at >= '2026-07-01 00:00:00'
 GROUP BY day, b.status, b.payment_mode, b.source
 ORDER BY day, b.status;

SELECT '--- by booking kind (lane vs event) ---' AS section;
SELECT IF(b.event_id IS NULL OR b.event_id = 0, 'lane', 'event') AS kind,
       b.status, b.payment_mode,
       COUNT(*) AS bookings,
       ROUND(SUM(b.total_amount), 2) AS total_amount,
       ROUND(SUM(b.paid_amount), 2)  AS paid_amount
  FROM wp_g2ab_bookings b
 WHERE b.created_at >= '2026-07-20 00:00:00'
 GROUP BY kind, b.status, b.payment_mode
 ORDER BY kind, b.status;

SELECT '--- by gateway recorded in metadata ---' AS section;
SELECT COALESCE(JSON_UNQUOTE(JSON_EXTRACT(b.metadata,'$.gateway')), '(none)') AS gateway,
       COUNT(*) AS bookings,
       ROUND(SUM(b.total_amount), 2) AS total_amount
  FROM wp_g2ab_bookings b
 WHERE b.created_at >= '2026-07-20 00:00:00' AND b.source = 'web'
 GROUP BY gateway
 ORDER BY bookings DESC;

SELECT '--- payment ledger rows by gateway + status ---' AS section;
SELECT p.gateway, p.status, COUNT(*) AS rows_, ROUND(SUM(p.amount),2) AS amount
  FROM wp_g2ab_payments p
 WHERE p.created_at >= '2026-07-01 00:00:00'
 GROUP BY p.gateway, p.status
 ORDER BY p.gateway, p.status;
