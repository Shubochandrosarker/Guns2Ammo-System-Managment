-- 02 — THE RELEASE INVARIANT. READ ONLY.
-- docs/DEPLOYMENT-1.10.0.md: "Paid bookings without a successful ledger row (must be zero)".
-- If this returns ANY row, escalate as a separate P0: the state machine has been bypassed.

SELECT b.id,
       b.uuid,
       b.customer_email,
       b.start_at,
       b.total_amount,
       b.paid_amount,
       b.status,
       b.payment_mode,
       b.source,
       b.created_at,
       p.id     AS payment_id,
       p.gateway,
       p.transaction_id,
       p.status AS payment_status
  FROM wp_g2ab_bookings b
  LEFT JOIN wp_g2ab_payments p
    ON p.booking_id = b.id
   AND p.status IN ('succeeded','captured','paid','completed')
 WHERE b.status = 'paid'
   AND p.id IS NULL
 ORDER BY b.created_at DESC;

-- Companion: bookings marked `confirmed` that were payable and have no ledger row.
-- Legitimate only when total_amount = 0 (member-included / free type).
SELECT b.id, b.uuid, b.customer_email, b.start_at, b.total_amount, b.status,
       b.payment_mode, b.source,
       JSON_UNQUOTE(JSON_EXTRACT(b.metadata,'$.membership_source')) AS membership_source
  FROM wp_g2ab_bookings b
  LEFT JOIN wp_g2ab_payments p
    ON p.booking_id = b.id
   AND p.status IN ('succeeded','captured','paid','completed')
 WHERE b.status = 'confirmed'
   AND b.total_amount > 0
   AND p.id IS NULL
 ORDER BY b.created_at DESC;
