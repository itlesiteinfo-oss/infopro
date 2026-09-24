import { test, expect } from '@playwright/test';
import { setSettings, setDefaultSettings, wp, collectErrors, countRequests, noHorizontalOverflow, routeStaleDocument, defaults, OLD } from './helpers.mjs';

// 2.17: the tests of the URGENT bar from 2.14 to 2.16 describe the chyron, still selectable (Breaking News is the default).
const CHYRON = { urgent_design: 'chyron' };


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
	setSettings( { mobile_layout: 'flow', mobile_lines: 2 } );
	const errors = collectErrors( page );
	await page.setViewportSize( { width: 1366, height: 800 } );
	await page.goto( '/' );

	const bar = page.locator( '#hprnb-root .hprnb-bar' );
	await expect( bar ).toBeVisible();
	await expect( bar ).toHaveAttribute( 'role', 'region' );
	await expect( bar ).toHaveAttribute( 'aria-live', 'off' );
	await expect( bar ).toHaveAttribute( 'dir', 'auto' );
	expect( await bar.evaluate( ( el ) => getComputedStyle( el ).position ) ).toBe( 'fixed' );
	expect( await bar.evaluate( ( el ) => getComputedStyle( el ).backgroundColor ) ).toBe( 'rgb(27, 28, 32)' );
	const box = await bar.boundingBox();
	expect( Math.round( box.y + box.height ) ).toBe( 800 );
	expect( Math.round( box.height ) ).toBe( 40 );

	await expect( page.locator( 'body' ) ).toHaveClass( /hprnb-reserve/ );
	expect( await page.evaluate( () => parseFloat( getComputedStyle( document.body ).paddingBottom ) ) ).toBeGreaterThanOrEqual( 40 );
	expect( await page.locator( '.hprnb-bar__item' ).count() ).toBeGreaterThanOrEqual( 5 );
	expect( await page.locator( '.hprnb-bar__item' ).count() ).toBeLessThanOrEqual( 10 );
	await expect( page.locator( '.hprnb-bar__label-text' ) ).toHaveText( 'EN CONTINU' );
	expect( await page.locator( 'script#hprnb-bootstrap-js' ).count() ).toBe( 1 );
	expect( await page.locator( 'script#hprnb-bar-js' ).count() ).toBe( 1 ); // mobile rotate needs it; harmless on desktop
	await expect( page.locator( '.hprnb-bar' ) ).toHaveAttribute( 'data-hprnb-init', '1' );
	expect( await page.locator( '.hprnb-bar button:not([hidden])' ).count() ).toBe( 2 ); // pause + close (v2 defaults)
	expect( await noHorizontalOverflow( page ) ).toBe( true );
	expect( errors ).toEqual( [] );
} );

test( 'fresh SSR: no REST request; stale SSR: exactly one', async ( { page } ) => {
	setSettings( { mobile_layout: 'flow', mobile_lines: 2 } );
	const hits = countRequests( page, /hprnb\/v1\/items/ );
	await page.goto( '/' );
	await page.waitForTimeout( 800 );
	expect( hits ).toHaveLength( 0 );
	// The session copy stores the real number of headlines: replayed on the next page it feeds
	// data-hprnb-count, which decides whether a separator is drawn at all.
	expect( await page.evaluate( () => { try { return JSON.parse( sessionStorage.getItem( 'hprnb_payload' ) ).count; } catch ( e ) { return null; } } ) ).toBe( 5 );

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
	setSettings( { mobile_layout: 'flow', mobile_lines: 2 } );
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
	await expect( page.locator( '.hprnb-bar__label-text' ) ).toHaveText( 'EN CONTINU' );
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
	await expect( page.locator( '.hprnb-bar__label-text' ) ).toHaveText( 'EN CONTINU' );
	await page.unroute( /hprnb\/v1\/items/ );
} );

test( 'hybrid: empty stale SSR gets the bar, CSS, JS and body class injected', async ( { page } ) => {
	setSettings( { mobile_layout: 'flow', mobile_lines: 2, close_button: true } );
	const errors = collectErrors( page );
	await page.evaluate( () => sessionStorage.clear() ).catch( () => {} );
	await routeStaleDocument( page, { empty: true } );
	await page.goto( '/' );

	await expect( page.locator( '#hprnb-root .hprnb-bar' ) ).toBeVisible();
	await expect( page.locator( 'link#hprnb-bar-css' ) ).toHaveCount( 1 );
	await expect( page.locator( 'script#hprnb-bar-js' ) ).toHaveCount( 1 );
	await expect( page.locator( 'body' ) ).toHaveClass( /hprnb-reserve/ );
	expect( await page.evaluate( () => parseFloat( getComputedStyle( document.body ).paddingBottom ) ) ).toBeGreaterThanOrEqual( 40 );
	expect( await page.locator( '#hprnb-root .hprnb-bar' ).evaluate( ( el ) => getComputedStyle( el ).position ) ).toBe( 'fixed' );

	// The injected script initialises the injected bar: the close button works.
	await page.locator( '.hprnb-bar__btn--close' ).click();
	await expect( page.locator( '#hprnb-root .hprnb-bar' ) ).toBeHidden();
	await expect( page.locator( 'body' ) ).not.toHaveClass( /hprnb-reserve/ );
	expect( errors ).toEqual( [] );
} );

test( 'hybrid: stale SSR and empty REST result removes the bar', async ( { page } ) => {
	setSettings( { mobile_layout: 'flow', mobile_lines: 2 } );
	await page.route( /hprnb\/v1\/items/, ( route ) => route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify( { version: '1.0.0', generated_at: Math.floor( Date.now() / 1000 ), count: 0, html: '' } ) } ) );
	await routeStaleDocument( page );
	await page.goto( '/' );
	await expect( page.locator( '#hprnb-root .hprnb-bar' ) ).toHaveCount( 0 );
	await expect( page.locator( '#hprnb-root' ) ).toBeHidden();
	await expect( page.locator( 'body' ) ).not.toHaveClass( /hprnb-reserve/ );
} );

test( 'each device can switch the bar off (the one-line mobile layout left the designs in 2.11)', async ( { page } ) => {
	await page.setViewportSize( { width: 375, height: 667 } );
	const bar = page.locator( '#hprnb-root .hprnb-bar' );

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
	setSettings( { mobile_layout: 'flow', mobile_lines: 2, show_separator: true, show_relative_time: true } );
	for ( const width of [ 320, 375, 390, 430, 768, 1366, 1920 ] ) {
		await page.setViewportSize( { width, height: 700 } );
		await page.goto( '/' );
		await expect( page.locator( '#hprnb-root .hprnb-bar' ) ).toBeVisible();
		expect( await noHorizontalOverflow( page ), `width ${ width }` ).toBe( true );
		expect( Math.round( ( await page.locator( '.hprnb-bar' ).boundingBox() ).height ), `width ${ width }` ).toBe( width < 768 ? 76 : 40 );
	}
} );

test( 'RTL: label "end" sits on the left, marquee direction flips, RTL stylesheet used', async ( { page } ) => {
	// dir="auto" resolves the direction from the first strong character of the bar (the label).
	setSettings( { ticker_enabled: true, ticker_mode: 'marquee', label_text: 'آخر الأخبار', label_position: 'end', mobile_layout: 'inline', mobile_ticker_mode: 'inherit' } );
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
	setSettings( { ticker_enabled: true, ticker_mode: 'marquee', label_position: 'end', mobile_layout: 'inline', mobile_ticker_mode: 'inherit' } );
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
	expect( size.width ).toBeGreaterThanOrEqual( 40 );
	expect( size.height ).toBeGreaterThanOrEqual( 40 );

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
	setSettings( { mobile_layout: 'flow', mobile_lines: 2, ticker_enabled: true, ticker_mode: 'marquee', mobile_ticker_mode: 'inherit' } );
	await page.emulateMedia( { reducedMotion: 'reduce' } );
	await page.setViewportSize( { width: 375, height: 667 } );
	await page.goto( '/' );
	const aside = page.locator( '.hprnb-bar' );
	await expect( aside ).toHaveClass( /hprnb-bar--reduced/ );
	await expect( aside ).not.toHaveClass( /hprnb-bar--marquee-on/ );
	await expect( page.locator( '.hprnb-bar__list--clone' ) ).toHaveCount( 0 );
	await expect( page.locator( '.hprnb-bar__btn--toggle' ) ).toBeHidden();
	expect( await page.locator( '.hprnb-bar__list' ).evaluate( ( el ) => getComputedStyle( el ).animationName ) ).toBe( 'none' );

	setSettings( { mobile_layout: 'flow', mobile_lines: 2, ticker_enabled: true, ticker_mode: 'rotate', mobile_ticker_mode: 'inherit' } );
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
	setSettings( { mobile_layout: 'flow', mobile_lines: 2, close_button: true, remember_dismiss: false } );
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

	setSettings( { mobile_layout: 'flow', mobile_lines: 2, close_button: true, remember_dismiss: true, dismiss_duration_hours: 2 } );
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
	setSettings( { mobile_layout: 'flow', mobile_lines: 2, show_relative_time: true, show_separator: true, separator_char: '|', separator_after_last: false, ticker_enabled: false } );
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
	setSettings( { mobile_layout: 'flow', mobile_lines: 2, show_separator: false, separator_after_last: true } );
	await page.goto( '/' );
	await expect( page.locator( '#hprnb-root' ) ).not.toHaveClass( /hprnb-bar--sep/ );
	expect( ( await separators( page, ORIGINAL ) ).every( ( s ) => s === null ) ).toBe( true );
} );

test( 'presentation profiles: the stacked design on desktop, headline lines, live dot, block label, mobile inline label first', async ( { page } ) => {
	setSettings( { mobile_layout: 'flow', mobile_lines: 2, desktop_layout: 'stacked', desktop_label_style: 'pill', desktop_label_dot: true, desktop_show_counter: true, desktop_lines: 2, desktop_show_progress: true, ticker_enabled: true, ticker_mode: 'rotate', rotate_interval: 1500, align_container: false } );
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
	expect( Math.round( ( await aside.boundingBox() ).height ) ).toBe( 78, '22 + 4 + 2 × 20 + 12' );
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

	// Three headline lines without rotation: wrapped cards in the scrolling list, 72px bar (3 × 20 + 12).
	setSettings( { mobile_layout: 'flow', mobile_lines: 2, desktop_lines: 3, label_position: 'start', ticker_enabled: false, desktop_label_style: 'strip', align_container: false } );
	await page.goto( '/' );
	await expect( root ).toHaveClass( /hprnb-root--d-wrap/ );
	await expect( root ).not.toHaveClass( /hprnb-root--d-end/ );
	expect( Math.round( ( await aside.boundingBox() ).height ) ).toBe( 72 );
	expect( await title.first().evaluate( ( el ) => getComputedStyle( el ).whiteSpace ) ).toBe( 'normal' );
	expect( await title.first().evaluate( ( el ) => getComputedStyle( el ).webkitLineClamp ) ).toBe( '3' );
	expect( ( await page.locator( '.hprnb-bar__item' ).first().boundingBox() ).width ).toBeLessThanOrEqual( 30 * 15 + 1 );
	expect( ( await label.boundingBox() ).x ).toBeLessThan( ( await page.locator( '.hprnb-bar__viewport' ).boundingBox() ).x );
	expect( Math.round( ( await label.boundingBox() ).height ) ).toBe( 72, 'Block label spans the full height.' );
	await expect( page.locator( '.hprnb-bar__counter' ) ).toHaveCount( 0 );

	// Marquee ignores the lines setting: one line, 40px.
	setSettings( { mobile_layout: 'flow', mobile_lines: 2, desktop_lines: 3, ticker_enabled: true, ticker_mode: 'marquee' } );
	await page.goto( '/' );
	await expect( aside ).toHaveClass( /hprnb-bar--marquee-on/ );
	expect( Math.round( ( await aside.boundingBox() ).height ) ).toBe( 40 );
	expect( await title.first().evaluate( ( el ) => getComputedStyle( el ).whiteSpace ) ).toBe( 'nowrap' );

	// Mobile: block label on its own row, separator shown in the static list only when asked.
	setSettings( { mobile_layout: 'flow', mobile_lines: 2, mobile_label_style: 'strip', mobile_label_dot: true, mobile_ticker_mode: 'static', mobile_lines: 2, show_separator: true, mobile_show_separator: false } );
	await page.setViewportSize( { width: 375, height: 667 } );
	await page.goto( '/' );
	await expect( root ).toHaveClass( /hprnb-root--m-label-strip/ );
	await expect( root ).toHaveClass( /hprnb-root--m-dot/ );
	await expect( root ).not.toHaveClass( /hprnb-root--m-sep/ );
	expect( await label.evaluate( ( el ) => getComputedStyle( el ).borderTopLeftRadius ) ).toBe( '0px' );
	expect( await page.locator( '.hprnb-bar__item' ).first().evaluate( ( el ) => getComputedStyle( el, '::after' ).content ) ).toBe( 'none' );
	setSettings( { mobile_layout: 'flow', mobile_lines: 2, mobile_ticker_mode: 'static', mobile_lines: 2, show_separator: true, mobile_show_separator: true } );
	await page.goto( '/' );
	await expect( root ).toHaveClass( /hprnb-root--m-sep-loop/ );
	expect( await page.locator( '.hprnb-bar__item' ).first().evaluate( ( el ) => getComputedStyle( el, '::after' ).content ) ).toContain( '•' );
	expect( await noHorizontalOverflow( page ) ).toBe( true );
	expect( errors ).toEqual( [] );
} );

test( 'v2: flow card, collapsed strip, chevron, offset contract, deep collapse, keyboard, landscape, desktop container, Jannah offset', async ( { page } ) => {
	setSettings( { mobile_layout: 'flow_image', mobile_lines: 2, rotate_interval: 1500 } );
	const errors = collectErrors( page );
	await page.setViewportSize( { width: 375, height: 667 } );
	await page.goto( '/' );
	const root = page.locator( '#hprnb-root' );
	const aside = page.locator( '#hprnb-root .hprnb-bar' );
	await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
	await expect( root ).toHaveClass( /hprnb-root--m-flow/ );
	await page.evaluate( () => { window.__states = []; document.addEventListener( 'hprnb:state', ( e ) => window.__states.push( e.detail ) ); } );

	// Card: 76px, pill 24px at 15px, headline 16px / 26px on two lines starting beside the pill and passing under it.
	const box = await aside.boundingBox();
	expect( Math.round( box.height ) ).toBe( 76 );
	expect( Math.round( box.y + box.height ) ).toBe( 667 );
	const label = page.locator( '.hprnb-bar__label' );
	const labelBox = await label.boundingBox();
	expect( Math.round( labelBox.height ) ).toBe( 24 );
	expect( Math.round( labelBox.x ) ).toBe( 15 );
	expect( await label.evaluate( ( el ) => getComputedStyle( el ).borderTopLeftRadius ) ).toBe( '999px' );
	expect( await label.evaluate( ( el ) => getComputedStyle( el, '::before' ).display ) ).toBe( 'block' );
	const title = page.locator( '.hprnb-bar__item:not([hidden]) .hprnb-bar__title' );
	expect( await title.evaluate( ( el ) => getComputedStyle( el ).fontSize ) ).toBe( '16px' );
	expect( await title.evaluate( ( el ) => getComputedStyle( el ).lineHeight ) ).toBe( '26px' );
	expect( Math.round( ( await title.boundingBox() ).x ) ).toBe( 15, 'The text block starts at the gutter: the first line flows beside the floating pill.' );
	expect( Math.round( ( await page.locator( '.hprnb-bar__viewport' ).boundingBox() ).height ) ).toBe( 52, 'Two lines, a third one is clipped.' );
	// A clipped headline ends with an ellipsis; the pill is roomy and its dot blinks.
	await expect( page.locator( '.hprnb-bar__viewport' ) ).toHaveClass( /is-clipped/ );
	// 2.13: the end of the last line fades into the bar instead of ending with three dots.
	expect( await page.locator( '.hprnb-bar__viewport' ).evaluate( ( el ) => { const a = getComputedStyle( el, '::after' ); return a.display + a.content + '|' + a.width + '|' + ( a.backgroundImage.startsWith( 'linear-gradient(to right' ) ); } ) ).toBe( 'block""|64px|true' );
	expect( await label.evaluate( ( el ) => getComputedStyle( el ).paddingLeft + ' ' + getComputedStyle( el ).paddingRight ) ).toBe( '12px 14px' );
	expect( await label.evaluate( ( el ) => getComputedStyle( el, '::before' ).animationName ) ).toBe( 'hprnb-pulse' );
	expect( await page.evaluate( () => getComputedStyle( document.body ).getPropertyValue( '--hprnb-offset' ).trim() ) ).toBe( '76px' );
	expect( await page.evaluate( () => parseFloat( getComputedStyle( document.body ).paddingBottom ) ) ).toBe( 76 );
	expect( await aside.evaluate( ( el ) => getComputedStyle( el ).backgroundColor ) ).toMatch( /27, 28, 32|0\.105882/ );
	expect( await page.locator( '.hprnb-bar__progress' ).evaluate( ( el ) => Math.round( el.getBoundingClientRect().height ) ) ).toBe( 3 );
	await expect( page.locator( '.hprnb-bar__counter' ) ).toHaveCount( 0 );
	// 2.11: the buttons sit in a 44px tab above the bar, and the contract says so for the corner elements.
	expect( await page.evaluate( () => window.hprnbBar.state() ) ).toEqual( { mobile: true, collapsed: false, height: 76, offset: 76, tab: 44 } );
	expect( await page.evaluate( () => getComputedStyle( document.body ).getPropertyValue( '--hprnb-tab' ).trim() ) ).toBe( '44px' );
	await page.evaluate( () => { const g = document.createElement( 'div' ); g.id = 'go-to-top'; g.style.cssText = 'position:fixed;right:15px;bottom:15px;width:35px;height:35px'; document.body.appendChild( g ); } );
	expect( await page.evaluate( () => getComputedStyle( document.getElementById( 'go-to-top' ) ).bottom ) ).toBe( '132px', 'Jannah\'s "go to top" clears the bar and its tab: 76 + 44 + 12.' );
	await page.evaluate( () => document.getElementById( 'go-to-top' ).remove() );

	// Scrolling down: 40px strip (pill + first line + chevron), links disabled, body class and offset updated, event emitted.
	await page.evaluate( () => window.scrollTo( 0, 0 ) );
	await page.evaluate( () => window.scrollTo( 0, 700 ) );
	await expect( aside ).toHaveClass( /hprnb-bar--collapsed/ );
	await expect( page.locator( 'body' ) ).toHaveClass( /hprnb-is-collapsed/ );
	await page.waitForTimeout( 650 ); // The fold takes 0.5 s since 2.11.
	expect( Math.round( 667 - ( await aside.boundingBox() ).y ) ).toBe( 40 );
	expect( await page.evaluate( () => getComputedStyle( document.body ).getPropertyValue( '--hprnb-offset' ).trim() ) ).toBe( '40px' );
	expect( await page.evaluate( () => window.__states.map( ( s ) => `${ s.collapsed }:${ s.offset }` ) ) ).toContain( 'true:40' );
	// The collapsed pill shrinks to a round beacon, hard against the gutter, and pulses like a button.
	expect( await label.evaluate( ( el ) => getComputedStyle( el ).inlineSize ) ).toBe( '24px' );
	expect( Math.round( ( await label.boundingBox() ).x ) ).toBeLessThanOrEqual( 15 );
	expect( await page.locator( '.hprnb-bar__label-text' ).evaluate( ( el ) => getComputedStyle( el ).opacity + ' ' + Math.round( el.getBoundingClientRect().width ) ) ).toBe( '0 0', 'The text closed up rather than vanished.' );
	expect( await label.evaluate( ( el ) => getComputedStyle( el, '::before' ).display ) ).toBe( 'block' );
	// The collapsed pill pulses like a button and the single visible line ends with an ellipsis.
	expect( await label.evaluate( ( el ) => getComputedStyle( el ).animationName ) ).toBe( 'hprnb-beacon' );
	expect( await page.locator( '.hprnb-bar__viewport' ).evaluate( ( el ) => getComputedStyle( el, '::after' ).top ) ).toBe( '0px' );
	const chevron = page.locator( '.hprnb-bar__btn--expand' );
	await expect( chevron ).toBeVisible();
	await expect( chevron ).toHaveAttribute( 'aria-label', 'Show the latest news' );
	// 2.11: the unfold button left the strip for the tab above the corner, where the cross was.
	const tab = await page.locator( '.hprnb-bar__controls' ).boundingBox();
	expect( Math.round( tab.y + tab.height ) ).toBe( Math.round( ( await aside.boundingBox() ).y ) );
	expect( Math.round( tab.x + tab.width ) ).toBe( 375 );
	await expect( page.locator( '.hprnb-bar__btn--toggle' ) ).toBeHidden();
	expect( await page.locator( '.hprnb-bar__link' ).first().evaluate( ( el ) => getComputedStyle( el ).pointerEvents ) ).toBe( 'none' );
	await chevron.click();
	await expect( aside ).not.toHaveClass( /hprnb-bar--collapsed/ );
	await expect( page.locator( 'body' ) ).not.toHaveClass( /hprnb-is-collapsed/ );
	expect( await label.evaluate( ( el ) => getComputedStyle( el ).animationName ) ).toBe( 'hprnb-beacon', 'The pill beats on arrival (default pulse: appear).' );
	expect( await label.evaluate( ( el ) => getComputedStyle( el ).animationIterationCount ) ).toBe( '3', 'And then stops.' );
	expect( await page.evaluate( () => getComputedStyle( document.body ).getPropertyValue( '--hprnb-offset' ).trim() ) ).toBe( '76px' );

	// "Pill only" strip option.
	setSettings( { mobile_layout: 'flow_image', mobile_lines: 2, rotate_interval: 1500, mobile_peek: 'label' } );
	await page.goto( '/' );
	await expect( root ).toHaveClass( /hprnb-root--peek-label/ );
	await page.evaluate( () => window.scrollTo( 0, 700 ) );
	await expect( aside ).toHaveClass( /hprnb-bar--collapsed/ );
	await expect.poll( () => page.locator( '.hprnb-bar__viewport' ).evaluate( ( el ) => getComputedStyle( el ).opacity ) ).toBe( '0' );

	// Keyboard: a field outside the bar slides it away (offset 0), blur brings it back.
	setSettings( { mobile_layout: 'flow_image', mobile_lines: 2, rotate_interval: 1500 } );
	await page.goto( '/' );
	await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
	await page.evaluate( () => { const i = document.createElement( 'input' ); i.type = 'email'; i.id = 'e2e-kbd'; i.style.cssText = 'position:fixed;top:10px;left:10px'; document.body.appendChild( i ); } );
	await page.focus( '#e2e-kbd' );
	await expect( page.locator( 'body' ) ).toHaveClass( /hprnb-kbd/ );
	expect( await page.evaluate( () => getComputedStyle( document.body ).getPropertyValue( '--hprnb-offset' ).trim() ) ).toBe( '0px' );
	await page.waitForTimeout( 350 );
	expect( ( await aside.boundingBox() ).y ).toBeGreaterThanOrEqual( 667 );
	await page.evaluate( () => document.getElementById( 'e2e-kbd' ).blur() );
	await expect( page.locator( 'body' ) ).not.toHaveClass( /hprnb-kbd/ );
	expect( await page.evaluate( () => getComputedStyle( document.body ).getPropertyValue( '--hprnb-offset' ).trim() ) ).toBe( '76px' );

	// Landing mid-page (reload keeps the scroll position): collapsed from the start.
	await page.evaluate( () => window.scrollTo( 0, 700 ) );
	await page.reload();
	await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
	expect( await page.evaluate( () => window.scrollY ) ).toBeGreaterThan( 120 );
	await expect( aside ).toHaveClass( /hprnb-bar--collapsed/ );
	setSettings( { mobile_layout: 'flow_image', mobile_lines: 2, rotate_interval: 1500, mobile_deep_collapse: false } );
	await page.reload();
	await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
	await page.waitForTimeout( 300 );
	await expect( aside ).not.toHaveClass( /hprnb-bar--collapsed/ );

	// Landscape phone: one line of 44px, never collapsed, buttons back.
	setSettings( { mobile_layout: 'flow_image', mobile_lines: 2, rotate_interval: 1500 } );
	await page.setViewportSize( { width: 700, height: 400 } );
	await page.goto( '/' );
	await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
	await page.evaluate( () => window.scrollTo( 0, 700 ) );
	await page.waitForTimeout( 400 );
	expect( Math.round( ( await aside.boundingBox() ).height ) ).toBe( 44 );
	await expect( aside ).not.toHaveClass( /hprnb-bar--collapsed/ );
	expect( await title.evaluate( ( el ) => getComputedStyle( el ).whiteSpace ) ).toBe( 'nowrap' );
	expect( await page.evaluate( () => getComputedStyle( document.body ).getPropertyValue( '--hprnb-offset' ).trim() ) ).toBe( '44px' );
	await expect( page.locator( '.hprnb-bar__btn--toggle' ) ).toBeVisible();
	expect( await noHorizontalOverflow( page ) ).toBe( true );

	// Desktop: 40px, pill at 15px of a 1230px container, 30 px/s marquee with edge fade, 40px buttons, Jannah offset.
	await page.setViewportSize( { width: 1366, height: 800 } );
	await page.goto( '/' );
	await expect( aside ).toHaveClass( /hprnb-bar--marquee-on/ );
	await page.evaluate( () => { const g = document.createElement( 'div' ); g.id = 'go-to-top'; g.style.cssText = 'position:fixed;right:15px;bottom:15px;width:35px;height:35px'; document.body.appendChild( g ); const r = document.createElement( 'div' ); r.id = 'reading-position-indicator'; r.style.cssText = 'position:fixed;left:0;bottom:0;height:4px;width:50%'; document.body.appendChild( r ); } );
	expect( Math.round( ( await aside.boundingBox() ).height ) ).toBe( 40 );
	const inner = await page.locator( '.hprnb-bar__inner' ).boundingBox();
	expect( Math.round( inner.width ) ).toBe( 1230 );
	expect( Math.round( ( await label.boundingBox() ).x - inner.x ) ).toBe( 15 );
	expect( Math.round( ( await label.boundingBox() ).height ) ).toBe( 24 );
	await expect( aside ).toHaveAttribute( 'data-hprnb-speed', '30' );
	expect( await page.locator( '.hprnb-bar__viewport' ).evaluate( ( el ) => getComputedStyle( el ).webkitMaskImage ) ).toContain( 'linear-gradient' );
	for ( const name of [ 'toggle', 'close' ] ) {
		expect( Math.round( ( await page.locator( `.hprnb-bar__btn--${ name }` ).boundingBox() ).height ) ).toBe( 40 );
	}
	expect( await page.evaluate( () => getComputedStyle( document.body ).getPropertyValue( '--hprnb-offset' ).trim() ) ).toBe( '40px' );
	await expect( page.locator( 'body' ) ).toHaveClass( /hprnb-theme-offset/ );
	expect( await page.evaluate( () => getComputedStyle( document.getElementById( 'go-to-top' ) ).bottom ) ).toBe( '52px' );
	expect( await page.evaluate( () => getComputedStyle( document.getElementById( 'reading-position-indicator' ) ).bottom ) ).toBe( '40px' );
	expect( await page.evaluate( () => getComputedStyle( document.getElementById( 'reading-position-indicator' ) ).zIndex ) ).toBe( '99991' );
	setSettings( { mobile_layout: 'flow_image', mobile_lines: 2, theme_offset: false, align_container: false } );
	await page.goto( '/' );
	await expect( page.locator( 'body' ) ).not.toHaveClass( /hprnb-theme-offset/ );
	expect( Math.round( ( await page.locator( '.hprnb-bar__inner' ).boundingBox() ).width ) ).toBe( 1366 );
	expect( await noHorizontalOverflow( page ) ).toBe( true );
	expect( errors ).toEqual( [] );
} );

