<?php

/*
* @@ Product Price 
* @@ Last updated version 4.0.5
*/

class AWDP_productGroup
{

    public function product_group ( $rules, $price, $item_id, $product, $prodLists, $cartRules, $item_price )
    {

        $result                         = [];

        if ( $rules != false && sizeof ( $rules ) >= 1 ) { 

            $prod_ID            = $product->get_data()['slug'];
            $variationCheck     = $product->is_type( 'variable' );
            $variations         = $variationCheck ? $product->get_children() : [];
            $converted_rate     = 1;
            $discountprice      = '';
            $mindiscountprice   = '';
            $maxdiscountprice   = '';
            $wdp_max_price      = 0;
            $wdp_min_price      = 0;
            $maxdiscount        = 0;
            $mindiscount        = 0;
            $discount           = 0;
            $ProductRuleActive  = false;

            foreach ( $rules as $rule ) {
               
                // Get Product List
                $checkItem = call_user_func_array ( 
                    array ( new AWDP_Discount(), 'get_items_to_apply_discount' ), 
                    array ( $product, $rule, false, true ) 
                );

                $validateRules = call_user_func_array ( 
                    array ( new AWDP_Discount(), 'validate_discount_rules' ), 
                    array ( $product, $rule, ['cart_total_amount', 'cart_total_amount_all_prods', 'cart_items', 'cart_items_all_prods', 'cart_products', 'cust_prev_order_count', 'cart_user_role', 'cart_user_selection', 'payment_method', 'shipment_method', 'number_orders', 'amount_spent', 'last_order', 'previous_order', 'product_in_cart'] ) 
                );

                if ( !$checkItem ) {
                    continue;
                }

                // Check if User if Logged-In
                if ( ( intval ( $rule['discount_reg_customers'] ) === 1 && !is_user_logged_in() ) || ( intval ( $rule['discount_reg_customers'] ) === 1 && is_user_logged_in() && ( !empty ( array_filter ( $rule['discount_reg_user_roles'] ) ) && empty ( array_intersect ( $rule['discount_cur_user_roles'], $rule['discount_reg_user_roles'] ) ) ) ) ) { 
                    continue;
                }

                // Validate Rules
                if ( !$validateRules ) {
                    continue;
                }

                if ( ( $rule['type'] == 'percent_product_price' || $rule['type'] == 'fixed_product_price' ) ) {

                    $type = ( $rule['type'] == 'fixed_product_price' ) ? 'fixed' : 'percentage';
                    $result[] = array ( 'type' => $type, 'value' => $rule['discount'] );

                }

            }

            return $result;

        }

        return $result;

    }

