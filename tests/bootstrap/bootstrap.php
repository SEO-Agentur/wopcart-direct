<?php
/**
 * PHPUnit bootstrap for the add-on's unit suite.
 *
 * Like the core plugin's suite, these tests run without WordPress loaded, which
 * is what keeps them fast enough to run on every save. They do need core's
 * source, though — the gateways extend core's AbstractGateway, the credential
 * seams are read back through core's Options, and the whole point of the suite is
 * that this plugin and core agree — so the core checkout has to be findable. It
 * is looked for next to this one, or at WOPCART_CORE_DIR.
 *
 * The WordPress stubs are core's suite's, included rather than copied. They are
 * the same handful of no-ops and identity functions, and two copies would drift
 * the first time either suite needed one more; a stub that drifted into
 * pretending to do something real would make these tests lie.
 *
 * @package WopCartDirect
 */

declare(strict_types=1);

/**
 * Locate the core WopCart plugin checkout.
 *
 * @return string Absolute path without a trailing slash.
 */
function wopcart_direct_core_dir(): string { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	$candidates = array( (string) getenv( 'WOPCART_CORE_DIR' ) );

	// Both plugins are checked out side by side, under the directory name the
	// repository uses (digital-cart) or the one WordPress installs them under.
	foreach ( array( 'digital-cart', 'wopcart' ) as $sibling ) {
		$candidates[] = dirname( __DIR__, 3 ) . '/' . $sibling;
	}

	foreach ( $candidates as $candidate ) {
		if ( '' !== $candidate && is_readable( $candidate . '/src/Autoloader.php' ) ) {
			return rtrim( $candidate, '/' );
		}
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- A CLI harness; WordPress, and so WP_Filesystem, is exactly what is not loaded.
	fwrite(
		STDERR,
		"The core WopCart plugin was not found.\n"
		. "Check it out next to this plugin, or set WOPCART_CORE_DIR to its directory.\n"
	);

	exit( 1 );
}

$wopcart_direct_core = wopcart_direct_core_dir();

/*
 * A small real hook system, defined *before* core's bootstrap so that these
 * definitions win its function_exists() guards.
 *
 * Core's suite stubs apply_filters() to return its input, with an override array
 * a test can pin an answer in. That is the right stub for core, whose unit tests
 * are about arithmetic behind a filter. It is the wrong one here: almost every
 * claim this plugin makes is "core asks a question and this plugin answers it",
 * and a filter stub that never calls a callback cannot test that at all — the
 * tests would only ever assert that a method returns what it returns.
 *
 * So callbacks are really registered and really run. Pinning is kept working
 * exactly as core defines it, and still takes precedence, because the gateway
 * tests copied over from core's suite rely on it.
 */

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * Register a callback, recording the priority and arity as WordPress does.
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 * @param int      $args     Number of arguments the callback accepts.
	 *
	 * @return bool
	 */
	function add_filter( string $hook, callable $callback, int $priority = 10, int $args = 1 ): bool { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$GLOBALS['wopcart_direct_test_hooks'][ $hook ][] = array(
			'callback' => $callback,
			'priority' => $priority,
			'args'     => $args,
		);

		return true;
	}

	/**
	 * Actions and filters share one registry, as they do in WordPress.
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 * @param int      $args     Number of arguments the callback accepts.
	 *
	 * @return bool
	 */
	function add_action( string $hook, callable $callback, int $priority = 10, int $args = 1 ): bool { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return add_filter( $hook, $callback, $priority, $args );
	}

	/**
	 * Whether anything is registered on a hook.
	 *
	 * @param string $hook Hook name.
	 *
	 * @return bool
	 */
	function has_filter( string $hook ): bool { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return array() !== ( $GLOBALS['wopcart_direct_test_hooks'][ $hook ] ?? array() );
	}
}

/**
 * Every callback registered on a hook, in the order WordPress would run them.
 *
 * Outside the guard above: apply_filters() below calls it, and the two are
 * guarded separately, so it must exist whichever of them the harness supplied.
 *
 * @param string $hook Hook name.
 *
 * @return array<int,array<string,mixed>>
 */
