<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->timestamps();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE product_photos ADD COLUMN embedding vector(512)');
            DB::statement('INSERT INTO product_photos (product_id, path, embedding, created_at, updated_at) SELECT id, photo, embedding, created_at, updated_at FROM products WHERE photo IS NOT NULL');
        } else {
            Schema::table('product_photos', function (Blueprint $table): void {
                $table->text('embedding')->nullable();
            });
            DB::table('products')->whereNotNull('photo')->orderBy('id')->each(function (object $product): void {
                DB::table('product_photos')->insert([
                    'product_id' => $product->id,
                    'path' => $product->photo,
                    'embedding' => $product->embedding,
                    'created_at' => $product->created_at,
                    'updated_at' => $product->updated_at,
                ]);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_photos');
    }
};
