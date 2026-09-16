import { test, expect } from '@playwright/test';
import { setSettings, wp, collectErrors, countRequests, noHorizontalOverflow, routeStaleDocument, defaults, OLD } from './helpers.mjs';


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
	setSettings();
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
	setSettings( { close_button: true } );
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
	expect( Math.round( ( await bar.boundingBox() ).height ) ).toBe( 40, 'Inherited marquee: one line, the 40px minimum.' );
	expect( await noHorizontalOverflow( page ) ).toBe( true );

	setSettings( { mobile_layout: 'inline', mobile_ticker_mode: 'static' } );
	await page.goto( '/' );
	expect( Math.round( ( await bar.boundingBox() ).height ) ).toBe( 54, 'Inline, two 16px lines: 2 × 21 + 12' );

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
	setSettings( { close_button: true, remember_dismiss: false } );
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
	setSettings( { show_relative_time: true, show_separator: true, separator_char: '|', separator_after_last: false, ticker_enabled: false } );
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
	setSettings( { desktop_layout: 'stacked', desktop_label_style: 'pill', desktop_label_dot: true, desktop_show_counter: true, desktop_lines: 2, desktop_show_progress: true, ticker_enabled: true, ticker_mode: 'rotate', rotate_interval: 1500, align_container: false } );
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
	setSettings( { desktop_lines: 3, label_position: 'start', ticker_enabled: false, desktop_label_style: 'strip', align_container: false } );
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
	setSettings( { desktop_lines: 3, ticker_enabled: true, ticker_mode: 'marquee' } );
	await page.goto( '/' );
	await expect( aside ).toHaveClass( /hprnb-bar--marquee-on/ );
	expect( Math.round( ( await aside.boundingBox() ).height ) ).toBe( 40 );
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

