/* eslint-disable import/no-unresolved -- Playwright is supplied by the disposable E2E runner. */
import fs from 'node:fs';
import path from 'node:path';
import { expect, test, type Page } from '@playwright/test';

const sourceId =
	process.env.CLONE_CONTRACT_FAILURE_SOURCE_ID ||
	process.env.CLONE_CONTRACT_SOURCE_ID;
const sandboxDescriptorPath = path.resolve( '.wp-env-port' );
const sandboxDescriptor = fs.existsSync( sandboxDescriptorPath )
	? ( JSON.parse( fs.readFileSync( sandboxDescriptorPath, 'utf8' ) ) as {
			baseUrl?: string;
			loginUrl?: string;
	  } )
	: undefined;
const baseURL = process.env.WP_BASE_URL || sandboxDescriptor?.baseUrl;
const loginURL = process.env.WP_LOGIN_URL || sandboxDescriptor?.loginUrl;
const storageState = process.env.WP_STORAGE_STATE;
let generatedSourceId: string | undefined;

const editorPath = ( id: string ) =>
	`/wp-admin/post.php?post=${ encodeURIComponent( id ) }&action=edit`;

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
			title: `Sandbox failure source ${ Date.now() }`,
			content:
				'<!-- wp:paragraph --><p>Failure source.</p><!-- /wp:paragraph -->',
		},
	} );
	expect( response.ok(), await response.text() ).toBe( true );
	const post = ( await response.json() ) as { id?: number };
	expect( post.id ).toEqual( expect.any( Number ) );
	generatedSourceId = String( post.id );
	return generatedSourceId;
}

test.describe( 'Save As failure and cleanup UX', () => {
	test.beforeEach( async ( { page } ) => {
		if ( ! baseURL ) {
			test.skip(
				true,
				"Set WP_BASE_URL or run through Sandbox's disposable E2E runner."
			);
		}
		await page.addInitScript( () => {
			window.localStorage.removeItem(
				'clonePostUnsavedChangesSkipModal'
			);
		} );
		await authenticate( page );
	} );

	test( 'a failed endpoint keeps the modal open and shows an actionable error', async ( {
		page,
	} ) => {
		await page.route(
			'**/wp-json/clone-post-unsaved-changes/v1/drafts**',
			async ( route ) => {
				await route.fulfill( {
					status: 500,
					contentType: 'application/json',
					body: JSON.stringify( {
						code: 'cpuc_incomplete_clone',
						message:
							'Fixture failure: target cleanup was verified.',
						data: { status: 500 },
					} ),
				} );
			}
		);

		await page.goto( editorPath( await ensureSourcePost( page ) ), {
			waitUntil: 'domcontentloaded',
		} );
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
		const originalURL = page.url();
		await page.getByRole( 'button', { name: 'Save As' } ).first().click();
		const dialog = page.getByRole( 'dialog', { name: 'Save As' } );
		await expect( dialog ).toBeVisible();
		await dialog
			.getByLabel( 'Title for the copy' )
			.fill( 'Expected failure copy' );
		await dialog.getByRole( 'button', { name: 'Create draft' } ).click();

		await expect(
			dialog.getByText( 'Could not create the copy:', { exact: false } )
		).toBeVisible();
		await expect(
			dialog.getByRole( 'button', { name: 'Create draft' } )
		).toBeEnabled();
		await expect( page ).toHaveURL( originalURL );
	} );

	test( 'retrying the same copy reuses its request ID', async ( {
		page,
	} ) => {
		const requestIds: string[] = [];
		let attempts = 0;
		await page.route(
			'**/wp-json/clone-post-unsaved-changes/v1/drafts**',
			async ( route ) => {
				const payload = route.request().postDataJSON() as {
					request_id?: string;
				};
				requestIds.push( payload.request_id || '' );
				attempts += 1;
				if ( 1 === attempts ) {
					await route.fulfill( {
						status: 500,
						contentType: 'application/json',
						body: JSON.stringify( {
							code: 'cpuc_transport_fixture',
							message:
								'Fixture failure before the response arrived.',
							data: { status: 500 },
						} ),
					} );
					return;
				}
				await route.continue();
			}
		);

		await page.goto( editorPath( await ensureSourcePost( page ) ), {
			waitUntil: 'domcontentloaded',
		} );
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
		await page.getByRole( 'button', { name: 'Save As' } ).first().click();
		const dialog = page.getByRole( 'dialog', { name: 'Save As' } );
		await expect( dialog ).toBeVisible();
		await dialog
			.getByLabel( 'Title for the copy' )
			.fill( 'Retry-safe copy' );
		await dialog.getByRole( 'button', { name: 'Create draft' } ).click();
		await expect(
			dialog.getByText( 'Could not create the copy:', { exact: false } )
		).toBeVisible();
		await dialog.getByRole( 'button', { name: 'Create draft' } ).click();
		await page.waitForURL(
			( url ) =>
				/\/wp-admin\/post\.php$/.test( url.pathname ) &&
				url.searchParams.get( 'action' ) === 'edit',
			{ timeout: 15_000 }
		);
		await expect.poll( () => requestIds.length ).toBe( 2 );
		expect( requestIds[ 0 ] ).not.toBe( '' );
		expect( requestIds[ 1 ] ).toBe( requestIds[ 0 ] );
	} );
} );
