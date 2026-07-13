<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-awdp-discount-module.php';

class AWDP_Discount_Utilities extends AWDP_Discount_Module
{

    public function get_con_unit( $product, $price = false, $insideloop = false )
    {

        if ( $this->owner->conversion_unit === false && $insideloop === false ) {  

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
            // if ( $this->owner->conversion_unit == 1) {
            //     $this->owner->conversion_unit = apply_filters('wcml_raw_price_amount', 1);
            // }

            if ($this->owner->conversion_unit == 1) { // Aelia Currency Switcher
                if(wc_get_price_decimals() == 0 ){
                    $converted_amount   = apply_filters('wc_aelia_cs_convert', 1, $from_currency, $to_currency,2);
                }else{
                    $converted_amount   = apply_filters('wc_aelia_cs_convert', 1, $from_currency, $to_currency);
                }
                $this->owner->conversion_unit  = $converted_amount;
            }

            if ( ( $this->owner->conversion_unit == 1 || $this->owner->conversion_unit == false ) && class_exists('WOOMULTI_CURRENCY') ) { // WooCommerce Multi Currency Plugin
                $data                   = WOOMULTI_CURRENCY_Data::get_ins(); 
                $currency_array         = $data->get_list_currencies();
                $rate                   = (float)$currency_array[$to_currency]['rate']; 
                $this->owner->conversion_unit  = $rate;
            }

            if ( ( $this->owner->conversion_unit == 1 || $this->owner->conversion_unit == false ) && class_exists('WOOMULTI_CURRENCY_F') ) { // WooCommerce Multi Currency Free Plugin
                $data                   = WOOMULTI_CURRENCY_F_Data::get_ins(); 
                $currency_array         = $data->get_list_currencies();
                $rate                   = (float)$currency_array[$to_currency]['rate']; 
                $this->owner->conversion_unit  = $rate;
            }

            if ( ( $this->owner->conversion_unit == 1 || $this->owner->conversion_unit == false ) && function_exists('wcpbc_the_zone') ) {
                $wcpbc                  = wcpbc_the_zone();
                $converted_amount       = 1;
                if (is_callable($wcpbc, 'get_exchange_rate_price')) {
                    $converted_amount   = $wcpbc->get_exchange_rate_price(1);
                }
                $this->owner->conversion_unit  = $converted_amount;
            } 

            if ( $view_price && $edit_price && $edit_price > 0 && $view_price > 0 && $this->owner->conversion_unit == false ) {
                $this->owner->conversion_unit  = $view_price / $edit_price;
            } else if ( $this->owner->conversion_unit == false ) {
                $this->owner->conversion_unit  = 1;
            } 

            // global $WOOCS;
            // if ($this->owner->conversion_unit == 1 && $WOOCS!==null) {
            //     if (method_exists($WOOCS, 'woocs_exchange_value')) {
            //         $res=$WOOCS->woocs_exchange_value(1);
            //         $this->owner->conversion_unit = $res;
            //     }
            // }

            return $this->owner->conversion_unit;

        } else if ( $this->owner->conversion_unit === false && $insideloop === true ) { // Pricing Table
            
            $from_currency      = get_option('woocommerce_currency');
            $to_currency        = get_woocommerce_currency(); 

            if ( $from_currency === $to_currency ) return 1;

            $converted_price    = $price;
            $unit_price         = $product->get_price('edit');

            $this->owner->conversion_unit = $converted_price / $unit_price;

            // if ($this->owner->conversion_unit == 1) { // Aelia Currency Switcher
            //     if(wc_get_price_decimals() == 0 ){
            //         $converted_amount = apply_filters('wc_aelia_cs_convert', 1, $from_currency, $to_currency,2);
            //     }else{
            //         $converted_amount = apply_filters('wc_aelia_cs_convert', 1, $from_currency, $to_currency);
            //     }
            //     $this->owner->conversion_unit = $converted_amount;
            // }

            if ( ( $this->owner->conversion_unit == 1 || $this->owner->conversion_unit == false ) && class_exists('WOOMULTI_CURRENCY') ) { // WooCommerce Multi Currency Plugin
                $data                   = WOOMULTI_CURRENCY_Data::get_ins(); 
                $currency_array         = $data->get_list_currencies();
                $rate                   = (float)$currency_array[$to_currency]['rate']; 
                $this->owner->conversion_unit  = $rate;
            }

            if ( ( $this->owner->conversion_unit == 1 || $this->owner->conversion_unit == false ) && class_exists('WOOMULTI_CURRENCY_F') ) { // WooCommerce Multi Currency Free Plugin
                $data                   = WOOMULTI_CURRENCY_F_Data::get_ins(); 
                $currency_array         = $data->get_list_currencies();
                $rate                   = (float)$currency_array[$to_currency]['rate']; 
                $this->owner->conversion_unit  = $rate;
            }

            if ( ( $this->owner->conversion_unit == 1 || $this->owner->conversion_unit == false ) && function_exists('wcpbc_the_zone') ) {
                $wcpbc                  = wcpbc_the_zone();
                $converted_amount       = 1;
                if (is_callable($wcpbc, 'get_exchange_rate_price')) {
                    $converted_amount   = $wcpbc->get_exchange_rate_price(1);
                }
                $this->owner->conversion_unit  = $converted_amount;
            }

            // global $WOOCS;
            // if ($this->owner->conversion_unit == 1 && $WOOCS!==null) {
            //     if (method_exists($WOOCS, 'woocs_exchange_value')) { 
            //         $res=$WOOCS->woocs_exchange_value(1); 
            //         $this->owner->conversion_unit = $res;
            //     }
            // } 

            return $this->owner->conversion_unit;

        } else {

            return $this->owner->conversion_unit;

        }

    }
    
