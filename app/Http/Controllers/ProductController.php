<?php

namespace App\Http\Controllers;

use App\Imports\ProductsImport;
use App\Models\Product;
use App\Models\ProductPhoto;
use App\Services\ImageFeatureClient;
use App\Services\ImageSearch;
use App\Services\ProductImageOptimizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
        $features = [];
        try {
            foreach ($validated['images'] as $image) {
                $prepared = $optimizer->prepare($image->getRealPath(), config('product_images.quality'), config('product_images.optimize_uploads'));
                try {
                    // Phase 1 changes storage only; embedding keeps the existing input pipeline.
                    $embedding = $this->createEmbedding($image);
                    $photoPath = $optimizer->store($prepared);
                    $storedPaths[] = $photoPath;
                    $feature = null;
                    if (config('image_search.index_uploads') || config('image_search.pipeline') === ImageFeatureClient::VERSION) {
                        try {
                            $feature = [app(ImageFeatureClient::class)->extract($prepared->file), hash_file('sha256', $prepared->file)];
                        } catch (\Throwable $error) {
                            Log::warning('image_features_pending', ['exception' => $error::class]);
                        }
                    }
                    $features[] = $feature;
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
            DB::transaction(function () use ($photos, $features): void {
                foreach ($photos as $index => $photo) {
                    $created = ProductPhoto::create($photo);
                    if ($features[$index] !== null) {
                        app(ImageFeatureClient::class)->store($created->id, $photo['path'], $features[$index][1], $features[$index][0]);
                    }
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
        $started = microtime(true);
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ]);

        try {
            if (config('image_search.pipeline') === ImageFeatureClient::VERSION && ! app(ImageFeatureClient::class)->pending()->exists()) {
                try {
                    $features = app(ImageFeatureClient::class)->extract($request->file('image')->getRealPath(), false);
                    $results = app(ImageSearch::class)->search($features['embedding'], $features,
                        fn (array $signals) => app(ImageFeatureClient::class)->descriptors($request->file('image')->getRealPath(), $signals));
                    Log::info('image_search_request', ['pipeline' => ImageFeatureClient::VERSION,
                        'mode' => $features['preprocessing']['mode'] ?? 'unknown',
                        'server_processing_ms' => round((microtime(true) - $started) * 1000, 2)]);

                    return view('products.search', [
                        'results' => $results,
                        'error' => null,
                    ]);
                } catch (\Throwable $error) {
                    Log::warning('image_search_legacy_fallback', ['exception' => $error::class]);
                }
            }
            $embedding = $this->createEmbedding($request->file('image'));
            $results = app(ImageSearch::class)->search($embedding);
            Log::info('image_search_request', ['pipeline' => 'legacy',
                'server_processing_ms' => round((microtime(true) - $started) * 1000, 2)]);
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
}
