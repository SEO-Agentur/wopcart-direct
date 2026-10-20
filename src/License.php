<?php
/**
 * This plugin's own product licence, and the updates it secures.
 *
 * @package WopCartDirect
 */

declare(strict_types=1);

namespace WopCartDirect;

use WopCart\Support\Options;

/**
 * Holds the licence key, activates it, and wires the updater.
 *
 * The wopcart.com store sells WopCart Direct as an ordinary licensed product,
 * using WopCart's own `src/Licensing` module — the same machinery a merchant uses to
 * licence their own customers. So there is nothing new to build on either side:
 * the store issues a key, this plugin activates it against the site, and the
 * embedded updater asks `/license/version` for the next release.
 *
 * ## What the key is for, and what it is not for
 *
 * Two jobs, both of which genuinely need it:
 *
 * - **Securing updates.** Without a key the store has no reason to hand over a
 *   zip, and the plugin would be freely redistributable in practice as well as
 *   in licence.
 * - **Counting production domains.** The product is priced per 1, 10 and 100
 *   domains, and an activation is how a domain is counted. Core already treats
 *   local, `.test` and known staging hosts as free, so a developer's copies do
 *   not use up the merchant's places.
 *
 * It is **not** what unlocks the gateways. `Credentials::uses_own_credentials()`
 * explains that at length; the short version is that taking a lapsed customer's
 * own Stripe account away from them would silently put them back on a 2% fee
 * they had already paid to escape. An expired licence stops updates and says so.
 * Nothing else.
 *
 * Which is also why this class holds no enforcement code at all. "Updates stop"
 * is not something it does — it is what happens on its own when the store
 * answers the version check with a failure and no package, and the updater
 * injects nothing into WordPress's update transient.
 */
final class License {

	/**
	 * Setting key holding the licence key.
	 *
	 * A setting rather than a standalone option, so it is rendered, sanitised,
	 * capability-checked and stored by core's settings screen like every other
	 * credential. Registered as a secret through `Credentials::SECRET_KEYS`, which
	 * puts it in the non-autoloaded option and stops core printing it back into
	 * the form.
	 */
	public const KEY_OPTION = 'direct_license_key';

	/**
	 * Option holding the store's last answer about this licence.
	 *
	 * Deliberately **not** prefixed `wopcart_`: core's `uninstall.php` deletes
	 * every option matching `wopcart_%`, and this plugin's own state should not
	 * disappear because core was uninstalled and reinstalled.
	 *
	 * Not autoloaded. It is read on admin pages to decide whether to show a
	 * notice, and never on the front end.
	 */
	public const STATUS_OPTION = 'wopcartdirect_license_status';

	/**
	 * Error codes that mean "ask again later", not "something is wrong with this licence".
	 *
	 * @var string[]
	 */
	public const TRANSPORT_ERRORS = array( 'connection_failed', 'invalid_response', 'busy', 'rate_limited' );

	/**
	 * Register the seams. Called at file scope; see Plugin::register().
	 */
	public static function hooks(): void {
		// Second argument: the settings as they were before the save. Without it a
		// listener cannot tell a changed key from a re-saved one, and releasing the
		// site's place on the licence every time the merchant saves an unrelated
		// tab would burn through their domain count.
		add_action( 'wopcart_settings_saved', array( self::class, 'settings_saved' ), 10, 2 );

		// Deliberately on init rather than at file scope: the updater's constructor
		// calls plugin_basename() and registers filters, and there is nothing to be
		// gained from doing that before WordPress has finished loading. Nothing it
		// hooks fires before init.
		add_action( 'init', array( self::class, 'boot_updater' ) );

		add_action( 'admin_notices', array( self::class, 'render_notice' ) );
	}

	/**
	 * The saved licence key.
	 *
	 * @return string Empty when none is saved.
	 */
	public static function key(): string {
		if ( ! Plugin::active() ) {
			return '';
		}

		return trim( (string) Options::get( self::KEY_OPTION, '' ) );
	}

