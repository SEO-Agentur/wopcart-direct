<?php
/**
 * Where this plugin's gateways appear outside the settings form.
 *
 * @package WopCartDirect
 */

declare(strict_types=1);

namespace WopCartDirect;

use WopCart\Admin\IntegrationMonitor;
use WopCart\Admin\IntegrationStatus;
use WopCart\Admin\Integrations;
use WopCart\Gateways\Connect;
use WopCart\Support\Options;
use WopCartDirect\Gateways\AuthorizeNet;

/**
 * Integration cards, status-screen webhook rows, privacy text and checkout scripts.
 *
 * A gateway that ships in a separate plugin still has to turn up in the four
 * places a merchant looks for it, all of which core assembles:
 *
 * - **Integrations** — one screen answering "is my store talking to everything
 *   it should be?". A second Integrations page under a second menu would be the
 *   wrong answer.
 * - **Status** — the "webhook secret set?" rows. A configured gateway with no
 *   webhook secret takes payments but silently never records a renewal, which is
 *   the single most useful thing that screen says.
 * - **The suggested privacy policy** — a legal obligation the store owner
 *   carries. A store paying through a provider core no longer knows about would
 *   publish a policy failing to name the company its customers' addresses go to.
 * - **The checkout's scripts** — Authorize.net draws its card fields in the
 *   browser, so without its script on the page it cannot take a payment at all.
 *
 * ## Adding only what is missing
 *
 * Core and this plugin carry the paid gateways in parallel for one release
 * cycle, so for now core still produces most of this itself. Every method here
 * therefore adds only what is not already present, rather than appending
 * unconditionally. That is not merely tidiness: two cards with the same key
 * would both render and both claim a Test button, and two identical privacy
 * paragraphs in a published policy look like a drafting error to the one reader
 * who matters.
 *
 * It also means none of this has to be revisited when core's copies are deleted.
 * The gap simply widens and these methods fill more of it.
 */
final class AdminSurfaces {

	/**
	 * Core's integration card for the plan key, which this plugin retires.
	 *
	 * The plan key is dropped outright rather than carried over — there are no
	 * existing customers to migrate — so a card reporting its state describes a
	 * credential that no longer does anything. Named as a literal for the same
	 * reason `Settings::CORE_PLAN_KEY` is: nothing here may depend on
	 * `\WopCart\Platform\Plan` surviving. It no longer does: core 1.4.0 deleted
	 * that class along with the card, so this constant now matches nothing.
	 *
	 * Kept all the same, because `MIN_CORE` is 1.3.0 and a merchant on a 1.3.x core
	 * is handed a "WopCart plan" card whose `hard_error` flag goes red the moment
	 * the platform stops answering for a plan key the add-on has made irrelevant.
	 * See `Gateways\Registry::duplicate_in_core()` for when all of this can go.
	 */
	private const CORE_PLAN_CARD = 'plan';

	/**
	 * This plugin's own card, which replaces it.
	 */
	public const LICENSE_CARD = 'direct';

	/**
	 * Core's key for the Stripe own-keys card, which this plugin now supplies.
	 *
	 * Core's own string rather than a new one, deliberately. The key is the screen
	 * anchor, the identifier `handle_test()` dispatches on, and what a 1.3.x core
	 * builds its own Stripe card under — so reusing it means the card is replaced
	 * on an old core instead of doubled, and a bookmark or a support reply pointing
	 * at `#wopcart-integration-stripe` still lands on it.
	 */
	public const STRIPE_CARD = 'stripe';

	/**
	 * Gateways this plugin ships: slug, label, the credential a card masks, docs.
	 *
	 * @var array<string,array{0:string,1:string,2:string}>
	 */
	private const GATEWAYS = array(
		'paypal'       => array( 'PayPal', 'paypal_%s_client_id', 'https://developer.paypal.com/api/rest/' ),
		'authorizenet' => array( 'Authorize.net', 'authnet_login_id', 'https://developer.authorize.net/' ),
		'2checkout'    => array( '2Checkout', 'twocheckout_merchant', 'https://verifone.cloud/docs/2checkout' ),
		'mollie'       => array( 'Mollie', 'mollie_%s_api_key', 'https://docs.mollie.com/' ),
	);

