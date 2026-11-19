<?php
/**
 * Runnable check for the Curated Review block. Exits non-zero on failure.
 *
 *   sudo -u myrvann wp --path=/var/www/staging.myrvann.no/htdocs \
 *     eval-file wp-content/plugins/kaupang-review-images/bin/check-review-block.php
 *
 * Seeds its own fixture, asserts, and trashes it again -- including when an
 * assertion fails, so a red run does not leave a review behind. No PHPUnit, no
 * fixtures framework: this is the smallest thing that goes red if the block,
 * the accessor or the avatar chain breaks.
 *
 * The Gravatar half of the avatar chain is made deterministic by pre-seeding
 * the comment meta Gravatar::hasGravatar() reads, and every lookup is answered
 * by a pre_http_request stub, so the check never depends on a network round
 * trip to gravatar.com.
 *
 * @package KaupangReviewImages
 */

// No declare(strict_types=1): `wp eval-file` evals the source, and a declare
// must be the first statement of a script.

// wp-fail2ban's syslog writer reads $_SERVER['HTTP_HOST'] unguarded and fatals
// under WP-CLI without it. Not our bug, but it takes the whole check down.
// ponytail: drop this line if wp-fail2ban ever guards its own getHost().
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? (string) wp_parse_url(home_url(), PHP_URL_HOST);

// What a fully booted request (plugins_loaded and init done, nothing rendered) has loaded
// from this plugin. Taken before anything below touches a module class.
$GLOBALS['kri_boot_files'] = array_values(array_map(
    static fn(string $f): string => substr($f, strlen(KAUPANG_REVIEW_IMAGES_DIR)),
    array_filter(get_included_files(), static fn(string $f): bool => strpos($f, KAUPANG_REVIEW_IMAGES_DIR) === 0)
));

define('KRI_FIXTURES_LIB', true);
require_once __DIR__ . '/seed-review-fixtures.php';

use Kaupang\ReviewImages\Avatars\Display;
use Kaupang\ReviewImages\Avatars\Gravatar;
use Kaupang\ReviewImages\Avatars\GravatarCheck;
use Kaupang\ReviewImages\Avatars\Upload;
use Kaupang\ReviewImages\Block\CuratedReview;
use Kaupang\ReviewImages\Plugin;
use Kaupang\ReviewImages\Reviews\Images;
use Kaupang\ReviewImages\Reviews\ListCap;

// $GLOBALS, not `global`: `wp eval-file` evals this inside a method, so the
// top-level scope here is function scope and `global` would bind elsewhere.
// That silently made every failure invisible and the exit code always 0.
$GLOBALS['kri_failures'] = [];
$GLOBALS['kri_checks']   = 0;

function kri_check(string $name, bool $ok, string $detail = ''): void
{
    $GLOBALS['kri_checks']++;

    if ($ok) {
        echo "  ok   $name\n";

        return;
    }

    echo "  FAIL $name" . ($detail !== '' ? " -- $detail" : '') . "\n";
    $GLOBALS['kri_failures'][] = $name;
}

/** A 1×1 PNG: what the stubbed gravatar.com returns. */
function kri_png(): string
{
    return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
}

/** Seed the answer GravatarCheck would store: with $exists, a real local copy. */
function kri_gravatar_cache(int $commentId, bool $exists): void
{
    $file = $exists ? Gravatar::store('kri-' . $commentId . '@kaupang-review-images.invalid', ['ext' => 'png', '1x' => kri_png(), '2x' => kri_png()]) : '';
    update_comment_meta($commentId, Gravatar::META_HAS, $exists ? 'yes' : 'no');
    update_comment_meta($commentId, Gravatar::META_CHECKED, time());
    $file ? update_comment_meta($commentId, Gravatar::META_FILE, $file) : delete_comment_meta($commentId, Gravatar::META_FILE);
}

/** Absolute paths of a META_FILE value's two copies. */
function kri_gravatar_paths(string $file): array
{
    [$stem, $ext] = explode('.', $file, 2);

    return [Gravatar::base('dir') . "/{$stem}@1x.{$ext}", Gravatar::base('dir') . "/{$stem}@2x.{$ext}"];
}

/**
 * Answer every outgoing HTTP request with $status (or a WP_Error for null); count those to gravatar.com.
 *
 * @return callable the filter, to remove again
 */
