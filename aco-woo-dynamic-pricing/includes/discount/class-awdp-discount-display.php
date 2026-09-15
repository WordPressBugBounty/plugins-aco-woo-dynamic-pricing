<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-awdp-discount-module.php';

class AWDP_Discount_Display extends AWDP_Discount_Module
{

    public function show_pricing_table(){

        // Load discount rules
        $this->owner->rules->load_rules();
        
        // Check if discount is active
        if ( $this->owner->discount_rules == null )
            return ''; // Exit if no rules 

        // Load Product List
        $this->owner->rules->set_product_list();

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

        $rules                      = $this->owner->discount_rules;
        $price                      = $priceIncTax ? $priceIncTax : $cartPrice;
        $prodLists                  = $this->owner->product_lists;
        $variations                 = $this->owner->variations;
        $discountedPrice            = $this->owner->discountProductPrice; 
        $discountProductMaxPrice    = $this->owner->discountProductMaxPrice;
        $discountProductMinPrice    = $this->owner->discountProductMinPrice; 

        // Variable products often have an empty parent price — use variation price range.
        if ( ( $price == '' || $price == 0 ) && $product->is_type( 'variable' ) ) {
            $variation_price_map = $product->get_variation_prices( true );
            if ( ! empty( $variation_price_map['price'] ) ) {
                $price = (float) min( $variation_price_map['price'] );
                if ( ! $discountProductMinPrice ) {
                    $discountProductMinPrice = (float) min( $variation_price_map['price'] );
                }
                if ( ! $discountProductMaxPrice ) {
                    $discountProductMaxPrice = (float) max( $variation_price_map['price'] );
                }
            }
        }

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
        $this->owner->rules->load_rules();
        
        // Check if discount is active
        if ( $this->owner->discount_rules == null )
            return $item_price; // Exit if no rules 

        // Load Product List
        $this->owner->rules->set_product_list();

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
        
        // Display-only original/struck-through price (sale by default; regular when setting is on).
        $display_price      = awdp_get_product_strikeout_display_price( $product );

        // if( $this->owner->converted_rate == '' && $item->get_ID() != '' ) {
        //     $this->owner->converted_rate = $this->owner->utilities->get_con_unit($item, $price, true);
        // }

        if ( $price == '' || $price == 0 ) return $item_price;

        $viewPrice = call_user_func_array ( 
            array ( new AWDP_viewProductPrice(), 'product_price' ), 
            array ( $rules, $price, $post_id, $product, $prodLists, $cartRules, $item_price, $display_price ) 
        );

        if ( is_array ( $viewPrice ) ) {
            $updatedPrice                   = $viewPrice['itemPrice'];
            $this->owner->discountProductPrice     = $viewPrice['discountedPrice'];
            $this->owner->discountProductMaxPrice  = $viewPrice['discountedMaxPrice'];
            $this->owner->discountProductMinPrice  = $viewPrice['discountedMinPrice'];
        }

        return $updatedPrice ? $updatedPrice : $item_price; 

    }

    // WCPA Get Variation Price

