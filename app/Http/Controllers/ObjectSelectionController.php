<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ObjectSelectionController extends Controller
{
    public function propose(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ]);

        try {
            $response = Http::timeout(30)
                ->attach('image', fopen($validated['image']->getRealPath(), 'r'), $validated['image']->getClientOriginalName())
                ->post(rtrim((string) config('services.ai.url'), '/').'/select');

            if (!$response->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Auto-selection service tidak tersedia.',
                    'boxes' => [],
                ], 503);
            }

            $data = $response->json();

            return response()->json([
                'success' => true,
                'boxes' => $data['boxes'] ?? [],
                'message' => count($data['boxes'] ?? []) > 0 ? 'Objek terdeteksi.' : 'Tidak ada objek yang terdeteksi. Silakan pilih area secara manual.',
            ]);
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Layanan seleksi otomatis sedang tidak tersedia.',
                'boxes' => [],
            ], 503);
        }
    }
}
