<?php
/**
 * 2Checkout (Verifone) gateway.
 *
 * @package WopCartDirect
 */

declare(strict_types=1);

namespace WopCartDirect\Gateways;

use WopCart\Gateways\AbstractGateway;
use WopCart\Gateways\CartProfile;
use WopCart\Model\Order;
use WopCart\Model\Subscription;
use WopCart\Support\Logger;
use WopCart\Support\Money;

/**
 * Hosted checkout through 2Checkout's ConvertPlus flow.
 *
 * 2Checkout acts as merchant of record: it collects the payment, handles the
 * buyer's local tax and pays out on a schedule. That means the plugin never sees
 * a card, and it also means order completion is driven entirely by 2Checkout's
 * Instant Notification Service rather than by anything the buyer's browser does.
 * The return URL is treated as a convenience redirect only.
 */
final class TwoCheckout extends AbstractGateway {

	/**
	 * Features supported.
	 *
	 * @var string[]
	 */
	protected array $supports = array( 'subscriptions', 'webhooks' );

	/**
	 * Hosted checkout endpoint.
	 */
	private const CHECKOUT_URL = 'https://secure.2checkout.com/checkout/buy';

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function slug(): string {
		return '2checkout';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function label(): string {
		return __( '2Checkout', 'wopcart-direct' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	protected function has_credentials(): bool {
		return '' !== $this->merchant_code() && '' !== $this->secret_word();
	}

	/**
	 * Merchant code from the 2Checkout control panel.
	 *
	 * @return string
	 */
	private function merchant_code(): string {
		return trim( (string) $this->setting( 'twocheckout_merchant' ) );
	}

	/**
	 * Buy-link secret word, used to sign outbound checkout links.
	 *
	 * @return string
	 */
	private function secret_word(): string {
		return trim( (string) $this->setting( 'twocheckout_secret' ) );
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
		$unsupported = $this->refusal( CartProfile::from_order( $order ) );

		if ( null !== $unsupported ) {
			return new \WP_Error( $unsupported['code'], $unsupported['message'] );
		}

		// The hosted checkout is built from item prices only, so a shipped order
		// would be underpaid. Checkout never offers this gateway for such a cart;
		// this is the backstop for any other path that reaches process().
		if ( array() !== \WopCart\Shipping\ShippingService::for_order( $order ) ) {
			return new \WP_Error( 'wopcart_2co_no_shipping', __( '2Checkout cannot be used for orders that are shipped.', 'wopcart-direct' ) );
		}

		$currency = $order->currency();
		$billing  = $order->billing();

		$params = array(
			'merchant'          => $this->merchant_code(),
			'dynamic'           => '1',
			'currency'          => $currency,
			'return-url'        => $this->return_url( $order ),
			'return-type'       => 'redirect',
			'merchant-order-id' => (string) $order->id(),
			'order-ext-ref'     => (string) $order->id(),
			'customer-ext-ref'  => $order->email(),
			'email'             => $order->email(),
			'first-name'        => (string) ( $billing['first_name'] ?? '' ),
			'last-name'         => (string) ( $billing['last_name'] ?? '' ),
			'country'           => (string) ( $billing['country'] ?? '' ),
			'language'          => 'en',
			'tpl'               => 'default',
		);

		if ( $this->is_test() ) {
			$params['test'] = '1';
		}

		$index = 0;

		foreach ( $order->items() as $item ) {
			if ( $item->is_bundle_child() ) {
				continue;
			}

			$prefix = "prod-{$index}";

			$params[ $prefix ]          = substr( $item->name(), 0, 128 );
			$params[ "price-{$index}" ] = number_format( Money::to_major( $item->total(), $currency ), Money::decimals( $currency ), '.', '' );
			$params[ "qty-{$index}" ]   = '1';
			$params[ "type-{$index}" ]  = 'digital';

			if ( $item->is_recurring() ) {
				$product = $item->product();
				$option  = $product?->price_option( '' === $item->price_id() ? null : $item->price_id() );

				if ( null !== $option ) {
					$params[ "recurrence-{$index}" ] = max( 1, $option->frequency ) . ' ' . $option->period;
					$params[ "duration-{$index}" ]   = $option->bill_times > 0
						? (string) ( $option->bill_times * max( 1, $option->frequency ) ) . ' ' . $option->period
						: 'forever';
				}
			}

			++$index;
		}

		if ( 0 === $index ) {
			return new \WP_Error( 'wopcart_2co_no_items', __( 'There is nothing to send to 2Checkout.', 'wopcart-direct' ) );
		}

		$params['signature'] = $this->sign( $params, 'sha256', $this->secret_word() );

		$order->set_status( Order::PROCESSING );

		/**
		 * Filter the 2Checkout hosted checkout parameters.
		 *
		 * @param array<string,string> $params Query parameters, already signed.
		 * @param Order                $order  Order.
		 */
		$params = (array) apply_filters( 'wopcart_2checkout_params', $params, $order );

		return array( 'redirect' => add_query_arg( array_map( 'rawurlencode', $params ), self::CHECKOUT_URL ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param CartProfile $profile Cart or order profile.
	 *
	 * @return string|null
	 */
	public function cart_error( CartProfile $profile ): ?string {
		return $this->refusal( $profile )['message'] ?? null;
	}

	/**
	 * Why a cart or order can't be billed through 2Checkout, if it can't.
	 *
	 * A dynamic ConvertPlus line carries one price, a recurrence and a duration,
	 * with no trial and no separate renewal price: it charges the line price today
	 * and renews at that same price. So the line may only be billed when today's
	 * net charge is exactly the renewal amount. A trial line would renew at its
	 * sign-up fee (or nothing), a sign-up fee would be charged again on every
	 * renewal, and a first-payment-only discount or introductory sale price would
	 * carry on to every renewal; all of these are refused.
	 *
	 * @param CartProfile $profile Cart or order profile.
	 *
	 * @return array{code:string,message:string}|null
	 */
	public function refusal( CartProfile $profile ): ?array {
		foreach ( $profile->recurring as $line ) {
			if ( $line['trial_days'] > 0 ) {
				return array(
					'code'    => 'wopcart_2co_trial',
					'message' => __( "Subscriptions with a free trial can't be paid with 2Checkout. Please choose another payment method.", 'wopcart-direct' ),
				);
			}
		}

		foreach ( $profile->recurring as $line ) {
			if ( $line['signup_fee'] > 0 ) {
				return array(
					'code'    => 'wopcart_2co_signup_fee',
					'message' => __( "Subscriptions with a sign-up fee can't be paid with 2Checkout. Please choose another payment method.", 'wopcart-direct' ),
				);
			}
		}

		foreach ( $profile->recurring as $line ) {
			if ( $line['subtotal'] - $line['discount'] !== $line['recurring_subtotal'] ) {
				return array(
					'code'    => 'wopcart_2co_first_payment',
					'message' => __( "Subscriptions with a first-payment discount or introductory price can't be paid with 2Checkout. Please choose another payment method.", 'wopcart-direct' ),
				);
			}
		}

		return null;
	}

	/**
	 * Build a 2Checkout signature.
	 *
	 * 2Checkout signs a length-prefixed concatenation of the parameter values in
	 * alphabetical key order: each value contributes its byte length followed by
	 * the value itself. The prefixing is what stops two different parameter sets
	 * from colliding on the same signed string.
	 *
	 * @param array<string,string> $params    Parameters to sign, excluding the signature.
	 * @param string               $algorithm Hash algorithm.
	 * @param string               $secret    Secret word.
	 *
	 * @return string Hex digest.
	 */
	public function sign( array $params, string $algorithm, string $secret ): string {
		unset( $params['signature'], $params['HASH'], $params['hash'] );

		ksort( $params );

		$source = '';

		foreach ( $params as $value ) {
			$value   = (string) $value;
			$source .= strlen( $value ) . $value;
		}

		return hash_hmac( $algorithm, $source, $secret );
	}

	/**
	 * {@inheritDoc}
	 *
	 * The buyer's return carries no proof of payment, so it only redirects. The
	 * order is completed by the notification, which is signed.
	 *
	 * @param Order $order Order.
	 */
	public function handle_return( Order $order ): void {
		wp_safe_redirect( $order->receipt_url() );
		exit;
	}

	/**
	 * {@inheritDoc}
	 */
	public function handle_webhook(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Authenticity comes from the signed HASH below.
		$params = array_map(
			static fn( $value ): string => is_scalar( $value ) ? (string) $value : '',
			wp_unslash( $_POST )
		);
		// phpcs:enable

		if ( array() === $params ) {
			$this->respond( 400, 'empty notification' );
		}

		$received = strtoupper( (string) ( $params['HASH'] ?? '' ) );
		$secret   = trim( (string) $this->setting( 'twocheckout_api_key' ) );

		if ( '' === $secret ) {
			// The INS secret word is configured separately from the buy-link one;
			// fall back to the buy-link word when only that has been filled in.
			$secret = $this->secret_word();
		}

		if ( '' === $secret ) {
			Logger::error( 'gateway.2checkout', 'Notification received but no secret word is configured.' );
			$this->respond( 400, 'no secret word' );
		}

		$expected = strtoupper( $this->sign( $params, 'md5', $secret ) );

		if ( '' === $received || ! hash_equals( $expected, $received ) ) {
			Logger::warning( 'gateway.2checkout', 'Notification signature verification failed.' );
			$this->respond( 400, 'invalid signature' );
		}

		$message_type = strtoupper( (string) ( $params['MESSAGE_TYPE'] ?? '' ) );
		$order_id     = (int) ( $params['VENDOR_ORDER_ID'] ?? $params['MERCHANT_ORDER_ID'] ?? 0 );
		$order        = $order_id > 0 ? Order::find( $order_id ) : null;

		if ( null === $order ) {
			$this->respond( 200, 'unknown order' );
		}

		$reference = (string) ( $params['REFNO'] ?? $params['SALE_ID'] ?? '' );

		switch ( $message_type ) {
			case 'ORDER_CREATED':
			case 'FRAUD_STATUS_CHANGED':
				if ( 'APPROVED' === strtoupper( (string) ( $params['FRAUD_STATUS'] ?? 'APPROVED' ) ) ) {
					$this->complete_if_paid( $order, $reference, $params );
				}
				break;

			case 'INVOICE_STATUS_CHANGED':
				$status = strtoupper( (string) ( $params['INVOICE_STATUS'] ?? '' ) );

				if ( 'APPROVED' === $status ) {
					$this->complete_if_paid( $order, $reference, $params );
				} elseif ( 'DECLINED' === $status ) {
					$order->set_status( Order::FAILED );
					$order->set_meta( 'failure_reason', 'declined' );
				} elseif ( 'REVERSED' === $status ) {
					$this->reverse( $order, $reference );
				}
				break;

			case 'REFUND_ISSUED':
				$this->record_refund( $order, $params );
				break;

			case 'RECURRING_INSTALLMENT_SUCCESS':
				$this->handle_recurring_success( $order, $params );
				break;

			case 'RECURRING_INSTALLMENT_FAILED':
			case 'RECURRING_STOPPED':
				$subscription = \WopCart\Repository\SubscriptionRepository::find_by_order( $order->id() );

				if ( null !== $subscription ) {
					if ( 'RECURRING_STOPPED' === $message_type ) {
						\WopCart\Subscriptions\SubscriptionManager::set_status( $subscription, Subscription::CANCELLED, 'gateway_webhook' );
					} else {
						\WopCart\Subscriptions\SubscriptionManager::record_failure(
							$subscription,
							__( '2Checkout reported a failed renewal payment.', 'wopcart-direct' )
						);
					}
				}
				break;
		}

		/**
		 * Fires for every verified 2Checkout notification.
		 *
		 * @param string               $message_type Notification type.
		 * @param array<string,string> $params       Notification parameters.
		 */
		do_action( 'wopcart_2checkout_webhook', $message_type, $params );

		// 2Checkout expects the notification echoed back with a date-stamped hash
		// to confirm receipt; anything else is retried for days.
		$this->acknowledge( $params, $secret );
	}

	/**
	 * Complete an order only when the notification shows it was paid in full.
	 *
	 * @param Order                $order     Order.
	 * @param string               $reference 2Checkout reference.
	 * @param array<string,string> $params    Verified notification parameters.
	 */
	private function complete_if_paid( Order $order, string $reference, array $params ): void {
		if ( $order->is_complete() ) {
			return;
		}

		if ( ! self::paid_in_full( $params, $order->total(), $order->currency() ) ) {
			$this->log(
				'Notification total is below the order total; the order was not completed.',
				array(
					'object_id' => $order->id(),
					'expected'  => $order->total() . ' ' . $order->currency(),
					'received'  => (string) ( $params['IPN_TOTALGENERAL'] ?? '' ) . ' ' . (string) ( $params['IPN_CURRENCY'] ?? '' ),
					'reference' => $reference,
				),
				Logger::ERROR
			);

			$order->set_meta( 'failure_reason', 'underpaid' );

			return;
		}

		$this->complete( $order, $reference );
	}

	/**
	 * Handle an invoice 2Checkout has reversed.
	 *
	 * A reversal is money taken back after the fact — a chargeback, or an order
	 * 2Checkout unwound itself. Failing the order was wrong twice over: an order
	 * that was paid is not "failed", and failing it restocks nothing and leaves
	 * the buyer's downloads open. A reversal on a paid order is a revocation, which
	 * returns the stock and withdraws access; before the order is paid there is
	 * nothing to take back, so it is simply failed.
	 *
	 * @param Order  $order     Order.
	 * @param string $reference 2Checkout reference.
	 */
	private function reverse( Order $order, string $reference ): void {
		$paid = in_array( $order->status(), array( Order::COMPLETE, Order::PARTIALLY_REFUNDED ), true );

		$order->set_meta( 'twocheckout_reversed', $reference );
		$order->set_status( $paid ? Order::REVOKED : Order::FAILED );

		$this->log(
			'2Checkout reversed this order.',
			array(
				'object_id' => $order->id(),
				'reference' => $reference,
				'was_paid'  => $paid,
			),
			Logger::WARNING
		);
	}

	/**
	 * Pure: whether a notification's total covers the order total.
	 *
	 * 2Checkout is merchant of record and may add its own tax on top, so a
	 * higher total is fine. A lower one, a different currency, or no total at
	 * all is not proof of payment for this order.
	 *
	 * @param array<string,string> $params         Notification parameters.
	 * @param int                  $order_total    Order total, minor units.
	 * @param string               $order_currency Order currency.
	 *
	 * @return bool
	 */
	public static function paid_in_full( array $params, int $order_total, string $order_currency ): bool {
		$raw      = trim( (string) ( $params['IPN_TOTALGENERAL'] ?? '' ) );
		$currency = strtoupper( trim( (string) ( $params['IPN_CURRENCY'] ?? '' ) ) );

		if ( '' === $raw || ! is_numeric( $raw ) ) {
			return false;
		}

		if ( '' !== $currency && strtoupper( $order_currency ) !== $currency ) {
			return false;
		}

		return Money::to_minor( $raw, strtoupper( $order_currency ) ) >= $order_total;
	}

	/**
	 * Record a refund notification once.
	 *
	 * 2Checkout resends a notification until it is acknowledged, so the same
	 * refund can arrive several times. Each one is identified by refund_key() and
	 * kept on the order; the total is capped at what was paid.
	 *
	 * @param Order                $order  Order.
	 * @param array<string,string> $params Verified notification parameters.
	 */
	private function record_refund( Order $order, array $params ): void {
		$key   = self::refund_key( $params );
		$claim = 'wopcart_refund_claim_' . md5( $this->slug() . '|' . $key );

		if ( ! \WopCart\Subscriptions\SubscriptionManager::claim( $claim, 60 ) ) {
			return;
		}

		try {
			$order = Order::find( $order->id() ) ?? $order;
			$seen  = array_map( 'strval', (array) $order->get_meta( 'twocheckout_refund_ids', array() ) );

			if ( in_array( $key, $seen, true ) ) {
				return;
			}

			$currency = strtoupper( (string) ( $params['IPN_CURRENCY'] ?? $order->currency() ) );
			$amount   = Money::to_minor( (string) ( $params['IPN_TOTALGENERAL'] ?? '0' ), $currency );
			$total    = min( $order->total(), $order->refunded() + abs( $amount ) );

			$seen[] = $key;

			// Id first, so a failure before the amount write cannot lead to a double count.
			$order->set_meta( 'twocheckout_refund_ids', $seen );
			$order->update( array( 'refunded' => $total ) );
			$order->set_status( $total >= $order->total() ? Order::REFUNDED : Order::PARTIALLY_REFUNDED );
		} finally {
			\WopCart\Subscriptions\SubscriptionManager::release_claim( $claim );
		}
	}

	/**
	 * Pure: what identifies one refund notification across redeliveries.
	 *
	 * A resend repeats the notification unchanged, date included, while a second
	 * partial refund on the same order is generated at a different IPN_DATE.
	 *
	 * @param array<string,string> $params Notification parameters.
	 *
	 * @return string
	 */
	public static function refund_key( array $params ): string {
		return md5(
			implode(
				'|',
				array(
					(string) ( $params['REFNO'] ?? '' ),
					(string) ( $params['IPN_DATE'] ?? '' ),
					(string) ( $params['IPN_TOTALGENERAL'] ?? '' ),
					strtoupper( (string) ( $params['IPN_CURRENCY'] ?? '' ) ),
				)
			)
		);
	}

	/**
	 * Record a successful recurring installment.
	 *
	 * @param Order                $order  Original order.
	 * @param array<string,string> $params Notification parameters.
	 */
	private function handle_recurring_success( Order $order, array $params ): void {
		$subscription = \WopCart\Repository\SubscriptionRepository::find_by_order( $order->id() );

		if ( null === $subscription ) {
			return;
		}

		$currency = strtoupper( (string) ( $params['IPN_CURRENCY'] ?? $subscription->currency() ) );

		\WopCart\Subscriptions\SubscriptionManager::record_renewal(
			$subscription,
			Money::to_minor( (string) ( $params['IPN_TOTALGENERAL'] ?? '0' ), $currency ),
			$currency,
			(string) ( $params['REFNOEXT'] ?? $params['REFNO'] ?? '' )
		);
	}

	/**
	 * Send the acknowledgement 2Checkout requires.
	 *
	 * @param array<string,string> $params Notification parameters.
	 * @param string               $secret Secret word.
	 */
	private function acknowledge( array $params, string $secret ): void {
		$date = gmdate( 'YmdHis' );

		$response_hash = strtoupper(
			$this->sign(
				array(
					'a' => (string) ( $params['IPN_PID'][0] ?? $params['IPN_PID'] ?? '' ),
					'b' => (string) ( $params['IPN_PNAME'][0] ?? $params['IPN_PNAME'] ?? '' ),
					'c' => (string) ( $params['IPN_DATE'] ?? '' ),
					'd' => $date,
				),
				'md5',
				$secret
			)
		);

		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );

		echo '<EPAYMENT>' . esc_html( $date . '|' . $response_hash ) . '</EPAYMENT>';
		exit;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Refunds are issued from the 2Checkout control panel because the merchant of
	 * record holds the funds; the store is notified of the result.
	 *
	 * @param Order $order  Order.
	 * @param int   $amount Amount.
	 *
	 * @return true|\WP_Error
	 */
	public function refund( Order $order, int $amount = 0 ): true|\WP_Error {
		return new \WP_Error(
			'wopcart_2co_manual_refund',
			__( 'Refund this order from the 2Checkout control panel. The refund will appear here automatically once 2Checkout confirms it.', 'wopcart-direct' )
		);
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
			__( '2Checkout bills this subscription directly.', 'wopcart-direct' )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * 2Checkout is the merchant of record and holds the recurring agreement. Its
	 * ordering API needs a merchant API key, which is a different credential from
	 * the buy-link and INS secret words this gateway is configured with, so there
	 * is nothing here that can stop the billing.
	 *
	 * Reporting success would be worse than refusing: the store would mark the
	 * subscription cancelled while 2Checkout kept charging the card, and the next
	 * RECURRING_INSTALLMENT_SUCCESS notification would put it back to active. The
	 * refusal carries the manual-cancellation code, which SubscriptionManager
	 * turns into "ends when the paid period does" locally.
	 *
	 * @param Subscription $subscription  Subscription.
	 * @param bool         $at_period_end Whether to defer to the end of the period.
	 *
	 * @return true|\WP_Error
	 */
	public function cancel_subscription( Subscription $subscription, bool $at_period_end = true ): true|\WP_Error {
		$this->log(
			'A 2Checkout subscription must be stopped in the 2Checkout control panel.',
			array(
				'object_id' => $subscription->id(),
				'reference' => $subscription->gateway_subscription_id(),
			),
			Logger::WARNING
		);

		return new \WP_Error(
			self::MANUAL_CANCEL,
			__( '2Checkout bills this subscription itself, so it has to be stopped in the 2Checkout control panel. It will keep running here until the period already paid for ends.', 'wopcart-direct' )
		);
	}
}