    public function show_offer_message(){

        global $product;
        if ( ! is_a( $product, 'WC_Product' ) ) {
            return '';
        }

        $offer_desc_config      = get_option('awdp_disc_desc_config') ? get_option('awdp_disc_desc_config') : [];
        $offerMsgEnable         = array_key_exists ( 'enable_dismessage', $offer_desc_config ) ? $offer_desc_config['enable_dismessage'] : '';
        if ( ! $offerMsgEnable ) {
            return '';
        }

        // Load discount rules
        $this->owner->rules->load_rules();
        
        // Check if discount is active
        if ( $this->owner->discount_rules == null )
            return ''; // Exit if no rules 

        // Load Product List
        $this->owner->rules->set_product_list();

        $productid              = $product->get_id();
        $result                 = '';
        $offer_rule             = array_key_exists ( 'dismessage_rule', $offer_desc_config ) ? $offer_desc_config['dismessage_rule'] : ''; 

        $checkML                = call_user_func ( array ( new AWDP_ML(), 'is_default_lan' ), '' );
        $currentLang            = !$checkML ? call_user_func ( array ( new AWDP_ML(), 'current_language' ), '' ) : '';
        $langSettings           = get_option('awdp_settings_lang_options') ? get_option('awdp_settings_lang_options') : [];

        /*
        * @ver 4.1.7
        * Offer description for all active rules (earlier version displays description on all products)
        */
        $show_description = false;
        if ( !empty( $this->owner->discount_rules ) ) {
            foreach ( $this->owner->discount_rules as $rule ) {
                if ( $offer_rule != '' && $offer_rule != 'all_active' && $rule['id'] != $offer_rule ) {
                    continue;
                }

                // Check if this rule is applicable to the current product
                $checkItem = $this->owner->rules->get_items_to_apply_discount ( $product, $rule );
                if ( $checkItem ) {
                    if ( ! awdp_user_qualifies_for_discount_rule( $rule ) ) {
                        continue;
                    }

                    // A valid active discount rule is applicable to this product, show description
                    $show_description = true;
                    break;
                }
            }
        }

        if ( $show_description ) {
 
            // $productlist    = ( $offer_rule != '' && $offer_rule != 'all_active' ) ? ( get_post_meta ( $offer_rule, 'discount_product_list', true ) ) : '';
            // // Check in product list           
            // if ( '' == $productlist || 0 == $productlist || $all_prods == true || ( !empty ( $list_products ) && in_array ( $productid, $list_products ) ) || ( isset ( $this->owner->product_lists[$productlist] ) && in_array ( $productid, $this->owner->product_lists[$productlist] ) ) ) {

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
                $offer_fontsize         = ( array_key_exists ( 'dismessage_fontsize', $offer_desc_config ) && $offer_desc_config['dismessage_fontsize'] !== '' ) ? $offer_desc_config['dismessage_fontsize'] : 12;
                $offer_paddding_lm      = ( array_key_exists ( 'dismessage_paddding_lm', $offer_desc_config ) && $offer_desc_config['dismessage_paddding_lm'] !== '' ) ? $offer_desc_config['dismessage_paddding_lm'] : 10;
                $offer_paddding_tp      = ( array_key_exists ( 'dismessage_paddding_tp', $offer_desc_config ) && $offer_desc_config['dismessage_paddding_tp'] !== '' ) ? $offer_desc_config['dismessage_paddding_tp'] : 10;
                $offer_radius           = ( array_key_exists ( 'dismessage_radius', $offer_desc_config ) && $offer_desc_config['dismessage_radius'] !== '' ) ? $offer_desc_config['dismessage_radius'] : 0;
                $offer_color            = array_key_exists ( 'dismessage_color', $offer_desc_config ) ? $offer_desc_config['dismessage_color'] : '';
                $offer_background       = array_key_exists ( 'dismessage_background', $offer_desc_config ) ? $offer_desc_config['dismessage_background'] : ''; 

                $border_top_width       = ( array_key_exists ( 'border_top_width', $offer_desc_config ) && $offer_desc_config['border_top_width'] !== '' ) ? $offer_desc_config['border_top_width'].'px' : '0px'; 
                $border_right_width     = ( array_key_exists ( 'border_right_width', $offer_desc_config ) && $offer_desc_config['border_right_width'] !== '' ) ? ' '.$offer_desc_config['border_right_width'].'px' : ' 0px'; 
                $border_bottom_width    = ( array_key_exists ( 'border_bottom_width', $offer_desc_config ) && $offer_desc_config['border_bottom_width'] !== '' ) ? ' '.$offer_desc_config['border_bottom_width'].'px' : ' 0px'; 
                $border_left_width      = ( array_key_exists ( 'border_left_width', $offer_desc_config ) && $offer_desc_config['border_left_width'] !== '' ) ? ' '.$offer_desc_config['border_left_width'].'px' : ' 0px'; 
                $offer_border_color     = array_key_exists ( 'offer_border_color', $offer_desc_config ) ? $offer_border_color_tmp = $offer_desc_config['offer_border_color'] : ''; 
                $offer_border_color     = isset($offer_border_color_tmp) ? $offer_border_color_tmp : '';

                $customStyle            = 'display: inline-block; font-size: '.$offer_fontsize.'px;padding: '.$offer_paddding_tp.'px '.$offer_paddding_lm.'px;border-radius: '.$offer_radius.'px;';
                $customStyle           .= $offer_color ? 'color: '.$offer_color.';' : '';
                $customStyle           .= $offer_background ? 'background: '.$offer_background.';' : ''; 
                $customStyle           .= $offer_border_color ? 'border-color: '.$offer_border_color.';' : ''; 
                $customStyle           .= ( $border_top_width || $border_right_width || $border_bottom_width || $border_left_width ) ? 'border-width: '.$border_top_width.$border_right_width.$border_bottom_width.$border_left_width.';' : ''; 

                $result                 = '<div class="awdpOfferMsg" style="display:none;"><div style="'.$customStyle.'">'.$offer_desc.'</div></div>';

            // }

        }

        echo $result;
 
    }

