<?php
/**
 * WordPress stubs for tests that drive a request through to the code under test.
 *
 * Kept out of the shared bootstrap because only a handful of tests need them, and
 * each is the least behaviour that lets the request get there: no post exists, and
 * request input is taken as given.
 *
 * Named after the plan server, which is where they started and which now lives in
 * the WopCart Platform plugin with a copy of this file.
 *
 * @package WopCartDirect
 */

declare(strict_types=1);

defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core constant.

if ( ! function_exists( 'get_post' ) ) {
	/**
	 * Post lookup stub: no post exists by ID; a post object passed in is returned as is,
	 * as WordPress does.
	 *
	 * @param mixed $post Post ID or object.
	 *
	 * @return mixed
	 */
	function get_post( mixed $post = null ): mixed { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return $post instanceof \WP_Post ? $post : null;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Sanitising stub: trims only.
	 *
	 * @param string $value Value.
	 *
	 * @return string
	 */
	function sanitize_text_field( string $value ): string { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return trim( $value );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * Unslash stub: identity.
	 *
	 * @param mixed $value Value.
	 *
	 * @return mixed
	 */
	function wp_unslash( mixed $value ): mixed { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return $value;
	}
}
