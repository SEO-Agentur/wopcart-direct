<?php
/**
 * The credential seams this plugin answers, and where the values are stored.
 *
 * @package WopCartDirect
 */

declare(strict_types=1);

namespace WopCartDirect\Tests\Unit;

use ReflectionMethod;
use WopCart\Gateways\ApplePay;
use WopCart\Gateways\Connect;
use WopCart\Gateways\Stripe;
use WopCart\Support\Options;
use WopCartDirect\Credentials;
use WopCartDirect\License;

/**
 * Entitlement, the two Stripe keys, and the secrets option.
 *
 * Answered through core's own classes wherever possible rather than by calling
 * this plugin's methods directly: what has to be true is not "Credentials returns
 * a key" but "core's Stripe gateway charges with the merchant's key", and only
 * the second of those would notice a filter registered for the wrong number of
 * arguments or a mode that was quietly ignored.
 *
 * @covers \WopCartDirect\Credentials
 */
final class CredentialSeamsTest extends AddonTestCase {

	/**
	 * Call a protected gateway method, as core's own seam tests do.
	 *
	 * @param Stripe $gateway Gateway.
	 * @param string $method  Method name.
	 *
	 * @return mixed
	 */
	private function call( Stripe $gateway, string $method ): mixed {
		return ( new ReflectionMethod( $gateway, $method ) )->invoke( $gateway );
	}

	/**
	 * Save the store's own Stripe keys, as a merchant pasting them in would.
	 *
	 * Written through core's Options::update() rather than straight into an
	 * option, so the routing this plugin asks for is exercised on the way in as
	 * well as on the way out.
	 *
	 * @param array<string,string> $values Setting keys and values.
	 */
	private function save( array $values ): void {
		Options::update( $values );
		Options::flush();
	}

	/**
	 * Installed is entitled, and core hears it.
	 */
	public function test_the_store_transacts_with_its_own_credentials(): void {
		$this->assertTrue( Credentials::uses_own_credentials( false ) );
		$this->assertTrue( Connect::uses_own_credentials(), 'Core asks the neutral question and this plugin answers it.' );
		$this->assertTrue( Connect::is_paid_plan(), 'Core\'s deprecated accessor agrees.' );
	}

	/**
	 * Entitlement does not depend on a licence key being present or valid.
	 *
	 * The same claim GatewayRegistrationTest makes about the gateways, made here
	 * about the credential seam itself: this is the answer that decides whether a
	 * payment carries a 2% platform fee.
	 */
	public function test_entitlement_ignores_the_licence(): void {
		$this->assertSame( '', License::key(), 'No key saved.' );
		$this->assertTrue( Credentials::uses_own_credentials( false ) );

		$this->store(
			License::STATUS_OPTION,
			array(
				'success' => false,
				'status'  => 'expired',
				'error'   => 'expired',
			)
		);

		$this->assertTrue( Credentials::uses_own_credentials( false ), 'An expired licence is not an entitlement question.' );
	}

	/**
	 * An earlier consumer's yes is never turned into a no.
	 */
	public function test_an_existing_yes_is_preserved(): void {
		$this->assertTrue( Credentials::uses_own_credentials( true ) );
	}

	/**
	 * Core's Stripe gateway charges with the merchant's saved key.
	 */
	public function test_stripe_charges_with_the_saved_key(): void {
		$this->save(
			array(
				'test_mode'               => true,
				'stripe_test_secret'      => 'sk_test_saved',
				'stripe_test_publishable' => 'pk_test_saved',
			)
		);

		$stripe = new Stripe();

		$this->assertSame( 'sk_test_saved', $this->call( $stripe, 'secret_key' ) );
		$this->assertSame( 'pk_test_saved', $stripe->publishable_key() );
		$this->assertTrue( $this->call( $stripe, 'has_credentials' ) );
	}

	/**
	 * The mode is honoured, which is not cosmetic: a live key handed to a sandbox
	 * checkout would take real money.
	 */
	public function test_live_mode_resolves_the_live_keys(): void {
		$this->save(
			array(
				'test_mode'               => false,
				'stripe_test_secret'      => 'sk_test_saved',
				'stripe_live_secret'      => 'sk_live_saved',
				'stripe_live_publishable' => 'pk_live_saved',
			)
		);

		$stripe = new Stripe();

		$this->assertSame( 'sk_live_saved', $this->call( $stripe, 'secret_key' ) );
		$this->assertSame( 'pk_live_saved', $stripe->publishable_key() );
	}

	/**
	 * An unrecognised mode resolves the sandbox key, never the live one.
	 *
	 * Core only ever passes 'test' or 'live'. The asymmetry is deliberate anyway:
	 * getting this wrong towards the sandbox produces a failed test payment, and
	 * wrong towards live produces a real charge against a real card.
	 */
	public function test_an_unknown_mode_never_resolves_the_live_key(): void {
		$this->save(
			array(
				'stripe_test_secret' => 'sk_test_saved',
				'stripe_live_secret' => 'sk_live_saved',
			)
		);

		$this->assertSame( 'sk_test_saved', Credentials::stripe_secret_key( '', 'LIVE' ) );
		$this->assertSame( 'sk_test_saved', Credentials::stripe_secret_key( '', '' ) );
		$this->assertSame( 'sk_live_saved', Credentials::stripe_secret_key( '', 'live' ) );
	}

