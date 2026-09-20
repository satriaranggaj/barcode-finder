<?php

namespace App\Http\Controllers;

use App\Models\ProductPhoto;
use App\Services\CropCoordinates;
use App\Services\RetrievalClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ObjectSelectionController extends Controller
{
    public function propose(Request $request)
    {
        $request->validate(['image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240']]);
        try {
            $data = app(RetrievalClient::class)->send('select', $request->file('image'));
            $candidates = [];
            foreach (array_slice($data['candidates'] ?? [], 0, 5) as $candidate) {
                if (! is_array($candidate)) {
                    continue;
                }
                $candidates[] = [
                    'box' => $candidate['box'] ?? $candidate,
                    'source' => is_string($candidate['source'] ?? null) ? $candidate['source'] : 'unknown',
                    'score' => is_numeric($candidate['score'] ?? null) ? (float) $candidate['score'] : null,
                ];
            }
            if ($candidates === [] && isset($data['boxes']) && is_array($data['boxes'])) {
                // Legacy backend without candidate metadata: keep boxes working.
                $candidates = array_map(fn ($box) => ['box' => $box, 'source' => 'unknown', 'score' => null],
                    array_slice($data['boxes'], 0, 5));
            }
            $boxes = [];
            $validated = [];
            foreach ($candidates as $candidate) {
                try {
                    $candidate['box'] = CropCoordinates::validate($candidate['box']);
                } catch (ValidationException) {
                    continue;
                }
                if ($candidate['box'] === null) {
                    continue;
                }
                $validated[] = $candidate;
                $boxes[] = $candidate['box'];
            }

            return response()->json([
                'success' => true,
                'boxes' => $boxes,
                'candidates' => $validated,
                'reason' => $data['reason'] ?? 'unknown',
            ]);
        } catch (\Throwable $error) {
            return response()->json(['success' => false, 'boxes' => [], 'candidates' => [], 'reason' => 'selection_unavailable']);
        }
    }

    public function edit(ProductPhoto $photo)
    {
        return view('products.selection', compact('photo'));
    }

    public function update(Request $request, ProductPhoto $photo)
    {
        $request->validate(['crop_json' => ['nullable', 'json'], 'selection_source' => ['required', Rule::in(CropCoordinates::SELECTION_MODES)]]);
        $crop = CropCoordinates::fromJson($request->input('crop_json'));
        $photo->update(['crop' => $crop,
            'selection_source' => $crop ? $request->input('selection_source') : 'full', 'selection_verified' => true, 'index_status' => 'pending']);
        \App\Jobs\IndexVisualReference::dispatch('photo', $photo->id)->afterCommit();

        return to_route('admin.products.show', $photo->product_id)->with('success', 'Area objek disimpan. AI sedang mempelajari reference yang diperbarui.');
    }

    public function image(ProductPhoto $photo)
    {
        $disk = Storage::disk($photo->diskName());
        $path = $photo->master_path ?: $photo->path;
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, null, ['Cache-Control' => 'private, no-store']);
    }
}