	/**
	 * Attach the updater to WordPress's update machinery.
	 *
	 * Only where an update check can actually happen. WordPress looks for plugin
	 * updates on admin pages and from the `wp_update_plugins` cron event; a
	 * front-end request has nothing to check, and registering there would put this
	 * plugin's filters on every page view for no purpose.
	 *
	 * A missing key is not a reason to skip this. The updater still registers,
	 * still asks the store, and the store refuses — which is what makes the "no
	 * update available" state honest rather than invisible. It also means pasting a
	 * key takes effect on the next dashboard load without a second code path.
	 */
	public static function boot_updater(): void {
		if ( ! Plugin::active() ) {
			return;
		}

		if ( ! is_admin() && ! wp_doing_cron() ) {
			return;
		}

		self::updater();
	}

	/**
	 * The updater instance, or null before it has been built.
	 *
	 * Memoised because the updater registers WordPress filters in its constructor:
	 * a second instance would mean two callbacks racing to inject the same update
	 * into the same transient. A property rather than a `static` local so the suite
	 * can clear it between tests through reflection, which keeps a test-only reset
	 * method out of the class.
	 *
	 * @var \WopCart_Direct_Updater|null
	 */
	private static ?\WopCart_Direct_Updater $updater = null;

	/**
	 * The updater instance, built once per request.
	 *
	 * The licence key is read when it is built. That is deliberate and not a
	 * staleness bug: every licence call below passes its key explicitly, so a key
	 * saved later in the same request is activated with the key the merchant just
	 * entered, not the one this was built with.
	 *
	 * @return \WopCart_Direct_Updater
	 */
	public static function updater(): \WopCart_Direct_Updater {
		if ( null === self::$updater ) {
			require_once Plugin::path( 'client/class-wopcart-direct-updater.php' );

			self::$updater = new \WopCart_Direct_Updater(
				array(
					'store_url' => (string) WOPCART_DIRECT_STORE_URL,
					'item_id'   => (int) WOPCART_DIRECT_PRODUCT_ID,
					'file'      => FILE,
					'version'   => VERSION,
					'license'   => self::key(),
					'author'    => 'Cyrus Badde',
				)
			);
		}

		return self::$updater;
	}

	/**
	 * Activate a newly entered key, and release the one it replaced.
	 *
	 * The key only appears in `$values` when it was actually submitted with
	 * something in it, or cleared through the checkbox core renders beside a secret
	 * field. Every other tab's form omits it entirely, and treating "absent" as a
	 * change is how a merchant saving their email settings would lose this site's
	 * place on their licence.
	 *
	 * The old key is deactivated first so that swapping keys — a merchant moving a
	 * site from one licence to another — gives the place back rather than consuming
	 * one from each. Deactivation failures are ignored: the new key is what the
	 * merchant is waiting on, and a place that was not released is a support
	 * question rather than a broken site.
	 *
	 * @param array<string,mixed> $values   Values that were stored.
	 * @param array<string,mixed> $previous All settings as they were before the save.
	 */
	public static function settings_saved( array $values, array $previous = array() ): void {
		if ( ! Plugin::active() || ! array_key_exists( self::KEY_OPTION, $values ) ) {
			return;
		}

		$new = trim( (string) $values[ self::KEY_OPTION ] );
		$old = trim( (string) ( $previous[ self::KEY_OPTION ] ?? '' ) );

		if ( '' !== $old && $old !== $new ) {
			self::updater()->deactivate( $old );
		}

		if ( '' === $new ) {
			delete_option( self::STATUS_OPTION );

			return;
		}

		// Re-saving the same key on purpose is how a merchant retries an activation
		// that failed while our store was unreachable, so this runs even when the
		// key has not changed.
		self::remember( self::updater()->activate( $new ) );
	}

	/**
	 * Ask the store about the saved key now, and remember the answer.
	 *
	 * A `check`, never an `activate`: this is reached from the Test button on the
	 * Integrations screen, and a merchant pressing Test to find out where they
	 * stand must not thereby use up one of the production domains they are paying
	 * per.
	 *
	 * @return array<string,mixed> Decoded store response.
	 */
	public static function check_now(): array {
		$key = self::key();

		if ( '' === $key ) {
			return array(
				'success' => false,
				'error'   => 'missing',
			);
		}

		$response = self::updater()->check( $key );

		self::remember( $response );

		return $response;
	}

