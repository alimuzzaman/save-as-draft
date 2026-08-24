/* eslint-disable import/no-unresolved -- Playwright is supplied by the disposable E2E runner. */
import { expect, test, type Page } from '@playwright/test';

const baseURL = process.env.WP_BASE_URL;
const storageState = process.env.WP_STORAGE_STATE;
const loginURL = process.env.WP_LOGIN_URL;
const sourceId = process.env.CLONE_CONTRACT_ELEMENTOR_SOURCE_ID;

const editorPath = ( id: string ) =>
	`/wp-admin/post.php?post=${ encodeURIComponent( id ) }&action=elementor`;

type RestPost = {
	id: number;
	status: string;
	title: { raw?: string };
	content: { raw?: string };
	excerpt: { raw?: string };
	featured_media?: number;
	template?: string;
	parent?: number;
	menu_order?: number;
	comment_status?: string;
	ping_status?: string;
};

type RestType = {
	rest_base?: string;
};

async function restNonce( page: Page ) {
	return page.evaluate( () => {
		const settings = (
			window as Window & { wpApiSettings?: { nonce?: string } }
		 ).wpApiSettings;
		const plugin = (
			window as Window & {
				clonePostUnsavedChangesElementor?: { nonce?: string };
			}
		 ).clonePostUnsavedChangesElementor;
		return settings?.nonce || plugin?.nonce || '';
	} );
}

async function authenticate( page: Page ) {
	if ( storageState ) {
		return;
	}
	if ( ! loginURL ) {
		test.skip(
			true,
			'Set WP_LOGIN_URL or WP_STORAGE_STATE for an authenticated Elementor fixture.'
		);
	}
	await page.goto( loginURL as string, { waitUntil: 'domcontentloaded' } );
	await page.goto( '/wp-admin/', { waitUntil: 'domcontentloaded' } );
	await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
}

async function findPostResource( page: Page, id: string, nonce: string ) {
	const typesResponse = await page.request.get( '/wp-json/wp/v2/types', {
		headers: { 'X-WP-Nonce': nonce },
	} );
	expect( typesResponse.ok(), await typesResponse.text() ).toBeTruthy();
	const types = ( await typesResponse.json() ) as Record< string, RestType >;

	for ( const type of Object.values( types ) ) {
		if ( ! type.rest_base ) {
			continue;
		}
		const response = await page.request.get(
			`/wp-json/wp/v2/${ type.rest_base }/${ encodeURIComponent(
				id
			) }?context=edit`,
			{ headers: { 'X-WP-Nonce': nonce } }
		);
		if ( response.ok() ) {
			return {
				restBase: type.rest_base,
				post: ( await response.json() ) as RestPost,
			};
		}
	}

	throw new Error(
		`Could not find a REST resource for Elementor post ${ id }.`
	);
}

function coreSnapshot( post: RestPost ) {
	return {
		status: post.status,
		title: post.title?.raw || '',
		content: post.content?.raw || '',
		excerpt: post.excerpt?.raw || '',
		featured_media: post.featured_media || 0,
		template: post.template || '',
		parent: post.parent || 0,
		menu_order: post.menu_order || 0,
		comment_status: post.comment_status || '',
		ping_status: post.ping_status || '',
	};
}

