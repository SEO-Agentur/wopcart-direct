<?php
/**
 * Mollie request payload helpers.
 *
 * @package WopCartDirect
 */

declare(strict_types=1);

namespace WopCartDirect\Gateways\Mollie;

/**
 * Pure builders for the parts of a Mollie payment request that need rules.
 */
final class Payload {

	/**
	 * Methods a merchant may restrict checkout to.
	 *
	 * A subset of the `method` values in docs.mollie.com/reference/create-payment:
	 * the ones this gateway was written and documented for, plus the other
	 * recurring-capable ones from the mandate table.
	 */
	public const METHODS = array( 'ideal', 'bancontact', 'creditcard', 'klarna', 'paypal', 'eps', 'kbc', 'belfius', 'paybybank' );

	/**
	 * Locales Mollie's hosted pages support (docs.mollie.com/reference/create-payment, `locale`).
	 */
	private const LOCALES = array(
		'ca_ES',
		'cs_CZ',
		'da_DK',
		'de_AT',
		'de_CH',
		'de_DE',
		'de_LU',
		'en_BE',
		'en_GB',
		'en_NL',
		'en_US',
		'es_ES',
		'fi_FI',
		'fr_BE',
		'fr_FR',
		'fr_LU',
		'hu_HU',
		'is_IS',
		'it_IT',
		'lt_LT',
		'lv_LV',
		'nb_NO',
		'nl_BE',
		'nl_NL',
		'pl_PL',
		'pt_PT',
		'sk_SK',
		'sv_SE',
	);

	/**
	 * Mollie locale for a WordPress locale, or '' to let Mollie use the browser's.
	 *
	 * WordPress variants such as de_DE_formal are reduced to their base locale.
	 *
	 * @param string $wp_locale WordPress locale.
	 *
	 * @return string
	 */
	public static function locale( string $wp_locale ): string {
		$base = substr( $wp_locale, 0, 5 );

		return in_array( $base, self::LOCALES, true ) ? $base : '';
	}

	/**
	 * Keep only known method slugs.
	 *
	 * @param mixed $methods Stored setting.
	 *
	 * @return string[]
	 */
	public static function sanitize_methods( mixed $methods ): array {
		return array_values( array_intersect( self::METHODS, array_map( 'strval', (array) $methods ) ) );
	}

	/**
	 * Whether Mollie could deliver a webhook to a URL.
	 *
	 * "The webhookUrl must be reachable from Mollie's point of view, so you cannot
	 * use localhost" (docs.mollie.com/reference/create-payment). Sending one that
	 * is not reachable only produces failed deliveries, so on a development host
	 * it is left out and the return handler's own API fetch settles the order.
	 *
	 * @param string $url Webhook URL.
	 *
	 * @return bool
	 */
	public static function webhook_reachable( string $url ): bool {
		$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure helper, tested without WordPress.
		$host = trim( $host, '[]' );

		if ( '' === $host || 'localhost' === $host ) {
			return false;
		}

		foreach ( array( '.localhost', '.test', '.local', '.invalid', '.example' ) as $suffix ) {
			if ( str_ends_with( $host, $suffix ) ) {
				return false;
			}
		}

		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}

