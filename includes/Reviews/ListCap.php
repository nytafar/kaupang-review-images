<?php
/**
 * A long product review list: "Show all reviews", and "Read all" on a long review.
 *
 * Every approved review is still rendered: the text is the page's SEO content. Only
 * the display is capped. Structured data is separate and unaffected: WooCommerce's
 * Product JSON-LD carries the aggregate rating and the five newest rated reviews,
 * whatever the list shows. Reviews past the cap carry CLASS_OVERFLOW and are hidden by
 * CSS from first paint; review-list.js puts a button after the list that shows
 * and hides them.
 *
 * A long review's text is clamped to a number of lines in the same way, and the
 * script gives each review that actually overflows a "Read all" button. The full
 * text is in the HTML either way.
 *
 * Without scripting neither rule matches (`@media (scripting: enabled)`) and no
 * button is added, so every review shows in full. A link to a hidden review
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
    public const CLASS_EXPANDED = 'kaupang-review--expanded';

    /** Hiding one or two reviews behind a button costs more than it saves: show them. */
    public const MIN_HIDDEN = 3;

    private static int $cap = 0;

    private static int $lines = 0;

    private static int $seen = 0;

    private static bool $listing = false;

    /**
     * wp_enqueue_scripts on a product page with reviews. Wires the cap only where it will hide
     * something; the clamp wherever it is on (the script decides per review whether it cuts).
     */
    public static function maybeWire(): void
    {
        $product = function_exists('wc_get_product') ? wc_get_product(get_queried_object_id()) : null;
        if (!$product) {
            return;
        }

        $count = (int) $product->get_review_count();
        if ($count < 1) {
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
        $cap = (int) apply_filters('kaupang/review-images/review_list_cap', 8, $product);
        $cap = $cap >= 1 && $count - $cap >= self::MIN_HIDDEN ? $cap : 0;

        /**
         * Lines of a review's text shown before "Read all". 0 shows every review in full.
         *
         * @param int         $lines
         * @param \WC_Product $product
         */
        $lines = max(0, (int) apply_filters('kaupang/review-images/review_clamp_lines', 8, $product));

        if (!$cap && !$lines) {
            return;
        }

        self::$cap   = $cap;
        self::$lines = $lines;
        if ($cap) {
            add_filter('woocommerce_product_review_list_args', [self::class, 'startList']);
            add_filter('comment_class', [self::class, 'markOverflow'], 10, 4);
        }

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
            'readAll'  => __('Read all', 'kaupang-review-images'),
            'readLess' => __('Show less', 'kaupang-review-images'),
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
        $rules = '';
        if (self::$cap) {
            $rules .= '.commentlist:not(.' . self::CLASS_OPEN . ')>.' . self::CLASS_OVERFLOW . '{display:none}';
        }
        if (self::$lines) {
            // -webkit-line-clamp is the cross-browser spelling (Firefox and Safari included).
            $rules .= '.commentlist .review:not(.' . self::CLASS_EXPANDED . ') .description{display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:'
                . self::$lines . ';overflow:hidden}';
        }

        return $rules === '' ? '' : '@media (scripting: enabled){' . $rules . '}';
    }
}
