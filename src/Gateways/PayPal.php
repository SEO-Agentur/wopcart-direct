<?php
/**
 * PayPal gateway.
 *
 * @package WopCartDirect
 */

declare(strict_types=1);

namespace WopCartDirect\Gateways;

use WopCart\Gateways\AbstractGateway;
use WopCart\Gateways\CartProfile;
use WopCart\Model\Order;
use WopCart\Model\OrderItem;
use WopCart\Model\Price;
use WopCart\Model\Subscription;
use WopCart\Repository\OrderRepository;
use WopCart\Repository\SubscriptionRepository;
use WopCart\Subscriptions\SubscriptionManager;
use WopCart\Support\Http;
use WopCart\Support\Logger;
use WopCart\Support\Money;

/**
 * Payments and subscriptions through PayPal's REST APIs.
 *
 * One-off purchases use Orders v2 with an approve-then-capture flow. Recurring
 * purchases use Billing Subscriptions, which needs a catalogue product and a plan
 * to exist at PayPal first; both are created on demand and their identifiers are
 * cached against the WordPress product so the round trip happens once per tier
 * rather than once per sale.
 */
final class PayPal extends AbstractGateway {

	/**
	 * Features supported.
	 *
	 * @var string[]
	 */
	protected array $supports = array( 'refunds', 'subscriptions', 'trials', 'webhooks' );

	/**
	 * Transient holding the OAuth access token.
	 */
	private const TOKEN_TRANSIENT = 'wopcart_paypal_token';

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'paypal';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'PayPal', 'wopcart-direct' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	protected function has_credentials(): bool {
		return '' !== $this->client_id() && '' !== $this->client_secret();
	}

	/**
	 * API base for the active mode.
	 *
	 * @return string
	 */
	private function base(): string {
		return $this->is_test() ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
	}

	/**
	 * Client ID for the active mode.
	 *
	 * @return string
	 */
	private function client_id(): string {
		return trim( (string) $this->setting( $this->is_test() ? 'paypal_test_client_id' : 'paypal_live_client_id' ) );
	}

	/**
	 * Client secret for the active mode.
	 *
	 * @return string
	 */
	private function client_secret(): string {
		return trim( (string) $this->setting( $this->is_test() ? 'paypal_test_secret' : 'paypal_live_secret' ) );
	}

