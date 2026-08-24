/**
 * Build the constrained block-editor overlay accepted by the clone endpoint.
 */
import { __ } from '@wordpress/i18n';
import { select } from '@wordpress/data';
import type {
	DraftRequest,
	EditedPostOverlay,
	TaxonomyOverlay,
} from '../types';

type EditorStore = {
	getCurrentPostId: () => number;
	getEditedPostAttribute: ( attribute: string ) => unknown;
	getEditedPostContent: () => string;
	getPostEdits: () => Record< string, unknown >;
};

type CoreStore = {
	getTaxonomies?: ( query?: Record< string, string > ) => Array< {
		rest_base?: string;
		name: string;
	} >;
};

const supportedFields = new Set( [
	'title',
	'content',
	'excerpt',
	'featured_media',
	'comment_status',
	'ping_status',
	'format',
	'sticky',
	'template',
	'parent',
	'menu_order',
	'meta',
] );

// WordPress 7.1's iframe editor includes these transient editor-state keys in
// getPostEdits() while a block is being edited. They are not REST post
// attributes; content is read through getEditedPostContent() below instead.
const internalEditorFields = new Set( [ 'selection', 'blocks' ] );

const requestId = (): string => {
	if ( typeof crypto !== 'undefined' && crypto.randomUUID ) {
		return crypto.randomUUID();
	}

	// This branch is only for older browser engines. It preserves the UUID shape
	// required by the server while modern WordPress browsers use randomUUID().
	const hex = (): string =>
		Math.floor( Math.random() * 0x100000000 )
			.toString( 16 )
			.padStart( 8, '0' );
	return `${ hex() }-${ hex().slice( 0, 4 ) }-4${ hex().slice( 0, 3 ) }-${ (
		8 + Math.floor( Math.random() * 4 )
	).toString( 16 ) }${ hex().slice( 0, 3 ) }-${ hex() }${ hex().slice(
		0,
		4
	) }`;
};

/**
 * Throws before making a request when another dirty field would be lost.
 *
 * @param copyTitle Title selected for the new draft.
 */
export const buildDraftRequest = ( copyTitle: string ): DraftRequest => {
	const editor = select( 'core/editor' ) as unknown as EditorStore;
	const postType = String(
		editor.getEditedPostAttribute( 'type' ) || 'post'
	);
	const core = select( 'core' ) as unknown as CoreStore;
	const taxonomies = core.getTaxonomies?.( { type: postType } ) || [];
	const postEdits = editor.getPostEdits() || {};
	const taxonomyBases = new Set(
		taxonomies.map( ( taxonomy ) => taxonomy.rest_base || taxonomy.name )
	);

	for ( const field of Object.keys( postEdits ) ) {
		if (
			! supportedFields.has( field ) &&
			! taxonomyBases.has( field ) &&
			! internalEditorFields.has( field )
		) {
			throw new Error(
				__(
					'This post has unsaved fields that Save As cannot safely copy.',
					'clone-post-unsaved-changes'
				)
			);
		}
	}

	const taxonomyOverlay: TaxonomyOverlay = {};
	for ( const taxonomy of taxonomies ) {
		const restBase = taxonomy.rest_base || taxonomy.name;
		const value = editor.getEditedPostAttribute( restBase );
		if ( Array.isArray( value ) ) {
			taxonomyOverlay[ restBase ] = value;
		}
	}

	const overlay: EditedPostOverlay = {
		title: copyTitle,
		content: editor.getEditedPostContent(),
		excerpt: editor.getEditedPostAttribute( 'excerpt' ),
		featured_media: editor.getEditedPostAttribute( 'featured_media' ),
		comment_status: editor.getEditedPostAttribute( 'comment_status' ),
		ping_status: editor.getEditedPostAttribute( 'ping_status' ),
		format: editor.getEditedPostAttribute( 'format' ),
		sticky: editor.getEditedPostAttribute( 'sticky' ),
		template: editor.getEditedPostAttribute( 'template' ),
		parent: editor.getEditedPostAttribute( 'parent' ),
		menu_order: editor.getEditedPostAttribute( 'menu_order' ),
		taxonomies: taxonomyOverlay,
	};
	const meta = editor.getEditedPostAttribute( 'meta' );
	if (
		Object.prototype.hasOwnProperty.call( postEdits, 'meta' ) &&
		meta &&
		typeof meta === 'object' &&
		! Array.isArray( meta )
	) {
		overlay.meta = meta as Record< string, unknown >;
	}

	return {
		source_id: editor.getCurrentPostId(),
		request_id: requestId(),
		copy_title: copyTitle,
		editor: 'block',
		edited: overlay,
	};
};
