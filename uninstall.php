<?php
/**
 * Uninstaller.
 *
 * Removes this plugin's own bookkeeping and nothing else.
 *
 * What is deliberately *not* removed:
 *
 * - **The merchant's gateway credentials.** They live in core's
 *   `wopcart_gateway_secrets` option, they are what was paid for, and they are
 *   core's data to keep. A merchant who removes this add-on for an afternoon and
 *   puts it back must not have to re-enter five sets of API keys, which in most
 *   cases means generating new ones because a secret key is shown once.
 * - **The licence key**, for the same reason: it is a setting inside core's
 *   options, and it is the one thing the merchant cannot recover from their own
 *   records as easily as re-reading it from their account.
 * - **The site's activation against the licence.** Releasing it would mean an
 *   HTTP call from inside an uninstall, where a slow or unreachable store leaves
 *   the merchant looking at a hung screen — and the one place a failure cannot be
 *   retried, because by then the plugin is gone. A site is released from the
 *   account page on wopcart.com, which is also where the merchant can see the
 *   count they are paying for.
 *
 * @package WopCartDirect
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/*
 * Only this plugin's own options, named one by one rather than matched with a
 * wildcard. A `wopcartdirect_%` sweep would also take the updater's cache keys,
 * which is harmless, but a wildcard delete in an uninstaller is how core came to
 * be at risk of deleting this plugin's data with its own `wopcart_%` rule.
 */
delete_option( 'wopcartdirect_license_status' );

// The updater's per-site record of having activated its key. Its name carries an
// md5 of the store URL, product and licence, so it cannot be named exactly here;
// losing it only costs one extra activation call on a reinstall.
global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off uninstall, and there is no API for a prefix match.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'wopcartdirect_upd_' ) . '%'
	)
);
