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

        add_action('admin_footer', array($this, 'wdp_deactivation_form'));

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

        // Deactivation JS
        if ( $screenID == 'plugins' ) {
            wp_enqueue_script('awdp-deactivation-message', esc_url($this->assets_url).'js/message.js', array('jquery'), AWDP_VERSION, true);
        }

    }

    // Deactivation Form
    public function wdp_deactivation_form() {
        $currentScreen = get_current_screen();
        if ( ! $currentScreen || $currentScreen->id !== 'plugins' ) {
            return;
        }

        // Values are sent to the feedback service as-is; only the visible labels may change.
        $reasons = array(
            'wdp_temporarily' => array( "I'm only deactivating temporarily", __( "I'm only deactivating temporarily", 'aco-woo-dynamic-pricing' ) ),
            'wdp_notneeded'   => array( 'I no longer need the plugin', __( 'I no longer need the plugin', 'aco-woo-dynamic-pricing' ) ),
            'wdp_short'       => array( 'I only needed the plugin for a short period', __( 'I only needed it for a short period', 'aco-woo-dynamic-pricing' ) ),
            'wdp_better'      => array( 'I found a better plugin', __( 'I found a better plugin', 'aco-woo-dynamic-pricing' ) ),
            'wdp_upgrade'     => array( 'Upgrading to PRO version', __( 'Upgrading to the Pro version', 'aco-woo-dynamic-pricing' ) ),
            'wdp_requirement' => array( "Plugin doesn't meets my requirement", __( "It doesn't meet my requirements", 'aco-woo-dynamic-pricing' ) ),
            'wdp_broke'       => array( 'Plugin broke my site', __( 'The plugin broke my site', 'aco-woo-dynamic-pricing' ) ),
            'wdp_stopped'     => array( 'Plugin suddenly stopped working', __( 'The plugin suddenly stopped working', 'aco-woo-dynamic-pricing' ) ),
            'wdp_bug'         => array( 'I found a bug', __( 'I found a bug', 'aco-woo-dynamic-pricing' ) ),
            'wdp_other'       => array( 'Other', __( 'Other', 'aco-woo-dynamic-pricing' ) ),
        );
        ?>
        <div id="wdp-survey-form-wrap">
            <div id="wdp-survey-form" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="wdp-survey-title" aria-describedby="wdp-survey-intro">
                <div class="wdp-survey__header">
                    <span class="wdp-survey__icon" aria-hidden="true">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    </span>
                    <div class="wdp-survey__heading">
                        <h2 id="wdp-survey-title"><?php esc_html_e( 'Quick feedback', 'aco-woo-dynamic-pricing' ); ?></h2>
                        <p id="wdp-survey-intro"><?php esc_html_e( 'If you have a moment, please let us know why you are deactivating this plugin. All submissions are anonymous and we only use this feedback for improving our plugin.', 'aco-woo-dynamic-pricing' ); ?></p>
                    </div>
                    <button type="button" class="wdp-survey__close" id="wdp_close" aria-label="<?php esc_attr_e( 'Close', 'aco-woo-dynamic-pricing' ); ?>">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
                    </button>
                </div>
                <form method="POST">
                    <input name="Plugin" type="hidden" placeholder="Plugin" value="<?php echo esc_attr( AWDP_TOKEN ); ?>" required>
                    <input name="Version" type="hidden" placeholder="Version" value="<?php echo esc_attr( AWDP_VERSION ); ?>" required>
                    <input name="Date" type="hidden" placeholder="Date" value="<?php echo esc_attr( date( 'm/d/Y' ) ); ?>" required>
                    <input name="Website" type="hidden" placeholder="Website" value="<?php echo esc_url( get_site_url() ); ?>" required>
                    <input name="Title" type="hidden" placeholder="Title" value="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" required>
                    <div class="wdp-survey__body">
                        <div class="wdp-survey__reasons" role="radiogroup" aria-labelledby="wdp-survey-title">
                            <?php foreach ( $reasons as $id => $reason ) : ?>
                                <label class="wdp-survey__reason" for="<?php echo esc_attr( $id ); ?>">
                                    <input type="radio" id="<?php echo esc_attr( $id ); ?>" name="Reason" value="<?php echo esc_attr( $reason[0] ); ?>">
                                    <span><?php echo esc_html( $reason[1] ); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="wdp-comments" style="display:none;">
                            <label class="screen-reader-text" for="wdp_comments"><?php esc_html_e( 'Please specify', 'aco-woo-dynamic-pricing' ); ?></label>
                            <textarea id="wdp_comments" name="Comments" placeholder="<?php esc_attr_e( 'Please tell us a little more…', 'aco-woo-dynamic-pricing' ); ?>" rows="3"></textarea>
                            <p class="wdp-survey__support"><?php esc_html_e( 'Need help instead?', 'aco-woo-dynamic-pricing' ); ?> <a href="https://support.acowebs.com/portal/en/newticket?departmentId=361181000000006907&amp;layoutId=361181000000074011" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Submit a support ticket', 'aco-woo-dynamic-pricing' ); ?></a></p>
                        </div>
                        <p id="wdp-error" role="alert"></p>
                    </div>
                    <div class="wdp-survey__footer">
                        <a href="#" class="wdp_button wdp_button--link" id="wdp_skip"><?php esc_html_e( 'Skip & Deactivate', 'aco-woo-dynamic-pricing' ); ?></a>
                        <div class="wdp-survey__actions">
                            <a href="#" class="wdp_button wdp_button--secondary" id="wdp_cancel" role="button"><?php esc_html_e( 'Cancel', 'aco-woo-dynamic-pricing' ); ?></a>
                            <button type="submit" class="wdp_button wdp_button--primary" id="wdp_deactivate"><?php esc_html_e( 'Submit & Deactivate', 'aco-woo-dynamic-pricing' ); ?></button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <style>
            #wdp-survey-form-wrap { --wdp-primary: #4f46e5; --wdp-primary-hover: #4338ca; --wdp-primary-light: #eef2ff; --wdp-text: #0f172a; --wdp-text-secondary: #475569; --wdp-text-muted: #64748b; --wdp-border: #e2e8f0; --wdp-border-light: #f1f5f9; --wdp-danger: #dc2626; --wdp-focus-ring: 0 0 0 3px rgba(79, 70, 229, 0.35); display: none; position: fixed; inset: 0; z-index: 100000; align-items: center; justify-content: center; padding: 16px; background: rgba(15, 23, 42, 0.55); font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; }
            #wdp-survey-form-wrap.is-open { display: flex; animation: wdp-survey-fade 150ms ease-out; }
            #wdp-survey-form { display: flex; flex-direction: column; width: 100%; max-width: 600px; max-height: calc(100vh - 32px); background: #fff; border-radius: 14px; box-shadow: 0 20px 50px rgba(15, 23, 42, 0.25); color: var(--wdp-text); text-align: left; overflow: hidden; outline: none; animation: wdp-survey-rise 180ms ease-out; }
            #wdp-survey-form * { box-sizing: border-box; }
            #wdp-survey-form form { display: flex; flex-direction: column; min-height: 0; margin: 0; }
            .wdp-survey__header { display: flex; align-items: flex-start; gap: 14px; padding: 24px 24px 16px; }
            .wdp-survey__icon { flex: 0 0 auto; display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 10px; background: var(--wdp-primary-light); color: var(--wdp-primary); }
            .wdp-survey__heading { flex: 1 1 auto; min-width: 0; }
            #wdp-survey-form .wdp-survey__heading h2 { margin: 0 0 4px; padding: 0; font-size: 17px; line-height: 1.4; font-weight: 600; color: var(--wdp-text); }
            #wdp-survey-form .wdp-survey__heading p { margin: 0; padding: 0; font-size: 13px; line-height: 1.6; color: var(--wdp-text-secondary); }
            .wdp-survey__close { flex: 0 0 auto; display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; margin: -4px -6px 0 0; padding: 0; border: 0; border-radius: 6px; background: transparent; color: var(--wdp-text-muted); cursor: pointer; transition: background 150ms ease, color 150ms ease; }
            .wdp-survey__close:hover { background: var(--wdp-border-light); color: var(--wdp-text); }
            .wdp-survey__close:focus-visible { outline: none; box-shadow: var(--wdp-focus-ring); }
            .wdp-survey__body { padding: 4px 24px 8px; overflow-y: auto; }
            .wdp-survey__reasons { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
            #wdp-survey-form .wdp-survey__reason { display: flex; align-items: center; gap: 10px; min-height: 44px; margin: 0; padding: 10px 12px; border: 1px solid var(--wdp-border); border-radius: 8px; background: #fff; font-size: 13px; line-height: 1.4; color: var(--wdp-text); cursor: pointer; transition: border-color 150ms ease, background 150ms ease, box-shadow 150ms ease; }
            #wdp-survey-form .wdp-survey__reason:hover { border-color: #cbd5e1; background: #f8fafc; }
            #wdp-survey-form .wdp-survey__reason.is-selected, #wdp-survey-form .wdp-survey__reason:has(input:checked) { border-color: var(--wdp-primary); background: var(--wdp-primary-light); box-shadow: inset 0 0 0 1px var(--wdp-primary); }
            #wdp-survey-form .wdp-survey__reason:has(input:focus-visible) { box-shadow: var(--wdp-focus-ring); }
            #wdp-survey-form .wdp-survey__reason input[type="radio"] { flex: 0 0 auto; margin: 0; accent-color: var(--wdp-primary); }
            #wdp-survey-form .wdp-survey__reason input[type="radio"]:focus { box-shadow: none; outline: none; }
            #wdp-survey-form .wdp-comments { margin-top: 14px; }
            #wdp-survey-form .wdp-comments textarea { display: block; width: 100%; min-height: 76px; margin: 0; padding: 10px 12px; border: 1px solid var(--wdp-border); border-radius: 8px; font-family: inherit; font-size: 13px; line-height: 1.5; color: var(--wdp-text); background: #fff; resize: vertical; box-shadow: none; }
            #wdp-survey-form .wdp-comments textarea:focus { border-color: var(--wdp-primary); box-shadow: var(--wdp-focus-ring); outline: none; }
            #wdp-survey-form .wdp-survey__support { margin: 8px 0 0; padding: 0; font-size: 12px; line-height: 1.5; color: var(--wdp-text-muted); }
            #wdp-survey-form .wdp-survey__support a { color: var(--wdp-primary); font-weight: 500; text-decoration: none; }
            #wdp-survey-form .wdp-survey__support a:hover { text-decoration: underline; }
            #wdp-survey-form p#wdp-error { margin: 10px 0 0; padding: 0; font-size: 13px; line-height: 1.5; color: var(--wdp-danger); }
            #wdp-survey-form p#wdp-error:empty { display: none; }
            .wdp-survey__footer { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 16px 24px; margin-top: 12px; border-top: 1px solid var(--wdp-border-light); background: #f8fafc; }
            .wdp-survey__actions { display: flex; align-items: center; gap: 8px; }
            #wdp-survey-form .wdp_button { display: inline-flex; align-items: center; justify-content: center; min-height: 38px; margin: 0; padding: 9px 18px; border: 1px solid transparent; border-radius: 6px; font-family: inherit; font-size: 13px; font-weight: 500; line-height: 1.4; text-decoration: none; white-space: nowrap; cursor: pointer; box-shadow: none; transition: background 150ms ease, border-color 150ms ease, color 150ms ease, opacity 150ms ease; }
            #wdp-survey-form .wdp_button:focus-visible { outline: none; box-shadow: var(--wdp-focus-ring); }
            #wdp-survey-form .wdp_button--primary { background: var(--wdp-primary); border-color: var(--wdp-primary); color: #fff; }
            #wdp-survey-form .wdp_button--primary:hover:not(:disabled) { background: var(--wdp-primary-hover); border-color: var(--wdp-primary-hover); color: #fff; }
            #wdp-survey-form .wdp_button--primary:disabled { opacity: 0.55; cursor: not-allowed; }
            #wdp-survey-form .wdp_button--secondary { background: #fff; border-color: var(--wdp-border); color: var(--wdp-text-secondary); }
            #wdp-survey-form .wdp_button--secondary:hover { background: var(--wdp-border-light); border-color: #cbd5e1; color: var(--wdp-text); }
            #wdp-survey-form .wdp_button--link { padding-left: 4px; padding-right: 4px; background: transparent; color: var(--wdp-text-muted); }
            #wdp-survey-form .wdp_button--link:hover { color: var(--wdp-text); text-decoration: underline; }
            body.wdp-survey-open { overflow: hidden; }
            @keyframes wdp-survey-fade { from { opacity: 0; } to { opacity: 1; } }
            @keyframes wdp-survey-rise { from { opacity: 0; transform: translateY(8px) scale(0.98); } to { opacity: 1; transform: none; } }
            @media (prefers-reduced-motion: reduce) { #wdp-survey-form-wrap.is-open, #wdp-survey-form { animation: none; } }
            @media (max-width: 600px) {
                #wdp-survey-form-wrap { align-items: flex-end; padding: 0; }
                #wdp-survey-form { max-width: none; max-height: 92vh; border-radius: 14px 14px 0 0; }
                .wdp-survey__header { padding: 20px 16px 12px; }
                .wdp-survey__body { padding: 4px 16px 8px; }
                .wdp-survey__reasons { grid-template-columns: 1fr; }
                .wdp-survey__footer { flex-direction: column-reverse; align-items: stretch; padding: 12px 16px 16px; }
                .wdp-survey__actions { flex-direction: column-reverse; align-items: stretch; }
                #wdp-survey-form .wdp_button { width: 100%; min-height: 44px; }
            }
        </style>
    <?php }

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