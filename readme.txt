=== Clone Post with Unsaved Changes to a Draft ===
Contributors: alimuzzamanalim
Tags: duplicate post, duplicate page, clone, copy, draft
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Duplicate or clone any post or page into a new draft — including unsaved changes — in one click, right from the block editor.

== Description ==

**Clone Post with Unsaved Changes to a Draft** adds a **Save As** button to the WordPress block editor. One click copies the post you are editing — including its unsaved changes — into a brand-new draft and opens it, so you can branch, version, or template your content without manually duplicating anything.

It works with supported Gutenberg posts, pages, and public REST-enabled custom post types. The plugin copies saved core fields and registered taxonomies, then applies supported unsaved block-editor changes. Copies are always drafts. Elementor 4.2.3 post/page documents have a separate, version-pinned adapter that mirrors Elementor's persistent document defaults, captures the unsaved editor snapshot before any save, verifies that Elementor persisted the complete snapshot, and writes it only to the new draft; other versions and document types fail closed. WooCommerce products use WooCommerce's native duplicate workflow only when a block-editor integration invokes the server adapter; classic Woo product screens, product variations, and unsaved Woo-specific fields remain outside this contract.

= Features =

* **Save As button** in the editor header, next to the native *Save* button.
* The same action is also available in the post sidebar and the editor's **⋮ (Options)** menu.
* A confirmation dialog lets you set the new draft's title before copying.
* New draft titles end in **(Copy)**, ready for you to adjust.
* **"Don't ask next time"** turns the dialog off for instant copies — Ctrl/⌘-click the button to bring it back.
* Press **Ctrl/⌘ + Alt + S** to open Save As without reaching for the mouse.
* Hide the toolbar and/or sidebar button from the dialog; the **⋮** menu item always stays available.
* Copies are always created as **drafts**, so you never overwrite the original.
* Uses a dedicated authenticated endpoint with capability checks and request-id idempotency.
* Fully translatable (proper `wp_set_script_translations` integration).

= How it works =

The plugin sends a constrained snapshot of the current block-editor state to its own authenticated endpoint. The server reads the saved source post, creates a draft for the current user, then applies supported edits and redirects you to the new draft. Nothing is changed on the original post.

== Installation ==

1. Upload the `clone-post-unsaved-changes` folder to the `/wp-content/plugins/` directory, or install the plugin through the **Plugins** screen in WordPress.
2. Activate the plugin through the **Plugins** screen.
3. Edit any existing post or page — the **Save As** button appears in the editor toolbar.

== Frequently Asked Questions ==

= Does it work on new, unsaved posts? =

No. There is nothing to copy until a post has been saved at least once, so the button is hidden on brand-new posts.

= Does it copy my unsaved changes? =

Yes, for supported block-editor fields: title, content, excerpt, featured media, discussion settings, sticky state for posts, format, template, parent, menu order, REST taxonomies, and registered REST-visible meta. If another dirty field cannot be copied safely, Save As stops before creating a draft.

= Does it work with pages and custom post types? =

Supported public post types must be available through the REST API and use the block editor. Attachments, autosaves, revisions, and trashed posts are not handled by the generic adapter. WooCommerce products require the native WooCommerce adapter and a block-editor integration; product variations and the standard classic Woo product editor remain unsupported. Custom post types that use Elementor require the separate Elementor adapter.

= What gets copied? =

Supported core post fields and registered taxonomies are copied, with current editor changes overlaid. The new draft belongs to the current user, has a fresh slug/guid, and does not inherit the source password, dates, status, or author. Sticky state is copied for posts, subject to WordPress's sticky capability. Featured media is reused by ID. Registered `show_in_rest` meta keys are copied after capability and operational-key checks; a site developer can narrow the exact set with the `clone_post_unsaved_changes_allowed_meta_keys` filter. WooCommerce product meta is handled only by WooCommerce's native CRUD adapter. The generic adapter does not directly copy files, attachments, comments, revisions, child records, custom tables, or plugin-owned external data.

= Does it support Elementor or WooCommerce products? =

Elementor 4.2.3 post/page documents use a separate, version-pinned adapter. Save As mirrors Elementor's persistent-setting defaults, captures the current document container settings and element tree without invoking Elementor's `save_builder`/autosave request, writes the frozen data to a new draft through Elementor's document API, verifies the draft status, persisted element/settings payload, and CSS generation, and checks that the source post, meta, and taxonomy relationships did not change. Other Elementor versions/document types fail closed. WooCommerce products use WooCommerce's own CRUD duplicate workflow for saved product data; unsaved product-specific fields and the normal classic product editor are not claimed as supported.

= I hid both buttons — how do I get them back? =

Open the editor's **⋮ (Options)** menu and choose **Save As**; that item is always available. Uncheck the "Hide" options in the dialog.

== Development ==

Development happens on GitHub. Bug reports, feature requests, and pull requests are welcome:

[https://github.com/alimuzzaman/clone-post-unsaved-changes](https://github.com/alimuzzaman/clone-post-unsaved-changes)

== Screenshots ==

1. The Save As button in the block editor toolbar.
2. The Save As dialog with title, "don't ask", and visibility options.
3. The Save As action in the post sidebar.

== Changelog ==

= 1.0.3 =
* Add WordPress 7.1 iframe-editor coverage for Save As, including unsaved title, content, and excerpt changes.
* Support REST-enabled custom post types with registered REST-visible metadata and direct source-state checks.
* Add version-pinned Elementor 4.2.3 and bounded WooCommerce native-duplication adapters.
* Make retries idempotent, expire request records, and keep development files out of production archives.

= 1.0.2 =
* Prevent duplicate drafts when a Save As request fails for a reason unrelated to post meta.
* New copies now start with a **(Copy)** title, making them easier to distinguish.
* Add a Ctrl/⌘ + Alt + S keyboard shortcut for Save As.
* Show confirmation while the new draft is opening.


= 1.0.1 =
* Fix spacing of the sidebar button and update screenshots to full-page landscape views.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.3 =
Adds WordPress 7.1 iframe-editor coverage, bounded custom-post-type metadata cloning, Elementor/WooCommerce adapters, safer retries, and clean production packaging.

= 1.0.2 =
Improves Save As reliability with a default copy title, keyboard shortcut, and clearer confirmation when opening a new draft.

= 1.0.1 =
Minor styling fix for sidebar button layout.

= 1.0.0 =
Initial release.
