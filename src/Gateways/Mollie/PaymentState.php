<?php
/**
 * Mollie payment state rules.
 *
 * @package WopCartDirect
 */

declare(strict_types=1);

namespace WopCartDirect\Gateways\Mollie;

use WopCart\Model\Order;

/**
 * The decisions the Mollie gateway makes about a payment it has fetched.
 *
 * Kept free of WordPress and database calls so every rule that moves money or
 * access can be tested on its own. The gateway fetches, this class decides, the
 * gateway writes.
 */
final class PaymentState {

	/**
	 * Renewal payment should be recorded as a new renewal order.
	 */
	public const RENEWAL_RECORD = 'record';

	/**
	 * Renewal payment was already recorded; a redelivered webhook.
	 */
	public const RENEWAL_DUPLICATE = 'duplicate';

	/**
	 * Renewal payment failed and counts against the subscription.
	 */
	public const RENEWAL_FAILURE = 'failure';

	/**
	 * Nothing to do yet (open, pending, authorized).
	 */
	public const RENEWAL_IGNORE = 'ignore';

	/**
	 * A fetch failed because this key cannot see the payment: try the other mode.
	 */
	public const FETCH_UNKNOWN = 'unknown';

	/**
	 * A fetch failed because Mollie refused the key itself.
	 */
	public const FETCH_AUTH = 'auth';

	/**
	 * A fetch failed for a reason that may pass: Mollie must retry the webhook.
	 */
	public const FETCH_TRANSIENT = 'transient';

	/**
	 * Most failed renewal payment ids remembered per subscription.
	 */
	public const MAX_REMEMBERED_FAILURES = 50;

	/**
	 * Classify the HTTP status of a failed payment fetch.
	 *
	 * Only 404 means "this key does not know the payment". 401 and 403 mean the
	 * key was revoked or lacks access (docs.mollie.com/reference/authentication);
	 * treating them like 404 used to answer the webhook with 200, which makes
	 * Mollie stop retrying, so paid orders never completed and nobody was told.
	 *
	 * @param int $status HTTP status, 0 when no answer arrived.
	 *
	 * @return string One of the FETCH_* constants.
	 */
	public static function fetch_error_kind( int $status ): string {
		return match ( $status ) {
			404      => self::FETCH_UNKNOWN,
			401, 403 => self::FETCH_AUTH,
			default  => self::FETCH_TRANSIENT,
		};
	}

	/**
	 * Add a failed renewal payment id to the remembered list, once.
	 *
	 * Replaces a never-released claim option per failed payment, which left one
	 * options row behind for every failure forever. The list is bounded because
	 * Mollie redelivers a payment's webhook for about a day, so only recent ids
	 * can ever come back; the oldest are dropped first.
	 *
	 * @param string[] $remembered Previously counted payment ids, oldest first.
	 * @param string   $id         Payment id that just failed.
	 * @param int      $max        Most ids kept.
	 *
	 * @return string[]|null New list, or null when the failure was already counted.
	 */
	public static function remember_failure( array $remembered, string $id, int $max = self::MAX_REMEMBERED_FAILURES ): ?array {
		$remembered = array_values( array_map( 'strval', $remembered ) );

		if ( in_array( $id, $remembered, true ) ) {
			return null;
		}

		$remembered[] = $id;

		return array_slice( $remembered, -max( 1, $max ) );
	}

	/**
	 * Whether a webhook `id` has the shape of a Mollie payment id.
	 *
	 * The Payments API webhook posts only `id=tr_…` (docs.mollie.com/reference/webhooks)
	 * and ids match `^tr_.+$` (docs.mollie.com/reference/get-payment). Real ids are
	 * short and alphanumeric, so anything else is refused before an API call is
	 * spent on it: the endpoint is unauthenticated, and a malformed id would
	 * otherwise let anyone make this store burn its Mollie rate limit.
	 *
	 * @param string $id Posted id.
	 *
	 * @return bool
	 */
	public static function valid_payment_id( string $id ): bool {
		return 1 === preg_match( '/\Atr_[A-Za-z0-9]{2,64}\z/', $id );
	}

	/**
	 * The order status a Mollie payment status points to.
	 *
	 * Statuses per docs.mollie.com/docs/handling-payment-status:
	 * - paid: completed → the order is complete.
	 * - authorized: funds held. Payments are created with the default automatic
	 *   capture (docs.mollie.com/reference/create-capture), so for Klarna this is
	 *   a short-lived step before paid; nothing is delivered until then.
	 * - pending: still being processed → processing.
	 * - failed, canceled, expired: final and unpaid → failed.
	 * - open: created but not completed. It maps to nothing, because Mollie
	 *   notes a payment stays open even after a cancelled attempt, so a buyer
	 *   returning with an open payment has not necessarily given up.
	 *
	 * @param string $status Mollie payment status.
	 *
	 * @return string Order status constant, '' for no change.
	 */
	public static function order_status( string $status ): string {
		return match ( $status ) {
			'paid'                           => Order::COMPLETE,
			'authorized', 'pending'          => Order::PROCESSING,
			'failed', 'canceled', 'expired'  => Order::FAILED,
			default                          => '',
		};
	}

