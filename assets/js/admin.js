/* WPVibe admin page — copy-button handlers. */
( function () {
	function flash( btn ) {
		var original = btn.textContent;
		btn.textContent = 'Copied!';
		btn.classList.add( 'is-copied' );
		setTimeout( function () {
			btn.textContent = original;
			btn.classList.remove( 'is-copied' );
		}, 1500 );
	}

	function legacyCopy( text ) {
		var ta = document.createElement( 'textarea' );
		ta.value = text;
		ta.setAttribute( 'readonly', '' );
		ta.style.position = 'absolute';
		ta.style.left = '-9999px';
		document.body.appendChild( ta );
		ta.select();
		try { document.execCommand( 'copy' ); } catch ( e ) { /* nothing useful to do */ }
		document.body.removeChild( ta );
	}

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '[data-wpvibe-copy]' );
		if ( ! btn ) return;
		var text = btn.getAttribute( 'data-wpvibe-copy' ) || '';
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( function () {
				flash( btn );
			}, function () {
				legacyCopy( text );
				flash( btn );
			} );
		} else {
			legacyCopy( text );
			flash( btn );
		}
	} );
} )();
