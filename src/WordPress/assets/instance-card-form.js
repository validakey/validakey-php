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

		form.addEventListener('submit', function (event) {
			event.preventDefault();
			showError(form, '');
			if (!card) {
				showError(form, i18n.notReady || 'Card form is not ready.');
				return;
			}
			if (submitBtn) {
				submitBtn.disabled = true;
			}

			card
				.tokenize()
				.then(function (result) {
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
				})
				.then(function () {
					window.location.reload();
				})
				.catch(function (err) {
					showError(form, (err && err.message) || i18n.saveFailed || 'Could not save card.');
				})
				.finally(function () {
					if (submitBtn) {
						submitBtn.disabled = false;
					}
				});
		});
	});
})();
