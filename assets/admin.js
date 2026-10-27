/**
 * Content Powered > Google Business Profile Post Sync page behaviour. Only loaded on that page.
 *
 * Tabs, the copy button, confirmations, template tokens, the live preview and
 * the "save before connecting" guard. gbp_post_sync_admin.i18n is passed in
 * from PHP (see GBP_Post_Sync_Admin::enqueue_assets).
 */

/* global gbp_post_sync_admin */
( function () {
	'use strict';

	var data = window.gbp_post_sync_admin;

	document.addEventListener( 'DOMContentLoaded', function () {
		if ( typeof data === 'undefined' ) {
			return;
		}

		var form = document.querySelector( '.gbpps-form' );

		initTabs( form );
		initCopy();
		initConfirm();
		initConnectGuard( form );
		initTokens();
		initPreview();
	} );

	// Tabs ---------------------------------------------------------------------

	function initTabs( form ) {
		var tabs = document.querySelectorAll( '[data-gbpps-tab]' );
		var panels = document.querySelectorAll( '[data-gbpps-panel]' );
		var referer = form ? form.querySelector( 'input[name="_wp_http_referer"]' ) : null;
		var refererBase = referer ? referer.value.split( '#' )[ 0 ] : '';

		function show( id ) {
			var found = false;

			tabs.forEach( function ( tab ) {
				var active = tab.getAttribute( 'data-gbpps-tab' ) === id;

				found = found || active;
				tab.classList.toggle( 'is-active', active );
				tab.setAttribute( 'aria-selected', active ? 'true' : 'false' );
			} );

			if ( ! found && tabs.length ) {
				show( tabs[ 0 ].getAttribute( 'data-gbpps-tab' ) );
				return;
			}

			panels.forEach( function ( panel ) {
				panel.hidden = panel.getAttribute( 'data-gbpps-panel' ) !== id;
			} );

			// Come back to this tab after saving.
			if ( referer ) {
				referer.value = refererBase + '#' + id;
			}
		}

		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function ( event ) {
				var id = tab.getAttribute( 'data-gbpps-tab' );

				event.preventDefault();
				show( id );
				history.replaceState( null, '', '#' + id );
			} );
		} );

		window.addEventListener( 'hashchange', function () {
			show( window.location.hash.replace( '#', '' ) );
		} );

		show( window.location.hash.replace( '#', '' ) );
	}

	// Copy the redirect URI ------------------------------------------------------

	function initCopy() {
		document.querySelectorAll( '[data-gbpps-copy]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var input = document.getElementById( button.getAttribute( 'data-gbpps-copy' ) );
				var label = button.lastChild;
				var original = label ? label.textContent : '';

				if ( ! input ) {
					return;
				}

				input.select();

				function done() {
					if ( label ) {
						label.textContent = data.i18n.copied;
						setTimeout( function () {
							label.textContent = original;
						}, 1500 );
					}
				}

				if ( navigator.clipboard && window.isSecureContext ) {
					navigator.clipboard.writeText( input.value ).then( done, function () {
						document.execCommand( 'copy' );
						done();
					} );
				} else {
					document.execCommand( 'copy' );
					done();
				}
			} );
		} );
	}

	// Confirm before disconnecting or clearing ------------------------------------

	function initConfirm() {
		document.querySelectorAll( '[data-gbpps-confirm]' ).forEach( function ( link ) {
			link.addEventListener( 'click', function ( event ) {
				var message = data.i18n[ link.getAttribute( 'data-gbpps-confirm' ) ];

				if ( message && ! window.confirm( message ) ) {
					event.preventDefault();
				}
			} );
		} );
	}

	// Connect uses the saved client, so unsaved edits have to be saved first.

	function initConnectGuard( form ) {
		var connect = document.querySelector( '[data-gbpps-connect]' );
		var dirty = false;

		if ( ! form || ! connect ) {
			return;
		}

		[ 'gbpps-client-id', 'gbpps-client-secret' ].forEach( function ( id ) {
			var field = document.getElementById( id );

			if ( field ) {
				field.addEventListener( 'input', function () {
					dirty = true;
				} );
			}
		} );

		connect.addEventListener( 'click', function ( event ) {
			if ( dirty ) {
				event.preventDefault();
				window.alert( data.i18n.saveFirst );
			}
		} );
	}

	// Template tokens --------------------------------------------------------------

	function initTokens() {
		var template = document.getElementById( 'gbpps-template' );

		if ( ! template ) {
			return;
		}

		document.querySelectorAll( '[data-gbpps-token]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var token = button.getAttribute( 'data-gbpps-token' );
				var start = template.selectionStart;
				var end = template.selectionEnd;

				template.value = template.value.slice( 0, start ) + token + template.value.slice( end );
				template.focus();
				template.selectionStart = template.selectionEnd = start + token.length;
				template.dispatchEvent( new Event( 'input' ) );
			} );
		} );
	}

	// Live preview -------------------------------------------------------------------

	function initPreview() {
		var source = document.getElementById( 'gbpps-preview-data' );
		var template = document.getElementById( 'gbpps-template' );
		var cta = document.getElementById( 'gbpps-cta' );
		var image = document.getElementById( 'gbpps-image' );
		var text = document.querySelector( '[data-gbpps-preview-text]' );
		var button = document.querySelector( '[data-gbpps-preview-cta]' );
		var picture = document.querySelector( '[data-gbpps-preview-image]' );
		var preview;

		if ( ! source || ! template || ! text ) {
			return;
		}

		try {
			preview = JSON.parse( source.textContent );
		} catch ( e ) {
			return;
		}

		function render() {
			var value = template.value;

			Object.keys( preview.tokens ).forEach( function ( token ) {
				value = value.split( token ).join( preview.tokens[ token ] );
			} );

			// Same tidying as the server: collapse spaces and blank lines, cut to length.
			value = value.replace( /\r\n?/g, '\n' ).replace( /[ \t ]+/g, ' ' ).replace( / *\n */g, '\n' ).replace( /\n{3,}/g, '\n\n' ).trim();

			if ( value.length > preview.max ) {
				value = value.slice( 0, preview.max - 1 ).replace( /[\s.,;:-]+\S*$/, '' ) + '…';
			}

			text.textContent = value;
		}

		template.addEventListener( 'input', render );

		if ( cta && button ) {
			cta.addEventListener( 'change', function () {
				button.hidden = cta.value === 'none';
				button.firstElementChild.textContent = preview.ctas[ cta.value ] || '';
			} );
		}

		if ( image && picture && picture.querySelector( 'img' ) ) {
			image.addEventListener( 'change', function () {
				picture.hidden = ! image.checked;
			} );
		}
	}
} )();