test( 'featured image: one switch, position and size per profile, own column on the mobile card', async ( { page } ) => {
	const ids = [];
	for ( let i = 1; i <= 3; i++ ) {
		ids.push( wp( [ 'post', 'create', '--post_type=post', '--post_status=publish', `--post_title=Image ${ i } — lorem ipsum dolor sit amet consectetur adipiscing elit sed`, '--porcelain' ] ) );
	}
	const media = ids.map( ( id ) => wp( [ 'media', 'import', 'tests/e2e/fixtures/thumb.png', '--post_id=' + id, '--featured_image', '--porcelain' ] ) );
	try {
		const errors = collectErrors( page );
		const root = page.locator( '#hprnb-root' );
		const aside = page.locator( '#hprnb-root .hprnb-bar' );
		const thumb = page.locator( '.hprnb-bar__item:not([hidden]) .hprnb-bar__thumb' ).first();
		const title = page.locator( '.hprnb-bar__item:not([hidden]) .hprnb-bar__title' ).first();

		// Since 2.11 both phone designs carry the article picture, so the markup always has it; the
		// desktop profile shows it only when asked.
		setSettings( { mobile_layout: 'flow_image', mobile_lines: 2, ticker_enabled: false } );
		await page.setViewportSize( { width: 1366, height: 800 } );
		await page.goto( '/' );
		await expect( aside ).toHaveClass( /hprnb-bar--has-thumbs/ );
		await expect( root ).not.toHaveClass( /hprnb-root--d-thumb/ );
		await expect( thumb ).toBeHidden();

		// Desktop on: the picture before the headline.
		setSettings( { mobile_layout: 'flow_image', mobile_lines: 2, desktop_show_thumbnail: true, ticker_enabled: false } );
		await page.goto( '/' );
		await expect( root ).toHaveClass( /hprnb-root--d-thumb(\s|$)/ );
		await expect( thumb ).toBeVisible();
		const size = await thumb.boundingBox();
		expect( Math.round( size.width ) ).toBe( 32 );
		expect( Math.round( size.height ) ).toBe( 32 );
		expect( size.x ).toBeLessThan( ( await title.boundingBox() ).x, 'Default position: before the headline.' );
		expect( Math.round( ( await aside.boundingBox() ).height ) ).toBe( 40 );

		// Desktop, after the headline and bigger: the bar grows with it.
		setSettings( { mobile_layout: 'flow_image', mobile_lines: 2, desktop_show_thumbnail: true, desktop_thumb_position: 'after', desktop_thumb_size: 56, ticker_enabled: false } );
		await page.goto( '/' );
		await expect( root ).toHaveClass( /hprnb-root--d-thumb-after/ );
		expect( ( await thumb.boundingBox() ).x ).toBeGreaterThan( ( await title.boundingBox() ).x );
		expect( Math.round( ( await thumb.boundingBox() ).width ) ).toBe( 56 );
		expect( Math.round( ( await aside.boundingBox() ).height ) ).toBe( 64, '56 + 12 − 4' );
		expect( await page.evaluate( () => parseFloat( getComputedStyle( document.body ).paddingBottom ) ) ).toBe( 64 );
		expect( await noHorizontalOverflow( page ) ).toBe( true );

		// Mobile: the bar with the article picture, whatever the image switch says.
		await page.setViewportSize( { width: 375, height: 667 } );
		// Oversized image: clamped to the headline block, the card never grows.
		setSettings( { mobile_layout: 'flow_image', mobile_lines: 2, mobile_thumb_size: 80, rotate_interval: 60000 } );
		await page.goto( '/' );
		await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
		expect( Math.round( ( await thumb.boundingBox() ).height ) ).toBe( 50, '2 × 26 − 2' );
		expect( Math.round( ( await aside.boundingBox() ).height ) ).toBe( 76 );
		expect( ( await thumb.boundingBox() ).x ).toBeGreaterThan( 200, 'At the end of the line, where the buttons were.' );
		await expect( root ).not.toHaveClass( /hprnb-root--d-thumb/ );

		// Collapsed strip: the image is resized to a single line (kept by default).
		await page.evaluate( () => window.scrollTo( 0, 700 ) );
		await expect( aside ).toHaveClass( /hprnb-bar--collapsed/ );
		await expect( thumb ).toBeVisible();
		await expect.poll( async () => Math.round( ( await thumb.boundingBox() ).height ) ).toBe( 22 ); // One line (26) minus 4, once the fold has run.
		expect( await noHorizontalOverflow( page ) ).toBe( true );
		expect( errors ).toEqual( [] );
	} finally {
		media.forEach( ( id ) => wp( [ 'post', 'delete', id, '--force' ] ) );
		ids.forEach( ( id ) => wp( [ 'post', 'delete', id, '--force' ] ) );
		setSettings( { mobile_layout: 'flow', mobile_lines: 2 } );
	}
} );

test( 'mobile: pulsing pill with its text, compact pill, picture kept in the folded strip — each one configurable', async ( { page } ) => {
	// The buttons now live in the tab of both designs (2.11): their placements inside the bar left
	// with the flowing bar without picture.
	const ids = [];
	for ( let i = 1; i <= 3; i++ ) {
		ids.push( wp( [ 'post', 'create', '--post_type=post', '--post_status=publish', `--post_title=Strip ${ i } — lorem ipsum dolor sit amet consectetur adipiscing elit sed do`, '--porcelain' ] ) );
	}
	const media = ids.map( ( id ) => wp( [ 'media', 'import', 'tests/e2e/fixtures/thumb.png', '--post_id=' + id, '--featured_image', '--porcelain' ] ) );
	try {
		const errors = collectErrors( page );
		const root = page.locator( '#hprnb-root' );
		const aside = page.locator( '#hprnb-root .hprnb-bar' );
		const label = page.locator( '.hprnb-bar__label' );
		const thumb = page.locator( '.hprnb-bar__item:not([hidden]) .hprnb-bar__thumb' ).first();
		const viewport = page.locator( '.hprnb-bar__viewport' );
		await page.setViewportSize( { width: 375, height: 667 } );

		// Defaults: full pill pulsing with its text on arrival, picture kept in the strip.
		setSettings( { mobile_layout: 'flow_image', mobile_lines: 2, rotate_interval: 60000 } );
		await page.goto( '/' );
		await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
		await expect( root ).toHaveClass( /hprnb-root--m-pulse-appear/ );
		expect( await label.evaluate( ( el ) => getComputedStyle( el ).animationIterationCount ) ).toBe( '3' );
		await expect( root ).toHaveClass( /hprnb-root--m-peek-thumb/ );
		expect( await label.evaluate( ( el ) => getComputedStyle( el ).animationName ) ).toBe( 'hprnb-beacon' );
		expect( await page.locator( '.hprnb-bar__label-text' ).evaluate( ( el ) => getComputedStyle( el ).opacity ) ).toBe( '1', 'The open bar keeps the red pill and its text.' );
		expect( Math.round( ( await aside.boundingBox() ).height ) ).toBe( 76 );

		// Folded: the picture stays at the end of the line, one line high (26 − 4), against the gutter.
		await page.evaluate( () => window.scrollTo( 0, 700 ) );
		await expect( aside ).toHaveClass( /hprnb-bar--collapsed/ );
		await expect( thumb ).toBeVisible();
		await expect.poll( async () => Math.round( ( await thumb.boundingBox() ).height ) ).toBe( 22 );
		const strip = await thumb.boundingBox();
		expect( Math.round( strip.width ) ).toBe( 22 );
		expect( Math.round( strip.x + strip.width ) ).toBe( 360 );
		expect( ( await viewport.boundingBox() ).x + ( await viewport.boundingBox() ).width ).toBeLessThan( strip.x );

		// No picture in the strip, pulse only when folded.
		setSettings( { mobile_layout: 'flow_image', mobile_lines: 2, rotate_interval: 60000, mobile_peek_thumbnail: false, mobile_label_pulse: 'collapsed' } );
		await page.goto( '/' );
		await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
		expect( await label.evaluate( ( el ) => getComputedStyle( el ).animationName ) ).toBe( 'none' );
		await page.evaluate( () => window.scrollTo( 0, 700 ) );
		await expect( aside ).toHaveClass( /hprnb-bar--collapsed/ );
		expect( await label.evaluate( ( el ) => getComputedStyle( el ).animationName ) ).toBe( 'hprnb-beacon' );
		await expect( thumb ).toBeHidden();

		// Never: no pulse at all. And the compact pill option, off by default.
		setSettings( { mobile_layout: 'flow_image', mobile_lines: 2, mobile_show_thumbnail: true, rotate_interval: 60000, mobile_label_pulse: 'never', mobile_label_compact: true } );
		await page.goto( '/' );
		await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
		await expect( root ).toHaveClass( /hprnb-root--m-label-compact/ );
		expect( await page.locator( '.hprnb-bar__label-text' ).evaluate( ( el ) => getComputedStyle( el ).opacity + ' ' + Math.round( el.getBoundingClientRect().width ) ) ).toBe( '0 0' );
		expect( await label.evaluate( ( el ) => getComputedStyle( el ).animationName ) ).toBe( 'none' );
		await page.evaluate( () => window.scrollTo( 0, 700 ) );
		await expect( aside ).toHaveClass( /hprnb-bar--collapsed/ );
		expect( await label.evaluate( ( el ) => getComputedStyle( el ).animationName ) ).toBe( 'none' );
		expect( await noHorizontalOverflow( page ) ).toBe( true );
		expect( errors ).toEqual( [] );
	} finally {
		media.forEach( ( id ) => wp( [ 'post', 'delete', id, '--force' ] ) );
		ids.forEach( ( id ) => wp( [ 'post', 'delete', id, '--force' ] ) );
		setSettings( { mobile_layout: 'flow', mobile_lines: 2 } );
	}
} );

test( 'v2.3: reveal threshold, collapse triggers, buttons outside or hidden, accent edge, pause on touch', async ( { page, browser } ) => {
	const errors = collectErrors( page );
	const root = page.locator( '#hprnb-root' );
	const aside = page.locator( '#hprnb-root .hprnb-bar' );
	const viewport = page.locator( '.hprnb-bar__viewport' );
	const peek = () => page.evaluate( () => {
		const rect = document.querySelector( '.hprnb-bar' ).getBoundingClientRect();
		return Math.round( window.innerHeight - rect.top );
	} );
	await page.setViewportSize( { width: 375, height: 667 } );

	// The bar waits for 400px of scrolling: nothing is shown and no space is reserved until then.
	setSettings( { mobile_layout: 'flow', mobile_lines: 2, mobile_reveal_mode: 'scroll', desktop_reveal_mode: 'scroll', mobile_reveal_value: 400, desktop_reveal_value: 400, rotate_interval: 60000 } );
	await page.goto( '/' );
	await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
	await expect( root ).toHaveClass( /hprnb-root--m-pending/ );
	await expect( page.locator( 'body' ) ).toHaveClass( /hprnb-m-pending/ );
	expect( await page.evaluate( () => getComputedStyle( document.body ).getPropertyValue( '--hprnb-offset' ).trim() ) ).toBe( '0px' );
	expect( await page.evaluate( () => getComputedStyle( document.body ).paddingBottom ) ).toBe( '0px' );
	expect( await page.evaluate( () => window.hprnbBar.state().offset ) ).toBe( 0 );
	expect( await peek() ).toBeLessThanOrEqual( 0, 'Out of view.' );

	// Past the threshold it comes in and reserves its space for good.
	await page.evaluate( () => window.scrollTo( 0, 500 ) );
	await expect( root ).not.toHaveClass( /hprnb-root--m-pending/ );
	await expect( page.locator( 'body' ) ).not.toHaveClass( /hprnb-m-pending/ );
	await expect.poll( peek ).toBeGreaterThan( 0 );
	expect( await page.evaluate( () => parseInt( getComputedStyle( document.body ).paddingBottom, 10 ) ) ).toBeGreaterThan( 0 );
	await page.evaluate( () => window.scrollTo( 0, 0 ) );
	await expect( root ).not.toHaveClass( /hprnb-root--pending/, 'Once shown it stays.' );

	// A percentage of the page works the same way.
	setSettings( { mobile_layout: 'flow', mobile_lines: 2, mobile_reveal_mode: 'percent', desktop_reveal_mode: 'percent', mobile_reveal_value: 50, desktop_reveal_value: 50, rotate_interval: 60000 } );
	await page.goto( '/' );
	await expect( root ).toHaveClass( /hprnb-root--m-pending/ );
	await page.evaluate( () => window.scrollTo( 0, document.documentElement.scrollHeight ) );
	await expect( root ).not.toHaveClass( /hprnb-root--m-pending/ );

	// Collapse on a threshold: it waits for 300px, then stays collapsed on the way back up.
	setSettings( { mobile_layout: 'flow', mobile_lines: 2, mobile_collapse_mode: 'threshold', mobile_collapse_after: 300, rotate_interval: 60000 } );
	await page.goto( '/' );
	await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
	await page.evaluate( () => window.scrollTo( 0, 200 ) );
	await expect( aside ).not.toHaveClass( /hprnb-bar--collapsed/ );
	await page.evaluate( () => window.scrollTo( 0, 400 ) );
	await expect( aside ).toHaveClass( /hprnb-bar--collapsed/ );
	await page.evaluate( () => window.scrollTo( 0, 350 ) );
	await expect( aside ).toHaveClass( /hprnb-bar--collapsed/ );

	// Always collapsed: the strip is the default state and a tap opens the card.
	setSettings( { mobile_layout: 'flow', mobile_lines: 2, mobile_collapse_mode: 'immediate', rotate_interval: 60000 } );
	await page.goto( '/' );
	await expect( aside ).toHaveClass( /hprnb-bar--collapsed/ );
	await page.locator( '.hprnb-bar__btn--expand' ).click();
	await expect( aside ).not.toHaveClass( /hprnb-bar--collapsed/ );

	// Collapsing turned off entirely: no class, no chevron, whatever the scrolling.
	setSettings( { mobile_layout: 'flow', mobile_lines: 2, mobile_hide_on_scroll: false, rotate_interval: 60000 } );
	await page.goto( '/' );
	await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
	await expect( root ).not.toHaveClass( /hprnb-root--m-collapse/ );
	await page.evaluate( () => window.scrollTo( 0, 900 ) );
	await expect( aside ).not.toHaveClass( /hprnb-bar--collapsed/ );
	await expect( page.locator( '.hprnb-bar__btn--expand' ) ).toHaveCount( 0 );

	// The buttons sit in the tab above the corner (2.11, both designs): painted, clickable, and the
	// headline keeps the width up to the picture.
	setSettings( { mobile_layout: 'flow_image', mobile_lines: 2, rotate_interval: 60000 } );
	await page.goto( '/' );
	await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
	await expect( root ).toHaveClass( /hprnb-root--m-ctrl-tab/ );
	const barBox = await aside.boundingBox();
	const tabClose = await page.locator( '.hprnb-bar__btn--close' ).boundingBox();
	const tabToggle = await page.locator( '.hprnb-bar__btn--toggle' ).boundingBox();
	expect( Math.round( tabClose.y + tabClose.height ) ).toBe( Math.round( barBox.y ), 'Resting on the top edge.' );
	expect( Math.round( tabToggle.y ) ).toBe( Math.round( tabClose.y ), 'Side by side.' );
	// Outside the bar, which clips its own overflow: they must really be painted, not just
	// positioned — a hit test at their centre catches the clipping the bounding box cannot see.
	const painted = ( selector ) => page.evaluate( ( sel ) => {
		const el = document.querySelector( sel );
		const rect = el.getBoundingClientRect();
		const top = document.elementFromPoint( rect.x + rect.width / 2, rect.y + rect.height / 2 );
		return el === top || el.contains( top );
	}, selector );
	expect( await painted( '.hprnb-bar__btn--close' ) ).toBe( true );
	expect( await painted( '.hprnb-bar__btn--toggle' ) ).toBe( true );
	await page.locator( '.hprnb-bar__btn--toggle' ).click();
	await expect( aside ).toHaveClass( /hprnb-bar--paused/, 'And really clickable.' );
	await page.locator( '.hprnb-bar__btn--toggle' ).click();

	// Each button can be hidden on mobile on its own; with none left the tab is empty and the theme's
	// corner elements have nothing to clear.
	setSettings( { mobile_layout: 'flow_image', mobile_lines: 2, mobile_show_pause: false, mobile_show_close: false, rotate_interval: 60000 } );
	await page.goto( '/' );
	await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
	await expect( page.locator( '.hprnb-bar__btn--toggle' ) ).toBeHidden();
	await expect( page.locator( '.hprnb-bar__btn--close' ) ).toBeHidden();
	expect( await page.evaluate( () => getComputedStyle( document.body ).getPropertyValue( '--hprnb-tab' ).trim() ) ).toBe( '0px' );

	// The accent edge, on by default and switchable.
	const edge = () => aside.evaluate( ( el ) => getComputedStyle( el ).boxShadow );
	expect( await edge() ).toContain( 'rgb(206, 48, 41) 0px 2px 0px 0px inset' );
	setSettings( { mobile_layout: 'flow', mobile_lines: 2, accent_edge: false, rotate_interval: 60000 } );
	await page.goto( '/' );
	await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
	expect( await edge() ).not.toContain( 'rgb(206, 48, 41) 0px 2px 0px 0px inset' );
	expect( await noHorizontalOverflow( page ) ).toBe( true );
	expect( errors ).toEqual( [] );

	// Touch: tapping Pause twice really resumes — the emulated hover must not keep it paused.
	setSettings( { mobile_layout: 'flow', mobile_lines: 2, rotate_interval: 3000 } );
	const touch = await browser.newContext( { viewport: { width: 375, height: 667 }, hasTouch: true, isMobile: true } );
	const tap = await touch.newPage();
	try {
		const touchErrors = collectErrors( tap );
		await tap.goto( 'http://127.0.0.1:8080/' );
		const touchBar = tap.locator( '#hprnb-root .hprnb-bar' );
		await expect( touchBar ).toHaveAttribute( 'data-hprnb-init', '1' );
		expect( await tap.evaluate( () => window.matchMedia( '(hover: hover) and (pointer: fine)' ).matches ) ).toBe( false );
		const first = await tap.locator( '.hprnb-bar__item:not([hidden]) .hprnb-bar__link' ).first().innerText();
		await tap.locator( '.hprnb-bar__btn--toggle' ).tap();
		await expect( touchBar ).toHaveClass( /hprnb-bar--paused/ );
		await tap.locator( '.hprnb-bar__btn--toggle' ).tap();
		await expect( touchBar ).not.toHaveClass( /hprnb-bar--paused/ );
		await expect( tap.locator( '.hprnb-bar__btn--toggle' ) ).toHaveAttribute( 'aria-label', /Pause/ );
		await expect
			.poll( async () => tap.locator( '.hprnb-bar__item:not([hidden]) .hprnb-bar__link' ).first().innerText(), { timeout: 15000 } )
			.not.toBe( first );
		expect( touchErrors ).toEqual( [] );
	} finally {
		await touch.close();
		setSettings( { mobile_layout: 'flow', mobile_lines: 2 } );
	}
} );

