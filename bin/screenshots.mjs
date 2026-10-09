/**
 * Regenerates the screenshots in .wordpress-org/ from a running local site with
 * the Halyard demo (see CONTRIBUTING.md). Needs Chrome and playwright-core:
 *
 *   CHROME=/path/to/chrome PLAYWRIGHT=/path/to/node_modules/playwright-core \
 *   node bin/screenshots.mjs http://localhost:8995 maya halyard
 */
import { createRequire } from 'module';
import { mkdirSync } from 'fs';

const require = createRequire( import.meta.url );
const { chromium } = require( process.env.PLAYWRIGHT || 'playwright-core' );

const [ , , base = 'http://localhost:8995', user = 'maya', pass = 'halyard', only = '' ] = process.argv;
const out = new URL( '../.wordpress-org/', import.meta.url ).pathname;
mkdirSync( out, { recursive: true } );

const browser = await chromium.launch( { executablePath: process.env.CHROME } );
const shots = [];
const want = ( name ) => ! only || only.split( ',' ).includes( name );

async function page( options = {} ) {
	const context = await browser.newContext( { viewport: { width: 1440, height: 900 }, deviceScaleFactor: 2, colorScheme: 'light', reducedMotion: 'reduce', ...options } );
	return { context, page: await context.newPage() };
}

async function shot( name, p, options = {} ) {
	await p.waitForTimeout( 250 );
	await p.screenshot( { path: out + name + '.png', ...options } );
	shots.push( name );
}

// Signed-in session for the admin screens.
const admin = await page();
await admin.page.goto( base + '/wp-login.php' );
await admin.page.fill( '#user_login', user );
await admin.page.fill( '#user_pass', pass );
await Promise.all( [ admin.page.waitForNavigation(), admin.page.click( '#wp-submit' ) ] );

// 1. Home.
if ( want( 'screenshot-1' ) ) {
	const { page: p } = await page();
	await p.goto( base + '/help/', { waitUntil: 'networkidle' } );
	await shot( 'screenshot-1', p );
}

// 2. Article.
if ( want( 'screenshot-2' ) ) {
	const { page: p } = await page();
	await p.goto( base + '/help/download-an-invoice/', { waitUntil: 'networkidle' } );
	await shot( 'screenshot-2', p );
}

// 3. Suggestions as you type.
if ( want( 'screenshot-3' ) ) {
	const { page: p } = await page( { viewport: { width: 1280, height: 800 } } );
	await p.goto( base + '/help/', { waitUntil: 'networkidle' } );
	await p.click( '.ruvelo-hc-hero input[type=search]' );
	await p.keyboard.type( 'invoice', { delay: 40 } );
	await p.waitForSelector( '.ruvelo-hc-hero .ruvelo-hc-suggest:not([hidden]) [role=option]' );
	await p.keyboard.press( 'ArrowDown' );
	await shot( 'screenshot-3', p );
}

// 4. Was this helpful? After a "No".
if ( want( 'screenshot-4' ) ) {
	const { page: p } = await page( { viewport: { width: 1280, height: 800 } } );
	await p.goto( base + '/help/when-will-i-get-paid/', { waitUntil: 'networkidle' } );
	await p.evaluate( () => document.querySelector( '#feedback' ).scrollIntoView( { block: 'center' } ) );
	await p.click( '#feedback button[value="0"]' );
	await p.waitForSelector( '#feedback[data-state="no"]' );
	await p.fill( '#feedback textarea', 'It doesn’t say what happens with payouts over Christmas.' );
	await p.evaluate( () => document.querySelector( '#feedback' ).scrollIntoView( { block: 'center' } ) );
	await shot( 'screenshot-4', p );
}

// 5. llms.txt and an article as Markdown, side by side.
if ( want( 'screenshot-5' ) ) {
	const { page: p } = await page( { viewport: { width: 1440, height: 900 } } );
	const llms = await ( await fetch( base + '/llms.txt' ) ).text();
	const md = await ( await fetch( base + '/help/refund-a-customer.md' ) ).text();
	const esc = ( t ) => t.replace( /[&<>]/g, ( c ) => ( { '&': '&amp;', '<': '&lt;', '>': '&gt;' } )[ c ] );
	await p.setContent( `<!doctype html><meta charset="utf-8"><style>
		body{margin:0;background:radial-gradient(60rem 30rem at 50% -10rem,#e9e4ff,#f8f8fc 70%);font:15px/1.5 system-ui,sans-serif;color:#16162a;height:100vh;box-sizing:border-box;padding:48px 56px;display:grid;grid-template-columns:1.25fr 1fr;gap:28px}
		.card{background:#fff;border:1px solid #e4e4ef;border-radius:14px;box-shadow:0 24px 60px -32px rgba(61,78,255,.6);overflow:hidden;display:flex;flex-direction:column;min-height:0}
		.bar{display:flex;align-items:center;gap:10px;padding:12px 16px;border-bottom:1px solid #e4e4ef;background:#f8f8fc;font-size:13px;color:#4b4b63}
		.bar b{font-weight:600;color:#16162a}.dot{width:10px;height:10px;border-radius:50%;background:#e4e4ef}
		.url{margin-left:6px;padding:4px 10px;border-radius:999px;background:#eef0ff;color:#2b38d6;font-family:ui-monospace,monospace;font-size:12px}
		pre{margin:0;padding:18px 20px;font:12.5px/1.65 ui-monospace,"SF Mono",Menlo,monospace;white-space:pre-wrap;overflow:hidden;flex:1}
		</style>
		<div class="card"><div class="bar"><span class="dot"></span><span class="dot"></span><span class="dot"></span><span class="url">/llms.txt</span></div><pre>${ esc( llms ) }</pre></div>
		<div class="card"><div class="bar"><span class="dot"></span><span class="dot"></span><span class="dot"></span><span class="url">/help/refund-a-customer.md</span></div><pre>${ esc( md ) }</pre></div>` );
	await shot( 'screenshot-5', p );
}

