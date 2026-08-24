/* eslint-disable import/no-unresolved -- Playwright is supplied by the disposable E2E runner. */
import { expect, test, type Page } from '@playwright/test';

const baseURL = process.env.WP_BASE_URL;
const storageState = process.env.WP_STORAGE_STATE;

const requestId = () => {
	if ( typeof globalThis.crypto?.randomUUID === 'function' ) {
		return globalThis.crypto.randomUUID();
	}

	const hex = () =>
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

const editorPath = ( id: string ) =>
	`/wp-admin/post.php?post=${ encodeURIComponent( id ) }&action=edit`;

async function wpRestNonce( page: Page ) {
	return page.evaluate( () => {
		const settings = (
			window as Window & {
				wpApiSettings?: { nonce?: string };
			}
		 ).wpApiSettings;
		return settings?.nonce || '';
	} );
}

async function assertGenericCloneBlocked(
	page: Page,
	sourceId: string,
	label: string,
	expectedCode: string
) {
	await page.goto( editorPath( sourceId ), {
		waitUntil: 'domcontentloaded',
	} );
	const nonce = await wpRestNonce( page );
	if ( ! nonce ) {
		test.skip(
			true,
			'The authenticated admin page did not expose wpApiSettings.nonce.'
		);
	}

	const response = await page.request.post(
		'/wp-json/clone-post-unsaved-changes/v1/drafts',
		{
			headers: { 'X-WP-Nonce': nonce },
			data: {
				source_id: Number( sourceId ),
				request_id: requestId(),
				editor: 'block',
				copy_title: `Blocked ${ label } copy`,
				edited: {},
			},
		}
	);

	expect( response.status(), await response.text() ).toBeGreaterThanOrEqual(
		400
	);
	const body = await response.json();
	expect( body.code ).toBe( expectedCode );
}

test.describe( 'Explicitly unsupported generic-editor boundaries', () => {
	test( 'WooCommerce products stay out of the generic clone endpoint', async ( {
		page,
	} ) => {
		test.info().annotations.push( {
			type: 'blocked',
			description:
				'Woo product/variation cloning requires Woo native CRUD and a separate pinned adapter; this generic Gutenberg contract must reject it.',
		} );
		const sourceId = process.env.CLONE_CONTRACT_WOO_SOURCE_ID;
		if ( ! baseURL || ! storageState || ! sourceId ) {
			test.skip(
				true,
				'Blocked expectation: provide an authenticated Woo fixture ID to exercise the direct rejection.'
			);
		}
		await assertGenericCloneBlocked(
			page,
			sourceId as string,
			'woo',
			'clone_post_unsaved_changes_woocommerce_unsupported'
		);
	} );

	test( 'Elementor documents stay out of the generic clone endpoint', async ( {
		page,
	} ) => {
		test.info().annotations.push( {
			type: 'blocked',
			description:
				'Elementor needs a version-pinned document/CSS adapter; generic Gutenberg cloning must reject Elementor-owned documents.',
		} );
		const sourceId = process.env.CLONE_CONTRACT_ELEMENTOR_SOURCE_ID;
		if ( ! baseURL || ! storageState || ! sourceId ) {
			test.skip(
				true,
				'Blocked expectation: provide an authenticated Elementor document fixture ID to exercise the direct rejection.'
			);
		}
		await assertGenericCloneBlocked(
			page,
			sourceId as string,
			'elementor',
			'clone_post_unsaved_changes_elementor_unsupported'
		);
	} );
} );
