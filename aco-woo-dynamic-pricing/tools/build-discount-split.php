<?php
/**
 * Generates discount module classes from class-awdp-discount.php
 */
$root = dirname(__DIR__);
$srcFile = $root . '/includes/class-awdp-discount.php';
$outDir = $root . '/includes/discount';
if (!is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}

$src = file($srcFile, FILE_IGNORE_NEW_LINES);

$methods = [];
$current = null;
foreach ($src as $i => $line) {
    $n = $i + 1;
    if (preg_match('/^\s*(public|protected|private)?\s*(static\s+)?function\s+(\w+)/', $line, $m)) {
        if ($current) {
            $current['end'] = $n - 1;
            $methods[$current['name']] = $current;
        }
        $vis = !empty($m[1]) ? $m[1] : 'public';
        $current = ['name' => $m[3], 'start' => $n, 'end' => 0, 'vis' => $vis, 'static' => !empty($m[2])];
    }
}
if ($current) {
    $current['end'] = count($src);
    $methods[$current['name']] = $current;
}

$modules = [
    'AWDP_Discount_Rules' => [
        'validate_discount_rules', 'eval_rule', 'load_rules', 'get_items_to_apply_discount',
        'check_in_product_list', 'set_product_list', 'set_custom_list', 'check_in_rule',
    ],
    'AWDP_Discount_Cart' => [
        'wdpCalculateDiscount', 'cart_discount_items', 'wdpCartLoop', 'check_discount',
        'check_discount_shop', 'get_individual_discounted_price_in_cents', 'get_discount',
        'get_discounts_by_item', 'apply_discount_remainder', 'get_discounted_price_in_cents',
    ],
    'AWDP_Discount_Display' => [
        'show_pricing_table', 'get_product_price_html', 'show_offer_message', 'wdpCartMessage',
        'wdpDynamicPricingTable',
    ],
    'AWDP_Discount_Wcpa' => [
        'wdpWCPAVariationPrice', 'wcpaQunantity_Discount', 'wdpDynamicDiscount', 'wcpaDiscount', 'wdpWCPAPrice',
    ],
    'AWDP_Discount_Coupon' => [
        'addVirtualCoupon', 'couponLabel', 'applyFakeCoupons', 'wdpMiniCart',
    ],
    'AWDP_Discount_Order' => [
        'wdpOrderMeta', 'wdpDisplayOrderMeta', 'wdpAdminOrderHeader', 'wdpAdminOrderContent', 'wdpCustomJS',
    ],
    'AWDP_Discount_Utilities' => [
        'get_con_unit', 'wdpGetVariations', 'array_needle_search', 'check_product_on_sale',
        'wdp_price_including_tax', 'wdp_price_excluding_tax',
    ],
];

$ownerProps = [
    '_version', 'product_lists', 'awdp_cart_rules', 'apply_wdp_coupon', 'pricing_table',
    'productvariations', 'couponLabel', 'wdp_discounted_price', 'wdpCartDicount', 'wdpCartDiscountValues',
    'awdp_cart_rule_ids', 'variations', 'variation_prods', 'wdpQNitems', 'actual_price', 'wdp_order_meta',
    'awdp_discount_applied', 'discountProductPrice', 'discountProductMaxPrice', 'discountProductMinPrice',
    'products_on_sale', '_active', 'types', 'discount_rules', 'conversion_unit', 'converted_rate',
    'discounts', 'discounted_products',
];

$rulesMethods = $modules['AWDP_Discount_Rules'];
$utilsMethods = $modules['AWDP_Discount_Utilities'];

function extract_method(array $src, array $info): string
{
    $lines = array_slice($src, $info['start'] - 1, $info['end'] - $info['start'] + 1);
    return implode("\n", $lines);
}

