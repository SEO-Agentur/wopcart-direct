<?php
/**
 * The option insert a refund claim needs, and the post-meta read an order does.
 *
 * Core's suite spreads this across `RenewalClaimStubs.php` and
 * `LicenseTermStubs.php`, which the refund tests pulled in for one function
 * each. Combined into one file here, because the halves of those two that this
 * suite actually needs are smaller than either: this harness's shared bootstrap
 * already stores and returns options for real, so only `add_option()` is
 * missing — and it is missing precisely the behaviour the claim depends on.
 *
 * @package WopCartDirect
 */

declare(strict_types=1);

defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core constant.

if ( ! function_exists( 'add_option' ) ) {
	/**
	 * Insert an option unless it exists, which is what makes a claim a claim.
	 *
	 * `SubscriptionManager::claim()` takes a refund claim by inserting an option
	 * and treating the insert failing as "somebody else holds it". That one return
	 * value is the whole lock: a stub that overwrote like `update_option()` would
	 * hand the claim to every caller at once, and the refund tests would pass while
	 * a webhook and a refund both counted the same money.
	 *
	 * Written in the shape the shared bootstrap's `get_option()` reads, and never
	 * autoloaded, which is how `claim()` asks for it.
	 *
	 * @param string $name     Name.
	 * @param mixed  $value    Value.
	 * @param string $unused   Deprecated argument, ignored.
	 * @param mixed  $autoload Autoload, as WordPress takes it: 'no' or false mean no.
	 *
	 * @return bool False when the option already exists.
	 */
	function add_option( string $name, mixed $value = '', string $unused = '', mixed $autoload = null ): bool { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( array_key_exists( $name, $GLOBALS['wopcart_direct_test_options'] ) ) {
			return false;
		}

		$GLOBALS['wopcart_direct_test_options'][ $name ] = array(
			'value'    => $value,
			'autoload' => ! in_array( $autoload, array( 'no', false ), true ),
		);

		return true;
	}
}

if ( ! function_exists( 'current_time' ) ) {
	/**
	 * Current time stub, UTC only.
	 *
	 * Read by core's order repository when it stamps `date_modified` on a write,
	 * which the refund tests look at to tell one UPDATE from another.
	 *
	 * @param string $type Format type.
	 * @param bool   $gmt  Ignored; always UTC.
	 *
	 * @return string|int
	 */
	function current_time( string $type, bool $gmt = false ): string|int { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return 'mysql' === $type ? gmdate( 'Y-m-d H:i:s' ) : time();
	}
}
