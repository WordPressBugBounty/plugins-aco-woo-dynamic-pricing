<?php

if (!defined('ABSPATH'))
    exit;

class AWDP_Front_End
{

    static $cart_error = array();
    /**
     * The single instance of WordPress_Plugin_Template_Settings.
     * @var    object
     * @access  private
     * @since    1.0.0
     */
    private static $_instance = null;
    public $products = false;
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
     * The plugin assets URL.
     * @var     string
     * @access  public
     * @since   1.0.0
     */
    public $assets_url;
    /**
     * The main plugin file.
     * @var     string
     * @access  public
     * @since   1.0.0
     */
    public $file;

    private $discount;
    private $conversion_unit = false;
    /**
     * Check if price has to be display in cart and checkout
     * @var type
     * @var boolean
     * @access private
     * @since 3.4.2
     */
    private $show_price = false;
    private $cart_message_rendered = false;
    private $discount_notice_shown = false;

    function __construct($discount, $file = '', $version = '1.0.0') {

        $this->_version = $version;
        $this->_token   = AWDP_TOKEN;
        $this->discount = $discount;
        // $couponStatus   = get_option('awdp_apply_coupon_discount') ? get_option('awdp_apply_coupon_discount') : false;
        $couponStatus   = false;
        
        add_action('init', array($this, 'register_awdp_discounts'));

        // Deactivation hook
        add_action( 'deactivate_' . plugin_basename(dirname(AWDP_FILE)), array( $this, 'awdp_plugin_deactivate') );

        if ( $this->awdp_check_woocommerce_active() ) {

            add_action ( 'woocommerce_before_calculate_totals', array($this, 'wdpCalculateDiscount'), 1000, 1 );
            add_filter( 'woocommerce_get_cart_item_from_session', array( $this, 'restore_cart_item_from_session' ), 20, 3 );

            // Change Discount Price HTML View
            add_filter( 'woocommerce_get_price_html', array($this, 'get_product_price_html'), 100, 2 );
            
            // Cart Item Price
            add_filter( 'woocommerce_cart_item_price', array($this, 'cart_price_view'), 1000, 2 );
            add_filter( 'woocommerce_cart_item_price_html', array($this, 'cart_price_view'), 1000, 2 );
            add_filter( 'woocommerce_widget_cart_item_quantity', array( $this, 'widget_cart_item_quantity' ), 1000, 3 );
            add_filter( 'rest_post_dispatch', array( $this, 'align_store_api_cart_item_unit_prices' ), 20, 3 );
            // Block cart/checkout SSR hydrates Store API without rest_post_dispatch.
            add_filter( 'woocommerce_hydration_request_after_callbacks', array( $this, 'align_store_api_cart_item_unit_prices' ), 20, 3 );

            // Cart subtotal (priority 1000: after WCPA so split display HTML is authoritative)
            add_filter( 'woocommerce_cart_item_subtotal', array( $this, 'wdpCartLoop' ), 1000, 3 );

            // Coupon Management
            /*
            * Coupon status check added from version 4.0.0
            * Compatibility Fix
            */
            // if ( !$couponStatus ) { 
                add_action( 'admin_notices', array ( $this, 'wdpAdminNotice' ) );

                add_filter( 'woocommerce_get_shop_coupon_data', array( $this, 'addVirtualCoupon'), 99, 2 );
                add_action( 'woocommerce_after_calculate_totals', array( $this, 'applyFakeCoupons') );
                add_filter( 'woocommerce_cart_totals_coupon_label', array( $this, 'couponLabel'), 99, 2 );
                
                add_filter( 'woocommerce_coupon_message', array($this, 'coupon_message'), 15, 3 );
                add_filter( 'woocommerce_coupon_error', array($this, 'coupon_message'), 15, 3 );

                // Coupon interaction: block or strip regular coupons when mode requires it.
                add_filter( 'woocommerce_coupon_is_valid', array( $this, 'validate_regular_coupon_interaction' ), 10, 2 );
                add_action( 'woocommerce_before_calculate_totals', array( $this, 'remove_regular_coupons_if_blocked' ), 5, 1 );
            // }
            
            // Clear Show discount applied message on cart page
            add_action( 'woocommerce_checkout_update_order_meta', array($this, 'wdpfirstOrderMsg'));
            // Classic cart: add notice before notices are printed (after ensuring totals/discounts exist).
            add_action( 'woocommerce_before_cart', array( $this, 'ensure_cart_discount_notice' ), 5 );
            // Blocks Store API + any calculate_totals on cart context.
            add_action( 'woocommerce_after_calculate_totals', array( $this, 'show_cart_discount_notice' ), 1000, 1 );
            // Elementor / content fallbacks (after shortcodes so discounts are calculated).
            add_filter( 'the_content', array( $this, 'show_cart_discount_notice_via_content' ), 20 );
            add_filter( 'the_content', array( $this, 'append_saved_text_to_content' ), 100 );
            // Block cart markup fallback for You've Saved Text.
            add_filter( 'render_block', array( $this, 'append_saved_text_to_cart_block' ), 20, 2 );

            // Pricing table
            if( false === get_option('awdp_table_position') ){
                $tablePosition = get_option('tableposition');
            } else {
                $tablePosition = get_option('awdp_table_position');
            }

            if ( 'before_product' == $tablePosition ) {
                add_filter( 'woocommerce_before_single_product', array($this, 'show_pricing_table'), 100 );
            } else if ( 'before_product_summary' == $tablePosition ) {
                add_filter( 'woocommerce_before_single_product_summary', array($this, 'show_pricing_table'), 100 );
            } else if ( 'in_product_summary' == $tablePosition ) {
                add_filter( 'woocommerce_single_product_summary', array($this, 'show_pricing_table'), 100 );
            } else if ( 'before_form' == $tablePosition ) {
                add_filter( 'woocommerce_before_add_to_cart_form', array($this, 'show_pricing_table'), 100 );
            } else if ( 'before_variations_form' == $tablePosition ) {
                add_filter( 'woocommerce_before_variations_form', array($this, 'show_pricing_table'), 100 );
            } else if ( 'before_button' == $tablePosition ) {
                add_filter( 'woocommerce_before_add_to_cart_button', array($this, 'show_pricing_table'), 100 );
            } else if ( 'after_button' == $tablePosition ) {
                add_filter( 'woocommerce_after_add_to_cart_button', array($this, 'show_pricing_table'), 100 );
            } else if ( 'after_variations_form' == $tablePosition ) {
                add_filter( 'woocommerce_after_variations_form', array($this, 'show_pricing_table'), 100 );
            } else if ( 'after_form' == $tablePosition ) {
                add_filter( 'woocommerce_after_add_to_cart_form', array($this, 'show_pricing_table'), 100 );
            } else if ( 'meta_start' == $tablePosition ) {
                add_filter( 'woocommerce_product_meta_start', array($this, 'show_pricing_table'), 100 );
            } else if ( 'meta_end' == $tablePosition ) {
                add_filter( 'woocommerce_product_meta_end', array($this, 'show_pricing_table'), 100 );
            } else if ( 'after_product_summary' == $tablePosition ) {
                add_filter( 'woocommerce_after_single_product_summary', array($this, 'show_pricing_table'), 100 );
            } else if ( 'after_product' == $tablePosition ) {
                add_filter( 'woocommerce_after_single_product', array($this, 'show_pricing_table'), 100 );
            } else {
                add_filter( 'woocommerce_before_add_to_cart_button', array($this, 'show_pricing_table'), 100 );
            }

            // Offer Description
            $offer_desc_config  = get_option('awdp_disc_desc_config') ? get_option('awdp_disc_desc_config') : [];
            $offerMsgPos        = array_key_exists ( 'dismessage_position', $offer_desc_config ) ? $offer_desc_config['dismessage_position'] : '';
            $offerMsgEnable     = array_key_exists ( 'enable_dismessage', $offer_desc_config ) ? $offer_desc_config['enable_dismessage'] : '';
            if ( $offerMsgEnable ) {
                if ( 'before_product' == $offerMsgPos ) {
                    add_filter( 'woocommerce_before_single_product', array($this, 'show_offer_message'), 99 );
                } else if ( 'before_product_summary' == $offerMsgPos ) {
                    add_filter( 'woocommerce_before_single_product_summary', array($this, 'show_offer_message'), 99 );
                } else if ( 'in_product_summary' == $offerMsgPos ) {
                    add_filter( 'woocommerce_single_product_summary', array($this, 'show_offer_message'), 99 );
                } else if ( 'before_form' == $offerMsgPos ) {
                    add_filter( 'woocommerce_before_add_to_cart_form', array($this, 'show_offer_message'), 99 );
                } else if ( 'before_button' == $offerMsgPos ) {
                    add_filter( 'woocommerce_before_add_to_cart_button', array($this, 'show_offer_message'), 99 );
                } else if ( 'after_button' == $offerMsgPos ) {
                    add_filter( 'woocommerce_after_add_to_cart_button', array($this, 'show_offer_message'), 99 );
                } else if ( 'after_form' == $offerMsgPos ) {
                    add_filter( 'woocommerce_after_add_to_cart_form', array($this, 'show_offer_message'), 99 );
                } else if ( 'meta_start' == $offerMsgPos ) {
                    add_filter( 'woocommerce_product_meta_start', array($this, 'show_offer_message'), 99 );
                } else if ( 'meta_end' == $offerMsgPos ) {
                    add_filter( 'woocommerce_product_meta_end', array($this, 'show_offer_message'), 99 );
                } else if ( 'after_product_summary' == $offerMsgPos ) {
                    add_filter( 'woocommerce_after_single_product_summary', array($this, 'show_offer_message'), 99 );
                } else if ( 'after_product' == $offerMsgPos ) {
                    add_filter( 'woocommerce_after_single_product', array($this, 'show_offer_message'), 99 );
                } else {
                    add_filter( 'woocommerce_before_add_to_cart_button', array($this, 'show_offer_message'), 99 );
                }
            }
            
            // Adding Frontend Styles
            add_action( 'wp_footer', array($this, 'awdp_styles'), 10 );

            // WCPA Price
            add_filter( 'wcpa_product_price', array($this, 'wdpWCPAPrice'), 10, 2 );

            // Mini Cart
            add_action( 'woocommerce_widget_shopping_cart_total', array( $this, 'wdpMiniCart'), 15 );

            // Admin Order Page Customization
            add_action( 'woocommerce_admin_order_item_headers', array( $this, 'wdpAdminOrderHeader'), 10, 1 );
            add_action( 'woocommerce_admin_order_item_values', array( $this, 'wdpAdminOrderContent'), 10, 3 );
            // add_action( 'admin_footer', array( $this, 'wdpCustomJS') );

            /*
             *  Order Meta @@ Ver 5.0.4
             *  The 'woocommerce_add_order_item_meta' hook has been changed to 'woocommerce_new_order_item' @@ ver 3.0.0
             */
            // add_action( 'woocommerce_add_order_item_meta', array( $this, 'wdpOrderMeta'), 10, 3 );
            add_action( 'woocommerce_new_order_item', array( $this, 'wdpOrderMeta'), 10, 3 );
            add_action( 'woocommerce_after_order_itemmeta', array( $this, 'wdpDisplayOrderMeta'), 10, 3 );
            add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'hide_awdp_order_item_meta' ), 10, 1 );
            add_filter( 'woocommerce_order_item_get_formatted_meta_data', array( $this, 'filter_awdp_formatted_order_item_meta' ), 10, 2 );