	/**
	 * Webhook secrets the status screen should check, for the gateways that have one.
	 *
	 * Mollie is absent deliberately, not forgotten. Mollie webhooks are unsigned:
	 * it posts only a payment id and expects the receiver to fetch the payment
	 * itself, so authenticity comes from that authenticated call and there is no
	 * secret for a merchant to set. A row reading "Mollie webhook secret: Missing"
	 * would send them looking for something that does not exist.
	 *
	 * @var array<string,string>
	 */
	private const WEBHOOK_SECRETS = array(
		'paypal'       => 'paypal_webhook_id',
		'authorizenet' => 'authnet_signature_key',
		'2checkout'    => 'twocheckout_api_key',
	);

	/**
	 * Register the seams. Called at file scope; see Plugin::register().
	 */
	public static function hooks(): void {
		add_filter( 'wopcart_integration_cards', array( self::class, 'integration_cards' ) );
		add_filter( 'wopcart_integration_test', array( self::class, 'integration_test' ), 10, 2 );
		add_filter( 'wopcart_system_webhook_checks', array( self::class, 'webhook_checks' ) );
		add_filter( 'wopcart_privacy_third_party_content', array( self::class, 'privacy_content' ) );
		add_action( 'wopcart_enqueue_gateway_scripts', array( self::class, 'enqueue_gateway_scripts' ) );
	}

	/**
	 * Contribute the cards core did not build, and retire the plan card.
	 *
	 * Core normalises every card it is handed — it recomputes `status` from
	 * `configured`, `last_success` and `last_error` whoever supplied the card, so
	 * "Connected" goes on meaning "something actually answered". So these carry
	 * `configured` and leave `status` alone.
	 *
	 * @param array<int,array<string,mixed>> $cards Cards core assembled.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function integration_cards( array $cards ): array {
		if ( ! Plugin::active() ) {
			return $cards;
		}

		$kept = array();

		foreach ( $cards as $card ) {
			if ( is_array( $card ) && self::CORE_PLAN_CARD === ( $card['key'] ?? '' ) ) {
				continue;
			}

			$kept[] = $card;
		}

		$present = array();

		foreach ( $kept as $card ) {
			if ( is_array( $card ) && isset( $card['key'] ) ) {
				$present[ (string) $card['key'] ] = true;
			}
		}

		$kept[] = self::license_card();

		if ( ! isset( $present[ self::STRIPE_CARD ] ) ) {
			$kept[] = self::stripe_card();
		}

		foreach ( self::missing_gateway_cards( $present ) as $card ) {
			$kept[] = $card;
		}

		return $kept;
	}

	/**
	 * The card for a store charging with its own Stripe keys.
	 *
	 * Core built this card — "Stripe (your own keys)" — until 1.4.0, where it went
	 * with the rest of the own-credentials code. What core still has is the
	 * `connect` card, which describes the platform's connected account: on a store
	 * running this plugin that account is not the one taking the money, and a
	 * Payments section whose only card describes an unused account answers the one
	 * question the screen exists for — "is my store talking to everything it
	 * should be?" — wrongly. So the card comes back from here, with core's own key,
	 * so that the anchor and the Test button go on working as they did.
	 *
	 * Modelled on core's, with one difference that is worth naming: core also mixed
	 * in the last `gateway.stripe` log entry, read by a private `log_activity()`
	 * this plugin cannot reach. So `last_error` and `message` here come only from
	 * `IntegrationMonitor`, which means a Stripe charge that failed at the API is
	 * no longer reflected on the card — only a failed Test press and a received
	 * webhook are. That is a narrowing, not a wrong answer: the status is still
	 * computed by core from the figures given, so "Connected" still means something
	 * really answered.
	 *
	 * Stripe stays this plugin's business even though core still ships the gateway
	 * class, because it is this plugin that answers `wopcart_stripe_secret_key` and
	 * core's entitlement question — the keys the card reports are the ones
	 * `Credentials` hands over.
	 *
	 * @return array<string,mixed>
	 */
	private static function stripe_card(): array {
		$mode    = Options::is_test_mode() ? 'test' : 'live';
		$secret  = trim( (string) Options::get( 'stripe_' . $mode . '_secret', '' ) );
		$webhook = IntegrationMonitor::get( 'stripe_webhook' );
		$tested  = IntegrationMonitor::get( 'stripe_api' );
		$usable  = Connect::uses_own_credentials() && '' !== $secret;

		$seen     = get_option( \WopCart\Gateways\StripePayload::VERSIONS_OPTION, array() );
		$mismatch = IntegrationStatus::stripe_version_warning( is_array( $seen ) ? $seen : array(), \WopCart\Gateways\Stripe::API_VERSION, time() );

		return array(
			'key'          => self::STRIPE_CARD,
			'group'        => 'payments',
			'label'        => __( 'Stripe (your own keys)', 'wopcart-direct' ),
			'configured'   => $usable,
			'last_success' => max( $webhook['success'], $tested['success'] ),
			'last_error'   => $tested['error'],
			'message'      => $tested['message'],
			// The pinned-API-version warning belongs to whichever account is
			// actually charging. While payments still go through the platform's
			// connected account it is core's `connect` card that carries it.
			'warning'      => Connect::is_active() ? '' : $mismatch,
			'details'      => array(
				__( 'Mode', 'wopcart-direct' )           => 'test' === $mode ? __( 'Test', 'wopcart-direct' ) : __( 'Live', 'wopcart-direct' ),
				__( 'Secret key', 'wopcart-direct' )     => IntegrationStatus::mask( $secret ),
				__( 'Webhook secret', 'wopcart-direct' ) => IntegrationStatus::mask( (string) Options::get( 'stripe_webhook_secret', '' ) ),
				__( 'Last webhook received', 'wopcart-direct' ) => Integrations::when( $webhook['success'] ),
			),
			'setup_url'    => self::settings_url(),
			'docs_url'     => 'https://docs.stripe.com/keys',
			'test'         => $usable,
		);
	}