		return true;
	}

	/**
	 * Build payment `lines` from order items, or null when they would not add up.
	 *
	 * Lines are required for Klarna (docs.mollie.com/reference/create-payment,
	 * `lines`), and Mollie requires the line totals to sum to the payment amount
	 * and `vatAmount` to equal `totalAmount × vatRate / (100 + vatRate)`. The VAT
	 * is therefore recomputed with Mollie's formula rather than copied, since the
	 * store rounds tax on the net amount and the two can differ by a cent. When
	 * the totals cannot be reconciled exactly the lines are left out: the payment
	 * still works with every other method, whereas a mismatch would make Mollie
	 * reject the whole payment.
	 *
	 * Shipping is added as a `shipping_fee` line of its gross charge, so the lines
	 * of a shipped order still sum to the amount.
	 *
	 * @param array<int,array{name:string,quantity:int,total:int,tax_rate:float,type?:string}> $items    Order lines.
	 * @param int                                                                              $total    Order total, minor units.
	 * @param string                                                                           $currency Currency.
	 * @param array<string,mixed>                                                              $shipping Order shipping record, empty when nothing ships.
	 *
	 * @return array<int,array<string,mixed>>|null
	 */
	public static function lines( array $items, int $total, string $currency, array $shipping = array() ): ?array {
		$lines = array();
		$sum   = 0;

		if ( (int) ( $shipping['total'] ?? 0 ) > 0 ) {
			$label   = trim( (string) ( $shipping['label'] ?? '' ) );
			$items[] = array(
				/* translators: %s: shipping method name. */
				'name'     => '' !== $label ? sprintf( __( 'Shipping: %s', 'wopcart-direct' ), $label ) : __( 'Shipping', 'wopcart-direct' ),
				'quantity' => 1,
				'total'    => (int) $shipping['total'],
				'tax_rate' => (float) ( $shipping['tax_rate'] ?? 0 ),
				'type'     => 'shipping_fee',
			);
		}

		foreach ( $items as $item ) {
			$line_total = (int) $item['total'];
			$quantity   = max( 1, (int) $item['quantity'] );
			$rate       = max( 0.0, (float) $item['tax_rate'] );

			if ( $line_total < 0 ) {
				return null;
			}

			// A unit price that does not divide evenly is sent as one line of
			// quantity 1, so (unitPrice × quantity) still equals totalAmount.
			if ( 0 !== $line_total % $quantity ) {
				$quantity = 1;
			}

			$unit = Amount::format( intdiv( $line_total, $quantity ), $currency );
			$line = Amount::format( $line_total, $currency );
			$vat  = Amount::format( (int) round( $line_total * $rate / ( 100 + $rate ) ), $currency );

			if ( is_wp_error( $unit ) || is_wp_error( $line ) || is_wp_error( $vat ) ) {
				return null;
			}

			$currency_code = strtoupper( $currency );

			$lines[] = array(
				'type'        => in_array( $item['type'] ?? '', array( 'physical', 'digital', 'shipping_fee' ), true ) ? (string) $item['type'] : 'digital',
				'description' => substr( (string) $item['name'], 0, 200 ),
				'quantity'    => $quantity,
				'unitPrice'   => array(
					'currency' => $currency_code,
					'value'    => $unit,
				),
				'totalAmount' => array(
					'currency' => $currency_code,
					'value'    => $line,
				),
				'vatRate'     => number_format( $rate, 2, '.', '' ),
				'vatAmount'   => array(
					'currency' => $currency_code,
					'value'    => $vat,
				),
			);

			$sum += $line_total;
		}

		return array() !== $lines && $sum === $total ? $lines : null;
	}

	/**
	 * Mollie `shippingAddress` for an order's delivery address, empty when incomplete.
	 *
	 * Mollie requires streetAndNumber, city and country once an address is sent
	 * (and a postal code where the country has them), and names of at least two
	 * characters, so an address that cannot satisfy that is left out rather than
	 * making Mollie reject the payment.
	 *
	 * @param array<string,mixed> $address Delivery address (ShippingAddress::to_array()).
	 * @param string              $email   Buyer email.
	 *
	 * @return array<string,string>
	 */
	public static function shipping_address( array $address, string $email = '' ): array {
		$street  = trim( (string) ( $address['address_1'] ?? '' ) );
		$city    = trim( (string) ( $address['city'] ?? '' ) );
		$country = strtoupper( trim( (string) ( $address['country'] ?? '' ) ) );

		if ( '' === $street || '' === $city || 1 !== preg_match( '/\A[A-Z]{2}\z/', $country ) ) {
			return array();
		}

		$given  = trim( (string) ( $address['first_name'] ?? '' ) );
		$family = trim( (string) ( $address['last_name'] ?? '' ) );

		return array_filter(
			array(
				'organizationName' => trim( (string) ( $address['company'] ?? '' ) ),
				'givenName'        => mb_strlen( $given ) >= 2 ? $given : '',
				'familyName'       => mb_strlen( $family ) >= 2 ? $family : '',
				'email'            => $email,
				'streetAndNumber'  => $street,
				'streetAdditional' => trim( (string) ( $address['address_2'] ?? '' ) ),
				'postalCode'       => trim( (string) ( $address['postal_code'] ?? '' ) ),
				'city'             => $city,
				'region'           => trim( (string) ( $address['region'] ?? '' ) ),
				'country'          => $country,
			),
			static fn( string $value ): bool => '' !== $value
		);
	}
}
