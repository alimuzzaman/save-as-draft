/**
 * Injects a container element into the editor header toolbar and returns it so a
 * React portal can render into it — the way the original prototype injected a
 * button, but state-aware. Returns null until the header has mounted (or while
 * disabled).
 */
import { useState, useEffect } from '@wordpress/element';

export const useToolbarSlot = ( enabled: boolean ): HTMLElement | null => {
	const [ slot, setSlot ] = useState< HTMLElement | null >( null );

	useEffect( () => {
		if ( ! enabled ) {
			return undefined;
		}

		let node: HTMLElement | undefined;
		let cancelled = false;

		const attach = (): boolean => {
			const settings = document.querySelector(
				'.editor-header__settings'
			);
			if ( ! settings || cancelled ) {
				return false;
			}
			node = document.createElement( 'div' );
			node.className = 'clone-post-unsaved-changes__toolbar-slot';
			node.style.display = 'flex';
			node.style.alignItems = 'center';
			settings.prepend( node ); // sit before the native Save/Publish buttons
			setSlot( node );
			return true;
		};

		// The header mounts asynchronously, so poll briefly until it appears.
		let intervalId: ReturnType< typeof setInterval > | undefined;
		if ( ! attach() ) {
			intervalId = setInterval( () => {
				if ( attach() ) {
					clearInterval( intervalId );
				}
			}, 300 );
		}

		return () => {
			cancelled = true;
			if ( intervalId ) {
				clearInterval( intervalId );
			}
			node?.remove();
			setSlot( null );
		};
	}, [ enabled ] );

	return slot;
};
