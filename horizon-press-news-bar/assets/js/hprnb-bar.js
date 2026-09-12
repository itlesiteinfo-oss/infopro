/**
 * Horizon Press News Bar — interactive front script.
 *
 * Loaded (deferred) only when a feature needs it: ticker (marquee / rotate /
 * manual), close button, relative time. Configuration is read from the
 * `data-hprnb-*` attributes of the `<aside class="hprnb-bar">` element so a bar
 * injected later by the hybrid bootstrap behaves exactly like a server-rendered
 * one. Exposes `window.hprnbBar.init(root)` for that late injection.
 *
 * Vanilla ES2018 IIFE. No dependencies, no console output, no setInterval
 * moving pixels (marquee is a CSS transform animation), every storage access
 * in try/catch, timers suspended while the document is hidden.
 */
(function () {
	'use strict';

	var DISMISS_KEY = 'hprnb_dismissed_until';

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	function prefersReducedMotion() {
		try {
			return !!( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches );
		} catch ( e ) {
			return false;
		}
	}

	function isRtl( el ) {
		return window.getComputedStyle( el ).direction === 'rtl';
	}

	function debounce( fn, wait ) {
		var timer = null;
		return function () {
			if ( timer !== null ) {
				clearTimeout( timer );
			}
			timer = setTimeout( function () {
				timer = null;
				fn();
			}, wait );
		};
	}

	/**
	 * Re-runs `fn` when `el` is resized: ResizeObserver when available, otherwise
	 * a debounced window resize listener.
	 */
	function observeSize( el, fn ) {
		var debounced = debounce( fn, 200 );
		if ( typeof window.ResizeObserver === 'function' ) {
			try {
				new window.ResizeObserver( debounced ).observe( el );
				return;
			} catch ( e ) {
				// Fall through to the resize listener.
			}
		}
		window.addEventListener( 'resize', debounced );
	}

	function hideToggle( toggle ) {
		if ( toggle ) {
			toggle.hidden = true;
		}
	}

	function forEach( list, fn ) {
		Array.prototype.forEach.call( list, fn );
	}

	/* ------------------------------------------------------------------ */
	/* Pause controller (shared by marquee and rotate)                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Combines every pause source. A user pause (toggle button) is sticky: a
	 * hover/focus leaving or the tab becoming visible never resumes it.
	 *
	 * @param {Element}  aside    The bar.
	 * @param {Object}   cfg      Configuration.
	 * @param {Element}  toggle   Pause/Play button (may be null).
	 * @param {Element}  region   Element whose inner focus pauses the bar.
	 * @param {Function} onChange Called with the paused state after each change.
	 */
	function createPauseController( aside, cfg, toggle, region, onChange ) {
		var state = { user: false, hover: false, focus: false, hidden: !!document.hidden };

		function paused() {
			return state.user || state.hover || state.focus || state.hidden;
		}

		function update() {
			var p = paused();
			aside.classList.toggle( 'hprnb-bar--paused', p );
			if ( toggle ) {
				var label = state.user ? toggle.getAttribute( 'data-hprnb-label-play' ) : toggle.getAttribute( 'data-hprnb-label-pause' );
				if ( label ) {
					toggle.setAttribute( 'aria-label', label );
				}
			}
			if ( onChange ) {
				onChange( p );
			}
		}

		if ( toggle ) {
			toggle.addEventListener( 'click', function () {
				state.user = ! state.user;
				update();
			} );
		}

		if ( cfg.hover ) {
			aside.addEventListener( 'mouseenter', function () {
				state.hover = true;
				update();
			} );
			aside.addEventListener( 'mouseleave', function () {
				state.hover = false;
				update();
			} );
		}

		region.addEventListener( 'focusin', function () {
			state.focus = true;
			update();
		} );
		region.addEventListener( 'focusout', function ( event ) {
			if ( ! event.relatedTarget || ! region.contains( event.relatedTarget ) ) {
				state.focus = false;
				update();
			}
		} );

		document.addEventListener( 'visibilitychange', function () {
			state.hidden = !!document.hidden;
			update();
		} );

		update();

		return { paused: paused, update: update };
	}

	/* ------------------------------------------------------------------ */
	/* Marquee                                                             */
	/* ------------------------------------------------------------------ */

	function setupMarquee( aside, cfg, toggle ) {
		var viewport = aside.querySelector( '.hprnb-bar__viewport' );
		var list = aside.querySelector( '.hprnb-bar__list' );
		if ( ! viewport || ! list ) {
			return;
		}

		var track = document.createElement( 'div' );
		track.className = 'hprnb-bar__track';
		list.parentNode.insertBefore( track, list );
		track.appendChild( list );

		var clone = null;
		createPauseController( aside, cfg, toggle, viewport, null );

		function measure() {
			var listWidth = list.getBoundingClientRect().width;
			var fits = listWidth <= viewport.clientWidth;

			if ( fits ) {
				aside.classList.remove( 'hprnb-bar--marquee-on' );
				if ( clone ) {
					track.removeChild( clone );
					clone = null;
				}
				hideToggle( toggle );
				return;
			}

			if ( ! clone ) {
				clone = list.cloneNode( true );
				clone.classList.add( 'hprnb-bar__list--clone' );
				clone.setAttribute( 'aria-hidden', 'true' );
				forEach( clone.querySelectorAll( 'a, button' ), function ( el ) {
					el.setAttribute( 'tabindex', '-1' );
				} );
				track.appendChild( clone );
			}

			var gap = parseFloat( window.getComputedStyle( track ).columnGap ) || 0;
			var distance = listWidth + gap;
			track.style.setProperty( '--hprnb-track', distance + 'px' );
			track.style.setProperty( '--hprnb-duration', ( distance / Math.max( 10, cfg.speed ) ) + 's' );
			aside.classList.toggle( 'hprnb-bar--rtl', isRtl( aside ) );
			aside.classList.add( 'hprnb-bar--marquee-on' );
			if ( toggle ) {
				toggle.hidden = false;
			}
		}

		measure();
		observeSize( viewport, measure );
	}

	/* ------------------------------------------------------------------ */
	/* Rotate                                                              */
	/* ------------------------------------------------------------------ */

	function setupRotate( aside, cfg, toggle ) {
		var viewport = aside.querySelector( '.hprnb-bar__viewport' );
		var items = aside.querySelectorAll( '.hprnb-bar__item' );
		if ( ! viewport || items.length < 2 ) {
			hideToggle( toggle );
			return;
		}

		var index = 0;
		var timer = null;
		var ctrl = null;

		function show( i ) {
			forEach( items, function ( li, k ) {
				li.hidden = ( k !== i );
			} );
		}

		function stop() {
			if ( timer !== null ) {
				clearTimeout( timer );
				timer = null;
			}
		}

		function tick() {
			timer = setTimeout( function () {
				timer = null;
				index = ( index + 1 ) % items.length;
				show( index );
				if ( ctrl && ! ctrl.paused() ) {
					tick();
				}
			}, cfg.interval );
		}

		function start() {
			if ( timer === null ) {
				tick();
			}
		}

		show( 0 );
		ctrl = createPauseController( aside, cfg, toggle, viewport, function ( paused ) {
			if ( paused ) {
				stop();
			} else {
				start();
			}
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Manual                                                              */
	/* ------------------------------------------------------------------ */

	function setupManual( aside, reduced ) {
		var viewport = aside.querySelector( '.hprnb-bar__viewport' );
		var prev = aside.querySelector( '.hprnb-bar__btn--prev' );
		var next = aside.querySelector( '.hprnb-bar__btn--next' );
		if ( ! viewport || ! prev || ! next ) {
			return;
		}

		function update() {
			var max = viewport.scrollWidth - viewport.clientWidth;
			var pos = Math.abs( viewport.scrollLeft );
			var fits = max <= 2;
			prev.disabled = fits || pos <= 2;
			next.disabled = fits || pos >= max - 2;
		}

		function scroll( direction ) {
			var delta = viewport.clientWidth * 0.8 * direction;
			if ( isRtl( aside ) ) {
				delta = -delta;
			}
			try {
				viewport.scrollBy( { left: delta, behavior: reduced ? 'auto' : 'smooth' } );
			} catch ( e ) {
				viewport.scrollLeft += delta;
			}
		}

		prev.addEventListener( 'click', function () {
			scroll( -1 );
		} );
		next.addEventListener( 'click', function () {
			scroll( 1 );
		} );
		viewport.addEventListener( 'scroll', update, { passive: true } );
		observeSize( viewport, update );
		update();
	}

	/* ------------------------------------------------------------------ */
	/* Relative time                                                       */
	/* ------------------------------------------------------------------ */

	function setupRelativeTime( aside, cfg ) {
		if ( typeof Intl === 'undefined' || typeof Intl.RelativeTimeFormat !== 'function' ) {
			return;
		}
		var times = aside.querySelectorAll( 'time[data-hprnb-ts]' );
		if ( ! times.length ) {
			return;
		}

		var rtf;
		try {
			rtf = new Intl.RelativeTimeFormat( document.documentElement.lang || 'en', { numeric: 'auto' } );
		} catch ( e ) {
			return;
		}

		var timer = null;
		var maxAge = cfg.reltimeMax * 3600;

		function label( age ) {
			if ( age < 60 ) {
				return rtf.format( 0, 'second' );
			}
			if ( age < 3600 ) {
				return rtf.format( -Math.floor( age / 60 ), 'minute' );
			}
			if ( age < 86400 ) {
				return rtf.format( -Math.floor( age / 3600 ), 'hour' );
			}
			return rtf.format( -Math.floor( age / 86400 ), 'day' );
		}

		function refresh() {
			var now = Math.floor( Date.now() / 1000 );
			forEach( times, function ( el ) {
				var ts = parseInt( el.getAttribute( 'data-hprnb-ts' ), 10 );
				if ( ! ts ) {
					return;
				}
				var age = Math.max( 0, now - ts );
				if ( age >= maxAge ) {
					var abs = el.getAttribute( 'data-hprnb-abs' );
					if ( abs ) {
						el.textContent = abs;
					}
					return;
				}
				el.textContent = label( age );
			} );
		}

		function schedule() {
			timer = setTimeout( function () {
				timer = null;
				refresh();
				if ( ! document.hidden ) {
					schedule();
				}
			}, 60000 );
		}

		document.addEventListener( 'visibilitychange', function () {
			if ( document.hidden ) {
				if ( timer !== null ) {
					clearTimeout( timer );
					timer = null;
				}
				return;
			}
			refresh();
			if ( timer === null ) {
				schedule();
			}
		} );

		refresh();
		if ( ! document.hidden ) {
			schedule();
		}
	}

	/* ------------------------------------------------------------------ */
	/* Close button                                                        */
	/* ------------------------------------------------------------------ */

	function moveFocusAway( button ) {
		if ( document.activeElement !== button ) {
			return;
		}
		var target = document.querySelector( 'main, [role="main"]' ) || document.body;
		var temporary = false;
		if ( ! target.hasAttribute( 'tabindex' ) ) {
			target.setAttribute( 'tabindex', '-1' );
			temporary = true;
		}
		try {
			target.focus( { preventScroll: true } );
		} catch ( e ) {
			target.focus();
		}
		if ( temporary ) {
			target.removeAttribute( 'tabindex' );
		}
	}

	function setupClose( root, aside, cfg ) {
		var button = aside.querySelector( '.hprnb-bar__btn--close' );
		if ( ! button ) {
			return;
		}
		button.addEventListener( 'click', function () {
			moveFocusAway( button );
			aside.hidden = true;
			root.hidden = true;
			document.body.classList.remove( 'hprnb-reserve' );
			if ( cfg.remember ) {
				try {
					localStorage.setItem( DISMISS_KEY, String( Date.now() + cfg.dismissHours * 3600000 ) );
				} catch ( e ) {
					// Storage unavailable: the bar is still closed for this page.
				}
			}
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Init                                                                */
	/* ------------------------------------------------------------------ */

	function readConfig( aside ) {
		var d = aside.dataset;
		return {
			ticker: d.hprnbTicker || 'none',
			speed: parseInt( d.hprnbSpeed, 10 ) || 60,
			interval: Math.max( 1000, parseInt( d.hprnbInterval, 10 ) || 5000 ),
			hover: d.hprnbHover === '1',
			remember: d.hprnbRemember === '1',
			dismissHours: parseInt( d.hprnbDismissHours, 10 ) || 24,
			reltime: d.hprnbReltime === '1',
			reltimeMax: parseInt( d.hprnbReltimeMax, 10 ) || 48
		};
	}

	/**
	 * Initialises the bar found in `root`. Idempotent per aside.
	 *
	 * @param {Element|null} root The #hprnb-root element.
	 */
	function init( root ) {
		if ( ! root ) {
			return;
		}
		var aside = root.querySelector( '.hprnb-bar' );
		if ( ! aside || aside.getAttribute( 'data-hprnb-init' ) === '1' ) {
			return;
		}
		aside.setAttribute( 'data-hprnb-init', '1' );

		var cfg = readConfig( aside );
		var reduced = prefersReducedMotion();
		var toggle = aside.querySelector( '.hprnb-bar__btn--toggle' );

		if ( reduced ) {
			aside.classList.add( 'hprnb-bar--reduced' );
		}

		setupClose( root, aside, cfg );

		if ( cfg.reltime ) {
			setupRelativeTime( aside, cfg );
		}

		if ( cfg.ticker === 'marquee' ) {
			if ( reduced ) {
				hideToggle( toggle );
			} else {
				setupMarquee( aside, cfg, toggle );
			}
		} else if ( cfg.ticker === 'rotate' ) {
			if ( reduced ) {
				hideToggle( toggle );
			} else {
				setupRotate( aside, cfg, toggle );
			}
		} else if ( cfg.ticker === 'manual' ) {
			setupManual( aside, reduced );
		}
	}

	window.hprnbBar = { init: init };

	function boot() {
		init( document.getElementById( 'hprnb-root' ) );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot, { once: true } );
	} else {
		boot();
	}
})();
