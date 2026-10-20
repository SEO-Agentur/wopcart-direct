<?php
/**
 * The credential sections this plugin puts on core's Payments tab.
 *
 * @package WopCartDirect
 */

declare(strict_types=1);

namespace WopCartDirect\Tests\Unit;

use WopCart\Support\Options;
use WopCartDirect\License;
use WopCartDirect\Settings;

/**
 * What the Payments tab ends up holding.
 *
 * The sections are core's field definitions moved, not redesigned, and the tests
 * below are mostly about that: the option keys, types and `secret` flags have to
 * be identical, or a store that saved a key before installing this plugin finds
 * it gone afterwards.
 *
 * @covers \WopCartDirect\Settings
 */
final class SettingsSectionsTest extends AddonTestCase {

	/**
	 * The sections the Payments tab ends up with, as core would render them.
	 *
	 * @param array<int,array<string,mixed>> $built_in What core assembled first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function payments( array $built_in = array() ): array {
		return (array) apply_filters( 'wopcart_settings_sections', $built_in, 'gateways' );
	}

	/**
	 * Every field across the Payments tab, keyed by option name.
	 *
	 * @param array<int,array<string,mixed>> $sections Sections.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function fields( array $sections ): array {
		$fields = array();

		foreach ( $sections as $section ) {
			foreach ( (array) ( $section['fields'] ?? array() ) as $key => $field ) {
				$fields[ (string) $key ] = $field;
			}
		}

		return $fields;
	}

	/**
	 * All five credential sections are contributed, licence first.
	 */
	public function test_the_credential_sections_are_contributed(): void {
		$titles = array_column( $this->payments(), 'title' );

		$this->assertSame(
			array( 'WopCart Direct licence', 'Stripe', 'PayPal', 'Authorize.net', '2Checkout', 'Mollie' ),
			$titles,
			'The licence comes first: it is the one field a merchant has to find on the day they buy.'
		);
	}

	/**
	 * They are on the Payments tab and nowhere else.
	 *
	 * Core renders twelve tabs, and a credential section repeated on each of them
	 * would put a live secret key on eleven screens that have no business with one.
	 */
	public function test_nothing_is_contributed_to_another_tab(): void {
		foreach ( array( 'general', 'emails', 'licensing', 'advanced' ) as $tab ) {
			$this->assertSame( array(), (array) apply_filters( 'wopcart_settings_sections', array(), $tab ), $tab );
		}
	}

	/**
	 * Every credential core collects is still collected, under the same option key.
	 *
	 * The list is core's, read from its own Options defaults rather than retyped,
	 * so a field core adds and this plugin forgets is caught here rather than by a
	 * merchant whose key has nowhere to go.
	 */
	public function test_every_credential_field_survived_the_move(): void {
		$expected = array(
			'stripe_test_publishable',
			'stripe_test_secret',
			'stripe_live_publishable',
			'stripe_live_secret',
			'stripe_webhook_secret',
			'stripe_statement_desc',
			'paypal_test_client_id',
			'paypal_test_secret',
			'paypal_live_client_id',
			'paypal_live_secret',
			'paypal_webhook_id',
			'authnet_login_id',
			'authnet_transaction_key',
			'authnet_public_client_key',
			'authnet_signature_key',
			'twocheckout_merchant',
			'twocheckout_secret',
			'twocheckout_api_key',
		);

		$fields = $this->fields( $this->payments() );

		foreach ( $expected as $key ) {
			$this->assertArrayHasKey( $key, $fields, $key . ' is no longer collected anywhere.' );
			$this->assertArrayHasKey(
				$key,
				Options::defaults(),
				$key . ' is not a setting core knows a default for.'
			);
		}
	}

	/**
	 * Every secret field is flagged as one, and every flagged field is routed.
	 *
	 * Two halves of the same requirement. `secret => true` on a password field is
	 * what stops core printing the value back into the page; registering the key
	 * through `wopcart_secret_setting_keys` is what keeps it out of the autoloaded
	 * option. A field with one and not the other is the bug worth testing for.
	 */
	public function test_secret_fields_are_flagged_and_routed(): void {
		$secret = 0;

		foreach ( $this->fields( $this->payments() ) as $key => $field ) {
			if ( 'password' !== ( $field['type'] ?? '' ) ) {
				continue;
			}

			// Mollie's two key inputs are deliberately neither. They are write-only
			// by being a different key from the stored one — nothing ever reads
			// mollie_live_api_key_input, so there is no value for core to print back
			// and nothing to keep out of the autoloaded option. The keys they write
			// to, mollie_{mode}_api_key, are secrets and are covered in
			// CredentialSeamsTest.
			if ( str_ends_with( $key, '_api_key_input' ) ) {
				$this->assertArrayNotHasKey( $key, Options::defaults(), $key . ' is a helper field, not a stored setting.' );

				continue;
			}

			++$secret;

			$this->assertNotEmpty( $field['secret'] ?? false, $key . ' is a password field but is not flagged secret.' );
			$this->assertTrue( Options::is_secret( $key ), $key . ' is flagged secret but is not routed to the secrets option.' );
		}

		$this->assertGreaterThan( 8, $secret, 'Sanity: the password fields were found at all.' );
	}

