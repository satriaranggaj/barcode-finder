<?php

return [
    'pipeline' => env('IMAGE_SEARCH_PIPELINE', 'legacy'),
    'index_uploads' => (bool) env('IMAGE_SEARCH_INDEX_UPLOADS', false),
    'candidates' => (int) env('IMAGE_SEARCH_CANDIDATES', 50),
    'ef_search' => (int) env('IMAGE_SEARCH_EF_SEARCH', 100),
    'adaptive_reranking' => (bool) env('IMAGE_SEARCH_ADAPTIVE_RERANKING', true),
    'confidence_similarity' => (float) env('IMAGE_SEARCH_CONFIDENCE_SIMILARITY', .90),
    'confidence_margin' => (float) env('IMAGE_SEARCH_CONFIDENCE_MARGIN', .08),
    // Populate from a frozen, labelled calibration run; never infer a default.
    'final_min_score' => env('IMAGE_SEARCH_FINAL_MIN_SCORE') === '' ? null : env('IMAGE_SEARCH_FINAL_MIN_SCORE'),
    // Additional signals remain off until a labelled benchmark validates weights.
    'weights' => [
        'global' => (float) env('IMAGE_SEARCH_WEIGHT_GLOBAL', 1),
        'shape' => (float) env('IMAGE_SEARCH_WEIGHT_SHAPE', 0),
        'color' => (float) env('IMAGE_SEARCH_WEIGHT_COLOR', 0),
        'texture' => (float) env('IMAGE_SEARCH_WEIGHT_TEXTURE', 0),
        'local' => (float) env('IMAGE_SEARCH_WEIGHT_LOCAL', 0),
        'proportion' => (float) env('IMAGE_SEARCH_WEIGHT_PROPORTION', 0),
    ],
];
