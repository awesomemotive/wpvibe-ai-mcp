document.addEventListener( 'DOMContentLoaded', function () {
	'use strict';
	if ( window.__wpvibe_live_reload ) return;

	var config = window.wpvibeLiveReloadFallback;
	if ( ! config || ! config.endpoint || ! window.WPVibePoller ) return;

	var userId = parseInt( config.userId, 10 ) || 0;
	var lastTs = 0;
	window.WPVibePoller.start( {
		url: function () { return config.endpoint; },
		nonce: config.nonce,
		interval: 3000,
		onData: function ( data ) {
			var changes = data.changes || ( data.timestamp ? [ data ] : [] );
			var baseline = lastTs === 0;
			var changed = false;
			for ( var i = 0; i < changes.length; i++ ) {
				var change = changes[ i ];
				if ( change.timestamp <= lastTs ) continue;
				lastTs = change.timestamp;
				if ( baseline ) continue;
				changed = true;
				if ( ! userId || userId !== change.user_id ) continue;
				var url = change.action.admin_url || change.action.url || '';
				if ( url ) window.location.href = url;
				else location.reload();
			}
			return changed;
		},
	} );
} );
