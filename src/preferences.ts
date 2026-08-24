/**
 * User preferences, persisted per-browser in localStorage.
 *
 * These are intentionally lightweight (no server round-trip): they only control
 * which Save As affordances are shown and whether the dialog is skipped.
 */

export const PREF_KEYS = {
	skipModal: 'clonePostUnsavedChangesSkipModal',
	hideToolbar: 'clonePostUnsavedChangesHideToolbar',
	hideSidebar: 'clonePostUnsavedChangesHideSidebar',
} as const;

export type PrefKey = ( typeof PREF_KEYS )[ keyof typeof PREF_KEYS ];

export const getPref = ( key: PrefKey, defaultValue = false ): boolean => {
	try {
		const stored = window.localStorage.getItem( key );
		// Fall back to the default only when the user hasn't chosen yet;
		// an explicit '0'/'1' always wins.
		return stored === null ? defaultValue : stored === '1';
	} catch ( e ) {
		return defaultValue;
	}
};

export const setPref = ( key: PrefKey, value: boolean ): void => {
	try {
		window.localStorage.setItem( key, value ? '1' : '0' );
	} catch ( e ) {
		// Ignore (private mode / storage disabled) — just don't persist.
	}
};
