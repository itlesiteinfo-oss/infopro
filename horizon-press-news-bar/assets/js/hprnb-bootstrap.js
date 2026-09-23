/**
 * Horizon Press News Bar — hybrid freshness bootstrap.
 *
 * Loaded (deferred) on every eligible front-end page in hybrid render mode.
 * A fresh server-rendered bar is kept untouched; a stale one is refreshed
 * from sessionStorage or from at most ONE request to the public REST endpoint.
 * Normative algorithm: docs/dev/CONTRACT.md §14.2 (spec §12, §12.3, §28).
 *
 * Vanilla ES2018 IIFE: no globals, no timers, no retries, no console output,
 * every storage access wrapped in try/catch, and innerHTML only ever receives
 * a string that passed isValid().
 */
(function () {
	'use strict';

	var SESSION_KEY = 'hprnb_payload';

	/**
	 * A payload (REST body or sessionStorage entry) is usable only when its
	 * three fields have the expected primitive types.
	 *
	 * @param {*} p Candidate payload.
	 * @return {boolean} Whether the payload may be applied.
	 */
	function isValid(p) {
		return !!p && typeof p === 'object' && typeof p.html === 'string' && Number.isFinite(p.generated_at) && Number.isFinite(p.count);
	}

	/**
	 * Urgent articles (2.14) ride in the same payload, optionally: an older
	 * body or session entry simply carries none.
	 *
	 * @param {Object} p Validated payload.
	 * @return {number} How many urgent headlines the payload carries.
	 */
	function urgentCount(p) {
		return (Number.isFinite(p.urgent_count) && p.urgent_count > 0 && typeof p.urgent_html === 'string' && p.urgent_html !== '') ? p.urgent_count : 0;
	}

	/**
	 * @param {Object} p Validated payload.
	 * @return {string} The urgent bar markup, '' without one.
	 */
	function urgentHtml(p) {
		return urgentCount(p) ? p.urgent_html : '';
	}

	/**
	 * @return {Object|null} The stored payload, or null when absent/invalid/unreadable.
	 */
	function readSession() {
		try {
			var raw = sessionStorage.getItem(SESSION_KEY);
			if (!raw) {
				return null;
			}
			var p = JSON.parse(raw);
			return isValid(p) ? p : null;
		} catch (e) {
			return null;
		}
	}

	/**
	 * Stores exactly { generated_at, count, html, urgent_count, urgent_html, urgent_devices }.
	 *
	 * @param {Object} p Validated payload.
	 */
	function saveSession(p) {
		try {
			sessionStorage.setItem(SESSION_KEY, JSON.stringify({ generated_at: p.generated_at, count: p.count, html: p.html, urgent_count: urgentCount(p), urgent_html: urgentHtml(p), urgent_devices: p.urgent_devices }));
		} catch (e) {
			// Storage unavailable or full: nothing to do.
		}
	}

	function run() {
		var root = document.getElementById('hprnb-root');
		if (!root) {
			return;
		}
		// 2.15: a page may show only one of the two bars ("news" or "urgent"), and the URGENT bar may be
		// switched off ("off"). A reader who closed the news bar still gets urgent articles.
		var show = root.dataset.hprnbShow || 'all';
		var urgentOff = show === 'news' || root.dataset.hprnbUrgent === 'off';
		if (document.documentElement.classList.contains('hprnb-dismissed') && urgentOff) {
			return;
		}
		var endpoint = root.dataset.hprnbEndpoint;
		if (!endpoint) {
			return; // PHP render mode: no freshness control.
		}

		var ssrGen = +root.dataset.hprnbGenerated || 0;
		var stale = +root.dataset.hprnbStale || 180;
		var now = Math.floor(Date.now() / 1000);
		var age = now - ssrGen; // Negative (client clock behind) counts as fresh.
		var hasAside = !!root.querySelector('.hprnb-bar');

		/**
		 * Reserve layout: copy the root's inline --hprnb-height onto <body>
		 * (the body rule consumes it), then add body.hprnb-reserve — only when
		 * a bar is actually present.
		 */
		function ensureLayout() {
			if (root.dataset.hprnbLayout !== 'reserve' || !root.querySelector('.hprnb-bar')) {
				return;
			}
			// Urgent articles in front (2.14): their bar has one height of its own on each device it is
			// switched on for (2.16).
			var urgent = root.classList.contains('hprnb-root--urgent');
			var ud = urgent && urgentOn('d');
			var um = urgent && urgentOn('m');
			var height = root.style.getPropertyValue(ud ? '--hprnb-u-height' : '--hprnb-height') || root.style.getPropertyValue('--hprnb-height');
			if (height) {
				document.body.style.setProperty('--hprnb-height', height);
			}
			var mobileHeight = root.style.getPropertyValue(um ? '--hprnb-u-m-height' : '--hprnb-m-height') || root.style.getPropertyValue('--hprnb-m-height');
			if (mobileHeight) {
				document.body.style.setProperty('--hprnb-m-height', mobileHeight);
			}
			var gap = root.style.getPropertyValue('--hprnb-m-gap');
			document.body.style.setProperty('--hprnb-m-gap', (um ? '' : gap) || '0px');
			var peek = root.style.getPropertyValue('--hprnb-peek');
			if (peek) {
				document.body.style.setProperty('--hprnb-peek', peek);
			}
			document.body.classList.add('hprnb-reserve');
		}

		function ensureCss() {
			var href = root.dataset.hprnbCss;
			// WordPress prints the handle as hprnb-bar-css, or hprnb-bar-rtl-css on RTL sites.
			if (!href || document.getElementById('hprnb-bar-css') || document.getElementById('hprnb-bar-rtl-css')) {
				return;
			}
			var link = document.createElement('link');
			link.id = 'hprnb-bar-css';
			link.rel = 'stylesheet';
			link.href = href;
			document.head.appendChild(link);
		}

		function ensureJs() {
			var src = root.dataset.hprnbJs;
			if (!src) {
				return;
			}
			if (window.hprnbBar && typeof window.hprnbBar.init === 'function') {
				window.hprnbBar.init(root);
				return;
			}
			if (document.getElementById('hprnb-bar-js')) {
				return; // Already enqueued by PHP: it self-initialises on load.
			}
			var script = document.createElement('script');
			script.id = 'hprnb-bar-js';
			script.src = src;
			script.defer = true;
			document.head.appendChild(script);
		}

		/**
		 * @param {string} p 'd' or 'm'.
		 * @return {boolean} Whether the URGENT bar is switched on for that device (2.16).
		 */
		function urgentOn(p) {
			return !root.classList.contains('hprnb-root--u-no-' + p);
		}

		/**
		 * The news bar's device restriction is parked while the URGENT bar is in front on that device
		 * (the interactive script puts it back when it hands over).
		 *
		 * @param {boolean} urgent Whether the URGENT bar is in front.
		 */
		function parkDevices(urgent) {
			['mobile', 'desktop'].forEach(function (d) {
				var hide = 'hprnb-hide-' + d;
				var park = 'hprnb-news-hide-' + d;
				if (urgent && urgentOn(d.charAt(0)) && root.classList.contains(hide)) {
					root.classList.replace(hide, park);
				} else if (!urgent && root.classList.contains(park)) {
					root.classList.replace(park, hide);
				}
			});
		}

		/**
		 * @param {Object} p Payload that passed isValid().
		 */
		function apply(p) {
			var urgent = urgentOff ? 0 : urgentCount(p);
			var html = (show === 'urgent' || p.count === 0 || p.html === '') ? '' : p.html;
			// The bars running on the old markup stop first, their timers with them.
			if (window.hprnbBar && typeof window.hprnbBar.destroy === 'function') {
				window.hprnbBar.destroy(root);
			}
			// Where the URGENT bar was in front, the server left out the news bar's wait.
			var was = root.classList.contains('hprnb-root--urgent');
			var front = { d: was && urgentOn('d'), m: was && urgentOn('m') };
			// 2.16: the devices the URGENT bar is switched on for, newer than a page from a page cache.
			var devices = p.urgent_devices;
			if (devices && typeof devices === 'object') {
				root.classList.toggle('hprnb-root--u-no-d', devices.d === false);
				root.classList.toggle('hprnb-root--u-no-m', devices.m === false);
			}
			if (html === '' && urgent === 0) {
				root.innerHTML = '';
				root.hidden = true;
				root.dataset.hprnbEmpty = '1';
				root.dataset.hprnbCount = '0';
				root.dataset.hprnbUrgent = '0';
				root.classList.remove('hprnb-root--urgent');
				document.body.classList.remove('hprnb-reserve');
				return;
			}
			ensureCss();
			root.innerHTML = (urgent ? p.urgent_html : '') + html;
			root.hidden = false;
			root.dataset.hprnbEmpty = '0';
			root.dataset.hprnbCount = String(html === '' ? 0 : p.count);
			root.dataset.hprnbUrgent = String(urgent);
			root.dataset.hprnbGenerated = String(p.generated_at);
			// Urgent articles wait for nobody: in front at once, whatever the news bar waits for.
			// The interactive script prunes the expired ones and hands over when none is left.
			root.classList.toggle('hprnb-root--urgent', urgent > 0);
			parkDevices(urgent > 0);
			var reveal = {};
			try {
				reveal = JSON.parse(root.dataset.hprnbReveal || '{}') || {};
			} catch (e) {
				// No reveal data: the news bar shows at once.
			}
			['d', 'm'].forEach(function (d) {
				if (urgent > 0 && urgentOn(d)) {
					root.classList.remove('hprnb-root--' + d + '-pending');
					document.body.classList.remove('hprnb-' + d + '-pending');
				} else if (front[d] && html !== '' && reveal[d] && reveal[d].mode && reveal[d].mode !== 'immediate') {
					// The news bar takes over there: it waits for the reader as the server renders it.
					root.classList.add('hprnb-root--' + d + '-pending', 'hprnb-root--reveal');
					document.body.classList.add('hprnb-' + d + '-pending');
				}
			});
			ensureLayout();
			ensureJs();
		}

		// 1. Fresh SSR: keep it, remember it for later pages when useful, stop.
		if (age <= stale) {
			ensureLayout();
			var stored = readSession();
			// Only a page that shows everything the payload carries may stand for the others.
			if (show === 'all' && (!stored || stored.generated_at < ssrGen)) {
				var ssrUrgent = root.querySelector('.hprnb-bar--urgent');
				var ssrBar = root.querySelector('.hprnb-bar:not(.hprnb-bar--urgent)');
				saveSession({
					generated_at: ssrGen,
					count: ssrBar ? (+root.dataset.hprnbCount || 1) : 0,
					html: ssrBar ? ssrBar.outerHTML : '',
					urgent_count: ssrUrgent ? (+root.dataset.hprnbUrgent || 1) : 0,
					urgent_html: ssrUrgent ? ssrUrgent.outerHTML : '',
					urgent_devices: { d: urgentOn('d'), m: urgentOn('m') }
				});
			}
			return;
		}

		// 2. Stale SSR: a session payload may replace it only when it is still
		//    fresh AND not older than the SSR (never overwrite a newer SSR).
		var session = readSession();
		if (session && now - session.generated_at <= stale && session.generated_at >= ssrGen) {
			apply(session);
			return;
		}

		// 3. Otherwise exactly one anonymous REST request; any failure keeps the SSR.
		if (typeof window.fetch !== 'function') {
			return;
		}
		window.fetch(endpoint, { credentials: 'omit', headers: { Accept: 'application/json' } })
			.then(function (res) {
				return res.ok ? res.json() : null;
			})
			.then(function (p) {
				if (!isValid(p) || p.generated_at < ssrGen) {
					return; // Invalid body, or older than the SSR: ignore.
				}
				apply(p);
				saveSession(p);
			})
			.catch(function () {
				// Network, HTTP or JSON failure: keep the server-rendered bar, no retry.
			});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', run, { once: true });
	} else {
		run();
	}
})();