function transform_body(string $body, string $moduleClass, array $rulesMethods, array $utilsMethods, array $ownerProps): string
{
    foreach ($ownerProps as $prop) {
        $body = preg_replace('/\$this->' . preg_quote($prop, '/') . '\b/', '$this->owner->' . $prop, $body);
    }

    if ($moduleClass !== 'AWDP_Discount_Rules') {
        foreach ($rulesMethods as $fn) {
            $body = preg_replace('/\$this->' . preg_quote($fn, '/') . '\s*\(/', '$this->owner->rules->' . $fn . '(', $body);
        }
    }

    if ($moduleClass !== 'AWDP_Discount_Utilities') {
        foreach ($utilsMethods as $fn) {
            $body = preg_replace('/\$this->' . preg_quote($fn, '/') . '\s*\(/', '$this->owner->utilities->' . $fn . '(', $body);
        }
    }

    if ($moduleClass === 'AWDP_Discount_Cart') {
        $body = preg_replace('/\$this->owner->rules->get_discounted_price_in_cents\(/', '$this->get_discounted_price_in_cents(', $body);
        $body = preg_replace('/\$this->owner->utilities->wdp_price_including_tax\(/', '$this->owner->utilities->wdp_price_including_tax(', $body);
    }

    return $body;
}

// Module base
$moduleBase = <<<'PHP'
<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Base for AWDP discount modules — shared owner reference.
 */
abstract class AWDP_Discount_Module
{

    /** @var AWDP_Discount */
    protected $owner;

    public function __construct(AWDP_Discount $owner)
    {
        $this->owner = $owner;
    }

}

PHP;
file_put_contents($outDir . '/class-awdp-discount-module.php', $moduleBase);

foreach ($modules as $className => $methodNames) {
    $parts = ["<?php\n\nif (!defined('ABSPATH')) {\n    exit;\n}\n\nrequire_once __DIR__ . '/class-awdp-discount-module.php';\n\nclass {$className} extends AWDP_Discount_Module\n{\n\n"];
    foreach ($methodNames as $name) {
        if (!isset($methods[$name])) {
            fwrite(STDERR, "Missing method: {$name}\n");
            continue;
        }
        $body = extract_method($src, $methods[$name]);
        $body = transform_body($body, $className, $rulesMethods, $utilsMethods, $ownerProps);
        $parts[] = $body . "\n\n";
    }
    $parts[] = "}\n";
    $file = $outDir . '/class-' . strtolower(str_replace('_', '-', $className)) . '.php';
    file_put_contents($file, implode('', $parts));
    echo "Wrote {$file}\n";
}

// Collect all delegated methods for parent
$allModuleMethods = [];
foreach ($modules as $methodNames) {
    foreach ($methodNames as $m) {
        $allModuleMethods[$m] = true;
    }
}

$parentMethods = [];
foreach ($methods as $name => $info) {
    if (in_array($name, ['__construct', 'isActive', '__clone', '__wakeup'], true)) {
        continue;
    }
    if (isset($allModuleMethods[$name])) {
        $moduleKey = null;
        foreach ($modules as $className => $methodNames) {
            if (in_array($name, $methodNames, true)) {
                $prop = lcfirst(substr($className, strlen('AWDP_Discount_')));
                $parentMethods[] = delegator($name, $info, $prop);
                break;
            }
        }
    }
}

function delegator(string $name, array $info, string $moduleProp): string
{
    $sigLine = $info['start'];
    global $src;
    $line = $src[$info['start'] - 1];
    if (!preg_match('/function\s+' . preg_quote($name, '/') . '\s*\((.*?)\)/', $line, $m)) {
        $line = $src[$info['start'] - 1] . ' ' . $src[$info['start']];
        preg_match('/function\s+' . preg_quote($name, '/') . '\s*\((.*?)\)/s', $line, $m);
    }
    $params = isset($m[1]) ? trim($m[1]) : '';
    $vis = $info['vis'] === 'protected' ? 'protected' : 'public';
    $args = [];
    if ($params !== '') {
        foreach (preg_split('/\s*,\s*/', $params) as $p) {
            if (preg_match('/\$(\w+)/', $p, $pm)) {
                $args[] = '$' . $pm[1];
            }
        }
    }
    $argList = implode(', ', $args);
    $pass = $argList !== '' ? $argList : '';
    return "    {$vis} function {$name}({$params})\n    {\n        return \$this->{$moduleProp}->{$name}({$pass});\n    }\n\n";
}

