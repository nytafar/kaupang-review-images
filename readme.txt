=== Kaupang Review Images ===
Contributors: lassejellum
Tags: woocommerce, reviews, review images, avatar, gravatar
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 2.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Customers attach a product photo and a profile picture to their WooCommerce review; default Gravatars stay off the page.

== Description ==

Review image and avatar uploads for WooCommerce product reviews — customers attach a product photo and a profile picture to their review, stored as ordinary media attachments; conditional Gravatar loading keeps default avatars off the page. Part of the Kaupang suite.

== Installation ==

1. Upload the `kaupang-review-images` folder to `/wp-content/plugins/`
2. Activate through the Plugins menu
3. The upload fields appear in the product review form automatically

`block/build/` is committed, so deploys are copy-only — no `node` on the server. See
[Building](#building) if you change the editor JS.

== Changelog ==

= 2.0.0 – 2026-09-01 =

Two breaking changes — the rename and the hook removal — hence the major bump.

* **BREAKING**: renamed `woocommerce-review-images` → `kaupang-review-images`. Folder, entry
  file, text domain, GitHub repo. Every `wcri_*` filter is now `kaupang/review-images/*`.
* **BREAKING**: the five `woocommerce_review_meta_{start,author,after_author,after_verified,end}`
  actions **no longer exist**. `custom-review-meta.php` replaced WooCommerce's
  `woocommerce_review_display_meta` renderer wholesale just to emit them, and the only consumer
  anywhere was the myrvann theme moving a gravatar. WooCommerce's own renderer runs again and the
  avatar returns to `woocommerce_review_before`. They are not renamed, deprecated or shimmed:
  no third party fires or consumes them, and impersonating a core hook only misleads whoever
  greps WooCommerce for it. `kaupang/review-images/display_avatar_in_meta` and
  `WC_Review_Images_Avatar_Display::display_avatar_in_review_meta()` go with them.
* **NEW**: the **Curated Review** block (`kaupang-review-images/curated-review`) — one
  hand-picked review, nine toggles, the `kaupang-review__*` class contract, and three
  style variations of which the default ships no CSS.
  Kill switch: `kaupang/review-images/enable_block`.
* **FIXED**: a review whose author has **no email address** rendered the default mystery-person.
  The conditional-Gravatar branch only suppressed when an address was present and had no
  Gravatar — but no address means no Gravatar can exist, so that was the case most in need of
  suppressing.
* **FIXED**: the avatar gate asked the wrong question. Both `get_avatar` filters early-returned
  unless `is_product()`, so the custom-upload → real-Gravatar → nothing chain never ran off a
  product page — exactly where the default silhouette this plugin exists to suppress would show.
  The gate now tests whether the *comment* is a product review (`Support\Comments`). A latent bug
  went with it: a numeric `$id_or_email` in `get_avatar()` is a *user* id, and the old code read
  it as a comment id.
* **CHANGED**: Composer-less PSR-4 (`Kaupang\ReviewImages\` → `includes/`), `strict_types=1`,
  `KAUPANG_REVIEW_IMAGES_{VERSION,FILE,DIR,URL}`, boot wiring in `includes/Plugin.php`.
* **CHANGED**: requirements raised to PHP 8.1 / WordPress 6.7 / WooCommerce 9.0.
* **NEW**: `Reviews\Images::getImageIds()` accessor; every `_review_image_id` call site routes
  through it and loop, so multi-image support is a storage change away.
* **NEW**: `Support\Media::ingest()` — one shared size → MIME → `media_handle_upload()` path
  replacing the two near-identical upload handlers, and one copy of the MIME allowlist.
* **CHANGED**: asset versions come from `filemtime()` instead of a frozen `'1.2.1'` literal that
  had already drifted from the header.
* **REMOVED**: the ~30-line bespoke `.mo` candidate-probing loader, the dead
  `display_avatar_upload_field()`, and the `WP_DEBUG` footer probe.
* **DOCS**: two defaults were documented wrongly before this release —
  `display_avatar_in_meta` was documented `true` but was always `false` (moot: it is deleted),
  and `gravatar_base_size` was documented `200` but has always been `120`.

= 1.2.1 – 2025-12-17 =

* Frontend styles and a toggle script for the review image/avatar upload form (accordion form now displays correctly when open)
* Improved translation loading and updated Norwegian translations
* Declared HPOS (custom order tables) compatibility
* Gravatar existence check extracted into one shared helper (`wcri_has_gravatar()`); duplicate implementations removed
* Removed debug logging and the unused `display_avatar_upload_field()` method

= 1.2.0 – 2025-10-27 =

* **NEW**: Added custom avatar upload functionality for review authors
* **NEW**: Custom avatars take precedence over Gravatar images
* **NEW**: Extended conditional display logic to custom avatars
* **NEW**: Separate modular classes for avatar upload and display
* **IMPROVED**: Better code organization with dedicated includes directory
* **IMPROVED**: Enhanced extensibility with new filters and hooks
* Added `wcri_enable_avatar_upload` filter
* Added `wcri_avatar_upload_field_label_text` filter
* Added `wcri_avatar_base_size` filter
* Added `wcri_custom_avatar_html` filter
* Added `wcri_display_avatar_in_meta` filter

= 1.1.1 – 2025-05-22 =

* Fixed critical bug in conditional Gravatar display
* Improved Gravatar detection and caching mechanism
* Fixed HTTP status code validation for Gravatar existence check
* Enhanced error handling for Gravatar display

= 1.1.0 – 2025-05-20 =

* Added granular hooks for customizing review meta output
* New hooks: `woocommerce_review_meta_start`, `woocommerce_review_meta_author`, 
  `woocommerce_review_meta_after_author`, `woocommerce_review_meta_after_verified`,
  and `woocommerce_review_meta_end`
* Improved customization options for review display

= 1.0.3 – 2025-05-20 =

* Added ability to completely disable review images functionality via filter
* Improved documentation for all available filters
* Reorganized filter documentation for better readability

= 1.0.2 – 2025-05-19 =

* Added configurable Gravatar size via filter
* Added option to completely disable Gravatar functionality
* Improved Gravatar handling and documentation
* Updated WordPress and WooCommerce version requirements
* Added proper documentation and filters

= 1.0.1 – 2025-05-19 =

* Added conditional Gravatar loading (only shows for users with custom Gravatars)
* Fixed image display in admin comments list
* Added basic security checks
* Improved code documentation

= 1.0.0 – 2025-05-19 =

* Initial release
* Basic image upload functionality
* Admin interface for managing review images

== Upgrade Notice ==

= 2.0.0 =
The plugin folder, text domain and every filter name changed, and the five
`woocommerce_review_meta_*` actions were deleted. If anything in your theme hooks them — the
myrvann child theme did — that code must move to `woocommerce_review_before` in the same
deploy window, or the byline avatar silently disappears.
