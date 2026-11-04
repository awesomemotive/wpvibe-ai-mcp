/* WPVibe: a real error on authorize-application.php when Approve cannot reach REST.
 *
 * Advisory only. Never touches the Approve form. Two triggers:
 *  1. core's wp_application_passwords_approve_app_request_error hook, when the
 *     response was not JSON (core shows an empty red bar in that case);
 *  2. a warn-only pre-flight GET of the same route before the user clicks.
 * Reports one beacon per trigger to the Worker named by the authorize link's
 * success_url, host-allowlisted, carrying status + response kind + one vendor marker.
 */
( function ( root ) {
	'use strict';

	var VENDORS = [
		[ /sucuri|cloudproxy/i, 'Sucuri' ],
		[ /wordfence/i, 'Wordfence' ],
		[ /imunify/i, 'Imunify360' ],
		[ /mod_security|modsecurity/i, 'ModSecurity' ],
		[ /cloudflare|cf-ray|attention required/i, 'Cloudflare' ],
		[ /litespeed/i, 'LiteSpeed' ],
		[ /siteground|sg-?captcha/i, 'SiteGround' ],
		[ /godaddy/i, 'GoDaddy' ],
		[ /defender/i, 'Defender' ],
		[ /solid security|ithemes/i, 'Solid Security' ],
		[ /cerber/i, 'WP Cerber' ],
		[ /all[- ]in[- ]one wp security|aiowps/i, 'All In One WP Security' ],
		[ /disable[- ]json[- ]api|disable[- ]rest[- ]api/i, 'Disable REST API' ],
		[ /wpengine|wp engine/i, 'WP Engine' ],
		[ /kinsta/i, 'Kinsta' ],
	];

	/** First vendor recognised in the response text or Server header, else null. */
	function markerFor( text, server ) {
		var hay = ( String( text || '' ).slice( 0, 20000 ) + '\n' + String( server || '' ) );
		for ( var i = 0; i < VENDORS.length; i++ ) {
			if ( VENDORS[ i ][ 0 ].test( hay ) ) return VENDORS[ i ][ 1 ];
		}
		return null;
	}

	/**
	 * What came back. JSON of any status is a healthy REST answer (401 rest_not_logged_in,
	 * 403 rest_cookie_invalid_nonce, 200 list); only non-JSON is a block.
	 * @return {{kind: 'json'|'html'|'empty'|'network', status: number, marker: string|null}}
	 */
	function classify( status, contentType, bodyText, server ) {
		status = Number( status ) || 0;
		var body = String( bodyText || '' );
		var ct = String( contentType || '' ).toLowerCase();
		if ( status === 0 ) return { kind: 'network', status: 0, marker: markerFor( body, server ) };
		var trimmed = body.replace( /^﻿/, '' ).trim();
		var looksJson = /^[\[{]/.test( trimmed );
		if ( looksJson ) {
			try { JSON.parse( trimmed ); return { kind: 'json', status: status, marker: null }; } catch ( e ) { /* fall through */ }
		}
		if ( ct.indexOf( 'application/json' ) !== -1 && looksJson ) return { kind: 'json', status: status, marker: null };
		if ( trimmed === '' ) return { kind: 'empty', status: status, marker: markerFor( body, server ) };
		return { kind: 'html', status: status, marker: markerFor( body, server ) };
	}

	/** Beacon target + state parsed from the authorize link's success_url; null unless the host is allowlisted. */
	function beaconTarget( successUrl, hosts ) {
		try {
			var u = new URL( String( successUrl || '' ) );
			if ( u.protocol !== 'https:' && u.hostname !== 'localhost' ) return null;
			if ( ( hosts || [] ).indexOf( u.hostname ) === -1 ) return null;
			var state = u.searchParams.get( 'state' );
			if ( ! state || ! /^[A-Za-z0-9-]{8,64}$/.test( state ) ) return null;
			return u.origin + '/auth/wp-preflight?state=' + encodeURIComponent( state );
		} catch ( e ) {
			return null;
		}
	}

	/** The success_url marked wpvibe_mint=plugin, so the Worker's callback records how the password was minted (#689); unchanged unless allowlisted. */
	function withMintMarker( successUrl, hosts ) {
		try {
			var u = new URL( String( successUrl || '' ) );
			if ( ( hosts || [] ).indexOf( u.hostname ) === -1 || u.searchParams.has( 'wpvibe_mint' ) ) return successUrl;
			u.searchParams.set( 'wpvibe_mint', 'plugin' );
			return u.toString();
		} catch ( e ) {
			return successUrl;
		}
	}

	var SESSION_ONLY_SIGNALS = [ 'app_password', 'x_wpvibe_authorization', 'authorization_header', 'php_auth_user' ];

	/** The plugin's session-only refusal (#733) as a verdict with its signal and message; null for any other answer. */
	function sessionOnlyVerdict( json, status ) {
		if ( ! json || typeof json !== 'object' || json.code !== 'wpvibe_authorize_session_only' ) return null;
		var signal = json.data && json.data.signal;
		return { kind: 'session_only', status: Number( status ) || 403, marker: null, signal: SESSION_ONLY_SIGNALS.indexOf( signal ) !== -1 ? signal : '', message: String( json.message || '' ) };
	}

	var api = { classify: classify, markerFor: markerFor, beaconTarget: beaconTarget, withMintMarker: withMintMarker, sessionOnlyVerdict: sessionOnlyVerdict, VENDORS: VENDORS };
	if ( typeof module !== 'undefined' && module.exports ) { module.exports = api; }
	if ( ! root || ! root.document ) return;
	root.wpvibeAuthorizePreflight = api;

	var cfg = root.wpvibeAuthorize, authApp = root.authApp, wp = root.wp, $ = root.jQuery;
	if ( ! cfg || ! authApp || ! wp || ! wp.apiRequest || ! wp.hooks || ! $ ) return;

	// Set once the advertised REST root 404s and ?rest_route= answers the preflight.
	var restRouteRoot = '';

	function viaRestRoute( base, options ) {
		var o = $.extend( {}, options );
		o.url = base + String( o.path || '' ).replace( '?', '&' );
		delete o.path;
		return o;
	}

	// Route the Approve mint through the plugin: host firewalls block
	// /wp/v2/users* as user enumeration and the one-click connect dies with
	// a blank red bar (#58). Same request, same session, same success redirect;
	// only the path changes, so wpApiSettings.root still handles pretty vs
	// ?rest_route= permalinks.
	if ( cfg.mintPath ) {
		var origApiRequest = wp.apiRequest;
		var routed = function ( options ) {
			if ( options && typeof options.path === 'string' && options.path.indexOf( '/wp/v2/users/me/application-passwords' ) === 0 && ( options.method || 'GET' ).toUpperCase() === 'POST' ) {
				var q = options.path.indexOf( '?' );
				// dataType: a site serving REST as text/html otherwise hands core a string and it redirects with password=undefined (#689).
				options = $.extend( {}, options, { path: cfg.mintPath + ( q >= 0 ? options.path.slice( q ) : '' ), dataType: 'json' } );
				if ( restRouteRoot ) options = viaRestRoute( restRouteRoot, options );
			}
			return origApiRequest.call( this, options );
		};
		for ( var key in origApiRequest ) {
			if ( Object.prototype.hasOwnProperty.call( origApiRequest, key ) ) routed[ key ] = origApiRequest[ key ];
		}
		wp.apiRequest = routed;
		// Core reads authApp.success after this action to build the redirect (wp-admin/js/auth-app.js).
		wp.hooks.addAction( 'wp_application_passwords_approve_app_request_success', 'wpvibe/mint-path', function () {
			try { authApp.success = withMintMarker( authApp.success, cfg.beaconHosts ); } catch ( e ) { /* telemetry never blocks the page */ }
		} );
	}

	var t = cfg.i18n || {};
	var sent = {};

	function fmt( s ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		return String( s || '' ).replace( /%[sd]/g, function () { return args.length ? String( args.shift() ) : ''; } );
	}

	function beacon( phase, verdict ) {
		var target = beaconTarget( authApp.success, cfg.beaconHosts );
		if ( ! target || sent[ phase ] ) return;
		sent[ phase ] = true;
		var payload = JSON.stringify( { phase: phase, kind: verdict.kind, status: verdict.status, marker: verdict.marker || '', signal: verdict.signal || '' } );
		try {
			if ( root.navigator && root.navigator.sendBeacon ) {
				root.navigator.sendBeacon( target, new Blob( [ payload ], { type: 'text/plain' } ) );
			} else if ( root.fetch ) {
				root.fetch( target, { method: 'POST', body: payload, mode: 'no-cors', keepalive: true } ).catch( function () {} );
			}
		} catch ( e ) { /* telemetry never blocks the page */ }
	}

	function describe( verdict ) {
		var what = verdict.kind === 'html' ? t.html : verdict.kind === 'empty' ? t.empty : t.network;
		if ( verdict.status ) what += ' ' + fmt( t.status, verdict.status );
		return what;
	}

	function mintAddress() {
		var mint = cfg.mintPath || '/wpvibe/v1/authorize';
		return restRouteRoot ? restRouteRoot + mint : String( ( root.wpApiSettings && root.wpApiSettings.root ) || '' ) + mint.replace( /^\//, '' );
	}

	/** DOM-built notice: every string goes through textContent. */
	function buildNotice( verdict, level, lead ) {
		var div = document.createElement( 'div' );
		div.className = 'notice notice-' + level + ' wpvibe-authorize-notice';
		div.setAttribute( 'role', 'alert' );
		var h = document.createElement( 'p' );
		var strong = document.createElement( 'strong' );
		strong.textContent = ( lead ? lead + ' ' : '' ) + ( t.title || '' );
		h.appendChild( strong );
		div.appendChild( h );
		var p1 = document.createElement( 'p' );
		p1.textContent = fmt( t.came_back, describe( verdict ) ) + ( verdict.marker ? ' ' + fmt( t.marker, verdict.marker ) : '' );
		if ( verdict.kind === 'cannot_create' || verdict.kind === 'unavailable_for_user' ) p1.textContent = t[ verdict.kind ] || '';
		if ( verdict.kind === 'session_only' ) p1.textContent = verdict.message;
		div.appendChild( p1 );
		if ( verdict.kind === 'session_only' ) return div; // the message carries its own remedy
		var ol = document.createElement( 'ol' );
		[ t.step1, fmt( t.step2, mintAddress() ) ].forEach( function ( s ) { var li = document.createElement( 'li' ); li.textContent = s || ''; ol.appendChild( li ); } );
		div.appendChild( ol );
		var p2 = document.createElement( 'p' );
		var a = document.createElement( 'a' );
		a.href = cfg.docsUrl || '#';
		a.target = '_blank';
		a.rel = 'noopener';
		a.textContent = t.docs || 'Docs';
		p2.appendChild( a );
		p2.appendChild( document.createTextNode( ' · ' + fmt( t.support, cfg.supportEmail || '' ) ) );
		div.appendChild( p2 );
		return div;
	}

	function place( notice ) {
		var h1 = document.querySelector( 'h1' );
		if ( h1 && h1.parentNode ) h1.parentNode.insertBefore( notice, h1.nextSibling ); else document.body.insertBefore( notice, document.body.firstChild );
		try { notice.focus(); } catch ( e ) {}
	}

	// 1. The failure path: replace core's empty red bar with what actually happened.
	wp.hooks.addAction( 'wp_application_passwords_approve_app_request_error', 'wpvibe/authorize-preflight', function ( error, textStatus, errorThrown, jqXHR ) {
		try {
			var refused = sessionOnlyVerdict( error, jqXHR && jqXHR.status );
			if ( refused ) { beacon( 'approve', refused ); return; } // core shows the plugin's message, which names the remedy
			if ( error && error.message ) return; // core showed a real message; leave it alone
			var v = classify( jqXHR && jqXHR.status, jqXHR && jqXHR.getResponseHeader && jqXHR.getResponseHeader( 'content-type' ), jqXHR && jqXHR.responseText, jqXHR && jqXHR.getResponseHeader && jqXHR.getResponseHeader( 'server' ) );
			if ( v.kind === 'json' ) return;
			// Core's bar is empty on HTTP/2 and carries the bare reason phrase ("Forbidden") on HTTP/1.1.
			var bare = String( errorThrown || '' ).trim();
			$( '.notice-error' ).not( '.wpvibe-authorize-notice' ).filter( function () {
				var t = $( this ).text().trim();
				return t === '' || ( bare !== '' && t === bare );
			} ).remove();
			$( '.wpvibe-authorize-notice' ).remove();
			place( buildNotice( v, 'error', '' ) );
			beacon( 'approve', v );
		} catch ( e ) { /* advisory only */ }
	} );

	function parseJson( text ) {
		try { return JSON.parse( String( text || '' ).replace( /^\uFEFF/, '' ).trim() ); } catch ( e ) { return null; }
	}

	function classifyXhr( jqXHR ) {
		return classify( jqXHR && jqXHR.status, jqXHR && jqXHR.getResponseHeader && jqXHR.getResponseHeader( 'content-type' ), jqXHR && jqXHR.responseText, jqXHR && jqXHR.getResponseHeader && jqXHR.getResponseHeader( 'server' ) );
	}

	/** JSON 404 rest_no_route: WordPress REST answered but without the wpvibe/v1 routes. */
	function noRoute( jqXHR, v ) {
		if ( v.kind !== 'json' || v.status !== 404 ) return false;
		try { return JSON.parse( String( jqXHR.responseText ).replace( /^﻿/, '' ).trim() ).code === 'rest_no_route'; } catch ( e ) { return false; }
	}

	// 2. Warn-only pre-flight of the same route, same headers auth-app.js will use.
	$( function () {
		try {
			var options = { path: ( cfg.preflightPath || '/wpvibe/v1/authorize/preflight' ) + '?_locale=user', method: 'GET', timeout: 8000 };
			var onFacts = function ( facts ) {
				if ( facts && ( facts.reason === 'cannot_create' || facts.reason === 'unavailable_for_user' ) ) {
					var verdict = { kind: facts.reason, status: 200, marker: null };
					if ( ! document.querySelector( '.wpvibe-authorize-notice' ) ) place( buildNotice( verdict, 'warning', t.preflight || '' ) );
					beacon( 'preflight', verdict );
				}
			};
			var warn = function ( v ) {
				if ( ! document.querySelector( '.wpvibe-authorize-notice' ) ) place( buildNotice( v, 'warning', t.preflight || '' ) );
				beacon( 'preflight', v );
			};
			var preflight = wp.apiRequest( options );
			if ( preflight.done ) preflight.done( onFacts );
			preflight
				.fail( function ( jqXHR, textStatus ) {
					try {
						// A slow host (8s cap) or the user clicking Approve mid-flight is not a block.
						if ( textStatus === 'timeout' || textStatus === 'abort' ) return;
						var v = classifyXhr( jqXHR );
						var missing = noRoute( jqXHR, v );
						if ( cfg.restRouteRoot && ( missing || v.kind === 'html' || v.kind === 'empty' ) ) {
							var retry = wp.apiRequest( viaRestRoute( cfg.restRouteRoot, options ) );
							retry.done( function ( facts ) { restRouteRoot = cfg.restRouteRoot; onFacts( facts ); } );
							retry.fail( function ( retryXHR ) {
								try {
									var rv = classifyXhr( retryXHR );
									if ( rv.kind === 'json' && ! noRoute( retryXHR, rv ) ) {
										restRouteRoot = cfg.restRouteRoot;
										var refusedRetry = sessionOnlyVerdict( retryXHR && ( retryXHR.responseJSON || parseJson( retryXHR.responseText ) ), rv.status );
										if ( refusedRetry ) warn( refusedRetry );
										return;
									}
									if ( v.kind !== 'json' ) warn( v );
								} catch ( e ) { /* advisory only */ }
							} );
							return;
						}
						if ( v.kind === 'json' ) {
							var refused = sessionOnlyVerdict( jqXHR && ( jqXHR.responseJSON || parseJson( jqXHR.responseText ) ), v.status );
							if ( refused ) warn( refused );
							return; // any other 401 / 403 JSON is a healthy REST answer
						}
						warn( v );
					} catch ( e ) { /* advisory only */ }
				} );
		} catch ( e ) { /* advisory only */ }
	} );
} )( typeof window !== 'undefined' ? window : null );
