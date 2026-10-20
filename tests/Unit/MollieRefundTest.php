<?php
/**
 * Tests for Mollie refund amount checks and payment lines.
 *
 * A refund issued from the store and the webhook reporting it can be processed
 * in either order; the refunded total must count the refund exactly once.
 *
 * @package WopCartDirect
 */

declare(strict_types=1);

namespace WopCartDirect\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WopCartDirect\Gateways\Mollie\Payload;
use WopCartDirect\Gateways\Mollie\PaymentState;
use WopCart\Model\Order;

/**
 * Unit tests for the Mollie gateway helpers.
 *
 * @covers \WopCartDirect\Gateways\Mollie\PaymentState
 * @covers \WopCartDirect\Gateways\Mollie\Payload
 */
final class MollieRefundTest extends TestCase {

	/**
	 * Zero means the full remaining balance.
	 */
	public function test_zero_refunds_the_remaining_balance(): void {
		$this->assertSame( 5000, PaymentState::refund_amount( 0, 5000 ) );
		$this->assertSame( 3000, PaymentState::refund_amount( 0, 5000, 3000 ), 'Mollie knows of a dashboard refund.' );
	}

	/**
	 * Partial refunds within the balance pass unchanged.
	 */
	public function test_partial_refund_within_balance(): void {
		$this->assertSame( 1250, PaymentState::refund_amount( 1250, 5000, 5000 ) );
		$this->assertSame( 5000, PaymentState::refund_amount( 5000, 5000, 5000 ) );
	}

	/**
	 * Too large, negative and nothing-left refunds are refused, never clamped.
	 */
	public function test_refuses_invalid_refunds(): void {
		$this->assertSame( 'wopcart_mollie_refund_exceeds', PaymentState::refund_amount( 5001, 5000 )->get_error_code() );
		$this->assertSame( 'wopcart_mollie_refund_exceeds', PaymentState::refund_amount( 4000, 5000, 3000 )->get_error_code() );
		$this->assertSame( 'wopcart_mollie_refund_invalid', PaymentState::refund_amount( -1, 5000 )->get_error_code() );
		$this->assertSame( 'wopcart_nothing_to_refund', PaymentState::refund_amount( 0, 0 )->get_error_code() );
		$this->assertSame( 'wopcart_nothing_to_refund', PaymentState::refund_amount( 100, 5000, 0 )->get_error_code() );
	}

	/**
	 * The refund is counted once whether or not the webhook got there first.
	 */
	public function test_refunded_total_counts_refund_once(): void {
		$this->assertSame( 3000, PaymentState::refunded_after( 1000, 2000, 1000 ), 'Webhook not yet processed.' );
		$this->assertSame( 3000, PaymentState::refunded_after( 1000, 2000, 3000 ), 'Webhook already stored the new total.' );
	}

	/**
	 * Refund status follows the total.
	 */
	public function test_refund_status(): void {
		$this->assertSame( '', PaymentState::refund_status( 0, 5000 ) );
		$this->assertSame( Order::PARTIALLY_REFUNDED, PaymentState::refund_status( 1, 5000 ) );
		$this->assertSame( Order::REFUNDED, PaymentState::refund_status( 5000, 5000 ) );
	}

	/**
	 * Klarna lines sum to the payment and use Mollie's VAT formula.
	 */
	public function test_builds_lines_that_reconcile(): void {
		$lines = Payload::lines(
			array(
				array(
					'name'     => 'Theme',
					'quantity' => 2,
					'total'    => 12100,
					'tax_rate' => 21.0,
				),
				array(
					'name'     => 'Addon',
					'quantity' => 3,
					'total'    => 1000,
					'tax_rate' => 0.0,
				),
			),
			13100,
			'EUR'
		);

		$this->assertIsArray( $lines );
		$this->assertSame( '60.50', $lines[0]['unitPrice']['value'] );
		$this->assertSame( '21.00', $lines[0]['vatAmount']['value'] );
		$this->assertSame( '21.00', $lines[0]['vatRate'] );
		$this->assertSame( 1, $lines[1]['quantity'], 'An uneven unit price is sent as one line.' );
		$this->assertSame( '10.00', $lines[1]['unitPrice']['value'] );
	}

	/**
	 * Lines that do not add up to the payment are left out entirely.
	 */
	public function test_omits_lines_that_do_not_reconcile(): void {
		$items = array(
			array(
				'name'     => 'Theme',
				'quantity' => 1,
				'total'    => 1000,
				'tax_rate' => 21.0,
			),
		);

		$this->assertNull( Payload::lines( $items, 999, 'EUR' ) );
		$this->assertNull( Payload::lines( array(), 0, 'EUR' ) );
		$this->assertNull( Payload::lines( $items, 1000, 'KWD' ) );
	}

	/**
	 * Locales Mollie's hosted pages do not support are omitted.
	 */
	public function test_maps_locales(): void {
		$this->assertSame( 'de_DE', Payload::locale( 'de_DE_formal' ) );
		$this->assertSame( 'nl_NL', Payload::locale( 'nl_NL' ) );
		$this->assertSame( '', Payload::locale( 'ja' ) );
	}
}
