<?php
/**
 * Conditional Gravatar loading.
 *
 * Hides default Gravatars for review authors who have none, and resizes real
 * ones to the theme's target size with a retina srcset.
 *
 * @package KaupangReviewImages
 */

declare(strict_types=1);

namespace Kaupang\ReviewImages\Avatars;

use Kaupang\ReviewImages\Support\Comments;

defined('ABSPATH') || exit;

final class Gravatar
{

    /** Comment meta: 'yes' or 'no', written by GravatarCheck, never on a page view. */
    public const META_HAS = '_kaupang_review_images_gravatar';

    /** Comment meta: unix time of the last lookup. */
    public const META_CHECKED = '_kaupang_review_images_gravatar_checked';

    /**
     * Does this review's author have a real (non-default) Gravatar?
     *
     * Reads only what is already known. Comment meta is primed with the comment
     * loop, so a list of 37 reviews costs no query and no remote call. A review
     * that has never been checked counts as "no" until GravatarCheck gets to it.
     */
    public static function hasGravatar(\WP_Comment $comment): bool
    {
        $known = get_comment_meta((int) $comment->comment_ID, self::META_HAS, true);
        if ($known !== '') {
            return $known === 'yes';
        }

        // ponytail: the 1-day transient the old synchronous lookup wrote. Read-only
        // bridge for the day between deploy and the first GravatarCheck run; delete
        // once every site has run `wp kaupang-review-images gravatars`.
        $email = (string) $comment->comment_author_email;

        return $email !== '' && get_transient('kaupang_review_images_has_gravatar_' . md5($email)) === 'yes';
    }

    /**
     * Ask gravatar.com. Background only: GravatarCheck and WP-CLI call this, a page view never does.
     *
     * @return bool|null null when the answer is unknown (network error, unexpected status)
     */
    public static function lookup(string $email): ?bool
    {
        if ($email === '' || !is_email($email)) {
            return false;
        }

        $response = wp_remote_head(
            'https://www.gravatar.com/avatar/' . md5(strtolower(trim($email))) . '?d=404',
            ['timeout' => 5]
        );
        if (is_wp_error($response)) {
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        return $code === 200 ? true : ($code === 404 ? false : null);
    }

    /**
     * @param string               $avatar
     * @param mixed                $idOrEmail
     * @param int                  $size
     * @param string               $default
     * @param string               $alt
     * @param array<string, mixed> $args
     * @return string
     */
    public static function filterWoocommerceGravatar($avatar, $idOrEmail, $size, $default, $alt, $args = [])
    {
        // See Display::displayCustomAvatar() for why admin is left alone.
        if (is_admin()) {
            return $avatar;
        }

        $comment = Comments::productReview($idOrEmail);
        if (!$comment) {
            return $avatar;
        }

        // A custom uploaded avatar already won at priority 5 -- leave it alone.
        if (is_string($avatar) && strpos($avatar, 'wcri-custom-avatar') !== false) {
            return $avatar;
        }

        $email = $comment->comment_author_email;
        if (empty($email) || !is_email($email)) {
            return $avatar;
        }

        if (!apply_filters('kaupang/review-images/enable_conditional_gravatars', true)) {
            return $avatar;
        }

        // No real Gravatar: preserve whatever came in (may be empty, may be a
        // custom upload supplied by another filter).
        if (!self::hasGravatar($comment)) {
            return $avatar;
        }

        $baseSize   = (int) apply_filters('kaupang/review-images/gravatar_base_size', 120);
        $retinaSize = $baseSize * 2;

        if (!preg_match('/src=[\'"]([^\'"]+)[\'"]/', $avatar, $srcMatch)) {
            return $avatar;
        }

        $srcUrl = preg_replace('/s=\d+/', 's=' . $baseSize, $srcMatch[1]);
        $avatar = preg_replace('/src=[\'"][^\'"]+[\'"]/', 'src="' . esc_url((string) $srcUrl) . '"', $avatar);

        $srcsetUrl = preg_replace('/s=\d+/', 's=' . $retinaSize, $srcMatch[1]);
        $avatar    = preg_replace('/srcset=[\'"][^\'"]*[\'"]/', 'srcset="' . esc_url((string) $srcsetUrl) . ' 2x"', (string) $avatar);

        $avatar = preg_replace('/(width|height)=[\'"](\d+)[\'"]/', '$1="' . $baseSize . '"', (string) $avatar);

        // get_avatar() quotes its class attribute with single quotes, so match either.
        return (string) preg_replace('/\bclass=([\'"])/', 'class=$1wcri-gravatar ', (string) $avatar, 1);
    }
}
