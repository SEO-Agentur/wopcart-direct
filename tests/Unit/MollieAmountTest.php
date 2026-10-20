<?php
/**
 * Tests for Mollie amount formatting.
 *
 * Mollie takes amounts as exact strings in its own per-currency precision, which
 * does not always match the store's minor units (ISK). A wrong string charges a
 * buyer a different figure than the order, so every case is pinned here.
 *
 * @package WopCartDirect
 */

declare(strict_types=1);

namespace WopCartDirect\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WopCartDirect\Gateways\Mollie\Amount;

/**
 * Unit tests for the Mollie gateway helpers.
 *
 * @covers \WopCartDirect\Gateways\Mollie\Amount
 */
final class MollieAmountTest extends TestCase {

	/**
	 * Formatting cases.
	 *
	 * @return array<string,array{int,string,string}>
	 */
	public static function formats(): array {
		return array(
			'EUR cents'                                  => array( 1999, 'EUR', '19.99' ),
			'EUR below one'                              => array( 5, 'EUR', '0.05' ),
			'EUR zero'                                   => array( 0, 'EUR', '0.00' ),
			'EUR large'                                  => array( 123456789, 'EUR', '1234567.89' ),
			'lowercase currency'                         => array( 1000, 'eur', '10.00' ),
			'JPY zero decimals'                          => array( 1500, 'JPY', '1500' ),
			'ISK store hundredths to Mollie whole units' => array( 150000, 'ISK', '1500' ),
			'HUF two decimals'                           => array( 12345, 'HUF', '123.45' ),
		);
	}

	/**
	 * Check one case from the data provider.
	 *
	 * @param int    $minor    Minor units.
	 * @param string $currency Currency.
	 * @param string $expected Mollie value.
	 */
	#[DataProvider( 'formats' )]
	public function test_formats_minor_units_as_mollie_value( int $minor, string $currency, string $expected ): void {
		$this->assertSame( $expected, Amount::format( $minor, $currency ) );
	}

	/**
	 * An ISK amount with a fractional krona cannot be charged exactly and is refused.
	 */
	public function test_refuses_amount_not_representable_in_mollie_precision(): void {
		$result = Amount::format( 150050, 'ISK' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wopcart_mollie_amount_precision', $result->get_error_code() );
	}

	/**
	 * Three-decimal and unknown currencies are not offered by Mollie.
	 */
	public function test_refuses_unsupported_currencies(): void {
		foreach ( array( 'KWD', 'BHD', 'XYZ', '' ) as $currency ) {
			$result = Amount::format( 1000, $currency );

			$this->assertInstanceOf( \WP_Error::class, $result, $currency );
			$this->assertSame( 'wopcart_mollie_currency', $result->get_error_code() );
			$this->assertFalse( Amount::supports( $currency ) );
		}
	}

	/**
	 * Negative amounts are never sent.
	 */
	public function test_refuses_negative_amount(): void {
		$this->assertInstanceOf( \WP_Error::class, Amount::format( -1, 'EUR' ) );
	}

	/**
	 * Parsing API amounts back to minor units.
	 */
	public function test_parses_amount_objects_and_strings(): void {
		$this->assertSame(
			1999,
			Amount::to_minor(
				array(
					'currency' => 'EUR',
					'value'    => '19.99',
				)
			)
		);
		$this->assertSame( 1500, Amount::to_minor( '1500', 'JPY' ) );
		$this->assertSame( 150000, Amount::to_minor( '1500', 'ISK' ) );
		$this->assertSame( 1000, Amount::to_minor( '10.000', 'EUR' ), 'Trailing zeros are harmless.' );
		$this->assertSame( 1000, Amount::to_minor( '10', 'EUR' ) );
	}

	/**
	 * Anything that would lose or invent part of an amount is an error.
	 */
	public function test_rejects_unreadable_amounts(): void {
		$this->assertInstanceOf( \WP_Error::class, Amount::to_minor( '10.001', 'EUR' ) );
		$this->assertInstanceOf( \WP_Error::class, Amount::to_minor( '1.2.3', 'EUR' ) );
		$this->assertInstanceOf( \WP_Error::class, Amount::to_minor( '-5.00', 'EUR' ) );
		$this->assertInstanceOf( \WP_Error::class, Amount::to_minor( '1500.5', 'JPY' ) );
		$this->assertInstanceOf(
			\WP_Error::class,
			Amount::to_minor(
				array(
					'currency' => 'KWD',
					'value'    => '1.000',
				)
			)
		);
		$this->assertInstanceOf( \WP_Error::class, Amount::to_minor( null, 'EUR' ) );
	}

	/**
	 * Format and parse are inverses for every supported currency.
	 */
	public function test_round_trips_every_supported_currency(): void {
		foreach ( array_keys( Amount::SUPPORTED ) as $currency ) {
			$minor = 'ISK' === $currency ? 987600 : 9876;
			$value = Amount::format( $minor, $currency );

			$this->assertIsString( $value, $currency );
			$this->assertSame( $minor, Amount::to_minor( $value, $currency ), $currency );
		}
	}
}
