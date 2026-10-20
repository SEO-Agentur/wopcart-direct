<?php
/**
 * Tests for Mollie payments that ship.
 *
 * @package WopCartDirect
 */

declare(strict_types=1);

namespace WopCartDirect\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WopCartDirect\Gateways\Mollie\Payload;

/**
 * Shipping fee line and shipping address.
 *
 * @covers \WopCartDirect\Gateways\Mollie\Payload::lines
 * @covers \WopCartDirect\Gateways\Mollie\Payload::shipping_address
 */
final class MollieShippingTest extends TestCase {

	/**
	 * One goods line.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function items(): array {
		return array(
			array(
				'name'     => 'Book',
				'quantity' => 1,
				'total'    => 2140,
				'tax_rate' => 7.0,
			),
		);
	}

	/**
	 * With shipping, the lines still sum to the amount through a shipping_fee line.
	 */
	public function test_shipping_fee_line_reconciles(): void {
		$shipping = array(
			'label'    => 'Standard',
			'total'    => 583,
			'tax_rate' => 19.0,
		);

		$lines = Payload::lines( $this->items(), 2723, 'EUR', $shipping );

		$this->assertIsArray( $lines );
		$this->assertCount( 2, $lines );
		$this->assertSame( 'shipping_fee', $lines[1]['type'] );
		$this->assertSame( 'Shipping: Standard', $lines[1]['description'] );
		$this->assertSame( '5.83', $lines[1]['totalAmount']['value'] );
		$this->assertSame( '0.93', $lines[1]['vatAmount']['value'] );
		$this->assertSame( '19.00', $lines[1]['vatRate'] );
	}

	/**
	 * Without the shipping line the same order's lines would not reconcile.
	 */
	public function test_lines_without_shipping_do_not_reconcile(): void {
		$this->assertNull( Payload::lines( $this->items(), 2723, 'EUR' ) );
		$this->assertCount( 1, Payload::lines( $this->items(), 2140, 'EUR', array( 'total' => 0 ) ), 'Free shipping adds no line.' );
	}

	/**
	 * The delivery address maps to Mollie's address object.
	 */
	public function test_shipping_address(): void {
		$this->assertSame(
			array(
				'givenName'        => 'Erika',
				'familyName'       => 'Mustermann',
				'email'            => 'erika@example.test',
				'streetAndNumber'  => 'Hauptstr. 1',
				'streetAdditional' => 'Hinterhaus',
				'postalCode'       => '10115',
				'city'             => 'Berlin',
				'country'          => 'DE',
			),
			Payload::shipping_address(
				array(
					'first_name'  => 'Erika',
					'last_name'   => 'Mustermann',
					'address_1'   => 'Hauptstr. 1',
					'address_2'   => 'Hinterhaus',
					'city'        => 'Berlin',
					'postal_code' => '10115',
					'country'     => 'de',
				),
				'erika@example.test'
			)
		);
	}

	/**
	 * An incomplete address is left out rather than rejected by Mollie.
	 */
	public function test_incomplete_address_is_omitted(): void {
		$this->assertSame( array(), Payload::shipping_address( array( 'city' => 'Berlin' ) ) );
		$this->assertSame( array(), Payload::shipping_address( array() ) );
	}
}
