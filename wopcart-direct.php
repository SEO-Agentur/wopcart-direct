<?php
/**
 * Plugin Name:       WopCart Direct
 * Plugin URI:        https://wopcart.com/direct/
 * Description:       Take payments through your own gateway accounts — Stripe, PayPal, Authorize.net, Mollie and 2Checkout — with no WopCart platform fee on any transaction. Add-on for WopCart.
 * Version:           1.0.1
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Requires Plugins:  wopcart
 * Author:            Cyrus Badde
 * Author URI:        https://wopcart.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wopcart-direct
 * Domain Path:       /languages
 *
 * @package WopCartDirect
 */

declare(strict_types=1);

namespace WopCartDirect;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION  = '1.0.1';
const BASENAME = 'wopcart-direct/wopcart-direct.php';
const FILE     = __FILE__;
const DIR      = __DIR__;

/**
 * Lowest core WopCart release this add-on can run against.
 *
 * 1.3.0 is the release that froze the extension points this plugin is built out
 * of — `wopcart_gateway_classes`, the two Stripe credential filters,
 * `wopcart_secret_setting_keys`, `wopcart_integration_cards`,
 * `wopcart_system_webhook_checks`, `wopcart_privacy_third_party_content`,
 * `wopcart_enqueue_gateway_scripts` and `Plugin::supports()` itself. Against
 * anything older this plugin's gateways would be registered and then charge with
 * no credentials, which is worse than not being there at all.
 */
const MIN_CORE = '1.3.0';

/**
 * Where this plugin's own updates and licence checks are sent.
 *
 * Overridable so a staging copy of the store can be pointed at, and so the
 * licence machinery can be exercised without touching the live store. Defined
 * here rather than read from a setting: a merchant has no reason to change it,
 * and a store URL that can be changed from the admin is a route for sending a
 * licence key somewhere else.
 */
if ( ! defined( 'WOPCART_DIRECT_STORE_URL' ) ) {
	define( 'WOPCART_DIRECT_STORE_URL', 'https://wopcart.com' );
}

/**
 * The WopCart product id this plugin's licences are issued for.
 *
 * The wopcart.com store sells WopCart Direct as an ordinary licensed product
 * using WopCart's own `src/Licensing` module, so this is a product post id on
 * that store and the value `item_id` is sent as. Overridable for the same reason as
 * the store URL: a staging store numbers its products differently.
 */
if ( ! defined( 'WOPCART_DIRECT_PRODUCT_ID' ) ) {
	define( 'WOPCART_DIRECT_PRODUCT_ID', 0 );
}

require_once __DIR__ . '/src/Autoloader.php';
Autoloader::register();

/*
 * File scope, deliberately, and not inside a plugins_loaded callback.
 *
 * Core's Options::secret_keys() consults `wopcart_secret_setting_keys` on every
 * read and every write, because that filter is what decides which of the two
 * options a value lives in. Registered late, anything that reads one of this
 * plugin's credentials before the callback exists looks in the autoloaded
 * settings option instead of the secrets option and gets an empty string back —
 * which is indistinguishable from an unconfigured gateway, and would silently
 * stop a paying merchant taking payments. Core documents this requirement in
 * Options::secret_keys()'s docblock.
 *
 * The same reasoning applies to the credential filters: Stripe resolves its key
 * while a front-end page is being built, so an answer that depended on hook
 * registration order would be a race nobody could reproduce in testing.
 *
 * Plugin::guard() is what makes this safe to do before core has loaded: these
 * callbacks answer with the value they were given until the guard is satisfied,
 * so an add-on sitting next to a missing or too-old core changes nothing at all.
 */
Plugin::register();

// Priority 6: one step after core, which boots its own modules at 5. Everything
// registered here reads classes core has already loaded.
add_action( 'plugins_loaded', array( Plugin::class, 'boot' ), 6 );
