jQuery(document).ready(function ($) {

    var $wdpTable           = $('.wdp_table ');
    var $dynamicPricing     = awdajaxobject.dynamicPricing;
    var $variablePricing    = awdajaxobject.variablePricing;

    // Function to check if a string is Base64-encoded
    function isBase64(str) {
        try {
        return btoa(atob(str)) == str;
        } catch (e) {
        return false;
        }
    }

    if ( typeof wp !== 'undefined' && wp.hooks) {
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
                data : {action: 'wdpDynamicDiscount',
                prodID: product_id,
                varID: variation_id,
                proCount: qnty },
                success: function(response) {
                    function modifyProductPrice() {
                        return response; 
                    }
                    wp.hooks.addFilter('wcpa_product_price', 'wcpa', modifyProductPrice, 10);
                    document.dispatchEvent(new Event("wcpaTrigger", {bubbles: true}));
                }
            });
        });
    }

    if ( $wdpTable.length > 0 ) {
        var dataTable = $wdpTable.attr('data-table');
        if (isBase64($wdpTable.attr('data-table'))) {
            dataTable = atob($wdpTable.attr('data-table'));
        }
        if ( $variablePricing ) {
            $( ".single_variation_wrap" ).on( "show_variation", function ( event, variation ) {
                let attributes = variation.attributes;
                let price = variation.display_price;
                let price_html = variation.price_html;
                let variation_id = variation.variation_id;
                let loader = '<div class="wdpLoader"><span></span><span></span><span></span></div>';
                $wdpTable.find('tbody td').html(loader);
                $('.wdpHiddenPrice').html(price_html);
                let varPrice = $('.wdpHiddenPrice del').length ? $('.wdpHiddenPrice ins .amount').text() : $('.wdpHiddenPrice .amount').text();

                if(awdajaxobject.thousandSeparator == "," && awdajaxobject.decimalSeparator == ".") {
                    varPrice = varPrice ? varPrice.replace(/[^\d\.]/g, '') : price;
                } else {
                    varPrice = varPrice ? varPrice.replace(/^[^\d]*/, '').replace(/\./g, '').replace(',', '.') : price;
                }
                // varPrice = varPrice ? varPrice.replace(/[^\d\.]/g, '') : price;
                let data = {
                    'action': 'wdpAjax', 
                    'nonce': awdajaxobject.nonce, 
                    'type': 'change',
                    'attributes': attributes,
                    'price': varPrice,
                    'variation_id': variation_id,
                    // 'DisData': $wdpTable.attr('data-table'),
                    'DisData': dataTable,
                    'Rule': $wdpTable.attr('data-rule'),
                }
                $.post(awdajaxobject.url, data, function(response) { 
                    if ( response ) { 
                        $wdpTable.attr('data-price', varPrice);
                        $wdpTable.find('tbody').html(response); 
                    }
                });
            });
        }

        if ( $dynamicPricing ) {
            let loader = '<div class="wdpLoader"><span></span><span></span><span></span></div>';
            $('form.cart').find('[name=quantity]').on('change input', function(){
            // $('form.cart').find('[name=quantity]').on('input', function(){ 
                if ( $wdpTable.attr('data-product') === '' ) { return; } 
                let data = { 
                    'action': 'wdpAjax', 
                    'nonce': awdajaxobject.nonce, 
                    'type': 'update',
                    'ProdID': $wdpTable.attr('data-product'), 
                    // 'DisData': $wdpTable.attr('data-table'),
                    'DisData': dataTable,
                    'ProdPrice': $wdpTable.attr('data-price'), 
                    'ProdVarPrice': $wdpTable.attr('data-var-price'), 
                    'ProdQty': $(this).parents('form.cart').find('input[name="quantity"]').val() 
                };
                $(".wdpDynamicValue .wdpPrice").html(loader);
                $(".wdpDynamicValue .wdpTotal").html(loader);
                $.post(awdajaxobject.url, data, function(response) { 
                    if ( response ) { 
                        response = JSON.parse(response); 
                        // $(".wdpDynamicValue .wdpPrice").removeClass('wdpLoader');
                        // $(".wdpDynamicValue .wdpTotal").removeClass('wdpLoader');
                        
                        let yourPrice  = response.price;
                        let totalPrice = response.total;
                        if(awdajaxobject.thousandSeparator == "," && awdajaxobject.decimalSeparator == ".") {
                            yourPrice  = yourPrice ? yourPrice.toString().replace(",", ".") : yourPrice;
                            totalPrice = totalPrice ? totalPrice.toString().replace(",", ".") : totalPrice;
                        } else {
                            yourPrice = yourPrice ? yourPrice.toString().replace(".", ",") : yourPrice;
                            totalPrice = totalPrice ? totalPrice.toString().replace(".", ",") : totalPrice;
                        }
                        $(".wdpDynamicValue .wdpPrice").html(response.currency + yourPrice); 
                        $(".wdpDynamicValue .wdpTotal").html(response.currency + totalPrice); 
                        $(".wdpDynamicValue").show();
                    }
                });
            });
        }
    }
});  