test( 'v2.4: page types per profile, the bar inside the article, desktop collapse, the "discover" card', async ( { page } ) => {
	const paragraphs = Array.from( { length: 8 }, ( _, i ) =>
		`<p>Paragraphe ${ i + 1 }. Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor.</p>` ).join( '\n' );
	// Created first, so the newest posts — the ones the bar lists — all carry a picture.
	const article = wp( [ 'post', 'create', '--post_type=post', '--post_status=publish', '--post_title=Article de placement', `--post_content=${ paragraphs }`, '--porcelain' ] );
	const url = new URL( wp( [ 'post', 'url', article ] ) ).pathname;
	const ids = [];
	for ( let i = 1; i <= 3; i++ ) {
		ids.push( wp( [ 'post', 'create', '--post_type=post', '--post_status=publish', `--post_title=Le conseil vote le budget ${ i } et lance les travaux du quartier`, '--porcelain' ] ) );
	}
	const media = ids.map( ( id ) => wp( [ 'media', 'import', 'tests/e2e/fixtures/thumb.png', '--post_id=' + id, '--featured_image', '--porcelain' ] ) );

	/** Paragraphs of the article standing before the root in document order. */
	const paragraphsBefore = () => page.evaluate( () => {
		const root = document.querySelector( '#hprnb-root' );
		return Array.from( document.querySelectorAll( '.wp-block-post-content p, .entry-content p' ) )
			.filter( ( p ) => p.compareDocumentPosition( root ) & Node.DOCUMENT_POSITION_FOLLOWING ).length;
	} );
	const reserved = () => page.evaluate( () => ( {
		offset: getComputedStyle( document.body ).getPropertyValue( '--hprnb-offset' ).trim(),
		pad: getComputedStyle( document.body ).paddingBottom,
		position: getComputedStyle( document.querySelector( '.hprnb-bar' ) ).position,
		width: Math.round( document.querySelector( '#hprnb-root' ).getBoundingClientRect().width ),
		left: Math.round( document.querySelector( '#hprnb-root' ).getBoundingClientRect().left ),
	} ) );

	try {
		const errors = collectErrors( page );
		const root = page.locator( '#hprnb-root' );
		const aside = page.locator( '#hprnb-root .hprnb-bar' );

		// Page types per profile: the front page is dropped for the desktop only.
		await page.setViewportSize( { width: 1366, height: 800 } );
		setSettings( { desktop_contexts: { ...defaults.desktop_contexts, front_page: false } } );
		await page.goto( '/' );
		await expect( root ).toHaveClass( /hprnb-hide-desktop/ );
		await expect( root ).not.toHaveClass( /hprnb-device-all/ );
		expect( await page.evaluate( () => getComputedStyle( document.querySelector( '#hprnb-root' ) ).display ) ).toBe( 'none' );
		expect( await page.evaluate( () => getComputedStyle( document.body ).paddingBottom ) ).toBe( '0px' );
		// The article is still allowed, so the bar comes back there.
		await page.goto( url );
		await expect( root ).toHaveClass( /hprnb-device-all/ );

		// Both profiles refused: nothing of the news bar is rendered. Since 2.15 the URGENT bar has page
		// types of its own, so an empty, hidden root waits there for an urgent article; with the URGENT
		// bar switched off, nothing at all.
		setSettings( {
			desktop_contexts: { ...defaults.desktop_contexts, front_page: false },
			mobile_contexts: { ...defaults.mobile_contexts, front_page: false },
		} );
		await page.goto( '/' );
		await expect( root ).toHaveAttribute( 'data-hprnb-show', 'urgent' );
		await expect( root ).toBeHidden();
		await expect( page.locator( '#hprnb-root .hprnb-bar' ) ).toHaveCount( 0 );
		setSettings( {
			desktop_contexts: { ...defaults.desktop_contexts, front_page: false },
			mobile_contexts: { ...defaults.mobile_contexts, front_page: false },
			urgent_enabled: false,
		} );
		await page.goto( '/' );
		await expect( root ).toHaveCount( 0 );

		// Inside the article, desktop, after the 3rd paragraph: a full-width block of the page.
		setSettings( { desktop_placement: 'inline', desktop_inline_anchor: 'after', desktop_inline_paragraph: 3, rotate_interval: 60000 } );
		await page.goto( url );
		await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
		await expect( root ).toHaveClass( /hprnb-root--d-inflow/ );
		await expect( root ).toHaveClass( /alignfull/ );
		expect( await paragraphsBefore() ).toBe( 3 );
		const inline = await reserved();
		expect( inline.position ).toBe( 'static', 'A block of the page, not pinned to the screen.' );
		expect( inline.offset ).toBe( '0px', 'It covers nothing, so it reserves nothing.' );
		expect( inline.pad ).toBe( '0px' );
		expect( inline.left ).toBe( 0, 'Full bleed even inside a constrained article column.' );
		expect( inline.width ).toBe( 1366 );
		expect( await page.evaluate( () => window.hprnbBar.state().offset ) ).toBe( 0 );
		expect( await noHorizontalOverflow( page ) ).toBe( true );

		// "Before the last N paragraphs" counts from the end.
		setSettings( { desktop_placement: 'inline', desktop_inline_anchor: 'before_end', desktop_inline_paragraph: 2, rotate_interval: 60000 } );
		await page.goto( url );
		await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
		expect( await paragraphsBefore() ).toBe( 6, '8 paragraphs, 2 left behind.' );

		// Collapsing from 768px: past the threshold the bar slides away and leaves a usable tab.
		setSettings( { desktop_hide_on_scroll: true, desktop_collapse_mode: 'threshold', desktop_collapse_after: 300, rotate_interval: 60000 } );
		await page.goto( url );
		await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
		await expect( root ).toHaveClass( /hprnb-root--d-collapse/ );
		await page.evaluate( () => window.scrollTo( 0, 150 ) );
		await expect( aside ).not.toHaveClass( /hprnb-bar--collapsed/ );
		await page.evaluate( () => window.scrollTo( 0, 500 ) );
		await expect( aside ).toHaveClass( /hprnb-bar--collapsed/ );
		expect( await page.evaluate( () => getComputedStyle( document.body ).getPropertyValue( '--hprnb-offset' ).trim() ) ).toBe( '0px' );
		// The tab must really be painted, not only positioned.
		expect( await page.evaluate( () => {
			const el = document.querySelector( '.hprnb-bar__btn--expand' );
			const rect = el.getBoundingClientRect();
			const top = document.elementFromPoint( rect.x + rect.width / 2, rect.y + rect.height / 2 );
			return el === top || el.contains( top );
		} ) ).toBe( true );
		await page.locator( '.hprnb-bar__btn--expand' ).click();
		await expect( aside ).not.toHaveClass( /hprnb-bar--collapsed/ );

		// The card on a phone — the client's reference: a label row, then a 16:9 picture at the start
		// of the line and the headline two sizes up beside it, edge to edge, and the close button in a
		// tab of the card's own colour above its end corner.
		await page.setViewportSize( { width: 390, height: 780 } );
		setSettings( { mobile_layout: 'card', rotate_interval: 60000, mobile_show_pause: false, close_button: true } );
		await page.goto( '/' );
		await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
		await expect( root ).toHaveClass( /hprnb-root--m-card/ );
		await expect( root ).not.toHaveClass( /hprnb-root--m-ctrl-col/, 'The card places its own buttons.' );
		await expect( root ).not.toHaveClass( /hprnb-root--m-float/, 'Flush by default.' );
		const thumb = page.locator( '.hprnb-bar__item:not([hidden]) .hprnb-bar__thumb' );
		await expect( thumb ).toBeVisible( { timeout: 10000 } );
		const image = await thumb.boundingBox();
		expect( Math.round( image.width ) ).toBe( 132 );
		expect( Math.round( image.height ) ).toBe( 74, '16:9 frame.' );
		const label = await page.locator( '.hprnb-bar__label' ).boundingBox();
		expect( label.width ).toBeLessThan( 200, 'The label shrink-wraps, it is not a full-width band.' );
		// Edge to edge, square corners, 12px of padding.
		const card = await aside.boundingBox();
		expect( Math.round( card.x ) ).toBe( 0 );
		expect( Math.round( card.width ) ).toBe( 390 );
		expect( await aside.evaluate( ( el ) => getComputedStyle( el ).borderTopLeftRadius ) ).toBe( '0px' );
		expect( Math.round( label.x ) ).toBe( 12, 'The label opens the card, against the padding.' );
		expect( Math.round( label.y - card.y ) ).toBe( 12, 'Top row.' );
		expect( Math.round( image.x ) ).toBe( 12, 'The picture opens the second row.' );
		expect( Math.round( image.y - card.y ) ).toBe( 40, 'Under the label row: 12 + 20 + 8.' );
		expect( image.y ).toBeGreaterThanOrEqual( label.y + label.height - 1, 'The label is a row of its own, above the picture.' );
		const headline = page.locator( '.hprnb-bar__item:not([hidden]) .hprnb-bar__title' );
		const headlineBox = await headline.boundingBox();
		expect( Math.round( headlineBox.x ) ).toBe( 154, 'Beside the picture: 12 + 132 + 10.' );
		expect( Math.abs( headlineBox.y - image.y ) ).toBeLessThanOrEqual( 2, 'Level with the top of the picture.' );
		expect( await headline.evaluate( ( el ) => getComputedStyle( el ).fontSize ) ).toBe( '18px', 'Two sizes above the 16px profile.' );
		expect( await headline.evaluate( ( el ) => getComputedStyle( el ).webkitLineClamp ) ).toBe( '3', 'Three lines, as on the reference.' );
		expect( await headline.evaluate( ( el ) => getComputedStyle( el ).fontWeight ) ).toBe( '700' );
		// 12 + 20 + 8 + max(74, 3 × 22) + 12 = 126: the picture drives it.
		expect( Math.round( card.height ) ).toBe( 126 );
		expect( await page.evaluate( () => getComputedStyle( document.body ).getPropertyValue( '--hprnb-offset' ).trim() ) ).toBe( '126px' );
		// The close button is a 44px tab ABOVE the card, against its end corner, of the card's colour.
		const tab = page.locator( '.hprnb-bar__btn--close' );
		const tabBox = await tab.boundingBox();
		expect( Math.round( tabBox.width ) ).toBe( 44 );
		expect( Math.round( tabBox.height ) ).toBe( 44 );
		expect( Math.round( tabBox.y + tabBox.height ) ).toBe( Math.round( card.y ), 'Sitting on the top edge of the card.' );
		expect( Math.round( tabBox.x + tabBox.width ) ).toBe( 390, 'Against the end corner.' );
		expect( await page.locator( '.hprnb-bar__controls' ).evaluate( ( el ) => getComputedStyle( el ).backgroundColor ) ).toBe( 'rgb(27, 28, 32)', 'The tab is the card\'s own colour.' );
		const overlaps = ( a, b ) => a.x < b.x + b.width && b.x < a.x + a.width && a.y < b.y + b.height && b.y < a.y + a.height;
		expect( overlaps( tabBox, headlineBox ) ).toBe( false, 'Outside the card: the headline never runs under it.' );
		expect( overlaps( tabBox, label ) ).toBe( false );
		await expect( page.locator( '.hprnb-bar__btn--toggle' ) ).toBeHidden( 'Alone: no pause button.' );
		expect( await page.evaluate( () => {
			const el = document.querySelector( '.hprnb-bar__btn--close' );
			const rect = el.getBoundingClientRect();
			const top = document.elementFromPoint( rect.x + rect.width / 2, rect.y + rect.height / 2 );
			return el === top || el.contains( top );
		} ) ).toBe( true, 'Painted, not merely positioned.' );
		// Folded: the pulsing dot, the first line and (2.11) the small 16:9 picture at the end, with the
		// unfold button in the tab above the corner, where the cross was.
		await page.evaluate( () => window.scrollTo( 0, 900 ) );
		await expect( aside ).toHaveClass( /hprnb-bar--collapsed/ );
		expect( await page.evaluate( () => getComputedStyle( document.body ).getPropertyValue( '--hprnb-offset' ).trim() ) ).toBe( '36px', 'One line and its padding.' );
		await expect.poll( async () => Math.round( ( await page.locator( '.hprnb-bar__label' ).boundingBox() ).width ) ).toBe( 24 ); // The pill closes onto its dot.
		await page.waitForTimeout( 600 );
		const dot = await page.locator( '.hprnb-bar__label' ).boundingBox();
		expect( Math.round( dot.x ) ).toBe( 12, 'Against the card padding, on the left.' );
		expect( await page.locator( '.hprnb-bar__label' ).evaluate( ( el ) => getComputedStyle( el ).animationName ) ).toBe( 'hprnb-beacon' );
		expect( await page.locator( '.hprnb-bar__label-text' ).evaluate( ( el ) => getComputedStyle( el ).opacity ) ).toBe( '0' );
		const strip = await headline.boundingBox();
		expect( strip.x ).toBeGreaterThan( dot.x + dot.width, 'The first line of the headline runs beside it.' );
		expect( Math.round( strip.height ) ).toBe( 22, 'One line.' );
		await expect( thumb ).toBeVisible();
		const small = await thumb.boundingBox();
		expect( [ Math.round( small.width ), Math.round( small.height ) ] ).toEqual( [ 32, 18 ], 'One line high, still 16:9.' );
		expect( Math.round( small.x + small.width ) ).toBe( 378, 'At the end of the strip.' );
		expect( strip.x + strip.width ).toBeLessThanOrEqual( small.x );
		expect( await tab.evaluate( ( el ) => getComputedStyle( el ).display ) ).toBe( 'none', 'No close button in the folded state.' );
		const unfold = await page.locator( '.hprnb-bar__btn--expand' ).boundingBox();
		const folded = await aside.boundingBox();
		expect( Math.round( unfold.y + unfold.height ) ).toBe( Math.round( folded.y ), 'The unfold button sits in the tab above the strip.' );
		expect( Math.round( unfold.x + unfold.width ) ).toBe( 390 );
		await page.evaluate( () => window.scrollTo( 0, 0 ) );
		await expect( aside ).not.toHaveClass( /hprnb-bar--collapsed/ );
		await tab.click();
		await expect( aside ).toBeHidden( 'The tab really closes the bar.' );
		// The dismissal is remembered for 24h: forget it before the next page.
		await page.evaluate( () => { try { localStorage.clear(); } catch ( e ) {} } );

		// Floating is an option: 8px clear of the edges, rounded, the tab rounded with it.
		setSettings( { mobile_layout: 'card', mobile_card_float: true, rotate_interval: 60000, mobile_show_pause: false, close_button: true } );
		await page.goto( '/' );
		await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
		await expect( root ).toHaveClass( /hprnb-root--m-float/ );
		const floating = await aside.boundingBox();
		expect( Math.round( floating.x ) ).toBe( 8 );
		expect( Math.round( floating.width ) ).toBe( 374 );
		expect( Math.round( floating.height ) ).toBe( 126, 'Same card, floating.' );
		expect( await aside.evaluate( ( el ) => getComputedStyle( el ).borderBottomLeftRadius ) ).toBe( '12px' );
		expect( await page.evaluate( () => getComputedStyle( document.body ).getPropertyValue( '--hprnb-offset' ).trim() ) ).toBe( '134px', 'The gap counts in the reserved space.' );
		expect( Math.round( ( await tab.boundingBox() ).x + 44 ) ).toBe( 382, 'The tab follows the card\'s end corner.' );
		await page.evaluate( () => { try { localStorage.clear(); } catch ( e ) {} } );

		// Mirrored on an RTL site with an Arabic heading: picture on the right, tab on the left.
		setSettings( { mobile_layout: 'card', rotate_interval: 60000, mobile_show_pause: false, close_button: true, label_text: 'اكتشف المزيد', mobile_label_style: 'strip' } );
		await page.goto( '/?hprnb_rtl=1' );
		await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
		expect( await aside.evaluate( ( el ) => getComputedStyle( el ).direction ) ).toBe( 'rtl' );
		const rtlImage = await thumb.boundingBox();
		const rtlHeadline = await headline.boundingBox();
		expect( rtlImage.x ).toBeGreaterThan( rtlHeadline.x + rtlHeadline.width, 'Picture at the start, which is the right.' );
		expect( Math.round( rtlImage.x + rtlImage.width ) ).toBe( 378, 'Against the right padding.' );
		expect( Math.round( ( await tab.boundingBox() ).x ) ).toBe( 0, 'The tab keeps the end corner: the left.' );
		const rtlLabel = page.locator( '.hprnb-bar__label' );
		expect( await rtlLabel.evaluate( ( el ) => getComputedStyle( el ).backgroundColor ) ).toBe( 'rgba(0, 0, 0, 0)', 'The strip style is a plain bold heading on the card.' );
		expect( await rtlLabel.evaluate( ( el ) => getComputedStyle( el ).fontSize ) ).toBe( '17px', 'Like "Explore More" on the reference.' );
		expect( await rtlLabel.evaluate( ( el ) => getComputedStyle( el ).fontWeight ) ).toBe( '700' );

		// The card without a picture keeps the whole width for its headline.
		media.splice( 0 ).forEach( ( id ) => wp( [ 'post', 'delete', id, '--force' ] ) );
		setSettings( { mobile_layout: 'card', rotate_interval: 60000, mobile_show_pause: false, close_button: true } );
		await page.goto( '/' );
		await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
		await expect( page.locator( '.hprnb-bar__item:not([hidden]) .hprnb-bar__thumb' ) ).toHaveCount( 0 );
		const wide = await page.locator( '.hprnb-bar__item:not([hidden]) .hprnb-bar__title' ).boundingBox();
		expect( wide.width ).toBeGreaterThan( 340, 'No picture, so its column goes back to the headline.' );
		expect( Math.round( wide.x ) ).toBe( 12 );
		expect( await noHorizontalOverflow( page ) ).toBe( true );
		expect( errors ).toEqual( [] );
	} finally {
		media.forEach( ( id ) => wp( [ 'post', 'delete', id, '--force' ] ) );
		ids.forEach( ( id ) => wp( [ 'post', 'delete', id, '--force' ] ) );
		wp( [ 'post', 'delete', article, '--force' ] );
		setSettings();
	}
} );

test( 'v2.5: a single headline carries no separator, in every ticker mode and both directions', async ( { page } ) => {
	// Only one post inside the window: the bar renders exactly one item. Its headline is made long
	// enough that the marquee really has to clone the list, which is the case worth covering.
	const keep = postIds.slice( 1 );
	keep.forEach( ( id ) => wp( [ 'post', 'update', id, '--post_status=draft' ] ) );
	const solo = postIds[ 0 ];
	const soloTitle = wp( [ 'post', 'get', solo, '--field=post_title' ] );
	wp( [ 'post', 'update', solo, '--post_title=' + 'Une seule actualité au titre délibérément très long pour que le défilement continu doive cloner sa liste et montrer la jonction '.repeat( 2 ) ] );
	try {
		const errors = collectErrors( page );
		const item = page.locator( '.hprnb-bar__item' ).first();
		const after = () => item.evaluate( ( el ) => getComputedStyle( el, '::after' ).content );

		for ( const mode of [ 'static', 'marquee', 'rotate', 'manual' ] ) {
			for ( const rtl of [ false, true ] ) {
				setSettings( {
					show_separator: true,
					separator_after_last: true,
					separator_char: '|',
					ticker_enabled: mode !== 'static',
					ticker_mode: mode === 'static' ? 'marquee' : mode,
					mobile_show_separator: true,
				} );
				await page.setViewportSize( { width: 1366, height: 800 } );
				await page.goto( rtl ? '/?hprnb_rtl=1' : '/' );
				await expect( page.locator( '#hprnb-root' ) ).toHaveAttribute( 'data-hprnb-count', '1' );
				// The marquee clone adds a second list: count the original one.
				await expect( page.locator( '.hprnb-bar__list:not(.hprnb-bar__list--clone) .hprnb-bar__item' ) ).toHaveCount( 1 );
				expect( await after() ).toBe( 'none', `${ mode }${ rtl ? ' RTL' : '' }: no separator after the only headline.` );
				if ( 'marquee' === mode ) {
					// The script clones the list: each copy is still a single item, so still none.
					await expect( page.locator( '.hprnb-bar__list' ) ).toHaveCount( 2, 'The marquee clone is there.' );
					const clone = page.locator( '.hprnb-bar__list--clone .hprnb-bar__item' ).first();
					expect( await clone.evaluate( ( el ) => getComputedStyle( el, '::after' ).content ) ).toBe( 'none' );
				}
			}
		}

		// The same settings on a phone, where the mobile separator classes apply instead.
		await page.setViewportSize( { width: 390, height: 780 } );
		setSettings( { show_separator: true, separator_after_last: true, mobile_show_separator: true, mobile_layout: 'inline', mobile_ticker_mode: 'static' } );
		await page.goto( '/' );
		await expect( page.locator( '.hprnb-bar__list:not(.hprnb-bar__list--clone) .hprnb-bar__item' ) ).toHaveCount( 1 );
		expect( await after() ).toBe( 'none', 'Mobile too.' );
		expect( errors ).toEqual( [] );
	} finally {
		wp( [ 'post', 'update', solo, '--post_title=' + soloTitle ] );
		keep.forEach( ( id ) => wp( [ 'post', 'update', id, '--post_status=publish' ] ) );
		setSettings( { mobile_layout: 'flow', mobile_lines: 2 } );
	}
} );

test( 'v2.5: two headlines keep their separators exactly as configured', async ( { page } ) => {
	const keep = postIds.slice( 2 );
	keep.forEach( ( id ) => wp( [ 'post', 'update', id, '--post_status=draft' ] ) );
	try {
		const items = page.locator( '.hprnb-bar__item' );
		const contentOf = ( index ) => items.nth( index ).evaluate( ( el ) => getComputedStyle( el, '::after' ).content );
		await page.setViewportSize( { width: 1366, height: 800 } );

		setSettings( { mobile_layout: 'flow', mobile_lines: 2, show_separator: true, separator_after_last: true, separator_char: '|' } );
		await page.goto( '/' );
		await expect( page.locator( '#hprnb-root' ) ).toHaveAttribute( 'data-hprnb-count', '2' );
		await expect( items ).toHaveCount( 2 );
		expect( await contentOf( 0 ) ).toContain( '|' );
		expect( await contentOf( 1 ) ).toContain( '|', 'separator_after_last still adds the loop junction.' );

		setSettings( { mobile_layout: 'flow', mobile_lines: 2, show_separator: true, separator_after_last: false, separator_char: '|' } );
		await page.goto( '/' );
		expect( await contentOf( 0 ) ).toContain( '|' );
		expect( await contentOf( 1 ) ).toBe( 'none', 'Without the option, nothing after the last one.' );

		setSettings( { mobile_layout: 'flow', mobile_lines: 2, show_separator: false } );
		await page.goto( '/' );
		expect( await contentOf( 0 ) ).toBe( 'none' );
		expect( await contentOf( 1 ) ).toBe( 'none' );
	} finally {
		keep.forEach( ( id ) => wp( [ 'post', 'update', id, '--post_status=publish' ] ) );
		setSettings( { mobile_layout: 'flow', mobile_lines: 2 } );
	}
} );

test( 'v2.5: smart reveal — the end of the article, a real scroll back up, an engaged reader', async ( { page } ) => {
	const body = Array.from( { length: 40 }, ( _, i ) =>
		`<p>Paragraphe ${ i + 1 }. Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.</p>` ).join( '\n' );
	const article = wp( [ 'post', 'create', '--post_type=post', '--post_status=publish', '--post_title=Article long pour le mode intelligent', `--post_content=${ body }`, '--porcelain' ] );
	const brief = wp( [ 'post', 'create', '--post_type=post', '--post_status=publish', '--post_title=Brève', '--post_content=<p>Deux phrases.</p><p>Pas plus.</p>', '--porcelain' ] );
	const url = new URL( wp( [ 'post', 'url', article ] ) ).pathname;
	const briefUrl = new URL( wp( [ 'post', 'url', brief ] ) ).pathname;

	const root = page.locator( '#hprnb-root' );
	const pending = () => page.evaluate( () => document.querySelector( '#hprnb-root' ).classList.contains( 'hprnb-root--' + ( window.innerWidth < 768 ? 'm' : 'd' ) + '-pending' ) );
	const layer = () => page.evaluate( () => ( window.dataLayer || [] ).map( ( r ) => ( { event: r.event, reason: r.trigger_reason, found: r.article_found } ) ) );
	await page.addInitScript( () => { window.dataLayer = []; } );

	try {
		const errors = collectErrors( page );
		await page.setViewportSize( { width: 390, height: 700 } );

		// Nothing at the top of the article: no bar, no reserved space.
		setSettings( { mobile_layout: 'flow', mobile_lines: 2, mobile_reveal_mode: 'smart', desktop_reveal_mode: 'smart', rotate_interval: 60000 } );
		await page.goto( url );
		await expect( page.locator( '.hprnb-bar' ) ).toHaveAttribute( 'data-hprnb-init', '1' );
		expect( await pending() ).toBe( true );
		expect( await page.evaluate( () => getComputedStyle( document.body ).getPropertyValue( '--hprnb-offset' ).trim() ) ).toBe( '0px' );
		expect( await page.evaluate( () => window.hprnbBar.state().offset ) ).toBe( 0 );
		expect( await layer() ).toEqual( [], 'No impression before the bar is shown.' );

		// 1. The end of the editorial body — the signal worth waiting for.
		await page.evaluate( () => document.querySelector( '.entry-content, .wp-block-post-content' ).scrollIntoView( { block: 'end' } ) );
		await expect.poll( pending ).toBe( false );
		await expect( root ).not.toHaveClass( /hprnb-root--m-pending/ );
		expect( ( await layer() ).filter( ( e ) => e.event === 'hprnb_impression' ) ).toEqual( [ { event: 'hprnb_impression', reason: 'article_end', found: true } ] );
		// And only once: scrolling on does not fire a second time.
		await page.evaluate( () => window.scrollTo( 0, 0 ) );
		await page.evaluate( () => window.scrollTo( 0, document.documentElement.scrollHeight ) );
		expect( ( await layer() ).filter( ( e ) => e.event === 'hprnb_impression' ) ).toHaveLength( 1 );

		// 2. A real scroll back up after reading a good share of it.
		setSettings( { mobile_layout: 'flow', mobile_lines: 2, mobile_reveal_mode: 'smart', desktop_reveal_mode: 'smart', rotate_interval: 60000, smart_mobile_time: 2, smart_mobile_fallback_time: 120 } );
		await page.goto( url );
		await expect( page.locator( '.hprnb-bar' ) ).toHaveAttribute( 'data-hprnb-init', '1' );
		await page.evaluate( () => window.scrollTo( 0, document.documentElement.scrollHeight * 0.55 ) );
		await page.waitForTimeout( 2600 );
		expect( await pending() ).toBe( true, 'Reading deep is not enough on its own.' );
		await page.evaluate( () => window.scrollBy( 0, -40 ) );
		await page.waitForTimeout( 120 );
		expect( await pending() ).toBe( true, 'Nor is a 40px nudge.' );
		for ( let i = 0; i < 12; i++ ) {
			await page.evaluate( () => window.scrollBy( 0, -30 ) );
			await page.waitForTimeout( 45 );
		}
		await expect.poll( pending ).toBe( false );
		expect( ( await layer() ).filter( ( e ) => e.event === 'hprnb_impression' )[ 0 ].reason ).toBe( 'scroll_up_intent' );

		// 3. A reader who never scrolled back up but got deep into it (desktop tuning).
		await page.setViewportSize( { width: 1366, height: 800 } );
		setSettings( { mobile_layout: 'flow', mobile_lines: 2, mobile_reveal_mode: 'smart', desktop_reveal_mode: 'smart', rotate_interval: 60000, smart_desktop_fallback: 40, smart_desktop_fallback_time: 3, smart_desktop_up: 1200 } );
		await page.goto( url );
		await expect( page.locator( '.hprnb-bar' ) ).toHaveAttribute( 'data-hprnb-init', '1' );
		await page.evaluate( () => window.scrollTo( 0, document.documentElement.scrollHeight * 0.45 ) );
		await page.waitForTimeout( 900 );
		expect( await pending() ).toBe( true, 'Deep enough, not long enough yet.' );
		await expect.poll( pending, { timeout: 15000 } ).toBe( false );
		expect( ( await layer() ).filter( ( e ) => e.event === 'hprnb_impression' )[ 0 ].reason ).toBe( 'engaged_reader' );

		// 4. A very short piece: only its end may fire, never the engagement fallback.
		await page.setViewportSize( { width: 390, height: 700 } );
		setSettings( { mobile_layout: 'flow', mobile_lines: 2, mobile_reveal_mode: 'smart', desktop_reveal_mode: 'smart', rotate_interval: 60000, smart_mobile_fallback: 10, smart_mobile_fallback_time: 1 } );
		await page.goto( briefUrl );
		await expect( page.locator( '.hprnb-bar' ) ).toHaveAttribute( 'data-hprnb-init', '1' );
		await expect.poll( pending ).toBe( false );
		expect( ( await layer() ).filter( ( e ) => e.event === 'hprnb_impression' )[ 0 ].reason ).toBe( 'article_end' );

		// 5. A dismissal is never undone by a smart signal, and it reports the reason it came from.
		setSettings( { mobile_layout: 'flow', mobile_lines: 2, mobile_reveal_mode: 'smart', desktop_reveal_mode: 'smart', rotate_interval: 60000, close_button: true, remember_dismiss: false, mobile_hide_on_scroll: false } );
		await page.goto( url );
		await expect( page.locator( '.hprnb-bar' ) ).toHaveAttribute( 'data-hprnb-init', '1' );
		await page.evaluate( () => document.querySelector( '.entry-content, .wp-block-post-content' ).scrollIntoView( { block: 'end' } ) );
		await expect.poll( pending ).toBe( false );
		await page.locator( '.hprnb-bar__btn--close' ).click();
		await expect( page.locator( '.hprnb-bar' ) ).toBeHidden();
		await page.evaluate( () => window.scrollTo( 0, 0 ) );
		await page.evaluate( () => window.scrollTo( 0, document.documentElement.scrollHeight ) );
		await page.waitForTimeout( 600 );
		await expect( page.locator( '.hprnb-bar' ) ).toBeHidden( 'Closed stays closed.' );
		const closed = await layer();
		expect( closed[ closed.length - 1 ] ).toEqual( { event: 'hprnb_close', reason: 'article_end', found: undefined } );

		// 6. The four original modes are untouched and report themselves.
		setSettings( { mobile_layout: 'flow', mobile_lines: 2, mobile_reveal_mode: 'scroll', desktop_reveal_mode: 'scroll', mobile_reveal_value: 400, desktop_reveal_value: 400, rotate_interval: 60000 } );
		await page.goto( url );
		await expect( page.locator( '.hprnb-bar' ) ).toHaveAttribute( 'data-hprnb-init', '1' );
		expect( await pending() ).toBe( true );
		await page.evaluate( () => window.scrollTo( 0, 600 ) );
		await expect.poll( pending ).toBe( false );
		expect( ( await layer() ).filter( ( e ) => e.event === 'hprnb_impression' )[ 0 ].reason ).toBe( 'legacy_scroll' );

		setSettings( { mobile_layout: 'flow', mobile_lines: 2, mobile_reveal_mode: 'immediate', desktop_reveal_mode: 'immediate', rotate_interval: 60000 } );
		await page.goto( url );
		await expect( page.locator( '.hprnb-bar' ) ).toHaveAttribute( 'data-hprnb-init', '1' );
		expect( await pending() ).toBe( false );
		expect( ( await layer() ).filter( ( e ) => e.event === 'hprnb_impression' )[ 0 ].reason ).toBe( 'legacy_immediate' );
		expect( errors ).toEqual( [] );
	} finally {
		wp( [ 'post', 'delete', article, '--force' ] );
		wp( [ 'post', 'delete', brief, '--force' ] );
		setSettings( { mobile_layout: 'flow', mobile_lines: 2 } );
	}
} );