	/**
	 * Store what the store said, for the notice to read later.
	 *
	 * Only the fields the notice needs are kept. The response also carries the
	 * masked key, seat counts and a renewal URL, and an option holding a partial
	 * copy of the licence record would start being treated as one.
	 *
	 * A transport failure is not remembered at all. It says nothing about the
	 * licence — only that our own server could not be reached — and writing it over
	 * a real verdict would turn a brief outage of ours into a merchant's
	 * Integrations card going quiet and their licence status reading "unknown".
	 * The previous answer stays until the store gives a new one.
	 *
	 * @param array<string,mixed> $response Decoded store response.
	 */
	private static function remember( array $response ): void {
		if ( in_array( (string) ( $response['error'] ?? '' ), self::TRANSPORT_ERRORS, true ) ) {
			return;
		}

		update_option(
			self::STATUS_OPTION,
			array(
				'success'     => ! empty( $response['success'] ),
				'status'      => (string) ( $response['status'] ?? '' ),
				'error'       => (string) ( $response['error'] ?? '' ),
				'message'     => (string) ( $response['message'] ?? '' ),
				'expires_at'  => (string) ( $response['expires_at'] ?? '' ),
				'renewal_url' => (string) ( $response['renewal_url'] ?? '' ),
				'checked_at'  => time(),
			),
			false
		);
	}

	/**
	 * The last answer from the store, or an empty array when it has not been asked.
	 *
	 * @return array<string,mixed>
	 */
	public static function status(): array {
		$status = get_option( self::STATUS_OPTION, array() );

		return is_array( $status ) ? $status : array();
	}

	/**
	 * Why the licence needs attention, or an empty string when it does not.
	 *
	 * Pure, so the wording of each case can be pinned by the suite without a
	 * database. Note what is *not* a problem worth a notice: a transport failure.
	 * `connection_failed` and `invalid_response` mean our server was unreachable,
	 * which is our fault and not something the merchant can act on, and a red
	 * banner on every admin page because wopcart.com was briefly down would be a
	 * false alarm about a plugin that is working perfectly. `remember()` already
	 * declines to store those, so this is the second of two guards; it stays
	 * because this method is pure and public, and will be handed whatever an
	 * earlier version of the plugin happened to write.
	 *
	 * @param array<string,mixed> $status Stored status.
	 * @param string              $key    Saved licence key.
	 *
	 * @return string Message, already translated.
	 */
	public static function problem( array $status, string $key ): string {
		if ( '' === $key ) {
			return __( 'WopCart Direct has no licence key. Your payment gateways are working normally — a key is only needed to receive updates. Add yours under WopCart → Settings → Payments.', 'wopcart-direct' );
		}

		if ( array() === $status || ! empty( $status['success'] ) ) {
			return '';
		}

		if ( in_array( (string) ( $status['error'] ?? '' ), self::TRANSPORT_ERRORS, true ) ) {
			return '';
		}

		$expired = 'expired' === (string) ( $status['status'] ?? '' )
			|| 'expired' === (string) ( $status['error'] ?? '' );

		if ( $expired ) {
			return __( 'Your WopCart Direct licence has expired. Your own payment gateways keep working exactly as they are and no platform fee has been reintroduced — but this plugin will not receive updates until the licence is renewed.', 'wopcart-direct' );
		}

		return __( 'WopCart Direct could not verify its licence key. Your own payment gateways keep working exactly as they are; only updates are affected. Check the key under WopCart → Settings → Payments.', 'wopcart-direct' );
	}

	/**
	 * Show the licence notice, if there is one to show.
	 */
	public static function render_notice(): void {
		if ( ! Plugin::active() ) {
			return;
		}

		$problem = self::problem( self::status(), self::key() );

		if ( '' === $problem ) {
			return;
		}

		// Warning rather than error, on purpose. Nothing is broken: payments are
		// still going through the merchant's own accounts. An error-red banner
		// would say otherwise, and a merchant who reads it as "my checkout is down"
		// may well switch the plugin off and give themselves the fee back.
		Plugin::notice( $problem, 'warning' );
	}
}
