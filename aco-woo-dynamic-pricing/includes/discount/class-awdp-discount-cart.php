<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-awdp-discount-module.php';

class AWDP_Discount_Cart extends AWDP_Discount_Module
{

    public function wdpCalculateDiscount ( $cartObject ) {
        
        if ( $cartObject ) { 

            $this->restore_cart_line_base_prices( $cartObject );
            $this->reset_cart_discount_state();

            $cartContents   = $cartObject->cart_contents; 
            $result         = [];
            $couponStatus   = false;

            // Disable Discount if any other gets added to the cart
            $disable_discount   = get_option('awdp_disable_discount') ? get_option('awdp_disable_discount') : '';
            $coupon             = get_option('awdp_fee_label') ? get_option('awdp_fee_label') : 'Discount';
            $coupon_code        = apply_filters('woocommerce_coupon_code', $coupon);
            if ( $disable_discount && !empty ( WC()->cart->get_applied_coupons() ) && !in_array ( $coupon_code, WC()->cart->get_applied_coupons() ) ) {
                return $cartObject;
            }

            // Load discount rules
            $this->owner->rules->load_rules();

            // Check if discount is active
            if ( $this->owner->discount_rules == null )
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
                $this->owner->converted_rate   = $this->owner->utilities->get_con_unit($product, $cartContent['data']->get_price());

                foreach ( $this->owner->discount_rules as $k => $rule ) { 

                    // Disable Discount for Deposit Items
                    $depositCheck       = get_post_meta($rule['id'], 'deposit_check', true) ? get_post_meta($rule['id'], 'deposit_check', true) : 0; 
                    if ( array_key_exists ( 'awcdp_deposit', $cartContent ) && $cartContent['awcdp_deposit'] && $depositCheck ) {
                        continue;
                    }

                    // Get Product List
                    if ( !$this->owner->rules->get_items_to_apply_discount( $product, $rule, $disc_prod_ID, false, $product_slug ) ) { 
                        continue;
                    } 
                    
                    // Check if User if Logged-In
                    if ( ( intval ( $rule['discount_reg_customers'] ) === 1 && !is_user_logged_in() ) || ( intval ( $rule['discount_reg_customers'] ) === 1 && is_user_logged_in() && ( !empty ( array_filter ( $rule['discount_reg_user_roles'] ) ) && empty ( array_intersect ( $rule['discount_cur_user_roles'], $rule['discount_reg_user_roles'] ) ) ) ) ) { 
                        continue;
                    }
                    
                    // Validate Rules
                    if( 'cart_quantity' != $rule['type'] ) { // Skipping cart_quantity rule // 
                        if ( !$this->owner->rules->validate_discount_rules( $product, $rule, ['product_price','cart_total_amount', 'cart_total_amount_all_prods', 'cart_items', 'cart_items_all_prods', 'cart_products', 'cart_products_list'], $cartContent ) ) {
                            continue;
                        }
                    }
    
                    // Discounts Default Values
                    if ( !isset ( $this->owner->discounts[$rule['id']] ) ) { 
                        $this->owner->discounts[$rule['id']] = [ 'label' => $rule['label'], 'discount_type' => $rule['type'], 'discount_remainder' => -1, 'taxable' => false ];
                    }

                    // Saving Actual Price (Cart View - Coupon Disabled)
                    // if ( $couponStatus && array_key_exists ( $product_slug, $this->owner->actual_price ) == false ) {
                    //     $this->owner->actual_price[$product_slug] = $cartItemPrice;
                    // } 

                    // Get Price
                    // $price              = !empty ($this->owner->wdp_discounted_price) ? ( ( array_key_exists ( $cartKey, $this->owner->wdp_discounted_price ) && $this->owner->wdp_discounted_price[$cartKey] != '' ) ? wc_remove_number_precision ( $this->owner->wdp_discounted_price[$cartKey] ) : $cartContent['data']->get_price() ) : $cartContent['data']->get_price();
                    $price              = !empty ($this->owner->wdp_discounted_price) ? ( ( array_key_exists ( $cartKey, $this->owner->wdp_discounted_price ) && $this->owner->wdp_discounted_price[$cartKey] != '' ) ? wc_remove_number_precision ( $this->owner->wdp_discounted_price[$cartKey] ) : $cartItemPrice ) : $cartItemPrice;

                    $discVariable       = $this->owner->discounts[$rule['id']]; 
                    $prodLists          = $this->owner->product_lists;

                    // Disable Double Discount
                    if ( array_key_exists ( 'discounts', $this->owner->discounts[$rule['id']] ) && array_key_exists ( $cartKey, $this->owner->discounts[$rule['id']]['discounts'] ) ) {
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

                        $this->owner->discounts[$rule['id']]                   = $result['productDiscount'];
                        $this->owner->discounted_products[]                    = $cartKey;
                        $this->owner->wdp_discounted_price[$cartKey]           = array_key_exists ( 'discountedprice', $result ) ? $result['discountedprice'] : ( array_key_exists ( $cartKey, $this->owner->wdp_discounted_price ) ? $this->owner->wdp_discounted_price[$cartKey] : '' );

                        // Set Cart Item Price
                        // if ( $couponStatus ) {
                        //     $cartContent['data']->set_price(wc_remove_number_precision($this->owner->wdp_discounted_price[$product_slug]));
                        // }

                        // Order Meta
                        $coupon                                         = get_option('awdp_fee_label') ? get_option('awdp_fee_label') : 'Discount';
                        $coupon_code                                    = apply_filters('woocommerce_coupon_code', $coupon);
                        $orderMetaData                                  = [];
                        $orderMetaData['type']                          = $rule['type'];
                        $orderMetaData['discount']                      = $result['productDiscount'];
                        $orderMetaData['coupon']                        = $coupon_code;
                        $orderMetaData['discountedPrice']               = array_key_exists ( 'discountedprice', $result ) ? $result['discountedprice'] : ( array_key_exists ( $cartKey, $this->owner->wdp_discounted_price ) ? $this->owner->wdp_discounted_price[$cartKey] : '' );
                        $orderMetaData['application']                   = AWDP_Discount_Application::applies_to_line_price( $rule['type'], $rule['id'] ) ? 'line_price' : 'coupon';
                        $orderMetaData['originalPrice']                 = wc_add_number_precision( $cartItemPrice );

                        $this->owner->wdp_order_meta[$cartKey][]               = $orderMetaData;

                    }

                } 

            }

            $this->apply_product_line_prices_to_cart( $cartObject );
            $this->owner->apply_wdp_coupon = $this->cart_has_coupon_discounts();

        }

    }

    // Show Pricing Table

    public function cart_discount_items ( $item_price, $cart_item )
    {

        if ( $this->cart_item_uses_line_price( $cart_item ) ) {
            return $this->format_line_discounted_unit_price_html( $cart_item, $item_price );
        }

        // Disable Discount if any other gets added to the cart
        $disable_discount   = get_option('awdp_disable_discount') ? get_option('awdp_disable_discount') : '';
        $coupon             = get_option('awdp_fee_label') ? get_option('awdp_fee_label') : 'Discount';
        $coupon_code        = apply_filters('woocommerce_coupon_code', $coupon);
        if ( $disable_discount && !empty ( WC()->cart->get_applied_coupons() ) && !in_array ( $coupon_code, WC()->cart->get_applied_coupons() ) ) {
            return $item_price;
        }

        // Load discount rules 
        $this->owner->rules->load_rules();
        
        // Check if discount is active
        if ( $this->owner->discount_rules == null )
            return $item_price; // Exit if no rules 

        // Load Product List
        $this->owner->rules->set_product_list(); 
        
        $post_id        = $cart_item['product_id'];
        $quantity       = $cart_item['quantity'];
        $product        = wc_get_product( $post_id );

        $rules          = $this->owner->discount_rules;
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
        $prodLists          = $this->owner->product_lists;
        $variations         = $this->owner->variations;
        $cartContents       = WC()->cart->get_cart();
        // $couponStatus   = get_option('awdp_apply_coupon_discount') ? get_option('awdp_apply_coupon_discount') : false;
        $couponStatus       = false;
        // $disc_prod_ID   = $post_id;
        $variationID        = $cart_item['variation_id'];
        // $variations     = $cart_item['variation'];
        $disc_prod_ID       = $variationID == 0 ? $post_id : $variationID;

        // Changing Product Slug to cart key - addons compatibility
        $cartKey            = $cart_item['key'];

        // if( $this->owner->converted_rate == '' && $item->get_ID() != '' ) {
        //     $this->owner->converted_rate = $this->owner->utilities->get_con_unit($item, $price, true);
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

        foreach ( $this->owner->discount_rules as $k => $rule ) {

            
            // Disable Discount for Deposit Items
            $depositCheck       = get_post_meta($rule['id'], 'deposit_check', true) ? get_post_meta($rule['id'], 'deposit_check', true) : 0; 
            if ( array_key_exists ( 'awcdp_deposit', $cart_item ) && $cart_item['awcdp_deposit'] && $depositCheck ) {
                continue;
            }

            // Get Product List
            if ( !$this->owner->rules->get_items_to_apply_discount( $product, $rule, $disc_prod_ID, false, $product_slug ) ) {
                continue;
            }

            // Check if User if Logged-In
            if ( ( intval ( $rule['discount_reg_customers'] ) === 1 && !is_user_logged_in() ) || ( intval ( $rule['discount_reg_customers'] ) === 1 && is_user_logged_in() && ( !empty ( array_filter ( $rule['discount_reg_user_roles'] ) ) && empty ( array_intersect ( $rule['discount_cur_user_roles'], $rule['discount_reg_user_roles'] ) ) ) ) ) { 
                continue;
            }

            // Validate Rules
            if( 'cart_quantity' != $rule['type'] ) { // Skipping cart_quantity rule // 
                if ( !$this->owner->rules->validate_discount_rules( $product, $rule, ['product_price','cart_total_amount', 'cart_total_amount_all_prods', 'cart_items', 'cart_items_all_prods', 'cart_products', 'cart_products_list'], $cart_item ) ) {
                    continue;
                }
            }

            // Discounts Default Values
            if ( !isset ( $this->owner->discounts[$rule['id']] ) ) { 
                $this->owner->discounts[$rule['id']] = [ 'label' => $rule['label'], 'discount_type' => $rule['type'], 'discount_remainder' => -1, 'taxable' => false ];
            }

            /* 
            * Disable Double Discount
            * @ver 4.1.6
            * $product_slug added to awdp_discount_applied list is discount already applied
            */
            if ( array_key_exists ( 'discounts', $this->owner->discounts[$rule['id']] ) && array_key_exists ( $cartKey, $this->owner->discounts[$rule['id']]['discounts'] ) ) { 
                $this->owner->awdp_discount_applied[] = $product_slug;
                continue;
            }

            // Get Price
            // if ( $couponStatus && array_key_exists ( $product_slug, $this->owner->actual_price ) ) {
            //     $price = $this->owner->actual_price[$product_slug];
            // } else {
                // $price = !empty ($this->owner->wdp_discounted_price) ? ( ( array_key_exists ( $cartKey, $this->owner->wdp_discounted_price ) && $this->owner->wdp_discounted_price[$cartKey] != '' ) ? wc_remove_number_precision ( $this->owner->wdp_discounted_price[$cartKey] ) : $cart_item['data']->get_price() ) : $cart_item['data']->get_price(); 
                $price = !empty ($this->owner->wdp_discounted_price) ? ( ( array_key_exists ( $cartKey, $this->owner->wdp_discounted_price ) && $this->owner->wdp_discounted_price[$cartKey] != '' ) ? wc_remove_number_precision ( $this->owner->wdp_discounted_price[$cartKey] ) : $cart_item['data']->get_price() ) : $cart_item['data']->get_price(); 
            // } 

            $discVariable       = $this->owner->discounts[$rule['id']]; 
            $prodLists          = $this->owner->product_lists;

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
                $this->owner->discounts[$rule['id']]               = $result['productDiscount'];
                $this->owner->discounted_products[]                = $cartKey;
                $this->owner->wdp_discounted_price[$cartKey]  = array_key_exists ( 'discountedprice', $result ) ? $result['discountedprice'] : ( array_key_exists ( $cartKey, $this->owner->wdp_discounted_price ) ? $this->owner->wdp_discounted_price[$cartKey] : '' );
            }

        }

        $activeDiscounts    = $this->owner->discounts;

        $viewPrice = call_user_func_array ( 
            array ( new AWDP_viewCartPrice(), 'cart_price' ), 
            array ( $rules, $price, $cart_item, $prodLists, $item_price, $activeDiscounts, $product, $quantity, $display_price ) 
        ); 

        return $viewPrice;

    }

    // Sub Total Calculations

    public function wdpCartLoop ( $wc, $cart_item, $cart_item_key )
    { 

        if ( $this->cart_item_uses_line_price( $cart_item ) ) {
            return $wc;
        }

        $activeDiscounts    = $this->owner->discounts;
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
        //         $price = $this->owner->utilities->wdp_price_including_tax( $product, $price, array(
        //             'qty' => $quantity,
        //             'price' => $price,
        //         ) );
        //     } else {
        //         $price = $this->owner->utilities->wdp_price_excluding_tax( $product, $price, array(
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

    public function check_discount($slug)
    {
        $_discounts = array();

        foreach ($this->owner->discounts as $discounts) {
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

        foreach ( $this->owner->discounts as $discounts ) {
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

    public function get_individual_discounted_price_in_cents($item, $include_tax = true, $sequential = false, $price = false)
    {

        $latest_price = '';
        $excluding_tax = get_option('woocommerce_tax_display_shop');
        $cur_price = $price ? $price : $item->get_data()['price'];
        if ($excluding_tax == 'incl') {
            $price = $this->owner->utilities->wdp_price_including_tax( $item, $cur_price, array(
                'price' => $cur_price,
            ) );
        } else {
            $price = $this->owner->utilities->wdp_price_excluding_tax( $item, $cur_price, array(
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
        $discounts = $this->owner->discounts;
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
                $this->owner->discounts[$rule['id']]['discounts'][$item->key] += $discount;

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
            $price = $this->owner->utilities->wdp_price_including_tax( $item, $product_actual_price, array (
                'price' => $product_actual_price,
            ) );
        } else {
            $price = $this->owner->utilities->wdp_price_excluding_tax( $item, $product_actual_price, array (
                'price' => $product_actual_price,
            ) );
        }

        // if($sequential)
        //     return abs($price - wc_remove_number_precision($this->get_discount($item->get_id(), true)));
        // else
        return $price;
    }


    /**
     * Restores catalog line prices before recalculating (prevents stacked discounts on repeated totals runs).
     *
     * Before restoring, validates that the cached base price still matches the live catalog price.
     * If the product price was changed in the admin since the session was last written, the stale
     * cache is discarded and the WC product object is reset to the current live catalog price.
     * This ensures discount rules are always evaluated against up-to-date product prices.
     *
     * @param WC_Cart $cartObject Cart instance.
     */
    protected function restore_cart_line_base_prices($cartObject)
    {
        if (empty($cartObject->cart_contents)) {
            return;
        }

        foreach ($cartObject->cart_contents as $cart_key => $cart_content) {
            if (!isset($cart_content['awdp_price_before_discount'])) {
                continue;
            }

            $cached_base = (float) $cart_content['awdp_price_before_discount'];

            // Resolve the live catalog price for the correct product/variation.
            $product_id  = !empty($cart_content['variation_id']) ? (int) $cart_content['variation_id'] : (int) $cart_content['product_id'];
            $live_product = wc_get_product($product_id);
            $live_price  = $live_product ? (float) $live_product->get_price('edit') : $cached_base;

            if (abs($live_price - $cached_base) < 0.0001) {
                // Catalog price unchanged — restore to the base price so discount calculations
                // do not compound across multiple woocommerce_before_calculate_totals calls.
                $cart_content['data']->set_price($cached_base);
            } else {
                // Catalog price changed since the session was written — discard the stale cache
                // and reset the product object to the current live price.
                unset($cartObject->cart_contents[$cart_key]['awdp_price_before_discount']);
                $cart_content['data']->set_price($live_price);
            }
        }
    }


    /**
     * Clears per-request discount state before recalculating cart totals.
     */
    protected function reset_cart_discount_state()
    {
        $this->owner->discounts                   = array();
        $this->owner->wdp_discounted_price        = array();
        $this->owner->wdp_order_meta              = array();
        $this->owner->discounted_products         = array();
        $this->owner->awdp_discount_applied       = array();
        $this->owner->product_line_prices_applied = false;
        $this->owner->apply_wdp_coupon            = false;
    }


    /**
     * Whether any active discount should be applied through the virtual coupon.
     */
    protected function cart_has_coupon_discounts()
    {
        if (empty($this->owner->discounts)) {
            return false;
        }

        foreach ($this->owner->discounts as $rule_id => $discounts) {
            $discount_type = $discounts['discount_type'];

            if (!array_key_exists('discounts', $discounts)) {
                continue;
            }

            foreach ($discounts['discounts'] as $entry) {
                if (empty($entry['discount']) && $entry['discount'] !== 0 && $entry['discount'] !== '0') {
                    continue;
                }

                if (AWDP_Discount_Application::applies_to_coupon($discount_type, $rule_id, $entry)) {
                    return true;
                }
            }
        }

        return false;
    }


    /**
     * Applies product-level discounts directly on cart line item prices.
     *
     * @param WC_Cart $cartObject Cart instance.
     */
    protected function apply_product_line_prices_to_cart($cartObject)
    {
        if (empty($cartObject->cart_contents)) {
            return;
        }

        foreach ($cartObject->cart_contents as $cart_key => $cart_content) {
            if (!$this->cart_item_has_line_price_discount($cart_key)) {
                continue;
            }

            $new_unit_price = $this->resolve_line_item_unit_price($cart_key, $cart_content);

            if ($new_unit_price === null) {
                continue;
            }

            // Always record the validated live base price for this request cycle.
            // restore_cart_line_base_prices() has already verified the live price above,
            // so the product object's current price is guaranteed to be correct here.
            $cartObject->cart_contents[$cart_key]['awdp_price_before_discount'] = (float) $cart_content['data']->get_price('edit');

            $cart_content['data']->set_price($new_unit_price);
            $this->owner->product_line_prices_applied = true;
        }
    }


    /**
     * @param string $cart_key Cart item key.
     */
    protected function cart_item_has_line_price_discount($cart_key)
    {
        if (!empty($this->owner->discounts)) {
            foreach ($this->owner->discounts as $rule_id => $discounts) {
                if (!array_key_exists('discounts', $discounts) || !array_key_exists($cart_key, $discounts['discounts'])) {
                    continue;
                }

                $entry = $discounts['discounts'][$cart_key];

                if (AWDP_Discount_Application::applies_to_line_price($discounts['discount_type'], $rule_id, $entry)) {
                    if ($entry['discount'] !== '' && $entry['discount'] !== null) {
                        return true;
                    }
                }
            }
        }

        return isset($this->owner->wdp_discounted_price[$cart_key]) && $this->owner->wdp_discounted_price[$cart_key] !== '';
    }


    /**
     * Resolves the unit price to set on the cart product object.
     *
     * @param string $cart_key     Cart item key.
     * @param array  $cart_content Cart line.
     */
    protected function resolve_line_item_unit_price($cart_key, $cart_content)
    {
        if (isset($this->owner->wdp_discounted_price[$cart_key]) && $this->owner->wdp_discounted_price[$cart_key] !== '') {
            return (float) wc_remove_number_precision($this->owner->wdp_discounted_price[$cart_key]);
        }

        $product    = $cart_content['data'];
        $unit_base  = isset($cart_content['awdp_price_before_discount'])
            ? (float) $cart_content['awdp_price_before_discount']
            : (float) $product->get_price('edit');

        $discount_total = $this->get_line_price_discount_amount($cart_key, $product);

        if ($discount_total <= 0) {
            return null;
        }

        return max(0, $unit_base - $discount_total);
    }


    /**
     * Sum of per-unit product discounts for display/set_price fallback.
     *
     * @param string     $cart_key Cart item key.
     * @param WC_Product $product  Cart product.
     */
    protected function get_line_price_discount_amount($cart_key, $product)
    {
        $discount_total = 0;

        if (empty($this->owner->discounts)) {
            return $discount_total;
        }

        foreach ($this->owner->discounts as $rule_id => $discounts) {
            if (!array_key_exists('discounts', $discounts) || !array_key_exists($cart_key, $discounts['discounts'])) {
                continue;
            }

            $entry = $discounts['discounts'][$cart_key];

            if (!AWDP_Discount_Application::applies_to_line_price($discounts['discount_type'], $rule_id, $entry)) {
                continue;
            }

            if ($entry['discount'] === '' || $entry['discount'] === null) {
                continue;
            }

            $discount_total += AWDP_Discount_Application::normalize_discount_amount($entry['discount']);
        }

        $tax_display_mode = get_option('woocommerce_tax_display_cart');

        if ($product->is_taxable() && in_array($tax_display_mode, array('incl', 'excl'), true)) {
            if ('incl' === $tax_display_mode) {
                $discount_total = wc_get_price_including_tax($product, array('price' => $discount_total));
            } else {
                $discount_total = wc_get_price_excluding_tax($product, array('price' => $discount_total));
            }
        }

        return (float) $discount_total;
    }


    /**
     * @param array $cart_item Cart line.
     */
    protected function cart_item_uses_line_price($cart_item)
    {
        return isset($cart_item['awdp_price_before_discount']);
    }


    /**
     * Formats unit price HTML when discount is already on the line item.
     *
     * @param array  $cart_item  Cart line.
     * @param string $item_price Default WooCommerce price HTML.
     */
    protected function format_line_discounted_unit_price_html($cart_item, $item_price)
    {
        $product  = $cart_item['data'];
        $current  = (float) $product->get_price();
        $original = (float) $cart_item['awdp_price_before_discount'];

        if ($original > $current) {
            return wc_format_sale_price($original, $current);
        }

        return $item_price;
    }

}
