/**
 * Horizon Press News Bar — the Urgent switch of Posts → All Posts (2.19).
 *
 * One click ticks or unticks the article over the plugin's REST route; the button is busy meanwhile
 * (no second request), and the cell is replaced by the one the server renders from what it stored,
 * the row tinted or not accordingly, the view's count updated. A refusal the server explains changed
 * nothing: the cell stays and says why. Any other failure (no reply, a reply that cannot be read) may
 * have been saved or not: the cell is read again from the server, so the page never shows a state
 * other than the stored one — or, when even that fails, says it could not be confirmed. An expired
 * REST nonce is renewed once, as wp.apiFetch does; a row leaves the red bar on screen when its
 * countdown runs out. Vanilla ES2018, no dependency but wp-a11y.
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

	/** A fresh REST nonce from core (admin-ajax.php?action=rest-nonce). */
	function renew() {
		return fetch(cfg.renew, { credentials: 'same-origin' }).then(function (response) {
			if (!response.ok) {
				throw new Error('hprnb-urgent');
			}
			return response.text();
		}).then(function (nonce) {
			cfg.nonce = nonce.trim();
		});
	}

	/**
	 * One request to the route. Resolves with a readable answer; rejects with an error whose `code` is
	 * set when the server refused before writing anything (a 4xx it explained), empty otherwise.
	 */
	function call(id, method, body, renewed) {
		var url = cfg.endpoint + id;
		// In the user's own language, as wp.apiFetch asks for it.
		url += (url.indexOf('?') === -1 ? '?' : '&') + '_locale=user';
		var headers = { 'X-WP-Nonce': cfg.nonce || '' };
		if (body) {
			headers['Content-Type'] = 'application/json';
		}
		return fetch(url, {
			method: method,
			credentials: 'same-origin',
			headers: headers,
			body: body ? JSON.stringify(body) : undefined
		}).then(function (response) {
			return response.json().catch(function () {
				return null;
			}).then(function (data) {
				if (response.ok && data && typeof data.html === 'string') {
					return data;
				}
				// A list left open for hours, a new login: the nonce is renewed, then the request sent once more.
				if (!renewed && cfg.renew && data && 'rest_cookie_invalid_nonce' === data.code) {
					return renew().then(function () {
						return call(id, method, body, true);
					});
				}
				var failure = new Error('hprnb-urgent');
				var explained = data && typeof data.code === 'string' && response.status >= 400 && response.status < 500;
				failure.code = explained ? data.code : '';
				failure.detail = explained && typeof data.message === 'string' ? data.message : '';
				throw failure;
			});
		});
	}

	/** Shows an error under the switch until the next click (announced by its role). */
	function fail(cell, text) {
		var old = cell.querySelector('.hprnb-urgent-cell__error');
		if (!old) {
			old = document.createElement('span');
			old.className = 'hprnb-urgent-cell__error';
			old.setAttribute('role', 'alert');
			cell.appendChild(old);
		}
		old.textContent = text;
	}

	function settle(cell, button) {
		button.removeAttribute('aria-busy');
		button.classList.remove('is-busy');
		cell.classList.remove('is-busy');
	}

	/** The cell as the server renders it; the row and the count follow. Returns the new cell. */
	function show(cell, data, clicked) {
		var holder = document.createElement('div');
		holder.innerHTML = data.html;
		var fresh = holder.firstElementChild;
		if (!fresh || !cell.parentNode) {
			return cell;
		}
		// The focus moves to the new switch only if it was on this cell (or nowhere, after a click on it).
		var focused = document.activeElement;
		var had = cell.contains(focused) || (clicked && (!focused || focused === document.body));
		var row = cell.closest('tr');
		cell.parentNode.replaceChild(fresh, cell);
		var next = fresh.querySelector('button.hprnb-urgent-switch');
		if (had && next) {
			next.focus();
		}
		if (row) {
			row.classList.toggle(ROW, data.state === 'active');
		}
		document.querySelectorAll('.hprnb-urgent-count').forEach(function (count) {
			count.textContent = String(data.count);
		});
		watch(fresh);
		return fresh;
	}

	function said(state) {
		return state === 'active' ? i18n.on : (state === 'armed' ? i18n.armed : i18n.off);
	}

	function toggle(button) {
		if (button.getAttribute('aria-busy') === 'true') {
			return; // A second click while saving does nothing.
		}
		var cell = button.closest('.hprnb-urgent-cell');
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

		call(id, 'POST', { urgent: want }).then(function (data) {
			show(cell, data, true);
			speak(said(data.state));
		}).catch(function (reason) {
			if (reason && reason.code) {
				// Refused before anything was written: the cell stays, with the server's reason.
				settle(cell, button);
				fail(cell, (i18n.refused || '') + (reason.detail ? ' ' + reason.detail : ''));
				return undefined;
			}
			// Saved or not, nobody knows: the page shows what the server holds now.
			return call(id, 'GET').then(function (data) {
				var fresh = show(cell, data, true);
				if ((data.state !== 'off') === want) {
					speak(said(data.state));
				} else {
					fail(fresh, i18n.error || '');
				}
			}).catch(function () {
				settle(cell, button);
				fail(cell, i18n.unsure || '');
			});
		});
	}

	/** An article in the red bar: read again when its countdown is over, so its row leaves the red too. */
	function watch(cell) {
		var left = parseInt(cell.getAttribute('data-hprnb-left'), 10);
		var id = parseInt(cell.getAttribute('data-hprnb-post'), 10);
		if (!(left > 0) || !id || !cfg.endpoint) {
			return;
		}
		setTimeout(function () {
			var button = cell.querySelector('.hprnb-urgent-switch');
			if (!cell.isConnected || (button && button.getAttribute('aria-busy') === 'true')) {
				return; // Replaced meanwhile (its successor has its own timer), or a click on its way.
			}
			call(id, 'GET').then(function (data) {
				if (cell.isConnected) {
					show(cell, data, false);
				}
			}).catch(function () {});
		}, Math.min(left * 1000 + 1500, 2147483647));
	}

	document.querySelectorAll('.wp-list-table .hprnb-urgent-cell[data-hprnb-left]').forEach(watch);

	document.addEventListener('click', function (event) {
		var button = event.target.closest && event.target.closest('button.hprnb-urgent-switch');
		if (button && button.closest('.wp-list-table')) {
			event.preventDefault();
			toggle(button);
		}
	});
}());