// Properties block from original (lines 9-50)
$props = implode("\n", array_slice($src, 8, 42));

$parent = <<<PHP
<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/discount/class-awdp-discount-module.php';
require_once __DIR__ . '/discount/class-awdp-discount-rules.php';
require_once __DIR__ . '/discount/class-awdp-discount-cart.php';
require_once __DIR__ . '/discount/class-awdp-discount-display.php';
require_once __DIR__ . '/discount/class-awdp-discount-wcpa.php';
require_once __DIR__ . '/discount/class-awdp-discount-coupon.php';
require_once __DIR__ . '/discount/class-awdp-discount-order.php';
require_once __DIR__ . '/discount/class-awdp-discount-utilities.php';

class AWDP_Discount
{

{$props}

    /** @var AWDP_Discount_Rules */
    public \$rules;

    /** @var AWDP_Discount_Cart */
    public \$cart;

    /** @var AWDP_Discount_Display */
    public \$display;

    /** @var AWDP_Discount_Wcpa */
    public \$wcpa;

    /** @var AWDP_Discount_Coupon */
    public \$coupon;

    /** @var AWDP_Discount_Order */
    public \$order;

    /** @var AWDP_Discount_Utilities */
    public \$utilities;

PHP;

// Modules access owner state via $this->owner->prop — must remain public.
$parent = str_replace('private $_active', 'public $_active', $parent);
$parent = str_replace('private $types', 'public $types', $parent);
$parent = str_replace('private $discount_rules', 'public $discount_rules', $parent);
$parent = str_replace('private $conversion_unit', 'public $conversion_unit', $parent);
$parent = str_replace('private $converted_rate', 'public $converted_rate', $parent);
$parent = str_replace('private $discounts', 'public $discounts', $parent);
$parent = str_replace('private $discounted_products', 'public $discounted_products', $parent);

    $construct = extract_method($src, $methods['__construct']);
    $construct = preg_replace('/\}\s*$/', "        \$this->init_modules();\n    }", trim($construct));
    $parent .= $construct . "\n\n";
    $parent .= extract_method($src, $methods['instance']) . "\n\n";
$parent .= extract_method($src, $methods['isActive']) . "\n\n";

$parent .= "    public function init_modules()\n    {\n";
$parent .= "        \$this->utilities = new AWDP_Discount_Utilities(\$this);\n";
$parent .= "        \$this->rules     = new AWDP_Discount_Rules(\$this);\n";
$parent .= "        \$this->cart      = new AWDP_Discount_Cart(\$this);\n";
$parent .= "        \$this->display   = new AWDP_Discount_Display(\$this);\n";
$parent .= "        \$this->wcpa      = new AWDP_Discount_Wcpa(\$this);\n";
$parent .= "        \$this->coupon    = new AWDP_Discount_Coupon(\$this);\n";
$parent .= "        \$this->order     = new AWDP_Discount_Order(\$this);\n";
$parent .= "    }\n\n";

foreach ($modules as $className => $methodNames) {
    $prop = lcfirst(substr($className, strlen('AWDP_Discount_')));
    foreach ($methodNames as $name) {
        if (!isset($methods[$name])) {
            continue;
        }
        $parent .= delegator($name, $methods[$name], $prop);
    }
}

$parent .= extract_method($src, $methods['__clone']) . "\n\n";
$parent .= extract_method($src, $methods['__wakeup']) . "\n\n";
$parent .= "}\n";

file_put_contents($root . '/includes/class-awdp-discount.php', $parent);
echo "Wrote parent class-awdp-discount.php\n";