            // Enqueue Scripts
            add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ), 10 );
            
            // Dynamic Pricing Table
            add_action( 'wp_ajax_wdpAjax', array( $this, 'wdpDynamicPricingTable') );
            add_action( 'wp_ajax_nopriv_wdpAjax', array( $this, 'wdpDynamicPricingTable') );

            // WCPA Discount For Frontend Quantity Change
            add_action( 'wp_ajax_wdpDynamicDiscount', array( $this, 'wdpDynamicDiscount') );
            add_action( 'wp_ajax_nopriv_wdpDynamicDiscount', array( $this, 'wdpDynamicDiscount') );

            // Adding WCPA Filed Value For Quantity Discount
            add_action( 'wp_ajax_wcpaQunantity_Discount', array( $this, 'wcpaQunantity_Discount') );
            add_action( 'wp_ajax_nopriv_wcpaQunantity_Discount', array( $this, 'wcpaQunantity_Discount') );

            // Cart Message (classic cart + collaterals fallback)
            add_action( 'woocommerce_after_cart_table', array( $this, 'wdpCartMessage') );
            add_action( 'woocommerce_before_cart_collaterals', array( $this, 'wdpCartMessage'), 5 );

            // WCPA 5.0.0
            add_filter ( 'wcpa_discount_rule', array( $this, 'wcpaDiscount' ), 10, 2);

        }
    }

    /**
     * Cart Message
    */

    public function wdpCartMessage()
    {
        if ( $this->cart_message_rendered ) {
            return;
        }

        $this->ensure_cart_discounts_ready();

        ob_start();
        $this->discount->wdpCartMessage();
        $saved_text = ob_get_clean();

        if ( $saved_text ) {
            $this->cart_message_rendered = true;
            echo $saved_text;
        }
    }

    /**
     * Append You've Saved Text on block-based / Elementor cart pages via page content.
     */
    public function append_saved_text_to_content( $content ) {
        if ( is_admin() || ! is_cart() || $this->cart_message_rendered ) {
            return $content;
        }

        $this->ensure_cart_discounts_ready();

        ob_start();
        $this->discount->wdpCartMessage();
        $saved_text = ob_get_clean();

        if ( $saved_text ) {
            $this->cart_message_rendered = true;
            $content .= $saved_text;
        }

        return $content;
    }

    /**
     * Append You've Saved Text after the WooCommerce Cart block.
     *
     * @param string $block_content Block HTML.
     * @param array  $block         Parsed block.
     * @return string
     */
    public function append_saved_text_to_cart_block( $block_content, $block ) {
        if ( $this->cart_message_rendered || empty( $block['blockName'] ) ) {
            return $block_content;
        }

        if ( 'woocommerce/cart' !== $block['blockName'] && 'woocommerce/filled-cart-block' !== $block['blockName'] ) {
            return $block_content;
        }

        // Prefer the outer cart block once; skip nested filled-cart if outer already handled.
        if ( 'woocommerce/filled-cart-block' === $block['blockName'] && false !== strpos( $block_content, 'wdp_save_text' ) ) {
            return $block_content;
        }

        $this->ensure_cart_discounts_ready();

        ob_start();
        $this->discount->wdpCartMessage();
        $saved_text = ob_get_clean();

        if ( $saved_text ) {
            $this->cart_message_rendered = true;
            $block_content .= $saved_text;
        }

        return $block_content;
    }

    /**
     * Ensure cart totals (and therefore AWDP discounts) have been calculated for this request.
     */
    private function ensure_cart_discounts_ready() {
        if ( ! WC()->cart ) {
            return;
        }

        if ( empty( $this->discount->discounts ) ) {
            WC()->cart->calculate_totals();
        }
    }

    /**
     * Build the discount-applied notice string.
     *
     * @return string
     */
    private function get_cart_discount_notice_text() {
        $label = get_option( 'awdp_fee_label' ) ? get_option( 'awdp_fee_label' ) : 'Discount';

        if ( get_option( 'awdp_discount_message' ) ) {
            return str_replace( '[label]', $label, get_option( 'awdp_discount_message' ) );
        }

        if ( 'discount' == mb_strtolower( $label, 'UTF-8' ) ) {
            return $label . __( ' has been applied!', 'aco-woo-dynamic-pricing' );
        }

        return __( "Discount '", 'aco-woo-dynamic-pricing' ) . $label . __( "' has been applied!", 'aco-woo-dynamic-pricing' );
    }

    /**
     * Whether the discount-applied notice should display (respects once-per-session setting).
     *
     * @return bool
     */
    private function should_show_cart_discount_notice() {
        if ( $this->discount_notice_shown ) {
            return false;
        }

        if ( get_option( 'awdp_message_status' ) != 1 ) {
            return false;
        }

        if ( empty( $this->discount->discounts ) ) {
            return false;
        }

        $message_once = ( get_option( 'awdp_message_once' ) !== false ) ? get_option( 'awdp_message_once' ) : 1;

        if ( $message_once == 1 && WC()->session && WC()->session->get( 'AWDP_CART_NOTICE' ) ) {
            return false;
        }

        return true;
    }

    /**
     * Register the WC notice and mark session when configured to show once.
     *
     * @param string $notice Notice text.
     */
    private function register_cart_discount_notice( $notice ) {
        if ( $this->discount_notice_shown ) {
            return;
        }

        $this->collapse_duplicate_discount_notices( $notice );

        // "success" maps cleanly to classic + Blocks snackbar notices.
        if ( false === wc_has_notice( $notice, 'success' ) && false === wc_has_notice( $notice, 'notice' ) ) {
            wc_add_notice( $notice, 'success' );
        }

        $message_once = ( get_option( 'awdp_message_once' ) !== false ) ? get_option( 'awdp_message_once' ) : 1;
        if ( $message_once == 1 && WC()->session ) {
            WC()->session->set( 'AWDP_CART_NOTICE', true );
        }

        $this->discount_notice_shown = true;
    }

    /**
     * Keep a single copy of the discount-applied notice in the WC notice queue.
     *
     * Store API add-to-cart requests previously queued the same notice once per item.
     *
     * @param string $notice Notice text.
     */
    private function collapse_duplicate_discount_notices( $notice ) {
        if ( $notice === '' || ! function_exists( 'wc_get_notices' ) || ! function_exists( 'wc_set_notices' ) ) {
            return;
        }

        $all = wc_get_notices();
        if ( empty( $all ) || ! is_array( $all ) ) {
            return;
        }

        $needle  = wp_strip_all_tags( $notice );
        $changed = false;
        $found   = false;

        foreach ( $all as $type => $entries ) {
            if ( ! is_array( $entries ) ) {
                continue;
            }

            $kept = array();

            foreach ( $entries as $entry ) {
                $text = '';
                if ( is_array( $entry ) && isset( $entry['notice'] ) ) {
                    $text = wp_strip_all_tags( (string) $entry['notice'] );
                } elseif ( is_string( $entry ) ) {
                    $text = wp_strip_all_tags( $entry );
                }

                if ( $text === $needle ) {
                    if ( $found ) {
                        $changed = true;
                        continue;
                    }
                    $found = true;
                }

                $kept[] = $entry;
            }

            $all[ $type ] = $kept;
        }

        if ( $changed ) {
            wc_set_notices( $all );
        }
    }

    /**
     * Mini Cart
    */

    public function wdpMiniCart()
    {

        echo $this->discount->wdpMiniCart();

    }
    
    /**
     * Admin Order 
    */

    public function wdpAdminOrderHeader($order)
    {
        
        echo $this->discount->wdpAdminOrderHeader($order);

    }

    public function wdpAdminOrderContent($_product, $item, $item_id = null)
    {
        
        echo $this->discount->wdpAdminOrderContent($_product, $item, $item_id = null);

    }
    
    public function wdpCustomJS()
    {
        
        echo $this->discount->wdpCustomJS();

    }
    
    /**
     * Clear Show discount applied message on cart page
    */
    
    public function wdpfirstOrderMsg(){
       
        WC()->session->set( 'AWDP_CART_NOTICE', null );
    }



    /**
     * Classic cart page, or Store API cart *view* (GET /wc/store/v1/cart).
     *
     * Mutations such as add-item must not queue cart notices: each shop add-to-cart
     * is a new REST request and would otherwise repeat the same message per product.
     */
    private function is_cart_context() {
        if ( is_cart() ) {
            return true;
        }

        return $this->is_store_api_cart_view_request();
    }

    /**
     * True for GET Store API cart reads only (not add-item, update-item, batch, checkout).
     *
     * @return bool
     */
    private function is_store_api_cart_view_request() {
        if ( ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
            return false;
        }

        $method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
        if ( 'GET' !== $method ) {
            return false;
        }

        $route = $this->get_store_api_request_route();
        if ( $route === '' ) {
            return false;
        }

        if ( false === strpos( $route, '/wc/store' ) || false !== strpos( $route, '/checkout' ) ) {
            return false;
        }

        $path = untrailingslashit( (string) wp_parse_url( $route, PHP_URL_PATH ) );
        if ( $path === '' ) {
            $path = untrailingslashit( $route );
        }
        if ( $path !== '' && isset( $path[0] ) && $path[0] !== '/' ) {
            $path = '/' . $path;
        }

        return (bool) preg_match( '#/wc/store(?:/v[0-9]+)?/cart$#', $path );
    }

    /**
     * Store API path from pretty permalinks or rest_route query (plain permalinks).
     *
     * @return string
     */
    private function get_store_api_request_route() {
        if ( isset( $GLOBALS['wp']->query_vars['rest_route'] ) && $GLOBALS['wp']->query_vars['rest_route'] ) {
            return (string) $GLOBALS['wp']->query_vars['rest_route'];
        }

        if ( isset( $_REQUEST['rest_route'] ) ) {
            return (string) wp_unslash( $_REQUEST['rest_route'] );
        }

        if ( empty( $_SERVER['REQUEST_URI'] ) ) {
            return '';
        }

        return (string) wp_unslash( $_SERVER['REQUEST_URI'] );
    }

    /**
     * Classic cart: ensure discounts exist, then register the discount-applied notice
     * before WooCommerce prints notices.
     */
    public function ensure_cart_discount_notice() {
        if ( get_option( 'awdp_message_status' ) != 1 ) {
            return;
        }

        $this->ensure_cart_discounts_ready();
        $this->collapse_duplicate_discount_notices( $this->get_cart_discount_notice_text() );

        if ( ! $this->should_show_cart_discount_notice() ) {
            if ( empty( $this->discount->discounts ) && WC()->session ) {
                WC()->session->set( 'AWDP_CART_NOTICE', null );
            }
            return;
        }

        $this->register_cart_discount_notice( $this->get_cart_discount_notice_text() );
    }

    /**
     * Show discount notice after totals for Blocks Store API requests.
     * Classic cart uses ensure_cart_discount_notice(); builders use the_content prepend.
     *
     * @param WC_Cart $cart Cart object.
     */
    public function show_cart_discount_notice( $cart ) {
        if ( ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ! $this->is_cart_context() ) {
            return;
        }

        if ( get_option( 'awdp_message_status' ) != 1 ) {
            return;
        }

        if ( empty( $this->discount->discounts ) ) {
            if ( WC()->session ) {
                WC()->session->set( 'AWDP_CART_NOTICE', null );
            }
            return;
        }

        $this->collapse_duplicate_discount_notices( $this->get_cart_discount_notice_text() );

        if ( ! $this->should_show_cart_discount_notice() ) {
            return;
        }

        $this->register_cart_discount_notice( $this->get_cart_discount_notice_text() );
    }

    /**
     * Show discount notice via HTML prepended to page content (Elementor / themes without notice hooks).
     *
     * @param string $content Post content.
     * @return string
     */
    public function show_cart_discount_notice_via_content( $content ) {
        if ( is_admin() || ! is_cart() || get_option( 'awdp_message_status' ) != 1 ) {
            return $content;
        }

        static $notice_prepended = false;
        if ( $notice_prepended ) {
            return $content;
        }

        $this->ensure_cart_discounts_ready();

        if ( empty( $this->discount->discounts ) ) {
            if ( WC()->session ) {
                WC()->session->set( 'AWDP_CART_NOTICE', null );
            }
            return $content;
        }

        $notice = $this->get_cart_discount_notice_text();
        $this->collapse_duplicate_discount_notices( $notice );

        // Block cart: Store API snackbar handles the notice (avoid duplicate page HTML).
        if ( function_exists( 'has_block' ) && has_block( 'woocommerce/cart' ) ) {
            return $content;
        }

        // Classic cart already printed the notice via woocommerce_before_cart.
        if ( $this->discount_notice_shown ) {
            return $content;
        }

        if ( ! $this->should_show_cart_discount_notice() ) {
            return $content;
        }

        // If WC already has the notice queued (and will print it), skip HTML prepend.
        if ( wc_has_notice( $notice, 'success' ) || wc_has_notice( $notice, 'notice' ) ) {
            $this->discount_notice_shown = true;
            return $content;
        }

        $notice_html  = '<div class="woocommerce-notices-wrapper">';
        $notice_html .= '<div class="woocommerce-message" role="alert">';
        $notice_html .= esc_html( $notice );
        $notice_html .= '</div>';
        $notice_html .= '</div>';

        $message_once = ( get_option( 'awdp_message_once' ) !== false ) ? get_option( 'awdp_message_once' ) : 1;
        if ( $message_once == 1 && WC()->session ) {
            WC()->session->set( 'AWDP_CART_NOTICE', true );
        }

        $this->discount_notice_shown = true;
        $notice_prepended            = true;

        return $notice_html . $content;
    }

    /**
     * Order Meta Save 
    */

    public function wdpOrderMeta($item_id, $values, $cart_item_key)
    {
        
        echo $this->discount->wdpOrderMeta($item_id, $values, $cart_item_key);

    }

    /**
     * Order Meta Display
    */

    public function wdpDisplayOrderMeta( $item_id, $item, $product )
    {

        echo $this->discount->wdpDisplayOrderMeta( $item_id, $item, $product );

    }
    
    /**
     * Load frontend Javascript.
     * @access  public
     * @since   4.0.6
     * @return  void
     */
    public function enqueue_scripts()
    {

        /*
        * Price Group @ version 4.0.5
        */
        $new_config         = get_option('awdp_new_config') ? get_option('awdp_new_config') : []; 

        $frontend_js = plugin_dir_path( AWDP_FILE ) . 'assets/js/frontend.js';
        $frontend_ver = file_exists( $frontend_js ) ? (string) filemtime( $frontend_js ) : $this->_version;

        wp_register_script('awd-script', AWDP_FOLDER_PATH . 'assets/js/frontend.js', array('jquery'), $frontend_ver);
        wp_localize_script('awd-script', 'awdajaxobject', 
            array( 
                'url'               => admin_url('admin-ajax.php'), 
                'nonce'             => wp_create_nonce('awdpnonce'),
                'priceGroup'        => $this->discount->wdpWCPAVariationPrice(),
                'dynamicPricing'    => array_key_exists ( 'dynamicpricing', $new_config ) ? $new_config['dynamicpricing'] : '',
                'variablePricing'   => array_key_exists ( 'variablepricing', $new_config ) ? $new_config['variablepricing'] : '',
                'thousandSeparator' => get_option('woocommerce_price_thousand_sep'),
                'decimalSeparator'  => get_option('woocommerce_price_decimal_sep'),
                'priceDecimals'     => wc_get_price_decimals(),
                'currencySymbol'    => get_woocommerce_currency_symbol()
            )
        );

        wp_enqueue_script('awd-script');

        wp_register_style('wdp-style', AWDP_FOLDER_PATH . 'assets/css/frontend.css', array(), $this->_version);
        
        wp_enqueue_style('wdp-style');

    }

    // Discount Calculation
    public function wdpCalculateDiscount ($cartOject) {

        return $this->discount->wdpCalculateDiscount($cartOject);

    }

    /**
     * Persists line-price metadata when the cart is loaded from session.
     *
     * Validates the cached base price against the live catalog price before restoring it.
     * If the product price was changed in the admin since the session was last written, the
     * stale value is discarded so that wdpCalculateDiscount() works from the correct live price.
     *
     * @param array  $cart_item Cart line.
     * @param array  $values    Session values.
     * @param string $cart_key  Cart item key.
     */
    public function restore_cart_item_from_session($cart_item, $values, $cart_key)
    {
        if ( isset( $values['awdp_wcpa_split'] ) ) {
            $cart_item['awdp_wcpa_split'] = (bool) $values['awdp_wcpa_split'];
        }
        if ( isset( $values['awdp_wcpa_addon_unit'] ) ) {
            $cart_item['awdp_wcpa_addon_unit'] = (float) $values['awdp_wcpa_addon_unit'];
        }

        if (!isset($values['awdp_price_before_discount'])) {
            return $cart_item;
        }

        $cached_base = (float) $values['awdp_price_before_discount'];

        // WCPA split/baked-in bases are not plain catalog prices — keep the session value.
        if ( $cached_base > 0 && ( ! empty( $values['awdp_wcpa_split'] ) || isset( $values['awdp_wcpa_addon_unit'] ) ) ) {
            $cart_item['awdp_price_before_discount'] = $cached_base;
            return $cart_item;
        }

        // Resolve the correct product ID (variation takes priority).
        $product_id  = !empty($cart_item['variation_id']) ? (int) $cart_item['variation_id'] : (int) $cart_item['product_id'];

        // Fetch the live product to get the current catalog price.
        // At session-restore time the WC product object has not been mutated yet, so
        // get_price() returns the true live catalog price (sale price if on sale, otherwise regular price).
        $live_product = wc_get_product($product_id);

        if (!$live_product) {
            // Fallback: cannot verify, so restore as-is to avoid breaking non-price-change sessions.
            $cart_item['awdp_price_before_discount'] = $cached_base;
            return $cart_item;
        }

        $live_price = (float) $live_product->get_price();

        // Only restore the cached base price when the live catalog price has not changed.
        // A tolerance of 0.0001 handles floating-point representation differences.
        if ($live_price > 0 && abs($live_price - $cached_base) < 0.0001) {
            $cart_item['awdp_price_before_discount'] = $cached_base;
        }
        // Otherwise: discard the stale session value silently.
        // restore_cart_line_base_prices() will detect the price change via wc_get_product()
        // and reset the product object to the live price on the next totals calculation.

        return $cart_item;
    }

    /**
     * Hides internal AWDP meta from the default order item meta list in admin.
     *
     * @param string[] $hidden_meta Hidden meta keys.
     * @return string[]
     */
    public function hide_awdp_order_item_meta($hidden_meta)
    {
        if (!is_array($hidden_meta)) {
            $hidden_meta = array();
        }

        return array_merge($hidden_meta, AWDP_Discount_Order::hidden_order_item_meta_keys());
    }

    /**
     * Removes internal AWDP meta from formatted meta (HPOS / block editor).
     *
     * @param array         $formatted_meta Formatted meta objects.
     * @param WC_Order_Item $item           Order item.
     * @return array
     */
    public function filter_awdp_formatted_order_item_meta($formatted_meta, $item)
    {
        if (empty($formatted_meta)) {
            return $formatted_meta;
        }

        $hidden = AWDP_Discount_Order::hidden_order_item_meta_keys();

        foreach ($formatted_meta as $meta_id => $meta) {
            $key = is_object($meta) && isset($meta->key) ? $meta->key : '';
            if (in_array($key, $hidden, true)) {
                unset($formatted_meta[$meta_id]);
            }
        }

        return $formatted_meta;
    }

    //Offer message
    public function show_offer_message() {

        if ( !is_admin() ) 
            return $this->discount->show_offer_message();
        else
            return '';

    }

    // WCPA Price
    public function wdpWCPAPrice( $default, $product ){

        return $this->discount->wdpWCPAPrice( $default, $product );

    }
    
    //Adding WCPA Filed Value For Quantity Discount
    public function wcpaQunantity_Discount() {

        echo $this->discount->wcpaQunantity_Discount();
        die();
    }

    //Addons Products Price For Quantity Change
    public function wdpDynamicDiscount()
    {
        echo $this->discount->wdpDynamicDiscount();
        die();
    }

    public function wcpaDiscount($response, $product) 
    {

        return $this->discount->wcpaDiscount($response, $product);

    }

    public function wdpDynamicPricingTable()
    {

        echo $this->discount->wdpDynamicPricingTable();

    }

    // Handling Coupon
    public function addVirtualCoupon($response, $curr_coupon_code) { 

        return $this->discount->addVirtualCoupon($response, $curr_coupon_code);

    }
    public function applyFakeCoupons() {

        return $this->discount->applyFakeCoupons();

    }
    public function couponLabel($label, $coupon) {

        return $this->discount->couponLabel($label, $coupon);

    }
    public function coupon_message($msg, $msg_code, $coupon=null) {

        if ($coupon === null) {
            return $msg;
        }

        $awdappliedCode = $coupon->get_code();
        $awdpluginLabel = get_option('awdp_fee_label') ? get_option('awdp_fee_label') : 'Discount';
        if ( $awdappliedCode == $awdpluginLabel || mb_strtolower($awdappliedCode, 'UTF-8') == mb_strtolower($awdpluginLabel, 'UTF-8')) {
            return '';
        }
        return $msg;

    }

    /**
     * Reject WooCommerce coupons when interaction mode is Dynamic Pricing only.
     *
     * @param bool      $valid  Whether the coupon is valid.
     * @param WC_Coupon $coupon Coupon object.
     * @return bool
     */
    public function validate_regular_coupon_interaction( $valid, $coupon ) {

        if ( ! $valid || ! $coupon ) {
            return $valid;
        }

        if ( awdp_should_block_regular_coupons() && ! awdp_is_virtual_coupon_code( $coupon->get_code() ) ) {
            throw new \Exception(
                __( 'Sorry, this coupon cannot be applied. A promotional discount is already active on your cart, and coupon codes cannot be used at the same time.', 'aco-woo-dynamic-pricing' )
            );
        }

        return $valid;

    }

    /**
     * Strip already-applied store coupons in Dynamic Pricing only mode.
     *
     * @param WC_Cart $cart Cart object.
     */
    public function remove_regular_coupons_if_blocked( $cart = null ) {

        if ( method_exists( $this->discount, 'remove_regular_coupons_if_blocked' ) ) {
            $this->discount->remove_regular_coupons_if_blocked();
        }

    }

    /**
     * Show quantity discount on cart items
     * @param $cart_obj object
    **/
    public function wdpCartLoop ( $wc, $cart_content, $cart_item_key ) {

        return $this->discount->wdpCartLoop( $wc, $cart_content, $cart_item_key );

    }

    // Admin Notices
    public function wdpAdminNotice () {

        if ( 'yes' !== get_option( 'woocommerce_enable_coupons' ) ) { ?>
            <div class="error">
                <p><strong><?php echo AWDP_PLUGIN_NAME; ?></strong> uses virtual coupons for applying discounts. For proper working of our plugin, please enable coupons (WooCommerce -> Settings -> Enable coupons).</p>
            </div>
        <?php }

        // Checking Permalink
        $wdp_permalink = get_option( 'permalink_structure' ); 
        if ( $wdp_permalink === '' ) { ?>
            <div class="error">
                <p>If you are facing any loading issues with <strong><?php echo AWDP_PLUGIN_NAME; ?></strong>, please make sure the permalink settings is not set to plain (Settings -> Permalinks).</p>
            </div>
        <?php }

    }

    //
    public function cart_price_view( $item_price, $cart_item ) {

        return $this->discount->cart_discount_items( $item_price, $cart_item );

    }

    /**
     * Mini-cart "qty × price" uses the calculated line unit only (no strike-through).
     *
     * Classic mini-cart reuses woocommerce_cart_item_price, which may include a
     * struck original for the main cart. Replace it with the applicable price.
     *
     * @param string $html          Default quantity HTML.
     * @param array  $cart_item     Cart line.
     * @param string $cart_item_key Cart item key.
     * @return string
     */
    public function widget_cart_item_quantity( $html, $cart_item, $cart_item_key ) {
        if ( empty( $cart_item['awdp_price_before_discount'] ) ) {
            return $html;
        }

        $unit = $this->discount->get_cart_item_calculated_unit_price( $cart_item );
        if ( $unit === null ) {
            return $html;
        }

        $original = (float) $cart_item['awdp_price_before_discount'];
        if ( $original <= $unit + 0.0001 ) {
            return $html;
        }

        $addon_unit = function_exists( 'awdp_get_wcpa_display_addon_unit' )
            ? awdp_get_wcpa_display_addon_unit( $cart_item )
            : 0;
        $qty        = isset( $cart_item['quantity'] ) ? $cart_item['quantity'] : 1;
        $price_html = wc_price( $unit + (float) $addon_unit );

        return '<span class="quantity">' . sprintf( '%s &times; %s', $qty, $price_html ) . '</span>';
    }

    /**
     * Store API / block cart, checkout, and mini-cart: align displayed item prices.
     *
     * Also hooked on woocommerce_hydration_request_after_callbacks because block
     * cart/checkout SSR does not run rest_post_dispatch.
     *
     * @param mixed            $response Response object.
     * @param mixed            $handler  Route handler.
     * @param WP_REST_Request  $request  Request object.
     * @return mixed
     */
    public function align_store_api_cart_item_unit_prices( $response, $handler, $request ) {
        if ( is_wp_error( $response ) || ! $request instanceof WP_REST_Request ) {
            return $response;
        }

        if ( ! is_object( $response ) || ! method_exists( $response, 'get_data' ) || ! method_exists( $response, 'set_data' ) ) {
            return $response;
        }

        $route = (string) $request->get_route();
        if ( false === strpos( $route, '/wc/store' ) ) {
            return $response;
        }

        $data = $response->get_data();
        if ( empty( $data['items'] ) || ! is_array( $data['items'] ) ) {
            return $response;
        }

        foreach ( $data['items'] as $index => $item ) {
            if ( is_object( $item ) ) {
                $item = json_decode( wp_json_encode( $item ), true );
            }
            if ( is_array( $item ) ) {
                $data['items'][ $index ] = $this->discount->align_store_api_cart_item_prices( $item );
            }
        }

        $response->set_data( $data );

        return $response;
    }

    // Price HTML Display
    public function get_product_price_html( $price, $product ) {

        return $this->discount->get_product_price_html( $price, $product );

    }

    // Pricing table
    public function show_pricing_table() {

        if ( !is_admin() ) 
            return $this->discount->show_pricing_table();
        else
            return '';

    }

    // Check if woocommerce plugin is active
    public function awdp_check_woocommerce_active() {

        if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
            return true;
        }
        if (is_multisite()) {
            $plugins = get_site_option('active_sitewide_plugins');
            if (isset($plugins['woocommerce/woocommerce.php']))
                return true;
        }
        return false;

    }

    // Deactivate plugin
    public function awdp_plugin_deactivate() {
        global $wpdb;
        // $wpdb->query( 
        //     $wpdb->prepare( 
        //         "DELETE pm FROM {$wpdb->prefix}postmeta pm INNER JOIN {$wpdb->prefix}posts wp ON wp.ID = pm.post_id WHERE pm.meta_key = '".AWDP_Feed_Attribute."';" 
        //     )
        // );
        $wpdb->query( 
            $wpdb->prepare( 
                "UPDATE {$wpdb->prefix}postmeta pm INNER JOIN {$wpdb->prefix}posts p on p.ID = pm.post_id SET pm.meta_value = '' WHERE pm.meta_key = '".AWDP_Feed_Attribute."';"
            )
        );
    }

    // Inline Styles
    public function awdp_styles() {

        // Hide Coupon Box (Dynamic Pricing only mode, or legacy flag).
        $hideCouponBox = awdp_should_hide_coupon_box();

        $couponLabel = get_option('awdp_fee_label') ? mb_strtolower ( get_option('awdp_fee_label') ) : 'discount';
        // $styleLabel = get_option('awdp_fee_label') ? str_replace(' ', '-', mb_strtolower ( get_option('awdp_fee_label') ) ) : 'discount'; 
        $bordercolor = get_option('awdp_table_border') ? get_option('awdp_table_border') : ''; 
        $tablefontsize = get_option('awdp_tablefontsize') ? ( get_option('awdp_tablefontsize') != '0' ? get_option('awdp_tablefontsize') : '' ) : ''; ?>

        <style> .wdp_table_outter{padding:10px 0;} .wdp_table_outter h4{margin: 10px 0 15px 0;} table.wdp_table{border-top-style:solid; border-top-width:1px !important; border-top-color:<?php if ( $bordercolor == '' ) echo 'inherit'; else echo $bordercolor; ?>; border-right-style:solid; border-right-width:1px !important; border-right-color:<?php if ( $bordercolor == '' ) echo 'inherit'; else echo $bordercolor; ?>;border-collapse: collapse; margin-bottom:0px; <?php if ( $tablefontsize ) { echo 'font-size:'.$tablefontsize.'px'; } ?> } table.wdp_table td{border-bottom-style:solid; border-bottom-width:1px !important; border-bottom-color:<?php if ( $bordercolor == '' ) echo 'inherit'; else echo $bordercolor; ?>; border-left-style:solid; border-left-width:1px !important; border-left-color:<?php if ( $bordercolor == '' ) echo 'inherit'; else echo $bordercolor; ?>; padding:10px 20px !important;} <?php if( $bordercolor != '' ) { ?> table.wdp_table td, table.wdp_table tr { border: 1px solid <?php echo $bordercolor; ?> } <?php } ?>table.wdp_table.lay_horzntl td{padding:10px 15px !important;} a[data-coupon="<?php echo $couponLabel; ?>"]{ display: none; } .wdp_helpText{ font-size: 12px; top: 5px; position: relative; } @media screen and (max-width: 640px) { table.wdp_table.lay_horzntl { width:100%; } table.wdp_table.lay_horzntl tbody.wdp_table_body { width:100%; display:block; } table.wdp_table.lay_horzntl tbody.wdp_table_body tr { display:inline-block; width:50%; box-sizing:border-box; } table.wdp_table.lay_horzntl tbody.wdp_table_body tr td {display: block; text-align:left;}} <?php if ( $hideCouponBox )  { ?> .woocommerce-cart-form .coupon, .woocommerce-cart .coupon, .woocommerce-form-coupon-toggle, .woocommerce .checkout_coupon { display:none !important; } <?php } ?> .awdpOfferMsg { width: 100%; float: left; margin: 20px 0px; box-sizing: border-box; display: block !important; } .awdpOfferMsg span, .awdpOfferMsg div { display: inline-block; } .wdp_miniCart { border: none !important; line-height: 30px; width: 100%; float: left; margin: 0px 0 30px 0; } .wdp_miniCart strong{ float: left; } /* .wdp_miniCart span { float: right; } */ .wdp_miniCart .woocommerce-Price-amount{ float: right; } .wdp_miniCart span.wdpLabel { float: left; } .theme-astra .wdp_miniCart{ float: none; } .wc-block-mini-cart .wc-block-components-product-price del, .wc-block-mini-cart .wc-block-components-product-price__regular, .widget_shopping_cart del, .woocommerce-mini-cart del { display: none !important; } </style>

        <?php 

    }

    // Register Custom post types
    public function register_awdp_discounts() {

        $post_type = AWDP_POST_TYPE;
        $labels = array(
            'name' => __('Pricing Rules', 'aco-woo-dynamic-pricing'),
            'singular_name' => __('Pricing Rule', 'aco-woo-dynamic-pricing'),
            'name_admin_bar' => 'WCPA_Form',
            'add_new' => _x('Add New Product Form', $post_type, 'aco-woo-dynamic-pricing'),
            'add_new_item' => sprintf(__('Add New %s', 'aco-woo-dynamic-pricing'), 'Form'),
            'edit_item' => sprintf(__('Edit %s', 'aco-woo-dynamic-pricing'), 'Form'),
            'new_item' => sprintf(__('New %s', 'aco-woo-dynamic-pricing'), 'Form'),
            'all_items' => sprintf(__('Product Rules', 'aco-woo-dynamic-pricing'), 'Form'),
            'view_item' => sprintf(__('View %s', 'aco-woo-dynamic-pricing'), 'Form'),
            'search_items' => sprintf(__('Search %s', 'aco-woo-dynamic-pricing'), 'Form'),
            'not_found' => sprintf(__('No %s Found', 'aco-woo-dynamic-pricing'), 'Form'),
            'not_found_in_trash' => sprintf(__('No %s Found In Trash', 'aco-woo-dynamic-pricing'), 'Form'),
            'parent_item_colon' => sprintf(__('Parent %s'), 'Form'),
            'menu_name' => 'Custom Product Options'
        );
        $args = array(
            'labels' => apply_filters($post_type . '_labels', $labels),
            'description' => '',
            'public' => false,
            'publicly_queryable' => false,
            'exclude_from_search' => true,
            'show_ui' => false,
            // 'show_in_menu' => 'edit.php?post_type=product',
            'show_in_nav_menus' => false,
            'query_var' => false,
            'can_export' => true,
            'rewrite' => false,
            'capability_type' => 'post',
            'has_archive' => false,
            'rest_base' => $post_type,
            'hierarchical' => false,
            'show_in_rest' => false,
            'rest_controller_class' => 'WP_REST_Posts_Controller',
            'supports' => array('title'),
            'menu_position' => 5,
            'menu_icon' => 'dashicons-admin-post'
        );
        register_post_type($post_type, apply_filters($post_type . '_register_args', $args, $post_type));

        // Product Lists
        $post_type = AWDP_PRODUCT_LIST;
        $labels = array(
            'name' => __('Product Lists', 'aco-woo-dynamic-pricing'),
            'singular_name' => __('Product List', 'aco-woo-dynamic-pricing'),
            'name_admin_bar' => 'WCPA_Form',
            'add_new' => _x('Add New Product List', $post_type, 'aco-woo-dynamic-pricing'),
            'add_new_item' => sprintf(__('Add New %s', 'aco-woo-dynamic-pricing'), 'Form'),
            'edit_item' => sprintf(__('Edit %s', 'aco-woo-dynamic-pricing'), 'Form'),
            'new_item' => sprintf(__('New %s', 'aco-woo-dynamic-pricing'), 'Form'),
            'all_items' => sprintf(__('Product Lists', 'aco-woo-dynamic-pricing'), 'Form'),
            'view_item' => sprintf(__('View %s', 'aco-woo-dynamic-pricing'), 'Form'),
            'search_items' => sprintf(__('Search %s', 'aco-woo-dynamic-pricing'), 'Form'),
            'not_found' => sprintf(__('No %s Found', 'aco-woo-dynamic-pricing'), 'Form'),
            'not_found_in_trash' => sprintf(__('No %s Found In Trash', 'aco-woo-dynamic-pricing'), 'Form'),
            'parent_item_colon' => sprintf(__('Parent %s'), 'Form'),
            'menu_name' => 'Custom Product Options'
        );
        $args = array(
            'labels' => apply_filters($post_type . '_labels', $labels),
            'description' => '',
            'public' => false,
            'publicly_queryable' => false,
            'exclude_from_search' => true,
            'show_ui' => false,
            // 'show_in_menu' => 'edit.php?post_type=product',
            'show_in_nav_menus' => false,
            'query_var' => false,
            'can_export' => true,
            'rewrite' => false,
            'capability_type' => 'post',
            'has_archive' => false,
            'rest_base' => $post_type,
            'hierarchical' => false,
            'show_in_rest' => false,
            'rest_controller_class' => 'WP_REST_Posts_Controller',
            'supports' => array('title'),
            'menu_position' => 5,
            'menu_icon' => 'dashicons-admin-post'
        );
        register_post_type($post_type, apply_filters($post_type . '_register_args', $args, $post_type));

    }

}