test.describe( 'Elementor 4.2.3 Save As contract', () => {
	test( 'copies the frozen editor snapshot without autosaving the source', async ( {
		page,
	} ) => {
		if ( ! baseURL || ! sourceId || ( ! storageState && ! loginURL ) ) {
			test.skip(
				true,
				'Set WP_BASE_URL, WP_LOGIN_URL (or WP_STORAGE_STATE), and CLONE_CONTRACT_ELEMENTOR_SOURCE_ID for an authenticated Elementor 4.2.3 fixture.'
			);
		}
		await authenticate( page );

		await page.goto( editorPath( sourceId as string ), {
			waitUntil: 'domcontentloaded',
		} );
		const nonce = await restNonce( page );
		expect( nonce ).not.toBe( '' );
		const sourceResource = await findPostResource(
			page,
			sourceId as string,
			nonce
		);
		const sourceBefore = coreSnapshot( sourceResource.post );

		await expect( page.locator( '.cpuc-elementor-save-as' ) ).toBeVisible( {
			timeout: 30_000,
		} );
		await page.waitForFunction(
			() => {
				const elementorWindow = window as Window & {
					elementor?: {
						documents?: {
							getCurrent?: () => {
								container?: {
									children?: {
										[ index: number ]: unknown;
										models?: unknown[];
										at?: ( index: number ) => unknown;
									};
								};
							};
						};
					};
					$e?: { run?: unknown };
				};
				const container =
					elementorWindow.elementor?.documents?.getCurrent?.()
						?.container;
				const children = container?.children;
				const child =
					children?.[ 0 ] ||
					children?.models?.[ 0 ] ||
					children?.at?.( 0 );
				return Boolean(
					child &&
						elementorWindow.$e &&
						'function' === typeof elementorWindow.$e.run
				);
			},
			undefined,
			{ timeout: 30_000 }
		);

		const unsavedHeading = `Unsaved Elementor heading ${ Date.now() }`;
		await page.evaluate( async ( title ) => {
			const elementorWindow = window as Window & {
				elementor?: {
					documents?: {
						getCurrent?: () => {
							container?: {
								children?: {
									[ index: number ]: unknown;
									models?: unknown[];
									at?: ( index: number ) => unknown;
								};
							};
						};
					};
				};
				$e?: {
					run?: (
						command: string,
						args: unknown
					) => Promise< unknown >;
				};
			};
			const container =
				elementorWindow.elementor?.documents?.getCurrent?.().container;
			const children = container?.children;
			const child =
				children?.[ 0 ] ||
				children?.models?.[ 0 ] ||
				children?.at?.( 0 );
			if ( ! child || ! elementorWindow.$e?.run ) {
				throw new Error( 'Elementor widget API is not ready.' );
			}
			await elementorWindow.$e.run( 'document/elements/settings', {
				container: child,
				settings: { title },
			} );
		}, unsavedHeading );

		const prohibitedRequests: string[] = [];
		page.on( 'request', ( request ) => {
			const data = request.postData() || '';
			if (
				/\/admin-ajax\.php/.test( request.url() ) &&
				( /(?:^|[?&])action=save_builder(?:&|$)/.test(
					request.url()
				) ||
					/action=save_builder/.test( data ) )
			) {
				prohibitedRequests.push( request.url() );
			}
		} );
		await page.evaluate( () => {
			const state = window as Window & { __cpucAutoSaveCalls?: number };
			state.__cpucAutoSaveCalls = 0;
			if ( window.$e && 'function' === typeof window.$e.run ) {
				const originalRun = window.$e.run.bind( window.$e );
				window.$e.run = ( command: string, ...args: unknown[] ) => {
					if ( 'document/save/auto' === command ) {
						state.__cpucAutoSaveCalls =
							( state.__cpucAutoSaveCalls || 0 ) + 1;
					}
					return originalRun( command, ...args );
				};
			}
		} );

		await page.locator( '.cpuc-elementor-save-as' ).click();
		const title = `Elementor contract copy ${ Date.now() }`;
		await page.locator( '[data-cpuc-title]' ).fill( title );
		const cloneRequestPromise = page.waitForRequest(
			( request ) =>
				request.method() === 'POST' &&
				/\/wp-json\/clone-post-unsaved-changes\/v1\/drafts(?:\?|$)/.test(
					request.url()
				),
			{ timeout: 15_000 }
		);
		await page.locator( '[data-cpuc-confirm]' ).click();
		const cloneRequest = await cloneRequestPromise;
		const payload = cloneRequest.postDataJSON() as Record<
			string,
			unknown
		>;
		const edited = payload.edited as {
			elementor?: { elements?: unknown; settings?: unknown };
		};
		expect( payload.source_id ).toBe( Number( sourceId ) );
		expect( payload.editor ).toBe( 'elementor' );
		expect( payload.copy_title ).toBe( title );
		expect( Array.isArray( edited.elementor?.elements ) ).toBeTruthy();
		expect(
			edited.elementor?.settings &&
				typeof edited.elementor.settings === 'object' &&
				! Array.isArray( edited.elementor.settings )
		).toBeTruthy();
		expect( JSON.stringify( edited.elementor ) ).toContain(
			unsavedHeading
		);
		expect( prohibitedRequests ).toEqual( [] );
		expect(
			await page.evaluate(
				() =>
					( window as Window & { __cpucAutoSaveCalls?: number } )
						.__cpucAutoSaveCalls || 0
			)
		).toBe( 0 );

		await page.waitForURL(
			( url ) =>
				/\/wp-admin\/post\.php$/.test( url.pathname ) &&
				url.searchParams.get( 'action' ) === 'elementor' &&
				url.searchParams.get( 'post' ) !== sourceId,
			{ timeout: 15_000 }
		);
		const targetId = Number(
			new URL( page.url() ).searchParams.get( 'post' )
		);
		expect( targetId ).toBeGreaterThan( 0 );
		expect( targetId ).not.toBe( Number( sourceId ) );

		const targetResponse = await page.request.get(
			`/wp-json/wp/v2/${ sourceResource.restBase }/${ targetId }?context=edit`,
			{ headers: { 'X-WP-Nonce': nonce } }
		);
		expect( targetResponse.ok(), await targetResponse.text() ).toBeTruthy();
		const target = ( await targetResponse.json() ) as RestPost;
		expect( target.status ).toBe( 'draft' );
		expect( target.title?.raw ).toBe( title );

		const sourceAfterResponse = await page.request.get(
			`/wp-json/wp/v2/${ sourceResource.restBase }/${ sourceId }?context=edit`,
			{ headers: { 'X-WP-Nonce': nonce } }
		);
		expect(
			sourceAfterResponse.ok(),
			await sourceAfterResponse.text()
		).toBeTruthy();
		const sourceAfter = ( await sourceAfterResponse.json() ) as RestPost;
		expect( coreSnapshot( sourceAfter ) ).toEqual( sourceBefore );
	} );
} );
