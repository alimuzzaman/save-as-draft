/**
 * The Save As dialog: title for the copy, plus the "don't ask" and
 * button-visibility preferences.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Button,
	Modal,
	TextControl,
	CheckboxControl,
	Notice,
	Flex,
} from '@wordpress/components';
import { getPref, setPref, PREF_KEYS } from '../preferences';
import { createDraftCopy, redirectToEditor, errorMessage } from '../api/draft';
import { buildDraftRequest } from '../api/payload';
import type { DraftRequest } from '../types';

export interface SaveAsModalProps {
	defaultTitle: string;
	onClose: () => void;
	hideToolbar: boolean;
	hideSidebar: boolean;
	onToggleToolbar: ( hide: boolean ) => void;
	onToggleSidebar: ( hide: boolean ) => void;
}

export const SaveAsModal = ( {
	defaultTitle,
	onClose,
	hideToolbar,
	hideSidebar,
	onToggleToolbar,
	onToggleSidebar,
}: SaveAsModalProps ) => {
	const [ title, setTitle ] = useState( defaultTitle );
	const [ dontAsk, setDontAsk ] = useState( getPref( PREF_KEYS.skipModal ) );
	const [ busy, setBusy ] = useState( false );
	const [ isComplete, setIsComplete ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ pendingRequest, setPendingRequest ] =
		useState< DraftRequest | null >( null );

	const submit = async () => {
		setBusy( true );
		setError( '' );
		setPref( PREF_KEYS.skipModal, dontAsk );
		try {
			const copyTitle = title.trim();
			// Reuse the complete first payload when the user retries the same
			// title. The server's request ID then makes a lost response safe to
			// retry without creating a second draft.
			const request =
				pendingRequest?.copy_title === copyTitle
					? pendingRequest
					: buildDraftRequest( copyTitle );
			setPendingRequest( request );
			const newPost = await createDraftCopy( request );
			setIsComplete( true );
			window.setTimeout(
				() => redirectToEditor( newPost.edit_url ),
				350
			);
		} catch ( err ) {
			// Keep the modal open so the user can retry / adjust the title.
			setError( errorMessage( err ) );
			setBusy( false );
		}
	};

	let submitLabel = __( 'Create draft', 'clone-post-unsaved-changes' );
	if ( busy ) {
		submitLabel = __( 'Creating…', 'clone-post-unsaved-changes' );
	}
	if ( isComplete ) {
		submitLabel = __( 'Opening…', 'clone-post-unsaved-changes' );
	}

	return (
		<Modal
			title={ __( 'Save As', 'clone-post-unsaved-changes' ) }
			onRequestClose={ busy ? () => undefined : onClose }
		>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ sprintf(
						// translators: %s: the error message returned by the server.
						__(
							'Could not create the copy: %s',
							'clone-post-unsaved-changes'
						),
						error
					) }
				</Notice>
			) }
			{ isComplete && (
				<Notice status="success" isDismissible={ false }>
					{ __(
						'Draft created. Opening it now…',
						'clone-post-unsaved-changes'
					) }
				</Notice>
			) }
			<TextControl
				label={ __(
					'Title for the copy',
					'clone-post-unsaved-changes'
				) }
				value={ title }
				onChange={ setTitle }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			<div style={ { marginTop: '16px' } }>
				<CheckboxControl
					label={ __(
						"Don't ask next time",
						'clone-post-unsaved-changes'
					) }
					help={ __(
						'You can reopen this dialog by Ctrl/⌘-clicking the “Save As” button.',
						'clone-post-unsaved-changes'
					) }
					checked={ dontAsk }
					onChange={ setDontAsk }
					__nextHasNoMarginBottom
				/>
			</div>
			<div style={ { marginTop: '16px' } }>
				<CheckboxControl
					label={ __(
						'Hide the toolbar button',
						'clone-post-unsaved-changes'
					) }
					checked={ hideToolbar }
					onChange={ onToggleToolbar }
					__nextHasNoMarginBottom
				/>
			</div>
			<div style={ { marginTop: '12px' } }>
				<CheckboxControl
					label={ __(
						'Hide the sidebar button',
						'clone-post-unsaved-changes'
					) }
					help={ __(
						'The “Save As” item in the ⋮ menu always stays available.',
						'clone-post-unsaved-changes'
					) }
					checked={ hideSidebar }
					onChange={ onToggleSidebar }
					__nextHasNoMarginBottom
				/>
			</div>
			<Flex justify="flex-end" gap={ 3 } style={ { marginTop: '24px' } }>
				<Button
					variant="tertiary"
					onClick={ onClose }
					disabled={ busy }
				>
					{ __( 'Cancel', 'clone-post-unsaved-changes' ) }
				</Button>
				<Button
					variant="primary"
					onClick={ submit }
					isBusy={ busy }
					disabled={ busy || isComplete || ! title.trim() }
					__next40pxDefaultSize
				>
					{ submitLabel }
				</Button>
			</Flex>
		</Modal>
	);
};
