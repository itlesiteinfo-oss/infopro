/**
 * Horizon Press News Bar — settings page script (loaded on the plugin page only).
 *
 * - Live visual preview (CSS variables, label, layout) without any network call.
 * - Content preview through POST /hprnb/v1/preview (button + 800 ms debounce).
 * - Term pickers: local filter, select all / deselect all.
 * - WCAG contrast warnings (never block saving).
 * - Reset confirmation.
 * The form is fully usable without this script.
 */
(function () {
	'use strict';

	var cfg = window.hprnbAdmin || {};
	var i18n = cfg.i18n || {};
	var form = document.getElementById( 'hprnb-form' );
	var previewRoot = document.getElementById( 'hprnb-preview-root' );
	var preview = document.getElementById( 'hprnb-preview' );
	var status = document.getElementById( 'hprnb-preview-status' );
	var refreshButton = document.getElementById( 'hprnb-preview-refresh' );
	var replayButton = document.getElementById( 'hprnb-preview-replay' );

	if ( ! form ) {
		return;
	}

	var VISUAL_VARS = {
		bg_color: '--hprnb-bg',
		text_color: '--hprnb-fg',
		label_bg_color: '--hprnb-label-bg',
		label_text_color: '--hprnb-label-fg',
		link_hover_color: '--hprnb-hover',
		accent_color: '--hprnb-accent',
		font_size: '--hprnb-font-size',
		max_width: '--hprnb-max',
		gutter: '--hprnb-gutter',
		z_index: '--hprnb-z',
		// The red bar of the urgent articles (2.14).
		urgent_bg_color: '--hprnb-u-bg',
		urgent_text_color: '--hprnb-u-fg'
	};
	var PX_KEYS = { font_size: true, max_width: true, gutter: true };
	var MOBILE_VARS = { mobile_bg_color: '--hprnb-m-bg', mobile_text_color: '--hprnb-m-fg', mobile_accent_color: '--hprnb-m-accent', mobile_label_text_color: '--hprnb-m-label-fg', mobile_font_size: '--hprnb-m-font-size' };
	var VISUAL_ONLY = { urgent_design: true, urgent_exclude_current: true, bar_font: true, urgent_desktop: true, urgent_mobile: true, urgent_desktop_layout: true, urgent_contexts: true, urgent_font_size: true, urgent_mobile_font_size: true, label_position: true, layout_mode: true, z_index: true, bar_height: true, align_container: true, show_separator: true, separator_char: true, separator_after_last: true, mobile_bar_height: true, mobile_peek: true, mobile_deep_collapse: true, mobile_kbd_hide: true, theme_offset: true, desktop_layout: true, desktop_label_style: true, desktop_label_dot: true, desktop_show_counter: true, desktop_lines: true, desktop_show_progress: true, desktop_thumb_position: true, desktop_thumb_size: true, mobile_thumb_position: true, mobile_thumb_size: true, mobile_controls_layout: true, mobile_controls_place: true, mobile_show_pause: true, mobile_show_close: true, mobile_collapse_mode: true, mobile_collapse_after: true, accent_edge: true, mobile_label_pulse: true, mobile_peek_thumbnail: true, mobile_label_compact: true, mobile_layout: true, mobile_label_style: true, mobile_label_dot: true, mobile_show_counter: true, mobile_lines: true, mobile_font_size: true, mobile_show_progress: true, mobile_swipe: true, mobile_hide_on_scroll: true, mobile_show_separator: true, mobile_card_thumb: true, mobile_card_float: true, desktop_hide_on_scroll: true, desktop_collapse_mode: true, desktop_collapse_after: true, desktop_placement: true, mobile_placement: true, mobile_custom_colors: true, mobile_bg_color: true, mobile_text_color: true, mobile_accent_color: true, mobile_label_text_color: true };
	/* Row height of the stacked label strip, row gap, block padding and title line-height: mirrors Renderer::profile_height(). */
	var STRIP = 22;
	var ROW_GAP = 4;
	var BLOCK_PAD = 12;
	var LINE_HEIGHT = 1.3;
	/* Mobile flow card: Renderer::FLOW_LINE / FLOW_PAD / PEEK_EXTRA. */
	var FLOW_LINE = 1.625;
	var FLOW_PAD = 6;
	var PEEK_EXTRA = 2;
	var CARD_PAD = 12;
	var CARD_RATIO = 0.5625;
	var CARD_FONT_PLUS = 2;
	var CARD_LINE = 1.24;
	var CARD_LABEL = 20;
	var CARD_ROW = 8;
	var CARD_LINES_MAX = 3;
	var CARD_FLOAT = 8;
	var reinitTimer = null;

	/** Restarts the interactive script on the preview root (rotation, progress, marquee…). */
	function reinitPreview() {
		if ( reinitTimer !== null ) {
			clearTimeout( reinitTimer );
		}
		reinitTimer = setTimeout( function () {
			reinitTimer = null;
			if ( previewRoot && window.hprnbBar && typeof window.hprnbBar.destroy === 'function' ) {
				window.hprnbBar.destroy( previewRoot );
				window.hprnbBar.init( previewRoot );
			}
		}, 120 );
	}

	/**
	 * Plays the Breaking News bar of the preview from its opening again (2.18): a fresh copy of the bar
	 * takes its place, so the stylesheet's opening and the typing start over, exactly as on the site.
	 */
	function replayPreview() {
		var aside = previewRoot && previewRoot.querySelector( '.hprnb-bar--urgent' );
		if ( ! aside || ! window.hprnbBar || typeof window.hprnbBar.destroy !== 'function' ) {
			return;
		}
		window.hprnbBar.destroy( previewRoot );
		aside.parentNode.replaceChild( aside.cloneNode( true ), aside );
		window.hprnbBar.init( previewRoot );
	}

	/** Single-quoted CSS string literal, mirroring Renderer::css_string(). */
	function cssString( value ) {
		return "'" + String( value ).replace( /\\/g, '\\\\' ).replace( /'/g, "\\'" ) + "'";
	}

	/* ------------------------------------------------------------------ */
	/* Form → nested settings object                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Parses "hprnb_settings[a][b][]" into ['a', 'b', ''].
	 */
	function pathOf( name ) {
		var match = /^hprnb_settings((?:\[[^\]]*\])+)$/.exec( name );
		if ( ! match ) {
			return null;
		}
		return match[ 1 ].slice( 1, -1 ).split( '][' );
	}

	function assign( target, path, value ) {
		var node = target;
		for ( var i = 0; i < path.length - 1; i++ ) {
			var key = path[ i ];
			if ( typeof node[ key ] !== 'object' || node[ key ] === null ) {
				node[ key ] = {};
			}
			node = node[ key ];
		}
		var last = path[ path.length - 1 ];
		if ( last === '' ) {
			var parentKey = path[ path.length - 2 ];
			var parent = path.length > 1 ? getParent( target, path.slice( 0, -2 ) ) : target;
			if ( ! Array.isArray( parent[ parentKey ] ) ) {
				parent[ parentKey ] = [];
			}
			parent[ parentKey ].push( value );
			return;
		}
		node[ last ] = value;
	}

	function getParent( target, path ) {
		var node = target;
		for ( var i = 0; i < path.length; i++ ) {
			if ( typeof node[ path[ i ] ] !== 'object' || node[ path[ i ] ] === null ) {
				node[ path[ i ] ] = {};
			}
			node = node[ path[ i ] ];
		}
		return node;
	}

	function collect() {
		var settings = {};
		Array.prototype.forEach.call( form.elements, function ( el ) {
			if ( ! el.name ) {
				return;
			}
			var path = pathOf( el.name );
			if ( ! path ) {
				return;
			}
			if ( ( el.type === 'checkbox' || el.type === 'radio' ) && ! el.checked ) {
				return;
			}
			assign( settings, path, el.value );
		} );
		return settings;
	}

	function fieldKey( el ) {
		var path = pathOf( el.name || '' );
		return path ? path[ 0 ] : '';
	}

	/* ------------------------------------------------------------------ */
	/* Visual preview                                                      */
	/* ------------------------------------------------------------------ */

	function valueOf( key ) {
		var el = form.querySelector( '[name="hprnb_settings[' + key + ']"]:not([type="radio"]), [name="hprnb_settings[' + key + ']"][type="radio"]:checked' );
		if ( ! el ) {
			return '';
		}
		if ( el.type === 'checkbox' ) {
			return el.checked ? '1' : '';
		}
		return el.value;
	}

	/** Effective ticker mode of a profile: none|marquee|rotate|manual (mirrors Renderer::desktop_ticker / mobile_ticker). */
	function effectiveMode( p ) {
		var desktop = valueOf( 'ticker_enabled' ) === '1' ? ( valueOf( 'ticker_mode' ) || 'marquee' ) : 'none';
		if ( p !== 'm' ) {
			return desktop;
		}
		var mobile = valueOf( 'mobile_ticker_mode' ) || 'rotate';
		if ( mobile === 'inherit' ) {
			return desktop;
		}
		if ( mobile === 'static' ) {
			return 'none';
		}
		return mobile;
	}

	/** Normalised presentation profile (mirrors Renderer::profile()). */
	function computeProfile( p ) {
		var mobile = ( p === 'm' );
		var prefix = mobile ? 'mobile_' : 'desktop_';
		var mode = effectiveMode( p );
		var layout = valueOf( prefix + 'layout' ) || ( mobile ? 'flow_image' : 'inline' );
		// The flowing bar with the article picture: the flowing layout, its picture at the end and its
		// buttons in a tab above the corner (mirrors Renderer::profile()).
		var imageBar = mobile && layout === 'flow_image';
		if ( imageBar ) {
			layout = 'flow';
		}
		if ( layout !== 'stacked' && layout !== 'inline' && layout !== 'flow' && layout !== 'card' ) {
			layout = mobile ? 'card' : 'inline';
		}
		if ( ! mobile && ( layout === 'flow' || layout === 'card' ) ) {
			layout = 'inline';
		}
		if ( ( layout === 'flow' || layout === 'card' ) && mode !== 'rotate' ) {
			layout = 'stacked';
		}
		var label = valueOf( prefix + 'label_style' ) || ( mobile ? 'pill' : 'strip' );
		var lines = Math.max( 1, Math.min( 4, parseInt( valueOf( prefix + 'lines' ), 10 ) || ( mobile ? 3 : 1 ) ) );
		return {
			layout: layout,
			label: label,
			dot: label !== 'hidden' && valueOf( prefix + 'label_dot' ) === '1',
			counter: mode === 'rotate' && valueOf( prefix + 'show_counter' ) === '1',
			lines: mode === 'marquee' ? 1 : lines,
			progress: mode === 'rotate' && valueOf( prefix + 'show_progress' ) === '1',
			mode: mode,
			fontSize: parseInt( valueOf( mobile ? 'mobile_font_size' : 'font_size' ), 10 ) || ( mobile ? 16 : 14 ),
			thumb: imageBar || valueOf( prefix + 'show_thumbnail' ) === '1',
			thumbAfter: imageBar || valueOf( prefix + 'thumb_position' ) === 'after',
			tab: imageBar,
			thumbSize: parseInt( valueOf( prefix + 'thumb_size' ), 10 ) || ( mobile ? 48 : 32 ),
			collapse: mobile
				? ( layout !== 'inline' && valueOf( 'mobile_hide_on_scroll' ) === '1' )
				: valueOf( 'desktop_hide_on_scroll' ) === '1',
			place: valueOf( prefix + 'placement' ) === 'inline' ? 'inline' : 'fixed',
			swipe: mobile && mode === 'rotate' && valueOf( 'mobile_swipe' ) === '1',
			peek: valueOf( 'mobile_peek' ) === 'label' ? 'label' : 'headline',
			deep: mobile && layout !== 'inline' && valueOf( 'mobile_deep_collapse' ) === '1',
			kbd: mobile && valueOf( 'mobile_kbd_hide' ) === '1'
		};
	}

	/** Mobile flow card metrics (mirrors Renderer::flow_metrics()). */
	function flowMetrics( profile ) {
		var line = Math.round( profile.fontSize * FLOW_LINE );
		var height = Math.max( parseInt( valueOf( 'mobile_bar_height' ), 10 ) || 76, profile.lines * line + 2 * FLOW_PAD );
		var pad = Math.floor( ( height - profile.lines * line ) / 2 );
		return { line: line, height: height, pad: pad, peek: pad + line + PEEK_EXTRA };
	}

	function cardMetrics( profile ) {
		var line = Math.round( ( profile.fontSize + CARD_FONT_PLUS ) * CARD_LINE );
		var thumb = Math.max( 72, Math.min( 160, parseInt( valueOf( 'mobile_card_thumb' ), 10 ) || 132 ) );
		var thumbH = Math.round( thumb * CARD_RATIO );
		var lines = Math.max( 1, Math.min( CARD_LINES_MAX, profile.lines ) );
		var text = lines * line;
		var height = 2 * CARD_PAD + CARD_LABEL + CARD_ROW + Math.max( thumbH, text );
		return { line: line, lines: lines, thumb: thumb, thumbH: thumbH, height: height, pad: CARD_PAD, peek: CARD_PAD + line + PEEK_EXTRA };
	}

	function applyVisual() {
		if ( ! previewRoot ) {
			return;
		}
		Object.keys( VISUAL_VARS ).forEach( function ( key ) {
			var value = valueOf( key );
			if ( value === '' ) {
				return;
			}
			previewRoot.style.setProperty( VISUAL_VARS[ key ], PX_KEYS[ key ] ? parseInt( value, 10 ) + 'px' : value );
		} );

		// Separator: classes + custom property on the root, exactly like the front-end root.
		var showSep = valueOf( 'show_separator' ) === '1';
		previewRoot.classList.toggle( 'hprnb-bar--sep', showSep );
		previewRoot.classList.toggle( 'hprnb-bar--sep-loop', showSep && valueOf( 'separator_after_last' ) === '1' );
		previewRoot.style.setProperty( '--hprnb-sep', cssString( valueOf( 'separator_char' ) || '•' ) );

		// Presentation profiles (desktop / mobile): root classes, custom properties, JSON flags and heights,
		// computed exactly like Renderer::profile() / profile_height().
		Object.keys( MOBILE_VARS ).forEach( function ( key ) {
			var value = valueOf( key );
			if ( value !== '' ) {
				previewRoot.style.setProperty( MOBILE_VARS[ key ], key === 'mobile_font_size' ? parseInt( value, 10 ) + 'px' : value );
			}
		} );
		var labelEnd = valueOf( 'label_position' ) !== 'start';
		var minHeight = parseInt( valueOf( 'bar_height' ), 10 ) || 40;
		previewRoot.classList.toggle( 'hprnb-root--align', valueOf( 'align_container' ) === '1' );
		previewRoot.classList.toggle( 'hprnb-root--peek-label', valueOf( 'mobile_peek' ) === 'label' );
		var cardDesign = computeProfile( 'm' ).layout === 'card';
		var tabDesign = computeProfile( 'm' ).tab;
		var outside = ! cardDesign && ! tabDesign && valueOf( 'mobile_controls_place' ) === 'outside';
		var stacked = ! cardDesign && ! tabDesign && valueOf( 'mobile_controls_layout' ) !== 'row';
		previewRoot.classList.toggle( 'hprnb-root--edge', valueOf( 'accent_edge' ) === '1' );
		// 2.16: both bars in the news face unless the site keeps its own.
		previewRoot.classList.toggle( 'hprnb-root--font-news', valueOf( 'bar_font' ) !== 'theme' );
		// 2.16: the URGENT bar switched off on one device (the feature itself being on).
		var uOn = valueOf( 'urgent_enabled' ) === '1';
		var uD = uOn && valueOf( 'urgent_desktop' ) === '1';
		var uM = uOn && valueOf( 'urgent_mobile' ) === '1';
		previewRoot.classList.toggle( 'hprnb-root--u-no-d', uM && ! uD );
		previewRoot.classList.toggle( 'hprnb-root--u-no-m', uD && ! uM );
		// 2.17: the URGENT bar's design (Breaking News unless the chyron is chosen), like Renderer::root_classes().
		var breaking = valueOf( 'urgent_design' ) !== 'chyron';
		var chosen = breaking && ! previewRoot.classList.contains( 'hprnb-root--u-bn' );
		previewRoot.classList.toggle( 'hprnb-root--u-bn', breaking );
		// Breaking News just chosen: its opening and its typing, from the start (2.18).
		if ( chosen ) {
			replayPreview();
		}
		if ( replayButton ) {
			replayButton.hidden = ! ( breaking && previewRoot.classList.contains( 'hprnb-root--urgent' ) && previewRoot.querySelector( '.hprnb-bar--urgent' ) );
		}
		// 2.15: the chyron in its phone design from 768px.
		previewRoot.classList.toggle( 'hprnb-root--u-d-flow', ! breaking && valueOf( 'urgent_desktop_layout' ) === 'mobile' );
		// 2.16: its sizes and heights, exactly like Renderer::urgent_font() / urgent_metrics() / urgent_height().
		var clampInt = function ( key, min, max, fallback ) {
			return Math.max( min, Math.min( max, parseInt( valueOf( key ), 10 ) || fallback ) );
		};
		var uFsD = clampInt( 'urgent_font_size', 14, 22, 17 );
		var uFsM = clampInt( 'urgent_mobile_font_size', 14, 20, 17 );
		var uLines = clampInt( 'mobile_lines', 1, 2, 2 );
		var uLine = Math.round( uFsM * 1.4 );
		var uHm = Math.max( parseInt( valueOf( 'mobile_bar_height' ), 10 ) || 76, uLines * uLine + 24 );
		var uHd = ( ! breaking && valueOf( 'urgent_desktop_layout' ) === 'mobile' ) ? uHm : Math.max( 48, minHeight, Math.ceil( uFsD * LINE_HEIGHT ) + 24 );
		[ [ '--hprnb-u-fs', uFsD + 'px' ], [ '--hprnb-u-m-fs', uFsM + 'px' ], [ '--hprnb-u-line', uLine + 'px' ], [ '--hprnb-u-lines', String( uLines ) ], [ '--hprnb-u-pad', Math.floor( ( uHm - uLines * uLine ) / 2 ) + 'px' ], [ '--hprnb-u-height', uHd + 'px' ], [ '--hprnb-u-m-height', uHm + 'px' ] ].forEach( function ( pair ) {
			previewRoot.style.setProperty( pair[ 0 ], pair[ 1 ] );
		} );
		previewRoot.classList.toggle( 'hprnb-root--m-ctrl-out', outside );
		previewRoot.classList.toggle( 'hprnb-root--m-ctrl-tab', tabDesign );
		var shownThumb = valueOf( 'mobile_show_thumbnail' ) === '1';
		previewRoot.classList.toggle( 'hprnb-root--m-ctrl-col', stacked && ! outside );
		previewRoot.classList.toggle( 'hprnb-root--m-peek-thumb', ( shownThumb || tabDesign || cardDesign ) && valueOf( 'mobile_peek_thumbnail' ) === '1' );
		previewRoot.classList.toggle( 'hprnb-root--m-label-compact', shownThumb && valueOf( 'mobile_label_compact' ) === '1' );
		[ 'always', 'appear', 'collapsed', 'never' ].forEach( function ( mode ) {
			previewRoot.classList.toggle( 'hprnb-root--m-pulse-' + mode, ( valueOf( 'mobile_label_pulse' ) || 'appear' ) === mode );
		} );
		[ 'd', 'm' ].forEach( function ( p ) {
			var profile = computeProfile( p );
			var cls = previewRoot.classList;
			cls.toggle( 'hprnb-root--' + p + '-inline', profile.layout === 'inline' );
			cls.toggle( 'hprnb-root--' + p + '-stacked', profile.layout === 'stacked' );
			cls.toggle( 'hprnb-root--' + p + '-flow', profile.layout === 'flow' );
			cls.toggle( 'hprnb-root--' + p + '-card', profile.layout === 'card' );
			if ( p === 'm' ) {
				cls.toggle( 'hprnb-root--m-float', profile.layout === 'card' && valueOf( 'mobile_card_float' ) === '1' );
			}
			cls.toggle( 'hprnb-root--' + p + '-inflow', profile.place === 'inline' );
			cls.toggle( 'hprnb-root--' + p + '-end', p === 'd' && profile.layout === 'inline' && labelEnd );
			[ 'pill', 'strip', 'hidden' ].forEach( function ( style ) {
				cls.toggle( 'hprnb-root--' + p + '-label-' + style, profile.label === style );
			} );
			cls.toggle( 'hprnb-root--' + p + '-dot', profile.dot );
			cls.toggle( 'hprnb-root--' + p + '-wrap', profile.lines > 1 && profile.layout !== 'flow' && profile.layout !== 'card' );
			cls.toggle( 'hprnb-root--' + p + '-thumb', profile.thumb );
			cls.toggle( 'hprnb-root--' + p + '-thumb-after', profile.thumb && profile.thumbAfter );
			previewRoot.style.setProperty( p === 'm' ? '--hprnb-m-thumb' : '--hprnb-d-thumb', profile.thumbSize + 'px' );
			var height;
			if ( profile.layout === 'flow' ) {
				var flow = flowMetrics( profile );
				height = flow.height;
				previewRoot.style.setProperty( '--hprnb-m-line', flow.line + 'px' );
				previewRoot.style.setProperty( '--hprnb-m-pad', flow.pad + 'px' );
				previewRoot.style.setProperty( '--hprnb-peek', flow.peek + 'px' );
			} else if ( profile.layout === 'card' ) {
				var card = cardMetrics( profile );
				height = card.height;
				previewRoot.style.setProperty( '--hprnb-m-line', card.line + 'px' );
				previewRoot.style.setProperty( '--hprnb-m-pad', card.pad + 'px' );
				previewRoot.style.setProperty( '--hprnb-peek', card.peek + 'px' );
				previewRoot.style.setProperty( '--hprnb-m-card-thumb', card.thumb + 'px' );
				previewRoot.style.setProperty( '--hprnb-m-card-thumb-h', card.thumbH + 'px' );
				previewRoot.style.setProperty( '--hprnb-m-card-lines', String( card.lines ) );
				previewRoot.style.setProperty( '--hprnb-m-gap', ( valueOf( 'mobile_card_float' ) === '1' ? CARD_FLOAT : 0 ) + 'px' );
			} else {
				height = profile.lines * Math.ceil( profile.fontSize * LINE_HEIGHT ) + BLOCK_PAD + ( profile.layout === 'stacked' ? STRIP + ROW_GAP : 0 );
				if ( profile.thumb ) {
					height = Math.max( height, profile.thumbSize + BLOCK_PAD - 4 );
				}
				height = Math.max( minHeight, height );
				if ( p === 'm' ) {
					previewRoot.style.setProperty( '--hprnb-peek', ( profile.layout === 'stacked' ? 36 : height ) + 'px' );
				}
			}
			if ( p === 'm' ) {
				var wantsPause = valueOf( 'mobile_show_pause' ) === '1' && ( profile.mode === 'marquee' || profile.mode === 'rotate' );
				var ctrls = ( valueOf( 'close_button' ) === '1' && valueOf( 'mobile_show_close' ) === '1' ) ? 1 : 0;
				ctrls += wantsPause ? 1 : ( profile.mode === 'manual' ? 2 : 0 );
				previewRoot.style.setProperty( '--hprnb-m-ctrls', String( outside || cardDesign || tabDesign || ! ctrls ? 0 : ( stacked ? 1 : ctrls ) ) );
			}
			previewRoot.style.setProperty( p === 'm' ? '--hprnb-m-height' : '--hprnb-height', height + 'px' );
			previewRoot.style.setProperty( p === 'm' ? '--hprnb-m-lines' : '--hprnb-d-lines', String( profile.lines ) );
			var data = { layout: profile.layout, lines: profile.lines, counter: profile.counter, progress: profile.progress, place: profile.place };
			if ( p === 'd' ) {
				data.collapse = profile.collapse;
				data.trigger = valueOf( 'desktop_collapse_mode' ) || 'scroll';
				data.after = parseInt( valueOf( 'desktop_collapse_after' ), 10 ) || 0;
			}
			if ( p === 'm' ) {
				data.swipe = profile.swipe;
				data.collapse = profile.collapse;
				data.peek = profile.peek;
				data.deep = profile.deep;
				data.kbd = profile.kbd;
				data.pause = valueOf( 'mobile_show_pause' ) === '1';
				data.close = valueOf( 'mobile_show_close' ) === '1';
				data.trigger = valueOf( 'mobile_collapse_mode' ) || 'scroll';
				data.after = parseInt( valueOf( 'mobile_collapse_after' ), 10 ) || 0;
			}
			previewRoot.setAttribute( p === 'm' ? 'data-hprnb-mobile' : 'data-hprnb-desktop', JSON.stringify( data ) );
			Array.prototype.forEach.call( document.querySelectorAll( '.hprnb-height-hint[data-hprnb-height="' + p + '"]' ), function ( hint ) {
				hint.textContent = ( hint.getAttribute( 'data-hprnb-height-format' ) || '%d' ).replace( '%d', String( height ) );
			} );
		} );
		var mobileSep = valueOf( 'mobile_show_separator' ) === '1';
		previewRoot.classList.toggle( 'hprnb-root--m-sep', mobileSep );
		previewRoot.classList.toggle( 'hprnb-root--m-sep-loop', mobileSep && valueOf( 'separator_after_last' ) === '1' );
		previewRoot.classList.toggle( 'hprnb-root--m-colors', valueOf( 'mobile_custom_colors' ) === '1' );
		previewRoot.classList.toggle( 'hprnb-root--m-collapse', computeProfile( 'm' ).collapse );
		previewRoot.classList.toggle( 'hprnb-root--d-collapse', computeProfile( 'd' ).collapse );
		reinitPreview();

		var aside = previewRoot.querySelector( '.hprnb-bar' );
		if ( ! aside ) {
			return;
		}
		var position = valueOf( 'label_position' ) === 'start' ? 'start' : 'end';
		aside.classList.toggle( 'hprnb-bar--label-start', position === 'start' );
		aside.classList.toggle( 'hprnb-bar--label-end', position === 'end' );

		var layout = valueOf( 'layout_mode' ) === 'overlay' ? 'overlay' : 'reserve';
		aside.classList.toggle( 'hprnb-bar--overlay', layout === 'overlay' );
		aside.classList.toggle( 'hprnb-bar--reserve', layout === 'reserve' );

		var labelText = aside.querySelector( '.hprnb-bar__label-text' );
		if ( labelText ) {
			// The urgent bar carries its own label (2.14).
			labelText.textContent = valueOf( aside.classList.contains( 'hprnb-bar--urgent' ) ? 'urgent_label' : 'label_text' );
		}

	}

	/* ------------------------------------------------------------------ */
	/* Dependent fields (greyed out, never disabled: their value is kept)  */
	/* ------------------------------------------------------------------ */

	/**
	 * "key" follows a checkbox; "key:value" (or "key:a|b") follows the chosen option of a radio or a
	 * select; several conditions separated by commas are alternatives ("a,b:c" = a, or b set to c).
	 */
	function isActive( dependency ) {
		if ( dependency.indexOf( ',' ) !== -1 ) {
			return dependency.split( ',' ).some( isActive );
		}
		var spec = dependency.split( ':' );
		var master = form.querySelector( '[name="hprnb_settings[' + spec[ 0 ] + ']"]' );
		return spec.length > 1
			? spec[ 1 ].split( '|' ).indexOf( valueOf( spec[ 0 ] ) ) !== -1
			: ( ! master || master.type !== 'checkbox' || master.checked );
	}

	function updateDependencies() {
		// A whole card can belong to one choice (the "Custom" behaviour): out of that choice it is
		// hidden, not greyed out, so the tab only shows what the admin has to decide.
		Array.prototype.forEach.call( form.querySelectorAll( '[data-hprnb-card-depends]' ), function ( card ) {
			card.hidden = ! isActive( card.getAttribute( 'data-hprnb-card-depends' ) );
		} );
		// A sub-choice of a bar switch (2.16) is hidden while the switch is off: it plays no part then.
		Array.prototype.forEach.call( form.querySelectorAll( 'tr[data-hprnb-reveal]' ), function ( row ) {
			row.hidden = ! isActive( row.getAttribute( 'data-hprnb-reveal' ) );
		} );
		Array.prototype.forEach.call( form.querySelectorAll( 'tr[data-hprnb-depends]' ), function ( row ) {
			var active = isActive( row.getAttribute( 'data-hprnb-depends' ) );
			row.classList.toggle( 'hprnb-row--inactive', ! active );
			Array.prototype.forEach.call( row.querySelectorAll( 'input, select' ), function ( input ) {
				input.setAttribute( 'aria-disabled', active ? 'false' : 'true' );
			} );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Content preview (REST)                                              */
	/* ------------------------------------------------------------------ */

	var pending = null;
	var inFlight = null;

	function setStatus( text ) {
		if ( status ) {
			status.textContent = text || '';
		}
	}

	function renderPreview( data ) {
		if ( ! previewRoot ) {
			return;
		}
		if ( window.hprnbBar && typeof window.hprnbBar.destroy === 'function' ) {
			window.hprnbBar.destroy( previewRoot );
		}
		if ( ! data || typeof data.html !== 'string' || data.count === 0 || data.html === '' ) {
			var empty = document.createElement( 'p' );
			empty.className = 'hprnb-preview__empty';
			empty.id = 'hprnb-preview-empty';
			empty.textContent = cfg.emptyMessage || '';
			previewRoot.innerHTML = '';
			previewRoot.appendChild( empty );
		} else {
			previewRoot.innerHTML = data.html;
		}
		// The root variables derived on the server (heights, URGENT sizes) follow the form too (2.16).
		if ( data && typeof data.style === 'string' && data.style !== '' ) {
			previewRoot.setAttribute( 'style', data.style );
		}
		// The Urgent tab previews the red bar (2.14): the root says so, as on the site.
		previewRoot.classList.toggle( 'hprnb-root--urgent', !! ( data && data.urgent ) );
		applyVisual();
	}

	/** Whether the preview should show the red bar of the urgent articles: while its tab is open. */
	function wantsUrgentPreview() {
		return currentTab === 'urgent';
	}

	function fetchPreview() {
		if ( ! cfg.restUrl || typeof window.fetch !== 'function' ) {
			return;
		}
		if ( inFlight ) {
			inFlight.abort();
		}
		var controller = typeof window.AbortController === 'function' ? new window.AbortController() : null;
		inFlight = controller;
		setStatus( i18n.previewLoading || '' );
		if ( preview ) {
			preview.classList.add( 'hprnb-preview--loading' );
		}

		window.fetch( cfg.restUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
			body: JSON.stringify( { settings: collect(), urgent: wantsUrgentPreview() } ),
			signal: controller ? controller.signal : undefined
		} ).then( function ( res ) {
			if ( ! res.ok ) {
				throw new Error( 'HTTP ' + res.status );
			}
			return res.json();
		} ).then( function ( data ) {
			renderPreview( data );
			setStatus( i18n.previewUpdated || '' );
		} ).catch( function ( err ) {
			if ( err && err.name === 'AbortError' ) {
				return;
			}
			setStatus( i18n.previewError || '' );
		} ).then( function () {
			if ( inFlight === controller ) {
				inFlight = null;
			}
			if ( preview ) {
				preview.classList.remove( 'hprnb-preview--loading' );
			}
		} );
	}

	function schedulePreview() {
		if ( pending !== null ) {
			clearTimeout( pending );
		}
		pending = setTimeout( function () {
			pending = null;
			fetchPreview();
		}, 800 );
	}

	if ( replayButton ) {
		replayButton.addEventListener( 'click', replayPreview );
	}
	if ( refreshButton ) {
		refreshButton.addEventListener( 'click', function () {
			if ( pending !== null ) {
				clearTimeout( pending );
				pending = null;
			}
			fetchPreview();
		} );
	}

	form.addEventListener( 'input', onFieldChange );
	form.addEventListener( 'change', onFieldChange );

	function onFieldChange( event ) {
		var el = event.target;
		if ( ! el || ! el.name ) {
			return;
		}
		var key = fieldKey( el );
		if ( ! key ) {
			return;
		}
		// Only the radio that ends up ticked: "Reset this tab" sends a change event to every one of them.
		if ( ( key === 'mobile_behavior' || key === 'desktop_behavior' ) && event.type === 'change' && el.checked ) {
			applyBehavior( key.replace( 'behavior', '' ), el.value );
		}
		applyVisual();
		updateContrast();
		updateDependencies();
		if ( key === 'window_unit' ) {
			updateWindowBounds();
		}
		if ( VISUAL_VARS[ key ] || VISUAL_ONLY[ key ] ) {
			return;
		}
		if ( ( key === 'label_text' || key === 'urgent_label' ) && previewRoot && previewRoot.querySelector( '.hprnb-bar__label-text' ) ) {
			return;
		}
		schedulePreview();
	}

	/* ------------------------------------------------------------------ */
	/* Window bounds follow the unit                                       */
	/* ------------------------------------------------------------------ */

	function updateWindowBounds() {
		var unit = form.querySelector( '[name="hprnb_settings[window_unit]"]' );
		var value = form.querySelector( '[name="hprnb_settings[window_value]"]' );
		if ( ! unit || ! value ) {
			return;
		}
		var option = unit.options[ unit.selectedIndex ];
		if ( ! option ) {
			return;
		}
		var min = parseInt( option.getAttribute( 'data-hprnb-min' ), 10 ) || 1;
		var max = parseInt( option.getAttribute( 'data-hprnb-max' ), 10 ) || 1440;
		value.min = String( min );
		value.max = String( max );
		var current = parseInt( value.value, 10 ) || min;
		value.value = String( Math.min( max, Math.max( min, current ) ) );
	}

	/* ------------------------------------------------------------------ */
	/* Contrast                                                            */
	/* ------------------------------------------------------------------ */

	function luminance( hex ) {
		hex = String( hex || '' ).replace( '#', '' );
		if ( hex.length === 3 ) {
			hex = hex[ 0 ] + hex[ 0 ] + hex[ 1 ] + hex[ 1 ] + hex[ 2 ] + hex[ 2 ];
		}
		if ( ! /^[0-9a-f]{6}$/i.test( hex ) ) {
			return 0;
		}
		var channels = [ 0, 2, 4 ].map( function ( offset ) {
			var v = parseInt( hex.substr( offset, 2 ), 16 ) / 255;
			return v <= 0.03928 ? v / 12.92 : Math.pow( ( v + 0.055 ) / 1.055, 2.4 );
		} );
		return 0.2126 * channels[ 0 ] + 0.7152 * channels[ 1 ] + 0.0722 * channels[ 2 ];
	}

	function contrast( a, b ) {
		var l1 = luminance( a );
		var l2 = luminance( b );
		var hi = Math.max( l1, l2 );
		var lo = Math.min( l1, l2 );
		return ( hi + 0.05 ) / ( lo + 0.05 );
	}

	function updateContrast() {
		var pairs = { text: [ 'text_color', 'bg_color' ], label: [ 'label_text_color', 'label_bg_color' ], mobile: [ 'mobile_text_color', 'mobile_bg_color' ], urgent: [ 'urgent_text_color', 'urgent_bg_color' ] };
		Object.keys( pairs ).forEach( function ( id ) {
			var warning = document.getElementById( 'hprnb-contrast-' + id );
			if ( ! warning ) {
				return;
			}
			var ratio = contrast( valueOf( pairs[ id ][ 0 ] ), valueOf( pairs[ id ][ 1 ] ) );
			if ( ratio < 4.5 ) {
				warning.textContent = ( i18n.contrastLow || '%s' ).replace( '%s', ratio.toFixed( 1 ) + ':1' );
				warning.hidden = false;
			} else {
				warning.hidden = true;
			}
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Term pickers                                                        */
	/* ------------------------------------------------------------------ */

	Array.prototype.forEach.call( document.querySelectorAll( '.hprnb-terms' ), function ( box ) {
		var filter = box.querySelector( '.hprnb-terms__filter' );
		var items = box.querySelectorAll( '.hprnb-terms__list li' );
		var all = box.querySelector( '.hprnb-terms__all' );
		var none = box.querySelector( '.hprnb-terms__none' );

		function visibleBoxes() {
			return Array.prototype.filter.call( items, function ( li ) {
				return ! li.hidden;
			} ).map( function ( li ) {
				return li.querySelector( 'input[type="checkbox"]' );
			} );
		}

		function setAll( checked ) {
			visibleBoxes().forEach( function ( input ) {
				if ( input ) {
					input.checked = checked;
				}
			} );
			schedulePreview();
		}

		if ( filter ) {
			filter.addEventListener( 'input', function () {
				var needle = filter.value.trim().toLowerCase();
				Array.prototype.forEach.call( items, function ( li ) {
					var name = li.querySelector( '.hprnb-terms__name' );
					var text = name ? name.textContent.toLowerCase() : li.textContent.toLowerCase();
					li.hidden = needle !== '' && text.indexOf( needle ) === -1;
				} );
			} );
		}
		if ( all ) {
			all.addEventListener( 'click', function () {
				setAll( true );
			} );
		}
		if ( none ) {
			none.addEventListener( 'click', function () {
				setAll( false );
			} );
		}
	} );

	/* ------------------------------------------------------------------ */
	/* Preview device tabs (desktop = flat root, mobile = 375px frame)     */
	/* ------------------------------------------------------------------ */

	var stage = document.getElementById( 'hprnb-preview-stage' );
	var tabs = Array.prototype.slice.call( document.querySelectorAll( '.hprnb-preview__tab[data-hprnb-device]' ) );

	function selectDevice( device ) {
		if ( ! stage || ! previewRoot ) {
			return;
		}
		stage.setAttribute( 'data-hprnb-device', device );
		previewRoot.classList.toggle( 'hprnb-root--flat', device !== 'mobile' );
		tabs.forEach( function ( tab ) {
			var active = tab.getAttribute( 'data-hprnb-device' ) === device;
			tab.classList.toggle( 'is-active', active );
			tab.setAttribute( 'aria-selected', active ? 'true' : 'false' );
		} );
		reinitPreview();
	}

	tabs.forEach( function ( tab ) {
		tab.addEventListener( 'click', function () {
			selectDevice( tab.getAttribute( 'data-hprnb-device' ) );
		} );
	} );

	/* ------------------------------------------------------------------ */
	/* Tabs (progressive: every panel is visible without this script)     */
	/* ------------------------------------------------------------------ */

	var tabBar = document.querySelector( '.hprnb-tabs' );
	/* ------------------------------------------------------------------ */
	/* Simple / advanced: the essential settings by default, every one of  */
	/* them with the switch on (remembered in this browser). Hidden inputs */
	/* stay in the form, so saving never loses a value.                    */
	/* ------------------------------------------------------------------ */

	var wrap = document.querySelector( '.hprnb-wrap' );
	var advancedToggle = document.getElementById( 'hprnb-advanced-toggle' );
	var advancedOn = false;
	try {
		advancedOn = window.localStorage.getItem( 'hprnb_admin_advanced' ) === '1';
	} catch ( e ) {
		advancedOn = false;
	}

	function applyAdvanced() {
		if ( wrap ) {
			wrap.classList.toggle( 'hprnb-wrap--simple', ! advancedOn );
		}
		if ( advancedToggle ) {
			advancedToggle.checked = advancedOn;
		}
	}

	/** A tab button the current mode shows. */
	function usable( button ) {
		return advancedOn || button.getAttribute( 'data-hprnb-advanced' ) !== '1';
	}

	var tabButtons = tabBar ? Array.prototype.slice.call( tabBar.querySelectorAll( '[data-hprnb-tab]' ) ) : [];
	var panels = Array.prototype.slice.call( document.querySelectorAll( '[data-hprnb-panel]' ) );
	var resetTabButton = document.getElementById( 'hprnb-reset-tab' );
	var currentTab = '';

	function selectTab( key, focus ) {
		if ( ! tabButtons.length ) {
			return;
		}
		var visible = tabButtons.filter( usable );
		var found = visible.some( function ( b ) {
			return b.getAttribute( 'data-hprnb-tab' ) === key;
		} );
		if ( ! found ) {
			key = ( visible[ 0 ] || tabButtons[ 0 ] ).getAttribute( 'data-hprnb-tab' );
		}
		var wasUrgent = wantsUrgentPreview();
		currentTab = key;
		if ( previewRoot && wasUrgent !== wantsUrgentPreview() && ( previewRoot.classList.contains( 'hprnb-root--urgent' ) !== wantsUrgentPreview() ) ) {
			fetchPreview();
		}
		tabButtons.forEach( function ( b ) {
			var active = b.getAttribute( 'data-hprnb-tab' ) === key;
			b.classList.toggle( 'is-active', active );
			b.setAttribute( 'aria-selected', active ? 'true' : 'false' );
			b.tabIndex = active ? 0 : -1;
			if ( active && focus ) {
				b.focus();
			}
		} );
		panels.forEach( function ( panel ) {
			panel.hidden = panel.getAttribute( 'data-hprnb-panel' ) !== key;
		} );
		try {
			window.localStorage.setItem( 'hprnb_admin_tab', key );
		} catch ( e ) {
			// Storage unavailable: the tab is simply not remembered.
		}
		if ( window.history && window.history.replaceState ) {
			window.history.replaceState( null, '', '#' + key );
		}
	}

	if ( tabButtons.length ) {
		tabBar.hidden = false;
		if ( resetTabButton ) {
			resetTabButton.hidden = false;
		}
		tabButtons.forEach( function ( b ) {
			b.addEventListener( 'click', function () {
				selectTab( b.getAttribute( 'data-hprnb-tab' ), false );
			} );
			b.addEventListener( 'keydown', function ( event ) {
				var delta = event.key === 'ArrowRight' ? 1 : ( event.key === 'ArrowLeft' ? -1 : 0 );
				if ( ! delta ) {
					return;
				}
				event.preventDefault();
				var visible = tabButtons.filter( usable );
				var index = visible.indexOf( b );
				var next = visible[ ( index + delta + visible.length ) % visible.length ];
				selectTab( next.getAttribute( 'data-hprnb-tab' ), true );
			} );
		} );
		var initial = ( window.location.hash || '' ).replace( '#', '' );
		if ( ! initial ) {
			try {
				initial = window.localStorage.getItem( 'hprnb_admin_tab' ) || '';
			} catch ( e ) {
				initial = '';
			}
		}
		// A settings error mentioning a field opens its tab.
		applyAdvanced();
		selectTab( initial, false );
	}

	if ( advancedToggle ) {
		advancedToggle.parentNode.hidden = false;
		advancedToggle.addEventListener( 'change', function () {
			advancedOn = advancedToggle.checked;
			try {
				window.localStorage.setItem( 'hprnb_admin_advanced', advancedOn ? '1' : '0' );
			} catch ( e ) {
				// Storage unavailable: the choice holds until the page is left.
			}
			applyAdvanced();
			if ( currentTab ) {
				selectTab( currentTab, false ); // An advanced tab gives way to the first one.
			}
		} );
	}
	applyAdvanced();

	/* ------------------------------------------------------------------ */
	/* "Reset this tab" and colour presets (nothing is saved)              */
	/* ------------------------------------------------------------------ */

	function setFieldValue( el, value ) {
		if ( el.type === 'checkbox' ) {
			el.checked = !! value;
		} else if ( el.type === 'radio' ) {
			el.checked = String( el.value ) === String( value );
		} else {
			el.value = value === null || typeof value === 'undefined' ? '' : ( Array.isArray( value ) ? value.join( ', ' ) : String( value ) );
		}
		el.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	}

	function resetPanel( panel ) {
		var defaults = cfg.defaults || {};
		Array.prototype.forEach.call( panel.querySelectorAll( '[name^="hprnb_settings["]' ), function ( el ) {
			var path = pathOf( el.name );
			if ( ! path ) {
				return;
			}
			var key = path[ 0 ];
			if ( ! Object.prototype.hasOwnProperty.call( defaults, key ) ) {
				return;
			}
			var value = defaults[ key ];
			if ( path.length > 1 && path[ 1 ] !== '' ) {
				value = value && typeof value === 'object' ? value[ path[ 1 ] ] : undefined;
			} else if ( path.length > 1 && path[ 1 ] === '' ) {
				value = Array.isArray( value ) && value.map( String ).indexOf( String( el.value ) ) !== -1;
			}
			setFieldValue( el, value );
		} );
		// The virtual "window" field maps to window_value / window_unit (already covered by their names).
	}

	/**
	 * Writes the detailed values a behaviour stands for into the (hidden) detailed fields, exactly as
	 * saving would: switching to "Custom" afterwards starts from what the last choice was doing.
	 *
	 * @param {string} prefix 'mobile_' or 'desktop_'.
	 * @param {string} name   Behaviour name.
	 */
	function applyBehavior( prefix, name ) {
		var preset = ( cfg.behaviors || {} )[ name ];
		if ( ! preset ) {
			return; // "Custom" changes nothing.
		}
		Object.keys( preset ).forEach( function ( key ) {
			Array.prototype.forEach.call( form.querySelectorAll( '[name="hprnb_settings[' + prefix + key + ']"]' ), function ( el ) {
				if ( el.type === 'hidden' ) {
					return;
				}
				setFieldValue( el, preset[ key ] );
			} );
		} );
	}

	if ( resetTabButton ) {
		resetTabButton.addEventListener( 'click', function () {
			var panel = document.querySelector( '[data-hprnb-panel="' + currentTab + '"]' );
			if ( ! panel || ! window.confirm( i18n.resetTab || '?' ) ) {
				return;
			}
			resetPanel( panel );
		} );
	}

	Array.prototype.forEach.call( document.querySelectorAll( '.hprnb-preset[data-hprnb-preset]' ), function ( button ) {
		button.addEventListener( 'click', function () {
			var preset = ( cfg.presets || {} )[ button.getAttribute( 'data-hprnb-preset' ) ];
			if ( ! preset ) {
				return;
			}
			Object.keys( preset ).forEach( function ( key ) {
				var el = form.querySelector( '[name="hprnb_settings[' + key + ']"]' );
				if ( el ) {
					setFieldValue( el, preset[ key ] );
				}
			} );
		} );
	} );

	/* ------------------------------------------------------------------ */
	/* Reset confirmation                                                  */
	/* ------------------------------------------------------------------ */

	var resetForm = document.getElementById( 'hprnb-reset-form' );
	if ( resetForm ) {
		resetForm.addEventListener( 'submit', function ( event ) {
			var box = document.getElementById( 'hprnb-reset-confirm' );
			if ( ( box && ! box.checked ) || ! window.confirm( i18n.confirmReset || '?' ) ) {
				event.preventDefault();
			}
		} );
	}

	updateWindowBounds();
	updateContrast();
	updateDependencies();
	applyVisual();
})();
