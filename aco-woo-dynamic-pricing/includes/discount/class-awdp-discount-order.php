<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-awdp-discount-module.php';

class AWDP_Discount_Order extends AWDP_Discount_Module
{

    /**
     * Order item meta keys stored internally (not shown in the default meta list).
     *
     * @return string[]
     */
    public static function hidden_order_item_meta_keys()
    {
        return apply_filters('awdp_hidden_order_item_meta_keys', array(
            '_awdp_discount_details',
            '_awdp_original_unit_price',
        ));
    }

    public function wdpOrderMeta($item_id, $values, $cart_item_key)
    {
        $wdpDiscount = $this->owner->wdp_order_meta ? $this->owner->wdp_order_meta : [];

        if (!is_object($values) || !isset($values->legacy_values)) {
            return;
        }

        $cart_values = $values->legacy_values;

        if (!isset($cart_values['key']) || !array_key_exists($cart_values['key'], $wdpDiscount)) {
            return;
        }

        $details = $wdpDiscount[$cart_values['key']] ? $wdpDiscount[$cart_values['key']] : [];

        wc_add_order_item_meta($item_id, '_awdp_discount_details', $details);

        if (isset($cart_values['awdp_price_before_discount'])) {
            wc_add_order_item_meta($item_id, '_awdp_original_unit_price', (float) $cart_values['awdp_price_before_discount']);
        }
    }

    /**
     * Single, de-duplicated discount note under the line item in admin/emails.
     */
    public function wdpDisplayOrderMeta($item_id, $item, $product)
    {
        if (!$product) {
            return;
        }

        $details = wc_get_order_item_meta($item_id, '_awdp_discount_details', true);

        if (empty($details) || !is_array($details)) {
            return;
        }

        $html = $this->render_order_item_discount_html($item_id, $product->get_id(), $details);

        if ($html === '') {
            return;
        }

        echo '<div class="wc-order-item-awdp-discount" style="color:#646970;font-size:12px;margin-top:4px;">';
        echo wp_kses_post($html);
        echo '</div>';
    }


    /**
     * Builds one consolidated HTML snippet for all AWDP rules on this line.
     *
     * @param int   $item_id    Order item ID.
     * @param int   $product_id Product ID.
     * @param array $details    Stored discount details.
     */
    protected function render_order_item_discount_html($item_id, $product_id, $details)
    {
        $parts = array();

        foreach ($details as $detail) {
            if (!is_array($detail)) {
                continue;
            }

            $bogo_gift = $this->render_bogo_gift_html($product_id, $detail);

            if ($bogo_gift !== '') {
                $parts[] = $bogo_gift;
            }
        }

        $line_price_html = $this->render_line_price_summary_html($item_id, $details);

        if ($line_price_html !== '') {
            $parts[] = $line_price_html;
        }

        return implode('<br/>', array_filter($parts));
    }


    /**
     * BOGO / gift lines (unchanged behaviour, one block per matching rule).
     */
    protected function render_bogo_gift_html($product_id, $detail)
    {
        $html = '';

        if (($detail['type'] ?? '') === 'bogo' && !empty($detail['bogo'])) {
            foreach ($detail['bogo'] as $orderBogo) {
                if ((int) $orderBogo['product_id'] !== (int) $product_id) {
                    continue;
                }

                if (($orderBogo['discount_type'] ?? '') === 'free') {
                    $html .= '<strong>' . esc_html($orderBogo['offer_items']) . ' × ' . esc_html__('Free', 'aco-woo-dynamic-pricing') . '</strong>';
                } else {
                    $html .= '<strong>' . esc_html($orderBogo['offer_items']) . ' × ' . esc_html($orderBogo['discounted_price_single']) . '</strong>';
                }
            }
        }

        if (($detail['type'] ?? '') === 'gift' && !empty($detail['gift'])) {
            foreach ($detail['gift'] as $orderGift) {
                if ((int) $orderGift['product_id'] === (int) $product_id) {
                    $html .= '<strong>' . esc_html__('1 × Free', 'aco-woo-dynamic-pricing') . '</strong>';
                }
            }
        }

        return $html;
    }


    /**
     * One sale-price line for product-level (line_price) discounts.
     */
    protected function render_line_price_summary_html($item_id, $details)
    {
        $original_unit = $this->get_original_unit_price($item_id, $details);
        $final_precise = $this->get_final_line_price_from_details($details);

        if ($final_precise === null) {
            return '';
        }

        $final_unit = (float) wc_remove_number_precision($final_precise);

        if ($final_unit === 0.0) {
            return '<strong>' . esc_html__('Free (dynamic pricing)', 'aco-woo-dynamic-pricing') . '</strong>';
        }

        if ($original_unit !== null && $final_unit < $original_unit) {
            return sprintf(
                '<span class="awdp-order-unit-price">%s: %s</span>',
                esc_html__('Unit price', 'aco-woo-dynamic-pricing'),
                wc_format_sale_price($original_unit, $final_unit)
            );
        }

        return sprintf(
            '<span class="awdp-order-unit-price">%s: %s</span>',
            esc_html__('Discounted unit price', 'aco-woo-dynamic-pricing'),
            wp_kses_post(wc_price($final_unit))
        );
    }


