/* eslint-disable import/no-unresolved -- Playwright is supplied by the disposable E2E runner. */
import fs from 'node:fs';
import path from 'node:path';
import { expect, test, type Page } from '@playwright/test';

const sourceId = process.env.CLONE_CONTRACT_SOURCE_ID;
const storageState = process.env.WP_STORAGE_STATE;
const sandboxDescriptorPath = path.resolve( '.wp-env-port' );
const sandboxDescriptor = fs.existsSync( sandboxDescriptorPath )
	? ( JSON.parse( fs.readFileSync( sandboxDescriptorPath, 'utf8' ) ) as {
			baseUrl?: string;
			loginUrl?: string;
	  } )
	: undefined;
const baseURL = process.env.WP_BASE_URL || sandboxDescriptor?.baseUrl;
const loginURL = process.env.WP_LOGIN_URL || sandboxDescriptor?.loginUrl;
let generatedSourceId: string | undefined;

const preferenceKeys = [
	'clonePostUnsavedChangesSkipModal',
	'clonePostUnsavedChangesHideToolbar',
	'clonePostUnsavedChangesHideSidebar',
];

const editorPath = ( id: string ) =>
	`/wp-admin/post.php?post=${ encodeURIComponent( id ) }&action=edit`;

async function openEditor( page: Page, id: string ) {
	await page.goto( editorPath( id ), { waitUntil: 'domcontentloaded' } );
	const welcomeGuide = page
		.getByRole( 'dialog' )
		.filter( { hasText: 'Welcome to the editor' } );
	if ( await welcomeGuide.count() ) {
		const closeButton = welcomeGuide.getByRole( 'button', {
			name: 'Close',
		} );
		if ( await closeButton.count() ) {
			await closeButton.click();
		}
	}
	await expect(
		page.getByRole( 'button', { name: 'Save As' } ).first()
	).toBeVisible();
}

async function authenticate( page: Page ) {
	if ( storageState ) {
		return;
	}
	if ( ! loginURL ) {
		test.skip(
			true,
			'Set WP_STORAGE_STATE or let Sandbox provide its disposable auto-login URL.'
		);
	}
	await page.goto( loginURL as string, { waitUntil: 'domcontentloaded' } );
	await page.goto( '/wp-admin/', { waitUntil: 'domcontentloaded' } );
	await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
}

async function ensureSourcePost( page: Page ) {
	if ( sourceId ) {
		return sourceId;
	}
	if ( generatedSourceId ) {
		return generatedSourceId;
	}

	await page.goto( '/wp-admin/post-new.php', {
		waitUntil: 'domcontentloaded',
	} );
	await expect
		.poll( () =>
			page.evaluate(
				() =>
					(
						window as Window & {
							wpApiSettings?: { nonce?: string };
						}
					 ).wpApiSettings?.nonce || ''
			)
		)
		.not.toBe( '' );
	const nonce = await page.evaluate(
		() =>
			( window as Window & { wpApiSettings?: { nonce?: string } } )
				.wpApiSettings?.nonce || ''
	);
	expect( nonce ).not.toBe( '' );
	const response = await page.request.post( '/wp-json/wp/v2/posts', {
		headers: { 'X-WP-Nonce': nonce },
		data: {
			status: 'publish',
			title: `Sandbox iframe source ${ Date.now() }`,
			content:
				'<!-- wp:paragraph --><p>Saved source content.</p><!-- /wp:paragraph -->',
			excerpt: 'Saved source excerpt.',
			comment_status: 'closed',
			ping_status: 'closed',
		},
	} );
	expect( response.ok(), await response.text() ).toBe( true );
	const post = ( await response.json() ) as { id?: number };
	expect( post.id ).toEqual( expect.any( Number ) );
	generatedSourceId = String( post.id );
	return generatedSourceId;
}

async function clearPreferences( page: Page ) {
	await page.addInitScript( ( keys ) => {
		for ( const key of keys ) {
			window.localStorage.removeItem( key );
		}
	}, preferenceKeys );
}

function endpointRequest( page: Page ) {
	return page.waitForRequest(
		( request ) =>
			request.method() === 'POST' &&
			/\/wp-json\/clone-post-unsaved-changes\/v1\/drafts(?:\?|$)/.test(
				request.url()
			),
		{ timeout: 15_000 }
	);
}

async function editorRestContext( page: Page ) {
	return page.evaluate( () => {
		const wpWindow = window as Window & {
			wp?: {
				data?: {
					select: ( store: string ) => {
						getCurrentPostType?: () => string;
						getPostType?: ( type: string ) => {
							rest_base?: string;
						};
					};
				};
			};
			wpApiSettings?: { nonce?: string };
		};
		const editor = wpWindow.wp?.data?.select( 'core/editor' );
		const core = wpWindow.wp?.data?.select( 'core' );
		const type = editor?.getCurrentPostType?.() || 'post';
		const postType = core?.getPostType?.( type );
		return {
			restBase: postType?.rest_base || `${ type }s`,
			nonce: wpWindow.wpApiSettings?.nonce || '',
		};
	} );
}

