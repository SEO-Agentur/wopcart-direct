<?php
/**
 * The screens and the policy text this plugin's gateways appear in.
 *
 * @package WopCartDirect
 */

declare(strict_types=1);

namespace WopCartDirect\Tests\Unit;

use WopCart\Admin\IntegrationStatus;
use WopCart\Admin\Integrations;
use WopCart\Admin\SystemInfo;
use WopCart\Privacy\Privacy;
use WopCartDirect\AdminSurfaces;
use WopCartDirect\License;

/**
 * Integration cards, webhook rows, privacy paragraphs and the checkout script.
 *
 * Each of these lists is assembled by core and ends in a hook, and each test
 * below drives core's own assembler rather than this plugin's callback, because
 * what matters is the list core ends up with.
 *
 * The recurring claim is "add only what is missing": core and this plugin carry
 * the paid gateways in parallel for one release cycle, so most of this already
 * exists, and appending unconditionally would give the merchant two of
 * everything — including two identical paragraphs in a published privacy policy.
 *
 * @covers \WopCartDirect\AdminSurfaces
 */
final class AdminSurfacesTest extends AddonTestCase {

	/**
	 * A card as core's cards() assembles one.
	 *
	 * @param string $key Card key.
	 *
	 * @return array<string,mixed>
	 */
	private function card( string $key ): array {
		return array(
			'key'   => $key,
			'group' => 'payments',
			'label' => ucfirst( $key ),
		);
	}

	/**
	 * The cards core's screen ends up rendering.
	 *
	 * @param array<int,array<string,mixed>> $built_in Cards core assembled.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function cards( array $built_in = array() ): array {
		return Integrations::filter_cards( $built_in, 2000 );
	}

	/**
	 * This plugin's own licence card is added, and core computes its status.
	 */
	public function test_the_licence_card_is_added(): void {
		$cards = $this->cards( array( $this->card( 'stripe' ) ) );
		$keys  = array_column( $cards, 'key' );

		$this->assertContains( AdminSurfaces::LICENSE_CARD, $keys );
		$this->assertContains( 'stripe', $keys, 'Core\'s own cards survive.' );

		$card = $cards[ array_search( AdminSurfaces::LICENSE_CARD, $keys, true ) ];

		$this->assertSame( 'payments', $card['group'] );
		$this->assertFalse( $card['configured'], 'No key saved yet.' );
		$this->assertFalse( $card['test'], 'Nothing to test without a key.' );
		$this->assertSame( IntegrationStatus::NOT_CONFIGURED, $card['status'] );
	}

	/**
	 * With a key saved the card offers a Test button and reports what it knows.
	 */
	public function test_the_licence_card_reflects_a_saved_key(): void {
		$this->store( \WopCart\Support\Options::SECRETS_OPTION, array( License::KEY_OPTION => 'WOP-DIRECT-1234' ) );
		$this->store(
			License::STATUS_OPTION,
			array(
				'success'    => true,
				'status'     => 'active',
				'expires_at' => '2027-03-01T00:00:00+00:00',
				'checked_at' => 1500,
			)
		);

		$keys = array_column( $this->cards(), 'key' );
		$card = $this->cards()[ array_search( AdminSurfaces::LICENSE_CARD, $keys, true ) ];

		$this->assertTrue( $card['configured'] );
		$this->assertTrue( $card['test'] );
		$this->assertSame( 1500, $card['last_success'] );
		$this->assertSame( 0, $card['last_error'] );
		$this->assertSame( '', $card['warning'], 'A valid licence needs no warning.' );
		$this->assertStringNotContainsString( 'WOP-DIRECT-1234', implode( ' ', $card['details'] ), 'The key is masked.' );
		$this->assertSame( '2027-03-01T00:00:00+00:00', $card['details']['Expires'] );
	}

	/**
	 * An expired licence marks the card, but never as a hard error.
	 *
	 * `hard_error` is core's "this is broken" flag. Nothing is broken: payments are
	 * still going through the merchant's own accounts. A card flagged as broken
	 * invites a merchant to start switching things off, which is how they would
	 * give themselves the platform fee back.
	 */
	public function test_an_expired_licence_is_not_a_hard_error(): void {
		$this->store( \WopCart\Support\Options::SECRETS_OPTION, array( License::KEY_OPTION => 'WOP-EXPIRED' ) );
		$this->store(
			License::STATUS_OPTION,
			array(
				'success'    => false,
				'status'     => 'expired',
				'error'      => 'expired',
				'message'    => 'This licence expired on 1 January 2026.',
				'checked_at' => 1500,
			)
		);

		$keys = array_column( $this->cards(), 'key' );
		$card = $this->cards()[ array_search( AdminSurfaces::LICENSE_CARD, $keys, true ) ];

		$this->assertFalse( $card['hard_error'] );
		$this->assertSame( 1500, $card['last_error'] );
		$this->assertStringContainsString( 'expired', $card['warning'] );
		$this->assertStringContainsString( 'keep working', $card['warning'] );
	}

