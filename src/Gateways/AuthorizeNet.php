<?php
/**
 * Authorize.net gateway.
 *
 * @package WopCartDirect
 */

declare(strict_types=1);

namespace WopCartDirect\Gateways;

use WopCart\Gateways\AbstractGateway;
use WopCart\Gateways\CartProfile;
use WopCart\Model\Order;
use WopCart\Model\OrderItem;
use WopCart\Model\Subscription;
use WopCart\Repository\OrderRepository;
use WopCart\Repository\SubscriptionRepository;
use WopCart\Subscriptions\SubscriptionManager;
use WopCart\Support\Http;
use WopCart\Support\Logger;
use WopCart\Support\Money;

/**
 * Card payments and recurring billing through Authorize.net.
 *
 * The card form is Accept.js, so the browser sends card data straight to
 * Authorize.net and hands the page back an opaque one-time token. The token is
 * all this class ever sees, which keeps the store out of PCI scope for card data
 * while still presenting a card form on the site rather than a redirect.
 *
 * Recurring billing uses Automated Recurring Billing (ARB): Authorize.net owns the
 * schedule and charges it, and the plugin listens for the resulting webhooks.
 */
final class AuthorizeNet extends AbstractGateway {

	/**
	 * Features supported.
	 *
	 * @var string[]
	 */
	protected array $supports = array( 'refunds', 'subscriptions', 'trials', 'webhooks' );

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'authorizenet';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Credit or debit card (Authorize.net)', 'wopcart-direct' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	protected function has_credentials(): bool {
		return '' !== $this->login_id() && '' !== $this->transaction_key();
	}

	/**
	 * API endpoint for the active mode.
	 *
	 * @return string
	 */
	private function endpoint(): string {
		return $this->is_test()
			? 'https://apitest.authorize.net/xml/v1/request.api'
			: 'https://api.authorize.net/xml/v1/request.api';
	}

	/**
	 * Accept.js script URL for the active mode.
	 *
	 * @return string
	 */
	public function accept_js_url(): string {
		return $this->is_test()
			? 'https://jstest.authorize.net/v1/Accept.js'
			: 'https://js.authorize.net/v1/Accept.js';
	}

	/**
	 * API login ID.
	 *
	 * @return string
	 */
	public function login_id(): string {
		return trim( (string) $this->setting( 'authnet_login_id' ) );
	}

	/**
	 * Transaction key.
	 *
	 * @return string
	 */
	private function transaction_key(): string {
		return trim( (string) $this->setting( 'authnet_transaction_key' ) );
	}

	/**
	 * Public client key used by Accept.js, stored alongside the signature key.
	 *
	 * @return string
	 */
	public function public_client_key(): string {
		return trim( (string) $this->setting( 'authnet_public_client_key' ) );
	}

	/**
	 * Post a request to the Authorize.net JSON API.
	 *
	 * Their API answers HTTP 200 even for failures, so the result code inside the
	 * body is what actually decides success. It also returns a UTF-8 BOM that
	 * breaks json_decode if it is not stripped first.
	 *
	 * @param array<string,mixed> $request Request envelope.
	 * @param string              $wrapper Root element name.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	private function api( array $request, string $wrapper ): array|\WP_Error {
		$payload = array(
			$wrapper => array_merge(
				array(
					'merchantAuthentication' => array(
						'name'           => $this->login_id(),
						'transactionKey' => $this->transaction_key(),
					),
				),
				$request
			),
		);

		$response = wp_remote_post(
			$this->endpoint(),
			array(
				'timeout' => 30,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			Logger::error( 'gateway.authorizenet', 'Request failed.', array( 'error' => $response->get_error_message() ) );

			return new \WP_Error( 'wopcart_authnet_http', __( 'We could not reach Authorize.net. Please try again.', 'wopcart-direct' ) );
		}

		$body    = ltrim( (string) wp_remote_retrieve_body( $response ), "\xEF\xBB\xBF \t\n\r" );
		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'wopcart_authnet_parse', __( 'Authorize.net returned an unreadable response.', 'wopcart-direct' ) );
		}

		$result_code = (string) ( $decoded['messages']['resultCode'] ?? '' );

		if ( 'Ok' !== $result_code ) {
			$message = (string) (
				$decoded['transactionResponse']['errors'][0]['errorText']
				?? $decoded['messages']['message'][0]['text']
				?? __( 'The payment was declined.', 'wopcart-direct' )
			);

			Logger::warning( 'gateway.authorizenet', 'Request rejected.', array( 'response' => $decoded ) );

			return new \WP_Error( 'wopcart_authnet_declined', $message, array( 'response' => $decoded ) );
		}

		// A transaction can be "Ok" at the envelope level and still be declined.
		$transaction_code = (int) ( $decoded['transactionResponse']['responseCode'] ?? 1 );

		if ( isset( $decoded['transactionResponse'] ) && 1 !== $transaction_code ) {
			$message = (string) (
				$decoded['transactionResponse']['errors'][0]['errorText']
				?? __( 'The card was declined.', 'wopcart-direct' )
			);

			return new \WP_Error( 'wopcart_authnet_declined', $message, array( 'response' => $decoded ) );
		}

		return $decoded;
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
		$unsupported = self::arb_cart_error( CartProfile::from_order( $order ) );

		if ( null !== $unsupported ) {
			return new \WP_Error( 'wopcart_authnet_unsupported_subscription', $unsupported );
		}

		$descriptor = (string) ( $input['payment_nonce'] ?? '' );
		$value      = (string) ( $input['payment_token'] ?? '' );

		if ( '' === $descriptor || '' === $value ) {
			return new \WP_Error(
				'wopcart_authnet_no_token',
				__( 'Your card details were not accepted. Please re-enter them and try again.', 'wopcart-direct' )
			);
		}

		$recurring = null;

		foreach ( $order->items() as $item ) {
			if ( $item->is_recurring() ) {
				$recurring = $item;
				break;
			}
		}

		if ( null !== $recurring ) {
			return $this->create_arb_subscription( $order, $recurring, $descriptor, $value );
		}

		return $this->charge( $order, $descriptor, $value );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param CartProfile $profile Cart or order profile.
	 *
	 * @return string|null
	 */
	public function cart_error( CartProfile $profile ): ?string {
		return self::arb_cart_error( $profile );
	}

	/**
	 * Pure: why ARB cannot bill a checkout exactly, null when it can.
	 *
	 * A subscription is paid by creating one ARB subscription and nothing else,
	 * and ARB charges its first occurrence at the recurring amount. A sign-up fee,
	 * a first-payment-only discount, an introductory sale price, a second
	 * subscription or a one-off line alongside it would therefore be left out of,
	 * or overcharged in, the first payment. Such carts are refused until the
	 * first payment is taken separately (Customer Profile + ARB from profile).
	 *
	 * @param CartProfile $profile Cart or order profile.
	 *
	 * @return string|null
	 */
	public static function arb_cart_error( CartProfile $profile ): ?string {
		if ( ! $profile->has_recurring() ) {
			return null;
		}

		$message = __( "This subscription can't be paid with Authorize.net. Please choose another payment method.", 'wopcart-direct' );

		if ( 1 !== count( $profile->recurring ) || $profile->one_off > 0 ) {
			return $message;
		}

		$line  = $profile->recurring[0];
		$today = $line['subtotal'] - $line['discount'];
		$first = $line['trial_days'] > 0 ? 0 : $line['recurring_subtotal'];

		return $line['signup_fee'] > 0 || $today !== $first ? $message : null;
	}

	/**
	 * Take a one-off card payment.
	 *
	 * @param Order  $order      Order.
	 * @param string $descriptor Accept.js data descriptor.
	 * @param string $value      Accept.js data value.
	 *
	 * @return array{redirect:string}|\WP_Error
	 */
	private function charge( Order $order, string $descriptor, string $value ): array|\WP_Error {
		$billing  = $order->billing();
		$currency = $order->currency();

		$response = $this->api(
			array(
				'refId'              => (string) $order->id(),
				'transactionRequest' => array(
					'transactionType' => 'authCaptureTransaction',
					'amount'          => number_format( Money::to_major( $order->total(), $currency ), 2, '.', '' ),
					'currencyCode'    => $currency,
					'payment'         => array(
						'opaqueData' => array(
							'dataDescriptor' => $descriptor,
							'dataValue'      => $value,
						),
					),
					'order'           => array(
						'invoiceNumber' => substr( $order->number(), 0, 20 ),
						'description'   => substr( $this->description( $order ), 0, 255 ),
					),
					'customer'        => array(
						'id'    => substr( (string) $order->customer_id(), 0, 20 ),
						'email' => $order->email(),
					),
					'billTo'          => $this->bill_to( $billing ),
					'customerIP'      => (string) ( $order->to_array()['ip_address'] ?? '' ),
				),
			),
			'createTransactionRequest'
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$transaction_id = (string) ( $response['transactionResponse']['transId'] ?? '' );
		$account        = (string) ( $response['transactionResponse']['accountNumber'] ?? '' );

		// Authorize.net requires the last four digits to issue a refund later.
		$order->set_meta( 'authnet_last_four', substr( $account, -4 ) );

		$this->complete( $order, $transaction_id );

		return array( 'redirect' => $order->receipt_url() );
	}

	/**
	 * Create an ARB subscription.
	 *
	 * @param Order     $order      Order.
	 * @param OrderItem $item       Recurring line.
	 * @param string    $descriptor Accept.js data descriptor.
	 * @param string    $value      Accept.js data value.
	 *
	 * @return array{redirect:string}|\WP_Error
	 */
	private function create_arb_subscription( Order $order, OrderItem $item, string $descriptor, string $value ): array|\WP_Error {
		$product = $item->product();
		$option  = $product?->price_option( '' === $item->price_id() ? null : $item->price_id() );

		if ( null === $option ) {
			return new \WP_Error( 'wopcart_authnet_no_price', __( 'The subscription price is no longer available.', 'wopcart-direct' ) );
		}

		$currency = $order->currency();
		$meta     = $item->meta();
		$net      = (int) ( $meta['recurring_subtotal'] ?? $item->subtotal() );
		$gross    = $net + (int) round( $net * ( $item->tax_rate() / 100 ) );
		$interval = $this->arb_interval( $option->period, $option->frequency );

		if ( is_wp_error( $interval ) ) {
			return $interval;
		}

		$schedule = array(
			'interval'         => $interval,
			'startDate'        => gmdate( 'Y-m-d', time() + ( $option->trial_days * DAY_IN_SECONDS ) ),
			'totalOccurrences' => $option->bill_times > 0 ? $option->bill_times : 9999,
		);

		// ARB expresses a free trial as trial occurrences at a trial amount, which
		// has to be a whole number of billing periods rather than an arbitrary
		// number of days. A day-length trial is therefore modelled by pushing the
		// start date out instead, which is what the startDate above already does.
		$request = array(
			'refId'        => (string) $order->id(),
			'subscription' => array(
				'name'            => substr( $item->name(), 0, 50 ),
				'paymentSchedule' => $schedule,
				'amount'          => number_format( Money::to_major( $gross, $currency ), 2, '.', '' ),
				'payment'         => array(
					'opaqueData' => array(
						'dataDescriptor' => $descriptor,
						'dataValue'      => $value,
					),
				),
				'order'           => array(
					'invoiceNumber' => substr( $order->number(), 0, 20 ),
					'description'   => substr( $item->name(), 0, 255 ),
				),
				'customer'        => array( 'email' => $order->email() ),
				'billTo'          => $this->bill_to( $order->billing(), true ),
			),
		);

		$response = $this->api( $request, 'ARBCreateSubscriptionRequest' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$subscription_id = (string) ( $response['subscriptionId'] ?? '' );

		$order->set_meta( 'authnet_subscription_id', $subscription_id );
		$this->complete( $order, $subscription_id );

		SubscriptionManager::attach_gateway_reference( $order->id(), $this->slug(), $subscription_id, '' );

		return array( 'redirect' => $order->receipt_url() );
	}

	/**
	 * Translate a billing period into an ARB interval.
	 *
	 * ARB only understands days (7 to 365) and months (1 to 12), so anything
	 * outside that is rejected up front rather than failing at the API.
	 *
	 * @param string $period    day|week|month|year.
	 * @param int    $frequency Periods between charges.
	 *
	 * @return array{length:int,unit:string}|\WP_Error
	 */
	private function arb_interval( string $period, int $frequency ): array|\WP_Error {
		$frequency = max( 1, $frequency );

		$interval = match ( $period ) {
			'day'   => array( 'length' => $frequency, 'unit' => 'days' ),
			'week'  => array( 'length' => $frequency * 7, 'unit' => 'days' ),
			'year'  => array( 'length' => $frequency * 12, 'unit' => 'months' ),
			default => array( 'length' => $frequency, 'unit' => 'months' ),
		};

		$valid = 'days' === $interval['unit']
			? ( $interval['length'] >= 7 && $interval['length'] <= 365 )
			: ( $interval['length'] >= 1 && $interval['length'] <= 12 );

		if ( ! $valid ) {
			return new \WP_Error(
				'wopcart_authnet_interval',
				__( 'Authorize.net cannot bill on that schedule. Use an interval between 7 days and 12 months.', 'wopcart-direct' )
			);
		}

		return $interval;
	}

	/**
	 * Build the billTo block.
	 *
	 * @param array<string,mixed> $billing Billing details.
	 * @param bool                $minimal Whether to omit optional fields.
	 *
	 * @return array<string,string>
	 */
	private function bill_to( array $billing, bool $minimal = false ): array {
		$bill_to = array(
			'firstName' => substr( (string) ( $billing['first_name'] ?? '' ), 0, 50 ),
			'lastName'  => substr( (string) ( $billing['last_name'] ?? '' ), 0, 50 ),
			'country'   => substr( (string) ( $billing['country'] ?? '' ), 0, 2 ),
		);

		if ( $minimal ) {
			return array_filter( $bill_to );
		}

		return array_filter(
			array_merge(
				$bill_to,
				array(
					'company' => substr( (string) ( $billing['company'] ?? '' ), 0, 50 ),
					'address' => substr( (string) ( $billing['address_1'] ?? '' ), 0, 60 ),
					'city'    => substr( (string) ( $billing['city'] ?? '' ), 0, 40 ),
					'state'   => substr( (string) ( $billing['region'] ?? '' ), 0, 40 ),
					'zip'     => substr( (string) ( $billing['postal_code'] ?? '' ), 0, 20 ),
				)
			)
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
		$transaction_id = $order->transaction_id();
		$last_four      = (string) $order->get_meta( 'authnet_last_four', '' );

		if ( '' === $transaction_id ) {
			return new \WP_Error( 'wopcart_authnet_no_transaction', __( 'This order has no Authorize.net transaction to refund.', 'wopcart-direct' ) );
		}

		if ( '' === $last_four ) {
			return new \WP_Error(
				'wopcart_authnet_no_card',
				__( 'Authorize.net needs the last four digits of the card to refund, and they were not recorded for this order. Refund it from the Authorize.net dashboard instead.', 'wopcart-direct' )
			);
		}

		$amount   = $amount > 0 ? min( $amount, $order->refundable() ) : $order->refundable();
		$currency = $order->currency();

		if ( $amount <= 0 ) {
			return new \WP_Error( 'wopcart_nothing_to_refund', __( 'There is nothing left to refund on this order.', 'wopcart-direct' ) );
		}

		$response = $this->api(
			array(
				'refId'              => (string) $order->id(),
				'transactionRequest' => array(
					'transactionType' => 'refundTransaction',
					'amount'          => number_format( Money::to_major( $amount, $currency ), 2, '.', '' ),
					'currencyCode'    => $currency,
					'payment'         => array(
						'creditCard' => array(
							'cardNumber'     => $last_four,
							'expirationDate' => 'XXXX',
						),
					),
					'refTransId'      => $transaction_id,
				),
			),
			'createTransactionRequest'
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$this->record_refund( $order, (string) ( $response['transactionResponse']['transId'] ?? '' ), $amount );

		return true;
	}

	/**
	 * Count a refund issued through refund() on the order, once.
	 *
	 * Authorize.net sends net.authorize.payment.refund.created for this refund
	 * too, possibly before this runs. Both take the same claim, re-read the order
	 * and skip an id already seen, and the id is stored before the refunded
	 * figure moves, so neither side can count it a second time.
	 *
	 * @param Order  $order     Order refunded.
	 * @param string $refund_id Refund transaction id, '' when the response had none.
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
			$seen    = array_map( 'strval', (array) $current->get_meta( 'authnet_refund_ids', array() ) );

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
			$order->set_meta( 'authnet_refund_ids', array_values( array_unique( $seen ) ) );

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
			__( 'Authorize.net Automated Recurring Billing charges this subscription directly.', 'wopcart-direct' )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Subscription $subscription  Subscription.
	 * @param bool         $at_period_end Ignored; ARB cancels immediately.
	 *
	 * @return true|\WP_Error
	 */
	public function cancel_subscription( Subscription $subscription, bool $at_period_end = true ): true|\WP_Error {
		$reference = $subscription->gateway_subscription_id();

		if ( '' === $reference ) {
			return true;
		}

		$response = $this->api(
			array( 'subscriptionId' => $reference ),
			'ARBCancelSubscriptionRequest'
		);

		return is_wp_error( $response ) ? $response : true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function handle_webhook(): void {
		$payload   = $this->raw_body();
		$signature = $this->header( 'X-ANET-Signature' );
		$secret    = trim( (string) $this->setting( 'authnet_signature_key' ) );

		if ( '' === $secret ) {
			Logger::error( 'gateway.authorizenet', 'Webhook received but no signature key is configured.' );
			$this->respond( 400, 'no signature key' );
		}

		if ( ! $this->verify_signature( $payload, $signature, $secret ) ) {
			Logger::warning( 'gateway.authorizenet', 'Webhook signature verification failed.' );
			$this->respond( 400, 'invalid signature' );
		}

		$event = json_decode( $payload, true );

		if ( ! is_array( $event ) ) {
			$this->respond( 400, 'malformed payload' );
		}

		$type         = (string) ( $event['eventType'] ?? '' );
		$payload_data = is_array( $event['payload'] ?? null ) ? $event['payload'] : array();

		switch ( $type ) {
			case 'net.authorize.customer.subscription.cancelled':
			case 'net.authorize.customer.subscription.terminated':
			case 'net.authorize.customer.subscription.expiring':
				$subscription = SubscriptionRepository::find_by_gateway_id( $this->slug(), (string) ( $payload_data['id'] ?? '' ) );

				if ( null !== $subscription && 'net.authorize.customer.subscription.expiring' !== $type ) {
					SubscriptionManager::set_status( $subscription, Subscription::CANCELLED, 'gateway_webhook' );
				}
				break;

			case 'net.authorize.payment.authcapture.created':
				$this->handle_captured_payment( $payload_data );
				break;

			case 'net.authorize.payment.refund.created':
				$this->handle_refund_event( $payload_data );
				break;
		}

		/**
		 * Fires for every verified Authorize.net event.
		 *
		 * @param string              $type  Event type.
		 * @param array<string,mixed> $event Full event payload.
		 */
		do_action( 'wopcart_authorizenet_webhook', $type, $event );

		$this->respond();
	}

	/**
	 * Verify the X-ANET-Signature header.
	 *
	 * The header is formatted "sha512=HEXDIGEST" and the digest is an HMAC-SHA512
	 * of the raw body keyed with the signature key from the merchant interface.
	 *
	 * @param string $payload   Raw body.
	 * @param string $signature Header value.
	 * @param string $secret    Signature key.
	 *
	 * @return bool
	 */
	public function verify_signature( string $payload, string $signature, string $secret ): bool {
		if ( '' === $payload || '' === $signature ) {
			return false;
		}

		$parts = explode( '=', $signature, 2 );

		if ( 2 !== count( $parts ) ) {
			return false;
		}

		return hash_equals(
			strtoupper( hash_hmac( 'sha512', $payload, $secret ) ),
			strtoupper( trim( $parts[1] ) )
		);
	}

	/**
	 * Record an ARB renewal charge.
	 *
	 * @param array<string,mixed> $payload Event payload.
	 */
	private function handle_captured_payment( array $payload ): void {
		$reference = (string) ( $payload['merchantReferenceId'] ?? '' );

		if ( '' === $reference ) {
			return;
		}

		$subscription = SubscriptionRepository::find_by_gateway_id( $this->slug(), $reference );

		if ( null === $subscription ) {
			return;
		}

		$transaction = (string) ( $payload['id'] ?? '' );

		// ARB reports the charge it took when the subscription started as well as
		// every renewal. That first one paid for the original order and is already
		// recorded, so it is matched off against the subscription's marker rather
		// than written again. times_billed() cannot tell them apart: it already
		// counts the signup charge when there is no trial.
		if ( SubscriptionManager::claim_initial_charge( $subscription, $transaction ) ) {
			SubscriptionManager::sync_from_gateway( $subscription, $payload );

			return;
		}

		SubscriptionManager::record_renewal(
			$subscription,
			Money::to_minor( (string) ( $payload['authAmount'] ?? '0' ), $subscription->currency() ),
			$subscription->currency(),
			$transaction
		);
	}

	/**
	 * Record a refund issued from the Authorize.net dashboard.
	 *
	 * @param array<string,mixed> $payload Event payload.
	 */
	private function handle_refund_event( array $payload ): void {
		$refund_id = (string) ( $payload['id'] ?? '' );

		if ( '' === $refund_id ) {
			return;
		}

		// The payload's id is the refund transaction, not the charge the order was
		// recorded under. The charge is its refTransId, which the webhook does not
		// carry, so the refund is read back from the Transaction Details API.
		$original = (string) ( $payload['refTransId'] ?? '' );
		$amount   = (string) ( $payload['authAmount'] ?? '' );

		if ( '' === $original ) {
			$details = $this->api( array( 'transId' => $refund_id ), 'getTransactionDetailsRequest' );

			if ( is_wp_error( $details ) ) {
				$this->log(
					'Could not read a refund transaction; the refund was not recorded. Enable the Transaction Details API in Authorize.net.',
					array(
						'refund' => $refund_id,
						'error'  => $details->get_error_message(),
					),
					Logger::WARNING
				);

				return;
			}

			$original = (string) ( $details['transaction']['refTransId'] ?? '' );
			$amount   = '' !== $amount ? $amount : (string) ( $details['transaction']['settleAmount'] ?? $details['transaction']['authAmount'] ?? '0' );
		}

		$order = '' !== $original ? OrderRepository::find_by_transaction( $original, $this->slug() ) : null;

		if ( null === $order ) {
			return;
		}

		$claim = 'wopcart_refund_claim_' . md5( $this->slug() . '|' . $refund_id );

		if ( ! SubscriptionManager::claim( $claim, 60 ) ) {
			return;
		}

		try {
			$order = Order::find( $order->id() ) ?? $order;
			$seen  = array_map( 'strval', (array) $order->get_meta( 'authnet_refund_ids', array() ) );

			// Redelivered, or issued through refund() and already counted there.
			if ( in_array( $refund_id, $seen, true ) ) {
				return;
			}

			$total = min( $order->total(), $order->refunded() + max( 0, Money::to_minor( '' !== $amount ? $amount : '0', $order->currency() ) ) );

			$seen[] = $refund_id;

			// Id first, so a failure before the amount write cannot lead to a double count.
			$order->set_meta( 'authnet_refund_ids', $seen );
			$order->update( array( 'refunded' => $total ) );
			$order->set_status( $total >= $order->total() ? Order::REFUNDED : Order::PARTIALLY_REFUNDED );
		} finally {
			SubscriptionManager::release_claim( $claim );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function checkout_fields(): string {
		ob_start();
		?>
		<div class="wopcart-card-fields" data-wopcart-authnet
			data-login-id="<?php echo esc_attr( $this->login_id() ); ?>"
			data-client-key="<?php echo esc_attr( $this->public_client_key() ); ?>">
			<p class="wopcart-field">
				<label for="wopcart-anet-number"><?php esc_html_e( 'Card number', 'wopcart-direct' ); ?></label>
				<input type="text" id="wopcart-anet-number" inputmode="numeric" autocomplete="cc-number" data-anet="number">
			</p>
			<p class="wopcart-field wopcart-field--half">
				<label for="wopcart-anet-expiry"><?php esc_html_e( 'Expiry (MMYY)', 'wopcart-direct' ); ?></label>
				<input type="text" id="wopcart-anet-expiry" inputmode="numeric" autocomplete="cc-exp" data-anet="expiry">
			</p>
			<p class="wopcart-field wopcart-field--half">
				<label for="wopcart-anet-cvc"><?php esc_html_e( 'Security code', 'wopcart-direct' ); ?></label>
				<input type="text" id="wopcart-anet-cvc" inputmode="numeric" autocomplete="cc-csc" data-anet="cvc">
			</p>
			<p class="wopcart-card-fields__notice"><?php esc_html_e( 'Card details are sent directly to Authorize.net and never reach this site.', 'wopcart-direct' ); ?></p>
		</div>
		<?php

		return (string) ob_get_clean();
	}
}
