<?php

if (!defined('ABSPATH'))
    exit;

class AWDP_Backend
{

    /**
     * @var    object
     * @access  private
     * @since    1.0.0
     */
    private static $_instance = null;

    /**
     * The version number.
     * @var     string
     * @access  public
     * @since   1.0.0
     */
    public $_version;

    /**
     * The token.
     * @var     string
     * @access  public
     * @since   1.0.0
     */
    public $_token;

    /**
     * The main plugin file.
     * @var     string
     * @access  public
     * @since   1.0.0
     */
    public $file;

    /**
     * The main plugin directory.
     * @var     string
     * @access  public
     * @since   1.0.0
     */
    public $dir;

    /**
     * The plugin assets directory.
     * @var     string
     * @access  public
     * @since   1.0.0
     */
    public $assets_dir;

    /**
     * Suffix for Javascripts.
     * @var     string
     * @access  public
     * @since   1.0.0
     */
    public $script_suffix;

    /**
     * The plugin assets URL.
     * @var     string
     * @access  public
     * @since   1.0.0
     */
    public $assets_url;
    public $hook_suffix = array();
    public $plugin_slug;

    /**
     * Constructor function.
     * @access  public
     * @since   1.0.0
     * @return  void
     */
    public function __construct($file = '', $version = '1.0.0')
    {
        $this->_version = $version;
        $this->_token = AWDP_TOKEN;
        $this->file = $file;
        $this->dir = dirname($this->file);
        $this->assets_dir = trailingslashit($this->dir) . 'assets';
        $this->assets_url = esc_url(trailingslashit(plugins_url('/assets/', $this->file)));

        $this->plugin_slug = 'abc';

        $this->script_suffix = defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ? '' : '.min';
        // $currentScreen = get_current_screen();


        register_activation_hook($this->file, array($this, 'install'));
        // register_deactivation_hook($this->file, array($this, 'deactivation'));
        add_action('save_post', array($this, 'delete_transient'), 1);
        add_action('edited_term', array($this, 'delete_transient'));
        add_action('delete_term', array($this, 'delete_transient'));
        add_action('created_term', array($this, 'delete_transient'));

        add_action('admin_menu', array($this, 'register_root_page'));

        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'), 10, 1);
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_styles'), 10, 1);

        $plugin = plugin_basename($this->file);
        add_filter("plugin_action_links_$plugin", array($this, 'add_settings_link'));

        // Deactivation feedback and the newsletter notice live in AWDP_Feedback.

        // Major update notice on the Plugins screen.
        add_filter('in_plugin_update_message_' . plugin_basename($this->file), array($this, 'major_update_notice'), 10, 2);
        add_action('admin_head-plugins.php', array($this, 'major_update_notice_styles'));

        //Body Class
        add_filter( 'admin_body_class', array($this, 'wdp_admin_class' ));
        
    }

    /*
    * ver @ 4.3.1
    * Body Class
    */
    public function major_update_notice($plugin_data, $response)
    {
        if (empty($plugin_data['new_version'])) {
            return;
        }

        $installed = get_option($this->_token . '_version', AWDP_VERSION);

        if (
            version_compare($plugin_data['new_version'], '5.0.0', '>=') &&
            version_compare($installed, '5.0.0', '<')
        ) {
            printf(
                '<br><span class="awdp-major-update-notice">%s</span>',
                esc_html__(
                    'Major update: this release includes a redesigned admin UI and refactored discount engine. Back up your site before updating.',
                    'aco-woo-dynamic-pricing'
                )
            );
        }
    }

    public function major_update_notice_styles()
    {
        echo '<style>
            .awdp-major-update-notice {
                display: inline-block;
                margin-top: 4px;
                padding: 4px 8px;
                font-size: 12px;
                line-height: 1.4;
                color: #646970;
                background: #f6f7f7;
                border-left: 3px solid #dba617;
                border-radius: 2px;
            }
        </style>';
    }

    public function wdp_admin_class($classes) {
        
        $currentScreen = get_current_screen();
        $screenID = $currentScreen->id; //
        if ( strpos ( $screenID, 'awdp_' ) !== false ) {
            if ( strpos ( $screenID, 'product_lists' ) !== false && strpos ( $classes, 'pricing-rules' ) === false ) {
                $classes .= ' pricing-rules_page_awdp_admin_product_lists';
            } elseif ( strpos ( $screenID, 'settings' ) !== false && strpos ( $classes, 'pricing-rules' ) === false ) {
                $classes .= ' pricing-rules_page_awdp_ui_settings';
            } elseif ( strpos ( $screenID, 'help' ) !== false && strpos ( $classes, 'pricing-rules' ) === false ) {
                $classes .= ' pricing-rules_page_awdp_ui_help';
            }
        }
        return $classes;
    }

