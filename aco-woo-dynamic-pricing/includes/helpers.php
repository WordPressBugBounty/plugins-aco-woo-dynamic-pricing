<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

// Dynamic Discount Value
if ( !function_exists('awdp_dynamic_value') ) {

    function awdp_dynamic_value( $rule, $item, $price, $quantity, $prodLists, $disc_prod_ID = false )
    {

        if ( isset($rule['rules']) && is_array($rule['rules']) && !empty($rule['rules']) ) {

            $product_lists      = $prodLists ? $prodLists : [];  
            $list_id            = ( array_key_exists ( 'product_list', $rule ) && $rule['product_list'] ) ? $rule['product_list'] : '';
            $rulesArray         = $rule['rules'];

            // Initialise
            $wdp_cart_totals = $wdp_cart_items = $wdp_cart_quantity = $wdp_cart_quantity_pl = $wdp_cart_totals_pl = $wdp_cart_items_pl = 0;

            // Sort Based on discount
            usort ( $rulesArray, function($a, $b) {
                if ( $b['discount'] != '' && $a['discount'] != '' )
                    return $b['discount'] - $a['discount']; 
            } );

            $item               = $disc_prod_ID ? wc_get_product ( $disc_prod_ID ) : $item;
            // $evel_str           = '';

            // Checking if Product List is Active
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

            foreach ( $rulesArray as $val ) {

                if ( !empty($val['rules']) && is_array($val['rules']) && count($val['rules']) ) {

                    $evel_str = '';

                    $val_rules  = array_values ( array_filter( $val['rules'] ) ); 
                    $dynmcDisc  = array_key_exists ( 'discount', $val ) ? $val['discount'] : ''; 

                    if ( !$dynmcDisc ) continue;
                    $allowed_operators = ['AND', 'OR', ''];
                   
                    foreach ( $val_rules as $rul ) { 

                        $evel_str .= '(';

                        if ( $rul['rule']['value'] != '' ) {
                            if ( awdp_combinations ( $rul, $item, $list_id, $product_lists, $wdp_cart_totals, $wdp_cart_items, $wdp_cart_quantity, $wdp_cart_totals_pl, $wdp_cart_items_pl, $wdp_cart_quantity_pl ) ) { 
                                $evel_str .= ' true ';
                            } else { 
                                $evel_str .= ' false ';
                            }
                        } else {
                            $evel_str .= ' true ';
                        }
                        $operator = (isset($rul['operator']) && in_array($rul['operator'], $allowed_operators, true)) ? $rul['operator'] :
                    '';
                        $evel_str .= ') ' . (($operator !== false) ? $operator : '') . ' ';

                        
                    }

                    if ( count($val['rules']) > 0 && !empty($val['rules']) ) {
                        preg_match_all('/\(.*\)/', $evel_str, $match);
                        $evel_str = $match[0][0] . ' ';
                    }

                    $evel_str = str_replace(['and', 'or'], ['&&', '||'], strtolower($evel_str));

                    if ( eval ( 'return ' . $evel_str . ';' ) ) {

                        return $dynmcDisc;

                    }

                }
                
            }

        }

    }

}

