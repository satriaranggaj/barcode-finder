<?php

namespace Tests\Unit;

use App\Services\RetrievalConfig;
use Tests\TestCase;

class RetrievalConfigTest extends TestCase
{
    public function test_defaults_are_valid(): void
    {
        RetrievalConfig::validate();
        $this->assertTrue(true);
    }

    public function test_invalid_values_fail_fast(): void
    {
        $cases = [
            ['retrieval.driver' => 'faiss-typo'],
            ['retrieval.connect_timeout' => 0],
            ['retrieval.read_timeout' => 500],
            ['product_images.quality' => 50],
            ['product_images.catalog_quality' => 96],
            ['product_images.catalog_side' => 500],
            ['product_images.thumbnail_side' => 900],
            ['retrieval.reference_min_blur' => -1],
            ['retrieval.reference_min_side' => 0],
            ['retrieval.reference_min_crop_pixels' => 0],
            ['retrieval.reference_min_std' => -0.5],
            ['retrieval.reference_max_dhash_distance' => -2],
            ['retrieval.private_disk' => 'public'],
            ['retrieval.query_temp_disk' => 'unknown-disk'],
        ];
        $keys = array_unique(array_merge(...array_map('array_keys', $cases)));
        $originals = array_combine($keys, array_map(fn ($key) => config($key), $keys));
        try {
            foreach ($cases as $overrides) {
                config($originals);
                config($overrides);
                try {
                    RetrievalConfig::validate();
                    $this->fail('Invalid config accepted: '.json_encode($overrides));
                } catch (\RuntimeException $error) {
                    $this->assertStringStartsWith('Invalid retrieval configuration:', $error->getMessage());
                }
            }
        } finally {
            config($originals);
        }
    }

    public function test_feedback_flag_parses_string_false(): void
    {
        putenv('SEARCH_FEEDBACK_ENABLED=false');
        try {
            $config = require base_path('config/retrieval.php');
            $this->assertFalse($config['feedback_enabled']);
        } finally {
            putenv('SEARCH_FEEDBACK_ENABLED');
        }
        putenv('SEARCH_FEEDBACK_ENABLED=true');
        try {
            $config = require base_path('config/retrieval.php');
            $this->assertTrue($config['feedback_enabled']);
        } finally {
            putenv('SEARCH_FEEDBACK_ENABLED');
        }
    }

    public function test_empty_path_env_falls_back_to_defaults(): void
    {
        putenv('AI_PYTHON_BINARY=');
        putenv('FAISS_INDEX_PATH=');
        try {
            $config = require base_path('config/retrieval.php');
            $this->assertNotSame('', $config['python']);
            $this->assertSame(base_path('ai-service/indexes'), $config['index_path']);
        } finally {
            putenv('AI_PYTHON_BINARY');
            putenv('FAISS_INDEX_PATH');
        }
    }

    public function test_example_env_documents_ai_keys(): void
    {
        $example = file_get_contents(base_path('.env.example'));
        foreach (['AI_SERVICE_URL=', 'AI_SEARCH_MIN_SIMILARITY=', 'AI_PYTHON_BINARY=',
            'FAISS_INDEX_PATH=', 'SKU_SEARCH_DRIVER=', 'PRODUCT_IMAGE_DISK=',
            'TRAINING_IMAGE_DISK=', 'QUERY_IMAGE_DISK='] as $key) {
            $this->assertStringContainsString($key, $example);
        }
    }
}