    /**
     * Total Dynamic Pricing savings for the current cart (line-price + coupon discounts).
     *
     * @return float
     */
    public function get_cart_saved_amount()
    {
        $total = 0.0;

        if ( empty( $this->owner->discounts ) || ! WC()->cart ) {
            return $total;
        }

        foreach ( $this->owner->discounts as $ruleid => $discounts ) {
            if ( empty( $discounts['discounts'] ) || ! is_array( $discounts['discounts'] ) ) {
                continue;
            }

            $discount_type = isset( $discounts['discount_type'] ) ? $discounts['discount_type'] : '';

            foreach ( $discounts['discounts'] as $discount ) {
                if ( ! isset( $discount['discount'] ) || $discount['discount'] === '' || $discount['discount'] === null ) {
                    continue;
                }

                $calc_discount = AWDP_Discount_Application::normalize_discount_amount( $discount['discount'] );

                // Per-unit product discounts are stored per item; multiply by line qty for the order total saved.
                if ( AWDP_Discount_Application::coupon_amount_is_per_unit( $discount_type, $ruleid ) ) {
                    $qty           = isset( $discount['quantity'] ) ? (int) $discount['quantity'] : 1;
                    $calc_discount = $calc_discount * max( 1, $qty );
                }

                $total += $calc_discount;
            }
        }

        return (float) $total;
    }

    // Cart Message

