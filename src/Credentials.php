<?php
/**
 * The merchant's own gateway credentials.
 *
 * @package WopCartDirect
 */

declare(strict_types=1);

namespace WopCartDirect;

use WopCart\Support\Options;

/**
 * Answers core's entitlement and Stripe credential seams, and declares which of
 * this plugin's settings are secrets.
 *
 * This class is the whole product, reduced to three questions core asks:
 *
 * - *May this store charge with keys of its own?* Yes, always, while this plugin
 *   is installed. That is what was bought.
 * - *What is the Stripe key for this mode?* Whatever the merchant saved.
 * - *Which settings belong in the non-autoloaded option?* The credentials.
 *
 * Nothing here consults the licence. See `uses_own_credentials()` for why.
 */
final class Credentials {

	/**
	 * Setting keys this plugin stores in core's non-autoloaded secrets option.
	 *
	 * Core already lists the eleven gateway keys below in Options::SECRET_KEYS and
	 * says it will keep them there for good, so registering them again is a
	 * no-op — core de-duplicates the filtered list. They are named here anyway,
	 * because this plugin now owns the fields: a reader of this file should be able
	 * to see that every credential it collects is kept out of the autoloaded
	 * option, without having to go and read core's constant to find out.
	 *
	 * The licence key is the one genuinely new entry. It is a bearer credential for
	 * our store, which is reason enough on its own, and it is read only on an admin
	 * screen and by the twice-daily update check, so there is nothing to gain from
	 * having it autoloaded on every front-end request.
	 *
	 * @var string[]
	 */
	public const SECRET_KEYS = array(
		'stripe_test_secret',
		'stripe_live_secret',
		'stripe_webhook_secret',
		'paypal_test_secret',
		'paypal_live_secret',
		'authnet_transaction_key',
		'authnet_signature_key',
		'twocheckout_secret',
		'twocheckout_api_key',
		'mollie_live_api_key',
		'mollie_test_api_key',
		License::KEY_OPTION,
	);

	/**
	 * Register the seams. Called at file scope; see Plugin::register().
	 */
	public static function hooks(): void {
		add_filter( 'wopcart_secret_setting_keys', array( self::class, 'secret_keys' ) );
		add_filter( 'wopcart_uses_own_gateway_credentials', array( self::class, 'uses_own_credentials' ) );
		add_filter( 'wopcart_stripe_secret_key', array( self::class, 'stripe_secret_key' ), 10, 2 );
		add_filter( 'wopcart_stripe_publishable_key', array( self::class, 'stripe_publishable_key' ), 10, 2 );
	}

	/**
	 * Add this plugin's secret setting keys to core's list.
	 *
	 * Deliberately *not* guarded by Plugin::active(). The other seams are, because
	 * contributing a gateway or a credential to a core too old to have the rest of
	 * the machinery would leave a half-wired checkout. This one is different: the
	 * list is what tells core's Options class which of two options a value lives
	 * in, and a value that was written to the secrets option has to go on being
	 * *read* from there. Withdrawing a key because core happens to be mid-update
	 * would not hide a credential, it would make core look for it in the wrong
	 * place and get an empty string — which reads exactly like an unconfigured
	 * gateway. So the routing answer stands whatever else is true.
	 *
	 * @param array<int,string> $keys Keys core and other consumers have supplied.
	 *
	 * @return array<int,string>
	 */
	public static function secret_keys( array $keys ): array {
		return array_merge( $keys, self::SECRET_KEYS );
	}

	/**
	 * Whether this store transacts with gateway credentials of its own.
	 *
	 * **Installed means bought, and that is the whole test.** This plugin is sold
	 * on wopcart.com and is not in any public directory, so a copy on a site got
	 * there because somebody paid for it. The licence key exists for the two jobs
	 * it is actually needed for — securing updates and counting production
	 * domains — and neither of them is this question.
	 *
	 * Deliberately **not** gated on licence validity, and this is the single most
	 * important decision in the plugin. A merchant whose licence lapsed still has
	 * their own Stripe and PayPal accounts, and answering "no" here would move
	 * their payments back onto core's connected-account route, which retains 2% of
	 * every transaction. They would be charged a fee they had already bought their
	 * way out of, for an expiry they may not have noticed, with no checkout error
	 * to tell them. An expired licence stops updates. It never takes a merchant's
	 * gateways away.
	 *
	 * It is also not gated on a key being *present*. A merchant who has not got
	 * round to pasting their key in, or who cleared it, is in exactly the position
	 * above.
	 *
	 * The exposure in the other direction is bounded and accepted: this is GPL
	 * code, so the check could be deleted anyway, and a store that obtained the
	 * plugin without paying still has to hold real gateway credentials of its own
	 * to take a single payment.
	 *
	 * @param bool $uses_own What core, or an earlier consumer, already answered.
	 *
	 * @return bool
	 */
	public static function uses_own_credentials( bool $uses_own ): bool {
		if ( ! Plugin::active() ) {
			return $uses_own;
		}

		return true;
	}

	/**
	 * The Stripe secret key this store charges with.
	 *
	 * Core asks for the key belonging to a named mode rather than "the" key,
	 * because handing back a live key in test mode would put real charges through
	 * a sandbox checkout. So the mode is used as given and never second-guessed.
	 *
	 * An empty string means "no key from me", which leaves core's own fallback in
	 * charge; returning something non-empty when nothing is saved would turn an
	 * unconfigured gateway into a gateway that fails at Stripe.
	 *
	 * @param string $secret Key an earlier consumer supplied, usually empty.
	 * @param string $mode   Stripe mode the key must belong to: 'test' or 'live'.
	 *
	 * @return string
	 */
	public static function stripe_secret_key( string $secret, string $mode = 'test' ): string {
		if ( ! Plugin::active() || '' !== $secret ) {
			return $secret;
		}

		return self::saved( 'stripe_' . self::mode( $mode ) . '_secret' );
	}

	/**
	 * The Stripe publishable key the browser half of a payment uses.
	 *
	 * Separate from the secret for the reason Stripe issues two keys: this one is
	 * printed into a page. It is answered from the ordinary autoloaded settings
	 * option, not the secrets option, because a publishable key is public by
	 * design and core's field definition reflects that.
	 *
	 * @param string $publishable Key an earlier consumer supplied, usually empty.
	 * @param string $mode        Stripe mode the key must belong to: 'test' or 'live'.
	 *
	 * @return string
	 */
	public static function stripe_publishable_key( string $publishable, string $mode = 'test' ): string {
		if ( ! Plugin::active() || '' !== $publishable ) {
			return $publishable;
		}

		return self::saved( 'stripe_' . self::mode( $mode ) . '_publishable' );
	}

	/**
	 * Read one of this store's saved credentials.
	 *
	 * @param string $key Setting key.
	 *
	 * @return string Trimmed value, empty when nothing is saved.
	 */
	private static function saved( string $key ): string {
		return trim( (string) Options::get( $key, '' ) );
	}

	/**
	 * Normalise the mode core handed over.
	 *
	 * Anything that is not the word "live" is treated as the sandbox. A mode this
	 * plugin did not recognise must never resolve to the live key: the cost of
	 * getting it wrong in that direction is a real charge against a real card.
	 *
	 * @param string $mode Mode as supplied.
	 *
	 * @return string Either 'test' or 'live'.
	 */
	private static function mode( string $mode ): string {
		return 'live' === $mode ? 'live' : 'test';
	}
}
