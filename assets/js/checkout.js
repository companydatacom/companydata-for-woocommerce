/**
 * Company lookup on the classic WooCommerce checkout. Turns the Company
 * field (and the optional registration-number field) into an autocomplete;
 * picking a company fills the address fields of the same section.
 *
 * jQuery is used on purpose: WooCommerce's checkout fires its own jQuery
 * events (country_to_state_changed, update_checkout) that the fill relies on.
 */
( function ( $ ) {
	const cfg = window.companydataConfig || {};
	const t = cfg.i18n || {};
	if ( ! cfg.restUrl ) return;

	const norm = ( s ) => String( s || '' ).toLowerCase().replace( /[^a-z0-9]/g, '' );

	/**
	 * One autocomplete on one input, filling the fields of `type` (billing / shipping).
	 */
	function attach( input, type ) {
		const $input = $( input );
		const wrap = $input.closest( '.woocommerce-input-wrapper' ).length ? $input.closest( '.woocommerce-input-wrapper' ) : $input.parent();
		const list = $( '<ul class="companydata-results" role="listbox" hidden></ul>' ).attr( 'aria-label', t.label || 'Company suggestions' );
		const note = $( '<p class="companydata-note" role="status" hidden></p>' );
		wrap.addClass( 'companydata-wrap' ).append( list, note );
		$input.attr( { autocomplete: 'off', 'aria-autocomplete': 'list', 'aria-expanded': 'false', 'aria-controls': list.attr( 'id', 'companydata-' + type + '-' + Math.random().toString( 36 ).slice( 2, 8 ) ).attr( 'id' ) } );

		let timer = null;
		let controller = null;
		let items = [];
		let active = -1;
		let justFilled = '';

		const field = ( name ) => $( '#' + type + '_' + name );
		const country = () => String( field( 'country' ).val() || '' ).toUpperCase();

		function close() {
			list.attr( 'hidden', true ).empty();
			$input.attr( 'aria-expanded', 'false' ).removeAttr( 'aria-activedescendant' );
			items = [];
			active = -1;
		}
		function say( text ) {
			note.text( text || '' ).attr( 'hidden', ! text );
		}

		function render( rows, warnings ) {
			close();
			if ( ! rows.length ) {
				say( t.noMatch );
				return;
			}
			const foreign = ( warnings || [] ).includes( 'SEARCH_MATCHED_OTHER_COUNTRY' );
			items = rows;
			rows.forEach( ( r, i ) => {
				const li = $( '<li role="option"></li>' ).attr( { id: list.attr( 'id' ) + '-' + i, 'aria-selected': 'false' } );
				li.append( $( '<strong></strong>' ).text( r.name ) );
				const addr = [ r.address_1, [ r.postcode, r.city ].filter( Boolean ).join( ' ' ) ].filter( Boolean ).join( ', ' );
				if ( addr ) li.append( $( '<span class="companydata-addr"></span>' ).text( addr ) );
				if ( r.registration ) li.append( $( '<span class="companydata-reg"></span>' ).text( ( t.reg || 'reg.' ) + ' ' + r.registration ) );
				if ( foreign && r.country !== country() ) li.append( $( '<span class="companydata-foreign"></span>' ).text( ( t.other || 'Registered in another country' ) + ': ' + ( r.countryName || r.country ) ) );
				li.on( 'mousedown', ( ev ) => ev.preventDefault() ); // keep focus on the input
				li.on( 'click', () => pick( i ) );
				list.append( li );
			} );
			list.removeAttr( 'hidden' );
			$input.attr( 'aria-expanded', 'true' );
		}

		function highlight( i ) {
			active = i;
			list.children().each( ( k, el ) => {
				const on = k === i;
				$( el ).toggleClass( 'is-active', on ).attr( 'aria-selected', on ? 'true' : 'false' );
				if ( on ) {
					$input.attr( 'aria-activedescendant', el.id );
					el.scrollIntoView( { block: 'nearest' } );
				}
			} );
		}

		async function search( q ) {
			if ( controller ) controller.abort();
			controller = new AbortController();
			const c = country();
			if ( ! c || ( cfg.countries || [] ).indexOf( c ) < 0 ) return;
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
			}
		}

		function setField( name, value ) {
			if ( ( cfg.fill || [] ).indexOf( name ) < 0 ) return;
			const $f = field( name );
			if ( ! $f.length ) return;
			if ( $f.is( 'select' ) ) {
				// State selects list names; match by text when the value is not a code.
				let v = value;
				if ( ! $f.find( 'option[value="' + value + '"]' ).length ) {
					const opt = $f.find( 'option' ).filter( ( k, o ) => norm( o.textContent ) === norm( value ) ).first();
					v = opt.length ? opt.val() : '';
				}
				$f.val( v ).trigger( 'change' );
			} else {
				$f.val( value ).trigger( 'change' );
			}
		}

		function fill( r ) {
			const company = field( 'company' );
			justFilled = r.name;
			company.val( r.name ).trigger( 'change' );
			wrap.closest( 'form' ).find( 'input[name="companydata_' + type + '_id"]' ).val( r.id || '' );
			wrap.closest( 'form' ).find( 'input[name="companydata_' + type + '_registration"]' ).val( r.registration || '' );
			if ( cfg.regField && type === 'billing' ) $( '#billing_company_registration' ).val( r.registration || '' );

			const applyAddress = () => {
				[ 'address_1', 'address_2', 'postcode', 'city' ].forEach( ( k ) => setField( k, r[ k ] || '' ) );
				setField( 'state', r.state || '' );
				$( document.body ).trigger( 'update_checkout' );
				say( t.filled );
			};
			if ( r.country && r.country !== country() && ( cfg.fill || [] ).indexOf( 'country' ) >= 0 && ( cfg.countries || [] ).indexOf( r.country ) >= 0 ) {
				// WooCommerce swaps the state field after a country change; wait for it.
				$( document.body ).one( 'country_to_state_changed', () => setTimeout( applyAddress, 50 ) );
				field( 'country' ).val( r.country ).trigger( 'change' );
			} else {
				applyAddress();
			}
		}

		function pick( i ) {
			const r = items[ i ];
			// A reply to an earlier keystroke must not overwrite the pick.
			clearTimeout( timer );
			if ( controller ) controller.abort();
			close();
			if ( r ) fill( r );
		}

		$input.on( 'input', () => {
			const q = $input.val().trim();
			clearTimeout( timer );
			if ( q === justFilled ) return;
			if ( q.length < ( cfg.minChars || 3 ) ) {
				close();
				say( '' );
				return;
			}
			timer = setTimeout( () => search( q ), cfg.debounce || 350 );
		} );
		$input.on( 'keydown', ( ev ) => {
			if ( list.attr( 'hidden' ) !== undefined ) return;
			if ( ev.key === 'ArrowDown' ) { ev.preventDefault(); highlight( Math.min( items.length - 1, active + 1 ) ); }
			else if ( ev.key === 'ArrowUp' ) { ev.preventDefault(); highlight( Math.max( 0, active - 1 ) ); }
			else if ( ev.key === 'Enter' && active >= 0 ) { ev.preventDefault(); pick( active ); }
			else if ( ev.key === 'Escape' ) { close(); }
		} );
		$input.on( 'blur', () => setTimeout( close, 150 ) );
	}

	$( function () {
		const billing = document.getElementById( 'billing_company' );
		if ( billing ) attach( billing, 'billing' );
		const reg = document.getElementById( 'billing_company_registration' );
		if ( reg ) attach( reg, 'billing' );
		const shipping = document.getElementById( 'shipping_company' );
		if ( shipping && cfg.shipping ) attach( shipping, 'shipping' );
		if ( cfg.credit && billing ) $( billing ).closest( '.form-row' ).after( cfg.credit );
	} );
} )( jQuery );
