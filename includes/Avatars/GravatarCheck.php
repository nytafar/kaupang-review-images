<?php
/**
 * Gravatar downloads, off the request.
 *
 * A reviewer's Gravatar is fetched from gravatar.com in the background, stored
 * under uploads (Gravatar::store()), and recorded on the review as comment meta,
 * which the comment loop primes. A page view only reads that meta and serves the
 * local copy (Gravatar::filterWoocommerceGravatar()).
 *
 * One download per address: a check applies to every review by the same author
 * email, so they share one copy, and an old copy is deleted only once no review
 * points at it.
 *
 * - A review that is approved or edited queues a check of itself.
 * - A daily Action Scheduler job queues checks for reviews never checked, or
 *   checked more than RECHECK_DAYS ago. That is also the backfill after deploy.
 * - `wp kaupang-review-images gravatars` does the same synchronously.
 *
 * @package KaupangReviewImages
 */

declare(strict_types=1);

namespace Kaupang\ReviewImages\Avatars;

use Kaupang\ReviewImages\Support\Comments;

defined('ABSPATH') || exit;

final class GravatarCheck
{
    public const HOOK_CHECK   = 'kaupang_review_images_check_gravatar';
    public const HOOK_REFRESH = 'kaupang_review_images_refresh_gravatars';
    public const GROUP        = 'kaupang-review-images';

    /** A Gravatar is added or removed rarely; a month-old answer is good enough. */
    public const RECHECK_DAYS = 30;

    /** Checks the daily job queues at most; the rest wait for the next day. */
    private const REFRESH_BATCH = 200;

    /**
     * comment_post: a review approved on submit (a logged-in customer, or moderation off).
     *
     * @param int        $commentId
     * @param int|string $approved
     */
    public static function onCommentPost($commentId, $approved): void
    {
        if ((string) $approved === '1') {
            self::queue((int) $commentId);
        }
    }

    /**
     * transition_comment_status: a review approved in moderation.
     *
     * @param string      $new
     * @param string      $old
     * @param \WP_Comment $comment
     */
    public static function onStatusChange($new, $old, $comment): void
    {
        if ($new === 'approved' && $comment instanceof \WP_Comment) {
            self::queue((int) $comment->comment_ID);
        }
    }

    /**
     * edit_comment: the author address may have changed.
     *
     * @param int $commentId
     */
    public static function onEdit($commentId): void
    {
        $comment = get_comment((int) $commentId);
        if ($comment && $comment->comment_approved === '1') {
            self::queue((int) $comment->comment_ID);
        }
    }

    /** One async action per review, unique while pending. Without Action Scheduler: inline. */
    public static function queue(int $commentId): void
    {
        $comment = get_comment($commentId);
        if (!$comment || !Comments::onProductPage($comment)) {
            return;
        }

        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::HOOK_CHECK, ['comment_id' => $commentId], self::GROUP, true);

