<?php
/**
 * Tests for the PayPal first-payment and 2Checkout trial guards.
 *
 * @package WopCartDirect
 */

declare(strict_types=1);

namespace WopCartDirect\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WopCartDirect\Gateways\PayPal;
use WopCartDirect\Gateways\TwoCheckout;
use WopCart\Checkout\Checkout;
use WopCart\Gateways\CartProfile;
use WopCart\Gateways\Registry;
use WopCart\Gateways\Stripe;
use WopCart\Model\Order;
use WopCart\Model\Price;

/**
 * PayPal subscriptions must charge exactly the order today (1.1.3); 2Checkout cannot bill trials,
 * sign-up fees, first-payment-only discounts or introductory prices (1.1.3).
 *
 * @covers \WopCartDirect\Gateways\PayPal::cart_error
 * @covers \WopCartDirect\Gateways\PayPal::plan_amounts
 * @covers \WopCartDirect\Gateways\TwoCheckout::cart_error
 * @covers \WopCartDirect\Gateways\TwoCheckout::refusal
 * @covers \WopCartDirect\Gateways\TwoCheckout::process
 * @covers \WopCart\Gateways\CartProfile
 * @covers \WopCart\Gateways\Registry::capable
 * @covers \WopCart\Checkout\Checkout::gateway_refusal
 */
final class PayPalTwoCheckoutGuardTest extends TestCase {

	private const PAYPAL_MESSAGE = "This subscription can't be paid with PayPal. Please choose another payment method.";

	private const TWOCO_MESSAGE = "Subscriptions with a free trial can't be paid with 2Checkout. Please choose another payment method.";

	private const TWOCO_FEE_MESSAGE = "Subscriptions with a sign-up fee can't be paid with 2Checkout. Please choose another payment method.";

	private const TWOCO_INTRO_MESSAGE = "Subscriptions with a first-payment discount or introductory price can't be paid with 2Checkout. Please choose another payment method.";

	/**
	 * A profile with one recurring line.
	 *
	 * @param array<string,int|float> $figures Overrides for subtotal, discount, recurring_subtotal, signup_fee, tax_rate, tax.
	 * @param int                     $trial   Trial days.
	 * @param int                     $one_off One-off lines.
	 *
	 * @return CartProfile
	 */
	private function single( array $figures = array(), int $trial = 0, int $one_off = 0 ): CartProfile {
		$figures += array(
			'subtotal'           => 1900,
			'discount'           => 0,
			'recurring_subtotal' => 1900,
			'signup_fee'         => 0,
			'tax_rate'           => 0.0,
			'tax'                => 0,
		);

		return new CartProfile(
			array(
				CartProfile::line(
					new Price( 'p', 'Plan', 1900, true, 'month', 1, $trial ),
					(int) $figures['subtotal'],
					(int) $figures['discount'],
					(int) $figures['recurring_subtotal'],
					(int) $figures['signup_fee'],
					(float) $figures['tax_rate'],
					(int) $figures['tax']
				),
			),
			$one_off
		);
	}

	/**
	 * A 19.00 + 5.00 sign-up fee line at 20% tax, as Calculator writes it.
	 *
	 * @param int $trial Trial days.
	 *
	 * @return CartProfile
	 */
	private function taxed_fee_line( int $trial = 0 ): CartProfile {
		return $trial > 0
			? $this->single(
				array(
					'subtotal'   => 500,
					'signup_fee' => 500,
					'tax_rate'   => 20.0,
					'tax'        => 100,
				),
				$trial
			)
			: $this->single(
				array(
					'subtotal'   => 2400,
					'signup_fee' => 500,
					'tax_rate'   => 20.0,
					'tax'        => 480,
				)
			);
	}

	/**
	 * Plain, trial, every-renewal discount and taxed sign-up fee subscriptions stay payable.
	 */
	public function test_paypal_allows_what_the_plan_charges_exactly(): void {
		$paypal = new PayPal();

		$this->assertNull( $paypal->cart_error( new CartProfile() ), 'No subscription.' );
		$this->assertNull( $paypal->cart_error( new CartProfile( array(), 3 ) ), 'One-off cart.' );
		$this->assertNull( $paypal->cart_error( $this->single() ) );
		$this->assertNull(
			$paypal->cart_error(
				$this->single(
					array(
						'tax_rate' => 20.0,
						'tax'      => 380,
					)
				)
			),
			'Taxed.'
		);
		$this->assertNull( $paypal->cart_error( $this->single( array( 'subtotal' => 0 ), 14 ) ), 'Free trial.' );
		$this->assertNull(
			$paypal->cart_error(
				$this->single(
					array(
						'discount'           => 190,
						'recurring_subtotal' => 1710,
					)
				)
			),
			'A discount on every renewal lowers the plan price too.'
		);
		$this->assertNull( $paypal->cart_error( $this->taxed_fee_line() ), 'Sign-up fee with tax.' );
		$this->assertNull( $paypal->cart_error( $this->taxed_fee_line( 14 ) ), 'Sign-up fee during a trial.' );
	}