	/**
	 * Core's plan card is retired, because the plan key is dropped outright.
	 */
	public function test_cores_plan_card_is_retired(): void {
		$keys = array_column( $this->cards( array( $this->card( 'stripe' ), $this->card( 'plan' ) ) ), 'key' );

		$this->assertNotContains( 'plan', $keys );
		$this->assertContains( AdminSurfaces::LICENSE_CARD, $keys, 'Replaced, not merely removed.' );
		$this->assertContains( 'stripe', $keys );
	}

	/**
	 * A gateway core already carded is not carded twice.
	 */
	public function test_a_gateway_core_already_carded_is_left_alone(): void {
		$keys = array_column( $this->cards( array( $this->card( 'paypal' ), $this->card( 'mollie' ) ) ), 'key' );

		$this->assertSame( 1, count( array_keys( $keys, 'paypal', true ) ) );
		$this->assertSame( 1, count( array_keys( $keys, 'mollie', true ) ) );
	}

	/**
	 * A gateway nobody carded gets one.
	 *
	 * 2Checkout is the live example rather than a hypothetical: its slug is
	 * '2checkout' while core's own card list keys it 'twocheckout', so core looks
	 * the gateway up under a name it never registers under and builds no card at
	 * all. This plugin supplies it under the slug the registry actually uses.
	 */
	public function test_a_gateway_nobody_carded_gets_one(): void {
		$this->store(
			\WopCart\Support\Options::OPTION,
			array(
				'gateways_enabled'     => array( '2checkout' ),
				'twocheckout_merchant' => 'MERCHANT-123456',
			)
		);

		$keys = array_column( $this->cards(), 'key' );
		$card = $this->cards()[ array_search( '2checkout', $keys, true ) ];

		$this->assertSame( '2Checkout', $card['label'] );
		$this->assertTrue( $card['configured'] );
		$this->assertSame( 'Yes', $card['details']['Enabled'] );
		$this->assertStringNotContainsString( 'MERCHANT-123456', implode( ' ', $card['details'] ), 'The credential is masked.' );
	}

	/**
	 * The Stripe own-keys card comes back, under core's own key.
	 *
	 * Core's "Stripe (your own keys)" card went with its own-credentials code, so
	 * the Payments section of a store running this plugin would otherwise hold one
	 * card — `connect` — describing the platform's connected account, which is
	 * exactly the account such a store is not using. The merchant would have no
	 * way of asking the screen whether their Stripe keys work.
	 */
	public function test_the_stripe_own_keys_card_is_added(): void {
		$cards = $this->cards( array( $this->card( 'connect' ) ) );
		$keys  = array_column( $cards, 'key' );
		$card  = $cards[ array_search( AdminSurfaces::STRIPE_CARD, $keys, true ) ];

		$this->assertSame( 'stripe', AdminSurfaces::STRIPE_CARD, 'Core\'s key, so the anchor and the Test dispatch are unchanged.' );
		$this->assertSame( 'Stripe (your own keys)', $card['label'] );
		$this->assertSame( 'payments', $card['group'] );
		$this->assertSame( 'https://docs.stripe.com/keys', $card['docs_url'] );
		$this->assertFalse( $card['configured'], 'No key saved yet.' );
		$this->assertFalse( $card['test'], 'Nothing to test without a key.' );
		$this->assertSame( IntegrationStatus::NOT_CONFIGURED, $card['status'] );
		$this->assertContains( 'connect', $keys, 'Core\'s platform card is left alone; it answers a different question.' );
	}

	/**
	 * With a key saved it reports the mode, masks the key and offers a Test button.
	 *
	 * The mode matters more than it looks: a card reading "Live" over a test key
	 * would have a merchant hunting for why no money arrives.
	 */
	public function test_the_stripe_card_reflects_a_saved_key(): void {
		$this->store(
			\WopCart\Support\Options::SECRETS_OPTION,
			array(
				'stripe_test_secret'    => 'sk_test_51HveryLongSecret',
				'stripe_webhook_secret' => 'whsec_abcdefgh',
			)
		);

		$card = $this->stripe_card();

		$this->assertTrue( $card['configured'] );
		$this->assertTrue( $card['test'] );
		$this->assertSame( 'Test', $card['details']['Mode'], 'Core\'s settings default to test mode.' );
		$this->assertStringNotContainsString( 'veryLongSecret', $card['details']['Secret key'], 'The key is masked.' );
		$this->assertStringNotContainsString( 'abcdefgh', $card['details']['Webhook secret'] );
		$this->assertArrayHasKey( 'Last webhook received', $card['details'] );
	}