            return;
        }

        self::check($commentId);
    }

    /**
     * The HOOK_CHECK handler. Idempotent.
     *
     * @return bool|null whether a Gravatar copy is now stored; null when gravatar.com gave no
     *                   answer or the copy could not be written (nothing changed, the daily job retries)
     */
    public static function check($commentId): ?bool
    {
        $comment = get_comment((int) $commentId);
        if (!$comment || !Comments::onProductPage($comment)) {
            return null;
        }

        $email   = (string) $comment->comment_author_email;
        $fetched = Gravatar::fetch($email);
        $file    = $fetched ? Gravatar::store($email, $fetched) : '';
        if ($fetched === null || $file === null) {
            self::retryTomorrow((int) $comment->comment_ID);

            return null;
        }

        $ids = self::sameAuthor($comment);
        $old = [];
        foreach ($ids as $id) {
            $old[] = (string) get_comment_meta($id, Gravatar::META_FILE, true);
            update_comment_meta($id, Gravatar::META_HAS, $file !== '' ? 'yes' : 'no');
            update_comment_meta($id, Gravatar::META_CHECKED, time());
            $file !== '' ? update_comment_meta($id, Gravatar::META_FILE, $file) : delete_comment_meta($id, Gravatar::META_FILE);
        }

        // Every review by this address now points at $file, so an older copy is unreferenced.
        foreach (array_unique(array_filter($old)) as $stale) {
            if ($stale !== $file) {
                Gravatar::deleteFiles($stale);
            }
        }

        return $file !== '';
    }

    /**
     * An unanswered check keeps whatever copy there is, and becomes due on the next daily run.
     *
     * Without this, a review that already had a copy was not due again for RECHECK_DAYS: a
     * failed refresh would wait a month.
     */
    private static function retryTomorrow(int $commentId): void
    {
        update_comment_meta($commentId, Gravatar::META_CHECKED, time() - (self::RECHECK_DAYS - 1) * DAY_IN_SECONDS);
    }

    /**
     * delete_comment: a review deleted for good takes its Gravatar copy along, unless another review still uses it.
     *
     * @param int|string $commentId
     */
    public static function onDelete($commentId): void
    {
        $file = (string) get_comment_meta((int) $commentId, Gravatar::META_FILE, true);
        if ($file === '') {
            return;
        }

        $others = get_comments([
            'meta_key'       => Gravatar::META_FILE,
            'meta_value'     => $file,
            'comment__not_in' => [(int) $commentId],
            'status'         => 'all',
            'count'          => true,
        ]);
        if ((int) $others === 0) {
            Gravatar::deleteFiles($file);
        }
    }

    /**
     * The comment and every other review or reply on a product by the same author email.
     *
     * @return array<int, int>
     */
    private static function sameAuthor(\WP_Comment $comment): array
    {
        $ids = [(int) $comment->comment_ID];
        if ((string) $comment->comment_author_email !== '') {
            $ids = array_merge($ids, array_map('intval', (array) get_comments([
                'type__in'                  => ['review', 'comment'],
                'author_email'              => $comment->comment_author_email,
                'post_type'                 => 'product',
                'status'                    => 'all',
                'fields'                    => 'ids',
                'update_comment_meta_cache' => false,
            ])));
        }

        return array_values(array_unique($ids));
    }

    /** The HOOK_REFRESH handler: queue a check for every review that is due. */
    public static function refresh(): void
    {
        foreach (self::due(self::REFRESH_BATCH) as $commentId) {
            self::queue($commentId);
        }
    }

    /**
     * Approved reviews and replies on products never checked, or checked more than RECHECK_DAYS ago.
     *
     * @return array<int, int>
     */
    public static function due(int $limit, bool $all = false): array
    {
        $args = [
            'type__in'                  => ['review', 'comment'],
            'status'                    => 'approve',
            'post_type'                 => 'product',
            'fields'                    => 'ids',
            'number'                    => $limit,
            'orderby'                   => 'comment_ID',
            'order'                     => 'ASC',
            'update_comment_meta_cache' => false,
            'update_comment_post_cache' => false,
        ];

        if (!$all) {
            $args['meta_query'] = [
                'relation' => 'OR',
                ['key' => Gravatar::META_CHECKED, 'compare' => 'NOT EXISTS'],
                [
                    'key'     => Gravatar::META_CHECKED,
                    'value'   => time() - self::RECHECK_DAYS * DAY_IN_SECONDS,
                    'compare' => '<',
                    'type'    => 'NUMERIC',
                ],
            ];
        }

        return array_map('intval', (array) get_comments($args));
    }

    /**
     * Daily, ensured where Action Scheduler asks for it (conventions §5A): never from a request path.
     */
    public static function ensureSchedule(): void
    {
        if (function_exists('as_schedule_recurring_action')) {
            as_schedule_recurring_action(time(), DAY_IN_SECONDS, self::HOOK_REFRESH, [], self::GROUP, true);
        }
    }

    public static function unschedule(): void
    {
        if (function_exists('as_unschedule_all_actions')) {
            // Hook only, no args or group: AS then cancels every action on the hook in one query.
            as_unschedule_all_actions(self::HOOK_REFRESH);
            as_unschedule_all_actions(self::HOOK_CHECK);
        }
    }

    /**
     * `wp kaupang-review-images gravatars [--all]`: check due reviews now, synchronously.
     *
     * @param array<int, string>    $args
     * @param array<string, string> $assoc
     */
    public static function cli(array $args, array $assoc): void
    {
        $ids = self::due(PHP_INT_MAX, isset($assoc['all']));
        if (!$ids) {
            \WP_CLI::success('No review is due for a Gravatar check.');

            return;
        }

        $start = time();
        $tally = ['yes' => 0, 'no' => 0, 'unknown' => 0];
        foreach ($ids as $id) {
            // Already done in this run, as another review by the same address.
            if ((int) get_comment_meta($id, Gravatar::META_CHECKED, true) >= $start) {
                continue;
            }
            $has = self::check($id);
            $tally[$has === null ? 'unknown' : ($has ? 'yes' : 'no')]++;
        }

        $line = sprintf('Checked %d reviews (%d reviewer addresses): %d with a Gravatar, now stored locally, %d without, %d unanswered.', count($ids), array_sum($tally), $tally['yes'], $tally['no'], $tally['unknown']);
        $tally['unknown'] ? \WP_CLI::warning($line . ' The daily job retries those.') : \WP_CLI::success($line);
    }
}