	/**
	 * One-off lines, first-payment-only discounts and introductory prices are refused.
	 */
	public function test_paypal_refuses_first_payment_differences(): void {
		$paypal = new PayPal();

		$this->assertSame( self::PAYPAL_MESSAGE, $paypal->cart_error( $this->single( array(), 0, 1 ) ), 'One-off line alongside.' );
		$this->assertSame( self::PAYPAL_MESSAGE, $paypal->cart_error( $this->single( array( 'discount' => 950 ) ) ), 'First-payment-only discount.' );
		$this->assertSame( self::PAYPAL_MESSAGE, $paypal->cart_error( $this->single( array( 'subtotal' => 900 ) ) ), 'Introductory sale price renewing at regular.' );
		$this->assertSame( self::PAYPAL_MESSAGE, $paypal->cart_error( $this->single( array( 'subtotal' => 900 ), 14 ) ), 'Charge during a trial that is not a sign-up fee.' );
		$this->assertSame(
			self::PAYPAL_MESSAGE,
			$paypal->cart_error(
				$this->single(
					array(
						'subtotal'   => 2400,
						'discount'   => 500,
						'signup_fee' => 500,
					)
				)
			),
			'Discount taken off the sign-up fee only.'
		);
		$this->assertSame(
			self::PAYPAL_MESSAGE,
			$paypal->cart_error(
				$this->single(
					array(
						'tax_rate' => 20.0,
						'tax'      => 0,
					)
				)
			),
			'Order untaxed (reverse charge) while the plan would add tax.'
		);

		$two = new CartProfile(
			array(
				CartProfile::line( null, 1900, 0, 1900, 0 ),
				CartProfile::line( null, 900, 0, 900, 0 ),
			)
		);

		$this->assertSame( 'Please buy one subscription at a time with this payment method.', $paypal->cart_error( $two ), 'Two subscriptions keep their message.' );
	}

	/**
	 * The plan's setup_fee carries the fee's tax, and fee plus first cycle equal the order line.
	 */
	public function test_paypal_setup_fee_matches_order_line(): void {
		$this->assertSame(
			array(
				'regular'   => 2280,
				'setup_fee' => 600,
			),
			PayPal::plan_amounts( 1900, 20.0, 500, 2880, false ),
			'19.00 + 5.00 fee at 20%: 22.80 per cycle, 6.00 setup fee including tax.'
		);
		$this->assertSame(
			array(
				'regular'   => 2280,
				'setup_fee' => 600,
			),
			PayPal::plan_amounts( 1900, 20.0, 500, 600, true ),
			'During a trial the whole line is the setup fee.'
		);
		$this->assertSame(
			array(
				'regular'   => 1900,
				'setup_fee' => 0,
			),
			PayPal::plan_amounts( 1900, 0.0, 0, 1900, false ),
			'No fee, no setup fee.'
		);

		// 10% on 19.05 + 5.05: the line taxes 24.10 as 2.41, separately it is 1.91 + 0.51.
		$amounts = PayPal::plan_amounts( 1905, 10.0, 505, 2651, false );

		$this->assertSame( 2096, $amounts['regular'] );
		$this->assertSame( 2651, $amounts['regular'] + $amounts['setup_fee'], 'The setup fee absorbs the rounding cent.' );
		$this->assertNull(
			( new PayPal() )->cart_error(
				$this->single(
					array(
						'subtotal'           => 2410,
						'recurring_subtotal' => 1905,
						'signup_fee'         => 505,
						'tax_rate'           => 10.0,
						'tax'                => 241,
					)
				)
			)
		);
	}

