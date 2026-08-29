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

	var api = { classify: classify, markerFor: markerFor, beaconTarget: beaconTarget, VENDORS: VENDORS };
	if ( typeof module !== 'undefined' && module.exports ) { module.exports = api; }
	if ( ! root || ! root.document ) return;
	root.wpvibeAuthorizePreflight = api;

	var cfg = root.wpvibeAuthorize, authApp = root.authApp, wp = root.wp, $ = root.jQuery;
	if ( ! cfg || ! authApp || ! wp || ! wp.apiRequest || ! wp.hooks || ! $ ) return;

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
		var payload = JSON.stringify( { phase: phase, kind: verdict.kind, status: verdict.status, marker: verdict.marker || '' } );
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
		div.appendChild( p1 );
		var ol = document.createElement( 'ol' );
		[ t.step1, t.step2 ].forEach( function ( s ) { var li = document.createElement( 'li' ); li.textContent = s || ''; ol.appendChild( li ); } );
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

	// 2. Warn-only pre-flight of the same route, same headers auth-app.js will use.
	$( function () {
		try {
			wp.apiRequest( { path: '/wp/v2/users/me/application-passwords?_locale=user', method: 'GET', timeout: 8000 } )
				.fail( function ( jqXHR, textStatus ) {
					try {
						// A slow host (8s cap) or the user clicking Approve mid-flight is not a block.
						if ( textStatus === 'timeout' || textStatus === 'abort' ) return;
						var v = classify( jqXHR && jqXHR.status, jqXHR && jqXHR.getResponseHeader && jqXHR.getResponseHeader( 'content-type' ), jqXHR && jqXHR.responseText, jqXHR && jqXHR.getResponseHeader && jqXHR.getResponseHeader( 'server' ) );
						if ( v.kind === 'json' ) return; // 401 / 403 JSON is a healthy REST answer
						if ( ! document.querySelector( '.wpvibe-authorize-notice' ) ) place( buildNotice( v, 'warning', t.preflight || '' ) );
						beacon( 'preflight', v );
					} catch ( e ) { /* advisory only */ }
				} );
		} catch ( e ) { /* advisory only */ }
	} );
} )( typeof window !== 'undefined' ? window : null );
