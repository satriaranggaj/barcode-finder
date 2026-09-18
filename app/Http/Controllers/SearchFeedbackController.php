<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\SearchFeedback;
use App\Services\DuplicateGuard;
use App\Services\FeedbackPayload;
use App\Services\ReferenceQuality;
use App\Services\StoragePaths;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class SearchFeedbackController extends Controller
{
    public function store(Request $request)
    {
        abort_unless(config('retrieval.feedback_enabled'), 404);
        $validated = $request->validate(['token' => ['required', 'uuid'], 'sku' => ['required', 'string', 'exists:products,sku']]);
        $token = $validated['token'];

        return Cache::lock('feedback-lock:'.$token, 30)->block(3, function () use ($request, $validated, $token) {
            $pending = Cache::get('search-evidence:'.$token);
            abort_unless($pending && $pending['user_id'] === $request->user()->id, 403);
            $quality = $pending['evidence'];
            $product = Product::where('sku', $validated['sku'])->firstOrFail();
            $duplicate = DuplicateGuard::check($quality['photo_hash'], $quality['dhash'], $product->id);
            if ($duplicate['status'] === DuplicateGuard::EXACT) {
                try {
                    Storage::disk($pending['disk'])->delete($pending['path']);
                } catch (\Throwable $error) {
                    report($error);
                }
                Cache::forget('search-evidence:'.$token);

                return back()->withErrors(['sku' => 'Foto ini sudah dikonfirmasi; label tidak diubah otomatis.']);
            }
            $nearDuplicate = $duplicate['status'] === DuplicateGuard::NEAR;
            // Fail closed before any storage write: unbounded or malformed
            // staged evidence (including any embedding vectors) never persists.
            $payload = FeedbackPayload::verifiedAttributes($pending);
            $targetDisk = config('retrieval.private_disk');
            abort_if($targetDisk === 'public', 503);
            $path = app(StoragePaths::class)->verifiedReference();
            $source = Storage::disk($pending['disk']);
            $target = Storage::disk($targetDisk);
            $stream = null;
            try {
                $stream = $source->readStream($pending['path']);
                abort_unless(is_resource($stream) && $target->writeStream($path, $stream, ['visibility' => 'private']), 503);
                $assessment = ReferenceQuality::evaluate($quality);
                $reasons = $assessment['reasons'];
                if ($nearDuplicate) {
                    $reasons[] = 'near_duplicate';
                }
                SearchFeedback::create([
                    'user_id' => $request->user()->id, 'confirmed_product_id' => $product->id,
                    'predicted_sku' => $payload['candidates'][0]['sku'] ?? null, 'confirmed_sku' => $product->sku,
                    'candidates' => $payload['candidates'], 'confidence' => $payload['confidence'],
                    'score_gap' => $payload['score_gap'], 'selection_source' => $payload['selection_source'],
                    'disk' => $targetDisk, 'query_image_path' => $path,
                    'photo_hash' => $quality['photo_hash'], 'dhash' => $quality['dhash'], 'crop' => $pending['crop'],
                    'training_status' => 'verified',
                    'evidence' => FeedbackPayload::sanitizedEvidence($pending, $reasons),
                    'reference_eligible' => ! $nearDuplicate && $assessment['eligible'],
                ]);
            } catch (\Throwable $error) {
                try {
                    $target->delete($path);
                } catch (\Throwable $cleanupError) {
                    report($cleanupError);
                }
                throw $error;
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            // Pending removal must never fail a stored confirmation; the
            // scheduled pruner reclaims stragglers.
            try {
                $source->delete($pending['path']);
            } catch (\Throwable $error) {
                report($error);
            }
            Cache::forget('search-evidence:'.$token);

            return to_route('products.show', $product)->with('success', 'Konfirmasi disimpan sebagai data terverifikasi.');
        });
    }
}
