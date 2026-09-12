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

	if ( ! form ) {
		return;
	}

	var VISUAL_VARS = {
		bg_color: '--hprnb-bg',
		text_color: '--hprnb-fg',
		label_bg_color: '--hprnb-label-bg',
		label_text_color: '--hprnb-label-fg',
		link_hover_color: '--hprnb-hover',
		font_size: '--hprnb-font-size',
		bar_height: '--hprnb-height',
		z_index: '--hprnb-z'
	};
	var PX_KEYS = { font_size: true, bar_height: true };
	var VISUAL_ONLY = { label_position: true, layout_mode: true, z_index: true };

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
			labelText.textContent = valueOf( 'label_text' );
		}

		var showSep = valueOf( 'show_separator' ) === '1';
		Array.prototype.forEach.call( aside.querySelectorAll( '.hprnb-bar__sep' ), function ( sep ) {
			sep.hidden = ! showSep;
			if ( showSep ) {
				sep.textContent = valueOf( 'separator_char' ) || sep.textContent;
			}
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
		applyVisual();
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
			body: JSON.stringify( { settings: collect() } ),
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
		applyVisual();
		updateContrast();
		if ( key === 'window_unit' ) {
			updateWindowBounds();
		}
		if ( VISUAL_VARS[ key ] || VISUAL_ONLY[ key ] ) {
			return;
		}
		if ( key === 'label_text' && previewRoot && previewRoot.querySelector( '.hprnb-bar__label-text' ) ) {
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
		var pairs = { text: [ 'text_color', 'bg_color' ], label: [ 'label_text_color', 'label_bg_color' ] };
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
	applyVisual();
})();