function wopcart_direct_test_callbacks( string $hook ): array { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	$callbacks = $GLOBALS['wopcart_direct_test_hooks'][ $hook ] ?? array();

	usort(
		$callbacks,
		static fn( array $a, array $b ): int => $a['priority'] <=> $b['priority']
	);

	return $callbacks;
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Run a hook's callbacks over a value.
	 *
	 * A pinned answer wins outright and no callback runs, which is core's
	 * documented behaviour for its own stub and what the copied gateway tests
	 * depend on. A callback is handed only as many arguments as it registered for,
	 * as WordPress does — this plugin registers the two Stripe credential filters
	 * for two arguments, and a harness that always passed one would not catch a
	 * missing arity.
	 *
	 * @param string $hook    Hook name.
	 * @param mixed  $value   Value to filter.
	 * @param mixed  ...$args Further arguments.
	 *
	 * @return mixed
	 */
	function apply_filters( string $hook, mixed $value = null, mixed ...$args ): mixed { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$pinned = $GLOBALS['wopcart_test_filters'] ?? array();

		if ( array_key_exists( $hook, $pinned ) ) {
			return $pinned[ $hook ];
		}

		foreach ( wopcart_direct_test_callbacks( $hook ) as $registered ) {
			$accepts = (int) $registered['args'];
			$passed  = array_slice( array_merge( array( $value ), $args ), 0, max( 1, $accepts ) );

			$value = call_user_func_array( $registered['callback'], $passed );
		}

		return $value;
	}

	/**
	 * Fire an action's callbacks.
	 *
	 * @param string $hook    Hook name.
	 * @param mixed  ...$args Arguments.
	 */
	function do_action( string $hook, mixed ...$args ): void { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		foreach ( wopcart_direct_test_callbacks( $hook ) as $registered ) {
			call_user_func_array(
				$registered['callback'],
				array_slice( $args, 0, (int) $registered['args'] )
			);
		}
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Options backed by a global array, so a test can seed and observe one.
	 *
	 * Also defined ahead of core's bootstrap, which stubs get_option() to always
	 * report an option as unset. That is the honest stub for core's own suite —
	 * its tests are about shipped defaults, and a canned value would mean the
	 * assertions covered the canned value. It cannot test this plugin, though,
	 * because two of its central claims are about *where* a value is stored: that a
	 * credential lands in the non-autoloaded secrets option rather than the
	 * autoloaded one, and that an expired licence recorded in an option changes
	 * nothing about the gateways. Neither is observable without somewhere to put a
	 * value.
	 *
	 * The behaviour with an empty store is identical to core's stub — the default
	 * comes back — so the gateway tests copied from core's suite are unaffected.
	 *
	 * @param string $name     Option name.
	 * @param mixed  $fallback Value returned when the option is unset. Named so
	 *                         rather than $default, which is a reserved keyword.
	 *
	 * @return mixed
	 */
	function get_option( string $name, mixed $fallback = false ): mixed { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		if ( ! array_key_exists( $name, $GLOBALS['wopcart_direct_test_options'] ) ) {
			return $fallback;
		}

		return $GLOBALS['wopcart_direct_test_options'][ $name ]['value'];
	}

	/**
	 * Option write, recording the autoload flag so a test can assert on it.
	 *
	 * @param string $name     Option name.
	 * @param mixed  $value    Value.
	 * @param bool   $autoload Whether WordPress would autoload it.
	 *
	 * @return bool
	 */
	function update_option( string $name, mixed $value, bool $autoload = true ): bool { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$GLOBALS['wopcart_direct_test_options'][ $name ] = array(
			'value'    => $value,
			'autoload' => $autoload,
		);

		return true;
	}

	/**
	 * Forget an option.
	 *
	 * @param string $name Option name.
	 *
	 * @return bool
	 */
	function delete_option( string $name ): bool { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $GLOBALS['wopcart_direct_test_options'][ $name ] );

		return true;
	}
}

$GLOBALS['wopcart_direct_test_hooks']   = array();
$GLOBALS['wopcart_direct_test_options'] = array();

/*
 * The core version the suite runs against.
 *
 * Defined here rather than left out, because Plugin::active() is false without it
 * and nearly every test in this suite is about what happens when this plugin is
 * active. The two refusals are asserted through Plugin::refusal(), which is pure
 * for exactly this reason — see its docblock.
 */
defined( 'WopCart\VERSION' ) || define( 'WopCart\VERSION', '1.3.1' );

// Defines ABSPATH, registers the core autoloader and installs the WordPress stubs.
require_once $wopcart_direct_core . '/tests/bootstrap/bootstrap.php';

require_once dirname( __DIR__, 2 ) . '/src/Autoloader.php';

\WopCartDirect\Autoloader::register();

/*
 * The suite's own classes, so a shared base class or stub can be used by name
 * rather than require_once'd at the top of every file that wants it. PHPUnit only
 * loads files matching *Test.php, so without this AddonTestCase would be missing
 * from the first test that extended it.
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'WopCartDirect\\Tests\\Unit\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$path = dirname( __DIR__ ) . '/Unit/' . substr( $class_name, strlen( $prefix ) ) . '.php';

		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

/*
 * Constants the main plugin file defines. Declared here because the tests load
 * classes directly rather than running the plugin file, which would need
 * WordPress's hook API.
 *
 * MIN_CORE is pinned to the same value the plugin file uses. BootstrapRefusalTest
 * runs in separate processes precisely so it can define \WopCart\VERSION above and
 * below it without the two cases contaminating each other.
 */
