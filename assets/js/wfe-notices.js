/**
 * WFE Notification Manager
 *
 * Intercepts WordPress admin notices (both static and dynamically injected),
 * hides them from the page, and surfaces them via a bell icon near Screen Options / Help.
 */
(function () {
	'use strict';

	// Don't run inside the Elementor editor iframe.
	if ( /[?&]action=elementor(&|$)/.test( location.search ) ) return;

	const NOTICE_SEL = '.notice, .updated, .error';

	// Persist acknowledged count in localStorage so the badge stays hidden
	// across page navigations once the user has opened the bell.
	// The badge only re-appears if more notices arrive than were last acknowledged.
	const ACK_KEY = 'wfe_notices_ack_count';

	// Shared UI references (created lazily on first notice).
	let uiReady = false;
	let listEl, titleEl, badgeEl, wrapEl, popupEl, btnEl;

	document.addEventListener( 'DOMContentLoaded', init );

	function init() {
		const wpbody = document.getElementById( 'wpbody-content' );
		if ( ! wpbody ) return;

		// ── Static notices present at DOMContentLoaded ───────────────────────
		// Query all matching elements anywhere inside wpbody, excluding anything
		// already inside our own popup (which doesn't exist yet, but guard anyway).
		const staticEls = Array.from( wpbody.querySelectorAll( NOTICE_SEL ) ).filter(
			( el ) => ! el.closest( '#wfe-notif-popup' )
		);
		captureAll( staticEls );

		// ── Watch for notices injected later via JavaScript ───────────────────
		// subtree: true catches notices added at any depth, not just direct children.
		new MutationObserver( ( mutations ) => {
			const added = [];
			for ( const m of mutations ) {
				for ( const node of m.addedNodes ) {
					if (
						node.nodeType === 1 &&
						node.matches( NOTICE_SEL ) &&
						! node.closest( '#wfe-notif-popup' )
					) {
						added.push( node );
					}
				}
			}
			if ( added.length ) captureAll( added );
		} ).observe( wpbody, { childList: true, subtree: true } );
	}

	/** Remove elements from the DOM and add them to the notification popup. */
	function captureAll( els ) {
		if ( ! els.length ) return;
		const htmls = els.map( ( el ) => el.outerHTML );
		els.forEach( ( el ) => el.remove() );
		ensureUI();
		htmls.forEach( addNotice );
	}

	// ── UI construction (created once, lazily) ───────────────────────────────

	function ensureUI() {
		if ( uiReady ) return;
		uiReady = true;

		const i18n = ( typeof wfeNotices !== 'undefined' ) ? wfeNotices.i18n : {
			title: 'Notifications',
			close: 'Close',
		};

		// Wrapper
		wrapEl    = document.createElement( 'div' );
		wrapEl.id = 'wfe-notif-wrap';

		// Bell button
		btnEl           = document.createElement( 'button' );
		btnEl.type      = 'button';
		btnEl.id        = 'wfe-notif-btn';
		btnEl.className = 'button';
		btnEl.title     = i18n.title;
		btnEl.setAttribute( 'aria-expanded', 'false' );

		badgeEl           = document.createElement( 'span' );
		badgeEl.className = 'wfe-notif-badge';
		badgeEl.textContent = '0';
		badgeEl.hidden = true; // shown later once count is known

		btnEl.innerHTML = `<svg viewBox="0 0 24 24" fill="currentColor" width="14" height="14" aria-hidden="true" focusable="false">
			<path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6V11c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/>
		</svg>`;
		btnEl.appendChild( badgeEl );

		// Popup
		popupEl    = document.createElement( 'div' );
		popupEl.id = 'wfe-notif-popup';
		popupEl.hidden = true;
		popupEl.setAttribute( 'role', 'dialog' );
		popupEl.setAttribute( 'aria-label', i18n.title );

		const header      = document.createElement( 'div' );
		header.className  = 'wfe-notif-header';

		titleEl           = document.createElement( 'span' );
		titleEl.className = 'wfe-notif-title';
		updateTitle( i18n.title, 0 );

		const closeBtn      = document.createElement( 'button' );
		closeBtn.type       = 'button';
		closeBtn.className  = 'wfe-notif-close';
		closeBtn.title      = i18n.close;
		closeBtn.innerHTML  = '&#x2715;';

		header.appendChild( titleEl );
		header.appendChild( closeBtn );

		listEl           = document.createElement( 'div' );
		listEl.className = 'wfe-notif-list';

		popupEl.appendChild( header );
		popupEl.appendChild( listEl );
		wrapEl.appendChild( btnEl );
		wrapEl.appendChild( popupEl );

		// ── Placement: append to #screen-meta-links so the bell is rightmost ─
		// (children of #screen-meta-links are float:left, so DOM-last = visual-right)
		const metaLinks = document.getElementById( 'screen-meta-links' );
		if ( metaLinks ) {
			metaLinks.appendChild( wrapEl );
		} else {
			// Fallback for custom plugin pages that have no Screen Options / Help.
			// Use fixed positioning so it aligns with where the toolbar ends.
			wrapEl.classList.add( 'wfe-notif-fallback' );
			document.body.appendChild( wrapEl );
		}

		// ── Events ─────────────────────────────────────────────────────────
		btnEl.addEventListener( 'click', ( e ) => {
			e.stopPropagation();
			const opening = popupEl.hidden;
			popupEl.hidden = ! popupEl.hidden;
			btnEl.setAttribute( 'aria-expanded', opening ? 'true' : 'false' );

			if ( opening ) {
				// Acknowledge current count — hide badge and persist so other
				// pages don't show the badge for the same or fewer notices.
				const count = listEl.querySelectorAll( '.wfe-notif-item' ).length;
				try { localStorage.setItem( ACK_KEY, String( count ) ); } catch ( _ ) {}
				badgeEl.hidden = true;
			}
		} );

		closeBtn.addEventListener( 'click', () => {
			popupEl.hidden = true;
			btnEl.setAttribute( 'aria-expanded', 'false' );
		} );

		document.addEventListener( 'click', ( e ) => {
			if ( ! wrapEl.contains( e.target ) && ! popupEl.hidden ) {
				popupEl.hidden = true;
				btnEl.setAttribute( 'aria-expanded', 'false' );
			}
		} );

		document.addEventListener( 'keydown', ( e ) => {
			if ( e.key === 'Escape' && ! popupEl.hidden ) {
				popupEl.hidden = true;
				btnEl.setAttribute( 'aria-expanded', 'false' );
				btnEl.focus();
			}
		} );
	}

	function addNotice( html ) {
		const i18n = ( typeof wfeNotices !== 'undefined' ) ? wfeNotices.i18n : { title: 'Notifications', close: 'Close' };

		const item      = document.createElement( 'div' );
		item.className  = 'wfe-notif-item';
		item.innerHTML  = html;

		// Strip is-dismissible so WP's own handler doesn't error on missing DOM node.
		const inner = item.querySelector( '.notice' );
		if ( inner ) {
			inner.classList.remove( 'is-dismissible' );
			inner.style.margin = '0';
		}

		const dismissBtn      = document.createElement( 'button' );
		dismissBtn.type       = 'button';
		dismissBtn.className  = 'wfe-notif-dismiss notice-dismiss';
		dismissBtn.title      = i18n.close;
		item.appendChild( dismissBtn );

		dismissBtn.addEventListener( 'click', ( e ) => {
			e.stopPropagation();
			item.remove();
			const remaining = listEl.querySelectorAll( '.wfe-notif-item' ).length;
			updateTitle( i18n.title, remaining );
			badgeEl.textContent = remaining;
			if ( remaining === 0 ) {
				// All dismissed — reset ack count so the badge can reappear if
				// new notices show up on future pages.
				try { localStorage.removeItem( ACK_KEY ); } catch ( _ ) {}
				popupEl.hidden = true;
				wrapEl.remove();
				uiReady = false;
			}
		} );

		listEl.appendChild( item );

		// Update badge: show only if current count exceeds last acknowledged count.
		const count   = listEl.querySelectorAll( '.wfe-notif-item' ).length;
		const ackStr  = ( function () { try { return localStorage.getItem( ACK_KEY ); } catch ( _ ) { return null; } } )();
		const ackCount = ackStr !== null ? parseInt( ackStr, 10 ) : -1;

		badgeEl.textContent = count;
		updateTitle( i18n.title, count );
		badgeEl.hidden = ( count <= ackCount );
	}

	function updateTitle( base, count ) {
		if ( titleEl ) titleEl.textContent = base + ' (' + count + ')';
	}

} )();
