<?php
/**
 * Mollie gateway.
 *
 * @package WopCartDirect
 */

declare(strict_types=1);

namespace WopCartDirect\Gateways;

use WopCart\Gateways\AbstractGateway;
use WopCart\Gateways\CartProfile;
use WopCart\Model\Order;
use WopCart\Model\Price;
use WopCart\Model\Subscription;
use WopCart\Repository\CustomerRepository;
use WopCart\Repository\OrderRepository;
use WopCart\Repository\SubscriptionRepository;
use WopCart\Security\RateLimiter;
use WopCart\Subscriptions\PlanChange;
use WopCart\Subscriptions\SubscriptionManager;
use WopCart\Support\Http;
use WopCart\Support\Logger;
use WopCart\Support\Options;
use WopCart\Support\Util;
use WopCartDirect\Gateways\Mollie\Amount;
use WopCartDirect\Gateways\Mollie\Payload;
use WopCartDirect\Gateways\Mollie\PaymentState;
use WopCartDirect\Gateways\Mollie\Recurring;

/**
 * Payments and subscriptions through Mollie (iDEAL, Bancontact, cards, Klarna,
 * PayPal and SEPA Direct Debit for renewals).
 *
 * One-off purchases create a Payments API payment and send the buyer to Mollie's
 * hosted checkout (docs.mollie.com/reference/create-payment). No card or bank
 * data touches this site, so the gateway needs no client-side code.
 *
 * Shipped by WopCart Direct rather than by core, because Mollie has no
 * application-fee mechanism a merchant's own API key would carry: offering it to
 * a store that settles through the WopCart platform would be an open bypass of
 * the platform fee. Here there is nothing to bypass — every store running this
 * plugin transacts with credentials of its own — so the three independent plan
 * checks core's copy carried (is_available(), process() and the settings section)
 * are gone. Being installed is the entitlement.
 *
 * Webhooks are unsigned. Mollie posts only `id=tr_…` and expects the receiver to
 * fetch the payment itself (docs.mollie.com/reference/webhooks); authenticity comes
 * from that authenticated HTTPS call made with the store's secret key
 * (docs.mollie.com/reference/security). Nothing in the request body is ever
 * trusted: a forged call can at most make the store re-read a real payment.
 *
 * Recurring uses Mollie's Subscriptions API rather than on-demand charges from
 * WopCart's renewal cron. SubscriptionManager::process_renewals() expects renew()
 * to report a finished charge synchronously and immediately records a completed
 * renewal order, but Mollie recurring payments — above all SEPA Direct Debit
 * mandates from iDEAL and Bancontact — stay pending for days
 * (docs.mollie.com/docs/sepa-direct-debit). Charging from the cron would record
 * unpaid renewals as paid, or need a second asynchronous renewal path that
 * SubscriptionManager does not have. The Subscriptions API fits the existing
 * "gateway owns the schedule" model used by Stripe and PayPal: renew() answers
 * wopcart_gateway_bills_externally, each paid subscription payment arrives by
 * webhook and becomes a renewal order, and an amount change applies to future
 * payments only (docs.mollie.com/reference/update-subscription), which is exactly
 * the store's rule that downgrades take effect at the next charge.
 */
final class Mollie extends AbstractGateway {

	/**
	 * Features supported.
	 *
	 * @var string[]
	 */
	protected array $supports = array( 'refunds', 'subscriptions', 'trials', 'webhooks' );

	/**
	 * API base. Test and live share it; the key decides the mode
	 * (docs.mollie.com/reference/authentication).
	 */
	private const API_BASE = 'https://api.mollie.com/v2/';

	/**
	 * Order meta: most recent Mollie payment id for the order.
	 */
	private const META_PAYMENT = 'mollie_payment_id';

	/**
	 * Order meta: every Mollie payment id created for the order.
	 */
	private const META_PAYMENTS = 'mollie_payment_ids';

	/**
	 * Settings helper fields that are never stored themselves.
	 */
	private const HELPER_FIELDS = array( 'mollie_live_api_key_input', 'mollie_live_api_key_clear', 'mollie_test_api_key_input', 'mollie_test_api_key_clear' );

	/**
	 * Transient holding the modes whose key Mollie refused, for the admin notice.
	 */
	public const AUTH_NOTICE = 'wopcart_mollie_key_rejected';