	/**
	 * A core that still builds its own Stripe card is not given a second one.
	 *
	 * This is the live case for a merchant on a pre-1.4.0 core, which this plugin
	 * still supports: two cards under one key would both render, both claim the
	 * anchor and both claim a Test button.
	 */
	public function test_cores_own_stripe_card_is_not_doubled(): void {
		$keys = array_column( $this->cards( array( $this->card( 'stripe' ) ) ), 'key' );

		$this->assertSame( 1, count( array_keys( $keys, 'stripe', true ) ) );
	}

	/**
	 * Pressing Test with no Stripe key refuses locally, without a call going out.
	 */
	public function test_testing_stripe_without_a_key_refuses_locally(): void {
		$result = Integrations::addon_test( AdminSurfaces::STRIPE_CARD );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wopcart_stripe_unconfigured', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['wopcart_direct_test_requests'], 'Nothing was asked of Stripe.' );

		// And no failure is recorded against the card: nothing failed, the merchant
		// simply has not entered a key, which `configured` already says.
		$this->assertSame( 0, $this->stripe_card()['last_error'] );
	}

	/**
	 * A good key is confirmed by an authenticated read, and the card remembers it.
	 *
	 * The balance endpoint rather than anything that moves money, which is the rule
	 * every test on this screen follows. Recording the result is this plugin's job
	 * now: core's `run_test()` lost its `stripe` entry along with the card, so
	 * without this the merchant would be told the key works and the card would go on
	 * saying nothing had ever answered.
	 */
	public function test_testing_stripe_reads_the_balance_and_records_it(): void {
		$this->store( \WopCart\Support\Options::SECRETS_OPTION, array( 'stripe_test_secret' => 'sk_test_good' ) );
		$GLOBALS['wopcart_direct_test_responses'][] = array( 'body' => array( 'object' => 'balance' ) );

		$result  = Integrations::addon_test( AdminSurfaces::STRIPE_CARD );
		$request = $GLOBALS['wopcart_direct_test_requests'][0];

		$this->assertSame( 'Stripe accepted the key.', $result );
		$this->assertSame( 'GET', $request['method'], 'A read, never a write.' );
		$this->assertSame( 'https://api.stripe.com/v1/balance', $request['url'] );
		$this->assertSame( 'Bearer sk_test_good', $request['headers']['Authorization'] );

		$card = $this->stripe_card();

		$this->assertGreaterThan( 0, $card['last_success'], 'The press is remembered, so the card can read Connected.' );
		$this->assertSame( 0, $card['last_error'] );
	}

	/**
	 * A refused key reports Stripe's own status code, and marks the card.
	 *
	 * The status code is the whole value of the test: "it did not work" sends a
	 * merchant round their whole Stripe dashboard, while "HTTP 401" tells them they
	 * pasted the wrong key.
	 */
	public function test_a_refused_stripe_key_reports_the_status_code(): void {
		$this->store( \WopCart\Support\Options::SECRETS_OPTION, array( 'stripe_test_secret' => 'sk_test_wrong' ) );
		$GLOBALS['wopcart_direct_test_responses'][] = array(
			'body' => array( 'error' => array( 'message' => 'Invalid API Key provided' ) ),
			'code' => 401,
		);

		$result = Integrations::addon_test( AdminSurfaces::STRIPE_CARD );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wopcart_stripe_test', $result->get_error_code() );
		$this->assertStringContainsString( '401', $result->get_error_message() );

		$card = $this->stripe_card();

		$this->assertGreaterThan( 0, $card['last_error'] );
		$this->assertSame( IntegrationStatus::ERROR, $card['status'] );
		$this->assertStringContainsString( '401', $card['message'], 'The card repeats what the merchant was just shown.' );
	}

	/**
	 * The Stripe card as core's screen ends up rendering it.
	 *
	 * @return array<string,mixed>
	 */
	private function stripe_card(): array {
		$cards = $this->cards();
		$keys  = array_column( $cards, 'key' );

		return $cards[ array_search( AdminSurfaces::STRIPE_CARD, $keys, true ) ];
	}

	/**
	 * Pressing Test on the licence card with no key says so, rather than calling out.
	 */
	public function test_testing_the_licence_card_without_a_key_refuses_locally(): void {
		$result = Integrations::addon_test( AdminSurfaces::LICENSE_CARD );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wopcart_direct_no_license', $result->get_error_code() );
	}

