<?php
/**
 * Acowebs Feedback API integration: deactivation feedback popup and newsletter opt-in notice.
 *
 * Nothing is sent without a user action: the popup posts only when the admin clicks
 * "Send & deactivate", and the newsletter notice only when they click "Subscribe".
 *
 * @package AWDP
 */

if (!defined('ABSPATH')) {
    exit;
}

// Shared Acowebs helpers. Both are guarded by class_exists(), so another Acowebs plugin may load its own copy.
require_once __DIR__ . '/class-acowebs-feedback-client.php';
require_once __DIR__ . '/class-acowebs-feedback-optin.php';

class AWDP_Feedback
{

    /**
     * Canonical plugin name. Free and Pro must send exactly this string.
     */
    const PLUGIN_NAME = 'Dynamic Pricing With Discount Rules';

    const EDITION = 'free';

    /**
     * The wordpress.org slug (not the install folder, which can differ).
     */
    const PLUGIN_SLUG = 'aco-woo-dynamic-pricing';

    const NEWSLETTER_LIST = 'dynamic pricing with discount rules';

    const PREFIX = 'awdp';

    const AJAX_ACTION = 'awdp_deactivation_feedback';

    const NONCE = 'awdp_deactivation_feedback';

    /**
     * This plugin's admin pages (?page=), where the newsletter notice may show.
     */
    const ADMIN_PAGES = array('awdp_admin_ui', 'awdp_admin_product_lists', 'awdp_ui_settings', 'awdp_ui_help');

    /** @var string Main plugin file. */
    private $file;

    /** @var string URL of the UI kit folder, with a trailing slash. */
    private $kit_url;

    public function __construct($file)
    {
        $this->file    = $file;
        $this->kit_url = plugin_dir_url($file) . 'assets/acowebs-ui-kit/';

        // Deactivation feedback.
        add_action('admin_enqueue_scripts', array($this, 'enqueue_deactivation_assets'));
        add_action('admin_footer', array($this, 'render_deactivation_modal'));
        add_action('wp_ajax_' . self::AJAX_ACTION, array($this, 'ajax_deactivation_feedback'));

        // Newsletter opt-in: the shared class owns the notice, its AJAX handlers and the "never again" flag.
        if (class_exists('Acowebs_Feedback_Optin')) {
            Acowebs_Feedback_Optin::register(array(
                'plugin'     => self::PLUGIN_NAME,
                'edition'    => self::EDITION,
                'slug'       => self::PLUGIN_SLUG,
                'page_slugs' => self::ADMIN_PAGES,
                'prefix'     => self::PREFIX,
                'list'       => self::NEWSLETTER_LIST,
                'kit_dir'    => plugin_dir_path($file) . 'assets/acowebs-ui-kit/',
                'kit_url'    => $this->kit_url,
            ));
            add_filter('acowebs_feedback_optin_meta', array($this, 'optin_meta'), 10, 2);
        }
    }

    /**
     * The deactivation reasons. The value is what the API receives, so it stays in English on every site
     * (the dashboard groups by it); only the label and placeholder are translated.
     *
     * @return array[] value => array( label, placeholder )
     */
    private function reasons()
    {
        return array(
            "I couldn't get it to work"                         => array(
                __("I couldn't get it to work", 'aco-woo-dynamic-pricing'),
                __('What went wrong? Any error message helps.', 'aco-woo-dynamic-pricing'),
            ),
            'I found a better plugin'                           => array(
                __('I found a better plugin', 'aco-woo-dynamic-pricing'),
                __('Which plugin did you switch to? What does it do better?', 'aco-woo-dynamic-pricing'),
            ),
            "It's missing a feature I need"                     => array(
                __("It's missing a feature I need", 'aco-woo-dynamic-pricing'),
                __('Which feature were you looking for?', 'aco-woo-dynamic-pricing'),
            ),
            'It broke my site or conflicts with another plugin' => array(
                __('It broke my site or conflicts with another plugin', 'aco-woo-dynamic-pricing'),
                __('What broke, or which plugin does it conflict with?', 'aco-woo-dynamic-pricing'),
            ),
            'I only needed it for a short time'                 => array(
                __('I only needed it for a short time', 'aco-woo-dynamic-pricing'),
                __('Thanks! Anything we could improve?', 'aco-woo-dynamic-pricing'),
            ),
            'Other'                                             => array(
                __('Other', 'aco-woo-dynamic-pricing'),
                __('Please tell us a little more.', 'aco-woo-dynamic-pricing'),
            ),
        );
    }

    /**
     * Whether the current request is the Plugins screen (single site or network admin) and the user can
     * deactivate plugins there.
     *
     * @return bool
     */
    private function is_plugins_screen()
    {
        global $pagenow;

        if ('plugins.php' !== $pagenow) {
            return false;
        }

        return current_user_can(is_network_admin() ? 'manage_network_plugins' : 'activate_plugins');
    }

