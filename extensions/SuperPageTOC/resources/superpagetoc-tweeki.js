( function () {
	'use strict';

	var NAVBAR_HEIGHT = 90; // px from viewport top — below the fixed navbar + breathing room

	function initTOCPin( container ) {
		// Walk up to the nearest sidebar column (#sidebar-left / #sidebar-right)
		var sidebar = container.closest( '[id^="sidebar"]' );
		if ( !sidebar ) {
			return;
		}

		function unpin() {
			container.style.position = '';
			container.style.top = '';
			container.style.left = '';
			container.style.width = '';
			container.style.maxHeight = '';
			container.style.overflowY = '';
			container.style.background = '';
		}

		function update() {
			// Bail out on narrow / mobile widths — CSS handles that layout
			if ( window.matchMedia( '(max-width: 767px)' ).matches || window.innerWidth < 768 ) {
				unpin();
				return;
			}

			var sidebarWidth = sidebar.offsetWidth;

			// If sidebar is hidden (display:none → offsetWidth===0), don't pin with bogus dimensions
			if ( sidebarWidth === 0 ) {
				unpin();
				return;
			}

			var rect = sidebar.getBoundingClientRect();

			if ( rect.top < NAVBAR_HEIGHT ) {
				// Sidebar has scrolled above the threshold → pin the TOC
				container.style.position = 'fixed';
				container.style.top = NAVBAR_HEIGHT + 'px';
				container.style.left = rect.left + 'px';
				container.style.width = sidebarWidth + 'px';
				container.style.maxHeight = ( window.innerHeight - NAVBAR_HEIGHT - 10 ) + 'px';
				container.style.overflowY = 'auto';
				container.style.background = '#fff';
			} else {
				unpin();
			}
		}

		window.addEventListener( 'scroll', update, { passive: true } );
		window.addEventListener( 'resize', update, { passive: true } );
		update(); // evaluate immediately on load
	}

	function waitForTOC() {
		var container = document.getElementById( 'tweekiTOC' );
		if ( !container ) {
			return; // no sidebar TOC element at all
		}

		// Tweeki's JS moves #toc into #tweekiTOC inside its own $(document).ready.
		// If it already ran (fast load), wire up immediately; otherwise observe.
		if ( container.querySelector( '#toc' ) ) {
			initTOCPin( container );
			return;
		}

		var observer = new MutationObserver( function () {
			if ( container.querySelector( '#toc' ) ) {
				observer.disconnect();
				initTOCPin( container );
			}
		} );
		observer.observe( container, { childList: true, subtree: true } );

		// Safety net: stop watching after 3 s on pages where Tweeki never inserts #toc
		setTimeout( function () { observer.disconnect(); }, 3000 );
	}

	$( waitForTOC );
}() );
