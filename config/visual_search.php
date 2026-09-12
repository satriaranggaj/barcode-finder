<?php

return [
    'pipeline' => 'lensku-object-1',
    'timeout' => (int) env('AI_TIMEOUT', 45),
    'candidates' => (int) env('IMAGE_SEARCH_CANDIDATES', 30),
    'ef_search' => (int) env('IMAGE_SEARCH_EF_SEARCH', 100),
    // Written by labelled calibration, never inferred from a screenshot.
    'policy_path' => env('IMAGE_SEARCH_POLICY_PATH') ?: storage_path('app/visual-search-policy.json'),
];
