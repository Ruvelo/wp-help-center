/**
 * Ruvelo Help Center: progressive enhancement for the help center blocks.
 *
 * Everything here also works without JavaScript: the search box is a normal
 * search form and "Was this helpful?" posts a normal form. With it, searches
 * suggest articles as you type, and votes are sent without leaving the page.
 */
( function () {
	'use strict';

	const { __, sprintf } = window.wp && window.wp.i18n ? window.wp.i18n : { __: ( text ) => text, sprintf: ( text, ...args ) => args.reduce( ( out, arg ) => out.replace( '%s', arg ), text ) };

	const escapeHtml = ( text ) => String( text ).replace( /[&<>"']/g, ( c ) => ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' } )[ c ] );

	const el = ( tag, className, html ) => {
		const node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( html !== undefined ) {
			node.innerHTML = html;
		}
		return node;
	};

	// Search suggestions: an accessible combobox (WAI-ARIA 1.2 pattern).
	function enhanceSearch( input ) {
		if ( input.dataset.ruveloReady ) {
			return;
		}
		input.dataset.ruveloReady = '1';

		const form = input.form;
		const list = document.getElementById( input.getAttribute( 'aria-controls' ) );
		const status = form.querySelector( '[data-ruvelo-status]' );
		let timer;
		let pending;
		let active = -1;

		if ( ! list ) {
			return;
		}

		const options = () => Array.from( list.querySelectorAll( '[role=option]' ) );

		const close = () => {
			list.hidden = true;
			input.setAttribute( 'aria-expanded', 'false' );
			input.removeAttribute( 'aria-activedescendant' );
			active = -1;
		};

		const highlight = ( index ) => {
			const items = options();
			items.forEach( ( item, i ) => {
				item.classList.toggle( 'is-active', i === index );
				item.setAttribute( 'aria-selected', i === index ? 'true' : 'false' );
			} );
			active = index;
			if ( items[ index ] ) {
				input.setAttribute( 'aria-activedescendant', items[ index ].id );
				items[ index ].scrollIntoView( { block: 'nearest' } );
			} else {
				input.removeAttribute( 'aria-activedescendant' );
			}
		};

		const show = ( data ) => {
			list.replaceChildren();

			if ( data.results.length === 0 ) {
				list.append( el( 'p', 'ruvelo-hc-suggest__empty', escapeHtml( __( 'No articles match yet. Press Enter to search everything.', 'ruvelo-help-center' ) ) ) );
			}

			data.results.forEach( ( result, i ) => {
				const link = el( 'a', '' );
				link.href = result.url;
				link.id = list.id + '-' + i;
				link.setAttribute( 'role', 'option' );
				link.setAttribute( 'aria-selected', 'false' );
				// title_html and snippet_html arrive escaped, with <mark> around the matches.
				link.append( el( 'span', 'ruvelo-hc-suggest__title', result.title_html ) );
				link.append( el( 'span', 'ruvelo-hc-suggest__meta', ( result.category ? escapeHtml( result.category ) + ' · ' : '' ) + result.snippet_html ) );
				list.append( link );
			} );

			if ( data.results.length > 0 ) {
				const all = el( 'a', 'ruvelo-hc-suggest__all' );
				all.href = data.all_url;
				all.id = list.id + '-all';
				all.setAttribute( 'role', 'option' );
				all.setAttribute( 'aria-selected', 'false' );
				/* translators: %s: what the visitor typed */
				all.textContent = sprintf( __( 'See all results for “%s”', 'ruvelo-help-center' ), data.query );
				list.append( all );
			}

			list.hidden = false;
			input.setAttribute( 'aria-expanded', 'true' );
			active = -1;

			if ( status ) {
				status.textContent = data.results.length === 0
					? __( 'No suggestions.', 'ruvelo-help-center' )
					/* translators: %s: number of suggestions */
					: sprintf( __( '%s suggestions. Use the up and down arrows to choose one.', 'ruvelo-help-center' ), String( data.results.length ) );
			}
		};

		input.addEventListener( 'input', () => {
			clearTimeout( timer );
			const query = input.value.trim();

			if ( query.length < 2 ) {
				if ( pending ) {
					pending.abort();
				}
				close();
				return;
			}

			timer = setTimeout( async () => {
				if ( pending ) {
					pending.abort();
				}
				pending = new AbortController();

				try {
					const url = new URL( input.dataset.ruveloSuggest, window.location.href );
					url.searchParams.set( 'q', query );
					const response = await fetch( url, { signal: pending.signal, headers: { Accept: 'application/json' }, credentials: 'same-origin' } );
					if ( ! response.ok ) {
						throw new Error( 'search failed' );
					}
					const data = await response.json();
					if ( input.value.trim() === query ) {
						show( data );
					}
				} catch ( error ) {
					if ( error.name !== 'AbortError' ) {
						close();
					}
				}
			}, 180 );
		} );

		input.addEventListener( 'keydown', ( event ) => {
			const items = options();

			if ( event.key === 'Escape' ) {
				if ( ! list.hidden ) {
					event.preventDefault();
					close();
				}
				return;
			}

			if ( list.hidden || items.length === 0 ) {
				return;
			}

			if ( event.key === 'ArrowDown' ) {
				event.preventDefault();
				highlight( ( active + 1 ) % items.length );
			} else if ( event.key === 'ArrowUp' ) {
				event.preventDefault();
				highlight( active <= 0 ? items.length - 1 : active - 1 );
			} else if ( event.key === 'Enter' && items[ active ] ) {
				event.preventDefault();
				window.location.href = items[ active ].href;
			}
		} );

		input.addEventListener( 'blur', () => setTimeout( close, 150 ) );
		input.addEventListener( 'focus', () => {
			if ( list.childElementCount > 0 && input.value.trim().length >= 2 ) {
				list.hidden = false;
				input.setAttribute( 'aria-expanded', 'true' );
			}
		} );
	}

	// "Was this helpful?" without leaving the page.
	function enhanceFeedback( box ) {
		if ( box.dataset.ruveloReady ) {
			return;
		}
		box.dataset.ruveloReady = '1';

		box.querySelectorAll( 'form' ).forEach( ( form ) => {
			form.addEventListener( 'submit', async ( event ) => {
				event.preventDefault();

				const data = new FormData( form );
				if ( event.submitter && event.submitter.name ) {
					data.set( event.submitter.name, event.submitter.value );
				}
				// The REST API reads _wpnonce as its own nonce; the form's is for the no-JavaScript path.
				data.delete( '_wpnonce' );
				data.delete( 'action' );

				const fallback = () => {
					// Let the browser post the form the ordinary way.
					if ( event.submitter && event.submitter.name ) {
						const hidden = el( 'input', '' );
						hidden.type = 'hidden';
						hidden.name = event.submitter.name;
						hidden.value = event.submitter.value;
						form.append( hidden );
					}
					form.submit();
				};

				const headers = { Accept: 'application/json' };
				if ( box.dataset.restNonce ) {
					headers[ 'X-WP-Nonce' ] = box.dataset.restNonce;
				}

				try {
					const response = await fetch( box.dataset.ruveloFeedback, { method: 'POST', body: data, headers, credentials: 'same-origin' } );
					if ( ! response.ok ) {
						return fallback();
					}
					const result = await response.json();
					box.dataset.state = result.state;
					const focus = box.querySelector( '.ruvelo-hc-fb--' + result.state + ' textarea, .ruvelo-hc-fb--' + result.state + ' [tabindex]' );
					if ( focus ) {
						focus.focus();
					}
				} catch ( error ) {
					fallback();
				}
			} );
		} );
	}

	// Mark the outline entry for the section being read.
	function enhanceOutline( outline ) {
		if ( ! ( 'IntersectionObserver' in window ) ) {
			return;
		}

		const links = Array.from( outline.querySelectorAll( 'a[href^="#"]' ) );
		const targets = links.map( ( link ) => document.getElementById( decodeURIComponent( link.getAttribute( 'href' ).slice( 1 ) ) ) ).filter( Boolean );

		const observer = new IntersectionObserver( ( entries ) => {
			entries.forEach( ( entry ) => {
				if ( entry.isIntersecting ) {
					links.forEach( ( link ) => link.classList.toggle( 'is-active', link.getAttribute( 'href' ) === '#' + entry.target.id ) );
				}
			} );
		}, { rootMargin: '0px 0px -70% 0px' } );

		targets.forEach( ( target ) => observer.observe( target ) );
	}

	function init() {
		document.querySelectorAll( 'input[data-ruvelo-suggest]' ).forEach( enhanceSearch );
		document.querySelectorAll( '.ruvelo-hc-feedback[data-ruvelo-feedback]' ).forEach( enhanceFeedback );
		document.querySelectorAll( '.ruvelo-hc-outline' ).forEach( enhanceOutline );

		// On small screens the menu starts folded, so the article comes first.
		if ( window.matchMedia( '(max-width: 63.99rem)' ).matches ) {
			document.querySelectorAll( '.ruvelo-hc-menu[open]' ).forEach( ( menu ) => menu.removeAttribute( 'open' ) );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
