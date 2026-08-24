# Clone Post with Unsaved Changes to a Draft

Adds a **Save As** button to the WordPress block editor that clones the current post or page — including unsaved changes — into a new draft and opens it.

- **Author:** [Alimuzzaman Alim](https://profiles.wordpress.org/alimuzzamanalim/)
- **Requires:** WordPress 6.6+ · PHP 7.4+
- **License:** GPL-2.0-or-later

> For the end-user description, installation, and FAQ, see [`readme.txt`](readme.txt) (the WordPress.org-formatted readme). This file is the developer guide.

## What it does

One click copies a supported Gutenberg post into a brand-new **draft** and redirects you to it — the original is never touched. The plugin’s own authenticated endpoint copies saved core fields and registered taxonomies, then applies supported unsaved block-editor changes.

The generic adapter supports public, REST-enabled post types except attachments and WooCommerce products. It copies the supported core fields (title, content, excerpt, discussion settings, parent, menu order, sticky state for posts, format, template, and featured-media reference) into a new draft owned by the current user; the new draft gets a fresh slug/guid and does not inherit the source password, dates, status, or author. Elementor 4.2.3 `wp-page` and `wp-post` documents use a separate, version-pinned adapter. It mirrors Elementor's persistent-setting defaults, captures the current container settings and element tree before any save, writes that frozen snapshot only to the new draft, verifies the persisted target payload and CSS, and fingerprints the source before and after the operation. Other Elementor versions and document types are rejected. The generic adapter reuses the featured-media ID; it does not directly copy files, attachments, comments, revisions, child records, custom tables, or plugin-owned external data. A server-side WooCommerce adapter uses WooCommerce's native duplicate workflow for `product` posts when a block-editor integration invokes it; product variations, classic Woo product screens, and unsaved Woo-specific fields remain outside this contract.

Post meta follows the WordPress REST contract: registered `show_in_rest` keys are copied by default after capability and operational-key checks. A site can narrow the exact set with the `clone_post_unsaved_changes_allowed_meta_keys` filter; unregistered/private keys remain denied. WooCommerce product meta is owned by WooCommerce's CRUD adapter and is not overlaid from this generic meta path. Plugin-owned data in custom tables, files, or external services remains outside the generic contract and needs a dedicated adapter.

The action is exposed in three places:

- the editor header toolbar (next to the native *Save*),
- the post status sidebar,
- the editor **⋮ (Options)** menu (always available — the escape hatch when the other two are hidden).

A dialog lets you name the copy and toggle visibility preferences (persisted per-browser in `localStorage`).

## Development

```bash
pnpm install     # install dependencies
pnpm start       # development build, watch mode
pnpm build       # one-off production build
pnpm typecheck   # tsc --noEmit (strict type checking)
```

> **Always run `pnpm build` after editing `src/`** — the PHP loads the compiled `build/` output, not `src/`. `build/` is gitignored, so it must be produced wherever the plugin is packaged/deployed.

## Architecture

The editor source is written in TypeScript/TSX and split into focused modules:

```
src/
  index.tsx                  Entry point — registers the editor plugin.
  types.ts                   Shared request/response payload types.
  preferences.ts             Typed localStorage preference helpers.
  api/payload.ts             Constrained unsaved editor overlay + preflight.
  api/draft.ts               Plugin REST client: createDraftCopy, redirect, quickCopy.
  hooks/useToolbarSlot.ts    Injects a portal slot into the editor header toolbar.
  components/
    SaveAsModal.tsx          The dialog (title + preference checkboxes).
    SaveAsPlugin.tsx         Orchestration: buttons, menu, dialog, visibility state.
```

- **State** is read/written through `@wordpress/data` stores (`core/editor`, `core`); the DOM is touched only to mount the toolbar button.
- **`clone-post-unsaved-changes.php`** registers `POST /clone-post-unsaved-changes/v1/drafts` and enqueues the compiled bundle on `enqueue_block_editor_assets`. The endpoint derives the source type and target author/status server-side, checks source/target/term capabilities, and uses request UUIDs for idempotency.
- **Build:** [`@wordpress/scripts`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-scripts/) with zero config — it auto-detects the `src/index.tsx` entry and emits `build/index.js` (+ `build/index.asset.php`). `wp-scripts` strips TypeScript via Babel at build time; `pnpm typecheck` runs `tsc` separately. Run `pnpm plugin-zip` to produce a distributable zip (see `.distignore`).

## Internationalization

All user-facing strings use `__`/`sprintf` from `@wordpress/i18n` with the `clone-post-unsaved-changes` text domain, wired for translation via `wp_set_script_translations()`. The text domain matches the plugin slug, as required for WordPress.org language packs.

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html)
