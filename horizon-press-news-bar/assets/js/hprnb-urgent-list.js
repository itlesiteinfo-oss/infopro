/**
 * Horizon Press News Bar — the Urgent switch of Posts → All Posts (2.19).
 *
 * One click ticks or unticks the article over the plugin's REST route; the button is busy meanwhile
 * (no second request), and the cell is replaced by the one the server renders from what it stored,
 * the row tinted or not accordingly, the view's count updated. On any failure nothing changes on the
 * page (the cell was never touched) and an error says so. Vanilla ES2018, no dependency but wp-a11y.
 */
(function () {
	'use strict';

	var cfg = window.hprnbUrgentList || {};
	var i18n = cfg.i18n || {};
	var ROW = 'hprnb-urgent-row';

	function speak(text, politeness) {
		if (text && window.wp && wp.a11y && typeof wp.a11y.speak === 'function') {
			wp.a11y.speak(text, politeness || 'polite');
		}
	}

	/** Shows an error under the switch until the next click, with the server's reason when it gave one. */
	function fail(cell, button, detail) {
		var old = cell.querySelector('.hprnb-urgent-cell__error');
		if (!old) {
			old = document.createElement('span');
			old.className = 'hprnb-urgent-cell__error';
			old.setAttribute('role', 'alert');
			cell.appendChild(old);
		}
		old.textContent = (i18n.error || 'Error') + (detail ? ' ' + detail : '');
		button.focus();
	}

	function toggle(button) {
		if (button.getAttribute('aria-busy') === 'true') {
			return; // A second click while saving does nothing.
		}
		var cell = button.closest('.hprnb-urgent-cell');
		var row = button.closest('tr');
		var id = parseInt(button.getAttribute('data-hprnb-post'), 10);
		if (!cell || !id || !cfg.endpoint) {
			return;
		}
		var want = button.getAttribute('aria-pressed') !== 'true';
		var error = cell.querySelector('.hprnb-urgent-cell__error');
		if (error) {
			error.remove();
		}
		button.setAttribute('aria-busy', 'true');
		button.classList.add('is-busy');
		cell.classList.add('is-busy');
		speak(i18n.saving);

		// In the user's own language, as wp.apiFetch asks for it.
		var url = cfg.endpoint + id;
		url += (url.indexOf('?') === -1 ? '?' : '&') + '_locale=user';
		fetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
			body: JSON.stringify({ urgent: want })
		}).then(function (response) {
			return response.json().then(function (data) {
				if (!response.ok || !data || typeof data.html !== 'string') {
					var refused = new Error('hprnb-urgent');
					refused.detail = data && typeof data.message === 'string' ? data.message : '';
					throw refused;
				}
				return data;
			});
		}).then(function (data) {
			// The cell as the server renders it from what it stored.
			var holder = document.createElement('div');
			holder.innerHTML = data.html;
			var fresh = holder.firstElementChild;
			if (fresh) {
				cell.parentNode.replaceChild(fresh, cell);
				var next = fresh.querySelector('.hprnb-urgent-switch');
				if (next && next.focus) {
					next.focus();
				}
			}
			if (row) {
				row.classList.toggle(ROW, data.state === 'active');
			}
			document.querySelectorAll('.hprnb-urgent-count').forEach(function (count) {
				count.textContent = String(data.count);
			});
			speak(data.state === 'active' ? i18n.on : (data.state === 'armed' ? i18n.armed : i18n.off));
		}).catch(function (reason) {
			// A network failure, a page that is not JSON, or a refusal: the cell was never touched.
			var detail = reason && typeof reason.detail === 'string' ? reason.detail : '';
			button.removeAttribute('aria-busy');
			button.classList.remove('is-busy');
			cell.classList.remove('is-busy');
			fail(cell, button, detail);
			speak((i18n.error || '') + (detail ? ' ' + detail : ''), 'assertive');
		});
	}

	document.addEventListener('click', function (event) {
		var button = event.target.closest && event.target.closest('button.hprnb-urgent-switch');
		if (button && button.closest('.wp-list-table')) {
			event.preventDefault();
			toggle(button);
		}
	});
}());
