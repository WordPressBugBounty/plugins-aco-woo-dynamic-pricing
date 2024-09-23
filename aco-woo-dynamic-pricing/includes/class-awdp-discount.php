<?php

if (!defined('ABSPATH'))
    exit;

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
    private $_active                = false;
    private $types                  = array();
    private $discount_rules         = false;
    private $conversion_unit        = false;
    private $converted_rate         = '';

    private $discounts              = array();
    private $discounted_products    = array();
    public $discountProductPrice    = '';
    public $discountProductMaxPrice = '';
    public $discountProductMinPrice = '';
    public $products_on_sale        = [];

    public function __construct()
    {

        $this->types = Array(
            'percent_total_amount'  => __('Percentage of cart total amount', 'aco-woo-dynamic-pricing'),
            'percent_product_price' => __('Percentage of product price', 'aco-woo-dynamic-pricing'),
            'fixed_product_price'   => __('Fixed price of product price', 'aco-woo-dynamic-pricing'),
            'fixed_cart_amount'     => __('Fixed price of cart total amount', 'aco-woo-dynamic-pricing'),
            'cart_quantity'         => __('Quantity based discount', 'aco-woo-dynamic-pricing')
        );

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

    /*
    * @change get_price to woocommerce_before_calculate_totals
    * @since 4.0
    * @fix compatibility and quantity discount issues
    */
    public function wdpCalculateDiscount ( $cartObject ) {
        
        if ( $cartObject ) { 

            $cartContents   = $cartObject->cart_contents; 
            $result         = [];
            // $couponStatus   = get_option('awdp_apply_coupon_discount') ? get_option('awdp_apply_coupon_discount') : false;
            $couponStatus   = false;

            // Disable Discount if any other gets added to the cart
            $disable_discount   = get_option('awdp_disable_discount') ? get_option('awdp_disable_discount') : '';
            $coupon             = get_option('awdp_fee_label') ? get_option('awdp_fee_label') : 'Discount';
            $coupon_code        = apply_filters('woocommerce_coupon_code', $coupon);
            if ( $disable_discount && !empty ( WC()->cart->get_applied_coupons() ) && !in_array ( $coupon_code, WC()->cart->get_applied_coupons() ) ) {
                return $cartObject;
            }

            // Load discount rules
            $this->load_rules();
            $this->apply_wdp_coupon = true;

            // Check if discount is active
            if ( $this->discount_rules == null )
                return $cartObject; // Exit if no rules 

            // CartContents Loop
            foreach ( $cartContents as $cartContent ) { 
 
                $prod_ID        = $cartContent['product_id']; 
                $product        = wc_get_product ( $prod_ID );
                // $product_slug   = $product->get_data()['slug'];
                $product_slug   = $cartContent['data']->get_slug();
                $quantity       = $cartContent['quantity'];
                $variationID    = $cartContent['variation_id'];
                $variations     = $cartContent['variation'];
                $disc_prod_ID   = $variationID == 0 ? $prod_ID : $variationID;
                
                // Changing Product Slug to cart key - addons compatibility
                $cartKey        = $cartContent['key'];
                
                // Get Cart Price
                $cartItemPrice  = $cartContent['data']->get_price();

                // Checking for addons price
                $addonPrice     = apply_filters('wcpa_cart_addon_data', false, $cartContent); 
                $dispPrice      = $addonPrice ? ( $addonPrice['totalPrice'] - $addonPrice['excludeFromDiscount'] ) : $cartItemPrice; 

                // $product_price1 = apply_filters('advanced_woo_discount_rules_product_price_on_before_calculate_discount', $product_price, $product, $quantity, $cart_item, $calculate_discount_from);

                /*
                * ver @ 4.3.3
                * Get conversion rate 
                * Remove conversion from the coupon total
                */
                $this->converted_rate   = $this->get_con_unit($product, $cartContent['data']->get_price());

                foreach ( $this->discount_rules as $k => $rule ) { 

                    // Disable Discount for Deposit Items
                    $depositCheck       = get_post_meta($rule['id'], 'deposit_check', true) ? get_post_meta($rule['id'], 'deposit_check', true) : 0; 
                    if ( array_key_exists ( 'awcdp_deposit', $cartContent ) && $cartContent['awcdp_deposit'] && $depositCheck ) {
                        continue;
                    }

                    // Get Product List
                    if ( !$this->get_items_to_apply_discount ( $product, $rule, $disc_prod_ID, false, $product_slug ) ) { 
                        continue;
                    } 
                    
                    // Check if User if Logged-In
                    if ( ( intval ( $rule['discount_reg_customers'] ) === 1 && !is_user_logged_in() ) || ( intval ( $rule['discount_reg_customers'] ) === 1 && is_user_logged_in() && ( !empty ( array_filter ( $rule['discount_reg_user_roles'] ) ) && empty ( array_intersect ( $rule['discount_cur_user_roles'], $rule['discount_reg_user_roles'] ) ) ) ) ) { 
                        continue;
                    }
                    
                    // Validate Rules
                    if( 'cart_quantity' != $rule['type'] ) { // Skipping cart_quantity rule // 
                        if ( !$this->validate_discount_rules( $product, $rule, ['product_price','cart_total_amount', 'cart_total_amount_all_prods', 'cart_items', 'cart_items_all_prods', 'cart_products', 'cart_products_list'], $cartContent ) ) {
                            continue;
                        }
                    }
    
                    // Discounts Default Values
                    if ( !isset ( $this->discounts[$rule['id']] ) ) { 
                        $this->discounts[$rule['id']] = [ 'label' => $rule['label'], 'discount_type' => $rule['type'], 'discount_remainder' => -1, 'taxable' => false ];
                    }

                    // Saving Actual Price (Cart View - Coupon Disabled)
                    // if ( $couponStatus && array_key_exists ( $product_slug, $this->actual_price ) == false ) {
                    //     $this->actual_price[$product_slug] = $cartItemPrice;
                    // } 

                    // Get Price
                    // $price              = !empty ($this->wdp_discounted_price) ? ( ( array_key_exists ( $cartKey, $this->wdp_discounted_price ) && $this->wdp_discounted_price[$cartKey] != '' ) ? wc_remove_number_precision ( $this->wdp_discounted_price[$cartKey] ) : $cartContent['data']->get_price() ) : $cartContent['data']->get_price();
                    $price              = !empty ($this->wdp_discounted_price) ? ( ( array_key_exists ( $cartKey, $this->wdp_discounted_price ) && $this->wdp_discounted_price[$cartKey] != '' ) ? wc_remove_number_precision ( $this->wdp_discounted_price[$cartKey] ) : $cartItemPrice ) : $cartItemPrice;

                    $discVariable       = $this->discounts[$rule['id']]; 
                    $prodLists          = $this->product_lists;

                    // Disable Double Discount
                    if ( array_key_exists ( 'discounts', $this->discounts[$rule['id']] ) && array_key_exists ( $cartKey, $this->discounts[$rule['id']]['discounts'] ) ) {
                        continue;
                    }  
                    
                    // Discount Types
                    if ( 'percent_product_price' == $rule['type'] )
                        $result = call_user_func_array ( 
                            array ( new AWDP_typeProductPrice(), 'apply_discount_percent_product_price' ), 
                            array ( $rule, $product, $price, $quantity, $discVariable, $cartContent, $disc_prod_ID, $prodLists, $dispPrice, $couponStatus ) 
                        );
                    else if ( 'fixed_product_price' == $rule['type'] )
                        $result = call_user_func_array ( 
                            array ( new AWDP_typeProductPrice(), 'apply_discount_fixed_product_price' ), 
                            array ( $rule, $product, $price, $quantity, $discVariable, $cartContent, $disc_prod_ID, $prodLists, $dispPrice, $couponStatus ) 
                        );
                    else if ( 'percent_total_amount' == $rule['type'] )
                        $result = call_user_func_array ( 
                            array ( new AWDP_typeTotalAmount(), 'apply_discount_percent_total_amount' ), 
                            array ( $rule, $product, $price, $quantity, $discVariable, $cartContent, $disc_prod_ID, $dispPrice, $couponStatus ) 
                        );
                    else if ( 'fixed_cart_amount' == $rule['type'] )
                        $result = call_user_func_array ( 
                            array ( new AWDP_typeTotalAmount(), 'apply_discount_fixed_price_total_amount' ), 
                            array ( $rule, $product, $price, $quantity, $discVariable, $cartContent, $disc_prod_ID, $dispPrice, $couponStatus ) 
                        );
                    else if ( 'cart_quantity' == $rule['type'] )
                        $result = call_user_func_array ( 
                            array ( new AWDP_typeCartQuantity(), 'apply_discount_cart_quantity' ), 
                            array ( $rule, $product, $price, $quantity, $discVariable, $prodLists, $cartContents, $cartContent, $disc_prod_ID, $dispPrice, $couponStatus ) 
                        );


                    if ( !empty($result) ) { 

                        $this->discounts[$rule['id']]                   = $result['productDiscount'];
                        $this->discounted_products[]                    = $cartKey;
                        $this->wdp_discounted_price[$cartKey]           = array_key_exists ( 'discountedprice', $result ) ? $result['discountedprice'] : ( array_key_exists ( $cartKey, $this->wdp_discounted_price ) ? $this->wdp_discounted_price[$cartKey] : '' );

                        // Set Cart Item Price
                        // if ( $couponStatus ) {
                        //     $cartContent['data']->set_price(wc_remove_number_precision($this->wdp_discounted_price[$product_slug]));
                        // }

                        // Order Meta
                        $coupon                                         = get_option('awdp_fee_label') ? get_option('awdp_fee_label') : 'Discount';
                        $coupon_code                                    = apply_filters('woocommerce_coupon_code', $coupon);
                        $orderMetaData                                  = [];
                        $orderMetaData['type']                          = $rule['type'];
                        $orderMetaData['discount']                      = $result['productDiscount'];
                        $orderMetaData['coupon']                        = $coupon_code;
                        $orderMetaData['discountedPrice']               = array_key_exists ( 'discountedprice', $result ) ? $result['discountedprice'] : ( array_key_exists ( $cartKey, $this->wdp_discounted_price ) ? $this->wdp_discounted_price[$cartKey] : '' );

                        $this->wdp_order_meta[$cartKey][]               = $orderMetaData;

                    }

                } 

            }

        }

    }

    // Show Pricing Table
    public function show_pricing_table(){

        // Load discount rules
        $this->load_rules();
        
        // Check if discount is active
        if ( $this->discount_rules == null )
            return ''; // Exit if no rules 

        // Load Product List
        $this->set_product_list();

        global $product;
        $post_id        = $product->get_id();
        $product        = wc_get_product( $post_id ); 

        // Divi Theme Page Builder Loading issue - Fix
        if(!$product){
			return;
		}

        /*
        * ver @ 4.3.3
        * Tax Settings
        */
        $tax_display_mode           = get_option( 'woocommerce_tax_display_shop' );
        $cartPrice                  = $product->get_sale_price() ? $product->get_sale_price() : $product->get_price();
        $priceIncTax                = ( 'incl' === $tax_display_mode ) ? wc_get_price_including_tax( $product, array ( 'price' => $cartPrice ) ) : wc_get_price_excluding_tax( $product, array ( 'price' => $cartPrice ) );

        $rules                      = $this->discount_rules;
        $price                      = $priceIncTax ? $priceIncTax : $cartPrice;
        $prodLists                  = $this->product_lists;
        $variations                 = $this->variations;
        $discountedPrice            = $this->discountProductPrice; 
        $discountProductMaxPrice    = $this->discountProductMaxPrice;
        $discountProductMinPrice    = $this->discountProductMinPrice; 

        // if( $this->converted_rate == '' && $item->get_ID() != '' ) {
        //     $this->converted_rate = $this->get_con_unit($item, $price, true);
        // }

        if ( $price == '' || $price == 0 ) return '';

        $pricing_table = call_user_func_array ( 
            array ( new AWDP_viewPricingTable(), 'pricin_table' ), 
            array ( $rules, $product, $price, $post_id, $prodLists, $variations, $discountedPrice, $discountProductMaxPrice, $discountProductMinPrice ) 
        ); 

        echo $pricing_table;

    }

    // Price View HTML
    public function get_product_price_html ( $item_price, $product )
    {

        if ( !$product ) return $item_price;

        $updatedPrice   = '';
        $post_id        = $product->get_id();

        /*
        * Set / Reset Attribute Value 
        * Support for Feed Plugin - Set sale price attribute key as 'acowdp_sale_price'
        * ver @ 4.4.4
        */
        if ( metadata_exists ( 'post', $post_id, AWDP_Feed_Attribute ) ) { 
            update_post_meta ( $post_id, AWDP_Feed_Attribute, '' );
        } else {
            add_post_meta ( $post_id, AWDP_Feed_Attribute, '' );
        }
        // End

        if ( is_admin() && !wp_doing_ajax() ) 
            return $item_price;

        // Load discount rules
        $this->load_rules();
        
        // Check if discount is active
        if ( $this->discount_rules == null )
            return $item_price; // Exit if no rules 

        // Load Product List
        $this->set_product_list();

        /*
        * ver @ 4.3.3
        * Tax Settings
        */
        $tax_display_mode   = get_option( 'woocommerce_tax_display_shop' );
        $cartPrice          = $product->get_sale_price() ? $product->get_sale_price() : $product->get_price();
        $priceIncTax        = ( 'incl' === $tax_display_mode ) ? wc_get_price_including_tax( $product, array ( 'price' => $cartPrice ) ) : wc_get_price_excluding_tax( $product, array ( 'price' => $cartPrice ) );

        $rules              = $this->discount_rules; 
        $price              = $priceIncTax ? $priceIncTax : $cartPrice;
        $prodLists          = $this->product_lists;
        $variations         = $this->variations;
        $cartRules          = $this->awdp_cart_rules; 
        
        // Display regular price instead of sale price @ ver 4.3.3
        $displayPrcIncTax   = ( 'incl' === $tax_display_mode ) ? wc_get_price_including_tax( $product, array('price' => $product->get_regular_price() ) ) : wc_get_price_excluding_tax( $product, array('price' => $product->get_regular_price() ) );
        $addition_settings  = get_option('awdp_addition_settings') ? get_option('awdp_addition_settings') : [];
        // $use_regular        = array_key_exists ( 'use_regular', $addition_settings ) ? $addition_settings['use_regular'] : false;
        // $display_price      = $use_regular ? ( $displayPrcIncTax ? $displayPrcIncTax : $product->get_regular_price() ) : '';
        // $display_price      = $displayPrcIncTax ? $displayPrcIncTax : $product->get_regular_price();
        $display_price      = '';

        // if( $this->converted_rate == '' && $item->get_ID() != '' ) {
        //     $this->converted_rate = $this->get_con_unit($item, $price, true);
        // }

        if ( $price == '' || $price == 0 ) return $item_price;

        $viewPrice = call_user_func_array ( 
            array ( new AWDP_viewProductPrice(), 'product_price' ), 
            array ( $rules, $price, $post_id, $product, $prodLists, $cartRules, $item_price, $display_price ) 
        );

        if ( is_array ( $viewPrice ) ) {
            $updatedPrice                   = $viewPrice['itemPrice'];
            $this->discountProductPrice     = $viewPrice['discountedPrice'];
            $this->discountProductMaxPrice  = $viewPrice['discountedMaxPrice'];
            $this->discountProductMinPrice  = $viewPrice['discountedMinPrice'];
        }

        return $updatedPrice ? $updatedPrice : $item_price; 

    }

    // WCPA Get Variation Price
    public function wdpWCPAVariationPrice ( )
    {

        // global $product;
        // $product->get_id();

        // Reset Query
        wp_reset_query(); 
        
        $post_id        = get_the_ID();
        $product        = wc_get_product( $post_id ); 

        if ( !$product ) return '';

        /*
        * ver @ 4.3.3
        * Tax Settings
        */
        $tax_display_mode   = get_option( 'woocommerce_tax_display_shop' );
        $cartPrice          = $product->get_sale_price() ? $product->get_sale_price() : $product->get_price();
        $priceIncTax        = ( 'incl' === $tax_display_mode ) ? wc_get_price_including_tax( $product, array ( 'price' => $cartPrice ) ) : wc_get_price_excluding_tax( $product, array ( 'price' => $cartPrice ) );

        $price              = $priceIncTax ? $priceIncTax : $cartPrice;

        if ( is_admin() ) 
            return $price;

        // Load discount rules
        $this->load_rules();
        
        // Check if discount is active
        if ( $this->discount_rules == null )
            return $price; // Exit if no rules 

        // Load Product List
        $this->set_product_list();

        $updatedPrice   = '';
        $product_slug   = $product->get_data()['slug'];

        $rules          = $this->discount_rules;
        $prodLists      = $this->product_lists;
        $variations     = $this->variations;
        $cartRules      = $this->awdp_cart_rules;
        $item_price     = $price;

        // if( $this->converted_rate == '' && $item->get_ID() != '' ) {
        //     $this->converted_rate = $this->get_con_unit($item, $price, true);
        // }
        $priceGroup = call_user_func_array ( 
            array ( new AWDP_productGroup(), 'product_group' ), 
            array ( $rules, $price, $post_id, $product, $prodLists, $cartRules, $item_price ) 
        ); 

        if ( is_array ( $priceGroup ) ) {

            return new WP_REST_Response($priceGroup, 200);
            
        }

        return $price;

    }

    // WCPA Discount Price For Frontend
    public function wdpDynamicDiscount ()
    {
        
        $post_id            = isset( $_GET['prodID'] ) ? $_GET['prodID'] : get_the_ID();
        $variation_id       = isset( $_GET['varID'] ) ? $_GET['varID'] : '';
        $itemCount          = isset( $_GET['proCount'] ) ? $_GET['proCount'] : 1;
        $product            = $variation_id ? wc_get_product ( $variation_id ) : wc_get_product ( $post_id ); 
        if ( !$product ) return '';

        update_option('itemCount', $itemCount);

         /*
        * ver @ 5.0.5
        * Tax Settings
        */        
        $tax_display_mode   = get_option( 'woocommerce_tax_display_shop' );
        $pirce_include_tax  = get_option( 'woocommerce_prices_include_tax' ); 
        $cartPrice          = $product->get_sale_price() ? $product->get_sale_price() : $product->get_price();

        /*
        * ver @ 5.0.9 
        * If more than 2 decimal points, round to 2 decimal points
        */
        $decimal_points     = get_option( 'woocommerce_price_num_decimals' ) ? get_option( 'woocommerce_price_num_decimals' ) : 2;
        $priceIncTax        = ( ( strlen ( strrchr ( wc_get_price_including_tax ( $product, array ( 'price' => $cartPrice ) ), '.' ) ) -1 ) > 2 ) ? round ( wc_get_price_including_tax ( $product, array ( 'price' => $cartPrice ) ), 2 ) : wc_get_price_including_tax ( $product, array ( 'price' => $cartPrice ) );
        $priceExcTax        = ( ( strlen ( strrchr ( wc_get_price_excluding_tax ( $product, array ( 'price' => $cartPrice ) ), '.' ) ) -1 ) > 2 ) ? round ( wc_get_price_excluding_tax ( $product, array ( 'price' => $cartPrice ) ), 2 ) : wc_get_price_excluding_tax ( $product, array ( 'price' => $cartPrice ) );

        // $priceAfterTax      = ( 'incl' === $tax_display_mode ) ? wc_get_price_including_tax ( $product, array ( 'price' => $cartPrice ) ) : wc_get_price_excluding_tax ( $product, array ( 'price' => $cartPrice ) );
        $priceAfterTax      = ( 'incl' === $tax_display_mode ) ? $priceIncTax : ( ( 'yes' === $pirce_include_tax && 'excl' === $tax_display_mode ) ? $priceExcTax : '' ); 

        $price              = $priceAfterTax ? $priceAfterTax : $cartPrice;

        // Load discount rules
        $this->load_rules();

        // Check if discount is active
        if ( $this->discount_rules == null )
            return $price; // Exit if no rules

            
        // Load Product List
        $this->set_product_list();

        $updatedPrice       = '';
        $product_slug       = $product->get_data()['slug'];
        $rules              = $this->discount_rules;
        $prodLists          = $this->product_lists;
        $variations         = $this->variations;
        $cartRules          = $this->awdp_cart_rules;
        $item_price         = $price;

        // if ( $discountedPrice ) {
        //     $product_id     = $_REQUEST['product_id']; 
        // }
        $priceGroup = call_user_func_array ( 
            array ( new AWDP_productGroup(), 'product_price' ), 
            array ( $rules, $price, $post_id, $product, $prodLists, $cartRules, $item_price,$itemCount) 
        ); 

        // if ( is_array ( $priceGroup ) ) {
        //     return new WP_REST_Response($priceGroup, 200);
        // }

        if ( is_array ( $priceGroup ) ) {
            return json_encode($priceGroup);
        }
        return  $price;

    }

    public function wcpaDiscount($response, $product) 
    {

        $result = [];

        if ( !$product ) return $response;

        // Load discount rules
        $this->load_rules();
        
        // Check if discount is active
        if ( $this->discount_rules == null ) return $response; 

        // Load Product List
        $this->set_product_list();

        $rules              = $this->discount_rules;
        $prodLists          = $this->product_lists;
        $variations         = $this->variations;
        $cartRules          = $this->awdp_cart_rules;
        // $item_price         = $price;

        $wcpaDisc = call_user_func_array ( 
            array ( new AWDP_productGroup(), 'wcpa_discount' ), 
            array ( $rules, $product ) 
        ); 

        if ( !empty ($wcpaDisc) ) {
            // Considering only single discount value as multiple values / group currently not supported with WCPA
            foreach ( $wcpaDisc as $disc ) {
                if ( $disc['type']  == 'percentage' ) {
                    $value                  = $disc['value'] ? $disc['value'] / 100 : 0;
                    $result['fixed']        = 0;
                    $result['percentage']   = $value;
                } elseif ( $disc['type'] == 'fixed' ) {
                    $value                  = $disc['value'] ? $disc['value'] : 0;
                    $result['percentage']   = 0;
                    $result['fixed']        = $value;
                }
            }
        }

        return !empty($result) ? $result : $response;

    }

    // WCPA Price
    public function wdpWCPAPrice($default, $product) {

        // Load discount rules
        $this->load_rules();
        
        // Check if discount is active
        if ( $this->discount_rules == null )
            return $default; // Exit if no rules 

        // Load Product List
        $this->set_product_list();

        if ( !$product ) return $default;

        $updatedPrice       = '';
        $result             = [];
        $product_slug       = $product->get_data()['slug'];
        $post_id            = $product->get_id();

        /*
        * ver @ 4.3.3
        * Tax Settings
        */
        $tax_display_mode   = get_option( 'woocommerce_tax_display_shop' );
        $cartPrice          = $product->get_sale_price() ? $product->get_sale_price() : $product->get_price();
        $priceIncTax        = ( 'incl' === $tax_display_mode ) ? wc_get_price_including_tax( $product, array ( 'price' => $cartPrice ) ) : wc_get_price_excluding_tax( $product, array ( 'price' => $cartPrice ) );

        $rules              = $this->discount_rules;
        $price              = $priceIncTax ? $priceIncTax : $cartPrice;
        $prodLists          = $this->product_lists;
        $variations         = $this->variations;
        $cartRules          = $this->awdp_cart_rules;
        $item_price         = $price;

        // Display regular price instead of sale price @ ver 4.3.3
        $displayPrcIncTax   = ( 'incl' === $tax_display_mode ) ? wc_get_price_including_tax( $product, array('price' => $product->get_regular_price() ) ) : wc_get_price_excluding_tax( $product, array('price' => $product->get_regular_price() ) );
        $addition_settings  = get_option('awdp_addition_settings') ? get_option('awdp_addition_settings') : [];
        // $use_regular        = array_key_exists ( 'use_regular', $addition_settings ) ? $addition_settings['use_regular'] : false;
        // $display_price      = $use_regular ? ( $displayPrcIncTax ? $displayPrcIncTax : $product->get_regular_price() ) : '';
        // $display_price      = $displayPrcIncTax ? $displayPrcIncTax : $product->get_regular_price();
        $display_price      = '';

        $viewPrice = call_user_func_array ( 
            array ( new AWDP_viewProductPrice(), 'product_price' ), 
            array ( $rules, $price, $post_id, $product, $prodLists, $cartRules, $item_price, $display_price ) 
        ); 

        $itemCount = get_option('itemCount', 1);
        $wcpaPrice = call_user_func_array ( 
            array ( new AWDP_productGroup(), 'product_price' ), 
            array ( $rules, $price, $post_id, $product, $prodLists, $cartRules, $item_price,$itemCount) 
        );
        update_option('itemCount', 1);

        if ( is_array ( $viewPrice ) || is_array ( $wcpaPrice ) ) {

            // $updatedPrice   = $viewPrice['discountedPrice'];

            $result['price']            =  $viewPrice['discountedPrice'] ?  $viewPrice['discountedPrice'] : $wcpaPrice['price'];
            $result['originalPrice']    = $price;

            return $result;
            
        }

        return $default;

    }

    // Cart Price View
    public function cart_discount_items ( $item_price, $cart_item )
    {

        // Disable Discount if any other gets added to the cart
        $disable_discount   = get_option('awdp_disable_discount') ? get_option('awdp_disable_discount') : '';
        $coupon             = get_option('awdp_fee_label') ? get_option('awdp_fee_label') : 'Discount';
        $coupon_code        = apply_filters('woocommerce_coupon_code', $coupon);
        if ( $disable_discount && !empty ( WC()->cart->get_applied_coupons() ) && !in_array ( $coupon_code, WC()->cart->get_applied_coupons() ) ) {
            return $item_price;
        }

        // Load discount rules 
        $this->load_rules();
        
        // Check if discount is active
        if ( $this->discount_rules == null )
            return $item_price; // Exit if no rules 

        // Load Product List
        $this->set_product_list(); 
        
        $post_id        = $cart_item['product_id'];
        $quantity       = $cart_item['quantity'];
        $product        = wc_get_product( $post_id );

        $rules          = $this->discount_rules;
        // $price          = $product->get_sale_price() ? $product->get_sale_price() : $product->get_price(); // get cart price
        // $price          = $cart_item['data']->get_price(); // get cart price

        /*
        * ver @ 4.3.3
        * Tax Settings
        */
        $tax_display_mode   = get_option( 'woocommerce_tax_display_shop' );

        $cartPrice          = $cart_item['data']->get_price();
        // Checking for addons price
        $addonPrice         = apply_filters('wcpa_cart_addon_data', false, $cart_item); 
        $dispPrice          = $addonPrice ? ( $addonPrice['totalPrice'] - $addonPrice['excludeFromDiscount'] ) : $cartPrice; 

        $priceIncTax        = ( 'incl' === $tax_display_mode ) ? wc_get_price_including_tax( $product, array ( 'price' => $cartPrice ) ) : wc_get_price_excluding_tax( $product, array ( 'price' => $cartPrice ) );

        $price              = $priceIncTax ? $priceIncTax : $cartPrice; // commenting $discPrices - fix - cart price mismatch when multiples rules are applied
        
        // $price          = '';
        // $product_slug   = $product->get_data()['slug'];
        $product_slug       = $cart_item['data']->get_slug();
        $prodLists          = $this->product_lists;
        $variations         = $this->variations;
        $cartContents       = WC()->cart->get_cart();
        // $couponStatus   = get_option('awdp_apply_coupon_discount') ? get_option('awdp_apply_coupon_discount') : false;
        $couponStatus       = false;
        // $disc_prod_ID   = $post_id;
        $variationID        = $cart_item['variation_id'];
        // $variations     = $cart_item['variation'];
        $disc_prod_ID       = $variationID == 0 ? $post_id : $variationID;

        // Changing Product Slug to cart key - addons compatibility
        $cartKey            = $cart_item['key'];

        // if( $this->converted_rate == '' && $item->get_ID() != '' ) {
        //     $this->converted_rate = $this->get_con_unit($item, $price, true);
        // }
        
        /*
        * ver @ 4.3.3
        * Tax Settings
        */
        $priceIncTax        = ( 'incl' === $tax_display_mode ) ? wc_get_price_including_tax( $product ) : wc_get_price_excluding_tax( $product );

        // Display regular price instead of sale price @ ver 4.3.3
        $displayPrcIncTax   = ( 'incl' === $tax_display_mode ) ? wc_get_price_including_tax( $product, array('price' => $product->get_regular_price() ) ) : wc_get_price_excluding_tax( $product, array('price' => $product->get_regular_price() ) );
        $addition_settings  = get_option('awdp_addition_settings') ? get_option('awdp_addition_settings') : [];
        // $use_regular        = array_key_exists ( 'use_regular', $addition_settings ) ? $addition_settings['use_regular'] : false;
        // $display_price      = $use_regular ? ( $displayPrcIncTax ? $displayPrcIncTax : $product->get_regular_price() ) : '';
        // $display_price      = $displayPrcIncTax ? $displayPrcIncTax : $product->get_regular_price();
        $display_price      = '';

        foreach ( $this->discount_rules as $k => $rule ) {

            
            // Disable Discount for Deposit Items
            $depositCheck       = get_post_meta($rule['id'], 'deposit_check', true) ? get_post_meta($rule['id'], 'deposit_check', true) : 0; 
            if ( array_key_exists ( 'awcdp_deposit', $cart_item ) && $cart_item['awcdp_deposit'] && $depositCheck ) {
                continue;
            }

            // Get Product List
            if ( !$this->get_items_to_apply_discount ( $product, $rule, $disc_prod_ID, false, $product_slug ) ) {
                continue;
            }

            // Check if User if Logged-In
            if ( ( intval ( $rule['discount_reg_customers'] ) === 1 && !is_user_logged_in() ) || ( intval ( $rule['discount_reg_customers'] ) === 1 && is_user_logged_in() && ( !empty ( array_filter ( $rule['discount_reg_user_roles'] ) ) && empty ( array_intersect ( $rule['discount_cur_user_roles'], $rule['discount_reg_user_roles'] ) ) ) ) ) { 
                continue;
            }

            // Validate Rules
            if( 'cart_quantity' != $rule['type'] ) { // Skipping cart_quantity rule // 
                if ( !$this->validate_discount_rules( $product, $rule, ['product_price','cart_total_amount', 'cart_total_amount_all_prods', 'cart_items', 'cart_items_all_prods', 'cart_products', 'cart_products_list'], $cart_item ) ) {
                    continue;
                }
            }

            // Discounts Default Values
            if ( !isset ( $this->discounts[$rule['id']] ) ) { 
                $this->discounts[$rule['id']] = [ 'label' => $rule['label'], 'discount_type' => $rule['type'], 'discount_remainder' => -1, 'taxable' => false ];
            }

            /* 
            * Disable Double Discount
            * @ver 4.1.6
            * $product_slug added to awdp_discount_applied list is discount already applied
            */
            if ( array_key_exists ( 'discounts', $this->discounts[$rule['id']] ) && array_key_exists ( $cartKey, $this->discounts[$rule['id']]['discounts'] ) ) { 
                $this->awdp_discount_applied[] = $product_slug;
                continue;
            }

            // Get Price
            // if ( $couponStatus && array_key_exists ( $product_slug, $this->actual_price ) ) {
            //     $price = $this->actual_price[$product_slug];
            // } else {
                // $price = !empty ($this->wdp_discounted_price) ? ( ( array_key_exists ( $cartKey, $this->wdp_discounted_price ) && $this->wdp_discounted_price[$cartKey] != '' ) ? wc_remove_number_precision ( $this->wdp_discounted_price[$cartKey] ) : $cart_item['data']->get_price() ) : $cart_item['data']->get_price(); 
                $price = !empty ($this->wdp_discounted_price) ? ( ( array_key_exists ( $cartKey, $this->wdp_discounted_price ) && $this->wdp_discounted_price[$cartKey] != '' ) ? wc_remove_number_precision ( $this->wdp_discounted_price[$cartKey] ) : $cart_item['data']->get_price() ) : $cart_item['data']->get_price(); 
            // } 

            $discVariable       = $this->discounts[$rule['id']]; 
            $prodLists          = $this->product_lists;

            // Discount Types
            if ( 'percent_product_price' == $rule['type'] )
                $result = call_user_func_array ( 
                    array ( new AWDP_typeProductPrice(), 'apply_discount_percent_product_price' ), 
                    array ( $rule, $product, $price, $quantity, $discVariable, $cart_item, $disc_prod_ID, $prodLists, $dispPrice, $couponStatus ) 
                );
            else if ( 'fixed_product_price' == $rule['type'] )
                $result = call_user_func_array ( 
                    array ( new AWDP_typeProductPrice(), 'apply_discount_fixed_product_price' ), 
                    array ( $rule, $product, $price, $quantity, $discVariable, $cart_item, $disc_prod_ID, $prodLists, $dispPrice, $couponStatus ) 
                );
            else if ( 'percent_total_amount' == $rule['type'] ) 
                $result = call_user_func_array ( 
                    array ( new AWDP_typeTotalAmount(), 'apply_discount_percent_total_amount' ), 
                    array ( $rule, $product, $price, $quantity, $discVariable, $cart_item, $disc_prod_ID, $dispPrice, $couponStatus ) 
                );
            else if ( 'fixed_cart_amount' == $rule['type'] )
                $result = call_user_func_array ( 
                    array ( new AWDP_typeTotalAmount(), 'apply_discount_fixed_price_total_amount' ), 
                    array ( $rule, $product, $price, $quantity, $discVariable, $cart_item, $disc_prod_ID, $dispPrice, $couponStatus ) 
                );
            else if ( 'cart_quantity' == $rule['type'] )
                $result = call_user_func_array ( 
                    array ( new AWDP_typeCartQuantity(), 'apply_discount_cart_quantity' ), 
                    array ( $rule, $product, $price, $quantity, $discVariable, $prodLists, $cartContents, $cart_item, $disc_prod_ID, $dispPrice, $couponStatus ) 
                );


            if ( !empty($result) ) {
                $this->discounts[$rule['id']]               = $result['productDiscount'];
                $this->discounted_products[]                = $cartKey;
                $this->wdp_discounted_price[$cartKey]  = array_key_exists ( 'discountedprice', $result ) ? $result['discountedprice'] : ( array_key_exists ( $cartKey, $this->wdp_discounted_price ) ? $this->wdp_discounted_price[$cartKey] : '' );
            }

        }

        $activeDiscounts    = $this->discounts;

        $viewPrice = call_user_func_array ( 
            array ( new AWDP_viewCartPrice(), 'cart_price' ), 
            array ( $rules, $price, $cart_item, $prodLists, $item_price, $activeDiscounts, $product, $quantity, $display_price ) 
        ); 

        return $viewPrice;

    }

    // Sub Total Calculations
    public function wdpCartLoop ( $wc, $cart_item, $cart_item_key )
    { 

        // $couponStatus   = get_option('awdp_apply_coupon_discount') ? get_option('awdp_apply_coupon_discount') : false;

        // if ( $couponStatus ) {
        //     return $wc;
        // }

        // $wcpa_price         = preg_match('/<span class="wcpa_price">(.*?)<\/span>/s', $wc, $match);

        $activeDiscounts    = $this->discounts;
        // $variationDiscounts = $this->variationDiscounts;
        $decimalPoints      = wc_get_price_decimals();
        $product_id         = $cart_item['product_id'];
        $product            = wc_get_product( $product_id ); 

        /*
        * ver @ 4.3.3
        * Tax Settings
        */
        $tax_display_mode   = get_option( 'woocommerce_tax_display_shop' );

        $cartPrice          = $cart_item['data']->get_price();
        // Checking for addons price
        $addonPrice         = apply_filters('wcpa_cart_addon_data', false, $cart_item); 
        $dispPrice          = $addonPrice ? ( $addonPrice['totalPrice'] - $addonPrice['excludeFromDiscount'] ) : $cartPrice; 
        
        $priceIncTax        = ( 'incl' === $tax_display_mode ) ? wc_get_price_including_tax( $product, array ( 'price' => $cartPrice ) ) : wc_get_price_excluding_tax( $product, array ( 'price' => $cartPrice ) );

        $price              = $priceIncTax ? $priceIncTax : $cartPrice;
        $quantity           = $cart_item['quantity'];
        $discount           = 0;
        $prod_ID            = $cart_item['data']->get_slug();
        $cartKey            = $cart_item['key'];

        // if ( $price > 0 ) {
        //     if (WC()->cart->display_prices_including_tax()) {
        //         $price = $this->wdp_price_including_tax ( $product, $price, array(
        //             'qty' => $quantity,
        //             'price' => $price,
        //         ) );
        //     } else {
        //         $price = $this->wdp_price_excluding_tax ( $product, $price, array(
        //             'qty' => $quantity,
        //             'price' => $price
        //         ) );
        //     }
        // }

        if ( $activeDiscounts ) {

            foreach ( $activeDiscounts as $discounts ) {  
                if ( array_key_exists ( 'discounts', $discounts ) ) {
                    if ( array_key_exists ( $cartKey, $discounts['discounts'] ) && $discounts['discounts'][$cartKey]['discount'] != '' && ( $discounts['discounts'][$cartKey]["displayoncart"] != false ) ) { 
                        $discount += wc_remove_number_precision ( $discounts['discounts'][$cartKey]['discount'] );
                    }
                }
            } 

            $discount           = ( 'incl' === $tax_display_mode ) ? round( wc_get_price_including_tax( $product, array ( 'price' => $discount ) ),$decimalPoints ) : round( wc_get_price_excluding_tax( $product, array ( 'price' => $discount ) ), $decimalPoints );
            $discounted_price   = ( $price - $discount ) * $quantity;
         
            $price              = round ( $discounted_price, $decimalPoints );
            
            $product_subtotal   = wc_price ( $price );

            if ( $product->is_taxable() && get_option('woocommerce_tax_display_cart') == 'incl' ) {
                if( !wc_prices_include_tax() && WC()->cart->get_subtotal_tax() > 0 ) {
                    $product_subtotal .= ' <small class="tax_label">' . WC()->countries->inc_tax_or_vat() . '</small>';
                }
            }

            return $product_subtotal;

        } else {

            return $wc;

        }

    }

    // Show Offer Message
    public function show_offer_message(){

        // Load discount rules
        $this->load_rules();
        
        // Check if discount is active
        if ( $this->discount_rules == null )
            return ''; // Exit if no rules 

        Global $product;

        // Load Product List
        $this->set_product_list();

        $productid              = $product->get_id();
        $result                 = '';
        $offer_desc_config      = get_option('awdp_disc_desc_config') ? get_option('awdp_disc_desc_config') : [];
        $offer_rule             = array_key_exists ( 'dismessage_rule', $offer_desc_config ) ? $offer_desc_config['dismessage_rule'] : ''; 
        $list_products          = [];
        $all_prods              = false;

        $checkML                = call_user_func ( array ( new AWDP_ML(), 'is_default_lan' ), '' );
        $currentLang            = !$checkML ? call_user_func ( array ( new AWDP_ML(), 'current_language' ), '' ) : '';
        $langSettings           = get_option('awdp_settings_lang_options') ? get_option('awdp_settings_lang_options') : [];

        /*
        * @ver 4.1.7
        * Offer description for all active rules (earlier version displays description on all products)
        */
        if ( $offer_rule != '' ) {

            /*
            * @ver 4.3.5
            * Added support for custom product list - offer description
            */
            $custom_prd_list        = get_post_meta ( $offer_rule, 'discount_custom_pl', true );  

            if ( $custom_prd_list ) {
                
                $list_products       = $this->set_custom_list($offer_rule); 

            } else {

                if ( $offer_rule == 'all_active' ) {
                    foreach ( $this->discount_rules as $k => $rule ) { 
                        $ruleID     = $rule['id'];
                        $prd_list   = get_post_meta ( $ruleID, 'discount_product_list', true ); 
                        if ( '' == $prd_list || 0 == $prd_list ) {
                            $all_prods  = true;
                            $offer_rule = '';
                            break;
                        }
                        $list_products = array_merge ( $list_products, $this->product_lists[$prd_list] );
                    }
                    array_values ( array_unique ( $list_products ) ); 
                } else {
                    $prd_list       = get_post_meta ( $offer_rule, 'discount_product_list', true );
                    if ( $prd_list ) {
                        $list_products  = array_merge ( $list_products, $this->product_lists[$prd_list] );
                    } else { // Set to all_products when no product list is selected (pricing rule)
                        $offer_rule = '';
                    }
                }

            }

        } 

        if ( $offer_rule == '' || ( !empty ( $list_products ) && in_array ( $productid, $list_products ) ) ) {  
 
            // $productlist    = ( $offer_rule != '' && $offer_rule != 'all_active' ) ? ( get_post_meta ( $offer_rule, 'discount_product_list', true ) ) : '';
            // // Check in product list           
            // if ( '' == $productlist || 0 == $productlist || $all_prods == true || ( !empty ( $list_products ) && in_array ( $productid, $list_products ) ) || ( isset ( $this->product_lists[$productlist] ) && in_array ( $productid, $this->product_lists[$productlist] ) ) ) {

                /*
                * ver @ 4.4.8
                * WMPL Support offer description
                */
                if ( !empty ($langSettings) && array_key_exists ( $currentLang, $langSettings ) ) {
                    $offer_desc         = array_key_exists ( 'dismessage', $langSettings[$currentLang] ) ? $langSettings[$currentLang]['dismessage'] : ( array_key_exists ( 'dismessage', $offer_desc_config ) ? $offer_desc_config['dismessage'] : '' );
                } else {
                    $offer_desc         = array_key_exists ( 'dismessage', $offer_desc_config ) ? $offer_desc_config['dismessage'] : '';
                }

                // $offer_desc             = array_key_exists ( 'dismessage', $offer_desc_config ) ? $offer_desc_config['dismessage'] : ''; 
                $offer_fontsize         = array_key_exists ( 'dismessage_fontsize', $offer_desc_config ) ? $offer_desc_config['dismessage_fontsize'] : 12;
                $offer_paddding_lm      = array_key_exists ( 'dismessage_paddding_lm', $offer_desc_config ) ? $offer_desc_config['dismessage_paddding_lm'] : 10;
                $offer_paddding_tp      = array_key_exists ( 'dismessage_paddding_tp', $offer_desc_config ) ? $offer_desc_config['dismessage_paddding_tp'] : 10;
                $offer_radius           = array_key_exists ( 'dismessage_radius', $offer_desc_config ) ? $offer_desc_config['dismessage_radius'] : 0;
                $offer_color            = array_key_exists ( 'dismessage_color', $offer_desc_config ) ? $offer_desc_config['dismessage_color'] : '';
                $offer_background       = array_key_exists ( 'dismessage_background', $offer_desc_config ) ? $offer_desc_config['dismessage_background'] : ''; 

                $border_top_width       = ( array_key_exists ( 'border_top_width', $offer_desc_config ) && $offer_desc_config['border_top_width'] != '' ) ? $offer_desc_config['border_top_width'].'px' : '0px'; 
                $border_right_width     = ( array_key_exists ( 'border_right_width', $offer_desc_config ) && $offer_desc_config['border_right_width'] != '' ) ? ' '.$offer_desc_config['border_right_width'].'px' : ' 0px'; 
                $border_bottom_width    = ( array_key_exists ( 'border_bottom_width', $offer_desc_config ) && $offer_desc_config['border_bottom_width'] != '' ) ? ' '.$offer_desc_config['border_bottom_width'].'px' : ' 0px'; 
                $border_left_width      = ( array_key_exists ( 'border_left_width', $offer_desc_config ) && $offer_desc_config['border_left_width'] != '' ) ? ' '.$offer_desc_config['border_left_width'].'px' : ' 0px'; 
                $offer_border_color     = array_key_exists ( 'offer_border_color', $offer_desc_config ) ? $offer_desc_config['offer_border_color'] : ''; 

                $customStyle            = 'font-size: '.$offer_fontsize.'px;padding: '.$offer_paddding_tp.'px '.$offer_paddding_lm.'px;border-radius: '.$offer_radius.'px;';
                $customStyle           .= $offer_color ? 'color: '.$offer_color.';' : '';
                $customStyle           .= $offer_background ? 'background: '.$offer_background.';' : ''; 
                $customStyle           .= $offer_border_color ? 'border-color: '.$offer_border_color.';' : ''; 
                $customStyle           .= ( $border_top_width || $border_right_width || $border_bottom_width || $border_left_width ) ? 'border-width: '.$border_top_width.$border_right_width.$border_bottom_width.$border_left_width.';' : ''; 

                $result                 = '<div class="awdpOfferMsg" style="display:none;"><span style="'.$customStyle.'">'.$offer_desc.'</span></div>';

            // }

        }

        echo $result;
 
    }

    // Cart Message
    public function wdpCartMessage(){
        
        global $woocommerce; 
        $coupon         = get_option('awdp_fee_label') ? get_option('awdp_fee_label') : 'Discount'; 
        $coupon_code    = apply_filters('woocommerce_coupon_code', $coupon);
        $customStyle    = '';
        $result         = '';
        $total          = 0; 

        $checkML        = call_user_func ( array ( new AWDP_ML(), 'is_default_lan' ), '' );
        $currentLang    = !$checkML ? call_user_func ( array ( new AWDP_ML(), 'current_language' ), '' ) : '';
        $langSettings   = get_option('awdp_settings_lang_options') ? get_option('awdp_settings_lang_options') : [];

        if ( in_array ( $coupon_code, $woocommerce->cart->get_applied_coupons() ) ) {

            if ( $this->discounts ) {
                
                foreach ( $this->discounts as $ruleid => $discounts ) { 

                    $discount_type = $discounts['discount_type'];
                    $qn_type       = ( $discount_type == 'cart_quantity' ) ? get_post_meta ( $ruleid, 'discount_quantity_type', true ) : '';

                    if ( array_key_exists ( 'discounts', $discounts ) ) { 

                        foreach ( $discounts['discounts'] as $key => $discount ) { 

                            $disc_product_ID = $discount['productid'];

                            if ( $discount['discount'] != '' ) {
                                // Decimal Round
                                $decimal_val    = $discount['discount'] - floor($discount['discount']);
                                $calc_discount  = ( $decimal_val == 0 ) ? $discount['discount'] : ( ( $decimal_val > 0.5 ) ? ceil ( $discount['discount'] ) : floor ( $discount['discount'] ) );

                                if ( $discount_type == 'fixed_product_price' || $discount_type == 'percent_product_price' || ( $discount_type == 'cart_quantity' && $qn_type == 'type_product' ) ) {
                                    $calc_discount = $calc_discount * $discount['quantity'];
                                }

                                $total                  = $total + ( wc_remove_number_precision ( $calc_discount ) );

                            }

                        }

                    }

                }

            }
                
            // $coupons_obj                = new WC_Coupon($coupon_code);
            // $coupons_amount             = $coupons_obj->get_amount();
            $coupons_amount             = $total;
            $custom_message_settings    = get_option('awdp_custom_msg_settings') ? get_option('awdp_custom_msg_settings') : [];
            $custom_message_status      = array_key_exists ( 'custom_message_status', $custom_message_settings ) ? $custom_message_settings['custom_message_status'] : false;

            if ( $coupons_amount > 0 && $custom_message_status ) {

                /*
                * ver @ 4.4.8
                * WMPL Support custom message
                */
                if ( !empty ($langSettings) && array_key_exists ( $currentLang, $langSettings ) ) {
                    $custom_message         = array_key_exists ( 'custom_message', $langSettings[$currentLang] ) ? $langSettings[$currentLang]['custom_message'] : ( array_key_exists ( 'custom_message', $custom_message_settings ) ? $custom_message_settings['custom_message'] : '' );
                } else {
                    $custom_message         = array_key_exists ( 'custom_message', $custom_message_settings ) ? $custom_message_settings['custom_message'] : '';
                }

                // $custom_message                       = array_key_exists ( 'custom_message', $custom_message_settings ) ? $custom_message_settings['custom_message'] : '';
                $custom_message_linheight             = array_key_exists ( 'custom_message_linheight', $custom_message_settings ) ? $custom_message_settings['custom_message_linheight'] : '';
                $custom_message_fontsize              = array_key_exists ( 'custom_message_fontsize', $custom_message_settings ) ? $custom_message_settings['custom_message_fontsize'] : '';
                $custom_message_position              = array_key_exists ( 'custom_message_position', $custom_message_settings ) ? $custom_message_settings['custom_message_position'] : '';
                $custom_message_paddding_lm           = array_key_exists ( 'custom_message_paddding_lm', $custom_message_settings ) ? $custom_message_settings['custom_message_paddding_lm'] : '';
                $custom_message_paddding_tp           = array_key_exists ( 'custom_message_paddding_tp', $custom_message_settings ) ? $custom_message_settings['custom_message_paddding_tp'] : '';
                $custom_message_border_radius         = array_key_exists ( 'custom_message_border_radius', $custom_message_settings ) ? $custom_message_settings['custom_message_border_radius'] : '';
                $custom_message_border_top_width      = array_key_exists ( 'custom_message_border_top_width', $custom_message_settings ) ? $custom_message_settings['custom_message_border_top_width'].'px ' : '';
                $custom_message_border_right_width    = array_key_exists ( 'custom_message_border_right_width', $custom_message_settings ) ? $custom_message_settings['custom_message_border_right_width'].'px ' : '';
                $custom_message_border_bottom_width   = array_key_exists ( 'custom_message_border_bottom_width', $custom_message_settings ) ? $custom_message_settings['custom_message_border_bottom_width'].'px ' : '';
                $custom_message_border_left_width     = array_key_exists ( 'custom_message_border_left_width', $custom_message_settings ) ? $custom_message_settings['custom_message_border_left_width'].'px' : '';
                $custom_message_border_color          = array_key_exists ( 'custom_message_border_color', $custom_message_settings ) ? $custom_message_settings['custom_message_border_color'] : '';
                $custom_message_background            = array_key_exists ( 'custom_message_background', $custom_message_settings ) ? $custom_message_settings['custom_message_background'] : '';
                $custom_message_color                 = array_key_exists ( 'custom_message_color', $custom_message_settings ) ? $custom_message_settings['custom_message_color'] : '';
                
                $customStyle           .= 'font-size: '.$custom_message_fontsize.'px;padding: '.$custom_message_paddding_tp.'px '.$custom_message_paddding_lm.'px;border-radius: '.$custom_message_border_radius.'px;';
                $customStyle           .= $custom_message_color ? 'color: '.$custom_message_color.';' : '';
                $customStyle           .= $custom_message_background ? 'background: '.$custom_message_background.';' : ''; 
                $customStyle           .= $custom_message_border_color ? 'border-color: '.$custom_message_border_color.';' : ''; 
                $customStyle           .= $custom_message_position ? 'text-align: '.$custom_message_position.';' : ''; 
                $customStyle           .= ( $custom_message_border_top_width || $custom_message_border_right_width || $custom_message_border_bottom_width || $custom_message_border_left_width ) ? 'border-width: '.$custom_message_border_top_width.$custom_message_border_right_width.$custom_message_border_bottom_width.$custom_message_border_left_width.';' : ''; 

                $message                = $custom_message ? str_replace('[discount]', wc_price($coupons_amount), $custom_message ) : __("You'he saved ", "aco-woo-dynamic-pricing").wc_price($coupons_amount).__(" on this order", "aco-woo-dynamic-pricing");
                $result                 = '<div class="wdp_save_text" style="'.$customStyle.'">'.$message.'</div>';
                echo $result;

            }
        }

    }

    // Currency 
    public function get_con_unit( $product, $price = false, $insideloop = false )
    {

        if ( $this->conversion_unit === false && $insideloop === false ) {  

            global $WOOCS; // checking WooCommerce Currency Switcher (WOOCS) is enabled
            $from_currency  = get_option('woocommerce_currency');
            $to_currency    = get_woocommerce_currency();

            if ( $from_currency === $to_currency || $WOOCS !== null ) return 1; 
                        
            $view_price     = $product->get_price('view');
            $edit_price     = ( $price ) ? $price : $product->get_price('edit'); 

            /* 
            * ver @ 4.3.5
            *Commenting wcml_raw_price_amount - wpml conversion issue fix
            */
            // if ( $this->conversion_unit == 1) {
            //     $this->conversion_unit = apply_filters('wcml_raw_price_amount', 1);
            // }

            if ($this->conversion_unit == 1) { // Aelia Currency Switcher
                if(wc_get_price_decimals() == 0 ){
                    $converted_amount   = apply_filters('wc_aelia_cs_convert', 1, $from_currency, $to_currency,2);
                }else{
                    $converted_amount   = apply_filters('wc_aelia_cs_convert', 1, $from_currency, $to_currency);
                }
                $this->conversion_unit  = $converted_amount;
            }

            if ( ( $this->conversion_unit == 1 || $this->conversion_unit == false ) && class_exists('WOOMULTI_CURRENCY') ) { // WooCommerce Multi Currency Plugin
                $data                   = WOOMULTI_CURRENCY_Data::get_ins(); 
                $currency_array         = $data->get_list_currencies();
                $rate                   = (float)$currency_array[$to_currency]['rate']; 
                $this->conversion_unit  = $rate;
            }

            if ( ( $this->conversion_unit == 1 || $this->conversion_unit == false ) && class_exists('WOOMULTI_CURRENCY_F') ) { // WooCommerce Multi Currency Free Plugin
                $data                   = WOOMULTI_CURRENCY_F_Data::get_ins(); 
                $currency_array         = $data->get_list_currencies();
                $rate                   = (float)$currency_array[$to_currency]['rate']; 
                $this->conversion_unit  = $rate;
            }

            if ( ( $this->conversion_unit == 1 || $this->conversion_unit == false ) && function_exists('wcpbc_the_zone') ) {
                $wcpbc                  = wcpbc_the_zone();
                $converted_amount       = 1;
                if (is_callable($wcpbc, 'get_exchange_rate_price')) {
                    $converted_amount   = $wcpbc->get_exchange_rate_price(1);
                }
                $this->conversion_unit  = $converted_amount;
            } 

            if ( $view_price && $edit_price && $edit_price > 0 && $view_price > 0 && $this->conversion_unit == false ) {
                $this->conversion_unit  = $view_price / $edit_price;
            } else if ( $this->conversion_unit == false ) {
                $this->conversion_unit  = 1;
            } 

            // global $WOOCS;
            // if ($this->conversion_unit == 1 && $WOOCS!==null) {
            //     if (method_exists($WOOCS, 'woocs_exchange_value')) {
            //         $res=$WOOCS->woocs_exchange_value(1);
            //         $this->conversion_unit = $res;
            //     }
            // }

            return $this->conversion_unit;

        } else if ( $this->conversion_unit === false && $insideloop === true ) { // Pricing Table
            
            $from_currency      = get_option('woocommerce_currency');
            $to_currency        = get_woocommerce_currency(); 

            if ( $from_currency === $to_currency ) return 1;

            $converted_price    = $price;
            $unit_price         = $product->get_price('edit');

            $this->conversion_unit = $converted_price / $unit_price;

            // if ($this->conversion_unit == 1) { // Aelia Currency Switcher
            //     if(wc_get_price_decimals() == 0 ){
            //         $converted_amount = apply_filters('wc_aelia_cs_convert', 1, $from_currency, $to_currency,2);
            //     }else{
            //         $converted_amount = apply_filters('wc_aelia_cs_convert', 1, $from_currency, $to_currency);
            //     }
            //     $this->conversion_unit = $converted_amount;
            // }

            if ( ( $this->conversion_unit == 1 || $this->conversion_unit == false ) && class_exists('WOOMULTI_CURRENCY') ) { // WooCommerce Multi Currency Plugin
                $data                   = WOOMULTI_CURRENCY_Data::get_ins(); 
                $currency_array         = $data->get_list_currencies();
                $rate                   = (float)$currency_array[$to_currency]['rate']; 
                $this->conversion_unit  = $rate;
            }

            if ( ( $this->conversion_unit == 1 || $this->conversion_unit == false ) && class_exists('WOOMULTI_CURRENCY_F') ) { // WooCommerce Multi Currency Free Plugin
                $data                   = WOOMULTI_CURRENCY_F_Data::get_ins(); 
                $currency_array         = $data->get_list_currencies();
                $rate                   = (float)$currency_array[$to_currency]['rate']; 
                $this->conversion_unit  = $rate;
            }

            if ( ( $this->conversion_unit == 1 || $this->conversion_unit == false ) && function_exists('wcpbc_the_zone') ) {
                $wcpbc                  = wcpbc_the_zone();
                $converted_amount       = 1;
                if (is_callable($wcpbc, 'get_exchange_rate_price')) {
                    $converted_amount   = $wcpbc->get_exchange_rate_price(1);
                }
                $this->conversion_unit  = $converted_amount;
            }

            // global $WOOCS;
            // if ($this->conversion_unit == 1 && $WOOCS!==null) {
            //     if (method_exists($WOOCS, 'woocs_exchange_value')) { 
            //         $res=$WOOCS->woocs_exchange_value(1); 
            //         $this->conversion_unit = $res;
            //     }
            // } 

            return $this->conversion_unit;

        } else {

            return $this->conversion_unit;

        }

    }
    
    // Discount Check
    public function check_discount($slug)
    {
        $_discounts = array();

        foreach ($this->discounts as $discounts) {
            if ($discounts['discount_type'] == 'percent_product_price' || $discounts['discount_type'] == 'fixed_product_price' || $discounts['discount_type'] == 'cart_quantity') {
                if (array_key_exists('discounts', $discounts)) {
                    if (!array_key_exists('type', $discounts['discounts'])) {
                        foreach ($discounts['discounts'] as $key => $discount) {
                            if (!isset($_discounts[$key])) {
                                $_discounts[$key] = 0.0;
                            }
                            if ($discount != '')
                                $_discounts[$key] += $discount;
                        }
                    }
                }
            }
        }

        if (isset($_discounts[$slug]) && $_discounts[$slug] > 0)
            return true;
        else
            return false;
    }


    public function check_discount_shop ( $slug )
    {
        $_discounts = array();

        foreach ( $this->discounts as $discounts ) {
            if ( $discounts['discount_type'] == 'percent_product_price' || $discounts['discount_type'] == 'fixed_product_price' ) {
                if ( array_key_exists ( 'discounts', $discounts ) ) {
                    if ( !array_key_exists ( 'type', $discounts['discounts'] ) ) {
                        foreach ( $discounts['discounts'] as $key => $discount ) {
                            if ( !isset ( $_discounts[$key] ) ) {
                                $_discounts[$key] = 0.0;
                            }
                            if ( $discount != '' )
                                $_discounts[$key] += $discount;
                        }
                    }
                }
            }
        }

        if ( isset($_discounts[$slug]) && $_discounts[$slug] > 0 )
            return true;
        else
            return false;
    }


    // Validate Rules
    public function validate_discount_rules ( $cart_obj, $rule, $rules_to_validate = array(), $item = false, $single = false )
    {

        $list_id = ( array_key_exists ( 'product_list', $rule ) && $rule['product_list'] ) ? $rule['product_list'] : '';

        $evel_str = '';
        //  $rules_to_validate = ['cart_total_amount', 'cart_total_amount_all_prods', 'cart_items', 'cart_items_all_prods', 'cart_products'];
        $result = true;// if no rules, the validation must be true

        // Disabling Quantity Rules for Discount Type -> Cart Quantity
        if (array_key_exists('type', $rule) && 'cart_quantity' == $rule['type'] && 'cart_quantity' == $rule['quantity_type']) {
            $qn_flag = true;
        } else {
            $qn_flag = false;
        }

        if ( isset($rule['rules']) && is_array($rule['rules']) && !empty($rule['rules']) ) {

            foreach ( $rule['rules'] as $val ) {

                if ( !empty($val['rules']) && is_array($val['rules']) && count($val['rules']) ) {

                    $evel_str .= '(';
                    $val_rules = array_values ( array_filter( $val['rules'] ) ); // Remove null elements - 3.4.2 fix
                    foreach ( $val_rules as $rul ) { 
                        $evel_str .= '(';
                        if ( in_array ( $rul['rule']['item'], $rules_to_validate) && $rul['rule']['value'] != '' ) {
                            if ( $this->eval_rule ( $rul['rule'], $cart_obj, $rule, $list_id, $qn_flag, $item, $single ) ) { 
                                $evel_str .= ' true ';
                            } else { 
                                $evel_str .= ' false ';
                            }
                        } else {
                            $evel_str .= ' true ';
                        }

                        $evel_str .= ') ' . (($rul['operator'] !== false) ? $rul['operator'] : '') . ' ';
                    }

                    if ( count($val['rules']) > 0 && !empty($val['rules']) ) {
                        preg_match_all('/\(.*\)/', $evel_str, $match);
                        $evel_str = $match[0][0] . ' ';
                    }

                    $evel_str .= ') ' . (($val['operator'] !== false) ? $val['operator'] : '') . ' ';

                }

            }

            if (count($rule['rules']) > 0 && !empty($rule['rules']) && $evel_str != '') {
                preg_match_all('/\(.*\)/', $evel_str, $match);
                $evel_str = $match[0][0] . ' ';
            }

            $evel_str = str_replace(['and', 'or'], ['&&', '||'], strtolower($evel_str));
            
            if ($evel_str !== '') {
                $result = eval('return ' . $evel_str . ';');
            }

        } 

        return $result;
    }


    public function eval_rule ( $rule, $cart_obj, $discount_rule, $list_id, $qn_flag, $item = false, $single = false )
    {

        $product_lists      = $this->product_lists ? $this->product_lists : [];  

        // Initialise
        $wdp_cart_totals = $wdp_cart_items = $wdp_cart_quantity = $wdp_cart_quantity_pl = $wdp_cart_totals_pl = $wdp_cart_items_pl = 0;

        if ( isset ( WC()->cart ) && WC()->cart->get_cart_contents_count() > 0 ) {

            // Checkout page ajax loading fix 
            $cart_items = is_checkout() ? ( WC()->session->get('WDP_Cart') ? WC()->session->get('WDP_Cart') : WC()->cart->get_cart() ) : WC()->cart->get_cart(); 

            // Product List
            $applicable_products    = ( $list_id && $list_id != 'null' ) ? ( !empty ( $product_lists ) && array_key_exists ( $list_id, $product_lists ) ? $product_lists[$list_id] : [] ) : [];

            if ($cart_items) {
                foreach ( $cart_items as $cart_item ) {
                    // $product_data       = $cart_item['data']->get_data();
                    // $wdp_cart_totals    = $wdp_cart_totals + $product_data['price'] * $cart_item['quantity'];
                    $wdp_cart_totals    = $wdp_cart_totals + $cart_item['data']->get_price() * $cart_item['quantity'];
                    $wdp_cart_items     = $wdp_cart_items + $cart_item['quantity'];
                    $wdp_cart_quantity  = $wdp_cart_quantity + 1;
                    // check Product List
                    if ( !empty ( $applicable_products ) && in_array ( $cart_item['product_id'], $applicable_products ) ) { 
                        $wdp_cart_totals_pl    = $wdp_cart_totals_pl + $cart_item['data']->get_price() * $cart_item['quantity'];
                        $wdp_cart_items_pl     = $wdp_cart_items_pl + $cart_item['quantity'];
                        $wdp_cart_quantity_pl  = $wdp_cart_quantity_pl + 1;
                    }
                }
            }

        }

        if ( 'cart_total_amount' == $rule['item'] ) { 

            // cart based rule : true
            $this->awdp_cart_rules  = true;
            $this->apply_wdp_coupon = true; 
            // $this->awdp_cart_rule_ids[] = $discount_rule;

            // Check if cart is empty
            if ( !isset (WC()->cart) || $wdp_cart_quantity_pl == 0 || !did_action('woocommerce_before_calculate_totals') ) 
                return false;

            $item_val   = $wdp_cart_totals_pl;
            $rel_val    = (float)$rule['value'];

        } else if ( 'cart_total_amount_all_prods' == $rule['item'] ) { 

            // cart based rule : true
            $this->awdp_cart_rules  = true;
            $this->apply_wdp_coupon = true; 
            // $this->awdp_cart_rule_ids[] = $discount_rule;

            // Check if cart is empty
            if ( !isset (WC()->cart) || $wdp_cart_totals == 0 || !did_action('woocommerce_before_calculate_totals') ) 
                return false;

            $item_val   = $wdp_cart_totals;
            $rel_val    = (float)$rule['value'];

        } else if ( 'product_price' == $rule['item'] ) {

            $this->apply_wdp_coupon = true;

            // if ($single) {
            //     if(is_object($item)){
            //         $item_val = (float)$item->get_price();
            //     } else {
            //         $item_val = (float)$item['data']->get_price();
            //     }
            // } else {
                if(is_object($item)){
                    $item_val = (float)$item->get_price();
                } else {
                    $item_val = (float)$item['data']->get_price();
                }
                // $item_val = (float)$item->get_price();
            // } 

            $rel_val = (float)$rule['value'];

        } else if ( 'cart_items' == $rule['item'] && false == $qn_flag ) {

            // cart based rule : true
            $this->awdp_cart_rules      = true;
            $this->apply_wdp_coupon     = true;
            $this->awdp_cart_rule_ids[] = $discount_rule;

            // Check if cart is empty
            if ( !isset ( WC()->cart ) || $wdp_cart_quantity_pl == 0 || !did_action('woocommerce_before_calculate_totals') ) return false;

            $item_val   = $wdp_cart_items_pl; 
            $rel_val    = (float)$rule['value'];

        } else if ( 'cart_items_all_prods' == $rule['item'] && false == $qn_flag ) {

            // cart based rule : true
            $this->awdp_cart_rules      = true;
            $this->apply_wdp_coupon     = true;
            $this->awdp_cart_rule_ids[] = $discount_rule;

            // Check if cart is empty
            if ( !isset ( WC()->cart ) || $wdp_cart_quantity == 0 || !did_action('woocommerce_before_calculate_totals') ) return false;

            $item_val   = $wdp_cart_items; 
            $rel_val    = (float)$rule['value'];

        } else if ( 'cart_products' == $rule['item'] && false == $qn_flag ) {

            // cart based rule : true
            $this->awdp_cart_rules      = true;
            $this->apply_wdp_coupon     = true;
            $this->awdp_cart_rule_ids[] = $discount_rule;

            // Check if cart is empty
            if ( !isset ( WC()->cart ) || $wdp_cart_quantity == 0 || !did_action('woocommerce_before_calculate_totals') ) return false;

            $item_val   = $wdp_cart_quantity;
            $rel_val    = (float)$rule['value'];

        } else if ( 'cart_products_list' == $rule['item'] && false == $qn_flag ) {

            // cart based rule : true
            $this->awdp_cart_rules      = true;
            $this->apply_wdp_coupon     = true;
            $this->awdp_cart_rule_ids[] = $discount_rule;

            // Check if cart is empty
            if ( !isset ( WC()->cart ) || $wdp_cart_quantity_pl == 0 || !did_action('woocommerce_before_calculate_totals') ) return false;

            $item_val   = $wdp_cart_quantity_pl; 
            $rel_val    = (float)$rule['value'];

        } else {

            return false;

        }

        // if ( $item_val == 0 ) return false; // Divisible by zero error

        switch ($rule['condition']) {
            case 'equal_to':
                if ( $item_val > 0 && @abs(($item_val - $rel_val) / $item_val) < 0.00001 ) {
                    return true;
                }
                break;
            case 'less_than':
                if ($item_val < $rel_val) {
                    return true;
                }
                break;
            case 'less_than_eq':
                if ($item_val < $rel_val || abs(($item_val - $rel_val) / $item_val) < 0.0001) {
                    return true;
                }
                break;
            case 'greater_than': 
                if ($item_val > $rel_val) { 
                    return true;
                }
                break;
            case 'greater_than_eq':
                if ($item_val > $rel_val || abs(($item_val - $rel_val) / $item_val) < 0.0001) {
                    return true;
                }
                break;
        }

        return false;
    }

    // Rules
    public function load_rules()
    {

        if ($this->discount_rules === false) {

            /* 
            * Wordpress Time Zone Settings
            * @ Ver 4.0.8
            */
            $wp_tz_stngs    = get_option('awdp_time_zone_config') ? get_option('awdp_time_zone_config') : []; 
            $wp_tz          = array_key_exists ( 'wordpress_timezone', $wp_tz_stngs ) ? $wp_tz_stngs['wordpress_timezone'] : '';

            if ( $wp_tz ) {

                $timezone = new DateTimeZone( wp_timezone_string() );
                $datenow = wp_date("Y-m-d H:i:s", null, $timezone );

            } else {

                // Get wordpress timezone settings
                $gmt_offset         = get_option('gmt_offset');
                $timezone_string    = get_option('timezone_string');
                if ($timezone_string) {
                    $datenow    = new DateTime(current_time('mysql'), new DateTimeZone($timezone_string));
                } else {
                    $min        = 60 * get_option('gmt_offset');
                    $sign       = $min < 0 ? "-" : "+";
                    $absmin     = abs($min);
                    $tz         = sprintf("%s%02d%02d", $sign, $absmin / 60, $absmin % 60);
                    $datenow    = new DateTime(current_time('mysql'), new DateTimeZone($tz));
                }
                // Converting to UTC+000 (moment isoString timezone)
                $datenow->setTimezone(new DateTimeZone('+000'));
                $datenow    = $datenow->format('Y-m-d H:i:s');

            }

            $stop_date  = date('Y-m-d H:i:s', strtotime($datenow . ' +1 day'));
            $day        = date("l");

            $awdp_discount_args = array(
                'post_type'         => AWDP_POST_TYPE,
                'fields'            => 'ids',
                'post_status'       => 'publish',
                'posts_per_page'    => -1,
                'meta_key'          => 'discount_priority',
                'orderby'           => 'meta_value_num',
                'order'             => 'ASC',
                'meta_query'        => array(
                    'relation'      => 'AND',
                    array(
                        'key'       => 'discount_status',
                        'value'     => 1,
                        'compare'   => '=',
                        'type'      => 'NUMERIC'
                    ),
                    array(
                        'key'       => 'discount_start_date',
                        'value'     => $datenow,
                        'compare'   => '<=',
                        'type'      => 'DATETIME'
                    ),
                    // array(
                    //     'relation'  => 'OR',
                    //     array(
                    //         'key'       => 'discount_type',
                    //         'value'     => 'cart_quantity',
                    //         'compare'   => '='
                    //     ),
                    //     array(
                    //         'key'       => 'discount_value',
                    //         'value'     => '',
                    //         'compare'   => '!='
                    //     )
                    //     // array(
                    //     //     'relation' => 'AND', // only 'color' OR 'price' must match
                    //     //     array(
                    //     //         'key'       => 'discount_type',
                    //     //         'value'     => array ( 'percent_product_price', 'fixed_product_price' ),
                    //     //         'compare'   => 'IN'
                    //     //     ),
                    //     //     array(
                    //     //         'key'       => 'dynamic_value',
                    //     //         'value'     => 1,
                    //     //         'compare'   => '=',
                    //     //         'type'      => 'NUMERIC'
                    //     //     )
                    //     // )
                    // ),
                    array(
                        'relation'  => 'OR',
                        array(
                            'key'       => 'discount_end_date',
                            'value'     => $datenow,
                            'compare'   => '>=',
                            'type'      => 'DATETIME'
                        ),
                        array(
                            'key'       => 'discount_end_date',
                            'compare'   => 'NOT EXISTS',
                        ),
                        array(
                            'key'       => 'discount_end_date',
                            'value'     => '',
                            'compare'   => '=',
                        ),
                    )
                )
            );

            $awdp_discount_rules    = get_posts($awdp_discount_args); 

            $current_user           = is_user_logged_in() ? wp_get_current_user() : '';
            $user_roles             = $current_user ? ( array ) $current_user->roles : [];
            
            $discount_rules = $check_rules = array();

            if ( $awdp_discount_rules ) {
                foreach ( $awdp_discount_rules as $awdpID ) {

                    // Discount Value Check
                    if ( ( get_post_meta($awdpID, 'discount_type', true) != 'cart_quantity' && get_post_meta($awdpID, 'discount_value', true) == '' && get_post_meta($awdpID, 'dynamic_value', true) == '' ) || ( ( get_post_meta($awdpID, 'discount_type', true) == 'percent_product_price' || get_post_meta($awdpID, 'discount_type', true) == 'fixed_product_price' ) && get_post_meta($awdpID, 'dynamic_value', true) == '' && get_post_meta($awdpID, 'discount_value', true) == '' ) ) 
                        continue;
                    // End

                    $schedules = unserialize(get_post_meta($awdpID, 'discount_schedules', true));
                    if ( $schedules ) { 
                        foreach ( $schedules as $schedule ) {
                            $mn_start_time      = date('H:i' , strtotime($schedule['start_date'])); 
                            $mn_end_time        = date('H:i' , strtotime($schedule['end_date'])); 
                            $current_time       = strtotime(gmdate('H:i'));
                            $awdp_start_date    = $schedule['start_date'];
                            $awdp_end_start     = $schedule['end_date'] ? $schedule['end_date'] : $stop_date;
                            if ( ( $awdp_start_date <= $datenow ) && ( $awdp_end_start >= $datenow ) && !in_array( $awdpID, $check_rules ) ) {
                                $rule_type          = get_post_meta($awdpID, 'discount_type', true);
                                $discount_config    = get_post_meta($awdpID, 'discount_config', true);
                                $check_rules[]      = $awdpID; // remove repeated entry - single rule
                                $discount_rules[]   = array(
                                    'id'                    => $awdpID,
                                    'priority'              => get_post_meta($awdpID, 'discount_priority', true),
                                    'label'                 => ($discount_config['label'] != '') ? $discount_config['label'] : ( get_option('awdp_fee_label') ? get_option('awdp_fee_label') : get_the_title($awdpID) ),
                                    'discount'              => get_post_meta($awdpID, 'discount_value', true),
                                    'inc_tax'               => $discount_config['inc_tax'],
                                    'disable_on_sale'       => $discount_config['disable_on_sale'],
                                    'apply_rule_once'       => array_key_exists ( 'apply_rule_once', $discount_config ) ? $discount_config['apply_rule_once'] : false,
                                    'discount_reg_customers' => get_post_meta($awdpID, 'discount_reg_customers', true),
                                    'discount_reg_user_roles' => get_post_meta($awdpID, 'discount_reg_user_roles', true) ? get_post_meta($awdpID, 'discount_reg_user_roles', true) : [],
                                    'discount_cur_user_roles' => $user_roles,

                                    'sequentially'          => $discount_config['sequentially'],
                                    'product_list'          => get_post_meta($awdpID, 'discount_product_list', true),
                                    'rules'                 => $discount_config['rules'] ? unserialize(base64_decode($discount_config['rules'])) : '',
                                    'type'                  => $rule_type,
                                    'quantity_rules'        => get_post_meta($awdpID, 'discount_quantityranges', true) ? unserialize(get_post_meta($awdpID, 'discount_quantityranges', true)) : '',
                                    'quantity_type'         => get_post_meta($awdpID, 'discount_quantity_type', true),
                                    'disc_calc_type'        => get_post_meta($awdpID, 'discount_calc_type', true),
                                    'pricing_table'         => get_post_meta($awdpID, 'discount_pricing_table', true),
                                    'table_layout'          => get_post_meta($awdpID, 'discount_table_layout', true),
                                    'variation_check'       => get_post_meta($awdpID, 'discount_variation_check', true),

                                    'dynamic_value'         => get_post_meta($awdpID, 'dynamic_value', true),
                                    
                                    'custom_pl_status'      => get_post_meta($awdpID, 'discount_custom_pl', true) ? get_post_meta($awdpID, 'discount_custom_pl', true) : '',
                                    'custom_pl'             => get_post_meta($awdpID, 'custom_product_list', true) ? get_post_meta($awdpID, 'custom_product_list', true) : '',
                                );
                            }
                        }
                    }
                }
            }

            // Moving Cart based rules to least priority
            $cart_rules = [];
            foreach ( $discount_rules as $key => $val ) {
                if ( isset($val) && ( 'cart_quantity' == $val['type'] || 'fixed_cart_amount' == $val['type'] || 'percent_total_amount' == $val['type'] ) ) {
                    $cart_rules[] = $discount_rules[$key];
                    unset($discount_rules[$key]);
                }
            }
            $discount_rules = array_merge($discount_rules, $cart_rules);
            $discount_rules = array_values($discount_rules);

            // Discount rules
            $this->discount_rules = $discount_rules;
        }

    }


    public function get_items_to_apply_discount ( $product, $rule, $disc_prod_ID = false, $cartRule = false, $product_slug = false )
    {

        $items = $result = array();
        global $woocommerce; 

        /*
        * @ver 4.1.3
        * Fix - Disable discount on onsale variations
        */
        $newProduct = $disc_prod_ID ? wc_get_product ( $disc_prod_ID ) : $product;

        //validate with $rule
        if (!$this->check_in_product_list($product, $rule)) {
            return false;
        }

        if (!$this->validate_discount_rules($product, $rule, ['product_price'], $newProduct)) { 
            return false;
        }
        
        if (isset($rule['disable_on_sale']) && $rule['disable_on_sale'] && $newProduct->is_on_sale('edit')) {
            return false;
        }

        if ( $cartRule && $this->awdp_cart_rules ) // For Product Price View // Check cart rules active
            return false;

        // if ( $product_slug && isset ( $rule['apply_rule_once'] ) && $rule['apply_rule_once'] && in_array ( $product_slug, $this->discounted_products ) ) 
        //      return false;

        return true;

    }


    public function check_in_product_list($product, $rule)
    {

        if ( ( '' == $rule['product_list'] || 0 == $rule['product_list'] ) && !$rule['custom_pl_status'] ) {

            return true;

        } else if ( $rule['custom_pl_status'] ) { 

            // Custom Product List
            $customPL   = $rule['custom_pl'];
            $pro_id     = ( $product->get_parent_id() == 0 ) ? $product->get_id() : $product->get_parent_id(); 
            $prodIDs    = [];   
            
            if ( !empty ( $customPL ) ) {

                $wdp_tax_query = $wdp_prod_ids = $prodIDs = []; $taxcnt = 1;
                foreach ( $customPL as $singlePL ) { 
                    foreach ( $singlePL['rules'] as $val ) {
                        if ( is_array ( $val ) && $val['rule']['value'] ) {
                            if ( $val['rule']['item'] == 'product_selection') {
                                $wdp_prod_ids = array_merge ( $wdp_prod_ids, $val['rule']['value'] );
                            } else {
                                if ( $taxcnt === 1 ) { $wdp_tax_query = array('relation' => 'OR'); }
                                $taxoperator = ( $val['rule']['condition'] === 'notin' ) ? 'NOT IN' : 'IN'; 
                                $wdp_tax_query[] = array(
                                    'taxonomy'  => $val['rule']['item'],
                                    'field'     => 'term_id',
                                    'terms'     => $val['rule']['value'],
                                    'operator'  => $taxoperator
                                );
                                $taxcnt++;
                            }
                        }
                    } 
                }

                if ( !empty($wdp_tax_query) ) {
                    $args = array(
                        'post_type'         => AWDP_WC_PRODUCTS,
                        'fields'            => 'ids',
                        'post_status'       => array( 'publish', 'draft' ),
                        'posts_per_page'    => -1,
                        'tax_query'         => $wdp_tax_query
                    );
                    $prodIDs    = get_posts ( $args );
                }
                $prodIDs	= !empty ( $wdp_prod_ids ) ? array_merge ( $wdp_prod_ids, $prodIDs ) : $prodIDs; 

                return isset($prodIDs) && in_array($pro_id, $prodIDs);

            } else {

                return false; // Return false if selection is empty
                
            }

        } else {

            $this->set_product_list();
            $pro_id = $product->get_parent_id(); // in case of variation
            if ($pro_id == 0) {
                $pro_id = $product->get_id();
            }
            return isset($this->product_lists[$rule['product_list']]) &&
                in_array($pro_id, $this->product_lists[$rule['product_list']]);
                
        }

    }
    

    public function set_product_list()
    {

        if (false == $this->product_lists) {

            $checkML                = call_user_func ( array ( new AWDP_ML(), 'is_default_lan' ), '' );
            $currentLang            = !$checkML ? call_user_func ( array ( new AWDP_ML(), 'current_language' ), '' ) : 'default';

            if ( false === ( $product_lists = get_transient(AWDP_PRODUCTS_TRANSIENT_KEY) ) || get_transient(AWDP_PRODUCTS_LANG_TRANSIENT_KEY) != $currentLang ) {
                
                $post_type = AWDP_PRODUCT_LIST;
                global $wpdb;

                $product_lists = array();
                $lists = array_values ( array_diff ( array_filter ( $wpdb->get_col ( $wpdb->prepare ( "
                        SELECT pm.meta_value FROM {$wpdb->postmeta} pm
                        LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                        WHERE pm.meta_key = '%s' 
                        AND p.post_status = '%s' 
                        AND p.post_type = '%s'
                        ", 'discount_product_list', 'publish', AWDP_POST_TYPE ) ) ), array("null") ) );

                $post_ids = array_map ( function($value) { return (int)$value; }, $lists );

                foreach ($post_ids as $id) {

                    $list_type      = get_post_meta($id, 'list_type', true); 
                    $other_config   = get_post_meta($id, 'product_list_config', true) ? get_post_meta($id, 'product_list_config', true) : [];

                    $product_lists[$id] = array();

                    if ( 'dynamic_request' == $list_type ) {

                        $tax_rules          = array_key_exists ( 'rules', $other_config ) ? ($other_config['rules']) : [];
                        $tax_rules          = ($tax_rules && is_array($tax_rules) && !empty($tax_rules)) ? $tax_rules : false;
                        $excludedProducts   = ($other_config['excludedProducts']);
                        $tax_query          = [];

                        $args = array(
                            'post_type'         => AWDP_WC_PRODUCTS,
                            'fields'            => 'ids',
                            'post_status'       => array( 'publish', 'draft' ),
                            'posts_per_page'    => -1,
                        );

                        if ( $excludedProducts ) {
                            $args['post__not_in'] = $excludedProducts;
                        }

                        if ( false !== $tax_rules ) { 

                            if ( isset($tax_rules[0]['rules']) && is_array($tax_rules[0]['rules']) ) {
                                $selected_tax = array_filter($tax_rules[0]['rules']);
                                if ( ( sizeof ( $selected_tax ) ) > 1 ) {
                                    $tax_query = array(
                                        'relation' => ('or' == strtolower($other_config['taxRelation'])) ? 'OR' : 'AND'
                                    );
                                }
                                foreach ( $selected_tax as $tr ) { 
                                    $taxoperator = ( $tr['rule']['condition'] === 'notin' ) ? 'NOT IN' : 'IN'; 
                                    $tax_query[] = array(
                                        'taxonomy'  => $tr['rule']['item'],
                                        'field'     => 'term_id',
                                        'terms'     => $tr['rule']['value'],
                                        'operator'  => $taxoperator
                                    );
                                }
                                $args['tax_query'] = $tax_query;
                            }

                        }

                        $product_lists[$id] = get_posts ( $args );

                    } else {

                        $product_lists[$id] = array_key_exists ( 'selectedProducts', $other_config ) ? ($other_config['selectedProducts']) : [];

                    }

                    if ( $product_lists[$id] && class_exists('SitePress') ) { // Get WPML Product ids @@ 3.6.2
                        $wpmlPosts = [];
                        foreach ( $product_lists[$id] as $product_list_id ) { 
                            $transID = apply_filters( 'wpml_object_id', $product_list_id, 'product' );
                            if ( $transID ) {
                                $wpmlPosts[] = $transID;
                            }
                        }
                        $product_lists[$id] = array_values ( array_unique ( array_merge ( $product_lists[$id], $wpmlPosts ) ) );
                    }
                    
                }

                set_transient(AWDP_PRODUCTS_TRANSIENT_KEY, $product_lists, 7 * 24 * HOUR_IN_SECONDS);
                set_transient(AWDP_PRODUCTS_LANG_TRANSIENT_KEY, $currentLang, 7 * 24 * HOUR_IN_SECONDS);

            }

            $this->product_lists = $product_lists;
            
        }

    }

    public function set_custom_list ( $product_list ) 
    {
        
        $customPL           = get_post_meta ( $product_list, 'custom_product_list', true ) ? get_post_meta ( $product_list, 'custom_product_list', true ) : [];
        $customProds        = []; 
        $pr_cnt             = 1; 
        $tax_flag           = false;

        if ( !empty ( $customPL ) ) { 
            $args = array(
                'post_type'         => AWDP_WC_PRODUCTS,
                'fields'            => 'ids',
                'post_status'       => array( 'publish', 'draft' ),
                'posts_per_page'    => -1,
            );
            $tax_query = array(
                'relation' => 'OR'
            );
            foreach ( $customPL as $singlePL ) { 
                foreach ( $singlePL['rules'] as $val ) { 
                    if ( is_array ( $val ) && $val['rule']['value'] ) {
                        if ( $val['rule']['item'] == 'product_selection') {
                            $customProds = array_merge ( $customProds, $val['rule']['value'] ); 
                            $pr_cnt++;
                        } else {
                            $tax_flag    = true;
                            $taxoperator = ( $val['rule']['condition'] === 'notin' ) ? 'NOT IN' : 'IN'; 
                            $tax_query[] = array(
                                'taxonomy'  => $val['rule']['item'],
                                'field'     => 'term_id',
                                'terms'     => $val['rule']['value'],
                                'operator'  => $taxoperator
                            );
                        }
                    }
                } 
            }
            if ( $tax_flag ) {
                $args['tax_query']  = $tax_query;
                $custom_ids         = get_posts ( $args );
                $customProds        = array_merge ( $customProds, $custom_ids );
            }
        }

        return $customProds;

    }

    public function get_individual_discounted_price_in_cents($item, $include_tax = true, $sequential = false, $price = false)
    {

        $latest_price = '';
        $excluding_tax = get_option('woocommerce_tax_display_shop');
        $cur_price = $price ? $price : $item->get_data()['price'];
        if ($excluding_tax == 'incl') {
            $price = $this->wdp_price_including_tax ( $item, $cur_price, array(
                'price' => $cur_price,
            ) );
        } else {
            $price = $this->wdp_price_excluding_tax ( $item, $cur_price, array(
                'price' => $cur_price,
            ) );
        }

        return wc_add_number_precision($price);

    }

    public function get_discount($key, $in_cents = false)
    {
        $item_discount_totals = $this->get_discounts_by_item($in_cents);
        return isset($item_discount_totals[$key]) ? $item_discount_totals[$key] : 0;
    }


    public function get_discounts_by_item($in_cents = false)
    {
        $discounts = $this->discounts;
        $item_discount_totals = array();

        foreach ($discounts as $item_discounts) {
            if ($item_discounts['discounts']) {
                foreach ($item_discounts['discounts'] as $item_key => $item_discount) {
                    if (!isset($item_discount_totals[$item_key])) {
                        $item_discount_totals[$item_key] = 0.0;
                    }
                    // $item_discount_totals[$item_key] += $item_discount;
                    $item_discount_totals[$item_key] += isset($item_discount) ? $item_discount : 0.0;
                }
            }
        }

        return $in_cents ? $item_discount_totals : $item_discount_totals;
    }

    public function addVirtualCoupon($response, $curr_coupon_code)
    {

        if ( $this->discounts && WC()->cart ) { 

            global $woocommerce;
            $prod_QNT               = [];
            $total                  = 0;
            $ct_total_new           = 0;
            $ct_cart_price_array    = [];
            $cart_contents          = $woocommerce->cart->get_cart();
            $ct_total               = $this->wdpCartDicount;
            $ct_discount_values     = $this->wdpCartDiscountValues;
            $converted_rate         = $this->converted_rate ? $this->converted_rate : 1;
            $label                  = get_option('awdp_fee_label') ? get_option('awdp_fee_label') : 'Discount';
            $this->couponLabel      = $label;
            $prod_IDs               = [];

            $discountItemsCount         = 0; // @4.4.6
            $discountItemsActPrice      = 0; // @4.4.6
            $discountItemsActPriceIDS   = []; // @4.5.0

            foreach ($cart_contents as $cart_content) {
                $prod_QNT[$cart_content['data']->get_data()['slug']]    = $cart_content['quantity'];
                $ct_total_new                                           = $ct_total_new + ($cart_content['data']->get_price() * $cart_content['quantity']);
                $ct_cart_price_array[]                                  = array ( 'id' => $cart_content['data']->get_slug(), 'price' => ( $cart_content['data']->get_price() * $cart_content['quantity'] ) ); 
                // if ( $cart_content['variation_id'] == 0 ) { 
                //     $cart_item_price[$cart_content['product_id']]   = $cart_content['data']->get_price();
                // } else {
                //     $cart_item_price[$cart_content['variation_id']] = $cart_content['data']->get_price();
                // }
                $cart_item_price[$cart_content['key']] = $cart_content['data']->get_price();

            } 

            foreach ( $this->discounts as $ruleid => $discounts ) { 

                $discount_type = $discounts['discount_type'];
                $qn_type       = ( $discount_type == 'cart_quantity' ) ? get_post_meta ( $ruleid, 'discount_quantity_type', true ) : '';

                if ( ( $label == $curr_coupon_code ) || ( mb_strtolower($label, 'UTF-8') == mb_strtolower($curr_coupon_code, 'UTF-8') ) || ( addslashes(mb_strtolower($label, 'UTF-8')) == mb_strtolower($curr_coupon_code, 'UTF-8') ) || ( preg_match('/[\'^£$%&*()}{@#~?><>,|=_+¬-]/', $label) && mb_strtolower($label, 'UTF-8') == mb_strtolower(htmlspecialchars_decode ($curr_coupon_code), 'UTF-8') ) ) {

                    if ( array_key_exists ( 'discounts', $discounts ) ) { 

                        foreach ( $discounts['discounts'] as $key => $discount ) { 

                            $disc_product_ID = $discount['productid'];

                            if ( !in_array ( $disc_product_ID, $prod_IDs ) ) {
                                $prod_IDs[] = $disc_product_ID;
                            }

                            if ( $discount['discount'] != '' ) {
                                // Decimal Round
                                $decimal_val    = $discount['discount'] - floor($discount['discount']);
                                // $calc_discount  = ( $decimal_val == 0 ) ? $discount['discount'] : ( ( $decimal_val > 0.5 ) ? ceil ( $discount['discount'] ) : floor ( $discount['discount'] ) );

                                // Check decimal length 
                                if ( ( strlen ( strrchr ( $decimal_val, '.' ) ) -1 ) > 2 ) { 
                                    $calc_discount  = ( $decimal_val == 0 ) ? $discount['discount'] : ( ( $decimal_val > 0.5 ) ? ceil ( $discount['discount'] ) : floor ( $discount['discount'] ) ); 
                                    // $calc_discount  = round ( $discount['discount'], 2 ); 
                                } else { 
                                    $calc_discount  = $discount['discount']; 
                                } 

                                if ( $discount_type == 'fixed_product_price' || $discount_type == 'percent_product_price' || ( $discount_type == 'cart_quantity' && $qn_type == 'type_product' ) ) {
                                    $calc_discount = $calc_discount * $discount['quantity'];
                                }

                                $total                  = $total + ( wc_remove_number_precision ( $calc_discount ) );
                                
                            }

                            /*
                            * @ 4.4.8
                            * moved outside discount null check
                            */
                            $discountItemsCount             = $discountItemsCount + $discount['quantity'];
                            // $discountItemsActPrice += $discount["quantity"] ? ( $cart_item_price[$key] * $discount["quantity"] ) : $cart_item_price[$key];

                            if (isset($cart_item_price[$key])) {

                                $discountItemsActPriceIDS[$key] = $discount["quantity"] ? ( $cart_item_price[$key] * $discount["quantity"] ) : $cart_item_price[$key];
                            }

                        }

                        
                    }
                    
                }
                
            } 
            
            $discountItemsActPrice  = !empty ( $discountItemsActPriceIDS ) ? array_sum ( $discountItemsActPriceIDS ) : 0;

            if ( $total > 0 ) { 

                if ( $converted_rate > 0 && $converted_rate != 1 ) { // Removing conversion from coupon total
                    $total = $total / $converted_rate;
                } 

                if ( !$discount_type ) 
                    return false; 

                /*
                * @ ver 4.4.6 
                * swicthing coupon discount type to 'fixed_product' from 'fixed_cart'
                * dividing by number of products (offer items)
                */

                // $total          = $discountItemsCount ? ( $total / $discountItemsCount ) : $total;
                // $coupnDiscType  = $discountItemsCount ? 'fixed_product' : 'fixed_cart';

                // Changing to percent
                // $total          = $discountItemsActPrice ? ( $total / $discountItemsActPrice ) * 100 : $total; 
                // $coupnDiscType  = $discountItemsActPrice ? 'percent' : 'fixed_cart'; 
                $coupnDiscType  = 'fixed_cart'; 

                $coupon_array = array(
                    'code'                          => mb_strtolower($label, 'UTF-8'),
                    'id'                            => 99999999 + rand(1000, 9999),
                    'amount'                        => $total,
                    'individual_use'                => false,
                    'product_ids'                   => $prod_IDs,
                    'exclude_product_ids'           => array(),
                    'usage_limit'                   => '',
                    'usage_limit_per_user'          => '',
                    'limit_usage_to_x_items'        => '',
                    'usage_count'                   => '',
                    'expiry_date'                   => '',
                    'apply_before_tax'              => 'yes',
                    'free_shipping'                 => false,
                    'product_categories'            => array(),
                    'exclude_product_categories'    => array(),
                    'exclude_sale_items'            => false,
                    'minimum_amount'                => '',
                    'maximum_amount'                => '',
                    'customer_email'                => '',
                    'discount_type'                 => $coupnDiscType
                );

                if ( !WC()->session->get( 'AWDP_CART_NOTICE' ) && get_option( 'awdp_message_status' ) == 1 && !isset( $_POST['update_cart'] ) && !is_checkout() ) { 

                    // wc_clear_notices();  // Clear Woocommerce notices

                    // define('AWDP_CART_NOTICE', true);
                    WC()->session->set( 'AWDP_CART_NOTICE', true ); // Changed to session 3.4.5

                    $notice = (get_option('awdp_message_status') == 1) ? (get_option('awdp_discount_message') ? str_replace('[label]', $label, get_option('awdp_discount_message')) : (('discount' == mb_strtolower($label, 'UTF-8')) ? $label . __(" has been applied!", "aco-woo-dynamic-pricing") : __("Discount '", "aco-woo-dynamic-pricing") . $label . __("' has been applied!", "aco-woo-dynamic-pricing"))) : (('discount' == mb_strtolower($label, 'UTF-8')) ? $label . __(" has been applied!", "aco-woo-dynamic-pricing") : __("Discount '", "aco-woo-dynamic-pricing") . $label . __("' has been applied!", "aco-woo-dynamic-pricing"));

                    if (false === wc_has_notice($notice, 'notice')) {
                        wc_add_notice($notice, 'notice');
                    }

                }

                return $coupon_array;
            } 

        }

        return $response;

    }


    // Create virtual coupon
    public function couponLabel($label, $coupon)
    {

        if ($coupon) {
            $coupon_label = $this->couponLabel;
            $code = $coupon->get_code();
            if ($code == $coupon_label || mb_strtolower($code, 'UTF-8') == mb_strtolower($coupon_label, 'UTF-8')) {
                return ucfirst($coupon_label);
            }
        }
        return $label;
    }


    // Coupon label
    public function applyFakeCoupons()
    {

        global $woocommerce;  //apply_filters('woocommerce_applied_coupon');
        $coupon             = get_option('awdp_fee_label') ? get_option('awdp_fee_label') : 'Discount';
        $coupon_code        = apply_filters('woocommerce_coupon_code', $coupon);

        if ( !in_array($coupon_code, $woocommerce->cart->get_applied_coupons()) && $this->discounts && true == $this->apply_wdp_coupon && WC()->cart ) {

            $coupons_obj    = new WC_Coupon($coupon_code);
            $coupons_amount = $coupons_obj->get_amount();
            if ($coupons_amount > 0) {
                $woocommerce->cart->add_discount($coupon_code);
                // wc_clear_notices(); // Clear Woocommerce notices
            }

        } else if ( in_array($coupon_code, $woocommerce->cart->get_applied_coupons()) ) {

            $coupons_obj    = new WC_Coupon($coupon_code);
            $coupons_amount = $coupons_obj->get_amount();
            if ($coupons_amount == 0) {
                WC()->cart->remove_coupon($coupon_code);
                //   wc_clear_notices(); // Clear Woocommerce notices
            }

        }

        $applied_coupons = WC()->cart->get_applied_coupons();

        return true;

    }

    public function wdpDynamicPricingTable() {
    
        $nonce              = array_key_exists ( 'nonce', $_POST ) ? $_POST['nonce'] : '';
        $type               = array_key_exists ( 'type', $_POST ) ? $_POST['type'] : '';
        $ProdID             = array_key_exists ( 'ProdID', $_POST ) ? $_POST['ProdID'] : '';
        $ProdPrice          = array_key_exists ( 'ProdPrice', $_POST ) ? $_POST['ProdPrice'] : 0;
        $ProdQty            = array_key_exists ( 'ProdQty', $_POST ) ? $_POST['ProdQty'] : '';
        $Rule               = array_key_exists ( 'Rule', $_POST ) ? $_POST['Rule'] : '';
        $RPrice             = array_key_exists ( 'price', $_POST ) ? $_POST['price'] : 0;
        $DisData            = array_key_exists ( 'DisData', $_POST ) ? ( $_POST['DisData'] ? json_decode ( stripslashes ( str_replace ('\'', '"', $_POST['DisData']) ) ) : '' ) : '';
        $price              = '';
        $discountprice      = 0;
        $converted_rate     = 1; 
        $result             = [];
        $table              = '';

        $checkML            = call_user_func ( array ( new AWDP_ML(), 'is_default_lan' ), '' );
        $currentLang        = !$checkML ? call_user_func ( array ( new AWDP_ML(), 'current_language' ), '' ) : '';
        $langSettings       = get_option('awdp_settings_lang_options') ? get_option('awdp_settings_lang_options') : [];

        if ( !empty ($langSettings) && array_key_exists ( $currentLang, $langSettings ) ) {
            $awdp_pc_title              = array_key_exists ( 'pricing_title', $langSettings[$currentLang] ) ? $langSettings[$currentLang]['pricing_title'] : ( get_option('awdp_pc_title') ? get_option('awdp_pc_title') : __("Quantity Discounts", "aco-woo-dynamic-pricing") );
            $awdp_pc_label              = array_key_exists ( 'pricing_price_label', $langSettings[$currentLang] ) ? $langSettings[$currentLang]['pricing_price_label'] : ( get_option('awdp_pc_label') ? get_option('awdp_pc_label') : __("Price", "aco-woo-dynamic-pricing") );
            $awdp_qn_label              = array_key_exists ( 'pricing_quantity_label', $langSettings[$currentLang] ) ? $langSettings[$currentLang]['pricing_quantity_label'] : ( get_option('awdp_qn_label') ? get_option('awdp_qn_label') : __("Quantity", "aco-woo-dynamic-pricing") );
            $awdp_nw_label              = array_key_exists ( 'pricing_new_label', $langSettings[$currentLang] ) ? $langSettings[$currentLang]['pricing_new_label'] : ( get_option('awdp_new_label') ? get_option('awdp_new_label') : __("Price", "aco-woo-dynamic-pricing") );   
        } else  {
            $awdp_pc_title              = get_option('awdp_pc_title') ? get_option('awdp_pc_title') : __("Quantity Discounts", "aco-woo-dynamic-pricing");
            $awdp_pc_label              = get_option('awdp_pc_label') ? get_option('awdp_pc_label') : __("Price", "aco-woo-dynamic-pricing");
            $awdp_qn_label              = get_option('awdp_qn_label') ? get_option('awdp_qn_label') : __("Quantity", "aco-woo-dynamic-pricing");
            $awdp_nw_label              = get_option('awdp_new_label') ? get_option('awdp_new_label') : __("Price", "aco-woo-dynamic-pricing");
        }
        if ( $type === 'change' ) { 

            if ( $DisData ) {

                $value_display              = get_option('awdp_table_value') ? get_option('awdp_table_value') : '';
                $value_display_text_hide    = get_option('awdp_table_value_notext') ? get_option('awdp_table_value_notext') : 0; 
                $table_layout               = $Rule ? get_post_meta($Rule, 'discount_table_layout', true) : '';
                $var_price                  = $RPrice;
                $discounted_new_price_bt    = '';

                if ( !empty ($langSettings) && array_key_exists ( $currentLang, $langSettings ) ) {
                    $value_display_text         = array_key_exists ( 'tablevaluetext', $langSettings[$currentLang] ) ? $langSettings[$currentLang]['tablevaluetext'] : get_option('awdp_table_value_text');
                } else  {
                    $value_display_text         = get_option('awdp_table_value_text') ? get_option('awdp_table_value_text') : '';
                }

                // Pricing table texts
                $prcn_text      = ( !$value_display_text_hide ) ? ( ( ( $value_display == 'discount_value' || $value_display == 'discount_both' ) && $value_display_text ) ? ' '.$value_display_text : __('% OFF', 'aco-woo-dynamic-pricing') ) : '';
                $fxd_text       = ( !$value_display_text_hide ) ? ( ( ( $value_display == 'discount_value' || $value_display == 'discount_both' ) && $value_display_text ) ? ' '.$value_display_text : __(' OFF on cart value', 'aco-woo-dynamic-pricing') ) : '';
                $fxd_text_two   = ( !$value_display_text_hide ) ? ( ( ( $value_display == 'discount_value' || $value_display == 'discount_both' ) && $value_display_text ) ? ' '.$value_display_text : __(' OFF', 'aco-woo-dynamic-pricing') ) : '';
                $cart_text      = ( !$value_display_text_hide ) ? ( ( ( $value_display == 'discount_value' || $value_display == 'discount_both' ) && $value_display_text ) ? ' '.$value_display_text : __(' will be deducted from cart', 'aco-woo-dynamic-pricing') ) : '';

                if ($table_layout == 'horizontal') {
                   
                    $tr_qn = '<tr><td>' . $awdp_qn_label . '</td>';
                    $tr_pr = '<tr><td>' . $awdp_pc_label . '</td>';
                    if ( $value_display == 'discount_both' ) {
                        $tr_nw = '<tr><td>' . $awdp_nw_label . '</td>';
                    }
                }

                foreach ( $DisData as $quantity_rule ) { 

                    $dis_value  = $quantity_rule->dis_value;
                    $dis_type   = $quantity_rule->dis_type;

                    if ($dis_type == 'percentage') {
                        $discounted_new_price_bt = (float)$dis_value . $prcn_text;
                    } else if ($dis_type == 'fixed') {
                        $discounted_new_price_bt = wc_price((float)$dis_value) . $fxd_text_two;
                    }

                    if ($dis_type == 'percentage') {
                        $discount_pt = $var_price * ((float)$dis_value / 100);
                        $discount_pt = min($var_price, $discount_pt);
                    } else if ($dis_type == 'fixed') {
                        $discount_pt = $dis_value * $converted_rate;
                    }

                    $discounted_new_price = (($var_price - $discount_pt) > 0) ? wc_price ( ( $var_price - $discount_pt ) * $converted_rate ) : 0;

                    if ( $table_layout == 'horizontal' ) {
                        if ($quantity_rule->start_range == $quantity_rule->end_range) {
                            $tr_qn .= '<td>' . $quantity_rule->start_range . '</td>';
                        } 
                        else if ($quantity_rule->end_range) {
                            $tr_qn .= '<td>' . $quantity_rule->start_range . ' - ' . $quantity_rule->end_range . '</td>';
                        } else {
                            $tr_qn .= '<td>' . $quantity_rule->start_range . ' +</td>';
                        }
                        $tr_pr .= '<td>' . $discounted_new_price_bt . '</td>';
                        if ( $value_display == 'discount_both' ) {
                            $tr_nw .= '<td>' . $discounted_new_price . '</td>';
                        }
                    } else {
                        if ($quantity_rule->start_range == $quantity_rule->end_range) {
                            if ( $value_display == 'discount_value' ) {
                                $table .= '<tr><td>' . $quantity_rule->start_range . '</td><td>' . $discounted_new_price_bt . '</td></tr>';
                            } else if ( $value_display == 'discount_both' ) {
                                $table .= '<tr><td>' . $quantity_rule->start_range . '</td><td>' . $discounted_new_price_bt . '</td><td>' . $discounted_new_price . '</td></tr>';
                            } else {
                                $table .= '<tr><td>' . $quantity_rule->start_range . '</td><td>' . $discounted_new_price . '</td></tr>';
                            }
                        } else if ($quantity_rule->end_range) {
                            if ( $value_display == 'discount_value' ) {
                                $table .= '<tr><td>' . $quantity_rule->start_range . '</td><td>' . $discounted_new_price_bt . '</td></tr>';
                            } else if ( $value_display == 'discount_both' ) {
                                $table .= '<tr><td>' . $quantity_rule->start_range . ' - ' . $quantity_rule->end_range . '</td><td>' . $discounted_new_price_bt . '</td><td>' . $discounted_new_price . '</td></tr>';
                            } else {
                                $table .= '<tr><td>' . $quantity_rule->start_range . ' - ' . $quantity_rule->end_range . '</td><td>' . $discounted_new_price . '</td></tr>';
                            }
                        } else {
                            if ( $value_display == 'discount_value' ) {
                                $table .= '<tr><td>' . $quantity_rule->start_range . '</td><td>' . $discounted_new_price_bt . '</td></tr>';
                            } else if ( $value_display == 'discount_both' ) {
                                $table .= '<tr><td>' . $quantity_rule->start_range . ' +</td><td>' . $discounted_new_price_bt . '</td><td>' . $discounted_new_price . '</td></tr>';
                            } else {
                                $table .= '<tr><td>' . $quantity_rule->start_range . ' +</td><td>' . $discounted_new_price . '</td></tr>';
                            }
                        }
                    }

                }

                if ($table_layout == 'horizontal') {
                    $tr_qn .= '</tr>';
                    $tr_pr .= '</tr>';
                    if ( $value_display == 'discount_both' ) { 
                        $tr_nw .= '</tr>';
                        $table .= $tr_qn . $tr_pr . $tr_nw;
                    } else {
                        $table .= $tr_qn . $tr_pr;
                    }
                }

                echo $table;

            }

        } else {
        
            $variation_prices   = $_POST['ProdVarPrice'] ? json_decode($_POST['ProdVarPrice']) : [];

            if ( $DisData ) {

                // if ( !empty ( $variation_prices ) ) {

                //     $price_to_discount_max = $variation_prices ? max($variation_prices) : 0;
                //     $price_to_discount_min = $variation_prices ? min($variation_prices) : 0;

                //     // Default value when quantity not in range
                //     $result['price']            = $price_to_discount_min ? $price_to_discount_min : 0;
                //     $result['total']            = $price_to_discount_min ? round ( ( $price_to_discount_min * $ProdQty ), wc_get_price_decimals() ) : 0;
                //     $result['currency']         = get_woocommerce_currency_symbol();

                //     foreach ( $DisData as $discount ) { 

                //         if ( ( $discount->end_range != '' && $ProdQty >= $discount->start_range && $ProdQty <= $discount->end_range ) || ( $discount->end_range == '' && $ProdQty >= $discount->start_range ) || ( $discount->end_range != '' && $ProdQty > $discount->end_range && ( $discount->start_range != $discount->end_range ) ) ) {

                //             if ( $discount->dis_type == 'percentage' ) {
                //                 $discount_max_value     = $price_to_discount_max * ((float)$discount->dis_value / 100);
                //                 $discount_min_value     = $price_to_discount_min * ((float)$discount->dis_value / 100);
                //                 // $discount_max_value = min($price_to_discount, $discount_pt);
                //             } else if ( $discount->dis_type == 'fixed' ) {
                //                 $discount_max_value     = wc_add_number_precision($discount->dis_value);
                //                 $discount_min_value     = wc_add_number_precision($discount->dis_value);
                //             }

                //             $result['price']            = $discounted_new_min_price ? round ( $discounted_new_min_price, wc_get_price_decimals() ) : 0;
                //             $result['total']            = $discounted_new_min_price ? round ( ( $discounted_new_min_price * $ProdQty ), wc_get_price_decimals() ) : 0;
                //             $result['currency']         = get_woocommerce_currency_symbol();
                            
                //             $discounted_new_max_price   = (($price_to_discount_max - $discount_max_value) > 0) ? wc_price ( wc_remove_number_precision ( $price_to_discount_max - $discount_max_value ) ) : 0;
                //             $discounted_new_min_price   = (($price_to_discount_min - $discount_min_value) > 0) ? wc_price ( wc_remove_number_precision ( $price_to_discount_min - $discount_min_value ) ) : 0;
                            
                //             // $result['price']            = wc_format_sale_price ( wc_price ( wc_remove_number_precision ( $price_to_discount_min ) ) . ' - ' . wc_price ( wc_remove_number_precision ( $price_to_discount_max ) ), $discounted_new_min_price . ' - ' . $discounted_new_max_price );

                //             continue;

                //         }

                //     }

                // } else {

                    // Default value when quantity not in range
                    $result['price']    = $ProdPrice ? (int)$ProdPrice : 0;
                    $result['total']    = $ProdPrice ? round ( ( $ProdPrice * $ProdQty ), wc_get_price_decimals() ) : 0;
                    $result['currency'] = get_woocommerce_currency_symbol();

                    foreach ( $DisData as $discount ) { 

                        if ( ( $discount->end_range != '' && $ProdQty >= $discount->start_range && $ProdQty <= $discount->end_range ) || ( $discount->end_range == '' && $ProdQty >= $discount->start_range ) || ( $discount->end_range != '' && $ProdQty > $discount->end_range && ( $discount->start_range != $discount->end_range ) ) ) {

                            // $discountprice      = ( $discount->dis_type === 'fixed' ) ? ( $ProdPrice - $discount->dis_value ) : ( $ProdPrice - ( $ProdPrice * ( (float)$discount->dis_value / 100 ) ) );

                            // $result['price']    = $discountprice ? round ( $discountprice, wc_get_price_decimals() ) : 0;
                            // $result['total']    = $discountprice ? round ( ( $discountprice * $ProdQty ), wc_get_price_decimals() ) : 0;
                            // $result['currency'] = get_woocommerce_currency_symbol();

                            $rounded_discount   = ( $discount->dis_type === 'fixed' ) ? ( $ProdPrice - $discount->dis_value ) : ( $ProdPrice - ( $ProdPrice * ( (float)$discount->dis_value / 100 ) ) );
                            $discountprice      = $rounded_discount ? round ( $rounded_discount, wc_get_price_decimals() ) : 0;

                            $result['price']    = $discountprice;
                            $result['total']    = $discountprice ? round ( ( $discountprice * $ProdQty ), wc_get_price_decimals() ) : 0;
                            $result['currency'] = get_woocommerce_currency_symbol();
                            continue;
                            
                        }

                    }

                // }

            }

            // $price = wc_format_sale_price($ProdPrice * $converted_rate, $discountprice * $converted_rate);
            echo !empty ( $result ) ? json_encode($result) : '';

        }
        die();

    }


    // Get variations 
    public function wdpGetVariations ( $productID, $list = false ) {

        if ( $productID ) {
            if ( ( !is_array ( $productID ) && array_key_exists ( $productID, $this->productvariations ) ) || ( $list && array_key_exists ( $list, $this->productvariations ) ) ) {
                return $this->productvariations[$productID];
            } else {
                global $wpdb;
                $productID      = is_array ( $productID ) ? implode(',', $productID) : $productID; 
                $PLVariations   = $wpdb->get_col("SELECT ID FROM {$wpdb->prefix}posts WHERE post_status = 'publish' AND post_parent IN ($productID) AND post_type = 'product_variation'");

                if ( $PLVariations ) {
                    if ( !is_array ( $productID ) ) $this->productvariations[$productID] = $PLVariations;
                    else if ( $list ) $this->productvariations[$list] = $PLVariations;

                    return $PLVariations;
                } 
            }
        }

        return false;

    }

    // Save Order Meta
    public function wdpOrderMeta ( $item_id, $values, $cart_item_key ) {

        $wdpDiscount    = $this->wdp_order_meta ? $this->wdp_order_meta : [];
        // $coupon         = get_option('awdp_fee_label') ? get_option('awdp_fee_label') : 'Discount';
        // $coupon_code    = apply_filters('woocommerce_coupon_code', $coupon);

        if ( array_key_exists ( $cart_item_key, $wdpDiscount ) ) {
            $_awdp_discounted_price = ( $wdpDiscount[$cart_item_key] ) ? $wdpDiscount[$cart_item_key] : [];
            wc_add_order_item_meta($item_id, '_awdp_discount_details', $_awdp_discounted_price);
            // wc_add_order_item_meta($item_id, '_awdp_coupon', $coupon_code);
            return;
        }

    }

    // Display Order Meta
    public function wdpDisplayOrderMeta( $item_id, $item, $product ) { 

        $wdp_discount_details   = wc_get_order_item_meta ( $item_id, '_awdp_discount_details', true ) ? wc_get_order_item_meta ( $item_id, '_awdp_discount_details', true ) : false;
        // $wdp_discount_label     = wc_get_order_item_meta($item_id, '_awdp_coupon', true);
        if ( $wdp_discount_details ) { 
            $prdID      = $product->get_ID(); 
            $wdp_meta   = '<br/><div class="wc-order-item-wdp" style="color: #888;">';
            foreach ( $wdp_discount_details as $wdp_discount_detail ) { 
                if ( $wdp_discount_detail['type'] === 'bogo' ) {
                    if ( $wdp_discount_detail['bogo'] ) {
                        foreach ( $wdp_discount_detail['bogo'] as $orderBogo ) {
                            if ( $orderBogo['product_id'] == $prdID ){
                                $wdp_meta .= ( $orderBogo['discount_type'] == 'free' ) ? '<strong>' .$orderBogo['offer_items'] .' * Free</strong>' : '<strong>' .$orderBogo['offer_items'] .' * ' .$orderBogo['discounted_price_single'];
                            }
                        }
                    }
                }
                if ( $wdp_discount_detail['type'] === 'gift' ) {
                    if ( $wdp_discount_detail['gift'] ) {
                        foreach ( $wdp_discount_detail['gift'] as $orderGift ) {
                            if ( $orderGift['product_id'] == $prdID ){
                                $wdp_meta .= '<strong>1 * Free</strong>';
                            }
                        }
                    }
                }
                if ( $wdp_discount_detail['discountedPrice'] && !( $wdp_discount_detail['type'] === 'gift' || $wdp_discount_detail['type'] === 'bogo' ) ) { 
                    $wdp_discounted_price = $wdp_discount_detail['discountedPrice'];
                    $wdp_meta .= $wdp_discounted_price == 0 ? '<strong>Free Product</strong><br/>' : '<strong>Discounted Price: </strong>'. wc_price ( round ( wc_remove_number_precision ( $wdp_discounted_price ), wc_get_price_decimals() ) ) .'<br/>';
                }
                // $wdp_meta .= $wdp_discount_label ? '<strong>Discount Label: </strong>'.$wdp_discount_label : '';
            }
            $wdp_meta .= '</div>';
            echo $wdp_meta;
        }

    }

    public function wdpAdminOrderHeader($order) {

        $items              = $order->get_items();
        $discount_status    = false;
        foreach ( $items as $key => $val ) { if ( wc_get_order_item_meta ( $key, '_awdp_discount_details', true ) ) { $discount_status = true; continue; } }
        if ( $discount_status ) {
            echo '<th class="line_wdpdata sortable" data-sort="your-sort-option" style="display:none">Dynamic Pricing Total</th>';
        }

    }
    
    public function wdpAdminOrderContent($_product, $item, $itemid = null) {

        // Exit if NULL
        if ( !$_product || $_product == NULL ) return;

        $item_id                = $item ? $item->get_id() : ''; 
        $data                   = $item ? $item->get_data() : []; 
        $subtotal               = ( !empty($data) && array_key_exists ( 'subtotal', $data ) ) ? $data['subtotal'] : '';
        $discount               = 0;
        $quantity               = ( !empty($data) && array_key_exists ( 'quantity', $data ) ) ? $data['quantity'] : '';
        $wdp_discount_details   = wc_get_order_item_meta ( $item_id, '_awdp_discount_details', true ) ? wc_get_order_item_meta ( $item_id, '_awdp_discount_details', true ) : false;
        // $wdp_discount_label     = wc_get_order_item_meta($item_id, '_awdp_coupon', true);
        if ( $wdp_discount_details ) {

            $prdID      = $_product->get_ID(); 
            foreach ( $wdp_discount_details as $wdp_discount_detail ) { 
                if ( $wdp_discount_detail['type'] === 'bogo' ) {
                    if ( $wdp_discount_detail['bogo'] ) {
                        foreach ( $wdp_discount_detail['bogo'] as $orderBogo ) {
                            if ( $orderBogo['product_id'] == $prdID ){
                                $discount .= $orderBogo['discount'];
                                $quantity = $quantity - $orderBogo['offer_items'];
                            }
                        }
                    }
                }
                if ( $wdp_discount_detail['type'] === 'gift' ) {
                    if ( $wdp_discount_detail['gift'] ) {
                        foreach ( $wdp_discount_detail['gift'] as $orderGift ) {
                            if ( $orderGift['product_id'] == $prdID ){
                                $discount += $orderGift['discount'];
                                $quantity = $quantity - 1; 
                            }
                        } 
                    }
                }  
                if ( $wdp_discount_detail['discountedPrice'] && !( $wdp_discount_detail['type'] === 'gift' || $wdp_discount_detail['type'] === 'bogo' ) ) { 
                    $wdp_discounts = $wdp_discount_detail['discount']['discounts'];
                    foreach ( $wdp_discounts as $key => $value ) {
                        if ( $value['productid'] == $prdID ) { 
                            $discount += round ( wc_remove_number_precision ( $value['discount'] ), wc_get_price_decimals() ) * $quantity;
                        }
                    }
                    $wdp_discounted_price = $wdp_discount_detail['discountedPrice']; 
                }
            }
            $discountedPrice = $subtotal - $discount;

            $wdp_meta   = '<td class="line_wdpdata" style="display:none"><div class="view">';
            $wdp_meta   .= '<span class="woocommerce-Price-amount amount">'.wc_price($discountedPrice).'</span>';
            $wdp_meta   .= $discount ? '<span class="wc-order-item-discount">'.wc_price($discount).' discount</span>' : '';
            $wdp_meta   .= '</div>';
            // $wdp_meta   .= '<div class="edit" style="display: none;"><input type="text" name="line_total['.absint( $item_id ).']" placeholder="'.esc_attr( wc_format_localized_price( 0 ) ).'" value="'.esc_attr( wc_format_localized_price( $discountedPrice ) ).'" class="line_total wc_input_price" /></div>';
            // $wdp_meta   .= '<div class="refund" style="display: none;"><input type="text" name="refund_line_total['.absint( $item_id ).']" placeholder="'.esc_attr( wc_format_localized_price( 0 ) ).'" class="refund_line_total wc_input_price" /></div>';
            $wdp_meta   .= '</td>';

            echo $wdp_meta;

        } else {

            $wdp_meta   = '<td class="line_wdpdata" style="display:none"><div class="view">';
            $wdp_meta   .= '<span class="woocommerce-Price-amount amount">'.wc_price($subtotal).'</span>';
            $wdp_meta   .= '</div>';
            // $wdp_meta   .= '<div class="edit" style="display: none;"><input type="text" name="line_total['.absint( $item_id ).']" placeholder="'.esc_attr( wc_format_localized_price( 0 ) ).'" value="'.esc_attr( wc_format_localized_price( $discountedPrice ) ).'" class="line_total wc_input_price" /></div>';
            // $wdp_meta   .= '<div class="refund" style="display: none;"><input type="text" name="refund_line_total['.absint( $item_id ).']" placeholder="'.esc_attr( wc_format_localized_price( 0 ) ).'" class="refund_line_total wc_input_price" /></div>';
            $wdp_meta   .= '</td>';

            echo $wdp_meta;

        }

    }
    
    public function wdpCustomJS() {

        $currentScreen = get_current_screen();
        $screenID = $currentScreen->id; //
        if ( $screenID === 'shop_order' ) { ?>
            <script>
                jQuery(document).ready(function() { 
                    jQuery('.line_wdpdata').each (function(index){
                        $wdpdata = jQuery(this).find('.view').html(); 
                        jQuery(this).parent().find('.line_cost .view').html($wdpdata);
                    });
                });
            </script>
        <?php }
        
    }

    // MiniCart
    public function wdpMiniCart() {

        global $woocommerce;  //apply_filters('woocommerce_applied_coupon');
        $coupon         = get_option('awdp_fee_label') ? get_option('awdp_fee_label') : 'Discount';
        $coupon_code    = apply_filters('woocommerce_coupon_code', $coupon); 
        $result         = '';
        $coupons_amount = 0;

        if ( $this->discounts ) {
                
            foreach ( $this->discounts as $ruleid => $discounts ) { 

                $discount_type = $discounts['discount_type'];
                $qn_type       = ( $discount_type == 'cart_quantity' ) ? get_post_meta ( $ruleid, 'discount_quantity_type', true ) : '';

                if ( array_key_exists ( 'discounts', $discounts ) ) { 

                    foreach ( $discounts['discounts'] as $key => $discount ) { 

                        $disc_product_ID = $discount['productid'];

                        if ( $discount['discount'] != '' ) {
                            // Decimal Round
                            $decimal_val    = $discount['discount'] - floor($discount['discount']);
                            $calc_discount  = ( $decimal_val == 0 ) ? $discount['discount'] : ( ( $decimal_val > 0.5 ) ? ceil ( $discount['discount'] ) : floor ( $discount['discount'] ) );

                            if ( $discount_type == 'fixed_product_price' || $discount_type == 'percent_product_price' || ( $discount_type == 'cart_quantity' && $qn_type == 'type_product' ) ) {
                                $calc_discount = $calc_discount * $discount['quantity'];
                            }

                            $coupons_amount                  = $coupons_amount + ( wc_remove_number_precision ( $calc_discount ) );

                        }

                    }

                }

            }

            $shipping       = $woocommerce->cart->get_cart_shipping_total();
            $taxes          = $woocommerce->cart->get_tax_totals();
            // $cart_total     = $woocommerce->cart->cart_contents_total;
            $total          = $woocommerce->cart->get_totals();
            if ( $coupons_amount ) { 
                $result = '<p class="wdp_miniCart total">';
                $result .= '<span class="wdpLabel">'.$coupon.': </span><span class="woocommerce-Price-amount amount">'.wc_price($coupons_amount).'</span></br>';
                $result .= ( $shipping && ( strpos ( $shipping, __('Free', 'aco-woo-dynamic-pricing') ) === false) ) ? '<span class="wdpLabel">'.__("Shipping", "aco-woo-dynamic-pricing").': </span><span class="woocommerce-Price-amount amount">'.$shipping.'</span></br>' : '';
                if ($taxes) {
                    foreach ($taxes as $key => $val) { 
                        $result .= '<span class="wdpLabel">'.$val->label.': </span><span class="woocommerce-Price-amount amount">'.wc_price($val->amount).'</span></br>';
                    }
                }
                $result .= ( $total && array_key_exists ( 'total', $total ) ) ? '<span class="wdpLabel">'.__("Total", "aco-woo-dynamic-pricing").': </span><span class="woocommerce-Price-amount amount">'.wc_price($total['total']).'</span>' : '';
                $result .= '</p>';
            }
            echo $result;

        }

        // if ( in_array($coupon_code, $woocommerce->cart->get_applied_coupons()) ) {
        //     $coupons_obj    = new WC_Coupon($coupon_code); 
        //     $coupons_amount = $coupons_obj->get_amount(); 
        //     $shipping       = $woocommerce->cart->get_cart_shipping_total();
        //     $taxes          = $woocommerce->cart->get_tax_totals();
        //     // $cart_total     = $woocommerce->cart->cart_contents_total;
        //     $total          = $woocommerce->cart->get_totals();
        //     if ( $coupons_amount ) { 
        //         $result = '<p class="wdp_miniCart total">';
        //         $result .= '<span class="wdpLabel">'.$coupon.': </span><span class="woocommerce-Price-amount amount">'.wc_price($coupons_amount).'</span></br>';
        //         $result .= ( $shipping && ( strpos ( $shipping, __('Free', 'aco-woo-dynamic-pricing') ) === false) ) ? '<span class="wdpLabel">'.__("Shipping", "aco-woo-dynamic-pricing").': </span><span class="woocommerce-Price-amount amount">'.$shipping.'</span></br>' : '';
        //         if ($taxes) {
        //             foreach ($taxes as $key => $val) { 
        //                 $result .= '<span class="wdpLabel">'.$val->label.': </span><span class="woocommerce-Price-amount amount">'.wc_price($val->amount).'</span></br>';
        //             }
        //         }
        //         $result .= ( $total && array_key_exists ( 'total', $total ) ) ? '<span class="wdpLabel">'.__("Total", "aco-woo-dynamic-pricing").': </span><span class="woocommerce-Price-amount amount">'.wc_price($total['total']).'</span>' : '';
        //         $result .= '</p>';
        //     }
        //     echo $result;
        // }

    }

    // Array Search 
    public function array_needle_search ( $needle, $haystack ) {

        $result = [];
        foreach ( $haystack as $key => $value ) {
            $current_key = $key;
            if ( is_array ( $value ) && in_array ( $needle, $value ) !== false ) {
                $result[] = $current_key;
            }
        }

        return $result;

    }

    // Products On Sale
    public function check_product_on_sale( $productID )
    {

        if ( false == $this->products_on_sale ) {
            
            global $wpdb;
            
            $awdp_onsale_prods = $wpdb->get_results( "
                SELECT posts.ID as id, posts.post_parent as parent_id
                FROM {$wpdb->posts} AS posts
                INNER JOIN {$wpdb->wc_product_meta_lookup} AS lookup ON posts.ID = lookup.product_id
                INNER JOIN {$wpdb->postmeta} as meta ON posts.ID = meta.post_id
                WHERE posts.post_type IN ( 'product', 'product_variation' )
                AND posts.post_status = 'publish'
                AND lookup.onsale = 1 
                AND meta.meta_key LIKE '_stock_status'
                AND meta.meta_value IN ( 'instock', 'onbackorder' )
                AND posts.post_parent NOT IN (
                    SELECT ID FROM `$wpdb->posts` as posts
                    WHERE posts.post_type = 'product'
                    AND posts.post_parent = 0
                    AND posts.post_status != 'publish'
                )
                GROUP BY posts.ID
                " 
            );

            $prods_onSale = wp_parse_id_list( array_merge( wp_list_pluck( $awdp_onsale_prods, 'id' ), array_diff( wp_list_pluck( $awdp_onsale_prods, 'parent_id' ), array( 0 ) ) ) );
            
            $this->products_on_sale = $prods_onSale;

        }

        $onSaleIDs = $this->products_on_sale; 

        return in_array ( $productID, $onSaleIDs ) ? true : false;

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

    public function check_in_rule ( $selectedRule, $productID ) {

        $this->load_rules();

        if ( $this->discount_rules == null ) return false; // Skip if no active rules

        $product        = wc_get_product ( $productID ); 
        $discount_index = array_search ( $selectedRule, array_column ( $this->discount_rules, 'id' ) );

        if ( $discount_index !== false ) {

            $rules  = $this->discount_rules;
            $rule   = $rules[$discount_index];

            // Get Product List
            if ( !$this->get_items_to_apply_discount ( $product, $rule ) ) { 
                return false;
            } 
            
            // Check if User if Logged-In
            if ( ( intval ( $rule['discount_reg_customers'] ) === 1 && !is_user_logged_in() ) || ( intval ( $rule['discount_reg_customers'] ) === 1 && is_user_logged_in() && ( !empty ( array_filter ( $rule['discount_reg_user_roles'] ) ) && empty ( array_intersect ( $rule['discount_cur_user_roles'], $rule['discount_reg_user_roles'] ) ) ) ) ) { 
                return false;
            }

            return true;

        }

        return false;

    }

    protected function apply_discount_remainder($rule, $items_to_apply, $amount)
    {
        $total_discount = 0;

        foreach ($items_to_apply as $item) {
            for ($i = 0; $i < $item->quantity; $i++) {
                // Find out how much price is available to discount for the item.
                $discounted_price = $this->get_discounted_price_in_cents($item);

                // $price_to_discount = (false) ? $discounted_price : $item->price;// check if apply_ sequential

                $discount = min($discounted_price, 1);

                // Store totals.
                $total_discount += $discount;

                // Store code and discount amount per item.
                $this->discounts[$rule['id']]['discounts'][$item->key] += $discount;

                if ($total_discount >= $amount) {
                    break 2;
                }
            }
            if ($total_discount >= $amount) {
                break;
            }
        }

        return $total_discount;
    }


    public function get_discounted_price_in_cents($item, $include_tax = true, $sequential = false)
    {

        $product_actual_price = $item->get_data()['price'];
        $excluding_tax = get_option('woocommerce_tax_display_shop');
        if ($include_tax && $excluding_tax == 'incl') {
            $price = $this->wdp_price_including_tax ( $item, $product_actual_price, array (
                'price' => $product_actual_price,
            ) );
        } else {
            $price = $this->wdp_price_excluding_tax ( $item, $product_actual_price, array (
                'price' => $product_actual_price,
            ) );
        }

        // if($sequential)
        //     return abs($price - wc_remove_number_precision($this->get_discount($item->get_id(), true)));
        // else
        return $price;
    }


    // Woocommerce functions
    function wdp_price_including_tax ( $product, $prodPrice, $args = array() ) {

        $args = wp_parse_args(
            $args,
            array(
                'qty'   => '',
                'price' => '',
            )
        ); 
    
        $price = '' !== $args['price'] ? max( 0.0, (float) $args['price'] ) : $prodPrice;
        $qty   = '' !== $args['qty'] ? max( 0.0, (float) $args['qty'] ) : 1; 
    
        if ( '' === $price ) {
            return '';
        } elseif ( empty( $qty ) ) {
            return 0.0;
        }
    
        $line_price   = $price * $qty;
        $return_price = $line_price;
    
        if ( $product->is_taxable() ) {

            if ( ! wc_prices_include_tax() ) {

                $tax_rates = WC_Tax::get_rates( $product->get_tax_class() );
                $taxes     = WC_Tax::calc_tax( $line_price, $tax_rates, false );
    
                if ( 'yes' === get_option( 'woocommerce_tax_round_at_subtotal' ) ) {
                    $taxes_total = array_sum( $taxes );
                } else {
                    $taxes_total = array_sum( array_map( 'wc_round_tax_total', $taxes ) );
                }
    
                $return_price = round( $line_price + $taxes_total, wc_get_price_decimals() );

            } else {

                $tax_rates      = WC_Tax::get_rates( $product->get_tax_class() );
                $base_tax_rates = WC_Tax::get_base_tax_rates( $product->get_tax_class( 'unfiltered' ) );
    
                /**
                 * If the customer is excempt from VAT, remove the taxes here.
                 * Either remove the base or the user taxes depending on woocommerce_adjust_non_base_location_prices setting.
                 */
                if ( ! empty( WC()->customer ) && WC()->customer->get_is_vat_exempt() ) { // @codingStandardsIgnoreLine.
                    $remove_taxes = apply_filters( 'woocommerce_adjust_non_base_location_prices', true ) ? WC_Tax::calc_tax( $line_price, $base_tax_rates, true ) : WC_Tax::calc_tax( $line_price, $tax_rates, true );
    
                    if ( 'yes' === get_option( 'woocommerce_tax_round_at_subtotal' ) ) {
                        $remove_taxes_total = array_sum( $remove_taxes );
                    } else {
                        $remove_taxes_total = array_sum( array_map( 'wc_round_tax_total', $remove_taxes ) );
                    }
    
                    $return_price = round( $line_price - $remove_taxes_total, wc_get_price_decimals() );
    
                    /**
                 * The woocommerce_adjust_non_base_location_prices filter can stop base taxes being taken off when dealing with out of base locations.
                 * e.g. If a product costs 10 including tax, all users will pay 10 regardless of location and taxes.
                 * This feature is experimental @since 2.4.7 and may change in the future. Use at your risk.
                 */

                } elseif ( $tax_rates !== $base_tax_rates && apply_filters( 'woocommerce_adjust_non_base_location_prices', true ) ) {

                    $base_taxes   = WC_Tax::calc_tax( $line_price, $base_tax_rates, true );
                    $modded_taxes = WC_Tax::calc_tax( $line_price - array_sum( $base_taxes ), $tax_rates, false );
    
                    if ( 'yes' === get_option( 'woocommerce_tax_round_at_subtotal' ) ) {
                        $base_taxes_total   = array_sum( $base_taxes );
                        $modded_taxes_total = array_sum( $modded_taxes );
                    } else {
                        $base_taxes_total   = array_sum( array_map( 'wc_round_tax_total', $base_taxes ) );
                        $modded_taxes_total = array_sum( array_map( 'wc_round_tax_total', $modded_taxes ) );
                    }
    
                    $return_price = round( $line_price - $base_taxes_total + $modded_taxes_total, wc_get_price_decimals() );

                }
            }
        }

        return apply_filters( 'woocommerce_get_price_including_tax', $return_price, $qty, $product );
    }
    

    function wdp_price_excluding_tax ( $product, $prodPrice, $args = array() ) {
        
        $args = wp_parse_args(
            $args,
            array(
                'qty'   => '',
                'price' => '',
                'skipcheck' => ''
            )
        );
    
        $price = '' !== $args['price'] ? max( 0.0, (float) $args['price'] ) : $prodPrice;
        $qty   = '' !== $args['qty'] ? max( 0.0, (float) $args['qty'] ) : 1;
        $skipcheck  = '' !== $args['skipcheck'] ? true : false;
    
        if ( '' === $price ) {
            return '';
        } elseif ( empty( $qty ) ) {
            return 0.0;
        }
    
        $line_price = $price * $qty;
    
        if ( ( $product->is_taxable() && wc_prices_include_tax() ) || $skipcheck ) {

            $tax_rates      = WC_Tax::get_rates( $product->get_tax_class() );
            $base_tax_rates = WC_Tax::get_base_tax_rates( $product->get_tax_class( 'unfiltered' ) );
            $remove_taxes   = apply_filters( 'woocommerce_adjust_non_base_location_prices', true ) ? WC_Tax::calc_tax( $line_price, $base_tax_rates, true ) : WC_Tax::calc_tax( $line_price, $tax_rates, true );
            $return_price   = $line_price - array_sum( $remove_taxes ); // Unrounded since we're dealing with tax inclusive prices. Matches logic in cart-totals class. @see adjust_non_base_location_price.

        } else {

            $return_price = $line_price;

        }
    
        return apply_filters( 'woocommerce_get_price_excluding_tax', $return_price, $qty, $product );
    }

}