	/**
	 * Order meta on a subscription's first order: renewal payment ids already
	 * counted as failures.
	 */
	private const META_FAILED_RENEWALS = 'mollie_failed_renewal_ids';

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'mollie';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Mollie (iDEAL, Bancontact, cards, Klarna)', 'wopcart-direct' );
	}

	/*
	 * is_available() is not overridden here. Core's copy overrode it only to add
	 * self::plan_allows() in front of the parent's answer; with the gate gone the
	 * override would call the parent and nothing else, and saying so in a comment
	 * is more useful to whoever diffs the two files than an empty method.
	 */

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	protected function has_credentials(): bool {
		return '' !== $this->api_key( $this->current_mode() );
	}

	/**
	 * Mode new payments are created in.
	 *
	 * @return string test|live
	 */
	private function current_mode(): string {
		return $this->is_test() ? 'test' : 'live';
	}

	/**
	 * API key for a mode.
	 *
	 * A constant wins over the stored option so a deployment can keep the key out
	 * of the database, as core's Gateways\Connect does for the platform key.
	 *
	 * @param string $mode test|live.
	 *
	 * @return string
	 */
	private function api_key( string $mode ): string {
		$constant = 'WOPCART_MOLLIE_' . strtoupper( $mode ) . '_KEY';

		if ( defined( $constant ) && '' !== trim( (string) constant( $constant ) ) ) {
			return trim( (string) constant( $constant ) );
		}

		return trim( (string) Options::get( 'mollie_' . $mode . '_api_key', '' ) );
	}

	/**
	 * Mode an order was created in, so later calls use the key that can see it.
	 *
	 * @param Order $order Order.
	 *
	 * @return string test|live
	 */
	private function order_mode( Order $order ): string {
		$mode = (string) ( $order->to_array()['gateway_mode'] ?? '' );

		return in_array( $mode, array( 'test', 'live' ), true ) ? $mode : $this->current_mode();
	}

	/**
	 * Mode a subscription was created in, taken from the order that started it.
	 *
	 * @param Subscription $subscription Subscription.
	 *
	 * @return string test|live
	 */
	private function subscription_mode( Subscription $subscription ): string {
		$order = Order::find( $subscription->order_id() );

		return null !== $order ? $this->order_mode( $order ) : $this->current_mode();
	}

	/**
	 * Call the Mollie API.
	 *
	 * The key travels only in the Authorization header, which Http does not log.
	 *
	 * @param string              $method      HTTP verb.
	 * @param string              $path        Path below /v2/.
	 * @param array<string,mixed> $body        JSON body.
	 * @param string              $mode        test|live.
	 * @param string              $idempotency Idempotency-Key for POSTs, '' for none.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	private function api( string $method, string $path, array $body, string $mode, string $idempotency = '' ): array|\WP_Error {
		$key = $this->api_key( $mode );

		if ( '' === $key ) {
			return new \WP_Error( 'wopcart_mollie_no_key', __( 'Mollie is not configured for this mode.', 'wopcart-direct' ) );
		}

		$headers = array(
			'Authorization' => 'Bearer ' . $key,
			'Content-Type'  => 'application/json',
		);

		// POST only: "Sending idempotency keys for GET, PATCH, or DELETE requests is
		// not necessary" (docs.mollie.com/reference/api-idempotency).
		if ( '' !== $idempotency && 'POST' === strtoupper( $method ) ) {
			$headers['Idempotency-Key'] = $idempotency;
		}

		$args = array(
			'headers'  => $headers,
			'source'   => 'gateway.mollie',
			// Payment and customer bodies carry names, emails and addresses. Asks
			// Http not to copy them into the debug log.
			'log_body' => false,
			// Webhook calls time out after 15 seconds at Mollie's end, so a slow
			// API call must give up well before that.
			'timeout'  => 10,
		);

		if ( array() !== $body ) {
			$args['body'] = $body;
		}

		$response = Http::json( $method, self::API_BASE . ltrim( $path, '/' ), $args );

		if ( is_wp_error( $response ) ) {
			$data   = $response->get_error_data();
			$detail = is_array( $data ) ? (string) ( $data['response']['detail'] ?? '' ) : '';

			// Mollie errors carry `detail`, which Http's generic extractor misses.
			if ( '' !== $detail ) {
				return new \WP_Error( $response->get_error_code(), $detail, $data );
			}
		}

		return $response;
	}

	/**
	 * HTTP status carried by an API error, 0 when the request never got an answer.
	 *
	 * @param \WP_Error $error Error.
	 *
	 * @return int
	 */
	private static function error_status( \WP_Error $error ): int {
		$data = $error->get_error_data();

		return is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Leaves Mollie out of the list for a cart process() would refuse: one
	 * Mollie subscription is attached per order (Recurring::sequence_type()).
	 *
	 * @param CartProfile $profile Cart or order profile.
	 *
	 * @return string|null
	 */
	public function cart_error( CartProfile $profile ): ?string {
		$sequence = Recurring::sequence_type( count( $profile->recurring ) );

		return is_wp_error( $sequence ) ? $sequence->get_error_message() : null;
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
		$mode = $this->current_mode();

		if ( '' === $this->api_key( $mode ) ) {
			return new \WP_Error( 'wopcart_mollie_no_key', __( 'That payment method is not available.', 'wopcart-direct' ) );
		}

		$currency = strtoupper( $order->currency() );
		$value    = Amount::format( $order->total(), $currency );

		if ( is_wp_error( $value ) ) {
			return $value;
		}

		$recurring = 0;
		$lines     = array();
		$delivery  = \WopCart\Shipping\ShippingService::for_order( $order );

		foreach ( $order->items() as $item ) {
			if ( $item->is_recurring() ) {
				++$recurring;

				$option = $item->product()?->price_option( '' === $item->price_id() ? null : $item->price_id() );

				// Checked before the buyer pays: once the first payment is taken,
				// an interval Mollie refuses could no longer become a subscription.
				if ( null !== $option ) {
					$interval = Recurring::interval( $option->period, $option->frequency );

					if ( is_wp_error( $interval ) ) {
						return $interval;
					}
				}
			}

			$lines[] = array(
				'name'     => $item->name(),
				'quantity' => $item->quantity(),
				'total'    => $item->total(),
				'tax_rate' => $item->tax_rate(),
			);
		}

		$sequence = Recurring::sequence_type( $recurring );

		if ( is_wp_error( $sequence ) ) {
			return $sequence;
		}

		$methods = Payload::sanitize_methods( $this->setting( 'mollie_methods', array() ) );

		if ( 'first' === $sequence ) {
			$methods = Recurring::first_payment_methods( $order->total(), $methods );

			if ( is_wp_error( $methods ) ) {
				return $methods;
			}
		}

		// A double submit or a buyer coming back from the checkout page reuses the
		// payment that is still open instead of creating a second one.
		$existing = (string) $order->get_meta( self::META_PAYMENT, '' );

		if ( PaymentState::valid_payment_id( $existing ) ) {
			$open = $this->api( 'GET', 'payments/' . rawurlencode( $existing ), array(), $mode );
			$href = is_array( $open ) ? (string) ( $open['_links']['checkout']['href'] ?? '' ) : '';

			if ( is_array( $open ) && 'open' === ( $open['status'] ?? '' ) && str_starts_with( $href, 'https://' ) ) {
				return array( 'redirect' => $href );
			}
		}

		$payload = array(
			'amount'       => array(
				'currency' => $currency,
				'value'    => $value,
			),
			'description'  => substr( $this->description( $order ), 0, 255 ),
			'redirectUrl'  => $this->return_url( $order ),
			'cancelUrl'    => $this->cancel_url( $order ),
			'sequenceType' => $sequence,
			// Only identifiers: metadata is shown in the Mollie dashboard and sent
			// back on every fetch, and it is limited to about 1kB.
			'metadata'     => array(
				'wopcart_order_id'     => $order->id(),
				'wopcart_order_number' => $order->number(),
			),
		);

		$webhook = $this->mollie_webhook_url();

		if ( '' !== $webhook ) {
			$payload['webhookUrl'] = $webhook;
		}

		$locale = function_exists( 'get_locale' ) ? Payload::locale( (string) get_locale() ) : '';

		if ( '' !== $locale ) {
			$payload['locale'] = $locale;
		}

		$method = Recurring::method_param( $methods );

		if ( null !== $method ) {
			$payload['method'] = $method;
		}

		if ( 'first' === $sequence ) {
			$customer = $this->ensure_customer( $order, $mode );

			if ( is_wp_error( $customer ) ) {
				return $customer;
			}

			$payload['customerId'] = $customer;
		} elseif ( array() === $methods || in_array( 'klarna', $methods, true ) ) {
			// Klarna needs lines and a billing address; without them Mollie simply
			// does not offer it, so they are only sent when they reconcile exactly.
			$payment_lines = Payload::lines( $lines, $order->total(), $currency, $delivery );

			if ( null !== $payment_lines ) {
				$payload['lines']          = $payment_lines;
				$payload['billingAddress'] = $this->billing_address( $order );
			}
		}

		// The delivery address goes with every shipped order, whichever method the
		// buyer picks; the amount already includes the shipping charge.
		$shipping_address = is_array( $delivery['address'] ?? null ) ? Payload::shipping_address( $delivery['address'], $order->email() ) : array();

		if ( array() !== $shipping_address ) {
			$payload['shippingAddress'] = $shipping_address;
		}

		// Scoped to the order and the exact body: a replay within Mollie's one-hour
		// window returns the same payment, and a changed body is a new request
		// instead of a 400 (docs.mollie.com/reference/api-idempotency).
		$idempotency = 'wopcart-order-' . $order->id() . '-' . substr( md5( (string) wp_json_encode( $payload ) ), 0, 12 );
		$payment     = $this->api( 'POST', 'payments', $payload, $mode, $idempotency );

		if ( is_wp_error( $payment ) ) {
			return $payment;
		}

		$id   = (string) ( $payment['id'] ?? '' );
		$href = (string) ( $payment['_links']['checkout']['href'] ?? '' );

		if ( ! PaymentState::valid_payment_id( $id ) || ! str_starts_with( $href, 'https://' ) ) {
			return new \WP_Error( 'wopcart_mollie_no_checkout', __( 'Mollie did not return a payment page.', 'wopcart-direct' ) );
		}

		$this->remember_payment( $order, $id );

		return array( 'redirect' => $href );
	}

	/**
	 * Webhook URL to give Mollie, '' when Mollie could not reach it.
	 *
	 * @return string
	 */
	private function mollie_webhook_url(): string {
		/**
		 * Filter the webhook URL sent to Mollie, e.g. to point a development site at a tunnel.
		 *
		 * @param string $url Webhook URL.
		 */
		$url = (string) apply_filters( 'wopcart_mollie_webhook_url', $this->webhook_url() );

		return Payload::webhook_reachable( $url ) ? $url : '';
	}

	/**
	 * Billing address for Klarna, using only the fields the buyer supplied.
	 *
	 * @param Order $order Order.
	 *
	 * @return array<string,string>
	 */
	private function billing_address( Order $order ): array {
		$billing = $order->billing();
		$street  = trim( (string) ( $billing['address_1'] ?? '' ) . ' ' . (string) ( $billing['address_2'] ?? '' ) );
		$given   = (string) ( $billing['first_name'] ?? '' );
		$family  = (string) ( $billing['last_name'] ?? '' );

		return array_filter(
			array(
				// Mollie wants names of at least two characters.
				'givenName'       => mb_strlen( $given ) >= 2 ? $given : '',
				'familyName'      => mb_strlen( $family ) >= 2 ? $family : '',
				'email'           => $order->email(),
				'streetAndNumber' => $street,
				'postalCode'      => (string) ( $billing['postal_code'] ?? '' ),
				'city'            => (string) ( $billing['city'] ?? '' ),
				'country'         => strtoupper( (string) ( $billing['country'] ?? '' ) ),
			),
			static fn( string $value ): bool => '' !== $value
		);
	}

	/**
	 * Find or create the Mollie customer a first payment is made for.
	 *
	 * Customers are only created for subscriptions, which need one for the
	 * mandate (docs.mollie.com/docs/recurring-payments); one-off buyers are not
	 * copied into Mollie's customer list. Ids are cached per mode because test
	 * and live customers are separate.
	 *
	 * @param Order  $order Order.
	 * @param string $mode  test|live.
	 *
	 * @return string|\WP_Error Customer id.
	 */
	private function ensure_customer( Order $order, string $mode ): string|\WP_Error {
		$meta_key = 'mollie_customer_id_' . $mode;

		if ( $order->customer_id() > 0 ) {
			$cached = (string) CustomerRepository::get_meta( $order->customer_id(), $meta_key, '' );

			if ( 1 === preg_match( '/\Acst_[A-Za-z0-9]+\z/', $cached ) ) {
				return $cached;
			}
		}

		$customer = $this->api(
			'POST',
			'customers',
			array(
				'name'     => $order->customer_name(),
				'email'    => $order->email(),
				'metadata' => array( 'wopcart_customer_id' => $order->customer_id() ),
			),
			$mode,
			'wopcart-customer-' . $order->id()
		);

		if ( is_wp_error( $customer ) ) {
			return $customer;
		}

		$id = (string) ( $customer['id'] ?? '' );

		if ( 1 !== preg_match( '/\Acst_[A-Za-z0-9]+\z/', $id ) ) {
			return new \WP_Error( 'wopcart_mollie_no_customer', __( 'Mollie did not return a customer.', 'wopcart-direct' ) );
		}

		if ( $order->customer_id() > 0 ) {
			CustomerRepository::update_meta( $order->customer_id(), $meta_key, $id );
		}

		return $id;
	}

	/**
	 * Record a payment id against its order.
	 *
	 * Every id is kept, not only the latest: an earlier attempt for the same
	 * order can still be paid after a later one was created, and its webhook must
	 * be recognised as belonging to this order.
	 *
	 * @param Order  $order Order.
	 * @param string $id    Payment id.
	 */
	private function remember_payment( Order $order, string $id ): void {
		$ids   = array_map( 'strval', (array) $order->get_meta( self::META_PAYMENTS, array() ) );
		$ids[] = $id;

		$order->set_meta( self::META_PAYMENTS, array_values( array_unique( $ids ) ) );
		$order->set_meta( self::META_PAYMENT, $id );
	}

	/**
	 * {@inheritDoc}
	 *
	 * The redirect itself proves nothing — Mollie notes a payment can still be
	 * `open` after a cancelled attempt (docs.mollie.com/docs/handling-payment-status).
	 * The payment is fetched with the API key, which is as trustworthy as the
	 * webhook, and processed the same way; the webhook remains the backstop.
	 *
	 * @param Order $order Order the buyer is returning for.
	 */
	public function handle_return( Order $order ): void {
		$id = (string) $order->get_meta( self::META_PAYMENT, '' );

		if ( PaymentState::valid_payment_id( $id ) ) {
			$fetched = $this->fetch_payment( $id, $this->order_mode( $order ) );

			// The buyer only sees the ordinary receipt page with the order still
			// pending; why it could not be checked is for the merchant.
			if ( is_wp_error( $fetched ) && 'wopcart_mollie_auth' === $fetched->get_error_code() ) {
				$this->report_rejected_key( $fetched, $id );
			}

			if ( is_array( $fetched ) ) {
				$this->process_payment( $fetched );

				$fresh = Order::find( $order->id() ) ?? $order;

				if ( in_array( (string) ( $fetched['status'] ?? '' ), array( 'failed', 'canceled', 'expired' ), true ) && ! $fresh->is_complete() ) {
					wp_safe_redirect( $this->cancel_url( $fresh ) );
					exit;
				}
			}
		}

		wp_safe_redirect( $order->receipt_url() );
		exit;
	}

	/**
	 * Whether Mollie reports that an order's latest payment will never be paid.
	 *
	 * Read with the API key, like handle_return(). Anything short of a definite
	 * failed, cancelled or expired payment (including a failed lookup) is false.
	 *
	 * @param Order $order Order.
	 *
	 * @return bool
	 */
	public function reports_no_payment( Order $order ): bool {
		$id = (string) $order->get_meta( self::META_PAYMENT, '' );

		if ( ! PaymentState::valid_payment_id( $id ) ) {
			return false;
		}

		$fetched = $this->fetch_payment( $id, $this->order_mode( $order ) );

		return is_array( $fetched ) && in_array( (string) ( $fetched['status'] ?? '' ), array( 'failed', 'canceled', 'expired' ), true );
	}

	/**
	 * Fetch a payment, trying the other mode's key when the preferred one cannot see it.
	 *
	 * A webhook does not say which mode it belongs to, and a store may have
	 * switched test mode since the payment was made.
	 *
	 * @param string $id        Payment id.
	 * @param string $preferred test|live.
	 *
	 * @return array<string,mixed>|\WP_Error Payment, or wopcart_mollie_unknown_payment.
	 */
	private function fetch_payment( string $id, string $preferred ): array|\WP_Error {
		$modes    = array_unique( array( $preferred, 'test' === $preferred ? 'live' : 'test' ) );
		$rejected = array();

		foreach ( $modes as $mode ) {
			if ( '' === $this->api_key( $mode ) ) {
				continue;
			}

			$payment = $this->api( 'GET', 'payments/' . rawurlencode( $id ), array(), $mode );

			if ( is_array( $payment ) ) {
				return $payment;
			}

			$kind = PaymentState::fetch_error_kind( self::error_status( $payment ) );

			// Anything but "not found" or "key refused" is a transient failure the
			// caller must report, so Mollie retries the webhook.
			if ( PaymentState::FETCH_TRANSIENT === $kind ) {
				return $payment;
			}

			// The other mode may still find it, so a refused key only decides the
			// outcome once every key has been tried.
			if ( PaymentState::FETCH_AUTH === $kind ) {
				$rejected[] = $mode;
			}
		}

		if ( array() !== $rejected ) {
			return new \WP_Error(
				'wopcart_mollie_auth',
				__( 'Mollie rejected the API key.', 'wopcart-direct' ),
				array( 'modes' => $rejected )
			);
		}

		return new \WP_Error( 'wopcart_mollie_unknown_payment', __( 'Mollie has no such payment for this store.', 'wopcart-direct' ) );
	}

	/**
	 * Record that Mollie refused a stored API key.
	 *
	 * A refused key silently stops every order from completing, so it is logged
	 * as an error and raised as an admin notice rather than left to be noticed
	 * from missing sales. The notice is a transient so it clears itself once
	 * nothing has been refused for a day, and a key save clears it at once.
	 *
	 * @param \WP_Error $error   Error from fetch_payment().
	 * @param string    $payment Payment id being fetched.
	 */
	private function report_rejected_key( \WP_Error $error, string $payment ): void {
		$data  = $error->get_error_data();
		$modes = is_array( $data ) ? array_map( 'strval', (array) ( $data['modes'] ?? array() ) ) : array();

		$this->log(
			'Mollie API key rejected (401/403); payments cannot be verified until the key is fixed.',
			array(
				'payment' => $payment,
				'modes'   => $modes,
			),
			Logger::ERROR
		);

		set_transient( self::AUTH_NOTICE, $modes, DAY_IN_SECONDS );
	}

	/**
	 * Admin notice for a rejected Mollie API key.
	 */
	public static function render_auth_notice(): void {
		if ( ! current_user_can( 'manage_wopcart_settings' ) ) {
			return;
		}

		$modes = get_transient( self::AUTH_NOTICE );

		if ( ! is_array( $modes ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
			esc_html__( 'Mollie API key rejected.', 'wopcart-direct' ),
			esc_html(
				sprintf(
					/* translators: %s: comma-separated modes, e.g. live. */
					__( 'Mollie refused the %s key while verifying a payment, so paid orders cannot complete. Mollie keeps retrying its notifications for about a day.', 'wopcart-direct' ),
					implode( ', ', array_map( 'sanitize_key', $modes ) )
				)
			),
			esc_url( admin_url( 'admin.php?page=wopcart-settings&tab=gateways' ) ),
			esc_html__( 'Check the Mollie keys', 'wopcart-direct' )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Responds 200 for handled and for unknown ids — Mollie recommends a 200 for
	 * unknown ids "to not leak any information" (docs.mollie.com/reference/webhooks) —
	 * and 503 when the payment could not be read or written, so Mollie retries
	 * (up to 10 times over 26 hours). Processing is synchronous rather than
	 * acknowledged first: a 200 sent before the work is done would stop those
	 * retries, and one API read plus local writes fits comfortably inside the
	 * 15-second timeout.
	 */
	public function handle_webhook(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) ) : '';

		if ( 'POST' !== $method ) {
			$this->respond( 405, 'method not allowed' );
		}

		/**
		 * Filter how many Mollie webhook calls one address may make per minute.
		 *
		 * The endpoint is unauthenticated and each valid-looking id costs an API
		 * call, so the limit protects the store's Mollie rate limit. It is generous
		 * because every genuine webhook comes from a handful of Mollie addresses,
		 * and a refused call is simply retried by Mollie later.
		 *
		 * @param int $limit Calls per minute; 0 disables the limit.
		 */
		$limit = (int) apply_filters( 'wopcart_mollie_webhook_rate_limit', 300 );

		if ( ! RateLimiter::hit( 'mollie_webhook', Util::ip(), $limit, MINUTE_IN_SECONDS ) ) {
			$this->respond( 429, 'too many requests' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Unsigned by design; authenticity comes from re-fetching the payment with the API key.
		$id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['id'] ) ) : '';

		if ( ! PaymentState::valid_payment_id( $id ) ) {
			$this->respond( 400, 'invalid id' );
		}

		$payment = $this->fetch_payment( $id, $this->current_mode() );

		if ( is_wp_error( $payment ) ) {
			if ( 'wopcart_mollie_unknown_payment' === $payment->get_error_code() ) {
				$this->respond();
			}

			// 503 rather than 200: Mollie keeps retrying, so fixing the key within
			// the retry window still completes the orders.
			if ( 'wopcart_mollie_auth' === $payment->get_error_code() ) {
				$this->report_rejected_key( $payment, $id );
				$this->respond( 503, 'retry' );
			}

			$this->log(
				'Could not fetch a payment named by a webhook.',
				array(
					'payment' => $id,
					'error'   => $payment->get_error_message(),
				),
				Logger::WARNING
			);
			$this->respond( 503, 'retry' );
		}

		$result = $this->process_payment( $payment );

		if ( is_wp_error( $result ) ) {
			$this->log(
				'Webhook processing failed; Mollie will retry.',
				array(
					'payment' => $id,
					'error'   => $result->get_error_message(),
				),
				Logger::WARNING
			);
			$this->respond( 503, 'retry' );
		}

		/**
		 * Fires after a Mollie payment named by a webhook has been fetched and processed.
		 *
		 * @param array<string,mixed> $payment Payment as returned by the API.
		 */
		do_action( 'wopcart_mollie_webhook', $payment );

		$this->respond();
	}

	/**
	 * Apply a fetched payment to the store.
	 *
	 * @param array<string,mixed> $payment Payment from the API.
	 *
	 * @return true|\WP_Error Error when Mollie should retry.
	 */
	private function process_payment( array $payment ): true|\WP_Error {
		$id     = (string) ( $payment['id'] ?? '' );
		$status = (string) ( $payment['status'] ?? '' );

		if ( ! PaymentState::valid_payment_id( $id ) ) {
			return true;
		}

		if ( '' !== (string) ( $payment['subscriptionId'] ?? '' ) ) {
			return $this->handle_subscription_payment( $payment );
		}

		$order_id = (int) ( $payment['metadata']['wopcart_order_id'] ?? 0 );
		$order    = $order_id > 0 ? Order::find( $order_id ) : null;

		if ( null === $order || $this->slug() !== $order->gateway() ) {
			return true;
		}

		// Metadata alone is not proof: the payment must be one this store created
		// for this order.
		if ( ! in_array( $id, array_map( 'strval', (array) $order->get_meta( self::META_PAYMENTS, array() ) ), true ) ) {
			$this->log(
				'Ignored a payment that is not recorded against its order.',
				array(
					'object_id' => $order->id(),
					'payment'   => $id,
				),
				Logger::WARNING
			);

			return true;
		}

		if ( isset( $payment['mode'] ) && (string) $payment['mode'] !== $this->order_mode( $order ) ) {
			$this->log(
				'Ignored a payment from the other mode.',
				array(
					'object_id' => $order->id(),
					'payment'   => $id,
				),
				Logger::WARNING
			);

			return true;
		}

		$claim = 'wopcart_claim_mollie_payment_' . md5( $id );

		// The return handler and the webhook often process the same payment at
		// the same moment; the loser is retried by Mollie.
		if ( ! SubscriptionManager::claim( $claim, 60 ) ) {
			return new \WP_Error( 'wopcart_mollie_busy', 'Payment is being processed by another request.' );
		}

		try {
			if ( 'paid' === $status && ! $this->amount_matches( $order, $payment ) ) {
				$order->set_meta( 'mollie_amount_mismatch', $id );
				$this->log(
					'A paid Mollie payment does not match the order total; the order was not completed.',
					array(
						'object_id' => $order->id(),
						'payment'   => $id,
					),
					Logger::ERROR
				);

				return true;
			}

			$target = PaymentState::order_transition( $status, $order->status() );

			if ( Order::COMPLETE === $target ) {
				$this->complete( $order, $id );
			} elseif ( Order::FAILED === $target ) {
				$order->set_status( Order::FAILED );
				$order->set_meta( 'failure_reason', 'mollie_' . $status );
			} elseif ( Order::PROCESSING === $target ) {
				$order->set_status( Order::PROCESSING );
			}

			$fresh = Order::find( $order->id() ) ?? $order;

			// Only for an order that is complete right now. A redelivered "paid"
			// webhook for an order the merchant has since refunded or revoked must
			// not start billing the customer again.
			if ( 'paid' === $status && 'first' === ( $payment['sequenceType'] ?? '' ) && $fresh->is_complete() ) {
				$created = $this->ensure_subscription( $fresh, $payment );

				if ( is_wp_error( $created ) ) {
					return $created;
				}
			}

			$this->sync_refunds( $fresh, $payment );
			$this->note_chargeback( $fresh, $payment );
		} finally {
			SubscriptionManager::release_claim( $claim );
		}

		return true;
	}

	/**
	 * Whether a paid payment is for exactly the order's total and currency.
	 *
	 * @param Order               $order   Order.
	 * @param array<string,mixed> $payment Payment.
	 *
	 * @return bool
	 */
	private function amount_matches( Order $order, array $payment ): bool {
		$paid = Amount::to_minor( $payment['amount'] ?? null );

		return ! is_wp_error( $paid )
			&& $paid === $order->total()
			&& strtoupper( (string) ( $payment['amount']['currency'] ?? '' ) ) === strtoupper( $order->currency() );
	}

	/**
	 * Create the Mollie subscription once the first payment has left a mandate.
	 *
	 * Runs after complete(), whose status hook has Fulfillment create the local
	 * subscription row. The Mollie subscription starts at that row's next payment
	 * date (the trial end, or one period after today) so the first payment is not
	 * charged twice. Serialised by a claim and an Idempotency-Key, and skipped once
	 * a reference is attached, so webhook retries never create a second schedule.
	 *
	 * @param Order               $order   Completed order.
	 * @param array<string,mixed> $payment Paid first payment.
	 *
	 * @return true|\WP_Error Error when Mollie should retry.
	 */
	private function ensure_subscription( Order $order, array $payment ): true|\WP_Error {
		$local = SubscriptionRepository::find_by_order( $order->id() );

		if ( null === $local || '' !== $local->gateway_subscription_id() ) {
			return true;
		}

		$customer = (string) ( $payment['customerId'] ?? '' );

		if ( 1 !== preg_match( '/\Acst_[A-Za-z0-9]+\z/', $customer ) ) {
			$this->log( 'A first payment came back without a customer; no subscription was created at Mollie.', array( 'object_id' => $order->id() ), Logger::ERROR );

			return true;
		}

		$times = Recurring::times( $local->bill_times(), $local->times_billed() );

		if ( 0 === $times ) {
			return true;
		}

		$interval = Recurring::interval( $local->period(), $local->frequency() );
		$value    = Amount::format( $local->recurring_amount(), $local->currency() );

		// Not retryable: the same data would fail again on every redelivery.
		if ( is_wp_error( $interval ) || is_wp_error( $value ) ) {
			$this->log( 'This subscription cannot be scheduled at Mollie; bill it manually.', array( 'object_id' => $local->id() ), Logger::ERROR );

			return true;
		}

		$body = array(
			'amount'      => array(
				'currency' => strtoupper( $local->currency() ),
				'value'    => $value,
			),
			'interval'    => $interval,
			'startDate'   => Recurring::start_date( $local->next_payment(), time() ),
			// Must be unique per customer among active subscriptions
			// (docs.mollie.com/reference/create-subscription); the id guarantees it.
			'description' => substr( $local->name() . ' #' . $local->id(), 0, 255 ),
			'metadata'    => array(
				'wopcart_subscription_id' => $local->id(),
				'wopcart_order_id'        => $order->id(),
			),
		);

		if ( null !== $times ) {
			$body['times'] = $times;
		}

		$mandate = (string) ( $payment['mandateId'] ?? '' );

		if ( 1 === preg_match( '/\Amdt_[A-Za-z0-9]+\z/', $mandate ) ) {
			$body['mandateId'] = $mandate;
		}

		$webhook = $this->mollie_webhook_url();

		if ( '' !== $webhook ) {
			$body['webhookUrl'] = $webhook;
		}

		$claim = 'wopcart_claim_mollie_subscription_' . md5( (string) $order->id() );

		if ( ! SubscriptionManager::claim( $claim, 120 ) ) {
			return new \WP_Error( 'wopcart_mollie_busy', 'Subscription is being created by another request.' );
		}

		try {
			$again = SubscriptionRepository::find_by_order( $order->id() );

			if ( null !== $again && '' !== $again->gateway_subscription_id() ) {
				return true;
			}

			$mode    = in_array( $payment['mode'] ?? '', array( 'test', 'live' ), true ) ? (string) $payment['mode'] : $this->order_mode( $order );
			$created = $this->api( 'POST', 'customers/' . rawurlencode( $customer ) . '/subscriptions', $body, $mode, 'wopcart-subscription-' . $local->id() );

			// A directdebit mandate can still be pending for a moment after the
			// first payment; the webhook retry creates the subscription then.
			if ( is_wp_error( $created ) ) {
				return $created;
			}

			$reference = (string) ( $created['id'] ?? '' );

			if ( 1 !== preg_match( '/\Asub_[A-Za-z0-9]+\z/', $reference ) ) {
				return new \WP_Error( 'wopcart_mollie_no_subscription', __( 'Mollie did not return a subscription.', 'wopcart-direct' ) );
			}

			SubscriptionManager::attach_gateway_reference( $order->id(), $this->slug(), $reference, $customer );

			$this->log(
				'Mollie subscription created.',
				array(
					'object_id' => $local->id(),
					'reference' => $reference,
				)
			);
		} finally {
			SubscriptionManager::release_claim( $claim );
		}

		return true;
	}

	/**
	 * Apply a payment Mollie created from a subscription.
	 *
	 * Idempotent: the renewal is keyed on the payment id, checked inside a claim,
	 * and SubscriptionManager::record_renewal() checks the transaction id again. A
	 * failure is counted once per payment through a claim that is never released.
	 *
	 * @param array<string,mixed> $payment Payment.
	 *
	 * @return true|\WP_Error
	 */
	private function handle_subscription_payment( array $payment ): true|\WP_Error {
		$id           = (string) $payment['id'];
		$status       = (string) ( $payment['status'] ?? '' );
		$subscription = SubscriptionRepository::find_by_gateway_id( $this->slug(), (string) $payment['subscriptionId'] );

		if ( null === $subscription ) {
			return true;
		}

		$claim = 'wopcart_claim_mollie_renewal_' . md5( $id );

		if ( ! SubscriptionManager::claim( $claim, 120 ) ) {
			return new \WP_Error( 'wopcart_mollie_busy', 'Renewal is being recorded by another request.' );
		}

		try {
			$recorded = OrderRepository::find_by_transaction( $id, $this->slug() );
			$action   = PaymentState::renewal_action( $status, null !== $recorded );

			if ( PaymentState::RENEWAL_RECORD === $action ) {
				$amount = Amount::to_minor( $payment['amount'] ?? null );

				if ( is_wp_error( $amount ) ) {
					$this->log(
						'A renewal payment had an unreadable amount.',
						array(
							'object_id' => $subscription->id(),
							'payment'   => $id,
						),
						Logger::ERROR
					);

					return true;
				}

				SubscriptionManager::record_renewal(
					$subscription,
					$amount,
					strtoupper( (string) ( $payment['amount']['currency'] ?? $subscription->currency() ) ),
					$id
				);
			} elseif ( PaymentState::RENEWAL_FAILURE === $action && $this->count_failure_once( $subscription, $id ) ) {
				SubscriptionManager::record_failure(
					$subscription,
					sprintf(
						/* translators: %s: Mollie payment status. */
						__( 'Mollie reported the renewal payment as %s.', 'wopcart-direct' ),
						$status
					)
				);

				$this->sync_subscription_status( $subscription );
			}

			$renewal = OrderRepository::find_by_transaction( $id, $this->slug() );

			if ( null !== $renewal ) {
				$this->sync_refunds( $renewal, $payment );
				$this->note_chargeback( $renewal, $payment );
			}
		} finally {
			SubscriptionManager::release_claim( $claim );
		}

		return true;
	}

	/**
	 * Whether a failed renewal payment has not been counted yet, marking it counted.
	 *
	 * The ids live in a bounded list on the subscription's first order, because
	 * subscriptions have no meta of their own. Called inside the per-payment
	 * renewal claim, so one payment's redeliveries cannot race each other.
	 *
	 * @param Subscription $subscription Subscription.
	 * @param string       $id           Failed payment id.
	 *
	 * @return bool
	 */
	private function count_failure_once( Subscription $subscription, string $id ): bool {
		$order = Order::find( $subscription->order_id() );

		if ( null === $order ) {
			// Nowhere to remember it; counting a redelivery twice is the lesser
			// harm than never counting a failed renewal at all.
			return true;
		}

		$list = PaymentState::remember_failure( (array) $order->get_meta( self::META_FAILED_RENEWALS, array() ), $id );

		if ( null === $list ) {
			return false;
		}

		$order->set_meta( self::META_FAILED_RENEWALS, $list );

		return true;
	}

	/**
	 * Mirror a subscription Mollie ended itself, e.g. after a revoked mandate.
	 *
	 * Mollie sends webhooks for subscription payments, not for the subscription
	 * (docs.mollie.com/reference/webhooks), so its status is checked after a failure.
	 *
	 * @param Subscription $subscription Subscription.
	 */
	private function sync_subscription_status( Subscription $subscription ): void {
		$reference = $subscription->gateway_subscription_id();
		$customer  = $subscription->gateway_customer_id();

		if ( '' === $reference || '' === $customer ) {
			return;
		}

		$remote = $this->api( 'GET', 'customers/' . rawurlencode( $customer ) . '/subscriptions/' . rawurlencode( $reference ), array(), $this->subscription_mode( $subscription ) );

		if ( ! is_array( $remote ) ) {
			return;
		}

		$status = (string) ( $remote['status'] ?? '' );

		if ( 'canceled' === $status ) {
			SubscriptionManager::set_status( $subscription, Subscription::CANCELLED, 'gateway_webhook' );
		} elseif ( 'completed' === $status ) {
			SubscriptionManager::set_status( $subscription, Subscription::COMPLETED, 'gateway_webhook' );
		} elseif ( 'suspended' === $status ) {
			$this->log( 'Mollie suspended this subscription; the customer needs a new mandate.', array( 'object_id' => $subscription->id() ), Logger::WARNING );
		}
	}

	/**
	 * Store Mollie's refunded total when it is ahead of the store's.
	 *
	 * The payment webhook fires when a refund reaches processing, refunded or
	 * failed (docs.mollie.com/reference/webhooks); `amountRefunded` on the fetched
	 * payment is the authority (docs.mollie.com/reference/get-payment). Setting the
	 * total rather than adding to it makes every redelivery harmless.
	 *
	 * @param Order               $order   Order.
	 * @param array<string,mixed> $payment Payment.
	 */
	private function sync_refunds( Order $order, array $payment ): void {
		if ( ! isset( $payment['amountRefunded'] ) ) {
			return;
		}

		$remote = Amount::to_minor( $payment['amountRefunded'] );

		if ( is_wp_error( $remote ) || $remote <= $order->refunded() ) {
			return;
		}

		$order->update( array( 'refunded' => $remote ) );

		$status = PaymentState::refund_status( $remote, $order->total() );

		if ( '' !== $status ) {
			$order->set_status( $status );
		}
	}

	/**
	 * Flag a chargeback for the merchant.
	 *
	 * Access is not revoked automatically: Order::REVOKED is a merchant decision,
	 * and a SEPA chargeback can be reversed. The flag, the log entry and the
	 * action let a store automate it if it wants to.
	 *
	 * @param Order               $order   Order.
	 * @param array<string,mixed> $payment Payment.
	 */
	private function note_chargeback( Order $order, array $payment ): void {
		if ( ! isset( $payment['amountChargedBack'] ) ) {
			return;
		}

		$amount = Amount::to_minor( $payment['amountChargedBack'] );

		if ( is_wp_error( $amount ) || $amount <= 0 || (int) $order->get_meta( 'mollie_charged_back', 0 ) >= $amount ) {
			return;
		}

		$order->set_meta( 'mollie_charged_back', $amount );

		$this->log(
			'Mollie reported a chargeback.',
			array(
				'object_id' => $order->id(),
				'amount'    => $amount,
			),
			Logger::WARNING
		);

		/**
		 * Fires when Mollie reports a (larger) chargeback on an order's payment.
		 *
		 * @param Order $order  Order.
		 * @param int   $amount Charged back total, minor units.
		 */
		do_action( 'wopcart_mollie_chargeback', $order, $amount );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Refunds go through the Refunds API (docs.mollie.com/reference/create-refund).
	 * Mollie queues a refund when the balance is too low (docs.mollie.com/docs/refunds);
	 * a queued refund is still recorded here because Mollie has accepted it.
	 *
	 * @param Order $order  Order to refund.
	 * @param int   $amount Amount in minor units, 0 for the full remaining balance.
	 *
	 * @return true|\WP_Error
	 */
	public function refund( Order $order, int $amount = 0 ): true|\WP_Error {
		$id = $order->transaction_id();

		if ( ! PaymentState::valid_payment_id( $id ) ) {
			$id = (string) $order->get_meta( self::META_PAYMENT, '' );
		}

		if ( ! PaymentState::valid_payment_id( $id ) ) {
			return new \WP_Error( 'wopcart_mollie_no_payment', __( 'This order has no Mollie payment to refund.', 'wopcart-direct' ) );
		}

		$mode    = $this->order_mode( $order );
		$payment = $this->api( 'GET', 'payments/' . rawurlencode( $id ), array(), $mode );

		if ( is_wp_error( $payment ) ) {
			return $payment;
		}

		if ( 'paid' !== ( $payment['status'] ?? '' ) ) {
			return new \WP_Error( 'wopcart_mollie_not_paid', __( 'Only a paid Mollie payment can be refunded.', 'wopcart-direct' ) );
		}

		$remaining = isset( $payment['amountRemaining'] ) ? Amount::to_minor( $payment['amountRemaining'] ) : null;
		$before    = $order->refunded();
		$resolved  = PaymentState::refund_amount( $amount, $order->refundable(), is_int( $remaining ) ? $remaining : null );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$value = Amount::format( $resolved, $order->currency() );

		if ( is_wp_error( $value ) ) {
			return $value;
		}

		$response = $this->api(
			'POST',
			'payments/' . rawurlencode( $id ) . '/refunds',
			array(
				'amount'      => array(
					'currency' => strtoupper( $order->currency() ),
					'value'    => $value,
				),
				/* translators: %s: order number. */
				'description' => substr( sprintf( __( 'Refund for order %s', 'wopcart-direct' ), $order->number() ), 0, 255 ),
				'metadata'    => array( 'wopcart_order_id' => $order->id() ),
			),
			$mode,
			// Includes the refunded total so a later, identical partial refund is a
			// new request rather than a replay of this one.
			'wopcart-refund-' . $order->id() . '-' . $before . '-' . $resolved
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$fresh = Order::find( $order->id() );
		$total = PaymentState::refunded_after( $before, $resolved, null !== $fresh ? $fresh->refunded() : $before );

		$order->update( array( 'refunded' => $total ) );

		$status = PaymentState::refund_status( $total, $order->total() );

		if ( '' !== $status ) {
			$order->set_status( $status );
		}

		return true;
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
			__( 'Mollie bills this subscription directly.', 'wopcart-direct' )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Mollie cancels a subscription at once and takes no further payments
	 * (docs.mollie.com/reference/cancel-subscription); the paid period still runs
	 * out locally because SubscriptionManager::cancel() keeps access until then.
	 * Nothing is refunded either way.
	 *
	 * @param Subscription $subscription  Subscription.
	 * @param bool         $at_period_end Ignored; see above.
	 *
	 * @return true|\WP_Error
	 */
	public function cancel_subscription( Subscription $subscription, bool $at_period_end = true ): true|\WP_Error {
		$reference = $subscription->gateway_subscription_id();
		$customer  = $subscription->gateway_customer_id();

		if ( '' === $reference ) {
			return true;
		}

		if ( '' === $customer ) {
			return new \WP_Error( 'wopcart_mollie_no_customer', __( 'This subscription has no Mollie customer to cancel it for.', 'wopcart-direct' ) );
		}

		$mode   = $this->subscription_mode( $subscription );
		$path   = 'customers/' . rawurlencode( $customer ) . '/subscriptions/' . rawurlencode( $reference );
		$result = $this->api( 'DELETE', $path, array(), $mode );

		if ( is_array( $result ) ) {
			return true;
		}

		// Cancelling twice is not an error worth showing the customer.
		$remote = $this->api( 'GET', $path, array(), $mode );

		if ( is_array( $remote ) && in_array( (string) ( $remote['status'] ?? '' ), array( 'canceled', 'completed' ), true ) ) {
			return true;
		}

		return $result;
	}

	/**
	 * Change the amount of a Mollie subscription.
	 *
	 * Business rule: never refund or credit, and downgrades take effect at the
	 * next charge. Mollie applies an amount change to future payments only
	 * (docs.mollie.com/reference/update-subscription), which is that rule exactly,
	 * so downgrades (TIMING_NEXT_PERIOD) and withdrawn downgrades (TIMING_REVERT)
	 * are one PATCH.
	 *
	 * Upgrades (TIMING_NOW) are refused. PlanChange applies an upgrade the moment
	 * this method returns true, expecting the prorated difference to have been
	 * charged. Mollie has no proration, and an on-demand charge against a SEPA
	 * mandate stays pending for days, so it could neither be confirmed now nor
	 * safely assumed — granting the higher tier would mean giving it away.
	 *
	 * @param Subscription $subscription Live subscription.
	 * @param Price        $price        Target price option.
	 * @param string       $timing       PlanChange::TIMING_* constant.
	 * @param int|null     $unit_amount  Per-period amount including tax, minor units.
	 *
	 * @return true|\WP_Error
	 */
	public function change_plan( Subscription $subscription, Price $price, string $timing = PlanChange::TIMING_NOW, ?int $unit_amount = null ): true|\WP_Error {
		if ( PlanChange::TIMING_NOW === $timing ) {
			return new \WP_Error(
				'wopcart_mollie_upgrade_unsupported',
				__( 'Upgrades are not available for subscriptions paid through Mollie. Please contact us to change plan.', 'wopcart-direct' )
			);
		}

		$reference = $subscription->gateway_subscription_id();
		$customer  = $subscription->gateway_customer_id();

		if ( '' === $reference || '' === $customer ) {
			return new \WP_Error( 'wopcart_mollie_no_subscription', __( 'This subscription has no Mollie reference to change.', 'wopcart-direct' ) );
		}

		if ( null === $unit_amount ) {
			$product = $subscription->product();

			if ( null === $product ) {
				return new \WP_Error( 'wopcart_plan_no_product', __( 'The product behind this subscription is no longer available.', 'wopcart-direct' ) );
			}

			$unit_amount = PlanChange::recurring_gross( $subscription, $product, $price );
		}

		$interval = Recurring::interval( $price->period, $price->frequency );
		$value    = Amount::format( max( 0, $unit_amount ), $subscription->currency() );

		if ( is_wp_error( $interval ) ) {
			return $interval;
		}

		if ( is_wp_error( $value ) || $unit_amount <= 0 ) {
			return new \WP_Error( 'wopcart_mollie_amount', __( 'That plan cannot be billed through Mollie.', 'wopcart-direct' ) );
		}

		$mode   = $this->subscription_mode( $subscription );
		$path   = 'customers/' . rawurlencode( $customer ) . '/subscriptions/' . rawurlencode( $reference );
		$remote = $this->api( 'GET', $path, array(), $mode );

		if ( is_wp_error( $remote ) ) {
			return $remote;
		}

		// Canceled subscriptions cannot be updated (docs.mollie.com/reference/update-subscription).
		if ( ! in_array( (string) ( $remote['status'] ?? '' ), array( 'active', 'pending' ), true ) ) {
			return new \WP_Error( 'wopcart_mollie_subscription_state', __( 'This subscription cannot change plan in its current billing state.', 'wopcart-direct' ) );
		}

		// Changing the interval would move the schedule; PlanChange already
		// refuses cross-interval downgrades, and this keeps Mollie in step.
		if ( (string) ( $remote['interval'] ?? '' ) !== $interval ) {
			return new \WP_Error( 'wopcart_mollie_interval_change', __( 'A plan with a different billing interval cannot be scheduled for this subscription.', 'wopcart-direct' ) );
		}

		$result = $this->api(
			'PATCH',
			$path,
			array(
				'amount' => array(
					'currency' => strtoupper( $subscription->currency() ),
					'value'    => $value,
				),
			),
			$mode
		);

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Add the Mollie section to the Payments tab.
	 *
	 * Core's Settings::sections() applies its own paid-plan gate to the sections it
	 * builds before this filter runs, and strips anything flagged
	 * `requires_paid_plan` on the free plan. This section deliberately carries no
	 * such flag: a store with this plugin installed is answering core's entitlement
	 * question with yes in any case, so the flag would only create a second place
	 * for the answer to disagree with itself.
	 *
	 * @param array<int,array<string,mixed>> $sections Sections.
	 * @param string                         $tab      Tab key.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function settings_sections( array $sections, string $tab ): array {
		if ( 'gateways' !== $tab ) {
			return $sections;
		}

		$has_live = '' !== trim( (string) Options::get( 'mollie_live_api_key', '' ) );
		$has_test = '' !== trim( (string) Options::get( 'mollie_test_api_key', '' ) );

		$sections[] = array(
			'title'       => __( 'Mollie', 'wopcart-direct' ),
			'description' => __( 'Your own Mollie API keys. Buyers pay on Mollie\'s hosted checkout with iDEAL, Bancontact, cards, Klarna and the other methods active on your Mollie profile; subscriptions renew through Mollie by card, PayPal or SEPA Direct Debit. The webhook address is sent with every payment, so there is nothing to configure in Mollie. Mollie webhooks are not signed: every notification is checked by fetching the payment from Mollie with your key. Keys can also be defined as WOPCART_MOLLIE_LIVE_KEY and WOPCART_MOLLIE_TEST_KEY in wp-config.php.', 'wopcart-direct' ),
			'fields'      => array(
				// Write-only: the stored keys live under mollie_{mode}_api_key,
				// which are not fields, so they are never printed back into the page.
				'mollie_live_api_key_input' => array(
					'type'        => 'password',
					'label'       => __( 'Live API key', 'wopcart-direct' ),
					'default'     => '',
					'description' => $has_live
						? __( 'A live key is saved. Leave this blank to keep it, or paste a new one (starting live_) to replace it.', 'wopcart-direct' )
						: __( 'No live key is saved. Paste the key starting live_ from Developers → API keys in Mollie.', 'wopcart-direct' ),
				),
				'mollie_live_api_key_clear' => array(
					'type'    => 'checkbox',
					'label'   => __( 'Remove the saved live API key', 'wopcart-direct' ),
					'default' => false,
				),
				'mollie_test_api_key_input' => array(
					'type'        => 'password',
					'label'       => __( 'Test API key', 'wopcart-direct' ),
					'default'     => '',
					'description' => $has_test
						? __( 'A test key is saved. Leave this blank to keep it, or paste a new one (starting test_) to replace it.', 'wopcart-direct' )
						: __( 'No test key is saved. Used while test mode is on.', 'wopcart-direct' ),
				),
				'mollie_test_api_key_clear' => array(
					'type'    => 'checkbox',
					'label'   => __( 'Remove the saved test API key', 'wopcart-direct' ),
					'default' => false,
				),
				'mollie_profile_id'         => array(
					'type'        => 'text',
					'label'       => __( 'Profile ID', 'wopcart-direct' ),
					'description' => __( 'Optional, for your reference (starts pfl_). An API key already belongs to one Mollie profile, so payments go to the profile of the key above.', 'wopcart-direct' ),
				),
				'mollie_methods'            => array(
					'type'        => 'multiselect',
					'label'       => __( 'Limit checkout to these methods', 'wopcart-direct' ),
					'default'     => array(),
					'options'     => array(
						'ideal'      => __( 'iDEAL', 'wopcart-direct' ),
						'bancontact' => __( 'Bancontact', 'wopcart-direct' ),
						'creditcard' => __( 'Cards', 'wopcart-direct' ),
						'klarna'     => __( 'Klarna', 'wopcart-direct' ),
						'paypal'     => __( 'PayPal', 'wopcart-direct' ),
						'eps'        => __( 'EPS', 'wopcart-direct' ),
						'kbc'        => __( 'KBC/CBC', 'wopcart-direct' ),
						'belfius'    => __( 'Belfius', 'wopcart-direct' ),
						'paybybank'  => __( 'Pay by Bank', 'wopcart-direct' ),
					),
					'description' => __( 'Leave empty to let Mollie show every method active on your profile. Subscriptions only offer methods that can set up a mandate; Klarna cannot, and a free trial can only start with a card or PayPal.', 'wopcart-direct' ),
				),
			),
		);

		return $sections;
	}

	/**
	 * Move newly entered API keys into place and drop the helper fields.
	 *
	 * Keys are only accepted from a save of the Payments tab, and only with the
	 * prefix of their mode (docs show test keys as `test_…`), so a live key pasted
	 * into the test field is refused rather than silently used.
	 *
	 * @param array<string,mixed> $values Sanitised values about to be stored.
	 * @param array<string,mixed> $input  Raw submitted form.
	 *
	 * @return array<string,mixed>
	 */
	public static function save_settings( array $values, array $input = array() ): array {
		if ( 'gateways' !== (string) ( $input['tab'] ?? '' ) ) {
			foreach ( self::HELPER_FIELDS as $field ) {
				unset( $values[ $field ] );
			}

			return $values;
		}

		foreach ( array( 'live', 'test' ) as $mode ) {
			if ( ! empty( $values[ 'mollie_' . $mode . '_api_key_clear' ] ) ) {
				$values[ 'mollie_' . $mode . '_api_key' ] = '';
			}

			$entered = trim( (string) ( $values[ 'mollie_' . $mode . '_api_key_input' ] ?? '' ) );

			if ( '' === $entered ) {
				continue;
			}

			if ( 1 === preg_match( '/\A' . $mode . '_[A-Za-z0-9]{20,}\z/', $entered ) ) {
				$values[ 'mollie_' . $mode . '_api_key' ] = $entered;

				// A new key is the fix for a rejected one; do not keep nagging.
				delete_transient( self::AUTH_NOTICE );
				continue;
			}

			if ( function_exists( 'add_settings_error' ) ) {
				add_settings_error(
					'wopcart',
					'wopcart_mollie_' . $mode . '_key',
					sprintf(
						/* translators: %s: key prefix, e.g. live_. */
						__( 'That Mollie key was not saved: it must start with %s.', 'wopcart-direct' ),
						$mode . '_'
					)
				);
			}
		}

		if ( array_key_exists( 'mollie_profile_id', $values ) ) {
			$profile                     = trim( (string) $values['mollie_profile_id'] );
			$values['mollie_profile_id'] = 1 === preg_match( '/\Apfl_[A-Za-z0-9]+\z/', $profile ) ? $profile : '';
		}

		foreach ( self::HELPER_FIELDS as $field ) {
			unset( $values[ $field ] );
		}

		return $values;
	}
}
