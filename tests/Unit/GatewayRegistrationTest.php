<?php
/**
 * The gateways reaching core's registry, and staying there.
 *
 * @package WopCartDirect
 */

declare(strict_types=1);

namespace WopCartDirect\Tests\Unit;

use WopCart\Forms\Submission;
use WopCart\Gateways\ApplePay;
use WopCart\Gateways\Connect;
use WopCart\Gateways\GatewayInterface;
use WopCart\Gateways\Stripe;
use WopCart\Model\Order;
use WopCartDirect\Gateways\AuthorizeNet;
use WopCartDirect\Gateways\Mollie;
use WopCartDirect\Gateways\PayPal;
use WopCartDirect\Gateways\Registry;
use WopCartDirect\Gateways\TwoCheckout;
use WopCartDirect\License;

/**
 * Registration through `wopcart_gateway_classes`, and the one thing a lapsed
 * licence must not change.
 *
 * Core's registry is driven here for real rather than imitated: these tests build
 * a `\WopCart\Gateways\Registry`, let it apply the filter this plugin registered,
 * and look at what it ended up holding. That is the only way to catch the
 * failures that matter — a class name core's `class_exists()` cannot resolve, or a
 * gateway that does not satisfy its `GatewayInterface` and is therefore dropped
 * without a word.
 *
 * @covers \WopCartDirect\Gateways\Registry
 */
final class GatewayRegistrationTest extends AddonTestCase {

	/**
	 * The slugs this plugin exists to add.
	 */
	/**
	 * Note '2checkout', not 'twocheckout'. The class is TwoCheckout and the
	 * credential options are named twocheckout_*, but the slug the gateway reports
	 * — and therefore the key the registry, the checkout, the webhook router and
	 * the `gateways_enabled` setting all use — is '2checkout'. Core's own
	 * Integrations screen has this wrong, which is why 2Checkout has no card there.
	 */
	private const SLUGS = array( 'paypal', 'authorizenet', '2checkout', 'mollie' );

	/**
	 * Core's registry, with this plugin's filter applied.
	 *
	 * @return array<string,GatewayInterface>
	 */
	private function core_registry(): array {
		return ( new \WopCart\Gateways\Registry() )->all();
	}

	/**
	 * The filter appends the four classes and keeps what was already there.
	 */
	public function test_the_filter_appends_the_four_gateways(): void {
		$classes = Registry::gateway_classes( array( Stripe::class, ApplePay::class ) );

		$this->assertSame(
			array( Stripe::class, ApplePay::class, PayPal::class, AuthorizeNet::class, TwoCheckout::class, Mollie::class ),
			$classes,
			'Core\'s own gateways stay at the head, so the default gateway at checkout does not change on install.'
		);
	}

	/**
	 * Core's registry really instantiates them, and really keeps them.
	 *
	 * Keyed by their own slug, which is what the checkout, the webhook router and
	 * the return URL handler all look them up by.
	 */
	public function test_core_registry_holds_this_plugins_gateways(): void {
		$gateways = $this->core_registry();

		foreach ( self::SLUGS as $slug ) {
			$this->assertArrayHasKey( $slug, $gateways, $slug . ' did not survive registration.' );
			$this->assertInstanceOf( GatewayInterface::class, $gateways[ $slug ], $slug );
		}

		$this->assertArrayHasKey( 'stripe', $gateways, 'Core\'s own gateways are untouched.' );
		$this->assertArrayHasKey( 'applepay', $gateways );
	}

	/**
	 * Each class extends core's base, which is what makes the rest of core — the
	 * checkout's cart_error() backstop, Registry::capable(), the refund flow —
	 * willing to talk to it.
	 */
	public function test_each_gateway_extends_cores_base(): void {
		foreach ( array( PayPal::class, AuthorizeNet::class, TwoCheckout::class, Mollie::class ) as $class_name ) {
			$this->assertTrue(
				is_subclass_of( $class_name, \WopCart\Gateways\AbstractGateway::class ),
				$class_name . ' must extend core\'s AbstractGateway, not a copy of it.'
			);
		}
	}

	/**
	 * Mollie's three admin hooks are wired by this plugin, against this plugin's
	 * copy of the class.
	 *
	 * Core wires them for its own `Gateways\Mollie` in `Gateways/Registry.php`. That
	 * wiring cannot serve this plugin's copy, and without them Mollie has no
	 * settings section at all, no way to accept a pasted key, and no notice when
	 * Mollie refuses one — a gateway that looks installed and silently cannot be
	 * configured.
	 */
	public function test_mollies_admin_hooks_are_wired(): void {
		$this->assertTrue( $this->is_hooked( 'wopcart_settings_sections', array( Registry::class, 'mollie_settings_sections' ) ) );
		$this->assertTrue( $this->is_hooked( 'wopcart_save_settings', array( Registry::class, 'mollie_save_settings' ) ) );
		$this->assertTrue( $this->is_hooked( 'admin_notices', array( Registry::class, 'mollie_auth_notice' ) ) );
	}