// Dynamic Discount Combinations
if ( !function_exists('awdp_combinations') ) {

    function awdp_combinations ( $rul, $item, $list_id, $product_lists, $wdp_cart_totals, $wdp_cart_items, $wdp_cart_quantity, $wdp_cart_totals_pl, $wdp_cart_items_pl, $wdp_cart_quantity_pl )
    {

        $ruleItem   = $rul['rule']['item'];
        $ruleVal    = $rul['rule']['value'];
        $rulCond    = $rul['rule']['condition'];

        // $operator = $rul["operator"];

        if ( 'cart_total_amount' == $ruleItem ) { 

            // Check if cart is empty
            if ( !isset (WC()->cart) || $wdp_cart_quantity_pl == 0 || !did_action('woocommerce_before_calculate_totals') ) 
                return false;

            $item_val   = $wdp_cart_totals_pl;
            $rel_val    = (float)$ruleVal;

        } else if ( 'cart_total_amount_all_prods' == $ruleItem ) { 

            // Check if cart is empty
            if ( !isset (WC()->cart) || $wdp_cart_totals == 0 || !did_action('woocommerce_before_calculate_totals') ) 
                return false;

            $item_val   = $wdp_cart_totals;
            $rel_val    = (float)$ruleVal;

        } else if ( 'product_price' == $ruleItem ) {

            $item_val   = (float)$item->get_price();  
            $rel_val    = (float)$ruleVal;

        } else if ( 'cart_items' == $ruleItem ) {

            // Check if cart is empty
            if ( !isset ( WC()->cart ) || $wdp_cart_quantity_pl == 0 || !did_action('woocommerce_before_calculate_totals') ) return false;

            $item_val   = $wdp_cart_items_pl;
            $rel_val    = (float)$ruleVal;

        } else if ( 'cart_items_all_prods' == $ruleItem ) {

            // Check if cart is empty
            if ( !isset ( WC()->cart ) || $wdp_cart_quantity == 0 || !did_action('woocommerce_before_calculate_totals') ) return false;

            $item_val   = $wdp_cart_items;
            $rel_val    = (float)$ruleVal;

        } else if ( 'cart_products' == $ruleItem ) {

            // Check if cart is empty
            if ( !isset ( WC()->cart ) || $wdp_cart_quantity == 0 || !did_action('woocommerce_before_calculate_totals') ) return false;

            $item_val   = $wdp_cart_quantity;
            $rel_val    = (float)$ruleVal;

        } else if ( 'cart_products_list' == $ruleItem ) {

            // Check if cart is empty
            if ( !isset ( WC()->cart ) || $wdp_cart_quantity_pl == 0 || !did_action('woocommerce_before_calculate_totals') ) return false;

            $item_val   = $wdp_cart_quantity_pl;
            $rel_val    = (float)$ruleVal;

        } else {

            return false;

        }

        if ( $item_val <= 0 ) {
            switch ($rulCond) {
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

        switch ($rulCond) {
            case 'equal_to':
                if (@abs(($item_val - $rel_val) / $item_val) < 0.00001) {
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

    }

}

/**
 * Allowed coupon interaction modes.
 *
 * @return string[]
 */
if ( ! function_exists( 'awdp_coupon_interaction_modes' ) ) {
	function awdp_coupon_interaction_modes() {
		return array( 'dynamic_pricing_only', 'both', 'coupons_only' );
	}
}

/**
 * Virtual coupon code used by Dynamic Pricing discounts.
 *
 * @return string
 */
if ( ! function_exists( 'awdp_get_virtual_coupon_code' ) ) {
	function awdp_get_virtual_coupon_code() {
		$coupon = get_option( 'awdp_fee_label' ) ? get_option( 'awdp_fee_label' ) : 'Discount';
		return apply_filters( 'woocommerce_coupon_code', $coupon );
	}
}

/**
 * Whether a coupon code is the plugin virtual discount coupon.
 *
 * @param string $code Coupon code.
 * @return bool
 */
if ( ! function_exists( 'awdp_is_virtual_coupon_code' ) ) {
	function awdp_is_virtual_coupon_code( $code ) {
		$virtual = awdp_get_virtual_coupon_code();
		return ( mb_strtolower( (string) $code, 'UTF-8' ) === mb_strtolower( (string) $virtual, 'UTF-8' ) );
	}
}

/**
 * Resolve coupon / Dynamic Pricing interaction mode.
 * Migrates from legacy hide_coupon_box / disable_discount when unset.
 *
 * @return string dynamic_pricing_only|both|coupons_only
 */
if ( ! function_exists( 'awdp_get_coupon_interaction_mode' ) ) {
	function awdp_get_coupon_interaction_mode() {
		$mode    = get_option( 'awdp_coupon_interaction', '' );
		$allowed = awdp_coupon_interaction_modes();

		if ( $mode && in_array( $mode, $allowed, true ) ) {
			return $mode;
		}

		// Legacy fallbacks.
		if ( get_option( 'awdp_hide_coupon_box' ) ) {
			return 'dynamic_pricing_only';
		}
		if ( get_option( 'awdp_disable_discount' ) ) {
			return 'coupons_only';
		}

		return 'both';
	}
}

/**
 * Persist coupon interaction mode and sync legacy flags.
 *
 * @param string $mode Mode value.
 * @return string Normalized mode.
 */
if ( ! function_exists( 'awdp_set_coupon_interaction_mode' ) ) {
	function awdp_set_coupon_interaction_mode( $mode ) {
		$allowed = awdp_coupon_interaction_modes();
		$mode    = in_array( $mode, $allowed, true ) ? $mode : 'both';

		if ( false === get_option( 'awdp_coupon_interaction' ) ) {
			add_option( 'awdp_coupon_interaction', $mode, '', 'yes' );
		} else {
			update_option( 'awdp_coupon_interaction', $mode );
		}

		$hide_coupon_box  = ( 'dynamic_pricing_only' === $mode ) ? 1 : '';
		$disable_discount = ( 'coupons_only' === $mode ) ? 1 : '';

		if ( false === get_option( 'awdp_hide_coupon_box' ) ) {
			add_option( 'awdp_hide_coupon_box', $hide_coupon_box, '', 'yes' );
		} else {
			update_option( 'awdp_hide_coupon_box', $hide_coupon_box );
		}

		if ( false === get_option( 'awdp_disable_discount' ) ) {
			add_option( 'awdp_disable_discount', $disable_discount, '', 'yes' );
		} else {
			update_option( 'awdp_disable_discount', $disable_discount );
		}

		return $mode;
	}
}

/**
 * True when the cart has any non-virtual WooCommerce coupon.
 *
 * @return bool
 */
if ( ! function_exists( 'awdp_cart_has_regular_coupons' ) ) {
	function awdp_cart_has_regular_coupons() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}

		$applied = WC()->cart->get_applied_coupons();
		if ( empty( $applied ) ) {
			return false;
		}

		foreach ( $applied as $code ) {
			if ( ! awdp_is_virtual_coupon_code( $code ) ) {
				return true;
			}
		}

		return false;
	}
}

/**
 * Whether Dynamic Pricing discounts should run for the current cart.
 *
 * @return bool
 */
if ( ! function_exists( 'awdp_should_apply_dynamic_pricing' ) ) {
	function awdp_should_apply_dynamic_pricing() {
		if ( 'coupons_only' === awdp_get_coupon_interaction_mode() && awdp_cart_has_regular_coupons() ) {
			return false;
		}
		return true;
	}
}

/**
 * Whether regular WooCommerce coupons should be blocked.
 *
 * @return bool
 */
if ( ! function_exists( 'awdp_should_block_regular_coupons' ) ) {
	function awdp_should_block_regular_coupons() {
		return ( 'dynamic_pricing_only' === awdp_get_coupon_interaction_mode() );
	}
}

/**
 * Whether the storefront coupon field should be hidden.
 *
 * @return bool
 */
if ( ! function_exists( 'awdp_should_hide_coupon_box' ) ) {
	function awdp_should_hide_coupon_box() {
		return ( 'dynamic_pricing_only' === awdp_get_coupon_interaction_mode() );
	}
}

/**
 * WCPA cart addon payload for a cart line (false when WCPA inactive / no data).
 *
 * @param array $cart_item Cart line.
 * @return array|false
 */
if ( ! function_exists( 'awdp_get_wcpa_addon_data' ) ) {
	function awdp_get_wcpa_addon_data( $cart_item ) {
		return apply_filters( 'wcpa_cart_addon_data', false, $cart_item );
	}
}

/**
 * Addon unit amount from WCPA payload (addonPrice, with totalPrice - productPrice fallback).
 *
 * @param array|false $addon_data Result of wcpa_cart_addon_data.
 * @return float
 */
if ( ! function_exists( 'awdp_get_wcpa_addon_unit_amount' ) ) {
	function awdp_get_wcpa_addon_unit_amount( $addon_data ) {
		if ( ! is_array( $addon_data ) ) {
			return 0.0;
		}

		if ( array_key_exists( 'addonPrice', $addon_data ) ) {
			return max( 0, (float) $addon_data['addonPrice'] );
		}

		if ( array_key_exists( 'totalPrice', $addon_data ) && array_key_exists( 'productPrice', $addon_data ) ) {
			return max( 0, (float) $addon_data['totalPrice'] - (float) $addon_data['productPrice'] );
		}

		return 0.0;
	}
}

/**
 * Whether the cart line unit is product-only (WCPA "No tax for addon" / tax-class split).
 * In that mode addons live outside get_price() (fees / WCPA subtotal filters).
 *
 * @param float       $cart_price Current cart line unit price.
 * @param array|false $addon_data Result of wcpa_cart_addon_data.
 * @return bool
 */
if ( ! function_exists( 'awdp_is_wcpa_split_pricing' ) ) {
	function awdp_is_wcpa_split_pricing( $cart_price, $addon_data ) {
		if ( ! is_array( $addon_data ) ) {
			return false;
		}

		$addon_unit = awdp_get_wcpa_addon_unit_amount( $addon_data );
		if ( $addon_unit <= 0 ) {
			return false;
		}

		if ( ! array_key_exists( 'productPrice', $addon_data ) ) {
			return false;
		}

		$epsilon = 0.0001;
		return abs( (float) $cart_price - (float) $addon_data['productPrice'] ) <= $epsilon;
	}
}

/**
 * Unit amount that must stay full (not discounted) for a WCPA line.
 *
 * @param array|false $addon_data Result of wcpa_cart_addon_data.
 * @return float
 */
if ( ! function_exists( 'awdp_get_wcpa_excluded_unit_amount' ) ) {
	function awdp_get_wcpa_excluded_unit_amount( $addon_data ) {
		if ( ! is_array( $addon_data ) ) {
			return 0.0;
		}

		$exclude = array_key_exists( 'excludeFromDiscount', $addon_data )
			? (float) $addon_data['excludeFromDiscount']
			: 0.0;

		// Discount product only; leave WCPA addon amounts intact (addonPrice, not totalPrice).
		$addition_settings = get_option( 'awdp_addition_settings' ) ? get_option( 'awdp_addition_settings' ) : array();
		if ( ! empty( $addition_settings['disable_addon'] ) ) {
			$exclude = max( $exclude, awdp_get_wcpa_addon_unit_amount( $addon_data ) );
		}

		return max( 0, $exclude );
	}
}

/**
 * Addon unit to add back for cart/checkout display when pricing is split.
 * Baked-in mode returns 0 (addons already inside the line price).
 *
 * @param array       $cart_item  Cart line.
 * @param array|false $addon_data Optional prefetched addon data.
 * @return float
 */
if ( ! function_exists( 'awdp_get_wcpa_display_addon_unit' ) ) {
	function awdp_get_wcpa_display_addon_unit( $cart_item, $addon_data = null ) {
		if ( null === $addon_data ) {
			$addon_data = awdp_get_wcpa_addon_data( $cart_item );
		}

		// Session / prior-pass fallback when WCPA filter is briefly unavailable.
		if ( ! is_array( $addon_data ) ) {
			if ( ! empty( $cart_item['awdp_wcpa_split'] ) && isset( $cart_item['awdp_wcpa_addon_unit'] ) ) {
				return max( 0, (float) $cart_item['awdp_wcpa_addon_unit'] );
			}
			return 0.0;
		}

		if ( empty( $cart_item['data'] ) || ! is_object( $cart_item['data'] ) ) {
			return 0.0;
		}

		$cart_price = (float) $cart_item['data']->get_price();
		// Prefer pre-discount product unit for split detection after AWDP set_price(0).
		if ( isset( $cart_item['awdp_price_before_discount'] ) ) {
			$probe_price = (float) $cart_item['awdp_price_before_discount'];
		} elseif ( ! empty( $cart_item['awdp_wcpa_split'] ) && array_key_exists( 'productPrice', $addon_data ) ) {
			$probe_price = (float) $addon_data['productPrice'];
		} else {
			$probe_price = $cart_price;
		}

		if ( ! awdp_is_wcpa_split_pricing( $probe_price, $addon_data ) ) {
			return 0.0;
		}

		return awdp_get_wcpa_addon_unit_amount( $addon_data );
	}
}

/**
 * Unit price portion that AWDP may discount when WCPA addon data is present.
 *
 * Without WCPA (or with exclude amount 0), returns $cart_price unchanged so
 * non-WCPA carts keep identical discount math.
 *
 * Split ("No tax for addon"): cart line ≈ productPrice; addon exclusions must not
 * zero the discountable product portion. Baked-in: excluding addonPrice leaves product.
 *
 * @param float       $cart_price  Current cart line unit price (product + baked-in addons).
 * @param array|false $addon_price Result of wcpa_cart_addon_data (false when WCPA inactive).
 * @return float
 */
if ( ! function_exists( 'awdp_get_wcpa_discountable_unit_price' ) ) {
	function awdp_get_wcpa_discountable_unit_price( $cart_price, $addon_price = false ) {
		$cart_price = (float) $cart_price;

		if ( ! is_array( $addon_price ) ) {
			return $cart_price;
		}

		$exclude = awdp_get_wcpa_excluded_unit_amount( $addon_price );

		if ( $exclude <= 0 ) {
			return $cart_price;
		}

		// Split mode: addons are outside the line — do not subtract them from product unit.
		if ( awdp_is_wcpa_split_pricing( $cart_price, $addon_price ) ) {
			$exclude = max( 0, $exclude - awdp_get_wcpa_addon_unit_amount( $addon_price ) );
		}

		if ( $exclude <= 0 ) {
			return $cart_price;
		}

		return max( 0, $cart_price - $exclude );
	}
}

/**
 * Whether a view/AJAX discountedPrice value is a real applied AWDP discount.
 * Empty string must not overwrite WCPA formula/lookup prices.
 *
 * @param mixed $discounted Price from AWDP view helpers.
 * @return bool
 */
if ( ! function_exists( 'awdp_has_applied_discount_price' ) ) {
	function awdp_has_applied_discount_price( $discounted ) {
		return ! ( $discounted === '' || $discounted === null );
	}
}

/**
 * Normalize stored role values to slug strings.
 *
 * @param mixed $roles Role list from rule meta.
 * @return string[]
 */
if ( ! function_exists( 'awdp_normalize_discount_role_slugs' ) ) {
	function awdp_normalize_discount_role_slugs( $roles ) {
		if ( $roles === '' || $roles === null || $roles === false ) {
			return array();
		}

		if ( ! is_array( $roles ) ) {
			$roles = array( $roles );
		}

		$slugs = array();
		foreach ( $roles as $role ) {
			if ( is_array( $role ) && isset( $role['value'] ) ) {
				$role = $role['value'];
			} elseif ( is_object( $role ) && isset( $role->value ) ) {
				$role = $role->value;
			}

			if ( ! is_string( $role ) && ! is_numeric( $role ) ) {
				continue;
			}

			$role = trim( (string) $role );
			if ( $role !== '' ) {
				$slugs[] = $role;
			}
		}

		return array_values( array_unique( $slugs ) );
	}
}

/**
 * WordPress user ID for role-restricted discounts (WP auth, then WooCommerce customer).
 *
 * @return int
 */
if ( ! function_exists( 'awdp_get_discount_user_id' ) ) {
	function awdp_get_discount_user_id() {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $user_id > 0 ) {
			return $user_id;
		}

		if ( function_exists( 'WC' ) && WC() && WC()->customer ) {
			$customer_id = (int) WC()->customer->get_id();
			if ( $customer_id > 0 && function_exists( 'get_userdata' ) && get_userdata( $customer_id ) ) {
				return $customer_id;
			}
		}

		return 0;
	}
}

/**
 * Role slugs assigned to a user, including custom roles stored in capabilities meta.
 *
 * @param int $user_id User ID.
 * @return string[]
 */
if ( ! function_exists( 'awdp_get_user_discount_role_slugs' ) ) {
	function awdp_get_user_discount_role_slugs( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! function_exists( 'get_userdata' ) ) {
			return array();
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return array();
		}

		$roles = is_array( $user->roles ) ? $user->roles : array();

		// Custom roles (e.g. Members) can exist in usermeta caps but be missing from $user->roles.
		if ( ! empty( $user->caps ) && is_array( $user->caps ) ) {
			foreach ( $user->caps as $cap => $granted ) {
				if ( $granted ) {
					$roles[] = $cap;
				}
			}
		}

		return awdp_normalize_discount_role_slugs( $roles );
	}
}

