-- 06 — Financial impact, bucketed. READ ONLY.
-- ONLY bucket 2 (UNCOLLECTED_PAYABLE) is exposure. Buckets 1, 3 and 4 are not loss.

SELECT '--- bucketed totals, web bookings created 2026-07-21 → today ---' AS section;
SELECT bucket,
       IF(kind = 'event', 'event/class', 'lane') AS kind,
       COUNT(*)                      AS bookings,
       ROUND(SUM(expected), 2)       AS expected_revenue,
       ROUND(SUM(charged), 2)        AS actually_charged,
       ROUND(SUM(expected - charged), 2) AS unpaid
  FROM (
    SELECT b.id,
           IF(b.event_id IS NULL OR b.event_id = 0, 'lane', 'event') AS kind,
           b.total_amount AS expected,
           COALESCE((SELECT SUM(p.amount) FROM wp_g2ab_payments p
                      WHERE p.booking_id = b.id
                        AND p.status IN ('succeeded','captured','paid','completed')), 0) AS charged,
           CASE
             WHEN b.status = 'cancelled' THEN '4_CANCELLED'
             WHEN b.status IN ('pending','expired','payment_failed') THEN '3_ABANDONED_CHECKOUT'
             WHEN b.total_amount = 0 THEN '1_LEGITIMATE_FREE'
             WHEN COALESCE((SELECT COUNT(*) FROM wp_g2ab_payments p
                             WHERE p.booking_id = b.id
                               AND p.status IN ('succeeded','captured','paid','completed')), 0) = 0
               THEN '2_UNCOLLECTED_PAYABLE'
             ELSE '0_OK_PAID'
           END AS bucket
      FROM wp_g2ab_bookings b
     WHERE b.created_at >= '2026-07-21 00:00:00'
       AND b.source = 'web'
  ) x
 GROUP BY bucket, kind
 WITH ROLLUP;

SELECT '--- CCW subset of bucket 2 ---' AS section;
SELECT COUNT(*) AS ccw_affected,
       ROUND(SUM(b.total_amount), 2) AS ccw_expected,
       0 AS ccw_charged,
       ROUND(SUM(b.total_amount), 2) AS ccw_unpaid
  FROM wp_g2ab_bookings b
  JOIN wp_g2ab_events e ON e.id = b.event_id
  LEFT JOIN wp_g2ab_payments p
    ON p.booking_id = b.id AND p.status IN ('succeeded','captured','paid','completed')
 WHERE b.total_amount > 0 AND p.id IS NULL
   AND b.status NOT IN ('cancelled','expired','payment_failed','pending')
   AND (e.title LIKE '%CCW%' OR e.title LIKE '%arry%');

SELECT '--- control: same buckets for 2026-06-21 → 2026-07-20 (the working period) ---' AS section;
SELECT IF(b.payment_mode = 'in_store', 'in_store', b.payment_mode) AS payment_mode,
       COUNT(*) AS bookings, ROUND(SUM(b.total_amount),2) AS total_amount,
       ROUND(SUM(b.paid_amount),2) AS paid_amount
  FROM wp_g2ab_bookings b
 WHERE b.created_at >= '2026-06-21' AND b.created_at < '2026-07-21' AND b.source = 'web'
 GROUP BY payment_mode;
