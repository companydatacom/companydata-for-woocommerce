/**
 * CompanyData company lookup for the block-based checkout.
 *
 * The block checkout is React, so the inputs are read through DOM events but
 * written through the wc/store/cart data store; setting input values directly
 * would be overwritten on the next render. The CompanyData ID and registration
 * number travel to the order as extension data on the Store API request.
 */
( function () {
	const cfg = window.companydataConfig;
	if ( ! cfg || ! window.wp || ! wp.data ) return;
	const t = cfg.i18n || {};
	const CART = 'wc/store/cart';
	const CHECKOUT = 'wc/store/checkout';
	const REG = 'companydata/registration';
	const picked = { billing_id: '', billing_registration: '', shipping_id: '', shipping_registration: '' };

	const norm = ( s ) => String( s || '' ).toLowerCase().normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' ).replace( /[^a-z0-9]/g, '' );
	const address = ( type ) => wp.data.select( CART ).getCustomerData()[ type + 'Address' ] || {};
	const shipAsBilling = () => {
		const s = wp.data.select( CHECKOUT );
		return s.getUseShippingAsBilling ? s.getUseShippingAsBilling() : true;
	};

	function sendPicked() {
		wp.data.dispatch( CHECKOUT ).setExtensionData( 'companydata', Object.assign( {}, picked ) );
	}

	/** The register returns state names ("Zuid-holland"); the store wants codes. */
	function stateCode( country, name ) {
		if ( ! name ) return '';
		const data = window.wc && wc.wcSettings ? wc.wcSettings.getSetting( 'countryData', {} ) : {};
		const states = ( data[ country ] && data[ country ].states ) || {};
		if ( states[ name ] ) return name;
		const hit = Object.keys( states ).find( ( k ) => norm( states[ k ] ) === norm( name ) );
		return hit || '';
	}

	function attach( input ) {
		if ( input.dataset.companydata ) return;
		input.dataset.companydata = '1';
		const type = input.id.indexOf( 'billing-' ) === 0 ? 'billing' : 'shipping';
		const wrap = input.closest( '.wc-block-components-text-input' ) || input.parentElement;
		wrap.classList.add( 'companydata-wrap' );

		const list = document.createElement( 'ul' );
		list.className = 'companydata-results';
		list.id = 'companydata-' + type + '-' + Math.random().toString( 36 ).slice( 2, 8 );
		list.setAttribute( 'role', 'listbox' );
		list.setAttribute( 'aria-label', t.label || 'Company suggestions' );
		list.hidden = true;
		const note = document.createElement( 'p' );
		note.className = 'companydata-note';
		note.setAttribute( 'role', 'status' );
		note.hidden = true;
		wrap.append( list, note );
		input.setAttribute( 'autocomplete', 'off' );
		input.setAttribute( 'aria-autocomplete', 'list' );
		input.setAttribute( 'aria-expanded', 'false' );
		input.setAttribute( 'aria-controls', list.id );

		let timer = null;
		let controller = null;
		let items = [];
		let active = -1;
		let justFilled = '';

		const say = ( text ) => {
			note.textContent = text || '';
			note.hidden = ! text;
		};
		const busy = ( on ) => {
			input.classList.toggle( 'companydata-loading', on );
			input.setAttribute( 'aria-busy', on ? 'true' : 'false' );
		};
		function close() {
			list.hidden = true;
			list.textContent = '';
			input.setAttribute( 'aria-expanded', 'false' );
			input.removeAttribute( 'aria-activedescendant' );
			items = [];
			active = -1;
		}
		function span( cls, text ) {
			const el = document.createElement( 'span' );
			el.className = cls;
			el.textContent = text;
			return el;
		}

		function render( rows, warnings ) {
			close();
			if ( ! rows.length ) {
				say( t.noMatch );
				return;
			}
			const foreign = ( warnings || [] ).includes( 'SEARCH_MATCHED_OTHER_COUNTRY' );
			const here = address( type ).country;
			items = rows;
			rows.forEach( ( r, i ) => {
				const li = document.createElement( 'li' );
				li.id = list.id + '-' + i;
				li.setAttribute( 'role', 'option' );
				li.setAttribute( 'aria-selected', 'false' );
				const name = document.createElement( 'strong' );
				name.textContent = r.name;
				li.append( name );
				const addr = [ r.address_1, [ r.postcode, r.city ].filter( Boolean ).join( ' ' ) ].filter( Boolean ).join( ', ' );
				if ( addr ) li.append( span( 'companydata-addr', addr ) );
				if ( r.registration ) li.append( span( 'companydata-reg', ( t.reg || 'reg.' ) + ' ' + r.registration ) );
				if ( foreign && r.country !== here ) li.append( span( 'companydata-foreign', ( t.other || 'Registered in another country' ) + ': ' + ( r.countryName || r.country ) ) );
				li.addEventListener( 'mousedown', ( ev ) => ev.preventDefault() ); // keep focus on the input
				li.addEventListener( 'click', () => pick( i ) );
				list.append( li );
			} );
			list.hidden = false;
			input.setAttribute( 'aria-expanded', 'true' );
		}

		function highlight( i ) {
			active = i;
			Array.from( list.children ).forEach( ( el, k ) => {
				const on = k === i;
				el.classList.toggle( 'is-active', on );
				el.setAttribute( 'aria-selected', on ? 'true' : 'false' );
				if ( on ) {
					input.setAttribute( 'aria-activedescendant', el.id );
					el.scrollIntoView( { block: 'nearest' } );
				}
			} );
		}

		async function search( q ) {
			if ( controller ) controller.abort();
			controller = new AbortController();
			const mine = controller;
			const c = String( address( type ).country || '' ).toUpperCase();
			if ( ! c || ( cfg.countries || [] ).indexOf( c ) < 0 ) return;
			busy( true );
			try {
				const sep = cfg.restUrl.indexOf( '?' ) < 0 ? '?' : '&';
				const r = await fetch( cfg.restUrl + sep + new URLSearchParams( { q, country: c } ), {
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': cfg.nonce },
					signal: controller.signal,
				} );
				const body = await r.json().catch( () => ( {} ) );
				if ( ! r.ok ) {
					close();
					say( r.status === 429 || r.status >= 500 ? t.unavailable : '' );
					return;
				}
				say( '' );
				render( body.data || [], body.warnings );
			} catch ( e ) {
				if ( e.name !== 'AbortError' ) close();
			} finally {
				// A newer search owns the spinner once this one is aborted.
				if ( controller === mine ) busy( false );
			}
		}

		function fill( r ) {
			const fill = cfg.fill || [];
			const current = address( type );
			const patch = { company: r.name };
			[ 'address_1', 'address_2', 'postcode', 'city' ].forEach( ( k ) => {
				if ( fill.indexOf( k ) >= 0 ) patch[ k ] = r[ k ] || '';
			} );
			let country = current.country;
			if ( r.country && r.country !== country && fill.indexOf( 'country' ) >= 0 && ( cfg.countries || [] ).indexOf( r.country ) >= 0 ) {
				country = patch.country = r.country;
			}
			if ( fill.indexOf( 'state' ) >= 0 ) patch.state = stateCode( country, r.state );
			if ( cfg.regField && r.registration ) patch[ REG ] = r.registration;

			const cart = wp.data.dispatch( CART );
			if ( type === 'shipping' ) {
				cart.setShippingAddress( Object.assign( {}, current, patch ) );
				if ( shipAsBilling() ) cart.setBillingAddress( Object.assign( {}, address( 'billing' ), patch ) );
			} else {
				cart.setBillingAddress( Object.assign( {}, current, patch ) );
			}

			justFilled = r.name;
			picked[ type + '_id' ] = r.id || '';
			picked[ type + '_registration' ] = r.registration || '';
			sendPicked();
			say( t.filled );
		}

		function pick( i ) {
			const r = items[ i ];
			// A reply to an earlier keystroke must not overwrite the pick.
			clearTimeout( timer );
			if ( controller ) controller.abort();
			busy( false );
			close();
			if ( r ) fill( r );
		}

		input.addEventListener( 'input', () => {
			const q = input.value.trim();
			clearTimeout( timer );
			if ( q === justFilled ) return;
			// Edited after a pick: the register match no longer applies.
			if ( justFilled || picked[ type + '_id' ] ) {
				justFilled = '';
				picked[ type + '_id' ] = '';
				picked[ type + '_registration' ] = '';
				sendPicked();
			}
			if ( q.length < ( cfg.minChars || 3 ) ) {
				if ( controller ) controller.abort();
				busy( false );
				close();
				say( '' );
				return;
			}
			timer = setTimeout( () => search( q ), cfg.debounce || 350 );
		} );
		input.addEventListener( 'keydown', ( ev ) => {
			if ( list.hidden ) return;
			if ( ev.key === 'ArrowDown' ) { ev.preventDefault(); highlight( Math.min( items.length - 1, active + 1 ) ); }
			else if ( ev.key === 'ArrowUp' ) { ev.preventDefault(); highlight( Math.max( 0, active - 1 ) ); }
			else if ( ev.key === 'Enter' && active >= 0 ) { ev.preventDefault(); pick( active ); }
			else if ( ev.key === 'Escape' ) { close(); }
		} );
		input.addEventListener( 'blur', () => setTimeout( close, 150 ) );

		if ( cfg.credit && ! wrap.parentElement.querySelector( '.companydata-credit' ) ) {
			wrap.insertAdjacentHTML( 'afterend', cfg.credit );
		}
	}

	/**
	 * The address forms mount late and remount when the customer toggles
	 * "use same address for billing", so watch for the inputs.
	 */
	function scan() {
		// Here the shipping form comes first and doubles as billing, so both get the lookup.
		document.querySelectorAll( '#shipping-company, #billing-company, #shipping-companydata-registration, #billing-companydata-registration' ).forEach( attach );
	}
	const root = document.querySelector( '.wp-block-woocommerce-checkout' ) || document.body;
	new MutationObserver( scan ).observe( root, { childList: true, subtree: true } );
	scan();
} )();
