<?php
/*
 * Plugin Name: Acowebs Woocommerce Dynamic Pricing
 * Version: 5.1.2
 * Description: Woocommerce Dynamic Pricing helps to apply discounts for woocommerce products. Its sophisticated user interfaces will help to add discounts very easily.
 * Author: Acowebs
 * Author URI: http://acowebs.com
 * Requires at least: 6.5
 * Tested up to: 7.2
 * Requires PHP: 7.4
 * Text Domain: aco-woo-dynamic-pricing
 * WC requires at least: 9.0
 * WC tested up to: 11.3
 * Requires Plugins: woocommerce
 */

// Declared before the Pro check so WooCommerce never flags this plugin as HPOS-incompatible while paused.
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

if (!function_exists('awdp_free_is_pro_active')) {

    /**
     * Whether the Pro plugin is active. Pro shares every AWDP_* class, constant and
     * data key with this plugin, so both must never run together. Pro defines
     * AWDP_PRO_CHECK when it loads first; otherwise it is found by its header,
     * whatever folder it was installed in.
     */
    function awdp_free_is_pro_active()
    {
        if (defined('AWDP_PRO_CHECK')) {
            return true;
        }

        $plugins = (array) get_option('active_plugins', array());
        if (is_multisite()) {
            $plugins = array_merge($plugins, array_keys((array) get_site_option('active_sitewide_plugins', array())));
        }

        $self = plugin_basename(__FILE__);
        foreach ($plugins as $plugin) {
            if ($plugin === $self || false === strpos($plugin, 'dynamic-pricing')) {
                continue;
            }
            $plugin_file = WP_PLUGIN_DIR . '/' . $plugin;
            if (!is_readable($plugin_file)) {
                continue;
            }
            $headers = get_file_data($plugin_file, array('name' => 'Plugin Name'));
            if (false !== stripos($headers['name'], 'Dynamic Pricing Pro')) {
                return true;
            }
        }

        return false;
    }

}

if (awdp_free_is_pro_active()) {

    // Pro reads the same rules and settings, so pause instead of loading a second copy.
    add_action('admin_notices', function () {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        echo '<div class="notice notice-info"><p>' . esc_html__('Dynamic Pricing Pro is active, so the free version of Dynamic Pricing With Discount Rules is paused. Your pricing rules are being used by Pro, and you can deactivate the free version.', 'aco-woo-dynamic-pricing') . '</p></div>';
    });

    return;

}

defined('AWDP_POST_TYPE') || define('AWDP_POST_TYPE', 'awdp_pt_rules');
defined('AWDP_PRODUCT_LIST') || define('AWDP_PRODUCT_LIST', 'awdp_pt_products');
defined('AWDP_WC_PRODUCTS') || define('AWDP_WC_PRODUCTS', 'product');

defined('AWDP_TOKEN') || define('AWDP_TOKEN', 'awdp');
defined('AWDP_VERSION') || define('AWDP_VERSION', '5.1.2');
defined('AWDP_FILE') || define('AWDP_FILE', __FILE__);
defined('AWDP_PLUGIN_NAME') || define('AWDP_PLUGIN_NAME', 'Acowebs Woocommerce Dynamic Pricing');
defined('AWDP_PRODUCTS_TRANSIENT_KEY') || define('AWDP_PRODUCTS_TRANSIENT_KEY', 'awdp_product_list');
defined('AWDP_PRODUCTS_LANG_TRANSIENT_KEY') || define('AWDP_PRODUCTS_LANG_TRANSIENT_KEY', 'awdp_product_lang_list');
defined('AWDP_STORE_URL') || define('AWDP_STORE_URL', 'https://api.acowebs.com');
defined('AWDP_FOLDER_PATH') || define('AWDP_FOLDER_PATH', plugin_dir_url( __FILE__ ));

defined('AWDP_Wordpress_Version') || define('AWDP_Wordpress_Version', get_bloginfo('version'));

defined('AWDP_Feed_Attribute') || define('AWDP_Feed_Attribute', 'acowdp_sale_price');

require_once(realpath(plugin_dir_path(__FILE__)) . DIRECTORY_SEPARATOR . 'includes/helpers.php');

if (!function_exists('awdp_init')) {

    function awdp_init()
    {
        $plugin_rel_path = basename(dirname(__FILE__)) . '/languages'; /* Relative to WP_PLUGIN_DIR */
        load_plugin_textdomain('aco-woo-dynamic-pricing', false, $plugin_rel_path);

        new AWDP_Api();

        $discount = new AWDP_Discount();

        new AWDP_Front_End($discount, __FILE__, AWDP_VERSION);
    }

}


if (!function_exists('awdp_autoloader')) {

    function awdp_autoloader($class_name)
    {
        if (0 === strpos($class_name, 'AWDP')) {
            $classes_dir = realpath(plugin_dir_path(__FILE__)) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR;
            $class_file = 'class-' . str_replace('_', '-', strtolower($class_name)) . '.php';

            if (0 === strpos($class_name, 'AWDP_Discount_')) {
                $discount_file = $classes_dir . 'discount' . DIRECTORY_SEPARATOR . $class_file;
                if (file_exists($discount_file)) {
                    require_once $discount_file;
                    return;
                }
            }

            // Leave classes we do not ship (e.g. from another AWDP plugin) to other autoloaders.
            if (file_exists($classes_dir . $class_file)) {
                require_once $classes_dir . $class_file;
            }
        }
    }

}

if (!function_exists('AWDP')) {

    function AWDP()
    {
        $instance = AWDP_Backend::instance(__FILE__, AWDP_VERSION);
        return $instance;
    }

}

// add_action('plugins_loaded', 'awdp_init');
add_action('init', 'awdp_init');
spl_autoload_register('awdp_autoloader');
if (is_admin()) {
    AWDP();
    // Deactivation feedback popup and newsletter opt-in notice (Acowebs Feedback API). Loaded at plugin load,
    // not on admin_init, so their admin-ajax handlers are registered too.
    new AWDP_Feedback(__FILE__);
}