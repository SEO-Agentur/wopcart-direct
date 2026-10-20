<?php
/**
 * The licence: activation, the notice, and what expiry does and does not do.
 *
 * @package WopCartDirect
 */

declare(strict_types=1);

namespace WopCartDirect\Tests\Unit;

use WopCart\Support\Options;
use WopCartDirect\License;

/**
 * Everything the licence key is for, and everything it is not for.
 *
 * The licence has exactly two jobs — securing updates and counting production
 * domains — and the tests fall into the same two halves. The second half is the
 * one worth having: a lapsed licence must leave the merchant's own gateways
 * completely alone, because the alternative is a working checkout that has
 * quietly started costing them 2% again.
 *
 * @covers \WopCartDirect\License
 */
final class LicenseUpdatesTest extends AddonTestCase {

	/**
	 * Queue a store response for the next licence call.
	 *
	 * @param array<string,mixed> $body Decoded body the store would return.
	 * @param int                 $code HTTP status.
	 */
	private function queue( array $body, int $code = 200 ): void {
		$GLOBALS['wopcart_direct_test_responses'][] = array(
			'body' => $body,
			'code' => $code,
		);
	}

	/**
	 * The licence calls that were made, in order.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function requests(): array {
		return $GLOBALS['wopcart_direct_test_requests'];
	}

	/**
	 * The action core fires after a settings save, with the values and the previous ones.
	 *
	 * @param array<string,mixed> $values   Values that were stored.
	 * @param array<string,mixed> $previous Settings as they were.
	 */
	private function save( array $values, array $previous = array() ): void {
		do_action( 'wopcart_settings_saved', $values, $previous );
	}

	/**
	 * Saving a key activates it against this site.
	 *
	 * Activation is how a production domain is counted, which is what the per-1,
	 * per-10 and per-100 pricing is sold against — so it has to happen when the
	 * merchant saves, not only whenever an update check next runs.
	 */
	public function test_saving_a_key_activates_it(): void {
		$this->queue(
			array(
				'success' => true,
				'status'  => 'active',
			)
		);

		$this->save( array( License::KEY_OPTION => 'WOP-NEW' ) );

		$requests = $this->requests();

		$this->assertCount( 1, $requests );
		$this->assertStringEndsWith( '/wp-json/wopcart/v1/license/activate', $requests[0]['url'] );
		$this->assertSame( 'WOP-NEW', $requests[0]['body']['license'] );
		$this->assertSame( WOPCART_DIRECT_PRODUCT_ID, $requests[0]['body']['item_id'] );
		$this->assertSame( \WopCartDirect\VERSION, $requests[0]['body']['version'] );

		$this->assertTrue( License::status()['success'] );
		$this->assertSame( 'active', License::status()['status'] );
	}

	/**
	 * Replacing a key releases the one it replaced first.
	 *
	 * A merchant moving a site from one licence to another would otherwise consume
	 * a place on both, and the place on the old one would stay consumed until they
	 * noticed and asked.
	 */
	public function test_replacing_a_key_releases_the_old_one_first(): void {
		$this->queue( array( 'success' => true ) );
		$this->queue(
			array(
				'success' => true,
				'status'  => 'active',
			)
		);

		$this->save( array( License::KEY_OPTION => 'WOP-NEW' ), array( License::KEY_OPTION => 'WOP-OLD' ) );

		$requests = $this->requests();

		$this->assertCount( 2, $requests );
		$this->assertStringEndsWith( '/license/deactivate', $requests[0]['url'] );
		$this->assertSame( 'WOP-OLD', $requests[0]['body']['license'] );
		$this->assertStringEndsWith( '/license/activate', $requests[1]['url'] );
		$this->assertSame( 'WOP-NEW', $requests[1]['body']['license'] );
	}

	/**
	 * Clearing the key releases the place and forgets the stored verdict.
	 */
	public function test_clearing_the_key_releases_the_place(): void {
		$this->store( License::STATUS_OPTION, array( 'success' => true ) );
		$this->queue( array( 'success' => true ) );

		$this->save( array( License::KEY_OPTION => '' ), array( License::KEY_OPTION => 'WOP-OLD' ) );

		$requests = $this->requests();

		$this->assertCount( 1, $requests );
		$this->assertStringEndsWith( '/license/deactivate', $requests[0]['url'] );
		$this->assertSame( array(), License::status(), 'A verdict about a key that is gone is not kept.' );
	}

	/**
	 * Re-saving the same key retries the activation rather than doing nothing.
	 *
	 * Which is how a merchant retries after our store was unreachable, or after
	 * freeing a place on their account.
	 */
	public function test_resaving_the_same_key_retries_activation(): void {
		$this->queue(
			array(
				'success' => true,
				'status'  => 'active',
			)
		);

		$this->save( array( License::KEY_OPTION => 'WOP-SAME' ), array( License::KEY_OPTION => 'WOP-SAME' ) );

		$requests = $this->requests();

		$this->assertCount( 1, $requests, 'No deactivation: the key did not change.' );
		$this->assertStringEndsWith( '/license/activate', $requests[0]['url'] );
	}

