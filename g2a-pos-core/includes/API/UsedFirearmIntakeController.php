<?php

namespace G2A\POS\API;

use G2A\POS\Database\UsedFirearmIntakeRepository;
use G2A\POS\Inventory\UsedFirearmIntake;
use WP_REST_Request;

final class UsedFirearmIntakeController
{
    public static function index(WP_REST_Request $request)
    {
        return ['items' => (new UsedFirearmIntakeRepository())->list([
            'status' => $request->get_param('status'),
            'q' => $request->get_param('q'),
            'limit' => (int) ($request->get_param('limit') ?: 100),
        ])];
    }

    public static function get(WP_REST_Request $request)
    {
        $row = (new UsedFirearmIntakeRepository())->find((int) $request['id']);
        if (!$row) return new \WP_Error('not_found', 'Intake not found', ['status' => 404]);
        return ['intake' => $row];
    }

    public static function create(WP_REST_Request $request)
    {
        $body = $request->get_json_params() ?: [];
        $res = UsedFirearmIntake::receive($body);
        if (empty($res['ok'])) {
            return new \WP_Error($res['error'] ?? 'failed', 'Receive failed', ['status' => 422, 'detail' => $res]);
        }
        return $res;
    }

    public static function payout(WP_REST_Request $request)
    {
        $id = (int) $request['id'];
        $body = $request->get_json_params() ?: [];
        $res = UsedFirearmIntake::pay_out($id, $body);
        if (empty($res['ok'])) {
            return new \WP_Error($res['error'] ?? 'failed', 'Payout failed', ['status' => 422, 'detail' => $res]);
        }
        return $res;
    }

    public static function list_for_sale(WP_REST_Request $request)
    {
        $id = (int) $request['id'];
        $body = $request->get_json_params() ?: [];
        return UsedFirearmIntake::mark_listed($id, isset($body['wc_product_id']) ? (int) $body['wc_product_id'] : null);
    }

    public static function sell(WP_REST_Request $request)
    {
        $id = (int) $request['id'];
        $body = $request->get_json_params() ?: [];
        if (empty($body['pos_order_id']) || !isset($body['sold_price'])) {
            return new \WP_Error('invalid_input', 'pos_order_id + sold_price required', ['status' => 400]);
        }
        return UsedFirearmIntake::mark_sold($id, (int) $body['pos_order_id'], (float) $body['sold_price']);
    }
}
