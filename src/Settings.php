<?php
/**
 * The credential settings sections this plugin contributes.
 *
 * @package WopCartDirect
 */

declare(strict_types=1);

namespace WopCartDirect;

/**
 * Puts the merchant's own gateway credential fields on core's Payments tab.
 *
 * The field definitions are core's, moved rather than redesigned: a store that
 * saved a key before installing this plugin must find it still there afterwards,
 * which only holds if the option keys, types and `secret` flags are identical.
 * Core flags these four sections `requires_paid_plan` and strips them on the free
 * plan; here the flag is dropped, because a store with this plugin installed
 * answers core's entitlement question with yes in any case (see
 * `Credentials::uses_own_credentials()`), so a second gate could only ever disagree with
 * the first.
 *
 * ## Why core's own copies are taken back out
 *
 * Core and this plugin deliberately both carry the paid gateways for one release
 * cycle. Core's `Settings::sections()` applies its paid-plan gate *before* this
 * filter runs, so with this plugin installed its entitlement answer is yes and
 * its four credential sections survive — and then these four would be appended
 * beside them. The merchant would get two "Stripe" headings and two inputs
 * named `stripe_live_secret` in one form. So `replace()` drops any section that
 * holds a field this plugin owns before adding its own.
 *
 * That is also why it is done by field key rather than by section title: the
 * title is a translated string, while the field key is the thing that would
 * actually collide. When core's copies are deleted there is simply nothing left
 * to drop and this code keeps working unchanged.
 */
final class Settings {

	/**
	 * The tab these sections belong on.
	 *
	 * Core calls it `gateways` and labels it "Payments".
	 */
	private const TAB = 'gateways';

	/**
	 * Core's plan key field.
	 *
	 * Written as a literal rather than read from `\WopCart\Platform\Plan`, because
	 * this plugin must not depend on that class: the plan key is dropped outright
	 * in a later core release, and a reference to it here would turn that deletion
	 * into a fatal error on every admin page.
	 *
	 * It is removed from the form rather than left alongside this plugin's own
	 * licence key. Two password fields both labelled as the key that unlocks the
	 * merchant's gateways, only one of which does anything, is worse than a missing
	 * field: a merchant pasting their Direct licence into the plan key field would
	 * get no error and no effect.
	 *
	 * Core 1.4.0 dropped the field, so on a current core there is nothing here to
	 * remove. Kept anyway, because `MIN_CORE` is 1.3.0 and a merchant on a 1.3.x
	 * core still gets the plan key field rendered — which is precisely the merchant
	 * most likely to paste the wrong key into it, having just bought this add-on.
	 * See `Gateways\Registry::duplicate_in_core()` for when all of this can go.
	 */
	private const CORE_PLAN_KEY = 'plan_key';

	/**
	 * Register the seam. Called at file scope; see Plugin::register().
	 */
	public static function hooks(): void {
		add_filter( 'wopcart_settings_sections', array( self::class, 'settings_sections' ), 10, 2 );
	}