    // Discount Check

    public function wdpGetVariations ( $productID, $list = false ) {

        if ( $productID ) {
            if ( ( !is_array ( $productID ) && array_key_exists ( $productID, $this->owner->productvariations ) ) || ( $list && array_key_exists ( $list, $this->owner->productvariations ) ) ) {
                return $this->owner->productvariations[$productID];
            } else {
                global $wpdb;
                $productID      = is_array ( $productID ) ? implode(',', $productID) : $productID; 
                $PLVariations   = $wpdb->get_col("SELECT ID FROM {$wpdb->prefix}posts WHERE post_status = 'publish' AND post_parent IN ($productID) AND post_type = 'product_variation'");

                if ( $PLVariations ) {
                    if ( !is_array ( $productID ) ) $this->owner->productvariations[$productID] = $PLVariations;
                    else if ( $list ) $this->owner->productvariations[$list] = $PLVariations;

                    return $PLVariations;
                } 
            }
        }

        return false;

    }

    // // Save Order Meta
    // public function wdpOrderMeta ( $item_id, $values, $cart_item_key ) {

    //     $wdpDiscount    = $this->owner->wdp_order_meta ? $this->owner->wdp_order_meta : [];
    //     // $coupon         = get_option('awdp_fee_label') ? get_option('awdp_fee_label') : 'Discount';
    //     // $coupon_code    = apply_filters('woocommerce_coupon_code', $coupon);

    //     if ( array_key_exists ( $cart_item_key, $wdpDiscount ) ) {
    //         $_awdp_discounted_price = ( $wdpDiscount[$cart_item_key] ) ? $wdpDiscount[$cart_item_key] : [];
    //         wc_add_order_item_meta($item_id, '_awdp_discount_details', $_awdp_discounted_price);
    //         // wc_add_order_item_meta($item_id, '_awdp_coupon', $coupon_code);
    //         return;
    //     }

    // }

    // Save Order Meta

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

        if ( false == $this->owner->products_on_sale ) {
            
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
            
            $this->owner->products_on_sale = $prods_onSale;

        }

        $onSaleIDs = $this->owner->products_on_sale; 

        return in_array ( $productID, $onSaleIDs ) ? true : false;

    }

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
