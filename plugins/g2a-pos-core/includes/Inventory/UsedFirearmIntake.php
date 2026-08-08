<?php

namespace G2A\POS\Inventory;

use G2A\POS\Catalog\IdentityService;
use G2A\POS\Database\AuditLogRepository;
use G2A\POS\Database\LoyaltyRepository;
use G2A\POS\Database\UsedFirearmIntakeRepository;

/**
 * Used-firearm intake — customer brings a used gun, the shop buys it outright.
 * Distinct from:
 *   • Consignment (we hold it for them; they get a share when it sells)
 *   • Trade-in   (we issue immediate store credit toward another purchase)
 *
 * On receipt:
 *   1. The intake record is created.
 *   2. IdentityService resolves the make/model to a canonical item identity
 *      (creating one if needed) so the gun joins the same catalog truth
 *      as anything we've ever sold in this make/model.
 *   3. The used-intake serial is linked to the canonical identity.
 *   4. Optionally writes a bound-book acquisition row (caller passes the
 *      ID back — bound-book write requires its own context).
 */
final class UsedFirearmIntake
{
    public static function receive(array $data): array
    {
        if (empty($data['customer_name']) || empty($data['manufacturer'])
            || empty($data['serial_number'])) {
            return ['ok' => false, 'error' => 'customer_name, manufacturer, serial_number required'];
        }

        // 1. Resolve canonical identity.
        $resolve = IdentityService::resolve_or_create([
            'item_type' => 'firearm',
            'firearm_type' => $data['firearm_type'] ?? '',
            'manufacturer' => $data['manufacturer'],
            'model' => $data['model'] ?? '',
            'caliber' => $data['caliber'] ?? '',
            'barrel_length_in' => $data['barrel_length_in'] ?? null,
        ], [
            'source_type' => 'used_intake',
            'source_ref' => $data['serial_number'],
        ]);

        // 2. Create the intake record with the resolved identity.
        $repo = new UsedFirearmIntakeRepository();
        $intake_id = $repo->create(array_merge($data, [
            'identity_id' => $resolve['identity_id'],
            'identity_confidence' => $resolve['score'],
        ]));

        $audit = new AuditLogRepository();
        $audit->add('used_firearm.receive', 'used_firearm_intake', (string) $intake_id, null, [
            'identity_id' => $resolve['identity_id'],
            'identity_decision' => $resolve['decision'],
            'identity_score' => $resolve['score'],
            'serial_number' => $data['serial_number'],
        ]);

        return [
            'ok' => true,
            'intake_id' => $intake_id,
            'intake' => $repo->find($intake_id),
            'identity' => $resolve,
        ];
    }

    public static function pay_out(int $intake_id, array $payload): array
    {
        $repo = new UsedFirearmIntakeRepository();
        $row = $repo->find($intake_id);
        if (!$row) return ['ok' => false, 'error' => 'not_found'];
        if (!empty($row['payout_paid_at'])) {
            return ['ok' => false, 'error' => 'already_paid_out'];
        }

        $method = sanitize_key((string) ($payload['payout_method'] ?? 'cash'));
        $amount = round((float) ($payload['payout_amount'] ?? ($row['appraised_value'] ?? 0)), 2);
        if ($amount <= 0) return ['ok' => false, 'error' => 'payout_amount_required'];

        $reference = sanitize_text_field((string) ($payload['payout_reference'] ?? ''));

        // Store-credit payouts hit the loyalty ledger.
        if ($method === 'store_credit') {
            $cid = (int) ($row['customer_id'] ?? 0);
            if ($cid <= 0) return ['ok' => false, 'error' => 'customer_id_required_for_store_credit'];
            $tx = (new LoyaltyRepository())->adjust(
                $cid, 'used_firearm_buy', 0, (int) round($amount * 100),
                ['reason' => 'Used firearm buy — intake #' . $intake_id]
            );
            if (empty($tx['ok'])) return ['ok' => false, 'error' => $tx['error'] ?? 'credit_failed'];
            $reference = $reference ?: ('loyalty_credit_cents_after=' . $tx['credit_cents']);
        }

        $repo->update($intake_id, [
            'payout_method' => $method,
            'payout_amount' => $amount,
            'payout_reference' => $reference,
            'payout_paid_at' => current_time('mysql'),
            'status' => 'paid',
        ]);

        (new AuditLogRepository())->add('used_firearm.payout', 'used_firearm_intake',
            (string) $intake_id, $row, ['method' => $method, 'amount' => $amount, 'reference' => $reference]);

        return ['ok' => true, 'intake' => $repo->find($intake_id)];
    }

    public static function mark_listed(int $intake_id, ?int $wc_product_id = null): array
    {
        $repo = new UsedFirearmIntakeRepository();
        if (!$repo->find($intake_id)) return ['ok' => false, 'error' => 'not_found'];
        $repo->update($intake_id, ['status' => 'listed', 'wc_product_id' => $wc_product_id]);
        return ['ok' => true, 'intake' => $repo->find($intake_id)];
    }

    public static function mark_sold(int $intake_id, int $pos_order_id, float $sold_price): array
    {
        $repo = new UsedFirearmIntakeRepository();
        if (!$repo->find($intake_id)) return ['ok' => false, 'error' => 'not_found'];
        $repo->update($intake_id, [
            'status' => 'sold',
            'pos_order_id' => $pos_order_id,
            'sold_price' => round($sold_price, 2),
            'sold_at' => current_time('mysql'),
        ]);
        return ['ok' => true, 'intake' => $repo->find($intake_id)];
    }
}
