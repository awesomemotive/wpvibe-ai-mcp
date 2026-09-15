/* WPVibe admin page — copy-button handlers. */
( function () {
	function flash( btn ) {
		var original = btn.textContent;
		btn.textContent = btn.id === 'wpvibe-copy-report' ? 'Report copied.' : 'Copied!';
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

( function () {
	var root = document.getElementById( 'wpvibe-connection-check' );
	if ( ! root ) return;
	var run = document.getElementById( 'wpvibe-run-check' );
	var progress = document.getElementById( 'wpvibe-check-progress' );
	var retryStatus = document.getElementById( 'wpvibe-check-retry-status' );
	var results = document.getElementById( 'wpvibe-check-results' );
	var overview = document.getElementById( 'wpvibe-connection-overview' );
	var authorization = document.getElementById( 'wpvibe-authorization-status' );
	var actions = document.getElementById( 'wpvibe-check-report-actions' );
	var copy = document.getElementById( 'wpvibe-copy-report' );
	var download = document.getElementById( 'wpvibe-download-report' );
	var reports = [];
	var busy = false;
	var cooldownUntil = Date.now() + Math.min( 60, Math.max( 0, Number( root.getAttribute( 'data-cooldown' ) ) || 0 ) ) * 1000;
	var cooldownTimer;
	var cooldownNotice = false;
	var flow = 'initial';
	var checkedAt = '';
	var authState = root.getAttribute( 'data-auth-state' );
	var authorized = authState === 'verified' || authState === 'limited';
	var aiObservedAt = Number( root.getAttribute( 'data-ai-observed-at' ) );
	var aiVerified = authorized && aiObservedAt > 0;
	var aiClient = root.getAttribute( 'data-ai-client' ) || '';
	var aiDate = aiVerified && Number.isFinite( new Date( aiObservedAt ).getTime() ) ? new Date( aiObservedAt ).toISOString().replace( 'T', ' ' ).replace( /\.\d{3}Z$/, ' UTC' ) : '';
	var step3 = document.getElementById( 'wpvibe-step-3' );
	var awaiting = !! step3 && step3.getAttribute( 'data-awaiting' ) === '1';
	var legacy = !! step3 && step3.getAttribute( 'data-legacy' ) === '1';
	var active = !! step3 && step3.getAttribute( 'data-active' ) === '1';
	var authObservedAt = Number( root.getAttribute( 'data-auth-observed-at' ) );
	var authDate = authObservedAt > 0 && Number.isFinite( new Date( authObservedAt ).getTime() ) ? new Date( authObservedAt ).toISOString().replace( 'T', ' ' ).replace( /\.\d{3}Z$/, ' UTC' ) : '';
	var labels = { passed: 'Passed', failed: 'Needs attention', limited: 'Limited', unknown: 'Could not verify', not_checked: 'Not tested here' };


	function step( number, state, current ) {
		var element = document.getElementById( 'wpvibe-step-' + number );
		var marker = document.getElementById( 'wpvibe-step-number-' + number );
		if ( ! element || ! marker ) return;
		element.className = 'wpvibe-step' + ( state ? ' wpvibe-step--' + state : '' );
		element.setAttribute( 'aria-current', current ? 'step' : 'false' );
		marker.textContent = state === 'done' ? '✓' : String( number );
	}

	function showOverview( state ) {
		if ( ! overview ) return;
		overview.textContent = '';
		function item( target, title, status, description, color ) {
			if ( ! target ) return;
			var card = document.createElement( 'div' );
			card.className = 'wpvibe-check-row wpvibe-check-row--' + color;
			var heading = document.createElement( 'strong' );
			heading.textContent = title + ': ' + status;
			var text = document.createElement( 'p' );
			text.textContent = description;
			card.appendChild( heading );
			card.appendChild( text );
			target.appendChild( card );
		}
		if ( state === 'initial' && ! reports.length ) return;
		var rootCause = reports.some( function ( report ) { return report.checks.some( function ( row ) { return row.status === 'failed'; } ); } );
		var edge = reports.some( function ( report ) { return report.checks.some( function ( row ) { return /^(Cloudflare|Firewall|Security|Sucuri)/.test( row.stage ) && row.status !== 'passed' && row.status !== 'not_checked'; } ); } );
		if ( state === 'attention' && ( rootCause || edge ) ) { overview.textContent = ''; } else
		item( overview, 'Site connectivity', state === 'done' ? 'Passed' : state === 'attention' ? 'Needs attention' : state === 'current' ? 'Checking' : 'Not checked',
			state === 'done' ? 'WPVibe reached this WordPress installation.' + ( checkedAt ? ' Checked ' + checkedAt + '.' : '' ) : state === 'attention' ? 'This check did not confirm all required settings and a working network path. Review the guidance below; completed results remain useful.' : state === 'current' ? 'Checking WordPress settings and requests between your site and WPVibe.' : 'Run Check site connectivity to test WordPress settings and network access.',
			state === 'done' ? 'passed' : state === 'attention' ? 'unknown' : 'not_checked' );
		if ( authorization ) authorization.textContent = '';
		if ( authState === 'not_checked' ) return;
		item( authorization, 'WordPress authorization', authState === 'verified' ? 'Verified' : authState === 'limited' ? 'Limited access' : String( authState ).indexOf( 'failing:' ) === 0 ? 'Needs attention' : state === 'done' ? 'Ready to authorize WordPress' : 'Not authorized yet',
			authorized ? 'WordPress accepted the saved authorization' + ( authDate ? ' at ' + authDate : '' ) + '. This was verified separately.' + ( authState === 'limited' ? ' Some management actions require additional permissions.' : '' ) : ( String( authState ).indexOf( 'failing:' ) === 0 ? 'The last authorization check needs attention' + ( authDate ? ' (' + authDate + ')' : '' ) + '. Use Check authorized access for the current result and next step.' : 'Follow the instructions below to approve access while signed into WordPress.' ),
			authState === 'verified' ? 'passed' : authState === 'limited' || String( authState ).indexOf( 'failing:' ) === 0 ? 'limited' : 'not_checked' );
		item( authorization, 'AI read', aiVerified ? 'Confirmed' : active ? 'Active' : authorized ? 'Waiting for your AI' : 'Not yet',
			aiVerified ? 'Your AI read this site through WPVibe' + ( aiDate ? ' at ' + aiDate : '' ) + '.' + ( aiClient ? ' Client: ' + aiClient + '.' : '' ) : active ? 'Your AI has used this site recently.' : authorized ? 'Paste the Step 4 prompt into your AI, then refresh this page.' : 'Confirmed after your AI reads the site once.',
			aiVerified || active ? 'passed' : 'not_checked' );
	}

	function showFlow( state ) {
		flow = state;
		showOverview( state );
		var proceed = state === 'done' || ( state === 'initial' && ( authorized || awaiting || legacy ) );
		step( 2, state === 'initial' ? ( authorized || awaiting || legacy ? '' : 'current' ) : state, ! proceed );
		step( 3, authState === 'verified' || legacy ? 'done' : ( authState === 'limited' || String( authState ).indexOf( 'failing:' ) === 0 ? 'attention' : ( proceed ? 'current' : '' ) ), proceed && ! authorized && ! legacy );
		step( 4, aiVerified || legacy || active ? 'done' : ( proceed && ( authorized || awaiting ) ? 'current' : '' ), proceed && ( authorized || awaiting ) && ! aiVerified && ! active );
	}
	function passedMessage() {
		var next = aiVerified || active || legacy ? 'Your site is connected.' : authorized ? 'Continue to Step 4 to confirm your AI can read the site.' : String( authState ).indexOf( 'failing:' ) === 0 ? 'Review WordPress access in Step 3.' : 'Continue to Step 3 to connect your site.';
		var attention = reports.some( function ( report ) { return report.checks.some( function ( row ) { return row.status !== 'passed' && row.status !== 'not_checked'; } ); } );
		return 'Connection check passed. ' + next + ( attention ? ' Review the guidance below for individual requests that need attention.' : '' );
	}

	function updateCooldown() {
		clearTimeout( cooldownTimer );
		var remaining = Math.max( 0, Math.ceil( ( cooldownUntil - Date.now() ) / 1000 ) );
		run.disabled = busy || remaining > 0;
		if ( busy ) return;
		var passed = flow === 'done';
		run.hidden = false;
		run.textContent = 'Check site connectivity';
		if ( retryStatus ) retryStatus.textContent = remaining > 0 ? 'Available again in ' + remaining + ' seconds.' : '';
		if ( cooldownNotice && ! passed ) {
			progress.textContent = remaining > 0
				? 'Please wait ' + remaining + ' seconds before running another check. Previous results are unchanged.'
				: 'You can run another connection check now. Previous results are unchanged.';
		}
		if ( remaining > 0 ) cooldownTimer = setTimeout( updateCooldown, 1000 );
	}

	function reportText() {
		return 'WPVibe connection report\n' + reports.map( function ( report ) {
			if ( report.report ) return report.report;
			return 'Site: ' + report.site + '\nChecked (UTC): ' + report.checkedAt + '\n' + report.checks.map( function ( row ) {
				return '[' + labels[ row.status ] + '] ' + row.stage + ( row.via ? ' (' + row.via + ')' : '' ) + ': ' + row.summary + ( row.next ? '\nNext: ' + row.next : '' ) + ( row.rule ? '\nRule: ' + row.rule : '' ) + ( row.supportDetails ? '\nDetails:\n' + row.supportDetails : '' );
			} ).join( '\n' ) + '\nRequest evidence:\n' + JSON.stringify( report.evidence || [], null, 2 ) + ( report.note ? '\n' + report.note : '' );
		} ).join( '\n\n' ) + '\n\nPlease inspect the failed requests at the listed times and identify the responsible layer. If a security rule caused the failure, advise the narrow exception needed for these WordPress REST requests. Please do not disable the firewall or allow all requests.';
	}

	function explanation( card, label, text ) {
		var paragraph = document.createElement( 'p' );
		var heading = document.createElement( 'strong' );
		heading.textContent = label + ': ';
		var body = document.createElement( 'span' );
		body.textContent = text;
		paragraph.appendChild( heading );
		paragraph.appendChild( body );
		card.appendChild( paragraph );
	}

	function render() {
		var privacyHint = document.getElementById( 'wpvibe-check-privacy-hint' );
		if ( privacyHint ) privacyHint.hidden = reports.length > 0;
		results.textContent = '';
		var details = document.createElement( 'details' );
		details.className = 'wpvibe-check-details';
		var summary = document.createElement( 'summary' );
		details.appendChild( summary );
		summary.textContent = 'Technical details';
		var failed = [];
		reports.forEach( function ( report ) { report.checks.forEach( function ( row ) { if ( row.status === 'failed' ) failed.push( row ); } ); } );
		var edgeBlock = reports.some( function ( report ) { return report.checks.some( function ( row ) { return /^(Cloudflare|Firewall|Security|Sucuri)/.test( row.stage ) && row.status !== 'passed' && row.status !== 'not_checked'; } ); } );
		var seen = {};
		reports.forEach( function ( report ) {
			report.checks.forEach( function ( row ) {
				if ( [ 'Authenticated site access', 'AI connection', 'Authorization and AI access', 'WordPress authorization endpoint', 'Alternate network path' ].indexOf( row.stage ) !== -1 && row.status === 'not_checked' ) return;
				var key = row.stage + '|' + ( row.via || '' ) + '|' + row.status + '|' + ( row.summary || '' );
				if ( seen[ key ] ) return;
				seen[ key ] = true;
				var lead = row.status === 'failed' || ( edgeBlock && /^(Cloudflare|Firewall|Security|Sucuri)/.test( row.stage ) && row.status !== 'passed' );
				var echo = ! lead && row.status !== 'passed' && row.status !== 'not_checked' && ( failed.length > 0 || edgeBlock );
				if ( edgeBlock && row.status === 'failed' && /web page instead of JSON/.test( row.summary || '' ) ) { lead = false; echo = true; }
				var card = document.createElement( 'div' );
				card.className = 'wpvibe-check-row wpvibe-check-row--' + ( labels[ row.status ] ? row.status : 'unknown' );
				var heading = document.createElement( 'strong' );
				heading.textContent = row.stage + ( row.via ? ' (' + row.via + ')' : '' ) + ': ' + ( labels[ row.status ] || labels.unknown );
				card.appendChild( heading );
				var ownerNote = '';
				if ( /^Cloudflare/.test( row.stage ) && row.status !== 'passed' ) {
					var edgeInfo = ( reports[ 0 ] && reports[ 0 ].edge ) || {};
					if ( edgeInfo.owner === 'customer' ) ownerNote = 'This site uses the Cloudflare plugin, so this is your own Cloudflare account. Open Cloudflare > Security > WAF > Custom rules and add the exception from the report.';
					else if ( edgeInfo.owner === 'host' ) ownerNote = edgeInfo.name + ' manages Cloudflare for this site. Send the report to ' + edgeInfo.name + ' support and ask them to add the exception.';
				}
				[ row.summary, row.next, ownerNote ].filter( Boolean ).forEach( function ( text ) {
					var paragraph = document.createElement( 'p' );
					paragraph.textContent = text;
					card.appendChild( paragraph );
				} );
				if ( row.rule && row.status !== 'passed' ) {
					var ruleLabel = document.createElement( 'p' );
					ruleLabel.className = 'wpvibe-rule-label';
					ruleLabel.textContent = 'Rule expression (Cloudflare > Security > WAF > Custom rules, action: Skip):';
					var ruleRow = document.createElement( 'div' );
					ruleRow.className = 'wpvibe-copy-row';
					var ruleCode = document.createElement( 'code' );
					ruleCode.className = 'wpvibe-copy-text';
					ruleCode.textContent = row.rule;
					var ruleCopy = document.createElement( 'button' );
					ruleCopy.type = 'button';
					ruleCopy.className = 'wpvibe-copy-btn';
					ruleCopy.setAttribute( 'data-wpvibe-copy', row.rule );
					ruleCopy.textContent = 'Copy';
					ruleRow.appendChild( ruleCode );
					ruleRow.appendChild( ruleCopy );
					card.appendChild( ruleLabel );
					card.appendChild( ruleRow );
				}

				if ( row.status === 'passed' || row.status === 'not_checked' || echo ) {
					details.appendChild( card );
				} else if ( lead ) {
					results.insertBefore( card, results.firstChild );
				} else {
					results.appendChild( card );
				}
			} );
			if ( report.note ) {
				var note = document.createElement( 'p' );
				note.textContent = report.note;
				details.appendChild( note );
			}
		} );
		if ( details.children.length > 1 ) results.appendChild( details );
		actions.hidden = ! reports.length;
		copy.setAttribute( 'data-wpvibe-copy', reportText() );
	}

	async function request( action, challenge, timeout ) {
		var controller = new AbortController();
		var timer = setTimeout( function () { controller.abort(); }, timeout );
		var startedAt = new Date().toISOString();
		var response;
		try {
			var body = new URLSearchParams( { action: action, nonce: root.getAttribute( 'data-nonce' ) } );
			if ( challenge ) body.set( 'challenge', challenge );
			response = await fetch( root.getAttribute( 'data-ajax-url' ), { method: 'POST', credentials: 'same-origin', body: body, signal: controller.signal } );
			var text = await response.text();
			if ( text.length > 300000 ) throw new Error( 'The check returned an unexpectedly large response. Refresh and retry once.' );
			var data;
			try { data = JSON.parse( text ); } catch ( e ) { if ( response.status === 429 ) data = {}; else throw new Error( 'The WordPress check did not return JSON (HTTP ' + response.status + '). A login, security rule, or server error may have interrupted it. Refresh and retry once; send the report to your host and WPVibe if it repeats.' ); }
			if ( ! response.ok || ! data.success ) {
				var error = new Error( data.data && data.data.message || 'The check could not complete. Refresh and retry once.' );
				if ( response.status === 429 ) {
					error.cooldown = true;
					error.retryAfter = Math.min( 300, Math.max( 1, Number( data.data && data.data.retry_after ) || 60 ) );
				}
				throw error;
			}
			return data.data;
		} catch ( error ) {
			var page = new URL( document.location.href );
			var target = new URL( root.getAttribute( 'data-ajax-url' ), page );
			error.evidence = [ {
				path: 'browser_to_wordpress', action: action, method: 'POST', checkedAt: startedAt,
				page: page.origin + page.pathname, url: target.origin + target.pathname,
				httpStatus: response ? response.status : null,
				responseAvailable: Boolean( response ), crossOrigin: target.origin !== page.origin
			} ];
			if ( ! response && error.name !== 'AbortError' ) {
				error.message = 'Your browser could not complete the request to WordPress that runs this check. No HTTP response was available to identify the cause. Refresh this admin page and retry; if it repeats, send this report to WPVibe support with any browser Network or Console error. Check for a redirect or a different WordPress admin address before changing security rules.';
			}
			throw error;
		} finally { clearTimeout( timer ); }
	}

	var watchTimer;
	var watchUntil = 0;
	function watchLine( kind, text, waiting ) {
		var line = document.getElementById( 'wpvibe-watch-' + kind );
		if ( ! line ) return;
		line.textContent = text;
		line.classList[ waiting ? 'add' : 'remove' ]( 'is-waiting' );
	}
	function applyState( state ) {
		var changedAuth = state.auth_state !== authState;
		var changedAi = state.ai_observed_at > 0 && ! aiVerified;
		authState = state.auth_state;
		authorized = authState === 'verified' || authState === 'limited';
		if ( authorized ) awaiting = false;
		if ( authState !== 'not_checked' ) legacy = false;
		authObservedAt = Number( state.auth_observed_at ) || 0;
		authDate = authObservedAt > 0 ? new Date( authObservedAt ).toISOString().replace( 'T', ' ' ).replace( /\.\d{3}Z$/, ' UTC' ) : '';
		aiObservedAt = Number( state.ai_observed_at ) || 0;
		aiVerified = authorized && aiObservedAt > 0;
		aiClient = state.ai_client || '';
		aiDate = aiVerified ? new Date( aiObservedAt ).toISOString().replace( 'T', ' ' ).replace( /\.\d{3}Z$/, ' UTC' ) : '';
		if ( changedAuth || changedAi ) {
			showFlow( flow === 'initial' && reports.length ? 'done' : flow );
			var title3 = document.getElementById( 'wpvibe-step-title-3' );
			var title4 = document.getElementById( 'wpvibe-step-title-4' );
			if ( title3 && authState === 'verified' ) title3.textContent = 'Site connected';
			if ( title4 && aiVerified ) title4.textContent = 'Your AI can read this site';
			if ( flow === 'done' ) progress.textContent = passedMessage();
		}
		return { auth: authorized, ai: aiVerified };
	}
	async function pollState( kind ) {
		if ( document.hidden ) { watchTimer = setTimeout( function () { pollState( kind ); }, 15000 ); return; }
		try {
			var state = applyState( await request( 'wpvibe_connection_state', '', 8000 ) );
			if ( kind === 'auth' && state.auth ) {
				watchLine( 'auth', 'Approved. Continue to Step 4.' );
				if ( ! state.ai ) { watchLine( 'ai', 'Waiting for your AI to read the site. This page updates on its own.', true ); watchTimer = setTimeout( function () { pollState( 'ai' ); }, 5000 ); }
				return;
			}
			if ( kind === 'ai' && state.ai ) { watchLine( 'ai', 'Confirmed. Your site is connected.' ); setTimeout( function () { document.location.reload(); }, 2000 ); return; }
		} catch ( e ) { /* keep waiting; the next poll retries */ }
		if ( Date.now() > watchUntil ) { watchLine( kind, 'Still waiting. Refresh this page after your AI finishes.' ); return; }
		watchTimer = setTimeout( function () { pollState( kind ); }, 5000 );
	}
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '[data-wpvibe-watch]' );
		if ( ! btn ) return;
		var kind = btn.getAttribute( 'data-wpvibe-watch' );
		if ( ( kind === 'auth' && authorized ) || ( kind === 'ai' && aiVerified ) ) return;
		clearTimeout( watchTimer );
		watchUntil = Date.now() + 15 * 60000;
		watchLine( kind, kind === 'auth' ? 'Waiting for you to approve in WordPress. This page updates on its own.' : 'Waiting for your AI to read the site. This page updates on its own.', true );
		watchTimer = setTimeout( function () { pollState( kind ); }, 5000 );
	} );

	if ( ( authorized || awaiting ) && ! aiVerified && ! active ) {
		watchUntil = Date.now() + 15 * 60000;
		watchLine( 'ai', 'Waiting for your AI to read the site. This page updates on its own.', true );
		watchTimer = setTimeout( function () { pollState( 'ai' ); }, 5000 );
	}
	showFlow( 'initial' );
	try {
		var saved = JSON.parse( root.getAttribute( 'data-last-check' ) || 'null' );
		if ( saved && saved.site === root.getAttribute( 'data-site-url' ) && ( saved.state === 'done' || saved.state === 'attention' ) && Array.isArray( saved.reports ) && saved.reports.length && Number.isFinite( Date.parse( saved.checkedAt ) ) ) {
			reports = saved.reports;
			checkedAt = new Date( saved.checkedAt ).toISOString().replace( 'T', ' ' ).replace( /\.\d{3}Z$/, ' UTC' );
			render();
			showFlow( saved.state );
			progress.textContent = saved.state === 'done' ? passedMessage() : 'Last connectivity check needs attention (' + checkedAt + '). Review the saved results below, then check again.';
		}
	} catch ( e ) { /* Ignore an unreadable saved report. */ }
	updateCooldown();
	if ( /[?&]wpvibe_recheck=1/.test( String( document.location.href || '' ) ) && ! run.disabled ) setTimeout( function () { run.click(); }, 300 );

	run.addEventListener( 'click', async function () {
		if ( busy ) return;
		if ( Date.now() < cooldownUntil ) { cooldownNotice = true; updateCooldown(); return; }
		busy = true;
		cooldownNotice = false;
		clearTimeout( cooldownTimer );
		run.disabled = true;
		run.classList.add( 'is-busy' );
		run.textContent = 'Checking\u2026';
		var previousReports = reports;
		var previousFlow = flow;
		var localBlocked = false;
		progress.textContent = 'Checking local WordPress settings…';
		try {
			var prepared = await request( 'wpvibe_connection_prepare', '', 10000 );
			localBlocked = prepared.report.checks.some( function ( row ) { return row.status === 'failed'; } );
			showFlow( 'current' );
			cooldownUntil = Date.now() + 60000;
			reports = [ prepared.report ];
			render();
			progress.textContent = 'Testing this site\u2019s own REST API and login headers…';
			try {
				var self = await request( 'wpvibe_connection_self', prepared.challenge, 20000 );
				if ( self && Array.isArray( self.checks ) ) {
					reports.push( self );
					localBlocked = localBlocked || self.checks.some( function ( row ) { return row.status === 'failed'; } );
					render();
				}
			} catch ( e ) { /* the remote check still runs; the self probe is advisory */ }
			progress.textContent = 'Testing requests from WPVibe back to this site…';
			var remote = await request( 'wpvibe_connection_remote', prepared.challenge, 30000 );
			if ( remote.cooldown ) {
				throw Object.assign( new Error( 'Check cooldown' ), { cooldown: true, retryAfter: Math.min( 300, Math.max( 1, Number( remote.retry_after ) || 60 ) ) } );
			}
			reports.push( remote );
			render();
			var reached = remote.checks.some( function ( row ) { return row.stage === 'WPVibe server reaching this installation' && row.status === 'passed'; } );
			var outboundFailed = remote.checks.some( function ( row ) { return row.stage === 'Remote readiness check'; } );
			checkedAt = new Date( remote.checkedAt ).toISOString().replace( 'T', ' ' ).replace( /\.\d{3}Z$/, ' UTC' );
			var edgeBlocked = remote.checks.some( function ( row ) { return /^(Cloudflare|Firewall|Security|Sucuri)/.test( row.stage ) && row.status !== 'passed' && row.status !== 'not_checked'; } );
			showFlow( ! localBlocked && reached ? 'done' : 'attention' );
			progress.textContent = edgeBlocked && ! reached
				? 'A firewall in front of this site is blocking WPVibe before it reaches WordPress. Follow the card below, then run the check again.'
				: localBlocked
				? 'Fix the WordPress requirements shown below, then run the check again.'
				: reached
					? passedMessage()
					: outboundFailed
						? 'Your site could not reach WPVibe. Wait one minute and click Check site connectivity again. If it fails twice, click Copy report and send it to support@wpvibe.ai.'
						: 'WPVibe could not reach your site. Wait one minute and try again. If it fails twice, click Copy report and send it to your host with the message at the bottom of the report.';
		} catch ( error ) {
			if ( error.cooldown ) {
				if ( localBlocked ) {
					showFlow( 'attention' );
					progress.textContent = 'Fix the WordPress requirements shown below, then check again. The remote check is waiting for its retry window.';
				} else {
					if ( previousReports.length ) { reports = previousReports; render(); }
					showFlow( previousFlow );
					if ( previousFlow === 'done' ) progress.textContent = passedMessage( false );
				}
				cooldownUntil = Date.now() + error.retryAfter * 1000;
				cooldownNotice = ! localBlocked;
				return;
			}
			showFlow( 'attention' );
			var message = error.name === 'AbortError' ? 'The check timed out. The failing layer is not yet known. Wait one minute and retry; send the report to WPVibe support if it repeats.' : error.message;
			progress.textContent = message;
			if ( ! reports.length ) reports = [ { site: root.getAttribute( 'data-site-url' ), checkedAt: new Date().toISOString(), checks: [], evidence: [] } ];
			if ( reports.length ) {
				reports.push( { site: reports[ 0 ].site, checkedAt: new Date().toISOString(), checks: [ { stage: 'Check completion', status: 'unknown', summary: message, next: 'Completed results below remain useful. Include this report and any AI error when contacting support@wpvibe.ai.' } ], evidence: error.evidence || [] } );
				render();
			}
		} finally {
			busy = false;
			run.classList.remove( 'is-busy' );
			updateCooldown();
		}
	} );
	download.addEventListener( 'click', function () {
		var url = URL.createObjectURL( new Blob( [ reportText() ], { type: 'text/plain;charset=utf-8' } ) );
		var link = document.createElement( 'a' );
		link.href = url;
		link.download = 'wpvibe-connection-report.txt';
		link.click();
		setTimeout( function () { URL.revokeObjectURL( url ); }, 1000 );
	} );
} )();
