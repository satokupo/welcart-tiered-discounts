/**
 * Native settings form row controls.
 *
 * @package Welcart_Tiered_Discounts
 */

(function () {
	'use strict';

	var list      = document.querySelector( '[data-wtd-tier-list]' );
	var addButton = document.querySelector( '[data-wtd-add-tier]' );

	if ( ! list || ! addButton) {
		return;
	}

	var labels = window.wtdSettings && window.wtdSettings.labels;
	if ( ! labels) {
		return;
	}

	var body         = list.querySelector( 'tbody' );
	var emptyMessage = document.querySelector( '[data-wtd-empty]' );
	var form         = list.form || list.closest( 'form' );
	var tierField    = list.parentElement;

	if ( ! body) {
		return;
	}

	function getRows() {
		return body.querySelectorAll( '[data-wtd-tier-row]' );
	}

	function updateEmptySentinel(rows) {
		if ( ! tierField) {
			return;
		}

		var sentinel = tierField.querySelector( '[data-wtd-empty-sentinel]' );
		if (0 === rows.length) {
			if ( ! sentinel) {
				sentinel       = document.createElement( 'input' );
				sentinel.type  = 'hidden';
				sentinel.name  = 'welcart_tiered_discounts[tiers][__empty]';
				sentinel.value = '1';
				sentinel.setAttribute( 'data-wtd-empty-sentinel', '' );
				tierField.insertBefore( sentinel, list );
			}
		} else if (sentinel) {
			sentinel.remove();
		}
	}

	function setValueAttributes(row) {
		var type  = row.querySelector( '[data-wtd-field="type"]' );
		var value = row.querySelector( '[data-wtd-field="value"]' );

		if ( ! type || ! value) {
			return;
		}

		if ('percentage' === type.value) {
			value.setAttribute( 'min', '0.01' );
			value.setAttribute( 'max', '100' );
			value.setAttribute( 'step', '0.01' );
			value.setAttribute( 'inputmode', 'decimal' );
			value.setAttribute( 'placeholder', '10.25' );
		} else {
			value.setAttribute( 'min', '1' );
			value.setAttribute( 'max', '99999999' );
			value.setAttribute( 'step', '1' );
			value.setAttribute( 'inputmode', 'numeric' );
			value.setAttribute( 'placeholder', '500' );
		}
	}

	function renumberRows() {
		var rows = getRows();

		Array.prototype.forEach.call(
			rows,
			function (row, index) {
				row.setAttribute( 'data-row-index', index );
				Array.prototype.forEach.call(
					row.querySelectorAll( '[data-wtd-field]' ),
					function (field) {
						var name   = field.getAttribute( 'data-wtd-field' );
						var id     = 'wtd-tier-' + index + '-' + name;
						field.name = 'welcart_tiered_discounts[tiers][' + index + '][' + name + ']';
						if ('enabled' !== name || 'checkbox' === field.type) {
							field.id = id;
						}
					}
				);

				Array.prototype.forEach.call(
					row.querySelectorAll( 'label' ),
					function (label) {
						var name       = label.getAttribute( 'data-wtd-label' );
						var currentFor = label.getAttribute( 'for' );
						if ( ! name && currentFor) {
							name = currentFor.substring( currentFor.lastIndexOf( '-' ) + 1 );
						}
						var field = row.querySelector( '[data-wtd-field="' + name + '"]:not([type="hidden"])' );
						if (field) {
							label.setAttribute( 'for', field.id );
						}
					}
				);
				setValueAttributes( row );
			}
		);

		list.hidden = 0 === rows.length;
		if (emptyMessage) {
			emptyMessage.hidden = 0 !== rows.length;
		}
		updateEmptySentinel( rows );
	}

	function escapeHtml(value) {
		var entities = {
			'&': '&amp;',
			'<': '&lt;',
			'>': '&gt;',
			'"': '&quot;',
			'\'': '&#039;'
		};

		return String( value ).replace(
			/[&<>"']/g,
			function (character) {
				return entities[character];
			}
		);
	}

	function createRow() {
		var row = document.createElement( 'tr' );
		row.setAttribute( 'data-wtd-tier-row', '' );
		row.innerHTML = '<td>' +
			'<input type="hidden" data-wtd-field="enabled" value="0">' +
			'<input type="checkbox" data-wtd-field="enabled" value="1" checked>' +
			'<label class="screen-reader-text" data-wtd-label="enabled">' + escapeHtml( labels.enabled ) + '</label>' +
			'</td>' +
			'<td><label class="screen-reader-text" data-wtd-label="threshold">' + escapeHtml( labels.threshold ) + '</label>' +
			'<input type="number" data-wtd-field="threshold" min="1" max="99999999" step="1" inputmode="numeric" required></td>' +
			'<td><label class="screen-reader-text" data-wtd-label="type">' + escapeHtml( labels.type ) + '</label>' +
			'<select data-wtd-field="type"><option value="fixed">' + escapeHtml( labels.fixed ) + '</option>' +
			'<option value="percentage">' + escapeHtml( labels.percentage ) + '</option></select></td>' +
			'<td><label class="screen-reader-text" data-wtd-label="value">' + escapeHtml( labels.value ) + '</label>' +
			'<input type="number" data-wtd-field="value" min="1" max="99999999" step="1" inputmode="numeric" placeholder="500" required></td>' +
			'<td><button type="button" class="button-link-delete" data-wtd-remove-tier aria-label="' + escapeHtml( labels.delete ) + '">' + escapeHtml( labels.deleteLabel ) + '</button></td>';

		return row;
	}

	function focusAfterRemoval(row) {
		var next = row.nextElementSibling || row.previousElementSibling;
		if (next) {
			var threshold = next.querySelector( '[data-wtd-field="threshold"]' );
			if (threshold) {
				threshold.focus();
				return;
			}
		}
		addButton.focus();
	}

	addButton.addEventListener(
		'click',
		function () {
			var row = createRow();
			body.appendChild( row );
			renumberRows();
			var threshold = row.querySelector( '[data-wtd-field="threshold"]' );
			if (threshold) {
				threshold.focus();
			}
		}
	);

	body.addEventListener(
		'click',
		function (event) {
			var button = event.target.closest( '[data-wtd-remove-tier]' );
			if ( ! button) {
				return;
			}

			var row = button.closest( '[data-wtd-tier-row]' );
			if ( ! row) {
				return;
			}

			focusAfterRemoval( row );
			row.remove();
			renumberRows();
		}
	);

	body.addEventListener(
		'change',
		function (event) {
			if (event.target.matches( '[data-wtd-field="type"]' )) {
				var row = event.target.closest( '[data-wtd-tier-row]' );
				if (row) {
					setValueAttributes( row );
				}
			}
		}
	);

	if (form) {
		form.addEventListener(
			'submit',
			function (event) {
				if ( ! form.checkValidity()) {
					event.preventDefault();
					var invalid = form.querySelector( ':invalid' );
					if (invalid) {
						invalid.focus();
					}
				}
			}
		);
	}

	var error = document.querySelector( '.notice-error, .wtd-settings-error' );
	if (error) {
		error.setAttribute( 'tabindex', '-1' );
		error.focus();
	}

	renumberRows();
}());
