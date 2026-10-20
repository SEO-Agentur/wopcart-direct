<?php
/**
 * Mollie recurring billing rules.
 *
 * @package WopCartDirect
 */

declare(strict_types=1);

namespace WopCartDirect\Gateways\Mollie;

/**
 * Translates WopCart subscriptions into Mollie's recurring model.
 *
 * Mollie recurring works in two steps (docs.mollie.com/docs/recurring-payments):
 * a `first` payment made by the customer establishes a mandate, after which a
 * Subscriptions API schedule charges that mandate. WopCart uses the Subscriptions
 * API rather than charging on demand from its own renewal cron — the gateway
 * docblock explains why. This class holds the pure translation rules.
 */
final class Recurring {

	/**
	 * Methods that can make a `first` payment and leave a recurring mandate.
	 *
	 * From the mandate table in docs.mollie.com/docs/recurring-payments. `bacs` is
	 * left out: it needs a 0.00 GBP first payment, which does not fit an order
	 * that charges its first period up front. Klarna is left out because it does
	 * not support recurring (docs.mollie.com/docs/klarna, "Recurring: No").
	 */
	public const FIRST_PAYMENT_METHODS = array( 'creditcard', 'paypal', 'bancontact', 'ideal', 'eps', 'kbc', 'belfius', 'paybybank' );

	/**
	 * Methods that accept a zero-amount first payment.
	 *
	 * "For credit card and PayPal payments, you can create a payment with a zero
	 * amount" (docs.mollie.com/docs/recurring-payments). A free trial with no
	 * signup fee has a zero order total, so only these can start it.
	 */
	public const ZERO_AMOUNT_METHODS = array( 'creditcard', 'paypal' );

	/**
	 * Mollie interval string for a WopCart billing period.
	 *
	 * Mollie accepts `^\d+ (days?|weeks?|months?)$` with a maximum of one year
	 * (`12 months`, `52 weeks` or `365 days`) (docs.mollie.com/reference/create-subscription).
	 * A yearly plan is sent as 12 months, which also keeps Mollie on the same
	 * calendar-month arithmetic as Price::next_charge_from(). Anything longer
	 * than a year is refused at checkout, before the buyer pays, rather than
	 * failing when the subscription is created after they already have.
	 *
	 * @param string $period    day|week|month|year.
	 * @param int    $frequency Periods between charges.
	 *
	 * @return string|\WP_Error
	 */
	public static function interval( string $period, int $frequency ): string|\WP_Error {
		$frequency = max( 1, $frequency );

		[ $unit, $count, $max ] = match ( $period ) {
			'day'   => array( 'day', $frequency, 365 ),
			'week'  => array( 'week', $frequency, 52 ),
			'month' => array( 'month', $frequency, 12 ),
			'year'  => array( 'month', $frequency * 12, 12 ),
			default => array( '', 0, 0 ),
		};

		if ( '' === $unit || $count > $max ) {
			return new \WP_Error(
				'wopcart_mollie_interval',
				__( 'Mollie can only bill subscriptions at most once a year apart.', 'wopcart-direct' )
			);
		}

		return $count . ' ' . $unit . ( 1 === $count ? '' : 's' );
	}

	/**
	 * Number of Mollie subscription payments still to take.
	 *
	 * The first payment is a separate `first` payment that is not part of the
	 * Mollie subscription, and SubscriptionManager already counts it in
	 * times_billed (0 during a trial), so the difference is what Mollie must take.
	 *
	 * @param int $bill_times   Total charges for the term; 0 for until cancelled.
	 * @param int $times_billed Charges already taken.
	 *
	 * @return int|null Null for unlimited, 0 when no Mollie subscription is needed.
	 */
	public static function times( int $bill_times, int $times_billed ): ?int {
		if ( $bill_times <= 0 ) {
			return null;
		}

		return max( 0, $bill_times - max( 0, $times_billed ) );
	}

	/**
	 * Mollie startDate for the subscription.
	 *
	 * The subscription starts at the store's next payment date. It is never
	 * allowed to be today or earlier: a subscription starting today charges today
	 * (docs.mollie.com/reference/create-subscription), on top of the first payment
	 * that was just taken.
	 *
	 * @param int $next_payment Store's next payment timestamp.
	 * @param int $now          Current timestamp.
	 *
	 * @return string YYYY-MM-DD.
	 */
	public static function start_date( int $next_payment, int $now ): string {
		return gmdate( 'Y-m-d', max( $next_payment, $now + DAY_IN_SECONDS ) );
	}

	/**
	 * The sequenceType a checkout needs, from its number of recurring lines.
	 *
	 * One Mollie subscription is attached per order
	 * (SubscriptionManager::attach_gateway_reference() writes the same reference
	 * to every subscription of an order), so a cart with two recurring lines
	 * would bill only one of them. It is refused instead.
	 *
	 * @param int $recurring_lines Number of recurring order lines.
	 *
	 * @return string|\WP_Error `oneoff` or `first`.
	 */
	public static function sequence_type( int $recurring_lines ): string|\WP_Error {
		if ( $recurring_lines <= 0 ) {
			return 'oneoff';
		}

		if ( 1 === $recurring_lines ) {
			return 'first';
		}

		return new \WP_Error(
			'wopcart_mollie_multiple_subscriptions',
			__( 'Please buy one subscription at a time with this payment method.', 'wopcart-direct' )
		);
	}

	/**
	 * Methods to offer for a first payment.
	 *
	 * @param int      $total      Order total in minor units.
	 * @param string[] $configured Methods the merchant restricted checkout to; empty for all.
	 *
	 * @return string[]|\WP_Error
	 */
	public static function first_payment_methods( int $total, array $configured ): array|\WP_Error {
		$allowed = $total > 0 ? self::FIRST_PAYMENT_METHODS : self::ZERO_AMOUNT_METHODS;

		if ( array() !== $configured ) {
			$allowed = array_values( array_intersect( $allowed, $configured ) );
		}

		if ( array() === $allowed ) {
			return new \WP_Error(
				'wopcart_mollie_no_recurring_method',
				__( 'None of the payment methods offered through Mollie can set up this subscription.', 'wopcart-direct' )
			);
		}

		return $allowed;
	}

	/**
	 * The `method` parameter for a list of methods.
	 *
	 * One method sends the buyer straight to it; several are passed as an array,
	 * which shows the hosted selection screen limited to those; none leaves the
	 * choice entirely to Mollie's hosted checkout (docs.mollie.com/reference/create-payment,
	 * `method`).
	 *
	 * @param string[] $methods Methods.
	 *
	 * @return string|string[]|null
	 */
	public static function method_param( array $methods ): string|array|null {
		$methods = array_values( array_unique( array_filter( array_map( 'strval', $methods ) ) ) );

		if ( array() === $methods ) {
			return null;
		}

		return 1 === count( $methods ) ? $methods[0] : $methods;
	}
}
