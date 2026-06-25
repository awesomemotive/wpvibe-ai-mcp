/**
 * Admin-side companion for the hover-to-edit affordance. Reads the URL
 * hash on load; if it matches #wpvibe-field-{key}, scrolls the matching
 * input into view, focuses it, and briefly flashes the surrounding row.
 *
 * The field renderer emits id="wpvibe-field-{key}" on each registered
 * input, so this works without any per-field wiring.
 */
(function () {
	'use strict';

	function focusFromHash() {
		var hash = location.hash || '';
		if ( hash.indexOf( '#wpvibe-field-' ) !== 0 ) {
			return;
		}
		var id    = hash.slice( 1 );
		var input = document.getElementById( id );
		if ( ! input ) {
			return;
		}
		try {
			input.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		} catch ( e ) {
			input.scrollIntoView();
		}
		// Defer focus until after the smooth-scroll has had a moment to
		// position the element; the focus would otherwise steal the
		// scroll target on some browsers.
		setTimeout( function () { input.focus(); }, 250 );

		var row = input.closest && input.closest( '.wpvibe-field-row' );
		if ( row ) {
			row.classList.add( 'wpvibe-field-row-flash' );
			setTimeout( function () {
				row.classList.remove( 'wpvibe-field-row-flash' );
			}, 1600 );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', focusFromHash );
	} else {
		focusFromHash();
	}

	// Same-page anchor clicks (e.g. from the WPVibe sidebar field list)
	// fire hashchange after the browser scrolls; re-run the focus + flash
	// so a click on a sidebar field has the same effect as arriving with
	// the hash already in the URL.
	window.addEventListener( 'hashchange', focusFromHash );
} )();
