/**
 * The Google Business Profile box in the post editor sidebar.
 *
 * Share now and Remove from Google, the character counter, and status checks:
 * after a save in the block editor, and while a share is queued, the box asks
 * the server for its status (which also runs a due share right away).
 * gbp_post_sync_editor.ajaxUrl, .action and .i18n come from PHP (see
 * GBP_Post_Sync_Editor::enqueue).
 */

/* global gbp_post_sync_editor */
( function () {
	'use strict';

	var data = window.gbp_post_sync_editor;

	document.addEventListener( 'DOMContentLoaded', function () {
		var box = document.querySelector( '.gbpps-box' );

		if ( ! box || typeof data === 'undefined' ) {
			return;
		}

		var polls = 0;
		var timer = null;

		function wrap() {
			return box.querySelector( '.gbpps-status-wrap' );
		}

		function state() {
			var status = box.querySelector( '.gbpps-status' );

			return status ? status.getAttribute( 'data-state' ) : '';
		}

		function request( op ) {
			var body = new FormData();
			var summary = box.querySelector( '#gbpps-summary' );

			body.append( 'action', data.action );
			body.append( 'op', op );
			body.append( 'post_id', box.getAttribute( 'data-post-id' ) );
			body.append( 'nonce', box.getAttribute( 'data-nonce' ) );

			if ( op === 'share' && summary ) {
				body.append( 'summary', summary.value );
			}

			box.classList.add( 'is-busy' );

			return fetch( data.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( json ) {
					box.classList.remove( 'is-busy' );

					if ( json && json.success && json.data && typeof json.data.html === 'string' ) {
						wrap().innerHTML = json.data.html;
						afterRender();
					} else if ( op !== 'status' ) {
						window.alert( json && json.data && json.data.message ? json.data.message : data.i18n.failed );
					}
				} )
				.catch( function () {
					box.classList.remove( 'is-busy' );

					if ( op !== 'status' ) {
						window.alert( data.i18n.failed );
					}
				} );
		}

		// Keep checking while a share is queued (about a minute at most).
		function poll() {
			clearTimeout( timer );

			if ( state() === 'queued' && polls < 15 ) {
				polls++;
				timer = setTimeout( function () {
					request( 'status' );
				}, 4000 );
			}
		}

		function afterRender() {
			var status = box.querySelector( '.gbpps-status' );
			var option = box.querySelector( '.gbpps-share-option' );

			// Once published, the "share when published" checkbox has done its job.
			if ( option && status && status.getAttribute( 'data-published' ) === '1' ) {
				option.hidden = true;
			}

			poll();
		}

		box.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '[data-gbpps-op]' );

			if ( ! button ) {
				return;
			}

			event.preventDefault();

			if ( button.getAttribute( 'data-gbpps-op' ) === 'remove' && ! window.confirm( data.i18n.confirm ) ) {
				return;
			}

			if ( button.getAttribute( 'data-gbpps-op' ) === 'share' ) {
				button.lastChild.textContent = ' ' + data.i18n.working;
			}

			request( button.getAttribute( 'data-gbpps-op' ) );
		} );

		var summary = box.querySelector( '#gbpps-summary' );
		var count = box.querySelector( '[data-gbpps-count]' );

		if ( summary && count ) {
			summary.addEventListener( 'input', function () {
				count.textContent = summary.value.length;
			} );
		}

		// Block editor: check again after each save (the sidebar box is saved
		// in a second request, so wait a moment).
		if ( window.wp && wp.data && wp.data.select && wp.data.select( 'core/editor' ) ) {
			var saving = false;

			wp.data.subscribe( function () {
				var editor = wp.data.select( 'core/editor' );
				var now = editor.isSavingPost() && ! editor.isAutosavingPost();

				if ( saving && ! now ) {
					polls = 0;
					setTimeout( function () {
						request( 'status' );
					}, 2500 );
				}

				saving = now;
			} );
		}

		afterRender();
	} );
} )();