    public function wdpCartMessage(){

        if ( ! WC()->cart ) {
            return;
        }

        $customStyle = '';
        $result      = '';

        $checkML      = call_user_func( array( new AWDP_ML(), 'is_default_lan' ), '' );
        $currentLang  = ! $checkML ? call_user_func( array( new AWDP_ML(), 'current_language' ), '' ) : '';
        $langSettings = get_option( 'awdp_settings_lang_options' ) ? get_option( 'awdp_settings_lang_options' ) : array();

        // Include line-price discounts (no virtual coupon) and coupon-based discounts.
        $coupons_amount          = $this->get_cart_saved_amount();
        $custom_message_settings = get_option( 'awdp_custom_msg_settings' ) ? get_option( 'awdp_custom_msg_settings' ) : array();
        $custom_message_status   = array_key_exists( 'custom_message_status', $custom_message_settings ) ? $custom_message_settings['custom_message_status'] : false;

        if ( $coupons_amount > 0 && $custom_message_status ) {

            /*
            * ver @ 4.4.8
            * WMPL Support custom message
            */
            if ( ! empty( $langSettings ) && array_key_exists( $currentLang, $langSettings ) ) {
                $custom_message = array_key_exists( 'custom_message', $langSettings[ $currentLang ] ) ? $langSettings[ $currentLang ]['custom_message'] : ( array_key_exists( 'custom_message', $custom_message_settings ) ? $custom_message_settings['custom_message'] : '' );
            } else {
                $custom_message = array_key_exists( 'custom_message', $custom_message_settings ) ? $custom_message_settings['custom_message'] : '';
            }

            $custom_message_linheight          = array_key_exists( 'custom_message_linheight', $custom_message_settings ) ? $custom_message_settings['custom_message_linheight'] : '';
            $custom_message_fontsize           = array_key_exists( 'custom_message_fontsize', $custom_message_settings ) ? $custom_message_settings['custom_message_fontsize'] : '';
            $custom_message_position           = array_key_exists( 'custom_message_position', $custom_message_settings ) ? $custom_message_settings['custom_message_position'] : '';
            $custom_message_paddding_lm        = array_key_exists( 'custom_message_paddding_lm', $custom_message_settings ) ? $custom_message_settings['custom_message_paddding_lm'] : '';
            $custom_message_paddding_tp        = array_key_exists( 'custom_message_paddding_tp', $custom_message_settings ) ? $custom_message_settings['custom_message_paddding_tp'] : '';
            $custom_message_border_radius      = array_key_exists( 'custom_message_border_radius', $custom_message_settings ) ? $custom_message_settings['custom_message_border_radius'] : '';
            $custom_message_border_top_width   = array_key_exists( 'custom_message_border_top_width', $custom_message_settings ) ? $custom_message_settings['custom_message_border_top_width'] . 'px ' : '';
            $custom_message_border_right_width = array_key_exists( 'custom_message_border_right_width', $custom_message_settings ) ? $custom_message_settings['custom_message_border_right_width'] . 'px ' : '';
            $custom_message_border_bottom_width = array_key_exists( 'custom_message_border_bottom_width', $custom_message_settings ) ? $custom_message_settings['custom_message_border_bottom_width'] . 'px ' : '';
            $custom_message_border_left_width  = array_key_exists( 'custom_message_border_left_width', $custom_message_settings ) ? $custom_message_settings['custom_message_border_left_width'] . 'px' : '';
            $custom_message_border_color       = array_key_exists( 'custom_message_border_color', $custom_message_settings ) ? $custom_message_settings['custom_message_border_color'] : '';
            $custom_message_background         = array_key_exists( 'custom_message_background', $custom_message_settings ) ? $custom_message_settings['custom_message_background'] : '';
            $custom_message_color              = array_key_exists( 'custom_message_color', $custom_message_settings ) ? $custom_message_settings['custom_message_color'] : '';

            $customStyle .= 'font-size: ' . $custom_message_fontsize . 'px;padding: ' . $custom_message_paddding_tp . 'px ' . $custom_message_paddding_lm . 'px;border-radius: ' . $custom_message_border_radius . 'px;';
            $customStyle .= $custom_message_linheight ? 'line-height: ' . $custom_message_linheight . ( is_numeric( $custom_message_linheight ) && $custom_message_linheight > 3 ? 'px' : '' ) . ';' : '';
            $customStyle .= $custom_message_color ? 'color: ' . $custom_message_color . ';' : '';
            $customStyle .= $custom_message_background ? 'background: ' . $custom_message_background . ';' : '';
            $customStyle .= $custom_message_border_color ? 'border-color: ' . $custom_message_border_color . ';' : '';
            $customStyle .= $custom_message_position ? 'text-align: ' . $custom_message_position . ';' : '';
            $customStyle .= ( $custom_message_border_top_width || $custom_message_border_right_width || $custom_message_border_bottom_width || $custom_message_border_left_width ) ? 'border-width: ' . $custom_message_border_top_width . $custom_message_border_right_width . $custom_message_border_bottom_width . $custom_message_border_left_width . ';' : '';

            $message = $custom_message ? str_replace( '[discount]', wc_price( $coupons_amount ), $custom_message ) : __( "You've saved ", 'aco-woo-dynamic-pricing' ) . wc_price( $coupons_amount ) . __( ' on this order', 'aco-woo-dynamic-pricing' );
            $result  = '<div class="wdp_save_text" style="' . $customStyle . '">' . nl2br( $message ) . '</div>';
            echo $result;
        }

    }

