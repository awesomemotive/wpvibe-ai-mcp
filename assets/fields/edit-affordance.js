/**
 * Hover-to-edit affordance — injects an "Edit" pin into each element
 * marked with data-wpvibe-edit (post meta) or data-wpvibe-edit-setting
 * (global setting). Click → navigates to wp-admin with a hash anchor at
 * the matching field input; the admin-side edit-focus script handles
 * scroll + focus + flash on arrival.
 *
 * Expects wpvibeEdit to be localized via wp_localize_script with:
 *   adminPostUrl     — /wp-admin/post.php
 *   adminSettingUrl  — /wp-admin/options-general.php?page=wpvibe
 *   labelEdit        — translated "Edit" label
 */
(function () {
	'use strict';

	var config = window.wpvibeEdit;
	if ( ! config ) {
		return;
	}

	var pencilSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true">' +
		'<path d="M21.731 2.269a2.625 2.625 0 0 0-3.712 0l-1.157 1.157 3.712 3.712 1.157-1.157a2.625 2.625 0 0 0 0-3.712zM19.513 8.199l-3.712-3.712-8.4 8.4a5.25 5.25 0 0 0-1.32 2.214l-.8 2.685a.75.75 0 0 0 .933.933l2.685-.8a5.25 5.25 0 0 0 2.214-1.32l8.4-8.4zM5.25 5.25a3 3 0 0 0-3 3v10.5a3 3 0 0 0 3 3h10.5a3 3 0 0 0 3-3V13.5a.75.75 0 0 0-1.5 0v5.25a1.5 1.5 0 0 1-1.5 1.5H5.25a1.5 1.5 0 0 1-1.5-1.5V8.25a1.5 1.5 0 0 1 1.5-1.5h5.25a.75.75 0 0 0 0-1.5H5.25z"/>' +
		'</svg>';

	function pinHtml( href, label ) {
		var a = document.createElement( 'a' );
		a.className = 'wpvibe-edit-pin';
		a.href      = href;
		a.setAttribute( 'data-wpvibe-no-preview', '' );  // Don't rewrite this URL.
		a.setAttribute( 'aria-label', label );
		a.innerHTML = pencilSvg + '<span>' + label + '</span>';
		return a;
	}

	function ensurePositionedHost( el ) {
		// If the element is inline (e.g. <span>), wrap it so absolute
		// positioning works. Skip if already positioned by the page CSS.
		var display = getComputedStyle( el ).display;
		if ( display === 'inline' ) {
			el.style.display = 'inline-block';
		}
		var position = getComputedStyle( el ).position;
		if ( position === 'static' ) {
			el.style.position = 'relative';
		}
	}

	function attachPostPin( el ) {
		var ref = el.getAttribute( 'data-wpvibe-edit' );
		if ( ! ref || el.querySelector( ':scope > .wpvibe-edit-pin' ) ) {
			return;
		}
		var parts = ref.split( ':' );
		if ( parts.length !== 2 ) {
			return;
		}
		var postId = parseInt( parts[0], 10 );
		var key    = parts[1];
		if ( ! postId || ! key ) {
			return;
		}

		var url  = new URL( config.adminPostUrl, location.origin );
		url.searchParams.set( 'post', String( postId ) );
		url.searchParams.set( 'action', 'edit' );
		var href = url.toString() + '#wpvibe-field-' + key;

		ensurePositionedHost( el );
		el.appendChild( pinHtml( href, config.labelEdit || 'Edit' ) );
	}

	function attachSettingPin( el ) {
		var key = el.getAttribute( 'data-wpvibe-edit-setting' );
		if ( ! key || el.querySelector( ':scope > .wpvibe-edit-pin' ) ) {
			return;
		}
		var href = config.adminSettingUrl + '#wpvibe-field-' + key;
		ensurePositionedHost( el );
		el.appendChild( pinHtml( href, config.labelEdit || 'Edit' ) );
	}

	function scan( root ) {
		( root.querySelectorAll ? root.querySelectorAll( '[data-wpvibe-edit]' ) : [] )
			.forEach( attachPostPin );
		( root.querySelectorAll ? root.querySelectorAll( '[data-wpvibe-edit-setting]' ) : [] )
			.forEach( attachSettingPin );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () { scan( document ); } );
	} else {
		scan( document );
	}

	// Catch dynamically-added editable elements.
	var observer = new MutationObserver( function ( mutations ) {
		mutations.forEach( function ( m ) {
			m.addedNodes.forEach( function ( node ) {
				if ( node.nodeType !== 1 ) {
					return;
				}
				if ( node.matches && node.matches( '[data-wpvibe-edit], [data-wpvibe-edit-setting]' ) ) {
					if ( node.hasAttribute( 'data-wpvibe-edit' ) ) {
						attachPostPin( node );
					} else {
						attachSettingPin( node );
					}
				}
				scan( node );
			} );
		} );
	} );
	observer.observe( document.body, { childList: true, subtree: true } );
} )();
