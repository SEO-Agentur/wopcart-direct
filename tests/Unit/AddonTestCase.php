<?php
/**
 * Shared setup for the tests about this plugin's own behaviour.
 *
 * @package WopCartDirect
 */

declare(strict_types=1);

namespace WopCartDirect\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WopCart\Support\Options;
use WopCartDirect\License;
use WopCartDirect\Plugin;

/**
 * A test that has this plugin's hooks really registered.
 *
 * The suite's harness implements enough of WordPress's hook API to run callbacks
 * for real (see tests/bootstrap/bootstrap.php). That is what lets these tests
 * assert the thing that actually matters — *core asks a question and this plugin
 * answers it* — rather than merely that a method returns what it returns.
 *
 * Every subclass therefore starts from the same place a real request does: hooks
 * registered exactly as the main plugin file registers them, an empty options
 * table, no pinned filters and no capabilities held.
 */
abstract class AddonTestCase extends TestCase {

	/**
	 * Register the plugin's seams, as its main file does at file scope.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->reset();

		Plugin::register();
	}

	/**
	 * Leave nothing behind for the next test.
	 */
	protected function tearDown(): void {
		$this->reset();

		parent::tearDown();
	}

	/**
	 * Empty the harness: hooks, options, pinned filters, capabilities and caches.
	 */
	protected function reset(): void {
		$GLOBALS['wopcart_direct_test_hooks']           = array();
		$GLOBALS['wopcart_direct_test_options']         = array();
		$GLOBALS['wopcart_direct_test_caps']            = array();
		$GLOBALS['wopcart_direct_test_requests']        = array();
		$GLOBALS['wopcart_direct_test_responses']       = array();
		$GLOBALS['wopcart_direct_test_site_transients'] = array();
		$GLOBALS['wopcart_direct_test_is_admin']        = false;
		$GLOBALS['wopcart_test_filters']                = array();

		// Core caches the settings for the length of a request, and a test's writes
		// would otherwise be invisible to the next one.
		Options::flush();

		// The updater is memoised because it registers filters in its constructor,
		// and it reads the licence key when it is built. Cleared through reflection
		// rather than through a reset method on the class, so that production code
		// carries nothing that exists only for the suite.
		$updater = new \ReflectionProperty( License::class, 'updater' );
		$updater->setValue( null, null );
	}

	/**
	 * Pin a filter's answer outright, as core's own suite does.
	 *
	 * The harness honours a pinned answer ahead of any registered callback, so this
	 * is how a test stands in for a *different* consumer of a hook — not for this
	 * plugin, whose callbacks are meant to run.
	 *
	 * @param string $hook  Filter name.
	 * @param mixed  $value Answer.
	 */
	protected function pin( string $hook, mixed $value ): void {
		$GLOBALS['wopcart_test_filters'][ $hook ] = $value;
	}

	/**
	 * Store an option and drop core's settings cache.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Value.
	 */
	protected function store( string $name, mixed $value ): void {
		update_option( $name, $value );

		Options::flush();
	}

	/**
	 * The autoload flag an option was written with, or null when it is unset.
	 *
	 * @param string $name Option name.
	 *
	 * @return bool|null
	 */
	protected function autoload_flag( string $name ): ?bool {
		$row = $GLOBALS['wopcart_direct_test_options'][ $name ] ?? null;

		return is_array( $row ) ? (bool) $row['autoload'] : null;
	}

	/**
	 * Whether a hook has this exact callback registered.
	 *
	 * @param string                   $hook     Hook name.
	 * @param array{0:string,1:string} $callback Class name and method.
	 *
	 * @return bool
	 */
	protected function is_hooked( string $hook, array $callback ): bool {
		foreach ( wopcart_direct_test_callbacks( $hook ) as $registered ) {
			if ( $registered['callback'] === $callback ) {
				return true;
			}
		}

		return false;
	}
}
