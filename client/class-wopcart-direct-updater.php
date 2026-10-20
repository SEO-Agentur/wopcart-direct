<?php
/**
 * WopCart Direct's update client.
 *
 * An embedded copy of core's drop-in updater template
 * (`client/class-wopcart-updater.php` in the WopCart repository), not a
 * reference to it. Core excludes its own `client/` directory from the
 * distributed package — it is a template for merchants to ship inside the
 * products *they* sell — so there is no file to require at runtime, and
 * requiring one from another plugin's directory would make this plugin's ability
 * to update itself depend on core's packaging.
 *
 * Three things were changed from the template, all for the same reason: a
 * merchant running this plugin may well also be selling a product of their own
 * with the same template dropped into it.
 *
 * - The class is `WopCart_Direct_Updater`, not `WopCart_Updater`. The template
 *   guards itself with `class_exists()` so that two copies on one site do not
 *   redeclare the class — which means the copy that loads first wins and the
 *   other silently uses it. Config is per-instance, so that mostly works, but if
 *   the merchant's copy is an older revision then *our* updates start behaving
 *   like that revision. Whether a paying customer receives updates must not
 *   depend on which plugin WordPress happened to load first.
 * - The activation filter is `wopcart_direct_updater_activate_on_first_use`, so
 *   a merchant switching the behaviour off for their own product does not
 *   switch it off for this plugin as well.
 * - The cache and activation-marker keys are prefixed `wopcartdirect_` rather
 *   than `wopcart_`. Core's `uninstall.php` deletes every option matching
 *   `wopcart_%`, so under the template's own prefix, uninstalling core would
 *   clear this plugin's record of having activated its key.
 *
 * Otherwise this is the template as shipped, and it should be updated from it
 * rather than edited in place.
 *
 * An invalid, expired or unknown licence needs no handling here. The store
 * answers the version check with `success: false` and no package, `fetch()`
 * returns null, and nothing is injected into WordPress's update transient — so
 * updates simply stop. Nothing else about the plugin changes, which is the whole
 * point: see `WopCartDirect\License`.
 *
 * @package WopCartDirect
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'WopCart_Direct_Updater' ) ) {
	return;
}

/**
 * Connects a plugin or theme to a WopCart store for licensing and updates.
 */
class WopCart_Direct_Updater {

	/**
	 * Configuration.
	 *
	 * @var array<string,mixed>
	 */
	private array $config;

	/**
	 * Cached remote response for this request.
	 *
	 * @var object|null
	 */
	private ?object $remote = null;

	/**
	 * Whether the remote has already been consulted this request.
	 *
	 * @var bool
	 */
	private bool $checked = false;

	/**
	 * Set the updater up.
	 *
	 * @param array<string,mixed> $config store_url, item_id, file, version, license.
	 */
	public function __construct( array $config ) {
		$defaults = array(
			'store_url' => '',
			'item_id'   => 0,
			'file'      => '',
			'version'   => '1.0.0',
			'license'   => '',
			'author'    => '',
			'type'      => 'plugin',
			'slug'      => '',
			'beta'      => false,
			// How long a successful check is cached. Twelve hours keeps a busy
			// store from being polled by every site on every dashboard load,
			// while still surfacing a release the same day.
			'cache_ttl' => 12 * HOUR_IN_SECONDS,
		);

		$this->config = array_merge( $defaults, $config );

		if ( '' === $this->config['slug'] ) {
			$this->config['slug'] = 'theme' === $this->config['type']
				? get_template()
				: dirname( plugin_basename( (string) $this->config['file'] ) );
		}

		$this->config['basename'] = 'theme' === $this->config['type']
			? (string) $this->config['slug']
			: plugin_basename( (string) $this->config['file'] );

		$this->hooks();
	}

	/**
	 * Register the WordPress hooks.
	 */
	private function hooks(): void {
		if ( 'theme' === $this->config['type'] ) {
			add_filter( 'pre_set_site_transient_update_themes', array( $this, 'inject_theme_update' ) );
		} else {
			add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_plugin_update' ) );
			add_filter( 'plugins_api', array( $this, 'plugin_details' ), 10, 3 );

			// WordPress caches the "view details" HTTP result; clearing our own
			// cache when an update completes stops the old version lingering in
			// the UI after a successful upgrade.
			add_action( 'upgrader_process_complete', array( $this, 'flush_cache' ), 10, 0 );
		}
	}

	/**
	 * Add our update to the plugin update transient.
	 *
	 * @param object $transient Update transient.
	 *
	 * @return object
	 */
	public function inject_plugin_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		// WordPress calls this filter more than once per request. Bailing when
		// the checked list is absent avoids a remote call during the early pass.
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$remote = $this->fetch();

		if ( null === $remote || empty( $remote->new_version ) ) {
			return $transient;
		}