    // WCPA Discounted Price - Filter 
    public function product_price ( $rules, $price, $item_id, $product, $prodLists, $cartRules, $item_price,$itemCount = 1 )
    { 
        $result                 = '';

        if ( $rules != false && sizeof ( $rules ) >= 1 ) { 

            $prod_ID            = $product->get_data()['slug'];
            $variationCheck     = $product->is_type( 'variable' );
            $variations         = $variationCheck ? $product->get_children() : [];
            $converted_rate     = 1;
            $discountprice      = '';
            $mindiscountprice   = '';
            $maxdiscountprice   = '';
            $originalPrice      = '';
            $wdp_max_price      = 0;
            $wdp_min_price      = 0;
            $maxdiscount        = 0;
            $mindiscount        = 0;
            $discount           = 0;
            $wdp_cart_quantity  = 0;
            $wdp_cart_totals    = 0;
            $ProductRuleActive  = false;
            
            // if($product->is_on_sale()){
            //     $regular_price = $product->get_data()['regular_price'];
            // }

            $regular_price = $product->is_on_sale() ? $product->get_data()['regular_price'] : $price;

            foreach ( $rules as $rule ) {
                
                // Get Product List
                $checkItem = call_user_func_array ( 
                    array ( new AWDP_Discount(), 'get_items_to_apply_discount' ), 
                    array ( $product, $rule, false, true ) 
                );

                $validateRules = call_user_func_array ( 
                    array ( new AWDP_Discount(), 'validate_discount_rules' ), 
                    array ( $product, $rule, ['cart_total_amount', 'cart_total_amount_all_prods', 'cart_items', 'cart_items_all_prods', 'cart_products', 'cust_prev_order_count', 'cart_user_role', 'cart_user_selection', 'payment_method', 'shipment_method', 'number_orders', 'amount_spent', 'last_order', 'previous_order'] ) 
                );

                if ( !$checkItem ) {
                    continue;
                }

                // Check if User if Logged-In
                if ( ( intval ( $rule['discount_reg_customers'] ) === 1 && !is_user_logged_in() ) || ( intval ( $rule['discount_reg_customers'] ) === 1 && is_user_logged_in() && ( !empty ( array_filter ( $rule['discount_reg_user_roles'] ) ) && empty ( array_intersect ( $rule['discount_cur_user_roles'], $rule['discount_reg_user_roles'] ) ) ) ) ) { 
                    continue;
                }

                // Validate Rules
                if ( !$validateRules ) {
                    continue;
                }
                if ( ( $rule['type'] == 'percent_product_price' || $rule['type'] == 'fixed_product_price' ) ) {

                    $discount       = ( $rule['type'] == 'fixed_product_price' ) ? $rule['discount'] : ( ( $price * $rule['discount'] ) / 100 );

                    $discountPrice          = ( $discount < $price ) ? $price - $discount : 0;

                // Single page calculation for Quantity based discount

                } else if ($rule['type'] == 'cart_quantity' ) {
        
                    $quantity_rules             = $rule['quantity_rules'];

                    foreach ($quantity_rules as $quantity_rule){

                        $discount_val           = $quantity_rule['dis_value'];
                        $discount_typ           = $quantity_rule['dis_type'];
                        $discounted_new_price   = '';

                        if ( ( (int)$quantity_rule['start_range'] == (int)$quantity_rule['end_range'] ) && ( (int)$quantity_rule['start_range'] == $itemCount ) ) {

                            $discount_amt = 0;
                            if ($discount_typ == 'percentage') {
                                $discount_amt = $price * ($discount_val / 100);
                            } else if ($discount_typ == 'fixed') {
                                $discount_amt = $discount_val;
                            } else {
                                $discount_amt = 0;
                            }
                            $discountPrice    = ( $discount_amt < $price ) ? $price - $discount_amt: 0;

                        } else if (($itemCount >= (int)$quantity_rule['start_range']) && ($itemCount <= (int)$quantity_rule['end_range']) && ((int)$quantity_rule['start_range'] != (int)$quantity_rule['end_range'])) {
                        
                            if ($discount_typ == 'percentage') {
                                
                                $discount_amt   = $price * ($discount_val / 100);
                            } else if ($discount_typ == 'fixed') {
                                $discount_amt = $discount_val;
                            } else {
                                $discount_amt = 0;
                            }
                            $discountPrice    = ( $discount_amt < $price ) ? $price - $discount_amt: 0;
                            $originalPrice    = $regular_price;
                        } else if (($itemCount >= (int)$quantity_rule['start_range']) && $quantity_rule['end_range'] == '' ) {
                            
                            if ($discount_typ == 'percentage') {
                                
                                $discount_amt   = $price * ($discount_val / 100);
                            } else if ($discount_typ == 'fixed') {
                                $discount_amt = $discount_val;
                            } else {
                                $discount_amt = 0;
                            }
                            $discountPrice    = ( $discount_amt < $price ) ? $price - $discount_amt: 0;
                            $originalPrice    = $regular_price;
                        }
                    }

                } else {
                    $originalPrice      = $regular_price;
                }

            }

            $result          = [];
            // $result['price'] = isset($discountPrice) ? (float)$discountPrice : (float)$price;
            $result['price'] = isset($discountPrice) ? round((float)$discountPrice, wc_get_price_decimals()) : round((float)$price, wc_get_price_decimals());
            $result['originalPrice'] = (float)$originalPrice ? (float)$originalPrice : (float)$price;
            return $result;
        }
        return $result;
    }

    public function wcpa_discount ( $rules, $product )
    {

        $result                 = [];

        if ( $rules != false && sizeof ( $rules ) >= 1 ) { 

            $prod_ID            = $product->get_data()['slug'];
            $variationCheck     = $product->is_type( 'variable' );
            $variations         = $variationCheck ? $product->get_children() : [];
            $converted_rate     = 1;
            $discountprice      = '';
            $mindiscountprice   = '';
            $maxdiscountprice   = '';
            $wdp_max_price      = 0;
            $wdp_min_price      = 0;
            $maxdiscount        = 0;
            $mindiscount        = 0;
            $discount           = 0;
            $ProductRuleActive  = false;

            foreach ( $rules as $rule ) {
               
                // Get Product List
                $checkItem = call_user_func_array ( 
                    array ( new AWDP_Discount(), 'get_items_to_apply_discount' ), 
                    array ( $product, $rule, false, true ) 
                );

                $validateRules = call_user_func_array ( 
                    array ( new AWDP_Discount(), 'validate_discount_rules' ), 
                    array ( $product, $rule, ['cart_total_amount', 'cart_total_amount_all_prods', 'cart_items', 'cart_items_all_prods', 'cart_products', 'cust_prev_order_count', 'cart_user_role', 'cart_user_selection', 'payment_method', 'shipment_method', 'number_orders', 'amount_spent', 'last_order', 'previous_order', 'product_in_cart'] ) 
                );

                if ( !$checkItem ) {
                    continue;
                }

                // Check if User if Logged-In
                if ( ( intval ( $rule['discount_reg_customers'] ) === 1 && !is_user_logged_in() ) || ( intval ( $rule['discount_reg_customers'] ) === 1 && is_user_logged_in() && ( !empty ( array_filter ( $rule['discount_reg_user_roles'] ) ) && empty ( array_intersect ( $rule['discount_cur_user_roles'], $rule['discount_reg_user_roles'] ) ) ) ) ) { 
                    continue;
                }

                // Validate Rules
                if ( !$validateRules ) {
                    continue;
                }

                if ( ( $rule['type'] == 'percent_product_price' || $rule['type'] == 'fixed_product_price' ) ) {

                    $type           = ( $rule['type'] == 'fixed_product_price' ) ? 'fixed' : 'percentage';
                    $result[]       = array ( 'type' => $type, 'value' => $rule['discount'] );

                }

            }

            return $result;

        }

        return $result;

    }

}