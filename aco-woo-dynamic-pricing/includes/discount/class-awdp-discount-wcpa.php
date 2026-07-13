<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-awdp-discount-module.php';

class AWDP_Discount_Wcpa extends AWDP_Discount_Module
{

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
        $this->owner->rules->load_rules();
        
        // Check if discount is active
        if ( $this->owner->discount_rules == null )
            return $price; // Exit if no rules 

        // Load Product List
        $this->owner->rules->set_product_list();

        $updatedPrice   = '';
        $product_slug   = $product->get_data()['slug'];

        $rules          = $this->owner->discount_rules;
        $prodLists      = $this->owner->product_lists;
        $variations     = $this->owner->variations;
        $cartRules      = $this->owner->awdp_cart_rules;
        $item_price     = $price;

        // if( $this->owner->converted_rate == '' && $item->get_ID() != '' ) {
        //     $this->owner->converted_rate = $this->owner->utilities->get_con_unit($item, $price, true);
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

    // Adding WCPA Filed Value For Quantity Discount

    public function wcpaQunantity_Discount() {

        // Verify nonce to prevent CSRF on unauthenticated AJAX endpoint.
        check_ajax_referer( 'awdpnonce', 'nonce' );

        $itemCount          = isset( $_GET['proCount'] ) ? absint( $_GET['proCount'] ) : 1;
        $this->owner->rules->load_rules();
        $rules              = $this->owner->discount_rules;
        $result = ['fixed' => 0, 'percentage' => 0];
        foreach ( $rules as $rule ) {
            if ( $rule['type'] == 'cart_quantity') {
                foreach ($rule['quantity_rules'] as $quantity_rule) {          
                   if($itemCount >= $quantity_rule['start_range'] && $itemCount <= $quantity_rule['end_range']) {            
                        if($quantity_rule['dis_type'] == 'percentage') {
                            $value     = $quantity_rule['dis_value'] ? $quantity_rule['dis_value'] / 100 : 0;
                            $result['fixed']      = 0;
                            $result['percentage'] = round($value, 2);
                        }  elseif ( $quantity_rule['dis_type'] == 'fixed' ) {
                            $value    = $quantity_rule['dis_value'] ? $quantity_rule['dis_value'] : 0;
                            $result['percentage'] = 0;
                            $result['fixed']      = round($value, 2);
                        }
                   }
                    
                }
            }
        }
        if ( is_array ( $result ) ) {
            return json_encode($result);
        }
        return  $result;
    }

    // WCPA Discount Price For Frontend

    public function wdpDynamicDiscount ()
    {
        
        // Verify nonce to prevent CSRF on unauthenticated AJAX endpoint.
        check_ajax_referer( 'awdpnonce', 'nonce' );

        $post_id            = isset( $_GET['prodID'] ) ? absint( $_GET['prodID'] ) : get_the_ID();
        $variation_id       = isset( $_GET['varID'] ) ? absint( $_GET['varID'] ) : 0;
        $itemCount          = isset( $_GET['proCount'] ) ? absint( $_GET['proCount'] ) : 1;
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
        $this->owner->rules->load_rules();

        // Check if discount is active
        if ( $this->owner->discount_rules == null )
            return $price; // Exit if no rules

            
        // Load Product List
        $this->owner->rules->set_product_list();

        $updatedPrice       = '';
        $product_slug       = $product->get_data()['slug'];
        $rules              = $this->owner->discount_rules;
        $prodLists          = $this->owner->product_lists;
        $variations         = $this->owner->variations;
        $cartRules          = $this->owner->awdp_cart_rules;
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
        $this->owner->rules->load_rules();
        
        // Check if discount is active
        if ( $this->owner->discount_rules == null ) return $response; 

        // Load Product List
        $this->owner->rules->set_product_list();

        $rules              = $this->owner->discount_rules;
        $prodLists          = $this->owner->product_lists;
        $variations         = $this->owner->variations;
        $cartRules          = $this->owner->awdp_cart_rules;
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
        $this->owner->rules->load_rules();
        
        // Check if discount is active
        if ( $this->owner->discount_rules == null )
            return $default; // Exit if no rules 

        // Load Product List
        $this->owner->rules->set_product_list();

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

        $rules              = $this->owner->discount_rules;
        $price              = $priceIncTax ? $priceIncTax : $cartPrice;
        $prodLists          = $this->owner->product_lists;
        $variations         = $this->owner->variations;
        $cartRules          = $this->owner->awdp_cart_rules;
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

        $wcpaPrice = call_user_func_array ( 
            array ( new AWDP_productGroup(), 'product_price' ), 
            array ( $rules, $price, $post_id, $product, $prodLists, $cartRules, $item_price) 
        );

        if ( is_array ( $viewPrice ) || is_array ( $wcpaPrice ) ) {

            // $updatedPrice   = $viewPrice['discountedPrice'];

            $result['price']            =  $viewPrice['discountedPrice'] ?  $viewPrice['discountedPrice'] : $wcpaPrice['price'];
            $result['originalPrice']    = $price;

            return $result;
            
        }

        return $default;

    }

    // Cart Price View

}
