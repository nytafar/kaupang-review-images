<?php
/**
 * Gravatars, served from this site.
 *
 * A reviewer's Gravatar is downloaded in the background (GravatarCheck) and kept
 * under uploads/kaupang-review-images/gravatars/, at the theme's size and twice
 * it. A page view serves those copies: the visitor's browser never contacts
 * gravatar.com, so a visitor's IP address is not handed to a third party, and the
 * page carries no hash of the reviewer's email address. A review author with no
 * Gravatar, or no copy yet, gets no <img> at all.
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

    /** Comment meta: the local copy, "{stem}.{ext}"; the files are {stem}@1x.{ext} and {stem}@2x.{ext}. */
    public const META_FILE = '_kaupang_review_images_gravatar_file';

    /** Comment meta: unix time of the last lookup. */
    public const META_CHECKED = '_kaupang_review_images_gravatar_checked';

    /** Under the uploads base directory. */
    public const SUBDIR = 'kaupang-review-images/gravatars';

    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];

    private const MAX_BYTES = 1024 * 1024;

    /**
     * Is there a local copy of this review author's Gravatar?
     *
     * Reads only comment meta, which the comment loop primes: no query, no remote call.
     */
    public static function hasGravatar(\WP_Comment $comment): bool
    {
        return self::localUrls($comment) !== null;
    }

    /**
     * @return array{0: string, 1: string}|null [1x URL, 2x URL]
     */
    public static function localUrls(\WP_Comment $comment): ?array
    {
        $id = (int) $comment->comment_ID;
        if (get_comment_meta($id, self::META_HAS, true) !== 'yes') {
            return null;
        }

        $file = (string) get_comment_meta($id, self::META_FILE, true);
        if (!preg_match('/^([a-f0-9]{16}-[a-f0-9]{8})\.(jpg|png|gif|webp)$/', $file, $m)) {
            return null;
        }

        $base = self::base('url');

        return [$base . '/' . $m[1] . '@1x.' . $m[2], $base . '/' . $m[1] . '@2x.' . $m[2]];
    }

    /** The theme's avatar size in CSS pixels; the copy is kept at this and twice this. */
    public static function baseSize(): int
    {
        return max(1, (int) apply_filters('kaupang/review-images/gravatar_base_size', 120));
    }

    /**
     * Download a Gravatar at the base size and twice it. Background only.
     *
     * @return array{ext: string, 1x: string, 2x: string}|false|null false when the address has no
     *                                                              Gravatar, null when unanswered
     */
    public static function fetch(string $email): array|false|null
    {
        $email = strtolower(trim($email));
        if ($email === '' || !is_email($email)) {
            return false;
        }

        $size = self::baseSize();
        $out  = [];
        foreach (['2x' => $size * 2, '1x' => $size] as $density => $px) {
            $response = wp_remote_get(
                'https://www.gravatar.com/avatar/' . hash('sha256', $email) . '?s=' . $px . '&d=404',
                ['timeout' => 5, 'limit_response_size' => self::MAX_BYTES]
            );
            if (is_wp_error($response)) {
                return null;
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code === 404) {
                return false;
            }

            $type = strtolower(trim(explode(';', (string) wp_remote_retrieve_header($response, 'content-type'))[0]));
            $body = (string) wp_remote_retrieve_body($response);
            if ($code !== 200 || !isset(self::TYPES[$type]) || $body === '' || strlen($body) >= self::MAX_BYTES) {
                return null;
            }

            $out['ext']     = self::TYPES[$type];
            $out[$density] = $body;
        }

        return $out;
    }

    /**
     * Write a fetched Gravatar under uploads. The name hides the address and changes with the image.
     *
     * The stem is a keyed hash of the address (a plain md5/sha256 of an email is reversible
     * by lookup) plus a hash of the image, so a changed Gravatar gets a new URL and no cache
     * serves the old one.
     *
     * @param array{ext: string, 1x: string, 2x: string} $fetched
     * @return string|null "{stem}.{ext}" for META_FILE; null when the files could not be written
     */
    public static function store(string $email, array $fetched): ?string
    {
        $dir = self::base('dir');
        if (!wp_mkdir_p($dir)) {
            return null;
        }

        $stem = substr(hash_hmac('sha256', strtolower(trim($email)), wp_salt('auth')), 0, 16)
            . '-' . substr(md5($fetched['2x']), 0, 8);

        // WebP where the server's image editor can write it: Gravatar serves PNGs of 100 kB+
        // at avatar sizes. Same pixel size, re-encoded only; otherwise the file as served.
        $ext = wp_image_editor_supports(['mime_type' => 'image/webp']) ? 'webp' : $fetched['ext'];

        foreach (['1x', '2x'] as $density) {
            $target = "{$dir}/{$stem}@{$density}.{$ext}";
            if (!self::write($fetched[$density], $target, $ext === $fetched['ext'] ? null : 'image/webp')) {
                return null;
            }
        }

        return $stem . '.' . $ext;
    }

    /** Write $body to $target, re-encoded to $mime when given. */
    private static function write(string $body, string $target, ?string $mime): bool
    {
        if ($mime === null) {
            return file_put_contents($target, $body) !== false;
        }

        $tmp = tempnam(get_temp_dir(), 'kri');
        if ($tmp === false || file_put_contents($tmp, $body) === false) {
            return false;
        }

        $editor = wp_get_image_editor($tmp);
        $saved  = !is_wp_error($editor) && !is_wp_error($editor->save($target, $mime));
        wp_delete_file($tmp);

        // An image the editor can't read: keep nothing rather than a file under the wrong extension.
        return $saved && is_file($target);
    }

    /** Remove a local copy. */
    public static function deleteFiles(string $file): void
    {
        if (!preg_match('/^([a-f0-9]{16}-[a-f0-9]{8})\.(jpg|png|gif|webp)$/', $file, $m)) {
            return;
        }

        foreach (['1x', '2x'] as $density) {
            wp_delete_file(self::base('dir') . "/{$m[1]}@{$density}.{$m[2]}");
        }
    }

    /** @param 'dir'|'url' $which */
    public static function base(string $which): string
    {
        $uploads = wp_upload_dir(null, false);

        return untrailingslashit($uploads[$which === 'dir' ? 'basedir' : 'baseurl']) . '/' . self::SUBDIR;
    }

    /**
     * get_avatar at priority 10: point a real Gravatar at the local copy.
     *
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

        // Reviews and the shop's replies to them: everything in the product's review list.
        $comment = Comments::onProductPageAvatar($idOrEmail);
        if (!$comment || !is_string($avatar) || $avatar === '') {
            return $avatar;
        }

        // A custom uploaded avatar already won at priority 5 -- leave it alone.
        if (strpos($avatar, 'wcri-custom-avatar') !== false) {
            return $avatar;
        }

        if (!apply_filters('kaupang/review-images/enable_conditional_gravatars', true)) {
            return $avatar;
        }

        // Never hotlink: without a local copy there is no Gravatar <img>.
        $urls = self::localUrls($comment);
        if ($urls === null) {
            return '';
        }

        $px = self::baseSize();

        $avatar = (string) preg_replace('/\ssrc=([\'"])[^\'"]*\1/', ' src="' . esc_url($urls[0]) . '"', $avatar, 1);
        $avatar = (string) preg_replace('/\ssrcset=([\'"])[^\'"]*\1/', ' srcset="' . esc_url($urls[1]) . ' 2x"', $avatar, 1);
        $avatar = (string) preg_replace('/\s(width|height)=([\'"])\d+\2/', ' $1="' . $px . '"', $avatar);

        // get_avatar() quotes its class attribute with single quotes, so match either.
        return (string) preg_replace('/\bclass=([\'"])/', 'class=$1wcri-gravatar ', $avatar, 1);
    }
}
