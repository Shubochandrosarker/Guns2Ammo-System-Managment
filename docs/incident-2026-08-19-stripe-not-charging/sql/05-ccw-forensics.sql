-- 05 — CCW / training / event forensics. READ ONLY. Emails masked.
-- The client identified 3 CCW registrations that were never charged.

SELECT '--- every event booking since 2026-07-01, masked ---' AS section;
SELECT b.id                                         AS booking_id,
       b.uuid                                       AS booking_uuid,
       b.created_at                                 AS booking_date,
       e.title                                      AS course,
       o.start_at                                   AS occurrence_start,
       CONCAT(LEFT(b.customer_email,1), '***@',
              LEFT(SUBSTRING_INDEX(b.customer_email,'@',-1),1), '***') AS customer_masked,
       JSON_UNQUOTE(JSON_EXTRACT(b.metadata,'$.unit_price')) AS unit_price,
       b.party_size                                 AS seats,
       b.total_amount                               AS price_stored,
       b.paid_amount,
       b.payment_mode,
       JSON_UNQUOTE(JSON_EXTRACT(b.metadata,'$.gateway')) AS gateway,
       b.status                                     AS booking_status,
       p.status                                     AS payment_status,
       p.transaction_id                             AS stripe_ref,
       p.gateway                                    AS ledger_gateway
  FROM wp_g2ab_bookings b
  LEFT JOIN wp_g2ab_events e             ON e.id = b.event_id
  LEFT JOIN wp_g2ab_event_occurrences o  ON o.id = b.event_occurrence_id
  LEFT JOIN wp_g2ab_payments p
    ON p.id = (SELECT MAX(p2.id) FROM wp_g2ab_payments p2 WHERE p2.booking_id = b.id)
 WHERE b.event_id IS NOT NULL AND b.event_id > 0
   AND b.created_at >= '2026-07-01 00:00:00'
 ORDER BY b.created_at;

SELECT '--- CCW specifically: paid course seats with NO successful payment ---' AS section;
SELECT b.id, b.uuid, e.title AS course, b.start_at, b.total_amount, b.status,
       b.payment_mode, b.source, b.created_at
  FROM wp_g2ab_bookings b
  JOIN wp_g2ab_events e ON e.id = b.event_id
  LEFT JOIN wp_g2ab_payments p
    ON p.booking_id = b.id AND p.status IN ('succeeded','captured','paid','completed')
 WHERE b.total_amount > 0
   AND p.id IS NULL
   AND b.status NOT IN ('cancelled','expired','payment_failed')
   AND (e.title LIKE '%CCW%' OR e.title LIKE '%carry%' OR e.title LIKE '%Carry%')
 ORDER BY b.created_at;

SELECT '--- email + confirmation trail for those seats (does wp_g2ab_email_log exist?) ---' AS section;
SELECT l.booking_id, l.event_type, l.severity, LEFT(l.message, 200) AS message, l.created_at
  FROM wp_g2ab_logs l
  JOIN wp_g2ab_bookings b ON b.id = l.booking_id
 WHERE b.event_id IS NOT NULL AND b.event_id > 0
   AND l.created_at >= '2026-07-01 00:00:00'
 ORDER BY l.created_at;
