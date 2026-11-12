<?php
/**
 * Acowebs feedback client: deactivation feedback helper.
 *
 * WHERE TO PUT THIS FILE
 * ----------------------
 * Copy this file into your plugin, for example:
 *
 *     your-plugin/includes/class-acowebs-feedback-client.php
 *
 * and require it from your main plugin file (or your loader):
 *
 *     require_once plugin_dir_path( __FILE__ ) . 'includes/class-acowebs-feedback-client.php';
 *
 * All Acowebs plugins (Free and PRO) may bundle this same file. The class is wrapped in a
 * `class_exists()` guard, so loading it from several plugins at once never causes a fatal error.
 * The first copy loaded wins, so keep the public methods backward compatible and bump
 * Acowebs_Feedback_Client::VERSION when you change anything.
 *
 * HOW TO RENAME THE PREFIX
 * ------------------------
 * Do NOT rename the class, the constant or the filter: they are shared across plugins on purpose.
 * Do rename the prefix in the usage example at the bottom of this file. Replace `myplugin` /
 * `MYPLUGIN` with your own unique, lowercase plugin prefix (for example `acopa` / `ACOPA` for
 * Product Addons) in:
 *
 *  - the AJAX action:        wp_ajax_myplugin_deactivation_feedback
 *  - the nonce name:         myplugin_deactivation_feedback
 *  - the function names:     myplugin_*
 *  - the constants:          MYPLUGIN_NAME, MYPLUGIN_EDITION, MYPLUGIN_VERSION, MYPLUGIN_SLUG
 *  - the JS global:          mypluginDeactivation
 *
 * WHAT IT SENDS
 * -------------
 * One non-blocking POST to {API}/v1/deactivation (JSON, 5 second timeout). It never throws, never
 * waits for a reply and never blocks deactivation. See docs/integration.md for the field reference.
 *
 * CHANGELOG
 * ---------
 * 1.1.1  Empty values are left out of the payload as well as nulls, so an unticked contact box
 *        never sends "name": "" or "email": "" (consent: false is still sent).
 * 1.1.0  Optional `meta` custom data.
 *
 * REQUIREMENTS: WordPress 5.0+, PHP 7.1+.
 *
 * @package Acowebs_Feedback
 */

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Generic.Files.OneObjectStructurePerFile

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Do not load directly.
}

/*
 * The public API base URL (no trailing slash). IMPORTANT: this URL must never change after plugins
 * ship, because old plugin versions keep posting to it.
 * Sites can override it with the `acowebs_feedback_api_url` filter.
 */
if ( ! defined( 'ACOWEBS_FEEDBACK_API' ) ) {
	define( 'ACOWEBS_FEEDBACK_API', 'https://audience.acowebs.com' );
}

if ( ! class_exists( 'Acowebs_Feedback_Client' ) ) {

	/**
	 * Sends deactivation feedback to the Acowebs feedback API.
	 */
	class Acowebs_Feedback_Client {

		/**
		 * Version of this helper. Bump when you change it.
		 */
		const VERSION = '1.1.1';

		/**
		 * The API base URL, passed through the `acowebs_feedback_api_url` filter.
		 *
		 * @return string URL without a trailing slash.
		 */
		public static function api_url() {
			/**
			 * Filters the Acowebs feedback API base URL.
			 *
			 * @param string $url Base URL, no trailing slash.
			 */
			$url = apply_filters( 'acowebs_feedback_api_url', ACOWEBS_FEEDBACK_API );

			return untrailingslashit( (string) $url );
		}

		/**
		 * Information about this site, sent with every deactivation.
		 *
		 * @return array {
		 *     @type string      $site_url      Home URL.
		 *     @type string      $site_name     Site title.
		 *     @type string      $wp_version    WordPress version.
		 *     @type string|null $wc_version    WooCommerce version, or null when WooCommerce is not active.
		 *     @type string      $php_version   PHP version.
		 *     @type string      $site_language Locale, for example en_US.
		 * }
		 */
		public static function site_info(): array {
			return array(
				'site_url'      => home_url(),
				'site_name'     => get_bloginfo( 'name' ),
				'wp_version'    => get_bloginfo( 'version' ),
				'wc_version'    => defined( 'WC_VERSION' ) ? WC_VERSION : null,
				'php_version'   => PHP_VERSION,
				'site_language' => get_locale(),
			);
		}

		/**
		 * Sends deactivation feedback. Non-blocking, never throws.
		 *
		 * @param array $args {
		 *     Feedback details. They are merged over site_info(), so any site_info() key can be overridden.
		 *
		 *     @type string $plugin         Canonical plugin name. Free and PRO must send the same value.
		 *     @type string $edition        'free' or 'pro'.
		 *     @type string $plugin_version Plugin version.
		 *     @type string $plugin_slug    Plugin slug, for example 'woo-custom-product-addons'.
		 *     @type string $reason         Short reason text (the option the user picked).
		 *     @type string $reason_details Free-text details.
		 *     @type string $name           Name the user typed (optional, may be empty).
		 *     @type string $email          Email the user typed (optional, may be empty).
		 *     @type bool   $consent        True only if the user ticked "Acowebs may contact me".
		 *     @type array  $meta           Optional custom data, shown as "Meta fields" in the dashboard, e.g.
		 *                                  array( 'website_name' => 'Shop X', 'active_theme' => 'Astra' ).
		 *                                  Flat: at most 30 keys (letters, numbers, _ . -), string (max 1000
		 *                                  characters), number, bool or null values, 4 KB in total. Sent only
		 *                                  when it is a non-empty array. An invalid meta makes the API reject
		 *                                  the whole feedback, so pass only values you control.
		 * }
		 * @return void
		 */
		public static function send_deactivation( array $args ): void {
			try {
				$args = wp_parse_args(
					$args,
					array(
						'plugin'         => '',
						'edition'        => 'free',
						'plugin_version' => '',
						'plugin_slug'    => '',
						'reason'         => '',
						'reason_details' => '',
						'name'           => '',
						'email'          => '',
						'consent'        => false,
					)
				);

				$payload = array_merge( self::site_info(), $args );

				// Required by the API. Skip silently rather than send a record that would be rejected.
				if ( '' === trim( (string) $payload['plugin'] ) || '' === trim( (string) $payload['site_url'] ) ) {
					return;
				}

				$payload['edition'] = strtolower( trim( (string) $payload['edition'] ) );
				$payload['consent'] = (bool) $payload['consent'];

				// Optional custom data: only a non-empty array, sent as a JSON object (an empty PHP array would be "[]").
				if ( isset( $payload['meta'] ) && is_array( $payload['meta'] ) && ! empty( $payload['meta'] ) ) {
					$payload['meta'] = (object) $payload['meta'];
				} else {
					unset( $payload['meta'] );
				}

				// The API rejects null for text fields (for example wc_version without WooCommerce), and an empty
				// string is never a useful value (an empty email would fail validation). Leave both out.
				// `consent: false` is a boolean, so it is kept.
				$payload = array_filter(
					$payload,
					static function ( $value ) {
						return null !== $value && '' !== $value;
					}
				);

				wp_remote_post(
					self::api_url() . '/v1/deactivation',
					array(
						'method'      => 'POST',
						'timeout'     => 5,
						'blocking'    => false, // Fire and forget: never wait for the API.
						'redirection' => 0,
						'headers'     => array(
							'Content-Type' => 'application/json',
						),
						'body'        => wp_json_encode( $payload ),
					)
				);
			} catch ( \Throwable $e ) {
				// Feedback must never break deactivation. Ignore everything.
				return;
			}
		}
	}
}