	/**
	 * Cards for this plugin's gateways that nothing else has carded.
	 *
	 * @param array<string,bool> $present Card keys already in the list.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function missing_gateway_cards( array $present ): array {
		$mode    = Options::is_test_mode() ? 'test' : 'live';
		$enabled = (array) Options::get( 'gateways_enabled', array() );
		$cards   = array();

		foreach ( self::GATEWAYS as $slug => $meta ) {
			if ( isset( $present[ $slug ] ) ) {
				continue;
			}

			list( $label, $credential, $docs ) = $meta;

			$credential = sprintf( $credential, $mode );
			$saved      = trim( (string) Options::get( $credential, '' ) );

			$cards[] = array(
				'key'        => $slug,
				'group'      => 'payments',
				'label'      => $label,
				'configured' => '' !== $saved,
				'details'    => array(
					__( 'Enabled', 'wopcart-direct' )    => in_array( $slug, $enabled, true ) ? __( 'Yes', 'wopcart-direct' ) : __( 'No', 'wopcart-direct' ),
					__( 'Credential', 'wopcart-direct' ) => IntegrationStatus::mask( $saved ),
				),
				'setup_url'  => self::settings_url(),
				'docs_url'   => $docs,
			);
		}

		return $cards;
	}

	/**
	 * The card describing this plugin's own licence.
	 *
	 * `configured` is whether a key is saved, and `last_error` is set only when the
	 * store gave a real verdict against it — never when the store was simply
	 * unreachable, which is our problem rather than the merchant's and would
	 * otherwise paint the card red during any outage of ours.
	 *
	 * @return array<string,mixed>
	 */
	private static function license_card(): array {
		$key     = License::key();
		$status  = License::status();
		$when    = (int) ( $status['checked_at'] ?? 0 );
		$ok      = ! empty( $status['success'] );
		$verdict = array() !== $status && ! in_array( (string) ( $status['error'] ?? '' ), License::TRANSPORT_ERRORS, true );

		return array(
			'key'          => self::LICENSE_CARD,
			'group'        => 'payments',
			'label'        => __( 'WopCart Direct licence', 'wopcart-direct' ),
			'configured'   => '' !== $key,
			'last_success' => $ok ? $when : 0,
			'last_error'   => ! $ok && $verdict ? $when : 0,
			'message'      => $ok ? '' : (string) ( $status['message'] ?? '' ),
			// Never a hard error: an unverified licence does not stop a single
			// payment, and a card flagged as broken invites a merchant to start
			// switching things off.
			'hard_error'   => false,
			'warning'      => License::problem( $status, $key ),
			'details'      => array(
				__( 'Licence key', 'wopcart-direct' ) => IntegrationStatus::mask( $key ),
				__( 'Status', 'wopcart-direct' )      => '' !== (string) ( $status['status'] ?? '' ) ? (string) $status['status'] : __( 'Not checked', 'wopcart-direct' ),
				__( 'Expires', 'wopcart-direct' )     => '' !== (string) ( $status['expires_at'] ?? '' ) ? (string) $status['expires_at'] : __( 'Unknown', 'wopcart-direct' ),
				__( 'Store', 'wopcart-direct' )       => (string) WOPCART_DIRECT_STORE_URL,
			),
			'setup_url'    => self::settings_url(),
			'docs_url'     => 'https://wopcart.com/direct/',
			'test'         => '' !== $key,
		);
	}

