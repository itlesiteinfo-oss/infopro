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
	/* Reveal: the bar may wait before showing up (settings reveal_mode)   */
	/* ------------------------------------------------------------------ */

	function setupReveal( state, root, contract ) {
		var cfg = parseJson( root.getAttribute( 'data-hprnb-reveal' ) );
		var mode = cfg && cfg.mode ? cfg.mode : 'immediate';
		if ( 'immediate' === mode || ! root.classList.contains( 'hprnb-root--pending' ) ) {
			return;
		}
		var body = document.body;
		var value = typeof cfg.value === 'number' ? cfg.value : 400;

		function reached() {
			var y = window.scrollY;
			if ( 'scroll' === mode ) {
				return y >= value;
			}
			var scrollable = Math.max( 1, document.documentElement.scrollHeight - window.innerHeight );
			return ( y / scrollable ) * 100 >= value;
		}

		function reveal() {
			root.classList.remove( 'hprnb-root--pending' );
			body.classList.remove( 'hprnb-pending' );
			if ( contract ) {
				contract.emit();
			}
		}

		state.add( function () {
			// destroy() must not leave a half-revealed bar behind.
			root.classList.remove( 'hprnb-root--pending' );
			body.classList.remove( 'hprnb-pending' );
		} );

		if ( reached() ) {
			reveal();
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
					reveal();
					window.removeEventListener( 'scroll', onScroll );
				}
			} );
		}
		state.on( window, 'scroll', onScroll, { passive: true } );
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
			var hidden = aside.hidden || root.hidden || body.classList.contains( 'hprnb-kbd' ) || root.classList.contains( 'hprnb-root--pending' );
			// In flow the bar is a block of the page: it covers nothing, so it reserves nothing.
			// Collapsed, a phone keeps its strip while the desktop bar slides fully away.
			var inflow = !! profile && profile.place === 'inline';
			var offset = ( hidden || inflow ) ? 0 : ( collapsed ? ( mobile ? peek : 0 ) : full );
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

	function setupClose( state, root, aside, cfg, contract ) {
		var button = aside.querySelector( '.hprnb-bar__btn--close' );
		if ( ! button ) {
			return;
		}
		state.on( button, 'click', function () {
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

		// Always collapsed (the reader opens it on demand), or landing past the threshold: start
		// collapsed, without a slide.
		if ( ( 'immediate' === trigger || ( profile.deep && window.scrollY > after ) ) && ! isShortScreen() ) {
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
		setupReveal( state, root, contract );
		setupClose( state, root, aside, cfg, contract );
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
