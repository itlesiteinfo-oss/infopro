import { test, expect } from '@playwright/test';
import { setSettings, wp, collectErrors, countRequests, noHorizontalOverflow, routeStaleDocument, OLD } from './helpers.mjs';


const postIds = [];

test.beforeAll( () => {
	setSettings();
	for ( let i = 1; i <= 5; i++ ) {
		postIds.push( wp( [ 'post', 'create', '--post_type=post', '--post_status=publish', `--post_title=E2E headline ${ i } — lorem ipsum dolor sit amet consectetur`, '--porcelain' ] ) );
	}
} );

test.afterAll( () => {
	postIds.forEach( ( id ) => wp( [ 'post', 'delete', id, '--force' ] ) );
	setSettings();
} );

test( 'desktop: bar is fixed at the bottom, reserves space, no overflow, no console error', async ( { page } ) => {
	setSettings();
	const errors = collectErrors( page );
	await page.setViewportSize( { width: 1366, height: 800 } );
	await page.goto( '/' );

	const bar = page.locator( '#hprnb-root .hprnb-bar' );
	await expect( bar ).toBeVisible();
	await expect( bar ).toHaveAttribute( 'role', 'region' );
	await expect( bar ).toHaveAttribute( 'aria-live', 'off' );
	await expect( bar ).toHaveAttribute( 'dir', 'auto' );
	expect( await bar.evaluate( ( el ) => getComputedStyle( el ).position ) ).toBe( 'fixed' );
	expect( await bar.evaluate( ( el ) => getComputedStyle( el ).backgroundColor ) ).toBe( 'rgb(176, 0, 0)' );
	const box = await bar.boundingBox();
	expect( Math.round( box.y + box.height ) ).toBe( 800 );
	expect( Math.round( box.height ) ).toBe( 44 );

	await expect( page.locator( 'body' ) ).toHaveClass( /hprnb-reserve/ );
	expect( await page.evaluate( () => parseFloat( getComputedStyle( document.body ).paddingBottom ) ) ).toBeGreaterThanOrEqual( 44 );
	expect( await page.locator( '.hprnb-bar__item' ).count() ).toBeGreaterThanOrEqual( 5 );
	expect( await page.locator( '.hprnb-bar__item' ).count() ).toBeLessThanOrEqual( 10 );
	await expect( page.locator( '.hprnb-bar__label-text' ) ).toHaveText( 'TOUTE L’ACTUALITÉ' );
	expect( await page.locator( 'script#hprnb-bootstrap-js' ).count() ).toBe( 1 );
	expect( await page.locator( 'script#hprnb-bar-js' ).count() ).toBe( 1 ); // mobile rotate needs it; harmless on desktop
	await expect( page.locator( '.hprnb-bar' ) ).toHaveAttribute( 'data-hprnb-init', '1' );
	expect( await page.locator( '.hprnb-bar button:not([hidden])' ).count() ).toBe( 0 );
	expect( await noHorizontalOverflow( page ) ).toBe( true );
	expect( errors ).toEqual( [] );
} );

test( 'fresh SSR: no REST request; stale SSR: exactly one', async ( { page } ) => {
	setSettings();
	const hits = countRequests( page, /hprnb\/v1\/items/ );
	await page.goto( '/' );
	await page.waitForTimeout( 800 );
	expect( hits ).toHaveLength( 0 );
	expect( await page.evaluate( () => { try { return JSON.parse( sessionStorage.getItem( 'hprnb_payload' ) ).count; } catch ( e ) { return null; } } ) ).toBe( 1 );

	await page.evaluate( () => sessionStorage.clear() );
	await routeStaleDocument( page );
	await page.goto( '/' );
	await expect.poll( () => hits.length ).toBe( 1 );
	await expect( page.locator( '#hprnb-root' ) ).not.toHaveAttribute( 'data-hprnb-generated', String( OLD ) );
	await page.waitForTimeout( 500 );
	expect( hits ).toHaveLength( 1 );
	await expect( page.locator( '#hprnb-root .hprnb-bar' ) ).toBeVisible();
} );

test( 'hybrid: sessionStorage rules, failed endpoint, older response', async ( { page } ) => {
	setSettings();
	const hits = countRequests( page, /hprnb\/v1\/items/ );
	const now = Math.floor( Date.now() / 1000 );

	// Session payload fresher than the (stale) SSR and still fresh: applied, no fetch.
	await page.goto( '/' );
	await page.evaluate( ( gen ) => sessionStorage.setItem( 'hprnb_payload', JSON.stringify( { generated_at: gen, count: 1, html: '<aside class="hprnb-bar" role="region" aria-label="x" aria-live="off"><div class="hprnb-bar__inner"><p class="hprnb-bar__label"><span class="hprnb-bar__label-text">SESSION</span></p></div></aside>' } ) ), now - 10 );
	await routeStaleDocument( page );
	await page.goto( '/' );
	await expect( page.locator( '.hprnb-bar__label-text' ) ).toHaveText( 'SESSION' );
	await page.waitForTimeout( 500 );
	expect( hits ).toHaveLength( 0 );

	// Session payload older than the SSR: never applied (a fetch happens instead).
	await page.evaluate( ( gen ) => sessionStorage.setItem( 'hprnb_payload', JSON.stringify( { generated_at: gen, count: 1, html: '<aside class="hprnb-bar"><span class="hprnb-bar__label-text">OLD SESSION</span></aside>' } ) ), OLD - 100 );
	await page.goto( '/' );
	await expect.poll( () => hits.length ).toBe( 1 );
	await expect( page.locator( '.hprnb-bar__label-text' ) ).toHaveText( 'TOUTE L’ACTUALITÉ' );
	await expect( page.locator( '.hprnb-bar__label-text' ) ).not.toHaveText( 'OLD SESSION' );

	// Endpoint failure: SSR kept, no retry, no error.
	await page.evaluate( () => sessionStorage.clear() );
	const errors = collectErrors( page );
	await page.route( /hprnb\/v1\/items/, ( route ) => route.fulfill( { status: 500, body: 'boom' } ) );
	await page.goto( '/' );
	await expect.poll( () => hits.length ).toBe( 2 );
	await page.waitForTimeout( 800 );
	expect( hits ).toHaveLength( 2 );
	await expect( page.locator( '#hprnb-root' ) ).toHaveAttribute( 'data-hprnb-generated', String( OLD ) );
	await expect( page.locator( '#hprnb-root .hprnb-bar' ) ).toBeVisible();
	expect( errors.filter( ( e ) => e.startsWith( 'pageerror' ) ) ).toEqual( [] );
	await page.unroute( /hprnb\/v1\/items/ );

	// Response older than the SSR: ignored.
	await page.route( /hprnb\/v1\/items/, ( route ) => route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify( { version: '1.0.0', generated_at: OLD - 5, count: 1, html: '<aside class="hprnb-bar"><span class="hprnb-bar__label-text">OLDER</span></aside>' } ) } ) );
	await page.goto( '/' );
	await expect.poll( () => hits.length ).toBe( 3 );
	await page.waitForTimeout( 500 );
	await expect( page.locator( '.hprnb-bar__label-text' ) ).toHaveText( 'TOUTE L’ACTUALITÉ' );
	await page.unroute( /hprnb\/v1\/items/ );
} );

