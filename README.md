# Kaupang Review Images

[![Version 2.0.0](https://img.shields.io/badge/Version-2.0.0-brightgreen.svg)](https://github.com/nytafar/kaupang-review-images/releases)
[![WooCommerce 9.0+](https://img.shields.io/badge/WooCommerce-9.0+-a46497.svg)](https://woocommerce.com/)
[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1+-8892BF.svg)](https://php.net/)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.en.html)

Customers attach a product photo and a profile picture to their WooCommerce review; both are
stored as ordinary media-library attachments referenced from comment meta. Conditional Gravatar
loading keeps default mystery-person avatars off the page. A **Curated Review** block lets an
editor place one hand-picked review anywhere on the site.

Part of the Kaupang suite: `Kaupang\ReviewImages\`, `KAUPANG_REVIEW_IMAGES_*`, Composer-less
PSR-4, slash-namespaced `kaupang/review-images/*` seams.

## Features

- **Review image uploads** — one photo per review, 2 MB ceiling
- **Custom avatar uploads** — takes precedence over Gravatar, 1 MB ceiling
- **Conditional Gravatar loading** — a review author with no real Gravatar and no upload gets
  *no* `<img>` at all, rather than the default silhouette
- **Retina avatars** — base size plus a `2x` `srcset`, for both custom uploads and real Gravatars
- **Gravatars served from this site** — downloaded in the background, stored under uploads as
  WebP; a visitor's browser never contacts gravatar.com
- **Long review lists** — every review stays in the HTML; past a cap the rest are hidden behind
  "Vis alle", and a long review is clamped behind "Les hele"
- **Curated Review block** — place one specific review anywhere; the theme owns presentation
- **Admin surface** — an "Image" column on the Comments screen plus review-image and avatar meta
  boxes on the comment-edit screen
- **JPEG, PNG, GIF and WebP**, nonce- and MIME-guarded on upload
- **HPOS-compatible** (declared; the plugin never touches order data)

## Requirements

- WordPress 6.7 or higher
- WooCommerce 9.0 or higher (tested up to 11.0)
- PHP 8.1 or higher

There are no settings, no options, no admin menu and no REST routes. Everything is configured
through the filters below. The one scheduled job is the daily Gravatar refresh
([Gravatars](#gravatars)).

## Installation

1. Upload the `kaupang-review-images` folder to `/wp-content/plugins/`
2. Activate through the Plugins menu
3. The upload fields appear in the product review form automatically

`block/build/` is committed, so deploys are copy-only — no `node` on the server. See
[Building](#building) if you change the editor JS.

## Usage

### For customers

1. Write a product review as usual
2. **Your Photo** (optional) — upload an avatar
3. **Product Image** (optional) — upload a photo of the product
4. Submit. Both appear with the review once it is approved.

### For administrators

Go to **Comments**. The "Image" column shows the review photo; opening a review shows the
"Review Image" and "Review Author Avatar" meta boxes. Uploads are ordinary attachments, so they
are managed and deleted from the Media Library like anything else.

### For editors — the Curated Review block

Editorial, not a feed. An editor picks *one* review and places it: a testimonial on the home
page, a callout in a blog post, a trust signal on a landing page. The same review can appear in
three places looking completely different, because the block decides only **which data elements
are present** and the theme decides how they look.

Insert **Curated Review** (Widgets category), pick a product in the sidebar, then one of its
reviews. On a product page the product is pre-filled — an editor convenience, not a different
default.

The review picker reads `GET /wc/v3/products/reviews`, whose permission is `moderate_comments`.
Administrator, Editor and Shop manager pass; Author and Contributor get a 403 and the picker says
so. The frontend renders for everyone — only *choosing* is gated.

Known cap: the picker requests `per_page=100`, so a product with more than 100 approved reviews
truncates the list.

## The block

### Identity

| | |
|---|---|
| Block name | `kaupang-review-images/curated-review` |
| Render callback | `Kaupang\ReviewImages\Block\CuratedReview::render()` |
| Metadata | `block/block.json` |
| Editor source | `block/src/index.js` → `block/build/` (committed) |
| Kill switch | `kaupang/review-images/enable_block` |

The PHP render callback is the single source of truth for the markup. The editor previews it
through `<ServerSideRender />`, so there is no JS-side copy that can drift.

### Style variations

Four choices in the editor's Styles panel. The block ships a **floor** that applies to all of them,
including the default, plus one selector per layout for a theme to hang chrome on.

| Style | For | What it does |
|---|---|---|
| **Default** | a theme that owns the whole look | the floor, and nothing else |
| **Quote** (`is-style-quote`) | a hero, a cover block, a testimonial band | body leads, attribution collapses to one byline row, a measure caps the line length |
| **Stacked** (`is-style-stacked`) | a page, a post, anywhere ordinary | reading order top to bottom at a readable rhythm — the mode a card is most naturally hung on |
| **Compact** (`is-style-compact`) | a one-third column, a sidebar | byline and rating share the top row, body clamps to four lines |

#### The floor

Not a look — the difference between a review that is *unstyled* and one that is *broken*. It belongs
to every mode, because a default that renders broken is not a default:

- **Stars.** WooCommerce's own `.star-rating` CSS is scoped under `.woocommerce`, and a page or post
  carrying this block is not. Without the floor the rating renders as the literal words "Rated 5 out
  of 5" overlapping themselves.
- **The blockquote.** Browsers indent it 40px. That indent is a presentation decision this block is
  not entitled to make on a theme's behalf.
- **Read more.** A bare `<button>` reads as a form control in the middle of a testimonial.
- **The clamp**, so `expandable` works in every mode rather than only where a variation supplied it.
- A round avatar, and images that stay inside their column.

No colour, no background, no border, no shadow, no font family. That is chrome, and chrome is the
theme's — `myrvann/scss/plugins/_kaupang-review.scss` is where it lands on this site. `color` is
**never** set, not even to `inherit`: a block inherits its surroundings without help, and setting it
once forced a cover block's white text onto a card the theme had just painted cream.

#### Zero specificity

Every selector is wrapped in `:where()`, so the whole stylesheet scores 0. A theme wins on a single
class, always, without depending on which stylesheet loaded first.

One rule is deliberately exempt: the margin reset is `.kaupang-review:is(.is-style-quote, …) > *`,
because its entire job is out-ranking a theme's element-level `article > *` — Ousia's content
constraint, which otherwise auto-margins every part of a flex row into the middle of the card.
`:where()` scores below an element selector, so wrapping that one silently broke it. `:is()` lands
it at two classes: enough to win, low enough that a theme takes it back at the same weight.

#### Slots, not declarations

**The stylesheet declares no custom property.** Each value is read where it is used:

```css
gap: var(--kaupang-review-gap, var(--spacing-2, 1em));
/*        └ this block's override  └ your design system  └ standalone default */
```

So a theme that already has a spacing scale gets a review that matches it with **no theme code at
all**, and a theme that has none still gets a sane block. Declaring a default here would freeze the
value on the style selector and force every theme to out-declare all of them — a wire where a slot
was wanted.

| Slot | Upstream token | Default | Used by |
|---|---|---|---|
| `--kaupang-review-gap` | `--spacing-2` / `--spacing-1` | `1em` / `0.5em` | every layout |
| `--kaupang-review-inline` | — | `34` | Quote |
| `--kaupang-review-avatar-size` | — | `2.5em` | floor |
| `--kaupang-review-body-size` | — | `1.35em` | Quote |
| `--kaupang-review-meta-size` | — | `0.875em` | Quote |
| `--kaupang-review-body-lines` | — | `6` | floor (Compact declares `4`) |
| `--kaupang-review-photo-size` | — | `4.5em` | Compact |

`--kaupang-review-inline` is a **character count**, multiplied by `1em` of the body's own size — the
idiom Ousia uses for every other width (`--inline-width: 42`). Anchoring it to a root font size
instead divides by three the moment a theme scales the quote up, and a display quote comes out eight
characters wide.

The one exception to "declares nothing" is a variation declaring the token that *is* its identity:
Compact sets `--kaupang-review-body-lines`, because a compact layout that does not clamp tighter is
not compact.

The variations never hide anything. Presence is the toggles' job; Compact caps the photo and the
product footnote small rather than removing them, so an editor who leaves them on gets something
proportionate instead of something invisible.

To drop the stylesheet entirely and style the selectors yourself — the variations stay registered
either way:

```php
add_filter( 'kaupang/review-images/enqueue_block_styles', '__return_false' );
```

There are still no inline styles on anything the block authors. The one you will see inside the
rating is WooCommerce's own `width:X%` star encoding from `wc_get_star_rating_html()`, which is
data, not presentation.

> **History.** Spec §2 §I originally said the block ships zero CSS in every configuration. The first
> implementation of these variations read that as a byte count: it scoped the stars, the blockquote
> reset and the clamp to two variations, leaving the *default* — the only mode in real use — as the
> one mode that rendered broken. The theme then grew an always-on card to paper over it, which
> framed every review as a box, including the ones that wanted no box at all. The rule is a
> boundary, not a byte count: **floor in the plugin, chrome in the theme, in every mode.**


### Attributes

All twelve, with the defaults from `block/block.json`:

| Attribute | Type | Default | | Attribute | Type | Default |
|---|---|---|---|---|---|---|
| `reviewId` | number | `0` | | `showAuthor` | boolean | `true` |
| `productId` | number | `0` | | `showAvatar` | boolean | `true` |
| `showBody` | boolean | `true` | | `showVerified` | boolean | **`false`** |
| `showReviewImages` | boolean | **`false`** | | `showDate` | boolean | `true` |
| `showRating` | boolean | **`false`** | | `showProductName` | boolean | `true` |
| `expandable` | boolean | **`false`** | | `showProductImage` | boolean | **`false`** |

Defaults are uniform — they do not vary by context. The render callback reads them back off the
block registry, so `block.json` is the only place they are defined.

### Read more / Les mer

`expandable` shortens a long review to a few lines and adds a button that expands it. It exists
because Quote has no length ceiling of its own: a 1500-character review will happily swallow a
hero.

The order of fallbacks matters more than the feature does:

| | |
|---|---|
| **No JavaScript** | full text, no button, nothing clamped |
| **JavaScript, short review** | full text, no button — the clamp never bit, so offering to expand would be a lie |
| **JavaScript, long review** | clamped text plus a real `<button>` |

PHP renders the whole review and a `hidden` button; `block/view.js` compares `scrollHeight` against
`clientHeight` and only then unhides the button and sets `data-expanded="false"` on the root, which
is what the clamp is keyed on. Nobody is ever left with truncated text and no way to reach the rest.

- The `<button>` is a real button — keyboard-operable, `aria-expanded` flips, `aria-controls` points
  at that instance's `<blockquote>`. Ids come from `wp_unique_id()`, because the same review may
  legitimately appear twice on one page.
- Both labels ship as `data-label-more` / `data-label-less`, translated server-side.
- Collapsing from below the fold scrolls the review back into view, rather than dumping the reader
  wherever the page happened to shorten to.
- The line count is `--kaupang-review-body-lines`, `6` by default and `4` in Compact.
- **Works in every style, including the default.** The clamp is part of the floor, not of a
  variation: an editor who switches a toggle on gets the behaviour the toggle promises, whatever
  style the block is wearing. It used to live in two variations, so turning `expandable` on under
  the default rendered a button that expanded nothing.
- Compact clamps *only* when `expandable` is on. Clamping without a way to expand hides text with no
  route to the rest of it, which is the one thing the variations are not allowed to do.

`block/view.js` is a plain ES module with no build step, registered as `viewScriptModule`, so core
prints it (deferred) only on pages where the block renders. Its module id is
`kaupang-review-images-curated-review-view-script-module`; `block.json` carries a `version` (synced to
the plugin version by the kit hook) as its cache-buster.

### Class contract

`kaupang-review__*`, BEM, vendor spelled out. Document order is fixed — body, images, rating,
attribution, product. Toggles decide *presence*, never position; CSS `order`/grid makes visual
position free. This is the reading order for screen readers and text extraction.

```html
<article class="kaupang-review" data-review-id="482" data-rating="5" data-verified="1" data-has-images="1">
  <blockquote class="kaupang-review__body" cite="https://…/produkt/kakaonibs/">
    <p>…</p>
  </blockquote>

  <figure class="kaupang-review__images">
    <img class="kaupang-review__image" …>
  </figure>

  <p class="kaupang-review__rating">
    <span class="star-rating" role="img" aria-label="Rated 5 out of 5">…</span>
  </p>

  <footer class="kaupang-review__author">
    <img class="kaupang-review__avatar avatar …" …>
    <cite class="kaupang-review__author-name">Kari N.</cite>
    <em class="kaupang-review__verified">(verified owner)</em>
    <time class="kaupang-review__date" datetime="2026-04-12T09:31:00+02:00">12. april 2026</time>
  </footer>

  <div class="kaupang-review__product">
    <a class="kaupang-review__product-link" href="…">
      <img class="kaupang-review__product-image" …>
      <span class="kaupang-review__product-name">…</span>
    </a>
  </div>
</article>
```

Notes on what is actually emitted:

- The root `<article>` carries `get_block_wrapper_attributes()`, so editor-set classes (align,
  spacing, `wp-block-*`) merge with `kaupang-review`.
- `star-rating` is WooCommerce's own class, around `wc_get_star_rating_html()`. It is
  deliberately **not** `wc_get_rating_html()` — the theme filters that one to an empty string
  (`myrvann/inc/woocommerce.php:18`) to kill loop ratings. `role="img"` + `aria-label` sit on the
  span, as in WooCommerce's own review-rating template; there is no second `aria-label` on the
  wrapping `<p>` competing with it.
- The avatar comes back from `get_avatar()`, which runs the full custom-upload → real-Gravatar →
  nothing chain, so it carries the chain's own classes (`avatar avatar-custom wcri-custom-avatar`
  or `wcri-gravatar`) in addition to `kaupang-review__avatar`. When the chain returns nothing, no
  `<img>` is emitted.
- `kaupang-review__product` and its `<a>` only render when the product still exists.
- `(verified owner)` and `Rated %s out of 5` use the `woocommerce` text domain on purpose: they
  are verbatim WooCommerce strings and arrive already translated.

### State attributes

`data-review-id`, `data-rating`, `data-verified`, `data-has-images` render on the root
**regardless of the corresponding `show*` toggle** — a hidden rating still exposes
`data-rating`. The theme can hang the cascade on state it cannot see.

`data-verified` and `data-has-images` are `"1"`/`"0"`. `data-rating` is the raw integer, `"0"`
when the review has no rating.

### Empty state

Missing means any of: `reviewId` 0, comment deleted, unapproved, spam, trashed, or not a review
on a product.

- **Frontend:** the empty string. An empty wrapper is itself a style decision and this block
  ships none.
- **Editor:** a plain `<p class="kaupang-review__notice">` saying why — detected via
  `REST_REQUEST`, because `ServerSideRender` comes through `/wp/v2/block-renderer/`.

### No structured data

No schema.org microdata, no JSON-LD, deliberately. Designation is carried by HTML semantics
instead: `<article>` around a `<blockquote cite>`, `<cite>` for the reviewer, `<time datetime>`
for the date, the rating as real text behind an `aria-label`. The reasoning and its primary
sources are recorded in `docs/suite-seams/kaupang-review-images.md` — read that before adding
schema back.

## Long review lists

Every approved review is rendered on the product page, in full: the review text is SEO content,
and Google reads text hidden behind a toggle but never clicks a "load more". So nothing is paged
and nothing is fetched later; only the display is shortened (`includes/Reviews/ListCap.php`).

### "Les hele" on a long review

- Each review's `.description` is clamped to **8** lines (filter
  `kaupang/review-images/review_clamp_lines`, 0 = off).
- The script measures every review and adds
  `<button class="kaupang-review__more" aria-expanded aria-controls>` after the text **only where
  the clamp actually cuts it**: "Les hele" / "Vis mindre". Opening puts `kaupang-review--expanded`
  on the review `<li>`. Reviews revealed by "Vis alle", a resize and late fonts are measured again.
- The button is placed by the theme. A card that is a CSS grid with explicit rows (myrvann's is)
  must give `.kaupang-review__more` a row, or it auto-places into the first free cell.

### "Vis alle" on a long list

- Past the cap (default **8** top-level reviews, filter `kaupang/review-images/review_list_cap`)
  each review `<li>` gets `kaupang-reviews__overflow`, and an inline rule hides those. A list is
  capped only when at least 3 reviews would be hidden; otherwise it shows whole.
- `assets/js/review-list.js` (handle `kaupang-review-images-list`, deferred) adds
  `<button class="button kaupang-reviews__more" aria-expanded aria-controls>` after the list:
  "Vis alle 37 omtaler" / "Vis færre omtaler". Open puts `kaupang-reviews--open` on the
  `.commentlist` and focus on the first review that was hidden.
- A link to a hidden review (`#comment-123`) opens the list and scrolls to it.
- No scripting, no cap and no clamp: both rules sit in `@media (scripting: enabled)`, and the
  script adds no button where the browser doesn't know that feature.
- Review photos are `loading="lazy"`, so a hidden review's photo isn't fetched until opened.
- Replies travel with their review. Order follows WordPress's Discussion settings (staging:
  newest first).
- Structured data is separate: WooCommerce's Product JSON-LD carries the aggregate rating and the
  five newest rated reviews whatever the list shows.

The rules and the script are functional, not chrome, and ship even when `enqueue_styles` is
off; the buttons' look is the theme's (`.kaupang-reviews__more`, `.kaupang-review__more`).

## Gravatars

A reviewer's Gravatar is downloaded in the background (`includes/Avatars/GravatarCheck.php`),
stored under `uploads/kaupang-review-images/gravatars/`, and served from there. A visitor's
browser never contacts gravatar.com, so no visitor IP address goes to a third party, and the page
carries no hash of the reviewer's email address (a plain md5/sha256 of an address is reversible by
lookup).

- **What renders**: a custom upload, else the local copy, else no `<img>` at all. Never a
  gravatar.com URL, including when a copy is recorded but missing.
- **Which comments**: everything in a product's review list, reviews and the shop's replies to them
  (`Comments::onProductPage()`). Uploads, review images and the block stay review-only.
- **The files**: `{key}-{v}@1x.webp` and `@2x.webp`, at `gravatar_base_size` (120) and twice it.
  `key` is a keyed hash (`wp_salt('auth')`) of the address, `v` a hash of the image, so a changed
  Gravatar gets a new URL. Re-encoded to WebP where the image editor can (a 133 kB PNG became
  5 kB); otherwise kept as served.
- **One copy per address**: a check updates every review and reply by that author email; an old
  copy is deleted once nothing points at it. A review deleted for good takes its copy along unless
  another still uses it. A removed Gravatar deletes the copy.
- **When**: approve, submit-approved or edit → one async Action Scheduler action
  (`kaupang_review_images_check_gravatar`, unique while pending; inline without Action
  Scheduler). Daily (`kaupang_review_images_refresh_gravatars`, recurring, group
  `kaupang-review-images`) → queues up to 200 never checked or checked over 30 days ago; that is
  also the backfill after deploy. Ensured on `action_scheduler_ensure_recurring_actions` and on
  activation, unscheduled on deactivation.
- **Now**: `wp kaupang-review-images gravatars [--all]` downloads every due copy synchronously.
  **Run it after deploying** this version to a site.
- A network error or a failed write changes nothing; the daily job asks again.

## Filters

Every filter this plugin defines, with the default it actually applies. All are slash-namespaced
`kaupang/review-images/*`.

| Filter | Args | Default | Applied in |
|---|---|---|---|
| `kaupang/review-images/enable_review_images` | `bool $enabled` | `true` | `includes/Plugin.php` |
| `kaupang/review-images/enable_avatar_upload` | `bool $enabled` | `true` | `includes/Plugin.php`, `includes/Reviews/Images.php` |
| `kaupang/review-images/enable_avatar_display` | `bool $enabled` | `true` | `includes/Plugin.php` |
| `kaupang/review-images/enable_conditional_gravatars` | `bool $enabled` | `true` | `includes/Plugin.php`, `includes/Avatars/Display.php`, `includes/Avatars/Gravatar.php` |
| `kaupang/review-images/enable_block` | `bool $enabled` | `true` | `includes/Plugin.php` |
| `kaupang/review-images/enqueue_styles` | `bool $enabled` | `true` | `includes/Reviews/Images.php` |
| `kaupang/review-images/enqueue_block_styles` | `bool $enabled` | `true` | `includes/Plugin.php` |
| `kaupang/review-images/upload_field_label` | `string $label`, `WP_Post $product` | `'Product Image'` | `includes/Reviews/Images.php` |
| `kaupang/review-images/avatar_upload_field_label` | `string $label`, `WP_Post $product` | `'Your Photo'` | `includes/Reviews/Images.php` |
| `kaupang/review-images/avatar_base_size` | `int $px` | `120` | `includes/Avatars/Display.php`, `includes/Block/CuratedReview.php` |
| `kaupang/review-images/gravatar_base_size` | `int $px` | `120` (the local copy is this and 2×) | `includes/Avatars/Gravatar.php` |
| `kaupang/review-images/review_list_cap` | `int $cap`, `WC_Product $product` | `8` (0 = off) | `includes/Reviews/ListCap.php` |
| `kaupang/review-images/review_clamp_lines` | `int $lines`, `WC_Product $product` | `8` (0 = off) | `includes/Reviews/ListCap.php` |
| `kaupang/review-images/custom_avatar_html` | `string $html`, `int $commentId`, `int $avatarId`, `int\|string $size` | the built `<img>` | `includes/Avatars/Display.php` |

### When the gates are evaluated

The four `enable_*` module gates in `Plugin::boot()` run at **plugin-load time**, so only an
mu-plugin or an earlier-loading plugin can flip them — a theme's `functions.php` runs too late.
That timing is inherited from before the Kaupang conversion, not new.

`enable_block` and `enqueue_block_styles` are different: both are applied inside the `init`
callback, so a theme *can* flip them.
`enable_avatar_upload` (in the form renderer), `enable_conditional_gravatars` (in the two
`get_avatar` filters) and `enqueue_styles` are all re-evaluated at render time and are themeable
too.

### Examples

```php
// Take the block out of the inserter entirely.
add_filter('kaupang/review-images/enable_block', '__return_false');

// Serve 96px avatars (and 192px for retina).
add_filter('kaupang/review-images/avatar_base_size', fn() => 96);

// Same for real Gravatars.
add_filter('kaupang/review-images/gravatar_base_size', fn() => 96);

// Show default Gravatars again, silhouettes and all.
add_filter('kaupang/review-images/enable_conditional_gravatars', '__return_false');

// Theme owns the upload-form CSS.
add_filter('kaupang/review-images/enqueue_styles', '__return_false');

// Product-specific field label.
add_filter('kaupang/review-images/upload_field_label', function ($label, $product) {
    return sprintf(__('Upload a photo of your %s', 'my-theme'), get_the_title($product));
}, 10, 2);

// Wrap the custom avatar.
add_filter('kaupang/review-images/custom_avatar_html', function ($html, $commentId, $avatarId, $size) {
    return '<span class="my-avatar-ring">' . $html . '</span>';
}, 10, 4);
```

## PHP API

Public statics worth knowing about; everything else is internal.

| Call | Returns |
|---|---|
| `Reviews\Images::getImageIds(int $commentId)` | `int[]` — attachment ids of the review's images. Zero or one element today; an array from day one so multi-image upload later is a storage change only. |
| `Avatars\Upload::getCommentAvatarId(int $commentId)` | `int\|false` |
| `Avatars\Upload::hasCustomAvatar(int\|WP_Comment $comment)` | `bool` |
| `Avatars\Upload::getCommentAvatarUrl(int $commentId, $size)` | `string\|false` |
| `Avatars\Display::getCustomAvatarHtml(int $commentId, $size, string $alt)` | `string` — the custom upload only, *not* the Gravatar fallback. Use `get_avatar()` if you want the whole chain. |
| `Avatars\Display::getAvatarUrl($comment, $size)` | `string\|false` — custom upload, else the local Gravatar copy. Never a gravatar.com URL. |
| `Avatars\Gravatar::hasGravatar(WP_Comment $comment)` | `bool` — is there a local copy; never a remote call. |
| `Avatars\Gravatar::localUrls(WP_Comment $comment)` | `?array` — `[1x URL, 2x URL]` of the local copy. |
| `Avatars\Gravatar::fetch(string $email)` | `array\|false\|null` — download both sizes; `false` = no Gravatar, `null` = unanswered. Background only. |
| `Avatars\GravatarCheck::check(int $commentId)` | `?bool` — download and store the copy for that comment's author, applied to all their reviews. |
| `Support\Comments::asProductReview(WP_Comment $comment)` | `?WP_Comment` — the "is this a product review" gate. |

## Data storage

No custom tables. Comment meta, ordinary attachments, and the Gravatar copies under
`uploads/kaupang-review-images/gravatars/`.

| Key | Holds |
|---|---|
| `_review_image_id` (comment meta) | attachment id of the review image |
| `_review_author_avatar_id` (comment meta) | attachment id of the custom avatar |
| `_kaupang_review_images_gravatar` (comment meta) | `'yes'`/`'no'`: does the author have a real Gravatar |
| `_kaupang_review_images_gravatar_file` (comment meta) | the local copy, `{key}-{v}.webp` |
| `_kaupang_review_images_gravatar_checked` (comment meta) | unix time of that answer |
| `kaupang_review_images_has_gravatar_{md5(email)}` (transient) | legacy (≤ 2.0.0), no longer read; removed by uninstall |

`uninstall.php` deletes the Gravatar meta and copies, the legacy transients and the two jobs. Review photos
and avatars are customer content and stay.

Uploads are parented to the product via `media_handle_upload()`; avatar attachments also get
`_wp_attachment_image_alt`. Meta is written in `comment_post` **only if the comment is approved**.

## Load map

What a request loads from this plugin (`tools/load-budget.php`, measured with
`wp kaupang-assets audit --plugins=kaupang-review-images --budget`). Every hook is registered in
`Plugin::boot()` with a class-string callable, so a module loads only when its hook fires.

| Context / page type | Modules (beyond main file + `Plugin`) | Assets | Autoloaded options |
|---|---|---|---|
| Guest page with no review and no block (shop, category, cart, checkout, account, …) | none (2 files) | none | none |
| Product page, reviews open | `Reviews/Images` (styles, toggle, upload fields), `Support/Media` (asset versions), the nb_NO catalogue (form strings); per shown review `Avatars/Display`, `Avatars/Upload`, `Avatars/Gravatar`, `Support/Comments` (avatar chain); `Reviews/ListCap` (decides the cap). Budget 10. | `kaupang-review-images` (CSS), `kaupang-review-images-toggle` (footer JS); the enctype inline script only once the upload fields have rendered; on a product with reviews `kaupang-review-images-list` (inline cap/clamp rules + deferred JS) | none |
| Approve / edit a review, Action Scheduler run, WP-CLI | `Avatars/GravatarCheck` on its hooks | — | — |
| Page with the Curated Review block | `Block/CuratedReview`, `Support/Comments`, `Reviews/Images` (image ids), the catalogue ("Read more", dates); with `showAvatar` also the three `Avatars/*`. Budget 9. | block `style` (core inlines it) and the `…-view-script-module` module, both enqueued by core on render | none |
| `wp-comments-post.php` (review submit) | `Reviews/Images`, `Avatars/Upload`, `Support/Media` on `preprocess_comment` / `comment_post` | — | — |
| REST (`/wp/v2/block-renderer` preview) | `Block/CuratedReview` and its helpers on render | — | — |
| wp-admin comments screens | `Reviews/Images`, `Avatars/Upload` on the column and meta-box hooks (also in admin-ajax for comment replies) | none | — |
| Block editor (wp-admin) | block registered with `editorScript` (`build/index.asset.php`) and translated title | `editorScript` via block.json only | — |

Outside wp-admin the block registers without `editorScript` and without `textdomain`
(`Plugin::blockMetadata`), so ordinary pages neither read `build/index.asset.php` nor load the
catalogue to translate a title nobody sees there.

## Building

The editor JS is the only thing that needs a build. `block/build/` is **committed** so deploys
stay copy-only.

```bash
npm ci
npm run build      # or: npm start  for a watch build
```

Known quirk on this server: `npm` can leave the symlinks in `node_modules/.bin` without the
executable bit, and the first `npm run build` then dies with `wp-scripts: Permission denied`.
Fix after a fresh install:

```bash
find node_modules/.bin -maxdepth 1 -type l -exec chmod +x {} \;
```

`node_modules/` is gitignored. `languages/*.l10n.php` files are committed; WP 6.5+
prefers them over the `.mo` and an untracked stale cache silently shadows updated translations.

## Verification

Half this plugin had nothing to render against — staging carries 143 reviews and zero
`_review_image_id`, zero `_review_author_avatar_id` — so there are two PHP-native scripts in
`bin/`. Run them as the site user, never as root.

```bash
# Seed one approved review with a rating, the verified flag, a review image and a custom avatar.
# Idempotent; `clean` removes the review and both attachments again.
sudo -u myrvann wp --path=/var/www/staging.myrvann.no/htdocs \
  eval-file wp-content/plugins/kaupang-review-images/bin/seed-review-fixtures.php
sudo -u myrvann wp --path=/var/www/staging.myrvann.no/htdocs \
  eval-file wp-content/plugins/kaupang-review-images/bin/seed-review-fixtures.php clean

# The runnable check: asserts the load map (boot loads two files, every hook registered,
# block assets on render only, no admin assets on a foreign screen), then seeds its own
# fixture, asserts the block, the avatar chain, the Gravatar cache and the list cap, trashes it
# in a finally block, and exits non-zero on failure.
sudo -u myrvann wp --path=/var/www/staging.myrvann.no/htdocs \
  eval-file wp-content/plugins/kaupang-review-images/bin/check-review-block.php
```

No PHPUnit, no fixtures framework — the smallest thing that goes red if the block, the
`getImageIds()` accessor or the avatar chain breaks. The Gravatar half is made deterministic by
pre-seeding the comment meta `Gravatar::hasGravatar()` reads and stubbing `pre_http_request`, so
it needs no network.

Neither script declares `strict_types`: `wp eval-file` evals the source, and a `declare` must be
the first statement of a script. Both say so in a comment — do not "fix" it back.

## Changelog

### 2.0.0 - 2026-09-01

Two breaking changes — the rename and the hook removal — hence the major bump.

- **BREAKING**: renamed `woocommerce-review-images` → `kaupang-review-images`. Folder, entry
  file, text domain, GitHub repo. Every `wcri_*` filter is now `kaupang/review-images/*`.
- **BREAKING**: the five `woocommerce_review_meta_{start,author,after_author,after_verified,end}`
  actions **no longer exist**. `custom-review-meta.php` replaced WooCommerce's
  `woocommerce_review_display_meta` renderer wholesale just to emit them, and the only consumer
  anywhere was the myrvann theme moving a gravatar. WooCommerce's own renderer runs again and the
  avatar returns to `woocommerce_review_before`. They are not renamed, deprecated or shimmed:
  no third party fires or consumes them, and impersonating a core hook only misleads whoever
  greps WooCommerce for it. `kaupang/review-images/display_avatar_in_meta` and
  `WC_Review_Images_Avatar_Display::display_avatar_in_review_meta()` go with them.
- **NEW**: the **Curated Review** block (`kaupang-review-images/curated-review`) — one
  hand-picked review, nine toggles, the `kaupang-review__*` class contract, a zero-specificity
  floor, and three style variations for a theme to hang chrome on.
  Kill switch: `kaupang/review-images/enable_block`.
- **FIXED**: a review whose author has **no email address** rendered the default mystery-person.
  The conditional-Gravatar branch only suppressed when an address was present and had no
  Gravatar — but no address means no Gravatar can exist, so that was the case most in need of
  suppressing.
- **FIXED**: the avatar gate asked the wrong question. Both `get_avatar` filters early-returned
  unless `is_product()`, so the custom-upload → real-Gravatar → nothing chain never ran off a
  product page — exactly where the default silhouette this plugin exists to suppress would show.
  The gate now tests whether the *comment* is a product review (`Support\Comments`). A latent bug
  went with it: a numeric `$id_or_email` in `get_avatar()` is a *user* id, and the old code read
  it as a comment id.
- **CHANGED**: Composer-less PSR-4 (`Kaupang\ReviewImages\` → `includes/`), `strict_types=1`,
  `KAUPANG_REVIEW_IMAGES_{VERSION,FILE,DIR,URL}`, boot wiring in `includes/Plugin.php`.
- **CHANGED**: requirements raised to PHP 8.1 / WordPress 6.7 / WooCommerce 9.0.
- **NEW**: `Reviews\Images::getImageIds()` accessor; every `_review_image_id` call site routes
  through it and loop, so multi-image support is a storage change away.
- **NEW**: `Support\Media::ingest()` — one shared size → MIME → `media_handle_upload()` path
  replacing the two near-identical upload handlers, and one copy of the MIME allowlist.
- **CHANGED**: asset versions come from `filemtime()` instead of a frozen `'1.2.1'` literal that
  had already drifted from the header.
- **REMOVED**: the ~30-line bespoke `.mo` candidate-probing loader, the dead
  `display_avatar_upload_field()`, and the `WP_DEBUG` footer probe.
- **DOCS**: two defaults were documented wrongly before this release —
  `display_avatar_in_meta` was documented `true` but was always `false` (moot: it is deleted),
  and `gravatar_base_size` was documented `200` but has always been `120`.

### 1.2.0 - 2025-10-27
- **NEW**: Added custom avatar upload functionality for review authors
- **NEW**: Custom avatars take precedence over Gravatar images
- **NEW**: Extended conditional display logic to custom avatars
- **NEW**: Separate modular classes for avatar upload and display
- **IMPROVED**: Better code organization with dedicated includes directory
- **IMPROVED**: Enhanced extensibility with new filters and hooks
- Added `wcri_enable_avatar_upload` filter
- Added `wcri_avatar_upload_field_label_text` filter
- Added `wcri_avatar_base_size` filter
- Added `wcri_custom_avatar_html` filter
- Added `wcri_display_avatar_in_meta` filter

### 1.1.1 - 2025-05-22
- Fixed critical bug in conditional Gravatar display
- Improved Gravatar detection and caching mechanism
- Fixed HTTP status code validation for Gravatar existence check
- Enhanced error handling for Gravatar display

### 1.1.0 - 2025-05-20
- Added granular hooks for customizing review meta output
- New hooks: `woocommerce_review_meta_start`, `woocommerce_review_meta_author`, 
  `woocommerce_review_meta_after_author`, `woocommerce_review_meta_after_verified`,
  and `woocommerce_review_meta_end`
- Improved customization options for review display

### 1.0.3 - 2025-05-20
- Added ability to completely disable review images functionality via filter
- Improved documentation for all available filters
- Reorganized filter documentation for better readability

### 1.0.2 - 2025-05-19
- Added configurable Gravatar size via filter
- Added option to completely disable Gravatar functionality
- Improved Gravatar handling and documentation
- Updated WordPress and WooCommerce version requirements
- Added proper documentation and filters

### 1.0.1 - 2025-05-19
- Added conditional Gravatar loading (only shows for users with custom Gravatars)
- Fixed image display in admin comments list
- Added basic security checks
- Improved code documentation

### 1.0.0 - 2025-05-18
- Initial release
- Basic image upload functionality
- Admin interface for managing review images

> Changelog entries below 2.0.0 are history. The hooks and `wcri_*` filter names they mention no
> longer exist — the current API is the filter table above.

## Upgrade notice

### 2.0.0
The plugin folder, text domain and every filter name changed, and the five
`woocommerce_review_meta_*` actions were deleted. If anything in your theme hooks them — the
myrvann child theme did — that code must move to `woocommerce_review_before` in the same
deploy window, or the byline avatar silently disappears.

## Support

For support, please [open an issue](https://github.com/nytafar/kaupang-review-images/issues) on GitHub.

## License

GPL-2.0-or-later

## Credits

Created by [Lasse Jellum](https://jellum.net)
