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


    /**
     * Thank-you page, My Account and emails: line subtotal as original (struck through) vs paid.
     *
     * Display only; reads the pre-discount unit stored at checkout.
     *
     * @param string                $subtotal Existing HTML.
     * @param WC_Order_Item_Product $item     Order line item.
     * @param WC_Order              $order    Order.
     * @return string
     */
    public function format_order_line_subtotal_html($subtotal, $item, $order)
    {
        if (!is_a($item, 'WC_Order_Item_Product')) {
            return $subtotal;
        }

        $original_unit = $this->get_order_item_original_unit($item);
        if ($original_unit === null) {
            return $subtotal;
        }

        if (!is_a($order, 'WC_Abstract_Order')) {
            $order = $item->get_order();
            if (!is_a($order, 'WC_Abstract_Order')) {
                return $subtotal;
            }
        }

        $product      = $item->get_product();
        $regular      = $product ? $product->get_regular_price('edit') : '';
        $display_unit = awdp_resolve_strikeout_original($original_unit, $regular, awdp_use_regular_as_strikeout());
        $qty          = max(1, (float) $item->get_quantity());
        $incl_tax     = 'incl' === get_option('woocommerce_tax_display_cart');

        // Same basis as WooCommerce's own line subtotal: ex tax, plus line tax when shown incl.
        $original_line   = $this->get_order_item_amount_excluding_tax($item, $order, $display_unit * $qty);
        $discounted_line = (float) $item->get_subtotal();
        if ($incl_tax) {
            $original_line  += $this->get_order_item_tax_for_amount($item, $original_line);
            $discounted_line = (float) $order->get_line_subtotal($item, true);
        }

        if ($original_line <= $discounted_line + $this->price_epsilon()) {
            return $subtotal;
        }

        $currency = array('currency' => $order->get_currency());
        $html     = wc_format_sale_price(wc_price($original_line, $currency), wc_price($discounted_line, $currency));
        if (!$incl_tax && $order->get_prices_include_tax()) {
            $html .= ' <small class="woocommerce-Price-taxLabel tax_label">' . WC()->countries->ex_tax_or_vat() . '</small>';
        }

        return $html;
    }


    /**
     * Order totals (thank-you page, My Account, emails): show product-rule savings as a Discount row.
     *
     * Product rules lower the line price, so WooCommerce records no discount for them. Subtotal is
     * restated at the pre-discount price so Subtotal - Discount + Tax = Total. Savings from the
     * virtual coupon are already in WooCommerce's own discount row and are added to, not repeated.
     *
     * @param array             $total_rows  Rows from WC_Order::get_order_item_totals().
     * @param WC_Abstract_Order $order       Order.
     * @param string            $tax_display 'excl' or 'incl'.
     * @return array
     */
    public function filter_order_item_totals($total_rows, $order, $tax_display = '')
    {
        if (!is_array($total_rows) || !isset($total_rows['cart_subtotal']) || !is_a($order, 'WC_Order')) {
            return $total_rows;
        }

        if (!apply_filters('awdp_order_totals_show_discount', true, $order)) {
            return $total_rows;
        }

        $tax_display = $tax_display ? $tax_display : get_option('woocommerce_tax_display_cart');
        $incl_tax    = 'incl' === $tax_display;
        $saving      = $this->get_order_line_price_saving($order);
        $amount      = $incl_tax ? $saving['excl'] + $saving['tax'] : $saving['excl'];
        $amount      = (float) apply_filters('awdp_order_totals_discount_amount', $amount, $order, $tax_display);

        if ($amount <= $this->price_epsilon()) {
            return $total_rows;
        }

        $currency = array('currency' => $order->get_currency());

        // Subtotal as WooCommerce builds it (WC_Order::get_subtotal_to_display), plus the saving.
        $subtotal = (float) $order->get_subtotal();
        if ($incl_tax) {
            $subtotal_taxes = 0;
            foreach ($order->get_items() as $item) {
                $line_tax        = (float) $item->get_subtotal_tax();
                $subtotal_taxes += 'yes' === get_option('woocommerce_tax_round_at_subtotal') ? $line_tax : wc_round_tax_total($line_tax);
            }
            $subtotal += wc_round_tax_total($subtotal_taxes);
        }
        $subtotal_html = wc_price($subtotal + $amount, $currency);
        if (!$incl_tax && $order->get_prices_include_tax() && wc_tax_enabled()) {
            $subtotal_html .= ' <small class="tax_label">' . WC()->countries->ex_tax_or_vat() . '</small>';
        }
        $total_rows['cart_subtotal']['value'] = apply_filters('woocommerce_order_subtotal_to_display', $subtotal_html, false, $order);

        $discount      = $amount + (isset($total_rows['discount']) ? (float) $order->get_total_discount(!$incl_tax) : 0);
        $discount_html = '-' . apply_filters('woocommerce_order_discount_to_display', wc_price($discount, $currency), $order);

        if (isset($total_rows['discount'])) {
            $total_rows['discount']['value'] = $discount_html;
            return $total_rows;
        }

        $rows = array();
        foreach ($total_rows as $key => $row) {
            $rows[$key] = $row;
            if ('cart_subtotal' === $key) {
                $rows['discount'] = array(
                    'type'  => 'discount',
                    'label' => __('Discount:', 'woocommerce'),
                    'value' => $discount_html,
                );
            }
        }

        return $rows;
    }


    /**
     * Saving from product rules that lowered order line prices.
     *
     * Only lines with a stored pre-discount unit count. Lines that fell back to the virtual
     * coupon were charged at that unit, so they add nothing here (the coupon row covers them).
     *
     * @param WC_Abstract_Order $order Order.
     * @return array { excl: float, tax: float }
     */
    public function get_order_line_price_saving($order)
    {
        $saving = array('excl' => 0.0, 'tax' => 0.0);

        foreach ($order->get_items('line_item') as $item) {
            if (!is_a($item, 'WC_Order_Item_Product')) {
                continue;
            }

            $original = $this->get_order_item_original_unit($item);
            $qty      = (float) $item->get_quantity();
            if ($original === null || $original <= 0 || $qty <= 0) {
                continue;
            }

            $original_line = $this->get_order_item_amount_excluding_tax($item, $order, $original * $qty);
            $line_saving   = $original_line - (float) $item->get_subtotal();
            if ($line_saving <= $this->price_epsilon()) {
                continue;
            }

            $saving['excl'] += $line_saving;
            $saving['tax']  += $this->get_order_item_tax_for_amount($item, $line_saving);
        }

        return $saving;
    }


    /**
     * Pre-discount unit price stored on the line at checkout (catalog price basis).
     *
     * @param WC_Order_Item_Product $item Order line item.
     * @return float|null
     */
    protected function get_order_item_original_unit($item)
    {
        $original = $item->get_meta('_awdp_original_unit_price', true);

        return ($original !== '' && is_numeric($original)) ? (float) $original : null;
    }


    /**
     * A catalog-basis amount for an order line without tax.
     *
     * Mirrors WC_Cart_Totals: with prices entered incl tax, the shop's base tax is removed
     * (or the line's own rates when woocommerce_adjust_non_base_location_prices is off).
     *
     * @param WC_Order_Item_Product $item   Order line item.
     * @param WC_Abstract_Order     $order  Order.
     * @param float                 $amount Amount in the catalog price basis.
     * @return float
     */
    protected function get_order_item_amount_excluding_tax($item, $order, $amount)
    {
        $amount = (float) $amount;
        if ($amount <= 0 || !$order->get_prices_include_tax() || !wc_tax_enabled()) {
            return $amount;
        }

        $product = $item->get_product();
        $taxable = $product ? $product->is_taxable() : !empty($this->get_order_item_tax_rates($item));
        if (!$taxable) {
            return $amount;
        }

        $rates = apply_filters('woocommerce_adjust_non_base_location_prices', true)
            ? WC_Tax::get_base_tax_rates($item->get_tax_class())
            : $this->get_order_item_tax_rates($item);

        return $amount - array_sum(WC_Tax::calc_tax($amount, $rates, true));
    }


    /**
     * Tax the order line would charge on an ex-tax amount.
     *
     * @param WC_Order_Item_Product $item   Order line item.
     * @param float                 $amount Amount excluding tax.
     * @return float
     */
    protected function get_order_item_tax_for_amount($item, $amount)
    {
        $subtotal = (float) $item->get_subtotal();
        if ($subtotal > 0) {
            return (float) $amount * (float) $item->get_subtotal_tax() / $subtotal;
        }

        // Fully free line: no ratio to use, so apply the rates it was taxed with.
        $rates = $this->get_order_item_tax_rates($item);

        return $rates ? (float) array_sum(WC_Tax::calc_tax((float) $amount, $rates, false)) : 0.0;
    }


    /**
     * Tax rates applied to an order line, in WC_Tax::calc_tax() format.
     *
     * @param WC_Order_Item_Product $item Order line item.
     * @return array
     */
    protected function get_order_item_tax_rates($item)
    {
        $taxes = $item->get_taxes();
        if (empty($taxes['subtotal']) || !is_array($taxes['subtotal'])) {
            return array();
        }

        $rates = array();
        foreach (array_keys($taxes['subtotal']) as $rate_id) {
            $rate = WC_Tax::_get_tax_rate($rate_id);
            if (empty($rate) || !isset($rate['tax_rate'])) {
                continue;
            }
            $rates[$rate_id] = array(
                'rate'     => (float) $rate['tax_rate'],
                'label'    => isset($rate['tax_rate_name']) ? $rate['tax_rate_name'] : '',
                'shipping' => !empty($rate['tax_rate_shipping']) ? 'yes' : 'no',
                'compound' => !empty($rate['tax_rate_compound']) ? 'yes' : 'no',
            );
        }

        return $rates;
    }


    /**
     * Smallest amount the store's currency can show.
     *
     * @return float
     */
    protected function price_epsilon()
    {
        return pow(10, -(int) wc_get_price_decimals());
    }

}
