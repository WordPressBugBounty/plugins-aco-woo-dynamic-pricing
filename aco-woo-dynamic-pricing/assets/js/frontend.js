jQuery(document).ready(function ($) {

    /**
     * WordPress hooks API (WCPA add-on integration). Safe when wp-hooks is not loaded.
     */
    function awdpHasWpHooks() {
        return typeof wp !== 'undefined' && wp.hooks && typeof wp.hooks.addFilter === 'function';
    }

    function awdpTriggerWcpaPriceUpdate(price) {
        if ( ! awdpHasWpHooks() ) {
            return;
        }
        wp.hooks.addFilter('wcpa_product_price', 'wcpa', function () {
            return price;
        }, 10);
        document.dispatchEvent(new Event('wcpaTrigger', { bubbles: true }));
    }

    /**
     * Only override WCPA price when AWDP actually applied a discount.
     * Empty/catalog echoes would zero formula/lookup prices.
     */
    function awdpShouldApplyWcpaPriceOverride(data) {
        if ( data == null ) {
            return false;
        }
        if ( typeof data === 'string' ) {
            try {
                data = JSON.parse(data);
            } catch (e) {
                return false;
            }
        }
        if ( typeof data !== 'object' ) {
            return false;
        }
        if ( data.hasDiscount === false ) {
            return false;
        }
        if ( data.price === '' || data.price === null || typeof data.price === 'undefined' ) {
            return false;
        }
        if ( Object.prototype.hasOwnProperty.call(data, 'originalPrice')
            && Number(data.price) === Number(data.originalPrice) ) {
            return false;
        }
        return data.hasDiscount === true
            || ( Object.prototype.hasOwnProperty.call(data, 'originalPrice')
                && Number(data.price) !== Number(data.originalPrice) );
    }

    var $wdpTable           = $('.wdp_table');
    var $dynamicPricing     = typeof awdajaxobject !== 'undefined' ? awdajaxobject.dynamicPricing : false;

    // Function to check if a string is Base64-encoded
    function isBase64(str) {
        try {
        return btoa(atob(str)) == str;
        } catch (e) {
        return false;
        }
    }

    if ( awdpHasWpHooks() && typeof awdajaxobject !== 'undefined' ) {
    //     wp.hooks.addFilter('wcpa_product_price', 'wcpa', (productPrice, product_id, variation_id) => {
    //         $.ajax({ url: awdajaxobject.url, data: {action: 'wdpDiscountedPrice', prodID: product_id, varID: variation_id} }).done(function(data) {
    //             productPrice = data ? data : productPrice;
    //             return productPrice;
    //         }).fail(function(data){
    //             return productPrice;
    //         });
    //     }, 10);
    //     document.dispatchEvent(new Event("wcpaTrigger", {bubbles: true}));
    //     $( '.variations_form' ).on( 'woocommerce_variation_select_change', function() {
    //         wp.hooks.addFilter('wcpa_product_price', 'wcpa', (productPrice, product_id, variation_id) => {
    //             $.ajax({ url: awdajaxobject.url, data: {action: 'wdpDiscountedPrice', prodID: product_id, varID: variation_id} }).done(function(data) {
    //                 productPrice = data ? data : productPrice;
    //                 return productPrice;
    //             }).fail(function(data){
    //                 return productPrice;
    //             });
    //         }, 10);
    //         document.dispatchEvent(new Event("wcpaTrigger", {bubbles: true}));
    //     });
    //     $('form.cart').find('[name=quantity]').on('change input', function(){ 
    //         let qn = $(this).parents('form.cart').find('input[name="quantity"]').val();
    //         wp.hooks.addFilter('wcpa_product_price', 'wcpa', (productPrice, product_id, variation_id) => {
    //             $.ajax({ url: awdajaxobject.url, data: {action: 'wdpDiscountedPrice', prodID: product_id, varID: variation_id} }).done(function(data) {
    //                 productPrice = data ? qn * data : qn * productPrice;
    //                 return productPrice;
    //             }).fail(function(data){
    //                 return qn * productPrice;
    //             });
    //         }, 10);
    //         document.dispatchEvent(new Event("wcpaTrigger", {bubbles: true}));
    //     });

        $('form.cart').find('[name=quantity]').on('change input', function() { 
            let qnty = $(this).parents('form.cart').find('input[name="quantity"]').val();
            let product_id = $(this).parents('form.cart').find('button[name="add-to-cart"]').val();
            let variation_id = $(this).parents('form.cart').find('input[name="variation_id"]').val();
            $.ajax({
                dataType : "json",
                url : awdajaxobject.url,
                data : {
                    action: 'wdpDynamicDiscount',
                    nonce: awdajaxobject.nonce,
                    prodID: product_id,
                    varID: variation_id,
                    proCount: qnty
                },
                success: function(response) {
                    if ( awdpShouldApplyWcpaPriceOverride(response) ) {
                        awdpTriggerWcpaPriceUpdate(response);
                    }
                }
            });

            $.ajax({
                dataType : "json",
                url : awdajaxobject.url,
                data : {
                    action: 'wcpaQunantity_Discount',
                    nonce: awdajaxobject.nonce,
                    proCount: qnty
                },
                success: function(response) {
                    if ( response == null ) {
                        return;
                    }
                    function modifyDiscountRule() {
                        return response; 
                    }
                    wp.hooks.addFilter('wcpa_discount_rule', 'wcpa', modifyDiscountRule, 10);
                }
            });
        });
    }

    if ( $wdpTable.length > 0 ) {

        /**
         * Per-table rule payload. Prefer the table's own data-table so later
         * variation updates are not stuck on the first table captured at load.
         */
        function awdpGetTableDisData($table) {
            var encoded = $table.attr('data-table') || '';
            if ( encoded && isBase64(encoded) ) {
                try {
                    return atob(encoded);
                } catch (e) {
                    return encoded;
                }
            }
            return encoded;
        }

        function awdpSettingEnabled(value) {
            return value === true || value === 1 || value === '1';
        }

        var awdpDynamicPricing = awdpSettingEnabled($dynamicPricing);
        var awdpTableRequest = 0;
        var awdpPriceRequest = 0;

        function awdpGetLiveTables() {
            var $tables = $('.wdp_table');
            return $tables.length ? $tables : $wdpTable;
        }

        function awdpEnsureTableOriginals($table) {
            if ( $table.data('awdpOriginalPrice') === undefined ) {
                $table.data('awdpOriginalPrice', $table.attr('data-price') || '');
            }
            if ( $table.data('awdpOriginalBody') === undefined ) {
                $table.data('awdpOriginalBody', $table.find('tbody').html());
            }
        }

        awdpGetLiveTables().each(function () {
            awdpEnsureTableOriginals($(this));
        });

        /**
         * Resolve a numeric variation display price from WooCommerce's variation object.
         * Prefer display_price (wc_get_price_to_display); fall back to parsing price_html.
         */
        function awdpResolveVariationPrice(variation) {
            if ( variation && variation.display_price !== undefined && variation.display_price !== null && variation.display_price !== '' ) {
                var direct = Number(variation.display_price);
                if ( ! isNaN(direct) && direct > 0 ) {
                    return direct;
                }
            }

            if ( ! variation || ! variation.price_html ) {
                return 0;
            }

            var $tmp = $('<div class="wdpHiddenPriceParse" />').html(variation.price_html);
            var raw = $tmp.find('del').length
                ? $tmp.find('ins .amount').first().text()
                : $tmp.find('.amount').first().text();

            if ( ! raw ) {
                return 0;
            }

            if ( typeof awdajaxobject !== 'undefined' && awdajaxobject.thousandSeparator == "," && awdajaxobject.decimalSeparator == "." ) {
                raw = raw.replace(/[^\d\.]/g, '');
            } else {
                raw = raw.replace(/^[^\d]*/, '').replace(/\./g, '').replace(',', '.');
            }

            var parsed = parseFloat(raw);
            return ( ! isNaN(parsed) && parsed > 0 ) ? parsed : 0;
        }

        function awdpFindVariationFromForm($form, variationId) {
            variationId = parseInt(variationId, 10);
            if ( ! variationId ) {
                return null;
            }
            var variations = $form.data('product_variations');
            if ( ! $.isArray(variations) ) {
                return null;
            }
            var found = null;
            $.each(variations, function (i, variation) {
                if ( parseInt(variation.variation_id, 10) === variationId ) {
                    found = variation;
                    return false;
                }
            });
            return found;
        }

        function awdpFindTablesForForm($form) {
            var productId = $form.data('product_id') || $form.find('input[name="product_id"]').val() || '';
            var $tables = $();

            awdpGetLiveTables().each(function () {
                var $table = $(this);
                awdpEnsureTableOriginals($table);
                var tableProduct = $table.attr('data-product') || '';
                if ( ! productId || ! tableProduct || String(tableProduct) === String(productId) ) {
                    $tables = $tables.add($table);
                }
            });

            return $tables.length ? $tables : awdpGetLiveTables();
        }

        function awdpGetDynamicBox($table) {
            var $outter = $table.closest('.wdp_table_outter');
            var $dynamic = $();
            if ( $outter.length ) {
                $dynamic = $outter.nextAll('.wdpDynamicValue').first();
                if ( ! $dynamic.length ) {
                    $dynamic = $outter.siblings('.wdpDynamicValue').first();
                }
                if ( ! $dynamic.length ) {
                    $dynamic = $outter.parent().find('.wdpDynamicValue').first();
                }
            }
            if ( ! $dynamic.length ) {
                $dynamic = $('.wdpDynamicValue').first();
            }
            return $dynamic;
        }

        function awdpGetProductForm($table) {
            var productId = $table.attr('data-product') || '';
            var $form = $('form.variations_form, form.cart').filter(function () {
                var id = $(this).data('product_id') || $(this).find('input[name="product_id"], button[name="add-to-cart"], input[name="add-to-cart"]').first().val();
                return ! productId || ! id || String(id) === String(productId);
            }).first();
            return $form.length ? $form : $('form.cart').first();
        }

        function awdpGetCurrentQty($table) {
            var qty = parseFloat(awdpGetProductForm($table).find('input[name="quantity"]').val());
            return ( ! isNaN(qty) && qty > 0 ) ? qty : 1;
        }

        function awdpGetSelectedVariationId($table) {
            var variationId = parseInt(awdpGetProductForm($table).find('input[name="variation_id"], input.variation_id').val(), 10);
            return ( variationId > 0 ) ? variationId : 0;
        }

        function awdpAbortTableRequest($table) {
            var xhr = $table.data('awdpTableXhr');
            if ( xhr && xhr.readyState !== 4 ) {
                xhr.abort();
            }
        }

        function awdpAbortPriceRequest($table) {
            var xhr = $table.data('awdpPriceXhr');
            if ( xhr && xhr.readyState !== 4 ) {
                xhr.abort();
            }
        }

        function awdpFormatFallbackPrice(amount) {
            var decimals = ( typeof awdajaxobject !== 'undefined' && awdajaxobject.priceDecimals !== undefined )
                ? parseInt(awdajaxobject.priceDecimals, 10)
                : 2;
            if ( isNaN(decimals) || decimals < 0 ) {
                decimals = 2;
            }
            var n = Number(amount);
            if ( isNaN(n) ) {
                n = 0;
            }
            var formatted = n.toFixed(decimals);
            var decimalSep = ( typeof awdajaxobject !== 'undefined' && awdajaxobject.decimalSeparator )
                ? awdajaxobject.decimalSeparator
                : '.';
            if ( decimalSep !== '.' ) {
                formatted = formatted.replace('.', decimalSep);
            }
            var symbol = ( typeof awdajaxobject !== 'undefined' && awdajaxobject.currencySymbol )
                ? awdajaxobject.currencySymbol
                : '';
            return symbol + formatted;
        }

        function awdpPaintDynamicPrice($dynamic, unit, qty) {
            qty = parseFloat(qty);
            if ( isNaN(qty) || qty < 0 ) {
                qty = 0;
            }
            unit = parseFloat(unit);
            if ( isNaN(unit) ) {
                unit = 0;
            }
            $dynamic.find('.wdpPrice').html(awdpFormatFallbackPrice(unit));
            $dynamic.find('.wdpTotal').html(awdpFormatFallbackPrice(unit * qty));
            $dynamic.show();
        }

        function awdpRefreshDynamicPrice($table, qty) {
            if ( ! awdpDynamicPricing ) {
                return;
            }
            if ( $table.attr('data-product') === '' ) {
                return;
            }

            var loader = '<div class="wdpLoader"><span></span><span></span><span></span></div>';
            var requestId = ++awdpPriceRequest;
            var $dynamic = awdpGetDynamicBox($table);
            var variationId = awdpGetSelectedVariationId($table);

            if ( typeof qty === 'undefined' || qty === null || qty === '' ) {
                qty = awdpGetCurrentQty($table);
            } else {
                qty = parseFloat(qty);
                if ( isNaN(qty) || qty <= 0 ) {
                    qty = awdpGetCurrentQty($table);
                }
            }

            $dynamic.show();
            $dynamic.find('.wdpPrice').html(loader);
            $dynamic.find('.wdpTotal').html(loader);

            awdpAbortPriceRequest($table);
            var xhr = $.post(awdajaxobject.url, {
                action: 'wdpAjax',
                nonce: awdajaxobject.nonce,
                type: 'update',
                ProdID: $table.attr('data-product'),
                DisData: awdpGetTableDisData($table),
                ProdPrice: $table.attr('data-price'),
                ProdVarPrice: $table.attr('data-var-price'),
                ProdQty: qty,
                variation_id: variationId
            }, function (response) {
                if ( requestId !== awdpPriceRequest ) {
                    return;
                }
                if ( ! response ) {
                    awdpPaintDynamicPrice($dynamic, $table.attr('data-price'), qty);
                    return;
                }
                if ( typeof response === 'string' ) {
                    try {
                        response = JSON.parse(response);
                    } catch (e) {
                        awdpPaintDynamicPrice($dynamic, $table.attr('data-price'), qty);
                        return;
                    }
                }

                if ( response.price_html && response.total_html ) {
                    $dynamic.find('.wdpPrice').html(response.price_html);
                    $dynamic.find('.wdpTotal').html(response.total_html);
                } else {
                    $dynamic.find('.wdpPrice').html(awdpFormatFallbackPrice(response.price));
                    $dynamic.find('.wdpTotal').html(awdpFormatFallbackPrice(response.total));
                }
                $dynamic.show();
            }).fail(function (jqXHR, textStatus) {
                if ( textStatus === 'abort' || requestId !== awdpPriceRequest ) {
                    return;
                }
                awdpPaintDynamicPrice($dynamic, $table.attr('data-price'), qty);
            });
            $table.data('awdpPriceXhr', xhr);
        }

        function awdpResetQuantityTable($table) {
            var variationId = awdpGetSelectedVariationId($table);

            // A variation is still selected (add-to-cart / fragment refresh). Keep its prices.
            if ( variationId > 0 ) {
                var $form = awdpGetProductForm($table);
                var variation = awdpFindVariationFromForm($form, variationId);
                if ( variation && parseInt($table.data('awdpAppliedVariation'), 10) !== variationId ) {
                    awdpApplyVariationToTable($table, variation, $form);
                    return;
                }
                awdpRefreshDynamicPrice($table, awdpGetCurrentQty($table));
                return;
            }

            awdpTableRequest++;
            awdpPriceRequest++;
            awdpAbortTableRequest($table);
            awdpAbortPriceRequest($table);
            $table.removeData('awdpTableXhr');
            $table.removeData('awdpPriceXhr');
            $table.removeData('awdpAppliedVariation');
            $table.attr('data-price', $table.data('awdpOriginalPrice') || '');
            var originalBody = $table.data('awdpOriginalBody');
            if ( originalBody ) {
                $table.find('tbody').html(originalBody);
            }
            awdpRefreshDynamicPrice($table, awdpGetCurrentQty($table));
        }

        function awdpApplyVariationToTable($table, variation, $form) {
            if ( ! variation ) {
                return;
            }

            var variationId = parseInt(variation.variation_id, 10) || 0;
            var varPrice = awdpResolveVariationPrice(variation);
            var qty = parseFloat($form.find('input[name="quantity"]').val());
            var requestId;

            if ( isNaN(qty) || qty <= 0 ) {
                qty = 1;
            }

            $table.data('awdpAppliedVariation', variationId);

            if ( varPrice > 0 ) {
                $table.attr('data-price', varPrice);
            }

            if ( $('.wdpHiddenPrice').length ) {
                $('.wdpHiddenPrice').html(variation.price_html || '');
            }

            requestId = ++awdpTableRequest;
            $table.find('tbody td').html('<div class="wdpLoader"><span></span><span></span><span></span></div>');

            awdpAbortTableRequest($table);
            var xhr = $.post(awdajaxobject.url, {
                action: 'wdpAjax',
                nonce: awdajaxobject.nonce,
                type: 'change',
                attributes: variation.attributes,
                price: varPrice,
                variation_id: variation.variation_id,
                DisData: awdpGetTableDisData($table),
                Rule: $table.attr('data-rule')
            }, function (response) {
                if ( requestId !== awdpTableRequest ) {
                    return;
                }
                if ( response ) {
                    if ( varPrice > 0 ) {
                        $table.attr('data-price', varPrice);
                    }
                    $table.find('tbody').html(response);
                }
                awdpRefreshDynamicPrice($table, qty);
            }).fail(function (jqXHR, textStatus) {
                if ( textStatus === 'abort' ) {
                    return;
                }
                if ( requestId !== awdpTableRequest ) {
                    return;
                }
                var originalBody = $table.data('awdpOriginalBody');
                if ( originalBody ) {
                    $table.find('tbody').html(originalBody);
                }
                awdpRefreshDynamicPrice($table, qty);
            });
            $table.data('awdpTableXhr', xhr);
        }

        function awdpSyncSelectedVariation($form) {
            var variationId = parseInt($form.find('input[name="variation_id"], input.variation_id').val(), 10);
            if ( ! ( variationId > 0 ) ) {
                return;
            }
            var variation = awdpFindVariationFromForm($form, variationId);
            awdpFindTablesForForm($form).each(function () {
                var $table = $(this);
                if ( parseInt($table.data('awdpAppliedVariation'), 10) === variationId ) {
                    return;
                }
                if ( variation ) {
                    awdpApplyVariationToTable($table, variation, $form);
                } else {
                    awdpRefreshDynamicPrice($table, awdpGetCurrentQty($table));
                }
            });
        }

        // WooCommerce fires found_variation on form.variations_form (not the wrap).
        $(document.body).on('found_variation', 'form.variations_form', function (event, variation) {
            if ( ! variation ) {
                return;
            }
            var $form = $(this);
            awdpFindTablesForForm($form).each(function () {
                awdpApplyVariationToTable($(this), variation, $form);
            });
        });

        // Woo inits the variation form after a 100ms timeout. If found_variation
        // already applied this variation, skip; otherwise catch a missed init.
        $(document.body).on('wc_variation_form', 'form.variations_form', function () {
            awdpSyncSelectedVariation($(this));
        });

        // Restore the range table when attributes are cleared. Keep Your Price
        // visible using the current qty and catalog/min price (or the still-
        // selected variation after add-to-cart).
        $(document.body).on('reset_data', 'form.variations_form', function () {
            awdpFindTablesForForm($(this)).each(function () {
                awdpResetQuantityTable($(this));
            });
        });

        $(document.body).on('added_to_cart', function () {
            awdpGetLiveTables().each(function () {
                var $table = $(this);
                awdpEnsureTableOriginals($table);
                awdpRefreshDynamicPrice($table, awdpGetCurrentQty($table));
            });
        });

        if ( awdpDynamicPricing ) {
            $(document.body).on('change input', 'form.cart [name="quantity"]', function (e) {
                var $form = $(this).closest('form.cart');
                var $table = awdpFindTablesForForm($form).first();
                if ( ! $table.length ) {
                    $table = awdpGetLiveTables().first();
                }
                // WooCommerce programmatically triggers quantity `change` while matching
                // a variation, before our found_variation handler can set data-price.
                if ( e.type === 'change' && ! e.originalEvent && $form.find('input[name="variation_id"], input.variation_id').val() ) {
                    return;
                }
                awdpRefreshDynamicPrice($table, $(this).val());
            });

            awdpGetLiveTables().each(function () {
                awdpRefreshDynamicPrice($(this), awdpGetCurrentQty($(this)));
            });

            $('form.variations_form').each(function () {
                awdpSyncSelectedVariation($(this));
            });
        }
    }
});
