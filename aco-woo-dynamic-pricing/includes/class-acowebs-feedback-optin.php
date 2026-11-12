<?php
/**
 * Acowebs feedback client: newsletter opt-in admin notice.
 *
 * Shows the Acowebs newsletter notice from the Acowebs UI kit (docs/ui-kit/newsletter-notice.html +
 * acowebs-feedback-ui.css): email (pre-filled with the admin's email), optional first name and phone,
 * Subscribe and Not now. Clicking Subscribe, with the privacy line visible, is the consent (consent: true).
 * Submitting subscribes the admin to the plugin's list (`general` unless the plugin registers another one)
 * through the Acowebs feedback API. Once the admin subscribes or dismisses the notice, a user-meta flag
 * stops it from ever showing again, in every Acowebs plugin.
 *
 * WHERE TO PUT THIS FILE
 * ----------------------
 * Copy it into your plugin, for example:
 *
 *     your-plugin/includes/class-acowebs-feedback-optin.php
 *
 * Also copy the UI kit files into your plugin, for example:
 *
 *     your-plugin/assets/acowebs-ui-kit/acowebs-feedback-ui.css
 *     your-plugin/assets/acowebs-ui-kit/newsletter-notice.html
 *
 * require it from your main plugin file, then register your plugin (see "USAGE" at the bottom).
 * All Acowebs plugins may bundle this same file: the class is wrapped in a `class_exists()` guard,
 * so loading it twice never fatals, and the notice is shown at most once per admin even when several
 * Acowebs plugins are active. The first copy loaded wins, so keep it backward compatible and bump
 * Acowebs_Feedback_Optin::VERSION when you change it.
 *
 * Do NOT rename the class, the AJAX actions, the nonce or the user-meta key: they are shared across
 * plugins on purpose, which is what makes the "never show again" flag work everywhere. The only thing
 * you rename is your own plugin's values passed to register().
 *
 * CUSTOM DATA (meta)
 * ------------------
 * To attach extra data to the signup (shown as "Meta fields" in the dashboard), use the
 * `acowebs_feedback_optin_meta` filter. Return a flat array: at most 30 keys (letters, numbers, _ . -),
 * values that are strings (up to 1000 characters), numbers, booleans or null, 4 KB in total.
 * An invalid meta makes the API reject the signup, so return only values you control:
 *
 *     add_filter( 'acowebs_feedback_optin_meta', function ( $meta, $plugin ) {
 *         $meta['website_name'] = get_bloginfo( 'name' );
 *         $meta['active_theme'] = wp_get_theme()->get( 'Name' );
 *         return $meta;
 *     }, 10, 2 );
 *
 * CHANGELOG
 * ---------
 * 1.3.0  Optional `list` argument in register(): the newsletter list the plugin's signups go to.
 *        Defaults to `general`, so plugins that do not pass it behave exactly as before.
 * 1.2.0  Optional phone field, honeypot, `acowebs_feedback_optin_meta` filter.
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
 * Public API base URL (no trailing slash). Must never change after plugins ship.
 * Sites can override it with the `acowebs_feedback_api_url` filter.
 */
if ( ! defined( 'ACOWEBS_FEEDBACK_API' ) ) {
	define( 'ACOWEBS_FEEDBACK_API', 'https://audience.acowebs.com' );
}