	/**
	 * **A save that did not touch the key touches nothing.**
	 *
	 * Every other tab's form omits the licence field, and treating "absent" as a
	 * change is how a merchant saving their email settings would release this
	 * site's place on their licence.
	 */
	public function test_a_save_without_the_key_does_nothing(): void {
		$this->save( array( 'email_from_name' => 'Shop' ), array( License::KEY_OPTION => 'WOP-SAVED' ) );

		$this->assertSame( array(), $this->requests(), 'Not one licence call.' );
	}

	/**
	 * A store that cannot be reached is not recorded as a verdict.
	 *
	 * An outage of ours would otherwise paint the merchant's Integrations card as
	 * failing and overwrite the last real answer with "unknown".
	 */
	public function test_a_transport_failure_is_not_recorded(): void {
		$this->store(
			License::STATUS_OPTION,
			array(
				'success' => true,
				'status'  => 'active',
			)
		);

		// Nothing queued: the stub reports a WP_Error, as an unreachable store does.
		$this->save( array( License::KEY_OPTION => 'WOP-NEW' ) );

		$this->assertTrue( License::status()['success'], 'The last real answer survives.' );
		$this->assertSame( 'active', License::status()['status'] );
	}

	/**
	 * The notice stays silent while our own server is the problem.
	 */
	public function test_a_transport_failure_raises_no_notice(): void {
		foreach ( License::TRANSPORT_ERRORS as $code ) {
			$this->assertSame(
				'',
				License::problem(
					array(
						'success' => false,
						'error'   => $code,
					),
					'WOP-KEY'
				),
				$code . ' is our fault, not something the merchant can act on.'
			);
		}
	}

	/**
	 * An expired licence says so, and says what is unaffected.
	 */
	public function test_an_expired_licence_is_explained_without_alarm(): void {
		$problem = License::problem(
			array(
				'success' => false,
				'status'  => 'expired',
			),
			'WOP-KEY'
		);

		$this->assertStringContainsString( 'expired', $problem );
		$this->assertStringContainsString( 'keep working', $problem );
		$this->assertStringContainsString( 'no platform fee', $problem );
		$this->assertStringContainsString( 'updates', $problem );
	}

	/**
	 * A refused key is reported differently from an expired one: it is a typo to
	 * fix, not a renewal to buy.
	 */
	public function test_a_refused_key_points_at_the_key(): void {
		$problem = License::problem(
			array(
				'success' => false,
				'status'  => 'missing',
				'error'   => 'missing',
			),
			'WOP-TYPO'
		);

		$this->assertStringContainsString( 'could not verify', $problem );
		$this->assertStringContainsString( 'Check the key', $problem );
		$this->assertStringContainsString( 'only updates', $problem );
	}

	/**
	 * No key at all is explained as an updates problem, not a payments one.
	 */
	public function test_no_key_is_explained_as_an_updates_problem(): void {
		$problem = License::problem( array(), '' );

		$this->assertStringContainsString( 'no licence key', $problem );
		$this->assertStringContainsString( 'working normally', $problem );
	}

	/**
	 * A valid licence says nothing at all.
	 */
	public function test_a_valid_licence_is_silent(): void {
		$this->assertSame(
			'',
			License::problem(
				array(
					'success' => true,
					'status'  => 'active',
				),
				'WOP-KEY'
			)
		);

		$this->assertSame( '', License::problem( array(), 'WOP-KEY' ), 'A key that has not been checked yet is not a problem.' );
	}

