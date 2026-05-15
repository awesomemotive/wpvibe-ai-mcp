/**
 * WPVibe Draft Preview — keeps navigation in preview mode by
 * appending the preview token to same-origin links and form actions.
 *
 * Expects wpvibePreview to be localized via wp_localize_script with:
 *   token — preview token string
 *   param — query param name (wpvibe_preview)
 */
(function () {
	'use strict';

	var config = window.wpvibePreview;
	if ( ! config || ! config.token ) {
		return;
	}

	var token  = config.token;
	var param  = config.param || 'wpvibe_preview';
	var origin = location.origin;

	document.body.classList.add( 'wpvibe-preview-active' );

	function addTokenToUrl( href ) {
		if ( ! href || href.startsWith( '#' ) || href.startsWith( 'mailto:' ) || href.startsWith( 'tel:' ) || href.startsWith( 'javascript:' ) ) {
			return href;
		}
		try {
			var url = new URL( href, origin );
			if ( url.origin !== origin ) {
				return href;
			}
			if ( url.searchParams.has( param ) ) {
				return href;
			}
			url.searchParams.set( param, token );
			return url.toString();
		} catch ( e ) {
			return href;
		}
	}

	document.querySelectorAll( 'a[href]' ).forEach( function ( a ) {
		a.href = addTokenToUrl( a.getAttribute( 'href' ) );
	} );

	document.querySelectorAll( 'form[action]' ).forEach( function ( f ) {
		f.action = addTokenToUrl( f.getAttribute( 'action' ) );
	} );

	var observer = new MutationObserver( function ( mutations ) {
		mutations.forEach( function ( m ) {
			m.addedNodes.forEach( function ( node ) {
				if ( node.nodeType !== 1 ) {
					return;
				}
				if ( node.tagName === 'A' && node.href ) {
					node.href = addTokenToUrl( node.getAttribute( 'href' ) );
				}
				var links = node.querySelectorAll ? node.querySelectorAll( 'a[href]' ) : [];
				links.forEach( function ( a ) {
					a.href = addTokenToUrl( a.getAttribute( 'href' ) );
				} );
			} );
		} );
	} );
	observer.observe( document.body, { childList: true, subtree: true } );
} )();