	/**
	 * PayPal::process() refuses the same order, before any API call.
	 */
	public function test_paypal_process_refuses_one_off_alongside(): void {
		require_once __DIR__ . '/PlatformServerStubs.php';

		$order = new Order(
			array(
				'id'       => 7,
				'currency' => 'USD',
				'total'    => 2900,
			)
		);

		$line = static fn( int $id, bool $recurring ): \WopCart\Model\OrderItem => new \WopCart\Model\OrderItem(
			array(
				'id'           => $id,
				'product_id'   => 0,
				'price_id'     => '',
				'name'         => 'Line',
				'qty'          => 1,
				'subtotal'     => $recurring ? 1900 : 1000,
				'discount'     => 0,
				'tax'          => 0,
				'total'        => $recurring ? 1900 : 1000,
				'tax_rate'     => 0,
				'is_recurring' => $recurring ? 1 : 0,
				'meta'         => '{}',
			)
		);

		$items = new \ReflectionProperty( Order::class, 'items' );
		$items->setValue( $order, array( $line( 1, true ), $line( 2, false ) ) );

		$result = ( new PayPal() )->process( $order, array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wopcart_paypal_unsupported_subscription', $result->get_error_code() );
		$this->assertSame( self::PAYPAL_MESSAGE, $result->get_error_message() );
	}

	/**
	 * 2Checkout refuses any recurring line with a trial, and nothing else.
	 */
	public function test_twocheckout_trial_rule(): void {
		$twoco = new TwoCheckout();

		$this->assertNull( $twoco->cart_error( new CartProfile() ) );
		$this->assertNull( $twoco->cart_error( new CartProfile( array(), 2 ) ), 'One-off cart.' );
		$this->assertNull( $twoco->cart_error( $this->single() ) );
		$this->assertNull( $twoco->cart_error( $this->single( array(), 0, 1 ) ), 'With a one-off line.' );

		$this->assertSame( self::TWOCO_MESSAGE, $twoco->cart_error( $this->single( array( 'subtotal' => 0 ), 14 ) ), 'Free trial.' );
		$this->assertSame( self::TWOCO_MESSAGE, $twoco->cart_error( $this->taxed_fee_line( 7 ) ), 'Trial with a sign-up fee.' );
		$this->assertSame(
			self::TWOCO_MESSAGE,
			$twoco->cart_error(
				new CartProfile(
					array(
						CartProfile::line( new Price( 'a', 'A', 900, true, 'month', 1, 0 ), 900, 0, 900, 0 ),
						CartProfile::line( new Price( 'b', 'B', 900, true, 'month', 1, 30 ), 0, 0, 900, 0 ),
					)
				)
			),
			'Any trial line.'
		);
	}

	/**
	 * 2Checkout renews at the line price, so today's net charge must equal the renewal amount (1.1.3).
	 */
	public function test_twocheckout_first_charge_must_equal_renewal(): void {
		$twoco = new TwoCheckout();

		$this->assertNull(
			$twoco->cart_error(
				$this->single(
					array(
						'tax_rate' => 20.0,
						'tax'      => 380,
					)
				)
			),
			'Taxed.'
		);
		$this->assertNull(
			$twoco->cart_error(
				$this->single(
					array(
						'discount'           => 190,
						'recurring_subtotal' => 1710,
					)
				)
			),
			'A discount on every renewal.'
		);

		$this->assertSame( self::TWOCO_FEE_MESSAGE, $twoco->cart_error( $this->taxed_fee_line() ), 'Sign-up fee without a trial.' );
		$this->assertSame(
			self::TWOCO_FEE_MESSAGE,
			$twoco->cart_error(
				$this->single(
					array(
						'subtotal'   => 2400,
						'discount'   => 500,
						'signup_fee' => 500,
					)
				)
			),
			'Sign-up fee cancelled by a discount is still refused.'
		);
		$this->assertSame( self::TWOCO_MESSAGE, $twoco->cart_error( $this->taxed_fee_line( 7 ) ), 'A trial keeps the trial message.' );

		$this->assertSame( self::TWOCO_INTRO_MESSAGE, $twoco->cart_error( $this->single( array( 'discount' => 950 ) ) ), 'First-payment-only discount.' );
		$this->assertSame( self::TWOCO_INTRO_MESSAGE, $twoco->cart_error( $this->single( array( 'subtotal' => 900 ) ) ), 'Introductory sale price.' );
		$this->assertSame(
			self::TWOCO_INTRO_MESSAGE,
			$twoco->cart_error(
				new CartProfile(
					array(
						CartProfile::line( null, 900, 0, 900, 0 ),
						CartProfile::line( null, 900, 0, 1900, 0 ),
					)
				)
			),
			'Any introductory line.'
		);
	}

	/**
	 * TwoCheckout::process() refuses a sign-up fee and a first-payment discount before building the link.
	 */
	public function test_twocheckout_process_refusal_codes(): void {
		require_once __DIR__ . '/PlatformServerStubs.php';

		$order = static function ( array $item ): Order {
			$order = new Order(
				array(
					'id'       => 9,
					'currency' => 'USD',
					'total'    => (int) $item['total'],
				)
			);

			$items = new \ReflectionProperty( Order::class, 'items' );
			$items->setValue(
				$order,
				array(
					new \WopCart\Model\OrderItem(
						$item + array(
							'id'           => 1,
							'product_id'   => 0,
							'price_id'     => '',
							'name'         => 'Plan',
							'qty'          => 1,
							'tax'          => 0,
							'tax_rate'     => 0,
							'is_recurring' => 1,
						)
					),
				)
			);

			return $order;
		};

		$fee = ( new TwoCheckout() )->process(
			$order(
				array(
					'subtotal' => 2400,
					'discount' => 0,
					'total'    => 2400,
					'meta'     => '{"recurring_subtotal":1900,"signup_fee":500}',
				)
			),
			array()
		);

		$this->assertInstanceOf( \WP_Error::class, $fee );
		$this->assertSame( 'wopcart_2co_signup_fee', $fee->get_error_code() );
		$this->assertSame( self::TWOCO_FEE_MESSAGE, $fee->get_error_message() );

		$discount = ( new TwoCheckout() )->process(
			$order(
				array(
					'subtotal' => 1900,
					'discount' => 950,
					'total'    => 950,
					'meta'     => '{"recurring_subtotal":1900}',
				)
			),
			array()
		);

		$this->assertInstanceOf( \WP_Error::class, $discount );
		$this->assertSame( 'wopcart_2co_first_payment', $discount->get_error_code() );
		$this->assertSame( self::TWOCO_INTRO_MESSAGE, $discount->get_error_message() );
	}

	/**
	 * The registry leaves each gateway out for exactly the carts it refuses.
	 */
	public function test_capable_filters_paypal_and_twocheckout(): void {
		$gateways = array(
			'stripe'      => new Stripe(),
			'paypal'      => new PayPal(),
			'twocheckout' => new TwoCheckout(),
		);

		$slugs = static fn( CartProfile $profile ): array => array_keys( Registry::capable( $gateways, true, false, $profile ) );

		$this->assertSame( array( 'stripe', 'paypal', 'twocheckout' ), $slugs( $this->single() ) );
		$this->assertSame(
			array( 'stripe', 'paypal', 'twocheckout' ),
			$slugs(
				$this->single(
					array(
						'discount'           => 190,
						'recurring_subtotal' => 1710,
					)
				)
			),
			'Every-renewal discount.'
		);
		$this->assertSame( array( 'stripe', 'paypal' ), $slugs( $this->taxed_fee_line() ), 'Taxed sign-up fee.' );
		$this->assertSame( array( 'stripe', 'paypal' ), $slugs( $this->single( array( 'subtotal' => 0 ), 14 ) ), 'Trial.' );
		$this->assertSame( array( 'stripe' ), $slugs( $this->single( array( 'subtotal' => 900 ) ) ), 'Introductory price.' );
		$this->assertSame( array( 'stripe' ), $slugs( $this->single( array( 'discount' => 950 ) ) ), 'First-payment-only discount.' );
		$this->assertSame( array( 'stripe', 'twocheckout' ), $slugs( $this->single( array(), 0, 1 ) ), 'One-off alongside.' );
		$this->assertSame( array( 'stripe' ), $slugs( $this->single( array( 'subtotal' => 900 ), 14 ) ), 'Trial with a non-fee charge.' );
	}

	/**
	 * Checkout names the reason the posted gateway was refused.
	 */
	public function test_checkout_refusal_messages(): void {
		$this->assertSame( self::PAYPAL_MESSAGE, Checkout::gateway_refusal( new PayPal(), false, $this->single( array(), 0, 1 ) ) );
		$this->assertSame( self::TWOCO_MESSAGE, Checkout::gateway_refusal( new TwoCheckout(), false, $this->single( array( 'subtotal' => 0 ), 14 ) ) );
		$this->assertSame( self::TWOCO_FEE_MESSAGE, Checkout::gateway_refusal( new TwoCheckout(), false, $this->taxed_fee_line() ) );
	}
}
