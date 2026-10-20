<?php
/**
 * Bootstrap for the add-on.
 *
 * @package WopCartDirect
 */

declare(strict_types=1);

namespace WopCartDirect;

/**
 * Registers the add-on's extension points, once it is sure it is allowed to.
 *
 * Static rather than a container like core's Plugin: there is no shared service
 * here, only a handful of classes that each hook one of core's seams, so a
 * singleton would be ceremony around nothing.
 *
 * ## Why registration is split in two
 *
 * `register()` runs at file scope — see the main plugin file for why it has to —
 * and `boot()` runs on `plugins_loaded` at priority 6, one step after core boots
 * its own modules at 5.
 *
 * At file scope core is not loaded yet. WordPress activates plugins in path
 * order, and `wopcart-direct/wopcart-direct.php` sorts before
 * `wopcart/wopcart.php`, so this file is included first on every single request.
 * Nothing here can therefore ask whether core is present at the moment it
 * registers. So every callback asks instead at the moment it *runs*, through
 * `active()`, and until that answer is yes each one hands back the value it was
 * given untouched. A copy of this plugin sitting next to a missing or too-old
 * core contributes no gateway, no settings section, no credential and no card:
 * it is inert, not half-present.
 *
 * Half-present is the failure worth this much care. A gateway registered with no
 * credentials behind it would appear at a checkout and then refuse every payment,
 * and a credential filter answered by a copy of this plugin that core is too old
 * to have given the settings sections to would take a merchant's own keys away
 * while leaving the gateway that needs them on the page.
 */
final class Plugin {

	/**
	 * Classes that hook core's seams, each with a static hooks() method.
	 *
	 * The order is visible to a merchant, so it is not arbitrary. Everything here
	 * registers on `wopcart_settings_sections` at priority 10, and WordPress runs
	 * callbacks of equal priority in registration order — so this list decides the
	 * order of the headings down the Payments tab. Settings goes before
	 * Gateways\Registry because Mollie builds its own section (its write-only key
	 * fields are rules only that class knows) and belongs with the other gateways
	 * rather than above the licence field.
	 *
	 * @var string[]
	 */
	private const MODULES = array(
		Credentials::class,
		Settings::class,
		Gateways\Registry::class,
		AdminSurfaces::class,
		License::class,
	);

	/**
	 * Register everything that must be hooked before core reads it.
	 *
	 * Called at file scope. Each callback guards itself with active(), so this is
	 * safe to do before core exists.
	 */
	public static function register(): void {
		foreach ( self::MODULES as $module ) {
			$module::hooks();
		}
	}

	/**
	 * Explain the refusal, if there is one.
	 *
	 * Everything functional is registered in register() and self-guarded, so this
	 * does nothing but talk to the person who can fix it. There is no second,
	 * later registration pass: a seam registered on `plugins_loaded` would already
	 * be too late for core's `wopcart_secret_setting_keys`, which is consulted on
	 * every settings read.
	 */
	public static function boot(): void {
		$problem = self::problem();

		if ( '' === $problem ) {
			return;
		}

		add_action(
			'admin_notices',
			static function () use ( $problem ): void {
				self::notice( $problem, 'warning' );
			}
		);
	}

	/**
	 * Whether this plugin may contribute anything at all.
	 *
	 * Asked by every callback, on every call, rather than resolved once and cached.
	 * It is two cheap checks against a loaded class and a constant, and caching it
	 * would mean a callback that ran before core loaded — which is possible, the
	 * main file is included first — poisoning the answer for the whole request.
	 *
	 * @return bool
	 */
	public static function active(): bool {
		return '' === self::problem();
	}

	/**
	 * Why this plugin cannot contribute, or an empty string when it can.
	 *
	 * Public so the suite can pin each refusal. The merchant has paid for this
	 * plugin, so a copy that silently does nothing is the one outcome that must
	 * not be possible: either it works or it says why.
	 *
	 * @return string Human-readable reason, already translated.
	 */
	public static function problem(): string {
		$loaded = class_exists( '\WopCart\Plugin' );

		// supports() is core's own version helper and the only version check an
		// add-on is meant to make. It reports version 0 when core's VERSION constant
		// is missing, which is what an install older than the helper itself looks
		// like, so asking it is safe even then.
		return self::refusal( $loaded, $loaded && \WopCart\Plugin::supports( MIN_CORE ) );
	}

	/**
	 * The refusal itself, as a decision with no environment in it.
	 *
	 * Pure, and separate from problem() for one reason: the two refusals cannot
	 * both be exercised through problem() in a single PHP process. Whether core is
	 * current is decided by the `\WopCart\VERSION` constant, and a constant defined
	 * for the "core is fine" case — which nearly every other test in the suite
	 * needs — cannot be undefined again for the "core is too old" case. Handing the
	 * two facts in as arguments makes both wordings assertable, which matters
	 * because they are the only thing a merchant whose paid add-on has gone quiet
	 * will have to go on.
	 *
	 * @param bool $core_loaded    Whether core's Plugin class is available.
	 * @param bool $core_supported Whether core is new enough, per its own supports().
	 *
	 * @return string Human-readable reason, already translated. Empty when there is none.
	 */
	public static function refusal( bool $core_loaded, bool $core_supported ): string {
		if ( ! $core_loaded ) {
			return __( 'WopCart Direct needs the WopCart plugin, which is not active on this site. Your gateway credentials are still saved and will be used again as soon as WopCart is active.', 'wopcart-direct' );
		}

		if ( ! $core_supported ) {
			return sprintf(
				/* translators: %s: minimum WopCart version. */
				__( 'WopCart Direct needs WopCart %s or newer, which is where the extension points it is built on were added. Until WopCart is updated this add-on stays inactive rather than registering gateways it cannot supply credentials for. Your saved credentials are untouched.', 'wopcart-direct' ),
				MIN_CORE
			);
		}

		return '';
	}

	/**
	 * Show a message to somebody who can act on it.
	 *
	 * @param string $message Already-translated message.
	 * @param string $level   Notice level: warning, error or info.
	 */
	public static function notice( string $message, string $level = 'warning' ): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%s"><p>%s</p></div>',
			esc_attr( $level ),
			wp_kses_post( $message )
		);
	}

	/**
	 * Absolute path inside the plugin directory.
	 *
	 * @param string $relative Relative path.
	 *
	 * @return string
	 */
	public static function path( string $relative = '' ): string {
		return DIR . '/' . ltrim( $relative, '/' );
	}
}