test( 'hybrid: empty stale SSR gets the bar, CSS, JS and body class injected', async ( { page } ) => {
	setSettings( { close_button: true } );
	const errors = collectErrors( page );
	await page.evaluate( () => sessionStorage.clear() ).catch( () => {} );
	await routeStaleDocument( page, { empty: true } );
	await page.goto( '/' );

	await expect( page.locator( '#hprnb-root .hprnb-bar' ) ).toBeVisible();
	await expect( page.locator( 'link#hprnb-bar-css' ) ).toHaveCount( 1 );
	await expect( page.locator( 'script#hprnb-bar-js' ) ).toHaveCount( 1 );
	await expect( page.locator( 'body' ) ).toHaveClass( /hprnb-reserve/ );
	expect( await page.evaluate( () => parseFloat( getComputedStyle( document.body ).paddingBottom ) ) ).toBeGreaterThanOrEqual( 44 );
	expect( await page.locator( '#hprnb-root .hprnb-bar' ).evaluate( ( el ) => getComputedStyle( el ).position ) ).toBe( 'fixed' );

	// The injected script initialises the injected bar: the close button works.
	await page.locator( '.hprnb-bar__btn--close' ).click();
	await expect( page.locator( '#hprnb-root .hprnb-bar' ) ).toBeHidden();
	await expect( page.locator( 'body' ) ).not.toHaveClass( /hprnb-reserve/ );
	expect( errors ).toEqual( [] );
} );

test( 'hybrid: stale SSR and empty REST result removes the bar', async ( { page } ) => {
	setSettings();
	await page.route( /hprnb\/v1\/items/, ( route ) => route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify( { version: '1.0.0', generated_at: Math.floor( Date.now() / 1000 ), count: 0, html: '' } ) } ) );
	await routeStaleDocument( page );
	await page.goto( '/' );
	await expect( page.locator( '#hprnb-root .hprnb-bar' ) ).toHaveCount( 0 );
	await expect( page.locator( '#hprnb-root' ) ).toBeHidden();
	await expect( page.locator( 'body' ) ).not.toHaveClass( /hprnb-reserve/ );
} );

test( 'mobile inline layout: single line, label capped, one-line bar, no overflow; hide on mobile', async ( { page } ) => {
	setSettings( { mobile_layout: 'inline', mobile_ticker_mode: 'inherit' } );
	await page.setViewportSize( { width: 375, height: 667 } );
	await page.goto( '/' );
	const bar = page.locator( '#hprnb-root .hprnb-bar' );
	await expect( bar ).toBeVisible();
	const label = page.locator( '.hprnb-bar__label' );
	await expect( label ).toBeVisible();
	const labelBox = await label.boundingBox();
	expect( labelBox.width ).toBeLessThanOrEqual( 0.5 * 375 + 1 );
	// On a phone the inline label always precedes the headline, whatever label_position says (default "end").
	expect( labelBox.x ).toBeLessThan( ( await page.locator( '.hprnb-bar__viewport' ).boundingBox() ).x );
	expect( Math.round( ( await bar.boundingBox() ).height ) ).toBe( 54, 'Inline, two 16px lines: 2 × 21 + 12' );
	expect( await noHorizontalOverflow( page ) ).toBe( true );

	setSettings( { mobile_layout: 'inline', mobile_ticker_mode: 'inherit', mobile_lines: 1 } );
	await page.goto( '/' );
	expect( Math.round( ( await bar.boundingBox() ).height ) ).toBe( 44 );

	setSettings( { show_on_mobile: false } );
	await page.goto( '/' );
	await expect( page.locator( '#hprnb-root' ) ).toHaveClass( /hprnb-hide-mobile/ );
	await expect( bar ).toBeHidden();
	expect( await page.evaluate( () => parseFloat( getComputedStyle( document.body ).paddingBottom ) ) ).toBe( 0 );
	await page.setViewportSize( { width: 1024, height: 700 } );
	await expect( bar ).toBeVisible();

	setSettings( { show_on_desktop: false } );
	await page.goto( '/' );
	await expect( bar ).toBeHidden();
	await page.setViewportSize( { width: 375, height: 667 } );
	await expect( bar ).toBeVisible();
} );

test( 'responsive widths never overflow horizontally', async ( { page } ) => {
	setSettings( { show_separator: true, show_relative_time: true } );
	for ( const width of [ 320, 375, 390, 430, 768, 1366, 1920 ] ) {
		await page.setViewportSize( { width, height: 700 } );
		await page.goto( '/' );
		await expect( page.locator( '#hprnb-root .hprnb-bar' ) ).toBeVisible();
		expect( await noHorizontalOverflow( page ), `width ${ width }` ).toBe( true );
		expect( Math.round( ( await page.locator( '.hprnb-bar' ).boundingBox() ).height ), `width ${ width }` ).toBe( width < 768 ? 80 : 44 );
	}
} );

test( 'RTL: label "end" sits on the left, marquee direction flips, RTL stylesheet used', async ( { page } ) => {
	// dir="auto" resolves the direction from the first strong character of the bar (the label).
	setSettings( { ticker_enabled: true, ticker_mode: 'marquee', label_text: 'آخر الأخبار', mobile_layout: 'inline', mobile_ticker_mode: 'inherit' } );
	const arabic = wp( [ 'post', 'create', '--post_type=post', '--post_status=publish', '--post_title=عنوان تجريبي طويل لاختبار الشريط الإخباري في اتجاه من اليمين إلى اليسار', '--porcelain' ] );
	try {
		await page.setViewportSize( { width: 1024, height: 700 } );
		await page.goto( '/?hprnb_rtl=1' );
		expect( await page.evaluate( () => document.documentElement.dir ) ).toBe( 'rtl' );
		expect( await page.locator( 'link#hprnb-bar-css' ).count() ).toBe( 0 );
		const css = await page.locator( 'link#hprnb-bar-rtl-css' ).getAttribute( 'href' );
		expect( css ).toContain( 'hprnb-bar-rtl.min.css' );
		const aside = page.locator( '.hprnb-bar' );
		expect( await aside.evaluate( ( el ) => getComputedStyle( el ).direction ) ).toBe( 'rtl' );
		const label = await page.locator( '.hprnb-bar__label' ).boundingBox();
		const viewport = await page.locator( '.hprnb-bar__viewport' ).boundingBox();
		expect( label.x ).toBeLessThan( viewport.x );
		await expect( aside ).toHaveClass( /hprnb-bar--marquee-on/ );
		await expect( aside ).toHaveClass( /hprnb-bar--rtl/ );
		expect( await page.locator( '.hprnb-bar__track' ).evaluate( ( el ) => getComputedStyle( el ).animationName ) ).toBe( 'hprnb-marquee-rtl' );
		expect( await noHorizontalOverflow( page ) ).toBe( true );

		// Same content on an LTR site: dir="auto" still yields RTL for the Arabic label (component-level direction).
		await page.goto( '/' );
		expect( await page.evaluate( () => document.documentElement.dir ) ).not.toBe( 'rtl' );
		expect( await aside.evaluate( ( el ) => getComputedStyle( el ).direction ) ).toBe( 'rtl' );
		await expect( aside ).toHaveClass( /hprnb-bar--rtl/ );
	} finally {
		wp( [ 'post', 'delete', arabic, '--force' ] );
	}

	// Latin content on an LTR site: label "end" on the right, LTR keyframe.
	setSettings( { ticker_enabled: true, ticker_mode: 'marquee', mobile_layout: 'inline', mobile_ticker_mode: 'inherit' } );
	await page.goto( '/' );
	const aside = page.locator( '.hprnb-bar' );
	await expect( aside ).toHaveClass( /hprnb-bar--marquee-on/ );
	await expect( aside ).not.toHaveClass( /hprnb-bar--rtl/ );
	expect( await page.locator( '.hprnb-bar__track' ).evaluate( ( el ) => getComputedStyle( el ).animationName ) ).toBe( 'hprnb-marquee' );
	const label2 = await page.locator( '.hprnb-bar__label' ).boundingBox();
	const viewport2 = await page.locator( '.hprnb-bar__viewport' ).boundingBox();
	expect( label2.x ).toBeGreaterThan( viewport2.x );
} );