test( 'v2: flow card, collapsed strip, chevron, offset contract, deep collapse, keyboard, landscape, desktop container, Jannah offset', async ( { page } ) => {
	setSettings( { rotate_interval: 1500 } );
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
	expect( await page.locator( '.hprnb-bar__viewport' ).evaluate( ( el ) => getComputedStyle( el, '::after' ).display + getComputedStyle( el, '::after' ).content ) ).toBe( 'block"…"' );
	expect( await label.evaluate( ( el ) => getComputedStyle( el ).paddingLeft + ' ' + getComputedStyle( el ).paddingRight ) ).toBe( '12px 14px' );
	expect( await label.evaluate( ( el ) => getComputedStyle( el, '::before' ).animationName ) ).toBe( 'hprnb-pulse' );
	expect( await page.evaluate( () => getComputedStyle( document.body ).getPropertyValue( '--hprnb-offset' ).trim() ) ).toBe( '76px' );
	expect( await page.evaluate( () => parseFloat( getComputedStyle( document.body ).paddingBottom ) ) ).toBe( 76 );
	expect( await aside.evaluate( ( el ) => getComputedStyle( el ).backgroundColor ) ).toMatch( /27, 28, 32|0\.105882/ );
	expect( await page.locator( '.hprnb-bar__progress' ).evaluate( ( el ) => Math.round( el.getBoundingClientRect().height ) ) ).toBe( 3 );
	await expect( page.locator( '.hprnb-bar__counter' ) ).toHaveCount( 0 );
	expect( await page.evaluate( () => window.hprnbBar.state() ) ).toEqual( { mobile: true, collapsed: false, height: 76, offset: 76 } );

	// Scrolling down: 40px strip (pill + first line + chevron), links disabled, body class and offset updated, event emitted.
	await page.evaluate( () => window.scrollTo( 0, 0 ) );
	await page.evaluate( () => window.scrollTo( 0, 700 ) );
	await expect( aside ).toHaveClass( /hprnb-bar--collapsed/ );
	await expect( page.locator( 'body' ) ).toHaveClass( /hprnb-is-collapsed/ );
	await page.waitForTimeout( 400 );
	expect( Math.round( 667 - ( await aside.boundingBox() ).y ) ).toBe( 40 );
	expect( await page.evaluate( () => getComputedStyle( document.body ).getPropertyValue( '--hprnb-offset' ).trim() ) ).toBe( '40px' );
	expect( await page.evaluate( () => window.__states.map( ( s ) => `${ s.collapsed }:${ s.offset }` ) ) ).toContain( 'true:40' );
	// The collapsed pill shrinks to a round beacon, hard against the gutter, and pulses like a button.
	expect( await label.evaluate( ( el ) => getComputedStyle( el ).inlineSize ) ).toBe( '24px' );
	expect( Math.round( ( await label.boundingBox() ).x ) ).toBeLessThanOrEqual( 15 );
	expect( await page.locator( '.hprnb-bar__label-text' ).evaluate( ( el ) => getComputedStyle( el ).display ) ).toBe( 'none' );
	expect( await label.evaluate( ( el ) => getComputedStyle( el, '::before' ).display ) ).toBe( 'block' );
	// The collapsed pill pulses like a button and the single visible line ends with an ellipsis.
	expect( await label.evaluate( ( el ) => getComputedStyle( el ).animationName ) ).toBe( 'hprnb-beacon' );
	expect( await page.locator( '.hprnb-bar__viewport' ).evaluate( ( el ) => getComputedStyle( el, '::after' ).top ) ).toBe( '0px' );
	const chevron = page.locator( '.hprnb-bar__btn--expand' );
	await expect( chevron ).toBeVisible();
	await expect( chevron ).toHaveAttribute( 'aria-label', 'Show the latest news' );
	await expect( page.locator( '.hprnb-bar__btn--toggle' ) ).toBeHidden();
	expect( await page.locator( '.hprnb-bar__link' ).first().evaluate( ( el ) => getComputedStyle( el ).pointerEvents ) ).toBe( 'none' );
	await chevron.click();
	await expect( aside ).not.toHaveClass( /hprnb-bar--collapsed/ );
	await expect( page.locator( 'body' ) ).not.toHaveClass( /hprnb-is-collapsed/ );
	expect( await label.evaluate( ( el ) => getComputedStyle( el ).animationName ) ).toBe( 'hprnb-beacon', 'The pill beats on arrival (default pulse: appear).' );
	expect( await label.evaluate( ( el ) => getComputedStyle( el ).animationIterationCount ) ).toBe( '3', 'And then stops.' );
	expect( await page.evaluate( () => getComputedStyle( document.body ).getPropertyValue( '--hprnb-offset' ).trim() ) ).toBe( '76px' );

	// "Pill only" strip option.
	setSettings( { rotate_interval: 1500, mobile_peek: 'label' } );
	await page.goto( '/' );
	await expect( root ).toHaveClass( /hprnb-root--peek-label/ );
	await page.evaluate( () => window.scrollTo( 0, 700 ) );
	await expect( aside ).toHaveClass( /hprnb-bar--collapsed/ );
	await expect.poll( () => page.locator( '.hprnb-bar__viewport' ).evaluate( ( el ) => getComputedStyle( el ).opacity ) ).toBe( '0' );

	// Keyboard: a field outside the bar slides it away (offset 0), blur brings it back.
	setSettings( { rotate_interval: 1500 } );
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
	setSettings( { rotate_interval: 1500, mobile_deep_collapse: false } );
	await page.reload();
	await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
	await page.waitForTimeout( 300 );
	await expect( aside ).not.toHaveClass( /hprnb-bar--collapsed/ );

	// Landscape phone: one line of 44px, never collapsed, buttons back.
	setSettings( { rotate_interval: 1500 } );
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
	setSettings( { theme_offset: false, align_container: false } );
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

		// Off on both profiles: no image in the markup at all.
		setSettings();
		await page.setViewportSize( { width: 1366, height: 800 } );
		await page.goto( '/' );
		await expect( page.locator( '.hprnb-bar__thumb' ) ).toHaveCount( 0 );
		await expect( aside ).not.toHaveClass( /hprnb-bar--has-thumbs/ );

		// Desktop only: the markup carries the image, the mobile profile hides it.
		setSettings( { desktop_show_thumbnail: true, ticker_enabled: false } );
		await page.goto( '/' );
		await expect( aside ).toHaveClass( /hprnb-bar--has-thumbs/ );
		await expect( root ).toHaveClass( /hprnb-root--d-thumb(\s|$)/ );
		await expect( root ).not.toHaveClass( /hprnb-root--m-thumb/ );
		await expect( thumb ).toBeVisible();
		const size = await thumb.boundingBox();
		expect( Math.round( size.width ) ).toBe( 32 );
		expect( Math.round( size.height ) ).toBe( 32 );
		expect( size.x ).toBeLessThan( ( await title.boundingBox() ).x, 'Default position: before the headline.' );
		expect( Math.round( ( await aside.boundingBox() ).height ) ).toBe( 40 );

		// Desktop, after the headline and bigger: the bar grows with it.
		setSettings( { desktop_show_thumbnail: true, desktop_thumb_position: 'after', desktop_thumb_size: 56, ticker_enabled: false } );
		await page.goto( '/' );
		await expect( root ).toHaveClass( /hprnb-root--d-thumb-after/ );
		expect( ( await thumb.boundingBox() ).x ).toBeGreaterThan( ( await title.boundingBox() ).x );
		expect( Math.round( ( await thumb.boundingBox() ).width ) ).toBe( 56 );
		expect( Math.round( ( await aside.boundingBox() ).height ) ).toBe( 64, '56 + 12 − 4' );
		expect( await page.evaluate( () => parseFloat( getComputedStyle( document.body ).paddingBottom ) ) ).toBe( 64 );
		expect( await noHorizontalOverflow( page ) ).toBe( true );

		// Mobile card: its own column before the headline; the pill becomes the red dot to keep the width.
		await page.setViewportSize( { width: 375, height: 667 } );
		setSettings( { mobile_show_thumbnail: true, mobile_thumb_position: 'before', rotate_interval: 60000 } );
		await page.goto( '/' );
		await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
		await expect( root ).toHaveClass( /hprnb-root--m-thumb(\s|$)/ );
		await expect( root ).not.toHaveClass( /hprnb-root--d-thumb/ );
		const image = await thumb.boundingBox();
		expect( Math.round( image.width ) ).toBe( 48 );
		expect( Math.round( image.x ) ).toBe( 15, 'Flush with the gutter, out of the text flow.' );
		expect( Math.round( ( await aside.boundingBox() ).height ) ).toBe( 76, 'The card keeps its height.' );
		const viewport = await page.locator( '.hprnb-bar__viewport' ).boundingBox();
		expect( Math.round( viewport.x ) ).toBe( 73, 'The headline column starts after the image column.' );
		expect( await page.locator( '.hprnb-bar__label-text' ).evaluate( ( el ) => getComputedStyle( el ).display ) ).toBe( 'block', 'The red pill keeps its text next to an image (compact option off by default).' );

		// Oversized image: clamped to the headline block, the card never grows.
		setSettings( { mobile_show_thumbnail: true, mobile_thumb_size: 80, rotate_interval: 60000 } );
		await page.goto( '/' );
		await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
		expect( Math.round( ( await thumb.boundingBox() ).height ) ).toBe( 50, '2 × 26 − 2' );
		expect( Math.round( ( await aside.boundingBox() ).height ) ).toBe( 76 );
		expect( ( await thumb.boundingBox() ).x ).toBeGreaterThan( 200, 'Default position: after the headline, before the buttons.' );

		// Collapsed strip: the image is resized to a single line (kept by default).
		await page.evaluate( () => window.scrollTo( 0, 700 ) );
		await expect( aside ).toHaveClass( /hprnb-bar--collapsed/ );
		await expect( thumb ).toBeVisible();
		expect( Math.round( ( await thumb.boundingBox() ).height ) ).toBe( 22, 'One line (26) minus 4.' );
		expect( await noHorizontalOverflow( page ) ).toBe( true );
		expect( errors ).toEqual( [] );
	} finally {
		media.forEach( ( id ) => wp( [ 'post', 'delete', id, '--force' ] ) );
		ids.forEach( ( id ) => wp( [ 'post', 'delete', id, '--force' ] ) );
		setSettings();
	}
} );

