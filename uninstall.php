<?php
/**
 * Uninstall: remove what this plugin derived, keep what customers made.
 *
 * Review photos and avatars are customer content (attachments + `_review_image_id` /
 * `_review_author_avatar_id`): they stay. The Gravatar answers are a cache of
 * gravatar.com and go, with their jobs.
 *
 * @package KaupangReviewImages
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->commentmeta} WHERE meta_key IN (%s, %s)",
    '_kaupang_review_images_gravatar',
    '_kaupang_review_images_gravatar_checked'
));

// The 1-day transients the old synchronous lookup wrote.
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
    $wpdb->esc_like('_transient_kaupang_review_images_has_gravatar_') . '%',
    $wpdb->esc_like('_transient_timeout_kaupang_review_images_has_gravatar_') . '%'
));

if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions('kaupang_review_images_refresh_gravatars');
    as_unschedule_all_actions('kaupang_review_images_check_gravatar');
}

wp_cache_flush_group('comment');