test( 'marquee: clone, pause/play button, hover, focus, hidden tab, fits → no animation', async ( { page } ) => {
	setSettings( { ticker_enabled: true, ticker_mode: 'marquee', pause_on_hover: true, mobile_ticker_mode: 'inherit' } );
	const errors = collectErrors( page );
	await page.setViewportSize( { width: 375, height: 667 } );
	await page.goto( '/' );

	const aside = page.locator( '.hprnb-bar' );
	await expect( aside ).toHaveClass( /hprnb-bar--marquee-on/ );
	expect( await page.locator( 'script#hprnb-bar-js' ).count() ).toBe( 1 );
	const clone = page.locator( '.hprnb-bar__list--clone' );
	await expect( clone ).toHaveCount( 1 );
	await expect( clone ).toHaveAttribute( 'aria-hidden', 'true' );
	expect( await clone.locator( 'a' ).first().getAttribute( 'tabindex' ) ).toBe( '-1' );
	expect( await page.locator( '.hprnb-bar__track' ).evaluate( ( el ) => el.style.getPropertyValue( '--hprnb-duration' ) ) ).toMatch( /^[0-9.]+s$/ );
	expect( await page.locator( '.hprnb-bar__track' ).evaluate( ( el ) => getComputedStyle( el ).animationPlayState ) ).toBe( 'running' );

	const toggle = page.locator( '.hprnb-bar__btn--toggle' );
	await expect( toggle ).toBeVisible();
	await expect( toggle ).toHaveAttribute( 'aria-label', 'Pause' );
	const size = await toggle.boundingBox();
	expect( size.width ).toBeGreaterThanOrEqual( 44 );
	expect( size.height ).toBeGreaterThanOrEqual( 44 );

	await toggle.click();
	await expect( aside ).toHaveClass( /hprnb-bar--paused/ );
	await expect( toggle ).toHaveAttribute( 'aria-label', 'Play' );
	expect( await page.locator( '.hprnb-bar__track' ).evaluate( ( el ) => getComputedStyle( el ).animationPlayState ) ).toBe( 'paused' );
	await page.mouse.move( 5, 5 );
	await expect( aside ).toHaveClass( /hprnb-bar--paused/ ); // user pause is sticky
	await toggle.click();
	await expect( toggle ).toHaveAttribute( 'aria-label', 'Pause' );
	await page.mouse.move( 5, 5 ); // leave the bar (hover pause is enabled in this scenario)
	await expect( aside ).not.toHaveClass( /hprnb-bar--paused/ );

	// Hover pause (option enabled).
	await aside.hover();
	await expect( aside ).toHaveClass( /hprnb-bar--paused/ );
	await page.mouse.move( 5, 5 );
	await expect( aside ).not.toHaveClass( /hprnb-bar--paused/ );

	// Focus pause.
	await page.locator( '.hprnb-bar__list:not(.hprnb-bar__list--clone) a' ).first().focus();
	await expect( aside ).toHaveClass( /hprnb-bar--paused/ );
	await page.locator( 'body' ).evaluate( ( el ) => el.focus() );
	await page.evaluate( () => document.activeElement && document.activeElement.blur() );
	await expect( aside ).not.toHaveClass( /hprnb-bar--paused/ );

	// Hidden tab.
	await page.evaluate( () => {
		Object.defineProperty( document, 'hidden', { configurable: true, get: () => true } );
		document.dispatchEvent( new Event( 'visibilitychange' ) );
	} );
	await expect( aside ).toHaveClass( /hprnb-bar--paused/ );
	await page.evaluate( () => {
		Object.defineProperty( document, 'hidden', { configurable: true, get: () => false } );
		document.dispatchEvent( new Event( 'visibilitychange' ) );
	} );
	await expect( aside ).not.toHaveClass( /hprnb-bar--paused/ );

	// Content that fits: no animation, no clone, toggle hidden.
	await page.setViewportSize( { width: 1920, height: 800 } );
	setSettings( { ticker_enabled: true, ticker_mode: 'marquee', max_items: 1, mobile_ticker_mode: 'inherit' } );
	await page.goto( '/' );
	await expect( page.locator( '.hprnb-bar__item' ) ).toHaveCount( 1 );
	await expect( aside ).not.toHaveClass( /hprnb-bar--marquee-on/ );
	await expect( clone ).toHaveCount( 0 );
	await expect( toggle ).toBeHidden();
	expect( errors ).toEqual( [] );
} );

test( 'reduced motion disables marquee and rotate', async ( { page } ) => {
	setSettings( { ticker_enabled: true, ticker_mode: 'marquee', mobile_ticker_mode: 'inherit' } );
	await page.emulateMedia( { reducedMotion: 'reduce' } );
	await page.setViewportSize( { width: 375, height: 667 } );
	await page.goto( '/' );
	const aside = page.locator( '.hprnb-bar' );
	await expect( aside ).toHaveClass( /hprnb-bar--reduced/ );
	await expect( aside ).not.toHaveClass( /hprnb-bar--marquee-on/ );
	await expect( page.locator( '.hprnb-bar__list--clone' ) ).toHaveCount( 0 );
	await expect( page.locator( '.hprnb-bar__btn--toggle' ) ).toBeHidden();
	expect( await page.locator( '.hprnb-bar__list' ).evaluate( ( el ) => getComputedStyle( el ).animationName ) ).toBe( 'none' );

	setSettings( { ticker_enabled: true, ticker_mode: 'rotate', mobile_ticker_mode: 'inherit' } );
	await page.goto( '/' );
	await expect( page.locator( '.hprnb-bar__btn--toggle' ) ).toBeHidden();
	expect( await page.locator( '.hprnb-bar__item[hidden]' ).count() ).toBe( 0 );
} );

test( 'rotate: one item at a time, advances, pauses on focus and via button', async ( { page } ) => {
	setSettings( { ticker_enabled: true, ticker_mode: 'rotate', rotate_interval: 1000 } );
	await page.goto( '/' );
	const items = page.locator( '.hprnb-bar__item' );
	const visible = () => page.locator( '.hprnb-bar__item:not([hidden])' );
	await expect( visible() ).toHaveCount( 1 );
	const first = await visible().first().textContent();
	await expect.poll( async () => visible().first().textContent(), { timeout: 5000 } ).not.toBe( first );
	await expect( visible() ).toHaveCount( 1 );
	expect( await items.count() ).toBeGreaterThan( 1 );

	const toggle = page.locator( '.hprnb-bar__btn--toggle' );
	await toggle.click();
	await expect( toggle ).toHaveAttribute( 'aria-label', 'Play' );
	const frozen = await visible().first().textContent();
	await page.waitForTimeout( 1600 );
	expect( await visible().first().textContent() ).toBe( frozen );
	await toggle.click();
	await expect( toggle ).toHaveAttribute( 'aria-label', 'Pause' );

	await visible().first().locator( 'a' ).focus();
	const focused = await visible().first().textContent();
	await page.waitForTimeout( 1600 );
	expect( await visible().first().textContent() ).toBe( focused );
} );

test( 'manual: prev/next buttons and end states', async ( { page } ) => {
	setSettings( { ticker_enabled: true, ticker_mode: 'manual', mobile_ticker_mode: 'inherit' } );
	await page.setViewportSize( { width: 375, height: 667 } );
	await page.goto( '/' );
	const prev = page.locator( '.hprnb-bar__btn--prev' );
	const next = page.locator( '.hprnb-bar__btn--next' );
	await expect( prev ).toBeDisabled();
	await expect( next ).toBeEnabled();
	await expect( page.locator( '.hprnb-bar__btn--toggle' ) ).toHaveCount( 0 );
	expect( await page.locator( '.hprnb-bar__viewport' ).evaluate( ( el ) => getComputedStyle( el ).scrollSnapType ) ).toContain( 'x' );

	await next.click();
	await expect.poll( () => page.locator( '.hprnb-bar__viewport' ).evaluate( ( el ) => el.scrollLeft ) ).toBeGreaterThan( 0 );
	await expect( prev ).toBeEnabled();
	for ( let i = 0; i < 12 && ! ( await next.isDisabled() ); i++ ) {
		await next.click();
		await page.waitForTimeout( 150 );
	}
	await expect( next ).toBeDisabled();

	await page.setViewportSize( { width: 1920, height: 800 } );
	setSettings( { ticker_enabled: true, ticker_mode: 'manual', max_items: 1, mobile_ticker_mode: 'inherit' } );
	await page.goto( '/' );
	await expect( prev ).toBeDisabled();
	await expect( next ).toBeDisabled();
} );

