// Run: node tests/OrderRecalculationScriptTest.js
// Control response timing without adding a browser dependency to the project.
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );

async function check() {
	const listeners = {};
	const fields = Object.fromEntries( ['wtd_preview', 'offer[discount]', 'offer[tax]', 'offer[usedpoint]', 'offer[getpoint]'].map( name => [name, { value: '' }] ) );
	const form = {
		elements: { namedItem: name => fields[name] },
		addEventListener: ( name, listener ) => { listeners[name] = listener; },
	};
	const button = { disabled: false, addEventListener: () => {}, focus: () => {} };
	const nativeButton = { disabled: false };
	const status = { textContent: '' };
	const nodes = {
		'wtd-order-panel': { closest: () => form },
		'wtd-preview-status': status,
		'wtd-preview-button': button,
		recalc: nativeButton,
	};
	const requests = [];
	const context = {
		document: {
			getElementById: id => nodes[id],
			addEventListener: ( name, listener ) => { if ( name === 'DOMContentLoaded' ) listener(); },
		},
		wtdOrders: { dirty: 'dirty', ready: 'ready', labels: [], url: '/preview' },
		jQuery: { ajaxPrefilter: () => {} },
		FormData: class { set() {} },
		fetch: () => new Promise( resolve => requests.push( resolve ) ),
	};
	vm.runInNewContext( fs.readFileSync( require.resolve( '../plugin/assets/orders.js' ), 'utf8' ), context );
	const response = token => ( { ok: true, json: async () => ( {
		success: true,
		data: { token, tier_text: 'tier', quote: {
			discount: '500', tax: '950', usedpoint: 0, getpoint: 0, total: '10450', internal_tax: '0',
			tax_parts: {}, internal_tax_parts: {},
		} },
	} ) } );
	const first = context.wtdOrders.preview();
	assert.equal( button.disabled, true );
	assert.equal( nativeButton.disabled, true );
	await context.wtdOrders.preview();
	assert.equal( requests.length, 1, 'Repeated clicks must share one in-flight request.' );
	listeners.input( { target: { name: 'quant[1000]' } } );
	requests.shift()( response( 'stale' ) );
	await first;
	assert.equal( fields.wtd_preview.value, '', 'An edited form must reject an old response.' );
	assert.equal( fields['offer[discount]'].value, '' );
	assert.equal( status.textContent, 'dirty' );
	assert.equal( button.disabled, false );
	assert.equal( nativeButton.disabled, false );
	const second = context.wtdOrders.preview();
	requests.shift()( response( 'fresh' ) );
	await second;
	assert.equal( fields.wtd_preview.value, 'fresh' );
	assert.equal( fields['offer[discount]'].value, '-500' );
	listeners.change( { target: { name: 'offer[shipping_charge]' } } );
	let blocked = false;
	listeners.submit( { preventDefault: () => { blocked = true; } } );
	assert.equal( blocked, true, 'A change after preview must block saving.' );
	console.log( 'PASS: shared request, stale response, fresh response, dirty save guard' );
}

check().catch( error => { console.error( error ); process.exitCode = 1; } );
