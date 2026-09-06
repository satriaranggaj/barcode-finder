<?php

namespace App\Http\Controllers;

use App\Imports\ProductsImport;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $query = Product::query()->whereNotNull('photo');
        $search = trim((string) $request->string('q'));

        $this->applyProductSearch($query, $search);

        $products = $query
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
        $productsWithoutPhotos = Product::query()
            ->whereNull('photo')
            ->when($search !== '', fn (Builder $query): Builder => $this->applyProductSearch($query, $search))
            ->latest('created_at')
            ->paginate(12)
            ->withQueryString();

        return view('admin.index', [
            'productsWithoutPhotos' => $productsWithoutPhotos,
            'totalProducts' => Product::count(),
            'productsWithPhotos' => Product::whereNotNull('photo')->count(),
            'search' => $search,
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

    public function show(Product $product): View
    {
        return view('products.show', compact('product'));
    }

    public function adminShow(Product $product): View
    {
        if ($product->photo && ! auth()->user()->isSuperAdmin()) {
            abort(403);
        }

        return view('products.show', compact('product'));
    }

    public function uploadPhoto(Request $request, Product $product): RedirectResponse
    {
        if ($product->photo && ! $request->user()->isSuperAdmin()) {
            abort(403);
        }

        $validated = $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ]);

        try {
            $embedding = $this->createEmbedding($validated['image']);
        } catch (\Throwable $exception) {
            return back()->withInput()->withErrors([
                'image' => 'Foto belum disimpan karena layanan pencarian gambar sedang tidak tersedia.',
            ]);
        }

        $productDisk = config('filesystems.product');
        $photoPath = $validated['image']->store('products', $productDisk);

        if ($product->photo) {
            Storage::disk($productDisk)->delete($product->photo);
        }

        $product->forceFill([
            'photo' => $photoPath,
            'embedding' => '['.implode(',', $embedding).']',
        ])->save();

        return to_route('admin.products.show', $product)->with('success', 'Foto SKU berhasil disimpan.');
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
        $response = Http::timeout(45)
            ->retry(2, 250)
            ->attach('image', fopen($image->getRealPath(), 'r'), $image->getClientOriginalName())
            ->post(rtrim((string) config('services.ai.url'), '/').'/embed');

        $response->throw();

        return $response->json('embedding');
    }

    private function findSimilarProducts(array $embedding): Collection
    {
        $vector = '['.implode(',', $embedding).']';
        $minimumSimilarity = (float) config('services.ai.search_min_similarity', 0.72);

        if (config('database.default') === 'pgsql') {
            return Product::query()
                ->whereNotNull('embedding')
                ->selectRaw('products.*, 1 - (embedding <=> CAST(? AS vector)) AS similarity', [$vector])
                ->whereRaw('1 - (embedding <=> CAST(? AS vector)) >= ?', [$vector, $minimumSimilarity])
                ->orderByRaw('embedding <=> CAST(? AS vector)', [$vector])
                ->limit(12)
                ->get();
        }

        return Product::query()
            ->whereNotNull('embedding')
            ->get()
            ->map(function (Product $product) use ($embedding): Product {
                $storedEmbedding = trim((string) $product->embedding, '[]');
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