test( 'close button: hides the bar, moves focus, remember uses localStorage and the head script', async ( { page } ) => {
	setSettings( { close_button: true } );
	await page.goto( '/' );
	expect( await page.locator( 'script#hprnb-dismiss' ).count() ).toBe( 0 );
	const close = page.locator( '.hprnb-bar__btn--close' );
	await expect( close ).toHaveAttribute( 'aria-label', 'Close the news bar' );
	await close.focus();
	await close.press( 'Enter' );
	await expect( page.locator( '#hprnb-root .hprnb-bar' ) ).toBeHidden();
	await expect( page.locator( 'body' ) ).not.toHaveClass( /hprnb-reserve/ );
	expect( await page.evaluate( () => document.activeElement && document.activeElement.tagName ) ).not.toBe( 'BUTTON' );
	expect( await page.evaluate( () => localStorage.getItem( 'hprnb_dismissed_until' ) ) ).toBeNull();

	setSettings( { close_button: true, remember_dismiss: true, dismiss_duration_hours: 2 } );
	await page.goto( '/' );
	expect( await page.locator( 'script#hprnb-dismiss' ).count() ).toBe( 1 );
	await page.locator( '.hprnb-bar__btn--close' ).click();
	const until = await page.evaluate( () => Number( localStorage.getItem( 'hprnb_dismissed_until' ) ) );
	expect( until ).toBeGreaterThan( Date.now() + 1.9 * 3600000 );

	const hits = countRequests( page, /hprnb\/v1\/items/ );
	await page.reload();
	await expect( page.locator( 'html' ) ).toHaveClass( /hprnb-dismissed/ );
	await expect( page.locator( '#hprnb-root' ) ).toBeHidden();
	expect( await page.evaluate( () => parseFloat( getComputedStyle( document.body ).paddingBottom ) ) ).toBe( 0 );
	await page.waitForTimeout( 400 );
	expect( hits ).toHaveLength( 0 );

	await page.evaluate( () => localStorage.removeItem( 'hprnb_dismissed_until' ) );
	await page.reload();
	await expect( page.locator( '#hprnb-root .hprnb-bar' ) ).toBeVisible();
} );

test( 'relative time and separator', async ( { page } ) => {
	setSettings( { show_relative_time: true, show_separator: true, separator_char: '|', separator_after_last: false } );
	await page.goto( '/' );
	const times = page.locator( 'time.hprnb-bar__time' );
	expect( await times.count() ).toBeGreaterThan( 0 );
	await expect( times.first() ).toHaveAttribute( 'data-hprnb-ts', /^\d+$/ );
	await expect( times.first() ).toHaveText( /ago|now/ );
	expect( await page.locator( '.hprnb-bar__sep' ).count() ).toBe( 0 );
	const seps = await separators( page, '.hprnb-bar__item' );
	expect( seps.length ).toBeGreaterThan( 1 );
	expect( seps.slice( 0, -1 ).every( ( s ) => s === '|' ) ).toBe( true );
	expect( seps[ seps.length - 1 ] ).toBeNull();
} );

