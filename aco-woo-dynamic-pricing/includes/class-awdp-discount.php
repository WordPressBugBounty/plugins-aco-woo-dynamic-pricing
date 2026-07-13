<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/discount/class-awdp-discount-module.php';
require_once __DIR__ . '/discount/class-awdp-discount-application.php';
require_once __DIR__ . '/discount/class-awdp-discount-rules.php';
require_once __DIR__ . '/discount/class-awdp-discount-cart.php';
require_once __DIR__ . '/discount/class-awdp-discount-display.php';
require_once __DIR__ . '/discount/class-awdp-discount-wcpa.php';
require_once __DIR__ . '/discount/class-awdp-discount-coupon.php';
require_once __DIR__ . '/discount/class-awdp-discount-order.php';
require_once __DIR__ . '/discount/class-awdp-discount-utilities.php';

class AWDP_Discount
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
    public $product_lists           = false;
    public $awdp_cart_rules         = false;
    public $apply_wdp_coupon        = false;
    public $product_line_prices_applied = false;
    public $pricing_table           = [];
    public $productvariations       = [];
    public $couponLabel             = '';
    public $wdp_discounted_price    = [];
    public $wdpCartDicount          = [];
    public $wdpCartDiscountValues   = [];
    public $awdp_cart_rule_ids      = [];
    public $variations              = [];
    public $variation_prods         = [];
    public $wdpQNitems              = [];
    public $actual_price            = [];
    public $wdp_order_meta          = [];
    public $awdp_discount_applied   = [];
    public $_active                = false;
    public $types                  = array();
    public $discount_rules         = false;
    public $conversion_unit        = false;
    public $converted_rate         = '';

    public $discounts              = array();
    public $discounted_products    = array();
    public $discountProductPrice    = '';
    public $discountProductMaxPrice = '';
    public $discountProductMinPrice = '';
    public $products_on_sale        = [];

    /** @var AWDP_Discount_Rules */
    public $rules;

    /** @var AWDP_Discount_Cart */
    public $cart;

    /** @var AWDP_Discount_Display */
    public $display;

    /** @var AWDP_Discount_Wcpa */
    public $wcpa;

    /** @var AWDP_Discount_Coupon */
    public $coupon;

    /** @var AWDP_Discount_Order */
    public $order;

    /** @var AWDP_Discount_Utilities */
    public $utilities;
    public function __construct()
    {

        $this->types = Array(
            'percent_total_amount'  => __('Percentage of cart total amount', 'aco-woo-dynamic-pricing'),
            'percent_product_price' => __('Percentage of product price', 'aco-woo-dynamic-pricing'),
            'fixed_product_price'   => __('Fixed price of product price', 'aco-woo-dynamic-pricing'),
            'fixed_cart_amount'     => __('Fixed price of cart total amount', 'aco-woo-dynamic-pricing'),
            'cart_quantity'         => __('Quantity based discount', 'aco-woo-dynamic-pricing')
        );

        $this->init_modules();

    }

    /**
     * Ensures only one instance of AWDP is loaded or can be loaded.
     * @since 1.0.0
     * @static
     * @see WordPress_Plugin_Template()
     * @return Main AWDP instance
     */

    public static function instance($file = '', $version = '1.0.0')
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new self($file, $version);
        }
        return self::$_instance;
    }

    /**
     * @return bool
     */

    public function isActive()
    {

        return $this->_active;

    }

    public function init_modules()
    {
        $this->utilities = new AWDP_Discount_Utilities($this);
        $this->rules     = new AWDP_Discount_Rules($this);
        $this->cart      = new AWDP_Discount_Cart($this);
        $this->display   = new AWDP_Discount_Display($this);
        $this->wcpa      = new AWDP_Discount_Wcpa($this);
        $this->coupon    = new AWDP_Discount_Coupon($this);
        $this->order     = new AWDP_Discount_Order($this);
    }

    public function validate_discount_rules($cart_obj, $rule, $rules_to_validate = array(), $item = false, $single = false)
    {
        return $this->rules->validate_discount_rules($cart_obj, $rule, $rules_to_validate, $item, $single);
    }

    public function eval_rule($rule, $cart_obj, $discount_rule, $list_id, $qn_flag, $item = false, $single = false)
    {
        return $this->rules->eval_rule($rule, $cart_obj, $discount_rule, $list_id, $qn_flag, $item, $single);
    }

    public function load_rules()
    {
        return $this->rules->load_rules();
    }

    public function get_items_to_apply_discount($product, $rule, $disc_prod_ID = false, $cartRule = false, $product_slug = false)
    {
        return $this->rules->get_items_to_apply_discount($product, $rule, $disc_prod_ID, $cartRule, $product_slug);
    }

    public function check_in_product_list($product, $rule)
    {
        return $this->rules->check_in_product_list($product, $rule);
    }

    public function set_product_list()
    {
        return $this->rules->set_product_list();
    }

    public function set_custom_list($product_list)
    {
        return $this->rules->set_custom_list($product_list);
    }

    public function check_in_rule($selectedRule, $productID)
    {
        return $this->rules->check_in_rule($selectedRule, $productID);
    }

    public function wdpCalculateDiscount($cartObject)
    {
        return $this->cart->wdpCalculateDiscount($cartObject);
    }

    public function cart_discount_items($item_price, $cart_item)
    {
        return $this->cart->cart_discount_items($item_price, $cart_item);
    }

    public function wdpCartLoop($wc, $cart_item, $cart_item_key)
    {
        return $this->cart->wdpCartLoop($wc, $cart_item, $cart_item_key);
    }

    public function check_discount($slug)
    {
        return $this->cart->check_discount($slug);
    }

    public function check_discount_shop($slug)
    {
        return $this->cart->check_discount_shop($slug);
    }

    public function get_individual_discounted_price_in_cents($item, $include_tax = true, $sequential = false, $price = false)
    {
        return $this->cart->get_individual_discounted_price_in_cents($item, $include_tax, $sequential, $price);
    }

    public function get_discount($key, $in_cents = false)
    {
        return $this->cart->get_discount($key, $in_cents);
    }

    public function get_discounts_by_item($in_cents = false)
    {
        return $this->cart->get_discounts_by_item($in_cents);
    }

    protected function apply_discount_remainder($rule, $items_to_apply, $amount)
    {
        return $this->cart->apply_discount_remainder($rule, $items_to_apply, $amount);
    }

    public function get_discounted_price_in_cents($item, $include_tax = true, $sequential = false)
    {
        return $this->cart->get_discounted_price_in_cents($item, $include_tax, $sequential);
    }

    public function show_pricing_table()
    {
        return $this->display->show_pricing_table();
    }

    public function get_product_price_html($item_price, $product)
    {
        return $this->display->get_product_price_html($item_price, $product);
    }

    public function show_offer_message()
    {
        return $this->display->show_offer_message();
    }

    public function wdpCartMessage()
    {
        return $this->display->wdpCartMessage();
    }

    public function wdpDynamicPricingTable()
    {
        return $this->display->wdpDynamicPricingTable();
    }

    public function wdpWCPAVariationPrice()
    {
        return $this->wcpa->wdpWCPAVariationPrice();
    }

    public function wcpaQunantity_Discount()
    {
        return $this->wcpa->wcpaQunantity_Discount();
    }

    public function wdpDynamicDiscount()
    {
        return $this->wcpa->wdpDynamicDiscount();
    }

    public function wcpaDiscount($response, $product)
    {
        return $this->wcpa->wcpaDiscount($response, $product);
    }

    public function wdpWCPAPrice($default, $product)
    {
        return $this->wcpa->wdpWCPAPrice($default, $product);
    }

    public function addVirtualCoupon($response, $curr_coupon_code)
    {
        return $this->coupon->addVirtualCoupon($response, $curr_coupon_code);
    }

    public function couponLabel($label, $coupon)
    {
        return $this->coupon->couponLabel($label, $coupon);
    }

    public function applyFakeCoupons()
    {
        return $this->coupon->applyFakeCoupons();
    }

    public function wdpMiniCart()
    {
        return $this->coupon->wdpMiniCart();
    }

    public function wdpOrderMeta($item_id, $values, $cart_item_key)
    {
        return $this->order->wdpOrderMeta($item_id, $values, $cart_item_key);
    }

    public function wdpDisplayOrderMeta($item_id, $item, $product)
    {
        return $this->order->wdpDisplayOrderMeta($item_id, $item, $product);
    }

    public function wdpAdminOrderHeader($order)
    {
        return $this->order->wdpAdminOrderHeader($order);
    }

    public function wdpAdminOrderContent($_product, $item, $itemid = null)
    {
        return $this->order->wdpAdminOrderContent($_product, $item, $itemid);
    }

    public function wdpCustomJS()
    {
        return $this->order->wdpCustomJS();
    }

    public function get_con_unit($product, $price = false, $insideloop = false)
    {
        return $this->utilities->get_con_unit($product, $price, $insideloop);
    }

    public function wdpGetVariations($productID, $list = false)
    {
        return $this->utilities->wdpGetVariations($productID, $list);
    }

    public function array_needle_search($needle, $haystack)
    {
        return $this->utilities->array_needle_search($needle, $haystack);
    }

    public function check_product_on_sale($productID)
    {
        return $this->utilities->check_product_on_sale($productID);
    }

    public function wdp_price_including_tax($product, $prodPrice, $args = array())
    {
        return $this->utilities->wdp_price_including_tax($product, $prodPrice, $args);
    }

    public function wdp_price_excluding_tax($product, $prodPrice, $args = array())
    {
        return $this->utilities->wdp_price_excluding_tax($product, $prodPrice, $args);
    }

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


}
