<?php

/*
* @@ Cart Price View
* Last updated version 4.0.0
*/

class AWDP_viewCartPrice
{

    public function cart_price ( $rules, $price, $cart_item, $prodLists, $item_price, $activeDiscounts, $product, $quantity, $display_price )
    {

        $parent_id          = $cart_item['product_id'];
        $prod_ID            = $cart_item['data']->get_slug();
        $cartKey            = $cart_item['key'];
        $converted_rate     = 1;
        $discount           = 0; 

        $quantity       = 1; // Set quantity to 1 - individual product price

        /*
        * ver @ 4.3.3
        * Tax Settings - disabled tax check - $price includes tax 
        */
        // if ( WC()->cart->display_prices_including_tax() ) {
        //     $price = call_user_func_array ( 
        //                 array ( new AWDP_Discount(), 'wdp_price_including_tax' ), 
        //                 array ( $product, $price, array ( 'qty' => $quantity, 'price' => $price ) ) 
        //             );
        // } else {
        //     $price = call_user_func_array ( 
        //                 array ( new AWDP_Discount(), 'wdp_price_excluding_tax' ), 
        //                 array ( $product, $price, array ( 'qty' => $quantity, 'price' => $price ) ) 
        //             );
        // }

        if ( $activeDiscounts ) {
            foreach ( $activeDiscounts as $discounts ) {  
                if ( array_key_exists ( 'discounts', $discounts ) ) {
                    if ( array_key_exists ( $cartKey, $discounts['discounts'] ) && $discounts['discounts'][$cartKey]['discount'] != '' && $discounts['discounts'][$cartKey]['displayoncart'] ) { 
                        $discount += wc_remove_number_precision ( $discounts['discounts'][$cartKey]['discount'] );
                    }
                }
            } 
        }

        /*
        * ver @ 4.3.3
        * Tax Settings
        */
        $tax_display_mode   = get_option( 'woocommerce_tax_display_shop' );
        $discount           = ( 'incl' === $tax_display_mode ) ? wc_get_price_including_tax( $product, array ( 'price' => $discount ) ) : wc_get_price_excluding_tax( $product, array ( 'price' => $discount ) );

        $discounted_price = $price - $discount; 

        if ( ( $discounted_price < $price ) || $discounted_price == 0 ) {

            $item_price = $display_price ? wc_format_sale_price ( $display_price * $converted_rate, $discounted_price * $converted_rate ) : wc_format_sale_price ( $price * $converted_rate, $discounted_price * $converted_rate );

        } else if ( $discounted_price > $price ) {

            $item_price = $display_price ? wc_format_sale_price ( $display_price * $converted_rate, $discounted_price * $converted_rate ) : wc_format_sale_price ( $price * $converted_rate, $discounted_price * $converted_rate );

        }

        return $item_price;

    }

}