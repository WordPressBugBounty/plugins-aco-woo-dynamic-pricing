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

            // Respect coupon interaction mode (skip DP when coupons_only + WC coupon).
            if ( ! awdp_should_apply_dynamic_pricing() ) {
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

                // Checking for addons price (discount product portion only; honor excludeFromDiscount / disable_addon)
                $addonPrice     = awdp_get_wcpa_addon_data( $cartContent );
                $dispPrice      = awdp_get_wcpa_discountable_unit_price( $cartItemPrice, $addonPrice ); 

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
                    
                    if ( ! awdp_user_qualifies_for_discount_rule( $rule ) ) {
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

        // Respect coupon interaction mode (skip DP when coupons_only + WC coupon).
        if ( ! awdp_should_apply_dynamic_pricing() ) {
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
        // Checking for addons price (discount product portion only; honor excludeFromDiscount / disable_addon)
        $addonPrice         = awdp_get_wcpa_addon_data( $cart_item );
        $dispPrice          = awdp_get_wcpa_discountable_unit_price( $cartPrice, $addonPrice );

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

        // Display-only original/struck-through price (sale by default; regular when setting is on).
        $price_product      = ( isset( $cart_item['data'] ) && is_object( $cart_item['data'] ) ) ? $cart_item['data'] : $product;
        $display_price      = awdp_get_product_strikeout_display_price( $price_product );

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

            if ( ! awdp_user_qualifies_for_discount_rule( $rule ) ) {
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
            return $this->format_line_item_subtotal_html( $cart_item, $wc );
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
        // Checking for addons price (discount product portion only; honor excludeFromDiscount / disable_addon)
        $addonPrice         = awdp_get_wcpa_addon_data( $cart_item );
        $dispPrice          = awdp_get_wcpa_discountable_unit_price( $cartPrice, $addonPrice );
        
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

            // WCPA owns pre-discount line composition (baked-in total or split productPrice).
            // Always restore the cached base so catalog comparison does not wipe addon context.
            $addon_data = awdp_get_wcpa_addon_data( $cart_content );
            if ( is_array( $addon_data ) && $cached_base > 0 ) {
                $cart_content['data']->set_price( $cached_base );
                continue;
            }

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

            // Record validated base for this request cycle (split: prefer productPrice; never lock 0).
            $current_unit = (float) $cart_content['data']->get_price('edit');
            $addon_data   = awdp_get_wcpa_addon_data( $cart_content );
            $is_split     = awdp_is_wcpa_split_pricing( $current_unit, $addon_data );
            $base_unit    = $current_unit;

            if ( $is_split && is_array( $addon_data ) && array_key_exists( 'productPrice', $addon_data ) ) {
                $product_unit = (float) $addon_data['productPrice'];
                if ( $product_unit > 0 ) {
                    $base_unit = $product_unit;
                }
            }

            if ( $base_unit > 0 ) {
                $cartObject->cart_contents[ $cart_key ]['awdp_price_before_discount'] = $base_unit;
            } elseif ( ! isset( $cartObject->cart_contents[ $cart_key ]['awdp_price_before_discount'] ) ) {
                $cartObject->cart_contents[ $cart_key ]['awdp_price_before_discount'] = $current_unit;
            }

            if ( is_array( $addon_data ) ) {
                $cartObject->cart_contents[ $cart_key ]['awdp_wcpa_split']      = $is_split;
                $cartObject->cart_contents[ $cart_key ]['awdp_wcpa_addon_unit'] = awdp_get_wcpa_addon_unit_amount( $addon_data );
            }

            // Split mode: set discounted product unit only — do not bake addons into set_price.
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
     * Calculated discounted unit price already stored on the cart line.
     *
     * Prefers the product object's set_price() value. If that still equals the
     * pre-discount base (common in mini-cart / Store API fragments), uses
     * line_subtotal / quantity from the existing totals run. Does not recalculate discounts.
     *
     * @param array $cart_item Cart line.
     * @return float|null Unit price excluding add-on display, or null if unavailable.
     */
    public function get_cart_item_calculated_unit_price( $cart_item )
    {
        if ( empty( $cart_item['data'] ) || ! is_object( $cart_item['data'] ) ) {
            return null;
        }

        $current = (float) $cart_item['data']->get_price();
        $qty     = isset( $cart_item['quantity'] ) ? (float) $cart_item['quantity'] : 0;

        if ( ! isset( $cart_item['awdp_price_before_discount'] ) ) {
            return $current;
        }

        $original = (float) $cart_item['awdp_price_before_discount'];

        if ( $original > $current + 0.0001 ) {
            return $current;
        }

        if ( $qty > 0 && isset( $cart_item['line_subtotal'] ) ) {
            $line_unit = (float) $cart_item['line_subtotal'] / $qty;
            if ( $original > $line_unit + 0.0001 ) {
                return $line_unit;
            }
        }

        $cart_key = isset( $cart_item['key'] ) ? $cart_item['key'] : '';
        if ( $cart_key && isset( $this->owner->wdp_discounted_price[ $cart_key ] ) && $this->owner->wdp_discounted_price[ $cart_key ] !== '' ) {
            return (float) wc_remove_number_precision( $this->owner->wdp_discounted_price[ $cart_key ] );
        }

        return $current;
    }

    /**
     * Align Store API item prices with the cart line (display only).
     *
     * Block cart/checkout compare raw_prices.regular_price vs raw_prices.price
     * (precision 6), not shop get_price_html. After set_price(), Woo still
     * exposes catalog regular as regular_price, so sale items strike the
     * regular instead of the sale/pre-discount original.
     *
     * @param array $item Store API cart item.
     * @return array
     */
    public function align_store_api_cart_item_prices( $item )
    {
        if ( empty( $item['key'] ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
            return $item;
        }

        $cart_item = WC()->cart->get_cart_item( $item['key'] );
        if ( empty( $cart_item['awdp_price_before_discount'] ) ) {
            return $item;
        }

        $unit = $this->get_cart_item_calculated_unit_price( $cart_item );
        if ( $unit === null ) {
            return $item;
        }

        $original = (float) $cart_item['awdp_price_before_discount'];
        if ( $original <= $unit + 0.0001 ) {
            return $item;
        }

        $product = ( isset( $cart_item['data'] ) && is_object( $cart_item['data'] ) ) ? $cart_item['data'] : null;
        $addon_unit = function_exists( 'awdp_get_wcpa_display_addon_unit' )
            ? awdp_get_wcpa_display_addon_unit( $cart_item )
            : 0;
        $strike_unit = awdp_get_cart_item_strikeout_unit_price( $cart_item, $original );

        $display_unit   = awdp_get_cart_tax_display_amount( $product, $unit ) + (float) $addon_unit;
        $strike_display = awdp_get_cart_tax_display_amount( $product, $strike_unit ) + (float) $addon_unit;

        $decimals = isset( $item['prices']->currency_minor_unit )
            ? (int) $item['prices']->currency_minor_unit
            : ( isset( $item['prices']['currency_minor_unit'] ) ? (int) $item['prices']['currency_minor_unit'] : wc_get_price_decimals() );

        $raw_precision = function_exists( 'wc_get_rounding_precision' ) ? wc_get_rounding_precision() : 6;
        $raw_prices    = null;
        if ( isset( $item['prices']->raw_prices ) ) {
            $raw_prices = $item['prices']->raw_prices;
        } elseif ( isset( $item['prices']['raw_prices'] ) ) {
            $raw_prices = $item['prices']['raw_prices'];
        }
        if ( is_object( $raw_prices ) && isset( $raw_prices->precision ) ) {
            $raw_precision = (int) $raw_prices->precision;
        } elseif ( is_array( $raw_prices ) && isset( $raw_prices['precision'] ) ) {
            $raw_precision = (int) $raw_prices['precision'];
        }

        $unit_minor   = awdp_store_api_format_money( $display_unit, $decimals );
        $strike_minor = awdp_store_api_format_money( $strike_display, $decimals );
        $unit_raw     = awdp_store_api_format_money( $display_unit, $raw_precision );
        $strike_raw   = awdp_store_api_format_money( $strike_display, $raw_precision );

        $item['prices'] = $this->apply_store_api_display_prices(
            isset( $item['prices'] ) ? $item['prices'] : array(),
            $unit_minor,
            $strike_minor,
            $unit_raw,
            $strike_raw
        );

        return $item;
    }

    /**
     * Writes discounted current + strike original onto Store API price fields.
     *
     * @param object|array $prices       Store API prices object/array.
     * @param string       $unit_minor   Discounted amount in currency minor units.
     * @param string       $strike_minor Display original in currency minor units.
     * @param string       $unit_raw     Discounted amount in raw_prices precision.
     * @param string       $strike_raw   Display original in raw_prices precision.
     * @return object|array
     */
    protected function apply_store_api_display_prices( $prices, $unit_minor, $strike_minor, $unit_raw, $strike_raw )
    {
        if ( is_object( $prices ) ) {
            $prices->price         = $unit_minor;
            $prices->sale_price    = $unit_minor;
            $prices->regular_price = $strike_minor;
            if ( isset( $prices->raw_prices ) && is_array( $prices->raw_prices ) ) {
                $prices->raw_prices['price']         = $unit_raw;
                $prices->raw_prices['sale_price']    = $unit_raw;
                $prices->raw_prices['regular_price'] = $strike_raw;
            } elseif ( isset( $prices->raw_prices ) && is_object( $prices->raw_prices ) ) {
                $prices->raw_prices->price         = $unit_raw;
                $prices->raw_prices->sale_price    = $unit_raw;
                $prices->raw_prices->regular_price = $strike_raw;
            }
            return $prices;
        }

        if ( ! is_array( $prices ) ) {
            $prices = array();
        }

        $prices['price']         = $unit_minor;
        $prices['sale_price']    = $unit_minor;
        $prices['regular_price'] = $strike_minor;
        if ( isset( $prices['raw_prices'] ) && is_array( $prices['raw_prices'] ) ) {
            $prices['raw_prices']['price']         = $unit_raw;
            $prices['raw_prices']['sale_price']    = $unit_raw;
            $prices['raw_prices']['regular_price'] = $strike_raw;
        }

        return $prices;
    }

    /**
     * Formats unit price HTML when discount is already on the line item.
     *
     * Uses WooCommerce cart tax display (same as WC_Cart::get_product_price).
     *
     * @param array  $cart_item  Cart line.
     * @param string $item_price Default WooCommerce price HTML.
     */
    protected function format_line_discounted_unit_price_html($cart_item, $item_price)
    {
        $current = $this->get_cart_item_calculated_unit_price( $cart_item );
        if ( $current === null ) {
            return $item_price;
        }

        $original   = (float) $cart_item['awdp_price_before_discount'];
        $addon_unit = awdp_get_wcpa_display_addon_unit( $cart_item );
        $strike     = awdp_get_cart_item_strikeout_unit_price( $cart_item, $original );
        $product    = ( isset( $cart_item['data'] ) && is_object( $cart_item['data'] ) ) ? $cart_item['data'] : null;

        $original_display = awdp_get_cart_tax_display_amount( $product, $strike ) + $addon_unit;
        $current_display  = awdp_get_cart_tax_display_amount( $product, $current ) + $addon_unit;

        if ($original_display > $current_display) {
            return wc_format_sale_price($original_display, $current_display);
        }

        if ( $addon_unit > 0 && $current_display > 0 ) {
            return wc_price( $current_display );
        }

        return $item_price;
    }

    /**
     * Formats line subtotal HTML when discount is already on the line item.
     *
     * Split mode: (product ± discount + addon) × qty. Save amount is product discount only.
     *
     * @param array  $cart_item    Cart line.
     * @param string $default_html Default WooCommerce / prior-filter subtotal HTML.
     * @return string
     */
    protected function format_line_item_subtotal_html( $cart_item, $default_html )
    {
        if ( empty( $cart_item['data'] ) || ! is_object( $cart_item['data'] ) ) {
            return $default_html;
        }

        $product    = $cart_item['data'];
        $quantity   = isset( $cart_item['quantity'] ) ? (float) $cart_item['quantity'] : 1;
        $current    = (float) $product->get_price();
        $original   = isset( $cart_item['awdp_price_before_discount'] )
            ? (float) $cart_item['awdp_price_before_discount']
            : $current;
        $addon_unit = awdp_get_wcpa_display_addon_unit( $cart_item );
        $strike     = isset( $cart_item['awdp_price_before_discount'] )
            ? awdp_get_cart_item_strikeout_unit_price( $cart_item, $original )
            : $original;

        // No split add-back and no product discount — keep prior HTML (WCPA may have adjusted it).
        if ( $addon_unit <= 0 && $original <= $current ) {
            return $default_html;
        }

        $original_line   = awdp_get_cart_tax_display_amount( $product, $strike, $quantity ) + ( $addon_unit * $quantity );
        $discounted_line = awdp_get_cart_tax_display_amount( $product, $current, $quantity ) + ( $addon_unit * $quantity );

        if ( $original_line > $discounted_line ) {
            $product_subtotal = wc_format_sale_price( $original_line, $discounted_line );
        } else {
            $product_subtotal = wc_price( $discounted_line );
        }

        if ( $product->is_taxable() && get_option( 'woocommerce_tax_display_cart' ) === 'incl' ) {
            if ( ! wc_prices_include_tax() && WC()->cart && WC()->cart->get_subtotal_tax() > 0 ) {
                $product_subtotal .= ' <small class="tax_label">' . WC()->countries->inc_tax_or_vat() . '</small>';
            }
        }

        return $product_subtotal;
    }

}
