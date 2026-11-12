/**
 * Deactivation feedback popup (Acowebs feedback UI kit) for Dynamic Pricing With Discount Rules.
 *
 * Opens from this plugin's own "Deactivate" link only. Never blocks deactivation:
 * - "Send & deactivate" posts the feedback, then follows the link as soon as the request finishes,
 *   fails, or after 3 seconds, whichever comes first.
 * - "Skip & deactivate" follows the link right away and sends nothing.
 * - With the contact box unticked, no name or email is sent.
 */
( function () {
	'use strict';

	var cfg = window.awdpDeactivation;
	if ( ! cfg ) {
		return;
	}

	var DEACTIVATE_AFTER_MS = 3000;

	/**
	 * This plugin's "Deactivate" link. Rows are matched by data-plugin (the plugin basename), which WordPress
	 * always prints, unlike data-slug and the link id, which depend on wordpress.org update data.
	 */
	function findDeactivateLink() {
		var rows = document.querySelectorAll( 'tr[data-plugin]' );
		for ( var i = 0; i < rows.length; i++ ) {
			if ( rows[ i ].getAttribute( 'data-plugin' ) === cfg.plugin ) {
				var link = rows[ i ].querySelector( '.deactivate a[href]' );
				if ( link ) {
					return link;
				}
			}
		}
		return null;
	}

	function init() {
		var overlay = document.getElementById( cfg.modalId );
		var link = findDeactivateLink();
		if ( ! overlay || ! link ) {
			return;
		}

		var dialog = overlay.querySelector( '[role="dialog"]' );
		var form = overlay.querySelector( 'form' );
		var errorEl = overlay.querySelector( '[data-acofb-error]' );
		var sendBtn = overlay.querySelector( '[data-acofb-action="send"]' );
		var consentBox = form.querySelector( 'input[name="consent"]' );
		var emailInput = form.querySelector( 'input[name="email"]' );
		var nameInput = form.querySelector( 'input[name="name"]' );

		var lastFocused = null;
		var bodyOverflow = '';
		var sending = false;

		function setError( text ) {
			errorEl.textContent = text || '';
		}

		function open() {
			lastFocused = document.activeElement;
			overlay.hidden = false;
			bodyOverflow = document.body.style.overflow;
			document.body.style.overflow = 'hidden';
			dialog.focus();
		}

		function close() {
			if ( sending ) {
				return;
			}
			overlay.hidden = true;
			document.body.style.overflow = bodyOverflow;
			form.reset();
			setError( '' );
			if ( lastFocused && typeof lastFocused.focus === 'function' ) {
				lastFocused.focus();
			}
		}

		var deactivated = false;
		function deactivate() {
			if ( deactivated ) {
				return;
			}
			deactivated = true;
			window.location.href = link.href;
		}

		function selectedReason() {
			return form.querySelector( 'input[name="reason"]:checked' );
		}

		function setBusy( busy ) {
			sending = busy;
			var buttons = overlay.querySelectorAll( 'button' );
			for ( var i = 0; i < buttons.length; i++ ) {
				buttons[ i ].disabled = busy;
			}
			sendBtn.setAttribute( 'aria-busy', busy ? 'true' : 'false' );
		}

		function send() {
			var reason = selectedReason();
			if ( ! reason ) {
				setError( cfg.i18n.reasonRequired );
				form.querySelector( 'input[name="reason"]' ).focus();
				return;
			}

			var consent = !! ( consentBox && consentBox.checked );
			if ( consent && emailInput && emailInput.value && ! emailInput.checkValidity() ) {
				setError( cfg.i18n.emailInvalid );
				emailInput.focus();
				return;
			}

			setError( '' );
			setBusy( true );

			// Only the details box under the selected reason is sent.
			var details = reason.parentNode.querySelector( 'textarea[name="reason_details"]' );

			var body = new URLSearchParams();
			body.append( 'action', cfg.action );
			body.append( 'nonce', cfg.nonce );
			body.append( 'reason', reason.value );
			body.append( 'reason_details', details ? details.value : '' );
			body.append( 'consent', consent ? '1' : '0' );
			if ( consent ) {
				body.append( 'name', nameInput ? nameInput.value : '' );
				body.append( 'email', emailInput ? emailInput.value : '' );
			}

			setTimeout( deactivate, DEACTIVATE_AFTER_MS );

			try {
				// keepalive lets the request finish even if the page navigates away first.
				fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body, keepalive: true } )
					.then( deactivate, deactivate );
			} catch ( e ) {
				deactivate();
			}
		}

		// The focusable elements currently visible in the popup, for the focus trap.
		function focusables() {
			var all = dialog.querySelectorAll( 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), textarea:not([disabled])' );
			var list = [];
			for ( var i = 0; i < all.length; i++ ) {
				if ( all[ i ].offsetWidth || all[ i ].offsetHeight || all[ i ].getClientRects().length ) {
					list.push( all[ i ] );
				}
			}
			return list;
		}

		link.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			open();
		} );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			if ( ! sending ) {
				send();
			}
		} );

		form.addEventListener( 'change', function ( event ) {
			if ( event.target && 'reason' === event.target.name ) {
				setError( '' );
			}
		} );

		// Close on a backdrop click only when the press also started on the backdrop, so selecting text in a
		// field and releasing the mouse outside the popup does not close it.
		var pressedBackdrop = false;
		overlay.addEventListener( 'mousedown', function ( event ) {
			pressedBackdrop = event.target === overlay;
		} );

		overlay.addEventListener( 'click', function ( event ) {
			if ( event.target === overlay ) {
				if ( pressedBackdrop ) {
					close();
				}
				pressedBackdrop = false;
				return;
			}
			var action = event.target.closest ? event.target.closest( '[data-acofb-action]' ) : null;
			if ( ! action ) {
				return;
			}
			var name = action.getAttribute( 'data-acofb-action' );
			if ( 'cancel' === name ) {
				event.preventDefault();
				close();
			} else if ( 'skip' === name ) {
				event.preventDefault();
				deactivate();
			}
		} );

		overlay.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key || 'Esc' === event.key ) {
				event.preventDefault();
				close();
				return;
			}
			if ( 'Tab' !== event.key ) {
				return;
			}
			var items = focusables();
			if ( ! items.length ) {
				event.preventDefault();
				return;
			}
			var first = items[ 0 ];
			var last = items[ items.length - 1 ];
			if ( event.shiftKey && ( document.activeElement === first || document.activeElement === dialog ) ) {
				event.preventDefault();
				last.focus();
			} else if ( ! event.shiftKey && document.activeElement === last ) {
				event.preventDefault();
				first.focus();
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