		$basename = (string) $this->config['basename'];

		if ( version_compare( (string) $this->config['version'], (string) $remote->new_version, '>=' ) ) {
			// No update available. Telling WordPress explicitly keeps the "you
			// have the latest version" message accurate.
			$transient->no_update[ $basename ] = $this->build_response( $remote );

			return $transient;
		}

		$transient->response[ $basename ] = $this->build_response( $remote );

		return $transient;
	}

	/**
	 * Add our update to the theme update transient.
	 *
	 * @param object $transient Update transient.
	 *
	 * @return object
	 */
	public function inject_theme_update( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
			return $transient;
		}

		$remote = $this->fetch();

		if ( null === $remote || empty( $remote->new_version ) ) {
			return $transient;
		}

		if ( version_compare( (string) $this->config['version'], (string) $remote->new_version, '>=' ) ) {
			return $transient;
		}

		$transient->response[ (string) $this->config['slug'] ] = array(
			'theme'       => (string) $this->config['slug'],
			'new_version' => (string) $remote->new_version,
			'url'         => (string) ( $remote->homepage ?? '' ),
			'package'     => (string) ( $remote->package ?? '' ),
		);

		return $transient;
	}

	/**
	 * Build the object WordPress expects in the update transient.
	 *
	 * @param object $remote Remote payload.
	 *
	 * @return object
	 */
	private function build_response( object $remote ): object {
		$response = new stdClass();

		$response->slug         = (string) $this->config['slug'];
		$response->plugin       = (string) $this->config['basename'];
		$response->new_version  = (string) $remote->new_version;
		$response->url          = (string) ( $remote->homepage ?? '' );
		$response->tested       = (string) ( $remote->tested ?? '' );
		$response->requires     = (string) ( $remote->requires ?? '' );
		$response->requires_php = (string) ( $remote->requires_php ?? '' );

		// An empty package is how the store says "there is a newer version but
		// this license does not currently include it". WordPress renders the row
		// without an install button, and the notice explains why.
		$response->package = (string) ( $remote->package ?? '' );

		if ( '' === $response->package && ! empty( $remote->upgrade_notice ) ) {
			$response->upgrade_notice = (string) $remote->upgrade_notice;
		}

		if ( ! empty( $remote->icons ) ) {
			$response->icons = (array) $remote->icons;
		}

		if ( ! empty( $remote->banners ) ) {
			$response->banners = (array) $remote->banners;
		}

		return $response;
	}

	/**
	 * Supply the "View details" modal.
	 *
	 * @param false|object|array $result Existing result.
	 * @param string             $action Requested action.
	 * @param object             $args   Request arguments.
	 *
	 * @return false|object|array
	 */
	public function plugin_details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || $args->slug !== $this->config['slug'] ) {
			return $result;
		}

		$remote = $this->fetch();

		if ( null === $remote ) {
			return $result;
		}

		$information = new stdClass();

		$information->name          = (string) ( $remote->name ?? '' );
		$information->slug          = (string) $this->config['slug'];
		$information->version       = (string) ( $remote->new_version ?? '' );
		$information->author        = (string) ( $remote->author ?? $this->config['author'] );
		$information->homepage      = (string) ( $remote->homepage ?? '' );
		$information->requires      = (string) ( $remote->requires ?? '' );
		$information->tested        = (string) ( $remote->tested ?? '' );
		$information->requires_php  = (string) ( $remote->requires_php ?? '' );
		$information->last_updated  = (string) ( $remote->last_updated ?? '' );
		$information->sections      = (array) ( $remote->sections ?? array() );
		$information->download_link = (string) ( $remote->package ?? '' );

		if ( ! empty( $remote->banners ) ) {
			$information->banners = (array) $remote->banners;
		}

		return $information;
	}

	/**
	 * Ask the store for update metadata, using the cache where possible.
	 *
	 * @return object|null
	 */
	private function fetch(): ?object {
		if ( $this->checked ) {
			return $this->remote;
		}

		$this->checked = true;

		$cache_key = $this->cache_key();
		$cached    = get_site_transient( $cache_key );

		if ( is_object( $cached ) ) {
			$this->remote = $cached;

			return $this->remote;
		}

		$this->maybe_activate_on_first_use();

		// activate() flushes the cache, which resets the per-request flags.
		$this->checked = true;

		$response = wp_remote_post(
			trailingslashit( (string) $this->config['store_url'] ) . 'wp-json/wopcart/v1/license/version',
			array(
				'timeout'   => 15,
				'sslverify' => true,
				'body'      => array(
					'license' => (string) $this->config['license'],
					'item_id' => (int) $this->config['item_id'],
					'version' => (string) $this->config['version'],
					'url'     => home_url(),
					'beta'    => $this->config['beta'] ? '1' : '0',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			// Cache the failure briefly. A store that is down should not be
			// re-polled on every single dashboard page load, but it also should
			// not be written off for twelve hours.
			set_site_transient( $cache_key, new stdClass(), 30 * MINUTE_IN_SECONDS );

			return null;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ) );

		if ( ! is_object( $body ) || empty( $body->success ) ) {
			set_site_transient( $cache_key, new stdClass(), HOUR_IN_SECONDS );

			return null;
		}

		set_site_transient( $cache_key, $body, (int) $this->config['cache_ttl'] );

		$this->remote = $body;

		return $this->remote;
	}

	/**
	 * Activate the configured key on this site the first time it is used.
	 *
	 * Stores only send update packages to sites the key is activated on. Plugins
	 * that never called activate() used to receive updates anyway; this keeps
	 * them working without any change in the plugin that ships this file. It
	 * runs once per key per site (remembered in a non-autoloaded option), and is
	 * retried later only when the store could not be reached or asked to retry.
	 * Stores running WopCart 1.2.1 or later also activate on the version check
	 * itself, so this is a no-op there beyond one extra request. Disable it with
	 * the `wopcart_direct_updater_activate_on_first_use` filter.
	 */
	private function maybe_activate_on_first_use(): void {
		$license = trim( (string) $this->config['license'] );

		if ( '' === $license ) {
			return;
		}

		/**
		 * Filter whether the updater activates its key on first use.
		 *
		 * @param bool                $activate Default true.
		 * @param array<string,mixed> $config   Updater configuration.
		 */
		if ( ! apply_filters( 'wopcart_direct_updater_activate_on_first_use', true, $this->config ) ) {
			return;
		}

		$marker = 'wopcartdirect_upd_act_' . md5( (string) $this->config['store_url'] . '|' . (string) $this->config['item_id'] . '|' . $license );

		if ( false !== get_option( $marker, false ) ) {
			return;
		}

		$result = $this->activate( $license );

		// Transient failures are retried on a later check; anything else (activated,
		// no seats left, unknown key) is an answer and is not asked again.
		if ( in_array( (string) ( $result['error'] ?? '' ), array( 'connection_failed', 'invalid_response', 'busy', 'rate_limited' ), true ) ) {
			return;
		}

		update_option( $marker, time(), false );
	}

	/**
	 * Cache key, scoped to the license and version.
	 *
	 * Including both means entering a new key or upgrading takes effect at once
	 * rather than waiting out the previous cache window.
	 *
	 * @return string
	 */
	private function cache_key(): string {
		return 'wopcartdirect_upd_' . md5(
			(string) $this->config['store_url'] .
			(string) $this->config['item_id'] .
			(string) $this->config['license'] .
			(string) $this->config['version']
		);
	}

	/**
	 * Drop the cached response.
	 */
	public function flush_cache(): void {
		delete_site_transient( $this->cache_key() );

		$this->remote  = null;
		$this->checked = false;
	}

	/**
	 * Activate the license on this site.
	 *
	 * @param string $license License key. Defaults to the configured one.
	 *
	 * @return array<string,mixed> Decoded store response.
	 */
	public function activate( string $license = '' ): array {
		return $this->license_request( 'activate', '' !== $license ? $license : (string) $this->config['license'] );
	}

	/**
	 * Deactivate the license on this site.
	 *
	 * @param string $license License key. Defaults to the configured one.
	 *
	 * @return array<string,mixed> Decoded store response.
	 */
	public function deactivate( string $license = '' ): array {
		return $this->license_request( 'deactivate', '' !== $license ? $license : (string) $this->config['license'] );
	}

	/**
	 * Check the license status.
	 *
	 * @param string $license License key. Defaults to the configured one.
	 *
	 * @return array<string,mixed> Decoded store response.
	 */
	public function check( string $license = '' ): array {
		return $this->license_request( 'check', '' !== $license ? $license : (string) $this->config['license'] );
	}

	/**
	 * Perform a licensing call.
	 *
	 * @param string $action  activate|deactivate|check.
	 * @param string $license License key.
	 *
	 * @return array<string,mixed>
	 */
	private function license_request( string $action, string $license ): array {
		$response = wp_remote_post(
			trailingslashit( (string) $this->config['store_url'] ) . 'wp-json/wopcart/v1/license/' . $action,
			array(
				'timeout'   => 15,
				'sslverify' => true,
				'body'      => array(
					'license' => $license,
					'item_id' => (int) $this->config['item_id'],
					'version' => (string) $this->config['version'],
					'url'     => home_url(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => 'connection_failed',
				'message' => $response->get_error_message(),
			);
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			return array(
				'success' => false,
				'error'   => 'invalid_response',
				'message' => __( 'The store returned an unreadable response.', 'wopcart-direct' ),
			);
		}

		// Any state change invalidates what we cached about updates.
		$this->flush_cache();

		return $body;
	}
}