test( 'php mode and empty states', async ( { page } ) => {
	setSettings( { mobile_layout: 'flow', mobile_lines: 2, render_mode: 'php' } );
	await page.goto( '/' );
	await expect( page.locator( '#hprnb-root .hprnb-bar' ) ).toBeVisible();
	expect( await page.locator( 'script#hprnb-bootstrap-js' ).count() ).toBe( 0 );
	expect( await page.locator( '#hprnb-root[data-hprnb-endpoint]' ).count() ).toBe( 0 );

	setSettings( { mobile_layout: 'flow', mobile_lines: 2, render_mode: 'php', categories_include: [ 999999 ] } );
	await page.goto( '/' );
	expect( await page.locator( '#hprnb-root' ).count() ).toBe( 0 );
	expect( await page.locator( 'link#hprnb-bar-css' ).count() ).toBe( 0 );
	await expect( page.locator( 'body' ) ).not.toHaveClass( /hprnb-reserve/ );

	setSettings( { mobile_layout: 'flow', mobile_lines: 2, categories_include: [ 999999 ] } );
	await page.goto( '/' );
	await expect( page.locator( '#hprnb-root' ) ).toHaveCount( 1 );
	await expect( page.locator( '#hprnb-root' ) ).toBeHidden();
	await expect( page.locator( '#hprnb-root' ) ).toHaveAttribute( 'data-hprnb-empty', '1' );
	expect( await page.locator( 'link#hprnb-bar-css' ).count() ).toBe( 0 );
	expect( await page.locator( 'script#hprnb-bootstrap-js' ).count() ).toBe( 1 );
	expect( await page.locator( '#hprnb-root' ).evaluate( ( el ) => el.getBoundingClientRect().height ) ).toBe( 0 );
} );

test( 'shortcode renders a single bar', async ( { page } ) => {
	setSettings( { mobile_layout: 'flow', mobile_lines: 2 } );
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
	setSettings( {} );
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
	// This scenario walks through every setting: "Advanced settings" on (2.11), remembered by the browser.
	await page.click( 'label[for="hprnb-advanced-toggle"]' );
	await expect( page.locator( '#hprnb-advanced-toggle' ) ).toBeChecked();
	await expect( page.locator( 'h1' ) ).toHaveText( 'Horizon Press News Bar' );
	expect( await page.locator( 'link#hprnb-admin-css' ).count() ).toBe( 1 );
	await expect( page.locator( '#hprnb-preview-root .hprnb-bar' ) ).toBeVisible();
	expect( await page.locator( '#hprnb-preview-root .hprnb-bar' ).evaluate( ( el ) => getComputedStyle( el ).position ) ).toBe( 'relative' );

	// Live visual updates without network.
	const previews = countRequests( page, /hprnb\/v1\/preview/ );
	await page.click( '[data-hprnb-tab="content"]' );
	await page.fill( '#hprnb-field-label-text', 'LIVE LABEL' );
	await expect( page.locator( '#hprnb-preview-root .hprnb-bar__label-text' ) ).toHaveText( 'LIVE LABEL' );
	await page.click( '[data-hprnb-tab="colors"]' );
	await page.fill( '#hprnb-field-bg-color', '#112233' );
	await page.locator( '#hprnb-field-bg-color' ).dispatchEvent( 'input' );
	expect( await page.locator( '#hprnb-preview-root .hprnb-bar' ).evaluate( ( el ) => getComputedStyle( el ).backgroundColor ) ).toBe( 'rgb(17, 34, 51)' );
	await page.click( '[data-hprnb-tab="content"]' );
	await page.check( '#hprnb-field-label-position-end' );
	await expect( page.locator( '#hprnb-preview-root .hprnb-bar' ) ).toHaveClass( /hprnb-bar--label-end/ );
	await page.check( '#hprnb-field-label-position-start' );
	await expect( page.locator( '#hprnb-preview-root .hprnb-bar' ) ).toHaveClass( /hprnb-bar--label-start/ );

	// Separator toggles are visual only and follow the dependency rule.
	const previewRoot = page.locator( '#hprnb-preview-root' );
	const dependentRow = page.locator( 'tr[data-hprnb-depends="show_separator"]' );
	await page.uncheck( '#hprnb-field-show-separator' );
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
	await expect( page.locator( '#hprnb-preview-root .hprnb-bar__counter' ) ).toHaveCount( 0 ); // v2: no counter on mobile
	await expect( previewRoot ).toHaveClass( /hprnb-root--m-card/, 'v2.8: the reference card is the default design.' );
	await expect( previewRoot ).not.toHaveClass( /hprnb-root--m-pending/, 'The preview never waits.' );
	expect( Math.round( ( await previewRoot.boundingBox() ).width ) ).toBeLessThanOrEqual( 375 );
	await page.click( '[data-hprnb-tab="colors"]' );
	await expect( previewRoot ).not.toHaveClass( /hprnb-root--m-colors/ );
	await expect( page.locator( 'tr[data-hprnb-depends="mobile_custom_colors"]' ).first() ).toHaveClass( /hprnb-row--inactive/ );
	await page.locator( '#hprnb-field-mobile-custom-colors' ).setChecked( true, { force: true } );
	await expect( previewRoot ).toHaveClass( /hprnb-root--m-colors/ );
	await page.locator( '#hprnb-field-mobile-custom-colors' ).setChecked( false, { force: true } );
	await page.click( '[data-hprnb-tab="mobile"]' );
	// The two designs of 2.11, switched live in the preview.
	await expect( page.locator( '#hprnb-field-mobile-layout-inline' ) ).toHaveCount( 0 );
	await expect( page.locator( '#hprnb-field-mobile-layout-stacked' ) ).toHaveCount( 0 );
	await page.check( '#hprnb-field-mobile-layout-flow_image' );
	await expect( previewRoot ).toHaveClass( /hprnb-root--m-flow/ );
	await expect( previewRoot ).toHaveClass( /hprnb-root--m-ctrl-tab/ );
	await page.check( '#hprnb-field-mobile-layout-card' );
	await expect( previewRoot ).toHaveClass( /hprnb-root--m-card/ );
	await expect( previewRoot ).not.toHaveClass( /hprnb-root--m-ctrl-tab/ );
	await page.check( '#hprnb-field-mobile-layout-flow_image' );
	await page.click( '#hprnb-preview-tab-desktop' );
	await expect( previewRoot ).toHaveClass( /hprnb-root--flat/ );
	await expect( page.locator( '#hprnb-preview-root .hprnb-bar' ) ).toHaveClass( /hprnb-bar--mode-marquee/ );
	// Presets fill the palette fields without a request; the height hint follows the lines setting.
	await page.click( '[data-hprnb-tab="colors"]' );
	await page.click( '.hprnb-preset[data-hprnb-preset="red"]' );
	await expect( page.locator( '#hprnb-field-bg-color' ) ).toHaveValue( '#ce3029' );
	await page.click( '.hprnb-preset[data-hprnb-preset="dark"]' );
	await expect( page.locator( '#hprnb-field-bg-color' ) ).toHaveValue( '#1b1c20' );
	await page.click( '[data-hprnb-tab="mobile"]' );
	await page.check( '#hprnb-field-mobile-layout-card' );
	await page.fill( '#hprnb-field-mobile-card-thumb', '96' );
	await page.locator( '#hprnb-field-mobile-card-thumb' ).dispatchEvent( 'input' );
	await expect( page.locator( '.hprnb-height-hint[data-hprnb-height="m"]' ) ).toHaveText( 'Bar height: 118 px', 'A 96px picture is 54px tall: three 22px lines drive the card.' );
	page.once( 'dialog', ( d ) => d.accept() );
	await page.click( '#hprnb-reset-tab' );
	// 2.11 defaults: the bar with the article picture on two lines, pause off, Continuous reading.
	await expect( page.locator( '#hprnb-field-mobile-layout-flow_image' ) ).toBeChecked();
	await expect( page.locator( '#hprnb-field-mobile-lines' ) ).toHaveValue( '2' );
	await expect( page.locator( '#hprnb-field-mobile-card-thumb' ) ).toHaveValue( '132' );
	await expect( page.locator( '#hprnb-field-mobile-show-pause' ) ).not.toBeChecked();
	await expect( page.locator( '#hprnb-field-mobile-behavior-reading' ) ).toBeChecked();
	await expect( page.locator( '.hprnb-height-hint[data-hprnb-height="m"]' ) ).toHaveText( 'Bar height: 76 px', '12 + 2 × 26 + 12.' );
	expect( previews ).toHaveLength( 0 );

	// Contrast warning (never blocks saving).
	await page.click( '[data-hprnb-tab="colors"]' );
	await page.fill( '#hprnb-field-text-color', '#112244' );
	await page.locator( '#hprnb-field-text-color' ).dispatchEvent( 'input' );
	await expect( page.locator( '#hprnb-contrast-text' ) ).toBeVisible();
	await page.fill( '#hprnb-field-text-color', '#ffffff' );
	await page.locator( '#hprnb-field-text-color' ).dispatchEvent( 'input' );
	await expect( page.locator( '#hprnb-contrast-text' ) ).toBeHidden();

	// Content refresh through the private endpoint. v2.6: how many articles is a Content question.
	await page.click( '[data-hprnb-tab="content"]' );
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
	// A save must never take the bar away: 2.4.0 posted the per-profile page types under the wrong
	// name, every type came back unticked and the bar vanished from the whole site.
	const saved = JSON.parse( wp( [ 'option', 'get', 'hprnb_settings', '--format=json' ] ) );
	expect( Object.values( saved.desktop_contexts ).every( Boolean ) ).toBe( true );
	expect( Object.values( saved.mobile_contexts ).every( Boolean ) ).toBe( true );
	await page.goto( '/' );
	await expect( page.locator( '#hprnb-root' ) ).toHaveClass( /hprnb-device-all/ );
	await expect( page.locator( '#hprnb-root .hprnb-bar' ) ).toBeVisible();
	await page.goto( '/wp-admin/options-general.php?page=horizon-press-news-bar' );

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
	await expect( page.locator( '#hprnb-field-label-text' ) ).toHaveValue( 'EN CONTINU' );
	expect( errors ).toEqual( [] );
} );

test( 'French locale: front and admin strings are translated', async ( { page } ) => {
	setSettings( { mobile_layout: 'flow', mobile_lines: 2 } );
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
	setSettings( { mobile_layout: 'flow', mobile_lines: 2 } );
	const hits = countRequests( page, /hprnb\/v1\/items/ );
	await page.clock.setFixedTime( new Date( Date.now() - 3 * 24 * 3600 * 1000 ) );
	await page.goto( '/' );
	await expect( page.locator( '#hprnb-root .hprnb-bar' ) ).toBeVisible();
	await page.waitForTimeout( 800 );
	expect( hits ).toHaveLength( 0 );
} );

test( 'admin page is usable in RTL', async ( { page } ) => {
	setSettings( { mobile_layout: 'flow', mobile_lines: 2 } );
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
		{ name: 'marquee+close+time+thumbs', settings: { ticker_enabled: true, ticker_mode: 'marquee', close_button: true, show_relative_time: true, desktop_show_thumbnail: true, mobile_show_thumbnail: true, show_separator: true } },
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
	setSettings( { mobile_layout: 'flow', mobile_lines: 2, ticker_enabled: true, ticker_mode: 'marquee', close_button: true } );
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

// The label row above the headline left the designs in 2.11: the rotation features it exercised
// are checked on the default design, the bar with the article picture.
const IMAGE_BAR = { mobile_layout: 'flow_image', mobile_lines: 2, mobile_label_dot: false, mobile_custom_colors: true, mobile_bg_color: '#141414', mobile_accent_color: '#E11D2A', mobile_text_color: '#F5F5F5', ticker_enabled: false };

test( 'mobile rotation: progress, rotation, swipe, pause, folding, palette, options, breakpoint, reduced motion', async ( { page } ) => {
	setSettings( { ...IMAGE_BAR, rotate_interval: 1500 } );
	const errors = collectErrors( page );
	await page.setViewportSize( { width: 375, height: 667 } );
	await page.goto( '/' );
	const root = page.locator( '#hprnb-root' );
	const aside = page.locator( '#hprnb-root .hprnb-bar' );
	const current = () => page.locator( '.hprnb-bar__item:not([hidden]) .hprnb-bar__title' ).first().innerText();
	await expect( aside ).toBeVisible();
	await expect( root ).toHaveClass( /hprnb-root--m-flow/ );
	await expect( aside ).toHaveClass( /hprnb-bar--mode-rotate/ );
	await expect( aside ).toHaveClass( /hprnb-bar--mobile/ );
	expect( Math.round( ( await aside.boundingBox() ).height ) ).toBe( 76 );
	expect( await page.evaluate( () => parseFloat( getComputedStyle( document.body ).paddingBottom ) ) ).toBeGreaterThanOrEqual( 76 );

	// The pill without its live dot (option off), the 16px headline on two lines.
	const label = page.locator( '.hprnb-bar__label' );
	await expect( label ).toBeVisible();
	expect( await label.evaluate( ( el ) => getComputedStyle( el, '::before' ).display ) ).toBe( 'none', 'The live dot is off here.' );
	expect( await label.evaluate( ( el ) => getComputedStyle( el ).borderTopLeftRadius ) ).toBe( '999px' );
	await expect( page.locator( '.hprnb-bar__counter' ) ).toBeHidden();
	const title = page.locator( '.hprnb-bar__item:not([hidden]) .hprnb-bar__title' );
	expect( await title.evaluate( ( el ) => getComputedStyle( el ).fontSize ) ).toBe( '16px' );

	// Dedicated mobile palette.
	expect( await aside.evaluate( ( el ) => getComputedStyle( el ).color ) ).toBe( 'rgb(245, 245, 245)' );
	// color-mix() resolves to rgb(20 20 20 / .94) or color(srgb 0.078 …), depending on the engine.
	expect( await aside.evaluate( ( el ) => getComputedStyle( el ).backgroundColor ) ).toMatch( /20, 20, 20|0\.078/ );
	expect( await label.evaluate( ( el ) => getComputedStyle( el ).backgroundColor ) ).toBe( 'rgb(225, 29, 42)' );
	expect( await page.locator( '.hprnb-bar__controls' ).evaluate( ( el ) => getComputedStyle( el ).backgroundColor ) ).toMatch( /20, 20, 20|0\.078/, 'The tab wears the same colour.' );

	// Progress line runs and the rotation advances.
	const progress = page.locator( '.hprnb-bar__progress' );
	await expect( progress ).toHaveClass( /is-run/ );
	expect( await progress.evaluate( ( el ) => getComputedStyle( el, '::after' ).animationName ) ).toBe( 'hprnb-progress' );
	expect( ( await progress.boundingBox() ).y ).toBeLessThan( ( await aside.boundingBox() ).y + 1, 'The progress track runs along the top edge of the bar.' );
	const first = await current();
	await expect.poll( current, { timeout: 4000 } ).not.toBe( first );

	// Swipe left → next headline.
	const before = await current();
	const box = await page.locator( '.hprnb-bar__viewport' ).boundingBox();
	await page.mouse.move( box.x + box.width - 20, box.y + box.height / 2 );
	await page.mouse.down();
	await page.mouse.move( box.x + 40, box.y + box.height / 2, { steps: 8 } );
	await page.mouse.up();
	await expect.poll( current ).not.toBe( before );

	// Folds while scrolling down, back on a scroll up or a tap on the strip.
	await page.evaluate( () => window.scrollTo( 0, 0 ) );
	await page.evaluate( () => window.scrollTo( 0, 600 ) );
	await expect( aside ).toHaveClass( /hprnb-bar--collapsed/ );
	await page.evaluate( () => window.scrollTo( 0, 300 ) );
	await expect( aside ).not.toHaveClass( /hprnb-bar--collapsed/ );
	await page.evaluate( () => window.scrollTo( 0, 900 ) );
	await expect( aside ).toHaveClass( /hprnb-bar--collapsed/ );
	await page.waitForTimeout( 650 );
	await label.click( { force: true } );
	await expect( aside ).not.toHaveClass( /hprnb-bar--collapsed/ );

	// Pause / Play (in the tab) pauses the rotation and the progress line; no prev/next in rotate mode.
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
	await expect( page.locator( '.hprnb-bar__progress' ) ).toHaveCount( 0 );
	await expect( toggle ).toBeHidden();
	expect( Math.round( ( await aside.boundingBox() ).height ) ).toBe( 40 );
	await page.setViewportSize( { width: 375, height: 667 } );
	await expect( aside ).toHaveClass( /hprnb-bar--mode-rotate/ );
	await expect( page.locator( '.hprnb-bar__progress' ) ).toHaveCount( 1 );

	// Options: hidden label, desktop colours, no progress, no folding.
	setSettings( { ...IMAGE_BAR, mobile_label_style: 'hidden', mobile_custom_colors: false, mobile_show_progress: false, mobile_hide_on_scroll: false } );
	await page.goto( '/' );
	await expect( label ).toBeHidden();
	expect( await aside.evaluate( ( el ) => getComputedStyle( el ).backgroundColor ) ).toBe( 'rgb(27, 28, 32)' );
	await expect( page.locator( '.hprnb-bar__progress' ) ).toHaveCount( 0 );
	await expect( root ).not.toHaveClass( /hprnb-root--m-collapse/ );
	await page.evaluate( () => window.scrollTo( 0, 900 ) );
	await page.waitForTimeout( 300 );
	await expect( aside ).not.toHaveClass( /hprnb-bar--collapsed/ );

	// Reduced motion: no automatic rotation, no progress line, toggle hidden, no folding animation.
	setSettings( IMAGE_BAR );
	await page.emulateMedia( { reducedMotion: 'reduce' } );
	await page.goto( '/' );
	await expect( aside ).toHaveClass( /hprnb-bar--reduced/ );
	await expect( page.locator( '.hprnb-bar__progress' ) ).toHaveCount( 0 );
	await expect( toggle ).toBeHidden();
	expect( await aside.evaluate( ( el ) => getComputedStyle( el ).transitionDuration ) ).toBe( '0s' );
	expect( await label.evaluate( ( el ) => getComputedStyle( el ).transitionDuration ) ).toBe( '0s', 'The pill no longer closes in motion either.' );
	expect( await noHorizontalOverflow( page ) ).toBe( true );
} );

test( 'v2.6: the discover card honours the headline line count, up to its own cap of three', async ( { page } ) => {
	const errors = collectErrors( page );
	await page.setViewportSize( { width: 390, height: 780 } );

	const headline = page.locator( '.hprnb-bar__item:not([hidden]) .hprnb-bar__title' );
	const aside = page.locator( '#hprnb-root .hprnb-bar' );

	// Two lines with a 96px picture (54px tall): the text drives the height at two lines already.
	setSettings( { mobile_layout: 'card', mobile_card_thumb: 96, mobile_lines: 2, rotate_interval: 60000, mobile_show_pause: false } );
	await page.goto( '/' );
	await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
	expect( await headline.evaluate( ( el ) => getComputedStyle( el ).webkitLineClamp ) ).toBe( '2' );
	expect( Math.round( ( await aside.boundingBox() ).height ) ).toBe( 106, '12 + 20 + 8 + max(54, 44) + 12.' );

	// Three lines: the clamp, the card and the space the page reserves all move together.
	setSettings( { mobile_layout: 'card', mobile_card_thumb: 96, mobile_lines: 3, rotate_interval: 60000, mobile_show_pause: false } );
	await page.goto( '/' );
	await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
	expect( await headline.evaluate( ( el ) => getComputedStyle( el ).webkitLineClamp ) ).toBe( '3' );
	expect( Math.round( ( await aside.boundingBox() ).height ) ).toBe( 118, '12 + 20 + 8 + max(54, 66) + 12.' );
	expect( await page.evaluate( () => getComputedStyle( document.body ).getPropertyValue( '--hprnb-m-height' ).trim() ) ).toBe( '118px' );

	// The reference picture (132 x 74) is taller than three lines: 126px whatever the count.
	setSettings( { mobile_layout: 'card', mobile_lines: 3, rotate_interval: 60000, mobile_show_pause: false } );
	await page.goto( '/' );
	await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
	expect( Math.round( ( await aside.boundingBox() ).height ) ).toBe( 126 );

	// A long headline really uses the third line and is still clipped, never overflowing the card.
	// Rename the post the card is actually showing, not whichever one the database lists first.
	const shown = await page.locator( '.hprnb-bar__item:not([hidden])' ).getAttribute( 'data-hprnb-id' );
	const original = wp( [ 'post', 'get', shown, '--field=post_title' ] );
	wp( [ 'post', 'update', shown, '--post_title=Un titre deliberement tres long qui ne tient pas sur une seule ligne et qui doit deborder sur une troisieme ligne avant d etre coupe proprement par le clamp' ] );
	wp( [ 'option', 'update', 'hprnb_cache_epoch', 'e2e-' + Date.now() ] );
	await page.goto( '/' );
	await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
	const box = await headline.boundingBox();
	const cardBox = await aside.boundingBox();
	expect( Math.round( box.height ) ).toBe( 66, 'Three 22px lines.' );
	expect( box.y + box.height ).toBeLessThanOrEqual( cardBox.y + cardBox.height + 1, 'Clipped inside the card.' );
	expect( await headline.evaluate( ( el ) => el.scrollHeight > el.clientHeight ) ).toBe( true, 'And the rest is really clamped away.' );

	// The collapsed strip stays one line whatever the count.
	expect( await page.evaluate( () => getComputedStyle( document.body ).getPropertyValue( '--hprnb-peek' ).trim() ) ).toBe( '36px' );
	expect( await noHorizontalOverflow( page ) ).toBe( true );

	wp( [ 'post', 'update', shown, `--post_title=${ original }` ] );
	expect( errors ).toEqual( [] );
} );

test( 'v2.6: a per-post switch keeps one article out of the bar, and the bar off one page', async ( { page } ) => {
	const errors = collectErrors( page );
	setSettings( { mobile_layout: 'flow', mobile_lines: 2, max_items: 2, window_value: 72, window_unit: 'hours' } );

	const ids = wp( [ 'post', 'list', '--post_type=post', '--post_status=publish', '--posts_per_page=3', '--field=ID', '--orderby=date', '--order=DESC' ] ).split( '\n' ).filter( Boolean );
	expect( ids.length ).toBeGreaterThanOrEqual( 3 );
	const [ newest, second, third ] = ids;

	await page.goto( '/' );
	const items = page.locator( '#hprnb-root .hprnb-bar__list:not(.hprnb-bar__list--clone) .hprnb-bar__item' );
	await expect( items ).toHaveCount( 2 );
	const before = await items.evaluateAll( ( els ) => els.map( ( el ) => el.getAttribute( 'data-hprnb-id' ) ) );
	expect( before ).toContain( second );

	// "Never list this article in the bar": it leaves, and the next one takes its place.
	wp( [ 'post', 'meta', 'update', second, '_hprnb_exclude_item', '1' ] );
	await page.goto( '/' );
	await expect( items ).toHaveCount( 2, 'The bar refills rather than shrinking.' );
	const after = await items.evaluateAll( ( els ) => els.map( ( el ) => el.getAttribute( 'data-hprnb-id' ) ) );
	expect( after ).not.toContain( second );
	expect( after ).toContain( newest );
	expect( after ).toContain( third );

	// Unticking brings it straight back — the epoch rotated, no stale transient.
	wp( [ 'post', 'meta', 'delete', second, '_hprnb_exclude_item' ] );
	await page.goto( '/' );
	expect( await items.evaluateAll( ( els ) => els.map( ( el ) => el.getAttribute( 'data-hprnb-id' ) ) ) ).toContain( second );

	// "Never show the bar on this page": no root, no reserved space, on that page only.
	await page.goto( `/?p=${ newest }` );
	await expect( page.locator( '#hprnb-root' ) ).toHaveCount( 1 );
	wp( [ 'post', 'meta', 'update', newest, '_hprnb_hide_bar', '1' ] );
	await page.goto( `/?p=${ newest }` );
	await expect( page.locator( '#hprnb-root' ) ).toHaveCount( 0 );
	await expect( page.locator( 'body.hprnb-reserve' ) ).toHaveCount( 0, 'And nothing is reserved for it either.' );
	expect( await page.locator( '.hprnb-bar__item' ).count() ).toBe( 0 );

	// The headline itself is untouched elsewhere: the two switches are independent.
	await page.goto( `/?p=${ third }` );
	await expect( page.locator( '#hprnb-root' ) ).toHaveCount( 1 );
	expect( await items.evaluateAll( ( els ) => els.map( ( el ) => el.getAttribute( 'data-hprnb-id' ) ) ) ).toContain( newest );

	wp( [ 'post', 'meta', 'delete', newest, '_hprnb_hide_bar' ] );
	expect( errors ).toEqual( [] );
} );

test( 'v2.6: the settings page names what it does — page types, appearing, folding', async ( { page } ) => {
	const errors = collectErrors( page );
	setSettings( {} );
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'admin' );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );
	await page.goto( '/wp-admin/options-general.php?page=horizon-press-news-bar' );

	// The six tabs, and no hidden "other" panel swallowing a setting.
	for ( const tab of [ 'content', 'where', 'mobile', 'desktop', 'colors', 'advanced' ] ) {
		await expect( page.locator( `[data-hprnb-tab="${ tab }"]` ) ).toHaveCount( 1 );
	}
	await expect( page.locator( '[data-hprnb-panel="other"]' ) ).toHaveCount( 0 );

	// The desktop font size was unreachable until 2.6: it now lives on a visible tab.
	await page.click( '[data-hprnb-tab="desktop"]' );
	await expect( page.locator( '#hprnb-field-font-size' ) ).toBeVisible();

	// Page types: one control, and the nine boxes grey out until the scope uses them.
	await page.click( '[data-hprnb-tab="where"]' );
	const contextRow = page.locator( 'tr[data-hprnb-depends="display_scope:custom"]' );
	await expect( contextRow ).toHaveClass( /hprnb-row--inactive/ );
	await page.check( '#hprnb-field-display-scope-custom' );
	await expect( contextRow ).not.toHaveClass( /hprnb-row--inactive/ );
	await expect( page.locator( 'input[name="hprnb_settings[contexts][single_post]"]' ) ).toBeVisible();

	// Folding: a plain on/off per device, with its timing underneath — on that device's own tab.
	// Since 2.9 those details sit behind the "Custom" behaviour: pick it first.
	await page.click( '[data-hprnb-tab="mobile"]' );
	await expect( page.locator( '#hprnb-field-mobile-reveal-mode-smart' ) ).toBeHidden();
	await page.check( '#hprnb-field-mobile-behavior-custom' );
	await expect( page.locator( '#hprnb-field-mobile-reveal-mode-smart' ) ).toBeVisible();
	await expect( page.locator( '#hprnb-field-mobile-layout-card' ) ).toBeChecked( { checked: true }, 'v2.8: the reference card is the default design, first field of the design card.' );
	await page.click( '[data-hprnb-tab="desktop"]' );
	await page.check( '#hprnb-field-desktop-behavior-custom' );
	await expect( page.locator( '#hprnb-field-desktop-reveal-mode-smart' ) ).toBeVisible();
	const desktopFold = page.locator( '#hprnb-field-desktop-hide-on-scroll' );
	await expect( desktopFold ).not.toBeChecked();
	const desktopWhen = page.locator( 'tr[data-hprnb-depends="desktop_hide_on_scroll"]' ).first();
	await expect( desktopWhen ).toHaveClass( /hprnb-row--inactive/, 'Folding off: its timing is inert and says so.' );
	await desktopFold.setChecked( true, { force: true } );
	await expect( desktopWhen ).not.toHaveClass( /hprnb-row--inactive/ );

	// The appearance threshold follows more than one mode — the "a|b" dependency, per device.
	const thresholdRow = page.locator( 'tr[data-hprnb-depends="desktop_reveal_mode:scroll|percent"]' );
	await expect( thresholdRow ).toHaveCount( 1 );
	await page.check( '#hprnb-field-desktop-reveal-mode-immediate' );
	await expect( thresholdRow ).toHaveClass( /hprnb-row--inactive/ );
	await page.check( '#hprnb-field-desktop-reveal-mode-scroll' );
	await expect( thresholdRow ).not.toHaveClass( /hprnb-row--inactive/ );
	await page.check( '#hprnb-field-desktop-reveal-mode-percent' );
	await expect( thresholdRow ).not.toHaveClass( /hprnb-row--inactive/, 'Both modes use it.' );
	await expect( page.locator( 'tr[data-hprnb-depends="mobile_reveal_mode:scroll|percent"]' ) ).toHaveCount( 1, 'And mobile has its own.' );

	// The worked examples are help, closed by default, never inputs.
	const scenarios = page.locator( '.hprnb-scenarios' );
	expect( await scenarios.count() ).toBeGreaterThan( 0 );
	expect( await scenarios.first().evaluate( ( el ) => el.open ) ).toBe( false );
	expect( await scenarios.first().locator( 'input, select' ).count() ).toBe( 0 );

	expect( errors ).toEqual( [] );
} );

