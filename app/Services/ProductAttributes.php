<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductAttribute;
use Illuminate\Support\Facades\DB;

class ProductAttributes
{
    /** Only staff-maintained values override parser evidence; vision/OCR do not. */
    public static function trustedForExport(Product $product): array
    {
        $values = [];
        foreach ($product->getRelationValue('attributes') as $attribute) {
            if ($attribute->source === ProductAttribute::SOURCE_MANUAL && trim($attribute->value) !== '') {
                $values[$attribute->key][] = DescriptionAttributes::normalize($attribute->value);
            }
        }
        ksort($values);
        foreach ($values as &$entries) {
            $entries = array_values(array_unique($entries));
            sort($entries);
        }

        return $values;
    }

    /**
     * Idempotently regenerate description_parser rows for one product.
     * Manual/vision/ocr rows and the raw description are never touched.
     */
    public static function refreshFromDescription(Product $product): int
    {
        $seen = [];
        $rows = [];
        $now = now();
        foreach (DescriptionAttributes::parse($product->description) as $record) {
            $identity = $record['key']."\0".$record['value'];
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $rows[] = [
                'product_id' => $product->id, 'key' => $record['key'], 'value' => $record['value'],
                'source' => ProductAttribute::SOURCE_DESCRIPTION_PARSER, 'rule' => $record['rule'],
                'confidence' => null, 'created_at' => $now, 'updated_at' => $now,
            ];
        }

        return DB::transaction(function () use ($product, $rows) {
            $product->attributes()->where('source', ProductAttribute::SOURCE_DESCRIPTION_PARSER)->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                $product->attributes()->insert($chunk);
            }

            return count($rows);
        });
    }
}
