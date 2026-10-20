<?php
/**
 * Registration of this plugin's gateways with core.
 *
 * @package WopCartDirect
 */

declare(strict_types=1);

namespace WopCartDirect\Gateways;

use WopCart\Model\Order;
use WopCartDirect\Plugin;

/**
 * Hands core's gateway registry the four gateways that ship here.
 *
 * Core's `Gateways\Registry` instantiates whatever class names come back from
 * `wopcart_gateway_classes`, keeps the ones that implement its
 * `GatewayInterface`, and keys them by their own `slug()`. That is the entire
 * contract: these classes extend core's `AbstractGateway` and so satisfy it
 * without anything further.
 *
 * ## Why this is not merged into Plugin
 *
 * Registering the classes is not the whole of it: core asks several questions
 * about the gateways themselves, and the answers belong next to the registration
 * they go with rather than in a bootstrap that otherwise knows nothing about
 * gateways.
 *
 * - Mollie needs three admin hooks of its own — its settings section, its key
 *   handling and its "key refused" notice. Core wired them for *its*
 *   `Gateways\Mollie`, so the wiring has to be repeated here against this
 *   plugin's class.
 * - `wopcart_invoice_gateway_reports_no_payment` decides whether a customer gets
 *   the "Start over" button at once or waits out the attempt window. Mollie is
 *   the one gateway that can end that wait early.
 * - `wopcart_form_gateways` decides which gateways a payment form may offer. That
 *   list is three-quarters this plugin's gateways, so it is this plugin's answer.
 *
 * Both plugins shipped the four gateways on purpose for one release cycle, which
 * is over: core 1.4.0 deleted its copies. This plugin still supports a 1.3.x
 * core, though, so both sets of hooks can still be registered on one site —
 * which is harmless, and `duplicate_in_core()` explains why.
 */
final class Registry {

	/**
	 * Gateway classes this plugin contributes, in the order they are offered.
	 *
	 * @var string[]
	 */
	private const GATEWAYS = array(
		PayPal::class,
		AuthorizeNet::class,
		TwoCheckout::class,
		Mollie::class,
	);

	/**
	 * Slugs of the gateways here that a payment form may use. See form_gateways().
	 *
	 * Authorize.net is absent on purpose: it collects card details on the page
	 * itself through Accept.js, and a form has no card fields.
	 *
	 * @var string[]
	 */
	private const FORM_GATEWAYS = array( 'paypal', 'mollie', '2checkout' );

	/**
	 * Register the seams. Called at file scope; see Plugin::register().
	 */
	public static function hooks(): void {
		add_filter( 'wopcart_gateway_classes', array( self::class, 'gateway_classes' ) );

		// Mollie keeps its settings section and key handling in the gateway class
		// rather than in a settings file, because the two write-only key fields and
		// their prefix validation are Mollie's own rules. Core wires these three for
		// its own copy; this is the same wiring against this plugin's copy.
		//
		// Through wrappers rather than straight at Mollie's methods, so that the
		// "is this plugin allowed to contribute anything?" guard lives in this
		// plugin's own code. Mollie.php is a copy of core's file and is meant to
		// stay comparable with it; a guard spliced into it would be one more
		// difference to explain every time the two are diffed.
		add_filter( 'wopcart_settings_sections', array( self::class, 'mollie_settings_sections' ), 10, 2 );
		add_filter( 'wopcart_save_settings', array( self::class, 'mollie_save_settings' ), 10, 2 );
		add_action( 'admin_notices', array( self::class, 'mollie_auth_notice' ) );

		// Core's invoice payment screen asks whether a gateway can confirm that an
		// attempt was not paid. Core answers false for everything it still ships;
		// Mollie is the one gateway that can say so, and it is here.
		add_filter( 'wopcart_invoice_gateway_reports_no_payment', array( self::class, 'mollie_reports_no_payment' ), 10, 2 );

		// Which gateways a payment form may hand a payment to. Core's own default
		// still names three of this plugin's gateways; see form_gateways().
		add_filter( 'wopcart_form_gateways', array( self::class, 'form_gateways' ) );
	}