test( 'v2.7: before the end of the article, and a bar that follows the reading', async ( { page } ) => {
	const body = Array.from( { length: 40 }, ( _, i ) =>
		`<p>Paragraphe ${ i + 1 }. Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.</p>` ).join( '\n' );
	const article = wp( [ 'post', 'create', '--post_type=post', '--post_status=publish', '--post_title=Article long pour le paragraphe avant la fin', `--post_content=${ body }`, '--porcelain' ] );
	const brief = wp( [ 'post', 'create', '--post_type=post', '--post_status=publish', '--post_title=Brève', '--post_content=<p>Deux phrases.</p><p>Pas plus.</p>', '--porcelain' ] );
	const url = new URL( wp( [ 'post', 'url', article ] ) ).pathname;
	const briefUrl = new URL( wp( [ 'post', 'url', brief ] ) ).pathname;

	const aside = page.locator( '.hprnb-bar' );
	const pending = () => page.evaluate( () => document.querySelector( '#hprnb-root' ).classList.contains( 'hprnb-root--' + ( window.innerWidth < 768 ? 'm' : 'd' ) + '-pending' ) );
	const folded = () => page.evaluate( () => document.querySelector( '.hprnb-bar' ).classList.contains( 'hprnb-bar--collapsed' ) );
	const impressions = () => page.evaluate( () => ( window.dataLayer || [] ).filter( ( r ) => r.event === 'hprnb_impression' ).map( ( r ) => ( { reason: r.trigger_reason, n: r.paragraph_from_end, found: r.paragraph_found } ) ) );
	const paragraphTop = ( i ) => page.evaluate( ( k ) => {
		const p = document.querySelectorAll( '.entry-content p, .wp-block-post-content p' )[ k ];
		return Math.round( p.getBoundingClientRect().top + window.scrollY );
	}, i );
	const articleEnd = () => page.evaluate( () => {
		const a = document.querySelector( '.entry-content, .wp-block-post-content' );
		return Math.round( a.getBoundingClientRect().bottom + window.scrollY );
	} );
	const scrollTo = async ( y ) => {
		await page.evaluate( ( v ) => window.scrollTo( 0, v ), y );
		await page.waitForTimeout( 160 );
	};
	await page.addInitScript( () => { window.dataLayer = []; } );

	try {
		const errors = collectErrors( page );
		await page.setViewportSize( { width: 390, height: 700 } );
		const vh = 700;

		// --- The reveal: nothing until the second-to-last paragraph enters the screen. ---------
		setSettings( { mobile_layout: 'flow', mobile_lines: 2, mobile_reveal_mode: 'paragraph', desktop_reveal_mode: 'paragraph', mobile_reveal_paragraph: 2, desktop_reveal_paragraph: 2, rotate_interval: 60000, mobile_hide_on_scroll: true, mobile_collapse_mode: 'article', mobile_deep_collapse: false } );
		await page.goto( url );
		await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
		expect( await pending() ).toBe( true );
		expect( await paragraphTop( 38 ) ).toBeGreaterThan( vh * 3, 'The penultimate paragraph is far below the fold.' );

		// Deep into the article but short of that paragraph: still nothing.
		const p30 = await paragraphTop( 29 );
		await scrollTo( p30 - vh + 40 );
		expect( await pending() ).toBe( true, 'Paragraph 30 on screen is not paragraph 39.' );
		expect( await impressions() ).toEqual( [] );

		// The penultimate paragraph reaches the bottom of the screen: the bar appears, in full.
		const p39 = await paragraphTop( 38 );
		const B = p39 - vh + 30;
		await scrollTo( B );
		await expect.poll( pending ).toBe( false );
		expect( await impressions() ).toEqual( [ { reason: 'paragraph_before_end', n: 2, found: true } ] );
		expect( await folded() ).toBe( false, 'The first appearance is the whole bar.' );
		expect( await page.evaluate( () => window.hprnbBar.state().collapsed ) ).toBe( false );

		// --- The folding: three zones on the article. -------------------------------------------
		// Back up inside the body: folded.
		await scrollTo( B - 200 );
		await expect.poll( folded ).toBe( true, 'Any scroll back up inside the article folds it.' );
		// Down again but still short of the trigger point: stays folded — the text is never covered.
		await scrollTo( B - 100 );
		await page.waitForTimeout( 120 );
		expect( await folded() ).toBe( true, 'Between the top and the trigger point it stays folded.' );
		// Past the trigger point, reading on: open again.
		await scrollTo( B + 150 );
		await expect.poll( folded ).toBe( false, 'Only past the trigger point does it unfold.' );
		// A small scroll up inside the body, past the trigger point: folded again.
		await scrollTo( B + 90 );
		await expect.poll( folded ).toBe( true );
		// Reading on again: open.
		await scrollTo( B + 150 );
		await expect.poll( folded ).toBe( false );

		// Past the end of the article: open, and a scroll back up no longer folds it.
		const endY = await articleEnd();
		await scrollTo( endY - vh + 60 );
		await expect.poll( folded ).toBe( false, 'The article is over: the bar stays.' );
		await scrollTo( endY - vh + 20 );
		await page.waitForTimeout( 120 );
		expect( await folded() ).toBe( false, 'Scrolling up while still past the end never folds it.' );
		// Back into the body, scrolling up: folded — the only place it ever folds.
		await scrollTo( endY - vh - 240 );
		await expect.poll( folded ).toBe( true );
		// A tap on the folded strip opens it and holds it open for a moment, as in every mode.
		await aside.click( { position: { x: 60, y: 12 } } );
		await expect.poll( folded ).toBe( false );
		await scrollTo( endY - vh - 300 );
		await page.waitForTimeout( 120 );
		expect( await folded() ).toBe( false, 'Held open after the tap, whatever the scroll.' );
		// And the bar appeared exactly once for all that.
		expect( await impressions() ).toHaveLength( 1 );
		expect( await noHorizontalOverflow( page ) ).toBe( true );

		// --- The count is the editor's: 10 paragraphs before the end fires ten paragraphs earlier.
		setSettings( { mobile_layout: 'flow', mobile_lines: 2, mobile_reveal_mode: 'paragraph', desktop_reveal_mode: 'paragraph', mobile_reveal_paragraph: 10, desktop_reveal_paragraph: 10, rotate_interval: 60000 } );
		await page.goto( url );
		await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
		const p31 = await paragraphTop( 30 );
		await scrollTo( p31 - vh - 200 );
		expect( await pending() ).toBe( true, 'Paragraph 31 still below the screen.' );
		await scrollTo( p31 - vh + 30 );
		await expect.poll( pending ).toBe( false );
		expect( await impressions() ).toEqual( [ { reason: 'paragraph_before_end', n: 10, found: true } ] );

		// --- A two-paragraph brief: its second-to-last paragraph is on screen at once. ------------
		setSettings( { mobile_layout: 'flow', mobile_lines: 2, mobile_reveal_mode: 'paragraph', desktop_reveal_mode: 'paragraph', mobile_reveal_paragraph: 2, desktop_reveal_paragraph: 2, rotate_interval: 60000 } );
		await page.goto( briefUrl );
		await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
		await expect.poll( pending ).toBe( false );
		expect( await impressions() ).toEqual( [ { reason: 'paragraph_before_end', n: 2, found: true } ] );

		// --- Restored mid-page beyond the paragraph: the reader is past the point, the bar shows. --
		setSettings( { mobile_layout: 'flow', mobile_lines: 2, mobile_reveal_mode: 'paragraph', desktop_reveal_mode: 'paragraph', mobile_reveal_paragraph: 2, desktop_reveal_paragraph: 2, rotate_interval: 60000 } );
		await page.goto( url );
		await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
		await page.evaluate( () => window.scrollTo( 0, document.documentElement.scrollHeight ) );
		await page.reload();
		await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
		await expect.poll( pending ).toBe( false );
		expect( [ 'paragraph_passed', 'paragraph_before_end' ] ).toContain( ( await impressions() )[ 0 ].reason );

		// --- Desktop follows the same three zones with its own switch. ---------------------------
		await page.setViewportSize( { width: 1366, height: 800 } );
		setSettings( { mobile_layout: 'flow', mobile_lines: 2, mobile_reveal_mode: 'paragraph', desktop_reveal_mode: 'paragraph', mobile_reveal_paragraph: 2, desktop_reveal_paragraph: 2, ticker_mode: 'none', desktop_hide_on_scroll: true, desktop_collapse_mode: 'article' } );
		await page.goto( url );
		await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
		expect( await pending() ).toBe( true );
		const dB = ( await paragraphTop( 38 ) ) - 800 + 30;
		await scrollTo( dB );
		await expect.poll( pending ).toBe( false );
		expect( await folded() ).toBe( false );
		await scrollTo( dB - 150 );
		await expect.poll( folded ).toBe( true, 'Desktop folds on the way back up too.' );
		await scrollTo( dB + 120 );
		await expect.poll( folded ).toBe( false );
		const dEnd = await articleEnd();
		await scrollTo( dEnd - 800 + 80 );
		await expect.poll( folded ).toBe( false );
		await scrollTo( dEnd - 800 + 30 );
		await page.waitForTimeout( 120 );
		expect( await folded() ).toBe( false, 'Past the end on desktop: never folded.' );

		// --- The three older triggers are untouched: "scroll" still folds on the way down. -------
		await page.setViewportSize( { width: 390, height: 700 } );
		setSettings( { mobile_layout: 'flow', mobile_lines: 2, mobile_reveal_mode: 'immediate', desktop_reveal_mode: 'immediate', rotate_interval: 60000, mobile_hide_on_scroll: true, mobile_collapse_mode: 'scroll', mobile_collapse_after: 120 } );
		await page.goto( url );
		await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
		await scrollTo( 900 );
		await expect.poll( folded ).toBe( true );
		await scrollTo( 700 );
		await expect.poll( folded ).toBe( false );
		expect( errors ).toEqual( [] );
	} finally {
		wp( [ 'post', 'delete', article, '--force' ] );
		wp( [ 'post', 'delete', brief, '--force' ] );
		setSettings( { mobile_layout: 'flow', mobile_lines: 2 } );
	}
} );

test( 'v2.9: continuous reading in one choice, and a bar that goes away in the next article', async ( { page } ) => {
	const errors = collectErrors( page );
	const body = Array.from( { length: 40 }, ( _, i ) =>
		`<p>Paragraphe ${ i + 1 }. Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.</p>` ).join( '\n' );
	const article = wp( [ 'post', 'create', '--post_type=post', '--post_status=publish', '--post_title=Article suivi d’un autre', `--post_content=${ body }`, '--porcelain' ] );
	const url = new URL( wp( [ 'post', 'url', article ] ) ).pathname;

	const root = page.locator( '#hprnb-root' );
	const aside = page.locator( '.hprnb-bar' );
	const device = () => page.evaluate( () => ( window.innerWidth < 768 ? 'm' : 'd' ) );
	const has = ( suffix ) => page.evaluate( ( s ) => document.querySelector( '#hprnb-root' ).classList.contains( 'hprnb-root--' + ( window.innerWidth < 768 ? 'm' : 'd' ) + '-' + s ), suffix );
	const folded = () => page.evaluate( () => document.querySelector( '.hprnb-bar' ).classList.contains( 'hprnb-bar--collapsed' ) );
	// Everything of the bar, its button tab included, below the bottom edge of the screen.
	const outOfView = () => page.evaluate( () => {
		const tops = [ '.hprnb-bar', '.hprnb-bar__controls' ].map( ( sel ) => document.querySelector( sel ).getBoundingClientRect().top );
		return Math.min.apply( null, tops ) >= window.innerHeight && getComputedStyle( document.querySelector( '.hprnb-bar' ) ).visibility === 'hidden';
	} );
	const padding = () => page.evaluate( () => parseFloat( getComputedStyle( document.body ).paddingBottom ) );
	const paragraphTop = ( i ) => page.evaluate( ( k ) => {
		const p = document.querySelectorAll( '.entry-content p, .wp-block-post-content p' )[ k ];
		return Math.round( p.getBoundingClientRect().top + window.scrollY );
	}, i );
	const scrollTo = async ( y ) => {
		await page.evaluate( ( v ) => window.scrollTo( 0, v ), y );
		await page.waitForTimeout( 160 );
	};
	// What a continuous-loading theme does: the next article, title first, appended below.
	const appendNext = () => page.evaluate( () => {
		const current = document.querySelector( '.entry-content, .wp-block-post-content' );
		const next = document.createElement( 'article' );
		next.className = 'hprnb-e2e-next';
		next.innerHTML = '<h2>Article suivant</h2><div style="height:320px">Image</div><div class="entry-content">' +
			Array.from( { length: 30 }, ( _, i ) => '<p>Suite ' + ( i + 1 ) + '. Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor.</p>' ).join( '' ) + '</div>';
		( current.closest( 'main' ) || current.parentNode ).appendChild( next );
		return Math.round( next.getBoundingClientRect().top + window.scrollY );
	} );

	try {
		// --- The admin: one choice, the details follow it and stay out of sight. -----------------
		setSettings( {} );
		await page.goto( '/wp-login.php' );
		await page.fill( '#user_login', 'admin' );
		await page.fill( '#user_pass', 'admin' );
		await page.click( '#wp-submit' );
		await page.waitForURL( /wp-admin/ );
		await page.goto( '/wp-admin/options-general.php?page=horizon-press-news-bar' );
		await page.click( '[data-hprnb-tab="mobile"]' );
		await expect( page.locator( '#hprnb-field-mobile-behavior-fold' ) ).toBeChecked();
		const customCards = page.locator( '[data-hprnb-card-depends="mobile_behavior:custom"]' );
		await expect( customCards ).toHaveCount( 2 );
		await expect( customCards.first() ).toBeHidden();
		await expect( page.locator( '#hprnb-field-mobile-reveal-paragraph' ) ).toBeHidden( { timeout: 2000 } );
		await page.check( '#hprnb-field-mobile-behavior-reading' );
		await expect( page.locator( '#hprnb-field-mobile-reveal-paragraph' ) ).toBeVisible();
		await expect( page.locator( '#hprnb-field-mobile-reveal-mode-paragraph' ) ).toBeChecked();
		await expect( page.locator( '#hprnb-field-mobile-collapse-mode-up' ) ).toBeChecked( { checked: true }, '2.13: folds on every scroll up, opens on every scroll down.' );
		await expect( page.locator( '#hprnb-field-mobile-next-hide' ) ).toBeChecked();
		await page.fill( '#hprnb-field-mobile-reveal-paragraph', '2' );
		await page.click( '#hprnb-save' );
		await page.waitForURL( /settings-updated=true/ );
		const saved = JSON.parse( wp( [ 'option', 'get', 'hprnb_settings', '--format=json' ] ) );
		expect( [ saved.mobile_behavior, saved.mobile_reveal_mode, saved.mobile_collapse_mode, saved.mobile_hide_on_scroll, saved.mobile_next_hide, saved.mobile_reveal_paragraph ] )
			.toEqual( [ 'reading', 'paragraph', 'up', true, true, 2 ] );
		expect( saved.desktop_behavior ).toBe( 'always', 'The other device keeps its own choice.' );
		await page.click( '[data-hprnb-tab="mobile"]' );
		await page.check( '#hprnb-field-mobile-behavior-custom' );
		await expect( customCards.first() ).toBeVisible();
		await expect( page.locator( '#hprnb-field-mobile-collapse-mode-up' ) ).toBeChecked( { checked: true }, 'Custom starts from what the last choice was doing.' );
		await page.context().clearCookies();

		// --- Mobile: hidden, then in full at the second-to-last paragraph. ----------------------
		await page.setViewportSize( { width: 390, height: 844 } );
		await page.goto( url );
		await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
		expect( await device() ).toBe( 'm' );
		expect( await has( 'pending' ) ).toBe( true );
		expect( await outOfView() ).toBe( true, 'Waiting: the card and its button tab are both out of view.' );
		expect( await padding() ).toBe( 0 );
		const B = ( await paragraphTop( 38 ) ) - 844 + 30;
		await scrollTo( B );
		await expect.poll( () => has( 'pending' ) ).toBe( false );
		expect( await folded() ).toBe( false, 'The first appearance is the full bar.' );
		expect( await padding() ).toBeGreaterThan( 100 );

		// Back up: folded. Down again: open.
		await scrollTo( B - 150 );
		await expect.poll( folded ).toBe( true );
		await scrollTo( B + 60 );
		await expect.poll( folded ).toBe( false );

		// --- The next article arrives below and the reader moves on to it: gone, space released. --
		const nextTop = await appendNext();
		await scrollTo( nextTop - 844 / 2 + 40 );
		await expect.poll( () => has( 'away' ) ).toBe( true );
		await expect( page.locator( 'body' ) ).toHaveClass( /hprnb-m-away/ );
		await expect.poll( outOfView ).toBe( true );
		expect( await padding() ).toBe( 0 );
		expect( await page.evaluate( () => window.hprnbBar.state().offset ) ).toBe( 0 );
		await scrollTo( nextTop + 1500 );
		expect( await has( 'away' ) ).toBe( true, 'Still gone deeper in the next article.' );

		// Back into the first article: it returns — folded, since the reader scrolled up (2.13: every
		// scroll up folds it, past the end of the article too).
		await scrollTo( nextTop - 844 );
		await expect.poll( () => has( 'away' ) ).toBe( false );
		await expect( page.locator( 'body' ) ).not.toHaveClass( /hprnb-m-away/ );
		await expect.poll( folded ).toBe( true );
		expect( await padding() ).toBeGreaterThan( 100 );
		await scrollTo( B - 150 );
		await expect.poll( folded ).toBe( true );

		// --- Desktop: the same choice, and the folded tab goes away with the bar. ----------------
		setSettings( { mobile_behavior: 'reading', desktop_behavior: 'reading', ticker_mode: 'none' } );
		await page.setViewportSize( { width: 1366, height: 800 } );
		await page.goto( url );
		await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
		expect( await device() ).toBe( 'd' );
		expect( await has( 'pending' ) ).toBe( true );
		const dB = ( await paragraphTop( 38 ) ) - 800 + 30;
		await scrollTo( dB );
		await expect.poll( () => has( 'pending' ) ).toBe( false );
		await scrollTo( dB - 150 );
		await expect.poll( folded ).toBe( true );
		const dNext = await appendNext();
		await scrollTo( dNext - 400 + 40 );
		await expect.poll( () => has( 'away' ) ).toBe( true );
		await expect.poll( outOfView ).toBe( true );
		await expect( page.locator( 'body' ) ).toHaveClass( /hprnb-d-away/ );
		await scrollTo( dNext - 800 );
		await expect.poll( () => has( 'away' ) ).toBe( false );

		// --- A listing is not an article: nothing to go away from. -------------------------------
		await page.goto( '/' );
		await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
		expect( await root.getAttribute( 'data-hprnb-desktop' ) ).not.toContain( '"next"' );
		expect( await root.getAttribute( 'data-hprnb-mobile' ) ).not.toContain( '"next"' );
		expect( errors ).toEqual( [] );
	} finally {
		wp( [ 'post', 'delete', article, '--force' ] );
		setSettings( { mobile_layout: 'flow', mobile_lines: 2 } );
	}
} );

test( 'v2.10: the flowing bar with the article picture, its buttons in a tab above the corner', async ( { page } ) => {
	const errors = collectErrors( page );
	const ids = [ 1, 2, 3 ].map( ( i ) => wp( [ 'post', 'create', '--post_type=post', '--post_status=publish', `--post_title=Exemple : trafic perturbé sur la ligne B ce soir ${ i }`, '--porcelain' ] ) );
	const media = ids.map( ( id ) => wp( [ 'media', 'import', 'tests/e2e/fixtures/thumb.png', '--post_id=' + id, '--featured_image', '--porcelain' ] ) );
	const geo = () => page.evaluate( () => {
		const box = ( el ) => {
			const b = el.getBoundingClientRect();
			return { left: Math.round( b.left ), right: Math.round( b.right ), top: Math.round( b.top ), bottom: Math.round( b.bottom ), width: Math.round( b.width ), height: Math.round( b.height ) };
		};
		const item = document.querySelector( '.hprnb-bar__item:not([hidden])' );
		const close = document.querySelector( '.hprnb-bar__btn--close' );
		const c = close.getBoundingClientRect();
		const hit = document.elementFromPoint( c.left + c.width / 2, c.top + c.height / 2 );
		return {
			bar: box( document.querySelector( '.hprnb-bar' ) ),
			label: box( document.querySelector( '.hprnb-bar__label' ) ),
			title: box( item.querySelector( '.hprnb-bar__title' ) ),
			thumb: box( item.querySelector( '.hprnb-bar__thumb' ) ),
			tab: box( document.querySelector( '.hprnb-bar__controls' ) ),
			close: box( close ),
			closeHit: !! ( hit && hit.closest( '.hprnb-bar__btn--close' ) ),
			tabBg: getComputedStyle( document.querySelector( '.hprnb-bar__controls' ) ).backgroundColor,
			barBg: getComputedStyle( document.querySelector( '.hprnb-bar' ) ).backgroundColor,
		};
	} );

	try {
		setSettings( { mobile_layout: 'flow_image', mobile_lines: 2, rotate_interval: 60000 } );
		await page.setViewportSize( { width: 390, height: 844 } );
		await page.goto( '/' );
		await expect( page.locator( '.hprnb-bar' ) ).toHaveAttribute( 'data-hprnb-init', '1' );
		await expect( page.locator( '#hprnb-root' ) ).toHaveClass( /hprnb-root--m-flow/ );
		await expect( page.locator( '#hprnb-root' ) ).toHaveClass( /hprnb-root--m-ctrl-tab/ );
		const g = await geo();
		expect( g.bar.height ).toBe( 76 );
		expect( g.bar.bottom ).toBe( 844 );
		// The pill opens the headline, which runs on two lines up to the picture.
		expect( g.label.left ).toBeLessThan( g.title.left + 5 );
		expect( g.title.height ).toBe( 52 );
		// The picture takes the buttons' place: at the end of the line, inside the gutter, beside the text.
		expect( [ g.thumb.width, g.thumb.height ] ).toEqual( [ 48, 48 ] );
		expect( g.thumb.right ).toBe( 390 - 15 );
		expect( g.title.right ).toBeLessThanOrEqual( g.thumb.left );
		expect( g.thumb.top ).toBeGreaterThanOrEqual( g.bar.top );
		// The buttons left the bar for a tab above its end corner, of its colour, with no gap.
		expect( g.tab.bottom ).toBe( g.bar.top );
		expect( g.tab.right ).toBe( 390 );
		expect( [ g.close.width, g.close.height ] ).toEqual( [ 44, 44 ] );
		expect( g.close.right ).toBe( 390 );
		expect( g.tabBg ).toBe( g.barBg );
		expect( g.closeHit ).toBe( true, 'Painted and clickable.' );
		expect( await page.locator( '.hprnb-bar__btn--toggle' ).isVisible() ).toBe( true, 'Pause, when on, stands beside the cross.' );
		expect( await page.evaluate( () => getComputedStyle( document.getElementById( 'hprnb-root' ) ).getPropertyValue( '--hprnb-m-ctrls' ).trim() ) ).toBe( '0' );

		// Pause off: the cross alone in the tab.
		setSettings( { mobile_layout: 'flow_image', mobile_lines: 2, rotate_interval: 60000, mobile_show_pause: false } );
		await page.goto( '/' );
		await expect( page.locator( '.hprnb-bar' ) ).toHaveAttribute( 'data-hprnb-init', '1' );
		expect( ( await geo() ).tab.width ).toBe( 44 );

		// Folded: the strip keeps the first line, the small picture and the chevron; the tab is gone.
		await page.evaluate( () => window.scrollTo( 0, 400 ) );
		await page.evaluate( () => window.scrollTo( 0, 900 ) );
		await expect.poll( () => page.evaluate( () => document.querySelector( '.hprnb-bar' ).classList.contains( 'hprnb-bar--collapsed' ) ) ).toBe( true );
		await page.waitForTimeout( 400 );
		const folded = await page.evaluate( () => {
			const bar = document.querySelector( '.hprnb-bar' ).getBoundingClientRect();
			const tab = document.querySelector( '.hprnb-bar__controls' ).getBoundingClientRect();
			const chevron = document.querySelector( '.hprnb-bar__btn--expand' );
			const thumb = document.querySelector( '.hprnb-bar__item:not([hidden]) .hprnb-bar__thumb' );
			return { tabInside: tab.top >= bar.top - 1, chevron: !! chevron && chevron.getBoundingClientRect().width > 0, thumb: getComputedStyle( thumb ).display !== 'none' && thumb.getBoundingClientRect().height <= 26, closeShown: getComputedStyle( document.querySelector( '.hprnb-bar__btn--close' ) ).display !== 'none' };
		} );
		// 2.11: the tab stays above the strip and now holds the unfold button.
		expect( folded ).toEqual( { tabInside: false, chevron: true, thumb: true, closeShown: false } );

		// The cross closes the bar.
		await page.evaluate( () => window.scrollTo( 0, 0 ) );
		await page.goto( '/' );
		await expect( page.locator( '.hprnb-bar' ) ).toHaveAttribute( 'data-hprnb-init', '1' );
		await page.locator( '.hprnb-bar__btn--close' ).click();
		await expect( page.locator( '.hprnb-bar' ) ).toBeHidden();
		await page.evaluate( () => localStorage.removeItem( 'hprnb_dismissed_until' ) );

		// Right to left (an Arabic label: dir="auto" reads the first strong character): the picture
		// and the tab move to the left corner.
		setSettings( { mobile_layout: 'flow_image', mobile_lines: 2, rotate_interval: 60000, label_text: 'آخر الأخبار' } );
		await page.goto( '/?hprnb_rtl=1' );
		await expect( page.locator( '.hprnb-bar' ) ).toHaveAttribute( 'data-hprnb-init', '1' );
		expect( await page.locator( '.hprnb-bar' ).evaluate( ( el ) => getComputedStyle( el ).direction ) ).toBe( 'rtl' );
		const r = await geo();
		expect( r.thumb.left ).toBe( 15 );
		expect( r.tab.left ).toBe( 0 );
		expect( r.tab.bottom ).toBe( r.bar.top );
		expect( r.title.left ).toBeGreaterThanOrEqual( r.thumb.right );

		// The admin: one click in Design, the preview follows at once.
		await page.goto( '/wp-login.php' );
		await page.fill( '#user_login', 'admin' );
		await page.fill( '#user_pass', 'admin' );
		await page.click( '#wp-submit' );
		await page.waitForURL( /wp-admin/ );
		setSettings( {} );
		await page.setViewportSize( { width: 1400, height: 1000 } );
		await page.goto( '/wp-admin/options-general.php?page=horizon-press-news-bar' );
		await page.click( '[data-hprnb-tab="mobile"]' );
		const sizeRow = page.locator( 'tr[data-hprnb-depends="mobile_show_thumbnail,mobile_layout:flow_image"]' ).first();
		await expect( sizeRow ).toHaveClass( /hprnb-row--inactive/ );
		await page.check( '#hprnb-field-mobile-layout-flow_image' );
		await expect( sizeRow ).not.toHaveClass( /hprnb-row--inactive/, 'Its picture size is reachable without the image switch.' );
		const preview = page.locator( '#hprnb-preview-root' );
		await expect( preview ).toHaveClass( /hprnb-root--m-ctrl-tab/ );
		await expect( preview ).toHaveClass( /hprnb-root--m-flow/ );
		await expect( preview ).toHaveClass( /hprnb-root--m-thumb-after/ );
		await expect( preview ).not.toHaveClass( /hprnb-root--m-ctrl-col/ );
		await page.click( '#hprnb-save' );
		await page.waitForURL( /settings-updated=true/ );
		expect( JSON.parse( wp( [ 'option', 'get', 'hprnb_settings', '--format=json' ] ) ).mobile_layout ).toBe( 'flow_image' );
		expect( errors ).toEqual( [] );
	} finally {
		media.forEach( ( id ) => wp( [ 'post', 'delete', id, '--force' ] ) );
		ids.forEach( ( id ) => wp( [ 'post', 'delete', id, '--force' ] ) );
		setSettings( { mobile_layout: 'flow', mobile_lines: 2 } );
	}
} );

