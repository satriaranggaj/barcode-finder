<?php

namespace App\Http\Controllers;

use App\Imports\ProductsImport;
use App\Models\Product;
use App\Models\ProductPhoto;
use App\Services\ProductImageOptimizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $query = Product::query()->whereHas('photos');
        $search = trim((string) $request->string('q'));

        $this->applyProductSearch($query, $search);

        $products = $query
            ->with(['photos' => fn ($query) => $query->latest('id')->limit(1)])
            ->latest('created_at')
            ->paginate(12)
            ->withQueryString();

        return view('products.index', [
            'products' => $products,
            'search' => $search,
        ]);
    }

    public function adminIndex(Request $request): View
    {
        $search = trim((string) $request->string('q'));
        $tab = $request->string('tab')->toString() === 'with-photo'
            ? 'with-photo'
            : 'without-photo';
        $productsWithoutPhotos = Product::query()
            ->whereDoesntHave('photos')
            ->when($search !== '', fn (Builder $query): Builder => $this->applyProductSearch($query, $search))
            ->latest('created_at')
            ->paginate(12)
            ->withQueryString();
        $productsWithPhotosList = Product::query()
            ->whereHas('photos')
            ->when($search !== '', fn (Builder $query): Builder => $this->applyProductSearch($query, $search))
            ->with('photos')
            ->latest('created_at')
            ->paginate(12, ['*'], 'photos_page')
            ->withQueryString();

        return view('admin.index', [
            'productsWithoutPhotos' => $productsWithoutPhotos,
            'totalProducts' => Product::count(),
            'productsWithPhotos' => Product::whereHas('photos')->count(),
            'productsWithPhotosList' => $productsWithPhotosList,
            'search' => $search,
            'tab' => $tab,
        ]);
    }

    public function importExcel(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:20480'],
        ]);

        Excel::import(new ProductsImport, $validated['file']);

        return to_route('admin.index')->with('success', 'Data katalog berhasil diimpor. SKU lama tetap dipertahankan dan deskripsi terbaru diperbarui.');
    }

    private function applyProductSearch(Builder $query, string $search): Builder
    {
        $terms = preg_split('/\s+/u', mb_strtolower(trim($search)), -1, PREG_SPLIT_NO_EMPTY);

        foreach ($terms as $term) {
            $query->where(function (Builder $builder) use ($term): void {
                $builder->whereRaw('LOWER(sku) LIKE ?', ["%{$term}%"])
                    ->orWhereRaw('LOWER(description) LIKE ?', ["%{$term}%"]);
            });
        }

        return $query;
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'sku' => ['required', 'string', 'max:255', 'unique:products,sku'],
            'description' => ['nullable', 'string'],
        ]);

        Product::create([
            'sku' => trim($validated['sku']),
            'description' => trim((string) ($validated['description'] ?? '')) ?: null,
        ]);

        return to_route('admin.index')->with('success', 'Item baru berhasil ditambahkan ke katalog.');
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $validated = $request->validate([
            'sku' => ['required', 'string', 'max:255', 'unique:products,sku,'.$product->id],
            'description' => ['nullable', 'string'],
        ]);

        $product->update([
            'sku' => trim($validated['sku']),
            'description' => trim((string) ($validated['description'] ?? '')) ?: null,
        ]);

        return to_route('admin.products.show', $product)->with('success', 'Data item berhasil diperbarui.');
    }

    public function show(Product $product): View
    {
        $product->load('photos');

        return view('products.show', compact('product'));
    }

    public function adminShow(Product $product): View
    {
        $product->load('photos');

        return view('products.show', compact('product'));
    }

    public function uploadPhoto(Request $request, Product $product, ProductImageOptimizer $optimizer): RedirectResponse
    {
        $validated = $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:'.config('product_images.max_uploads')],
            'images.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:10240'],
        ]);

        $storedPaths = [];
        $photos = [];
        try {
            foreach ($validated['images'] as $image) {
                $prepared = $optimizer->prepare($image->getRealPath(), config('product_images.quality'), config('product_images.optimize_uploads'));
                try {
                    // Phase 1 changes storage only; embedding keeps the existing input pipeline.
                    $embedding = $this->createEmbedding($image);
                    $photoPath = $optimizer->store($prepared);
                    $storedPaths[] = $photoPath;
                    $photos[] = [
                        'product_id' => $product->id,
                        'path' => $photoPath,
                        'embedding' => '['.implode(',', $embedding).']',
                        'storage_optimized_at' => config('product_images.optimize_uploads') ? now() : null,
                        'storage_optimization' => $prepared->reason,
                    ];
                } finally {
                    $prepared->cleanup();
                }
            }
            DB::transaction(function () use ($photos): void {
                foreach ($photos as $photo) {
                    ProductPhoto::create($photo);
                }
            });
        } catch (\Throwable $exception) {
            foreach ($storedPaths as $path) {
                $optimizer->discardUnreferenced($path);
            }
            report($exception);

            return back()->withInput()->withErrors([
                'images' => 'Foto belum disimpan. Pastikan gambar valid dan dimensinya sesuai batas pemrosesan; periksa penyimpanan serta layanan pencarian gambar.',
            ]);
        }

        return to_route('admin.products.show', $product)->with('success', count($validated['images']).' design foto SKU berhasil ditambahkan.');
    }

    public function deletePhoto(ProductPhoto $photo): RedirectResponse
    {
        $product = $photo->product;
        Storage::disk('public')->delete($photo->path);
        $photo->delete();

        return to_route('admin.products.show', $product)->with('success', 'Foto design berhasil dihapus.');
    }

    public function search(Request $request): View
    {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ]);

        try {
            $embedding = $this->createEmbedding($request->file('image'));
            $results = $this->findSimilarProducts($embedding);
        } catch (\Throwable $exception) {
            return view('products.search', [
                'results' => collect(),
                'error' => 'Pencarian belum dapat dilakukan. Pastikan layanan AI sedang berjalan.',
            ]);
        }

        return view('products.search', [
            'results' => $results,
            'error' => null,
        ]);
    }

    private function createEmbedding(mixed $image): array
    {
        $stream = fopen($image->getRealPath(), 'rb');
        try {
            $response = Http::timeout(45)
                ->retry(2, 250)
                ->attach('image', $stream, $image->getClientOriginalName())
                ->post(rtrim((string) config('services.ai.url'), '/').'/embed');

            $response->throw();

            return $response->json('embedding');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function findSimilarProducts(array $embedding): Collection
    {
        $vector = '['.implode(',', $embedding).']';
        $minimumSimilarity = (float) config('services.ai.search_min_similarity', 0.72);

        if (config('database.default') === 'pgsql') {
            return Product::query()
                ->join('product_photos', 'product_photos.product_id', '=', 'products.id')
                ->whereNotNull('product_photos.embedding')
                ->selectRaw('products.*, product_photos.path AS photo, 1 - (product_photos.embedding <=> CAST(? AS vector)) AS similarity', [$vector])
                ->whereRaw('1 - (product_photos.embedding <=> CAST(? AS vector)) >= ?', [$vector, $minimumSimilarity])
                ->orderByRaw('product_photos.embedding <=> CAST(? AS vector)', [$vector])
                ->limit(12)
                ->get();
        }

        return Product::query()
            ->join('product_photos', 'product_photos.product_id', '=', 'products.id')
            ->whereNotNull('product_photos.embedding')
            ->select('products.*', 'product_photos.path as photo', 'product_photos.embedding as photo_embedding')
            ->get()
            ->map(function (Product $product) use ($embedding): Product {
                $storedEmbedding = trim((string) $product->photo_embedding, '[]');
                $values = array_map('floatval', $storedEmbedding === '' ? [] : explode(',', $storedEmbedding));
                $product->similarity = $this->cosineSimilarity($embedding, $values);

                return $product;
            })
            ->filter(fn (Product $product): bool => $product->similarity >= $minimumSimilarity)
            ->sortByDesc('similarity')
            ->take(12)
            ->values();
    }

    private function cosineSimilarity(array $left, array $right): float
    {
        $dot = 0.0;
        $leftLength = 0.0;
        $rightLength = 0.0;

        foreach ($left as $index => $value) {
            $other = $right[$index] ?? 0.0;
            $dot += $value * $other;
            $leftLength += $value * $value;
            $rightLength += $other * $other;
        }

        return $leftLength && $rightLength
            ? $dot / (sqrt($leftLength) * sqrt($rightLength))
            : 0.0;
    }
}