    /**
     *
     *
     * Ensures only one instance of WCPA is loaded or can be loaded.
     *
     * @since 1.0.0
     * @static
     * @see WordPress_Plugin_Template()
     * @return Main WCPA instance
     */
    public static function instance($file = '', $version = '1.0.0')
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new self($file, $version);
        }
        return self::$_instance;
    }

    public function register_root_page()
    {
        $this->hook_suffix[] = add_menu_page(
            __('Pricing Rules', 'aco-woo-dynamic-pricing'), __('Pricing Rules', 'aco-woo-dynamic-pricing'), awdp_free_manage_capability(), 'awdp_admin_ui', array($this, 'admin_ui'), esc_url($this->assets_url) . '/images/icon.png', 25);
        $this->hook_suffix[] = add_submenu_page(
            'awdp_admin_ui', __('Product Lists', 'aco-woo-dynamic-pricing'), __('Product Lists', 'aco-woo-dynamic-pricing'), awdp_free_manage_capability(), 'awdp_admin_product_lists', array($this, 'admin_ui_pro_lists'));
        $this->hook_suffix[] = add_submenu_page(
            'awdp_admin_ui', __('Settings', 'aco-woo-dynamic-pricing'), __('Settings', 'aco-woo-dynamic-pricing'), awdp_free_manage_capability(), 'awdp_ui_settings', array($this, 'admin_ui_settings'));
        $this->hook_suffix[] = add_submenu_page(
            'awdp_admin_ui', __('Help', 'aco-woo-dynamic-pricing'), __('Help', 'aco-woo-dynamic-pricing'), awdp_free_manage_capability(), 'awdp_ui_help', array($this, 'awdp_ui_help'));
    }

    public function admin_ui()
    {
        AWDP_Backend::view('admin-root', []);
    }

    public function add_settings_link($links)
    {
        $settings   = '<a href="' . admin_url('admin.php?page=awdp_admin_ui#/') . '">' . __('Pricing Rules','aco-woo-dynamic-pricing') . '</a>';
        $products   = '<a href="' . admin_url('admin.php?page=awdp_admin_product_lists#/') . '">' . __('Product Lists','aco-woo-dynamic-pricing') . '</a>';
        $upgrade    = '<a href="https://acowebs.com/woocommerce-dynamic-pricing-with-discount-rules/" target="_blank" style="font-weight:600;color:#6D71F9;">' . __('Upgrade to PRO','aco-woo-dynamic-pricing') . '</a>';
        array_push($links, $settings);
        array_push($links, $products);
        array_push($links, $upgrade);
        return $links;
    }

    /**
     *    Create post type forms
     */

     static function view($view, $data = array())
    {
        extract($data);
        include(plugin_dir_path(__FILE__) . 'views/' . $view . '.php');
    }

    // End admin_enqueue_styles ()

    public function admin_ui_pro_lists()
    {
        AWDP_Backend::view('admin-lists', []);
    }

    public function admin_ui_settings()
    {
        AWDP_Backend::view('admin-settings', []);
    }

    public function awdp_ui_help()
    {
        AWDP_Backend::view('admin-help', []);
    }

    /**
     * Load admin CSS.
     * @access  public
     * @since   1.0.0
     * @return  void
     */
    public function admin_enqueue_styles($hook = '')
    {
        $currentScreen = get_current_screen();
        $screenID = $currentScreen->id; //
        if (strpos($screenID, 'awdp_') !== false) {

            wp_register_style($this->_token . '-admin', esc_url($this->assets_url) . 'css/backend.css', array(), $this->_version);
            wp_enqueue_style($this->_token . '-admin');
            
        }
    }

    /**
     * Load admin Javascript.
     * @access  public
     * @since   1.0.0
     * @return  void
     */
    public function admin_enqueue_scripts($hook = '')
    {

        $currentScreen = get_current_screen();
        $screenID = $currentScreen->id; //
        if (strpos($screenID, 'awdp_') !== false) {

            if (!isset($this->hook_suffix) || empty($this->hook_suffix)) {
                return;
            }

            // All Categories
            $categories = get_terms('product_cat', ['taxonomy' => 'product_cat', 'hide_empty' => false, 'fields' => 'id=>name']);

            // Product List
            $awdpList = get_posts(array('fields' => 'ids', 'numberposts' => -1, 'post_type' => AWDP_PRODUCT_LIST, 'orderby' => 'title', 'order' => 'ASC'));
            $awdpList = array_map(function ($v) {
                return ['id' => $v, 'name' => get_the_title($v)];
            }, $awdpList);

            // Rules
            $awdpRules = get_posts ( array ( 'fields' => 'ids', 'numberposts' => -1, 'post_type' => AWDP_POST_TYPE, 'orderby' => 'title', 'order' => 'ASC', 'meta_query' => array ( array ( 'key' => 'discount_status', 'value' => 1 ) ) ) );
            $awdpRules = array_map(function ($v) {
                return ['id' => $v, 'name' => get_the_title($v)];
            }, $awdpRules);

            // Tags
            $taglist        = get_terms(array('hide_empty' => false, 'taxonomy' => 'product_tag'));

            $permaStruc     = get_option( 'permalink_structure' );

            // $screen = get_current_screen();

            $defaultLabel   = get_option('awdp_fee_label') ? get_option('awdp_fee_label') : __("Discount", "aco-woo-dynamic-pricing");

            wp_enqueue_script('jquery');

            // User Roles
            $wp_roles = new WP_Roles();
            $user_roles = array_map(function ($v) {
                return $v['name'];
            }, $wp_roles->roles);

            if (in_array($currentScreen->id, $this->hook_suffix)) {

                if (!wp_script_is('wp-i18n', 'registered')) {
                    wp_register_script('wp-i18n', esc_url($this->assets_url) . 'js/i18n.min.js', array('jquery'), $this->_version, true);
                }

                wp_enqueue_script($this->_token . '-backend-script', esc_url($this->assets_url) . 'js/backend.js', array('jquery', 'wp-i18n'), $this->_version, true);
                wp_localize_script($this->_token . '-backend-script', 'awdp_object', array(
                        'api_nonce'     => wp_create_nonce('wp_rest'),
                        'root'          => rest_url('awdp/v1/'),
                        'adminUrl'      => admin_url(),
                        'cats'          => (array)$categories,
                        'tags'          => (array)$taglist,
                        'productlist'   => (array)$awdpList,
                        'defaultlabel'  => $defaultLabel,
                        'activerules'   => (array)$awdpRules,
                        'permalink'     => $permaStruc,
                        'user_roles'    => (array)$user_roles,
                        'awdpBaseurl'   => AWDP_FOLDER_PATH
                    )
                );

                $plugin_rel_path = (dirname($this->file)) . '\languages'; /* Relative to WP_PLUGIN_DIR */

                if ( AWDP_Wordpress_Version >= 5 ) {
                    wp_set_script_translations(AWDP_TOKEN . '-backend-script', 'aco-woo-dynamic-pricing', $plugin_rel_path);
                }

            }

        }

    }

    /**
     * Cloning is forbidden.
     *
     * @since 1.0.0
     */
    public function __clone()
    {
        _doing_it_wrong(__FUNCTION__, __('Cheatin&#8217; huh?'), $this->_version);
    }

    /**
     * Unserializing instances of this class is forbidden.
     *
     * @since 1.0.0
     */
    public function __wakeup()
    {
        _doing_it_wrong(__FUNCTION__, __('Cheatin&#8217; huh?'), $this->_version);
    }

    /**
     * Installation. Runs on activation.
     * @access  public
     * @since   1.0.0
     * @return  void
     */
    public function install()
    {
        $this->_log_version_number();

    }

    /**
     * Log the plugin version number.
     * @access  public
     * @since   1.0.0
     * @return  void
     */
    private function _log_version_number()
    {
        update_option($this->_token . '_version', $this->_version);
    }

    public function delete_transient($arg = false)
    {
        if ($arg) {
            in_array(get_post_type($arg), ['product', AWDP_POST_TYPE, AWDP_PRODUCT_LIST]) && delete_transient(AWDP_PRODUCTS_TRANSIENT_KEY);
        } else {
            delete_transient(AWDP_PRODUCTS_TRANSIENT_KEY);
        }

    }

}