/**
 * Role slugs for the customer in the current request.
 *
 * @return string[]
 */
if ( ! function_exists( 'awdp_get_current_discount_user_roles' ) ) {
	function awdp_get_current_discount_user_roles() {
		return awdp_get_user_discount_role_slugs( awdp_get_discount_user_id() );
	}
}

/**
 * Whether a rule's registered-customer / user-role restriction allows the current customer.
 *
 * Evaluated at apply time so cart/checkout (including Store API) is not stuck with an
 * empty role snapshot from an earlier unauthenticated load_rules() call.
 *
 * @param array $rule Discount rule.
 * @return bool
 */
if ( ! function_exists( 'awdp_user_qualifies_for_discount_rule' ) ) {
	function awdp_user_qualifies_for_discount_rule( $rule ) {
		if ( ! is_array( $rule ) || intval( isset( $rule['discount_reg_customers'] ) ? $rule['discount_reg_customers'] : 0 ) !== 1 ) {
			return true;
		}

		$user_id = awdp_get_discount_user_id();
		if ( $user_id <= 0 ) {
			return false;
		}

		$selected_roles = awdp_normalize_discount_role_slugs( isset( $rule['discount_reg_user_roles'] ) ? $rule['discount_reg_user_roles'] : array() );
		if ( empty( $selected_roles ) ) {
			return true;
		}

		$user_roles = awdp_get_user_discount_role_slugs( $user_id );

		return ! empty( array_intersect( $user_roles, $selected_roles ) );
	}
}