    /**
     * Whether a product-page quantity falls inside a pricing-table tier.
     * Open-ended tiers (empty end) match qty >= start. Does not apply a tier
     * when qty is below start or above a closed end.
     */
    protected function quantity_matches_display_tier( $qty, $discount )
    {
        if ( ! is_object( $discount ) ) {
            return false;
        }

        $start = isset( $discount->start_range ) ? (int) $discount->start_range : 0;
        if ( $start <= 0 ) {
            return false;
        }

        $end_raw = isset( $discount->end_range ) ? $discount->end_range : '';
        $end     = ( $end_raw === '' || $end_raw === null ) ? 0 : (int) $end_raw;

        if ( $end <= 0 ) {
            return $qty >= $start;
        }

        if ( $start === $end ) {
            return $qty === $start;
        }

        return $qty >= $start && $qty <= $end;
    }

    /**
     * Catalog display price for the product-page "Your Price" box.
     */
    protected function resolve_display_unit_price( $posted_price, $product_id, $variation_id )
    {
        if ( $variation_id ) {
            $variation = wc_get_product( $variation_id );
            if ( $variation && $variation->exists() ) {
                $parent_id = (int) $variation->get_parent_id();
                if ( ! $product_id || ! $parent_id || $parent_id === (int) $product_id ) {
                    $resolved = (float) wc_get_price_to_display( $variation );
                    if ( $resolved > 0 ) {
                        return $resolved;
                    }
                }
            }
        }

        if ( $product_id ) {
            $product = wc_get_product( $product_id );
            if ( $product && $product->exists() && ! $product->is_type( 'variable' ) ) {
                $resolved = (float) wc_get_price_to_display( $product );
                if ( $resolved > 0 ) {
                    return $resolved;
                }
            }
        }

        return (float) $posted_price;
    }

    // Currency 