/** Text of the ::after separator of each matched element (null when none). */
async function separators( page, selector ) {
	return page.evaluate( ( sel ) => Array.from( document.querySelectorAll( sel ) ).map( ( el ) => {
		const c = getComputedStyle( el, '::after' ).content;
		if ( ! c || c === 'none' || c === 'normal' ) {
			return null;
		}
		return c.split( ' / ' )[ 0 ].replace( /^["']|["']$/g, '' );
	} ), selector );
}

test( 'separator after the last post: 4 modes × LTR/RTL × on/off', async ( { page } ) => {
	const ORIGINAL = '.hprnb-bar__list:not(.hprnb-bar__list--clone) .hprnb-bar__item';
	const CLONE = '.hprnb-bar__list--clone .hprnb-bar__item';
	await page.setViewportSize( { width: 375, height: 667 } );
	for ( const mode of [ 'static', 'marquee', 'rotate', 'manual' ] ) {
		for ( const rtl of [ false, true ] ) {
			for ( const loop of [ true, false ] ) {
				const label = `${ mode } ${ rtl ? 'RTL' : 'LTR' } loop=${ loop }`;
				setSettings( {
					show_separator: true,
					separator_char: '|',
					separator_after_last: loop,
					mobile_show_separator: true,
					ticker_enabled: mode !== 'static',
					ticker_mode: mode === 'static' ? 'marquee' : mode,
					mobile_ticker_mode: 'inherit',
					label_text: rtl ? 'آخر الأخبار' : 'TOUTE L’ACTUALITÉ',
				} );
				await page.goto( rtl ? '/?hprnb_rtl=1' : '/' );
				const aside = page.locator( '#hprnb-root .hprnb-bar' );
				await expect( aside ).toBeVisible();
				expect( await aside.evaluate( ( el ) => getComputedStyle( el ).direction ), label ).toBe( rtl ? 'rtl' : 'ltr' );
				const root = page.locator( '#hprnb-root' );
				await expect( root ).toHaveClass( /hprnb-bar--sep(\s|$)/ );
				if ( loop ) {
					await expect( root ).toHaveClass( /hprnb-bar--sep-loop/ );
				} else {
					await expect( root ).not.toHaveClass( /hprnb-bar--sep-loop/ );
				}
				expect( await page.locator( '.hprnb-bar__sep' ).count(), label ).toBe( 0 );
				expect( await noHorizontalOverflow( page ), label ).toBe( true );

				const seps = await separators( page, ORIGINAL );
				expect( seps.length, label ).toBeGreaterThan( 1 );
				if ( mode === 'rotate' ) {
					expect( seps.every( ( s ) => s === null ), label ).toBe( true );
					continue;
				}
				expect( seps.slice( 0, -1 ).every( ( s ) => s === '|' ), label ).toBe( true );
				expect( seps[ seps.length - 1 ], label ).toBe( loop ? '|' : null );

				if ( mode === 'marquee' ) {
					await expect( aside ).toHaveClass( /hprnb-bar--marquee-on/ );
					const cloneSeps = await separators( page, CLONE );
					expect( cloneSeps, label ).toEqual( seps );
					// Junction original → clone: exactly one separator when looping, none otherwise.
					const junction = ( seps[ seps.length - 1 ] === '|' ? 1 : 0 ) + ( await page.evaluate( () => {
						const first = document.querySelector( '.hprnb-bar__list--clone .hprnb-bar__item' );
						const c = getComputedStyle( first, '::before' ).content;
						return c && c !== 'none' && c !== 'normal' ? 1 : 0;
					} ) );
					expect( junction, label ).toBe( loop ? 1 : 0 );
					// The wrap junction uses the same spacing as any other junction: no list padding while scrolling.
					expect( await page.locator( '.hprnb-bar__list' ).first().evaluate( ( el ) => getComputedStyle( el ).paddingInlineStart ), label ).toBe( '0px' );
					const gaps = await page.evaluate( () => [ getComputedStyle( document.querySelector( '.hprnb-bar__track' ) ).columnGap, getComputedStyle( document.querySelector( '.hprnb-bar__list' ) ).columnGap ] );
					expect( gaps[ 0 ], label ).toBe( gaps[ 1 ] );
				}
			}
		}
	}
	// Separator off: after_last is ignored, no class at all.
	setSettings( { show_separator: false, separator_after_last: true } );
	await page.goto( '/' );
	await expect( page.locator( '#hprnb-root' ) ).not.toHaveClass( /hprnb-bar--sep/ );
	expect( ( await separators( page, ORIGINAL ) ).every( ( s ) => s === null ) ).toBe( true );
} );

test( 'presentation profiles: the stacked design on desktop, headline lines, live dot, block label, mobile inline label first', async ( { page } ) => {
	setSettings( { desktop_layout: 'stacked', desktop_label_style: 'pill', desktop_label_dot: true, desktop_show_counter: true, desktop_lines: 2, desktop_show_progress: true, ticker_enabled: true, ticker_mode: 'rotate', rotate_interval: 1500 } );
	const errors = collectErrors( page );
	await page.setViewportSize( { width: 1366, height: 800 } );
	await page.goto( '/' );
	const root = page.locator( '#hprnb-root' );
	const aside = page.locator( '#hprnb-root .hprnb-bar' );
	const label = page.locator( '.hprnb-bar__label' );
	await expect( root ).toHaveClass( /hprnb-root--d-stacked/ );
	await expect( root ).toHaveClass( /hprnb-root--d-label-pill/ );
	await expect( root ).toHaveClass( /hprnb-root--d-dot/ );
	await expect( aside ).toHaveClass( /hprnb-bar--mode-rotate/ );
	expect( Math.round( ( await aside.boundingBox() ).height ) ).toBe( 76, '22 + 4 + 2 × 19 + 12' );
	expect( await label.evaluate( ( el ) => getComputedStyle( el ).borderTopLeftRadius ) ).toBe( '999px' );
	expect( await label.evaluate( ( el ) => getComputedStyle( el, '::before' ).display ) ).toBe( 'block' );
	expect( await label.evaluate( ( el ) => getComputedStyle( el, '::before' ).animationName ) ).toBe( 'hprnb-pulse' );
	await expect( page.locator( '.hprnb-bar__counter' ) ).toHaveText( /^1\/\d+$/ );
	const title = page.locator( '.hprnb-bar__item:not([hidden]) .hprnb-bar__title' );
	expect( await title.evaluate( ( el ) => getComputedStyle( el ).webkitLineClamp ) ).toBe( '2' );
	expect( ( await title.boundingBox() ).y ).toBeGreaterThan( ( await label.boundingBox() ).y + 20 );
	const progress = page.locator( '.hprnb-bar__progress' );
	await expect( progress ).toHaveClass( /is-run/ );
	expect( await progress.evaluate( ( el ) => getComputedStyle( el, '::after' ).animationName ) ).toBe( 'hprnb-progress' );
	expect( ( await page.locator( '.hprnb-bar__inner' ).boundingBox() ).width ).toBeLessThanOrEqual( 1200 );
	await expect( page.locator( '.hprnb-bar__counter' ) ).toHaveText( /^2\/\d+$/, { timeout: 4000 } );
	expect( await noHorizontalOverflow( page ) ).toBe( true );

	// Three headline lines without rotation: wrapped cards in the scrolling list, 69px bar (3 × 19 + 12).
	setSettings( { desktop_lines: 3, label_position: 'start' } );
	await page.goto( '/' );
	await expect( root ).toHaveClass( /hprnb-root--d-wrap/ );
	await expect( root ).not.toHaveClass( /hprnb-root--d-end/ );
	expect( Math.round( ( await aside.boundingBox() ).height ) ).toBe( 69 );
	expect( await title.first().evaluate( ( el ) => getComputedStyle( el ).whiteSpace ) ).toBe( 'normal' );
	expect( await title.first().evaluate( ( el ) => getComputedStyle( el ).webkitLineClamp ) ).toBe( '3' );
	expect( ( await page.locator( '.hprnb-bar__item' ).first().boundingBox() ).width ).toBeLessThanOrEqual( 30 * 14 + 1 );
	expect( ( await label.boundingBox() ).x ).toBeLessThan( ( await page.locator( '.hprnb-bar__viewport' ).boundingBox() ).x );
	expect( Math.round( ( await label.boundingBox() ).height ) ).toBe( 69, 'Block label spans the full height.' );
	await expect( page.locator( '.hprnb-bar__counter' ) ).toHaveCount( 0 );

	// Marquee ignores the lines setting: one line, 44px.
	setSettings( { desktop_lines: 3, ticker_enabled: true, ticker_mode: 'marquee' } );
	await page.goto( '/' );
	await expect( aside ).toHaveClass( /hprnb-bar--marquee-on/ );
	expect( Math.round( ( await aside.boundingBox() ).height ) ).toBe( 44 );
	expect( await title.first().evaluate( ( el ) => getComputedStyle( el ).whiteSpace ) ).toBe( 'nowrap' );

	// Mobile: block label on its own row, separator shown in the static list only when asked.
	setSettings( { mobile_label_style: 'strip', mobile_label_dot: true, mobile_ticker_mode: 'static', mobile_lines: 2, show_separator: true, mobile_show_separator: false } );
	await page.setViewportSize( { width: 375, height: 667 } );
	await page.goto( '/' );
	await expect( root ).toHaveClass( /hprnb-root--m-label-strip/ );
	await expect( root ).toHaveClass( /hprnb-root--m-dot/ );
	await expect( root ).not.toHaveClass( /hprnb-root--m-sep/ );
	expect( await label.evaluate( ( el ) => getComputedStyle( el ).borderTopLeftRadius ) ).toBe( '0px' );
	expect( await page.locator( '.hprnb-bar__item' ).first().evaluate( ( el ) => getComputedStyle( el, '::after' ).content ) ).toBe( 'none' );
	setSettings( { mobile_ticker_mode: 'static', mobile_lines: 2, show_separator: true, mobile_show_separator: true } );
	await page.goto( '/' );
	await expect( root ).toHaveClass( /hprnb-root--m-sep-loop/ );
	expect( await page.locator( '.hprnb-bar__item' ).first().evaluate( ( el ) => getComputedStyle( el, '::after' ).content ) ).toContain( '•' );
	expect( await noHorizontalOverflow( page ) ).toBe( true );
	expect( errors ).toEqual( [] );
} );

test( 'php mode and empty states', async ( { page } ) => {
	setSettings( { render_mode: 'php' } );
	await page.goto( '/' );
	await expect( page.locator( '#hprnb-root .hprnb-bar' ) ).toBeVisible();
	expect( await page.locator( 'script#hprnb-bootstrap-js' ).count() ).toBe( 0 );
	expect( await page.locator( '#hprnb-root[data-hprnb-endpoint]' ).count() ).toBe( 0 );

	setSettings( { render_mode: 'php', categories_include: [ 999999 ] } );
	await page.goto( '/' );
	expect( await page.locator( '#hprnb-root' ).count() ).toBe( 0 );
	expect( await page.locator( 'link#hprnb-bar-css' ).count() ).toBe( 0 );
	await expect( page.locator( 'body' ) ).not.toHaveClass( /hprnb-reserve/ );

	setSettings( { categories_include: [ 999999 ] } );
	await page.goto( '/' );
	await expect( page.locator( '#hprnb-root' ) ).toHaveCount( 1 );
	await expect( page.locator( '#hprnb-root' ) ).toBeHidden();
	await expect( page.locator( '#hprnb-root' ) ).toHaveAttribute( 'data-hprnb-empty', '1' );
	expect( await page.locator( 'link#hprnb-bar-css' ).count() ).toBe( 0 );
	expect( await page.locator( 'script#hprnb-bootstrap-js' ).count() ).toBe( 1 );
	expect( await page.locator( '#hprnb-root' ).evaluate( ( el ) => el.getBoundingClientRect().height ) ).toBe( 0 );
} );

test( 'shortcode renders a single bar', async ( { page } ) => {
	setSettings();
	const pageId = wp( [ 'post', 'create', '--post_type=page', '--post_status=publish', '--post_title=Shortcode page', '--post_content=<p>Before</p>[hprnb_news_bar]<p>After</p>[hprnb_news_bar]', '--porcelain' ] );
	try {
		await page.goto( '/?page_id=' + pageId );
		await expect( page.locator( '#hprnb-root' ) ).toHaveCount( 1 );
		await expect( page.locator( '.hprnb-bar' ) ).toHaveCount( 1 );
		await expect( page.locator( 'body' ) ).toHaveClass( /hprnb-reserve/ );
	} finally {
		wp( [ 'post', 'delete', pageId, '--force' ] );
	}
} );

test( 'admin: settings page, live preview, contrast warning, save, export, import, reset', async ( { page } ) => {
	setSettings();
	const errors = collectErrors( page );
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'admin' );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );

	// Admin assets are not loaded on other admin pages.
	await page.goto( '/wp-admin/index.php' );
	expect( await page.locator( 'link#hprnb-admin-css' ).count() ).toBe( 0 );
	expect( await page.locator( 'script#hprnb-admin-js' ).count() ).toBe( 0 );

	await page.goto( '/wp-admin/options-general.php?page=horizon-press-news-bar' );
	await expect( page.locator( 'h1' ) ).toHaveText( 'Horizon Press News Bar' );
	expect( await page.locator( 'link#hprnb-admin-css' ).count() ).toBe( 1 );
	await expect( page.locator( '#hprnb-preview-root .hprnb-bar' ) ).toBeVisible();
	expect( await page.locator( '#hprnb-preview-root .hprnb-bar' ).evaluate( ( el ) => getComputedStyle( el ).position ) ).toBe( 'relative' );

	// Live visual updates without network.
	const previews = countRequests( page, /hprnb\/v1\/preview/ );
	await page.fill( '#hprnb-field-label-text', 'LIVE LABEL' );
	await expect( page.locator( '#hprnb-preview-root .hprnb-bar__label-text' ) ).toHaveText( 'LIVE LABEL' );
	await page.fill( '#hprnb-field-bg-color', '#112233' );
	await page.locator( '#hprnb-field-bg-color' ).dispatchEvent( 'input' );
	expect( await page.locator( '#hprnb-preview-root .hprnb-bar' ).evaluate( ( el ) => getComputedStyle( el ).backgroundColor ) ).toBe( 'rgb(17, 34, 51)' );
	await page.check( '#hprnb-field-label-position-start' );
	await expect( page.locator( '#hprnb-preview-root .hprnb-bar' ) ).toHaveClass( /hprnb-bar--label-start/ );

	// Separator toggles are visual only and follow the dependency rule.
	const previewRoot = page.locator( '#hprnb-preview-root' );
	const dependentRow = page.locator( 'tr[data-hprnb-depends="show_separator"]' );
	await expect( dependentRow ).toHaveClass( /hprnb-row--inactive/ );
	await expect( previewRoot ).not.toHaveClass( /hprnb-bar--sep/ );
	await page.check( '#hprnb-field-show-separator' );
	await expect( dependentRow ).not.toHaveClass( /hprnb-row--inactive/ );
	await expect( previewRoot ).toHaveClass( /hprnb-bar--sep-loop/ );
	await page.fill( '#hprnb-field-separator-char', '|' );
	expect( await previewRoot.evaluate( ( el ) => el.style.getPropertyValue( '--hprnb-sep' ) ) ).toBe( "'|'" );
	const previewSeps = await separators( page, '#hprnb-preview-root .hprnb-bar__item' );
	expect( previewSeps.every( ( s ) => s === '|' ) ).toBe( true );
	await page.uncheck( '#hprnb-field-separator-after-last' );
	await expect( previewRoot ).not.toHaveClass( /hprnb-bar--sep-loop/ );
	await expect( previewRoot ).toHaveClass( /hprnb-bar--sep(\s|$)/ );
	await page.uncheck( '#hprnb-field-show-separator' );
	await expect( previewRoot ).not.toHaveClass( /hprnb-bar--sep/ );
	await expect( dependentRow ).toHaveClass( /hprnb-row--inactive/ );

	// Preview tabs: the mobile frame renders the stacked presentation, animated by the front script.
	await page.click( '#hprnb-preview-tab-mobile' );
	await expect( page.locator( '#hprnb-preview-stage' ) ).toHaveAttribute( 'data-hprnb-device', 'mobile' );
	await expect( previewRoot ).not.toHaveClass( /hprnb-root--flat/ );
	await expect( page.locator( '#hprnb-preview-root .hprnb-bar' ) ).toHaveClass( /hprnb-bar--mode-rotate/ );
	await expect( page.locator( '#hprnb-preview-root .hprnb-bar__counter' ) ).toHaveCount( 1 );
	expect( Math.round( ( await previewRoot.boundingBox() ).width ) ).toBeLessThanOrEqual( 375 );
	await page.uncheck( '#hprnb-field-mobile-custom-colors' );
	await expect( previewRoot ).not.toHaveClass( /hprnb-root--m-colors/ );
	await expect( page.locator( 'tr[data-hprnb-depends="mobile_custom_colors"]' ).first() ).toHaveClass( /hprnb-row--inactive/ );
	await page.check( '#hprnb-field-mobile-custom-colors' );
	await page.check( '#hprnb-field-mobile-layout-inline' );
	await expect( previewRoot ).toHaveClass( /hprnb-root--m-inline/ );
	await page.check( '#hprnb-field-mobile-layout-stacked' );
	await page.click( '#hprnb-preview-tab-desktop' );
	await expect( previewRoot ).toHaveClass( /hprnb-root--flat/ );
	await expect( page.locator( '#hprnb-preview-root .hprnb-bar' ) ).toHaveClass( /hprnb-bar--mode-none/ );
	expect( previews ).toHaveLength( 0 );

	// Contrast warning (never blocks saving).
	await page.fill( '#hprnb-field-text-color', '#112244' );
	await page.locator( '#hprnb-field-text-color' ).dispatchEvent( 'input' );
	await expect( page.locator( '#hprnb-contrast-text' ) ).toBeVisible();
	await page.fill( '#hprnb-field-text-color', '#ffffff' );
	await page.locator( '#hprnb-field-text-color' ).dispatchEvent( 'input' );
	await expect( page.locator( '#hprnb-contrast-text' ) ).toBeHidden();

	// Content refresh through the private endpoint.
	await page.fill( '#hprnb-field-max-items', '2' );
	await page.click( '#hprnb-preview-refresh' );
	await expect.poll( () => previews.length ).toBeGreaterThanOrEqual( 1 );
	await expect( page.locator( '#hprnb-preview-status' ) ).toHaveText( 'Preview updated.' );
	await expect( page.locator( '#hprnb-preview-root .hprnb-bar__item' ) ).toHaveCount( 2 );
	await expect( page.locator( '#hprnb-preview-root .hprnb-bar__label-text' ) ).toHaveText( 'LIVE LABEL' );

	// Save.
	await page.click( '#submit' );
	await expect( page.locator( '.notice-success, #setting-error-settings_updated' ).first() ).toBeVisible();
	await expect( page.locator( '#hprnb-field-label-text' ) ).toHaveValue( 'LIVE LABEL' );
	await expect( page.locator( '#hprnb-field-max-items' ) ).toHaveValue( '2' );
	expect( wp( [ 'option', 'get', 'hprnb_settings', '--format=json' ] ) ).toContain( '"label_text":"LIVE LABEL"' );

	// Export.
	const [ download ] = await Promise.all( [ page.waitForEvent( 'download' ), page.click( 'text=Download settings (JSON)' ) ] );
	expect( download.suggestedFilename() ).toMatch( /^horizon-press-news-bar-settings-\d{8}\.json$/ );
	const exported = JSON.parse( ( await ( await import( 'node:fs/promises' ) ).readFile( await download.path(), 'utf8' ) ) );
	expect( exported._meta.plugin ).toBe( 'horizon-press-news-bar' );
	expect( exported.settings.label_text ).toBe( 'LIVE LABEL' );

	// Import a modified document (unknown key ignored, HTML stripped).
	exported.settings.label_text = '<b>IMPORTED</b>';
	exported.settings.unknown = 'x';
	exported.settings.categories_include = [ 1 ];
	await page.locator( '#hprnb-import-file' ).setInputFiles( { name: 'settings.json', mimeType: 'text/plain', buffer: Buffer.from( JSON.stringify( exported ) ) } );
	await page.locator( '#hprnb_import_submit' ).click();
	await expect( page.locator( '.notice-warning' ).first() ).toContainText( 'Settings imported' );
	await expect( page.locator( '#hprnb-field-label-text' ) ).toHaveValue( 'IMPORTED' );

	// Reset (dialog accepted).
	page.once( 'dialog', ( d ) => d.accept() );
	await page.check( '#hprnb-reset-confirm' );
	await page.locator( '#hprnb_reset_submit' ).click();
	await expect( page.locator( '.notice-success' ).first() ).toContainText( 'restored' );
	await expect( page.locator( '#hprnb-field-label-text' ) ).toHaveValue( 'TOUTE L’ACTUALITÉ' );
	expect( errors ).toEqual( [] );
} );

