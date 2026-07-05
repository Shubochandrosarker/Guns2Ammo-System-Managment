<?php

namespace G2A\POS\Domain;

use G2A\POS\Database\AuditLogRepository;
use G2A\POS\Database\GiftCardRepository;
use G2A\POS\Database\LoyaltyRepository;
use G2A\POS\Database\OrderRepository;
use G2A\POS\Database\TenderRepository;

/**
 * Split-tender checkout engine. One order can be paid by N tender lines —
 * cash + card + gift card + store credit + trade-in credit + check, any mix.
 *
 * For tender methods that touch a ledger (gift card, store credit,
 * trade-in credit) we hit the relevant repository synchronously inside
 * the same call so over-redemption is structurally impossible.
 *
 * For card we accept an `external_ref` (Stripe payment intent id) and
 * record only the captured amount; the actual auth+capture is the
 * caller's responsibility via the existing StripeController.
 */
final class TenderService {

	public const METHODS = array(
		'cash',
		'card',
		'gift_card',
		'store_credit',
		'tradein_credit',
		'check',
		'house_charge',
	);

	/**
	 * Compute the balance contract for an order at this moment.
	 * Returns the grand_total, the sum already captured, and how much is
	 * still owed (zero or negative ⇒ fully paid; positive ⇒ owed).
	 */
	public static function balance( int $order_id ): array {
		$order = ( new OrderRepository() )->find_with_items( $order_id );
		if ( ! $order ) {
			return array(
				'ok'    => false,
				'error' => 'order_not_found',
			);
		}
		$total    = (float) $order['grand_total'];
		$captured = ( new TenderRepository() )->captured_total( $order_id );
		$owed     = max( 0.0, round( $total - $captured, 2 ) );
		return array(
			'ok'          => true,
			'order_id'    => $order_id,
			'grand_total' => $total,
			'captured'    => round( $captured, 2 ),
			'owed'        => $owed,
			'fully_paid'  => $owed <= 0.0001,
			'tenders'     => ( new TenderRepository() )->list_for_order( $order_id ),
		);
	}

