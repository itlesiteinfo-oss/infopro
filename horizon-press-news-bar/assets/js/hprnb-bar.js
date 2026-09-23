/**
 * Horizon Press News Bar — interactive front script.
 *
 * Loaded (deferred) only when a feature needs it: ticker (marquee / rotate /
 * manual, desktop or mobile), close button, relative time, stacked mobile
 * presentation. Configuration is read from the `data-hprnb-*` attributes of the
 * `<aside class="hprnb-bar">` (cached markup) and from `#hprnb-root`
 * (`data-hprnb-mobile`, assembled outside the cache), so a bar injected later by
 * the hybrid bootstrap behaves exactly like a server-rendered one.
 *
 * The effective mode depends on the root width (< 768px = mobile, the same
 * threshold as the CSS container query); every listener, timer and DOM change
 * is registered on a per-bar state so the bar can be destroyed and initialised
 * again when the threshold is crossed. Exposes window.hprnbBar = { init, destroy }.
 *
 * Vanilla ES2018 IIFE. No dependencies, no console output, no setInterval moving
 * pixels, storage access in try/catch, timers suspended while the tab is hidden.
 */
(function () {
	'use strict';

	var DISMISS_KEY = 'hprnb_dismissed_until';
	var NARROW = 768;

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

	function forEach( list, fn ) {
		Array.prototype.forEach.call( list, fn );
	}

	function parseJson( text ) {
		try {
			var value = JSON.parse( text );
			return value && typeof value === 'object' ? value : {};
		} catch ( e ) {
			return {};
		}
	}

	var TOP_ZONE = 120; // px: "top of the page" zone (never collapsed there).
	var CHEVRON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m6 15 6-6 6 6"/></svg>';
	var FIELD = 'input:not([type=button]):not([type=submit]):not([type=checkbox]):not([type=radio]):not([type=range]), textarea, select, [contenteditable="true"]';

	/** A phone in landscape (short screen): one line, never collapsed. Same media query as the stylesheet. */
	function isShortScreen() {
		return !! ( window.matchMedia && window.matchMedia( '(max-height: 480px) and (max-width: 1023.98px)' ).matches );
	}

	function isKeyboardFocus( el ) {
		try {
			return el.matches( ':focus-visible' );
		} catch ( e ) {
			return true;
		}
	}

	function isNarrow( root ) {
		// A "flat" root (admin Desktop preview) opts out of the container query.
		if ( root.classList.contains( 'hprnb-root--flat' ) ) {
			return false;
		}
		var width = root.getBoundingClientRect().width || window.innerWidth;
		return width < NARROW;
	}

	/**
	 * Per-bar state: listeners, timers and DOM changes registered here are undone by destroy().
	 */
	function createState() {
		var state = { cleanups: [] };
		state.on = function ( target, type, fn, opts ) {
			target.addEventListener( type, fn, opts );
			state.cleanups.push( function () {
				target.removeEventListener( type, fn, opts );
			} );
		};
		state.add = function ( fn ) {
			state.cleanups.push( fn );
		};
		state.addClass = function ( el, name, on ) {
			if ( on === false ) {
				return;
			}
			el.classList.add( name );
			state.add( function () {
				el.classList.remove( name );
			} );
		};
		state.hide = function ( el, hidden ) {
			if ( ! el || el.hidden === hidden ) {
				return;
			}
			var previous = el.hidden;
			el.hidden = hidden;
			state.add( function () {
				el.hidden = previous;
			} );
		};
		state.timer = function ( fn, delay ) {
			var id = setTimeout( fn, delay );
			state.add( function () {
				clearTimeout( id );
			} );
			return id;
		};
		return state;
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
	 * Both profiles asked for the bar inside the article, at two different paragraphs: the server
	 * rendered it where the desktop wants it and left an empty slot for the phone. Move the root
	 * between the two, remembering where it came from.
	 *
	 * @param {Element} root   The #hprnb-root element.
	 * @param {boolean} mobile Whether the mobile profile is the active one.
	 */
	function relocate( root, mobile ) {
		var slot = document.querySelector( '.hprnb-slot[data-hprnb-slot="m"]' );
		if ( ! slot ) {
			return;
		}
		if ( ! root.hprnbHome ) {
			root.hprnbHome = { parent: root.parentNode, next: root.nextSibling };
		}
		var home = root.hprnbHome;
		if ( mobile ) {
			if ( root.parentNode !== slot ) {
				slot.appendChild( root );
			}
		} else if ( home.parent && root.parentNode !== home.parent ) {
			home.parent.insertBefore( root, home.next );
		}
	}

	/**
	 * A bar placed in the article spans the screen. The pure-CSS formula assumes the article column
	 * is centred, which no theme guarantees, so measure the real gap and the real viewport width.
	 *
	 * @param {Element} root The #hprnb-root element.
	 */
	function measureBleed( root ) {
		if ( ! root.classList.contains( 'hprnb-root--d-inflow' ) && ! root.classList.contains( 'hprnb-root--m-inflow' ) ) {
			return;
		}
		var width = document.documentElement.clientWidth;
		// Neutralise the pull first: the element then sits where the article column puts it, which
		// is the gap to measure. Reading it back after removing the property would only return the
		// position the CSS fallback already moved it to.
		root.style.setProperty( '--hprnb-bleed', '0px' );
		root.style.setProperty( '--hprnb-bleed-w', width + 'px' );
		var rect = root.getBoundingClientRect();
		var rtl = 'rtl' === ( getComputedStyle( root ).direction || 'ltr' );
		var gap = rtl ? width - rect.right : rect.left;
		root.style.setProperty( '--hprnb-bleed', Math.round( gap ) + 'px' );
	}

	/** Re-runs `fn` when `el` is resized (ResizeObserver, else a debounced window resize). */
	function observeSize( state, el, fn ) {
		var debounced = debounce( fn, 200 );
		if ( typeof window.ResizeObserver === 'function' ) {
			try {
				var observer = new window.ResizeObserver( debounced );
				observer.observe( el );
				state.add( function () {
					observer.disconnect();
				} );
				return;
			} catch ( e ) {
				// Fall through to the resize listener.
			}
		}
		state.on( window, 'resize', debounced );
	}

	/* ------------------------------------------------------------------ */
	/* Pause controller (shared by marquee and rotate)                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Combines every pause source. A user pause (toggle button) is sticky: hover or
	 * focus leaving, or the tab becoming visible, never resumes it.
	 */
	function createPauseController( state, aside, cfg, toggle, region, onChange ) {
		var st = { user: false, hover: false, focus: false, hidden: !!document.hidden };

		function paused() {
			return st.user || st.hover || st.focus || st.hidden;
		}

		function update() {
			var p = paused();
			aside.classList.toggle( 'hprnb-bar--paused', p );
			if ( toggle ) {
				var label = toggle.getAttribute( st.user ? 'data-hprnb-label-play' : 'data-hprnb-label-pause' );
				if ( label ) {
					toggle.setAttribute( 'aria-label', label );
				}
			}
			if ( onChange ) {
				onChange( p );
			}
		}

		if ( toggle ) {
			state.on( toggle, 'click', function () {
				st.user = ! st.user;
				update();
			} );
		}
		// Only a real mouse can "hover": on a touch screen the emulated hover after a tap never ends,
		// which used to leave the bar paused with the Play icon stuck.
		var canHover = ! window.matchMedia || window.matchMedia( '(hover: hover) and (pointer: fine)' ).matches;
		if ( cfg.hover && canHover ) {
			state.on( aside, 'mouseenter', function () {
				st.hover = true;
				update();
			} );
			state.on( aside, 'mouseleave', function () {
				st.hover = false;
				update();
			} );
			state.on( aside, 'pointerdown', function ( event ) {
				if ( event.pointerType && event.pointerType !== 'mouse' && st.hover ) {
					st.hover = false; // Hybrid device: the finger wins over the stale hover.
					update();
				}
			} );
		}
		state.on( region, 'focusin', function () {
			st.focus = true;
			update();
		} );
		state.on( region, 'focusout', function ( event ) {
			if ( ! event.relatedTarget || ! region.contains( event.relatedTarget ) ) {
				st.focus = false;
				update();
			}
		} );
		state.on( document, 'visibilitychange', function () {
			st.hidden = !!document.hidden;
			update();
		} );
		state.add( function () {
			aside.classList.remove( 'hprnb-bar--paused' );
			if ( toggle ) {
				var label = toggle.getAttribute( 'data-hprnb-label-pause' );
				if ( label ) {
					toggle.setAttribute( 'aria-label', label );
				}
			}
		} );

		update();
		return { paused: paused, update: update };
	}

	/* ------------------------------------------------------------------ */
	/* Marquee                                                             */
	/* ------------------------------------------------------------------ */

	function setupMarquee( state, aside, cfg, toggle ) {
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

		state.add( function () {
			if ( clone ) {
				track.removeChild( clone );
				clone = null;
			}
			if ( track.parentNode ) {
				track.parentNode.insertBefore( list, track );
				track.parentNode.removeChild( track );
			}
			aside.classList.remove( 'hprnb-bar--marquee-on' );
		} );

		createPauseController( state, aside, cfg, toggle, viewport, null );

		function measure() {
			if ( aside.hprnbState !== state ) {
				return; // A debounced measurement arriving after destroy().
			}
			var listWidth = list.getBoundingClientRect().width;
			if ( listWidth <= viewport.clientWidth ) {
				aside.classList.remove( 'hprnb-bar--marquee-on' );
				if ( clone ) {
					track.removeChild( clone );
					clone = null;
				}
				if ( toggle ) {
					toggle.hidden = true;
				}
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
			aside.classList.add( 'hprnb-bar--marquee-on' );
			if ( toggle ) {
				toggle.hidden = false;
			}
		}

		measure();
		observeSize( state, viewport, measure );
	}

	/* ------------------------------------------------------------------ */
	/* Rotate (+ counter, progress line and swipe, per profile)           */
	/* ------------------------------------------------------------------ */

	function setupRotate( state, aside, cfg, toggle, profile ) {
		var viewport = aside.querySelector( '.hprnb-bar__viewport' );
		var items = aside.querySelectorAll( '.hprnb-bar__item' );
		if ( ! viewport || items.length < 2 ) {
			state.hide( toggle, true );
			return;
		}

		var index = 0;
		var timer = null;
		var ctrl = null;
		var counter = null;
		var progress = null;

		if ( profile.counter ) {
			counter = document.createElement( 'span' );
			counter.className = 'hprnb-bar__counter';
			counter.setAttribute( 'aria-hidden', 'true' );
			var label = aside.querySelector( '.hprnb-bar__label' );
			var inner = aside.querySelector( '.hprnb-bar__inner' ) || aside;
			if ( label ) {
				label.insertAdjacentElement( 'afterend', counter );
			} else {
				inner.insertBefore( counter, inner.firstChild );
			}
		}
		if ( profile.progress ) {
			progress = document.createElement( 'div' );
			progress.className = 'hprnb-bar__progress';
			progress.setAttribute( 'aria-hidden', 'true' );
			aside.style.setProperty( '--hprnb-progress', cfg.interval + 'ms' );
			aside.appendChild( progress );
		}

		state.add( function () {
			if ( timer !== null ) {
				clearTimeout( timer );
			}
			forEach( items, function ( li ) {
				li.hidden = false;
			} );
			if ( counter ) {
				counter.parentNode.removeChild( counter );
			}
			if ( progress ) {
				progress.parentNode.removeChild( progress );
				aside.style.removeProperty( '--hprnb-progress' );
			}
		} );

		function restartProgress() {
			if ( ! progress ) {
				return;
			}
			progress.classList.remove( 'is-run' );
			void progress.offsetWidth;
			progress.classList.add( 'is-run' );
		}

		// A headline taller than its clipped viewport gets an ellipsis (flow card): flagged on the viewport.
		function checkClip() {
			if ( aside.hprnbState !== state ) {
				return;
			}
			viewport.classList.toggle( 'is-clipped', viewport.scrollHeight > viewport.clientHeight + 1 );
		}

		function show( i ) {
			index = ( i + items.length ) % items.length;
			forEach( items, function ( li, k ) {
				li.hidden = ( k !== index );
			} );
			if ( counter ) {
				counter.textContent = ( index + 1 ) + '/' + items.length;
			}
			checkClip();
		}

		observeSize( state, viewport, checkClip );
		state.add( function () {
			viewport.classList.remove( 'is-clipped' );
		} );

		function stop() {
			if ( timer !== null ) {
				clearTimeout( timer );
				timer = null;
			}
		}

		function tick() {
			timer = setTimeout( function () {
				timer = null;
				show( index + 1 );
				if ( ctrl && ! ctrl.paused() ) {
					restartProgress();
					tick();
				}
			}, cfg.interval );
		}

		function start() {
			if ( timer === null ) {
				restartProgress();
				tick();
			}
		}

		show( 0 );
		ctrl = createPauseController( state, aside, cfg, toggle, viewport, function ( paused ) {
			if ( paused ) {
				stop();
			} else {
				start();
			}
		} );

		if ( profile.swipe ) {
			var startX = 0;
			var startY = 0;
			var tracking = false;
			var swiped = false;
			state.on( viewport, 'pointerdown', function ( event ) {
				startX = event.clientX;
				startY = event.clientY;
				tracking = true;
				swiped = false;
			} );
			// A pointer drag over the headline link must not start a native link drag.
			state.on( viewport, 'dragstart', function ( event ) {
				if ( tracking ) {
					event.preventDefault();
				}
			} );
			state.on( viewport, 'pointerup', function ( event ) {
				if ( ! tracking ) {
					return;
				}
				tracking = false;
				var dx = event.clientX - startX;
				var dy = event.clientY - startY;
				if ( Math.abs( dx ) < 40 || Math.abs( dy ) > 60 ) {
					return;
				}
				var direction = dx < 0 ? 1 : -1;
				if ( isRtl( aside ) ) {
					direction = -direction;
				}
				swiped = true;
				event.preventDefault();
				// A pointer swipe over the link gave it focus: release it so the rotation resumes.
				if ( document.activeElement && viewport.contains( document.activeElement ) && ! isKeyboardFocus( document.activeElement ) ) {
					document.activeElement.blur();
				}
				show( index + direction );
				if ( ctrl && ! ctrl.paused() ) {
					stop();
					start();
				}
			} );
			// The click synthesised after a recognised swipe must not follow the link.
			state.on( viewport, 'click', function ( event ) {
				if ( swiped ) {
					swiped = false;
					event.preventDefault();
					event.stopPropagation();
				}
			}, true );
			state.on( viewport, 'pointercancel', function () {
				tracking = false;
			} );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Manual                                                              */
	/* ------------------------------------------------------------------ */

	function setupManual( state, aside, reduced ) {
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

		state.on( prev, 'click', function () {
			scroll( -1 );
		} );
		state.on( next, 'click', function () {
			scroll( 1 );
		} );
		state.on( viewport, 'scroll', update, { passive: true } );
		observeSize( state, viewport, update );
		state.add( function () {
			prev.disabled = true;
			next.disabled = false;
		} );
		update();
	}

	/* ------------------------------------------------------------------ */
	/* Relative time                                                       */
	/* ------------------------------------------------------------------ */

	function setupRelativeTime( state, aside, cfg ) {
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

		state.on( document, 'visibilitychange', function () {
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
		state.add( function () {
			if ( timer !== null ) {
				clearTimeout( timer );
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

	/* ------------------------------------------------------------------ */
	/* Analytics: three events on dataLayer and on `document`, never       */
	/* blocking and never required — the bar shows with or without them.   */
	/* ------------------------------------------------------------------ */

	function createAnalytics( root, aside, mobile ) {
		var sent = {};

		function recommended() {
			var item = aside.querySelector( '.hprnb-bar__item:not([hidden])' );
			return item ? ( parseInt( item.getAttribute( 'data-hprnb-id' ), 10 ) || 0 ) : 0;
		}

		function push( name, extra ) {
			var detail = {
				device: mobile ? 'mobile' : 'desktop',
				current_article_id: parseInt( root.getAttribute( 'data-hprnb-post' ), 10 ) || 0,
				recommended_article_id: recommended(),
				items: parseInt( root.getAttribute( 'data-hprnb-count' ), 10 ) || 0
			};
			var key;
			for ( key in extra ) {
				if ( Object.prototype.hasOwnProperty.call( extra, key ) ) {
					detail[ key ] = extra[ key ];
				}
			}
			try {
				window.dataLayer = window.dataLayer || [];
				var row = { event: name };
				for ( key in detail ) {
					if ( Object.prototype.hasOwnProperty.call( detail, key ) ) {
						row[ key ] = detail[ key ];
					}
				}
				window.dataLayer.push( row );
			} catch ( e ) {
				// No dataLayer, or a frozen one: the document event below still fires.
			}
			try {
				document.dispatchEvent( new CustomEvent( 'hprnb:' + name, { detail: detail } ) );
			} catch ( e ) {
				// Engines without CustomEvent lose the event, never the bar.
			}
		}

		return {
			/** The bar became visible. `state` carries the smart measurements when there are any. */
			impression: function ( reason, extra ) {
				if ( sent.impression ) {
					return;
				}
				sent.impression = reason;
				push( 'hprnb_impression', merge( { trigger_reason: reason }, extra ) );
			},
			click: function () {
				push( 'hprnb_click', { trigger_reason: sent.impression || 'unknown' } );
			},
			close: function () {
				push( 'hprnb_close', { trigger_reason: sent.impression || 'unknown' } );
			}
		};
	}

	function merge( base, extra ) {
		var key;
		for ( key in extra ) {
			if ( Object.prototype.hasOwnProperty.call( extra, key ) ) {
				base[ key ] = extra[ key ];
			}
		}
		return base;
	}

	/* ------------------------------------------------------------------ */
	/* Reveal: the bar may wait before showing up (settings reveal_mode)   */
	/*   immediate | scroll | percent | end  — the page, unchanged;        */
	/*   smart — the article body, its end, a real scroll back up, or a    */
	/*   reader who got deep into it. One decision point, one trigger.     */
	/* ------------------------------------------------------------------ */

	/** Editorial body of the page: the configured selector first, then the usual suspects. */
	var ARTICLE_SELECTORS = [
		'.entry-content',
		'.post-content',
		'.article-content',
		'.wp-block-post-content',
		'[itemprop="articleBody"]',
		'article .content',
		'main article'
	];

	function findArticle( selector ) {
		var list = selector ? [ selector ].concat( ARTICLE_SELECTORS ) : ARTICLE_SELECTORS;
		for ( var i = 0; i < list.length; i++ ) {
			var el;
			try {
				el = document.querySelector( list[ i ] );
			} catch ( e ) {
				continue; // A selector typed in the settings can be invalid: skip it.
			}
			// Prose with a height, so a two-paragraph news item still counts while an empty wrapper
			// or a bare widget does not.
			if ( el && el.querySelector( 'p' ) && el.getBoundingClientRect().height > 40 ) {
				return el;
			}
		}
		return null;
	}

	function setupReveal( state, root, contract, mobile, analytics ) {
		var all = parseJson( root.getAttribute( 'data-hprnb-reveal' ) );
		// Each device decides for itself: the active profile's block, its own pending classes.
		var cfg = ( all && all[ mobile ? 'm' : 'd' ] ) || {};
		cfg.sel = all ? all.sel : '';
		cfg.smart = all ? all.smart : null;
		var mode = cfg.mode || 'immediate';
		var pendingClass = 'hprnb-root--' + ( mobile ? 'm' : 'd' ) + '-pending';
		var bodyClass = 'hprnb-' + ( mobile ? 'm' : 'd' ) + '-pending';
		var pending = root.classList.contains( pendingClass );
		// Shared with the collapse engine: where the reader was when the bar became visible (null
		// while it is still pending), the editorial body once located, and who wants to know.
		state.articleSel = cfg.sel || ( cfg.smart && cfg.smart.sel ) || '';
		state.article = undefined;
		state.revealY = null;
		state.onReveal = [];
		if ( 'immediate' === mode || ! pending ) {
			state.revealY = 0; // Visible from the top of the page: the trigger point is the top.
			if ( analytics ) {
				analytics.impression( 'legacy_immediate' );
			}
			return;
		}
		var body = document.body;
		var value = typeof cfg.value === 'number' ? cfg.value : 400;
		var done = false;

		/** The one place that decides the bar is now visible. Fires at most once. */
		function reveal( reason, extra ) {
			if ( done ) {
				return;
			}
			done = true;
			state.revealY = window.scrollY;
			root.classList.remove( pendingClass );
			body.classList.remove( bodyClass );
			if ( contract ) {
				contract.emit();
			}
			for ( var i = 0; i < state.onReveal.length; i++ ) {
				state.onReveal[ i ]();
			}
			if ( analytics ) {
				analytics.impression( reason, extra );
			}
		}

		state.add( function () {
			// destroy() must not leave a half-revealed bar behind.
			root.classList.remove( pendingClass );
			body.classList.remove( bodyClass );
		} );

		if ( 'smart' === mode ) {
			setupSmart( state, cfg, mobile, reveal );
			return;
		}
		if ( 'paragraph' === mode ) {
			setupParagraph( state, cfg, reveal );
			return;
		}
		watchPage( state, mode, value, 'legacy_' + mode, reveal );
	}

	/**
	 * The page-based triggers: a scroll distance, or a share of the whole page. Also the honest
	 * stand-in when a body-based trigger finds no article to measure.
	 *
	 * @param {Object}   state  Teardown registry of the bar.
	 * @param {string}   mode   'scroll' | 'percent' | 'end'.
	 * @param {number}   value  Pixels, or a percentage.
	 * @param {string}   reason Impression reason reported when it fires.
	 * @param {Function} reveal The single decision point.
	 * @param {Object=}  extra  Extra analytics fields.
	 */
	function watchPage( state, mode, value, reason, reveal, extra ) {
		function reached() {
			var y = window.scrollY;
			if ( 'scroll' === mode ) {
				return y >= value;
			}
			var scrollable = Math.max( 1, document.documentElement.scrollHeight - window.innerHeight );
			return ( y / scrollable ) * 100 >= value;
		}

		if ( reached() ) {
			reveal( reason, extra );
			return;
		}
		var ticking = false;
		function onScroll() {
			if ( ticking ) {
				return;
			}
			ticking = true;
			window.requestAnimationFrame( function () {
				ticking = false;
				if ( reached() ) {
					reveal( reason, extra );
					window.removeEventListener( 'scroll', onScroll );
				}
			} );
		}
		state.on( window, 'scroll', onScroll, { passive: true } );
	}

	/** The editorial body, located once per bar and shared by the reveal and collapse engines. */
	function articleOf( state ) {
		if ( state.article === undefined ) {
			state.article = findArticle( state.articleSel || '' );
		}
		return state.article;
	}

	/**
	 * "Before the end of the article": the bar appears as soon as the Nth paragraph counted from
	 * the end of the editorial body comes into view — 2 is the second-to-last one. Positional and
	 * deterministic, so the same reader always sees the bar at the same line.
	 *
	 * @param {Object}   state  Teardown registry of the bar.
	 * @param {Object}   cfg    Parsed data-hprnb-reveal.
	 * @param {Function} reveal The single decision point.
	 */
	function setupParagraph( state, cfg, reveal ) {
		var n = typeof cfg.paragraph === 'number' ? Math.max( 1, cfg.paragraph ) : 2;
		var article = articleOf( state );
		var target = null;
		if ( article ) {
			var all = article.querySelectorAll( 'p' );
			var prose = [];
			for ( var i = 0; i < all.length; i++ ) {
				var p = all[ i ];
				// The bar itself can sit inside the article (inline placement): its text never counts.
				if ( p.closest && p.closest( '.hprnb-root' ) ) {
					continue;
				}
				if ( ( p.textContent || '' ).replace( /\s+/g, '' ).length > 0 ) {
					prose.push( p );
				}
			}
			if ( prose.length ) {
				target = prose[ Math.max( 0, prose.length - n ) ];
			}
		}
		var extra = { paragraph_from_end: n, article_found: !! article, paragraph_found: !! target };
		if ( ! target ) {
			// Nothing to count: near the end of the page is the only honest stand-in.
			watchPage( state, 'percent', 90, 'paragraph_fallback', reveal, extra );
			return;
		}

		// Where the paragraph sits, measured now and again whenever the page reflows (lazy images,
		// ads), never inside the scroll handler. A scroll — including the browser restoring a
		// position, or a deep link jumping straight past the paragraph — is then a plain comparison.
		// An IntersectionObserver would miss that jump: nothing ever crosses the screen.
		var top = 0;
		var bottom = 0;
		function measure() {
			var rect = target.getBoundingClientRect();
			top = rect.top + window.scrollY;
			bottom = rect.bottom + window.scrollY;
		}
		function check() {
			var y = window.scrollY;
			if ( y > bottom ) {
				reveal( 'paragraph_passed', extra ); // Already beyond it: the reader is past the point.
				return true;
			}
			if ( y + window.innerHeight >= top ) {
				reveal( 'paragraph_before_end', extra );
				return true;
			}
			return false;
		}
		measure();
		if ( check() ) {
			return;
		}
		observeSize( state, document.body, measure );
		var ticking = false;
		function onScroll() {
			if ( ticking ) {
				return;
			}
			ticking = true;
			window.requestAnimationFrame( function () {
				ticking = false;
				if ( check() ) {
					window.removeEventListener( 'scroll', onScroll );
				}
			} );
		}
		state.on( window, 'scroll', onScroll, { passive: true } );
	}

	/**
	 * Smart reveal. Three signals, in order of how much they mean: the reader reached the end of
	 * the article body, scrolled back up in earnest after reading a good share of it, or simply got
	 * deep into it. Whichever comes first wins; `reveal()` guarantees the rest are ignored.
	 *
	 * @param {Object}   state  Teardown registry of the bar.
	 * @param {Object}   cfg    Parsed data-hprnb-reveal.
	 * @param {boolean}  mobile Whether the mobile profile is the active one.
	 * @param {Function} reveal The single decision point.
	 */
	function setupSmart( state, cfg, mobile, reveal ) {
		var smart = cfg.smart || {};
		var tune = smart[ mobile ? 'm' : 'd' ] || {};
		var minProgress = ( typeof tune.p === 'number' ? tune.p : 55 ) / 100;
		var minTime = ( typeof tune.t === 'number' ? tune.t : 15 ) * 1000;
		var minUp = typeof tune.u === 'number' ? tune.u : 300;
		var fallbackProgress = ( typeof tune.fp === 'number' ? tune.fp : 75 ) / 100;
		var fallbackTime = ( typeof tune.ft === 'number' ? tune.ft : 25 ) * 1000;

		var article = articleOf( state );
		// Geometry is read here and on resize only: never inside the scroll handler.
		var top = 0;
		var height = 0;
		var short = false;

		function measure() {
			if ( article ) {
				var rect = article.getBoundingClientRect();
				top = rect.top + window.scrollY;
				height = rect.height;
			} else {
				top = 0;
				height = Math.max( 1, document.documentElement.scrollHeight );
			}
			// A piece barely longer than the screen: its end is the only honest signal.
			short = height < window.innerHeight * 1.5;
		}
		measure();

		/** How much of the article body has gone past the bottom of the screen, 0 → 1. */
		function progress() {
			var read = window.scrollY + window.innerHeight - top;
			return Math.max( 0, Math.min( 1, read / Math.max( 1, height ) ) );
		}

		// Active reading time: paused with the tab, and after a long spell of no activity at all.
		var active = 0;
		var since = Date.now();
		var lastMove = Date.now();
		function beat() {
			var now = Date.now();
			if ( 'hidden' !== document.visibilityState && now - lastMove < 60000 ) {
				active += now - since;
			}
			since = now;
		}

		var lastY = window.scrollY;
		var up = 0;
		var reason = null;

		function payload( why ) {
			return {
				trigger_reason: why,
				article_progress: Math.round( progress() * 100 ),
				active_reading_time: Math.round( active / 1000 ),
				article_found: !! article
			};
		}

		function fire( why ) {
			reason = why;
			reveal( why, payload( why ) );
			stop();
		}

		/** Called from the scroll handler and from the slow beat; never touches the layout. */
		function evaluate() {
			beat();
			if ( reason ) {
				return;
			}
			var p = progress();
			if ( p >= minProgress && active >= minTime && up >= minUp ) {
				fire( 'scroll_up_intent' );
				return;
			}
			if ( ! short && p >= fallbackProgress && active >= fallbackTime ) {
				fire( 'engaged_reader' );
			}
		}

		var ticking = false;
		function onScroll() {
			var y = window.scrollY;
			var max = document.documentElement.scrollHeight - window.innerHeight;
			// Rubber banding at either end is not reading.
			if ( y >= 0 && y <= max ) {
				var delta = lastY - y;
				if ( delta >= 2 ) {
					up += delta;
				} else if ( delta <= -40 ) {
					up = 0; // Off down the page again: the intent is gone.
				}
			}
			lastY = y;
			lastMove = Date.now();
			if ( ticking ) {
				return;
			}
			ticking = true;
			window.requestAnimationFrame( function () {
				ticking = false;
				evaluate();
			} );
		}

		// The end of the article body is the signal worth waiting for.
		var observer = null;
		var sentinel = null;
		if ( article && typeof window.IntersectionObserver === 'function' ) {
			sentinel = document.createElement( 'div' );
			sentinel.className = 'hprnb-sentinel';
			sentinel.setAttribute( 'aria-hidden', 'true' );
			// One real pixel: a zero-height box never produces a non-empty intersection rectangle.
			sentinel.style.cssText = 'display:block;block-size:1px;inline-size:100%;margin:0;padding:0;pointer-events:none';
			article.appendChild( sentinel );
			observer = new window.IntersectionObserver( function ( entries ) {
				for ( var i = 0; i < entries.length; i++ ) {
					if ( entries[ i ].isIntersecting ) {
						beat();
						fire( 'article_end' );
						return;
					}
				}
			} );
			observer.observe( sentinel );
		}

		var timer = window.setInterval( function () {
			if ( 'hidden' === document.visibilityState ) {
				beat();
				return;
			}
			evaluate();
		}, 1000 );

		function stop() {
			if ( timer ) {
				window.clearInterval( timer );
				timer = null;
			}
			if ( observer ) {
				observer.disconnect();
				observer = null;
			}
			if ( sentinel && sentinel.parentNode ) {
				sentinel.parentNode.removeChild( sentinel );
				sentinel = null;
			}
		}

		state.on( window, 'scroll', onScroll, { passive: true } );
		state.on( window, 'resize', debounce( function () {
			measure();
			lastY = window.scrollY; // A resize is not a scroll: do not bank it as intent.
			up = 0;
		}, 200 ) );
		state.on( document, 'visibilitychange', function () {
			beat();
			if ( 'hidden' !== document.visibilityState ) {
				lastMove = Date.now();
			}
		} );
		state.add( stop );

		// The article may still be loading its images: one late re-measure, cheaply.
		if ( typeof window.ResizeObserver === 'function' && article ) {
			var ro = new window.ResizeObserver( debounce( measure, 200 ) );
			ro.observe( article );
			state.add( function () {
				ro.disconnect();
			} );
		}

		evaluate();
	}

	/* ------------------------------------------------------------------ */
	/* Continuous loading: gone in the next article (settings next_hide)   */
	/* ------------------------------------------------------------------ */

	/**
	 * Themes that load the next article below the current one (continuous loading, infinite
	 * scroll) append a second article body built from the same template. The bar belongs to the
	 * article the page was opened on: once the start of the next article reaches the middle of the
	 * screen, the bar slides out of view and releases its space; scrolling back up above that line
	 * brings it back, and the folding rules of the first article apply again.
	 *
	 * @param {Object}  state    Teardown registry of the bar, with the located article.
	 * @param {Element} root     The #hprnb-root element.
	 * @param {boolean} mobile   Whether the mobile profile is the active one.
	 * @param {Object=} contract Theme contract (offset variable, state event).
	 */
	function setupNextArticle( state, root, mobile, contract ) {
		var article = articleOf( state );
		if ( ! article ) {
			return;
		}
		// Every selector the current body answers to: the next article is built the same way.
		var selectors = ( state.articleSel ? [ state.articleSel ] : [] ).concat( ARTICLE_SELECTORS ).filter( function ( sel ) {
			try {
				return article.matches( sel );
			} catch ( e ) {
				return false;
			}
		} );
		if ( ! selectors.length ) {
			return;
		}
		var body = document.body;
		var rootClass = 'hprnb-root--' + ( mobile ? 'm' : 'd' ) + '-away';
		var bodyClass = 'hprnb-' + ( mobile ? 'm' : 'd' ) + '-away';
		var nextY = Infinity;
		var dirty = true;
		var away = false;
		var ticking = false;

		/** Document Y of the top of the next article (its whole block, title included), or Infinity. */
		function measure() {
			dirty = false;
			nextY = Infinity;
			var end = article.getBoundingClientRect().bottom;
			for ( var s = 0; s < selectors.length; s++ ) {
				var list;
				try {
					list = document.querySelectorAll( selectors[ s ] );
				} catch ( e ) {
					continue;
				}
				for ( var i = 0; i < list.length; i++ ) {
					var el = list[ i ];
					if ( el === article || article.contains( el ) || el.contains( article ) || root.contains( el ) ) {
						continue;
					}
					if ( ! el.querySelector( 'p' ) || el.getBoundingClientRect().height <= 40 ) {
						continue;
					}
					// The article block around that body starts with its title: that is where the
					// reader "arrives" in the next article.
					var box = el.closest ? el.closest( 'article' ) : null;
					if ( ! box || box.contains( article ) ) {
						box = el;
					}
					var top = box.getBoundingClientRect().top;
					if ( top >= end - 1 ) {
						nextY = Math.min( nextY, top + window.scrollY );
						break;
					}
				}
			}
		}

		function set( on ) {
			if ( on === away ) {
				return;
			}
			away = on;
			root.classList.toggle( rootClass, on );
			body.classList.toggle( bodyClass, on );
			if ( contract ) {
				contract.emit();
			}
		}

		function update() {
			if ( dirty ) {
				measure();
			}
			set( window.scrollY + window.innerHeight / 2 >= nextY );
		}

		// The slide in and out is the entrance transition: a bar visible from the start has none yet.
		if ( ! root.classList.contains( 'hprnb-root--reveal' ) ) {
			state.addClass( root, 'hprnb-root--reveal' );
		}
		state.add( function () {
			root.classList.remove( rootClass );
			body.classList.remove( bodyClass );
		} );

		// The next article arrives later, appended by the theme: any change of the page marks the
		// measure stale, and the next scroll frame takes it again.
		function stale() {
			dirty = true;
		}
		if ( typeof window.MutationObserver === 'function' ) {
			var observer = new window.MutationObserver( stale );
			observer.observe( body, { childList: true, subtree: true } );
			state.add( function () {
				observer.disconnect();
			} );
		}
		observeSize( state, body, function () {
			stale();
			update();
		} );
		state.on( window, 'resize', stale );
		state.on( window, 'scroll', function () {
			if ( ticking ) {
				return;
			}
			ticking = true;
			window.requestAnimationFrame( function () {
				ticking = false;
				update();
			} );
		}, { passive: true } );
		update();
	}

	/* ------------------------------------------------------------------ */
	/* Contract with the theme and other plugins (v2)                      */
	/*   --hprnb-offset on <body>: visible height of the bar (0 when hidden)*/
	/*   body.hprnb-is-collapsed / body.hprnb-kbd, document "hprnb:state"  */
	/*   { mobile, collapsed, height, offset }, window.hprnbBar.state().   */
	/* ------------------------------------------------------------------ */

	function setupContract( state, root, aside, profile, mobile ) {
		if ( root.classList.contains( 'hprnb-root--preview' ) ) {
			return null;
		}
		var body = document.body;

		function current() {
			var collapsed = aside.classList.contains( 'hprnb-bar--collapsed' );
			var cs = getComputedStyle( body );
			var full = parseFloat( cs.getPropertyValue( mobile ? '--hprnb-m-height' : '--hprnb-height' ) ) || aside.offsetHeight;
			var peek = parseFloat( cs.getPropertyValue( '--hprnb-peek' ) ) || 40;
			// A floating mobile layout also keeps its distance from the bottom edge.
			var gap = mobile ? ( parseFloat( cs.getPropertyValue( '--hprnb-m-gap' ) ) || 0 ) : 0;
			var hidden = aside.hidden || root.hidden || body.classList.contains( 'hprnb-kbd' ) || root.classList.contains( 'hprnb-root--' + ( mobile ? 'm' : 'd' ) + '-pending' )
				|| root.classList.contains( 'hprnb-root--' + ( mobile ? 'm' : 'd' ) + '-away' );
			// In flow the bar is a block of the page: it covers nothing, so it reserves nothing.
			// Collapsed, a phone keeps its strip while the desktop bar slides fully away.
			var inflow = !! profile && profile.place === 'inline';
			var offset = ( hidden || inflow ) ? 0 : ( collapsed ? ( mobile ? peek + gap : 0 ) : full + gap );
			return { mobile: mobile, collapsed: collapsed, height: full, offset: offset };
		}

		function emit() {
			var detail = current();
			body.classList.toggle( 'hprnb-is-collapsed', detail.collapsed );
			body.style.setProperty( '--hprnb-offset', detail.offset + 'px' );
			try {
				document.dispatchEvent( new CustomEvent( 'hprnb:state', { detail: detail } ) );
			} catch ( e ) {
				// Very old engines without CustomEvent: the body classes and the variable still apply.
			}
		}

		api.state = current;
		state.add( function () {
			body.classList.remove( 'hprnb-is-collapsed' );
			body.classList.remove( 'hprnb-kbd' );
			body.style.removeProperty( '--hprnb-offset' );
			if ( api.state === current ) {
				api.state = null;
			}
		} );

		// Keyboard open (a field outside the bar has the focus on a phone): the bar slides away.
		if ( profile.kbd ) {
			state.on( document, 'focusin', function ( event ) {
				var target = event.target;
				if ( ! isNarrow( root ) || ! target || ! target.matches || aside.contains( target ) ) {
					return;
				}
				if ( target.matches( FIELD ) ) {
					body.classList.add( 'hprnb-kbd' );
					emit();
				}
			} );
			state.on( document, 'focusout', function () {
				setTimeout( function () {
					if ( aside.hprnbState !== state ) {
						return;
					}
					var active = document.activeElement;
					if ( ! active || ! active.matches || ! active.matches( FIELD ) || aside.contains( active ) ) {
						if ( body.classList.contains( 'hprnb-kbd' ) ) {
							body.classList.remove( 'hprnb-kbd' );
							emit();
						}
					}
				}, 60 );
			} );
		}

		return { emit: emit, current: current };
	}

	function setupClose( state, root, aside, cfg, contract, analytics ) {
		var button = aside.querySelector( '.hprnb-bar__btn--close' );
		if ( ! button ) {
			return;
		}
		state.on( button, 'click', function ( event ) {
			// The button sits inside the card, over the link on some layouts: never follow it.
			event.preventDefault();
			event.stopPropagation();
			if ( analytics ) {
				analytics.close();
			}
			moveFocusAway( button );
			aside.hidden = true;
			root.hidden = true;
			document.body.classList.remove( 'hprnb-reserve' );
			if ( contract ) {
				contract.emit();
			}
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
	/* Collapse on scroll (mobile, stacked)                                */
	/* ------------------------------------------------------------------ */

	function setupCollapse( state, aside, profile, contract ) {
		var lastY = window.scrollY;
		var ticking = false;
		var holdUntil = 0;
		var after = typeof profile.after === 'number' ? Math.max( 0, profile.after ) : TOP_ZONE;
		var trigger = profile.trigger || 'scroll';

		function set( collapsed ) {
			if ( aside.classList.contains( 'hprnb-bar--collapsed' ) === collapsed ) {
				return;
			}
			aside.classList.toggle( 'hprnb-bar--collapsed', collapsed );
			if ( contract ) {
				contract.emit();
			}
		}

		// Chevron "expand" in the collapsed strip (its click bubbles to the strip handler below).
		var controls = aside.querySelector( '.hprnb-bar__controls' );
		if ( ! controls ) {
			controls = document.createElement( 'div' );
			controls.className = 'hprnb-bar__controls';
			( aside.querySelector( '.hprnb-bar__inner' ) || aside ).appendChild( controls );
			state.add( function () {
				controls.parentNode.removeChild( controls );
			} );
		}
		var chevron = document.createElement( 'button' );
		chevron.type = 'button';
		chevron.className = 'hprnb-bar__btn hprnb-bar__btn--expand';
		chevron.setAttribute( 'aria-label', aside.getAttribute( 'data-hprnb-label-expand' ) || 'Expand' );
		chevron.innerHTML = CHEVRON;
		controls.insertBefore( chevron, controls.firstChild );
		state.add( function () {
			chevron.parentNode.removeChild( chevron );
		} );

		// "Follows the reading": three zones on the article. Before the trigger point (where the bar
		// first appeared) the bar stays folded so the body is never covered; inside the body it
		// unfolds while reading on and folds on any scroll back up; once the reader is past the
		// end of the article it stays open. The collapse threshold plays no part here.
		var article = 'article' === trigger ? articleOf( state ) : null;
		var endY = Infinity;
		function measureEnd() {
			if ( article ) {
				endY = article.getBoundingClientRect().bottom + window.scrollY;
			}
		}
		function pastEnd( y ) {
			return y + window.innerHeight >= endY;
		}
		if ( 'article' === trigger ) {
			measureEnd();
			if ( article ) {
				observeSize( state, document.body, measureEnd );
			}
			// The first appearance is always the full bar: that is the whole point of waiting.
			state.onReveal.push( function () {
				set( false );
				lastY = window.scrollY;
			} );
		}

		// Always collapsed (the reader opens it on demand), or landing past the threshold: start
		// collapsed, without a slide.
		var landed = profile.deep && window.scrollY > after;
		if ( 'article' === trigger ) {
			// A pending bar has nothing to fold yet; a visible one landing inside the body does.
			landed = landed && state.revealY !== null && ! pastEnd( window.scrollY );
		}
		if ( ( 'immediate' === trigger || landed ) && ! isShortScreen() ) {
			aside.style.transition = 'none';
			set( true );
			void aside.offsetHeight;
			aside.style.removeProperty( 'transition' );
		}

		state.on( window, 'scroll', function () {
			if ( ticking ) {
				return;
			}
			ticking = true;
			window.requestAnimationFrame( function () {
				ticking = false;
				var y = window.scrollY;
				if ( Date.now() < holdUntil ) {
					lastY = y;
					return;
				}
				if ( isShortScreen() ) {
					set( false );
				} else if ( 'immediate' === trigger ) {
					lastY = y;
					return; // Only a tap opens it.
				} else if ( 'threshold' === trigger ) {
					// Collapse once past the threshold and stay collapsed until the reader taps.
					set( y > after );
				} else if ( 'article' === trigger ) {
					var b = state.revealY;
					if ( b === null ) {
						lastY = y;
						return; // Still waiting to appear: nothing to fold.
					}
					if ( pastEnd( y ) ) {
						set( false );
					} else if ( y < b ) {
						set( true );
					} else if ( y > lastY + 8 ) {
						set( false );
					} else if ( y < lastY - 8 ) {
						set( true );
					}
				} else if ( y > lastY + 8 && y > after ) {
					set( true );
				} else if ( y < lastY - 8 || y <= after ) {
					set( false );
				}
				lastY = y;
			} );
		}, { passive: true } );

		// A tap on the peeking label row (or keyboard focus inside) expands the bar again.
		state.on( aside, 'click', function ( event ) {
			if ( aside.classList.contains( 'hprnb-bar--collapsed' ) ) {
				event.preventDefault();
				set( false );
				holdUntil = Date.now() + 4000;
			}
		} );
		state.on( aside, 'focusin', function ( event ) {
			// Keyboard focus keeps the bar open; a pointer landing on the link does not.
			if ( isKeyboardFocus( event.target ) ) {
				set( false );
				holdUntil = Date.now() + 4000;
			}
		} );
		state.add( function () {
			set( false );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Init / destroy                                                      */
	/* ------------------------------------------------------------------ */

	function readConfig( aside, root ) {
		var d = aside.dataset;
		return {
			ticker: d.hprnbTicker || 'none',
			tickerMobile: d.hprnbTickerMobile || d.hprnbTicker || 'none',
			speed: parseInt( d.hprnbSpeed, 10 ) || 60,
			interval: Math.max( 1000, parseInt( d.hprnbInterval, 10 ) || 5000 ),
			hover: d.hprnbHover === '1',
			remember: d.hprnbRemember === '1',
			dismissHours: parseInt( d.hprnbDismissHours, 10 ) || 24,
			reltime: d.hprnbReltime === '1',
			reltimeMax: parseInt( d.hprnbReltimeMax, 10 ) || 48,
			d: parseJson( root.getAttribute( 'data-hprnb-desktop' ) ),
			m: parseJson( root.getAttribute( 'data-hprnb-mobile' ) )
		};
	}

	/**
	 * Initialises the bar found in `root`. Idempotent per aside; destroy() undoes everything.
	 *
	 * @param {Element|null} root The #hprnb-root element (or the admin preview root).
	 */
	function init( root ) {
		if ( ! root ) {
			return;
		}
		var aside = root.querySelector( '.hprnb-bar' );
		if ( ! aside || aside.hprnbState ) {
			return;
		}
		var state = createState();
		aside.hprnbState = state;
		aside.setAttribute( 'data-hprnb-init', '1' );
		state.add( function () {
			aside.removeAttribute( 'data-hprnb-init' );
		} );

		var cfg = readConfig( aside, root );
		var mobile = isNarrow( root );
		var profile = mobile ? cfg.m : cfg.d;
		relocate( root, mobile );
		measureBleed( root );
		state.on( window, 'resize', debounce( function () {
			if ( aside.hprnbState === state ) {
				measureBleed( root );
			}
		}, 150 ) );
		state.add( function () {
			root.style.removeProperty( '--hprnb-bleed' );
			root.style.removeProperty( '--hprnb-bleed-w' );
		} );
		var mode = mobile ? cfg.tickerMobile : cfg.ticker;
		var reduced = prefersReducedMotion();
		var toggle = aside.querySelector( '.hprnb-bar__btn--toggle' );
		var prev = aside.querySelector( '.hprnb-bar__btn--prev' );
		var next = aside.querySelector( '.hprnb-bar__btn--next' );

		state.addClass( aside, 'hprnb-bar--mode-' + mode );
		state.addClass( aside, 'hprnb-bar--mobile', mobile );
		state.addClass( aside, 'hprnb-bar--reduced', reduced );
		state.addClass( aside, 'hprnb-bar--rtl', isRtl( aside ) );

		// Buttons that make no sense for the effective mode are hidden; under 768px each one can also
		// be switched off in the settings.
		var wantsPause = ! mobile || profile.pause !== false;
		var wantsClose = ! mobile || profile.close !== false;
		state.hide( toggle, ( mode !== 'marquee' && mode !== 'rotate' ) || ! wantsPause );
		state.hide( prev, mode !== 'manual' );
		state.hide( next, mode !== 'manual' );
		state.hide( aside.querySelector( '.hprnb-bar__btn--close' ), ! wantsClose );

		var contract = setupContract( state, root, aside, profile, mobile );
		var analytics = root.classList.contains( 'hprnb-root--preview' ) ? null : createAnalytics( root, aside, mobile );
		setupReveal( state, root, contract, mobile, analytics );
		setupClose( state, root, aside, cfg, contract, analytics );
		if ( analytics ) {
			state.on( aside, 'click', function ( event ) {
				if ( event.target.closest && event.target.closest( '.hprnb-bar__link' ) ) {
					analytics.click();
				}
			} );
		}
		if ( cfg.reltime ) {
			setupRelativeTime( state, aside, cfg );
		}

		if ( mode === 'marquee' ) {
			if ( reduced ) {
				state.hide( toggle, true );
			} else {
				setupMarquee( state, aside, cfg, toggle );
			}
		} else if ( mode === 'rotate' ) {
			if ( reduced ) {
				state.hide( toggle, true );
			} else {
				setupRotate( state, aside, cfg, toggle, profile );
			}
		} else if ( mode === 'manual' ) {
			setupManual( state, aside, reduced );
		}

		if ( profile.collapse && profile.place !== 'inline' && ! root.classList.contains( 'hprnb-root--preview' ) ) {
			setupCollapse( state, aside, profile, contract );
		}
		if ( profile.next && profile.place !== 'inline' && ! root.classList.contains( 'hprnb-root--preview' ) ) {
			setupNextArticle( state, root, mobile, contract );
		}
		if ( contract ) {
			contract.emit();
			var resizeTimer = null;
			state.on( window, 'resize', function () {
				clearTimeout( resizeTimer );
				resizeTimer = setTimeout( function () {
					if ( aside.hprnbState !== state ) {
						return;
					}
					if ( isShortScreen() && aside.classList.contains( 'hprnb-bar--collapsed' ) ) {
						aside.classList.remove( 'hprnb-bar--collapsed' );
					}
					contract.emit();
				}, 150 );
			} );
			state.add( function () {
				clearTimeout( resizeTimer );
			} );
		}

		// Crossing the 768px threshold re-initialises the bar for the other presentation.
		observeSize( state, root, function () {
			if ( aside.hprnbState === state && isNarrow( root ) !== mobile ) {
				destroy( root );
				init( root );
			}
		} );
	}

	/**
	 * Undoes everything init() did on the bar found in `root`.
	 *
	 * @param {Element|null} root The #hprnb-root element (or the admin preview root).
	 */
	function destroy( root ) {
		var aside = root ? root.querySelector( '.hprnb-bar' ) : null;
		if ( ! aside || ! aside.hprnbState ) {
			return;
		}
		var cleanups = aside.hprnbState.cleanups;
		delete aside.hprnbState;
		for ( var i = cleanups.length - 1; i >= 0; i-- ) {
			try {
				cleanups[ i ]();
			} catch ( e ) {
				// A failed cleanup must not block the others.
			}
		}
	}

	var api = { init: init, destroy: destroy, state: null };
	window.hprnbBar = api;

	function boot() {
		init( document.getElementById( 'hprnb-root' ) );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot, { once: true } );
	} else {
		boot();
	}
})();