	/**
	 * Whether Mollie reports that an invoice payment attempt will never be paid.
	 *
	 * What this decides for the customer: core keeps a "Start over" button behind
	 * an attempt window, so that a second payment can never begin while a first is
	 * still in flight. Waiting it out is the safe answer and core's default. Mollie
	 * is the one gateway here that can end the wait early, because a Mollie payment
	 * reaches a terminal `failed`, `canceled` or `expired` state that no later
	 * money can come out of — so a customer who cancelled at their bank gets the
	 * button at once instead of staring at a page for the rest of the window.
	 *
	 * Answered with this plugin's own Mollie rather than through core's registry,
	 * which keeps `new Mollie()` honest: `AbstractGateway` declares no constructor
	 * and `reports_no_payment()` reads everything it needs from the order and the
	 * saved settings, so the instance carries no state the registry's would have.
	 * Going through the registry would also mean that on a pre-1.4.0 core, where
	 * core's own copy may be the one it kept, this plugin would answer false for an
	 * order it can perfectly well read.
	 *
	 * `$unpaid` short-circuits: on that older core, core's own Mollie has already
	 * answered, and asking again would spend a second API call on a question that
	 * has been answered yes. Only the slug is consulted otherwise — not whether
	 * Mollie is currently available or enabled — because the order in hand was
	 * already paid for through Mollie, whatever the settings say today.
	 *
	 * @param bool  $unpaid What core, or an earlier consumer, already answered.
	 * @param mixed $order  The payment order. Core passes a `\WopCart\Model\Order`.
	 *
	 * @return bool
	 */
	public static function mollie_reports_no_payment( bool $unpaid, mixed $order = null ): bool {
		if ( $unpaid || ! Plugin::active() || ! $order instanceof Order || 'mollie' !== $order->gateway() ) {
			return $unpaid;
		}

		return ( new Mollie() )->reports_no_payment( $order );
	}

	/**
	 * Gateways a payment form may hand a payment to.
	 *
	 * Core's `Forms\Submission::hosted_gateways()` admits only the gateways whose
	 * `process()` needs nothing but the order — a form has no card fields on it, so
	 * a gateway that collects card data in the browser cannot be offered there.
	 * That rules out two of the six gateways the two plugins have between them:
	 * core's Apple Pay and this plugin's Authorize.net, which draws its own fields
	 * through Accept.js. What is left is core's Stripe plus PayPal, Mollie and
	 * 2Checkout, all three of which redirect.
	 *
	 * Appended rather than replacing the list, so that a gateway core adds to its
	 * own default later is not silently dropped by this plugin being installed.
	 *
	 * The one thing removed is `twocheckout`. Core's default has carried that
	 * string since before the gateway reported a slug, and the slug is `2checkout`
	 * — so core's entry matches nothing in the registry and 2Checkout has never
	 * actually been offered on a form. It is dropped rather than left in place
	 * because it is now this plugin's gateway and this plugin knows its name; a
	 * dead entry beside the live one would read like one of the two being a
	 * mistake, which is exactly what it is.
	 *
	 * @param array<int,string> $slugs Gateway slugs core and other consumers supplied.
	 *
	 * @return array<int,string>
	 */
	public static function form_gateways( array $slugs ): array {
		if ( ! Plugin::active() ) {
			return $slugs;
		}

		$slugs = array_values( array_diff( array_map( 'strval', $slugs ), array( 'twocheckout' ) ) );

		return array_values( array_unique( array_merge( $slugs, self::FORM_GATEWAYS ) ) );
	}