if ( ! class_exists( 'Acowebs_Feedback_Optin' ) ) {

	/**
	 * Newsletter opt-in admin notice.
	 */
	class Acowebs_Feedback_Optin {

		/**
		 * Version of this helper. Bump when you change it.
		 */
		const VERSION = '1.3.0';

		/**
		 * User-meta key set when the user subscribed or dismissed the notice.
		 */
		const META_KEY = 'acowebs_optin_done';

		/**
		 * Nonce action shared by the notice and both AJAX handlers.
		 */
		const NONCE = 'acowebs_optin';

		/**
		 * List used when a plugin does not register its own.
		 */
		const DEFAULT_LIST = 'general';

		/**
		 * Registered plugins, keyed by slug.
		 *
		 * @var array
		 */
		private static $plugins = array();

		/**
		 * Whether the hooks have been added.
		 *
		 * @var bool
		 */
		private static $hooked = false;

		/**
		 * Registers a plugin that may show the notice. Call this once from your plugin, early
		 * (at load time, not only on admin_init), so the AJAX handlers can find it.
		 *
		 * @param array $args {
		 *     @type string   $plugin     Canonical plugin name, for example 'Product Table'.
		 *     @type string   $edition    'free' or 'pro'.
		 *     @type string   $slug       Unique plugin slug, for example 'product-table-for-woocommerce'.
		 *     @type string[] $page_slugs Values of ?page= for the plugin's own admin pages.
		 *     @type string   $prefix     Your plugin prefix, e.g. 'wcpa'. Keeps the notice's ids unique.
		 *     @type string   $display    Name shown in the notice text. Defaults to $plugin.
		 *     @type string   $list       Newsletter list the signups go to. Defaults to 'general'. (1.3.0)
		 *     @type string   $kit_dir    Absolute path to your copy of the UI kit folder (with trailing slash).
		 *     @type string   $kit_url    URL of that folder (with trailing slash), for the stylesheet.
		 * }
		 * @return void
		 */
		public static function register( array $args ): void {
			$args = wp_parse_args(
				$args,
				array(
					'plugin'     => '',
					'edition'    => 'free',
					'slug'       => '',
					'page_slugs' => array(),
					'prefix'     => '',
					'display'    => '',
					'list'       => self::DEFAULT_LIST,
					'kit_dir'    => '',
					'kit_url'    => '',
				)
			);

			if ( '' === $args['plugin'] || '' === $args['slug'] ) {
				return;
			}

			$args['edition']    = 'pro' === strtolower( (string) $args['edition'] ) ? 'pro' : 'free';
			$args['page_slugs'] = array_filter( array_map( 'sanitize_key', (array) $args['page_slugs'] ) );
			$args['prefix']     = sanitize_key( '' !== $args['prefix'] ? $args['prefix'] : $args['slug'] );
			$args['display']    = '' !== $args['display'] ? $args['display'] : $args['plugin'];
			$args['list']       = trim( sanitize_text_field( (string) $args['list'] ) );
			$args['list']       = '' !== $args['list'] ? $args['list'] : self::DEFAULT_LIST;

			self::$plugins[ sanitize_key( $args['slug'] ) ] = $args;

			if ( ! self::$hooked ) {
				self::$hooked = true;
				add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_styles' ) );
				add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
				add_action( 'wp_ajax_acowebs_optin_subscribe', array( __CLASS__, 'ajax_subscribe' ) );
				add_action( 'wp_ajax_acowebs_optin_dismiss', array( __CLASS__, 'ajax_dismiss' ) );
			}
		}

		/**
		 * The API base URL, passed through the `acowebs_feedback_api_url` filter.
		 *
		 * @return string URL without a trailing slash.
		 */
		public static function api_url() {
			// Same filter as Acowebs_Feedback_Client::api_url().
			return untrailingslashit( (string) apply_filters( 'acowebs_feedback_api_url', ACOWEBS_FEEDBACK_API ) );
		}

		/**
		 * Returns the registered plugin that owns the current admin screen, or null.
		 * The dashboard counts for every registered plugin: the first one registered is used.
		 *
		 * @return array|null
		 */
		private static function current_plugin() {
			if ( ! function_exists( 'get_current_screen' ) ) {
				return null;
			}

			$screen = get_current_screen();
			if ( ! $screen ) {
				return null;
			}

			if ( 'dashboard' === $screen->id ) {
				return empty( self::$plugins ) ? null : reset( self::$plugins );
			}

			foreach ( self::$plugins as $plugin ) {
				foreach ( $plugin['page_slugs'] as $page ) {
					// Top-level pages are "toplevel_page_{slug}", submenu pages "{parent}_page_{slug}".
					if ( substr( $screen->id, -strlen( '_page_' . $page ) ) === '_page_' . $page ) {
						return $plugin;
					}
				}
			}

			return null;
		}

		/**
		 * The registered plugin whose notice should show on this screen for this user, or null.
		 *
		 * @return array|null
		 */
		private static function plugin_to_show() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return null;
			}
			if ( get_user_meta( get_current_user_id(), self::META_KEY, true ) ) {
				return null;
			}
			$plugin = self::current_plugin();
			if ( ! $plugin || '' === $plugin['kit_dir'] || ! is_readable( $plugin['kit_dir'] . 'newsletter-notice.html' ) ) {
				return null;
			}
			return $plugin;
		}

		/**
		 * Loads the UI kit stylesheet on screens where the notice shows.
		 *
		 * @return void
		 */
		public static function enqueue_styles(): void {
			$plugin = self::plugin_to_show();
			if ( $plugin && '' !== $plugin['kit_url'] ) {
				// One handle for every Acowebs plugin: the stylesheet is shared and loaded once.
				wp_enqueue_style( 'acowebs-feedback-ui', $plugin['kit_url'] . 'acowebs-feedback-ui.css', array(), self::VERSION );
			}
		}

		/**
		 * Prints the notice: the UI kit markup with its placeholders filled in (escaped).
		 *
		 * @return void
		 */
		public static function render_notice(): void {
			$plugin = self::plugin_to_show();
			if ( ! $plugin ) {
				return;
			}

			$user   = wp_get_current_user();
			$markup = (string) file_get_contents( $plugin['kit_dir'] . 'newsletter-notice.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			// Drop the kit's leading comment and the preview-only "Hidden: ..." line.
			$markup = preg_replace( '/^\s*<!--.*?-->\s*/s', '', $markup );
			$markup = preg_replace( '#<p class="acofb-api-auto".*?</p>\s*#s', '', $markup );

			$markup = strtr(
				$markup,
				array(
					'value="{Plugin name}"'            => 'value="' . esc_attr( $plugin['plugin'] ) . '"',
					'{Plugin name}'                    => esc_html( $plugin['display'] ),
					'{prefix}'                         => esc_attr( $plugin['prefix'] ),
					'value="admin@example.com"'        => 'value="' . esc_attr( $user->user_email ) . '"',
					'name="list" value="general"'      => 'name="list" value="' . esc_attr( self::plugin_list( $plugin ) ) . '"',
					'name="edition" value="free"'      => 'name="edition" value="' . esc_attr( $plugin['edition'] ) . '"',
					'value="https://shop.example.com"' => 'value="' . esc_url( home_url() ) . '"',
					'value="Example Shop"'             => 'value="' . esc_attr( get_bloginfo( 'name' ) ) . '"',
				)
			);

			$config = array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'slug'    => sanitize_key( $plugin['slug'] ),
				'rootId'  => 'acofb-' . $plugin['prefix'] . '-newsletter',
				'i18n'    => array(
					'sending' => __( 'Subscribing…', 'acowebs-feedback' ),
					'error'   => __( 'Sorry, we could not subscribe you right now. Please try again later.', 'acowebs-feedback' ),
				),
			);

			// .notice lets WordPress place it with the other admin notices; the kit card does the styling.
			echo '<div class="notice acowebs-optin-notice" style="border:0;background:none;box-shadow:none;padding:0;margin:16px 20px 16px 0;">';
			echo $markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static kit markup, placeholders escaped above.
			echo '</div>';
			?>
			<script>
			( function () {
				var cfg = <?php echo wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
				var box = document.getElementById( cfg.rootId );
				if ( ! box ) { return; }
				var form = box.querySelector( 'form.acofb-panel' );
				var msg = box.querySelector( '.acofb-msg' );
				var submit = form.querySelector( 'button[type="submit"]' );

				function post( action, fields ) {
					var body = new URLSearchParams();
					body.append( 'action', action );
					body.append( 'nonce', cfg.nonce );
					body.append( 'slug', cfg.slug );
					Object.keys( fields || {} ).forEach( function ( key ) { body.append( key, fields[ key ] ); } );
					return fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
						.then( function ( response ) { return response.json(); } );
				}

				function say( text, isError ) {
					msg.textContent = text || '';
					msg.classList.toggle( 'acofb-msg--error', !! isError );
				}

				function hide() {
					var wrap = box.closest( '.acowebs-optin-notice' ) || box;
					wrap.style.transition = 'opacity .3s';
					wrap.style.opacity = '0';
					setTimeout( function () { wrap.style.display = 'none'; }, 300 );
				}

				form.addEventListener( 'submit', function ( event ) {
					event.preventDefault();
					if ( ! form.email.value || ! form.email.checkValidity() ) { form.email.reportValidity(); return; }
					submit.disabled = true;
					say( cfg.i18n.sending );
					post( 'acowebs_optin_subscribe', {
						email: form.email.value,
						name: form.name.value,
						phone: form.phone ? form.phone.value : '',
						website_hp: form.website_hp ? form.website_hp.value : ''
					} ).then( function ( result ) {
						if ( result && result.success ) {
							// Show the kit's thank-you state and move focus to it.
							box.querySelector( '.acofb-notice-body' ).hidden = true;
							var thanks = box.querySelector( '.acofb-thanks' );
							thanks.hidden = false;
							thanks.focus();
						} else {
							submit.disabled = false;
							say( ( result && result.data && result.data.message ) || cfg.i18n.error, true );
						}
					} ).catch( function () {
						submit.disabled = false;
						say( cfg.i18n.error, true );
					} );
				} );

				// "Not now" and the X both dismiss for good.
				box.addEventListener( 'click', function ( event ) {
					if ( event.target.closest( '[data-acofb-action="dismiss"]' ) ) {
						post( 'acowebs_optin_dismiss' ).catch( function () {} );
						hide();
					}
				} );
			} )();
			</script>
			<?php
		}

		/**
		 * The newsletter list a registered plugin's signups go to.
		 *
		 * @param array $plugin A registered plugin (see register()).
		 * @return string
		 */
		private static function plugin_list( array $plugin ): string {
			return ( isset( $plugin['list'] ) && '' !== $plugin['list'] ) ? (string) $plugin['list'] : self::DEFAULT_LIST;
		}

		/**
		 * AJAX: subscribe the current admin.
		 *
		 * @return void
		 */
		public static function ajax_subscribe(): void {
			check_ajax_referer( self::NONCE, 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'acowebs-feedback' ) ), 403 );
			}

			$slug = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
			if ( ! isset( self::$plugins[ $slug ] ) ) {
				wp_send_json_error( array( 'message' => __( 'Unknown plugin.', 'acowebs-feedback' ) ), 400 );
			}
			$plugin = self::$plugins[ $slug ];

			$email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
			$name     = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
			$phone    = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
			$honeypot = isset( $_POST['website_hp'] ) ? sanitize_text_field( wp_unslash( $_POST['website_hp'] ) ) : '';

			if ( '' !== $honeypot ) {
				// A bot filled the hidden field: pretend it worked, send nothing.
				wp_send_json_success();
			}

			if ( ! is_email( $email ) ) {
				wp_send_json_error( array( 'message' => __( 'Please enter a valid email address.', 'acowebs-feedback' ) ), 422 );
			}

			$payload = array(
				'email'     => $email,
				'list'      => self::plugin_list( $plugin ),
				'source'    => 'plugin-admin-notice',
				'plugin'    => $plugin['plugin'],
				'edition'   => $plugin['edition'],
				'site_name' => get_bloginfo( 'name' ),
				'site_url'  => home_url(),
				// Clicking Subscribe, with the privacy line visible, is the consent.
				'consent'   => true,
			);
			if ( '' !== $name ) {
				$payload['name'] = $name;
			}
			if ( '' !== $phone ) {
				// Optional. The API strips spaces, dashes, dots and brackets, and rejects anything that isn't a phone number.
				$payload['phone'] = $phone;
			}

			/**
			 * Filters the optional custom data (meta) sent with the signup. Return a flat array
			 * (string keys; string, number, bool or null values). Anything else is ignored.
			 *
			 * @param array $meta   Custom data. Empty by default.
			 * @param array $plugin The registered plugin (see register()).
			 */
			$meta = apply_filters( 'acowebs_feedback_optin_meta', array(), $plugin );
			if ( is_array( $meta ) && ! empty( $meta ) ) {
				$payload['meta'] = (object) $meta; // Always a JSON object, never a JSON array.
			}

			// Blocking, so we can tell the admin whether it worked. Do not set website_hp.
			$response = wp_remote_post(
				self::api_url() . '/v1/subscribe',
				array(
					'method'      => 'POST',
					'timeout'     => 5,
					'blocking'    => true,
					'redirection' => 0,
					'headers'     => array(
						'Content-Type' => 'application/json',
					),
					'body'        => wp_json_encode( $payload ),
				)
			);

			if ( ! is_wp_error( $response ) && 422 === (int) wp_remote_retrieve_response_code( $response ) ) {
				// Tell the admin when it was the phone number; other 422s get the generic message below.
				$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
				$issues = ( is_array( $body ) && isset( $body['issues'] ) && is_array( $body['issues'] ) ) ? $body['issues'] : array();
				foreach ( $issues as $issue ) {
					if ( is_array( $issue ) && isset( $issue['path'] ) && 'phone' === $issue['path'] ) {
						wp_send_json_error( array( 'message' => __( 'Please enter a valid phone number, or leave it empty.', 'acowebs-feedback' ) ), 422 );
					}
				}
			}

			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				// Keep the notice so the admin can retry.
				wp_send_json_error( array( 'message' => __( 'Sorry, we could not subscribe you right now. Please try again later.', 'acowebs-feedback' ) ), 502 );
			}

			update_user_meta( get_current_user_id(), self::META_KEY, time() );
			wp_send_json_success();
		}

		/**
		 * AJAX: dismiss the notice for good.
		 *
		 * @return void
		 */
		public static function ajax_dismiss(): void {
			check_ajax_referer( self::NONCE, 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'acowebs-feedback' ) ), 403 );
			}

			update_user_meta( get_current_user_id(), self::META_KEY, time() );
			wp_send_json_success();
		}
	}
}

