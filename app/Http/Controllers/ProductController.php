<?php

namespace App\Http\Controllers;

use App\Imports\ProductsImport;
use App\Models\Product;
use App\Models\ProductPhoto;
use App\Services\VisualRepresentation;
use App\Services\VisualSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function uploadPhoto(Request $request, Product $product): RedirectResponse
    {
        $validated = $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:10'],
            'images.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ]);

        $pending = 0;
        foreach ($validated['images'] as $image) {
            $photoPath = $image->store('products', 'public');
            try {
                $photo = ProductPhoto::create(['product_id' => $product->id, 'path' => $photoPath]);
            } catch (\Throwable $exception) {
                Storage::disk('public')->delete($photoPath);
                throw $exception;
            }
            try {
                app(VisualRepresentation::class)->index($photo);
            } catch (\Throwable $exception) {
                $pending++;
                Log::warning('visual_index_pending', ['photo_id' => $photo->id, 'exception' => $exception::class]);
            }
        }
        if ($pending) {
            return to_route('admin.products.show', $product)->with('success', count($validated['images']).' foto disimpan. '.$pending.' foto menunggu pengindeksan ulang.');
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
            $representation = app(VisualRepresentation::class)->extract($request->file('image')->getRealPath());
            $results = app(VisualSearch::class)->search($representation);
        } catch (\Throwable $exception) {
            Log::warning('visual_search_failure', ['exception' => $exception::class]);

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
}
