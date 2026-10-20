<?php
/**
 * Tests for mapping Mollie payment statuses to order states.
 *
 * Webhooks arrive late, repeatedly and out of order. The mapping must only ever
 * move an order forwards, and a late failure must never undo a paid order.
 *
 * @package WopCartDirect
 */

declare(strict_types=1);

namespace WopCartDirect\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WopCartDirect\Gateways\Mollie\PaymentState;
use WopCart\Model\Order;

/**
 * Unit tests for the Mollie gateway helpers.
 *
 * @covers \WopCartDirect\Gateways\Mollie\PaymentState
 */
final class MollieStatusMappingTest extends TestCase {

	/**
	 * Every documented Mollie status.
	 *
	 * @return array<string,array{string,string}>
	 */
	public static function statuses(): array {
		return array(
			'paid'       => array( 'paid', Order::COMPLETE ),
			'authorized' => array( 'authorized', Order::PROCESSING ),
			'pending'    => array( 'pending', Order::PROCESSING ),
			'failed'     => array( 'failed', Order::FAILED ),
			'canceled'   => array( 'canceled', Order::FAILED ),
			'expired'    => array( 'expired', Order::FAILED ),
			'open'       => array( 'open', '' ),
			'unknown'    => array( 'something-new', '' ),
		);
	}

	/**
	 * Check one case from the data provider.
	 *
	 * @param string $mollie   Mollie status.
	 * @param string $expected Order status.
	 */
	#[DataProvider( 'statuses' )]
	public function test_maps_each_mollie_status( string $mollie, string $expected ): void {
		$this->assertSame( $expected, PaymentState::order_status( $mollie ) );
	}

	/**
	 * Transitions, as [mollie status, current order status, expected target].
	 *
	 * @return array<string,array{string,string,string}>
	 */
	public static function transitions(): array {
		return array(
			'paid completes a pending order'            => array( 'paid', Order::PENDING, Order::COMPLETE ),
			'paid completes a processing order'         => array( 'paid', Order::PROCESSING, Order::COMPLETE ),
			'paid completes an order failed by cancel'  => array( 'paid', Order::FAILED, Order::COMPLETE ),
			'redelivered paid is a no-op'               => array( 'paid', Order::COMPLETE, '' ),
			'paid never reopens a refunded order'       => array( 'paid', Order::REFUNDED, '' ),
			'paid never reopens a revoked order'        => array( 'paid', Order::REVOKED, '' ),
			'pending marks a pending order processing'  => array( 'pending', Order::PENDING, Order::PROCESSING ),
			'pending does not touch a complete order'   => array( 'pending', Order::COMPLETE, '' ),
			'expired fails a pending order'             => array( 'expired', Order::PENDING, Order::FAILED ),
			'failed fails a processing order'           => array( 'failed', Order::PROCESSING, Order::FAILED ),
			'expired retry never fails a paid order'    => array( 'expired', Order::COMPLETE, '' ),
			'canceled never fails a partially refunded' => array( 'canceled', Order::PARTIALLY_REFUNDED, '' ),
			'open changes nothing'                      => array( 'open', Order::PENDING, '' ),
		);
	}

	/**
	 * Check one case from the data provider.
	 *
	 * @param string $mollie   Mollie status.
	 * @param string $current  Current order status.
	 * @param string $expected Target.
	 */
	#[DataProvider( 'transitions' )]
	public function test_only_moves_orders_forwards( string $mollie, string $current, string $expected ): void {
		$this->assertSame( $expected, PaymentState::order_transition( $mollie, $current ) );
	}
}