test( 'French locale: front and admin strings are translated', async ( { page } ) => {
	setSettings();
	await page.goto( '/?hprnb_lang=fr_FR' );
	await expect( page.locator( '#hprnb-root .hprnb-bar' ) ).toHaveAttribute( 'aria-label', 'Dernières actualités' );
	expect( await page.evaluate( () => document.documentElement.lang ) ).toBe( 'fr-FR' );

	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'admin' );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );
	await page.goto( '/wp-admin/options-general.php?page=horizon-press-news-bar&hprnb_lang=fr_FR' );
	await expect( page.locator( 'label[for="hprnb-field-label-text"]' ) ).toHaveText( 'Label' );
	await expect( page.locator( 'label[for="hprnb-field-max-items"]' ) ).toHaveText( 'Nombre maximum d’articles' );
	await expect( page.locator( '#hprnb-preview-refresh' ) ).toHaveText( 'Actualiser les articles de l’aperçu' );
	await expect( page.locator( '#submit' ) ).toHaveValue( 'Enregistrer les réglages' );
} );

test( 'client clock behind the server: SSR counts as fresh, no fetch, no loop', async ( { page } ) => {
	setSettings();
	const hits = countRequests( page, /hprnb\/v1\/items/ );
	await page.clock.setFixedTime( new Date( Date.now() - 3 * 24 * 3600 * 1000 ) );
	await page.goto( '/' );
	await expect( page.locator( '#hprnb-root .hprnb-bar' ) ).toBeVisible();
	await page.waitForTimeout( 800 );
	expect( hits ).toHaveLength( 0 );
} );

