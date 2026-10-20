<?php
/**
 * Tests for PayPal webhook order binding and refund webhook idempotency.
 *
 * Moved here from core's suite with the gateways, unchanged except for the
 * classes it names and the option store its claim assertion reads. These are
 * money-correctness tests — a refund counted twice is a refund the store pays
 * twice — so they follow the code rather than being rewritten against it.
 *
 * @package WopCartDirect
 */

declare(strict_types=1);

namespace WopCartDirect\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WopCart\Model\Order;
use WopCart\Support\Options;
use WopCartDirect\Gateways\AuthorizeNet;
use WopCartDirect\Gateways\PayPal;
use WopCartDirect\Gateways\TwoCheckout;

require_once __DIR__ . '/RefundClaimStubs.php';
require_once __DIR__ . '/SecAtomicOrderFakeDb.php';

/**
 * A webhook resource must be the order's own PayPal object and pay it in full;
 * a redelivered refund must not be counted twice.
 *
 * @covers \WopCartDirect\Gateways\PayPal::resource_matches
 * @covers \WopCartDirect\Gateways\PayPal::capture_covers
 * @covers \WopCartDirect\Gateways\PayPal::refund_details
 * @covers \WopCartDirect\Gateways\PayPal::refunded_after
 * @covers \WopCartDirect\Gateways\PayPal::record_refund
 * @covers \WopCartDirect\Gateways\AuthorizeNet::record_refund
 * @covers \WopCartDirect\Gateways\TwoCheckout::refund_key
 */
final class SecWebhookRefundTest extends TestCase {

	/**
	 * Start from an empty option store, as core's suite does.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->forget();
	}

	/**
	 * Remove the fake database and options.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );

		$this->forget();

		parent::tearDown();
	}

	/**
	 * Empty the harness's option store and core's settings cache.
	 *
	 * This suite's `get_option()` really stores and returns values, unlike core's,
	 * so the refund claim an earlier test took has to be cleared or the next test's
	 * `claim()` blocks on it.
	 */
	private function forget(): void {
		$GLOBALS['wopcart_direct_test_options'] = array();

		Options::flush();
	}

	/**
	 * The options the harness is holding.
	 *
	 * @return array<string,mixed>
	 */
	private function options(): array {
		return $GLOBALS['wopcart_direct_test_options'];
	}

	/**
	 * Install a fake database holding one completed $10.00 order.
	 *
	 * @param array<string,mixed> $row Column overrides.
	 *
	 * @return SecAtomicOrderFakeDb
	 */
	private function install_db( array $row = array() ): SecAtomicOrderFakeDb {
		$db         = new SecAtomicOrderFakeDb();
		$db->orders = array(
			7 => $row + array(
				'id'       => 7,
				'status'   => Order::COMPLETE,
				'total'    => 1000,
				'refunded' => 0,
				'currency' => 'USD',
				'meta'     => '{"order_key":"abc"}',
			),
		);

		$GLOBALS['wpdb'] = $db; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the unit suite has no real $wpdb.

		return $db;
	}

	/**
	 * Gateway classes, with the meta key each keeps refund ids under.
	 *
	 * @return array<string,array{0:class-string,1:string}>
	 */
	public static function refund_gateways(): array {
		return array(
			'paypal'       => array( PayPal::class, 'paypal_refund_ids' ),
			'authorizenet' => array( AuthorizeNet::class, 'authnet_refund_ids' ),
		);
	}

	/**
	 * Run a gateway's private record_refund(), the step refund() takes after the API call.
	 *
	 * @param class-string $gateway   Gateway class.
	 * @param Order        $order     Order.
	 * @param string       $refund_id Refund id.
	 * @param int          $amount    Amount, minor units.
	 */
	private function record( string $gateway, Order $order, string $refund_id, int $amount ): void {
		( new \ReflectionMethod( $gateway, 'record_refund' ) )->invoke( new $gateway(), $order, $refund_id, $amount );
	}

