(function () {
	'use strict';

	var active = null;
	var requestTimeout = 10000;
	var outageTimeout = 120000;
	var idleTimeout = 300000;
	var maxFailures = 5;

	function validChange( change ) {
		return change && typeof change === 'object' && typeof change.timestamp === 'number' &&
			isFinite( change.timestamp ) && change.timestamp >= 0 &&
			typeof change.summary === 'string' && typeof change.post_id === 'number' &&
			isFinite( change.post_id ) && change.post_id >= 0 &&
			( change.timestamp === 0 || ( typeof change.user_id === 'number' && isFinite( change.user_id ) && change.user_id > 0 ) ) &&
			change.action && typeof change.action === 'object' && ! Array.isArray( change.action ) &&
			typeof change.action.label === 'string' && typeof change.action.url === 'string' &&
			typeof change.action.admin_url === 'string' && typeof change.action.force === 'boolean';
	}

	function validResponse( data ) {
		if ( ! data || typeof data !== 'object' || Array.isArray( data ) ) return false;
		if ( Object.prototype.hasOwnProperty.call( data, 'changes' ) ) {
			return Array.isArray( data.changes ) && data.changes.every( validChange );
		}
		return validChange( data );
	}

	window.WPVibePoller = {
		start: function ( options ) {
			// A delayed footer script must never start a second poller in this tab.
			if ( active ) return active;
			var stopped = false;
			var inFlight = false;
			var failures = 0;
			var outageAt = null;
			var lastActivity = Date.now();
			var nextAt = Date.now();
			var timer;
			var deadlineTimer;
			var requestTimer;
			var controller;

			function stop() {
				stopped = true;
				clearTimeout( timer );
				clearTimeout( deadlineTimer );
				clearTimeout( requestTimer );
				if ( controller ) controller.abort();
				document.removeEventListener( 'visibilitychange', schedule );
				window.removeEventListener( 'pagehide', stop );
			}

			function deadline() {
				return Math.min( lastActivity + idleTimeout, outageAt === null ? Infinity : outageAt + outageTimeout );
			}

			function armDeadline() {
				clearTimeout( deadlineTimer );
				deadlineTimer = setTimeout( stop, Math.max( 0, deadline() - Date.now() ) );
			}

			function schedule() {
				clearTimeout( timer );
				if ( stopped ) return;
				if ( Date.now() >= deadline() ) return stop();
				if ( document.hidden || inFlight ) return;
				timer = setTimeout( poll, Math.max( 0, nextAt - Date.now() ) );
			}

			function fail( retryAfter ) {
				if ( stopped ) return;
				failures++;
				if ( outageAt === null ) outageAt = Date.now();
				if ( failures >= maxFailures ) return stop();
				var delay = Math.min( 30000, options.interval * Math.pow( 2, failures ) ) * ( 1 + Math.random() * 0.2 );
				if ( retryAfter ) {
					var seconds = /^\d+(?:\.\d+)?$/.test( retryAfter.trim() ) ? Number( retryAfter ) : NaN;
					var requested = isNaN( seconds ) ? Date.parse( retryAfter ) - Date.now() : seconds * 1000;
					if ( isFinite( requested ) && requested > delay ) delay = requested;
				}
				nextAt = Date.now() + delay;
				if ( nextAt >= deadline() ) return stop();
				armDeadline();
			}

			function poll() {
				if ( stopped || inFlight || document.hidden ) return;
				if ( Date.now() >= deadline() ) return stop();
				inFlight = true;
				controller = new AbortController();
				var timedOut = false;
				requestTimer = setTimeout( function () {
					timedOut = true;
					controller.abort();
					fail();
				}, requestTimeout );
				Promise.resolve().then( function () {
					return fetch( options.url(), {
						headers: { 'X-WP-Nonce': options.nonce },
						credentials: 'same-origin',
						signal: controller.signal,
					} );
				} ).then( function ( response ) {
					if ( stopped || timedOut ) return;
					if ( response.status === 401 || response.status === 403 ) return stop();
					if ( ! response.ok ) {
						fail( response.headers.get( 'Retry-After' ) );
						return;
					}
					return response.json().then( function ( data ) {
						if ( stopped || timedOut ) return;
						if ( ! validResponse( data ) ) return fail();
						var changed = options.onData( data ) === true;
						failures = 0;
						outageAt = null;
						if ( changed ) lastActivity = Date.now();
						nextAt = Date.now() + options.interval;
						armDeadline();
					} );
				} ).catch( function () {
					if ( ! stopped && ! timedOut ) fail();
				} ).finally( function () {
					clearTimeout( requestTimer );
					// Error responses may leave an unread streaming body open.
					if ( controller ) controller.abort();
					inFlight = false;
					controller = null;
					schedule();
				} );
			}

			active = { stop: stop };
			if ( typeof AbortController === 'undefined' ) {
				stop();
				return active;
			}
			document.addEventListener( 'visibilitychange', schedule );
			window.addEventListener( 'pagehide', stop );
			armDeadline();
			schedule();
			return active;
		},
	};
})();
