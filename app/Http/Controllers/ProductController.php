<?php

namespace App\Http\Controllers;

use App\Exceptions\RetrievalUnavailableException;
use App\Imports\ProductsImport;
use App\Models\Product;
use App\Models\ProductPhoto;
use App\Models\SearchFeedback;
use App\Services\CropCoordinates;
use App\Services\ProductAttributes;
use App\Services\ProductImages;
use App\Services\RetrievalClient;
use App\Services\SearchEvidence;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
            'pendingReferences' => $this->pendingReferenceCount(),
        ]);
    }

    /**
     * Verified eligible confirmations newer than the last successful index
     * build (all of them when never built). Approximation by design: it
     * nudges a rebuild, never gates search.
     */
    private function pendingReferenceCount(): int
    {
        $query = SearchFeedback::where('training_status', 'verified')->where('reference_eligible', true);
        $builtAt = Cache::get('visual-index-built-at');
        if (is_string($builtAt) && $builtAt !== '') {
            try {
                $query->where('created_at', '>', \Carbon\Carbon::parse($builtAt));
            } catch (\Throwable) {
                // Corrupt marker: fall through to counting everything.
            }
        }

        return $query->count();
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

        $product = Product::create([
            'sku' => trim($validated['sku']),
            'description' => trim((string) ($validated['description'] ?? '')) ?: null,
        ]);
        ProductAttributes::refreshFromDescription($product);

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
        ProductAttributes::refreshFromDescription($product->fresh());

        return to_route('admin.products.show', $product)->with('success', 'Data item berhasil diperbarui.');
    }

    public function show(Product $product): View
    {
        $product->load('photos');

        return view('products.show', compact('product'));
    }

    public function adminShow(Product $product, Request $request): View
    {
        $product->load('photos');
        // Review filter for backfilled auto-selection; public catalog always
        // shows every photo.
        $filter = in_array($request->string('selection')->toString(), ['all', 'unverified', 'verified', 'failed'], true)
            ? $request->string('selection')->toString()
            : 'all';
        if ($filter !== 'all') {
            $product->setRelation('photos', $product->photos->filter(
                fn ($photo) => match ($filter) {
                    'verified' => (bool) $photo->selection_verified,
                    'unverified' => ! $photo->selection_verified,
                    'failed' => $photo->crop === null,
                }
            )->values());
        }

        return view('products.show', compact('product') + ['selectionFilter' => $filter]);
    }

    public function uploadPhoto(Request $request, Product $product): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:10'],
            'images.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'crops' => ['nullable', 'array', 'max:10'],
            'crops.*' => ['nullable', 'array'],
            'crop_coordinates' => ['nullable', 'array', 'max:10'],
            'crop_coordinates.*' => ['nullable', 'json'],
            'selection_source' => ['nullable', Rule::in(CropCoordinates::SELECTION_MODES)],
            'selection_sources' => ['nullable', 'array', 'max:10'],
            'selection_sources.*' => [Rule::in(CropCoordinates::SELECTION_MODES)],
        ]);

        $crops = array_map(fn ($crop) => CropCoordinates::validate($crop), $validated['crops'] ?? []);
        foreach ($validated['crop_coordinates'] ?? [] as $index => $json) {
            $value = $json ? json_decode($json, true) : null;
            if ($value !== null && ! is_array($value)) {
                throw ValidationException::withMessages(['crop_coordinates' => 'Koordinat crop tidak valid.']);
            }
            $crops[$index] = CropCoordinates::validate($value);
        }

        try {
            foreach ($validated['images'] as $index => $image) {
                // Existing uploads keep their full catalog image. FAISS consumes
                // the stored crop during the controlled reference export/build.
                $crop = $crops[$index] ?? null;
                $source = $validated['selection_sources'][$index] ?? $validated['selection_source'] ?? 'full';
                $embedding = config('retrieval.driver') === 'faiss' ? null : $this->createEmbedding($image, $crop);
                $stored = app(ProductImages::class)->store($image);
                try {
                    ProductPhoto::create([
                        ...$stored,
                        'product_id' => $product->id,
                        'embedding' => $embedding === null ? null : '['.implode(',', $embedding).']',
                        'crop' => $crop,
                        'selection_source' => $crop ? $source : 'full',
                        'selection_verified' => $crop !== null,
                    ]);
                } catch (\Throwable $error) {
                    app(ProductImages::class)->discard($stored);
                    throw $error;
                }
            }
        } catch (\Throwable $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Pemrosesan foto gagal. Periksa foto yang sudah tersimpan sebelum mencoba lagi.'], 503);
            }

            return back()->withInput()->withErrors([
                'images' => 'Sebagian foto mungkin sudah tersimpan. Periksa katalog sebelum mencoba lagi; layanan pemrosesan foto sedang tidak tersedia.',
            ]);
        }

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'redirect' => route('admin.products.show', $product)]);
        }

        return to_route('admin.products.show', $product)->with('success', count($validated['images']).' design foto SKU berhasil ditambahkan.');
    }

    public function deletePhoto(ProductPhoto $photo): RedirectResponse
    {
        $product = $photo->product;
        app(ProductImages::class)->discardPhoto($photo);
        $photo->delete();

        return to_route('admin.products.show', $product)->with('success', 'Foto design berhasil dihapus.');
    }

    public function search(Request $request): View
    {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'crop' => ['nullable', 'array'],
            'crop_json' => ['nullable', 'json'],
            'selection_source' => ['nullable', Rule::in(CropCoordinates::SELECTION_MODES)],
        ]);
        $cropKey = $request->has('crop_json') ? 'crop_json' : 'crop';
        $crop = $request->has('crop_json') ? CropCoordinates::fromJson($request->input('crop_json')) : CropCoordinates::validate($request->input('crop'));
        $selectionSource = $request->input('selection_source');
        if ($selectionSource === 'full' && $crop !== null) {
            throw ValidationException::withMessages([$cropKey => 'Crop tidak dapat digunakan untuk pencarian full image.']);
        }
        if ($selectionSource === 'manual' && $crop === null) {
            throw ValidationException::withMessages([$cropKey => 'Pencarian manual memerlukan koordinat crop.']);
        }
        $selectionMode = CropCoordinates::selectionMode($selectionSource, $crop);
        $fullImage = $selectionMode === 'full';

        try {
            // Primary retrieval: FastAPI /search -> FAISS shortlist -> rerank.
            // The legacy pgvector cosine path runs only when SKU_SEARCH_DRIVER=legacy.
            if (config('retrieval.driver') === 'faiss') {
                $allowFeedback = config('retrieval.feedback_enabled') && $request->user() !== null && $request->boolean('allow_feedback');
                $report = app(RetrievalClient::class)->search($request->file('image'), $crop, $allowFeedback, $fullImage, $selectionMode);
                $feedbackToken = $allowFeedback
                    ? app(SearchEvidence::class)->stage($report, $request->user()->id, 'session-'.hash('sha256', $request->session()->getId()), $selectionMode) : null;
                $products = Product::whereIn('sku', array_column($report['results'], 'sku'))->with('photos')->get()->keyBy('sku');
                $results = collect($report['results'])->map(function ($row) use ($products) {
                    $product = $products->get($row['sku']);
                    if (! $product) {
                        return null;
                    }
                    $photoId = pathinfo(basename($row['image_id'] ?? ''), PATHINFO_FILENAME);
                    $photo = $product->photos->firstWhere('id', $photoId) ?? $product->photos->first();
                    if (! $photo) {
                        return null;
                    }
                    $product->photo = $photo->path;
                    $product->image_url = $photo->thumbnail_url;
                    $product->similarity = $row['score'];

                    return $product;
                })->filter()->values();
            } else {
                $embedding = $this->createEmbedding($request->file('image'), $crop);
                $results = $this->findSimilarProducts($embedding);
            }
        } catch (RetrievalUnavailableException $exception) {
            return view('products.search', [
                'results' => collect(),
                'error' => 'Pencarian belum dapat dilakukan. Layanan AI sedang tidak tersedia atau memberikan respons tidak valid.',
            ]);
        } catch (\Throwable $exception) {
            return view('products.search', [
                'results' => collect(),
                'error' => 'Pencarian belum dapat dilakukan. Pastikan layanan AI sedang berjalan.',
            ]);
        }

        return view('products.search', [
            'results' => $results,
            'error' => null,
            'feedbackToken' => $feedbackToken ?? null,
            // Explicit prediction for the confirmation UI; never treated as ground truth.
            'predictedSku' => isset($report) ? ($report['results'][0]['sku'] ?? null) : null,
            'searchInfo' => isset($report) ? ['confidence' => $report['confidence'] ?? 'low', 'calibrated' => $report['relevance_calibrated'] ?? false] : null,
            // Near-tie alternatives ask staff to pick the variant; heuristic flag only.
            'ambiguity' => isset($report) ? ['ambiguous' => (bool) ($report['ambiguous'] ?? false),
                'reason' => $report['ambiguity_reason'] ?? null,
                'alternatives' => array_values(array_filter(array_map(fn ($row) => is_array($row) && isset($row['sku']) ? [
                    'sku' => (string) $row['sku'], 'score' => isset($row['score']) && is_numeric($row['score']) ? (float) $row['score'] : null,
                ] : null, $report['ambiguity_alternatives'] ?? [])))] : null,
        ]);
    }

    private function createEmbedding(mixed $image, ?array $crop = null): array
    {
        $fields = [];
        foreach ($crop ?? [] as $key => $value) {
            $fields['crop_'.$key] = $value;
        }

        return app(RetrievalClient::class)->send('embed', $image, $fields)['embedding'];
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