	/**
	 * A refund from refund() stores its id first, as a single meta key, then the refunded figure.
	 *
	 * @dataProvider refund_gateways
	 *
	 * @param class-string $gateway  Gateway class.
	 * @param string       $meta_key Meta key for refund ids.
	 */
	public function test_refund_id_is_written_before_the_refunded_figure( string $gateway, string $meta_key ): void {
		$db    = $this->install_db();
		$order = new Order( $db->orders[7] );

		// Meta written by someone else after the admin loaded the order survives.
		$db->orders[7]['meta'] = '{"order_key":"abc","note":"later"}';

		$this->record( $gateway, $order, 'R1', 400 );

		$stored = json_decode( (string) $db->orders[7]['meta'], true );

		$this->assertSame( 400, $db->orders[7]['refunded'] );
		$this->assertSame( array( 'R1' ), $stored[ $meta_key ] );
		$this->assertSame( 'later', $stored['note'] );
		$this->assertSame( Order::PARTIALLY_REFUNDED, $db->orders[7]['status'] );

		$writes = array_values( array_filter( $db->log, static fn( string $q ): bool => str_starts_with( $q, 'UPDATE' ) && ! str_contains( $q, '"status"' ) ) );
		$this->assertCount( 2, $writes, 'One single-key meta write, then the refunded figure.' );
		$this->assertStringContainsString( 'SET meta', $writes[0], 'The refund id is written first.' );
		$this->assertStringContainsString( 'R1', $writes[0] );
		$this->assertStringNotContainsString( 'SET meta', $writes[1], 'The refunded figure is written without the meta blob.' );

		// The caller's copy is current for what it does next (RefundLog::sync).
		$this->assertSame( 400, $order->refunded() );
		$this->assertSame( array( 'R1' ), $order->get_meta( $meta_key ) );

		$this->assertSame( array(), $this->options(), 'The claim is released.' );
	}

	/**
	 * The webhook for the same refund counted it first: refund() does not add it again.
	 *
	 * @dataProvider refund_gateways
	 *
	 * @param class-string $gateway  Gateway class.
	 * @param string       $meta_key Meta key for refund ids.
	 */
	public function test_refund_already_counted_by_webhook_is_not_added( string $gateway, string $meta_key ): void {
		$db    = $this->install_db();
		$order = new Order( $db->orders[7] );

		// The webhook ran between the API response and record_refund().
		$db->orders[7]['refunded'] = 400;
		$db->orders[7]['status']   = Order::PARTIALLY_REFUNDED;
		$db->orders[7]['meta']     = wp_json_encode(
			array(
				'order_key' => 'abc',
				$meta_key   => array( 'R1' ),
			)
		);

		$this->record( $gateway, $order, 'R1', 400 );

		$this->assertSame( 400, $db->orders[7]['refunded'] );
		$this->assertSame( array( 'R1' ), json_decode( (string) $db->orders[7]['meta'], true )[ $meta_key ] );
		$this->assertSame( Order::PARTIALLY_REFUNDED, $db->orders[7]['status'] );
		$this->assertSame( 400, $order->refunded() );

		// A second, different refund still adds, capped at the total.
		$this->record( $gateway, $order, 'R2', 700 );

		$this->assertSame( 1000, $db->orders[7]['refunded'] );
		$this->assertSame( array( 'R1', 'R2' ), json_decode( (string) $db->orders[7]['meta'], true )[ $meta_key ] );
		$this->assertSame( Order::REFUNDED, $db->orders[7]['status'] );
	}

	/**
	 * A capture for another store's order 5, sharing the PayPal account, does not bind to ours.
	 */
	public function test_resource_must_name_the_orders_paypal_object(): void {
		$references = array( '8AB12345CD', '', '', '' );

		$capture = array(
			'id'                 => '3C679366HH908993F',
			'custom_id'          => '5',
			'supplementary_data' => array( 'related_ids' => array( 'order_id' => '8AB12345CD' ) ),
		);

		$this->assertTrue( PayPal::resource_matches( $capture, $references ) );

		$capture['supplementary_data']['related_ids']['order_id'] = '9ZZ99999ZZ';
		$this->assertFalse( PayPal::resource_matches( $capture, $references ) );

		// Approved PayPal order and activated subscription carry their own id.
		$this->assertTrue( PayPal::resource_matches( array( 'id' => '8AB12345CD' ), $references ) );
		$this->assertTrue( PayPal::resource_matches( array( 'id' => 'I-SUB1' ), array( '', 'I-SUB1' ) ) );

		// Empty ids never match empty references.
		$this->assertFalse( PayPal::resource_matches( array( 'id' => '' ), array( '', '' ) ) );
	}