	/**
	 * Answer the Test button on the cards this plugin supplies.
	 *
	 * Core reaches this filter only for a key it has no test of its own for, so
	 * answering `stripe` here does not take anything over: core's own `stripe` arm
	 * went with its Stripe card, and on a 1.3.x core — which still has both — core
	 * answers first and this is never consulted for that key.
	 *
	 * @param string|\WP_Error|null $result What an earlier consumer answered.
	 * @param string                $key    Card key being tested.
	 *
	 * @return string|\WP_Error|null
	 */
	public static function integration_test( $result, string $key ) {
		if ( ! Plugin::active() || null !== $result ) {
			return $result;
		}

		if ( self::STRIPE_CARD === $key ) {
			return self::test_stripe();
		}

		if ( self::LICENSE_CARD !== $key ) {
			return $result;
		}

		return self::test_license();
	}

	/**
	 * Press Test on the licence card.
	 *
	 * A `check` rather than an `activate`: pressing Test must not quietly consume
	 * one of the merchant's production domains. The answer is remembered, so the
	 * notice and the card agree with what the merchant was just shown.
	 *
	 * @return string|\WP_Error
	 */
	private static function test_license(): string|\WP_Error {
		$license = License::key();

		if ( '' === $license ) {
			return new \WP_Error( 'wopcart_direct_no_license', __( 'No licence key is saved.', 'wopcart-direct' ) );
		}

		$response = License::check_now();

		if ( ! empty( $response['success'] ) ) {
			return __( 'wopcart.com accepted the licence key.', 'wopcart-direct' );
		}

		$message = (string) ( $response['message'] ?? '' );

		return new \WP_Error(
			'wopcart_direct_license',
			'' !== $message ? $message : __( 'wopcart.com did not accept the licence key. Your gateways are unaffected; only updates are.', 'wopcart-direct' )
		);
	}

	/**
	 * Read the Stripe balance: authenticated, read-only.
	 *
	 * Core's own test, moved rather than rewritten, down to the error codes and the
	 * wording — a merchant who has read "Stripe answered with HTTP 401" on this
	 * screen before should read the same sentence after installing this plugin.
	 * `/v1/balance` is the cheapest authenticated read Stripe offers: it proves the
	 * key works, costs nothing, and moves no money, which is the rule every test on
	 * this screen follows.
	 *
	 * The result is recorded against `stripe_api` because core's `run_test()` no
	 * longer does it for this key — its monitor map lost the `stripe` entry with
	 * the card. Without this, a press of Test would be reported to the merchant and
	 * then forgotten, and the card could never show "Connected": nothing else
	 * writes that entry.
	 *
	 * @return string|\WP_Error
	 */
	private static function test_stripe(): string|\WP_Error {
		$result = self::stripe_balance();

		if ( is_wp_error( $result ) ) {
			// An unconfigured store is not a failed connection: nothing was asked
			// of Stripe, so recording an error would leave a red card and a "last
			// error" timestamp for a key the merchant has not entered yet.
			if ( 'wopcart_stripe_unconfigured' !== $result->get_error_code() ) {
				IntegrationMonitor::error( 'stripe_api', $result->get_error_message() );
			}

			return $result;
		}

		IntegrationMonitor::success( 'stripe_api' );

		return $result;
	}

