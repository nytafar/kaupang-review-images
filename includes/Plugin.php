<?php
/**
 * Boot wiring.
 *
 * One file registers every module, suite convention (conventions.md §5A): hooks are
 * registered here with class-string callables, so a module's class loads only when its
 * hook fires. A guest page that shows no review and no Curated Review block loads this
 * file and the main file, nothing else (README "Load map").
 *
 * Each module keeps the enable-filter gate it had before the Kaupang conversion; the
 * gates run at plugin-load time, so only an mu-plugin or an earlier-loading plugin can
 * flip them — a theme cannot. That timing is inherited, not new.
 *
 * @package KaupangReviewImages
 */

declare(strict_types=1);

namespace Kaupang\ReviewImages;

use Kaupang\ReviewImages\Avatars\Display;
use Kaupang\ReviewImages\Avatars\Gravatar;
use Kaupang\ReviewImages\Avatars\GravatarCheck;
use Kaupang\ReviewImages\Avatars\Upload;
use Kaupang\ReviewImages\Block\CuratedReview;
use Kaupang\ReviewImages\Reviews\Images;
use Kaupang\ReviewImages\Reviews\ListCap;

defined('ABSPATH') || exit;

final class Plugin
{
    // Here, not on CuratedReview: reading CuratedReview::BLOCK_NAME would load the class on every request.
    public const BLOCK_NAME = 'kaupang-review-images/curated-review';

    public static function boot(): void
    {
        add_action('plugins_loaded', [self::class, 'loadTextdomain']);

        $images        = (bool) apply_filters('kaupang/review-images/enable_review_images', true);
        $avatarUpload  = (bool) apply_filters('kaupang/review-images/enable_avatar_upload', true);
        $avatarDisplay = (bool) apply_filters('kaupang/review-images/enable_avatar_display', true);

        if (apply_filters('kaupang/review-images/enable_conditional_gravatars', true)) {
            // Priority 10 — must run after Display's custom-avatar filter at 5.
            add_filter('get_avatar', [Gravatar::class, 'filterWoocommerceGravatar'], 10, 6);
            self::wireGravatarCheck();
        }

        // WooCommerce loads after this plugin, so its presence is known at plugins_loaded.
        add_action('plugins_loaded', static function () use ($images, $avatarUpload, $avatarDisplay): void {
            if (!class_exists('WooCommerce')) {
                if ($images) {
                    add_action('admin_notices', [Images::class, 'woocommerceMissingNotice']);
                }

                return;
            }

            if ($images) {
                self::wireImages();
            }

            if ($avatarUpload) {
                // A review is posted through wp-comments-post.php, not admin: register everywhere.
                // The upload field itself is rendered by Reviews\Images, alongside the review-image field.
                add_action('preprocess_comment', [Upload::class, 'handleAvatarUpload']);
                add_action('comment_post', [Upload::class, 'saveAvatarMeta'], 10, 2);
                if (is_admin()) {
                    add_action('add_meta_boxes_comment', [Upload::class, 'addAvatarMetaBox']);
                }
            }

            if ($avatarDisplay) {
                // Priority 5 — must run before Gravatar's resize filter at 10.
                add_filter('get_avatar', [Display::class, 'displayCustomAvatar'], 5, 6);
            }
        });

        add_filter('block_type_metadata', [self::class, 'blockMetadata']);
        add_action('init', [self::class, 'registerBlock']);
    }

    /**
     * Gravatar lookups happen here, never on a page view (README "Gravatars").
     *
     * Approve, submit and edit are cross-context events (admin, REST, wp-comments-post.php):
     * registered everywhere, lazy handlers. The job hooks fire in cron, async requests and CLI.
     */
    private static function wireGravatarCheck(): void
    {
        add_action('comment_post', [GravatarCheck::class, 'onCommentPost'], 10, 2);
        add_action('transition_comment_status', [GravatarCheck::class, 'onStatusChange'], 10, 3);
        add_action('edit_comment', [GravatarCheck::class, 'onEdit']);
        add_action('delete_comment', [GravatarCheck::class, 'onDelete']);
        // Literals, not GravatarCheck::HOOK_*: reading a class constant loads the class on every request.
        add_action('kaupang_review_images_check_gravatar', [GravatarCheck::class, 'check']);
        add_action('kaupang_review_images_refresh_gravatars', [GravatarCheck::class, 'refresh']);
        add_action('action_scheduler_ensure_recurring_actions', [GravatarCheck::class, 'ensureSchedule']);

        if (defined('WP_CLI') && WP_CLI) {
            // A closure, not [GravatarCheck::class, 'cli']: WP-CLI reflects a method callable at
            // registration, which would load the class on every CLI run.
            \WP_CLI::add_command(
                'kaupang-review-images gravatars',
                /**
                 * Download reviewers' Gravatars now instead of waiting for the daily job.
                 *
                 * Pages serve local copies and never contact gravatar.com. Run this after
                 * deploying, or with --all to refresh every copy.
                 *
                 * [--all]
                 * : Check every approved review, not only those never checked or checked over 30 days ago.
                 *
                 * @when after_wp_load
                 */
                static function (array $args, array $assoc): void {
                    GravatarCheck::cli($args, $assoc);
                }
            );
        }
    }

