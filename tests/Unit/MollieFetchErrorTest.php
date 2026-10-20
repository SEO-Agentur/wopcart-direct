<?php
/**
 * Tests for how the Mollie gateway classifies failed payment fetches.
 *
 * A webhook answered with 200 is never retried by Mollie. Treating a refused API
 * key like an unknown payment therefore lost paid orders silently, so the
 * classification that decides between 200 and 503 is pinned here.
 *
 * @package WopCartDirect
 */

declare(strict_types=1);

namespace WopCartDirect\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WopCartDirect\Gateways\Mollie\PaymentState;

/**
 * Unit tests for fetch error classification and failed-renewal memory.
 *
 * @covers \WopCartDirect\Gateways\Mollie\PaymentState
 */
final class MollieFetchErrorTest extends TestCase {

	/**
	 * Only 404 means the key does not know the payment.
	 */
	public function test_only_404_is_unknown(): void {
		$this->assertSame( PaymentState::FETCH_UNKNOWN, PaymentState::fetch_error_kind( 404 ) );
	}

	/**
	 * A refused key is an auth error, never an unknown payment.
	 */
	public function test_401_and_403_are_auth_errors(): void {
		$this->assertSame( PaymentState::FETCH_AUTH, PaymentState::fetch_error_kind( 401 ) );
		$this->assertSame( PaymentState::FETCH_AUTH, PaymentState::fetch_error_kind( 403 ) );
	}

	/**
	 * Everything else must be retried.
	 */
	public function test_other_failures_are_transient(): void {
		foreach ( array( 0, 400, 422, 429, 500, 502, 503 ) as $status ) {
			$this->assertSame( PaymentState::FETCH_TRANSIENT, PaymentState::fetch_error_kind( $status ), (string) $status );
		}
	}

	/**
	 * A failure is counted once; a redelivery is recognised.
	 */
	public function test_failure_is_remembered_once(): void {
		$list = PaymentState::remember_failure( array(), 'tr_first' );

		$this->assertSame( array( 'tr_first' ), $list );
		$this->assertNull( PaymentState::remember_failure( $list, 'tr_first' ) );
		$this->assertSame( array( 'tr_first', 'tr_second' ), PaymentState::remember_failure( $list, 'tr_second' ) );
	}

	/**
	 * The list stays bounded, dropping the oldest ids.
	 */
	public function test_failure_list_is_bounded(): void {
		$list = array();

		foreach ( range( 1, 5 ) as $i ) {
			$list = PaymentState::remember_failure( $list, 'tr_p' . $i, 3 );
		}

		$this->assertSame( array( 'tr_p3', 'tr_p4', 'tr_p5' ), $list );
	}
}