	/**
	 * The call itself, separated so that recording the verdict is not tangled with it.
	 *
	 * @return string|\WP_Error
	 */
	private static function stripe_balance(): string|\WP_Error {
		$key = trim( (string) Options::get( 'stripe_' . ( Options::is_test_mode() ? 'test' : 'live' ) . '_secret', '' ) );

		if ( '' === $key || ! Connect::uses_own_credentials() ) {
			return new \WP_Error( 'wopcart_stripe_unconfigured', __( 'No Stripe secret key is in use.', 'wopcart-direct' ) );
		}

		$response = wp_remote_get(
			'https://api.stripe.com/v1/balance',
			array(
				'timeout' => 10,
				'headers' => array( 'Authorization' => 'Bearer ' . $key ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			return new \WP_Error(
				'wopcart_stripe_test',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Stripe answered with HTTP %d. Check the secret key.', 'wopcart-direct' ),
					$code
				)
			);
		}

		return __( 'Stripe accepted the key.', 'wopcart-direct' );
	}

	/**
	 * Add the webhook rows core did not list.
	 *
	 * Core renders a row only while the gateway it names is actually available, so
	 * a row for a gateway the store does not offer costs nothing.
	 *
	 * @param array<string,array{0:string,1:string}> $checks Checks core assembled.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function webhook_checks( array $checks ): array {
		if ( ! Plugin::active() ) {
			return $checks;
		}

		$labels = array(
			'paypal'       => __( 'PayPal webhook ID', 'wopcart-direct' ),
			'authorizenet' => __( 'Authorize.net signature key', 'wopcart-direct' ),
			'2checkout'    => __( '2Checkout INS secret word', 'wopcart-direct' ),
		);

		foreach ( self::WEBHOOK_SECRETS as $slug => $option ) {
			if ( ! isset( $checks[ $slug ] ) ) {
				$checks[ $slug ] = array( $option, $labels[ $slug ] );
			}
		}

		return $checks;
	}

	/**
	 * Describe the payment providers this plugin introduces.
	 *
	 * Appended, never replaced: returning something unrelated would silently drop
	 * the disclosures the store still needs, the geolocation one above all.
	 *
	 * Each paragraph is skipped when the text already names that provider, which is
	 * how core's own Mollie paragraph is not duplicated while core still carries
	 * the gateway. Matching on the company name rather than on a phrase from the
	 * sentence is deliberate: the sentence is translated, the company name is a
	 * proper noun and is not.
	 *
	 * Written in the store owner's voice, one provider per paragraph, and only
	 * while the gateway is actually switched on — a policy listing providers the
	 * store does not use trains readers to ignore it.
	 *
	 * @param string $content Third-party section HTML core assembled.
	 *
	 * @return string
	 */
	public static function privacy_content( string $content ): string {
		if ( ! Plugin::active() ) {
			return $content;
		}

		$enabled = (array) Options::get( 'gateways_enabled', array() );

		foreach ( self::privacy_paragraphs() as $slug => $paragraph ) {
			list( $provider, $text ) = $paragraph;

			if ( ! in_array( $slug, $enabled, true ) || false !== strpos( $content, $provider ) ) {
				continue;
			}

			$content .= '<p>' . esc_html( $text ) . '</p>';
		}

		return $content;
	}

	/**
	 * The paragraphs, keyed by gateway slug: the provider name to look for, and the text.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	private static function privacy_paragraphs(): array {
		return array(
			'paypal'       => array(
				'PayPal',
				__( 'If you pay with PayPal, this store sends the amount, currency, order reference and the names and prices of the items — and, for a delivery, your name and delivery address — to PayPal (PayPal (Europe) S.à r.l. et Cie, S.C.A. for buyers in Europe). You log in and approve the payment on PayPal\'s own pages, so your PayPal credentials and any card details never reach this store. PayPal tells this store the payer\'s name, email address and the payment status, and for a subscription it keeps the billing agreement so renewals can be collected. PayPal is an independent controller of the data it receives; PayPal\'s privacy policy applies.', 'wopcart-direct' ),
			),
			'authorizenet' => array(
				'Authorize.net',
				__( 'If you pay by card, your card number, expiry date and security code are sent straight from your browser to Authorize.net (Authorize.net LLC, a Visa solution) and are never received or stored by this store, which only ever sees a one-time token standing in for them. Alongside that token the store sends the amount, currency, order reference, the names of the items and your billing name, address and email address, so that Authorize.net can take the payment and carry out its own fraud checks; for a subscription it keeps a payment profile so renewals can be collected. Authorize.net is an independent controller of the data it receives; its privacy policy applies.', 'wopcart-direct' ),
			),
			'2checkout'    => array(
				'2Checkout',
				__( 'If you pay with 2Checkout, this store sends you to 2Checkout\'s own payment page (2Checkout is the Verifone company Verifone Payments B.V.) with the amount, currency, order reference, the names of the items and, where you have entered them, your name, email address and billing address. 2Checkout is the merchant of record for that payment, which means it sells to you in its own name, collects any tax due and carries out its own fraud and sanctions checks; card details are entered only on its pages. It then tells this store the payment status, your name and email address and the billing country. 2Checkout is an independent controller of the data it receives; its privacy policy applies.', 'wopcart-direct' ),
			),
			'mollie'       => array(
				'Mollie',
				__( 'If you pay with Mollie, this store sends the amount, currency and order number — and, for Klarna, the order lines together with your name, email address and billing address — to Mollie B.V. (Amsterdam). For subscriptions the store creates a customer record at Mollie with your name and email address, and your payment authorisation (mandate) is kept by Mollie so renewals can be collected. Card and bank details are entered only on Mollie\'s own payment page and never reach this store. Mollie is an independent controller of the data it receives; Mollie\'s privacy policy applies.', 'wopcart-direct' ),
			),
		);
	}

	/**
	 * Put Authorize.net's Accept.js on the checkout.
	 *
	 * Core enqueues this for *its* `Gateways\AuthorizeNet`, by an `instanceof`
	 * check this plugin's class cannot satisfy — it extends core's AbstractGateway
	 * but it is a different class. So whenever this plugin's copy is the one the
	 * registry kept for the `authorizenet` slug, core's enqueue does not fire and
	 * the card fields would be drawn with no library behind them: every card
	 * payment would fail in the browser with nothing in any log to explain it.
	 *
	 * The handle is core's, not a second one. `wp_enqueue_script()` ignores a
	 * repeat of an existing handle, so when core's own copy is the registered one
	 * this call is a no-op rather than a second copy of Accept.js on the page.
	 *
	 * No version, and in the footer: Accept.js must be fetched from Authorize.net's
	 * own domain, unversioned, which is their requirement for PCI scope.
	 *
	 * @param \WopCart\Gateways\Registry $gateways Core's gateway registry.
	 */
	public static function enqueue_gateway_scripts( $gateways ): void {
		if ( ! Plugin::active() || ! $gateways instanceof \WopCart\Gateways\Registry ) {
			return;
		}

		$authnet = $gateways->get( 'authorizenet' );

		if ( ! $authnet instanceof AuthorizeNet || ! $authnet->is_available() ) {
			return;
		}

		wp_enqueue_script( 'wopcart-accept-js', $authnet->accept_js_url(), array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Authorize.net requires the unversioned URL from their own domain.
	}

	/**
	 * The Payments settings screen, where every credential above is entered.
	 *
	 * @return string
	 */
	private static function settings_url(): string {
		return admin_url( 'admin.php?page=wopcart-settings&tab=gateways' );
	}
}