    /**
     * Loads the popup's stylesheet and script on the Plugins screen only.
     *
     * @param string $hook Current admin page.
     */
    public function enqueue_deactivation_assets($hook = '')
    {
        if ('plugins.php' !== $hook || !$this->is_plugins_screen()) {
            return;
        }

        // Shared handle: if another Acowebs plugin already registered the kit, its copy is used.
        wp_enqueue_style('acowebs-feedback-ui', $this->kit_url . 'acowebs-feedback-ui.css', array(), AWDP_VERSION);

        wp_enqueue_script(
            'awdp-deactivation-feedback',
            plugins_url('assets/js/awdp-deactivation-feedback.js', $this->file),
            array(),
            AWDP_VERSION,
            true
        );

        wp_localize_script('awdp-deactivation-feedback', 'awdpDeactivation', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action'  => self::AJAX_ACTION,
            'nonce'   => wp_create_nonce(self::NONCE),
            'plugin'  => plugin_basename($this->file),
            'modalId' => 'acofb-' . self::PREFIX . '-deactivation',
            'i18n'    => array(
                'reasonRequired' => __('Please choose a reason, or click Skip & deactivate.', 'aco-woo-dynamic-pricing'),
                'emailInvalid'   => __('Please enter a valid email address, or untick the box above.', 'aco-woo-dynamic-pricing'),
            ),
        ));
    }

    /**
     * Prints the popup (hidden) on the Plugins screen. The script opens it from this plugin's Deactivate link.
     */
    public function render_deactivation_modal()
    {
        if (!$this->is_plugins_screen()) {
            return;
        }

        $user = wp_get_current_user();

        $prefix      = self::PREFIX;
        $plugin_name = self::PLUGIN_NAME;
        $user_name   = $user->exists() ? $user->display_name : '';
        $user_email  = $user->exists() ? $user->user_email : '';
        $reasons     = $this->reasons();

        include plugin_dir_path(__FILE__) . 'views/feedback-deactivation-modal.php';
    }

    /**
     * AJAX: sends the deactivation feedback. Non-blocking: the script deactivates whatever happens here.
     */
    public function ajax_deactivation_feedback()
    {
        check_ajax_referer(self::NONCE, 'nonce');

        if (!current_user_can('activate_plugins')) {
            wp_send_json_error(array('message' => 'forbidden'), 403);
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by check_ajax_referer() above.
        $reason         = isset($_POST['reason']) ? sanitize_text_field(wp_unslash($_POST['reason'])) : '';
        $reason_details = isset($_POST['reason_details']) ? sanitize_textarea_field(wp_unslash($_POST['reason_details'])) : '';
        $consent        = isset($_POST['consent']) && rest_sanitize_boolean(wp_unslash($_POST['consent']));
        $name           = '';
        $email          = '';

        // Name and email are sent only while "Acowebs can contact me" is ticked.
        if ($consent) {
            $name  = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
            $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        }
        // phpcs:enable

        // Only the reasons the popup offers, so the dashboard gets clean, groupable values.
        if (!array_key_exists($reason, $this->reasons())) {
            wp_send_json_error(array('message' => 'invalid_reason'), 400);
        }

        Acowebs_Feedback_Client::send_deactivation(array(
            'plugin'         => self::PLUGIN_NAME,
            'edition'        => self::EDITION,
            'plugin_version' => AWDP_VERSION,
            'plugin_slug'    => self::PLUGIN_SLUG,
            'reason'         => $reason,
            'reason_details' => $this->limit($reason_details, 5000),
            'name'           => $this->limit($name, 255),
            'email'          => is_email($email) ? $email : '',
            'consent'        => $consent,
        ));

        wp_send_json_success();
    }

    /**
     * Adds where the admin subscribed from to this plugin's newsletter signups: "dashboard" or the
     * plugin page's ?page= slug. Values are fixed slugs we control, so they always pass the API's meta rules.
     *
     * @param array $meta   Custom data so far.
     * @param array $plugin The registered plugin the signup is for.
     * @return array
     */
    public function optin_meta($meta, $plugin)
    {
        if (!is_array($meta) || !is_array($plugin) || !isset($plugin['slug']) || self::PLUGIN_SLUG !== $plugin['slug']) {
            return $meta;
        }

        $referer = wp_get_referer();
        if (!$referer) {
            return $meta;
        }

        $query = array();
        wp_parse_str((string) wp_parse_url($referer, PHP_URL_QUERY), $query);
        $page = isset($query['page']) ? sanitize_key($query['page']) : '';

        if ('' !== $page && in_array($page, self::ADMIN_PAGES, true)) {
            $meta['source_page'] = $page;
        } elseif ('' === $page && preg_match('#/wp-admin/(index\.php)?$#', (string) wp_parse_url($referer, PHP_URL_PATH))) {
            $meta['source_page'] = 'dashboard';
        }

        return $meta;
    }

    /**
     * Trims a string to the API's maximum length.
     *
     * @param string $value Value.
     * @param int    $max   Maximum characters.
     * @return string
     */
    private function limit($value, $max)
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }

}