	/**
	 * Add one tender line. The caller passes:
	 *   tender_method, amount, plus method-specific fields:
	 *     gift_card     → code
	 *     store_credit  → customer_id
	 *     tradein_credit→ customer_id (same as store credit; just tagged)
	 *     card          → external_ref (payment_intent_id), last4 (optional, → reference)
	 *     check         → reference (check #)
	 *     cash          → nothing else
	 *
	 * Returns: { ok, tender_id, change_due, balance: {...} } on success.
	 */
	public static function add_tender( int $order_id, array $payload, ?int $register_session_id = null ): array {
		$repo  = new OrderRepository();
		$order = $repo->find_with_items( $order_id );
		if ( ! $order ) {
			return array(
				'ok'    => false,
				'error' => 'order_not_found',
			);
		}
		if ( in_array( ( $order['status'] ?? '' ), array( 'cancelled', 'void', 'refunded' ), true ) ) {
			return array(
				'ok'    => false,
				'error' => 'order_not_open_for_tender',
			);
		}

		$method = sanitize_key( (string) ( $payload['tender_method'] ?? '' ) );
		if ( ! in_array( $method, self::METHODS, true ) ) {
			return array(
				'ok'      => false,
				'error'   => 'invalid_tender_method',
				'allowed' => self::METHODS,
			);
		}
		$amount = round( (float) ( $payload['amount'] ?? 0 ), 2 );
		if ( $amount <= 0 ) {
			return array(
				'ok'    => false,
				'error' => 'amount_must_be_positive',
			);
		}

		$balance = self::balance( $order_id );
		$owed    = $balance['owed'];

		// Cash is the only method allowed to over-tender — change due is returned.
		$change_due = 0.0;
		if ( $amount > $owed + 0.0001 ) {
			if ( $method === 'cash' ) {
				$change_due = round( $amount - $owed, 2 );
				$amount     = $owed;
			} else {
				return array(
					'ok'           => false,
					'error'        => 'tender_exceeds_balance',
					'balance_owed' => $owed,
				);
			}
		}

		$external_ref = null;
		$reference    = $payload['reference'] ?? null;

		// ---- Method-specific atomic side effects ----
		switch ( $method ) {
			case 'gift_card':
				$code = (string) ( $payload['code'] ?? '' );
				if ( ! $code ) {
					return array(
						'ok'    => false,
						'error' => 'gift_card_code_required',
					);
				}
				$redeem = ( new GiftCardRepository() )->redeem( $code, (int) round( $amount * 100 ), $order_id );
				if ( empty( $redeem['ok'] ) ) {
					return array(
						'ok'    => false,
						'error' => $redeem['error'] ?? 'gift_card_redeem_failed',
					);
				}
				$amount       = round( $redeem['applied_cents'] / 100, 2 );
				$reference    = self::mask_gift_code( $code );
				$external_ref = 'gift_card_id=' . (int) ( $redeem['gift_card_id'] ?? 0 ) . ';balance_cents_after=' . $redeem['balance_cents'];
				break;

			case 'store_credit':
			case 'tradein_credit':
				$cid = (int) ( $payload['customer_id'] ?? 0 );
				if ( $cid <= 0 ) {
					return array(
						'ok'    => false,
						'error' => 'customer_id_required',
					);
				}
				$bal = ( new LoyaltyRepository() )->balance( $cid );
				if ( ( $bal['credit_cents'] ?? 0 ) < (int) round( $amount * 100 ) ) {
					return array(
						'ok'        => false,
						'error'     => 'insufficient_store_credit',
						'available' => round( ( $bal['credit_cents'] ?? 0 ) / 100, 2 ),
					);
				}
				$tx = ( new LoyaltyRepository() )->adjust(
					$cid,
					$method === 'tradein_credit' ? 'tradein_credit_spend' : 'store_credit_spend',
					0,
					- (int) round( $amount * 100 ),
					array(
						'pos_order_id' => $order_id,
						'reason'       => 'Tendered on order #' . $order_id,
					)
				);
				if ( empty( $tx['ok'] ) ) {
					return array(
						'ok'    => false,
						'error' => $tx['error'] ?? 'store_credit_debit_failed',
					);
				}
				$external_ref = 'customer=' . $cid . ';credit_cents_after=' . $tx['credit_cents'];
				$reference    = 'Customer #' . $cid;
				break;

			case 'card':
				$external_ref = sanitize_text_field( (string) ( $payload['external_ref'] ?? '' ) );
				if ( ! $external_ref ) {
					return array(
						'ok'    => false,
						'error' => 'external_ref_required_for_card',
					);
				}
				if ( ! empty( $payload['last4'] ) ) {
					$reference = 'Card ****' . substr( preg_replace( '/[^0-9]/', '', (string) $payload['last4'] ), -4 );
				}
				break;

			case 'check':
				if ( empty( $payload['reference'] ) ) {
					return array(
						'ok'    => false,
						'error' => 'check_number_required',
					);
				}
				break;

			case 'house_charge':
				$cid = (int) ( $payload['customer_id'] ?? 0 );
				if ( $cid <= 0 ) {
					return array(
						'ok'    => false,
						'error' => 'customer_id_required',
					);
				}
				$reference = 'House charge — customer #' . $cid;
				break;

			case 'cash':
			default:
				break;
		}

		$tender_id = ( new TenderRepository() )->add(
			$order_id,
			array(
				'tender_method'       => $method,
				'amount'              => $amount,
				'change_due'          => $change_due,
				'reference'           => $reference,
				'external_ref'        => $external_ref,
				'register_session_id' => $register_session_id,
			)
		);

		( new AuditLogRepository() )->add(
			'tender.capture',
			'pos_order',
			(string) $order_id,
			null,
			array(
				'tender_id'  => $tender_id,
				'method'     => $method,
				'amount'     => $amount,
				'change_due' => $change_due,
				'reference'  => $reference,
			)
		);

		$new_balance = self::balance( $order_id );

		// If this tender closed the order, flip payment_status + record payment_method.
		if ( $new_balance['fully_paid'] ) {
			self::finalize_order( $order_id, $new_balance );
		}

		return array(
			'ok'         => true,
			'tender_id'  => $tender_id,
			'change_due' => $change_due,
			'balance'    => $new_balance,
		);
	}

