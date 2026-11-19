<?php
/**
 * "Show all reviews" on a long product review list.
 *
 * Every approved review is still rendered: the text is the page's SEO content. Only
 * the display is capped. Structured data is separate and unaffected: WooCommerce's
 * Product JSON-LD carries the aggregate rating and the five newest rated reviews,
 * whatever the list shows. Reviews past the cap carry CLASS_OVERFLOW and are hidden by
 * CSS from first paint; review-list.js puts a button after the list that shows
 * and hides them.
 *
 * Without scripting the hide rule never matches (`@media (scripting: enabled)`)
 * and no button is added, so every review shows. A link to a hidden review
 * (#comment-123) opens the list.
 *
 * @package KaupangReviewImages
 */

declare(strict_types=1);

namespace Kaupang\ReviewImages\Reviews;

use Kaupang\ReviewImages\Support\Media;

defined('ABSPATH') || exit;

final class ListCap
{
    public const HANDLE         = 'kaupang-review-images-list';
    public const CLASS_OVERFLOW = 'kaupang-reviews__overflow';
    public const CLASS_OPEN     = 'kaupang-reviews--open';

    /** Hiding one or two reviews behind a button costs more than it saves: show them. */
    public const MIN_HIDDEN = 3;

    private static int $cap = 0;

    private static int $seen = 0;

    private static bool $listing = false;

    /**
     * wp_enqueue_scripts on a product page. Wires the cap only where it will hide something.
     */
    public static function maybeWire(): void
    {
        $product = function_exists('wc_get_product') ? wc_get_product(get_queried_object_id()) : null;
        if (!$product) {
            return;
        }

        /**
         * How many reviews show before "Show all". 0 turns the cap off.
         *
         * Counts top-level reviews; replies travel with their review. A list is
         * capped only when at least MIN_HIDDEN (3) reviews would be hidden.
         *
         * @param int         $cap
         * @param \WC_Product $product
         */
        $cap = (int) apply_filters('kaupang/review-images/review_list_cap', 6, $product);
        if ($cap < 1 || (int) $product->get_review_count() - $cap < self::MIN_HIDDEN) {
            return;
        }

        self::$cap = $cap;
        add_filter('woocommerce_product_review_list_args', [self::class, 'startList']);
        add_filter('comment_class', [self::class, 'markOverflow'], 10, 4);

        // Functional, not chrome: it ships even when a theme turns the plugin's stylesheet off.
        wp_register_style(self::HANDLE, false, [], KAUPANG_REVIEW_IMAGES_VERSION);
        wp_enqueue_style(self::HANDLE);
        wp_add_inline_style(self::HANDLE, self::css());

        wp_enqueue_script(
            self::HANDLE,
            KAUPANG_REVIEW_IMAGES_URL . 'assets/js/review-list.js',
            [],
            Media::assetVersion('assets/js/review-list.js'),
            ['in_footer' => true, 'strategy' => 'defer']
        );
        wp_localize_script(self::HANDLE, 'kaupangReviewList', [
            /* translators: %d: number of reviews on the product */
            'more' => __('Show all %d reviews', 'kaupang-review-images'),
            'less' => __('Show fewer reviews', 'kaupang-review-images'),
        ]);
    }

    /**
     * woocommerce_product_review_list_args: fires right before the review list renders.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function startList($args)
    {
        self::$listing = true;
        self::$seen    = 0;

        return $args;
    }

    /**
     * comment_class: mark top-level reviews past the cap.
     *
     * @param array<int, string> $classes
     * @param string|string[]    $cssClass
     * @param int|string         $commentId
     * @param \WP_Comment|null   $comment
     * @return array<int, string>
     */
    public static function markOverflow($classes, $cssClass = '', $commentId = 0, $comment = null)
    {
        if (!self::$listing || !$comment instanceof \WP_Comment || (int) $comment->comment_parent !== 0) {
            return $classes;
        }

        if (++self::$seen > self::$cap) {
            $classes[] = self::CLASS_OVERFLOW;
        }

        return $classes;
    }

    public static function css(): string
    {
        return '@media (scripting: enabled){.commentlist:not(.' . self::CLASS_OPEN . ')>.' . self::CLASS_OVERFLOW . '{display:none}}';
    }
}