	/**
	 * Contribute the credential sections to the Payments tab.
	 *
	 * @param array<int,array<string,mixed>> $sections Sections core assembled.
	 * @param string                         $tab      Tab key being rendered.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function settings_sections( array $sections, string $tab ): array {
		if ( self::TAB !== $tab || ! Plugin::active() ) {
			return $sections;
		}

		return array_merge( self::replace( $sections ), self::sections() );
	}

	/**
	 * Drop the sections this plugin is about to supply itself.
	 *
	 * A section goes if any of its fields is one this plugin owns, so a section
	 * core later adds a field to is still recognised, and an unrelated section that
	 * merely sits on the same tab — "Active gateways", "Apple Pay" — is untouched.
	 *
	 * **A no-op against core 1.4.0 and newer**, which carries no credential
	 * sections of its own any more, and kept for the same reason
	 * `Gateways\Registry::duplicate_in_core()` is: `MIN_CORE` is 1.3.0, so a
	 * merchant can be running a core that still assembles all four. On that core
	 * this is what stops them seeing two "Stripe" headings and two inputs named
	 * `stripe_live_secret` in one form — a form in which whichever input they
	 * filled in last would win, with nothing on screen saying so. It costs one
	 * `array_intersect()` per settings render and can be deleted together with
	 * CORE_PLAN_KEY the day MIN_CORE reaches 1.4.0.
	 *
	 * @param array<int,array<string,mixed>> $sections Sections core assembled.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function replace( array $sections ): array {
		$owned = self::owned_fields();
		$kept  = array();

		foreach ( $sections as $section ) {
			$fields = array_keys( (array) ( $section['fields'] ?? array() ) );

			if ( array() === array_intersect( $fields, $owned ) ) {
				$kept[] = $section;
			}
		}

		return $kept;
	}

	/**
	 * Every field key this plugin's own sections render.
	 *
	 * Derived from the sections themselves rather than listed twice, so a field
	 * added below cannot be forgotten here and end up duplicated.
	 *
	 * @return array<int,string>
	 */
	public static function owned_fields(): array {
		$keys = array( self::CORE_PLAN_KEY );

		foreach ( self::sections() as $section ) {
			$keys = array_merge( $keys, array_keys( (array) $section['fields'] ) );
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * The sections, in the order they are shown.
	 *
	 * Mollie is absent on purpose: it builds its own section in
	 * `Gateways\Mollie::settings_sections()`, because its two write-only key fields
	 * and their prefix validation are rules only that class knows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function sections(): array {
		return array(
			self::licence_section(),
			array(
				'title'       => __( 'Stripe', 'wopcart-direct' ),
				'description' => __( 'Your own Stripe keys. Payments settle directly in your Stripe account and WopCart adds no fee of its own to any transaction.', 'wopcart-direct' ),
				'fields'      => array(
					'stripe_test_publishable' => array(
						'type'  => 'text',
						'label' => __( 'Test publishable key', 'wopcart-direct' ),
					),
					'stripe_test_secret'      => array(
						'type'   => 'password',
						'secret' => true,
						'label'  => __( 'Test secret key', 'wopcart-direct' ),
					),
					'stripe_live_publishable' => array(
						'type'  => 'text',
						'label' => __( 'Live publishable key', 'wopcart-direct' ),
					),
					'stripe_live_secret'      => array(
						'type'   => 'password',
						'secret' => true,
						'label'  => __( 'Live secret key', 'wopcart-direct' ),
					),
					'stripe_webhook_secret'   => array(
						'type'        => 'password',
						'secret'      => true,
						'label'       => __( 'Webhook signing secret', 'wopcart-direct' ),
						'description' => __( 'Required. Without it, webhooks are rejected and renewals will not record.', 'wopcart-direct' ),
					),
					'stripe_statement_desc'   => array(
						'type'  => 'text',
						'label' => __( 'Statement descriptor', 'wopcart-direct' ),
					),
				),
			),
			array(
				'title'  => __( 'PayPal', 'wopcart-direct' ),
				'fields' => array(
					'paypal_test_client_id' => array(
						'type'  => 'text',
						'label' => __( 'Sandbox client ID', 'wopcart-direct' ),
					),
					'paypal_test_secret'    => array(
						'type'   => 'password',
						'secret' => true,
						'label'  => __( 'Sandbox secret', 'wopcart-direct' ),
					),
					'paypal_live_client_id' => array(
						'type'  => 'text',
						'label' => __( 'Live client ID', 'wopcart-direct' ),
					),
					'paypal_live_secret'    => array(
						'type'   => 'password',
						'secret' => true,
						'label'  => __( 'Live secret', 'wopcart-direct' ),
					),
					'paypal_webhook_id'     => array(
						'type'        => 'text',
						'label'       => __( 'Webhook ID', 'wopcart-direct' ),
						'description' => __( 'Required. PayPal events cannot be verified without it.', 'wopcart-direct' ),
					),
				),
			),
			array(
				'title'  => __( 'Authorize.net', 'wopcart-direct' ),
				'fields' => array(
					'authnet_login_id'          => array(
						'type'  => 'text',
						'label' => __( 'API login ID', 'wopcart-direct' ),
					),
					'authnet_transaction_key'   => array(
						'type'   => 'password',
						'secret' => true,
						'label'  => __( 'Transaction key', 'wopcart-direct' ),
					),
					'authnet_public_client_key' => array(
						'type'        => 'text',
						'label'       => __( 'Public client key', 'wopcart-direct' ),
						'description' => __( 'Used by Accept.js so card details never touch this server.', 'wopcart-direct' ),
					),
					'authnet_signature_key'     => array(
						'type'   => 'password',
						'secret' => true,
						'label'  => __( 'Signature key', 'wopcart-direct' ),
					),
				),
			),
			array(
				'title'  => __( '2Checkout', 'wopcart-direct' ),
				'fields' => array(
					'twocheckout_merchant' => array(
						'type'  => 'text',
						'label' => __( 'Merchant code', 'wopcart-direct' ),
					),
					'twocheckout_secret'   => array(
						'type'   => 'password',
						'secret' => true,
						'label'  => __( 'Buy link secret word', 'wopcart-direct' ),
					),
					'twocheckout_api_key'  => array(
						'type'   => 'password',
						'secret' => true,
						'label'  => __( 'INS secret word', 'wopcart-direct' ),
					),
				),
			),
		);
	}

	/**
	 * The licence key section.
	 *
	 * First on the tab rather than last, because it is the one field a merchant has
	 * to find on the day they buy. Its description says plainly what the key does
	 * and does not do: a merchant reading "licence key" above their payment
	 * credentials will otherwise assume the credentials stop working without it.
	 *
	 * The field is a `secret` password, which is what makes core keep it out of the
	 * autoloaded option and never print it back into the form. A blank submission
	 * therefore means "leave the saved key alone", and the checkbox core renders
	 * alongside (`…__clear`) is how it is removed.
	 *
	 * @return array<string,mixed>
	 */
	private static function licence_section(): array {
		return array(
			'title'       => __( 'WopCart Direct licence', 'wopcart-direct' ),
			'description' => __( 'Your licence key from wopcart.com. It keeps this plugin updated and counts the production sites on your plan. Your payment gateways go on working whatever the licence says — if it lapses, only updates stop.', 'wopcart-direct' ),
			'fields'      => array(
				License::KEY_OPTION => array(
					'type'        => 'password',
					'secret'      => true,
					'label'       => __( 'Licence key', 'wopcart-direct' ),
					'description' => __( 'Paste the key from the receipt or from your account on wopcart.com. Saving it activates this site against your licence; local and staging sites activate without using one of your places.', 'wopcart-direct' ),
				),
			),
		);
	}
}
