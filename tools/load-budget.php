<?php
// Read by `wp kaupang-assets audit --budget`. Unlisted page types fall back to '*', then to the default (2 files, nothing else).
// A guest page loads the main file and Plugin only; product reviews and the Curated Review block load their own
// modules where they render. Per-file reasons: README "Load map".
// +1 file and the -list handle where the review list is long enough to cap (Reviews\ListCap).
$reviews = ['files' => 10, 'handles' => ['kaupang-review-images', 'kaupang-review-images-toggle', 'kaupang-review-images-list'], 'modules' => []];
$block   = [
    'files'   => 9,
    'handles' => ['kaupang-review-images-curated-review-style'],
    'modules' => ['kaupang-review-images-curated-review-view-script-module'],
];

return [
    'autoload' => [],
    'pages'    => [
        '*'                => ['files' => 2, 'handles' => [], 'modules' => []],
        'product_simple'   => $reviews,
        'product_variable' => $reviews,
        'faq'              => $reviews, // resolves to a product on staging (myrvann-cacao)
        'front_page'       => $block,
        'pac'              => $block,
    ],
];