	/**
	 * And they reach Mollie's own methods when they fire.
	 */
	public function test_mollies_section_is_contributed_when_the_hook_fires(): void {
		$sections = apply_filters( 'wopcart_settings_sections', array(), 'gateways' );
		$titles   = array_column( $sections, 'title' );

		$this->assertContains( 'Mollie', $titles );

		$this->assertSame(
			array(),
			apply_filters( 'wopcart_settings_sections', array(), 'emails' ),
			'Mollie belongs on the Payments tab and nowhere else.'
		);
	}

	/**
	 * Mollie's key handler is reached, and still enforces the key prefix.
	 *
	 * The behaviour that survived the move: a live key pasted into the test field
	 * is refused rather than quietly used, which would otherwise take real money
	 * through a sandbox checkout.
	 */
	public function test_mollies_key_handler_is_reached_and_still_checks_the_prefix(): void {
		$saved = apply_filters(
			'wopcart_save_settings',
			array( 'mollie_test_api_key_input' => 'test_abcdefghijklmnopqrstuvwxyz' ),
			array( 'tab' => 'gateways' )
		);

		$this->assertSame( 'test_abcdefghijklmnopqrstuvwxyz', $saved['mollie_test_api_key'] );
		$this->assertArrayNotHasKey( 'mollie_test_api_key_input', $saved, 'The write-only helper field is dropped.' );

		$refused = apply_filters(
			'wopcart_save_settings',
			array( 'mollie_test_api_key_input' => 'live_abcdefghijklmnopqrstuvwxyz' ),
			array( 'tab' => 'gateways' )
		);

		$this->assertArrayNotHasKey( 'mollie_test_api_key', $refused, 'A live key in the test field is not stored.' );
	}

	/**
	 * The plan checks really are gone from this copy of Mollie.
	 *
	 * Core's copy gates `is_available()`, `process()` and its settings section on
	 * the plan three separate times. Here there is nothing to gate: the method core
	 * used no longer exists, and the section is contributed without asking.
	 */
	public function test_the_plan_checks_are_gone_from_mollie(): void {
		$this->assertFalse(
			method_exists( Mollie::class, 'plan_allows' ),
			'plan_allows() answered a question this plugin does not ask.'
		);

		foreach ( apply_filters( 'wopcart_settings_sections', array(), 'gateways' ) as $section ) {
			$this->assertArrayNotHasKey(
				'requires_paid_plan',
				$section,
				'A section flagged for the paid plan would be a second entitlement answer able to disagree with the first.'
			);
		}
	}

	/**
	 * Payment forms are offered exactly the gateways that can actually serve one.
	 *
	 * Core's `Forms\Submission` admits only gateways whose `process()` needs
	 * nothing but the order, because a payment form has no card fields on it. The
	 * default it ships is now `['stripe']`, and the other three are this plugin's
	 * to add. Core's assembler is driven here rather than the callback, because
	 * what matters is the list a form ends up with.
	 */
	public function test_payment_forms_are_offered_every_redirect_gateway(): void {
		$this->assertSame(
			array( 'stripe', 'paypal', 'mollie', '2checkout' ),
			array_values( Submission::hosted_gateways() )
		);
	}

	/**
	 * Every slug on that list is a gateway that really exists, under its real slug.
	 *
	 * The failure this pins is not hypothetical: core's own default carried
	 * `twocheckout` for years while the gateway reports `2checkout`, so core looked
	 * the gateway up under a name nothing registers under and 2Checkout was never
	 * actually offered on a form — silently, because an unmatched slug is simply
	 * skipped. This plugin owns the gateway now, so it supplies the real slug and
	 * takes the dead one out.
	 */
	public function test_no_form_gateway_is_a_slug_nothing_registers_under(): void {
		$offered = Submission::hosted_gateways();

		$this->assertNotContains( 'twocheckout', $offered, 'A slug no gateway reports; 2Checkout answers to "2checkout".' );

		foreach ( $offered as $slug ) {
			$this->assertArrayHasKey( $slug, $this->core_registry(), $slug . ' is offered on forms but no gateway registers under it.' );
		}
	}

	/**
	 * The two gateways that collect card details on the page are left off.
	 *
	 * Both are registered and both work at a checkout; neither can work on a form,
	 * which renders no card fields and loads no Accept.js. Offering them would give
	 * the customer a payment button that cannot complete.
	 */
	public function test_the_on_page_card_gateways_are_not_offered_on_forms(): void {
		$offered = Submission::hosted_gateways();

		$this->assertNotContains( 'authorizenet', $offered, 'Authorize.net draws its own fields through Accept.js.' );
		$this->assertNotContains( 'applepay', $offered );

		// But Authorize.net really is registered, so this is a deliberate omission
		// rather than a gateway that failed to load.
		$this->assertArrayHasKey( 'authorizenet', $this->core_registry() );
	}