    private static function wireImages(): void
    {
        // Pattern B: product page with reviews open, decided once the main query is known.
        add_action('wp_enqueue_scripts', static function (): void {
            if (function_exists('is_product') && is_product() && comments_open()) {
                Images::enqueueAssets();
                ListCap::maybeWire();
            }
        });

        // The form hooks fire only where a comment form renders; the field renderer
        // adds the enctype footer script, so it prints only where the form is shown.
        add_action('comment_form_logged_in_after', [Images::class, 'displayUploadFieldAndNonce']);
        add_action('comment_form_after_fields', [Images::class, 'displayUploadFieldAndNonce']);
        add_action('preprocess_comment', [Images::class, 'handleImageUpload']);
        add_action('comment_post', [Images::class, 'saveImageMeta'], 10, 2);
        add_action('woocommerce_review_before', [Images::class, 'displayReviewImage']);

        // is_admin() includes admin-ajax, where a comment reply re-renders the list-table row.
        if (is_admin()) {
            add_filter('manage_edit-comments_columns', [Images::class, 'addReviewImageAdminColumnHeader']);
            add_action('manage_comments_custom_column', [Images::class, 'displayReviewImageAdminColumnContent'], 10, 2);
            add_action('add_meta_boxes_comment', [Images::class, 'addReviewImageMetaBox']);
        }
    }

    /**
     * Outside wp-admin the block needs no editor script and no translated title.
     *
     * Registering `editorScript: file:` reads build/index.asset.php, and translating the
     * title loads the text-domain catalogue, on every request. Only the editor (always a
     * wp-admin screen) uses either, so the front end and REST register the block without
     * them; its render callback still translates its own strings when it runs.
     *
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public static function blockMetadata($metadata)
    {
        if (is_array($metadata) && ($metadata['name'] ?? '') === self::BLOCK_NAME && !is_admin()) {
            unset($metadata['editorScript'], $metadata['textdomain']);
        }

        return $metadata;
    }

    public static function registerBlock(): void
    {
        /**
         * Kill switch for the whole block.
         *
         * __return_false removes it from the inserter entirely.
         *
         * @param bool $enabled
         */
        if (!apply_filters('kaupang/review-images/enable_block', true)) {
            return;
        }

        // Core enqueues `style` and the `viewScriptModule` only where the block renders.
        $type = register_block_type(
            KAUPANG_REVIEW_IMAGES_DIR . 'block',
            ['render_callback' => [CuratedReview::class, 'render']]
        );

        /**
         * Drop the block's own stylesheet.
         *
         * The sheet ships structure only -- reading order, a round avatar, a
         * photo that stays inside its column, stars that are not broken -- and
         * no chrome, at zero specificity. A theme that would rather own even
         * that returns false here and styles .is-style-quote /
         * .is-style-stacked / .is-style-compact itself. The variations stay
         * registered either way, so the editor still offers them.
         *
         * @param bool $enqueue
         */
        if ($type && !apply_filters('kaupang/review-images/enqueue_block_styles', true)) {
            $type->style_handles = [];
        }

        // Editor strings ship in the plugin's own .mo files.
        if ($type && !empty($type->editor_script_handles)) {
            foreach ($type->editor_script_handles as $handle) {
                wp_set_script_translations(
                    $handle,
                    'kaupang-review-images',
                    KAUPANG_REVIEW_IMAGES_DIR . 'languages'
                );
            }
        }
    }

    public static function loadTextdomain(): void
    {
        load_plugin_textdomain(
            'kaupang-review-images',
            false,
            dirname(plugin_basename(KAUPANG_REVIEW_IMAGES_FILE)) . '/languages'
        );
    }
}
