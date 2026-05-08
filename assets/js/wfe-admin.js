/**
 * Widget Finder for Elementor — Admin JS
 * Handles Plugin Manager: deactivate, uninstall, bulk actions.
 */
( function () {
	'use strict';

	if ( typeof wfxAdmin === 'undefined' ) return;

	const cfg  = wfxAdmin;
	const i18n = cfg.i18n;

	// ── helpers ───────────────────────────────────────────────────────────────

	function restCall( method, endpoint, body ) {
		return fetch( cfg.restUrl + endpoint, {
			method:  method,
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   cfg.nonce,
			},
			body: body ? JSON.stringify( body ) : undefined,
		} ).then( function ( r ) { return r.json(); } );
	}

	function confirm( msg ) {
		return window.confirm( msg );
	}

	function setRowStatus( row, text ) {
		var actionsCell = row.querySelector( '.column-actions' );
		if ( actionsCell ) {
			actionsCell.innerHTML = '<span class="wfx-row-status">' + text + '</span>';
		}
	}

	function removeRow( row ) {
		row.style.transition = 'opacity 0.3s';
		row.style.opacity    = '0';
		setTimeout( function () { row.remove(); }, 320 );

		// If table is now empty, refresh page
		var tbody = document.querySelector( '.wfe-plugins-table tbody' );
		if ( tbody && tbody.querySelectorAll( 'tr' ).length === 1 ) {
			setTimeout( function () { location.reload(); }, 400 );
		}
	}

	// ── deactivate ────────────────────────────────────────────────────────────

	function doDeactivate( btn ) {
		if ( ! confirm( i18n.confirmDeactivate ) ) return;

		var row        = btn.closest( 'tr' );
		var pluginFile = btn.dataset.pluginFile;

		setRowStatus( row, i18n.deactivating );

		restCall( 'POST', 'deactivate', { plugin_file: pluginFile } )
			.then( function ( data ) {
				if ( data.success ) {
					location.reload();
				} else {
					setRowStatus( row, i18n.error.replace( '%s', data.message || '?' ) );
				}
			} )
			.catch( function ( e ) {
				setRowStatus( row, i18n.error.replace( '%s', e.message ) );
			} );
	}

	// ── uninstall ─────────────────────────────────────────────────────────────

	function doUninstall( btn ) {
		if ( ! confirm( i18n.confirmUninstall ) ) return;

		var row        = btn.closest( 'tr' );
		var slug       = btn.dataset.slug;
		var pluginFile = btn.dataset.pluginFile;

		setRowStatus( row, i18n.uninstalling );

		restCall( 'POST', 'uninstall', { slug: slug, plugin_file: pluginFile } )
			.then( function ( data ) {
				if ( data.success ) {
					removeRow( row );
				} else {
					var msg = data.message || '?';
					if ( msg.toLowerCase().indexOf( 'critical error' ) !== -1 ) {
						msg = i18n.uninstallCriticalError;
					}
					setRowStatus( row, i18n.error.replace( '%s', msg ) );
				}
			} )
			.catch( function ( e ) {
				setRowStatus( row, i18n.error.replace( '%s', e.message ) );
			} );
	}

	// ── remove from list ──────────────────────────────────────────────────────

	function doRemove( btn ) {
		if ( ! confirm( i18n.confirmRemove ) ) return;

		var row  = btn.closest( 'tr' );
		var slug = btn.dataset.slug;

		setRowStatus( row, i18n.removing );

		restCall( 'POST', 'remove', { slug: slug } )
			.then( function ( data ) {
				if ( data.success ) {
					removeRow( row );
				} else {
					setRowStatus( row, i18n.error.replace( '%s', data.message || '?' ) );
				}
			} )
			.catch( function ( e ) {
				setRowStatus( row, i18n.error.replace( '%s', e.message ) );
			} );
	}

	// ── bulk actions ──────────────────────────────────────────────────────────

	function doBulk() {
		var action    = document.getElementById( 'wfx-bulk-select' ).value;
		var checked   = document.querySelectorAll( 'input[name="plugins[]"]:checked' );
		var slugs     = Array.from( checked ).map( function ( cb ) { return cb.value; } );

		if ( ! action || slugs.length === 0 ) {
			alert( 'Select an action and at least one plugin.' );
			return;
		}

		var msg = i18n.confirmBulk.replace( '%d', slugs.length );
		if ( action === 'uninstall' ) msg = i18n.confirmUninstall + '\n\n' + slugs.join( '\n' );
		if ( action === 'remove' )    msg = i18n.confirmRemove + '\n\n' + slugs.join( '\n' );
		if ( ! confirm( msg ) ) return;

		var promises = slugs.map( function ( slug ) {
			var row        = document.querySelector( 'tr[data-slug="' + slug + '"]' );
			var pluginFile = row ? row.dataset.pluginFile : '';

			if ( action === 'deactivate' ) {
				if ( row ) setRowStatus( row, i18n.deactivating );
				return restCall( 'POST', 'deactivate', { plugin_file: pluginFile } );
			}
			if ( action === 'uninstall' ) {
				if ( row ) setRowStatus( row, i18n.uninstalling );
				return restCall( 'POST', 'uninstall', { slug: slug, plugin_file: pluginFile } )
					.then( function ( data ) {
						if ( data.success && row ) removeRow( row );
						return data;
					} );
			}
			if ( action === 'remove' ) {
				if ( row ) setRowStatus( row, i18n.removing );
				return restCall( 'POST', 'remove', { slug: slug } )
					.then( function ( data ) {
						if ( data.success && row ) removeRow( row );
						return data;
					} );
			}
		} );

		Promise.all( promises ).then( function () {
			if ( action === 'deactivate' ) location.reload();
		} );
	}

	// ── select all ────────────────────────────────────────────────────────────

	function bindSelectAll() {
		var selectAll = document.getElementById( 'wfx-select-all' );
		if ( ! selectAll ) return;
		selectAll.addEventListener( 'change', function () {
			document.querySelectorAll( 'input[name="plugins[]"]' ).forEach( function ( cb ) {
				cb.checked = selectAll.checked;
			} );
		} );
	}

	// ── init ──────────────────────────────────────────────────────────────────

	document.addEventListener( 'DOMContentLoaded', function () {
		// Deactivate buttons
		document.querySelectorAll( '.wfe-btn-deactivate' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () { doDeactivate( btn ); } );
		} );

		// Uninstall buttons
		document.querySelectorAll( '.wfe-btn-uninstall' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () { doUninstall( btn ); } );
		} );

		// Bulk apply
		var bulkBtn = document.getElementById( 'wfx-bulk-apply' );
		if ( bulkBtn ) bulkBtn.addEventListener( 'click', doBulk );

		bindSelectAll();

		// Toggle "days" row when auto-delete checkbox changes
		var autoDeleteChk = document.getElementById( 'wfe_auto_delete_unused' );
		var daysRow        = document.getElementById( 'wfe_auto_delete_days_row' );
		if ( autoDeleteChk && daysRow ) {
			autoDeleteChk.addEventListener( 'change', function () {
				daysRow.style.display = autoDeleteChk.checked ? '' : 'none';
			} );
		}
	} );
} )();