// 6. Admin: articles with views and helpful share.
if ( want( 'screenshot-6' ) ) {
	await admin.page.goto( base + '/wp-admin/edit.php?post_type=ruvelo_article&orderby=ruvelo_views&order=desc', { waitUntil: 'networkidle' } );
	await shot( 'screenshot-6', admin.page );
}

// 7. Admin: what readers said.
if ( want( 'screenshot-7' ) ) {
	await admin.page.goto( base + '/wp-admin/edit.php?post_type=ruvelo_article&page=ruvelo-help-feedback', { waitUntil: 'networkidle' } );
	await shot( 'screenshot-7', admin.page );
}

// 8. Mobile.
if ( want( 'screenshot-8' ) ) {
	const { page: p } = await page( { viewport: { width: 390, height: 844 }, deviceScaleFactor: 3, isMobile: true, hasTouch: true } );
	await p.goto( base + '/help/download-an-invoice/', { waitUntil: 'networkidle' } );
	await shot( 'screenshot-8', p );
}

// 9. Dark.
if ( want( 'screenshot-9' ) ) {
	const { page: p } = await page( { colorScheme: 'dark' } );
	await p.goto( base + '/help/set-up-webhooks/', { waitUntil: 'networkidle' } );
	await shot( 'screenshot-9', p );
}

// 10. The help center home in the block editor: every block is a real, editable block.
if ( want( 'screenshot-10' ) ) {
	await admin.page.goto( base + '/wp-admin/post.php?post=' + ( process.env.HOME_PAGE_ID || '' ) + '&action=edit', { waitUntil: 'load' } );
	await admin.page.waitForFunction( () => window.wp && window.wp.data && window.wp.data.select( 'core/editor' ) );
	await admin.page.evaluate( () => {
		const { dispatch } = window.wp.data;
		dispatch( 'core/preferences' ).set( 'core', 'welcomeGuide', false );
		dispatch( 'core/preferences' ).set( 'core/edit-post', 'welcomeGuide', false );
	} );
	await admin.page.keyboard.press( 'Escape' );
	const frame = admin.page.frameLocator( 'iframe[name="editor-canvas"]' );
	await frame.locator( '.ruvelo-hc-cards' ).first().waitFor( { timeout: 20000 } );
	await frame.locator( '.wp-block-ruvelo-help-categories' ).first().click();
	await admin.page.waitForTimeout( 1500 );
	await shot( 'screenshot-10', admin.page );
}

// 11. Get started.
if ( want( 'screenshot-11' ) ) {
	await admin.page.goto( base + '/wp-admin/edit.php?post_type=ruvelo_article&page=ruvelo-help-setup', { waitUntil: 'networkidle' } );
	await shot( 'screenshot-11', admin.page );
}

// WordPress.org banners and icons, framing the home screenshot.
if ( want( 'art' ) ) {
	const art = new URL( './art/', import.meta.url ).pathname;
	require( 'fs' ).copyFileSync( out + 'screenshot-1.png', art + 'shot.png' );
	for ( const [ file, scale ] of [ [ 'banner-1544x500', 1 ], [ 'banner-772x250', 0.5 ] ] ) {
		const { page: p } = await page( { viewport: { width: 1544, height: 500 }, deviceScaleFactor: scale } );
		await p.goto( 'file://' + art + 'banner.html', { waitUntil: 'networkidle' } );
		await shot( file, p );
	}
	for ( const [ file, scale ] of [ [ 'icon-128x128', 1 ], [ 'icon-256x256', 2 ] ] ) {
		const { page: p } = await page( { viewport: { width: 128, height: 128 }, deviceScaleFactor: scale } );
		await p.goto( 'file://' + art + 'icon.html', { waitUntil: 'networkidle' } );
		await shot( file, p, { omitBackground: true } );
	}
	require( 'fs' ).unlinkSync( art + 'shot.png' );
}

await browser.close();
console.log( 'Wrote ' + shots.map( ( s ) => s + '.png' ).join( ', ' ) );