defined( 'WOPCART_DIRECT_TEST_SUITE' ) || define( 'WOPCART_DIRECT_TEST_SUITE', true );

if ( ! defined( 'WopCartDirect\VERSION' ) ) {
	define( 'WopCartDirect\VERSION', '1.0.0' );
	define( 'WopCartDirect\MIN_CORE', '1.3.0' );
	define( 'WopCartDirect\BASENAME', 'wopcart-direct/wopcart-direct.php' );
	define( 'WopCartDirect\FILE', dirname( __DIR__, 2 ) . '/wopcart-direct.php' );
	define( 'WopCartDirect\DIR', dirname( __DIR__, 2 ) );
}

defined( 'WOPCART_DIRECT_STORE_URL' ) || define( 'WOPCART_DIRECT_STORE_URL', 'https://wopcart.test' );
defined( 'WOPCART_DIRECT_PRODUCT_ID' ) || define( 'WOPCART_DIRECT_PRODUCT_ID', 0 );

/*
 * Stubs for the handful of admin-side WordPress functions this plugin's seams
 * touch, which core's bootstrap does not need and so does not define. Each is the
 * least behaviour that lets the code under test run: no capability is held, URLs
 * are built the way WordPress builds them, and options are recorded in a global
 * so a test can observe a write without a database.
 */