test( 'v2.11: the new defaults, a premium fold with the unfold button in the tab, and a simple settings page', async ( { page } ) => {
	const errors = collectErrors( page );
	const body = Array.from( { length: 40 }, ( _, i ) =>
		`<p>Paragraphe ${ i + 1 }. Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.</p>` ).join( '\n' );
	const ids = [ 1, 2 ].map( ( i ) => wp( [ 'post', 'create', '--post_type=post', '--post_status=publish', `--post_title=Exemple : trafic perturbé sur la ligne B ce soir ${ i }`, `--post_content=${ body }`, '--porcelain' ] ) );
	const media = ids.map( ( id ) => wp( [ 'media', 'import', 'tests/e2e/fixtures/thumb.png', '--post_id=' + id, '--featured_image', '--porcelain' ] ) );
	const url = new URL( wp( [ 'post', 'url', ids[ 0 ] ] ) ).pathname;
	const aside = page.locator( '.hprnb-bar' );
	const folded = () => page.evaluate( () => document.querySelector( '.hprnb-bar' ).classList.contains( 'hprnb-bar--collapsed' ) );
	const geo = () => page.evaluate( () => {
		const t = document.querySelector( '.hprnb-bar__item:not([hidden]) .hprnb-bar__thumb' ).getBoundingClientRect();
		const l = document.querySelector( '.hprnb-bar__label' ).getBoundingClientRect();
		return { size: Math.round( t.width ), right: Math.round( t.right ), pill: Math.round( l.width ) };
	} );
	try {
		// Out of the box: the bar with the article picture, the cross alone in its tab, Continuous reading.
		setDefaultSettings( { rotate_interval: 60000 } );
		await page.setViewportSize( { width: 390, height: 844 } );
		await page.goto( url );
		await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
		await expect( page.locator( '#hprnb-root' ) ).toHaveClass( /hprnb-root--m-flow/ );
		await expect( page.locator( '#hprnb-root' ) ).toHaveClass( /hprnb-root--m-ctrl-tab/ );
		await expect( page.locator( '#hprnb-root' ) ).toHaveClass( /hprnb-root--m-pending/ );
		const B = await page.evaluate( () => Math.round( document.querySelectorAll( '.entry-content p, .wp-block-post-content p' )[ 38 ].getBoundingClientRect().top + window.scrollY ) - 844 + 30 );
		await page.evaluate( ( y ) => window.scrollTo( 0, y ), B );
		await expect.poll( folded ).toBe( false );
		await expect( page.locator( '#hprnb-root' ) ).not.toHaveClass( /hprnb-root--m-pending/ );
		await page.waitForTimeout( 600 );
		await expect( page.locator( '.hprnb-bar__btn--toggle' ) ).toBeHidden( 'Pause hidden by default.' );
		await expect( page.locator( '.hprnb-bar__btn--close' ) ).toBeVisible();
		expect( ( await page.locator( '.hprnb-bar__controls' ).boundingBox() ).width ).toBe( 44, 'The cross alone in the tab.' );
		const open = await geo();
		expect( open.size ).toBe( 48 );

		// The reader scrolls back up: the bar folds, and nothing jumps on the way — the picture shrinks
		// step by step against the same edge, the pill closes onto its dot.
		await page.evaluate( ( y ) => window.scrollTo( 0, y ), B - 150 );
		const frames = [];
		for ( let i = 0; i < 6; i++ ) {
			frames.push( await geo() );
			await page.waitForTimeout( 70 );
		}
		await page.waitForTimeout( 400 );
		frames.push( await geo() );
		expect( await folded() ).toBe( true );
		const sizes = frames.map( ( f ) => f.size );
		expect( sizes[ sizes.length - 1 ] ).toBe( 22 );
		expect( sizes.some( ( v ) => v > 22 && v < 48 ), 'Intermediate sizes: it moves, it does not snap. ' + sizes ).toBe( true );
		expect( sizes.every( ( v, i ) => i === 0 || v <= sizes[ i - 1 ] ), 'Only ever shrinking. ' + sizes ).toBe( true );
		expect( frames.every( ( f ) => f.right === open.right ), 'The outer edge never moves. ' + frames.map( ( f ) => f.right ) ).toBe( true );
		expect( frames[ frames.length - 1 ].pill ).toBe( 24 );
		expect( frames.some( ( f ) => f.pill > 24 && f.pill < open.pill ), 'The pill closes progressively.' ).toBe( true );
		expect( await aside.evaluate( ( el ) => getComputedStyle( el ).transitionTimingFunction ) ).toBe( 'cubic-bezier(0.22, 1, 0.36, 1)' );

		// Folded: the unfold button sits in the tab above the corner, like the cross of the open bar.
		const chevron = page.locator( '.hprnb-bar__btn--expand' );
		await expect( chevron ).toBeVisible();
		const tab = await chevron.boundingBox();
		const strip = await aside.boundingBox();
		expect( [ Math.round( tab.width ), Math.round( tab.height ) ] ).toEqual( [ 44, 44 ] );
		expect( Math.round( tab.y + tab.height ) ).toBe( Math.round( strip.y ) );
		expect( Math.round( tab.x + tab.width ) ).toBe( 390 );
		expect( await chevron.evaluate( ( el ) => getComputedStyle( el.querySelector( 'svg' ) ).animationName ) ).toBe( 'hprnb-tab-icon' );
		expect( await page.evaluate( () => getComputedStyle( document.body ).getPropertyValue( '--hprnb-tab' ).trim() ) ).toBe( '44px' );
		await chevron.click();
		await expect.poll( folded ).toBe( false );
		await expect( page.locator( '.hprnb-bar__btn--close' ) ).toBeVisible();

		// The settings page opens in simple mode: the essentials only, every value kept on save.
		await page.goto( '/wp-login.php' );
		await page.fill( '#user_login', 'admin' );
		await page.fill( '#user_pass', 'admin' );
		await page.click( '#wp-submit' );
		await page.waitForURL( /wp-admin/ );
		setDefaultSettings( { mobile_font_size: 18 } );
		await page.setViewportSize( { width: 1400, height: 1000 } );
		await page.goto( '/wp-admin/options-general.php?page=horizon-press-news-bar' );
		await expect( page.locator( '#hprnb-advanced-toggle' ) ).not.toBeChecked();
		await expect( page.locator( '[data-hprnb-tab="advanced"]' ) ).toBeHidden( 'The Advanced tab waits for the switch.' );
		await page.click( '[data-hprnb-tab="mobile"]' );
		await expect( page.locator( '#hprnb-field-mobile-layout-flow_image' ) ).toBeVisible();
		await expect( page.locator( '#hprnb-field-mobile-show-pause' ) ).toBeVisible();
		await expect( page.locator( '#hprnb-field-mobile-font-size' ) ).toBeHidden();
		await page.click( 'label[for="hprnb-advanced-toggle"]' );
		await expect( page.locator( '#hprnb-field-mobile-font-size' ) ).toBeVisible();
		await expect( page.locator( '#hprnb-field-mobile-font-size' ) ).toHaveValue( '18' );
		await expect( page.locator( '[data-hprnb-tab="advanced"]' ) ).toBeVisible();
		await page.click( '[data-hprnb-tab="advanced"]' );
		await page.click( 'label[for="hprnb-advanced-toggle"]' );
		await expect( page.locator( '[data-hprnb-panel="content"]' ) ).toBeVisible( 'Leaving advanced mode on the Advanced tab falls back to the first tab.' );
		await page.click( '#hprnb-save' );
		await page.waitForURL( /settings-updated=true/ );
		expect( JSON.parse( wp( [ 'option', 'get', 'hprnb_settings', '--format=json' ] ) ).mobile_font_size ).toBe( 18, 'A hidden setting is saved untouched.' );
		expect( errors ).toEqual( [] );
	} finally {
		media.forEach( ( id ) => wp( [ 'post', 'delete', id, '--force' ] ) );
		ids.forEach( ( id ) => wp( [ 'post', 'delete', id, '--force' ] ) );
		setSettings( { mobile_layout: 'flow', mobile_lines: 2 } );
	}
} );

test( 'v2.12: the bar with the article picture on an existing site, and a design picker drawn open and folded', async ( { page } ) => {
	const errors = collectErrors( page );
	const root = page.locator( '#hprnb-root' );
	try {
		// A 2.11 site on the card, pause shown, three lines: the update applies the design as delivered.
		setSettings( { mobile_layout: 'card', mobile_lines: 3, mobile_show_pause: true, mobile_label_style: 'strip' } );
		wp( [ 'option', 'update', 'hprnb_schema_version', '7' ] );
		await page.setViewportSize( { width: 390, height: 844 } );
		await page.goto( '/' );
		await expect( page.locator( '.hprnb-bar' ) ).toHaveAttribute( 'data-hprnb-init', '1' );
		expect( wp( [ 'option', 'get', 'hprnb_schema_version' ] ) ).toBe( '9', 'Brought up to the current schema (9 since 2.16).' );
		const stored = JSON.parse( wp( [ 'option', 'get', 'hprnb_settings', '--format=json' ] ) );
		expect( [ stored.mobile_layout, stored.mobile_lines, stored.mobile_show_pause, stored.mobile_label_style ] ).toEqual( [ 'flow_image', 2, false, 'pill' ] );
		expect( stored.urgent_enabled ).not.toBe( false, 'A site that was on keeps its URGENT bar through step 9.' );
		expect( stored.mobile_behavior ).toBe( 'fold', 'The behaviour the site had is kept.' );
		await expect( root ).toHaveClass( /hprnb-root--m-flow/ );
		await expect( root ).toHaveClass( /hprnb-root--m-ctrl-tab/ );
		await expect( page.locator( '.hprnb-bar__btn--toggle' ) ).toBeHidden();
		expect( errors ).toEqual( [] );

		// The picker: two boxes, each drawn open and folded; a click on the drawing chooses the design.
		await page.goto( '/wp-login.php' );
		await page.fill( '#user_login', 'admin' );
		await page.fill( '#user_pass', 'admin' );
		await page.click( '#wp-submit' );
		await page.waitForURL( /wp-admin/ );
		await page.setViewportSize( { width: 1400, height: 1000 } );
		await page.goto( '/wp-admin/options-general.php?page=horizon-press-news-bar' );
		await page.click( '[data-hprnb-tab="mobile"]' );
		await expect( page.locator( '#hprnb-field-mobile-layout-flow_image' ) ).toBeChecked();
		await expect( page.locator( '.hprnb-choices--designs .hprnb-mock' ) ).toHaveCount( 2 );
		await expect( page.locator( '.hprnb-mock--image .hprnb-mock__state' ) ).toHaveCount( 2 );
		const drawing = page.locator( '.hprnb-mock--image .hprnb-mock__state--open .hprnb-mock__screen' );
		await expect( drawing ).toBeVisible();
		const box = await drawing.boundingBox();
		expect( [ Math.round( box.width ), Math.round( box.height ) ] ).toEqual( [ 152, 98 ] );
		// The drawing's tab sits on the bar, against the end corner, as on the phone.
		const tab = await page.locator( '.hprnb-mock--image .hprnb-mock__state--open .hprnb-mock__tab' ).boundingBox();
		const bar = await page.locator( '.hprnb-mock--image .hprnb-mock__state--open .hprnb-mock__bar' ).boundingBox();
		expect( Math.round( tab.y + tab.height ) ).toBe( Math.round( bar.y ) );
		expect( Math.round( tab.x + tab.width ) ).toBe( Math.round( bar.x + bar.width ) );
		await page.locator( '.hprnb-mock--card .hprnb-mock__state--folded .hprnb-mock__screen' ).click();
		await expect( page.locator( '#hprnb-field-mobile-layout-card' ) ).toBeChecked();
		await expect( page.locator( '#hprnb-preview-root' ) ).toHaveClass( /hprnb-root--m-card/ );
		await page.locator( '.hprnb-mock--image .hprnb-mock__state--open .hprnb-mock__screen' ).click();
		await expect( page.locator( '#hprnb-field-mobile-layout-flow_image' ) ).toBeChecked();
		await expect( page.locator( '#hprnb-preview-root' ) ).toHaveClass( /hprnb-root--m-ctrl-tab/ );
		expect( errors ).toEqual( [] );
	} finally {
		setSettings( { mobile_layout: 'flow', mobile_lines: 2 } );
	}
} );

test( 'v2.14: the URGENT bar takes the place of the news bar, each article leaves on time, the news bar returns, closing is remembered until a newer flag', async ( { page } ) => {
	const errors = collectErrors( page );
	const root = page.locator( '#hprnb-root' );
	const urgent = page.locator( '.hprnb-bar--urgent' );
	const bar = page.locator( '.hprnb-bar:not(.hprnb-bar--urgent)' );
	const now = () => Math.floor( Date.now() / 1000 );
	const flag = ( id, since, until ) => {
		wp( [ 'post', 'meta', 'update', id, '_hprnb_urgent_since', String( since ) ] );
		wp( [ 'post', 'meta', 'update', id, '_hprnb_urgent_until', String( until ) ] );
		wp( [ 'option', 'update', 'hprnb_cache_epoch', 'e2e-' + Date.now() ] );
	};
	const body = '<p>Paragraphe de lecture, assez long pour donner de la hauteur à la page et laisser la barre attendre son moment.</p>'.repeat( 14 );
	const a = wp( [ 'post', 'create', '--post_type=post', '--post_status=publish', '--post_title=Urgent A — le conseil municipal adopte le budget 2027 et lance trois chantiers dans le centre-ville', '--post_content=' + body, '--porcelain' ] );
	const b = wp( [ 'post', 'create', '--post_type=post', '--post_status=publish', '--post_title=Urgent B — évacuation de la gare après une alerte', '--post_content=' + body, '--porcelain' ] );
	const n = wp( [ 'post', 'create', '--post_type=post', '--post_status=publish', '--post_title=Article normal du jour', '--post_content=' + body, '--porcelain' ] );
	const url = wp( [ 'post', 'url', n ] );
	try {
		setDefaultSettings( CHYRON );
		let t = now();
		flag( a, t - 60, t + 40 ); // Flagged first, lasts longer.
		flag( b, t - 30, t + 9 ); // Flagged last: first in the bar, first to go.

		// Phone: the red bar, in front at once (no wait, no fold), the news bar behind it, out of sight.
		await page.setViewportSize( { width: 390, height: 844 } );
		await page.goto( url );
		await expect( urgent ).toHaveAttribute( 'data-hprnb-init', '1' );
		await expect( root ).toHaveClass( /hprnb-root--urgent/ );
		await expect( root ).not.toHaveClass( /hprnb-root--m-pending/ );
		await expect( root ).toHaveAttribute( 'data-hprnb-urgent', '2' );
		await expect( urgent ).toHaveClass( /hprnb-bar--mode-rotate/ );
		await expect( urgent.locator( '.hprnb-bar__label-text' ) ).toHaveText( 'URGENT' );
		// 2.16: the label is a plate in the band's two colours swapped, no chevron.
		await expect( urgent.locator( '.hprnb-bar__label-chevron' ) ).toHaveCount( 0 );
		expect( await urgent.locator( '.hprnb-bar__label' ).evaluate( ( el ) => [ getComputedStyle( el ).backgroundColor, getComputedStyle( el ).color ] ) ).toEqual( [ 'rgb(255, 255, 255)', 'rgb(225, 29, 43)' ] );
		await expect( urgent.locator( '.hprnb-bar__item' ) ).toHaveCount( 2 );
		await expect( urgent.locator( '.hprnb-bar__item' ).first() ).toContainText( 'Urgent B', { useInnerText: false } );
		await expect( urgent.locator( '.hprnb-bar__item' ).nth( 1 ) ).toBeHidden();
		await expect( urgent.locator( 'img' ) ).toHaveCount( 0 );
		await expect( urgent.locator( '.hprnb-bar__btn--toggle' ) ).toBeHidden();
		expect( await urgent.evaluate( ( el ) => [ getComputedStyle( el ).backgroundColor, getComputedStyle( el ).position, Math.round( el.getBoundingClientRect().height ), getComputedStyle( el.querySelector( '.hprnb-bar__title' ) ).fontWeight, getComputedStyle( el.querySelector( '.hprnb-bar__label' ), '::before' ).display ] ) ).toEqual( [ 'rgb(225, 29, 43)', 'fixed', 76, '700', 'block' ] );
		// The close button in the tab above the end corner, 44px, as on the news bar; 2.16: it overlaps
		// the band by 1px, so no seam shows between the two.
		const tab = await urgent.locator( '.hprnb-bar__controls' ).boundingBox();
		const box = await urgent.boundingBox();
		expect( [ Math.round( tab.width ), Math.round( tab.height ), Math.round( tab.x + tab.width ), Math.round( tab.y + tab.height ) ] ).toEqual( [ 44, 44, 390, Math.round( box.y ) + 1 ] );
		expect( await bar.evaluate( ( el ) => [ getComputedStyle( el ).display, el.getAttribute( 'data-hprnb-init' ) ] ) ).toEqual( [ 'none', null ] );
		expect( await page.evaluate( () => { const cs = getComputedStyle( document.body ); return [ cs.getPropertyValue( '--hprnb-offset' ).trim(), cs.getPropertyValue( '--hprnb-tab' ).trim(), document.body.classList.contains( 'hprnb-m-pending' ) ]; } ) ).toEqual( [ '76px', '44px', false ] );

		// B's time is up: it leaves, A stays, the attribute follows.
		await expect.poll( () => urgent.locator( '.hprnb-bar__item' ).count(), { timeout: 15000 } ).toBe( 1 );
		await expect( urgent.locator( '.hprnb-bar__item' ).first() ).toContainText( 'Urgent A' );
		await expect( root ).toHaveAttribute( 'data-hprnb-urgent', '1' );
		expect( await urgent.evaluate( ( el ) => getComputedStyle( el.querySelector( '.hprnb-bar__viewport' ), '::after' ).display ) ).toBe( 'block', 'A single long headline fades out at the end of its last line.' );

		// A's time is up: the red bar is gone, the news bar waits for the reader as the server would have it.
		await expect( urgent ).toHaveCount( 0, { timeout: 40000 } );
		await expect( root ).not.toHaveClass( /hprnb-root--urgent/ );
		await expect( root ).toHaveClass( /hprnb-root--m-pending/ );
		await expect( root ).toHaveClass( /hprnb-root--reveal/ );
		await expect( root ).toHaveAttribute( 'data-hprnb-urgent', '0' );
		await expect( bar ).toHaveAttribute( 'data-hprnb-init', '1' );
		expect( await page.evaluate( () => [ document.body.classList.contains( 'hprnb-m-pending' ), getComputedStyle( document.body ).getPropertyValue( '--hprnb-offset' ).trim() ] ) ).toEqual( [ true, '0px' ] );
		const end = await page.evaluate( () => { const el = document.querySelector( '.entry-content, article' ); return el ? el.getBoundingClientRect().bottom + window.scrollY : document.body.scrollHeight; } );
		for ( const y of [ 300, 900, end - 700, end - 300 ] ) {
			await page.evaluate( ( v ) => window.scrollTo( 0, v ), y );
			await page.waitForTimeout( 200 );
		}
		await expect( root ).not.toHaveClass( /hprnb-root--m-pending/ );
		await expect( bar ).toBeVisible();
		await expect( bar.locator( '.hprnb-bar__label-text' ) ).toHaveText( 'EN CONTINU' );
		expect( errors ).toEqual( [] );

		// Desktop: one line, the news bar's own ticker, the buttons at the end.
		await page.setViewportSize( { width: 1366, height: 900 } );
		t = now();
		flag( a, t - 5, t + 600 );
		await page.goto( url );
		await expect( urgent ).toHaveAttribute( 'data-hprnb-init', '1' );
		// 2.16: one headline at a time, never a marquee; a 48px chyron in bold 17px.
		await expect( urgent ).toHaveClass( /hprnb-bar--mode-rotate/ );
		expect( await urgent.evaluate( ( el ) => [ Math.round( el.getBoundingClientRect().height ), getComputedStyle( el.querySelector( '.hprnb-bar__inner' ) ).display, getComputedStyle( el.querySelector( '.hprnb-bar__inner' ) ).gridTemplateAreas, getComputedStyle( el.querySelector( '.hprnb-bar__title' ) ).fontSize ] ) ).toEqual( [ 48, 'grid', '"label title ctrl"', '17px' ] );
		await expect( urgent.locator( '.hprnb-bar__btn--close' ) ).toBeVisible();
		expect( await page.evaluate( () => getComputedStyle( document.body ).getPropertyValue( '--hprnb-offset' ).trim() ) ).toBe( '48px' );

		// Closing hands over to the news bar and is remembered for this set — a newer flag opens it again.
		await page.setViewportSize( { width: 390, height: 844 } );
		await page.goto( url );
		await expect( urgent ).toHaveAttribute( 'data-hprnb-init', '1' );
		await urgent.locator( '.hprnb-bar__btn--close' ).click();
		await expect( urgent ).toHaveCount( 0 );
		await expect( root ).toHaveClass( /hprnb-root--m-pending/ );
		expect( Number( await page.evaluate( () => localStorage.getItem( 'hprnb_urgent_closed' ) ) ) ).toBe( t - 5 );
		await page.reload();
		await expect( bar ).toHaveAttribute( 'data-hprnb-init', '1' );
		await expect( urgent ).toHaveCount( 0 );
		flag( b, t + 1, t + 600 );
		await page.reload();
		await expect( urgent ).toHaveAttribute( 'data-hprnb-init', '1' );
		await expect( urgent.locator( '.hprnb-bar__item' ) ).toHaveCount( 2 );
		await expect( urgent.locator( '.hprnb-bar__item' ).first() ).toContainText( 'Urgent B' );

		// The REST body carries the red bar too, so a cached page gets it on its next visit.
		const rest = await page.evaluate( async () => { const r = await fetch( '/wp-json/hprnb/v1/items' ); return r.json(); } );
		expect( [ rest.urgent_count, rest.urgent_html.startsWith( '<aside class="hprnb-bar' ), rest.urgent_html.includes( 'hprnb-bar--urgent' ) ] ).toEqual( [ 2, true, true ] );
		expect( errors ).toEqual( [] );

		// The edit screen: the box, its state while it runs, "start over", and the end when unticked.
		await page.goto( '/wp-login.php' );
		await page.fill( '#user_login', 'admin' );
		await page.fill( '#user_pass', 'admin' );
		await page.click( '#wp-submit' );
		await page.waitForURL( /wp-admin/ );
		await page.setViewportSize( { width: 1400, height: 1000 } );
		await page.goto( '/wp-admin/post.php?post=' + a + '&action=edit' );
		await expect( page.locator( '#hprnb-urgent' ) ).toBeChecked();
		await expect( page.locator( '.hprnb-urgent-box__state' ) ).toContainText( 'Urgent until' );
		await expect( page.locator( '#hprnb-urgent-restart' ) ).toHaveCount( 1 );
		await expect( page.locator( 'link#hprnb-post-css' ) ).toHaveCount( 1 );
		await page.goto( '/wp-admin/post.php?post=' + n + '&action=edit' );
		await expect( page.locator( '#hprnb-urgent' ) ).not.toBeChecked();
		await expect( page.locator( '#hprnb-urgent-restart' ) ).toHaveCount( 0 );
		await expect( page.locator( '#hprnb-urgent-postbox' ) ).toContainText( 'For 10 minutes after you publish or update' );

		// The settings page: an Urgent tab whose preview shows the red bar, live label and colours.
		await page.goto( '/wp-admin/options-general.php?page=horizon-press-news-bar' );
		await page.click( '[data-hprnb-tab="urgent"]' );
		await expect( page.locator( '#hprnb-field-urgent-minutes' ) ).toHaveValue( '10' );
		await expect( page.locator( '#hprnb-preview-root' ) ).toHaveClass( /hprnb-root--urgent/, { timeout: 10000 } );
		await expect( page.locator( '#hprnb-preview-root .hprnb-bar--urgent .hprnb-bar__item' ) ).toHaveCount( 2 );
		await page.fill( '#hprnb-field-urgent-label', 'FLASH' );
		await expect( page.locator( '#hprnb-preview-root .hprnb-bar__label-text' ) ).toHaveText( 'FLASH' );
		await page.fill( '#hprnb-field-urgent-bg-color', '#7a0010' );
		await page.locator( '#hprnb-field-urgent-bg-color' ).dispatchEvent( 'input' );
		expect( await page.locator( '#hprnb-preview-root .hprnb-bar--urgent' ).evaluate( ( el ) => getComputedStyle( el ).backgroundColor ) ).toBe( 'rgb(122, 0, 16)' );
		await page.click( '[data-hprnb-tab="content"]' );
		await expect( page.locator( '#hprnb-preview-root' ) ).not.toHaveClass( /hprnb-root--urgent/, { timeout: 10000 } );
		await expect( page.locator( '#hprnb-preview-root .hprnb-bar--urgent' ) ).toHaveCount( 0 );
		expect( errors ).toEqual( [] );
	} finally {
		wp( [ 'post', 'delete', a, b, n, '--force' ] );
		setSettings( {} );
	}
} );

