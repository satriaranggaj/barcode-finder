<?php

namespace Tests\Unit;

use App\Services\StoragePaths;
use PHPUnit\Framework\TestCase;

class StoragePathsTest extends TestCase
{
    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public function test_product_variant_paths_are_server_generated(): void
    {
        $paths = new StoragePaths;
        $path = $paths->productVariant('master', 'jpg');
        $this->assertMatchesRegularExpression('#^products/'.self::UUID.'-master\.jpg$#', $path);
        $this->assertNotSame($path, $paths->productVariant('master', 'jpg'));
        $this->assertStringContainsString('-catalog.', $paths->productVariant('catalog', 'webp'));
        $this->assertStringContainsString('-thumbnail.', $paths->productVariant('thumbnail', 'png'));
    }

    public function test_safe_extension_normalizes_to_allowlist(): void
    {
        $paths = new StoragePaths;
        $this->assertSame('jpg', $paths->safeExtension('jpeg'));
        $this->assertSame('jpg', $paths->safeExtension('JPG'));
        $this->assertSame('png', $paths->safeExtension('png'));
        $this->assertSame('webp', $paths->safeExtension('webp'));
    }

    public function test_safe_extension_rejects_unknown_formats(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new StoragePaths)->safeExtension('gif');
    }

    public function test_transient_and_verified_paths_are_prefixed_and_uuid_named(): void
    {
        $paths = new StoragePaths;
        $this->assertMatchesRegularExpression('#^search-pending/'.self::UUID.'\.webp$#', $paths->pendingQuery());
        $this->assertMatchesRegularExpression('#^verified-search/'.self::UUID.'\.webp$#', $paths->verifiedReference());
        $this->assertMatchesRegularExpression('#^index-builds/'.self::UUID.'$#', $paths->indexBuild());
    }
}