	/**
	 * Fetch an OAuth token, cached until shortly before it expires.
	 *
	 * @return string|\WP_Error
	 */
	private function token(): string|\WP_Error {
		$cached = get_transient( self::TOKEN_TRANSIENT . ( $this->is_test() ? '_test' : '_live' ) );

		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$response = Http::json(
			'POST',
			$this->base() . '/v1/oauth2/token',
			array(
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $this->client_id() . ':' . $this->client_secret() ),
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => 'grant_type=client_credentials',
				'source'  => 'gateway.paypal',
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$token = (string) ( $response['access_token'] ?? '' );

		if ( '' === $token ) {
			return new \WP_Error( 'wopcart_paypal_no_token', __( 'PayPal did not return an access token.', 'wopcart-direct' ) );
		}

		$expires = max( 60, (int) ( $response['expires_in'] ?? 3000 ) - 120 );

		set_transient( self::TOKEN_TRANSIENT . ( $this->is_test() ? '_test' : '_live' ), $token, $expires );

		return $token;
	}

	/**
	 * Call the PayPal API.
	 *
	 * @param string              $method  HTTP verb.
	 * @param string              $path    Path below the API base.
	 * @param array<string,mixed> $body    Request body.
	 * @param array<string,string> $extra  Additional headers.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	private function api( string $method, string $path, array $body = array(), array $extra = array() ): array|\WP_Error {
		$token = $this->token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$args = array(
			'headers' => array_merge(
				array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				$extra
			),
			'source'  => 'gateway.paypal',
		);

		if ( array() !== $body || 'GET' !== strtoupper( $method ) ) {
			$args['body'] = $body;
		}

		return Http::json( $method, $this->base() . '/' . ltrim( $path, '/' ), $args );
	}

	/**
	 * Format a minor-unit amount the way PayPal expects it.
	 *
	 * @param int    $minor    Amount.
	 * @param string $currency Currency code.
	 *
	 * @return string
	 */
	private function amount( int $minor, string $currency ): string {
		return self::format_amount( $minor, $currency );
	}

	/**
	 * Pure: a minor-unit amount as PayPal's decimal string.
	 *
	 * @param int    $minor    Amount.
	 * @param string $currency Currency code.
	 *
	 * @return string
	 */
	public static function format_amount( int $minor, string $currency ): string {
		return number_format( Money::to_major( $minor, $currency ), Money::decimals( $currency ), '.', '' );
	}

	/**
	 * Pure: amount and shipping of a purchase unit for an order that ships.
	 *
	 * The breakdown uses gross figures only: `shipping` is the shipping charge
	 * with its tax, and `item_total` is everything else (goods after discounts,
	 * with their tax). Both are exact integers in minor units subtracted from the
	 * order total, so item_total + shipping equals the amount with no rounding,
	 * which PayPal requires or it rejects the order. Tax is not broken out: the
	 * store has already computed it and PayPal does not need it to charge.
	 *
	 * @param array<string,mixed> $shipping Order shipping record (ShippingService::for_order()).
	 * @param int                 $total    Order total, minor units.
	 * @param string              $currency Currency code.
	 *
	 * @return array{amount:array<string,mixed>,shipping:array<string,mixed>}|\WP_Error|null Null when nothing ships.
	 */
	public static function shipping_unit( array $shipping, int $total, string $currency ): array|\WP_Error|null {
		if ( array() === $shipping ) {
			return null;
		}

		$address = \WopCart\Shipping\ShippingAddress::from_array( is_array( $shipping['address'] ?? null ) ? $shipping['address'] : array() );
		$charge  = max( 0, (int) ( $shipping['total'] ?? 0 ) );
		$items   = $total - $charge;

		if ( '' === $address->address_1 || 1 !== preg_match( '/\A[A-Z]{2}\z/', $address->country ) ) {
			return new \WP_Error( 'wopcart_paypal_no_address', __( 'The delivery address is incomplete, so PayPal cannot be used for this order.', 'wopcart-direct' ) );
		}

		if ( $items < 0 ) {
			return new \WP_Error( 'wopcart_paypal_breakdown', __( 'The order total could not be sent to PayPal. Please choose another payment method.', 'wopcart-direct' ) );
		}

		$currency = strtoupper( $currency );
		$name     = '' !== $address->name() ? $address->name() : $address->company;

		return array(
			'amount'   => array(
				'currency_code' => $currency,
				'value'         => self::format_amount( $total, $currency ),
				'breakdown'     => array(
					'item_total' => array(
						'currency_code' => $currency,
						'value'         => self::format_amount( $items, $currency ),
					),
					'shipping'   => array(
						'currency_code' => $currency,
						'value'         => self::format_amount( $charge, $currency ),
					),
				),
			),
			'shipping' => array(
				'type'    => 'SHIPPING',
				'name'    => array( 'full_name' => substr( $name, 0, 300 ) ),
				'address' => array_filter(
					array(
						'address_line_1' => substr( $address->address_1, 0, 300 ),
						'address_line_2' => substr( $address->address_2, 0, 300 ),
						'admin_area_2'   => substr( $address->city, 0, 120 ),
						'admin_area_1'   => substr( $address->region, 0, 300 ),
						'postal_code'    => substr( $address->postal_code, 0, 60 ),
						'country_code'   => $address->country,
					),
					static fn( string $part ): bool => '' !== $part
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Order               $order Pending order.
	 * @param array<string,mixed> $input Checkout input.
	 *
	 * @return array{redirect?:string}|\WP_Error
	 */
	public function process( Order $order, array $input ): array|\WP_Error {
		$recurring = array();

		foreach ( $order->items() as $item ) {
			if ( $item->is_recurring() ) {
				$recurring[] = $item;
			}
		}

		$unsupported = $this->cart_error( CartProfile::from_order( $order ) );

		if ( null !== $unsupported ) {
			return new \WP_Error(
				count( $recurring ) > 1 ? 'wopcart_paypal_multiple_subscriptions' : 'wopcart_paypal_unsupported_subscription',
				$unsupported
			);
		}

		return array() !== $recurring
			? $this->start_subscription( $order, $recurring[0] )
			: $this->start_payment( $order );
	}

	/**
	 * {@inheritDoc}
	 *
	 * A PayPal subscription follows one billing plan, and one is created per
	 * order, so a second recurring line would never be billed. Nothing else is
	 * charged with it: PayPal takes the plan's setup fee plus, without a trial,
	 * its first regular cycle. A one-off line, a first-payment-only discount or an
	 * introductory price renewing at the regular price would make today's order
	 * total differ from that, so such carts are refused.
	 *
	 * @param CartProfile $profile Cart or order profile.
	 *
	 * @return string|null
	 */
	public function cart_error( CartProfile $profile ): ?string {
		if ( ! $profile->has_recurring() ) {
			return null;
		}

		if ( count( $profile->recurring ) > 1 ) {
			return __( 'Please buy one subscription at a time with this payment method.', 'wopcart-direct' );
		}

		$message = __( "This subscription can't be paid with PayPal. Please choose another payment method.", 'wopcart-direct' );

		if ( $profile->one_off > 0 ) {
			return $message;
		}

		$line      = $profile->recurring[0];
		$has_trial = $line['trial_days'] > 0;
		$today_net = $line['subtotal'] - $line['discount'];

		if ( $today_net !== ( $has_trial ? 0 : $line['recurring_subtotal'] ) + $line['signup_fee'] ) {
			return $message;
		}

		$today   = $today_net + $line['tax'];
		$amounts = self::plan_amounts( $line['recurring_subtotal'], $line['tax_rate'], $line['signup_fee'], $today, $has_trial );
		$charged = ( $has_trial ? 0 : $amounts['regular'] ) + $amounts['setup_fee'];

		return $charged !== $today || ( $line['signup_fee'] > 0 ) !== ( $amounts['setup_fee'] > 0 ) ? $message : null;
	}

	/**
	 * Pure: the gross amounts a billing plan charges.
	 *
	 * The regular cycle is the net renewal plus its tax. The setup fee is what the
	 * order line charges today beyond that first cycle (all of it during a trial),
	 * so it carries the fee's tax and absorbs the cent a line taxed as one sum can
	 * differ by from two amounts taxed separately. cart_error() refuses lines
	 * where the net figures do not add up, so the fee is never a discount.
	 *
	 * @param int   $recurring_net  Net renewal amount.
	 * @param float $tax_rate       Tax rate, percent.
	 * @param int   $signup_fee_net Net sign-up fee, 0 for none.
	 * @param int   $line_total     Gross charged today for the line.
	 * @param bool  $has_trial      Whether the plan starts with a free trial.
	 *
	 * @return array{regular:int,setup_fee:int}
	 */
	public static function plan_amounts( int $recurring_net, float $tax_rate, int $signup_fee_net, int $line_total, bool $has_trial ): array {
		$regular = $recurring_net + (int) round( $recurring_net * ( $tax_rate / 100 ) );

		return array(
			'regular'   => $regular,
			'setup_fee' => $signup_fee_net > 0 ? max( 0, $line_total - ( $has_trial ? 0 : $regular ) ) : 0,
		);
	}

	/**
	 * Create a one-off PayPal order and hand back its approval URL.
	 *
	 * @param Order $order Order.
	 *
	 * @return array{redirect:string}|\WP_Error
	 */
	private function start_payment( Order $order ): array|\WP_Error {
		$currency = $order->currency();
		$shipping = self::shipping_unit( \WopCart\Shipping\ShippingService::for_order( $order ), $order->total(), $currency );

		if ( is_wp_error( $shipping ) ) {
			return $shipping;
		}

		$payload = array(
			'intent'         => 'CAPTURE',
			'purchase_units' => array(
				array(
					'reference_id' => (string) $order->id(),
					'custom_id'    => (string) $order->id(),
					'invoice_id'   => $order->number() . '-' . $order->id(),
					'description'  => substr( $this->description( $order ), 0, 127 ),
					'amount'       => array(
						'currency_code' => $currency,
						'value'         => $this->amount( $order->total(), $currency ),
					),
				),
			),
			'payment_source' => array(
				'paypal' => array(
					'experience_context' => array(
						'return_url'          => $this->return_url( $order ),
						'cancel_url'          => $this->cancel_url( $order ),
						'user_action'         => 'PAY_NOW',
						// The address was collected and validated at checkout; the buyer
						// sees it at PayPal but cannot change it there.
						'shipping_preference' => null === $shipping ? 'NO_SHIPPING' : 'SET_PROVIDED_ADDRESS',
					),
				),
			),
		);

		if ( null !== $shipping ) {
			$payload['purchase_units'][0]['amount']   = $shipping['amount'];
			$payload['purchase_units'][0]['shipping'] = $shipping['shipping'];
		}

		$response = $this->api(
			'POST',
			'v2/checkout/orders',
			$payload,
			array( 'PayPal-Request-Id' => 'wopcart-' . $order->id() )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$order->set_meta( 'paypal_order_id', (string) ( $response['id'] ?? '' ) );
		$order->set_status( Order::PROCESSING );

		$approve = $this->link( $response, 'payer-action' ) ?: $this->link( $response, 'approve' );

		if ( '' === $approve ) {
			return new \WP_Error( 'wopcart_paypal_no_approve', __( 'PayPal did not return an approval link.', 'wopcart-direct' ) );
		}

		return array( 'redirect' => $approve );
	}

	/**
	 * Create a PayPal subscription and hand back its approval URL.
	 *
	 * @param Order     $order Order.
	 * @param OrderItem $item  The recurring line driving the subscription.
	 *
	 * @return array{redirect:string}|\WP_Error
	 */
	private function start_subscription( Order $order, OrderItem $item ): array|\WP_Error {
		// A billing plan charges the plan price only; shipping would go unpaid.
		if ( array() !== \WopCart\Shipping\ShippingService::for_order( $order ) ) {
			return new \WP_Error( 'wopcart_paypal_subscription_shipping', __( 'PayPal subscriptions cannot include shipping. Please choose another payment method.', 'wopcart-direct' ) );
		}

		$plan_id = $this->ensure_plan( $item, $order->currency() );

		if ( is_wp_error( $plan_id ) ) {
			return $plan_id;
		}

		$billing = $order->billing();

		$payload = array(
			'plan_id'             => $plan_id,
			'custom_id'           => (string) $order->id(),
			'subscriber'          => array(
				'email_address' => $order->email(),
				'name'          => array(
					'given_name' => substr( (string) ( $billing['first_name'] ?? '' ), 0, 140 ),
					'surname'    => substr( (string) ( $billing['last_name'] ?? '' ), 0, 140 ),
				),
			),
			'application_context' => array(
				'return_url'          => $this->return_url( $order ),
				'cancel_url'          => $this->cancel_url( $order ),
				'user_action'         => 'SUBSCRIBE_NOW',
				'shipping_preference' => 'NO_SHIPPING',
			),
		);

		$response = $this->api(
			'POST',
			'v1/billing/subscriptions',
			$payload,
			array( 'PayPal-Request-Id' => 'wopcart-sub-' . $order->id() )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$order->set_meta( 'paypal_subscription_id', (string) ( $response['id'] ?? '' ) );
		$order->set_status( Order::PROCESSING );

		$approve = $this->link( $response, 'approve' );

		if ( '' === $approve ) {
			return new \WP_Error( 'wopcart_paypal_no_approve', __( 'PayPal did not return an approval link.', 'wopcart-direct' ) );
		}

		return array( 'redirect' => $approve );
	}

	/**
	 * Pure: the post meta key a minted PayPal plan is remembered under.
	 *
	 * A plan at PayPal is immutable, so the key has to name every part of the
	 * plan: change one and a different plan is needed. It used to cover the price
	 * option, the currency, the amount, the mode and the sign-up fee, but not the
	 * schedule — so a merchant who switched a monthly plan to yearly, shortened a
	 * trial or changed how many times it bills got the old plan handed back and
	 * PayPal kept billing the old schedule for ever, with nothing in the store to
	 * show it.
	 *
	 * @param string $price_id   Price option identifier.
	 * @param string $currency   Order currency.
	 * @param int    $gross      Recurring amount including tax, minor units.
	 * @param int    $setup_fee  Sign-up fee, minor units.
	 * @param Price  $option     Price option the schedule comes from.
	 * @param bool   $is_test    Whether the sandbox is in use.
	 *
	 * @return string
	 */
	public static function plan_cache_key( string $price_id, string $currency, int $gross, int $setup_fee, Price $option, bool $is_test ): string {
		$signature = implode(
			'|',
			array(
				$price_id,
				strtoupper( $currency ),
				$gross,
				$setup_fee,
				$option->period,
				max( 1, $option->frequency ),
				max( 0, $option->trial_days ),
				max( 0, $option->bill_times ),
			)
		);

		// Hashed because a post meta key is limited to 255 bytes and a price id is
		// free text. The mode stays readable so the two sets can be told apart.
		return '_wopcart_paypal_plan_' . ( $is_test ? 'test' : 'live' ) . '_' . md5( $signature );
	}

	/**
	 * Find or create the PayPal plan matching a recurring line.
	 *
	 * The plan identifier is cached on the WordPress product, keyed by tier and
	 * currency, because a plan's price is immutable at PayPal: changing a price
	 * means minting a new plan rather than editing the old one.
	 *
	 * @param OrderItem $item     Recurring line.
	 * @param string    $currency Order currency.
	 *
	 * @return string|\WP_Error Plan ID.
	 */
	private function ensure_plan( OrderItem $item, string $currency ): string|\WP_Error {
		$product = $item->product();

		if ( null === $product ) {
			return new \WP_Error( 'wopcart_paypal_no_product', __( 'The subscription product no longer exists.', 'wopcart-direct' ) );
		}

		$option = $product->price_option( '' === $item->price_id() ? null : $item->price_id() );

		if ( null === $option ) {
			return new \WP_Error( 'wopcart_paypal_no_price', __( 'The subscription price is no longer available.', 'wopcart-direct' ) );
		}

		$meta    = $item->meta();
		$amounts = self::plan_amounts(
			(int) ( $meta['recurring_subtotal'] ?? $item->subtotal() ),
			$item->tax_rate(),
			(int) ( $meta['signup_fee'] ?? 0 ),
			$item->total(),
			$option->trial_days > 0
		);

		$gross     = $amounts['regular'];
		$setup_fee = $amounts['setup_fee'];

		$cache_key = self::plan_cache_key( $item->price_id(), $currency, $gross, $setup_fee, $option, $this->is_test() );
		$cached    = (string) get_post_meta( $product->id(), $cache_key, true );

		if ( '' !== $cached ) {
			return $cached;
		}

		$catalog_key = '_wopcart_paypal_product_' . ( $this->is_test() ? 'test' : 'live' );
		$catalog_id  = (string) get_post_meta( $product->id(), $catalog_key, true );

		if ( '' === $catalog_id ) {
			$created = $this->api(
				'POST',
				'v1/catalogs/products',
				array(
					'name'        => substr( $product->name(), 0, 127 ),
					'description' => substr( $product->excerpt( 20 ), 0, 256 ),
					'type'        => 'DIGITAL',
					'category'    => 'SOFTWARE',
					'home_url'    => $product->permalink(),
				)
			);

			if ( is_wp_error( $created ) ) {
				return $created;
			}

			$catalog_id = (string) ( $created['id'] ?? '' );

			if ( '' === $catalog_id ) {
				return new \WP_Error( 'wopcart_paypal_no_catalog', __( 'PayPal did not return a product ID.', 'wopcart-direct' ) );
			}

			update_post_meta( $product->id(), $catalog_key, $catalog_id );
		}

		$cycles   = array();
		$sequence = 1;

		if ( $option->trial_days > 0 ) {
			$cycles[] = array(
				'frequency'      => array(
					'interval_unit'  => 'DAY',
					'interval_count' => min( 365, $option->trial_days ),
				),
				'tenure_type'    => 'TRIAL',
				'sequence'       => $sequence,
				'total_cycles'   => 1,
				'pricing_scheme' => array(
					'fixed_price' => array(
						'currency_code' => $currency,
						'value'         => $this->amount( 0, $currency ),
					),
				),
			);

			++$sequence;
		}

		$cycles[] = array(
			'frequency'      => array(
				'interval_unit'  => strtoupper( $option->period ),
				'interval_count' => max( 1, $option->frequency ),
			),
			'tenure_type'    => 'REGULAR',
			'sequence'       => $sequence,
			'total_cycles'   => max( 0, $option->bill_times ),
			'pricing_scheme' => array(
				'fixed_price' => array(
					'currency_code' => $currency,
					'value'         => $this->amount( $gross, $currency ),
				),
			),
		);

		$preferences = array(
			'auto_bill_outstanding'     => true,
			'setup_fee_failure_action'  => 'CANCEL',
			'payment_failure_threshold' => 3,
		);

		if ( $setup_fee > 0 ) {
			$preferences['setup_fee'] = array(
				'currency_code' => $currency,
				'value'         => $this->amount( $setup_fee, $currency ),
			);
		}

		$plan = $this->api(
			'POST',
			'v1/billing/plans',
			array(
				'product_id'          => $catalog_id,
				'name'                => substr( $product->name() . ' — ' . $option->interval_label(), 0, 127 ),
				'status'              => 'ACTIVE',
				'billing_cycles'      => $cycles,
				'payment_preferences' => $preferences,
			)
		);

		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		$plan_id = (string) ( $plan['id'] ?? '' );

		if ( '' === $plan_id ) {
			return new \WP_Error( 'wopcart_paypal_no_plan', __( 'PayPal did not return a plan ID.', 'wopcart-direct' ) );
		}

		update_post_meta( $product->id(), $cache_key, $plan_id );

		return $plan_id;
	}

	/**
	 * Pull a HATEOAS link out of a PayPal response.
	 *
	 * @param array<string,mixed> $response Decoded response.
	 * @param string              $rel      Relation name.
	 *
	 * @return string
	 */
	private function link( array $response, string $rel ): string {
		foreach ( (array) ( $response['links'] ?? array() ) as $link ) {
			if ( is_array( $link ) && $rel === ( $link['rel'] ?? '' ) ) {
				return (string) ( $link['href'] ?? '' );
			}
		}

		return '';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Order $order Order the buyer is returning for.
	 */
	public function handle_return( Order $order ): void {
		$subscription_id = (string) $order->get_meta( 'paypal_subscription_id', '' );

		if ( '' !== $subscription_id ) {
			$this->confirm_subscription( $order, $subscription_id );

			wp_safe_redirect( $order->receipt_url() );
			exit;
		}

		$paypal_order_id = (string) $order->get_meta( 'paypal_order_id', '' );

		if ( '' !== $paypal_order_id ) {
			$this->capture( $order, $paypal_order_id );
		}

		wp_safe_redirect( $order->receipt_url() );
		exit;
	}

	/**
	 * Capture an approved PayPal order.
	 *
	 * @param Order  $order           Order.
	 * @param string $paypal_order_id PayPal order ID.
	 */
	private function capture( Order $order, string $paypal_order_id ): void {
		if ( $order->is_complete() ) {
			return;
		}

		$response = $this->api(
			'POST',
			'v2/checkout/orders/' . rawurlencode( $paypal_order_id ) . '/capture',
			array(),
			array( 'PayPal-Request-Id' => 'wopcart-capture-' . $order->id() )
		);

		if ( is_wp_error( $response ) ) {
			// A duplicate capture means the webhook already did the work.
			if ( ! str_contains( $response->get_error_message(), 'ORDER_ALREADY_CAPTURED' ) ) {
				$order->set_status( Order::FAILED );
				$order->set_meta( 'failure_reason', $response->get_error_message() );
			}

			return;
		}

		if ( 'COMPLETED' !== (string) ( $response['status'] ?? '' ) ) {
			$order->set_meta( 'paypal_status', (string) ( $response['status'] ?? '' ) );

			return;
		}

		$capture_id = (string) ( $response['purchase_units'][0]['payments']['captures'][0]['id'] ?? '' );

		$order->set_meta( 'paypal_capture_id', $capture_id );
		$this->complete( $order, '' !== $capture_id ? $capture_id : $paypal_order_id );
	}

	/**
	 * Confirm an approved PayPal subscription.
	 *
	 * @param Order  $order           Order.
	 * @param string $subscription_id PayPal subscription ID.
	 */
	private function confirm_subscription( Order $order, string $subscription_id ): void {
		$response = $this->api( 'GET', 'v1/billing/subscriptions/' . rawurlencode( $subscription_id ) );

		if ( is_wp_error( $response ) ) {
			return;
		}

		$status = strtoupper( (string) ( $response['status'] ?? '' ) );

		if ( ! in_array( $status, array( 'ACTIVE', 'APPROVED' ), true ) ) {
			return;
		}

		$this->complete( $order, $subscription_id );

		SubscriptionManager::attach_gateway_reference(
			$order->id(),
			$this->slug(),
			$subscription_id,
			(string) ( $response['subscriber']['payer_id'] ?? '' )
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function handle_webhook(): void {
		$payload = $this->raw_body();
		$event   = json_decode( $payload, true );

		if ( ! is_array( $event ) || empty( $event['event_type'] ) ) {
			$this->respond( 400, 'malformed payload' );
		}

		if ( ! $this->verify_webhook( $payload, $event ) ) {
			Logger::warning( 'gateway.paypal', 'Webhook signature verification failed.', array( 'event' => $event['event_type'] ) );
			$this->respond( 400, 'invalid signature' );
		}

		$type     = (string) $event['event_type'];
		$resource = is_array( $event['resource'] ?? null ) ? $event['resource'] : array();

		switch ( $type ) {
			case 'CHECKOUT.ORDER.APPROVED':
				$order = $this->order_from_resource( $resource );

				if ( null !== $order ) {
					$this->capture( $order, (string) ( $resource['id'] ?? '' ) );
				}
				break;

			case 'PAYMENT.CAPTURE.COMPLETED':
				$order = $this->order_from_resource( $resource );

				if ( null === $order ) {
					break;
				}

				if ( ! $order->is_complete() && ! self::capture_covers( $resource, $order->total(), $order->currency() ) ) {
					$this->log(
						'Capture amount did not match the order; the order was not completed.',
						array(
							'object_id' => $order->id(),
							'expected'  => $order->total() . ' ' . $order->currency(),
							'received'  => (string) ( $resource['amount']['value'] ?? '' ) . ' ' . (string) ( $resource['amount']['currency_code'] ?? '' ),
							'capture'   => (string) ( $resource['id'] ?? '' ),
						),
						Logger::ERROR
					);
					break;
				}

				$this->complete( $order, (string) ( $resource['id'] ?? '' ) );
				break;

			case 'PAYMENT.CAPTURE.REFUNDED':
			case 'PAYMENT.CAPTURE.REVERSED':
				$this->handle_refund_event( $resource, $type );
				break;

			case 'BILLING.SUBSCRIPTION.ACTIVATED':
				$order = $this->order_from_resource( $resource );

				if ( null !== $order ) {
					$this->confirm_subscription( $order, (string) ( $resource['id'] ?? '' ) );
				}
				break;

			case 'PAYMENT.SALE.COMPLETED':
				$this->handle_recurring_payment( $resource );
				break;

			case 'BILLING.SUBSCRIPTION.CANCELLED':
			case 'BILLING.SUBSCRIPTION.EXPIRED':
				$subscription = SubscriptionRepository::find_by_gateway_id( $this->slug(), (string) ( $resource['id'] ?? '' ) );

				if ( null !== $subscription ) {
					SubscriptionManager::set_status(
						$subscription,
						'BILLING.SUBSCRIPTION.EXPIRED' === $type ? Subscription::EXPIRED : Subscription::CANCELLED,
						'gateway_webhook'
					);
				}
				break;

			case 'BILLING.SUBSCRIPTION.PAYMENT.FAILED':
				$subscription = SubscriptionRepository::find_by_gateway_id( $this->slug(), (string) ( $resource['id'] ?? '' ) );

				if ( null !== $subscription ) {
					SubscriptionManager::record_failure( $subscription, __( 'PayPal reported a failed renewal payment.', 'wopcart-direct' ) );
				}
				break;
		}

		/**
		 * Fires for every verified PayPal event.
		 *
		 * @param string              $type  Event type.
		 * @param array<string,mixed> $event Full event payload.
		 */
		do_action( 'wopcart_paypal_webhook', $type, $event );

		$this->respond();
	}

	/**
	 * Ask PayPal whether a webhook really came from them.
	 *
	 * PayPal signs with a certificate chain rather than a shared secret, so the
	 * only supported check is to post the headers back to their verification
	 * endpoint. Without a configured webhook ID there is nothing to verify
	 * against, and the event is refused rather than trusted.
	 *
	 * @param string              $payload Raw body.
	 * @param array<string,mixed> $event   Decoded body.
	 *
	 * @return bool
	 */
	private function verify_webhook( string $payload, array $event ): bool {
		$webhook_id = trim( (string) $this->setting( 'paypal_webhook_id' ) );

		if ( '' === $webhook_id ) {
			Logger::error( 'gateway.paypal', 'Webhook received but no webhook ID is configured.' );

			return false;
		}

		$response = $this->api(
			'POST',
			'v1/notifications/verify-webhook-signature',
			array(
				'auth_algo'         => $this->header( 'PayPal-Auth-Algo' ),
				'cert_url'          => $this->header( 'PayPal-Cert-Url' ),
				'transmission_id'   => $this->header( 'PayPal-Transmission-Id' ),
				'transmission_sig'  => $this->header( 'PayPal-Transmission-Sig' ),
				'transmission_time' => $this->header( 'PayPal-Transmission-Time' ),
				'webhook_id'        => $webhook_id,
				'webhook_event'     => $event,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		return 'SUCCESS' === strtoupper( (string) ( $response['verification_status'] ?? '' ) );
	}

	/**
	 * Resolve the order a webhook resource belongs to.
	 *
	 * @param array<string,mixed> $resource Event resource.
	 *
	 * @return Order|null
	 */
	private function order_from_resource( array $resource ): ?Order {
		$candidates = array(
			$resource['custom_id'] ?? null,
			$resource['purchase_units'][0]['custom_id'] ?? null,
		);

		foreach ( $candidates as $candidate ) {
			// PayPal's own ids (supplementary_data.related_ids.order_id is one) start
			// with digits too, and an int cast of "5O190127TN364715T" is order 5.
			if ( ! is_scalar( $candidate ) || 1 !== preg_match( '/\A[1-9][0-9]*\z/', (string) $candidate ) ) {
				continue;
			}

			$order = Order::find( (int) $candidate );

			if ( null === $order ) {
				continue;
			}

			// custom_id is only a hint: a PayPal account shared by two stores sends
			// both every event, and their order ids overlap. The resource has to
			// name a PayPal object this order created.
			$references = array(
				$order->get_meta( 'paypal_order_id', '' ),
				$order->get_meta( 'paypal_subscription_id', '' ),
				$order->get_meta( 'paypal_capture_id', '' ),
				$order->transaction_id(),
			);

			if ( $order->gateway() === $this->slug() && self::resource_matches( $resource, $references ) ) {
				return $order;
			}

			$this->log(
				'PayPal event names an order it does not belong to; ignored.',
				array(
					'object_id' => $order->id(),
					'resource'  => (string) ( $resource['id'] ?? '' ),
				),
				Logger::WARNING
			);

			return null;
		}

		$reference = (string) ( $resource['id'] ?? '' );

		return '' !== $reference ? OrderRepository::find_by_transaction( $reference, $this->slug() ) : null;
	}

	/**
	 * Pure: whether a webhook resource is one of the PayPal objects an order holds.
	 *
	 * A PayPal order (CHECKOUT.ORDER.APPROVED) and a subscription
	 * (BILLING.SUBSCRIPTION.ACTIVATED) carry their own id; a capture carries its
	 * id and the PayPal order it belongs to.
	 *
	 * @param array<string,mixed> $data       Event resource.
	 * @param array<int,mixed>    $references PayPal ids stored on the order.
	 *
	 * @return bool
	 */
	public static function resource_matches( array $data, array $references ): bool {
		$references = array_values( array_filter( array_map( 'strval', $references ), static fn( string $id ): bool => '' !== $id ) );
		$claims     = array(
			(string) ( $data['id'] ?? '' ),
			(string) ( $data['supplementary_data']['related_ids']['order_id'] ?? '' ),
		);

		foreach ( $claims as $claim ) {
			if ( '' !== $claim && in_array( $claim, $references, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Pure: whether a capture paid an order in full, in its currency.
	 *
	 * @param array<string,mixed> $data     Capture resource.
	 * @param int                 $total    Order total, minor units.
	 * @param string              $currency Order currency.
	 *
	 * @return bool
	 */
	public static function capture_covers( array $data, int $total, string $currency ): bool {
		$value = trim( (string) ( $data['amount']['value'] ?? '' ) );
		$code  = strtoupper( (string) ( $data['amount']['currency_code'] ?? '' ) );

		if ( '' === $value || ! is_numeric( $value ) || strtoupper( $currency ) !== $code ) {
			return false;
		}

		return Money::to_minor( $value, $code ) >= $total;
	}

	/**
	 * Pure: what a refund or reversal event refers to.
	 *
	 * A PAYMENT.CAPTURE.REFUNDED resource is the refund, whose own id says nothing
	 * about the order; the capture is its "up" link. A REVERSED resource is the
	 * capture itself, taken back in full. The total PayPal reports as refunded on
	 * the capture, when present, is absolute and so safe to apply twice.
	 *
	 * @param array<string,mixed> $data Event resource.
	 * @param string              $type Event type.
	 *
	 * @return array{capture:string,refund:string,amount:int,total_refunded:int|null,full:bool}
	 */
	public static function refund_details( array $data, string $type ): array {
		$id       = (string) ( $data['id'] ?? '' );
		$currency = strtoupper( (string) ( $data['amount']['currency_code'] ?? '' ) );
		$amount   = '' !== $currency ? Money::to_minor( (string) ( $data['amount']['value'] ?? '0' ), $currency ) : 0;

		if ( 'PAYMENT.CAPTURE.REVERSED' === $type ) {
			return array(
				'capture'        => $id,
				'refund'         => 'reversal:' . $id,
				'amount'         => $amount,
				'total_refunded' => null,
				'full'           => true,
			);
		}

		$capture = (string) ( $data['supplementary_data']['related_ids']['capture_id'] ?? '' );

		if ( '' === $capture ) {
			foreach ( (array) ( $data['links'] ?? array() ) as $link ) {
				if ( is_array( $link ) && 'up' === ( $link['rel'] ?? '' ) && 1 === preg_match( '#/captures/([A-Za-z0-9-]+)/?\z#', (string) ( $link['href'] ?? '' ), $match ) ) {
					$capture = $match[1];
					break;
				}
			}
		}

		$breakdown = $data['seller_payable_breakdown']['total_refunded_amount'] ?? null;
		$absolute  = null;

		if ( is_array( $breakdown ) && is_numeric( $breakdown['value'] ?? null ) ) {
			$absolute = Money::to_minor( (string) $breakdown['value'], strtoupper( (string) ( $breakdown['currency_code'] ?? $currency ) ) );
		}

		return array(
			'capture'        => $capture,
			'refund'         => $id,
			'amount'         => $amount,
			'total_refunded' => $absolute,
			'full'           => false,
		);
	}

	/**
	 * Pure: an order's refunded figure after a refund event.
	 *
	 * @param int      $current        Refunded so far.
	 * @param int      $total          Order total.
	 * @param int      $amount         This refund's amount.
	 * @param int|null $total_refunded Absolute total the provider reports, when it does.
	 * @param bool     $full           Whether the whole payment was taken back.
	 *
	 * @return int
	 */
	public static function refunded_after( int $current, int $total, int $amount, ?int $total_refunded, bool $full = false ): int {
		if ( $full ) {
			return $total;
		}

		$next = null !== $total_refunded ? max( $current, $total_refunded ) : $current + max( 0, $amount );

		return min( $total, $next );
	}

	/**
	 * Record a refund made from the PayPal dashboard.
	 *
	 * Each refund is applied once: PayPal redelivers until it gets a 200, and a
	 * refund issued through refund() below reports back by webhook too. Its id is
	 * kept on the order, under a claim so two deliveries at once cannot both add.
	 *
	 * @param array<string,mixed> $resource Refund resource.
	 * @param string              $type     Event type.
	 */
	private function handle_refund_event( array $resource, string $type ): void {
		$details = self::refund_details( $resource, $type );
		$order   = '' !== $details['capture'] ? OrderRepository::find_by_transaction( $details['capture'], $this->slug() ) : null;

		// An older event shape whose resource is the refunded capture itself.
		if ( null === $order && '' === $details['capture'] && '' !== (string) ( $resource['id'] ?? '' ) ) {
			$order = OrderRepository::find_by_transaction( (string) $resource['id'], $this->slug() );
		}

		if ( null === $order || '' === $details['refund'] ) {
			return;
		}

		$claim = 'wopcart_refund_claim_' . md5( $this->slug() . '|' . $details['refund'] );

		if ( ! SubscriptionManager::claim( $claim, 60 ) ) {
			return;
		}

		try {
			$order = Order::find( $order->id() ) ?? $order;
			$seen  = array_map( 'strval', (array) $order->get_meta( 'paypal_refund_ids', array() ) );

			if ( in_array( $details['refund'], $seen, true ) ) {
				return;
			}

			$total = self::refunded_after( $order->refunded(), $order->total(), $details['amount'], $details['total_refunded'], $details['full'] );

			$seen[] = $details['refund'];

			// Id first, so a failure before the amount write cannot lead to a double count.
			$order->set_meta( 'paypal_refund_ids', $seen );
			$order->update( array( 'refunded' => $total ) );
			$order->set_status( $total >= $order->total() ? Order::REFUNDED : Order::PARTIALLY_REFUNDED );
		} finally {
			SubscriptionManager::release_claim( $claim );
		}
	}

	/**
	 * Record a subscription renewal payment.
	 *
	 * @param array<string,mixed> $resource Sale resource.
	 */
	private function handle_recurring_payment( array $resource ): void {
		$agreement = (string) ( $resource['billing_agreement_id'] ?? '' );

		if ( '' === $agreement ) {
			return;
		}

		$subscription = SubscriptionRepository::find_by_gateway_id( $this->slug(), $agreement );

		if ( null === $subscription ) {
			return;
		}

		$sale = (string) ( $resource['id'] ?? '' );

		// The charge PayPal takes when the subscription starts is the payment for
		// the original order, which is already recorded; only what comes after it
		// is a renewal. Matched by an explicit marker on the subscription, because
		// times_billed() counts that first charge on a plan with no trial and
		// cannot be used to spot it.
		if ( SubscriptionManager::claim_initial_charge( $subscription, $sale ) ) {
			SubscriptionManager::sync_from_gateway( $subscription, $resource );

			return;
		}

		$currency = strtoupper( (string) ( $resource['amount']['currency'] ?? $subscription->currency() ) );

		SubscriptionManager::record_renewal(
			$subscription,
			Money::to_minor( (string) ( $resource['amount']['total'] ?? '0' ), $currency ),
			$currency,
			$sale
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Order $order  Order to refund.
	 * @param int   $amount Amount in minor units, 0 for the full remaining balance.
	 *
	 * @return true|\WP_Error
	 */
	public function refund( Order $order, int $amount = 0 ): true|\WP_Error {
		$capture_id = (string) $order->get_meta( 'paypal_capture_id', $order->transaction_id() );

		if ( '' === $capture_id ) {
			return new \WP_Error( 'wopcart_paypal_no_capture', __( 'This order has no PayPal capture to refund.', 'wopcart-direct' ) );
		}

		$amount = $amount > 0 ? min( $amount, $order->refundable() ) : $order->refundable();

		if ( $amount <= 0 ) {
			return new \WP_Error( 'wopcart_nothing_to_refund', __( 'There is nothing left to refund on this order.', 'wopcart-direct' ) );
		}

		$currency = $order->currency();
		$before   = $order->refunded();

		$response = $this->api(
			'POST',
			'v2/payments/captures/' . rawurlencode( $capture_id ) . '/refund',
			array(
				'amount'     => array(
					'currency_code' => $currency,
					'value'         => $this->amount( $amount, $currency ),
				),
				'invoice_id' => $order->number() . '-R' . time(),
			),
			array( 'PayPal-Request-Id' => self::refund_request_id( $order->id(), $before, $amount ) )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$this->record_refund( $order, (string) ( $response['id'] ?? '' ), $amount );

		return true;
	}

	/**
	 * Pure: the idempotency key one refund request is sent under.
	 *
	 * PayPal replays the stored response for a PayPal-Request-Id it has already
	 * seen. Keyed on the order and the amount alone, a second partial refund of
	 * the same amount was that same request: PayPal handed back the first refund's
	 * response, no money moved, and the store recorded a refund it had not made.
	 * Including what the order had already been refunded makes every refund in a
	 * sequence its own request, while a duplicate submission of this very refund —
	 * a double-clicked button — is still recognised and collapsed.
	 *
	 * @param int $order_id Order ID.
	 * @param int $before   Amount already refunded, minor units.
	 * @param int $amount   Amount being refunded now, minor units.
	 *
	 * @return string
	 */
	public static function refund_request_id( int $order_id, int $before, int $amount ): string {
		return 'wopcart-refund-' . $order_id . '-' . $before . '-' . ( $before + $amount );
	}

	/**
	 * Count a refund issued through refund() on the order, once.
	 *
	 * The PAYMENT.CAPTURE.REFUNDED webhook for the same refund can arrive before
	 * this runs. Both take the claim handle_refund_event() uses, re-read the
	 * order and skip an id already seen, and the id is stored before the
	 * refunded figure moves, so neither side can count it a second time.
	 *
	 * @param Order  $order     Order refunded.
	 * @param string $refund_id PayPal refund id, '' when the response had none.
	 * @param int    $amount    Amount refunded, minor units.
	 */
	private function record_refund( Order $order, string $refund_id, int $amount ): void {
		if ( '' === $refund_id ) {
			$total = $order->refunded() + $amount;

			$order->update( array( 'refunded' => $total ) );
			$order->set_status( $total >= $order->total() ? Order::REFUNDED : Order::PARTIALLY_REFUNDED );

			return;
		}

		$claim   = 'wopcart_refund_claim_' . md5( $this->slug() . '|' . $refund_id );
		$claimed = SubscriptionManager::claim( $claim, 60 );

		// A webhook holding the claim finishes in well under a second.
		for ( $attempt = 0; ! $claimed && $attempt < 20; $attempt++ ) {
			usleep( 250000 );
			$claimed = SubscriptionManager::claim( $claim, 60 );
		}

		if ( ! $claimed ) {
			// The gateway has already refunded, so record it rather than lose it;
			// the stored refund ids below still stop a double count.
			Logger::warning(
				'refunds',
				'Recorded a refund while its webhook still held the refund claim.',
				array(
					'object_id' => $order->id(),
					'refund'    => $refund_id,
				)
			);
		}

		try {
			$current = Order::find( $order->id() ) ?? $order;
			$seen    = array_map( 'strval', (array) $current->get_meta( 'paypal_refund_ids', array() ) );

			// The webhook got here first and has already counted it; bring the
			// caller's copy, which it reads afterwards, up to date.
			if ( in_array( $refund_id, $seen, true ) ) {
				$order->refresh();

				return;
			}

			$seen[] = $refund_id;

			// The id goes first, as a single key: if the amount write below fails,
			// a redelivered webhook skips this refund instead of counting it twice,
			// and no other request's meta is overwritten. Both writes go through
			// the caller's copy, which it reads afterwards.
			$order->set_meta( 'paypal_refund_ids', array_values( array_unique( $seen ) ) );

			$total = min( $current->total(), $current->refunded() + $amount );

			$order->update( array( 'refunded' => $total ) );
			$order->set_status( $total >= $order->total() ? Order::REFUNDED : Order::PARTIALLY_REFUNDED );
		} finally {
			if ( $claimed ) {
				SubscriptionManager::release_claim( $claim );
			}
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Subscription $subscription Subscription.
	 *
	 * @return array{transaction_id:string}|\WP_Error
	 */
	public function renew( Subscription $subscription ): array|\WP_Error {
		return new \WP_Error(
			'wopcart_gateway_bills_externally',
			__( 'PayPal bills this subscription directly.', 'wopcart-direct' )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Subscription $subscription  Subscription.
	 * @param bool         $at_period_end Ignored; PayPal always ends the term.
	 *
	 * @return true|\WP_Error
	 */
	public function cancel_subscription( Subscription $subscription, bool $at_period_end = true ): true|\WP_Error {
		$reference = $subscription->gateway_subscription_id();

		if ( '' === $reference ) {
			return true;
		}

		$response = $this->api(
			'POST',
			'v1/billing/subscriptions/' . rawurlencode( $reference ) . '/cancel',
			array( 'reason' => 'Cancelled by the customer.' )
		);

		return is_wp_error( $response ) ? $response : true;
	}
}
