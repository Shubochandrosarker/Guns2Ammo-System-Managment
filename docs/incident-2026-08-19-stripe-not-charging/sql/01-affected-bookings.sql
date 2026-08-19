-- 01 — The affected population. READ ONLY.
-- Every PUBLIC WEB booking since 2026-07-20 that was payable but routed away
-- from Stripe, or zeroed without a Memberistic entitlement.

SELECT '--- A. web bookings routed to pay-in-store (RC-1 / RC-2) ---' AS section;
SELECT b.id, b.uuid, b.customer_email, b.start_at, b.total_amount, b.paid_amount,
       b.status, b.payment_mode, b.source,
       JSON_UNQUOTE(JSON_EXTRACT(b.metadata,'$.gateway')) AS gateway,
       JSON_UNQUOTE(JSON_EXTRACT(b.metadata,'$.due_now')) AS due_now,
       bt.name AS booking_type, b.event_id, b.created_at
  FROM wp_g2ab_bookings b
  LEFT JOIN wp_g2ab_booking_types bt ON bt.id = b.booking_type_id
 WHERE b.created_at >= '2026-07-20 00:00:00'
   AND b.source = 'web'
   AND b.payment_mode = 'in_store'
 ORDER BY b.created_at;

SELECT '--- B. web bookings that resolved to $0 with no Memberistic entitlement (RC-4 / H12) ---' AS section;
SELECT b.id, b.uuid, b.customer_email, b.start_at, b.total_amount, b.status,
       b.payment_mode, bt.name AS booking_type, bt.base_price AS type_base_price,
       JSON_UNQUOTE(JSON_EXTRACT(b.metadata,'$.membership_source')) AS membership_source,
       b.created_at
  FROM wp_g2ab_bookings b
  LEFT JOIN wp_g2ab_booking_types bt ON bt.id = b.booking_type_id
 WHERE b.created_at >= '2026-07-20 00:00:00'
   AND b.source = 'web'
   AND b.total_amount = 0
   AND COALESCE(bt.base_price, 0) > 0
   AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(b.metadata,'$.membership_source')), '') <> 'memberistic'
 ORDER BY b.created_at;

SELECT '--- C. payable web bookings with NO successful payment row of any kind ---' AS section;
SELECT b.id, b.uuid, b.customer_email, b.start_at, b.total_amount, b.status,
       b.payment_mode, b.source, b.event_id, b.created_at
  FROM wp_g2ab_bookings b
  LEFT JOIN wp_g2ab_payments p
    ON p.booking_id = b.id
   AND p.status IN ('succeeded','captured','paid','completed')
 WHERE b.created_at >= '2026-07-20 00:00:00'
   AND b.source = 'web'
   AND b.total_amount > 0
   AND b.status NOT IN ('cancelled','expired','payment_failed','pending')
   AND p.id IS NULL
 ORDER BY b.created_at;
