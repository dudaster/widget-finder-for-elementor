/**
 * WFE Plugin Settings menu collapse/expand toggle.
 *
 * Finds the "Plugin Settings" top-level item and all WFE-grouped plugin items
 * in #adminmenu, then wires up a click-to-toggle.  State persists in
 * localStorage so the sidebar remembers its position across pages.
 *
 * The grouped items are never removed from the DOM — they're hidden with a
 * CSS class — so WordPress's own active-menu highlight still works normally.
 */
( function () {
	'use strict';

	const { toggleSlug, groupSlugs, i18n } = wfeMenuCollapse;
	const LS_KEY = 'wfe_plugin_menu_collapsed';

	document.addEventListener( 'DOMContentLoaded', init );

	function init() {
		const adminMenu = document.getElementById( 'adminmenu' );
		if ( ! adminMenu ) return;

		// ── Locate the toggle <li> ──────────────────────────────────────────
		const toggleItem = findBySlug( adminMenu, toggleSlug );
		if ( ! toggleItem ) return;

		// ── Locate grouped plugin <li> items ───────────────────────────────
		const groupItems = groupSlugs
			.map( ( slug ) => findBySlug( adminMenu, slug ) )
			.filter( Boolean );

		if ( ! groupItems.length ) return;

		// ── Mark items so CSS can target them ──────────────────────────────
		toggleItem.classList.add( 'wfx-plugin-settings-toggle' );
		groupItems.forEach( ( li ) => li.classList.add( 'wfx-grouped-plugin' ) );

		// ── Arrow indicator ────────────────────────────────────────────────
		// Appended to the <li>, not the <a>, so we never disturb the link's
		// internal layout (wp-menu-image + wp-menu-name blocks).
		const toggleLink = toggleItem.querySelector( 'a' );
		const arrow      = document.createElement( 'span' );
		arrow.className  = 'wfx-collapse-arrow';
		arrow.setAttribute( 'aria-hidden', 'true' );
		toggleItem.appendChild( arrow );

		// ── If any grouped item is active, always start expanded ───────────
		const hasActive = groupItems.some( ( li ) =>
			li.classList.contains( 'current' ) ||
			li.classList.contains( 'wp-has-current-submenu' ) ||
			li.classList.contains( 'wp-menu-open' )
		);

		const stored     = ( function () { try { return localStorage.getItem( LS_KEY ); } catch ( _ ) { return null; } } )();
		const collapsed  = hasActive ? false : ( stored === '1' );

		applyState( collapsed, groupItems, toggleItem, arrow );

		// ── Click handler ──────────────────────────────────────────────────
		if ( toggleLink ) {
			toggleLink.addEventListener( 'click', ( e ) => {
				e.preventDefault();
				const nowCollapsed = ! toggleItem.classList.contains( 'wfx-group-collapsed' );
				applyState( nowCollapsed, groupItems, toggleItem, arrow );
				try { localStorage.setItem( LS_KEY, nowCollapsed ? '1' : '0' ); } catch ( _ ) {}
			} );
		}
	}

	/** Show or hide the group and update the toggle's visual state. */
	function applyState( collapsed, groupItems, toggleItem, arrow ) {
		groupItems.forEach( ( li ) => li.classList.toggle( 'wfx-grouped-hidden', collapsed ) );
		toggleItem.classList.toggle( 'wfx-group-collapsed', collapsed );
		if ( arrow ) arrow.title = collapsed ? i18n.expand : i18n.collapse;
	}

	/**
	 * Find an admin-menu <li> whose first <a> corresponds to the given slug.
	 *
	 * Two slug formats exist:
	 *  • Page-based  — "my-plugin"  → href ends with ?page=my-plugin
	 *  • URL-based   — "edit.php?post_type=wpzoom-shortcode"
	 *                  → href path + params must match (CPT menus, etc.)
	 */
	function findBySlug( adminMenu, slug ) {
		const isUrlSlug = slug.includes( '?' ) || slug.includes( '.php' );

		for ( const a of adminMenu.querySelectorAll( 'a' ) ) {
			try {
				const url = new URL( a.href, location.origin );

				if ( ! isUrlSlug ) {
					// Standard ?page= menu.
					if ( url.searchParams.get( 'page' ) === slug ) return a.closest( 'li' );
				} else {
					// URL-based slug: match path + every query param in the slug.
					const slugUrl = new URL( slug, location.origin + '/wp-admin/' );
					if ( url.pathname !== slugUrl.pathname ) continue;
					let match = true;
					slugUrl.searchParams.forEach( ( v, k ) => {
						if ( url.searchParams.get( k ) !== v ) match = false;
					} );
					if ( match ) return a.closest( 'li' );
				}
			} catch ( _ ) {}
		}
		return null;
	}
} )();
