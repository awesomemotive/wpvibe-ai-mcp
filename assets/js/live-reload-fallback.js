/**
 * WPVibe Live Reload Fallback — minimal polling for admin error pages
 * that don't fire admin_footer (e.g., "not allowed" pages).
 *
 * Expects wpvibeLiveReloadFallback to be localized via wp_localize_script with:
 *   endpoint — REST URL for /wpvibe/v1/last-change
 *   nonce    — wp_rest nonce
 *   userId   — current WordPress user ID (integer)
 */
document.addEventListener( 'DOMContentLoaded', function () {
	if ( window.__wpvibe_live_reload ) {
		return;
	}

	var config = window.wpvibeLiveReloadFallback;
	if ( ! config || ! config.endpoint ) {
		return;
	}

	var endpoint = config.endpoint;
	var nonce    = config.nonce;
	var userId   = parseInt( config.userId, 10 ) || 0;
	var lastTs   = 0;

	setInterval( function () {
		fetch( endpoint, {
			headers: { 'X-WP-Nonce': nonce },
			credentials: 'same-origin',
		} )
			.then( function ( r ) {
				return r.ok ? r.json() : null;
			} )
			.then( function ( data ) {
				if ( ! data || ! data.timestamp ) {
					return;
				}
				if ( lastTs === 0 ) {
					lastTs = data.timestamp;
					return;
				}
				if ( data.timestamp <= lastTs ) {
					return;
				}
				lastTs = data.timestamp;

				var action     = data.action || {};
				var isMyChange = userId && data.user_id && userId === data.user_id;
				if ( ! isMyChange ) {
					return;
				}
				var url = action.admin_url || action.url || '';
				if ( url ) {
					window.location.href = url;
				} else {
					location.reload();
				}
			} )
			.catch( function () {} );
	}, 3000 );
} );
