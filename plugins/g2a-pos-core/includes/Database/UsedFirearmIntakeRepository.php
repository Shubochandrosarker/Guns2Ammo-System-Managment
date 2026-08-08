<?php

namespace G2A\POS\Database;

final class UsedFirearmIntakeRepository extends Repository
{
    public function create(array $data): int
    {
        global $wpdb;
        $now = $this->now();
        $wpdb->insert($this->table('g2a_used_firearm_intakes'), [
            'intake_number' => $data['intake_number'] ?? $this->generate_number(),
            'customer_id' => isset($data['customer_id']) ? (int) $data['customer_id'] : null,
            'customer_name' => sanitize_text_field($data['customer_name'] ?? ''),
            'customer_id_type' => sanitize_text_field($data['customer_id_type'] ?? ''),
            'customer_id_number' => sanitize_text_field($data['customer_id_number'] ?? ''),
            'customer_phone' => sanitize_text_field($data['customer_phone'] ?? ''),
            'customer_email' => sanitize_email($data['customer_email'] ?? ''),
            'customer_address' => sanitize_text_field($data['customer_address'] ?? ''),
            'customer_state' => strtoupper(substr(sanitize_text_field($data['customer_state'] ?? ''), 0, 2)),
            'manufacturer' => sanitize_text_field($data['manufacturer'] ?? ''),
            'model' => sanitize_text_field($data['model'] ?? ''),
            'serial_number' => sanitize_text_field($data['serial_number'] ?? ''),
            'firearm_type' => sanitize_text_field($data['firearm_type'] ?? ''),
            'caliber' => sanitize_text_field($data['caliber'] ?? ''),
            'barrel_length_in' => isset($data['barrel_length_in']) ? (float) $data['barrel_length_in'] : null,
            'condition_grade' => sanitize_text_field($data['condition_grade'] ?? ''),
            'condition_notes' => sanitize_textarea_field($data['condition_notes'] ?? ''),
            'intake_photos' => !empty($data['intake_photos']) ? wp_json_encode($data['intake_photos']) : null,
            'appraised_value' => isset($data['appraised_value']) ? (float) $data['appraised_value'] : null,
            'payout_amount' => (float) ($data['payout_amount'] ?? 0),
            'payout_method' => sanitize_text_field($data['payout_method'] ?? ''),
            'status' => sanitize_key($data['status'] ?? 'received'),
            'identity_id' => isset($data['identity_id']) ? (int) $data['identity_id'] : null,
            'identity_confidence' => isset($data['identity_confidence']) ? (float) $data['identity_confidence'] : null,
            'received_by' => get_current_user_id(),
            'received_at' => $data['received_at'] ?? $now,
            'actor_id' => get_current_user_id(),
            'notes' => sanitize_textarea_field($data['notes'] ?? ''),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return (int) $wpdb->insert_id;
    }

    public function update(int $id, array $fields): bool
    {
        global $wpdb;
        $allowed = ['status', 'appraised_value', 'payout_amount', 'payout_method',
                    'payout_reference', 'payout_paid_at', 'identity_id', 'identity_confidence',
                    'bound_book_id', 'wc_product_id', 'pos_order_id', 'sold_at', 'sold_price',
                    'condition_grade', 'condition_notes', 'notes', 'intake_photos'];
        $set = [];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $fields)) {
                $set[$k] = $k === 'intake_photos' && is_array($fields[$k])
                    ? wp_json_encode($fields[$k]) : $fields[$k];
            }
        }
        if (!$set) return false;
        $set['updated_at'] = $this->now();
        return (bool) $wpdb->update($this->table('g2a_used_firearm_intakes'), $set, ['id' => $id]);
    }

    public function find(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table('g2a_used_firearm_intakes')} WHERE id = %d",
            $id
        ), ARRAY_A);
        if ($row && !empty($row['intake_photos'])) {
            $row['intake_photos'] = json_decode((string) $row['intake_photos'], true);
        }
        return $row ?: null;
    }

    public function list(array $filters = []): array
    {
        global $wpdb;
        $t = $this->table('g2a_used_firearm_intakes');
        $where = ['1=1'];
        $args = [];
        if (!empty($filters['status'])) {
            $where[] = 'status = %s';
            $args[] = sanitize_key($filters['status']);
        }
        if (!empty($filters['q'])) {
            $like = '%' . $wpdb->esc_like($filters['q']) . '%';
            $where[] = '(customer_name LIKE %s OR serial_number LIKE %s OR manufacturer LIKE %s OR model LIKE %s)';
            $args[] = $like; $args[] = $like; $args[] = $like; $args[] = $like;
        }
        $limit = max(1, min(500, (int) ($filters['limit'] ?? 100)));
        $sql = "SELECT * FROM {$t} WHERE " . implode(' AND ', $where) .
               " ORDER BY id DESC LIMIT %d";
        $args[] = $limit;
        return $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A) ?: [];
    }

    public function generate_number(): string
    {
        return 'UF-' . date('Ymd') . '-' . strtoupper(wp_generate_password(4, false, false));
    }
}