/*
 * =====================================================================================
 * USAGE (in your main plugin file, after requiring this file)
 * =====================================================================================
 *
 * require_once plugin_dir_path( __FILE__ ) . 'includes/class-acowebs-feedback-optin.php';
 *
 * Acowebs_Feedback_Optin::register(
 *     array(
 *         'plugin'     => 'Product Table',                    // Canonical name, same in Free and PRO.
 *         'edition'    => 'free',                             // 'free' or 'pro'.
 *         'slug'       => 'product-table-for-woocommerce',    // Unique per plugin.
 *         'page_slugs' => array( 'product-table-settings' ),  // Your admin pages (?page=...).
 *         'prefix'     => 'wcpt',                             // Your plugin prefix (unique ids).
 *         'list'       => 'general',                          // Optional (1.3.0+): newsletter list.
 *         'kit_dir'    => plugin_dir_path( __FILE__ ) . 'assets/acowebs-ui-kit/',
 *         'kit_url'    => plugin_dir_url( __FILE__ ) . 'assets/acowebs-ui-kit/',
 *     )
 * );
 *
 * The notice shows only to users with `manage_options`, on the dashboard and on the pages listed in
 * `page_slugs`. To allow someone to re-show the notice (for example while testing), delete the
 * `acowebs_optin_done` user meta for that user.
 */
