-- 00 — Schema and configuration probe. READ ONLY.
-- Answers: did the migrations run, which columns exist, and how is Stripe configured?
-- Secrets are NEVER printed: only "set/unset", length and an 8-char prefix.

SELECT '--- installed g2ab/memberistic tables ---' AS section;
SELECT table_name, table_rows, create_time
  FROM information_schema.tables
 WHERE table_schema = DATABASE()
   AND (table_name LIKE '%g2ab%' OR table_name LIKE '%memberistic%')
 ORDER BY table_name;

SELECT '--- wp_g2ab_payments columns (1.10.0+ expects the *_minor and session/intent columns) ---' AS section;
SELECT column_name, column_type, is_nullable
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'wp_g2ab_payments'
 ORDER BY ordinal_position;

SELECT '--- DB version + payment-relevant options (secrets masked) ---' AS section;
SELECT option_name,
       CASE
         WHEN option_name LIKE '%secret%' OR option_name LIKE '%_key%'
           THEN CONCAT('set=', IF(option_value <> '', 'YES', 'NO'),
                       ' len=', CHAR_LENGTH(option_value),
                       ' prefix=', LEFT(option_value, 8))
         ELSE option_value
       END AS value_or_fingerprint
  FROM wp_options
 WHERE option_name IN (
        'g2ab_db_version',
        'g2ab_addons_active',
        'g2ab_stripe_enabled',
        'g2ab_stripe_test_mode',
        'g2ab_stripe_secret_key',
        'g2ab_stripe_publishable_key',
        'g2ab_stripe_webhook_secret',
        'g2ab_stripe_webhook_last_received_at',
        'g2ab_stripe_webhook_last_verified_at',
        'g2ab_stripe_webhook_last_processed_at',
        'g2ab_stripe_webhook_last_failed_at',
        'g2ab_payment_gateway_default',
        'g2ab_require_public_prepay',
        'g2ab_allow_event_pay_in_store_fallback',
        'g2ab_allow_guest_booking',
        'g2ab_reservation_hold_minutes',
        'g2ab_currency',
        'memberistic_lane_included_plan_slugs',
        'memberistic_lane_eligible_statuses',
        'memberistic_stripe_webhook_last_verified_at',
        'memberistic_stripe_webhook_last_processed_at',
        'memberistic_stripe_webhook_last_failed_at'
       )
 ORDER BY option_name;

SELECT '--- booking types: an EMPTY payment_modes on a paid lane type is root cause RC-1 trigger 2 ---' AS section;
SELECT id, name, slug, base_price, member_discount, deposit_amount,
       payment_modes, members_only, is_active
  FROM wp_g2ab_booking_types
 ORDER BY id;
