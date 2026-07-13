<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-awdp-discount-module.php';

class AWDP_Discount_Coupon extends AWDP_Discount_Module
{

    /**
     * Builds the coupon discount total from cart-level rules only (excludes line-price product discounts).
     */
    protected function get_cart_coupon_discount_total()
    {
        $total = 0;

        if (empty($this->owner->discounts)) {
            return $total;
        }

        foreach ($this->owner->discounts as $ruleid => $discounts) {
            $discount_type = $discounts['discount_type'];

            if (!array_key_exists('discounts', $discounts)) {
                continue;
            }

            foreach ($discounts['discounts'] as $discount) {
                if ($discount['discount'] === '' || $discount['discount'] === null) {
                    continue;
                }

                if (!AWDP_Discount_Application::applies_to_coupon($discount_type, $ruleid, $discount)) {
                    continue;
                }

                $calc_discount = AWDP_Discount_Application::normalize_discount_amount($discount['discount']);

                if (AWDP_Discount_Application::coupon_amount_is_per_unit($discount_type, $ruleid)) {
                    $calc_discount = $calc_discount * (int) $discount['quantity'];
                }

                $total += $calc_discount;
            }
        }

        $converted_rate = $this->owner->converted_rate ? $this->owner->converted_rate : 1;

        if ($converted_rate > 0 && $converted_rate != 1) {
            $total = $total / $converted_rate;
        }

        return $total;
    }


    public function addVirtualCoupon($response, $curr_coupon_code)
    {

        if ( $this->owner->discounts && WC()->cart ) { 

            global $woocommerce;
            $prod_QNT               = [];
            $total                  = 0;
            $ct_total_new           = 0;
            $ct_cart_price_array    = [];
            $cart_contents          = $woocommerce->cart->get_cart();
            $ct_total               = $this->owner->wdpCartDicount;
            $ct_discount_values     = $this->owner->wdpCartDiscountValues;
            $converted_rate         = $this->owner->converted_rate ? $this->owner->converted_rate : 1;
            $label                  = get_option('awdp_fee_label') ? get_option('awdp_fee_label') : 'Discount';
            $this->owner->couponLabel      = $label;
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

            if ( ( $label == $curr_coupon_code ) || ( mb_strtolower($label, 'UTF-8') == mb_strtolower($curr_coupon_code, 'UTF-8') ) || ( addslashes(mb_strtolower($label, 'UTF-8')) == mb_strtolower($curr_coupon_code, 'UTF-8') ) || ( preg_match('/[\'^£$%&*()}{@#~?><>,|=_+¬-]/', $label) && mb_strtolower($label, 'UTF-8') == mb_strtolower(htmlspecialchars_decode ($curr_coupon_code), 'UTF-8') ) ) {

                foreach ( $this->owner->discounts as $ruleid => $discounts ) {

                    if ( !array_key_exists ( 'discounts', $discounts ) ) {
                        continue;
                    }

                    $discount_type = $discounts['discount_type'];

                    foreach ( $discounts['discounts'] as $discount ) {
                        if ( !empty ( $discount['productid'] ) && !in_array ( $discount['productid'], $prod_IDs ) ) {
                            $prod_IDs[] = $discount['productid'];
                        }
                    }
                }

                $total = $this->get_cart_coupon_discount_total();
            }

            if ( $total > 0 ) { 

                if ( !$this->owner->apply_wdp_coupon ) {
                    return false;
                }

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



                return $coupon_array;
            } 

        }

        return $response;

    }


    // Create virtual coupon

    public function couponLabel($label, $coupon)
    {

        if ($coupon) {
            $coupon_label = $this->owner->couponLabel;
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

        if ( !WC()->cart ) {
            return true;
        }

        $coupon_applied = in_array($coupon_code, $woocommerce->cart->get_applied_coupons(), true);

        if ( $this->owner->apply_wdp_coupon && !empty($this->owner->discounts) ) {

            $coupons_obj    = new WC_Coupon($coupon_code);
            $coupons_amount = $coupons_obj->get_amount();

            if ($coupons_amount > 0 && !$coupon_applied) {
                $woocommerce->cart->add_discount($coupon_code);
            } elseif ($coupons_amount <= 0 && $coupon_applied) {
                WC()->cart->remove_coupon($coupon_code);
            }

        } elseif ( $coupon_applied ) {

            WC()->cart->remove_coupon($coupon_code);

        }

        $applied_coupons = WC()->cart->get_applied_coupons();

        return true;

    }


    public function wdpMiniCart() {

        global $woocommerce;  //apply_filters('woocommerce_applied_coupon');
        $coupon         = get_option('awdp_fee_label') ? get_option('awdp_fee_label') : 'Discount';
        $coupon_code    = apply_filters('woocommerce_coupon_code', $coupon); 
        $result         = '';
        $coupons_amount = 0;

        if ( $this->owner->discounts && apply_filters('wdp_hideMiniCart', true)) {

            $coupons_amount = $this->get_cart_coupon_discount_total();

            $shipping           = $woocommerce->cart->get_cart_shipping_total();
            $taxes              = $woocommerce->cart->get_tax_totals();
            // $cart_total     = $woocommerce->cart->cart_contents_total;
            $tax_total_display  = get_option('woocommerce_tax_total_display'); 
            $total          = $woocommerce->cart->get_totals();
            if ( $coupons_amount ) { 
                $result = '<p class="wdp_miniCart total">';
                $result .= '<span class="wdpLabel">'.$coupon.': </span><span class="woocommerce-Price-amount amount">'.wc_price($coupons_amount).'</span></br>';
                $result .= ( $shipping && ( strpos ( $shipping, __('Free', 'aco-woo-dynamic-pricing') ) === false) ) ? '<span class="wdpLabel">'.__("Shipping", "aco-woo-dynamic-pricing").': </span><span class="woocommerce-Price-amount amount">'.$shipping.'</span></br>' : '';
                if ($taxes) {
                    if($tax_total_display == 'single') {
                        $single_tax = 0;
                        foreach ($taxes as $key => $val) { 
                            $single_tax += $val->amount; 
                        }
                        $tax_label = isset($taxes[array_key_first($taxes)]->label) ? $taxes[array_key_first($taxes)]->label : 'Tax';
                        $result .= '<span class="wdpLabel">'.$tax_label.': </span><span class="woocommerce-Price-amount amount">'.wc_price($single_tax).'</span></br>';
                    } else {
                        foreach ($taxes as $key => $val) { 
                            $result .= '<span class="wdpLabel">'.$val->label.': </span><span class="woocommerce-Price-amount amount">'.wc_price($val->amount).'</span></br>';
                        }
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

}