function kri_stub_http(?int $status): callable
{
    $GLOBALS['kri_http_calls'] = 0;
    $stub = static function ($pre, $args, $url) use ($status) {
        // Other plugins make requests of their own when a comment is inserted; count only ours.
        if (strpos((string) $url, 'gravatar.com') !== false) {
            $GLOBALS['kri_http_calls']++;
        }

        return $status === null
            ? new WP_Error('kri_offline', 'stubbed')
            : [
                'headers'  => $status === 200 ? ['content-type' => 'image/png'] : [],
                'body'     => $status === 200 ? kri_png() : '',
                'response' => ['code' => $status, 'message' => ''],
                'cookies'  => [],
            ];
    };
    add_filter('pre_http_request', $stub, 10, 3);

    return $stub;
}

/** Every toggle on, so nothing is hidden from the assertions. */
function kri_all_on(int $reviewId): array
{
    return [
        'reviewId'         => $reviewId,
        'showBody'         => true,
        'showReviewImages' => true,
        'showRating'       => true,
        'showAuthor'       => true,
        'showAvatar'       => true,
        'showVerified'     => true,
        'showDate'         => true,
        'showProductName'  => true,
        'showProductImage' => true,
    ];
}

function kri_all_off(int $reviewId): array
{
    return array_merge(kri_all_on($reviewId), array_fill_keys([
        'showBody',
        'showReviewImages',
        'showRating',
        'showAuthor',
        'showAvatar',
        'showVerified',
        'showDate',
        'showProductName',
        'showProductImage',
    ], false));
}

// --- loading (README "Load map") -------------------------------------------
echo "loading\n";
kri_check(
    'boot loads only the main file and Plugin',
    $GLOBALS['kri_boot_files'] === ['kaupang-review-images.php', 'includes/Plugin.php'],
    implode(', ', $GLOBALS['kri_boot_files'])
);

// Every hook that can fire outside a page view (wp-comments-post.php, REST comment
// creation, admin) is registered in this CLI request too, with a class-string callable.
$kriHooks = [
    ['preprocess_comment', [Images::class, 'handleImageUpload'], 10],
    ['comment_post', [Images::class, 'saveImageMeta'], 10],
    ['preprocess_comment', [Upload::class, 'handleAvatarUpload'], 10],
    ['comment_post', [Upload::class, 'saveAvatarMeta'], 10],
    ['woocommerce_review_before', [Images::class, 'displayReviewImage'], 10],
    ['comment_form_logged_in_after', [Images::class, 'displayUploadFieldAndNonce'], 10],
    ['comment_form_after_fields', [Images::class, 'displayUploadFieldAndNonce'], 10],
    ['get_avatar', [Display::class, 'displayCustomAvatar'], 5],
    ['get_avatar', [Gravatar::class, 'filterWoocommerceGravatar'], 10],
    ['comment_post', [GravatarCheck::class, 'onCommentPost'], 10],
    ['transition_comment_status', [GravatarCheck::class, 'onStatusChange'], 10],
    ['edit_comment', [GravatarCheck::class, 'onEdit'], 10],
    ['delete_comment', [GravatarCheck::class, 'onDelete'], 10],
    [GravatarCheck::HOOK_CHECK, [GravatarCheck::class, 'check'], 10],
    [GravatarCheck::HOOK_REFRESH, [GravatarCheck::class, 'refresh'], 10],
    ['action_scheduler_ensure_recurring_actions', [GravatarCheck::class, 'ensureSchedule'], 10],
];
foreach ($kriHooks as [$hook, $cb, $prio]) {
    kri_check("$hook → {$cb[0]}::{$cb[1]} at $prio", has_filter($hook, $cb) === $prio && is_callable($cb));
}

// The upload handlers run as static callables and pass non-review data through untouched.
$kriData = ['comment_post_ID' => 0, 'comment_author' => 'x'];
kri_check('image upload handler passes non-product comments through', Images::handleImageUpload($kriData) === $kriData);
kri_check('avatar upload handler passes non-product comments through', Upload::handleAvatarUpload($kriData) === $kriData);

kri_check('no enctype footer script before a review form renders', has_action('wp_footer', [Images::class, 'ensureFormEnctypeScript']) === false);

$kriType = WP_Block_Type_Registry::get_instance()->get_registered(Plugin::BLOCK_NAME);
kri_check('block registered with its render callback', $kriType && $kriType->render_callback === [CuratedReview::class, 'render']);
kri_check(
    'view script is a module core enqueues on render',
    $kriType && $kriType->view_script_module_ids === ['kaupang-review-images-curated-review-view-script-module'] && $kriType->view_script_handles === []
);
kri_check('style is registered for core to enqueue on render', $kriType && $kriType->style_handles === ['kaupang-review-images-curated-review-style']);
kri_check('no editor script outside wp-admin', $kriType && $kriType->editor_script_handles === []);