if ( ! function_exists( 'admin_url' ) ) {
	/**
	 * Admin URL stub.
	 *
	 * @param string $path Path below wp-admin.
	 *
	 * @return string
	 */
	function admin_url( string $path = '' ): string { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * Capability stub: nobody holds anything unless a test says so.
	 *
	 * Defaulting to false rather than true on purpose. Every admin surface in this
	 * plugin is capability-checked, and a stub that granted everything would make
	 * a missing check invisible to the suite.
	 *
	 * @param string $capability Capability.
	 *
	 * @return bool
	 */
	function current_user_can( string $capability ): bool { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return in_array( $capability, (array) ( $GLOBALS['wopcart_direct_test_caps'] ?? array() ), true );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Escape for HTML output, as WordPress does.
	 *
	 * Real escaping rather than an identity function, because two of this plugin's
	 * surfaces hand text to core expecting it to be escaped already — the privacy
	 * paragraphs and the admin notice — and a passthrough stub would let an
	 * unescaped apostrophe through unnoticed.
	 *
	 * @param string $text Text.
	 *
	 * @return string
	 */
	function esc_html( string $text ): string { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Escape for an HTML attribute.
	 *
	 * @param string $text Text.
	 *
	 * @return string
	 */
	function esc_attr( string $text ): string { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Post-content filtering stub: the markup this plugin emits is already safe.
	 *
	 * @param string $text Text.
	 *
	 * @return string
	 */
	function wp_kses_post( string $text ): string { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return $text;
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * Tag-stripping stub, used by core's IntegrationMonitor when it records a failure.
	 *
	 * Real stripping rather than an identity function: the message it is handed is
	 * an API error this plugin passes through, and it is printed on the
	 * Integrations screen unescaped by core's card renderer.
	 *
	 * @param string $text Text.
	 *
	 * @return string
	 */
	function wp_strip_all_tags( string $text ): string { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return trim( strip_tags( $text ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- This is the stub wp_strip_all_tags() stands in for.
	}
}

if ( ! function_exists( 'plugin_basename' ) ) {
	/**
	 * Enough of WordPress's plugin-path helpers for the updater to configure itself.
	 *
	 * @param string $file Absolute plugin file path.
	 *
	 * @return string
	 */
	function plugin_basename( string $file ): string { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return basename( dirname( $file ) ) . '/' . basename( $file );
	}

	/**
	 * Whether this is an admin request, which a test sets.
	 *
	 * @return bool
	 */
	function is_admin(): bool { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return (bool) ( $GLOBALS['wopcart_direct_test_is_admin'] ?? false );
	}

	/**
	 * Never cron: the one test that cares about cron asserts the front end instead.
	 *
	 * @return bool
	 */
	function wp_doing_cron(): bool { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return false;
	}
}

if ( ! function_exists( 'wp_remote_post' ) ) {
	/**
	 * HTTP stub: answers from a queue a test fills, and records what was sent.
	 *
	 * The licence calls are the one place this plugin talks to a network, and the
	 * two things worth asserting about them cannot be seen any other way: that
	 * saving a key really activates it and releases the key it replaced, and that
	 * a `check` is sent rather than an `activate` where pressing a button must not
	 * consume one of the merchant's paid-for domains.
	 *
	 * With an empty queue it reports a transport failure, which is also the honest
	 * default — a test that forgot to queue a response has not reached a store.
	 *
	 * @param string              $url  Request URL.
	 * @param array<string,mixed> $args Request arguments.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	function wp_remote_post( string $url, array $args = array() ): array|\WP_Error { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return wopcart_direct_test_request( 'POST', $url, $args );
	}

	/**
	 * The same stub for GET, which the Stripe connection test uses.
	 *
	 * Separate function, one queue. The Integrations screen's Test button for
	 * Stripe reads the balance, and the two things worth asserting about it need a
	 * stub that answers: that an unconfigured store is refused without a call
	 * going out at all, and that the merchant is shown Stripe's status code rather
	 * than a generic failure when the key is wrong.
	 *
	 * @param string              $url  Request URL.
	 * @param array<string,mixed> $args Request arguments.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	function wp_remote_get( string $url, array $args = array() ): array|\WP_Error { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return wopcart_direct_test_request( 'GET', $url, $args );
	}

	/**
	 * Record a request and answer it from the queue.
	 *
	 * @param string              $method HTTP verb.
	 * @param string              $url    Request URL.
	 * @param array<string,mixed> $args   Request arguments.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	function wopcart_direct_test_request( string $method, string $url, array $args ): array|\WP_Error { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$GLOBALS['wopcart_direct_test_requests'][] = array(
			'method'  => $method,
			'url'     => $url,
			'body'    => (array) ( $args['body'] ?? array() ),
			'headers' => (array) ( $args['headers'] ?? array() ),
		);

		$queued = array_shift( $GLOBALS['wopcart_direct_test_responses'] );

		if ( null === $queued ) {
			return new \WP_Error( 'http_request_failed', 'Nothing queued.' );
		}

		return array(
			'response' => array( 'code' => (int) ( $queued['code'] ?? 200 ) ),
			'body'     => (string) wp_json_encode( $queued['body'] ?? array() ),
		);
	}

	/**
	 * The body of a stubbed response.
	 *
	 * @param array<string,mixed>|\WP_Error $response Response.
	 *
	 * @return string
	 */
	function wp_remote_retrieve_body( array|\WP_Error $response ): string { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return is_array( $response ) ? (string) $response['body'] : '';
	}

	/**
	 * The status code of a stubbed response.
	 *
	 * @param array<string,mixed>|\WP_Error $response Response.
	 *
	 * @return int
	 */
	function wp_remote_retrieve_response_code( array|\WP_Error $response ): int { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return is_array( $response ) ? (int) $response['response']['code'] : 0;
	}

	/**
	 * JSON encoding, which is all WordPress's wrapper does on supported PHP.
	 *
	 * @param mixed $data Data to encode.
	 *
	 * @return string
	 */
	function wp_json_encode( mixed $data ): string { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return (string) json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- This is the stub wp_json_encode() stands in for.
	}
}

if ( ! function_exists( 'get_site_transient' ) ) {
	/**
	 * Site transients, backed by a global so the updater's caching is observable.
	 *
	 * @param string $key Transient name.
	 *
	 * @return mixed
	 */
	function get_site_transient( string $key ): mixed { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return $GLOBALS['wopcart_direct_test_site_transients'][ $key ] ?? false;
	}

	/**
	 * Store a site transient. Lifetime is ignored: nothing here expires.
	 *
	 * @param string $key   Transient name.
	 * @param mixed  $value Value.
	 * @param int    $ttl   Lifetime, ignored.
	 *
	 * @return bool
	 */
	function set_site_transient( string $key, mixed $value, int $ttl = 0 ): bool { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound,Generic.CodeAnalysis.UnusedFunctionParameter -- Signature has to match WordPress's.
		$GLOBALS['wopcart_direct_test_site_transients'][ $key ] = $value;

		return true;
	}

	/**
	 * Forget a site transient.
	 *
	 * @param string $key Transient name.
	 *
	 * @return bool
	 */
	function delete_site_transient( string $key ): bool { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $GLOBALS['wopcart_direct_test_site_transients'][ $key ] );

		return true;
	}
}

$GLOBALS['wopcart_direct_test_caps']            = array();
$GLOBALS['wopcart_direct_test_requests']        = array();
$GLOBALS['wopcart_direct_test_responses']       = array();
$GLOBALS['wopcart_direct_test_site_transients'] = array();
$GLOBALS['wopcart_direct_test_is_admin']        = false;