	/**
	 * The filters are registered for two arguments.
	 *
	 * Without the arity, core's mode argument never arrives and every store
	 * charges with its test key — including in live mode, where the payment simply
	 * fails. Asserted through core, which is the only place the arity is visible.
	 */
	public function test_the_mode_argument_arrives(): void {
		$this->save(
			array(
				'test_mode'          => false,
				'stripe_test_secret' => 'sk_test_saved',
				'stripe_live_secret' => 'sk_live_saved',
			)
		);

		$this->assertSame(
			'sk_live_saved',
			$this->call( new Stripe(), 'secret_key' ),
			'A filter registered for one argument would hand back the test key here.'
		);
	}

	/**
	 * A key somebody else already supplied is left alone.
	 */
	public function test_another_consumers_key_wins(): void {
		$this->save( array( 'stripe_test_secret' => 'sk_test_saved' ) );

		$this->assertSame( 'sk_test_elsewhere', Credentials::stripe_secret_key( 'sk_test_elsewhere', 'test' ) );
		$this->assertSame( 'pk_test_elsewhere', Credentials::stripe_publishable_key( 'pk_test_elsewhere', 'test' ) );
	}

	/**
	 * Nothing saved means no key, not a key made up.
	 *
	 * Core reads an empty answer as "no key from me" and refuses the payment with
	 * its own unconfigured error, which is the message that sends the merchant to
	 * the settings screen. Answering with anything non-empty would instead produce
	 * a failure at Stripe.
	 */
	public function test_nothing_saved_resolves_to_no_key(): void {
		$stripe = new Stripe();

		$this->assertSame( '', $this->call( $stripe, 'secret_key' ) );
		$this->assertFalse( $this->call( $stripe, 'has_credentials' ) );

		$refusal = ( new ReflectionMethod( $stripe, 'api' ) )->invoke( $stripe, 'GET', 'payment_intents/pi_test' );

		$this->assertInstanceOf( \WP_Error::class, $refusal );
		$this->assertSame( 'wopcart_stripe_unconfigured', $refusal->get_error_code() );
	}

	/**
	 * Apple Pay rides on the same seam, and still needs both halves of the pair.
	 */
	public function test_apple_pay_inherits_the_seam(): void {
		$this->save( array( 'stripe_test_secret' => 'sk_test_saved' ) );

		$wallet = new ApplePay();

		$this->assertFalse( $this->call( $wallet, 'has_credentials' ), 'A secret alone draws no wallet sheet.' );

		$this->save( array( 'stripe_test_publishable' => 'pk_test_saved' ) );

		$this->assertTrue( $this->call( new ApplePay(), 'has_credentials' ) );
	}

	/**
	 * Every credential is treated as a secret by core.
	 */
	public function test_every_credential_is_a_secret_key(): void {
		foreach ( Credentials::SECRET_KEYS as $key ) {
			$this->assertTrue( Options::is_secret( $key ), $key . ' must not live in the autoloaded option.' );
		}

		$this->assertTrue( Options::is_secret( License::KEY_OPTION ), 'The licence key is a bearer credential for our own store.' );
	}

	/**
	 * **A saved credential lands in the non-autoloaded secrets option.**
	 *
	 * The reason the filter has to be registered at file scope. Written through
	 * core's own Options::update() and then read back out of the two raw options,
	 * because that split is the whole point and a value in the wrong one reads back
	 * as an empty string — indistinguishable from an unconfigured gateway.
	 */
	public function test_credentials_are_written_to_the_secrets_option(): void {
		$this->save(
			array(
				'store_name'          => 'Shop',
				'stripe_live_secret'  => 'sk_live_saved',
				'mollie_live_api_key' => 'live_abcdefghijklmnopqrstuvwxyz',
				License::KEY_OPTION   => 'WOP-DIRECT-1',
			)
		);

		$settings = get_option( Options::OPTION, array() );
		$secrets  = get_option( Options::SECRETS_OPTION, array() );

		$this->assertSame( 'Shop', $settings['store_name'], 'An ordinary setting still goes in the autoloaded option.' );

		foreach ( array( 'stripe_live_secret', 'mollie_live_api_key', License::KEY_OPTION ) as $key ) {
			$this->assertArrayNotHasKey( $key, $settings, $key . ' must not be in the autoloaded option.' );
			$this->assertArrayHasKey( $key, $secrets, $key . ' must be in the secrets option.' );
		}

		$this->assertFalse( $this->autoload_flag( Options::SECRETS_OPTION ), 'The secrets option is never autoloaded.' );

		// And it reads back out again, which is what the gateways actually do.
		$this->assertSame( 'sk_live_saved', Options::get( 'stripe_live_secret', '' ) );
		$this->assertSame( 'WOP-DIRECT-1', License::key() );
	}

	/**
	 * A value an older version left in the autoloaded option is migrated, not lost.
	 *
	 * Core's migration is driven by the same filtered list, so a key this plugin
	 * adds is moved across by it. A store that saved credentials before installing
	 * this plugin must find them where this plugin looks.
	 */
	public function test_a_legacy_value_is_migrated_out_of_the_autoloaded_option(): void {
		$plan = Options::secrets_migration(
			array(
				'store_name'        => 'Shop',
				License::KEY_OPTION => 'WOP-OLD',
			),
			false
		);

		$this->assertIsArray( $plan );
		$this->assertSame( array( 'store_name' => 'Shop' ), $plan['settings'] );
		$this->assertSame( array( License::KEY_OPTION => 'WOP-OLD' ), $plan['secrets'] );
	}
}
