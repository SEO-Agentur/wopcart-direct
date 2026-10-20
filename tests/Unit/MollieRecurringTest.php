<?php
/**
 * Tests for translating WopCart subscriptions into Mollie's recurring model.
 *
 * Every refusal here happens at checkout, before the buyer pays. The same
 * refusal after the first payment would leave a customer charged for a
 * subscription Mollie will never renew.
 *
 * @package WopCartDirect
 */

declare(strict_types=1);

namespace WopCartDirect\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WopCartDirect\Gateways\Mollie\Recurring;

/**
 * Unit tests for the Mollie gateway helpers.
 *
 * @covers \WopCartDirect\Gateways\Mollie\Recurring
 */
final class MollieRecurringTest extends TestCase {

	/**
	 * 2026-01-01 00:00:00 UTC.
	 */
	private const NOW = 1767225600;

	/**
	 * Intervals, as [period, frequency, expected string or null for refused].
	 *
	 * @return array<string,array{string,int,string|null}>
	 */
	public static function intervals(): array {
		return array(
			'monthly'            => array( 'month', 1, '1 month' ),
			'quarterly'          => array( 'month', 3, '3 months' ),
			'twelve months'      => array( 'month', 12, '12 months' ),
			'thirteen months'    => array( 'month', 13, null ),
			'yearly'             => array( 'year', 1, '12 months' ),
			'every two years'    => array( 'year', 2, null ),
			'weekly'             => array( 'week', 1, '1 week' ),
			'52 weeks'           => array( 'week', 52, '52 weeks' ),
			'53 weeks'           => array( 'week', 53, null ),
			'daily'              => array( 'day', 1, '1 day' ),
			'365 days'           => array( 'day', 365, '365 days' ),
			'366 days'           => array( 'day', 366, null ),
			'frequency 0 is one' => array( 'month', 0, '1 month' ),
			'unknown period'     => array( 'fortnight', 1, null ),
		);
	}

	/**
	 * Check one case from the data provider.
	 *
	 * @param string      $period    Period.
	 * @param int         $frequency Frequency.
	 * @param string|null $expected  Interval or null.
	 */
	#[DataProvider( 'intervals' )]
	public function test_translates_intervals_within_mollies_one_year_limit( string $period, int $frequency, ?string $expected ): void {
		$result = Recurring::interval( $period, $frequency );

		if ( null === $expected ) {
			$this->assertInstanceOf( \WP_Error::class, $result );

			return;
		}

		$this->assertSame( $expected, $result );
		$this->assertMatchesRegularExpression( '/\A\d+ (days?|weeks?|months?)\z/', $result );
	}

	/**
	 * The first payment is not part of the Mollie subscription.
	 */
	public function test_counts_remaining_payments(): void {
		$this->assertNull( Recurring::times( 0, 1 ), 'Until cancelled.' );
		$this->assertSame( 11, Recurring::times( 12, 1 ) );
		$this->assertSame( 12, Recurring::times( 12, 0 ), 'A trial has not billed yet.' );
		$this->assertSame( 0, Recurring::times( 1, 1 ), 'Nothing left to schedule.' );
		$this->assertSame( 0, Recurring::times( 3, 5 ) );
	}

	/**
	 * A subscription never starts today, which would charge on top of the first payment.
	 */
	public function test_start_date_is_never_today_or_earlier(): void {
		$this->assertSame( '2026-01-31', Recurring::start_date( self::NOW + ( 30 * DAY_IN_SECONDS ), self::NOW ) );
		$this->assertSame( '2026-01-02', Recurring::start_date( self::NOW, self::NOW ) );
		$this->assertSame( '2026-01-02', Recurring::start_date( self::NOW - DAY_IN_SECONDS, self::NOW ) );
	}

	/**
	 * One recurring line per order.
	 */
	public function test_decides_sequence_type_from_recurring_lines(): void {
		$this->assertSame( 'oneoff', Recurring::sequence_type( 0 ) );
		$this->assertSame( 'first', Recurring::sequence_type( 1 ) );
		$this->assertInstanceOf( \WP_Error::class, Recurring::sequence_type( 2 ) );
	}

	/**
	 * First payments only offer methods that leave a mandate.
	 */
	public function test_first_payment_offers_only_mandate_methods(): void {
		$methods = Recurring::first_payment_methods( 1000, array() );

		$this->assertIsArray( $methods );
		$this->assertContains( 'ideal', $methods );
		$this->assertContains( 'bancontact', $methods );
		$this->assertContains( 'creditcard', $methods );
		$this->assertNotContains( 'klarna', $methods );
	}

	/**
	 * A zero-amount first payment (free trial) is card or PayPal only.
	 */
	public function test_zero_amount_first_payment_is_card_or_paypal_only(): void {
		$this->assertSame( array( 'creditcard', 'paypal' ), Recurring::first_payment_methods( 0, array() ) );
		$this->assertInstanceOf( \WP_Error::class, Recurring::first_payment_methods( 0, array( 'ideal' ) ) );
	}

	/**
	 * The merchant's method restriction is respected, and can leave nothing.
	 */
	public function test_merchant_restriction_is_intersected(): void {
		$this->assertSame( array( 'ideal' ), Recurring::first_payment_methods( 1000, array( 'ideal', 'klarna' ) ) );
		$this->assertInstanceOf( \WP_Error::class, Recurring::first_payment_methods( 1000, array( 'klarna' ) ) );
	}

	/**
	 * One method is a string, several an array, none is omitted.
	 */
	public function test_builds_method_parameter(): void {
		$this->assertNull( Recurring::method_param( array() ) );
		$this->assertSame( 'ideal', Recurring::method_param( array( 'ideal' ) ) );
		$this->assertSame( array( 'ideal', 'creditcard' ), Recurring::method_param( array( 'ideal', 'creditcard', 'ideal' ) ) );
	}
}