test( 'v2.15: the URGENT bar on the front page where the news bar stays away, the phone design on desktop, its own box first in the side column', async ( { page } ) => {
	const errors = collectErrors( page );
	const root = page.locator( '#hprnb-root' );
	const urgent = page.locator( '.hprnb-bar--urgent' );
	const now = () => Math.floor( Date.now() / 1000 );
	const flag = ( id, since, until ) => {
		wp( [ 'post', 'meta', 'update', id, '_hprnb_urgent_since', String( since ) ] );
		wp( [ 'post', 'meta', 'update', id, '_hprnb_urgent_until', String( until ) ] );
		wp( [ 'option', 'update', 'hprnb_cache_epoch', 'e2e-' + Date.now() ] );
	};
	const noFront = Object.fromEntries( Object.keys( defaults.contexts ).map( ( k ) => [ k, k !== 'front_page' ] ) );
	const a = wp( [ 'post', 'create', '--post_type=post', '--post_status=publish', '--post_title=Urgent — la une annonce une édition spéciale ce soir', '--porcelain' ] );
	try {
		// The news bar kept off the front page ("Everywhere except the home page"); one urgent article.
		setDefaultSettings( { ...CHYRON, display_scope: 'custom', contexts: noFront } );
		let t = now();
		flag( a, t - 30, t + 600 );
		await page.setViewportSize( { width: 390, height: 844 } );
		await page.goto( '/' );
		await expect( urgent ).toHaveAttribute( 'data-hprnb-init', '1' );
		await expect( root ).toHaveAttribute( 'data-hprnb-show', 'urgent' );
		await expect( root ).toHaveClass( /hprnb-root--urgent/ );
		await expect( page.locator( '.hprnb-bar:not(.hprnb-bar--urgent)' ) ).toHaveCount( 0 );
		expect( await urgent.evaluate( ( el ) => Math.round( el.getBoundingClientRect().height ) ) ).toBe( 76 );
		await page.setViewportSize( { width: 1366, height: 900 } );
		await page.goto( '/' );
		await expect( urgent ).toHaveAttribute( 'data-hprnb-init', '1' );
		expect( await urgent.evaluate( ( el ) => [ Math.round( el.getBoundingClientRect().height ), getComputedStyle( el.querySelector( '.hprnb-bar__inner' ) ).display ] ) ).toEqual( [ 48, 'grid' ] );

		// The second desktop design: the phone design, two lines, the close button in the tab at the screen corner.
		setDefaultSettings( { ...CHYRON, display_scope: 'custom', contexts: noFront, urgent_desktop_layout: 'mobile' } );
		await page.goto( '/' );
		await expect( urgent ).toHaveAttribute( 'data-hprnb-init', '1' );
		await expect( root ).toHaveClass( /hprnb-root--u-d-flow/ );
		await expect( urgent ).toHaveClass( /hprnb-bar--mode-rotate/ );
		await expect( urgent.locator( '.hprnb-bar__btn--toggle' ) ).toBeHidden();
		const bar = await urgent.boundingBox();
		const tab = await urgent.locator( '.hprnb-bar__controls' ).boundingBox();
		expect( [ Math.round( bar.height ), Math.round( tab.width ), Math.round( tab.height ), Math.round( tab.x + tab.width ), Math.round( tab.y + tab.height ) ] ).toEqual( [ 76, 44, 44, 1366, Math.round( bar.y ) + 1 ] );
		// A one-line headline sits in the middle: the bar keeps its height, the page keeps exactly that.
		await expect( urgent ).toHaveClass( /hprnb-bar--u-one/ );
		const centre = async () => { const t = await urgent.locator( '.hprnb-bar__item:not([hidden]) .hprnb-bar__title' ).boundingBox(); return Math.round( Math.abs( ( t.y + t.height / 2 ) - ( bar.y + bar.height / 2 ) ) ) <= 2; };
		await expect.poll( centre ).toBe( true );
		const title = await urgent.locator( '.hprnb-bar__item:not([hidden]) .hprnb-bar__title' ).boundingBox();
		expect( await page.evaluate( () => [ getComputedStyle( document.body ).paddingBottom, getComputedStyle( document.body ).getPropertyValue( '--hprnb-tab' ).trim() ] ) ).toEqual( [ '76px', '44px' ] );
		// The line keeps to the site's width: the plate first, then the headline.
		const plate = await urgent.locator( '.hprnb-bar__label' ).boundingBox();
		expect( Math.round( plate.x ) ).toBe( Math.round( ( 1366 - 1230 ) / 2 ) );
		expect( Math.round( title.x ) ).toBe( Math.round( plate.x ) ); // The headline flows around the plate.

		// The news bar kept to desktop on the front page: the red bar still reaches phones, then the
		// restriction is back and nothing is left on the phone.
		setDefaultSettings( { ...CHYRON, mobile_contexts: noFront } );
		t = now();
		flag( a, t - 30, t + 6 );
		await page.setViewportSize( { width: 390, height: 844 } );
		await page.goto( '/' );
		await expect( urgent ).toHaveAttribute( 'data-hprnb-init', '1' );
		await expect( root ).toHaveClass( /hprnb-news-hide-mobile/ );
		await expect( urgent ).toBeVisible();
		await expect( urgent ).toHaveCount( 0, { timeout: 15000 } );
		await expect( root ).toHaveClass( /hprnb-hide-mobile/ );
		await expect( root ).toBeHidden();
		expect( await page.evaluate( () => getComputedStyle( document.body ).paddingBottom ) ).toBe( '0px' );

		// A reader who closed the news bar still gets an urgent article.
		setDefaultSettings( CHYRON );
		t = now();
		flag( a, t - 5, t + 600 );
		await page.setViewportSize( { width: 1366, height: 900 } );
		await page.goto( '/' );
		await page.evaluate( () => localStorage.setItem( 'hprnb_dismissed_until', String( Date.now() + 3600000 ) ) );
		await page.reload();
		await expect( urgent ).toBeVisible();
		await expect( page.locator( '.hprnb-bar:not(.hprnb-bar--urgent)' ) ).toBeHidden();
		await page.evaluate( () => localStorage.removeItem( 'hprnb_dismissed_until' ) );
		expect( errors ).toEqual( [] );

		// The edit screen (classic editor, as on the client's site): the URGENT box first in the side
		// column, above Publish, even after the editor saved another order by dragging boxes around.
		wp( [ 'user', 'meta', 'update', '1', 'meta-box-order_post', JSON.stringify( { side: 'submitdiv,categorydiv,tagsdiv-post_tag,postimagediv,hprnb-post-controls', normal: '', advanced: '' } ), '--format=json' ] );
		const draft = wp( [ 'post', 'create', '--post_type=post', '--post_status=draft', '--post_title=Brouillon urgent', '--porcelain' ] );
		await page.goto( '/wp-login.php' );
		await page.fill( '#user_login', 'admin' );
		await page.fill( '#user_pass', 'admin' );
		await page.click( '#wp-submit' );
		await page.waitForURL( /wp-admin/ );
		await page.setViewportSize( { width: 1400, height: 1000 } );
		await page.goto( '/wp-admin/post.php?post=' + draft + '&action=edit&hprnb_classic=1' );
		expect( await page.$$eval( '#side-sortables > .postbox', ( boxes ) => boxes.map( ( b ) => b.id ).slice( 0, 2 ) ) ).toEqual( [ 'hprnb-urgent-postbox', 'submitdiv' ] );
		await expect( page.locator( '#hprnb-urgent-postbox' ) ).toBeVisible();
		await expect( page.locator( '#hprnb-urgent-postbox .hndle' ) ).toHaveText( 'URGENT bar' );
		await expect( page.locator( '#hprnb-post-controls #hprnb-urgent' ) ).toHaveCount( 0 );
		await page.check( '#hprnb-urgent' );
		await page.click( '#publish' );
		await expect( page.locator( '.hprnb-urgent-box__state' ) ).toContainText( 'Urgent until' );
		expect( Number( wp( [ 'post', 'meta', 'get', draft, '_hprnb_urgent_until' ] ) ) ).toBeGreaterThan( now() + 500 );
		wp( [ 'post', 'delete', draft, '--force' ] );

		// The settings: the URGENT bar's own page types (the front page ticked) and the two desktop designs.
		await page.goto( '/wp-admin/options-general.php?page=horizon-press-news-bar' );
		await page.click( '[data-hprnb-tab="urgent"]' );
		await expect( page.locator( '#hprnb-field-urgent-contexts-front_page' ) ).toBeChecked();
		await expect( page.locator( '#hprnb-field-urgent-desktop-layout-line' ) ).toBeChecked();
		await page.locator( 'label[for="hprnb-field-urgent-desktop-layout-mobile"]' ).click();
		await expect( page.locator( '#hprnb-preview-root' ) ).toHaveClass( /hprnb-root--u-d-flow/ );
		expect( errors ).toEqual( [] );
	} finally {
		try {
			wp( [ 'user', 'meta', 'delete', '1', 'meta-box-order_post' ] );
		} catch ( e ) {
			// Never saved: the scenario stopped before the edit screen.
		}
		wp( [ 'post', 'delete', a, '--force' ] );
		setSettings( {} );
	}
} );

test( 'v2.16: one switch per bar and per device — sub-choices hidden while their bar is off, the URGENT box and bar following them', async ( { page, browser } ) => {
	const errors = collectErrors( page );
	const root = page.locator( '#hprnb-root' );
	const urgent = page.locator( '.hprnb-bar--urgent' );
	const news = page.locator( '.hprnb-bar:not(.hprnb-bar--urgent)' );
	const now = () => Math.floor( Date.now() / 1000 );
	const a = wp( [ 'post', 'create', '--post_type=post', '--post_status=publish', '--post_title=Urgent — un seul appareil', '--porcelain' ] );
	const flag = ( since, until ) => {
		wp( [ 'post', 'meta', 'update', a, '_hprnb_urgent_since', String( since ) ] );
		wp( [ 'post', 'meta', 'update', a, '_hprnb_urgent_until', String( until ) ] );
		wp( [ 'option', 'update', 'hprnb_cache_epoch', 'e2e-' + Date.now() ] );
	};
	try {
		// The card, first of the first tab: each bar's devices show only while the bar is on.
		setDefaultSettings( CHYRON );
		await page.goto( '/wp-login.php' );
		await page.fill( '#user_login', 'admin' );
		await page.fill( '#user_pass', 'admin' );
		await page.click( '#wp-submit' );
		await page.waitForURL( /wp-admin/ );
		await page.setViewportSize( { width: 1400, height: 1000 } );
		await page.goto( '/wp-admin/options-general.php?page=horizon-press-news-bar' );
		await page.evaluate( () => localStorage.setItem( 'hprnb_admin_advanced', '0' ) );
		await page.goto( '/wp-admin/options-general.php?page=horizon-press-news-bar#content' );
		await expect( page.locator( '[data-hprnb-panel="content"] .hprnb-card' ).first() ).toHaveClass( /hprnb-card--bars/ );
		const sub = ( id ) => page.locator( 'tr:has(#hprnb-field-' + id + ')' );
		for ( const id of [ 'urgent-desktop', 'urgent-mobile', 'show-on-desktop', 'show-on-mobile' ] ) {
			await expect( sub( id ) ).toBeVisible();
		}
		await page.click( 'label[for="hprnb-field-urgent-enabled"]' );
		await expect( sub( 'urgent-desktop' ) ).toBeHidden();
		await expect( sub( 'urgent-mobile' ) ).toBeHidden();
		await expect( sub( 'show-on-desktop' ) ).toBeVisible();
		await page.click( 'label[for="hprnb-field-enabled"]' );
		await expect( sub( 'show-on-desktop' ) ).toBeHidden();
		await expect( sub( 'show-on-mobile' ) ).toBeHidden();
		await page.click( 'label[for="hprnb-field-enabled"]' );
		await page.click( 'label[for="hprnb-field-urgent-enabled"]' );
		await expect( sub( 'urgent-mobile' ) ).toBeVisible();
		await page.click( 'label[for="hprnb-field-urgent-mobile"]' );
		await page.click( '#submit' );
		await expect( page.locator( '#hprnb-field-urgent-mobile' ) ).not.toBeChecked();
		let stored = JSON.parse( wp( [ 'option', 'get', 'hprnb_settings', '--format=json' ] ) );
		expect( [ stored.urgent_enabled, stored.urgent_desktop, stored.urgent_mobile, stored.enabled, stored.show_on_desktop, stored.show_on_mobile ] ).toEqual( [ true, true, false, true, true, true ] );

		// The URGENT box on the edit screen: there while a device is on, gone with the last one.
		const draft = wp( [ 'post', 'create', '--post_type=post', '--post_status=draft', '--post_title=Brouillon', '--porcelain' ] );
		await page.goto( '/wp-admin/post.php?post=' + draft + '&action=edit&hprnb_classic=1' );
		await expect( page.locator( '#hprnb-urgent-postbox' ) ).toHaveCount( 1 );
		setDefaultSettings( { ...CHYRON, urgent_desktop: false, urgent_mobile: false } );
		await page.reload();
		await expect( page.locator( '#hprnb-urgent-postbox' ) ).toHaveCount( 0 );
		setDefaultSettings( { ...CHYRON, urgent_enabled: false } );
		await page.reload();
		await expect( page.locator( '#hprnb-urgent-postbox' ) ).toHaveCount( 0 );
		wp( [ 'post', 'delete', draft, '--force' ] );

		// On the site: the URGENT bar on desktop only — the phone keeps the news bar.
		setDefaultSettings( { ...CHYRON, urgent_mobile: false } );
		const t = now();
		flag( t - 30, t + 600 );
		await page.setViewportSize( { width: 1366, height: 900 } );
		await page.goto( '/' );
		await expect( urgent ).toHaveAttribute( 'data-hprnb-init', '1' );
		await expect( news ).toBeHidden();
		await page.setViewportSize( { width: 390, height: 844 } );
		await page.goto( '/' );
		await expect( root ).toHaveClass( /hprnb-root--u-no-m/ );
		await expect( urgent ).toBeHidden();
		await expect( news ).toHaveAttribute( 'data-hprnb-init', '1' );
		// Turned into a landscape tablet past 768px: the red bar takes over.
		await page.setViewportSize( { width: 1024, height: 768 } );
		await expect( urgent ).toHaveAttribute( 'data-hprnb-init', '1' );
		await expect( urgent ).toBeVisible();

		// The initial bar off, the URGENT bar on: the red bar alone; both off: nothing.
		setDefaultSettings( { ...CHYRON, enabled: false } );
		await page.setViewportSize( { width: 390, height: 844 } );
		await page.goto( '/' );
		await expect( urgent ).toHaveAttribute( 'data-hprnb-init', '1' );
		await expect( news ).toHaveCount( 0 );
		setDefaultSettings( { ...CHYRON, enabled: false, urgent_enabled: false } );
		await page.goto( '/' );
		await expect( root ).toHaveCount( 0 );
		stored = null;

		// Closing the news bar where the URGENT bar is off leaves the red bar to the other device.
		setDefaultSettings( { ...CHYRON, urgent_mobile: false } );
		await page.setViewportSize( { width: 390, height: 844 } );
		await page.goto( '/' );
		await expect( news ).toHaveAttribute( 'data-hprnb-init', '1' );
		for ( const y of [ 400, 1200, 2400, 4000 ] ) {
			await page.evaluate( ( v ) => window.scrollTo( 0, v ), y );
			await page.waitForTimeout( 150 );
		}
		await expect( news.locator( '.hprnb-bar__btn--close' ) ).toBeVisible();
		await news.locator( '.hprnb-bar__btn--close' ).click();
		await expect( news ).toHaveCount( 0 );
		await expect( root ).toHaveClass( /hprnb-root--urgent/ );
		await expect( urgent ).toBeHidden();
		expect( await page.evaluate( () => [ getComputedStyle( document.body ).paddingBottom, getComputedStyle( document.body ).getPropertyValue( '--hprnb-offset' ).trim() ] ) ).toEqual( [ '0px', '0px' ] );
		await page.setViewportSize( { width: 1024, height: 768 } );
		await expect( urgent ).toHaveAttribute( 'data-hprnb-init', '1' );
		await expect( urgent ).toBeVisible();
		expect( await page.evaluate( () => getComputedStyle( document.body ).paddingBottom ) ).toBe( '48px' );
		await page.evaluate( () => localStorage.removeItem( 'hprnb_dismissed_until' ) );

		// The URGENT bar off on desktop and no headline for the news bar: nothing on desktop, not even
		// the space the page keeps for a bar.
		const tag = wp( [ 'term', 'create', 'post_tag', 'Aucun article ' + Date.now(), '--porcelain' ] );
		setDefaultSettings( { ...CHYRON, urgent_desktop: false, tags_include: [ Number( tag ) ] } );
		await page.setViewportSize( { width: 1366, height: 900 } );
		await page.goto( '/' );
		await expect( root ).toHaveAttribute( 'data-hprnb-count', '0' );
		await expect( urgent ).toBeHidden();
		expect( await page.evaluate( () => [ getComputedStyle( document.body ).paddingBottom, getComputedStyle( document.body ).getPropertyValue( '--hprnb-offset' ).trim() ] ) ).toEqual( [ '0px', '0px' ] );
		await page.setViewportSize( { width: 390, height: 844 } );
		await expect( urgent ).toHaveAttribute( 'data-hprnb-init', '1' );
		await expect( urgent ).toBeVisible();
		expect( await page.evaluate( () => getComputedStyle( document.body ).paddingBottom ) ).toBe( '76px' );
		wp( [ 'term', 'delete', 'post_tag', tag ] );

		// A page from a page cache made before the phone was switched off: the REST refresh says so.
		// (A new browser: this one keeps the REST body of the steps above in its HTTP cache.)
		setDefaultSettings( { ...CHYRON, urgent_mobile: false } );
		const cached = await browser.newPage( { viewport: { width: 390, height: 844 }, baseURL: 'http://127.0.0.1:8080' } );
		cached.on( 'pageerror', ( e ) => errors.push( e.message ) );
		await cached.route( ( u ) => u.pathname === '/', async ( route ) => {
			const response = await route.fetch();
			const html = ( await response.text() ).replace( ' hprnb-root--u-no-m', '' ).replace( /data-hprnb-generated="\d+"/, 'data-hprnb-generated="' + ( now() - 3600 ) + '"' );
			await route.fulfill( { response, body: html } );
		} );
		await cached.goto( '/' );
		await expect( cached.locator( '#hprnb-root' ) ).toHaveClass( /hprnb-root--u-no-m/ );
		await expect( cached.locator( '.hprnb-bar--urgent' ) ).toBeHidden();
		await expect( cached.locator( '.hprnb-bar:not(.hprnb-bar--urgent)' ) ).toHaveAttribute( 'data-hprnb-init', '1' );
		await cached.close();

		// The settings preview follows the device switches: off on desktop, a note instead of the bar.
		setDefaultSettings( { ...CHYRON, urgent_desktop: false } );
		await page.setViewportSize( { width: 1400, height: 1000 } );
		await page.goto( '/wp-admin/options-general.php?page=horizon-press-news-bar#urgent' );
		await page.click( '[data-hprnb-tab="urgent"]' );
		await expect( page.locator( '#hprnb-preview-root .hprnb-bar--urgent' ) ).toHaveCount( 1, { timeout: 10000 } );
		await expect( page.locator( '#hprnb-preview-root .hprnb-bar--urgent' ) ).toBeHidden();
		expect( await page.locator( '.hprnb-preview__frame' ).evaluate( ( el ) => getComputedStyle( el, '::after' ).content ) ).toContain( 'switched off on this device' );
		await page.click( '#hprnb-preview-tab-mobile' );
		await expect( page.locator( '#hprnb-preview-root .hprnb-bar--urgent' ) ).toBeVisible();
		expect( await page.locator( '.hprnb-preview__frame' ).evaluate( ( el ) => getComputedStyle( el, '::after' ).content ) ).toBe( 'none' );
		expect( errors ).toEqual( [] );
	} finally {
		wp( [ 'post', 'delete', a, '--force' ] );
		setSettings( {} );
	}
} );

