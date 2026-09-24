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
	// The newest urgent flag the reader closed (2.14): a later flag opens the red bar again.
	var URGENT_KEY = 'hprnb_urgent_closed';
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
	 * The device whose switches apply (2.16): the screen's, measured like the device classes and the
	 * reserved space (media queries, scrollbar included); the admin preview follows its frame.
	 *
	 * @param {Element} root The root.
	 * @return {string} 'd' or 'm'.
	 */
	function deviceOf( root ) {
		if ( root.classList.contains( 'hprnb-root--preview' ) || ! window.matchMedia ) {
			return isNarrow( root ) ? 'm' : 'd';
		}
		return window.matchMedia( '(min-width: 768px)' ).matches ? 'd' : 'm';
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
	/* Pause controller (shared by marquee, rotate and Breaking News)        */
	/* ------------------------------------------------------------------ */

	/**
	 * Combines every pause source. A user pause (toggle button) is sticky: hover or
	 * focus leaving, or the tab becoming visible, never resumes it. onChange gets
	 * whether the bar is paused, and the sources ({user, hover, focus, hidden}).
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
				onChange( p, st );
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

	function createAnalytics( root, aside, mobile, kind ) {
		var sent = {};

		function recommended() {
			// The headline on screen: Breaking News marks it .is-current (its memory before it starts), rotation unhides it.
			var memory = aside.hprnbBn;
			var item = aside.querySelector( '.hprnb-bar__item.is-current' ) || ( memory && memory.id && aside.querySelector( '.hprnb-bar__item[data-hprnb-id="' + memory.id + '"]' ) ) || aside.querySelector( '.hprnb-bar__item:not([hidden])' );
			return item ? ( parseInt( item.getAttribute( 'data-hprnb-id' ), 10 ) || 0 ) : 0;
		}

		function push( name, extra ) {
			var detail = {
				bar: kind || 'news',
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
				analytics.impression( state.urgent ? 'urgent' : 'legacy_immediate' );
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
			if ( state.urgent ) {
				// The red bar's own height, whatever the device's news bar measures (2.16).
				full = root.hprnbBnH || parseFloat( getComputedStyle( root ).getPropertyValue( state.uFlow ? '--hprnb-u-m-height' : '--hprnb-u-height' ) ) || aside.offsetHeight;
				gap = 0;
			}
			var hidden = aside.hidden || root.hidden || body.classList.contains( 'hprnb-kbd' ) || root.classList.contains( 'hprnb-root--' + ( mobile ? 'm' : 'd' ) + '-pending' )
				|| root.classList.contains( 'hprnb-root--' + ( mobile ? 'm' : 'd' ) + '-away' );
			// In flow the bar is a block of the page: it covers nothing, so it reserves nothing.
			// Collapsed, a phone keeps its strip while the desktop bar slides fully away.
			var inflow = !! profile && profile.place === 'inline';
			var offset = ( hidden || inflow ) ? 0 : ( collapsed ? ( mobile ? peek + gap : 0 ) : full + gap );
			// The two phone designs carry their buttons in a tab above the bar: what sits in the
			// corner (Jannah's "go to top") must clear it too.
			var controls = aside.querySelector( '.hprnb-bar__controls' );
			// The URGENT bar has a tab in the chyron's phone design only (Breaking News keeps its buttons in the band).
			var tabbed = state.urgent ? !! state.uFlow : ( mobile && ( root.classList.contains( 'hprnb-root--m-ctrl-tab' ) || root.classList.contains( 'hprnb-root--m-card' ) ) );
			var tab = ( hidden || inflow || ! tabbed || ! controls ) ? 0 : controls.offsetHeight;
			return { mobile: mobile, collapsed: collapsed, height: full, offset: offset, tab: tab };
		}

		function emit() {
			var detail = current();
			body.classList.toggle( 'hprnb-is-collapsed', detail.collapsed );
			body.style.setProperty( '--hprnb-offset', detail.offset + 'px' );
			body.style.setProperty( '--hprnb-tab', detail.tab + 'px' );
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
			body.style.removeProperty( '--hprnb-tab' );
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
			if ( state.urgent ) {
				// Closed for this set of urgent articles: a newer flag brings the red bar back.
				try {
					// The headline of the article being read, set aside (2.17), belongs to the set closed too.
					localStorage.setItem( URGENT_KEY, String( newestUrgent( Array.prototype.slice.call( aside.querySelectorAll( '.hprnb-bar__item' ) ).concat( aside.hprnbOut || [] ) ) ) );
				} catch ( e ) {
					// Storage unavailable: closed for this page.
				}
				retireUrgent( root, aside );
				init( root );
				return;
			}
			if ( cfg.remember ) {
				try {
					localStorage.setItem( DISMISS_KEY, String( Date.now() + cfg.dismissHours * 3600000 ) );
				} catch ( e ) {
					// Storage unavailable: the bar is still closed for this page.
				}
			}
			if ( root.classList.contains( 'hprnb-root--urgent' ) && root.querySelector( '.hprnb-bar--urgent' ) ) {
				// The URGENT bar, switched off on this device only (2.16), waits in the root for the other
				// one: the news bar goes, the root stays, and the rules of a remembered dismissal decide
				// per device (nothing here, the red bar and its space there).
				document.documentElement.classList.add( 'hprnb-dismissed' );
				destroyAside( aside );
				root.removeChild( aside );
				init( root );
				return;
			}
			aside.hidden = true;
			root.hidden = true;
			document.body.classList.remove( 'hprnb-reserve' );
			if ( contract ) {
				contract.emit();
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
		}
		// "up" (2.13): once the bar has appeared, every scroll down opens it and every scroll up
		// folds it, wherever the reader is. No threshold, no zones.
		if ( 'article' === trigger || 'up' === trigger ) {
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
		if ( 'up' === trigger ) {
			landed = false; // It opens with its appearance and folds only on a scroll up.
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
				} else if ( 'up' === trigger ) {
					if ( state.revealY === null ) {
						lastY = y;
						return; // Still waiting to appear: nothing to fold.
					}
					if ( y > lastY + 8 ) {
						set( false );
					} else if ( y < lastY - 8 ) {
						set( true );
					}
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

	/* ------------------------------------------------------------------ */
	/* Urgent articles (2.14): the red bar in front of the news bar        */
	/* ------------------------------------------------------------------ */

	function nowSeconds() {
		return Math.floor( Date.now() / 1000 );
	}

	/**
	 * Drops the urgent headlines whose time is up and returns the ones still alive.
	 *
	 * @param {Element} aside The urgent bar.
	 * @return {Element[]} The remaining items.
	 */
	function pruneUrgent( aside ) {
		var alive = [];
		var t = nowSeconds();
		forEach( aside.querySelectorAll( '.hprnb-bar__item' ), function ( li ) {
			if ( ( parseInt( li.getAttribute( 'data-hprnb-until' ), 10 ) || 0 ) > t ) {
				alive.push( li );
			} else if ( li.parentNode ) {
				li.parentNode.removeChild( li );
			}
		} );
		// The headlines set aside for the article being read (2.17) end on time too.
		aside.hprnbOut = ( aside.hprnbOut || [] ).filter( function ( li ) {
			return ( parseInt( li.getAttribute( 'data-hprnb-until' ), 10 ) || 0 ) > t;
		} );
		var root = aside.parentNode;
		if ( root && root.setAttribute ) {
			root.setAttribute( 'data-hprnb-urgent', String( alive.length ) );
		}
		return alive;
	}

	/* ------------------------------------------------------------------ */
	/* The article being read (2.17, setting urgent_exclude_current)       */
	/* ------------------------------------------------------------------ */

	var HERE = null;

	/**
	 * An address reduced to what identifies an article: host without "www.", path without its trailing
	 * slash, lower case, and the ?p= / ?page_id= of a plain permalink; '' when it cannot be read.
	 *
	 * @param {string} href Address.
	 * @return {string}
	 */
	function urlKey( href ) {
		try {
			var u = new URL( href, location.href );
			var path = u.pathname.replace( /\/+$/, '' ) || '/';
			try {
				path = decodeURI( path );
			} catch ( e ) {
				// Malformed escape: compared as it is.
			}
			var q = u.searchParams.get( 'p' ) || u.searchParams.get( 'page_id' );
			return u.hostname.replace( /^www\./i, '' ).toLowerCase() + path.toLowerCase() + ( q ? '?' + q : '' );
		} catch ( e ) {
			return '';
		}
	}

	/**
	 * The article being read: its ID from the server, and its canonical address; after an in-page
	 * navigation, the new address alone.
	 *
	 * @param {Element} root The root.
	 * @return {{id:number,key:string}}
	 */
	function hereOf( root ) {
		if ( ! HERE ) {
			var canonical = document.querySelector( 'link[rel="canonical"]' );
			HERE = { id: parseInt( root.getAttribute( 'data-hprnb-post' ), 10 ) || 0, key: urlKey( canonical && canonical.href ? canonical.href : location.href ) };
		}
		return HERE;
	}

	/**
	 * Whether a headline announces the article being read.
	 *
	 * @param {Element} li   The headline.
	 * @param {Object}  here hereOf().
	 * @return {boolean}
	 */
	function isHere( li, here ) {
		var id = parseInt( li.getAttribute( 'data-hprnb-id' ), 10 ) || 0;
		var link = li.querySelector( '.hprnb-bar__link' );
		return ( here.id > 0 && id === here.id ) || ( '' !== here.key && !! link && urlKey( link.href ) === here.key );
	}

	/**
	 * Takes the headline of the article being read out of the URGENT bar, and puts back the one of the
	 * article just left, in their first order. Only between two runs of the bar.
	 *
	 * @param {Element} root  The root.
	 * @param {Element} aside The URGENT bar.
	 * @return {boolean} Whether the set aside changed.
	 */
	function hereFilter( root, aside ) {
		var list = aside.querySelector( '.hprnb-bar__list' );
		if ( ! list ) {
			return false;
		}
		var out = aside.hprnbOut || [];
		var n = 0;
		forEach( list.children, function ( li ) {
			if ( undefined === li.hprnbOrder ) {
				li.hprnbOrder = n;
			}
			n++;
		} );
		var on = '1' === root.getAttribute( 'data-hprnb-here' ) && ! root.classList.contains( 'hprnb-root--preview' );
		var here = on ? hereOf( root ) : null;
		var drop = [];
		Array.prototype.slice.call( list.children ).concat( out ).sort( function ( a, b ) {
			return a.hprnbOrder - b.hprnbOrder;
		} ).forEach( function ( li ) {
			// The server's mark only hides it until the script takes over.
			li.classList.remove( 'hprnb-bar__item--here' );
			if ( here && isHere( li, here ) ) {
				drop.push( li );
				if ( li.parentNode ) {
					li.parentNode.removeChild( li );
				}
			} else {
				list.appendChild( li );
			}
		} );
		aside.hprnbOut = drop;
		return drop.length !== out.length || drop.some( function ( li, i ) {
			return li !== out[ i ];
		} );
	}

	/**
	 * The URGENT bar steps aside on the page of the only article it announces: out of sight, still in
	 * the page for the next article; the news bar takes the page as it is.
	 *
	 * @param {Element} root The root.
	 */
	function parkUrgent( root ) {
		var was = root.classList.contains( 'hprnb-root--urgent' );
		root.classList.remove( 'hprnb-root--urgent' );
		root.setAttribute( 'data-hprnb-urgent', '0' );
		parkDevices( root, false );
		if ( root.classList.contains( 'hprnb-root--preview' ) ) {
			return;
		}
		// A news bar the reader has closed does not count: nothing would show in the space kept.
		if ( ! root.querySelector( '.hprnb-bar:not(.hprnb-bar--urgent):not([hidden])' ) ) {
			root.hidden = true;
			root.setAttribute( 'data-hprnb-empty', '1' );
			document.body.classList.remove( 'hprnb-reserve' );
		} else if ( was ) {
			// The news bar takes the page as the server would have rendered it: with its wait.
			restorePending( root );
			reserveFor( root, false );
		}
	}

	/**
	 * Follows a theme that moves on to another article without reloading the page (history API):
	 * the URGENT bar is filtered again for the new address.
	 */
	function followLocation() {
		if ( window.hprnbFollow ) {
			return;
		}
		window.hprnbFollow = true;
		var check = function () {
			var key = urlKey( location.href );
			if ( ! HERE || key === HERE.key ) {
				return;
			}
			HERE = { id: 0, key: key };
			var root = document.getElementById( 'hprnb-root' );
			var aside = root && root.querySelector( '.hprnb-bar--urgent' );
			if ( ! aside || '1' !== root.getAttribute( 'data-hprnb-here' ) ) {
				return;
			}
			var out = aside.hprnbOut || [];
			var now = Array.prototype.slice.call( aside.querySelectorAll( '.hprnb-bar__item' ) ).concat( out ).filter( function ( li ) {
				return isHere( li, HERE );
			} );
			if ( now.length !== out.length || now.some( function ( li ) {
				return out.indexOf( li ) === -1;
			} ) ) {
				destroy( root );
				init( root );
			}
		};
		forEach( [ 'pushState', 'replaceState' ], function ( method ) {
			var original = window.history && window.history[ method ];
			if ( typeof original !== 'function' ) {
				return;
			}
			window.history[ method ] = function () {
				var result = original.apply( this, arguments );
				setTimeout( check, 0 );
				return result;
			};
		} );
		window.addEventListener( 'popstate', check );
	}

	/** The most recent flag among the headlines: what a closed set is remembered by. */
	function newestUrgent( items ) {
		var newest = 0;
		forEach( items, function ( li ) {
			newest = Math.max( newest, parseInt( li.getAttribute( 'data-hprnb-since' ), 10 ) || 0 );
		} );
		return newest;
	}

	/**
	 * Whether the urgent bar still has something to show: at least one headline whose time is not up,
	 * and a set the reader has not closed (the preview root never expires and is never closed).
	 *
	 * @param {Element} root  The root.
	 * @param {Element} aside The urgent bar.
	 * @return {boolean}
	 */
	function urgentLive( root, aside ) {
		var items = pruneUrgent( aside );
		return items.length > 0 && urgentOpen( root, items );
	}

	/**
	 * Whether a set of urgent headlines is still open: never closed by the reader, or closed before its
	 * newest flag (the preview root is never closed).
	 *
	 * @param {Element}         root  The root.
	 * @param {Array|NodeList}  items The headlines.
	 * @return {boolean}
	 */
	function urgentOpen( root, items ) {
		if ( root.classList.contains( 'hprnb-root--preview' ) ) {
			return true;
		}
		var closed = 0;
		try {
			closed = parseInt( localStorage.getItem( URGENT_KEY ), 10 ) || 0;
		} catch ( e ) {
			closed = 0;
		}
		return closed < newestUrgent( items );
	}

	/**
	 * Whether the URGENT bar is switched on for a device (2.16: one switch per device).
	 *
	 * @param {Element} root The root.
	 * @param {string}  p    'd' or 'm'.
	 * @return {boolean}
	 */
	function urgentOn( root, p ) {
		return ! root.classList.contains( 'hprnb-root--u-no-' + p );
	}

	/**
	 * The URGENT bar shows on both devices: the news bar's own restriction (hprnb-hide-*) is parked
	 * under another name while it is in front, and put back when it hands over (2.15).
	 *
	 * @param {Element} root   The root.
	 * @param {boolean} urgent Whether the URGENT bar is in front.
	 */
	function parkDevices( root, urgent ) {
		forEach( [ 'mobile', 'desktop' ], function ( d ) {
			var hide = 'hprnb-hide-' + d;
			var park = 'hprnb-news-hide-' + d;
			var front = urgent && urgentOn( root, d.charAt( 0 ) );
			if ( front && root.classList.contains( hide ) ) {
				root.classList.replace( hide, park );
			} else if ( ! front && root.classList.contains( park ) ) {
				root.classList.replace( park, hide );
			}
		} );
	}

	/** The news bar waits for the reader again, exactly as the server renders it without urgent articles. */
	function restorePending( root ) {
		var all = parseJson( root.getAttribute( 'data-hprnb-reveal' ) ) || {};
		var waits = false;
		forEach( [ 'd', 'm' ], function ( p ) {
			if ( 'immediate' !== ( ( all[ p ] && all[ p ].mode ) || 'immediate' ) ) {
				waits = true;
				root.classList.add( 'hprnb-root--' + p + '-pending' );
				document.body.classList.add( 'hprnb-' + p + '-pending' );
			}
		} );
		if ( waits ) {
			root.classList.add( 'hprnb-root--reveal' );
		}
	}

	/**
	 * The space the page keeps for the bar in front: the urgent bar's own heights, or the news bar's,
	 * both carried by the root's inline style.
	 *
	 * @param {Element} root   The root.
	 * @param {boolean} urgent Whether the urgent bar is in front.
	 */
	function reserveFor( root, urgent ) {
		var body = document.body;
		var read = function ( name ) {
			return root.style.getPropertyValue( name );
		};
		// Each device keeps the height of the bar in front there (2.16).
		var ud = urgent && urgentOn( root, 'd' );
		var um = urgent && urgentOn( root, 'm' );
		// 2.17: the Breaking News bar has the height it measured (the short-screen 44px does not apply).
		var bn = urgent && root.hprnbBnH ? root.hprnbBnH + 'px' : '';
		// A screen past 768px whose bar is still under it (the scrollbar's width) shows the phone design.
		var d = ( ud && ( bn || read( isNarrow( root ) ? '--hprnb-u-m-height' : '--hprnb-u-height' ) ) ) || read( '--hprnb-height' );
		var m = ( um && ( bn || read( '--hprnb-u-m-height' ) ) ) || read( '--hprnb-m-height' );
		if ( d ) {
			body.style.setProperty( '--hprnb-height', d, ud && bn ? 'important' : '' );
		}
		if ( m ) {
			body.style.setProperty( '--hprnb-m-height', m, um && bn ? 'important' : '' );
		}
		body.style.setProperty( '--hprnb-m-gap', ( um ? '' : read( '--hprnb-m-gap' ) ) || '0px' );
	}

	/**
	 * Takes the urgent bar out for good and gives the page back to the news bar — or to nothing.
	 *
	 * @param {Element} root  The root.
	 * @param {Element} aside The urgent bar.
	 */
	function retireUrgent( root, aside ) {
		destroyAside( aside );
		if ( aside.parentNode !== root ) {
			return; // Markup already replaced (hybrid refresh): the root is no longer this bar's.
		}
		root.removeChild( aside );
		root.classList.remove( 'hprnb-root--urgent' );
		root.setAttribute( 'data-hprnb-urgent', '0' );
		parkDevices( root, false );
		if ( root.classList.contains( 'hprnb-root--preview' ) ) {
			return;
		}
		if ( ! root.querySelector( '.hprnb-bar' ) ) {
			root.hidden = true;
			root.setAttribute( 'data-hprnb-empty', '1' );
			document.body.classList.remove( 'hprnb-reserve' );
			return;
		}
		restorePending( root );
		reserveFor( root, false );
	}

	/**
	 * Ends every urgent headline on time: at the next expiry the bar is pruned and restarted with what
	 * is left, and retired when nothing is; a tab coming back to the front checks at once.
	 *
	 * @param {Object}  state Teardown registry of the urgent bar.
	 * @param {Element} root  The root.
	 * @param {Element} aside The urgent bar.
	 */
	function scheduleUrgent( state, root, aside ) {
		if ( root.classList.contains( 'hprnb-root--preview' ) ) {
			return;
		}
		var timer = null;

		function check() {
			if ( aside.hprnbState !== state ) {
				return;
			}
			if ( aside.parentNode !== root ) {
				destroyAside( aside ); // Markup replaced (hybrid refresh): the new bars are not this timer's.
				return;
			}
			var before = aside.querySelectorAll( '.hprnb-bar__item' ).length;
			if ( ! urgentLive( root, aside ) ) {
				// Nothing left on screen: init() parks the bar if the article being read is still urgent (2.17), retires it otherwise.
				destroyAside( aside );
				init( root );
				return;
			}
			if ( aside.querySelectorAll( '.hprnb-bar__item' ).length !== before ) {
				destroyAside( aside ); // Fewer headlines: the rotation starts afresh with what is left.
				init( root );
				return;
			}
			arm();
		}

		function arm() {
			var next = 0;
			var t = nowSeconds();
			forEach( aside.querySelectorAll( '.hprnb-bar__item' ), function ( li ) {
				var until = parseInt( li.getAttribute( 'data-hprnb-until' ), 10 ) || 0;
				if ( until > t && ( ! next || until < next ) ) {
					next = until;
				}
			} );
			if ( timer !== null ) {
				clearTimeout( timer );
			}
			timer = next ? state.timer( check, Math.min( 2147483647, ( next - t ) * 1000 + 250 ) ) : null;
		}

		state.on( document, 'visibilitychange', function () {
			if ( ! document.hidden ) {
				check();
			}
		} );
		arm();
	}

	/**
	 * The URGENT bar in its phone design keeps one height (2.15): a headline shorter than the lines the
	 * bar is made for sits in the middle instead of at the top, so the bar never jumps while it rotates
	 * and the space the page keeps for it is exactly its height.
	 *
	 * @param {Object}  state Teardown registry of the bar.
	 * @param {Element} aside The urgent bar.
	 */
	function balanceUrgent( state, aside ) {
		var viewport = aside.querySelector( '.hprnb-bar__viewport' );
		var inner = aside.querySelector( '.hprnb-bar__inner' );
		if ( ! viewport || ! inner ) {
			return;
		}
		function check() {
			if ( aside.hprnbState !== state ) {
				return;
			}
			var title = aside.querySelector( '.hprnb-bar__item:not([hidden]) .hprnb-bar__title' );
			var line = parseFloat( getComputedStyle( inner ).lineHeight ) || 26;
			aside.classList.toggle( 'hprnb-bar--u-one', !! title && title.getBoundingClientRect().height < line * 1.5 );
		}
		observeSize( state, viewport, check );
		state.add( function () {
			aside.classList.remove( 'hprnb-bar--u-one' );
		} );
		check();
	}

	/**
	 * A headline taller than its clipped viewport fades out at the end of its last line: the flag the
	 * rotation sets, for a bar with a single headline.
	 *
	 * @param {Object}  state Teardown registry of the bar.
	 * @param {Element} aside The bar.
	 */
	function flagClip( state, aside ) {
		var viewport = aside.querySelector( '.hprnb-bar__viewport' );
		if ( ! viewport ) {
			return;
		}
		function check() {
			if ( aside.hprnbState === state ) {
				viewport.classList.toggle( 'is-clipped', viewport.scrollHeight > viewport.clientHeight + 1 );
			}
		}
		observeSize( state, viewport, check );
		state.add( function () {
			viewport.classList.remove( 'is-clipped' );
		} );
		check();
	}

	/* ------------------------------------------------------------------ */
	/* Breaking News (2.17): each whole headline typed in, one after another */
	/* ------------------------------------------------------------------ */

	/**
	 * The rhythm of the typing and of the rotation: a letter every 26ms (a space counts half), a short
	 * breath after punctuation, never under 0.45s nor over 2.6s for a whole headline (the rate rises
	 * instead); each letter shows at once in the headline's own colour, no cursor, no fade (2.19); each
	 * headline then stays whole 5s at least (or the rotation interval), plus 40ms a letter past 60 (7s
	 * more at most); the one leaving fades out in 220ms, and the label stays alone 90ms before the next
	 * one types in. The first headline types 700ms after the band's opening starts (2.19), once the
	 * cartouche, the hairline and the buttons are in place.
	 */
	var BN = { unit: 26, min: 450, max: 2600, breath: 70, hold: 5000, from: 60, per: 40, extra: 7000, fade: 220, gap: 90, resume: 2000, open: 700 };

	/** The CSS highlight of the typing: the part not typed yet. */
	var BN_REST = 'hprnb-u-bn-rest';

	/** Scripts whose letters join (Arabic and neighbours; Hebrew for its final forms): typed word by word, never in broken forms. */
	var JOINED = ( function () {
		try {
			return new RegExp( '[\\p{Script=Arabic}\\p{Script=Syriac}\\p{Script=Nko}\\p{Script=Mandaic}\\p{Script=Mongolian}\\p{Script=Phags_Pa}\\p{Script=Adlam}\\p{Script=Hebrew}]', 'u' );
		} catch ( e ) {
			return /[\u0590-\u08FF\u1800-\u18AF\uA840-\uA87F\uFB1D-\uFDFF\uFE70-\uFEFF]/;
		}
	}() );

	var BREATH = /[,;:.!?\u2026\u060C\u061B\u061F\u06D4]\s*$/;

	/**
	 * The graphemes of a text: an accented letter or an emoji is one.
	 *
	 * @param {string} text The text.
	 * @return {string[]}
	 */
	function graphemes( text ) {
		if ( typeof Intl === 'object' && typeof Intl.Segmenter === 'function' ) {
			try {
				return Array.from( new Intl.Segmenter( undefined, { granularity: 'grapheme' } ).segment( text ), function ( part ) {
					return part.segment;
				} );
			} catch ( e ) {}
		}
		return Array.from( text );
	}

	/**
	 * The typing of one headline: where each step ends in the string, when it shows (ms after the first
	 * frame), when the last one shows, and how much longer than the base the whole headline stays.
	 *
	 * @param {string} text The headline.
	 * @return {{ends: number[], times: number[], done: number, extra: number, length: number}}
	 */
	function typePlan( text ) {
		var steps = [];
		var total = 0;
		var letters = 0;
		if ( JOINED.test( text ) ) {
			// A word with the spaces after it, weighed by its letters: a sentence takes as long as it would letter by letter.
			var re = /\s*\S+\s*/g;
			var m;
			while ( ( m = re.exec( text ) ) !== null ) {
				var w = graphemes( m[ 0 ].trim() ).length;
				steps.push( { end: re.lastIndex, w: w, breath: BREATH.test( m[ 0 ] ) } );
				total += w;
				letters += w;
			}
		} else {
			var end = 0;
			forEach( graphemes( text ), function ( g ) {
				var space = ! g.trim();
				end += g.length;
				steps.push( { end: end, w: space ? 0.5 : 1, breath: BREATH.test( g ) } );
				total += space ? 0.5 : 1;
				letters += space ? 0 : 1;
			} );
		}
		var per = total ? Math.min( BN.max, Math.max( BN.min, total * BN.unit ) ) / total : 0;
		var t = 0;
		var ends = [];
		var times = [];
		forEach( steps, function ( step ) {
			ends.push( step.end );
			times.push( t );
			t += step.w * per + ( step.breath ? BN.breath : 0 );
		} );
		return {
			ends: ends,
			times: times,
			done: times.length ? times[ times.length - 1 ] : 0,
			extra: Math.min( BN.extra, Math.max( 0, letters - BN.from ) * BN.per ),
			length: text.length,
		};
	}

	/**
	 * Paints a title as typed up to an offset, without ever changing its text: [0, a) as it is (the
	 * letters typed show whole at once), [a, end) laid out but transparent. Through the CSS Custom
	 * Highlight API; in two spans (the second .hprnb-bar__rest), put back as they were by clear(), where
	 * it is missing.
	 *
	 * @return {{attach: function(Element): boolean, set: function(number, number), clear: function()}}
	 */
	function createReveal() {
		var title = null;
		if ( typeof CSS !== 'undefined' && CSS.highlights && typeof Highlight === 'function' ) {
			var range = document.createRange();
			var nodes = null;
			var at = function ( offset ) {
				for ( var i = 0; i < nodes.length; i++ ) {
					if ( offset <= nodes[ i ].end || i === nodes.length - 1 ) {
						return [ nodes[ i ].node, Math.max( 0, Math.min( offset - nodes[ i ].start, nodes[ i ].node.length ) ) ];
					}
				}
				return null;
			};
			return {
				attach: function ( el ) {
					this.clear();
					nodes = [];
					var walker = document.createTreeWalker( el, NodeFilter.SHOW_TEXT );
					var pos = 0;
					for ( var n = walker.nextNode(); n; n = walker.nextNode() ) {
						nodes.push( { node: n, start: pos, end: pos + n.length } );
						pos += n.length;
					}
					if ( ! nodes.length ) {
						nodes = null;
						return false;
					}
					title = el;
					var mark = CSS.highlights.get( BN_REST );
					if ( ! mark ) {
						mark = new Highlight();
						CSS.highlights.set( BN_REST, mark );
					}
					mark.add( range );
					return true;
				},
				set: function ( a, length ) {
					var from = at( a );
					var to = at( length );
					range.setStart( from[ 0 ], from[ 1 ] );
					range.setEnd( to[ 0 ], to[ 1 ] );
				},
				clear: function () {
					if ( ! title ) {
						return;
					}
					var mark = CSS.highlights.get( BN_REST );
					if ( mark ) {
						mark.delete( range );
						if ( ! mark.size ) {
							CSS.highlights.delete( BN_REST );
						}
					}
					title = null;
					nodes = null;
				},
			};
		}
		var saved = null;
		var parts = null;
		var text = '';
		return {
			attach: function ( el ) {
				this.clear();
				title = el;
				saved = Array.prototype.slice.call( el.childNodes );
				text = el.textContent;
				parts = [ '', 'hprnb-bar__rest' ].map( function ( name ) {
					var span = document.createElement( 'span' );
					if ( name ) {
						span.className = name;
					}
					return span;
				} );
				el.textContent = '';
				forEach( parts, function ( span ) {
					el.appendChild( span );
				} );
				return true;
			},
			set: function ( a, length ) {
				var cuts = [ 0, a, length ];
				forEach( parts, function ( span, i ) {
					var part = text.slice( cuts[ i ], cuts[ i + 1 ] );
					if ( span.textContent !== part ) {
						span.textContent = part;
					}
				} );
			},
			clear: function () {
				if ( ! title ) {
					return;
				}
				title.textContent = '';
				forEach( saved, function ( node ) {
					title.appendChild( node );
				} );
				title = null;
				saved = null;
				parts = null;
			},
		};
	}

	/**
	 * How long the band's opening (stylesheet 17 quater, 2.18) still runs before a headline may type:
	 * its clock is the band's own animation, which lasts the whole opening (700ms), read where it is (a
	 * re-initialisation or a late start waits only for what is left); 0 once it is over, under reduced
	 * motion, or without it.
	 *
	 * @param {Element} aside The URGENT bar.
	 * @return {number} Milliseconds.
	 */
	function openingLeft( aside ) {
		var left = 0;
		if ( typeof aside.getAnimations === 'function' ) {
			forEach( aside.getAnimations(), function ( animation ) {
				if ( 'hprnb-u-bn-open' === animation.animationName && 'running' === animation.playState ) {
					left = Math.max( 0, BN.open - ( ( animation.effect.getComputedTiming().localTime || 0 ) - ( animation.effect.getTiming().delay || 0 ) ) );
				}
			} );
		}
		return left;
	}

	/**
	 * Whether the first Breaking News headline is already on screen: the stylesheet keeps it invisible
	 * 1.5s for the script (hprnb-u-bn-wait), then lets it appear; a script later than that must not
	 * erase it to type it again. Read before the bar is marked as initialised (that ends the wait).
	 *
	 * @param {Element} aside The URGENT bar.
	 * @return {boolean}
	 */
	function breakingLate( aside ) {
		var list = aside.querySelector( '.hprnb-bar__list' );
		if ( ! list || typeof list.getAnimations !== 'function' ) {
			return false;
		}
		return ! list.getAnimations().some( function ( animation ) {
			return 'hprnb-u-bn-wait' === animation.animationName && 'running' === animation.playState;
		} );
	}

	/**
	 * The "Breaking News" design of the URGENT bar: the label stays still, each headline is typed in,
	 * held long enough to be read, then fades out and the next one types in; a single headline is typed
	 * once and stays. The title's text never changes (the part not typed yet is laid out but painted
	 * transparent), so nothing moves while typing and screen readers get the whole headline (the bar is
	 * aria-live="off"). Every headline shares one grid cell, so the bar keeps the height of the tallest
	 * from the first paint; that height is measured and reserved by the page. The keyboard on the
	 * headline or the pause button completes a headline being typed and stops the sequence; the mouse
	 * over the bar lets it finish, then stops it; a hidden tab stops it too; it goes on with what was
	 * left of the hold, 2s at least. Reduced motion or forced colours (followed live): every headline
	 * whole, swapped without a fade. A re-initialisation of the same bar resumes on the headline it was
	 * showing, without typing it again.
	 *
	 * @param {Object}      state    Teardown registry.
	 * @param {Element}     root     The root.
	 * @param {Element}     aside    The URGENT bar.
	 * @param {Object}      cfg      readConfig().
	 * @param {Element}     toggle   The pause button, or null.
	 * @param {Object|null} contract setupContract().
	 */
	function setupBreaking( state, root, aside, cfg, toggle, contract ) {
		var items = Array.prototype.slice.call( aside.querySelectorAll( '.hprnb-bar__item' ) );
		var viewport = aside.querySelector( '.hprnb-bar__viewport' );
		if ( ! items.length || ! viewport ) {
			return;
		}
		var preview = root.classList.contains( 'hprnb-root--preview' );
		var memory = aside.hprnbBn || ( aside.hprnbBn = {} );
		var reveal = createReveal();
		var plans = [];
		var index = 0;
		var phase = 'idle'; // typing, hold, leaving, still (a single headline, done)
		var frame = 0;
		var start = 0;
		var shown = -1;
		var timer = null;
		var holdLeft = 0;
		var holdAt = 0;
		var ctrl = null;
		var started = false;
		var reduce = window.matchMedia ? window.matchMedia( '(prefers-reduced-motion: reduce)' ) : null;
		var forced = window.matchMedia ? window.matchMedia( '(forced-colors: active)' ) : null;
		var lead = 0;
		forEach( items, function ( li, k ) {
			if ( li.getAttribute( 'data-hprnb-id' ) === memory.id ) {
				index = k;
			}
		} );
		state.hide( toggle, items.length < 2 );
		state.addClass( aside, 'hprnb-bar--bn' );

		function still() {
			return !! ( ( reduce && reduce.matches ) || ( forced && forced.matches ) );
		}

		function planOf( k ) {
			if ( ! plans[ k ] ) {
				var title = items[ k ].querySelector( '.hprnb-bar__title' );
				plans[ k ] = typePlan( title ? title.textContent : '' );
			}
			return plans[ k ];
		}

		function clearTimer() {
			if ( null !== timer ) {
				clearTimeout( timer );
				timer = null;
			}
		}

		function stopFrame() {
			if ( frame ) {
				cancelAnimationFrame( frame );
				frame = 0;
			}
		}

		function tick( now ) {
			frame = 0;
			if ( 'typing' !== phase || aside.hprnbState !== state ) {
				return;
			}
			if ( ! start ) {
				// The first letter shows on the first frame after the band's opening (and the buttons' fade).
				start = now + Math.max( openingLeft( aside ), lead );
				lead = 0;
			}
			var p = planOf( index );
			var elapsed = now - start;
			var n = p.times.length;
			var a = 0;
			while ( a < n && p.times[ a ] <= elapsed ) {
				a++;
			}
			if ( a >= n ) {
				typed();
				return;
			}
			if ( shown !== a ) {
				shown = a;
				// Letters on screen: a re-initialisation shows this headline whole instead of typing it again.
				memory.started = memory.id;
				reveal.set( a ? p.ends[ a - 1 ] : 0, p.length );
			}
			frame = requestAnimationFrame( tick );
		}

		/** The headline is whole: it stays (a single one), or its hold starts. */
		function typed() {
			stopFrame();
			reveal.clear();
			shown = -1;
			items[ index ].classList.remove( 'is-typing' );
			aside.classList.remove( 'hprnb-bar--typing' );
			memory.done = memory.id;
			if ( items.length < 2 ) {
				phase = 'still';
				return;
			}
			phase = 'hold';
			holdLeft = Math.max( BN.hold, cfg.interval || 0 ) + planOf( index ).extra;
			arm();
		}

		function arm() {
			clearTimer();
			if ( 'hold' !== phase || ( ctrl && ctrl.paused() ) ) {
				return;
			}
			holdAt = Date.now();
			timer = setTimeout( leave, holdLeft );
		}

		function leave() {
			timer = null;
			if ( still() ) {
				enter( index + 1 );
				return;
			}
			phase = 'leaving';
			items[ index ].classList.add( 'is-leaving' );
			timer = setTimeout( function () {
				timer = null;
				enter( index + 1 );
			}, BN.fade + BN.gap );
		}

		function enter( i ) {
			stopFrame();
			clearTimer();
			reveal.clear();
			index = ( i + items.length ) % items.length;
			var li = items[ index ];
			var id = li.getAttribute( 'data-hprnb-id' );
			var title = li.querySelector( '.hprnb-bar__title' );
			var p = planOf( index );
			// Already typed (or being typed) before a re-initialisation, already on screen, or nothing to type: whole at once.
			var whole = still() || memory.late || ( memory.id === id && ( memory.done === id || memory.started === id ) ) || ( ctrl && ctrl.paused() ) || ! p.times.length || ! title || ! reveal.attach( title );
			memory.late = false;
			memory.id = id;
			memory.done = null;
			memory.started = null;
			if ( ! whole ) {
				// Everything transparent in the same task as the swap: one paint shows the empty headline.
				reveal.set( 0, p.length );
				shown = 0;
				li.classList.add( 'is-typing' );
				aside.classList.add( 'hprnb-bar--typing' );
				phase = 'typing';
				start = 0;
			}
			forEach( items, function ( other, k ) {
				other.classList.remove( 'is-leaving' );
				other.classList.toggle( 'is-current', k === index );
			} );
			if ( whole ) {
				typed();
			} else {
				frame = requestAnimationFrame( tick );
			}
		}

		ctrl = createPauseController( state, aside, cfg, toggle, viewport, function ( paused, why ) {
			if ( ! started ) {
				return;
			}
			if ( 'typing' === phase ) {
				// The reader wants this headline now (the keyboard on it, the pause button); the mouse lets it finish.
				if ( why.user || why.focus ) {
					typed();
				}
				return;
			}
			if ( paused ) {
				if ( null !== timer && 'hold' === phase ) {
					clearTimer();
					holdLeft = Math.max( 0, holdLeft - ( Date.now() - holdAt ) );
				} else if ( 'leaving' === phase ) {
					clearTimer();
					items[ index ].classList.remove( 'is-leaving' );
					phase = 'hold';
					holdLeft = 0;
				}
			} else if ( 'hold' === phase && null === timer ) {
				holdLeft = Math.max( holdLeft, BN.resume );
				arm();
			}
		} );

		// The opening plays once (2.18): the bar is marked when it ends, or at once when it is over or not
		// played. Kept by a re-initialisation, so a bar hidden and shown again, or a preference that
		// changes, never opens again.
		var opening = openingLeft( aside );
		var opened = function () {
			aside.setAttribute( 'data-hprnb-opened', '' );
		};
		var controls = aside.querySelector( '.hprnb-bar__controls' );
		if ( ! memory.shown && ! still() && ! aside.hasAttribute( 'data-hprnb-opened' ) && ! aside.style.getPropertyValue( '--hprnb-u-bn-t' ) && controls && typeof controls.animate === 'function' && ! controls.getAnimations().some( function ( animation ) {
			return 'hprnb-u-bn-ctrl' === animation.animationName;
		} ) ) {
			// The script came after the buttons' fade, which ran out of sight (they wait for it): they fade in now.
			controls.animate( [ { opacity: 0, scale: '.92' }, { opacity: 1, scale: '1' } ], { duration: 170, easing: 'ease-out' } );
			lead = 220;
		}
		memory.shown = true;
		if ( still() || ( ! opening && aside.getClientRects().length ) ) {
			opened();
		} else if ( opening ) {
			state.on( aside, 'animationend', function ( event ) {
				if ( event.target === aside && 'hprnb-u-bn-open' === event.animationName ) {
					opened();
				}
			} );
		}

		// Reduced motion or forced colours switched on meanwhile: the headline being typed is whole at once.
		forEach( [ reduce, forced ], function ( query ) {
			if ( query && query.addEventListener ) {
				state.on( query, 'change', function () {
					if ( ! still() ) {
						return;
					}
					if ( 'typing' === phase ) {
						typed();
					} else if ( 'leaving' === phase ) {
						enter( index + 1 );
					}
				} );
			}
		} );

		// The height of the tallest headline, reserved by the page.
		function measure() {
			if ( aside.hprnbState !== state ) {
				return;
			}
			var height = Math.ceil( aside.getBoundingClientRect().height - ( parseFloat( getComputedStyle( aside ).paddingBottom ) || 0 ) );
			if ( height > 0 && height !== root.hprnbBnH ) {
				root.hprnbBnH = height;
				if ( ! preview ) {
					reserveFor( root, true );
				}
				if ( contract ) {
					contract.emit();
				}
			}
		}
		observeSize( state, aside, measure );
		if ( document.fonts && document.fonts.ready ) {
			document.fonts.ready.then( measure );
		}

		state.add( function () {
			started = false;
			stopFrame();
			clearTimer();
			reveal.clear();
			phase = 'idle';
			aside.classList.remove( 'hprnb-bar--typing' );
			forEach( items, function ( li ) {
				li.classList.remove( 'is-current', 'is-leaving', 'is-typing' );
			} );
			delete root.hprnbBnH;
		} );

		started = true;
		enter( index );
		measure();
	}

	/**
	 * Initialises one bar. Idempotent per aside; destroyAside() undoes everything.
	 *
	 * @param {Element} root   The #hprnb-root element (or the admin preview root).
	 * @param {Element} aside  The bar to run.
	 * @param {boolean} urgent Whether it is the red bar of the urgent articles (2.14).
	 */
	function initAside( root, aside, urgent ) {
		if ( ! aside || aside.hprnbState ) {
			return;
		}
		var state = createState();
		state.urgent = !! urgent;
		aside.hprnbState = state;
		if ( urgent && ! aside.hprnbBn && root.classList.contains( 'hprnb-root--u-bn' ) ) {
			// Read before the bar is marked as running, which ends the stylesheet's wait (2.17).
			aside.hprnbBn = { late: ! root.classList.contains( 'hprnb-root--preview' ) && breakingLate( aside ) };
		}
		aside.setAttribute( 'data-hprnb-init', '1' );
		state.add( function () {
			aside.removeAttribute( 'data-hprnb-init' );
		} );

		var cfg = readConfig( aside, root );
		var mobile = isNarrow( root );
		var device = deviceOf( root );
		// 2.17: the "Breaking News" design types each whole headline in, its close button in the band.
		var bn = !! urgent && root.classList.contains( 'hprnb-root--u-bn' );
		state.bn = bn;
		// The chyron in its phone design: always on a phone, and from 768px too when chosen (2.15).
		var uFlow = !! urgent && ! bn && ( mobile || root.classList.contains( 'hprnb-root--u-d-flow' ) );
		state.uFlow = uFlow;
		var profile = ( mobile || uFlow ) ? cfg.m : cfg.d;
		if ( urgent ) {
			// One shape, in front at once, never folding, never leaving for the next article, always closable.
			profile = merge( merge( {}, profile ), { collapse: false, next: false, deep: false, place: 'fixed', close: true, counter: false } );
		}
		// The URGENT bar is fixed wherever the root is: moving the root would only replay its opening (2.18);
		// the news bar puts the root in its place when it takes over.
		if ( ! urgent ) {
			relocate( root, mobile );
		}
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
		if ( uFlow ) {
			mode = 'rotate'; // The two-line strip shows one headline at a time, whatever the news bar does.
		}
		if ( bn ) {
			mode = 'type';
		}
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
		var phoneLike = mobile || uFlow;
		var wantsPause = ! phoneLike || profile.pause !== false;
		var wantsClose = ! phoneLike || profile.close !== false;
		state.hide( toggle, ( mode !== 'marquee' && mode !== 'rotate' && mode !== 'type' ) || ! wantsPause );
		state.hide( prev, mode !== 'manual' );
		state.hide( next, mode !== 'manual' );
		state.hide( aside.querySelector( '.hprnb-bar__btn--close' ), ! wantsClose );

		var contract = setupContract( state, root, aside, profile, mobile );
		var analytics = root.classList.contains( 'hprnb-root--preview' ) ? null : createAnalytics( root, aside, mobile, urgent ? 'urgent' : 'news' );
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

		if ( mode === 'type' ) {
			setupBreaking( state, root, aside, cfg, toggle, contract );
		} else if ( mode === 'marquee' ) {
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
		// A single headline clipped to its lines fades out at the end like a rotated one (2.14).
		if ( mobile && ! bn && aside.querySelectorAll( '.hprnb-bar__item' ).length < 2 && ( urgent || profile.layout === 'flow' ) ) {
			flagClip( state, aside );
		}
		if ( urgent ) {
			scheduleUrgent( state, root, aside );
		}
		if ( uFlow ) {
			balanceUrgent( state, aside );
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

		// Crossing the 768px threshold re-initialises the bar for the other presentation, and for the
		// other device's switches (the screen may cross it a scrollbar's width before the bar does).
		observeSize( state, root, function () {
			if ( aside.hprnbState === state && ( isNarrow( root ) !== mobile || deviceOf( root ) !== device ) ) {
				destroy( root );
				init( root );
			}
		} );
	}

	/**
	 * Initialises the bar in force in `root`: the red bar of the urgent articles while any of them
	 * lasts and the reader has not closed that set, the news bar otherwise (2.14).
	 *
	 * @param {Element|null} root The #hprnb-root element (or the admin preview root).
	 */
	function init( root ) {
		if ( ! root ) {
			return;
		}
		unwatch( root );
		var urgent = root.querySelector( '.hprnb-bar--urgent' );
		if ( urgent && urgent.hprnbState ) {
			return; // In front and running.
		}
		if ( urgent ) {
			hereFilter( root, urgent );
			if ( '1' === root.getAttribute( 'data-hprnb-here' ) && ! root.classList.contains( 'hprnb-root--preview' ) ) {
				followLocation();
			}
			if ( urgentLive( root, urgent ) ) {
				var preview = root.classList.contains( 'hprnb-root--preview' );
				root.classList.add( 'hprnb-root--urgent' );
				if ( root.hidden && ! preview ) {
					// Back from a parked state on a page without a news bar (2.17).
					root.hidden = false;
					root.setAttribute( 'data-hprnb-empty', '0' );
					if ( 'reserve' === root.getAttribute( 'data-hprnb-layout' ) ) {
						document.body.classList.add( 'hprnb-reserve' );
					}
				}
				parkDevices( root, true );
				// In front, and waiting for nobody, on the devices it is switched on for (2.16).
				forEach( [ 'd', 'm' ], function ( p ) {
					if ( urgentOn( root, p ) ) {
						root.classList.remove( 'hprnb-root--' + p + '-pending' );
						if ( ! preview ) {
							document.body.classList.remove( 'hprnb-' + p + '-pending' );
						}
					}
				} );
				if ( urgentOn( root, 'd' ) && urgentOn( root, 'm' ) ) {
					root.classList.remove( 'hprnb-root--reveal' );
				}
				if ( ! preview ) {
					reserveFor( root, true );
				}
				if ( urgentOn( root, deviceOf( root ) ) ) {
					// The presentation may be the other device's (a screen past 768px whose bar is under it):
					// nothing waits there either while the red bar is in front.
					var shown = isNarrow( root ) ? 'm' : 'd';
					root.classList.remove( 'hprnb-root--' + shown + '-pending' );
					if ( ! preview ) {
						document.body.classList.remove( 'hprnb-' + shown + '-pending' );
					}
					initAside( root, urgent, true );
					return;
				}
				// Switched off on this device: the news bar runs here, the red bar waits (hidden) for the
				// other device, where crossing 768px re-initialises everything.
			} else if ( urgent.hprnbOut && urgent.hprnbOut.length && urgentOpen( root, urgent.hprnbOut ) ) {
				// Only the article being read is urgent: the red bar steps aside for this page (2.17).
				parkUrgent( root );
			} else {
				retireUrgent( root, urgent );
			}
		}
		var news = root.querySelector( '.hprnb-bar:not(.hprnb-bar--urgent)' );
		// A news bar the stylesheet hides here (closed and remembered, or switched off on this device)
		// does not run: no impression, no space announced to the theme.
		var hidden = document.documentElement.classList.contains( 'hprnb-dismissed' ) || root.classList.contains( 'hprnb-hide-' + ( 'd' === deviceOf( root ) ? 'desktop' : 'mobile' ) );
		if ( news && ! hidden ) {
			initAside( root, news, false );
		} else if ( news || ( urgent && urgent.parentNode ) ) {
			// Nothing runs on this device: still re-evaluate when the screen crosses 768px, where a bar
			// may be on (2.16).
			watch( root );
		}
	}

	/**
	 * Re-initialises the root when the screen crosses 768px while no bar runs on it.
	 *
	 * @param {Element} root The root.
	 */
	function watch( root ) {
		var state = createState();
		var device = deviceOf( root );
		root.hprnbWatch = state;
		// No bar in view: the contract says so (hprnb:state and hprnbBar.state()).
		var idle = { mobile: 'm' === device, collapsed: false, height: 0, offset: 0, tab: 0 };
		api.state = function () {
			return idle;
		};
		try {
			document.dispatchEvent( new CustomEvent( 'hprnb:state', { detail: idle } ) );
		} catch ( e ) {
			// Very old engines without CustomEvent.
		}
		observeSize( state, root, function () {
			if ( root.hprnbWatch === state && deviceOf( root ) !== device ) {
				init( root );
			}
		} );
	}

	/** Stops the watch of watch(), if any. */
	function unwatch( root ) {
		var state = root.hprnbWatch;
		if ( ! state ) {
			return;
		}
		delete root.hprnbWatch;
		for ( var i = state.cleanups.length - 1; i >= 0; i-- ) {
			state.cleanups[ i ]();
		}
	}

	/**
	 * Undoes everything initAside() did on one bar.
	 *
	 * @param {Element|null} aside The bar.
	 */
	function destroyAside( aside ) {
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

	/**
	 * Undoes everything init() did on the bars found in `root`.
	 *
	 * @param {Element|null} root The #hprnb-root element (or the admin preview root).
	 */
	function destroy( root ) {
		if ( ! root ) {
			return;
		}
		unwatch( root );
		forEach( root.querySelectorAll( '.hprnb-bar' ), destroyAside );
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
