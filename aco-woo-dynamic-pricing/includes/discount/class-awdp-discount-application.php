<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Determines whether a rule applies discount to the cart line price or via WooCommerce coupons.
 */
class AWDP_Discount_Application
{

    /**
     * Discount types always applied per product line (not as cart coupons).
     *
     * @return string[]
     */
    public static function line_price_discount_types()
    {
        return apply_filters('awdp_line_price_discount_types', array(
            'percent_product_price',
            'fixed_product_price',
        ));
    }

    /**
     * Discount types always applied as cart coupons.
     *
     * @return string[]
     */
    public static function coupon_discount_types()
    {
        return apply_filters('awdp_coupon_discount_types', array(
            'percent_total_amount',
            'fixed_cart_amount',
        ));
    }

    /**
     * @param string $discount_type Rule type slug.
     * @param int    $rule_id       Rule post ID.
     * @param array  $discount_entry Per-line discount data (optional).
     */
    public static function applies_to_line_price($discount_type, $rule_id = 0, $discount_entry = array())
    {
        if (in_array($discount_type, self::line_price_discount_types(), true)) {
            return true;
        }

        if ('cart_quantity' === $discount_type) {
            if (isset($discount_entry['displayoncart']) && $discount_entry['displayoncart']) {
                return true;
            }

            return 'type_product' === self::get_quantity_discount_type($rule_id);
        }

        return (bool) apply_filters(
            'awdp_applies_to_line_price',
            false,
            $discount_type,
            $rule_id,
            $discount_entry
        );
    }

    /**
     * @param string $discount_type Rule type slug.
     * @param int    $rule_id       Rule post ID.
     * @param array  $discount_entry Per-line discount data (optional).
     */
    public static function applies_to_coupon($discount_type, $rule_id = 0, $discount_entry = array())
    {
        if (in_array($discount_type, self::coupon_discount_types(), true)) {
            return true;
        }

        if ('cart_quantity' === $discount_type) {
            return !self::applies_to_line_price($discount_type, $rule_id, $discount_entry);
        }

        return (bool) apply_filters(
            'awdp_applies_to_coupon',
            false,
            $discount_type,
            $rule_id,
            $discount_entry
        );
    }

    /**
     * @param int $rule_id Rule post ID.
     */
    public static function get_quantity_discount_type($rule_id)
    {
        if (!$rule_id) {
            return '';
        }

        return (string) get_post_meta($rule_id, 'discount_quantity_type', true);
    }

    /**
     * Normalizes a stored discount amount for totals (handles WC number precision).
     *
     * @param mixed $discount_amount Stored discount amount.
     */
    public static function normalize_discount_amount($discount_amount)
    {
        if ($discount_amount === '' || $discount_amount === null) {
            return 0.0;
        }

        $decimal_val = $discount_amount - floor($discount_amount);

        if ((strlen(strrchr((string) $decimal_val, '.')) - 1) > 2) {
            $calc_discount = ($decimal_val == 0)
                ? $discount_amount
                : (($decimal_val > 0.5) ? ceil($discount_amount) : floor($discount_amount));
        } else {
            $calc_discount = $discount_amount;
        }

        return (float) wc_remove_number_precision($calc_discount);
    }

    /**
     * Whether a per-unit discount should be multiplied by line quantity in coupon totals.
     *
     * @param string $discount_type Rule type slug.
     * @param int    $rule_id       Rule post ID.
     */
    public static function coupon_amount_is_per_unit($discount_type, $rule_id = 0)
    {
        if (in_array($discount_type, self::line_price_discount_types(), true)) {
            return true;
        }

        if ('cart_quantity' === $discount_type && 'type_product' === self::get_quantity_discount_type($rule_id)) {
            return true;
        }

        return false;
    }

}