// In wp-admin the editor gets its script and the translated title. is_admin() follows
// the current screen; CLI has none, so lend it one and take it back.
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
$kriMeta = wp_json_file_decode(KAUPANG_REVIEW_IMAGES_DIR . 'block/block.json', ['associative' => true]);
kri_check('front end drops editorScript and textdomain', !isset(Plugin::blockMetadata($kriMeta)['editorScript']) && !isset(Plugin::blockMetadata($kriMeta)['textdomain']));
set_current_screen('post');
$kriAdmin = Plugin::blockMetadata($kriMeta);
kri_check('wp-admin keeps editorScript and textdomain', isset($kriAdmin['editorScript'], $kriAdmin['textdomain']));

// Pattern E: on a foreign admin screen none of this plugin's handles is queued.
$kriBefore = array_merge(wp_scripts()->queue, wp_styles()->queue);
foreach ($GLOBALS['wp_filter']['admin_enqueue_scripts']->callbacks ?? [] as $kriCbs) {
    foreach ($kriCbs as $kriCb) {
        $fn = $kriCb['function'];
        if (is_array($fn) && is_string($fn[0]) && strpos($fn[0], 'Kaupang\\ReviewImages\\') === 0) {
            call_user_func($fn, 'index.php');
        }
    }
}
$kriLeaked = array_filter(
    array_diff(array_merge(wp_scripts()->queue, wp_styles()->queue), $kriBefore),
    static fn(string $h): bool => strpos($h, 'kaupang-review-images') === 0
);
kri_check('no admin assets on a foreign screen', $kriLeaked === [], implode(', ', $kriLeaked));
$GLOBALS['current_screen'] = null;

$fixture = kri_fixture_create();
$id      = $fixture['comment_id'];

