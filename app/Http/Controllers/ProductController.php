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
            'indexBuilding' => (bool) Cache::get('visual-index-building'),
            'indexRebuildRequired' => app(\App\Services\VisualIndexLifecycle::class)->dirtyReason(),
            'indexFailure' => app(\App\Services\VisualIndexLifecycle::class)->failure(),
        ]);
    }

    /**
     * References not yet in any published generation: catalog photos awaiting
     * (pending/indexing/failed/rebuild-required) plus eligible confirmations
     * never indexed. Informational only; it nudges, never gates search.
     */
    private function pendingReferenceCount(): int
    {
        $photos = ProductPhoto::whereIn('index_status', ['pending', 'indexing', 'failed', 'rebuild-required'])->count();
        $feedback = SearchFeedback::where('training_status', 'verified')
            ->where('reference_eligible', true)->whereNull('indexed_at')->count();

        return $photos + $feedback;
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

        $oldSku = $product->sku;
        $oldDescription = $product->description;
        $newSku = trim($validated['sku']);
        $newDescription = trim((string) ($validated['description'] ?? '')) ?: null;
        $product->update(['sku' => $newSku, 'description' => $newDescription]);
        ProductAttributes::refreshFromDescription($product->fresh());

        // FAISS HNSW vectors are immutable: indexed references whose SKU or
        // description changed can only be replaced by a full rebuild, which
        // is scheduled automatically (no manual command needed).
        // Pending photos stay pending (fresh append, no rebuild needed).
        if ($oldSku !== $newSku || $oldDescription !== $newDescription) {
            $indexed = $product->photos()->whereIn('index_status', \App\Services\VisualIndexLifecycle::SERVED_STATUSES)->pluck('id')->all();
            app(\App\Services\VisualIndexLifecycle::class)->invalidateServedPhotos(
                $indexed, "product {$product->id} metadata changed; full rebuild required");
        }

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
                    $photo = ProductPhoto::create([
                        ...$stored,
                        'product_id' => $product->id,
                        'embedding' => $embedding === null ? null : '['.implode(',', $embedding).']',
                        'crop' => $crop,
                        'selection_source' => $crop ? $source : 'full',
                        'selection_verified' => $crop !== null,
                    ]);
                    // Indexing runs in the background: the upload responds
                    // immediately while the reference joins the next index
                    // generation on its own (hot-reloaded, no restart).
                    \App\Jobs\IndexVisualReference::dispatch('photo', $photo->id)->afterCommit();
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

        return to_route('admin.products.show', $product)->with('success', count($validated['images']).' design foto SKU berhasil ditambahkan. AI sedang mempelajari reference.');
    }

    public function deletePhoto(ProductPhoto $photo): RedirectResponse
    {
        $product = $photo->product;
        $wasIndexed = in_array($photo->index_status, ['indexed', 'indexing', 'rebuild-required'], true);
        app(ProductImages::class)->discardPhoto($photo);
        $photo->delete();
        // HNSW vectors cannot be removed safely: a full rebuild regenerates
        // the generation without this reference, scheduled automatically.
        // Pending photos never reached the index, so they need no rebuild.
        if ($wasIndexed) {
            app(\App\Services\VisualIndexLifecycle::class)->markDirty("photo {$photo->id} deleted; full rebuild required");
        }

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
                // Display hygiene (uncalibrated): hide sub-threshold rows so
                // obvious junk never reaches staff. Unlike the frozen
                // relevance policy (API-side gate with calibrated flags),
                // this only affects rendering; staged evidence keeps the
                // full AI view for audit, and predictedSku stays the AI's
                // true top-1. Tune the value from eval trials, never by gut
                // feel (see ai-service/policies/README.md).
                $minSimilarity = (float) config('services.ai.search_min_similarity', 0.72);
                $displayRows = array_values(array_filter(
                    $report['results'],
                    fn ($row) => is_numeric($row['score'] ?? null) && (float) $row['score'] >= $minSimilarity
                ));
                $products = Product::whereIn('sku', array_column($displayRows, 'sku'))->with('photos')->get()->keyBy('sku');
                $results = collect($displayRows)->map(function ($row) use ($products) {
                    $product = is_array($row) ? $products->get($row['sku'] ?? null) : null;
                    if (! $product) {
                        return null;
                    }
                    // SKU score stays the best-reference score from aggregate_skus()
                    // (max semantics, no count bias). The card cover is the
                    // best-scoring matched reference that resolves to a
                    // ProductPhoto of THIS product. Verified feedback
                    // references may win retrieval but never become covers;
                    // only ProductPhoto assets are shown publicly.
                    $photo = $this->resolveMatchedProductPhoto($product, is_array($row) ? $row : [])
                        ?? $product->photos->first();
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

    /**
     * Best-matching catalog cover for a returned SKU (request-local display only).
     *
     * SKU score = best matching reference score (aggregate_skus max semantics).
     * Display cover = best-scoring matched catalog reference resolvable to a
     * ProductPhoto of THIS product, trying winning image_id first then
     * matched_image_ids in score order. Verified feedback references
     * (verified-*) may affect retrieval but never become covers; only
     * ProductPhoto rows are shown. Operates on the already eager-loaded
     * $product->photos collection (no N+1). Returns null when nothing
     * resolves so callers can use the normal product cover fallback.
     */
    private function resolveMatchedProductPhoto(Product $product, array $row): ?ProductPhoto
    {
        $candidates = [];
        if (isset($row['image_id']) && is_string($row['image_id']) && $row['image_id'] !== '') {
            $candidates[] = $row['image_id'];
        }
        $matched = $row['matched_image_ids'] ?? [];
        if (is_array($matched)) {
            foreach ($matched as $candidate) {
                if (is_string($candidate) && $candidate !== '') {
                    $candidates[] = $candidate;
                }
            }
        }
        // Deduplicate while preserving best-to-worst order; bounded so a
        // malformed payload cannot force an unbounded loop.
        $ordered = [];
        $seen = [];
        foreach ($candidates as $candidate) {
            if (! isset($seen[$candidate])) {
                $seen[$candidate] = true;
                $ordered[] = $candidate;
                if (count($ordered) >= 25) {
                    break;
                }
            }
        }
        foreach ($ordered as $candidate) {
            $photoId = self::catalogPhotoIdFromReference($candidate);
            if ($photoId === null) {
                continue;
            }
            $photo = $product->photos->firstWhere('id', $photoId);
            // Ownership check prevents cross-product image leakage: only a
            // photo already belonging to this product (via the eager-loaded
            // relation) may become its cover.
            if ($photo instanceof ProductPhoto && (int) $photo->product_id === (int) $product->id) {
                return $photo;
            }
        }

        return null;
    }

    /**
     * Map an indexed reference id to a catalog ProductPhoto id, or null.
     *
     * Catalog refs are "<photoId>.<ext>" (e.g. 103.webp); feedback refs are
     * "verified-<id>[.<ext>]". Only a basename stem that is an unambiguous
     * numeric id resolves; verified-* and any non-numeric stem never do, so
     * verified-123 can never be misread as photo 123.
     */
    private static function catalogPhotoIdFromReference(string $reference): ?int
    {
        $reference = trim($reference);
        if ($reference === '') {
            return null;
        }
        $stem = trim((string) pathinfo(basename($reference), PATHINFO_FILENAME));
        if ($stem === '' || ! ctype_digit($stem)) {
            return null;
        }

        return (int) $stem;
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
