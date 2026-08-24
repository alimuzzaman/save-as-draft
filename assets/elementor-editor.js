( function () {
	'use strict';

	const config = window.clonePostUnsavedChangesElementor;
	if ( ! config || ! config.endpoint || ! config.nonce ) {
		return;
	}

	const createId = () => {
		if ( window.crypto && window.crypto.randomUUID ) {
			return window.crypto.randomUUID();
		}

		const hex = () =>
			Math.floor( Math.random() * 0x100000000 )
				.toString( 16 )
				.padStart( 8, '0' );
		return `${ hex() }-${ hex().slice( 0, 4 ) }-4${ hex().slice(
			0,
			3
		) }-${ ( 8 + Math.floor( Math.random() * 4 ) ).toString(
			16
		) }${ hex().slice( 0, 3 ) }-${ hex() }${ hex().slice( 0, 4 ) }`;
	};

	const sourceId = () => {
		const configured = window.elementor?.config?.document?.id;
		if ( configured ) {
			return Number( configured );
		}

		return Number(
			new URLSearchParams( window.location.search ).get( 'post' )
		);
	};

	const sourceTitle = () => {
		const title = window.elementor?.config?.document?.title;
		return (
			title ||
			document.title.replace( /\s*[-|].*$/, '' ) ||
			config.untitledText ||
			'Untitled'
		);
	};

	const text = ( key, fallback ) => config[ key ] || fallback;
	let pendingRequest = null;

	const captureDocument = () => {
		const current = window.elementor?.documents?.getCurrent?.();
		const container = current?.container;
		const settings = container?.settings;
		const elements = container?.model?.get?.( 'elements' );
		if (
			! settings ||
			'function' !== typeof settings.toJSON ||
			! elements ||
			'function' !== typeof elements.toJSON
		) {
			throw new Error(
				text(
					'snapshotErrorText',
					'This Elementor document cannot be copied safely.'
				)
			);
		}

		const snapshotSettings = settings.toJSON( { remove: [ 'default' ] } );
		const snapshotElements = elements.toJSON( {
			remove: [ 'default', 'editSettings', 'defaultEditSettings' ],
		} );
		if (
			! snapshotSettings ||
			'object' !== typeof snapshotSettings ||
			Array.isArray( snapshotSettings ) ||
			! Array.isArray( snapshotElements )
		) {
			throw new Error(
				text(
					'snapshotErrorText',
					'This Elementor document cannot be copied safely.'
				)
			);
		}

		// Elementor's own 4.2.3 Save command adds persistent document defaults
		// that are not present in the model JSON. Mirror that step before the
		// snapshot is sent, otherwise page-level settings can disappear from the
		// new draft even though the editor showed them as configured.
		const persistentKeys = window.elementor?.config?.persistent_keys;
		const defaults = settings.defaults;
		if ( Array.isArray( persistentKeys ) && defaults ) {
			for ( const key of persistentKeys ) {
				if (
					Object.prototype.hasOwnProperty.call( defaults, key ) &&
					! Object.prototype.hasOwnProperty.call(
						snapshotSettings,
						key
					)
				) {
					snapshotSettings[ key ] = defaults[ key ];
				}
			}
		}

		for ( const key of [
			'ID',
			'post_id',
			'post_status',
			'post_author',
			'post_title',
			'post_name',
			'post_date',
			'post_modified',
			'guid',
			'post_content',
			'post_excerpt',
		] ) {
			delete snapshotSettings[ key ];
		}

		try {
			return JSON.parse(
				JSON.stringify( {
					elements: snapshotElements,
					settings: snapshotSettings,
				} )
			);
		} catch ( error ) {
			throw new Error(
				text(
					'snapshotErrorText',
					'This Elementor document cannot be copied safely.'
				)
			);
		}
	};

	const frozenDocument = async () => {
		let lastError;
		for ( let attempt = 0; attempt < 100; attempt++ ) {
			try {
				return captureDocument();
			} catch ( caught ) {
				lastError = caught;
				await new Promise( ( resolve ) =>
					window.setTimeout( resolve, 100 )
				);
			}
		}
		throw lastError instanceof Error
			? lastError
			: new Error(
					text(
						'snapshotErrorText',
						'This Elementor document cannot be copied safely.'
					)
			  );
	};

	const showError = ( message ) => {
		const error = modal.querySelector( '[data-cpuc-error]' );
		error.textContent = message || config.errorText;
		error.hidden = false;
	};

	const modal = document.createElement( 'dialog' );
	modal.className = 'cpuc-elementor-modal';
	const form = document.createElement( 'form' );
	form.method = 'dialog';
	const heading = document.createElement( 'h2' );
	heading.textContent = text( 'dialogTitle', 'Save as draft' );
	const titleLabel = document.createElement( 'label' );
	titleLabel.textContent = text( 'draftTitleLabel', 'Draft title' );
	const titleInput = document.createElement( 'input' );
	titleInput.dataset.cpucTitle = '';
	titleInput.required = true;
	titleInput.type = 'text';
	titleLabel.appendChild( titleInput );
	const error = document.createElement( 'p' );
	error.dataset.cpucError = '';
	error.hidden = true;
	error.setAttribute( 'role', 'alert' );
	const menu = document.createElement( 'menu' );
	const cancel = document.createElement( 'button' );
	cancel.type = 'button';
	cancel.value = 'cancel';
	cancel.textContent = text( 'cancelText', 'Cancel' );
	const confirm = document.createElement( 'button' );
	confirm.type = 'submit';
	confirm.dataset.cpucConfirm = '';
	confirm.value = 'default';
	confirm.textContent = config.copyText || 'Save As';
	menu.append( cancel, confirm );
	form.append( heading, titleLabel, error, menu );
	modal.appendChild( form );
	document.body.appendChild( modal );
	cancel.addEventListener( 'click', () => {
		pendingRequest = null;
		modal.close( 'cancel' );
	} );

	const button = document.createElement( 'button' );
	button.className = 'cpuc-elementor-save-as';
	button.type = 'button';
	button.textContent = config.copyText;
	button.addEventListener( 'click', () => {
		pendingRequest = null;
		titleInput.value = `${ sourceTitle() }${ text(
			'copySuffix',
			' (Copy)'
		) }`;
		modal.querySelector( '[data-cpuc-error]' ).hidden = true;
		modal.showModal();
		titleInput.focus();
	} );
	document.body.appendChild( button );

	modal
		.querySelector( 'form' )
		.addEventListener( 'submit', async ( event ) => {
			if (
				event.submitter &&
				! event.submitter.matches( '[data-cpuc-confirm]' )
			) {
				return;
			}

			event.preventDefault();
			const title = titleInput.value.trim();
			const postId = sourceId();
			if ( ! title || ! Number.isInteger( postId ) || postId < 1 ) {
				showError( config.errorText );
				return;
			}
			confirm.disabled = true;
			try {
				if (
					! pendingRequest ||
					pendingRequest.source_id !== postId ||
					pendingRequest.copy_title !== title
				) {
					const elementor = await frozenDocument();
					pendingRequest = {
						source_id: postId,
						request_id: createId(),
						copy_title: title,
						editor: 'elementor',
						edited: { elementor },
					};
				}
				const response = await window.fetch( config.endpoint, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': config.nonce,
					},
					body: JSON.stringify( pendingRequest ),
				} );
				const result = await response.json();
				if ( ! response.ok || ! result.edit_url ) {
					throw new Error( result.message || config.errorText );
				}
				pendingRequest = null;
				window.location.assign( result.edit_url );
			} catch ( caught ) {
				showError(
					caught instanceof Error ? caught.message : config.errorText
				);
				confirm.disabled = false;
			}
		} );
} )();
