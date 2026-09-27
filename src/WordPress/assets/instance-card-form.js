(function () {
	'use strict';

	function showError(form, message) {
		var el = form.querySelector('.validakey-ie-card-error');
		if (!el) {
			return;
		}
		if (!message) {
			el.hidden = true;
			el.textContent = '';
			return;
		}
		el.hidden = false;
		el.textContent = message;
	}

	function ready(fn) {
		if (document.readyState !== 'loading') {
			fn();
		} else {
			document.addEventListener('DOMContentLoaded', fn);
		}
	}

	function attachCard(card, cfg) {
		var i18n = cfg.i18n || {};

		return card.tokenize().then(function (result) {
			if (result.status !== 'OK' || !result.token) {
				var msg =
					result.errors && result.errors[0] && result.errors[0].message
						? result.errors[0].message
						: i18n.tokenizeFailed || 'Card tokenization failed.';
				throw new Error(msg);
			}

			var body = new FormData();
			body.append('action', cfg.ajaxAction);
			body.append('nonce', cfg.nonce);
			body.append('source_id', result.token);
			body.append('country', 'US');

			return fetch(cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body,
			}).then(function (response) {
				return response.json().then(function (data) {
					if (!response.ok || !data || !data.success) {
						var fail =
							data && data.data && data.data.message
								? data.data.message
								: i18n.saveFailed || 'Could not save card.';
						throw new Error(fail);
					}
					return data;
				});
			});
		});
	}

	function setBusy(buttons, busy) {
		buttons.forEach(function (btn) {
			if (btn) {
				btn.disabled = !!busy;
			}
		});
	}

	function nativeSubmit(form) {
		HTMLFormElement.prototype.submit.call(form);
	}

	ready(function () {
		var cfg = window.validakeyInstanceCardForm;
		if (!cfg || !cfg.applicationId || !window.Square) {
			return;
		}

		var form = document.getElementById(cfg.formId || 'validakey-ie-card-form');
		if (!form) {
			return;
		}

		var card = null;
		var submitBtn = form.querySelector('button[type="submit"]');
		var i18n = cfg.i18n || {};
		var chainSelector = cfg.chainSubmitSelector || '';
		var chainForm = chainSelector ? document.querySelector(chainSelector) : null;

		try {
			var payments = Square.payments(cfg.applicationId, cfg.locationId || undefined);
			payments
				.card()
				.then(function (cardInstance) {
					card = cardInstance;
					return card.attach('#' + (cfg.containerId || 'validakey-ie-card-container'));
				})
				.catch(function (err) {
					showError(form, (err && err.message) || i18n.tokenizeFailed || 'Card form failed to load.');
				});
		} catch (err) {
			showError(form, (err && err.message) || i18n.tokenizeFailed || 'Card form failed to load.');
		}

		function runAttachThen(onSuccess, busyButtons) {
			showError(form, '');
			if (!card) {
				showError(form, i18n.notReady || 'Card form is not ready.');
				return;
			}
			setBusy(busyButtons, true);

			attachCard(card, cfg)
				.then(function () {
					onSuccess();
				})
				.catch(function (err) {
					showError(form, (err && err.message) || i18n.saveFailed || 'Could not save card.');
				})
				.finally(function () {
					setBusy(busyButtons, false);
				});
		}

		if (submitBtn) {
			form.addEventListener('submit', function (event) {
				event.preventDefault();
				runAttachThen(function () {
					window.location.reload();
				}, [submitBtn]);
			});
		}

		// LicensePurchasePanel: Purchase/Request also saves the card when required.
		if (chainForm) {
			chainForm.addEventListener('submit', function (event) {
				event.preventDefault();
				var purchaseBtn = chainForm.querySelector('input[type="submit"], button[type="submit"]');
				var requireCard = chainForm.getAttribute('data-validakey-require-card') === '1';

				// Free / unpriced: never tokenize — Square would flash validation then we'd POST anyway.
				if (!requireCard) {
					nativeSubmit(chainForm);
					return;
				}

				runAttachThen(function () {
					nativeSubmit(chainForm);
				}, [purchaseBtn, submitBtn]);
			});
		}
	});
})();
