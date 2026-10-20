<?php
/**
 * Tests for AuthorizeNet::arb_interval().
 *
 * Authorize.net's recurring billing only understands days (7 to 365) or months
 * (1 to 12). A subscription outside that has to be rejected here, in the store,
 * rather than at the API — a failure at the API happens after the buyer has
 * entered their card, which turns a configuration mistake into a lost sale.
 *
 * The method is private because nothing outside the gateway should call it. It
 * is reached by reflection rather than being widened, since making a method
 * public purely to test it changes the class's real contract.
 *
 * @package WopCartDirect
 */

declare(strict_types=1);

namespace WopCartDirect\Tests\Unit;

use WopCartDirect\Gateways\AuthorizeNet;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @covers \WopCartDirect\Gateways\AuthorizeNet
 */
final class ArbIntervalTest extends TestCase {

	/**
	 * Reflected accessor for the private method.
	 *
	 * @var ReflectionMethod
	 */
	private ReflectionMethod $method;

	/**
	 * Gateway instance. AbstractGateway declares no constructor, so this costs
	 * nothing and touches no WordPress state.
	 *
	 * @var AuthorizeNet
	 */
	private AuthorizeNet $gateway;

	/**
	 * Set up the reflection handle.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->gateway = new AuthorizeNet();

		// No setAccessible() call: since PHP 8.1 reflection reaches private
		// members without it, and the method is deprecated as a no-op.
		$this->method = new ReflectionMethod( AuthorizeNet::class, 'arb_interval' );
	}

	/**
	 * Call the private method.
	 *
	 * @param string $period    day|week|month|year.
	 * @param int    $frequency Periods between charges.
	 *
	 * @return array{length:int,unit:string}|\WP_Error
	 */
	private function interval( string $period, int $frequency ): array|\WP_Error {
		return $this->method->invoke( $this->gateway, $period, $frequency );
	}

	/**
	 * Monthly is the common case and maps straight through.
	 */
	public function test_monthly_maps_to_months(): void {
		$this->assertSame( array( 'length' => 1, 'unit' => 'months' ), $this->interval( 'month', 1 ) );
		$this->assertSame( array( 'length' => 3, 'unit' => 'months' ), $this->interval( 'month', 3 ) );
	}

	/**
	 * A year is expressed as twelve months, because ARB has no year unit.
	 */
	public function test_yearly_becomes_twelve_months(): void {
		$this->assertSame( array( 'length' => 12, 'unit' => 'months' ), $this->interval( 'year', 1 ) );
	}

	/**
	 * Weeks become days, since ARB has no week unit either.
	 */
	public function test_weekly_becomes_days(): void {
		$this->assertSame( array( 'length' => 7, 'unit' => 'days' ), $this->interval( 'week', 1 ) );
		$this->assertSame( array( 'length' => 14, 'unit' => 'days' ), $this->interval( 'week', 2 ) );
	}

	/**
	 * Daily intervals pass through, but only once they reach ARB's floor.
	 */
	public function test_daily_passes_through_when_long_enough(): void {
		$this->assertSame( array( 'length' => 7, 'unit' => 'days' ), $this->interval( 'day', 7 ) );
		$this->assertSame( array( 'length' => 30, 'unit' => 'days' ), $this->interval( 'day', 30 ) );
	}

	/**
	 * Anything under a week is rejected rather than sent to fail at the API.
	 */
	public function test_rejects_intervals_below_the_floor(): void {
		$result = $this->interval( 'day', 1 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wopcart_authnet_interval', $result->get_error_code() );
	}

	/**
	 * More than a year in days is out of range.
	 */
	public function test_rejects_intervals_above_the_day_ceiling(): void {
		$this->assertInstanceOf( \WP_Error::class, $this->interval( 'day', 366 ) );
	}

	/**
	 * 365 days is the last accepted value, 366 the first rejected one.
	 */
	public function test_day_boundaries_are_inclusive(): void {
		$this->assertIsArray( $this->interval( 'day', 365 ) );
		$this->assertInstanceOf( \WP_Error::class, $this->interval( 'day', 366 ) );
	}

	/**
	 * Two years is 24 months, which exceeds ARB's twelve-month ceiling.
	 */
	public function test_rejects_multi_year_terms(): void {
		$this->assertInstanceOf( \WP_Error::class, $this->interval( 'year', 2 ) );
	}

	/**
	 * Twelve months is allowed, thirteen is not.
	 */
	public function test_month_boundaries_are_inclusive(): void {
		$this->assertIsArray( $this->interval( 'month', 12 ) );
		$this->assertInstanceOf( \WP_Error::class, $this->interval( 'month', 13 ) );
	}

	/**
	 * A frequency of zero would otherwise produce a zero-length interval, so it
	 * is clamped to one rather than rejected.
	 */
	public function test_frequency_is_clamped_to_at_least_one(): void {
		$this->assertSame( array( 'length' => 1, 'unit' => 'months' ), $this->interval( 'month', 0 ) );
		$this->assertSame( array( 'length' => 1, 'unit' => 'months' ), $this->interval( 'month', -5 ) );
	}

	/**
	 * An unrecognised period falls back to months, which matches the method's
	 * own default arm and keeps a mis-typed setting from producing days.
	 */
	public function test_unknown_period_falls_back_to_months(): void {
		$this->assertSame( array( 'length' => 1, 'unit' => 'months' ), $this->interval( 'fortnight', 1 ) );
	}
}
