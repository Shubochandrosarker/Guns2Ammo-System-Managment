/*!
 * Advanced FFL Checkout — Dealer Portal Frontend JS
 * Vanilla JS, no dependencies.
 *
 * Responsibilities:
 *   - Set the hidden "portal_action" input when a button is clicked.
 *   - Confirm destructive or unusual actions (report_issue, not_my_shipment).
 *   - Auto-format the last-4-of-FFL input.
 *   - Prevent double-submits.
 */
(function () {
	'use strict';

	const form = document.getElementById('wpistic-ffl-portal-form');
	if (!form) return;

	const actionInput = document.getElementById('wpistic-ffl-portal-action');
	const buttons     = form.querySelectorAll('button[data-action]');
	const last4       = document.getElementById('wpistic-ffl-last4');

	const confirmCopy = {
		report_issue:    'Report an issue with this shipment? Our team will reach out to resolve it.',
		not_my_shipment: 'Flag this shipment as not belonging to your FFL? We will review immediately.',
	};

	buttons.forEach(function (btn) {
		btn.addEventListener('click', function (e) {
			const action = btn.getAttribute('data-action');

			// Confirm unusual actions
			if (confirmCopy[action] && !window.confirm(confirmCopy[action])) {
				e.preventDefault();
				return false;
			}

			actionInput.value = action;

			// Visual loading state on all buttons
			buttons.forEach(function (b) {
				b.classList.add('wpistic-ffl-portal__btn--loading');
				b.disabled = true;
			});
			btn.classList.remove('wpistic-ffl-portal__btn--loading');
		});
	});

	// Auto-uppercase + trim the last-4 field
	if (last4) {
		last4.addEventListener('input', function () {
			last4.value = last4.value.replace(/[^0-9A-Za-z]/g, '').slice(0, 4).toUpperCase();
		});
	}

	// Prevent double-submits at the form level too
	form.addEventListener('submit', function () {
		setTimeout(function () {
			buttons.forEach(function (b) { b.disabled = true; });
		}, 10);
	});

})();