test.describe( 'Gutenberg Save As contract', () => {
	test.beforeEach( async ( { page } ) => {
		if ( ! baseURL ) {
			test.skip(
				true,
				"Set WP_BASE_URL or run through Sandbox's disposable E2E runner."
			);
		}
		await clearPreferences( page );
		await authenticate( page );
		await page.goto( '/wp-admin/edit.php?post_type=post', {
			waitUntil: 'domcontentloaded',
		} );
	} );

	test( 'toolbar opens the modal, sends the frozen block payload, and follows edit_url redirect', async ( {
		page,
	} ) => {
		const testSourceId = await ensureSourcePost( page );
		await openEditor( page, testSourceId );

		const toolbarButton = page
			.getByRole( 'button', { name: 'Save As' } )
			.first();
		await toolbarButton.click();

		const dialog = page.getByRole( 'dialog', { name: 'Save As' } );
		await expect( dialog ).toBeVisible();
		const title = `Browser contract copy ${ Date.now() }`;
		await dialog.getByLabel( 'Title for the copy' ).fill( title );

		const requestPromise = endpointRequest( page );
		await dialog.getByRole( 'button', { name: 'Create draft' } ).click();
		const request = await requestPromise;
		const payload = request.postDataJSON() as Record< string, unknown >;

		expect( payload ).toMatchObject( {
			source_id: Number( testSourceId ),
			editor: 'block',
			copy_title: title,
		} );
		expect( payload.edited ).toBeDefined();
		expect( payload.request_id ).toEqual( expect.any( String ) );

		await page.waitForURL( ( url ) => {
			return (
				/\/wp-admin\/post\.php$/.test( url.pathname ) &&
				url.searchParams.get( 'action' ) === 'edit' &&
				url.searchParams.get( 'post' ) !== testSourceId
			);
		} );
		expect( page.url() ).toMatch( /post=\d+&action=edit/ );
	} );

	test( 'copies unsaved title/content/excerpt from the forced editor-canvas iframe', async ( {
		page,
	} ) => {
		const testSourceId = await ensureSourcePost( page );
		await openEditor( page, testSourceId );
		const rest = await editorRestContext( page );
		if ( ! rest.nonce ) {
			test.skip(
				true,
				'The authenticated editor page did not expose a REST nonce.'
			);
		}

		const sourcePath = `/wp-json/wp/v2/${ rest.restBase }/${ testSourceId }?context=edit`;
		const sourceBeforeResponse = await page.request.get( sourcePath, {
			headers: { 'X-WP-Nonce': rest.nonce },
		} );
		expect(
			sourceBeforeResponse.ok(),
			await sourceBeforeResponse.text()
		).toBe( true );
		const sourceBefore = await sourceBeforeResponse.json();

		const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
		await expect( canvas.locator( 'body' ) ).toBeVisible();
		const titleInput = canvas
			.locator( '.editor-post-title__input, [aria-label="Add title"]' )
			.first();
		await expect( titleInput ).toBeVisible();
		const unsavedTitle = `Iframe unsaved title ${ Date.now() }`;
		await titleInput.fill( unsavedTitle );

		const content = canvas
			.locator(
				'[contenteditable="true"]:not(.editor-post-title__input)'
			)
			.first();
		await expect( content ).toBeVisible();
		const unsavedContent = `Iframe unsaved content ${ Date.now() }`;
		await content.fill( unsavedContent );

		const excerpt = page
			.locator(
				'textarea.editor-post-excerpt__textarea, textarea[id^="inspector-textarea-control"]'
			)
			.first();
		if ( ! ( await excerpt.count() ) ) {
			const editExcerpt = page
				.getByRole( 'button', {
					name: 'Edit excerpt',
					exact: true,
				} )
				.first();
			if (
				( await editExcerpt.count() ) &&
				( await editExcerpt.isVisible() )
			) {
				await editExcerpt.click();
			} else {
				const settings = page
					.locator( 'button[aria-label="Settings"]' )
					.first();
				await expect( settings ).toBeVisible();
				const postTab = page
					.locator(
						'button[role="tab"][data-tab-id="edit-post/document"]'
					)
					.first();
				if ( ! ( await postTab.isVisible() ) ) {
					await settings.click( { force: true } );
				}
				await expect( postTab ).toBeVisible();
				await postTab.evaluate( ( element ) =>
					( element as HTMLElement ).click()
				);
				await page.waitForTimeout( 300 );
				const editExcerptAfterSettings = page
					.getByRole( 'button', {
						name: 'Edit excerpt',
						exact: true,
					} )
					.first();
				await expect( editExcerptAfterSettings ).toBeVisible();
				await editExcerptAfterSettings.click();
			}
		}
		await expect( excerpt ).toBeVisible();
		const unsavedExcerpt = `Iframe unsaved excerpt ${ Date.now() }`;
		await excerpt.fill( unsavedExcerpt );
		await excerpt.press( 'Tab' );
		await page.waitForTimeout( 300 );
		await page.keyboard.press( 'Escape' );

		await page.getByRole( 'button', { name: 'Save As' } ).first().click();
		const dialog = page.getByRole( 'dialog', { name: 'Save As' } );
		await expect( dialog ).toBeVisible();
		const copyTitle = await dialog
			.getByLabel( 'Title for the copy' )
			.inputValue();
		expect( copyTitle ).toContain( unsavedTitle );

		const requestPromise = endpointRequest( page );
		await dialog.getByRole( 'button', { name: 'Create draft' } ).click();
		const request = await requestPromise;
		const payload = request.postDataJSON() as Record< string, any >;
		expect( payload.edited ).toMatchObject( {
			title: copyTitle,
			excerpt: unsavedExcerpt,
		} );
		expect( payload.edited.content ).toContain( unsavedContent );

		await page.waitForURL( ( url ) => {
			return (
				/\/wp-admin\/post\.php$/.test( url.pathname ) &&
				url.searchParams.get( 'action' ) === 'edit' &&
				url.searchParams.get( 'post' ) !== testSourceId
			);
		} );
		const targetId = new URL( page.url() ).searchParams.get( 'post' );
		expect( targetId ).toMatch( /^\d+$/ );
		const targetResponse = await page.request.get(
			`/wp-json/wp/v2/${ rest.restBase }/${ targetId }?context=edit`,
			{ headers: { 'X-WP-Nonce': rest.nonce } }
		);
		const targetResponseText = await targetResponse.text();
		const target = JSON.parse( targetResponseText );
		expect( targetResponse.ok(), targetResponseText ).toBe( true );
		expect( target.status ).toBe( 'draft' );
		expect( target.title.raw ).toBe( copyTitle );
		expect( target.content.raw ).toContain( unsavedContent );
		expect( target.excerpt.raw ).toBe( unsavedExcerpt );
		for ( const field of [
			'featured_media',
			'categories',
			'tags',
			'format',
			'template',
			'comment_status',
			'ping_status',
			'parent',
			'menu_order',
		] ) {
			if ( field in sourceBefore && field in target ) {
				expect( target[ field ] ).toEqual( sourceBefore[ field ] );
			}
		}

		const sourceAfterResponse = await page.request.get( sourcePath, {
			headers: { 'X-WP-Nonce': rest.nonce },
		} );
		expect(
			sourceAfterResponse.ok(),
			await sourceAfterResponse.text()
		).toBe( true );
		const sourceAfter = await sourceAfterResponse.json();
		expect( sourceAfter.title.raw ).toBe( sourceBefore.title.raw );
		expect( sourceAfter.content.raw ).toBe( sourceBefore.content.raw );
		expect( sourceAfter.excerpt.raw ).toBe( sourceBefore.excerpt.raw );
	} );

	test( 'Options menu remains the escape hatch after toolbar and sidebar are hidden', async ( {
		page,
	} ) => {
		await openEditor( page, await ensureSourcePost( page ) );
		await page.getByRole( 'button', { name: 'Save As' } ).first().click();

		const dialog = page.getByRole( 'dialog', { name: 'Save As' } );
		await dialog.getByLabel( 'Hide the toolbar button' ).check();
		await dialog.getByLabel( 'Hide the sidebar button' ).check();
		await dialog.getByRole( 'button', { name: 'Cancel' } ).click();

		await page.reload( { waitUntil: 'domcontentloaded' } );
		const options = page
			.getByRole( 'button', { name: /Options/i } )
			.first();
		await expect( options ).toBeVisible();
		await options.click();
		const menuItem = page.getByRole( 'menuitem', { name: 'Save As' } );
		await expect( menuItem ).toBeVisible();
		await menuItem.click();
		await expect(
			page.getByRole( 'dialog', { name: 'Save As' } )
		).toBeVisible();
	} );

	test( 'skip-modal preference still sends one request and follows the server redirect', async ( {
		page,
	} ) => {
		await page.addInitScript( () => {
			window.localStorage.setItem(
				'clonePostUnsavedChangesSkipModal',
				'1'
			);
		} );
		await openEditor( page, await ensureSourcePost( page ) );

		const requestPromise = endpointRequest( page );
		await page.getByRole( 'button', { name: 'Save As' } ).first().click();
		await requestPromise;
		await expect(
			page.getByRole( 'dialog', { name: 'Save As' } )
		).toHaveCount( 0 );
		await page.waitForURL(
			( url ) =>
				/\/wp-admin\/post\.php$/.test( url.pathname ) &&
				url.searchParams.get( 'action' ) === 'edit' &&
				url.searchParams.get( 'post' ) !==
					( generatedSourceId || sourceId ),
			{ timeout: 15_000 }
		);
	} );
} );
