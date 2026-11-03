// Deactivation Form
jQuery(document).ready(function() {

    var commentReasons = [ 'I found a bug', 'Plugin suddenly stopped working', 'Plugin broke my site', 'Other', 'Plugin doesn\'t meets my requirement' ];
    var lastFocused = null;

    function deactivateUrl() {
        return document.querySelector('a#deactivate-aco-woo-dynamic-pricing').getAttribute('href');
    }

    function wdpOpen(trigger) {
        let overlay = document.getElementById('wdp-survey-form-wrap');
        lastFocused = trigger || document.activeElement;
        overlay.classList.add('is-open');
        document.body.classList.add('wdp-survey-open');
        // Focus the dialog itself so keyboard users start inside it without pre-highlighting a reason.
        document.getElementById('wdp-survey-form').focus();
    }

    jQuery(document).on("click", function(e) {
        let overlay = document.getElementById('wdp-survey-form-wrap');
        let openButton = document.getElementById('deactivate-aco-woo-dynamic-pricing');
        if (!overlay) {
            return;
        }
        if(e.target.id == 'wdp-survey-form-wrap'){
            wdpClose();
        }
        if(openButton && e.target === openButton){
            e.preventDefault();
            wdpOpen(openButton);
        }
        if(e.target.id == 'wdp_skip'){
            e.preventDefault();
            window.location = deactivateUrl();
        }
        if(e.target.id == 'wdp_cancel' || jQuery(e.target).closest('#wdp_close').length){
            e.preventDefault();
            wdpClose();
        }
    });

    jQuery(document).on('keydown', function(e) {
        let overlay = document.getElementById('wdp-survey-form-wrap');
        if (e.key === 'Escape' && overlay && overlay.classList.contains('is-open')) {
            wdpClose();
        }
    });

	function wdpClose() {
        let overlay = document.getElementById('wdp-survey-form-wrap');
		overlay.classList.remove('is-open');
        document.body.classList.remove('wdp-survey-open');
		jQuery('#wdp-survey-form form')[0].reset();
		jQuery("#wdp-survey-form form .wdp-comments").hide();
        jQuery('#wdp-survey-form .wdp-survey__reason').removeClass('is-selected');
        jQuery('#wdp_deactivate').prop('disabled', false);
		jQuery('#wdp-error').html('');
        if (lastFocused && typeof lastFocused.focus === 'function') {
            lastFocused.focus();
        }
	}

    jQuery("#wdp-survey-form form").on('submit', function(e) {
        e.preventDefault();
        jQuery('#wdp_deactivate').prop('disabled', true);
        let valid = wdpValidate();
		if (valid) {
            let urlRedirect = deactivateUrl();
            let form = jQuery(this);
            let serializeArray = form.serializeArray();
            let actionUrl = 'https://feedback.acowebs.com/plugin.php';
            jQuery.ajax({
                type: "post",
                url: actionUrl,
                data: serializeArray,
                contentType: "application/javascript",
                dataType: 'jsonp',
                success: function(data)
                {
                    window.location = urlRedirect;
                },
                error: function (jqXHR, textStatus, errorThrown) {
                    window.location = urlRedirect;
                }
            });
        } else {
            // Let the user fix the answer and submit again, and make sure they can see why.
            jQuery('#wdp_deactivate').prop('disabled', false);
            let errorEl = document.getElementById('wdp-error');
            if (errorEl && errorEl.scrollIntoView) {
                errorEl.scrollIntoView({ block: 'nearest' });
            }
        }
    });

    jQuery('#wdp-survey-form .wdp-comments textarea').on('keyup', function () {
		wdpValidate();
	});

    jQuery("#wdp-survey-form form input[type='radio']").on('change', function(){
        wdpValidate();
        let val = jQuery(this).val();
        // Fallback for browsers without :has() support in the stylesheet.
        jQuery('#wdp-survey-form .wdp-survey__reason').removeClass('is-selected');
        jQuery(this).closest('.wdp-survey__reason').addClass('is-selected');
        if ( commentReasons.indexOf(val) !== -1 ) {
            let comments = jQuery("#wdp-survey-form form .wdp-comments").show();
            // On small screens the comment box can open below the fold.
            if (comments[0].scrollIntoView) {
                comments[0].scrollIntoView({ block: 'nearest' });
            }
        } else {
            jQuery("#wdp-survey-form form .wdp-comments").hide();
        }
    });

    function wdpValidate() {
		let error = '';
		let reason = jQuery("#wdp-survey-form form input[name='Reason']:checked").val();
		if ( !reason ) {
			error += 'Please select your reason for deactivation';
		}
		if ( error === '' && commentReasons.indexOf(reason) !== -1 ) {
			let comments = jQuery('#wdp-survey-form .wdp-comments textarea').val();
			if (comments.trim().length <= 0) {
				error += 'Please specify';
			}
		}
		if ( error !== '' ) {
			jQuery('#wdp-error').html(error);
			return false;
		}
		jQuery('#wdp-error').html('');
		return true;
	}

});