	public static function void_tender( int $tender_id, ?string $reason = null ): array {
		$tender = ( new TenderRepository() )->find( $tender_id );
		if ( ! $tender ) {
			return array(
				'ok'    => false,
				'error' => 'not_found',
			);
		}
		if ( $tender['status'] !== 'captured' ) {
			return array(
				'ok'     => false,
				'error'  => 'only_captured_lines_can_be_voided',
				'status' => $tender['status'],
			);
		}
		// Reverse the side effect for ledger-touching methods.
		$amount_cents        = (int) round( (float) $tender['amount'] * 100 );
		$gift_card_restored  = null;
		switch ( $tender['tender_method'] ) {
			case 'store_credit':
			case 'tradein_credit':
				if ( preg_match( '/customer=(\d+)/', (string) $tender['external_ref'], $m ) ) {
					( new LoyaltyRepository() )->adjust(
						(int) $m[1],
						'tender_void_refund',
						0,
						$amount_cents,
						array(
							'pos_order_id' => (int) $tender['pos_order_id'],
							'reason'       => 'Reversed voided tender #' . $tender_id,
						)
					);
				}
				break;
			case 'gift_card':
				// Put the debited value back on the card (locked transaction +
				// ledger row inside GiftCardRepository::credit()). Legacy
				// tender lines predating gift_card_id capture can't be
				// auto-reversed — those still need a manual re-issue.
				if ( preg_match( '/gift_card_id=(\d+)/', (string) $tender['external_ref'], $m ) && (int) $m[1] > 0 ) {
					$credit = ( new GiftCardRepository() )->credit( (int) $m[1], $amount_cents, (int) $tender['pos_order_id'], 'void_refund' );
					if ( empty( $credit['ok'] ) ) {
						return array(
							'ok'    => false,
							'error' => $credit['error'] ?? 'gift_card_credit_failed',
						);
					}
					$gift_card_restored = array(
						'gift_card_id'        => (int) $m[1],
						'restored_cents'      => $amount_cents,
						'balance_cents_after' => $credit['balance_cents'],
					);
				}
				break;
		}
		$ok = ( new TenderRepository() )->void( $tender_id, $reason );
		( new AuditLogRepository() )->add(
			'tender.void',
			'pos_order',
			(string) $tender['pos_order_id'],
			null,
			array(
				'tender_id' => $tender_id,
				'reason'    => $reason,
				'gift_card' => $gift_card_restored,
			)
		);
		return array(
			'ok'      => $ok,
			'balance' => self::balance( (int) $tender['pos_order_id'] ),
		);
	}

	public static function refund_tender( int $tender_id, float $amount ): array {
		$tender = ( new TenderRepository() )->find( $tender_id );
		if ( ! $tender ) {
			return array(
				'ok'    => false,
				'error' => 'not_found',
			);
		}
		$result = ( new TenderRepository() )->refund( $tender_id, round( $amount, 2 ) );
		if ( empty( $result['ok'] ) ) {
			return $result;
		}

		$cents = (int) round( $amount * 100 );
		switch ( $tender['tender_method'] ) {
			case 'store_credit':
			case 'tradein_credit':
				if ( preg_match( '/customer=(\d+)/', (string) $tender['external_ref'], $m ) ) {
					( new LoyaltyRepository() )->adjust(
						(int) $m[1],
						'tender_refund',
						0,
						$cents,
						array(
							'pos_order_id' => (int) $tender['pos_order_id'],
							'reason'       => 'Refund of tender #' . $tender_id,
						)
					);
				}
				break;
			// gift_card / cash / card refund handling is the caller's job
			// (it has the receipt + Stripe context). We just record the line state.
		}
		( new AuditLogRepository() )->add(
			'tender.refund',
			'pos_order',
			(string) $tender['pos_order_id'],
			null,
			array(
				'tender_id' => $tender_id,
				'amount'    => $amount,
			)
		);
		return array(
			'ok'      => true,
			'tender'  => ( new TenderRepository() )->find( $tender_id ),
			'balance' => self::balance( (int) $tender['pos_order_id'] ),
		);
	}

	private static function finalize_order( int $order_id, array $balance ): void {
		$tenders      = $balance['tenders'] ?? array();
		$methods_used = array_values(
			array_unique(
				array_map(
					static fn( $t ) => $t['tender_method'],
					array_filter( $tenders, static fn( $t ) => in_array( ( $t['status'] ?? '' ), array( 'captured', 'partially_refunded', 'refunded' ), true ) )
				)
			)
		);
		$method       = count( $methods_used ) > 1 ? 'split' : ( $methods_used[0] ?? '' );
		( new OrderRepository() )->update_states(
			$order_id,
			array(
				'payment_status' => 'paid',
				'payment_method' => $method,
			)
		);

		// The counter sale is fully paid — move the linked WC order out of
		// 'pending' so WooCommerce records payment and decrements stock.
		$order = ( new OrderRepository() )->find_with_items( $order_id );
		if ( ! empty( $order['wc_order_id'] ) ) {
			WooBridge::complete_payment( (int) $order['wc_order_id'] );
		}
	}

	private static function mask_gift_code( string $code ): string {
		$clean = preg_replace( '/[^A-Za-z0-9]/', '', $code );
		return 'Gift card ****' . substr( $clean, -4 );
	}
}