	/**
	 * A card this plugin did not supply is not answered for.
	 */
	public function test_another_cards_test_is_left_to_its_owner(): void {
		$result = Integrations::addon_test( 'zapier' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wopcart_no_test', $result->get_error_code(), 'Core\'s own "there is no test" answer.' );
	}

	/**
	 * The webhook rows core is missing are added, keyed by the gateway slug.
	 */
	public function test_the_missing_webhook_rows_are_added(): void {
		$checks = SystemInfo::webhook_checks( false );

		$this->assertSame( array( 'twocheckout_api_key', '2Checkout INS secret word' ), $checks['2checkout'] );
		$this->assertArrayHasKey( 'stripe', $checks, 'Core\'s own rows are kept.' );
		$this->assertArrayHasKey( 'paypal', $checks );
		$this->assertArrayHasKey( 'authorizenet', $checks );
	}

	/**
	 * Core's wording for a row it already has wins.
	 *
	 * Two rows for the same gateway cannot happen — they share a key — so the only
	 * question is whose label shows, and changing a label a merchant has been
	 * reading for a year gains nothing.
	 */
	public function test_cores_own_row_is_not_relabelled(): void {
		$checks = SystemInfo::webhook_checks( false );

		$this->assertSame( 'PayPal webhook ID', $checks['paypal'][1] );
		$this->assertSame( 'paypal_webhook_id', $checks['paypal'][0] );
	}

	/**
	 * Mollie gets no webhook row, deliberately.
	 *
	 * Mollie webhooks are unsigned: it posts a payment id and expects the receiver
	 * to fetch the payment itself, so authenticity comes from that authenticated
	 * call and there is no secret for a merchant to set. A row reading "Mollie
	 * webhook secret: Missing" would send them looking for something that does not
	 * exist.
	 */
	public function test_mollie_gets_no_webhook_row(): void {
		$this->assertArrayNotHasKey( 'mollie', SystemInfo::webhook_checks( false ) );
	}

	/**
	 * A gateway that is switched on gets a privacy paragraph naming the company.
	 */
	public function test_an_enabled_gateway_is_disclosed(): void {
		$this->store( \WopCart\Support\Options::OPTION, array( 'gateways_enabled' => array( 'paypal', '2checkout' ) ) );

		$content = Privacy::filter_third_party_content( '<h2>Who else sees this data</h2>' );

		$this->assertStringContainsString( 'PayPal', $content );
		$this->assertStringContainsString( '2Checkout', $content );
		$this->assertStringNotContainsString( 'Authorize.net', $content, 'A gateway the store does not use is not disclosed.' );
		$this->assertStringNotContainsString( 'Mollie', $content );
		$this->assertStringStartsWith( '<h2>Who else sees this data</h2>', $content, 'Appended, never replaced.' );
	}

	/**
	 * A provider core already named is not named twice.
	 *
	 * Core still carries its own Mollie paragraph while it still carries the
	 * gateway. Matching on the company name rather than on a phrase from the
	 * sentence is deliberate: the sentence gets translated, the company name does
	 * not.
	 */
	public function test_a_provider_core_already_named_is_not_repeated(): void {
		$this->store( \WopCart\Support\Options::OPTION, array( 'gateways_enabled' => array( 'mollie' ) ) );

		$content = Privacy::filter_third_party_content( '<p>… sends the order to Mollie B.V. (Amsterdam).</p>' );

		$this->assertSame( 1, substr_count( $content, 'Mollie B.V.' ) );
	}

	/**
	 * Nothing is disclosed for a gateway that is switched off.
	 *
	 * A policy listing services the store does not use is worse than none: it
	 * trains the reader to ignore it.
	 */
	public function test_nothing_is_disclosed_for_a_disabled_gateway(): void {
		$this->store( \WopCart\Support\Options::OPTION, array( 'gateways_enabled' => array( 'stripe' ) ) );

		$content = Privacy::filter_third_party_content( '<h2>Who else sees this data</h2>' );

		$this->assertSame( '<h2>Who else sees this data</h2>', $content );
	}

	/**
	 * Each paragraph is escaped, because core runs the result through
	 * wp_kses_post() and wpautop() rather than escaping it again.
	 */
	public function test_the_paragraphs_are_escaped(): void {
		$this->store( \WopCart\Support\Options::OPTION, array( 'gateways_enabled' => array( 'authorizenet', 'mollie' ) ) );

		$content = Privacy::filter_third_party_content( '' );

		$this->assertStringNotContainsString( "'", $content, 'An apostrophe would arrive raw if esc_html() were skipped.' );
		$this->assertStringContainsString( '&#039;', $content );
	}
}