try {
    echo "fixture: review {$id} on product {$fixture['product_id']}\n";

    // --- accessor -----------------------------------------------------------
    kri_check(
        'accessor returns [id] for a review with an image',
        Images::getImageIds($id) === [$fixture['image_id']],
        wp_json_encode(Images::getImageIds($id))
    );

    $bare = wp_insert_comment([
        'comment_post_ID'  => $fixture['product_id'],
        'comment_author'       => 'Kaupang Fixture (no image)',
        'comment_author_email' => 'no-avatar@kaupang-review-images.invalid',
        'comment_type'         => 'review',
        'comment_approved' => 1,
        'comment_content'  => 'Fixture without an image.',
    ]);
    $bare = (int) $bare;
    update_comment_meta($bare, KRI_FIXTURE_META, 1);

    kri_check('accessor returns [] for a review with no image', Images::getImageIds($bare) === []);

    // --- render: happy path -------------------------------------------------
    kri_gravatar_cache($id, true);
    $html = CuratedReview::render(kri_all_on($id));

    // Assert against the OPENING <article> TAG, not the whole document: a
    // substring search for class="kaupang-review passes on any child element,
    // and state attributes would pass wherever they sat.
    preg_match('#^<article\b[^>]*>#', $html, $rootMatch);
    $root = $rootMatch[0] ?? '';

    kri_check('root element is <article> with the block class', (bool) preg_match('#\bclass="[^"]*\bkaupang-review\b#', $root), $root);
    kri_check('root carries data-review-id', strpos($root, 'data-review-id="' . $id . '"') !== false, $root);
    kri_check('root carries data-rating', strpos($root, 'data-rating="5"') !== false, $root);
    kri_check('root carries data-verified', strpos($root, 'data-verified="1"') !== false, $root);
    kri_check('root carries data-has-images', strpos($root, 'data-has-images="1"') !== false, $root);
    kri_check('review image renders', strpos($html, 'kaupang-review__image') !== false);
    kri_check('rating renders WooCommerce star markup', strpos($html, 'class="star-rating"') !== false);

    // Document order is part of the contract: body, images, rating, author,
    // product. Toggles decide presence, never position.
    $order = [];
    foreach (['kaupang-review__body', 'kaupang-review__images', 'kaupang-review__rating', 'kaupang-review__author', 'kaupang-review__product'] as $class) {
        $order[$class] = strpos($html, $class);
    }
    // strpos() returns false for an absent element, and false sorts before every
    // integer -- so a missing section used to make this assertion vacuously
    // true. Every section must be present AND in order.
    $missing   = array_keys($order, false, true);
    $positions = array_values($order);
    $sorted    = $positions;
    sort($sorted);
    kri_check(
        'document order is body, images, rating, author, product',
        !$missing && $positions === $sorted,
        $missing ? 'missing: ' . implode(', ', $missing) : wp_json_encode($order)
    );

    // --- read more ----------------------------------------------------------
    $plain = CuratedReview::render(kri_all_on($id));
    kri_check('no expand button unless asked for', strpos($plain, 'kaupang-review__more') === false);
    kri_check('no data-expandable unless asked for', strpos($plain, 'data-expandable') === false);

    $expand = CuratedReview::render(array_merge(kri_all_on($id), ['expandable' => true]));
    kri_check('expandable marks the root', strpos($expand, 'data-expandable') !== false);
    kri_check('expand button ships hidden', (bool) preg_match('#<button[^>]*kaupang-review__more[^>]*\bhidden\b#', $expand), $expand);
    kri_check('expand button starts collapsed', strpos($expand, 'aria-expanded="false"') !== false);

    // aria-controls must point at THIS instance's body, and two instances on one
    // page must not collide -- the same review can legitimately appear twice.
    preg_match('#<blockquote class="kaupang-review__body" id="([^"]+)"#', $expand, $bodyIdMatch);
    preg_match('#<button[^>]*aria-controls="([^"]+)"#', $expand, $controlsMatch);
    kri_check(
        'aria-controls points at this instance\'s body',
        !empty($bodyIdMatch[1]) && ($bodyIdMatch[1] ?? null) === ($controlsMatch[1] ?? null),
        wp_json_encode([$bodyIdMatch[1] ?? null, $controlsMatch[1] ?? null])
    );

    $second = CuratedReview::render(array_merge(kri_all_on($id), ['expandable' => true]));
    preg_match('#<blockquote class="kaupang-review__body" id="([^"]+)"#', $second, $secondIdMatch);
    kri_check(
        'a second instance of the same review gets its own id',
        !empty($secondIdMatch[1]) && $secondIdMatch[1] !== ($bodyIdMatch[1] ?? null),
        wp_json_encode([$bodyIdMatch[1] ?? null, $secondIdMatch[1] ?? null])
    );

    kri_check(
        'both label states travel with the button',
        strpos($expand, 'data-label-more=') !== false && strpos($expand, 'data-label-less=') !== false
    );

    kri_check(
        'expandable is inert when the body is hidden',
        strpos(CuratedReview::render(array_merge(kri_all_on($id), ['expandable' => true, 'showBody' => false])), 'kaupang-review__more') === false
    );

    // --- render: empty states ----------------------------------------------
    kri_check('deleted id renders empty on the frontend', CuratedReview::render(['reviewId' => 999999999]) === '');
    kri_check('id 0 renders empty on the frontend', CuratedReview::render(['reviewId' => 0]) === '');

    // Created unapproved rather than transitioned: wp_set_comment_status() fires
    // the post-author notification chain, which is what drags wp-fail2ban in.
    $unapproved = (int) wp_insert_comment([
        'comment_post_ID'  => $fixture['product_id'],
        'comment_author'   => 'Kaupang Fixture (unapproved)',
        'comment_type'     => 'review',
        'comment_approved' => 0,
        'comment_content'  => 'Fixture awaiting moderation.',
    ]);
    update_comment_meta($unapproved, KRI_FIXTURE_META, 1);
    kri_check('unapproved review renders empty', CuratedReview::render(kri_all_on($unapproved)) === '');

    // --- render: all toggles off -------------------------------------------
    $off = CuratedReview::render(kri_all_off($id));
    preg_match('#^<article\b[^>]*>#', $off, $offRoot);
    kri_check('all toggles off keeps the state attributes', strpos($offRoot[0] ?? '', 'data-rating="5"') !== false, $off);
    kri_check(
        'all toggles off emits no children',
        (bool) preg_match('#^<article [^>]*></article>$#', trim($off)),
        trim($off)
    );

    // --- no structured data, no inline style we authored ---------------------
    kri_check('no itemprop', stripos($html, 'itemprop') === false);
    kri_check('no itemscope', stripos($html, 'itemscope') === false);
    kri_check('no JSON-LD', stripos($html, 'application/ld') === false);

    // WooCommerce's own star span carries style="width:X%" -- that is its data
    // encoding of the rating, not markup this block authored. Everything
    // outside it must be style-free.
    $outsideStars = (string) preg_replace('#<span class="star-rating".*?</span></span>#s', '', $html);
    kri_check('no inline style outside WooCommerce\'s star span', stripos($outsideStars, 'style=') === false, $outsideStars);

    // --- the stylesheet keeps its side of the split -------------------------
    // Structure here, chrome in the theme. Three rules, each one a promise a
    // theme is entitled to rely on, and each one a thing a hurried edit breaks
    // without any test noticing.
    $css = (string) file_get_contents(KAUPANG_REVIEW_IMAGES_DIR . 'block/build/style-index.css');

    // 1. Zero specificity throughout: every selector wrapped in :where(), so a
    //    theme always wins on a single class regardless of load order. The lone
    //    exception is the margin reset, whose job IS to out-rank a theme's
    //    element-level `article > *` -- see the comment on it in style.scss.
    preg_match_all('#(^|\})\s*([^{}@]+)\{#', $css, $selectors);
    $specific = array_values(array_filter(
        array_map('trim', $selectors[2] ?? []),
        static fn($sel) => $sel !== ''
            && strpos($sel, ':where') === false
            && strpos($sel, '.kaupang-review:is(') !== 0
    ));
    kri_check('every selector is zero-specificity', $specific === [], implode(' | ', $specific));

    // 2. No custom property declared, with one exception: Compact states the
    //    line count that IS its identity. Everything else is a slot, read as
    //    var(--slot, var(--upstream, literal)) so a theme's scale wins untouched.
    preg_match_all('#(--kaupang-review-[a-z-]+)\s*:#', $css, $declared);
    $unexpected = array_values(array_diff(
        array_unique($declared[1] ?? []),
        ['--kaupang-review-body-lines']
    ));
    kri_check('declares no slot but Compact\'s own', $unexpected === [], implode(', ', $unexpected));

    // 3. No chrome. The three allowed strings are resets and structure, not
    //    decoration: `background:none`/`border:0` strip UA button and blockquote
    //    chrome, `border-radius:50%` is what makes an avatar an avatar.
    preg_match_all('#(?:background|border|box-shadow|font-family)[a-z-]*\s*:\s*[^;}]+#', $css, $paint);
    $chrome = array_values(array_diff(
        array_unique(array_map(
            static fn($d) => preg_replace('/\s+/', '', $d),
            $paint[0] ?? []
        )),
        ['background:none', 'border:0', 'border-radius:50%']
    ));
    kri_check('ships no chrome', $chrome === [], implode(' | ', $chrome));

    // --- avatar chain -------------------------------------------------------
    // Upload must beat a real Gravatar.
    kri_gravatar_cache($id, true);
    $withUpload = CuratedReview::render(kri_all_on($id));
    $avatarUrl  = (string) wp_get_attachment_image_url($fixture['avatar_id'], [120, 120]);
    kri_check('custom upload wins over a real Gravatar', $avatarUrl !== '' && strpos($withUpload, $avatarUrl) !== false);
    kri_check('avatar carries the block class', strpos($withUpload, 'kaupang-review__avatar') !== false);

    // Neither an upload nor a Gravatar must produce no <img> at all.
    $noAvatar = array_merge(kri_all_on($bare), ['showReviewImages' => false, 'showProductImage' => false]);
    kri_gravatar_cache($bare, false);
    $bareHtml = CuratedReview::render($noAvatar);
    kri_check('no upload and no Gravatar renders no <img>', stripos($bareHtml, '<img') === false, $bareHtml);

    // A review with no author address at all: no Gravatar can exist, so the
    // default silhouette must not survive either.
    $anon = (int) wp_insert_comment([
        'comment_post_ID'  => $fixture['product_id'],
        'comment_author'   => 'Kaupang Fixture (no email)',
        'comment_type'     => 'review',
        'comment_approved' => 1,
        'comment_content'  => 'Fixture with no author email.',
    ]);
    update_comment_meta($anon, KRI_FIXTURE_META, 1);
    $anonHtml = CuratedReview::render(array_merge(kri_all_on($anon), ['showReviewImages' => false, 'showProductImage' => false]));
    kri_check('review with no author email renders no <img>', stripos($anonHtml, '<img') === false, $anonHtml);

    // --- Gravatar: downloaded in the background, served from uploads ---------
    echo "gravatar\n";
    // Strip the answer the avatar-chain block above seeded: $bare is now "never checked".
    foreach ([Gravatar::META_HAS, Gravatar::META_FILE, Gravatar::META_CHECKED] as $kriKey) {
        delete_comment_meta($bare, $kriKey);
    }

    $stub = kri_stub_http(200);
    $neverChecked = CuratedReview::render($noAvatar);
    kri_check('render never calls gravatar.com', $GLOBALS['kri_http_calls'] === 0, $GLOBALS['kri_http_calls'] . ' requests');
    kri_check('a review never checked renders no <img>', stripos($neverChecked, '<img') === false, $neverChecked);
    kri_check('a review never checked is due', in_array($bare, GravatarCheck::due(PHP_INT_MAX), true));

    // A second review by the same address shares the download.
    $twin = (int) wp_insert_comment([
        'comment_post_ID'      => $fixture['product_id'],
        'comment_author'       => 'Kaupang Fixture (twin)',
        'comment_author_email' => get_comment($bare)->comment_author_email,
        'comment_type'         => 'review',
        'comment_approved'     => 1,
        'comment_content'      => 'Fixture by the same address.',
    ]);
    update_comment_meta($twin, KRI_FIXTURE_META, 1);

    kri_check('check stores a copy on 200', GravatarCheck::check($bare) === true && get_comment_meta($bare, Gravatar::META_HAS, true) === 'yes');
    kri_check('one download per address: two sizes', $GLOBALS['kri_http_calls'] === 2, $GLOBALS['kri_http_calls'] . ' requests');
    $kriFile  = (string) get_comment_meta($bare, Gravatar::META_FILE, true);
    $kriPaths = $kriFile !== '' ? kri_gravatar_paths($kriFile) : ['', ''];
    kri_check('both copies are on disk', $kriFile !== '' && is_file($kriPaths[0]) && is_file($kriPaths[1]), $kriFile);
    kri_check('the file name carries no plain email hash', strpos($kriFile, md5((string) get_comment($bare)->comment_author_email)) === false && strpos($kriFile, hash('sha256', (string) get_comment($bare)->comment_author_email)) === false);
    kri_check('the same address shares the copy', get_comment_meta($twin, Gravatar::META_FILE, true) === $kriFile);
    kri_check('check stamps the time', abs((int) get_comment_meta($bare, Gravatar::META_CHECKED, true) - time()) < 60);
    kri_check('a fresh answer is not due', !in_array($bare, GravatarCheck::due(PHP_INT_MAX), true));
    kri_check('--all includes fresh answers', in_array($bare, GravatarCheck::due(PHP_INT_MAX, true), true));

    $GLOBALS['kri_http_calls'] = 0;
    $withGravatar = CuratedReview::render(array_merge($noAvatar, ['showAvatar' => true]));
    kri_check('a stored copy renders, marked wcri-gravatar', strpos($withGravatar, 'wcri-gravatar') !== false, $withGravatar);
    kri_check('src and srcset point at this site', strpos($withGravatar, Gravatar::base('url')) !== false && stripos($withGravatar, 'gravatar.com') === false, $withGravatar);
    kri_check('rendering a copy makes no request', $GLOBALS['kri_http_calls'] === 0);
    kri_check('getAvatarUrl() is local', strpos((string) Display::getAvatarUrl(get_comment($bare), 240), Gravatar::base('url')) === 0);
    remove_filter('pre_http_request', $stub, 10);

    // A copy recorded but gone from disk must not fall back to gravatar.com.
    update_comment_meta($twin, Gravatar::META_FILE, 'garbage');
    $broken = CuratedReview::render(array_merge(kri_all_on($twin), ['showReviewImages' => false, 'showProductImage' => false]));
    kri_check('no valid copy renders no <img>, never a hotlink', stripos($broken, '<img') === false, $broken);
    update_comment_meta($twin, Gravatar::META_FILE, $kriFile);

    update_comment_meta($bare, Gravatar::META_CHECKED, time() - (GravatarCheck::RECHECK_DAYS + 1) * DAY_IN_SECONDS);
    kri_check('an answer older than RECHECK_DAYS is due', in_array($bare, GravatarCheck::due(PHP_INT_MAX), true));

    $stub   = kri_stub_http(null);
    $before = get_comment_meta($bare, Gravatar::META_CHECKED, true);
    kri_check('a network error changes nothing', GravatarCheck::check($bare) === null && get_comment_meta($bare, Gravatar::META_CHECKED, true) === $before && is_file($kriPaths[1]));
    remove_filter('pre_http_request', $stub, 10);

    $stub = kri_stub_http(404);
    kri_check('check stores no on 404', GravatarCheck::check($bare) === false && get_comment_meta($bare, Gravatar::META_HAS, true) === 'no');
    kri_check('a removed Gravatar deletes the copy for every review', !is_file($kriPaths[0]) && !is_file($kriPaths[1]) && get_comment_meta($twin, Gravatar::META_FILE, true) === '');
    remove_filter('pre_http_request', $stub, 10);

    // Deleting a review deletes its copy only once no other review uses it.
    $stub = kri_stub_http(200);
    GravatarCheck::check($bare);
    $kriPaths = kri_gravatar_paths((string) get_comment_meta($bare, Gravatar::META_FILE, true));
    remove_filter('pre_http_request', $stub, 10);
    wp_delete_comment($twin, true);
    kri_check('deleting one of two reviews keeps the shared copy', is_file($kriPaths[1]));

    kri_check('check ignores a non-review', GravatarCheck::check(0) === null);

    // The shop's reply to a review renders in the same list: its Gravatar is local too.
    $reply = (int) wp_insert_comment([
        'comment_post_ID'      => $fixture['product_id'],
        'comment_parent'       => $id,
        'comment_author'       => 'Kaupang Fixture (shop reply)',
        'comment_author_email' => 'shop-reply@kaupang-review-images.invalid',
        'comment_type'         => 'comment',
        'comment_approved'     => 1,
        'comment_content'      => 'Fixture reply.',
    ]);
    update_comment_meta($reply, KRI_FIXTURE_META, 1);
    // KRI_FIXTURE_META is read with type 'review' by the fixture cleanup; this one is removed here.
    kri_check('a reply with no copy renders no <img>, never a hotlink', get_avatar(get_comment($reply), 60) === '' );
    $stub = kri_stub_http(200);
    kri_check('a reply is checked like a review', GravatarCheck::check($reply) === true);
    remove_filter('pre_http_request', $stub, 10);
    $replyAvatar = (string) get_avatar(get_comment($reply), 60);
    kri_check('a reply\'s copy is served from this site', strpos($replyAvatar, Gravatar::base('url')) !== false && stripos($replyAvatar, 'gravatar.com') === false, $replyAvatar);
    wp_delete_comment($reply, true);

    if (function_exists('as_has_scheduled_action')) {
        GravatarCheck::onStatusChange('approved', 'unapproved', get_comment($bare));
        $queued = as_has_scheduled_action(GravatarCheck::HOOK_CHECK, ['comment_id' => $bare], GravatarCheck::GROUP);
        kri_check('approving a review queues its check', $queued !== false);
        as_unschedule_all_actions(GravatarCheck::HOOK_CHECK, ['comment_id' => $bare], GravatarCheck::GROUP);

        GravatarCheck::onCommentPost($bare, 0);
        kri_check('a held review queues nothing', as_has_scheduled_action(GravatarCheck::HOOK_CHECK, ['comment_id' => $bare], GravatarCheck::GROUP) === false);
    } else {
        echo "  skip queue: Action Scheduler is not loaded\n";
    }

    // --- list cap -------------------------------------------------------------
    echo "list cap\n";
    $capRef = new ReflectionClass(ListCap::class);
    $capRef->setStaticPropertyValue('cap', 2);
    $capRef->setStaticPropertyValue('listing', false);
    $top   = get_comment($id);
    $reply = clone $top;
    $reply->comment_parent = (string) $id;

    kri_check('nothing is marked outside the review list', ListCap::markOverflow(['review'], '', $id, $top) === ['review']);
    ListCap::startList([]);
    $marks = [];
    foreach ([$top, $top, $reply, $top, $top] as $c) {
        $marks[] = in_array(ListCap::CLASS_OVERFLOW, ListCap::markOverflow(['review'], '', $id, $c), true);
    }
    kri_check('top-level reviews past the cap are marked, replies never', $marks === [false, false, false, true, true], wp_json_encode($marks));
    $capRef->setStaticPropertyValue('lines', 8);
    kri_check('the hide and clamp rules only apply with scripting', strpos(ListCap::css(), '@media (scripting: enabled)') === 0, ListCap::css());
    kri_check('the clamp is 8 lines and lifts on an expanded review', strpos(ListCap::css(), '-webkit-line-clamp:8') !== false && strpos(ListCap::css(), ':not(.' . ListCap::CLASS_EXPANDED . ')') !== false, ListCap::css());
    $capRef->setStaticPropertyValue('cap', 0);
    $capRef->setStaticPropertyValue('lines', 0);
    kri_check('neither on, no rule', ListCap::css() === '');
    $capRef->setStaticPropertyValue('listing', false);

    // maybeWire() decides from the product's review count; pretend it is long, then short.
    $GLOBALS['wp_query'] = new WP_Query(['p' => $fixture['product_id'], 'post_type' => 'product']);
    $GLOBALS['wp_query']->get_queried_object();
    $count = 0;
    $countFilter = static function () use (&$count) {
        return $count;
    };
    add_filter('woocommerce_product_get_review_count', $countFilter);

    $count = 8 + ListCap::MIN_HIDDEN - 1;
    ListCap::maybeWire();
    kri_check('no cap when fewer than MIN_HIDDEN would hide (default cap 8)', has_filter('comment_class', [ListCap::class, 'markOverflow']) === false);
    kri_check('the clamp still wires a short list', wp_script_is(ListCap::HANDLE, 'enqueued') && strpos((string) wp_scripts()->get_data(ListCap::HANDLE, 'data'), 'readAll') !== false);
    wp_dequeue_script(ListCap::HANDLE);
    wp_dequeue_style(ListCap::HANDLE);

    $count = 40;
    ListCap::maybeWire();
    kri_check('a long list wires the cap', has_filter('comment_class', [ListCap::class, 'markOverflow']) === 10 && has_filter('woocommerce_product_review_list_args', [ListCap::class, 'startList']) === 10);
    kri_check('its script is queued, deferred', wp_script_is(ListCap::HANDLE, 'enqueued') && wp_scripts()->get_data(ListCap::HANDLE, 'strategy') === 'defer');
    kri_check('its hide rule is inline, no file', wp_style_is(ListCap::HANDLE, 'enqueued') && wp_styles()->registered[ListCap::HANDLE]->src === false);
    kri_check('the button labels travel with the script', strpos((string) wp_scripts()->get_data(ListCap::HANDLE, 'data'), '%d') !== false);

    remove_filter('woocommerce_product_get_review_count', $countFilter);
    remove_filter('comment_class', [ListCap::class, 'markOverflow'], 10);
    remove_filter('woocommerce_product_review_list_args', [ListCap::class, 'startList']);
    wp_dequeue_script(ListCap::HANDLE);
    wp_dequeue_style(ListCap::HANDLE);

    $capOff = static fn() => 0;
    add_filter('kaupang/review-images/review_list_cap', $capOff);
    add_filter('woocommerce_product_get_review_count', $countFilter);
    ListCap::maybeWire();
    kri_check('cap 0 turns the cap off', has_filter('comment_class', [ListCap::class, 'markOverflow']) === false);
    wp_dequeue_script(ListCap::HANDLE);
    wp_dequeue_style(ListCap::HANDLE);
    add_filter('kaupang/review-images/review_clamp_lines', $capOff);
    ListCap::maybeWire();
    kri_check('cap 0 and clamp 0 load nothing', !wp_script_is(ListCap::HANDLE, 'enqueued'));
    remove_filter('kaupang/review-images/review_clamp_lines', $capOff);
    remove_filter('woocommerce_product_get_review_count', $countFilter);
    remove_filter('kaupang/review-images/review_list_cap', $capOff);

    // --- review form ----------------------------------------------------------
    // The enctype script follows the upload fields: rendered on a product page with
    // reviews open, it is queued for the footer.
    $GLOBALS['wp_query'] = new WP_Query(['p' => $fixture['product_id'], 'post_type' => 'product']);
    $GLOBALS['post']     = get_post($fixture['product_id']);
    if (comments_open()) {
        ob_start();
        Images::displayUploadFieldAndNonce();
        $form = (string) ob_get_clean();
        kri_check('upload fields render on a product with reviews open', strpos($form, 'wcri_review_image_upload') !== false);
        kri_check('enctype footer script queued where the form renders', has_action('wp_footer', [Images::class, 'ensureFormEnctypeScript']) === 99);
    } else {
        echo "  skip review form: reviews closed on product {$fixture['product_id']}\n";
    }
} finally {
    $kriLeft = array_filter(array_map(
        static fn($c) => (string) get_comment_meta((int) $c->comment_ID, Gravatar::META_FILE, true),
        get_comments(['meta_key' => KRI_FIXTURE_META, 'status' => 'all', 'type' => 'review'])
    ));
    kri_fixture_delete();
    echo "fixture removed\n";
    $kriOrphans = array_filter(array_merge(...array_map('kri_gravatar_paths', array_values(array_unique($kriLeft)) ?: [])), 'is_file');
    kri_check('deleting the fixture reviews deletes their copies', $kriOrphans === [], implode(', ', $kriOrphans));
}

// Storefront load budget (conventions.md §5B).
if (class_exists('WP_CLI') && is_array(WP_CLI::get_runner()->find_command_to_run(['kaupang-assets', 'audit']))) {
    $audit = WP_CLI::runcommand('kaupang-assets audit --plugins=kaupang-review-images --budget', ['return' => 'all', 'exit_error' => false]);
    echo $audit->stdout . "\n";
    kri_check('kaupang-assets audit --plugins=kaupang-review-images --budget exits 0', $audit->return_code === 0 && $audit->stdout !== '', trim($audit->stderr));
} else {
    echo "  skip storefront budget: `wp kaupang-assets audit` is not available here\n";
}

printf("\n%d checks, %d failed\n", $GLOBALS['kri_checks'], count($GLOBALS['kri_failures']));

if ($GLOBALS['kri_failures']) {
    foreach ($GLOBALS['kri_failures'] as $name) {
        echo "  - $name\n";
    }
    exit(1);
}

exit(0);