/**
 * Whether the regular price should be used as the struck-through original
 * when a plugin discount is displayed. Display only — does not change calculations.
 *
 * @return bool
 */
if ( ! function_exists( 'awdp_use_regular_as_strikeout' ) ) {
	function awdp_use_regular_as_strikeout() {
		$addition_settings = get_option( 'awdp_addition_settings' ) ? get_option( 'awdp_addition_settings' ) : array();

		return ! empty( $addition_settings['use_regular'] );
	}
}

/**
 * Pick the displayed original/struck-through amount.
 *
 * Default uses the sale/catalog fallback (price before the plugin discount).
 * When the setting is enabled and a higher regular price exists, that regular
 * price is shown instead. Calculations are not changed.
 *
 * @param float|int|string      $fallback    Sale / pre-discount amount.
 * @param float|int|string|null $regular     Catalog regular price, or empty.
 * @param bool                  $use_regular Whether the regular-price setting is on.
 * @return float
 */
if ( ! function_exists( 'awdp_resolve_strikeout_original' ) ) {
	function awdp_resolve_strikeout_original( $fallback, $regular, $use_regular ) {
		$fallback = (float) $fallback;

		if ( ! $use_regular ) {
			return $fallback;
		}

		if ( $regular === '' || $regular === false || $regular === null ) {
			return $fallback;
		}

		$regular = (float) $regular;
		if ( $regular > $fallback + 0.0001 ) {
			return $regular;
		}

		return $fallback;
	}
}

