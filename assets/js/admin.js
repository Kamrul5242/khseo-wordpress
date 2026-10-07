/**
 * KHSEO admin: steps a running audit through the REST API, then reloads.
 * Loaded only on the Overview screen while an audit is running. Without
 * JavaScript, the "Process next batch" button does the same thing.
 */
( function () {
	'use strict';
	if ( ! window.khseoAdmin || ! window.fetch ) {
		return;
	}
	var progress = document.getElementById( 'khseo-progress' );
	var steps = 0;

	function step() {
		steps++;
		if ( steps > 200 ) {
			return; // Safety stop; the user can continue with the button.
		}
		window.fetch( window.khseoAdmin.stepUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': window.khseoAdmin.nonce }
		} )
			.then( function ( response ) {
				return response.ok ? response.json() : Promise.reject( response.status );
			} )
			.then( function ( data ) {
				var audit = data && data.audit;
				if ( ! audit || audit.status !== 'running' ) {
					window.location.reload();
					return;
				}
				if ( progress ) {
					progress.textContent = 'Audit running: ' + audit.processed + ' URL(s) analysed, ' + audit.remaining + ' remaining.';
				}
				window.setTimeout( step, 500 );
			} )
			.catch( function () {
				if ( progress ) {
					progress.textContent += ' (Automatic progress stopped; use "Process next batch".)';
				}
			} );
	}
	window.setTimeout( step, 300 );
}() );
