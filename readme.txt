=== Clone Post with Unsaved Changes to a Draft ===
Contributors: alimuzzamanalim
Tags: duplicate post, duplicate page, clone, copy, draft
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Duplicate or clone any post or page into a new draft — including unsaved changes — in one click, right from the block editor.

== Description ==

**Clone Post with Unsaved Changes to a Draft** adds a **Save As** button to the WordPress block editor. One click copies the post you are editing — including its unsaved changes — into a brand-new draft and opens it, so you can branch, version, or template your content without manually duplicating anything.

It works for posts, pages, and any public custom post type, and copies the title, content, excerpt, featured image, taxonomies, discussion settings, format, template, and post meta.

= Features =

* **Save As button** in the editor header, next to the native *Save* button.
* The same action is also available in the post sidebar and the editor's **⋮ (Options)** menu.
* A confirmation dialog lets you set the new draft's title before copying.
* New draft titles end in **(Copy)**, ready for you to adjust.
* **"Don't ask next time"** turns the dialog off for instant copies — Ctrl/⌘-click the button to bring it back.
* Press **Ctrl/⌘ + Alt + S** to open Save As without reaching for the mouse.
* Hide the toolbar and/or sidebar button from the dialog; the **⋮** menu item always stays available.
* Copies are always created as **drafts**, so you never overwrite the original.
* Fully translatable (proper `wp_set_script_translations` integration).

= How it works =

The plugin reads the current editor state (including unsaved edits) and creates a new draft through the WordPress REST API, then redirects you to the new draft's edit screen. Nothing is changed on the original post.

== Installation ==

1. Upload the `clone-post-unsaved-changes` folder to the `/wp-content/plugins/` directory, or install the plugin through the **Plugins** screen in WordPress.
2. Activate the plugin through the **Plugins** screen.
3. Edit any existing post or page — the **Save As** button appears in the editor toolbar.

== Frequently Asked Questions ==

= Does it work on new, unsaved posts? =

No. There is nothing to copy until a post has been saved at least once, so the button is hidden on brand-new posts.

= Does it copy my unsaved changes? =

Yes. The copy is built from the current editor state, so edits you have not saved yet are included in the new draft.

= Does it work with pages and custom post types? =

Yes. The REST route is derived from the current post type, so posts, pages, and public custom post types are all supported.

= What gets copied? =

Title, content, excerpt, featured image, categories and tags, discussion (comment/ping) settings, post format, page template, and post meta exposed to the REST API.

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

= 1.0.2 =
Improves Save As reliability with a default copy title, keyboard shortcut, and clearer confirmation when opening a new draft.

= 1.0.1 =
Minor styling fix for sidebar button layout.

= 1.0.0 =
Initial release.