test( 'mobile: stacked buttons, pulsing pill with its text, image kept in the collapsed strip — each one configurable', async ( { page } ) => {
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

		// Defaults: close above pause in a single column, full pill pulsing with its text, image in the strip.
		setSettings( { mobile_show_thumbnail: true, rotate_interval: 60000 } );
		await page.goto( '/' );
		await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
		await expect( root ).toHaveClass( /hprnb-root--m-ctrl-col/ );
		// Since 2.5 the default is a few beats on arrival, not a permanent one.
		await expect( root ).toHaveClass( /hprnb-root--m-pulse-appear/ );
		expect( await page.locator( '.hprnb-bar__label' ).evaluate( ( el ) => getComputedStyle( el ).animationIterationCount ) ).toBe( '3' );
		await expect( root ).toHaveClass( /hprnb-root--m-peek-thumb/ );
		const close = await page.locator( '.hprnb-bar__btn--close' ).boundingBox();
		const toggle = await page.locator( '.hprnb-bar__btn--toggle' ).boundingBox();
		expect( Math.round( close.x ) ).toBe( Math.round( toggle.x ), 'Same column.' );
		expect( close.y ).toBeLessThan( toggle.y, 'Close on top, pause below.' );
		expect( Math.round( close.width ) ).toBe( 40 );
		const stackedWidth = ( await viewport.boundingBox() ).width;
		expect( await label.evaluate( ( el ) => getComputedStyle( el ).animationName ) ).toBe( 'hprnb-beacon' );
		expect( await page.locator( '.hprnb-bar__label-text' ).evaluate( ( el ) => getComputedStyle( el ).display ) ).toBe( 'block', 'The open card keeps the red pill and its text.' );
		expect( Math.round( ( await aside.boundingBox() ).height ) ).toBe( 76 );

		// Collapsed: the image stays at the end of the line, never taller than one line (26 − 4).
		await page.evaluate( () => window.scrollTo( 0, 700 ) );
		await expect( aside ).toHaveClass( /hprnb-bar--collapsed/ );
		await expect( thumb ).toBeVisible();
		const strip = await thumb.boundingBox();
		expect( Math.round( strip.height ) ).toBe( 22 );
		expect( Math.round( strip.width ) ).toBe( 22 );
		const chevron = await page.locator( '.hprnb-bar__btn--expand' ).boundingBox();
		expect( strip.x ).toBeLessThan( chevron.x, 'Between the headline and the chevron.' );
		expect( ( await viewport.boundingBox() ).width ).toBeLessThan( strip.x );

		// Side by side, no image in the strip, pulse only when collapsed.
		setSettings( { mobile_show_thumbnail: true, rotate_interval: 60000, mobile_controls_layout: 'row', mobile_peek_thumbnail: false, mobile_label_pulse: 'collapsed' } );
		await page.goto( '/' );
		await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
		await expect( root ).not.toHaveClass( /hprnb-root--m-ctrl-col/ );
		const rowClose = await page.locator( '.hprnb-bar__btn--close' ).boundingBox();
		const rowToggle = await page.locator( '.hprnb-bar__btn--toggle' ).boundingBox();
		expect( Math.round( rowClose.y ) ).toBe( Math.round( rowToggle.y ), 'Same row.' );
		expect( Math.round( rowClose.height ) ).toBe( 40 );
		expect( ( await viewport.boundingBox() ).width ).toBeLessThan( stackedWidth, 'Two columns leave less room for the headline.' );
		expect( await label.evaluate( ( el ) => getComputedStyle( el ).animationName ) ).toBe( 'none' );
		await page.evaluate( () => window.scrollTo( 0, 700 ) );
		await expect( aside ).toHaveClass( /hprnb-bar--collapsed/ );
		expect( await label.evaluate( ( el ) => getComputedStyle( el ).animationName ) ).toBe( 'hprnb-beacon' );
		await expect( thumb ).toBeHidden();

		// Never: no pulse at all. And the compact pill option, off by default.
		setSettings( { mobile_show_thumbnail: true, rotate_interval: 60000, mobile_label_pulse: 'never', mobile_label_compact: true } );
		await page.goto( '/' );
		await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
		await expect( root ).toHaveClass( /hprnb-root--m-label-compact/ );
		expect( await page.locator( '.hprnb-bar__label-text' ).evaluate( ( el ) => getComputedStyle( el ).display ) ).toBe( 'none' );
		expect( await label.evaluate( ( el ) => getComputedStyle( el ).animationName ) ).toBe( 'none' );
		await page.evaluate( () => window.scrollTo( 0, 700 ) );
		await expect( aside ).toHaveClass( /hprnb-bar--collapsed/ );
		expect( await label.evaluate( ( el ) => getComputedStyle( el ).animationName ) ).toBe( 'none' );
		expect( await noHorizontalOverflow( page ) ).toBe( true );
		expect( errors ).toEqual( [] );
	} finally {
		media.forEach( ( id ) => wp( [ 'post', 'delete', id, '--force' ] ) );
		ids.forEach( ( id ) => wp( [ 'post', 'delete', id, '--force' ] ) );
		setSettings();
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
	setSettings( { reveal_mode: 'scroll', reveal_value: 400, rotate_interval: 60000 } );
	await page.goto( '/' );
	await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
	await expect( root ).toHaveClass( /hprnb-root--pending/ );
	await expect( page.locator( 'body' ) ).toHaveClass( /hprnb-pending/ );
	expect( await page.evaluate( () => getComputedStyle( document.body ).getPropertyValue( '--hprnb-offset' ).trim() ) ).toBe( '0px' );
	expect( await page.evaluate( () => getComputedStyle( document.body ).paddingBottom ) ).toBe( '0px' );
	expect( await page.evaluate( () => window.hprnbBar.state().offset ) ).toBe( 0 );
	expect( await peek() ).toBeLessThanOrEqual( 0, 'Out of view.' );

	// Past the threshold it comes in and reserves its space for good.
	await page.evaluate( () => window.scrollTo( 0, 500 ) );
	await expect( root ).not.toHaveClass( /hprnb-root--pending/ );
	await expect( page.locator( 'body' ) ).not.toHaveClass( /hprnb-pending/ );
	await expect.poll( peek ).toBeGreaterThan( 0 );
	expect( await page.evaluate( () => parseInt( getComputedStyle( document.body ).paddingBottom, 10 ) ) ).toBeGreaterThan( 0 );
	await page.evaluate( () => window.scrollTo( 0, 0 ) );
	await expect( root ).not.toHaveClass( /hprnb-root--pending/, 'Once shown it stays.' );

	// A percentage of the page works the same way.
	setSettings( { reveal_mode: 'percent', reveal_value: 50, rotate_interval: 60000 } );
	await page.goto( '/' );
	await expect( root ).toHaveClass( /hprnb-root--pending/ );
	await page.evaluate( () => window.scrollTo( 0, document.documentElement.scrollHeight ) );
	await expect( root ).not.toHaveClass( /hprnb-root--pending/ );

	// Collapse on a threshold: it waits for 300px, then stays collapsed on the way back up.
	setSettings( { mobile_collapse_mode: 'threshold', mobile_collapse_after: 300, rotate_interval: 60000 } );
	await page.goto( '/' );
	await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
	await page.evaluate( () => window.scrollTo( 0, 200 ) );
	await expect( aside ).not.toHaveClass( /hprnb-bar--collapsed/ );
	await page.evaluate( () => window.scrollTo( 0, 400 ) );
	await expect( aside ).toHaveClass( /hprnb-bar--collapsed/ );
	await page.evaluate( () => window.scrollTo( 0, 350 ) );
	await expect( aside ).toHaveClass( /hprnb-bar--collapsed/ );

	// Always collapsed: the strip is the default state and a tap opens the card.
	setSettings( { mobile_collapse_mode: 'immediate', rotate_interval: 60000 } );
	await page.goto( '/' );
	await expect( aside ).toHaveClass( /hprnb-bar--collapsed/ );
	await page.locator( '.hprnb-bar__btn--expand' ).click();
	await expect( aside ).not.toHaveClass( /hprnb-bar--collapsed/ );

	// Collapsing turned off entirely: no class, no chevron, whatever the scrolling.
	setSettings( { mobile_hide_on_scroll: false, rotate_interval: 60000 } );
	await page.goto( '/' );
	await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
	await expect( root ).not.toHaveClass( /hprnb-root--m-collapse/ );
	await page.evaluate( () => window.scrollTo( 0, 900 ) );
	await expect( aside ).not.toHaveClass( /hprnb-bar--collapsed/ );
	await expect( page.locator( '.hprnb-bar__btn--expand' ) ).toHaveCount( 0 );

	// Buttons inside (default) versus floating above the bar: the headline gains the width.
	setSettings( { rotate_interval: 60000 } );
	await page.goto( '/' );
	await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
	const insideWidth = ( await viewport.boundingBox() ).width;
	setSettings( { mobile_controls_place: 'outside', rotate_interval: 60000 } );
	await page.goto( '/' );
	await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
	await expect( root ).toHaveClass( /hprnb-root--m-ctrl-out/ );
	const barBox = await aside.boundingBox();
	const outClose = await page.locator( '.hprnb-bar__btn--close' ).boundingBox();
	const outToggle = await page.locator( '.hprnb-bar__btn--toggle' ).boundingBox();
	expect( outClose.y + outClose.height ).toBeLessThanOrEqual( barBox.y + 1, 'Above the bar.' );
	expect( Math.round( outToggle.y ) ).toBe( Math.round( outClose.y ), 'Side by side.' );
	expect( ( await viewport.boundingBox() ).width ).toBeGreaterThan( insideWidth );
	// Placed outside the bar, which clips its own overflow: they must really be painted, not just
	// positioned — a hit test at their centre catches the clipping the bounding box cannot see.
	const painted = ( selector ) => page.evaluate( ( sel ) => {
		const el = document.querySelector( sel );
		const rect = el.getBoundingClientRect();
		const top = document.elementFromPoint( rect.x + rect.width / 2, rect.y + rect.height / 2 );
		return el === top || el.contains( top );
	}, selector );
	expect( await painted( '.hprnb-bar__btn--close' ) ).toBe( true );
	expect( await painted( '.hprnb-bar__btn--toggle' ) ).toBe( true );
	await expect( root ).not.toHaveClass( /hprnb-root--m-ctrl-col/, 'The floating group is a row of its own.' );
	await page.locator( '.hprnb-bar__btn--toggle' ).click();
	await expect( aside ).toHaveClass( /hprnb-bar--paused/, 'And really clickable.' );
	await page.locator( '.hprnb-bar__btn--toggle' ).click();

	// Each button can be hidden on mobile on its own.
	setSettings( { mobile_show_pause: false, mobile_show_close: false, rotate_interval: 60000 } );
	await page.goto( '/' );
	await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
	await expect( page.locator( '.hprnb-bar__btn--toggle' ) ).toBeHidden();
	await expect( page.locator( '.hprnb-bar__btn--close' ) ).toBeHidden();
	expect( ( await viewport.boundingBox() ).width ).toBeGreaterThan( insideWidth );

	// The accent edge, on by default and switchable.
	const edge = () => aside.evaluate( ( el ) => getComputedStyle( el ).boxShadow );
	expect( await edge() ).toContain( 'rgb(206, 48, 41) 0px 2px 0px 0px inset' );
	setSettings( { accent_edge: false, rotate_interval: 60000 } );
	await page.goto( '/' );
	await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
	expect( await edge() ).not.toContain( 'rgb(206, 48, 41) 0px 2px 0px 0px inset' );
	expect( await noHorizontalOverflow( page ) ).toBe( true );
	expect( errors ).toEqual( [] );

	// Touch: tapping Pause twice really resumes — the emulated hover must not keep it paused.
	setSettings( { rotate_interval: 3000 } );
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
		setSettings();
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

		// Both profiles refused: nothing is rendered at all.
		setSettings( {
			desktop_contexts: { ...defaults.desktop_contexts, front_page: false },
			mobile_contexts: { ...defaults.mobile_contexts, front_page: false },
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

		// The "discover" card on a phone: the pill above a 16:10 image at the start of the line, the
		// headline two sizes up beside it, and the close button alone in a tab above the corner.
		await page.setViewportSize( { width: 390, height: 780 } );
		setSettings( { mobile_layout: 'card', rotate_interval: 60000, mobile_show_pause: false, close_button: true } );
		await page.goto( '/' );
		await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
		await expect( root ).toHaveClass( /hprnb-root--m-card/ );
		await expect( root ).not.toHaveClass( /hprnb-root--m-ctrl-col/, 'The card places its own buttons.' );
		const thumb = page.locator( '.hprnb-bar__item:not([hidden]) .hprnb-bar__thumb' );
		await expect( thumb ).toBeVisible( { timeout: 10000 } );
		const image = await thumb.boundingBox();
		expect( Math.round( image.width ) ).toBe( 96 );
		expect( Math.round( image.height ) ).toBe( 75, '5:4 box.' );
		const label = await page.locator( '.hprnb-bar__label' ).boundingBox();
		expect( label.width ).toBeLessThan( 200, 'The heading shrink-wraps, it is not a full-width band.' );
		// The card floats 8px clear of the edges and pads itself by 12px.
		const card = await aside.boundingBox();
		expect( Math.round( card.x ) ).toBe( 8 );
		expect( Math.round( card.width ) ).toBe( 374 );
		expect( await aside.evaluate( ( el ) => getComputedStyle( el ).borderBottomLeftRadius ) ).toBe( '12px' );
		expect( Math.round( image.x ) ).toBe( 20, 'The image opens the line.' );
		expect( label.x ).toBeGreaterThan( image.x + image.width, 'The pill takes the first line of the text column, beside the image.' );
		expect( Math.abs( label.y - image.y ) ).toBeLessThanOrEqual( 2, 'Level with the top of the image, not on a row of its own.' );
		const headline = page.locator( '.hprnb-bar__item:not([hidden]) .hprnb-bar__title' );
		const headlineBox = await headline.boundingBox();
		expect( headlineBox.x ).toBeGreaterThan( image.x + image.width, 'The headline sits beside the image.' );
		expect( headlineBox.y ).toBeGreaterThanOrEqual( label.y + label.height - 2, 'And starts on the second line, under the pill.' );
		expect( await headline.evaluate( ( el ) => getComputedStyle( el ).fontSize ) ).toBe( '18px', 'Two sizes above the 16px profile.' );
		expect( await headline.evaluate( ( el ) => getComputedStyle( el ).webkitLineClamp ) ).toBe( '2', 'Never a third line.' );
		expect( await headline.evaluate( ( el ) => getComputedStyle( el ).fontWeight ) ).toBe( '700' );
		// 12 + max(75, 20 + 6 + 2 × 22) + 12 = 99, inside the 96-112px target.
		expect( Math.round( card.height ) ).toBe( 99 );
		// The close button is INSIDE the card, in its top end corner, with a 44px touch target.
		const barBox = card;
		const tab = page.locator( '.hprnb-bar__btn--close' );
		const tabBox = await tab.boundingBox();
		expect( tabBox.y ).toBeGreaterThanOrEqual( barBox.y - 1, 'Inside the card, not a tab above it.' );
		expect( tabBox.y + tabBox.height ).toBeLessThanOrEqual( barBox.y + barBox.height + 1 );
		expect( Math.round( tabBox.x + tabBox.width ) ).toBe( 370, 'Against the end corner, inside the padding.' );
		expect( await tab.evaluate( ( el ) => {
			const after = getComputedStyle( el, '::after' );
			return Math.round( el.getBoundingClientRect().width - parseFloat( after.insetInlineStart ) - parseFloat( after.insetInlineEnd ) );
		} ) ).toBeGreaterThanOrEqual( 44, 'Touch target of at least 44px.' );
		// The guarantee is that neither the label nor the headline runs under the button: their boxes
		// must not intersect, which the reserved column in the text side is what buys.
		const overlaps = ( a, b ) => a.x < b.x + b.width && b.x < a.x + a.width && a.y < b.y + b.height && b.y < a.y + a.height;
		expect( overlaps( tabBox, headlineBox ) ).toBe( false, 'The headline never runs under the button.' );
		expect( overlaps( tabBox, label ) ).toBe( false, 'Nor does the label.' );
		await expect( page.locator( '.hprnb-bar__btn--toggle' ) ).toBeHidden( 'Alone: no pause button.' );
		expect( await page.evaluate( () => {
			const el = document.querySelector( '.hprnb-bar__btn--close' );
			const rect = el.getBoundingClientRect();
			const top = document.elementFromPoint( rect.x + rect.width / 2, rect.y + rect.height / 2 );
			return el === top || el.contains( top );
		} ) ).toBe( true, 'Painted, not merely positioned.' );
		// Collapsed it is the strip of the flowing card: the pulsing dot and the first line.
		await page.evaluate( () => window.scrollTo( 0, 900 ) );
		await expect( aside ).toHaveClass( /hprnb-bar--collapsed/ );
		expect( await page.evaluate( () => getComputedStyle( document.body ).getPropertyValue( '--hprnb-offset' ).trim() ) ).toBe( '44px', 'One line, its padding and the floating gap.' );
		const dot = await page.locator( '.hprnb-bar__label' ).boundingBox();
		expect( Math.round( dot.width ) ).toBe( 24, 'The pill shrinks to its dot.' );
		expect( Math.round( dot.x ) ).toBe( 20, 'Against the card padding, on the left.' );
		expect( await page.locator( '.hprnb-bar__label' ).evaluate( ( el ) => getComputedStyle( el ).animationName ) ).toBe( 'hprnb-beacon' );
		expect( await page.locator( '.hprnb-bar__label-text' ).evaluate( ( el ) => getComputedStyle( el ).display ) ).toBe( 'none' );
		const strip = await headline.boundingBox();
		expect( strip.x ).toBeGreaterThan( dot.x + dot.width, 'The first line of the headline runs beside it.' );
		expect( Math.round( strip.height ) ).toBe( 22, 'One line.' );
		await expect( thumb ).toBeHidden( 'No picture in the strip.' );
		expect( await tab.evaluate( ( el ) => getComputedStyle( el ).display ) ).toBe( 'none', 'And no close tab.' );
		await page.evaluate( () => window.scrollTo( 0, 0 ) );
		await expect( aside ).not.toHaveClass( /hprnb-bar--collapsed/ );
		await tab.click();
		await expect( aside ).toBeHidden( 'The tab really closes the bar.' );
		// The dismissal is remembered for 24h: forget it before the next page.
		await page.evaluate( () => { try { localStorage.clear(); } catch ( e ) {} } );

		// Mirrored on an RTL site with an Arabic heading: image on the right, tab on the left.
		setSettings( { mobile_layout: 'card', rotate_interval: 60000, mobile_show_pause: false, close_button: true, label_text: 'اكتشف المزيد', mobile_label_style: 'strip' } );
		await page.goto( '/?hprnb_rtl=1' );
		await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
		expect( await aside.evaluate( ( el ) => getComputedStyle( el ).direction ) ).toBe( 'rtl' );
		const rtlImage = await thumb.boundingBox();
		const rtlHeadline = await headline.boundingBox();
		expect( rtlImage.x ).toBeGreaterThan( rtlHeadline.x + rtlHeadline.width, 'Image at the start, which is the right.' );
		expect( Math.round( ( await tab.boundingBox() ).x ) ).toBe( 20, 'The button keeps the end corner: the left.' );
		expect( await page.locator( '.hprnb-bar__label' ).evaluate( ( el ) => getComputedStyle( el ).backgroundColor ) ).toBe( 'rgba(0, 0, 0, 0)', 'The strip style is a plain bold heading on the card.' );

		// The card without a picture keeps the whole width for its headline.
		media.splice( 0 ).forEach( ( id ) => wp( [ 'post', 'delete', id, '--force' ] ) );
		setSettings( { mobile_layout: 'card', rotate_interval: 60000, mobile_show_pause: false, close_button: true } );
		await page.goto( '/' );
		await expect( aside ).toHaveAttribute( 'data-hprnb-init', '1' );
		await expect( page.locator( '.hprnb-bar__item:not([hidden]) .hprnb-bar__thumb' ) ).toHaveCount( 0 );
		const wide = await page.locator( '.hprnb-bar__item:not([hidden]) .hprnb-bar__title' ).boundingBox();
		expect( wide.width ).toBeGreaterThan( 290, 'No picture, so its column goes back to the headline.' );
		expect( Math.round( wide.x ) ).toBe( 20 );
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
		setSettings();
	}
} );

test( 'v2.5: two headlines keep their separators exactly as configured', async ( { page } ) => {
	const keep = postIds.slice( 2 );
	keep.forEach( ( id ) => wp( [ 'post', 'update', id, '--post_status=draft' ] ) );
	try {
		const items = page.locator( '.hprnb-bar__item' );
		const contentOf = ( index ) => items.nth( index ).evaluate( ( el ) => getComputedStyle( el, '::after' ).content );
		await page.setViewportSize( { width: 1366, height: 800 } );

		setSettings( { show_separator: true, separator_after_last: true, separator_char: '|' } );
		await page.goto( '/' );
		await expect( page.locator( '#hprnb-root' ) ).toHaveAttribute( 'data-hprnb-count', '2' );
		await expect( items ).toHaveCount( 2 );
		expect( await contentOf( 0 ) ).toContain( '|' );
		expect( await contentOf( 1 ) ).toContain( '|', 'separator_after_last still adds the loop junction.' );

		setSettings( { show_separator: true, separator_after_last: false, separator_char: '|' } );
		await page.goto( '/' );
		expect( await contentOf( 0 ) ).toContain( '|' );
		expect( await contentOf( 1 ) ).toBe( 'none', 'Without the option, nothing after the last one.' );

		setSettings( { show_separator: false } );
		await page.goto( '/' );
		expect( await contentOf( 0 ) ).toBe( 'none' );
		expect( await contentOf( 1 ) ).toBe( 'none' );
	} finally {
		keep.forEach( ( id ) => wp( [ 'post', 'update', id, '--post_status=publish' ] ) );
		setSettings();
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
	const pending = () => page.evaluate( () => document.querySelector( '#hprnb-root' ).classList.contains( 'hprnb-root--pending' ) );
	const layer = () => page.evaluate( () => ( window.dataLayer || [] ).map( ( r ) => ( { event: r.event, reason: r.trigger_reason, found: r.article_found } ) ) );
	await page.addInitScript( () => { window.dataLayer = []; } );

	try {
		const errors = collectErrors( page );
		await page.setViewportSize( { width: 390, height: 700 } );

		// Nothing at the top of the article: no bar, no reserved space.
		setSettings( { reveal_mode: 'smart', rotate_interval: 60000 } );
		await page.goto( url );
		await expect( page.locator( '.hprnb-bar' ) ).toHaveAttribute( 'data-hprnb-init', '1' );
		expect( await pending() ).toBe( true );
		expect( await page.evaluate( () => getComputedStyle( document.body ).getPropertyValue( '--hprnb-offset' ).trim() ) ).toBe( '0px' );
		expect( await page.evaluate( () => window.hprnbBar.state().offset ) ).toBe( 0 );
		expect( await layer() ).toEqual( [], 'No impression before the bar is shown.' );

		// 1. The end of the editorial body — the signal worth waiting for.
		await page.evaluate( () => document.querySelector( '.entry-content, .wp-block-post-content' ).scrollIntoView( { block: 'end' } ) );
		await expect.poll( pending ).toBe( false );
		await expect( root ).not.toHaveClass( /hprnb-root--pending/ );
		expect( ( await layer() ).filter( ( e ) => e.event === 'hprnb_impression' ) ).toEqual( [ { event: 'hprnb_impression', reason: 'article_end', found: true } ] );
		// And only once: scrolling on does not fire a second time.
		await page.evaluate( () => window.scrollTo( 0, 0 ) );
		await page.evaluate( () => window.scrollTo( 0, document.documentElement.scrollHeight ) );
		expect( ( await layer() ).filter( ( e ) => e.event === 'hprnb_impression' ) ).toHaveLength( 1 );

		// 2. A real scroll back up after reading a good share of it.
		setSettings( { reveal_mode: 'smart', rotate_interval: 60000, smart_mobile_time: 2, smart_mobile_fallback_time: 120 } );
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
		setSettings( { reveal_mode: 'smart', rotate_interval: 60000, smart_desktop_fallback: 40, smart_desktop_fallback_time: 3, smart_desktop_up: 1200 } );
		await page.goto( url );
		await expect( page.locator( '.hprnb-bar' ) ).toHaveAttribute( 'data-hprnb-init', '1' );
		await page.evaluate( () => window.scrollTo( 0, document.documentElement.scrollHeight * 0.45 ) );
		await page.waitForTimeout( 900 );
		expect( await pending() ).toBe( true, 'Deep enough, not long enough yet.' );
		await expect.poll( pending, { timeout: 15000 } ).toBe( false );
		expect( ( await layer() ).filter( ( e ) => e.event === 'hprnb_impression' )[ 0 ].reason ).toBe( 'engaged_reader' );

		// 4. A very short piece: only its end may fire, never the engagement fallback.
		await page.setViewportSize( { width: 390, height: 700 } );
		setSettings( { reveal_mode: 'smart', rotate_interval: 60000, smart_mobile_fallback: 10, smart_mobile_fallback_time: 1 } );
		await page.goto( briefUrl );
		await expect( page.locator( '.hprnb-bar' ) ).toHaveAttribute( 'data-hprnb-init', '1' );
		await expect.poll( pending ).toBe( false );
		expect( ( await layer() ).filter( ( e ) => e.event === 'hprnb_impression' )[ 0 ].reason ).toBe( 'article_end' );

		// 5. A dismissal is never undone by a smart signal, and it reports the reason it came from.
		setSettings( { reveal_mode: 'smart', rotate_interval: 60000, close_button: true, remember_dismiss: false, mobile_hide_on_scroll: false } );
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
		setSettings( { reveal_mode: 'scroll', reveal_value: 400, rotate_interval: 60000 } );
		await page.goto( url );
		await expect( page.locator( '.hprnb-bar' ) ).toHaveAttribute( 'data-hprnb-init', '1' );
		expect( await pending() ).toBe( true );
		await page.evaluate( () => window.scrollTo( 0, 600 ) );
		await expect.poll( pending ).toBe( false );
		expect( ( await layer() ).filter( ( e ) => e.event === 'hprnb_impression' )[ 0 ].reason ).toBe( 'legacy_scroll' );

		setSettings( { reveal_mode: 'immediate', rotate_interval: 60000 } );
		await page.goto( url );
		await expect( page.locator( '.hprnb-bar' ) ).toHaveAttribute( 'data-hprnb-init', '1' );
		expect( await pending() ).toBe( false );
		expect( ( await layer() ).filter( ( e ) => e.event === 'hprnb_impression' )[ 0 ].reason ).toBe( 'legacy_immediate' );
		expect( errors ).toEqual( [] );
	} finally {
		wp( [ 'post', 'delete', article, '--force' ] );
		wp( [ 'post', 'delete', brief, '--force' ] );
		setSettings();
	}
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
	await expect( previewRoot ).toHaveClass( /hprnb-root--m-flow/ );
	expect( Math.round( ( await previewRoot.boundingBox() ).width ) ).toBeLessThanOrEqual( 375 );
	await page.click( '[data-hprnb-tab="colors"]' );
	await expect( previewRoot ).not.toHaveClass( /hprnb-root--m-colors/ );
	await expect( page.locator( 'tr[data-hprnb-depends="mobile_custom_colors"]' ).first() ).toHaveClass( /hprnb-row--inactive/ );
	await page.locator( '#hprnb-field-mobile-custom-colors' ).setChecked( true, { force: true } );
	await expect( previewRoot ).toHaveClass( /hprnb-root--m-colors/ );
	await page.locator( '#hprnb-field-mobile-custom-colors' ).setChecked( false, { force: true } );
	await page.click( '[data-hprnb-tab="display"]' );
	await page.check( '#hprnb-field-mobile-layout-inline' );
	await expect( previewRoot ).toHaveClass( /hprnb-root--m-inline/ );
	await page.check( '#hprnb-field-mobile-layout-stacked' );
	await expect( previewRoot ).toHaveClass( /hprnb-root--m-stacked/ );
	await expect( page.locator( '#hprnb-preview-root .hprnb-bar__counter' ) ).toHaveCount( 0 );
	await page.check( '#hprnb-field-mobile-show-counter' );
	await expect( page.locator( '#hprnb-preview-root .hprnb-bar__counter' ) ).toHaveCount( 1 );
	await page.check( '#hprnb-field-mobile-layout-flow' );
	await page.click( '#hprnb-preview-tab-desktop' );
	await expect( previewRoot ).toHaveClass( /hprnb-root--flat/ );
	await expect( page.locator( '#hprnb-preview-root .hprnb-bar' ) ).toHaveClass( /hprnb-bar--mode-marquee/ );
	// Presets fill the palette fields without a request; the height hint follows the lines setting.
	await page.click( '[data-hprnb-tab="colors"]' );
	await page.click( '.hprnb-preset[data-hprnb-preset="red"]' );
	await expect( page.locator( '#hprnb-field-bg-color' ) ).toHaveValue( '#ce3029' );
	await page.click( '.hprnb-preset[data-hprnb-preset="dark"]' );
	await expect( page.locator( '#hprnb-field-bg-color' ) ).toHaveValue( '#1b1c20' );
	await page.click( '[data-hprnb-tab="display"]' );
	await page.selectOption( '#hprnb-field-mobile-lines', '3' );
	await expect( page.locator( '.hprnb-height-hint[data-hprnb-height="m"]' ) ).toHaveText( 'Bar height: 90 px' );
	page.once( 'dialog', ( d ) => d.accept() );
	await page.click( '#hprnb-reset-tab' );
	await expect( page.locator( '#hprnb-field-mobile-lines' ) ).toHaveValue( '2' );
	await expect( page.locator( '.hprnb-height-hint[data-hprnb-height="m"]' ) ).toHaveText( 'Bar height: 76 px' );
	expect( previews ).toHaveLength( 0 );

	// Contrast warning (never blocks saving).
	await page.click( '[data-hprnb-tab="colors"]' );
	await page.fill( '#hprnb-field-text-color', '#112244' );
	await page.locator( '#hprnb-field-text-color' ).dispatchEvent( 'input' );
	await expect( page.locator( '#hprnb-contrast-text' ) ).toBeVisible();
	await page.fill( '#hprnb-field-text-color', '#ffffff' );
	await page.locator( '#hprnb-field-text-color' ).dispatchEvent( 'input' );
	await expect( page.locator( '#hprnb-contrast-text' ) ).toBeHidden();

	// Content refresh through the private endpoint.
	await page.click( '[data-hprnb-tab="display"]' );
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

const STACKED = { mobile_layout: 'stacked', mobile_show_counter: true, mobile_label_dot: false, mobile_custom_colors: true, mobile_bg_color: '#141414', mobile_accent_color: '#E11D2A', mobile_text_color: '#F5F5F5', ticker_enabled: false };

test( 'mobile stacked presentation: pill, counter, progress, rotation, swipe, collapse, colours, options, breakpoint', async ( { page } ) => {
	setSettings( { ...STACKED, rotate_interval: 1500 } );
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
	expect( Math.round( ( await aside.boundingBox() ).height ) ).toBe( 40 );
	await page.setViewportSize( { width: 375, height: 667 } );
	await expect( aside ).toHaveClass( /hprnb-bar--mode-rotate/ );
	await expect( page.locator( '.hprnb-bar__counter' ) ).toHaveCount( 1 );

	// Options: hidden label, desktop colours, no counter/progress/collapse.
	setSettings( { ...STACKED, mobile_label_style: 'hidden', mobile_custom_colors: false, mobile_show_counter: false, mobile_show_progress: false, mobile_hide_on_scroll: false } );
	await page.goto( '/' );
	await expect( label ).toBeHidden();
	expect( await aside.evaluate( ( el ) => getComputedStyle( el ).backgroundColor ) ).toBe( 'rgb(27, 28, 32)' );
	await expect( page.locator( '.hprnb-bar__counter' ) ).toHaveCount( 0 );
	await expect( page.locator( '.hprnb-bar__progress' ) ).toHaveCount( 0 );
	await expect( root ).not.toHaveClass( /hprnb-root--m-collapse/ );
	await page.evaluate( () => window.scrollTo( 0, 900 ) );
	await page.waitForTimeout( 300 );
	await expect( aside ).not.toHaveClass( /hprnb-bar--collapsed/ );

	// Reduced motion: no automatic rotation, no progress line, toggle hidden.
	setSettings( STACKED );
	await page.emulateMedia( { reducedMotion: 'reduce' } );
	await page.goto( '/' );
	await expect( aside ).toHaveClass( /hprnb-bar--reduced/ );
	await expect( page.locator( '.hprnb-bar__progress' ) ).toHaveCount( 0 );
	await expect( toggle ).toBeHidden();
	expect( await noHorizontalOverflow( page ) ).toBe( true );
} );
