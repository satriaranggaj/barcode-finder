<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\ProductAttributes;
use Illuminate\Console\Command;

class RefreshProductAttributes extends Command
{
    protected $signature = 'products:refresh-attributes {--sku= : Only regenerate one SKU}';

    protected $description = 'Regenerate description_parser attributes without touching manual rows or raw descriptions';

    public function handle(): int
    {
        $query = Product::query()->orderBy('id');
        if ($sku = $this->option('sku')) {
            $query->where('sku', $sku);
        }
        $products = 0;
        $rows = 0;
        foreach ($query->lazyById(200) as $product) {
            $rows += ProductAttributes::refreshFromDescription($product);
            $products++;
        }
        $this->info("Refreshed {$rows} parsed attributes across {$products} products.");

        return self::SUCCESS;
    }
}
