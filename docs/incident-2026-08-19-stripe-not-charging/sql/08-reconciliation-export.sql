-- 08 — Reconciliation export. READ ONLY. Emails masked.
-- Produces the WordPress half of payment-reconciliation-2026-08-19.csv.
-- The four stripe_* columns are left empty here and are filled from the Stripe
-- dashboard export (Payments → Export) joined on booking_uuid == client_reference_id.
--
--   wp db query --skip-column-names < 08-reconciliation-export.sql \
--     | tr '\t' ',' >> payment-reconciliation-2026-08-19.csv

SELECT b.id                                              AS booking_id,
       b.uuid                                            AS booking_uuid,
       IF(b.event_id IS NULL OR b.event_id = 0, 'lane', COALESCE(e.title,'event')) AS booking_type,
       CONCAT(LEFT(b.customer_email,1), '***@',
              LEFT(SUBSTRING_INDEX(b.customer_email,'@',-1),1), '***.',
              SUBSTRING_INDEX(b.customer_email,'.',-1))  AS customer,
       b.created_at                                      AS booking_created,
       b.start_at                                        AS service_date,
       ROUND(COALESCE(bt.base_price,0) * GREATEST(b.party_size,1), 2) AS expected_amount,
       b.total_amount                                    AS local_amount,
       b.payment_mode,
       b.status                                          AS local_status,
       COALESCE(p.status, 'none')                        AS local_payment_status,
       ''                                                AS stripe_checkout_session,
       ''                                                AS stripe_payment_intent,
       ''                                                AS stripe_charge,
       ''                                                AS stripe_status,
       ''                                                AS stripe_amount,
       CASE
         WHEN b.status = 'cancelled'                                   THEN 'OK_CANCELLED'
         WHEN b.status IN ('pending','expired','payment_failed')        THEN 'ABANDONED_CHECKOUT'
         WHEN b.total_amount = 0
              AND JSON_UNQUOTE(JSON_EXTRACT(b.metadata,'$.membership_source')) = 'memberistic'
                                                                        THEN 'OK_MEMBER_FREE'
         WHEN b.total_amount = 0                                        THEN 'OK_MEMBER_FREE'
         WHEN p.status IN ('succeeded','captured','paid','completed')
              AND p.gateway = 'stripe'                                  THEN 'OK_PAID'
         WHEN p.status IN ('succeeded','captured','paid','completed')
              AND p.gateway <> 'stripe'                                 THEN 'WRONG_GATEWAY'
         WHEN b.status = 'paid'                                         THEN 'LOCAL_PAID_NO_STRIPE'
         WHEN b.payment_mode = 'in_store' AND b.source = 'web'          THEN 'MISSING_STRIPE_SESSION'
         WHEN b.payment_mode = 'in_store'                               THEN 'PAY_IN_STORE'
         ELSE 'MANUAL_REVIEW'
       END                                               AS classification,
       CASE
         WHEN b.payment_mode = 'in_store' AND b.source = 'web' AND b.total_amount > 0
           THEN 'CONTACT_CUSTOMER_COLLECT_AT_DESK_DO_NOT_AUTO_CHARGE'
         WHEN b.status = 'paid'
              AND NOT EXISTS (SELECT 1 FROM wp_g2ab_payments p3
                               WHERE p3.booking_id = b.id
                                 AND p3.status IN ('succeeded','captured','paid','completed'))
           THEN 'ESCALATE_P0'
         WHEN b.status IN ('pending','expired')                         THEN 'NONE_EXPIRE_HOLD'
         ELSE 'NONE'
       END                                               AS action_required
  FROM wp_g2ab_bookings b
  LEFT JOIN wp_g2ab_booking_types bt ON bt.id = b.booking_type_id
  LEFT JOIN wp_g2ab_events e         ON e.id = b.event_id
  LEFT JOIN wp_g2ab_payments p
    ON p.id = (SELECT MAX(p2.id) FROM wp_g2ab_payments p2 WHERE p2.booking_id = b.id)
 WHERE b.created_at >= '2026-07-15 00:00:00'
 ORDER BY b.created_at;
