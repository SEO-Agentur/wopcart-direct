<?php
/**
 * What stops this plugin contributing anything.
 *
 * @package WopCartDirect
 */

declare(strict_types=1);

namespace WopCartDirect\Tests\Unit;

use ReflectionMethod;
use WopCartDirect\AdminSurfaces;
use WopCartDirect\Credentials;
use WopCartDirect\Gateways;
use WopCartDirect\License;
use WopCartDirect\Plugin;
use WopCartDirect\Settings;

/**
 * The refusal, and the guard that makes it reach every seam.
 *
 * This plugin is paid for, so the two outcomes that must not be possible are a
 * copy that silently does nothing and a copy that half works. The first is
 * covered by the wordings below — whatever else happens, the merchant is told.
 * The second is covered by the guard test: a gateway registered against a core
 * too old to have the credential seams would appear at a checkout and then
 * refuse every payment.
 *
 * @covers \WopCartDirect\Plugin
 */
final class BootstrapRefusalTest extends AddonTestCase {

	/**
	 * No core at all. The notice has to say the credentials are safe, because the
	 * merchant's first instinct on seeing their gateways vanish is to re-enter
	 * keys they cannot see.
	 */
	public function test_a_missing_core_is_refused_and_reassures(): void {
		$refusal = Plugin::refusal( false, false );

		$this->assertStringContainsString( 'not active on this site', $refusal );
		$this->assertStringContainsString( 'still saved', $refusal );
	}

	/**
	 * A core too old names the version needed, not just "too old".
	 */
	public function test_an_older_core_is_refused_by_version(): void {
		$refusal = Plugin::refusal( true, false );

		$this->assertStringContainsString( \WopCartDirect\MIN_CORE, $refusal );
		$this->assertStringNotContainsString( 'not active on this site', $refusal, 'The missing-core check comes first.' );
		$this->assertStringContainsString( 'untouched', $refusal );
	}

	/**
	 * A core that is present and current is no refusal at all.
	 */
	public function test_a_current_core_is_accepted(): void {
		$this->assertSame( '', Plugin::refusal( true, true ) );
	}

	/**
	 * And the live wiring agrees: against the core this suite runs with, the
	 * plugin is active. Without this, refusal() could be correct while problem()
	 * asked it the wrong question.
	 */
	public function test_the_live_wiring_resolves_to_active(): void {
		$this->assertSame( '', Plugin::problem() );
		$this->assertTrue( Plugin::active() );
	}

	/**
	 * Every callback the plugin registers consults the guard.
	 *
	 * Asserted by reading each registered method's own source, because
	 * Plugin::active() is derived from a class existing and a constant's value, and
	 * neither can be taken away from a running PHP process — the constant this
	 * suite needs defined for every other test cannot be undefined for this one.
	 *
	 * What it really pins is an invariant about future seams rather than today's
	 * code: a seam added later without the guard is the exact mistake that produces
	 * a half-present add-on, and it would otherwise be invisible until a merchant
	 * on an un-updated core lost a checkout.
	 */
	public function test_every_registered_callback_consults_the_guard(): void {
		$unguarded = array();

		foreach ( $this->registered_callbacks() as $hook => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( ! str_contains( $this->source_of( $callback ), 'Plugin::active()' ) ) {
					$unguarded[] = $hook . ' → ' . $callback[0] . '::' . $callback[1];
				}
			}
		}