	/**
	 * The notice is only drawn for somebody who can act on it, and as a warning.
	 *
	 * Warning rather than error on purpose: nothing is broken, payments are still
	 * going through the merchant's own accounts, and a red banner would read as
	 * "my checkout is down" — which might well make them switch the plugin off and
	 * hand themselves the platform fee back.
	 */
	public function test_the_notice_respects_capability_and_tone(): void {
		$this->store( \WopCart\Support\Options::SECRETS_OPTION, array( License::KEY_OPTION => 'WOP-KEY' ) );
		$this->store(
			License::STATUS_OPTION,
			array(
				'success' => false,
				'status'  => 'expired',
			)
		);

		ob_start();
		do_action( 'admin_notices' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Firing WordPress's own hook is how the suite drives this plugin's callbacks.
		$silent = (string) ob_get_clean();

		$this->assertSame( '', $silent, 'Nobody holding activate_plugins sees nothing.' );

		$GLOBALS['wopcart_direct_test_caps'] = array( 'activate_plugins' );

		ob_start();
		do_action( 'admin_notices' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Firing WordPress's own hook is how the suite drives this plugin's callbacks.
		$shown = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $shown );
		$this->assertStringNotContainsString( 'notice-error', $shown );
		$this->assertStringContainsString( 'expired', $shown );
	}

	/**
	 * Pressing Test sends a `check`, never an `activate`.
	 *
	 * The merchant is paying per production domain. A button that told them where
	 * they stood and consumed one of their domains in the process would be a trap.
	 */
	public function test_checking_the_licence_does_not_consume_a_domain(): void {
		$this->store( Options::SECRETS_OPTION, array( License::KEY_OPTION => 'WOP-KEY' ) );
		$this->queue(
			array(
				'success' => true,
				'status'  => 'active',
			)
		);

		License::check_now();

		$requests = $this->requests();

		$this->assertCount( 1, $requests );
		$this->assertStringEndsWith( '/license/check', $requests[0]['url'] );
		$this->assertStringNotContainsString( '/activate', $requests[0]['url'] );
	}

	/**
	 * With no key there is nothing to ask, and nothing is asked.
	 */
	public function test_checking_without_a_key_makes_no_request(): void {
		$response = License::check_now();

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'missing', $response['error'] );
		$this->assertSame( array(), $this->requests() );
	}

	/**
	 * The updater is configured from the two overridable constants.
	 *
	 * Constants rather than settings: a merchant has no reason to change either,
	 * and a store URL that could be changed from the admin would be a route for
	 * sending a licence key somewhere else.
	 */
	public function test_the_updater_is_configured_from_the_constants(): void {
		$this->store( Options::SECRETS_OPTION, array( License::KEY_OPTION => 'WOP-KEY' ) );
		$this->queue( array( 'success' => true ) );

		License::check_now();

		$this->assertStringStartsWith( WOPCART_DIRECT_STORE_URL, $this->requests()[0]['url'] );
		$this->assertSame( WOPCART_DIRECT_PRODUCT_ID, $this->requests()[0]['body']['item_id'] );
	}

	/**
	 * Both constants are defined by the plugin file and both can be overridden.
	 *
	 * Asserted against the source, because a constant already defined by the
	 * suite's bootstrap cannot be redefined to prove it is overridable.
	 */
	public function test_both_constants_are_overridable(): void {
		$source = (string) file_get_contents( \WopCartDirect\FILE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source file.

		foreach ( array( 'WOPCART_DIRECT_STORE_URL', 'WOPCART_DIRECT_PRODUCT_ID' ) as $constant ) {
			$this->assertStringContainsString( "if ( ! defined( '" . $constant . "' ) ) {", $source, $constant . ' must be overridable from wp-config.php.' );
		}
	}

	/**
	 * The updater is only wired where an update check can actually happen.
	 *
	 * A front-end request has nothing to check, and registering there would put
	 * these filters on every page view of every store for no purpose.
	 */
	public function test_the_updater_is_not_wired_on_the_front_end(): void {
		do_action( 'init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Firing WordPress's own hook is how the suite drives this plugin's callbacks.

		$this->assertFalse( has_filter( 'pre_set_site_transient_update_plugins' ) );

		$GLOBALS['wopcart_direct_test_is_admin'] = true;

		do_action( 'init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Firing WordPress's own hook is how the suite drives this plugin's callbacks.

		$this->assertTrue( has_filter( 'pre_set_site_transient_update_plugins' ) );
		$this->assertTrue( has_filter( 'plugins_api' ) );
	}

	/**
	 * This plugin's own state is kept out of core's uninstall sweep.
	 *
	 * Core's uninstall.php deletes every option matching `wopcart_%`. An option
	 * named `wopcart_direct_…` would match, so uninstalling and reinstalling core
	 * would clear this plugin's record of its own licence.
	 */
	public function test_the_status_option_survives_cores_uninstall_wildcard(): void {
		$this->assertStringStartsNotWith( 'wopcart_', License::STATUS_OPTION );
		$this->assertStringStartsWith( 'wopcartdirect_', License::STATUS_OPTION );
	}

	/**
	 * The licence key itself is a setting, so it does live in core's options.
	 *
	 * That is the right trade — it gets core's form rendering, sanitising,
	 * capability check and secret storage — but it does mean core's uninstall takes
	 * it with the rest of `wopcart_gateway_secrets`, along with every gateway
	 * credential the merchant paid for. Narrowing that rule is core's to do, and is
	 * recorded as such; this test exists so the exposure is written down somewhere
	 * that fails if the key quietly moves.
	 */
	public function test_the_licence_key_is_a_core_setting(): void {
		$this->assertTrue( Options::is_secret( License::KEY_OPTION ) );
		$this->assertStringStartsNotWith( 'wopcart', License::KEY_OPTION, 'A setting key, not an option name.' );
	}
}
