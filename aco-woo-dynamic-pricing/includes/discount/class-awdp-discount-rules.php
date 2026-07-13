<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-awdp-discount-module.php';

class AWDP_Discount_Rules extends AWDP_Discount_Module
{

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
        $allowed_operators = ['AND', 'OR', ''];
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
                        $operator = (isset($rul['operator']) && in_array($rul['operator'], $allowed_operators, true)) ? $rul['operator'] :
                            'AND';
                        $evel_str .= ') ' . (($operator !== false) ? $operator : '') . ' ';
                    }

                    if ( count($val['rules']) > 0 && !empty($val['rules']) ) {
                        preg_match_all('/\(.*\)/', $evel_str, $match);
                        $evel_str = $match[0][0] . ' ';
                    }
                    $operator = (isset($val['operator']) && in_array($val['operator'], $allowed_operators, true)) ? $val['operator'] :
                        'AND';
                    $evel_str .= ') ' . (($operator !== false) ? $operator : '') . ' ';

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

        $product_lists      = $this->owner->product_lists ? $this->owner->product_lists : [];  

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
                    if ( ( !$list_id || $list_id == 'null' ) || ( !empty ( $applicable_products ) && in_array ( $cart_item['product_id'], $applicable_products ) ) ) { 
                        $wdp_cart_totals_pl    = $wdp_cart_totals_pl + $cart_item['data']->get_price() * $cart_item['quantity'];
                        $wdp_cart_items_pl     = $wdp_cart_items_pl + $cart_item['quantity'];
                        $wdp_cart_quantity_pl  = $wdp_cart_quantity_pl + 1;
                    }
                }
            }

        }

        if ( 'cart_total_amount' == $rule['item'] ) { 

            // cart based rule : true
            $this->owner->awdp_cart_rules  = true;
            $this->owner->apply_wdp_coupon = true; 
            // $this->owner->awdp_cart_rule_ids[] = $discount_rule;

            // Check if cart is empty
            if ( !isset (WC()->cart) || $wdp_cart_quantity_pl == 0 || !did_action('woocommerce_before_calculate_totals') ) 
                return false;

            $item_val   = $wdp_cart_totals_pl;
            $rel_val    = (float)$rule['value'];

        } else if ( 'cart_total_amount_all_prods' == $rule['item'] ) { 

            // cart based rule : true
            $this->owner->awdp_cart_rules  = true;
            $this->owner->apply_wdp_coupon = true; 
            // $this->owner->awdp_cart_rule_ids[] = $discount_rule;

            // Check if cart is empty
            if ( !isset (WC()->cart) || $wdp_cart_totals == 0 || !did_action('woocommerce_before_calculate_totals') ) 
                return false;

            $item_val   = $wdp_cart_totals;
            $rel_val    = (float)$rule['value'];

        } else if ( 'product_price' == $rule['item'] ) {

            $this->owner->apply_wdp_coupon = true;

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
            $this->owner->awdp_cart_rules      = true;
            $this->owner->apply_wdp_coupon     = true;
            $this->owner->awdp_cart_rule_ids[] = $discount_rule;

            // Check if cart is empty
            if ( !isset ( WC()->cart ) || $wdp_cart_quantity_pl == 0 || !did_action('woocommerce_before_calculate_totals') ) return false;

            $item_val   = $wdp_cart_items_pl; 
            $rel_val    = (float)$rule['value'];

        } else if ( 'cart_items_all_prods' == $rule['item'] && false == $qn_flag ) {

            // cart based rule : true
            $this->owner->awdp_cart_rules      = true;
            $this->owner->apply_wdp_coupon     = true;
            $this->owner->awdp_cart_rule_ids[] = $discount_rule;

            // Check if cart is empty
            if ( !isset ( WC()->cart ) || $wdp_cart_quantity == 0 || !did_action('woocommerce_before_calculate_totals') ) return false;

            $item_val   = $wdp_cart_items; 
            $rel_val    = (float)$rule['value'];

        } else if ( 'cart_products' == $rule['item'] && false == $qn_flag ) {

            // cart based rule : true
            $this->owner->awdp_cart_rules      = true;
            $this->owner->apply_wdp_coupon     = true;
            $this->owner->awdp_cart_rule_ids[] = $discount_rule;

            // Check if cart is empty
            if ( !isset ( WC()->cart ) || $wdp_cart_quantity == 0 || !did_action('woocommerce_before_calculate_totals') ) return false;

            $item_val   = $wdp_cart_quantity;
            $rel_val    = (float)$rule['value'];

        } else if ( 'cart_products_list' == $rule['item'] && false == $qn_flag ) {

            // cart based rule : true
            $this->owner->awdp_cart_rules      = true;
            $this->owner->apply_wdp_coupon     = true;
            $this->owner->awdp_cart_rule_ids[] = $discount_rule;

            // Check if cart is empty
            if ( !isset ( WC()->cart ) || $wdp_cart_quantity_pl == 0 || !did_action('woocommerce_before_calculate_totals') ) return false;

            $item_val   = $wdp_cart_quantity_pl; 
            $rel_val    = (float)$rule['value'];

        } else {

            return false;

        }

        if ( $item_val <= 0 ) {
            switch ($rule['condition']) {
                case 'equal_to':
                    return ( $item_val == $rel_val );
                case 'less_than':
                    return ( $item_val < $rel_val );
                case 'less_than_eq':
                    return ( $item_val <= $rel_val );
                case 'greater_than': 
                    return ( $item_val > $rel_val );
                case 'greater_than_eq':
                    return ( $item_val >= $rel_val );
            }
            return false;
        }

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

        if ($this->owner->discount_rules === false) {

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
            $this->owner->discount_rules = $discount_rules;
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

        if ( $cartRule && $this->owner->awdp_cart_rules ) // For Product Price View // Check cart rules active
            return false;

        // if ( $product_slug && isset ( $rule['apply_rule_once'] ) && $rule['apply_rule_once'] && in_array ( $product_slug, $this->owner->discounted_products ) ) 
        //      return false;

        return true;

    }



    public function check_in_product_list($product, $rule)
    {

        if ( ( !$rule['product_list'] || 'null' == $rule['product_list'] || 0 == $rule['product_list'] ) && !$rule['custom_pl_status'] ) {

            return true;

        } else if ( $rule['custom_pl_status'] ) { 

            // Custom Product List
            $customPL   = $rule['custom_pl'];
            // $pro_id     = ( $product->get_parent_id() == 0 ) ? $product->get_id() : $product->get_parent_id();
            
            if ( is_object( $product ) ) {
                $pro_id    = ( $product->get_parent_id() == 0 ) ? $product->get_id() : $product->get_parent_id();
            } else {
                $pro_id    = 0;
            }
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

            if ( is_object( $product ) ) {
                $pro_id    = ( $product->get_parent_id() == 0 ) ? $product->get_id() : $product->get_parent_id();
            } else {
				$pro_id    = 0;
			 }
            // $pro_id = $product->get_parent_id(); // in case of variation
            // if ($pro_id == 0) {
            //     $pro_id = $product->get_id();
            // }
            return isset($this->owner->product_lists[$rule['product_list']]) &&
                in_array($pro_id, $this->owner->product_lists[$rule['product_list']]);
                
        }

    }
    


    public function set_product_list()
    {

        if (false == $this->owner->product_lists) {

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

            $this->owner->product_lists = $product_lists;
            
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


    public function check_in_rule ( $selectedRule, $productID ) {

        $this->load_rules();

        if ( $this->owner->discount_rules == null ) return false; // Skip if no active rules

        $product        = wc_get_product ( $productID ); 
        $discount_index = array_search ( $selectedRule, array_column ( $this->owner->discount_rules, 'id' ) );

        if ( $discount_index !== false ) {

            $rules  = $this->owner->discount_rules;
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


}