	/**
	 * Core's own entries survive, so a gateway core adds later is not dropped.
	 *
	 * Called directly with the default core is being trimmed to, and then with a
	 * hypothetical fifth entry of core's, because the filter being an append is the
	 * whole reason this plugin can be installed without taking something away.
	 */
	public function test_cores_own_form_gateways_are_kept(): void {
		$this->assertSame(
			array( 'stripe', 'paypal', 'mollie', '2checkout' ),
			Registry::form_gateways( array( 'stripe' ) ),
			'The default core is trimmed to.'
		);

		$this->assertSame(
			array( 'stripe', 'klarna', 'paypal', 'mollie', '2checkout' ),
			Registry::form_gateways( array( 'stripe', 'klarna' ) ),
			'A gateway core adds to its own default is kept, at the head where core put it.'
		);

		$this->assertSame(
			array( 'stripe', 'paypal', 'mollie', '2checkout' ),
			Registry::form_gateways( array( 'stripe', 'paypal', 'mollie', 'twocheckout' ) ),
			'A pre-1.4.0 core\'s default is de-duplicated and its dead 2Checkout slug corrected.'
		);
	}

	/**
	 * Mollie answers whether an invoice payment attempt is already dead.
	 *
	 * Core holds the "Start over" button behind an attempt window so that a second
	 * payment can never begin while a first is in flight, and its own answer is now
	 * always no. Mollie is the one gateway that can end that wait early, because a
	 * Mollie payment reaches a terminal failed, cancelled or expired state no later
	 * money can come out of.
	 *
	 * What is checked here is the gating, which is this plugin's code: the question
	 * is only put to Mollie for a Mollie order, an answer already given is left
	 * alone, and an order with no Mollie payment recorded is refused before an API
	 * call is spent on it. The affirmative answer itself is Mollie's own
	 * `reports_no_payment()`, which needs a live API, and is covered on a real site.
	 */
	public function test_only_a_mollie_order_is_put_to_mollie(): void {
		$this->assertFalse(
			$this->reports_no_payment( false, $this->order( 'stripe' ) ),
			'A Stripe order is core\'s business; its attempts are read from Stripe itself.'
		);

		$this->assertFalse(
			$this->reports_no_payment( false, $this->order( 'mollie' ) ),
			'No Mollie payment id recorded, so there is nothing to ask about.'
		);

		$this->assertTrue(
			$this->reports_no_payment( true, $this->order( 'mollie' ) ),
			'An answer already given stands; asking again would spend a second API call on it.'
		);

		$this->assertFalse(
			$this->reports_no_payment( false, null ),
			'Nothing is assumed about an argument core did not pass.'
		);
	}

	/**
	 * The filter is registered for two arguments, which is the whole of it.
	 *
	 * Registered for one, WordPress hands the callback no order, every order looks
	 * like "not mine", and this plugin silently answers no to every question —
	 * leaving a customer whose Mollie payment definitely failed watching out the
	 * attempt window with no button. Nothing else in the suite would notice,
	 * because the safe answer and the broken answer are the same word.
	 */
	public function test_the_invoice_filter_is_registered_for_the_order_argument(): void {
		$registered = array_values(
			array_filter(
				wopcart_direct_test_callbacks( 'wopcart_invoice_gateway_reports_no_payment' ),
				static fn( array $hook ): bool => $hook['callback'] === array( Registry::class, 'mollie_reports_no_payment' )
			)
		);

		$this->assertCount( 1, $registered );
		$this->assertSame( 2, $registered[0]['args'] );
	}

	/**
	 * Put the question through core's filter, as core's invoice screen does.
	 *
	 * @param bool       $unpaid What core answered.
	 * @param Order|null $order  The payment order.
	 *
	 * @return bool
	 */
	private function reports_no_payment( bool $unpaid, ?Order $order ): bool {
		return (bool) apply_filters( 'wopcart_invoice_gateway_reports_no_payment', $unpaid, $order );
	}

	/**
	 * A minimal order row paid through one gateway.
	 *
	 * @param string $gateway Gateway slug.
	 *
	 * @return Order
	 */
	private function order( string $gateway ): Order {
		return new Order(
			array(
				'id'      => 11,
				'gateway' => $gateway,
				'total'   => 5000,
				'meta'    => '{}',
			)
		);
	}