	/**
	 * A capture below the order total or in another currency does not complete it.
	 */
	public function test_capture_must_cover_the_order(): void {
		$capture = array(
			'amount' => array(
				'currency_code' => 'USD',
				'value'         => '10.00',
			),
		);

		$this->assertTrue( PayPal::capture_covers( $capture, 1000, 'USD' ) );
		$this->assertFalse( PayPal::capture_covers( $capture, 100000, 'USD' ) );
		$this->assertFalse( PayPal::capture_covers( $capture, 1000, 'EUR' ) );
		$this->assertFalse( PayPal::capture_covers( array(), 1000, 'USD' ) );
	}

	/**
	 * A refund resource is matched through its capture, not its own id.
	 */
	public function test_refund_resolves_to_its_capture(): void {
		$refund = array(
			'id'                       => '1JU08902781691411',
			'amount'                   => array(
				'currency_code' => 'USD',
				'value'         => '2.50',
			),
			'seller_payable_breakdown' => array(
				'total_refunded_amount' => array(
					'currency_code' => 'USD',
					'value'         => '4.00',
				),
			),
			'links'                    => array(
				array(
					'rel'  => 'self',
					'href' => 'https://api.paypal.com/v2/payments/refunds/1JU08902781691411',
				),
				array(
					'rel'  => 'up',
					'href' => 'https://api.paypal.com/v2/payments/captures/2GG279541U471931P',
				),
			),
		);

		$details = PayPal::refund_details( $refund, 'PAYMENT.CAPTURE.REFUNDED' );

		$this->assertSame( '2GG279541U471931P', $details['capture'] );
		$this->assertSame( '1JU08902781691411', $details['refund'] );
		$this->assertSame( 250, $details['amount'] );
		$this->assertSame( 400, $details['total_refunded'] );
		$this->assertFalse( $details['full'] );

		$reversal = PayPal::refund_details( array( 'id' => 'CAP1' ), 'PAYMENT.CAPTURE.REVERSED' );

		$this->assertSame( 'CAP1', $reversal['capture'] );
		$this->assertTrue( $reversal['full'] );
	}

	/**
	 * Applying the same refund's figures twice does not double the refunded total.
	 */
	public function test_refunded_total_is_idempotent_and_capped(): void {
		// Absolute total reported: a replay changes nothing.
		$once  = PayPal::refunded_after( 0, 1000, 400, 400 );
		$twice = PayPal::refunded_after( $once, 1000, 400, 400 );

		$this->assertSame( 400, $once );
		$this->assertSame( 400, $twice );

		// Without it the amount is added, never past what was paid.
		$this->assertSame( 700, PayPal::refunded_after( 300, 1000, 400, null ) );
		$this->assertSame( 1000, PayPal::refunded_after( 900, 1000, 400, null ) );
		$this->assertSame( 1000, PayPal::refunded_after( 0, 1000, 0, null, true ) );
	}

	/**
	 * A resent 2Checkout refund notification has the same key; a second refund does not.
	 */
	public function test_twocheckout_refund_key(): void {
		$params = array(
			'REFNO'            => '12345',
			'IPN_DATE'         => '20260916120000',
			'IPN_TOTALGENERAL' => '5.00',
			'IPN_CURRENCY'     => 'usd',
			'HASH'             => 'abc',
		);

		$resent         = $params;
		$resent['HASH'] = 'def';

		$second             = $params;
		$second['IPN_DATE'] = '20260917120000';

		$this->assertSame( TwoCheckout::refund_key( $params ), TwoCheckout::refund_key( $resent ) );
		$this->assertNotSame( TwoCheckout::refund_key( $params ), TwoCheckout::refund_key( $second ) );
	}
}
