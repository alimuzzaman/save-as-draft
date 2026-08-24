# Clone endpoint contract fixtures

These files are disposable-site test assets. They are intentionally outside
the plugin runtime and are not packaged for WordPress.org.

## WordPress/REST matrix

Load the fixture plugin and run the machine-readable assertion matrix inside a
WordPress installation where the plugin under test is active:

```sh
wp --require=tests/fixtures/clone-contract-fixtures.php \
   eval-file tests/contract/run.php
```

The command prints one JSON document and exits non-zero when an assertion or
environment gate fails. It calls `POST /clone-post-unsaved-changes/v1/drafts`
through `rest_do_request()` with the current WP-CLI user's `X-WP-Nonce`.
There are no credentials, hosts, or tokens in the fixture. Set
`CLONE_CONTRACT_USER_ID` only when the WP-CLI process is not already running as
an editor-capable user.

Useful modes:

```sh
# Create rows and keep them for inspection (the option stores all IDs).
CLONE_CONTRACT_ACTION=setup CLONE_CONTRACT_KEEP=1 \
  wp --require=tests/fixtures/clone-contract-fixtures.php \
  eval-file tests/contract/run.php

# Run the matrix and keep source/target rows for a DB diff.
CLONE_CONTRACT_KEEP=1 \
  wp --require=tests/fixtures/clone-contract-fixtures.php \
  eval-file tests/contract/run.php

# Remove only the rows/table created by the fixture option.
CLONE_CONTRACT_ACTION=teardown \
  wp --require=tests/fixtures/clone-contract-fixtures.php \
  eval-file tests/contract/run.php
```

Use a disposable database for the run. The default action cleans fixture posts,
terms, the test custom table, comments, and known target IDs after emitting
JSON. `CLONE_CONTRACT_KEEP=1` intentionally leaves rows so an operator can
inspect `wp_posts`, `wp_postmeta`, term relationships, and the custom table;
run the explicit teardown command afterward.

The fixture source contains:

- a REST-enabled `clone_contract_item` Gutenberg CPT;
- hierarchical `clone_contract_topic` and flat `clone_contract_label` REST
  taxonomies, plus core category/tag assignments;
- a thumbnail attachment, source parent/menu order/template/format/discussion
  values, a sticky post source, password/slug negative controls, a child post,
  comment, and revision;
- registered REST-visible single, repeated, and serialized meta keys, plus
  private, lock, internal, and Elementor-like keys that must not cross the
  clone boundary;
- one disposable custom-table row documenting that the generic adapter does not
  directly copy unknown plugin-owned storage.

The runner verifies the frozen schema:

```json
{
  "source_id": 123,
  "request_id": "uuid",
  "editor": "block",
  "copy_title": "Source title (Copy)",
  "edited": { "content": "...", "taxonomies": {}, "meta": {} }
}
```

It checks core and unsaved overlay values (including saved and changed sticky
state), dynamic saved taxonomies, registered REST-visible meta rows, source immutability, attachment-reference reuse,
absence of comments/revisions/children/custom-table rows, idempotent replay,
payload conflict, and zero-target rejection for product variations, Elementor documents,
revisions/autosaves/trash/attachments, unsupported dirty fields/taxonomies,
private meta, invalid terms, and missing sources. Each source/target snapshot
also captures raw `wp_posts`, `wp_postmeta`, `wp_term_relationships`, and the
fixture custom-table rows, so the source-side assertion is a direct SQL diff as
well as a WordPress object-model comparison. A missing endpoint route is
reported as an environment failure rather than being treated as a passing
negative test.

## Browser specifications

`tests/e2e/*.spec.ts` are Playwright specifications for the Gutenberg toolbar,
Options-menu escape hatch, modal and skip-modal paths, redirect, and failure
notice. `elementor-save-as.spec.ts` additionally exercises the real pinned
Elementor 4.2.3 adapter when a source fixture is supplied. When WooCommerce's
native product APIs are installed, the runner creates one disposable simple
product and temporarily forces the product post type through the block-editor
capability filter to exercise the server adapter. It verifies the native
product adapter, its draft result, response marker, saved product data,
featured-media removal, safe core overlay, source immutability, and rejection
of an unsaved product-specific field. The forced capability is a server
contract only; it is not a claim that the standard WooCommerce 11.0.1 product
screen exposes the Save As UI. Product variations and non-block editor paths
remain unsupported in every environment.
Optional tests never claim support without a source fixture and a real
authenticated browser session.

`@playwright/test` is a development-only dependency; no credentials or
authenticated storage state are committed. Run the specs against a disposable
site with a host-provided browser binary:

```sh
WP_BASE_URL="https://your-disposable-site.example" \
WP_STORAGE_STATE="/secure/path/editor-storage.json" \
CLONE_CONTRACT_SOURCE_ID=123 \
pnpm exec playwright test tests/e2e
```

`WP_STORAGE_STATE` is a normal Playwright storage-state file produced by the
operator; it is not committed. Optional environment IDs are
`CLONE_CONTRACT_ELEMENTOR_SOURCE_ID`, `CLONE_CONTRACT_WOO_SOURCE_ID`, and
`CLONE_CONTRACT_FAILURE_SOURCE_ID`. Without them, the corresponding scenarios
are marked skipped with a reason. The tests do not log cookies, nonces, or
storage-state contents.
