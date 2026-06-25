/* WPVibe review notice — dismiss / snooze handlers. */
( function () {
	if ( typeof window.wpvibeReviewNotice === 'undefined' ) return;

	function hide( notice ) {
		notice.style.transition = 'opacity 0.25s ease';
		notice.style.opacity = '0';
		setTimeout( function () {
			if ( notice.parentNode ) notice.parentNode.removeChild( notice );
		}, 250 );
	}

	function postDismiss( notice, status ) {
		var nonce = notice.getAttribute( 'data-wpvibe-review-nonce' ) || '';
		var body = new URLSearchParams();
		body.set( 'action', 'wpvibe_dismiss_review' );
		body.set( '_wpnonce', nonce );
		body.set( 'status', status );
		fetch( window.wpvibeReviewNotice.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} ).catch( function () { /* server-side fallback: notice will reappear next page load */ } );
	}

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.wpvibe-review-action' );
		if ( ! btn ) return;
		var notice = btn.closest( '.wpvibe-review-notice' );
		if ( ! notice ) return;
		var status = btn.getAttribute( 'data-wpvibe-review-action' ) || 'dismissed';
		postDismiss( notice, status );
		// "Leave a review" anchor opens in a new tab (target=_blank) — let the
		// browser handle navigation, just hide the notice locally.
		hide( notice );
	} );
} )();