test( 'v2.16: a refreshed cached page keeps its waits and its running bars, short screens, the article placement, a remembered close, the preview', async ( { page, browser } ) => {
	const errors = collectErrors( page );
	const now = () => Math.floor( Date.now() / 1000 );
	const body = '<p>Paragraphe de lecture, assez long pour donner de la hauteur à la page et laisser la barre attendre son moment.</p>'.repeat( 12 );
	const n = wp( [ 'post', 'create', '--post_type=post', '--post_status=publish', '--post_title=Article du jour pour la recette', '--post_content=' + body, '--porcelain' ] );
	const url = new URL( wp( [ 'post', 'url', n ] ) );
	const path = url.pathname + url.search;
	let u = null;
	const flag = ( id, since, until ) => {
		wp( [ 'post', 'meta', 'update', id, '_hprnb_urgent_since', String( since ) ] );
		wp( [ 'post', 'meta', 'update', id, '_hprnb_urgent_until', String( until ) ] );
		wp( [ 'option', 'update', 'hprnb_cache_epoch', 'e2e-' + Date.now() ] );
	};
	// A page served from a page cache: rendered long ago, optionally with a headline since edited.
	const cachedPage = async ( width, height, edit ) => {
		const p = await browser.newPage( { viewport: { width, height }, baseURL: 'http://127.0.0.1:8080' } );
		p.on( 'pageerror', ( e ) => errors.push( e.message ) );
		await p.addInitScript( () => {
			// Markup swaps of the root once the page is parsed (the parser's own insertions do not count).
			window.__swaps = 0;
			let parsed = false;
			document.addEventListener( 'DOMContentLoaded', () => {
				parsed = true;
			} );
			new MutationObserver( ( list ) => {
				list.forEach( ( m ) => {
					if ( parsed && m.target.id === 'hprnb-root' && m.type === 'childList' ) {
						window.__swaps++;
					}
				} );
			} ).observe( document, { childList: true, subtree: true } );
		} );
		await p.route( ( x ) => x.pathname === url.pathname, async ( route ) => {
			const response = await route.fetch();
			let html = ( await response.text() ).replace( /data-hprnb-generated="\d+"/, 'data-hprnb-generated="' + ( now() - 3600 ) + '"' );
			if ( edit ) {
				html = html.replace( 'Article du jour pour la recette</span>', 'Ancien titre</span>' );
			}
			await route.fulfill( { response, body: html } );
		} );
		const rest = p.waitForResponse( ( r ) => r.url().includes( 'hprnb/v1/items' ) );
		await p.goto( path );
		await rest;
		await p.waitForTimeout( 400 );
		return p;
	};
	try {
		// 1. Phone, the news bar waiting for the paragraph (Continuous reading). The refresh brings the
		// same headlines: nothing is swapped and the bar keeps waiting.
		setDefaultSettings( CHYRON );
		let p = await cachedPage( 390, 844, false );
		expect( await p.evaluate( () => window.__swaps ) ).toBe( 0 );
		await expect( p.locator( '#hprnb-root' ) ).toHaveClass( /hprnb-root--m-pending/ );
		expect( await p.evaluate( () => [ document.body.classList.contains( 'hprnb-m-pending' ), getComputedStyle( document.body ).paddingBottom ] ) ).toEqual( [ true, '0px' ] );
		await p.close();
		// A headline edited since: the bars are swapped, and the new one still waits for the paragraph.
		p = await cachedPage( 390, 844, true );
		expect( await p.evaluate( () => window.__swaps ) ).toBeGreaterThan( 0 );
		await expect( p.locator( '.hprnb-bar' ).first() ).toContainText( 'Article du jour pour la recette' );
		await expect( p.locator( '#hprnb-root' ) ).toHaveClass( /hprnb-root--m-pending/ );
		expect( await p.evaluate( () => [ document.body.classList.contains( 'hprnb-m-pending' ), getComputedStyle( document.body ).paddingBottom ] ) ).toEqual( [ true, '0px' ] );
		await p.close();

		// 2. An URGENT article: an unchanged refresh keeps the running red bar (no second entrance).
		u = wp( [ 'post', 'create', '--post_type=post', '--post_status=publish', '--post_title=Urgent — la recette vérifie le bandeau', '--porcelain' ] );
		const t = now();
		flag( u, t - 30, t + 900 );
		p = await cachedPage( 1366, 900, false );
		expect( await p.evaluate( () => window.__swaps ) ).toBe( 0 );
		await expect( p.locator( '.hprnb-bar--urgent' ) ).toHaveAttribute( 'data-hprnb-init', '1' );
		await p.close();

		// 3. Phone: the headline appears after its plate (not the fold engine's animation); the
		// progress line and the bevel stop under the tab.
		const second = wp( [ 'post', 'create', '--post_type=post', '--post_status=publish', '--post_title=Urgent — second titre pour la rotation', '--porcelain' ] );
		flag( second, t - 10, t + 900 );
		await page.setViewportSize( { width: 390, height: 844 } );
		await page.goto( path );
		const urgent = page.locator( '.hprnb-bar--urgent' );
		await expect( urgent ).toHaveAttribute( 'data-hprnb-init', '1' );
		expect( await urgent.evaluate( ( el ) => getComputedStyle( el.querySelector( '.hprnb-bar__viewport' ) ).animationName ) ).toBe( 'hprnb-appear' );
		const progress = await urgent.locator( '.hprnb-bar__progress' ).boundingBox();
		expect( Math.round( progress.x + progress.width ) ).toBe( 390 - 44 );
		wp( [ 'post', 'delete', second, '--force' ] );

		// 4. A phone in landscape (short screen): the red bar fits the 44px the page keeps.
		await page.setViewportSize( { width: 740, height: 360 } );
		await page.goto( path );
		await expect( urgent ).toHaveAttribute( 'data-hprnb-init', '1' );
		expect( await urgent.evaluate( ( el ) => [ Math.round( el.getBoundingClientRect().height ), getComputedStyle( document.body ).paddingBottom ] ) ).toEqual( [ 44, '44px' ] );

		// 5. The news bar placed inside the article: the fixed red bar still gets its space.
		setDefaultSettings( { ...CHYRON, desktop_placement: 'inline' } );
		await page.setViewportSize( { width: 1366, height: 900 } );
		await page.goto( path );
		await expect( urgent ).toHaveAttribute( 'data-hprnb-init', '1' );
		await expect( page.locator( '#hprnb-root' ) ).toHaveClass( /hprnb-root--d-inflow/ );
		expect( await page.evaluate( () => getComputedStyle( document.body ).paddingBottom ) ).toBe( '48px' );
		wp( [ 'post', 'delete', u, '--force' ] );
		u = null;
		wp( [ 'option', 'update', 'hprnb_cache_epoch', 'e2e-' + Date.now() ] );

		// 6. A remembered close: the hidden news bar does not run, and nothing is lifted for it.
		setDefaultSettings( CHYRON );
		await page.goto( path );
		await page.evaluate( () => localStorage.setItem( 'hprnb_dismissed_until', String( Date.now() + 3600000 ) ) );
		await page.reload();
		await expect( page.locator( '#hprnb-root' ) ).toBeHidden();
		await page.waitForTimeout( 500 );
		expect( await page.evaluate( () => [ document.querySelector( '.hprnb-bar' ).getAttribute( 'data-hprnb-init' ), getComputedStyle( document.body ).getPropertyValue( '--hprnb-offset' ).trim(), window.hprnbBar.state().offset ] ) ).toEqual( [ null, '0px', 0 ] );
		await page.evaluate( () => localStorage.removeItem( 'hprnb_dismissed_until' ) );

		// 7. The settings preview: never hidden by the admin window, and the URGENT heights follow the form.
		setDefaultSettings( { ...CHYRON, show_on_desktop: false } );
		await page.goto( '/wp-login.php' );
		await page.fill( '#user_login', 'admin' );
		await page.fill( '#user_pass', 'admin' );
		await page.click( '#wp-submit' );
		await page.waitForURL( /wp-admin/ );
		await page.setViewportSize( { width: 1400, height: 1000 } );
		await page.goto( '/wp-admin/options-general.php?page=horizon-press-news-bar#content' );
		await expect( page.locator( '#hprnb-preview-root' ) ).toHaveClass( /hprnb-device-all/ );
		await expect( page.locator( '#hprnb-preview-root' ) ).toBeVisible();
		await page.click( '[data-hprnb-tab="urgent"]' );
		await expect( page.locator( '#hprnb-preview-root .hprnb-bar--urgent' ) ).toHaveCount( 1, { timeout: 10000 } );
		const uHeight = () => page.locator( '#hprnb-preview-root' ).evaluate( ( el ) => el.style.getPropertyValue( '--hprnb-u-height' ) );
		expect( await uHeight() ).toBe( '48px' );
		await page.click( 'label:has(input[name="hprnb_settings[urgent_desktop_layout]"][value="mobile"])' );
		expect( await uHeight() ).toBe( '76px' );
		await page.click( 'label:has(input[name="hprnb_settings[urgent_desktop_layout]"][value="line"])' );
		await page.fill( '#hprnb-field-urgent-font-size', '22' );
		await page.locator( '#hprnb-field-urgent-font-size' ).dispatchEvent( 'input' );
		expect( await uHeight() ).toBe( '53px' );
		expect( errors ).toEqual( [] );
	} finally {
		wp( [ 'post', 'delete', n, '--force' ] );
		if ( u ) {
			wp( [ 'post', 'delete', u, '--force' ] );
		}
		setSettings( {} );
	}
} );

test( 'v2.17: Breaking News — each whole headline typed in, held, then the next; one headline typed once; constant height; keyboard; reduced motion; the chyron still selectable', async ( { page } ) => {
	const errors = collectErrors( page );
	const urgent = page.locator( '.hprnb-bar--urgent' );
	const now = () => Math.floor( Date.now() / 1000 );
	const flag = ( id, since, until ) => {
		wp( [ 'post', 'meta', 'update', id, '_hprnb_urgent_since', String( since ) ] );
		wp( [ 'post', 'meta', 'update', id, '_hprnb_urgent_until', String( until ) ] );
		wp( [ 'option', 'update', 'hprnb_cache_epoch', 'e2e-' + Date.now() ] );
	};
	const titles = [
		'Premiers résultats à Rabat-Océan : Mehdi Bensaid en tête, Mustapha El Khalfi deuxième',
		'Le gouvernement annonce un plan d’urgence de 12 milliards de dirhams pour l’automobile et convoque les constructeurs dès lundi matin à Casablanca',
	];
	const ids = titles.map( ( title ) => wp( [ 'post', 'create', '--post_type=post', '--post_status=publish', '--post_title=' + title, '--porcelain' ] ) );
	const urls = Object.fromEntries( ids.map( ( id ) => [ id, wp( [ 'post', 'url', id ] ) ] ) );
	const state = () => page.evaluate( () => {
		const u = document.querySelector( '.hprnb-bar--urgent' );
		const li = u && u.querySelector( '.hprnb-bar__item.is-current' );
		const title = li && li.querySelector( '.hprnb-bar__title' );
		// How far the typing is: where the part not typed yet starts (CSS highlight, or its span); -1 when whole.
		let typed = -1;
		const rest = title && window.CSS && CSS.highlights && CSS.highlights.get( 'hprnb-u-bn-rest' );
		if ( rest ) {
			for ( const range of rest ) {
				if ( title.contains( range.startContainer ) ) {
					typed = range.startOffset;
				}
			}
		} else if ( title && title.querySelector( '.hprnb-bar__rest' ) ) {
			typed = title.textContent.length - title.querySelector( '.hprnb-bar__rest' ).textContent.length;
		}
		return {
			id: li ? li.getAttribute( 'data-hprnb-id' ) : null,
			href: li ? li.querySelector( '.hprnb-bar__link' ).href : null,
			text: title ? title.textContent : null,
			typed,
			h: u ? Math.round( u.getBoundingClientRect().height ) : 0,
			pad: parseFloat( getComputedStyle( document.body ).paddingBottom ),
		};
	} );
	try {
		setDefaultSettings();
		const t = now();
		flag( ids[ 0 ], t - 60, t + 900 );
		flag( ids[ 1 ], t - 30, t + 900 ); // Flagged last: typed first.

		await page.setViewportSize( { width: 390, height: 844 } );
		await page.goto( '/' );
		await expect( urgent ).toHaveAttribute( 'data-hprnb-init', '1' );
		await expect( urgent ).toHaveClass( /hprnb-bar--bn/ );
		await expect( urgent ).toHaveClass( /hprnb-bar--mode-type/ );
		await expect( urgent ).toHaveAttribute( 'aria-live', 'off' );
		await expect( page.locator( '#hprnb-root' ) ).toHaveClass( /hprnb-root--u-bn/ );

		// Sampled every 250ms over 16s: the title keeps its whole text for screen readers, its link
		// points to its article, the height never moves and the page keeps exactly that height.
		const samples = [];
		for ( let k = 0; k < 64; k++ ) {
			samples.push( { ...( await state() ), at: Date.now() } );
			await page.waitForTimeout( 250 );
		}
		const heights = [ ...new Set( samples.map( ( s ) => s.h ) ) ];
		expect( heights.length ).toBe( 1 );
		expect( samples.every( ( s ) => Math.abs( s.pad - s.h ) <= 1 ) ).toBe( true );
		for ( const s of samples ) {
			expect( s.text ).toBe( titles[ ids.indexOf( s.id ) ] );
			expect( s.href ).toBe( urls[ s.id ] );
		}
		expect( samples[ 0 ].id ).toBe( ids[ 1 ] );
		expect( samples.some( ( s ) => s.typed > 0 && s.typed < titles[ 1 ].length ) ).toBe( true, 'Typed in, letter by letter.' );
		// The first headline stays whole at least 5s after its typing, then the next is typed in.
		const whole = samples.find( ( s ) => s.id === ids[ 1 ] && s.typed === -1 );
		const next = samples.find( ( s ) => s.id === ids[ 0 ] );
		expect( whole ).toBeTruthy();
		expect( next ).toBeTruthy();
		expect( next.at - whole.at ).toBeGreaterThanOrEqual( 5000 );
		expect( samples.some( ( s ) => s.id === ids[ 0 ] && s.typed > 0 ) ).toBe( true );

		// Keyboard: the link of the headline takes a visible focus, the headline is whole at once and stays.
		const link = urgent.locator( '.hprnb-bar__item.is-current .hprnb-bar__link' );
		for ( let k = 0; k < 80; k++ ) {
			await page.keyboard.press( 'Tab' );
			if ( await page.evaluate( () => !! document.activeElement.closest( '.hprnb-bar--urgent .hprnb-bar__item.is-current' ) ) ) {
				break;
			}
		}
		await expect( link ).toBeFocused();
		const focused = await state();
		expect( focused.typed ).toBe( -1 );
		expect( await link.evaluate( ( el ) => getComputedStyle( el.closest( '.hprnb-bar--urgent' ) ).outlineStyle !== 'none' || getComputedStyle( el ).outlineStyle !== 'none' ) ).toBe( true );
		await page.waitForTimeout( 7000 );
		expect( ( await state() ).id ).toBe( focused.id );
		expect( errors ).toEqual( [] );

		// One headline: typed once, then it stays — no pause button, no second typing.
		flag( ids[ 1 ], t - 30, t - 1 );
		await page.goto( '/' );
		await expect( urgent ).toHaveAttribute( 'data-hprnb-init', '1' );
		await expect( urgent.locator( '.hprnb-bar__btn--toggle' ) ).toBeHidden();
		await page.waitForTimeout( 3200 );
		const once = await state();
		expect( [ once.id, once.typed ] ).toEqual( [ ids[ 0 ], -1 ] );
		for ( let k = 0; k < 12; k++ ) {
			await page.waitForTimeout( 500 );
			expect( ( await state() ).typed ).toBe( -1 );
		}

		// Reduced motion: the whole headline at once, never typed.
		flag( ids[ 1 ], t - 30, t + 900 );
		await page.emulateMedia( { reducedMotion: 'reduce' } );
		await page.goto( '/' );
		await expect( urgent ).toHaveAttribute( 'data-hprnb-init', '1' );
		for ( let k = 0; k < 8; k++ ) {
			expect( ( await state() ).typed ).toBe( -1 );
			await page.waitForTimeout( 150 );
		}
		await page.emulateMedia( { reducedMotion: 'no-preference' } );

		// Desktop, a long headline: whole, clear of the label and of the close button.
		await page.setViewportSize( { width: 1366, height: 900 } );
		await page.goto( '/' );
		await expect( urgent ).toHaveAttribute( 'data-hprnb-init', '1' );
		const box = await urgent.evaluate( ( el ) => {
			const r = ( s ) => el.querySelector( s ).getBoundingClientRect();
			const title = el.querySelector( '.hprnb-bar__item.is-current .hprnb-bar__title' );
			return { label: r( '.hprnb-bar__label' ), title: title.getBoundingClientRect(), close: r( '.hprnb-bar__btn--close' ), fits: title.scrollHeight <= title.clientHeight + 1 && title.scrollWidth <= title.clientWidth + 1 };
		} );
		expect( box.fits ).toBe( true );
		expect( box.title.left ).toBeGreaterThanOrEqual( box.label.right );
		expect( box.title.right ).toBeLessThanOrEqual( box.close.left );

		// The chyron is still there to choose.
		setDefaultSettings( { urgent_design: 'chyron' } );
		await page.goto( '/' );
		await expect( urgent ).toHaveAttribute( 'data-hprnb-init', '1' );
		await expect( urgent ).toHaveClass( /hprnb-bar--mode-rotate/ );
		await expect( urgent ).not.toHaveClass( /hprnb-bar--bn/ );
		await expect( page.locator( '#hprnb-root' ) ).not.toHaveClass( /hprnb-root--u-bn/ );
		expect( errors ).toEqual( [] );
	} finally {
		wp( [ 'post', 'delete', ...ids, '--force' ] );
		setSettings( {} );
	}
} );

test( 'v2.17: Breaking News engine — the pause button completes the headline, the mouse lets it finish, reduced motion followed live, a late script never types again, spans without CSS highlights, closing clears everything', async ( { browser } ) => {
	const now = () => Math.floor( Date.now() / 1000 );
	const flag = ( id, since, until ) => {
		wp( [ 'post', 'meta', 'update', id, '_hprnb_urgent_since', String( since ) ] );
		wp( [ 'post', 'meta', 'update', id, '_hprnb_urgent_until', String( until ) ] );
		wp( [ 'option', 'update', 'hprnb_cache_epoch', 'e2e-' + Date.now() ] );
	};
	const titles = [
		'Séisme au large d’Al Hoceïma : les secours mobilisés dans toute la région du Rif',
		'Le gouvernement annonce un plan d’urgence de 12 milliards de dirhams pour l’automobile et convoque les constructeurs dès lundi matin à Casablanca',
	];
	const ids = titles.map( ( title ) => wp( [ 'post', 'create', '--post_type=post', '--post_status=publish', '--post_title=' + title, '--porcelain' ] ) );
	const state = ( page ) => page.evaluate( () => {
		const u = document.querySelector( '.hprnb-bar--urgent' );
		const li = u && u.querySelector( '.hprnb-bar__item.is-current' );
		const title = li && li.querySelector( '.hprnb-bar__title' );
		let typed = -1;
		const rest = title && window.CSS && CSS.highlights && CSS.highlights.get( 'hprnb-u-bn-rest' );
		if ( rest ) {
			for ( const range of rest ) {
				if ( title.contains( range.startContainer ) ) {
					typed = range.startOffset;
				}
			}
		}
		const span = title && title.querySelector( '.hprnb-bar__rest' );
		if ( span ) {
			typed = title.textContent.length - span.textContent.length;
		}
		return { id: li ? li.getAttribute( 'data-hprnb-id' ) : null, typed, text: title ? title.textContent : null, spans: title ? title.children.length : 0, marks: window.CSS && CSS.highlights ? CSS.highlights.size : -1 };
	} );
	const typing = async ( page ) => {
		await expect.poll( async () => ( await state( page ) ).typed, { intervals: [ 50 ], timeout: 8000 } ).toBeGreaterThan( 0 );
	};
	const open = async ( init, waitUntil = 'load' ) => {
		const context = await browser.newContext( { viewport: { width: 1366, height: 900 } } );
		const page = await context.newPage();
		const errors = collectErrors( page );
		if ( init ) {
			await init( page );
		}
		await page.goto( '/', { waitUntil } );
		return { context, page, errors, urgent: page.locator( '.hprnb-bar--urgent' ) };
	};
	try {
		setDefaultSettings();
		const t = now();
		flag( ids[ 0 ], t - 60, t + 900 );
		flag( ids[ 1 ], t - 30, t + 900 );

		// The pause button while a headline types: whole at once, then it stays; Play goes on.
		let { context, page, errors, urgent } = await open();
		await typing( page );
		const toggle = urgent.locator( '.hprnb-bar__btn--toggle' );
		await toggle.click();
		await page.mouse.move( 5, 5 );
		const paused = await state( page );
		expect( paused.typed ).toBe( -1 );
		expect( paused.marks ).toBe( 0 );
		await expect( toggle ).toHaveAttribute( 'aria-label', 'Play' );
		await page.waitForTimeout( 7000 );
		expect( ( await state( page ) ).id ).toBe( paused.id );
		await toggle.click();
		await page.mouse.move( 5, 5 );
		await expect( toggle ).toHaveAttribute( 'aria-label', 'Pause' );
		await expect.poll( async () => ( await state( page ) ).id, { timeout: 15000 } ).not.toBe( paused.id );

		// The mouse over the bar lets the headline finish typing, then holds it; leaving goes on.
		await page.reload();
		await typing( page );
		await urgent.locator( '.hprnb-bar__label' ).hover();
		const hovered = await state( page );
		await expect.poll( async () => ( await state( page ) ).typed, { timeout: 4000 } ).toBe( -1 );
		await page.waitForTimeout( 8000 );
		expect( ( await state( page ) ).id ).toBe( hovered.id );
		await page.mouse.move( 5, 5 );
		await expect.poll( async () => ( await state( page ) ).id, { timeout: 15000 } ).not.toBe( hovered.id );

		// Reduced motion switched on while a headline types: whole at once.
		await expect.poll( async () => ( await state( page ) ).typed, { intervals: [ 50 ], timeout: 15000 } ).toBeGreaterThan( 0 );
		await page.emulateMedia( { reducedMotion: 'reduce' } );
		await expect.poll( async () => ( await state( page ) ).typed, { timeout: 1000 } ).toBe( -1 );
		await page.emulateMedia( { reducedMotion: 'no-preference' } );

		// Closing: no highlight, no timer left behind.
		await urgent.locator( '.hprnb-bar__btn--close' ).click();
		await expect( urgent ).toHaveCount( 0 );
		expect( await page.evaluate( () => CSS.highlights.size ) ).toBe( 0 );
		await page.waitForTimeout( 6000 );
		expect( errors ).toEqual( [] );
		await context.close();

		// A script later than the stylesheet's wait (1.5s): the first headline is already there, whole; never erased to be typed again.
		( { context, page, errors, urgent } = await open( ( p ) => p.route( '**/hprnb-bar.min.js*', async ( route ) => {
			await new Promise( ( resolve ) => setTimeout( resolve, 2500 ) );
			await route.continue();
		} ), 'commit' ) );
		await page.waitForTimeout( 2000 );
		expect( await urgent.evaluate( ( el ) => [ el.hasAttribute( 'data-hprnb-init' ), getComputedStyle( el.querySelector( '.hprnb-bar__list' ) ).opacity, getComputedStyle( el.querySelector( '.hprnb-bar__item' ) ).visibility ] ) ).toEqual( [ false, '1', 'visible' ] );
		await expect( urgent ).toHaveAttribute( 'data-hprnb-init', '1' );
		const late = await state( page );
		expect( [ late.id, late.typed ] ).toEqual( [ ids[ 1 ], -1 ] );
		for ( let k = 0; k < 10; k++ ) {
			await page.waitForTimeout( 200 );
			expect( ( await state( page ) ).typed ).toBe( -1 );
		}
		expect( errors ).toEqual( [] );
		await context.close();

		// Without the CSS Custom Highlight API: the same typing in spans, and the title put back after.
		( { context, page, errors, urgent } = await open( ( p ) => p.addInitScript( () => {
			window.Highlight = undefined;
		} ) ) );
		await typing( page );
		const split = await state( page );
		expect( split.spans ).toBe( 4 );
		expect( split.text ).toBe( titles[ 1 ] );
		await expect.poll( async () => ( await state( page ) ).typed, { timeout: 5000 } ).toBe( -1 );
		expect( await state( page ) ).toMatchObject( { spans: 0, text: titles[ 1 ] } );
		expect( errors ).toEqual( [] );
		await context.close();
	} finally {
		wp( [ 'post', 'delete', ...ids, '--force' ] );
		setSettings( {} );
	}
} );

test( 'v2.17: the article being read is left out of the URGENT bar, also after an in-page navigation; with nothing left the bar hides without leaving space', async ( { page } ) => {
	const errors = collectErrors( page );
	const root = page.locator( '#hprnb-root' );
	const urgent = page.locator( '.hprnb-bar--urgent' );
	const now = () => Math.floor( Date.now() / 1000 );
	const flag = ( id, since, until ) => {
		wp( [ 'post', 'meta', 'update', id, '_hprnb_urgent_since', String( since ) ] );
		wp( [ 'post', 'meta', 'update', id, '_hprnb_urgent_until', String( until ) ] );
		wp( [ 'option', 'update', 'hprnb_cache_epoch', 'e2e-' + Date.now() ] );
	};
	const body = '<p>Paragraphe de lecture, assez long pour donner de la hauteur à la page.</p>'.repeat( 10 );
	const a = wp( [ 'post', 'create', '--post_type=post', '--post_status=publish', '--post_title=Flash A — l’article que l’on lit', '--post_content=' + body, '--porcelain' ] );
	const b = wp( [ 'post', 'create', '--post_type=post', '--post_status=publish', '--post_title=Flash B — une autre urgence', '--post_content=' + body, '--porcelain' ] );
	const urlA = wp( [ 'post', 'url', a ] );
	const urlB = wp( [ 'post', 'url', b ] );
	const pathA = new URL( urlA ).pathname + new URL( urlA ).search;
	const listed = () => urgent.evaluate( ( el ) => [ ...el.querySelectorAll( '.hprnb-bar__list > .hprnb-bar__item' ) ].map( ( li ) => li.getAttribute( 'data-hprnb-id' ) ) );
	try {
		setDefaultSettings( { mobile_behavior: 'always' } );
		const t = now();
		flag( a, t - 60, t + 900 );
		flag( b, t - 30, t + 900 );
		await page.setViewportSize( { width: 390, height: 844 } );

		// On article A: only B, before the first paint (server) and in the rotation (script).
		await page.goto( pathA );
		await expect( urgent ).toHaveAttribute( 'data-hprnb-init', '1' );
		expect( await listed() ).toEqual( [ b ] );
		await expect( urgent.locator( '.hprnb-bar__item.is-current .hprnb-bar__link' ) ).toHaveAttribute( 'href', urlB );
		await expect( urgent.locator( '.hprnb-bar__btn--toggle' ) ).toBeHidden();

		// Only A urgent: on A the red bar steps aside, the news bar has the page.
		flag( b, t - 30, t - 1 );
		await page.goto( pathA );
		await expect( page.locator( '.hprnb-bar:not(.hprnb-bar--urgent)' ) ).toHaveAttribute( 'data-hprnb-init', '1' );
		await expect( root ).not.toHaveClass( /hprnb-root--urgent/ );
		await expect( urgent ).toBeHidden();
		const newsPad = await page.evaluate( () => getComputedStyle( document.body ).paddingBottom );

		// The theme moves on to another article without reloading (history API): the red bar is back.
		await page.evaluate( () => history.pushState( {}, '', '/?hprnb-next-article=1' ) );
		await expect( root ).toHaveClass( /hprnb-root--urgent/ );
		await expect( urgent ).toBeVisible();
		await expect( urgent.locator( '.hprnb-bar__item.is-current .hprnb-bar__link' ) ).toHaveAttribute( 'href', urlA );
		await expect.poll( () => page.evaluate( () => Math.round( parseFloat( getComputedStyle( document.body ).paddingBottom ) - document.querySelector( '.hprnb-bar--urgent' ).getBoundingClientRect().height ) ) ).toBe( 0 );
		// And back to A (the reader scrolls up): it steps aside again.
		await page.evaluate( ( url ) => history.pushState( {}, '', url ), urlA );
		await expect( root ).not.toHaveClass( /hprnb-root--urgent/ );
		await expect( urgent ).toBeHidden();
		expect( await page.evaluate( () => getComputedStyle( document.body ).paddingBottom ) ).toBe( newsPad );

		// Nothing else to show at all (news bar off): the root hides and keeps no space.
		setDefaultSettings( { mobile_behavior: 'always', enabled: false } );
		await page.goto( pathA );
		await expect( root ).toBeHidden();
		expect( await page.evaluate( () => [ document.body.classList.contains( 'hprnb-reserve' ), getComputedStyle( document.body ).paddingBottom ] ) ).toEqual( [ false, '0px' ] );
		await page.evaluate( () => history.pushState( {}, '', '/?hprnb-next-article=2' ) );
		await expect( urgent ).toBeVisible();
		await expect( urgent ).toHaveAttribute( 'data-hprnb-init', '1' );
		await expect.poll( () => page.evaluate( () => parseFloat( getComputedStyle( document.body ).paddingBottom ) ) ).toBeGreaterThan( 40 );

		// The checkbox off: the article being read is announced like any other.
		setDefaultSettings( { mobile_behavior: 'always', urgent_exclude_current: false } );
		await page.goto( pathA );
		await expect( urgent ).toHaveAttribute( 'data-hprnb-init', '1' );
		expect( await listed() ).toEqual( [ a ] );

		// Closing: the red bar goes, remembered for this set of urgent articles.
		await urgent.locator( '.hprnb-bar__btn--close' ).click();
		await expect( urgent ).toHaveCount( 0 );
		await page.reload();
		await expect( page.locator( '.hprnb-bar:not(.hprnb-bar--urgent)' ) ).toHaveAttribute( 'data-hprnb-init', '1' );
		await expect( urgent ).toHaveCount( 0 );
		expect( errors ).toEqual( [] );
	} finally {
		wp( [ 'post', 'delete', a, b, '--force' ] );
		setSettings( {} );
	}
} );
