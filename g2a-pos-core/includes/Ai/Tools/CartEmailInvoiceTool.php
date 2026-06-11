<?php

namespace G2A\POS\Ai\Tools;

use G2A\POS\Ai\ActionPolicy;
use G2A\POS\Ai\ToolRegistry;
use G2A\POS\Database\OrderRepository;
use G2A\POS\Messaging\MessagingService;

final class CartEmailInvoiceTool {

	public static function register(): void {
		ToolRegistry::register(
			'cart.email_invoice',
			array(
				'description' => 'Email the invoice for a POS order to a recipient. ALWAYS shows a confirmation popup before sending. Use this when the user asks "email the invoice".',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'order_id' => array( 'type' => 'integer' ),
						'to'       => array(
							'type'        => 'string',
							'description' => 'Recipient email address',
						),
						'subject'  => array( 'type' => 'string' ),
						'body'     => array(
							'type'        => 'string',
							'description' => 'Optional override; if blank, a default invoice email is used.',
						),
					),
					'required'   => array( 'order_id', 'to' ),
				),
			),
			array( self::class, 'run' ),
			'g2a_pos_access',
			ActionPolicy::CONFIRM
		);
	}

	public static function run( array $args ): array {
		$order_id = (int) ( $args['order_id'] ?? 0 );
		$to       = sanitize_email( (string) ( $args['to'] ?? '' ) );
		if ( ! $order_id || ! $to ) {
			return array(
				'ok'    => false,
				'error' => 'order_id_and_to_required',
			);
		}
		$order = ( new OrderRepository() )->find_with_items( $order_id );
		if ( ! $order ) {
			return array(
				'ok'    => false,
				'error' => 'order_not_found',
			);
		}

		$subject = $args['subject'] ?? ( 'Your invoice — Order #' . $order_id );
		$body    = trim( (string) ( $args['body'] ?? '' ) ) ?: self::default_body( $order );

		$msg_id = MessagingService::queue_email(
			$to,
			$subject,
			$body,
			array(
				'template_key'        => 'pos_invoice_email',
				'related_entity_type' => 'pos_order',
				'related_entity_id'   => $order_id,
			)
		);
		return array(
			'ok'                => true,
			'queued_message_id' => $msg_id,
			'order_id'          => $order_id,
			'to'                => $to,
		);
	}

	private static function default_body( array $order ): string {
		$lines = '';
		foreach ( ( $order['items'] ?? array() ) as $i ) {
			$lines .= sprintf(
				'<tr><td>%s</td><td>%s</td><td style="text-align:right">$%s</td></tr>',
				esc_html( (string) ( $i['sku'] ?? '' ) ),
				esc_html( (string) $i['quantity'] ),
				number_format( (float) $i['line_total'], 2 )
			);
		}
		return '<p>Thank you for your purchase. Your invoice is below.</p>'
			. '<table cellpadding="6" cellspacing="0" border="1" style="border-collapse:collapse">'
			. '<tr><th>SKU</th><th>Qty</th><th>Total</th></tr>' . $lines . '</table>'
			. '<p><strong>Grand total: $' . number_format( (float) $order['grand_total'], 2 ) . '</strong></p>';
	}
}
