/**
 * REST client for the server-owned draft clone endpoint.
 */
import { __, sprintf } from '@wordpress/i18n';
import { dispatch, select } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { buildDraftRequest } from './payload';
import type { DraftRequest, DraftResponse } from '../types';

let pendingQuickCopyRequest: DraftRequest | null = null;

// Creates a draft through the plugin endpoint. The request UUID means a caller
// can safely retry the identical payload after a transport failure.
export const createDraftCopy = async (
	request: string | DraftRequest
): Promise< DraftResponse > => {
	const payload =
		typeof request === 'string' ? buildDraftRequest( request ) : request;
	const newPost = await apiFetch< DraftResponse >( {
		path: '/clone-post-unsaved-changes/v1/drafts',
		method: 'POST',
		data: payload,
	} );

	if ( ! newPost || ! newPost.id || ! newPost.edit_url ) {
		throw new Error(
			__(
				'No draft edit link was returned by the server.',
				'clone-post-unsaved-changes'
			)
		);
	}
	return newPost;
};

export const redirectToEditor = ( editUrl: string ): void => {
	window.location.href = editUrl;
};

// Abandon a failed skip-modal request when the user reopens the dialog.
export const resetQuickCopyRequest = (): void => {
	pendingQuickCopyRequest = null;
};

export const errorMessage = ( err: unknown ): string => {
	if ( err && typeof err === 'object' && 'message' in err ) {
		const { message } = err as { message?: unknown };
		if ( typeof message === 'string' && message ) {
			return message;
		}
	}
	return __( 'Unknown error', 'clone-post-unsaved-changes' );
};

// Copy straight away (used when the user opted out of the dialog), surfacing any
// endpoint or preflight failure as an editor snackbar notice. Keep the complete
// request after a failure so clicking Save As again cannot create a duplicate if
// the first response was lost after the server committed the draft.
export const quickCopy = ( title: string ): Promise< void > => {
	let request: DraftRequest;
	const currentPostId = Number(
		(
			select( 'core/editor' ) as unknown as {
				getCurrentPostId?: () => number;
			}
		 ).getCurrentPostId?.()
	);
	if (
		pendingQuickCopyRequest &&
		pendingQuickCopyRequest.copy_title === title &&
		pendingQuickCopyRequest.source_id === currentPostId
	) {
		request = pendingQuickCopyRequest;
	} else {
		try {
			request = buildDraftRequest( title );
			pendingQuickCopyRequest = request;
		} catch ( err ) {
			pendingQuickCopyRequest = null;
			( dispatch( 'core/notices' ) as any ).createErrorNotice(
				sprintf(
					// translators: %s: the error message returned by the server.
					__( 'Save As failed: %s', 'clone-post-unsaved-changes' ),
					errorMessage( err )
				),
				{ type: 'snackbar' }
			);
			return Promise.resolve();
		}
	}

	return createDraftCopy( request )
		.then( ( newPost ) => {
			pendingQuickCopyRequest = null;
			( dispatch( 'core/notices' ) as any ).createSuccessNotice(
				__(
					'Draft created. Opening it now…',
					'clone-post-unsaved-changes'
				),
				{ type: 'snackbar' }
			);
			window.setTimeout(
				() => redirectToEditor( newPost.edit_url ),
				350
			);
		} )
		.catch( ( err ) => {
			( dispatch( 'core/notices' ) as any ).createErrorNotice(
				sprintf(
					// translators: %s: the error message returned by the server.
					__( 'Save As failed: %s', 'clone-post-unsaved-changes' ),
					errorMessage( err )
				),
				{ type: 'snackbar' }
			);
		} );
};
