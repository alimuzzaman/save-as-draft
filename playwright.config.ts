/* eslint-disable import/no-unresolved -- Playwright is supplied by the disposable E2E runner. */
import fs from 'node:fs';
import path from 'node:path';
import { defineConfig, devices } from '@playwright/test';

// Sandbox writes this descriptor for projects that opt into its one-instance
// E2E convention. Keeping the marker here lets the runner provide both the
// base URL and its disposable auto-login URL without putting credentials in
// the repository or in a committed storage state.
const sandboxDescriptorPath = path.resolve( '.wp-env-port' );
const sandboxDescriptor = fs.existsSync( sandboxDescriptorPath )
	? ( JSON.parse( fs.readFileSync( sandboxDescriptorPath, 'utf8' ) ) as {
			baseUrl?: string;
			loginUrl?: string;
	  } )
	: undefined;
const baseURL = process.env.WP_BASE_URL || sandboxDescriptor?.baseUrl;
const storageState = process.env.WP_STORAGE_STATE;

/**
 * Optional contract-suite configuration. Playwright is a development-only
 * dependency; the disposable-site runner supplies the site and auto-login URL
 * without storing an authenticated state in the repository.
 */
export default defineConfig( {
	testDir: './tests/e2e',
	fullyParallel: false,
	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 1 : 0,
	workers: 1,
	timeout: 45_000,
	expect: { timeout: 7_000 },
	use: {
		...( baseURL ? { baseURL } : {} ),
		...( storageState ? { storageState } : {} ),
		...devices[ 'Desktop Chrome' ],
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'off',
	},
	reporter: process.env.CI ? [ [ 'line' ] ] : [ [ 'list' ] ],
} );
