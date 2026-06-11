<?php

namespace G2A\POS\Payments;

/**
 * Stripe Terminal — server-side helpers.
 *
 * Endpoints:
 *  - connection_token():       returns Stripe Terminal connection token for the JS SDK.
 *  - create_payment_intent():  creates a PaymentIntent for card_present.
 *  - capture_payment_intent(): captures after collect_payment_method completed on the reader.
 *  - refund_charge():          full or partial refund via /v1/refunds.
 *
 * Credentials come from option g2a_pos_stripe { secret_key, publishable_key, account_id?, mode }.
 */
final class StripeTerminal
{
    private const API_BASE = 'https://api.stripe.com';

    public static function connection_token(): array
    {
        $resp = self::api('POST', '/v1/terminal/connection_tokens', []);
        if (isset($resp['error'])) {
            return $resp;
        }
        return ['secret' => $resp['secret'] ?? null];
    }

    public static function create_payment_intent(int $amount_cents, string $currency = 'usd', array $extra = []): array
    {
        if ($amount_cents <= 0) {
            return ['error' => 'amount_cents must be positive.'];
        }
        $params = array_merge([
            'amount' => $amount_cents,
            'currency' => $currency,
            'payment_method_types[]' => 'card_present',
            'capture_method' => 'manual',
        ], $extra);
        return self::api('POST', '/v1/payment_intents', $params);
    }

    public static function capture_payment_intent(string $pi_id): array
    {
        if ($pi_id === '') {
            return ['error' => 'payment_intent id required.'];
        }
        return self::api('POST', '/v1/payment_intents/' . rawurlencode($pi_id) . '/capture', []);
    }

    public static function refund_charge(string $payment_intent_id, ?int $amount_cents = null, string $reason = 'requested_by_customer'): array
    {
        if ($payment_intent_id === '') {
            return ['error' => 'payment_intent id required.'];
        }
        $params = ['payment_intent' => $payment_intent_id, 'reason' => $reason];
        if ($amount_cents !== null) {
            $params['amount'] = $amount_cents;
        }
        return self::api('POST', '/v1/refunds', $params);
    }

    public static function configured(): bool
    {
        $opt = (array) get_option('g2a_pos_stripe', []);
        return !empty($opt['secret_key']);
    }

    private static function api(string $method, string $path, array $params): array
    {
        $opt = (array) get_option('g2a_pos_stripe', []);
        $sk = (string) ($opt['secret_key'] ?? '');
        if ($sk === '') {
            return ['error' => 'Stripe secret key not configured.'];
        }
        $url = self::API_BASE . $path;
        $args = [
            'method' => $method,
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $sk,
                'Stripe-Version' => '2024-06-20',
            ],
        ];
        if (!empty($opt['account_id'])) {
            $args['headers']['Stripe-Account'] = $opt['account_id'];
        }
        if ($method === 'POST' && $params) {
            $args['body'] = http_build_query($params, '', '&');
            $args['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
        }
        $resp = wp_remote_request($url, $args);
        if (is_wp_error($resp)) {
            return ['error' => $resp->get_error_message()];
        }
        $body = (string) wp_remote_retrieve_body($resp);
        $code = (int) wp_remote_retrieve_response_code($resp);
        $data = json_decode($body, true) ?: [];
        if ($code >= 400) {
            return ['error' => $data['error']['message'] ?? ('HTTP ' . $code), 'raw' => $data];
        }
        return $data;
    }
}