/**
 * Tax-adjusted regular price for shop/product HTML when the strikeout setting is on.
 * Returns '' so callers keep the sale/catalog original.
 *
 * @param WC_Product $product Product or variation being displayed.
 * @return float|string
 */
if ( ! function_exists( 'awdp_get_product_strikeout_display_price' ) ) {
	function awdp_get_product_strikeout_display_price( $product ) {
		if ( ! awdp_use_regular_as_strikeout() || ! $product || ! is_object( $product ) ) {
			return '';
		}

		if ( ! is_callable( array( $product, 'get_regular_price' ) ) ) {
			return '';
		}

		$regular = $product->get_regular_price();
		if ( $regular === '' || $regular === null ) {
			return '';
		}

		$sale = is_callable( array( $product, 'get_sale_price' ) ) ? $product->get_sale_price() : '';
		if ( $sale === '' || $sale === false || (float) $regular <= (float) $sale ) {
			return '';
		}

		$tax_display_mode = get_option( 'woocommerce_tax_display_shop' );
		$display          = ( 'incl' === $tax_display_mode )
			? wc_get_price_including_tax( $product, array( 'price' => $regular ) )
			: wc_get_price_excluding_tax( $product, array( 'price' => $regular ) );

		return $display ? $display : $regular;
	}
}

