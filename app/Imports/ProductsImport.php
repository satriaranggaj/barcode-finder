<?php

namespace App\Imports;

use App\Models\Product;
use App\Services\ProductAttributes;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ProductsImport implements ToCollection, WithChunkReading, WithHeadingRow
{
    public function collection(Collection $rows): void
    {
        $rowsBySku = $rows->mapWithKeys(function (mixed $row): array {
            $row = $row instanceof Collection ? $row->all() : $row;
            $sku = trim((string) ($row['sku'] ?? ''));

            return $sku === '' ? [] : [
                $sku => trim((string) ($row['desc'] ?? '')),
            ];
        });

        if ($rowsBySku->isEmpty()) {
            return;
        }

        $existingDescriptions = Product::query()
            ->whereIn('sku', $rowsBySku->keys())
            ->pluck('description', 'sku');
        $now = now();
        $records = [];

        foreach ($rowsBySku as $sku => $description) {
            if ($existingDescriptions->has($sku) && $existingDescriptions->get($sku) === $description) {
                continue;
            }

            $records[] = [
                'sku' => $sku,
                'description' => $description,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($records === []) {
            return;
        }

        Product::upsert($records, ['sku'], ['description', 'updated_at']);

        // Upsert bypasses model events, so parser rows refresh explicitly.
        // Manual/vision/ocr attribute rows are never touched by the refresh.
        foreach (Product::query()->whereIn('sku', array_column($records, 'sku'))->lazyById(200) as $product) {
            ProductAttributes::refreshFromDescription($product);
        }
    }

    public function chunkSize(): int
    {
        return 500;
    }
}
