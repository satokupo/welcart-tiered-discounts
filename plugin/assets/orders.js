/**
 * Read-only server preview within Welcart's existing order form.
 *
 * @package Welcart_Tiered_Discounts
 */

document.addEventListener(
	'DOMContentLoaded',
	function () {
		'use strict';
		const panel = document.getElementById( 'wtd-order-panel' );
		if ( ! panel) {
			return;
		}
		const form         = panel.closest( 'form' );
		const status       = document.getElementById( 'wtd-preview-status' );
		const token        = form.elements.namedItem( 'wtd_preview' );
		const button       = document.getElementById( 'wtd-preview-button' );
		const nativeButton = document.getElementById( 'recalc' );
		let revision       = 0;
		let adding         = false;
		function dirty() {
			revision++;
			token.value        = '';
			status.textContent = wtdOrders.dirty;
		}
		function moneyInput(target) {
			return /^(skuPrice|quant|postId)\[/.test( target.name ) || /^offer\[(shipping_charge|cod_fee|usedpoint)\]$/.test( target.name );
		}
		form.addEventListener(
			'input',
			function (event) {
				if (moneyInput( event.target )) {
					dirty();
				} }
		);
		form.addEventListener(
			'change',
			function (event) {
				if (moneyInput( event.target )) {
					dirty();
				} }
		);
		['discount', 'tax', 'getpoint'].forEach(
			function (name) {
				const field = form.elements.namedItem( 'offer[' + name + ']' );
				if (field) {
					field.readOnly = true;
				}
			}
		);
		const changeTax = document.getElementById( 'change_taxrate' );
		if (changeTax) {
			changeTax.checked                       = false;
			changeTax.disabled                      = true;
			changeTax.closest( 'span' ).textContent = wtdOrders.historicalTax;
		}
		async function preview() {
			if (button.disabled || adding) {
				return;
			}
			const current   = ++revision;
			token.value     = '';
			button.disabled = true;
			if (nativeButton) {
				nativeButton.disabled = true;
			}
			const data = new FormData( form );
			data.set( 'action', 'wtd_preview_order' );
			try {
				const response = await fetch( wtdOrders.url, { method: 'POST', body: data, credentials: 'same-origin' } );
				const result   = await response.json();
				if (revision !== current) {
					return;
				}
				if ( ! response.ok || ! result.success) {
					throw new Error( result.data && result.data.message || wtdOrders.failed );
				}
				const quote = result.data.quote;
				[['discount', '-' + quote.discount], ['tax', quote.tax], ['usedpoint', quote.usedpoint], ['getpoint', quote.getpoint]].forEach(
					function (pair) {
						const field = form.elements.namedItem( 'offer[' + pair[0] + ']' );
						if (field) {
							field.value = pair[1];
						}
					}
				);
				function display(id, value) {
					const field = document.getElementById( id );
					if (field) {
						field.textContent = value;
					}
				}
				const formatted = function (value) {
					return Number( value ).toLocaleString( 'ja-JP', { maximumFractionDigits: 2 } ); };
				['total_full', 'total_full_top'].forEach(
					function (id) {
						display( id, formatted( quote.total ) ); }
				);
				display( 'include_tax', '(' + formatted( quote.internal_tax ) + ')' );
				['standard', 'reduced'].forEach(
					function (rate) {
						display( 'subtotal_' + rate, formatted( Number( quote.tax_parts['subtotal_' + rate] ) + Number( quote.tax_parts['discount_' + rate] ) ) );
						display( 'include_tax_' + rate, '(' + formatted( quote.internal_tax_parts[rate] ) + ')' );
					}
				);
				Object.keys( quote.tax_parts ).forEach(
					function (name) {
						const field = form.elements.namedItem( 'order_' + name );
						if (field) {
							field.value = quote.tax_parts[name];
						}
					}
				);
				status.textContent = wtdOrders.ready + ' — ' + result.data.tier_text + ' / ' +
					[quote.discount, quote.tax, quote.internal_tax, quote.usedpoint, quote.getpoint, quote.total]
					.map(
						function (value, index) {
							return wtdOrders.labels[index] + ': ' + value; }
					).join( ' / ' );
				token.value        = result.data.token;
			} catch (error) {
				if (revision === current) {
					status.textContent = error.message;
				}
			} finally {
				button.disabled = false;
				if (nativeButton) {
					nativeButton.disabled = false;
				}
			}
		}
		wtdOrders.preview = preview;
		button.addEventListener( 'click', preview );
		document.addEventListener(
			'click',
			function (event) {
				const remove = event.target.closest( '.delCartButton' );
				if (remove && form.contains( remove )) {
					event.preventDefault();
					event.stopImmediatePropagation();
					const match = /^delButtonAdmin\[(-?\d+)\]$/.exec( remove.name );
					if ( ! match) {
						return;
					}
					const removed = form.elements.namedItem( 'wtd_removed' );
					const ids     = JSON.parse( removed.value );
					ids.push( Number( match[1] ) );
					removed.value = JSON.stringify( ids );
					remove.closest( 'tr' ).remove();
					dirty();
					return;
				}
			},
			true
		);
		// Keep native product search/options; only replace its immediate DB write with a draft.
		jQuery.ajaxPrefilter(
			function (options, original, request) {
				if (typeof options.data !== 'string' || ! /(^|&)action=order_item2cart_ajax(&|$)/.test( options.data )) {
					return;
				}
				if (adding) {
					request.abort(); return; }
				options.data += '&' + new URLSearchParams( { wtd_form: new URLSearchParams( new FormData( form ) ).toString() } ).toString();
				adding        = true;
				dirty();
				const controls = Array.from( form.elements ).filter(
					function (field) {
						return ! field.disabled; }
				);
				controls.forEach(
					function (field) {
						field.disabled = true; }
				);
				request.always(
					function () {
						controls.forEach(
							function (field) {
								field.disabled = false; }
						);
						adding = false;
					}
				);
			}
		);
		form.addEventListener(
			'submit',
			function (event) {
				if ( ! token.value) {
					event.preventDefault();
					status.textContent = wtdOrders.dirty;
					button.focus();
				}
			}
		);
	}
);