	/**
	 * **An expired licence takes nothing away.**
	 *
	 * The decision this plugin is shaped around. A merchant whose licence lapsed
	 * still owns their Stripe and PayPal accounts, and the failure to avoid is not
	 * a broken checkout — it is a working one that has quietly started costing them
	 * 2% again, for an expiry they may not have noticed.
	 *
	 * So with an expired licence recorded: the gateways are still registered, core
	 * still answers that the store uses its own credentials, no gateway is filtered
	 * out as free-plan-ineligible, and the platform fee on a €100 order is zero.
	 *
	 * The "not filtered out" half is now asserted as an absence. Core used to keep
	 * a free-plan allow-list in `Connect::allowed_gateways()`, and this test used
	 * to pin that it came back empty for a store with this plugin installed. The
	 * method went with the rest of the paid-plan machinery, so the list cannot be
	 * non-empty — but the reasoning still has to be held somewhere, because a core
	 * that reintroduced an allow-list would reintroduce exactly the failure above:
	 * a licensed merchant's own PayPal quietly dropped from their own checkout.
	 */
	public function test_an_expired_licence_leaves_everything_in_place(): void {
		$this->store(
			License::STATUS_OPTION,
			array(
				'success'    => false,
				'status'     => 'expired',
				'error'      => 'expired',
				'expires_at' => '2026-01-01T00:00:00+00:00',
				'checked_at' => 1760000000,
			)
		);

		$gateways = $this->core_registry();

		foreach ( self::SLUGS as $slug ) {
			$this->assertArrayHasKey( $slug, $gateways, $slug . ' was taken away by an expired licence.' );
		}

		$this->assertTrue( Connect::uses_own_credentials(), 'The store still transacts with its own keys.' );
		$this->assertFalse(
			method_exists( Connect::class, 'allowed_gateways' ),
			'Core has a gateway allow-list again. Check that it cannot restrict a store charging with its own credentials, then pin the empty list here as this test used to.'
		);
		$this->assertFalse( Connect::is_active(), 'Payments do not go back through the platform.' );
		$this->assertSame( 0, Connect::fee_amount( 10000 ), 'No platform fee is reintroduced.' );

		// And the merchant is told, in terms that do not suggest their shop is down.
		$notice = License::problem( License::status(), 'WOP-EXPIRED-KEY' );

		$this->assertStringContainsString( 'expired', $notice );
		$this->assertStringContainsString( 'keep working', $notice );
		$this->assertStringContainsString( 'updates', $notice );
	}

	/**
	 * Nor does having no licence key at all.
	 *
	 * A merchant who has not pasted their key in yet, or who cleared it, is in the
	 * same position as one whose licence lapsed. Entitlement is installation.
	 */
	public function test_no_licence_key_leaves_everything_in_place(): void {
		$this->assertSame( '', License::key() );

		$this->assertSame( self::SLUGS, array_values( array_intersect( self::SLUGS, array_keys( $this->core_registry() ) ) ) );
		$this->assertTrue( Connect::uses_own_credentials() );
		$this->assertSame( 0, Connect::fee_amount( 10000 ) );
	}

	/**
	 * Core no longer carries its own copies, and this plugin is the only source.
	 *
	 * The inverse of what this test asserted while both plugins shipped the four
	 * gateways on purpose. That overlap existed so that no paying store was ever
	 * briefly left without a gateway it was using, and it ended when core's paid
	 * code was deleted; the tripwire that said "both exist" has been turned round
	 * rather than deleted, because the fact worth pinning is the same one from the
	 * other side. Every one of the four gateways a store can still reach is now
	 * this plugin's, so a test that only checked that *a* `paypal` gateway was
	 * registered would pass on a core that had somehow kept its own.
	 *
	 * `Registry::duplicate_in_core()` is kept rather than retired, because a
	 * merchant may be running a pre-1.4.0 core for a while yet — see its docblock.
	 * On this checkout of core it answers false, which is the only answer the
	 * released add-on should ever see once core 1.4.0 is out.
	 */
	public function test_core_no_longer_carries_its_own_copies(): void {
		$this->assertFalse(
			Registry::duplicate_in_core(),
			'Core is carrying its own gateways again. The overlap is tolerable — see duplicate_in_core() — but say so here deliberately rather than by accident.'
		);

		foreach ( array( 'PayPal', 'AuthorizeNet', 'TwoCheckout', 'Mollie' ) as $gateway ) {
			$this->assertFalse(
				class_exists( '\WopCart\Gateways\\' . $gateway ),
				'\WopCart\Gateways\\' . $gateway . ' is back. Core\'s registry keys both copies by the same slug and keeps one of them, so which gateway a store charges through would depend on filter order.'
			);
		}

		// And the registry's four entries are all this plugin's, not core's.
		$gateways = $this->core_registry();

		foreach ( self::SLUGS as $slug ) {
			$this->assertStringStartsWith(
				'WopCartDirect\\Gateways\\',
				$gateways[ $slug ]::class,
				$slug . ' is registered by something other than this plugin.'
			);
		}
	}
}