    /**
     * @param int   $item_id Order item ID.
     * @param array $details Discount details.
     */
    protected function get_original_unit_price($item_id, $details)
    {
        $stored = wc_get_order_item_meta($item_id, '_awdp_original_unit_price', true);

        if ($stored !== '' && $stored !== false) {
            return (float) $stored;
        }

        foreach ($details as $detail) {
            if (!empty($detail['originalPrice'])) {
                return (float) wc_remove_number_precision($detail['originalPrice']);
            }
        }

        return null;
    }


    /**
     * Last applied line-price unit amount from stored rule entries.
     *
     * @param array $details Discount details.
     */
    protected function get_final_line_price_from_details($details)
    {
        $final = null;

        foreach ($details as $detail) {
            if (!is_array($detail)) {
                continue;
            }

            if (($detail['application'] ?? '') !== 'line_price') {
                continue;
            }

            if (!isset($detail['discountedPrice']) || $detail['discountedPrice'] === '') {
                continue;
            }

            if (in_array($detail['type'] ?? '', array('gift', 'bogo'), true)) {
                continue;
            }

            $final = $detail['discountedPrice'];
        }

        return $final;
    }


    public function wdpAdminOrderHeader($order)
    {

        $items              = $order->get_items();
        $discount_status    = false;
        foreach ($items as $key => $val) {
            if (wc_get_order_item_meta($key, '_awdp_discount_details', true)) {
                $discount_status = true;
                continue;
            }
        }
        if ($discount_status) {
            echo '<th class="line_wdpdata sortable" data-sort="your-sort-option" style="display:none">Dynamic Pricing Total</th>';
        }

    }


    public function wdpAdminOrderContent($_product, $item, $itemid = null)
    {

        // Exit if NULL
        if (!$_product || $_product == NULL) {
            return;
        }

        $item_id                = $item ? $item->get_id() : '';
        $data                   = $item ? $item->get_data() : [];
        $subtotal               = (!empty($data) && array_key_exists('subtotal', $data)) ? $data['subtotal'] : '';
        $discount               = 0;
        $quantity               = (!empty($data) && array_key_exists('quantity', $data)) ? $data['quantity'] : '';
        $wdp_discount_details   = wc_get_order_item_meta($item_id, '_awdp_discount_details', true) ? wc_get_order_item_meta($item_id, '_awdp_discount_details', true) : false;

        if ($wdp_discount_details) {

            $prdID      = $_product->get_ID();
            foreach ($wdp_discount_details as $wdp_discount_detail) {
                if ($wdp_discount_detail['type'] === 'bogo') {
                    if ($wdp_discount_detail['bogo']) {
                        foreach ($wdp_discount_detail['bogo'] as $orderBogo) {
                            if ($orderBogo['product_id'] == $prdID) {
                                $discount += $orderBogo['discount'];
                                $quantity = $quantity - $orderBogo['offer_items'];
                            }
                        }
                    }
                }
                if ($wdp_discount_detail['type'] === 'gift') {
                    if ($wdp_discount_detail['gift']) {
                        foreach ($wdp_discount_detail['gift'] as $orderGift) {
                            if ($orderGift['product_id'] == $prdID) {
                                $discount += $orderGift['discount'];
                                $quantity = $quantity - 1;
                            }
                        }
                    }
                }
                if ($wdp_discount_detail['discountedPrice'] && !($wdp_discount_detail['type'] === 'gift' || $wdp_discount_detail['type'] === 'bogo')) {
                    if (($wdp_discount_detail['application'] ?? '') === 'line_price') {
                        continue;
                    }
                    $wdp_discounts = $wdp_discount_detail['discount']['discounts'];
                    foreach ($wdp_discounts as $key => $value) {
                        if ($value['productid'] == $prdID) {
                            $discount += round(wc_remove_number_precision($value['discount']), wc_get_price_decimals()) * $quantity;
                        }
                    }
                }
            }
            $discountedPrice = $subtotal - $discount;

            $wdp_meta   = '<td class="line_wdpdata" style="display:none"><div class="view">';
            $wdp_meta   .= '<span class="woocommerce-Price-amount amount">' . wc_price($discountedPrice) . '</span>';
            $wdp_meta   .= $discount ? '<span class="wc-order-item-discount">' . wc_price($discount) . ' discount</span>' : '';
            $wdp_meta   .= '</div>';
            $wdp_meta   .= '</td>';

            echo $wdp_meta;

        } else {

            $wdp_meta   = '<td class="line_wdpdata" style="display:none"><div class="view">';
            $wdp_meta   .= '<span class="woocommerce-Price-amount amount">' . wc_price($subtotal) . '</span>';
            $wdp_meta   .= '</div>';
            $wdp_meta   .= '</td>';

            echo $wdp_meta;

        }

    }


    public function wdpCustomJS()
    {

        $currentScreen = get_current_screen();
        $screenID = $currentScreen->id; //
        if ($screenID === 'shop_order') { ?>
            <script>
                jQuery(document).ready(function() {
                    jQuery('.line_wdpdata').each (function(index){
                        $wdpdata = jQuery(this).find('.view').html();
                        jQuery(this).parent().find('.line_cost .view').html($wdpdata);
                    });
                });
            </script>
        <?php }

    }

}
