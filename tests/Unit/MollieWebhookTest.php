<?php
/**
 * Tests for the Mollie webhook's input rules and renewal idempotency.
 *
 * Mollie webhooks are unsigned and carry only a payment id, so the id is the one
 * piece of input the endpoint reads: it must be validated before an API call is
 * spent on it. Renewal webhooks are redelivered until a 200, so the decision of
 * what to record must make a second delivery harmless.
 *
 * @package WopCartDirect
 */

declare(strict_types=1);

namespace WopCartDirect\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WopCartDirect\Gateways\Mollie\Payload;
use WopCartDirect\Gateways\Mollie\PaymentState;

/**
 * Unit tests for the Mollie gateway helpers.
 *
 * @covers \WopCartDirect\Gateways\Mollie\PaymentState
 * @covers \WopCartDirect\Gateways\Mollie\Payload
 */
final class MollieWebhookTest extends TestCase {

	/**
	 * Ids and whether they are accepted.
	 *
	 * @return array<string,array{string,bool}>
	 */
	public static function ids(): array {
		return array(
			'documented example' => array( 'tr_5B8cwPMGnU6qLbRvo7qEZo', true ),
			'short real id'      => array( 'tr_d0b0E3EA3v', true ),
			'empty'              => array( '', false ),
			'prefix only'        => array( 'tr_', false ),
			'subscription id'    => array( 'sub_rVKGtNd6s3', false ),
			'refund id'          => array( 're_4qqhO89gsT', false ),
			'injection'          => array( "tr_abc'; DROP TABLE x", false ),
			'path traversal'     => array( 'tr_../../customers', false ),
			'trailing newline'   => array( "tr_abcdef\n", false ),
			'too long'           => array( 'tr_' . str_repeat( 'a', 65 ), false ),
			'uppercase prefix'   => array( 'TR_abcdef', false ),
		);
	}

	/**
	 * Check one case from the data provider.
	 *
	 * @param string $id       Posted id.
	 * @param bool   $expected Whether accepted.
	 */
	#[DataProvider( 'ids' )]
	public function test_validates_webhook_payment_ids( string $id, bool $expected ): void {
		$this->assertSame( $expected, PaymentState::valid_payment_id( $id ) );
	}

	/**
	 * A paid renewal is recorded once; its redelivery is recognised as a duplicate.
	 */
	public function test_paid_renewal_is_recorded_once(): void {
		$this->assertSame( PaymentState::RENEWAL_RECORD, PaymentState::renewal_action( 'paid', false ) );
		$this->assertSame( PaymentState::RENEWAL_DUPLICATE, PaymentState::renewal_action( 'paid', true ) );
	}

	/**
	 * A failed renewal counts, but never against a renewal already recorded as paid.
	 */
	public function test_failed_renewal_counts_only_when_not_recorded(): void {
		foreach ( array( 'failed', 'canceled', 'expired' ) as $status ) {
			$this->assertSame( PaymentState::RENEWAL_FAILURE, PaymentState::renewal_action( $status, false ), $status );
			$this->assertSame( PaymentState::RENEWAL_IGNORE, PaymentState::renewal_action( $status, true ), $status );
		}
	}

	/**
	 * Unfinished renewal payments change nothing.
	 */
	public function test_unfinished_renewal_is_ignored(): void {
		foreach ( array( 'open', 'pending', 'authorized' ) as $status ) {
			$this->assertSame( PaymentState::RENEWAL_IGNORE, PaymentState::renewal_action( $status, false ), $status );
		}
	}

	/**
	 * Webhook URLs Mollie cannot reach are not sent.
	 */
	public function test_detects_unreachable_webhook_hosts(): void {
		$this->assertTrue( Payload::webhook_reachable( 'https://shop.example.com/?wopcart-webhook=mollie' ) );
		$this->assertTrue( Payload::webhook_reachable( 'https://8.8.8.8/?wopcart-webhook=mollie' ) );

		foreach ( array( 'http://localhost/', 'https://shop.test/', 'https://mysite.local/', 'https://dev.localhost/', 'http://127.0.0.1/', 'http://192.168.1.20/', 'http://[::1]/', 'not a url' ) as $url ) {
			$this->assertFalse( Payload::webhook_reachable( $url ), $url );
		}
	}
}
