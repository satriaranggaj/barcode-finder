<?php

return [
    'pipeline' => 'lensku-object-1',
    'timeout' => (int) env('AI_TIMEOUT', 45),
    'candidates' => (int) env('IMAGE_SEARCH_CANDIDATES', 30),
    'ef_search' => (int) env('IMAGE_SEARCH_EF_SEARCH', 100),
    // Configurable starting defaults, not calibrated accuracy guarantees.
    'threshold' => (float) env('IMAGE_SEARCH_MIN_SCORE', 0.72),
    'min_margin' => (float) env('IMAGE_SEARCH_MIN_MARGIN', 0.04),
    // Optional global overrides; missing/invalid policies use the defaults above.
    'policy_path' => env('IMAGE_SEARCH_POLICY_PATH') ?: storage_path('app/visual-search-policy.json'),
];
