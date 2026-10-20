<?php
/**
 * Tests for PayPal orders that ship.
 *
 * @package WopCartDirect
 */

declare(strict_types=1);

namespace WopCartDirect\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WopCartDirect\Gateways\PayPal;
use WopCart\Support\Money;

/**
 * Provided address and an amount breakdown that sums exactly.
 *
 * @covers \WopCartDirect\Gateways\PayPal::shipping_unit
 * @covers \WopCartDirect\Gateways\PayPal::format_amount
 */
final class PayPalShippingTest extends TestCase {

	/**
	 * Shipping record as OrderBuilder stores it.
	 *
	 * @param int $total Gross shipping.
	 *
	 * @return array<string,mixed>
	 */
	private function shipping( int $total = 583 ): array {
		return array(
			'rate_id'  => 'eu:std',
			'label'    => 'Standard',
			'net'      => 490,
			'tax'      => 93,
			'tax_rate' => 19.0,
			'total'    => $total,
			'address'  => array(
				'first_name'  => 'Erika',
				'last_name'   => 'Mustermann',
				'company'     => '',
				'address_1'   => 'Hauptstr. 1',
				'address_2'   => '',
				'city'        => 'Berlin',
				'region'      => '',
				'postal_code' => '10115',
				'country'     => 'DE',
				'phone'       => '',
			),
		);
	}

	/**
	 * Digital orders are unchanged.
	 */
	public function test_nothing_ships(): void {
		$this->assertNull( PayPal::shipping_unit( array(), 1000, 'EUR' ) );
	}

	/**
	 * Address and breakdown for a shipped order.
	 */
	public function test_shipping_unit(): void {
		$unit = PayPal::shipping_unit( $this->shipping(), 6533, 'EUR' );

		$this->assertIsArray( $unit );
		$this->assertSame( '65.33', $unit['amount']['value'] );
		$this->assertSame( '59.50', $unit['amount']['breakdown']['item_total']['value'] );
		$this->assertSame( '5.83', $unit['amount']['breakdown']['shipping']['value'] );
		$this->assertSame( 'Erika Mustermann', $unit['shipping']['name']['full_name'] );
		$this->assertSame(
			array(
				'address_line_1' => 'Hauptstr. 1',
				'admin_area_2'   => 'Berlin',
				'postal_code'    => '10115',
				'country_code'   => 'DE',
			),
			$unit['shipping']['address']
		);
	}

	/**
	 * The breakdown sums to the amount exactly, for every currency precision.
	 */
	public function test_breakdown_sums_exactly(): void {
		foreach ( array( 'EUR', 'JPY', 'KWD', 'USD' ) as $currency ) {
			foreach ( array( array( 1, 0 ), array( 99999, 1 ), array( 100001, 333 ), array( 7, 7 ), array( 123457, 12345 ) ) as $case ) {
				list( $total, $charge ) = $case;

				$unit = PayPal::shipping_unit( $this->shipping( $charge ), $total, $currency );

				$items    = Money::to_minor( $unit['amount']['breakdown']['item_total']['value'], $currency );
				$shipping = Money::to_minor( $unit['amount']['breakdown']['shipping']['value'], $currency );

				$this->assertSame( $total, Money::to_minor( $unit['amount']['value'], $currency ), $currency );
				$this->assertSame( $total, $items + $shipping, $currency . ' ' . $total . '/' . $charge );
			}
		}
	}

	/**
	 * Missing address or shipping above the total refuses instead of sending a wrong order.
	 */
	public function test_refuses_bad_input(): void {
		$no_address = $this->shipping();

		$no_address['address']['address_1'] = '';

		$this->assertSame( 'wopcart_paypal_no_address', PayPal::shipping_unit( $no_address, 6533, 'EUR' )->get_error_code() );
		$this->assertSame( 'wopcart_paypal_breakdown', PayPal::shipping_unit( $this->shipping( 9000 ), 6533, 'EUR' )->get_error_code() );
	}
}
