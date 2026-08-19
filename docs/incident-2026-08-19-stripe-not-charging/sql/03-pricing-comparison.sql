-- 03 — Expected vs calculated vs stored price, per booking. READ ONLY.
-- expected_amount is reconstructed from the booking type's list price and party size.
-- A gap between expected_amount and total_amount is a discount/entitlement event;
-- a gap between total_amount and due_now is the pay-at-store bypass (RC-1/RC-2).

SELECT b.id,
       b.uuid,
       b.start_at,
       bt.name                                                   AS booking_type,
       bt.base_price                                             AS list_unit_price,
       b.party_size,
       ROUND(COALESCE(bt.base_price,0) * GREATEST(b.party_size,1), 2) AS expected_amount,
       b.total_amount                                            AS stored_total,
       CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(b.metadata,'$.due_now')), '0') AS DECIMAL(10,2)) AS stored_due_now,
       b.paid_amount,
       b.currency,
       b.payment_mode,
       b.status,
       JSON_UNQUOTE(JSON_EXTRACT(b.metadata,'$.membership_source')) AS membership_source,
       JSON_UNQUOTE(JSON_EXTRACT(b.metadata,'$.gateway'))          AS gateway,
       p.amount                                                   AS ledger_amount,
       p.status                                                   AS ledger_status,
       p.transaction_id                                           AS stripe_ref
  FROM wp_g2ab_bookings b
  LEFT JOIN wp_g2ab_booking_types bt ON bt.id = b.booking_type_id
  LEFT JOIN wp_g2ab_payments p
    ON p.id = (SELECT MAX(p2.id) FROM wp_g2ab_payments p2 WHERE p2.booking_id = b.id)
 WHERE b.created_at >= '2026-07-15 00:00:00'
 ORDER BY b.created_at;
