import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

const WP = [ '/opt/wp/bin/wp-cli.phar', '--allow-root', '--path=/opt/wp/core' ];

export const defaults = JSON.parse( readFileSync( new URL( '../phpunit/fixtures/defaults.json', import.meta.url ), 'utf8' ) );

export function wp( args ) {
	return execFileSync( 'php', [ ...WP, ...args ], { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'ignore' ] } ).trim();
}

export function setSettings( overrides = {} ) {
	const settings = { ...defaults, ...overrides };
	wp( [ 'option', 'update', 'hprnb_settings', JSON.stringify( settings ), '--format=json' ] );
	wp( [ 'option', 'update', 'hprnb_cache_epoch', 'e2e-' + Date.now() ] );
	return settings;
}

export function collectErrors( page ) {
	const errors = [];
	page.on( 'pageerror', ( e ) => errors.push( 'pageerror: ' + e.message ) );
	page.on( 'console', ( m ) => {
		// Resource failures caused by the sandbox network (no internet) are not plugin errors.
		const where = ( m.location() && m.location().url ) || '';
		if ( m.type() === 'error' && ! /favicon|gravatar\.com/.test( m.text() + ' ' + where ) ) {
			errors.push( 'console: ' + m.text() + ' @ ' + where );
		}
	} );
	return errors;
}

export function countRequests( page, pattern ) {
	const hits = [];
	page.on( 'request', ( r ) => {
		if ( pattern.test( r.url() ) ) {
			hits.push( r.url() );
		}
	} );
	return hits;
}

export async function noHorizontalOverflow( page ) {
	return page.evaluate( () => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1 );
}

export const OLD = 1000000000;

/** Rewrites the HTML document so the SSR looks stale (and optionally empty). */
export async function routeStaleDocument( page, { empty = false } = {} ) {
	await page.route( ( url ) => url.pathname === '/' , async ( route ) => {
		if ( route.request().resourceType() !== 'document' ) {
			return route.continue();
		}
		const response = await route.fetch();
		let html = await response.text();
		html = html.replace( /data-hprnb-generated="\d+"/, `data-hprnb-generated="${ OLD }"` );
		if ( empty ) {
			html = html.replace( /(<div id="hprnb-root"[^>]*)data-hprnb-empty="0"([^>]*)>[\s\S]*?<\/aside><\/div>/, '$1data-hprnb-empty="1"$2 hidden></div>' );
			html = html.replace( /<link[^>]*id="hprnb-bar-css"[^>]*>/, '' ).replace( /<style id="hprnb-bar-inline-css">[\s\S]*?<\/style>/, '' );
			html = html.replace( 'class="home blog wp-embed-responsive wp-theme-twentytwentyfive hprnb-reserve"', 'class="home blog wp-embed-responsive wp-theme-twentytwentyfive"' );
		}
		await route.fulfill( { response, body: html, headers: { ...response.headers(), 'content-type': 'text/html; charset=UTF-8' } } );
	} );
}