	/**
	 * The status an order should move to, given its current one.
	 *
	 * Webhooks arrive late, repeatedly and out of order, so each move is only
	 * allowed forwards:
	 * - complete from pending, processing or failed. A buyer can abandon one
	 *   attempt (failing the order through the cancel URL) and still pay the same
	 *   payment afterwards; the money arrived, so the order must complete.
	 * - processing only from pending.
	 * - failed only from pending or processing: an expired retry must never undo
	 *   a completed or refunded order.
	 *
	 * @param string $mollie_status  Mollie payment status.
	 * @param string $current_status Order's current status.
	 *
	 * @return string Target status, '' when nothing should change.
	 */
	public static function order_transition( string $mollie_status, string $current_status ): string {
		$target = self::order_status( $mollie_status );

		$allowed = match ( $target ) {
			Order::COMPLETE   => array( Order::PENDING, Order::PROCESSING, Order::FAILED ),
			Order::PROCESSING => array( Order::PENDING ),
			Order::FAILED     => array( Order::PENDING, Order::PROCESSING ),
			default           => array(),
		};

		return in_array( $current_status, $allowed, true ) ? $target : '';
	}

	/**
	 * What to do with a payment Mollie created from a subscription.
	 *
	 * Subscription payments carry `subscriptionId` (docs.mollie.com/reference/get-payment)
	 * and every status change of each one reaches the subscription's webhook,
	 * failures included (docs.mollie.com/reference/create-subscription).
	 *
	 * @param string $status           Mollie payment status.
	 * @param bool   $already_recorded Whether a renewal order already carries this payment id.
	 *
	 * @return string One of the RENEWAL_* constants.
	 */
	public static function renewal_action( string $status, bool $already_recorded ): string {
		if ( 'paid' === $status ) {
			return $already_recorded ? self::RENEWAL_DUPLICATE : self::RENEWAL_RECORD;
		}

		if ( in_array( $status, array( 'failed', 'canceled', 'expired' ), true ) ) {
			// A payment that was recorded as paid and later reported failed does
			// not happen at Mollie; a paid SEPA debit that bounces arrives as a
			// chargeback instead. Guarding here keeps a confused redelivery from
			// counting a failure against a renewal the customer paid.
			return $already_recorded ? self::RENEWAL_IGNORE : self::RENEWAL_FAILURE;
		}

		return self::RENEWAL_IGNORE;
	}

	/**
	 * Resolve and check a requested refund amount.
	 *
	 * Refused rather than clamped when too large: a merchant who types 50 and
	 * sees 30 refunded without being told has been misled. Mollie's own
	 * `amountRemaining` is consulted when known, because it also reflects refunds
	 * made in the Mollie dashboard that the store has not heard about yet.
	 *
	 * @param int      $requested        Minor units; 0 for the full remaining balance.
	 * @param int      $local_refundable What the store believes can still be refunded.
	 * @param int|null $remote_remaining Mollie's amountRemaining in minor units, null when unknown.
	 *
	 * @return int|\WP_Error Amount to refund.
	 */
	public static function refund_amount( int $requested, int $local_refundable, ?int $remote_remaining = null ): int|\WP_Error {
		if ( $requested < 0 ) {
			return new \WP_Error( 'wopcart_mollie_refund_invalid', __( 'A refund amount cannot be negative.', 'wopcart-direct' ) );
		}

		$cap = null === $remote_remaining ? $local_refundable : min( $local_refundable, $remote_remaining );

		if ( $cap <= 0 ) {
			return new \WP_Error( 'wopcart_nothing_to_refund', __( 'There is nothing left to refund on this order.', 'wopcart-direct' ) );
		}

		if ( 0 === $requested ) {
			return $cap;
		}

		if ( $requested > $cap ) {
			return new \WP_Error( 'wopcart_mollie_refund_exceeds', __( 'The refund is larger than the amount that can still be refunded on this payment.', 'wopcart-direct' ) );
		}

		return $requested;
	}

	/**
	 * The refunded total to store after a refund issued from the store succeeded.
	 *
	 * The webhook for the same refund can land between the API call and this
	 * write and already have stored Mollie's figure. Taking the larger of "before
	 * plus this refund" and the freshly read value records the refund once
	 * whichever order the two writes happen in.
	 *
	 * @param int $before      Refunded total read before the refund.
	 * @param int $amount      Amount just refunded.
	 * @param int $fresh_local Refunded total re-read after the API call.
	 *
	 * @return int
	 */
	public static function refunded_after( int $before, int $amount, int $fresh_local ): int {
		return max( $before + $amount, $fresh_local );
	}

	/**
	 * The order status matching a refunded total.
	 *
	 * @param int $refunded Refunded total, minor units.
	 * @param int $total    Order total, minor units.
	 *
	 * @return string Status, '' when nothing is refunded.
	 */
	public static function refund_status( int $refunded, int $total ): string {
		if ( $refunded <= 0 ) {
			return '';
		}

		return $refunded >= $total ? Order::REFUNDED : Order::PARTIALLY_REFUNDED;
	}
}