/**
 * Cart/checkout unit used as the struck-through original (display only).
 *
 * @param array            $cart_item Cart line.
 * @param float|int|string $fallback  awdp_price_before_discount / sale unit.
 * @return float
 */
if ( ! function_exists( 'awdp_get_cart_item_strikeout_unit_price' ) ) {
	function awdp_get_cart_item_strikeout_unit_price( $cart_item, $fallback ) {
		$product = ( isset( $cart_item['data'] ) && is_object( $cart_item['data'] ) ) ? $cart_item['data'] : null;
		$regular = ( $product && is_callable( array( $product, 'get_regular_price' ) ) )
			? $product->get_regular_price()
			: '';

		return awdp_resolve_strikeout_original( $fallback, $regular, awdp_use_regular_as_strikeout() );
	}
}

/**
 * Format an amount the way Store API money fields expect (integer string).
 *
 * Top-level prices use currency_minor_unit (usually 2). raw_prices use
 * wc_get_rounding_precision() (usually 6). Using the wrong scale makes
 * block cart/checkout strike the wrong original.
 *
 * @param float|int|string $amount   Decimal amount.
 * @param int              $decimals Scale used by the target field.
 * @return string
 */
if ( ! function_exists( 'awdp_store_api_format_money' ) ) {
	function awdp_store_api_format_money( $amount, $decimals ) {
		$decimals = (int) $decimals;
		if ( $decimals < 0 ) {
			$decimals = 0;
		}

		return (string) (int) round( (float) $amount * pow( 10, $decimals ) );
	}
}

/**
 * Cart/checkout display amount using WooCommerce cart tax display (not shop).
 *
 * @param WC_Product       $product Product or variation on the cart line.
 * @param float|int|string $price   Catalog/unit amount to convert.
 * @param float|int        $qty     Quantity (1 for unit price).
 * @return float
 */
if ( ! function_exists( 'awdp_get_cart_tax_display_amount' ) ) {
	function awdp_get_cart_tax_display_amount( $product, $price, $qty = 1 ) {
		$price = (float) $price;
		$qty   = (float) $qty;
		if ( $qty <= 0 ) {
			$qty = 1;
		}

		if ( ! $product || ! is_object( $product ) || ! function_exists( 'wc_get_price_including_tax' ) ) {
			return $price * $qty;
		}

		$args = array(
			'price' => $price,
			'qty'   => $qty,
		);

		$incl = ( function_exists( 'WC' ) && WC()->cart )
			? WC()->cart->display_prices_including_tax()
			: ( get_option( 'woocommerce_tax_display_cart' ) === 'incl' );

		if ( $incl ) {
			return (float) wc_get_price_including_tax( $product, $args );
		}

		return (float) wc_get_price_excluding_tax( $product, $args );
	}
}