    public function wdpDynamicPricingTable() {
    
        // Verify nonce to prevent CSRF and unauthenticated XSS attacks.
        check_ajax_referer( 'awdpnonce', 'nonce' );

        $type               = array_key_exists ( 'type', $_POST ) ? sanitize_text_field( $_POST['type'] ) : '';
        $ProdID             = array_key_exists ( 'ProdID', $_POST ) ? absint( $_POST['ProdID'] ) : 0;
        $ProdPrice          = array_key_exists ( 'ProdPrice', $_POST ) ? floatval( $_POST['ProdPrice'] ) : 0;
        $ProdQty            = array_key_exists ( 'ProdQty', $_POST ) ? absint( $_POST['ProdQty'] ) : 0;
        $Rule               = array_key_exists ( 'Rule', $_POST ) ? absint( $_POST['Rule'] ) : 0;
        $RPrice             = array_key_exists ( 'price', $_POST ) ? floatval( $_POST['price'] ) : 0;
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

                // Prefer WooCommerce's variation display price over a client-parsed value.
                if ( ! empty( $_POST['variation_id'] ) ) {
                    $variation_product = wc_get_product( absint( $_POST['variation_id'] ) );
                    if ( $variation_product && $variation_product->exists() ) {
                        $resolved_price = (float) wc_get_price_to_display( $variation_product );
                        if ( $resolved_price > 0 ) {
                            $var_price = $resolved_price;
                        }
                    }
                }

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
                            $tr_qn .= '<td>' . esc_html( $quantity_rule->start_range ) . '</td>';
                        } 
                        else if ($quantity_rule->end_range) {
                            $tr_qn .= '<td>' . esc_html( $quantity_rule->start_range ) . ' - ' . esc_html( $quantity_rule->end_range ) . '</td>';
                        } else {
                            $tr_qn .= '<td>' . esc_html( $quantity_rule->start_range ) . ' +</td>';
                        }
                        $tr_pr .= '<td>' . $discounted_new_price_bt . '</td>';
                        if ( $value_display == 'discount_both' ) {
                            $tr_nw .= '<td>' . $discounted_new_price . '</td>';
                        }
                    } else {
                        if ($quantity_rule->start_range == $quantity_rule->end_range) {
                            if ( $value_display == 'discount_value' ) {
                                $table .= '<tr><td>' . esc_html( $quantity_rule->start_range ) . '</td><td>' . $discounted_new_price_bt . '</td></tr>';
                            } else if ( $value_display == 'discount_both' ) {
                                $table .= '<tr><td>' . esc_html( $quantity_rule->start_range ) . '</td><td>' . $discounted_new_price_bt . '</td><td>' . $discounted_new_price . '</td></tr>';
                            } else {
                                $table .= '<tr><td>' . esc_html( $quantity_rule->start_range ) . '</td><td>' . $discounted_new_price . '</td></tr>';
                            }
                        } else if ($quantity_rule->end_range) {
                            if ( $value_display == 'discount_value' ) {
                                $table .= '<tr><td>' . esc_html( $quantity_rule->start_range ) . '</td><td>' . $discounted_new_price_bt . '</td></tr>';
                            } else if ( $value_display == 'discount_both' ) {
                                $table .= '<tr><td>' . esc_html( $quantity_rule->start_range ) . ' - ' . esc_html( $quantity_rule->end_range ) . '</td><td>' . $discounted_new_price_bt . '</td><td>' . $discounted_new_price . '</td></tr>';
                            } else {
                                $table .= '<tr><td>' . esc_html( $quantity_rule->start_range ) . ' - ' . esc_html( $quantity_rule->end_range ) . '</td><td>' . $discounted_new_price . '</td></tr>';
                            }
                        } else {
                            if ( $value_display == 'discount_value' ) {
                                $table .= '<tr><td>' . esc_html( $quantity_rule->start_range ) . '</td><td>' . $discounted_new_price_bt . '</td></tr>';
                            } else if ( $value_display == 'discount_both' ) {
                                $table .= '<tr><td>' . esc_html( $quantity_rule->start_range ) . ' +</td><td>' . $discounted_new_price_bt . '</td><td>' . $discounted_new_price . '</td></tr>';
                            } else {
                                $table .= '<tr><td>' . esc_html( $quantity_rule->start_range ) . ' +</td><td>' . $discounted_new_price . '</td></tr>';
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

            $variation_id = array_key_exists( 'variation_id', $_POST ) ? absint( $_POST['variation_id'] ) : 0;
            $unit_price   = $this->resolve_display_unit_price( $ProdPrice, $ProdID, $variation_id );
            $qty          = max( 0, $ProdQty );
            $decimals     = wc_get_price_decimals();
            $display_unit = $unit_price;

            if ( $DisData && $qty > 0 && $unit_price > 0 ) {
                $tiers = is_array( $DisData ) ? $DisData : array();
                usort( $tiers, function ( $a, $b ) {
                    return (int) $a->start_range - (int) $b->start_range;
                } );

                foreach ( $tiers as $discount ) {
                    if ( ! $this->quantity_matches_display_tier( $qty, $discount ) ) {
                        continue;
                    }

                    if ( isset( $discount->dis_type ) && $discount->dis_type === 'fixed' ) {
                        $display_unit = max( 0, $unit_price - (float) $discount->dis_value );
                    } else {
                        $display_unit = max( 0, $unit_price - ( $unit_price * ( (float) $discount->dis_value / 100 ) ) );
                    }
                    break;
                }
            }

            $result['price']      = round( (float) $display_unit, $decimals );
            $result['total']      = round( $result['price'] * $qty, $decimals );
            $result['price_html'] = wc_price( $result['price'] );
            $result['total_html'] = wc_price( $result['total'] );
            $result['currency']   = get_woocommerce_currency_symbol();

            echo ! empty( $result ) ? wp_json_encode( $result ) : '';

        }
        die();

    }


    // Get variations 

}
