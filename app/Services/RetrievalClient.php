<?php

namespace App\Services;

use App\Exceptions\RetrievalUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RetrievalClient
{
    /**
     * Single-attempt POST to the AI service. Deliberately no retry: inference
     * is expensive and non-idempotent in cost, so timeouts (not retries) bound
     * the request inside the PHP execution budget. Logs carry endpoint and
     * timing context only, never image bytes, filenames or embeddings.
     */
    public function send(string $endpoint, mixed $image, array $fields = []): array
    {
        $stream = fopen($image->getRealPath(), 'rb');
        $connectTimeout = $this->connectTimeout();
        $readTimeout = $this->readTimeout();
        try {
            $response = Http::connectTimeout($connectTimeout)->timeout($readTimeout)
                ->attach('image', $stream, $image->getClientOriginalName())
                ->post(rtrim((string) config('services.ai.url'), '/').'/'.$endpoint, $fields);
            $response->throw();
            $data = $response->json();
            if (! is_array($data)) {
                Log::warning('AI retrieval invalid response', ['endpoint' => $endpoint, 'status' => $response->status()]);
                throw RetrievalUnavailableException::invalidResponse('body bukan JSON array (HTTP '.$response->status().')');
            }

            return $data;
        } catch (ConnectionException $exception) {
            Log::warning('AI retrieval unreachable', ['endpoint' => $endpoint,
                'connect_timeout' => $connectTimeout, 'read_timeout' => $readTimeout]);
            throw RetrievalUnavailableException::offline($exception->getMessage());
        } catch (RequestException $exception) {
            $status = $exception->response?->status() ?? 0;
            // HTTP 4xx means the AI was reached but rejected the request
            // (validation error) — not an unavailable service. Include the
            // safe server detail (e.g. why /prepare refused the image) so
            // corrupt data stays diagnosable instead of a bare status code.
            if ($status >= 400 && $status < 500) {
                Log::warning('AI retrieval request rejected', ['endpoint' => $endpoint, 'status' => $status]);
                throw RetrievalUnavailableException::invalidResponse('HTTP '.$status.self::rejectionDetail($exception));
            }
            Log::warning('AI retrieval HTTP error', ['endpoint' => $endpoint, 'status' => $exception->response?->status()]);
            throw RetrievalUnavailableException::offline('HTTP '.$exception->response?->status() ?? 'error');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function search(mixed $image, ?array $crop, bool $feedback = false, bool $fullImage = false, ?string $selectionMode = null): array
    {
        $fields = ['top_k' => 5, 'preprocessing_mode' => $crop || $fullImage ? 'original' : 'object', 'feedback_preview' => $feedback ? 'true' : 'false'];
        if ($selectionMode !== null) {
            $fields['selection_mode'] = $selectionMode;
        }
        foreach ($crop ?? [] as $key => $value) {
            $fields['crop_'.$key] = $value;
        }
        $data = $this->send('search', $image, $fields);
        if (($data['success'] ?? false) !== true || ! isset($data['results']) || ! is_array($data['results']) || count($data['results']) > 5) {
            $this->invalidContract('struktur hasil /search tidak sesuai kontrak');
        }
        foreach ($data['results'] as $row) {
            if (! is_array($row) || ! is_string($row['sku'] ?? null) || ! is_numeric($row['score'] ?? null)
                || ! is_finite((float) $row['score']) || abs((float) $row['score']) > 1.000001) {
                $this->invalidContract('kandidat hasil tidak sesuai kontrak');
            }
            // Contract forbids full embedding vectors in /search responses.
            foreach ($row as $key => $value) {
                if (is_string($key) && preg_match('/embedding|vector/i', $key)) {
                    $this->invalidContract('respons /search memuat embedding');
                }
            }
        }

        return $data;
    }

    private function invalidContract(string $reason): never
    {
        Log::warning('AI retrieval contract violation', ['reason' => $reason]);

        throw RetrievalUnavailableException::invalidResponse($reason);
    }

    /**
     * Extract a short human-readable reason from a 4xx body. Never throws,
     * never leaks bytes: FastAPI errors are {"detail": "..."}; anything else
     * is truncated plain text.
     */
    private static function rejectionDetail(RequestException $exception): string
    {
        try {
            $body = (string) $exception->response?->getBody();
        } catch (\Throwable) {
            return '';
        }
        if ($body === '') {
            return '';
        }
        try {
            $decoded = json_decode($body, true, 3, JSON_THROW_ON_ERROR);
            $snippet = is_array($decoded) ? (string) ($decoded['detail'] ?? '') : $body;
        } catch (\Throwable) {
            $snippet = $body;
        }
        $snippet = trim(preg_replace('/\s+/', ' ', $snippet) ?? '');

        return $snippet === '' ? '' : ': '.mb_strimwidth($snippet, 0, 160, '…');
    }

    private function connectTimeout(): int
    {
        return max(1, min((int) config('retrieval.connect_timeout', 3), $this->remainingSeconds()));
    }

    private function readTimeout(): int
    {
        return max(1, min((int) config('retrieval.read_timeout', 20), $this->remainingSeconds()));
    }

    /**
     * Keep the request inside the PHP execution budget instead of raising
     * max_execution_time to cover slow inference.
     */
    private function remainingSeconds(): int
    {
        $limit = (int) ini_get('max_execution_time');
        if ($limit <= 0 || ! defined('LARAVEL_START')) {
            return PHP_INT_MAX;
        }

        return max(1, $limit - (int) ceil(microtime(true) - LARAVEL_START));
    }
}