test( 'admin page is usable in RTL', async ( { page } ) => {
	setSettings();
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'admin' );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );
	await page.goto( '/wp-admin/options-general.php?page=horizon-press-news-bar&hprnb_rtl=1' );
	expect( await page.evaluate( () => document.documentElement.dir ) ).toBe( 'rtl' );
	await expect( page.locator( '#hprnb-form' ) ).toBeVisible();
	await expect( page.locator( '#hprnb-preview-root .hprnb-bar' ) ).toBeVisible();
	await expect( page.locator( 'link#hprnb-admin-css' ) ).toHaveCount( 1 );
	await expect( page.locator( 'link#hprnb-bar-rtl-css' ) ).toHaveCount( 1 );
	const form = await page.locator( '#hprnb-form' ).boundingBox();
	const preview = await page.locator( '#hprnb-preview' ).boundingBox();
	expect( preview.x ).toBeLessThan( form.x ); // sidebar flips to the left in RTL
	expect( await noHorizontalOverflow( page ) ).toBe( true );
	await page.fill( '#hprnb-field-label-text', 'RTL LABEL' );
	await expect( page.locator( '#hprnb-preview-root .hprnb-bar__label-text' ) ).toHaveText( 'RTL LABEL' );
} );

test( 'accessibility: axe-core audit of the bar and visible keyboard focus', async ( { page } ) => {
	const axePath = new URL( '../../node_modules/axe-core/axe.min.js', import.meta.url ).pathname;
	const variants = [
		{ name: 'default', settings: {} },
		{ name: 'marquee+close+time+thumbs', settings: { ticker_enabled: true, ticker_mode: 'marquee', close_button: true, show_relative_time: true, show_thumbnail: true, show_separator: true } },
		{ name: 'rotate', settings: { ticker_enabled: true, ticker_mode: 'rotate' } },
		{ name: 'manual', settings: { ticker_enabled: true, ticker_mode: 'manual' } },
	];
	for ( const variant of variants ) {
		setSettings( variant.settings );
		await page.setViewportSize( { width: 375, height: 667 } );
		await page.goto( '/' );
		await expect( page.locator( '#hprnb-root .hprnb-bar' ) ).toBeVisible();
		await expect( page.locator( '#hprnb-root .hprnb-bar' ) ).toHaveAttribute( 'data-hprnb-init', '1' );
		await page.waitForTimeout( 500 ); // let the headline entrance animation (opacity) finish before sampling contrast
		await page.addScriptTag( { path: axePath } );
		const results = await page.evaluate( async () => {
			const r = await window.axe.run( document.getElementById( 'hprnb-root' ), { runOnly: { type: 'tag', values: [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice' ] } } );
			return r.violations.map( ( v ) => ( { id: v.id, impact: v.impact, nodes: v.nodes.map( ( n ) => n.target.join( ' ' ) ).slice( 0, 3 ) } ) );
		} );
		expect( results, variant.name ).toEqual( [] );
	}

	// Keyboard focus is always visible on links and buttons.
	setSettings( { ticker_enabled: true, ticker_mode: 'marquee', close_button: true } );
	await page.goto( '/' );
	const link = page.locator( '.hprnb-bar__list:not(.hprnb-bar__list--clone) .hprnb-bar__link' ).first();
	await link.focus();
	await page.keyboard.press( 'Shift+Tab' );
	await page.keyboard.press( 'Tab' );
	await expect( link ).toBeFocused();
	expect( await link.evaluate( ( el ) => getComputedStyle( el ).outlineStyle ) ).not.toBe( 'none' );
	expect( await link.evaluate( ( el ) => parseFloat( getComputedStyle( el ).outlineWidth ) ) ).toBeGreaterThanOrEqual( 2 );
	const toggle = page.locator( '.hprnb-bar__btn--toggle' );
	await toggle.focus();
	await page.keyboard.press( 'Shift+Tab' );
	await page.keyboard.press( 'Tab' );
	await expect( toggle ).toBeFocused();
	expect( await toggle.evaluate( ( el ) => getComputedStyle( el ).outlineStyle ) ).not.toBe( 'none' );
	await page.keyboard.press( 'Space' );
	await expect( toggle ).toHaveAttribute( 'aria-label', 'Play' );
	await expect( page.locator( '.hprnb-bar' ) ).toHaveClass( /hprnb-bar--paused/ );
	// The marquee clone never receives keyboard focus.
	for ( let i = 0; i < 40; i++ ) {
		await page.keyboard.press( 'Tab' );
		const inClone = await page.evaluate( () => !! ( document.activeElement && document.activeElement.closest( '.hprnb-bar__list--clone' ) ) );
		expect( inClone ).toBe( false );
	}
} );

test( 'mobile stacked presentation: pill, counter, progress, rotation, swipe, collapse, colours, options, breakpoint', async ( { page } ) => {
	setSettings( { rotate_interval: 1500 } );
	const errors = collectErrors( page );
	await page.setViewportSize( { width: 375, height: 667 } );
	await page.goto( '/' );
	const root = page.locator( '#hprnb-root' );
	const aside = page.locator( '#hprnb-root .hprnb-bar' );
	await expect( aside ).toBeVisible();
	await expect( root ).toHaveClass( /hprnb-root--m-stacked/ );
	await expect( aside ).toHaveClass( /hprnb-bar--mode-rotate/ );
	await expect( aside ).toHaveClass( /hprnb-bar--mobile/ );
	expect( Math.round( ( await aside.boundingBox() ).height ) ).toBe( 80, 'Label row 22 + gap 4 + two 16px lines (2 × 21) + padding 12' );
	expect( await page.evaluate( () => parseFloat( getComputedStyle( document.body ).paddingBottom ) ) ).toBeGreaterThanOrEqual( 80 );

	// Row 1: pill label (live dot off by default) and the counter; row 2: full-width 16px headline, two lines max.
	const label = page.locator( '.hprnb-bar__label' );
	await expect( label ).toBeVisible();
	expect( await label.evaluate( ( el ) => getComputedStyle( el, '::before' ).display ) ).toBe( 'none', 'The live dot is off by default.' );
	expect( await label.evaluate( ( el ) => getComputedStyle( el ).borderTopLeftRadius ) ).toBe( '999px' );
	// The default label ("TOUTE L’ACTUALITÉ") fits in the pill without an ellipsis, and the pill stays under 60% of the bar.
	expect( await page.locator( '.hprnb-bar__label-text' ).evaluate( ( el ) => el.scrollWidth <= el.clientWidth + 1 ) ).toBe( true );
	expect( ( await label.boundingBox() ).width ).toBeLessThanOrEqual( 375 * 0.6 );
	const counter = page.locator( '.hprnb-bar__counter' );
	await expect( counter ).toHaveText( /^1\/\d+$/ );
	const title = page.locator( '.hprnb-bar__item:not([hidden]) .hprnb-bar__title' );
	expect( await title.evaluate( ( el ) => getComputedStyle( el ).fontSize ) ).toBe( '16px' );
	expect( await title.evaluate( ( el ) => getComputedStyle( el ).webkitLineClamp ) ).toBe( '2' );
	expect( ( await page.locator( '.hprnb-bar__item:not([hidden]) .hprnb-bar__link' ).boundingBox() ).width ).toBeGreaterThan( 250 );
	const labelBox = await label.boundingBox();
	const titleBox = await title.boundingBox();
	expect( titleBox.y ).toBeGreaterThan( labelBox.y + labelBox.height - 1 );

	// Dedicated mobile palette.
	expect( await aside.evaluate( ( el ) => getComputedStyle( el ).color ) ).toBe( 'rgb(245, 245, 245)' );
	// color-mix() resolves to rgb(20 20 20 / .94) or color(srgb 0.078 …), depending on the engine.
	expect( await aside.evaluate( ( el ) => getComputedStyle( el ).backgroundColor ) ).toMatch( /20, 20, 20|0\.078/ );
	expect( await label.evaluate( ( el ) => getComputedStyle( el ).backgroundColor ) ).toBe( 'rgb(225, 29, 42)' );

	// Progress line runs and the rotation advances.
	const progress = page.locator( '.hprnb-bar__progress' );
	await expect( progress ).toHaveClass( /is-run/ );
	expect( await progress.evaluate( ( el ) => getComputedStyle( el, '::after' ).animationName ) ).toBe( 'hprnb-progress' );
	expect( ( await progress.boundingBox() ).y ).toBeLessThan( ( await aside.boundingBox() ).y + 1, 'The progress track runs along the top edge of the bar.' );
	await expect( counter ).toHaveText( /^2\/\d+$/, { timeout: 4000 } );

	// Swipe left → next headline.
	const before = await counter.textContent();
	const box = await page.locator( '.hprnb-bar__viewport' ).boundingBox();
	await page.mouse.move( box.x + box.width - 20, box.y + box.height / 2 );
	await page.mouse.down();
	await page.mouse.move( box.x + 40, box.y + box.height / 2, { steps: 8 } );
	await page.mouse.up();
	await expect( counter ).not.toHaveText( before );

	// Collapse while scrolling down, expand when scrolling up or tapping the label row.
	// (Before the buttons get focus: an interaction inside the bar holds it open for 4 s.)
	await page.evaluate( () => window.scrollTo( 0, 0 ) );
	await page.evaluate( () => window.scrollTo( 0, 600 ) );
	await expect( aside ).toHaveClass( /hprnb-bar--collapsed/ );
	await page.evaluate( () => window.scrollTo( 0, 300 ) );
	await expect( aside ).not.toHaveClass( /hprnb-bar--collapsed/ );
	await page.evaluate( () => window.scrollTo( 0, 900 ) );
	await expect( aside ).toHaveClass( /hprnb-bar--collapsed/ );
	await label.click( { force: true } );
	await expect( aside ).not.toHaveClass( /hprnb-bar--collapsed/ );

	// Pause / Play pauses the rotation and the progress line; no prev/next buttons in rotate mode.
	const toggle = page.locator( '.hprnb-bar__btn--toggle' );
	await expect( toggle ).toBeVisible();
	await toggle.click();
	await expect( aside ).toHaveClass( /hprnb-bar--paused/ );
	expect( await progress.evaluate( ( el ) => getComputedStyle( el, '::after' ).animationPlayState ) ).toBe( 'paused' );
	await toggle.click();
	await expect( page.locator( '.hprnb-bar__btn--prev' ) ).toBeHidden();
	expect( await noHorizontalOverflow( page ) ).toBe( true );

	expect( errors ).toEqual( [] );

	// Crossing the threshold re-initialises the bar for the desktop presentation, and back.
	await page.setViewportSize( { width: 1366, height: 800 } );
	await expect( aside ).toHaveClass( /hprnb-bar--mode-none/ );
	await expect( page.locator( '.hprnb-bar__counter' ) ).toHaveCount( 0 );
	await expect( page.locator( '.hprnb-bar__progress' ) ).toHaveCount( 0 );
	await expect( toggle ).toBeHidden();
	expect( Math.round( ( await aside.boundingBox() ).height ) ).toBe( 44 );
	await page.setViewportSize( { width: 375, height: 667 } );
	await expect( aside ).toHaveClass( /hprnb-bar--mode-rotate/ );
	await expect( page.locator( '.hprnb-bar__counter' ) ).toHaveCount( 1 );

	// Options: hidden label, desktop colours, no counter/progress/collapse.
	setSettings( { mobile_label_style: 'hidden', mobile_custom_colors: false, mobile_show_counter: false, mobile_show_progress: false, mobile_hide_on_scroll: false } );
	await page.goto( '/' );
	await expect( label ).toBeHidden();
	expect( await aside.evaluate( ( el ) => getComputedStyle( el ).backgroundColor ) ).toBe( 'rgb(176, 0, 0)' );
	await expect( page.locator( '.hprnb-bar__counter' ) ).toHaveCount( 0 );
	await expect( page.locator( '.hprnb-bar__progress' ) ).toHaveCount( 0 );
	await expect( root ).not.toHaveClass( /hprnb-root--m-collapse/ );
	await page.evaluate( () => window.scrollTo( 0, 900 ) );
	await page.waitForTimeout( 300 );
	await expect( aside ).not.toHaveClass( /hprnb-bar--collapsed/ );

	// Reduced motion: no automatic rotation, no progress line, toggle hidden.
	setSettings();
	await page.emulateMedia( { reducedMotion: 'reduce' } );
	await page.goto( '/' );
	await expect( aside ).toHaveClass( /hprnb-bar--reduced/ );
	await expect( page.locator( '.hprnb-bar__progress' ) ).toHaveCount( 0 );
	await expect( toggle ).toBeHidden();
	expect( await noHorizontalOverflow( page ) ).toBe( true );
} );