	/**
	 * Publishable keys are deliberately *not* secret.
	 *
	 * A publishable key is printed into a page by design, and marking it secret
	 * would stop core showing the merchant the value they had saved.
	 */
	public function test_publishable_keys_are_not_secret(): void {
		$fields = $this->fields( $this->payments() );

		foreach ( array( 'stripe_test_publishable', 'stripe_live_publishable', 'authnet_public_client_key' ) as $key ) {
			$this->assertSame( 'text', $fields[ $key ]['type'], $key );
			$this->assertFalse( Options::is_secret( $key ), $key . ' is public by design.' );
		}
	}

	/**
	 * The licence field is write-only, like every other credential.
	 */
	public function test_the_licence_field_is_a_secret_password(): void {
		$field = $this->fields( $this->payments() )[ License::KEY_OPTION ];

		$this->assertSame( 'password', $field['type'] );
		$this->assertTrue( (bool) $field['secret'] );
		$this->assertStringContainsString( 'activates this site', (string) $field['description'] );
	}

	/**
	 * The licence section says what the key does *not* do.
	 *
	 * A merchant reading "licence key" directly above their payment credentials
	 * will otherwise assume the credentials stop working without it — and may well
	 * go looking for a fee they are not paying.
	 */
	public function test_the_licence_section_says_the_gateways_are_unaffected(): void {
		$description = (string) $this->payments()[0]['description'];

		$this->assertStringContainsString( 'go on working', $description );
		$this->assertStringContainsString( 'only updates stop', $description );
	}

	/**
	 * **Core's duplicate sections are taken back out.**
	 *
	 * Both plugins carry the paid gateways for one release cycle on purpose, and
	 * core's paid-plan gate runs before this filter — so with this plugin installed
	 * its answer is yes and its own four credential sections survive. Appending
	 * beside them would give the merchant two "Stripe" headings and two inputs
	 * named `stripe_live_secret` in one form.
	 */
	public function test_cores_own_credential_sections_are_replaced(): void {
		$built_in = array(
			array(
				'title'  => 'Active gateways',
				'fields' => array( 'test_mode' => array( 'type' => 'checkbox' ) ),
			),
			array(
				'title'              => 'Stripe',
				'requires_paid_plan' => true,
				'fields'             => array( 'stripe_live_secret' => array( 'type' => 'password' ) ),
			),
			array(
				'title'  => 'Apple Pay',
				'fields' => array( 'applepay_enabled' => array( 'type' => 'checkbox' ) ),
			),
			array(
				'title'  => 'Using your own payment accounts',
				'fields' => array( 'plan_key' => array( 'type' => 'password' ) ),
			),
		);

		$sections = $this->payments( $built_in );
		$titles   = array_column( $sections, 'title' );
		$keys     = array_keys( $this->fields( $sections ) );

		$this->assertSame( 1, count( array_keys( $titles, 'Stripe', true ) ), 'Exactly one Stripe section.' );
		$this->assertSame( 1, count( array_keys( $keys, 'stripe_live_secret', true ) ), 'Exactly one secret key input.' );

		$this->assertContains( 'Active gateways', $titles, 'An unrelated section on the same tab is untouched.' );
		$this->assertContains( 'Apple Pay', $titles, 'Apple Pay is a switch, not a credential, and stays with core.' );
		$this->assertContains( 'test_mode', $keys );
		$this->assertContains( 'applepay_enabled', $keys );
	}

	/**
	 * Core's plan key field goes with them.
	 *
	 * The plan key is dropped outright rather than carried over, so a field
	 * collecting it describes a credential that no longer does anything — and a
	 * merchant pasting their Direct licence into it would get no error and no
	 * effect.
	 */
	public function test_cores_plan_key_field_is_retired(): void {
		$built_in = array(
			array(
				'title'  => 'Using your own payment accounts',
				'fields' => array( 'plan_key' => array( 'type' => 'password' ) ),
			),
		);

		$keys = array_keys( $this->fields( $this->payments( $built_in ) ) );

		$this->assertNotContains( 'plan_key', $keys );
		$this->assertContains( License::KEY_OPTION, $keys, 'Replaced, not merely removed.' );
	}

	/**
	 * The list of owned fields is derived from the sections, not maintained beside
	 * them — so a field added to a section cannot be forgotten and left duplicated.
	 */
	public function test_owned_fields_are_derived_from_the_sections(): void {
		$owned  = Settings::owned_fields();
		$fields = array_keys( $this->fields( Settings::sections() ) );

		foreach ( $fields as $key ) {
			$this->assertContains( $key, $owned, $key . ' is rendered but not claimed.' );
		}

		$this->assertContains( 'plan_key', $owned, 'Core\'s plan key is claimed so that it is removed.' );
	}
}