	/**
	 * Mollie's settings section, once this plugin is allowed to contribute one.
	 *
	 * @param array<int,array<string,mixed>> $sections Sections core assembled.
	 * @param string                         $tab      Tab key being rendered.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function mollie_settings_sections( array $sections, string $tab ): array {
		if ( ! Plugin::active() ) {
			return $sections;
		}

		return Mollie::settings_sections( $sections, $tab );
	}

	/**
	 * Mollie's handling of newly entered API keys.
	 *
	 * Guarded like the rest, and consistently with it: while this plugin is inert
	 * `mollie_settings_sections()` contributes no section, so the write-only key
	 * fields are never rendered and there is nothing for this to move or strip.
	 * Guarding one without the other is what would leave a submitted key stranded.
	 *
	 * @param array<string,mixed> $values Sanitised values about to be stored.
	 * @param array<string,mixed> $input  Raw submitted form.
	 *
	 * @return array<string,mixed>
	 */
	public static function mollie_save_settings( array $values, array $input = array() ): array {
		if ( ! Plugin::active() ) {
			return $values;
		}

		return Mollie::save_settings( $values, $input );
	}

	/**
	 * Mollie's "your API key was refused" notice.
	 */
	public static function mollie_auth_notice(): void {
		if ( ! Plugin::active() ) {
			return;
		}

		Mollie::render_auth_notice();
	}

	/**
	 * Add this plugin's gateways to the registry.
	 *
	 * Appended rather than prepended: core's own Stripe and Apple Pay stay at the
	 * head of the list, so the gateway a store sees selected by default at checkout
	 * does not change the day this plugin is installed.
	 *
	 * Core's registry de-duplicates by slug — it keys the instances by `slug()` —
	 * so while core still ships its own copies of these four classes, this
	 * plugin's copy of each one replaces core's in that map rather than appearing
	 * beside it. Which of the two identical classes wins is not worth controlling;
	 * see duplicate_in_core().
	 *
	 * @param array<int,string> $classes Fully qualified class names core assembled.
	 *
	 * @return array<int,string>
	 */
	public static function gateway_classes( array $classes ): array {
		if ( ! Plugin::active() ) {
			return $classes;
		}

		return array_merge( $classes, self::GATEWAYS );
	}

	/**
	 * Whether core still carries its own copy of these gateways.
	 *
	 * Both plugins shipped the four gateways on purpose for one release cycle: the
	 * paid code was only deleted from core once this plugin had been published and
	 * a release had passed, so that no paying store was ever briefly left without
	 * a gateway it was using. Core 1.4.0 deleted its copies, so against a current
	 * core this answers false and the overlap is over.
	 *
	 * **Kept rather than retired**, and so is everything above that depends on the
	 * overlap. This plugin's `MIN_CORE` is 1.3.0, and that is the version a
	 * merchant may still be sitting on for weeks — WordPress does not force a
	 * plugin update, and the add-on updates on its own licence check rather than
	 * alongside core. A copy of this plugin running against core 1.3.x really does
	 * meet core's own PayPal, Authorize.net, 2Checkout and Mollie classes, and the
	 * de-duplication the methods above perform is live, not decorative. The day
	 * `MIN_CORE` rises to 1.4.0 is the day this and `Settings::replace()`,
	 * `Settings::CORE_PLAN_KEY` and `AdminSurfaces::CORE_PLAN_CARD` can all go.
	 *
	 * Nothing acts on this one directly. It exists so the suite can state which
	 * side of that line the checkout it runs against is on, and so that a core
	 * that brought its copies back fails a test rather than quietly deciding by
	 * filter order which of two identical classes a store charges through. The two
	 * copies are byte-identical in behaviour apart from the stripped plan checks,
	 * they are keyed by the same slug, and they read the same settings — so
	 * whichever instance core's registry keeps behaves the same.
	 *
	 * @return bool
	 */
	public static function duplicate_in_core(): bool {
		return class_exists( '\WopCart\Gateways\Mollie' );
	}
}