		$this->assertSame(
			array( 'wopcart_secret_setting_keys → WopCartDirect\Credentials::secret_keys' ),
			$unguarded,
			'Exactly one callback is deliberately unguarded; see Credentials::secret_keys().'
		);
	}

	/**
	 * The one unguarded callback answers whatever happens, on purpose.
	 *
	 * Withdrawing a secret key from core's list would not hide the credential, it
	 * would make core look for it in the autoloaded option, find nothing, and read
	 * that as an unconfigured gateway. Routing has to be stable even mid-update.
	 */
	public function test_the_secret_key_list_is_answered_unconditionally(): void {
		$keys = Credentials::secret_keys( array( 'stripe_live_secret' ) );

		$this->assertContains( License::KEY_OPTION, $keys );
		$this->assertContains( 'mollie_live_api_key', $keys );
		$this->assertContains( 'stripe_live_secret', $keys, 'Core\'s own keys are never dropped.' );
	}

	/**
	 * Nothing is registered on plugins_loaded except the notice.
	 *
	 * The seams are registered at file scope deliberately: core consults
	 * `wopcart_secret_setting_keys` on every settings read, so a registration
	 * inside a plugins_loaded callback arrives after the first read and sends it
	 * looking in the wrong option. Core documents this in Options::secret_keys();
	 * this holds this plugin to it, because the mistake is silent.
	 */
	public function test_boot_registers_no_seams(): void {
		$this->reset();

		Plugin::boot();

		foreach ( array_keys( $this->expected_hooks() ) as $hook ) {
			$this->assertFalse(
				has_filter( $hook ),
				$hook . ' must be registered at file scope, not from plugins_loaded.'
			);
		}
	}

	/**
	 * The hooks register() is expected to answer, and who answers each.
	 *
	 * @return array<string,array<int,array{0:string,1:string}>>
	 */
	private function expected_hooks(): array {
		return array(
			'wopcart_secret_setting_keys'                => array( array( Credentials::class, 'secret_keys' ) ),
			'wopcart_uses_own_gateway_credentials'       => array( array( Credentials::class, 'uses_own_credentials' ) ),
			'wopcart_stripe_secret_key'                  => array( array( Credentials::class, 'stripe_secret_key' ) ),
			'wopcart_stripe_publishable_key'             => array( array( Credentials::class, 'stripe_publishable_key' ) ),
			'wopcart_settings_sections'                  => array( array( Settings::class, 'settings_sections' ), array( Gateways\Registry::class, 'mollie_settings_sections' ) ),
			'wopcart_gateway_classes'                    => array( array( Gateways\Registry::class, 'gateway_classes' ) ),
			'wopcart_save_settings'                      => array( array( Gateways\Registry::class, 'mollie_save_settings' ) ),
			'wopcart_invoice_gateway_reports_no_payment' => array( array( Gateways\Registry::class, 'mollie_reports_no_payment' ) ),
			'wopcart_form_gateways'                      => array( array( Gateways\Registry::class, 'form_gateways' ) ),
			'wopcart_integration_cards'                  => array( array( AdminSurfaces::class, 'integration_cards' ) ),
			'wopcart_integration_test'                   => array( array( AdminSurfaces::class, 'integration_test' ) ),
			'wopcart_system_webhook_checks'              => array( array( AdminSurfaces::class, 'webhook_checks' ) ),
			'wopcart_privacy_third_party_content'        => array( array( AdminSurfaces::class, 'privacy_content' ) ),
			'wopcart_enqueue_gateway_scripts'            => array( array( AdminSurfaces::class, 'enqueue_gateway_scripts' ) ),
			'wopcart_settings_saved'                     => array( array( License::class, 'settings_saved' ) ),
		);
	}

	/**
	 * Every callback actually registered, as class/method pairs.
	 *
	 * @return array<string,array<int,array{0:string,1:string}>>
	 */
	private function registered_callbacks(): array {
		$found = array();

		foreach ( array_keys( $GLOBALS['wopcart_direct_test_hooks'] ) as $hook ) {
			foreach ( wopcart_direct_test_callbacks( (string) $hook ) as $registered ) {
				$callback = $registered['callback'];

				// Closures and WordPress's own actions (init, admin_notices) are not
				// seams core reads through; the guard test is about those.
				if ( is_array( $callback ) && is_string( $callback[0] ) && 0 === strpos( $callback[0], 'WopCartDirect\\' ) && 0 === strpos( (string) $hook, 'wopcart_' ) ) {
					$found[ (string) $hook ][] = array( $callback[0], (string) $callback[1] );
				}
			}
		}

		return $found;
	}

	/**
	 * A method's own source text.
	 *
	 * @param array{0:string,1:string} $callback Class name and method.
	 *
	 * @return string
	 */
	private function source_of( array $callback ): string {
		$method = new ReflectionMethod( $callback[0], $callback[1] );
		$lines  = file( (string) $method->getFileName() );

		return implode(
			'',
			array_slice(
				is_array( $lines ) ? $lines : array(),
				(int) $method->getStartLine() - 1,
				(int) $method->getEndLine() - (int) $method->getStartLine() + 1
			)
		);
	}

	/**
	 * Registration covers every hook this plugin claims to answer, and no other.
	 */
	public function test_register_hooks_exactly_the_expected_seams(): void {
		foreach ( $this->expected_hooks() as $hook => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$this->assertTrue(
					$this->is_hooked( $hook, $callback ),
					$callback[0] . '::' . $callback[1] . ' is not registered on ' . $hook
				);
			}
		}

		$this->assertSame(
			array_keys( $this->expected_hooks() ),
			array_keys( $this->registered_callbacks() ),
			'A seam was added, removed or reordered without the suite being told. The order is not cosmetic: callbacks of equal priority run in registration order, which is what puts the licence field above the gateway credentials on the Payments tab.'
		);
	}

	/**
	 * problem() never calls core's helper before checking that it exists.
	 *
	 * `supports()` only exists from core 1.3.0. On an older core the class is there
	 * and the method is not, so calling it straight is a fatal error on every
	 * request — and WordPress's `Requires Plugins` header does not protect against
	 * it, because it enforces that wopcart is present, not which version. The suite
	 * loads a current core and so cannot produce that core by loading classes; the
	 * guard is therefore asserted on the source.
	 */
	public function test_the_core_helper_is_only_called_when_it_exists(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Plugin.php' );

		$this->assertMatchesRegularExpression(
			'/method_exists\([^)]*\'supports\'[^)]*\)/',
			$source,
			'problem() must check that supports() exists before calling it.'
		);
	}
}
