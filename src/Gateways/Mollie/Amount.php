<?php
/**
 * Mollie amount formatting.
 *
 * @package WopCartDirect
 */

declare(strict_types=1);

namespace WopCartDirect\Gateways\Mollie;

use WopCart\Support\Money;

/**
 * Converts between WopCart's integer minor units and Mollie's amount strings.
 *
 * Mollie takes amounts as `{currency, value}` with value a string holding "the
 * exact amount in the given currency" (docs.mollie.com/reference/common-data-types),
 * and publishes the number of decimals it expects per currency
 * (docs.mollie.com/docs/multicurrency). That table does not always agree with
 * ISO 4217 as Money::decimals() applies it: Mollie wants ISK with 0 decimals
 * while the store keeps ISK in hundredths. The conversion is therefore done on
 * integers only, and an amount that cannot be expressed exactly in Mollie's
 * precision is refused rather than rounded — charging a buyer a different figure
 * than the order says is never acceptable, even by one unit.
 *
 * Currencies Mollie does not list (every three-decimal currency, for instance)
 * are refused outright.
 */
final class Amount {

	/**
	 * Currencies Mollie accepts, with the decimals Mollie expects in `value`.
	 *
	 * Source: docs.mollie.com/docs/multicurrency (table "Currency / ISO code /
	 * Decimal places"). Kept as a constant rather than fetched, so checkout never
	 * waits on a network call to decide whether it can take a payment.
	 */
	public const SUPPORTED = array(
		'AED' => 2,
		'AUD' => 2,
		'BRL' => 2,
		'CAD' => 2,
		'CHF' => 2,
		'CZK' => 2,
		'DKK' => 2,
		'EUR' => 2,
		'GBP' => 2,
		'HKD' => 2,
		'HUF' => 2,
		'ILS' => 2,
		'ISK' => 0,
		'JPY' => 0,
		'MXN' => 2,
		'MYR' => 2,
		'NOK' => 2,
		'NZD' => 2,
		'PHP' => 2,
		'PLN' => 2,
		'RON' => 2,
		'RUB' => 2,
		'SEK' => 2,
		'SGD' => 2,
		'THB' => 2,
		'TWD' => 2,
		'USD' => 2,
		'ZAR' => 2,
	);

	/**
	 * Whether Mollie accepts a currency at all.
	 *
	 * @param string $currency ISO 4217 code.
	 *
	 * @return bool
	 */
	public static function supports( string $currency ): bool {
		return array_key_exists( strtoupper( $currency ), self::SUPPORTED );
	}

	/**
	 * Format a minor-unit amount as Mollie's value string.
	 *
	 * @param int    $minor    Amount in the store's minor units.
	 * @param string $currency ISO 4217 code.
	 *
	 * @return string|\WP_Error E.g. "19.99", or "1500" for JPY.
	 */
	public static function format( int $minor, string $currency ): string|\WP_Error {
		$currency = strtoupper( $currency );

		if ( ! self::supports( $currency ) ) {
			return new \WP_Error(
				'wopcart_mollie_currency',
				sprintf(
					/* translators: %s: currency code. */
					__( 'Mollie cannot take payments in %s.', 'wopcart-direct' ),
					$currency
				)
			);
		}

		if ( $minor < 0 ) {
			return new \WP_Error( 'wopcart_mollie_amount_negative', __( 'A payment amount cannot be negative.', 'wopcart-direct' ) );
		}

		$target = self::SUPPORTED[ $currency ];
		$units  = self::rescale( $minor, Money::decimals( $currency ), $target );

		if ( null === $units ) {
			return new \WP_Error(
				'wopcart_mollie_amount_precision',
				sprintf(
					/* translators: %s: currency code. */
					__( 'This amount cannot be charged exactly in %s through Mollie.', 'wopcart-direct' ),
					$currency
				)
			);
		}

		if ( 0 === $target ) {
			return (string) $units;
		}

		$digits = str_pad( (string) $units, $target + 1, '0', STR_PAD_LEFT );

		return substr( $digits, 0, -$target ) . '.' . substr( $digits, -$target );
	}

	/**
	 * Parse a Mollie amount object back into the store's minor units.
	 *
	 * Used on amounts read from the API (payment totals, amountRefunded), so it is
	 * strict: anything that is not a plain decimal string is an error, not a zero.
	 *
	 * @param mixed  $amount   Amount object as decoded from JSON, or a value string.
	 * @param string $currency Currency to read the value in, when $amount is a string.
	 *
	 * @return int|\WP_Error
	 */
	public static function to_minor( mixed $amount, string $currency = '' ): int|\WP_Error {
		if ( is_array( $amount ) ) {
			$currency = (string) ( $amount['currency'] ?? $currency );
			$amount   = $amount['value'] ?? '';
		}

		$currency = strtoupper( $currency );
		$value    = is_scalar( $amount ) ? (string) $amount : '';

		if ( ! self::supports( $currency ) || 1 !== preg_match( '/\A(\d+)(?:\.(\d+))?\z/', $value, $parts ) ) {
			return new \WP_Error( 'wopcart_mollie_amount_invalid', __( 'Mollie returned an amount that could not be read.', 'wopcart-direct' ) );
		}

		$target   = self::SUPPORTED[ $currency ];
		$fraction = $parts[2] ?? '';

		// Extra trailing digits are tolerated only when they are zeros; anything
		// else would mean silently dropping part of the amount.
		if ( strlen( $fraction ) > $target ) {
			if ( '' !== trim( substr( $fraction, $target ), '0' ) ) {
				return new \WP_Error( 'wopcart_mollie_amount_invalid', __( 'Mollie returned an amount that could not be read.', 'wopcart-direct' ) );
			}

			$fraction = substr( $fraction, 0, $target );
		}

		$units = (int) ( $parts[1] . str_pad( $fraction, $target, '0' ) );
		$minor = self::rescale( $units, $target, Money::decimals( $currency ) );

		if ( null === $minor ) {
			return new \WP_Error( 'wopcart_mollie_amount_invalid', __( 'Mollie returned an amount that could not be read.', 'wopcart-direct' ) );
		}

		return $minor;
	}

	/**
	 * Move an integer amount between two decimal precisions without rounding.
	 *
	 * @param int $amount Amount at the source precision.
	 * @param int $from   Source decimals.
	 * @param int $to     Target decimals.
	 *
	 * @return int|null Null when the amount is not exactly representable.
	 */
	private static function rescale( int $amount, int $from, int $to ): ?int {
		if ( $from === $to ) {
			return $amount;
		}

		if ( $from < $to ) {
			return $amount * ( 10 ** ( $to - $from ) );
		}

		$factor = 10 ** ( $from - $to );

		return 0 === $amount % $factor ? intdiv( $amount, $factor ) : null;
	}
}
