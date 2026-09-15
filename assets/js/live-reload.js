/**
 * WPVibe Live Reload — polls for changes and updates the browser.
 *
 * Expects wpvibeLiveReload to be localized via wp_localize_script with:
 *   endpoint  — REST URL for /wpvibe/v1/last-change
 *   nonce     — wp_rest nonce
 *   isAdmin   — "1" or ""
 *   postId    — current post ID being edited (admin only), or "0"
 *   userId    — current WordPress user ID
 */
(function () {
	'use strict';

	var config = window.wpvibeLiveReload;
	if ( ! config || ! config.endpoint || ! window.WPVibePoller ) {
		return;
	}

	var endpoint      = config.endpoint;
	var nonce         = config.nonce;
	var isAdmin       = config.isAdmin === '1';
	var currentPostId = parseInt( config.postId, 10 ) || 0;
	var currentUserId = parseInt( config.userId, 10 ) || 0;
	var pollInterval  = 2500;
	var toastDuration = 15000;

	var lastTimestamp = 0;
	var toastTimer   = null;

	function pollUrl() {
		return lastTimestamp
			? endpoint + ( endpoint.indexOf( '?' ) !== -1 ? '&' : '?' ) + 'since=' + lastTimestamp
			: endpoint;
	}

	function receiveChanges( data ) {
		var changes = data.changes || ( data.timestamp ? [ data ] : [] );
		var baseline = lastTimestamp === 0;
		var changed = false;
		for ( var i = 0; i < changes.length; i++ ) {
			if ( changes[ i ].timestamp <= lastTimestamp ) continue;
			lastTimestamp = changes[ i ].timestamp;
			if ( ! baseline ) {
				handleChange( changes[ i ] );
				changed = true;
			}
		}
		return changed;
	}

	function handleChange( change ) {
		var action       = change.action || {};
		var url          = isAdmin ? ( action.admin_url || action.url || '' ) : ( action.url || '' );
		var label        = action.label || ( url ? 'View' : 'Refresh' );
		var changedPostId = change.post_id || 0;
		var changeUserId = change.user_id || 0;
		var forceNav     = action.force || false;
		var isMyChange   = currentUserId && changeUserId && currentUserId === changeUserId;

		// Only show notifications for the current user's own MCP changes.
		if ( ! isMyChange ) {
			return;
		}

		// Explicit navigate — go immediately, no toast.
		if ( forceNav && url ) {
			window.location.href = url;
			return;
		}

		if ( isAdmin ) {
			if ( currentPostId && changedPostId && currentPostId === changedPostId ) {
				location.reload();
			} else {
				showToast( change.summary || 'Something changed', url, label );
			}
		} else if ( url ) {
			if ( location.href === url || location.href === url + '/' ) {
				location.reload();
			} else {
				window.location.href = url;
			}
		} else {
			location.reload();
		}
	}

	function showToast( summary, targetUrl, actionLabel ) {
		removeToast();

		var btnLabel = actionLabel || ( targetUrl ? 'View' : 'Refresh' );
		var actionBtn = '';
		if ( targetUrl ) {
			actionBtn = '<a href="' + targetUrl + '" target="_blank" class="wpvibe-toast-btn">' + escHtml( btnLabel ) + '</a>';
		} else {
			actionBtn = '<button class="wpvibe-toast-btn" onclick="location.reload()">' + escHtml( btnLabel ) + '</button>';
		}

		var toast = document.createElement( 'div' );
		toast.id = 'wpvibe-reload-toast';
		toast.innerHTML =
			'<span class="wpvibe-toast-dot"></span>' +
			'<span class="wpvibe-toast-text">' + escHtml( summary ) + '</span>' +
			actionBtn +
			'<button class="wpvibe-toast-dismiss" onclick="this.parentElement.remove()">&times;</button>';
		document.body.appendChild( toast );

		if ( ! targetUrl ) {
			toastTimer = setTimeout( removeToast, toastDuration );
		}
	}

	function removeToast() {
		var existing = document.getElementById( 'wpvibe-reload-toast' );
		if ( existing ) {
			existing.remove();
		}
		if ( toastTimer ) {
			clearTimeout( toastTimer );
			toastTimer = null;
		}
	}

	function escHtml( str ) {
		var d = document.createElement( 'div' );
		d.textContent = str;
		return d.innerHTML;
	}

	// Mark that the full script loaded (prevents head fallback from running).
	window.__wpvibe_live_reload = true;

	window.WPVibePoller.start( { url: pollUrl, nonce: nonce, interval: pollInterval, onData: receiveChanges } );
})();
