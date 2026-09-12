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
	 * Stores exactly { generated_at, count, html }.
	 *
	 * @param {Object} p Validated payload.
	 */
	function saveSession(p) {
		try {
			sessionStorage.setItem(SESSION_KEY, JSON.stringify({ generated_at: p.generated_at, count: p.count, html: p.html }));
		} catch (e) {
			// Storage unavailable or full: nothing to do.
		}
	}

	function run() {
		var root = document.getElementById('hprnb-root');
		if (!root) {
			return;
		}
		if (document.documentElement.classList.contains('hprnb-dismissed')) {
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
			var height = root.style.getPropertyValue('--hprnb-height');
			if (height) {
				document.body.style.setProperty('--hprnb-height', height);
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
		 * @param {Object} p Payload that passed isValid().
		 */
		function apply(p) {
			if (p.count === 0 || p.html === '') {
				root.innerHTML = '';
				root.hidden = true;
				root.dataset.hprnbEmpty = '1';
				document.body.classList.remove('hprnb-reserve');
				return;
			}
			ensureCss();
			root.innerHTML = p.html;
			root.hidden = false;
			root.dataset.hprnbEmpty = '0';
			root.dataset.hprnbGenerated = String(p.generated_at);
			ensureLayout();
			ensureJs();
		}

		// 1. Fresh SSR: keep it, remember it for later pages when useful, stop.
		if (age <= stale) {
			ensureLayout();
			var stored = readSession();
			if (!stored || stored.generated_at < ssrGen) {
				saveSession({ generated_at: ssrGen, count: hasAside ? 1 : 0, html: root.innerHTML });
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
