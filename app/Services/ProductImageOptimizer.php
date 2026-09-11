<?php

namespace App\Services;

use GdImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ProductImageOptimizer
{
    public function assertAvailable(): void
    {
        if (! extension_loaded('gd') || ! (gd_info()['WebP Support'] ?? false)) {
            throw new RuntimeException('PHP GD dengan dukungan WebP diperlukan.');
        }
        if (config('filesystems.disks.public.driver') !== 'local') {
            throw new RuntimeException('Optimasi ini memerlukan public disk lokal.');
        }
    }

    public function localPath(string $path): string
    {
        $root = realpath(Storage::disk('public')->path(''));
        $file = realpath(Storage::disk('public')->path($path));
        if ($root === false || $file === false || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR) || ! is_file($file)) {
            throw new RuntimeException('File tidak ditemukan atau berada di luar public disk.');
        }

        return $file;
    }

    public function prepare(string $source, int $quality, bool $optimize = true): PreparedProductImage
    {
        $lock = fopen(storage_path('framework/product-image-optimizer.lock'), 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('Pemrosesan gambar sedang sibuk; coba lagi setelah proses lain selesai.');
        }
        try {
            return $this->prepareImage($source, $quality, $optimize);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function prepareImage(string $source, int $quality, bool $optimize): PreparedProductImage
    {
        $this->assertAvailable();
        if ($quality < 1 || $quality > 100) {
            throw new RuntimeException('Quality harus antara 1 dan 100.');
        }
        $bytes = filesize($source);
        if ($bytes === false || $bytes < 1 || $bytes > config('product_images.max_bytes')) {
            throw new RuntimeException('Ukuran file melampaui batas pemrosesan.');
        }
        $info = @getimagesize($source, $markers);
        $extension = match ($info[2] ?? null) {
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_WEBP => 'webp',
            default => throw new RuntimeException('Gambar harus berupa JPEG, PNG, atau WebP yang valid.'),
        };
        $pixels = $info[0] * $info[1];
        $estimatedBytes = $pixels * 12 + $bytes * 2 + 16 * 1024 * 1024;
        $memoryLimit = ini_parse_quantity(ini_get('memory_limit'));
        $budget = (int) config('product_images.memory_budget_mb') * 1024 * 1024;
        if ($memoryLimit > 0) {
            $budget = min($budget, $memoryLimit);
        }
        if ($pixels < 1 || $pixels > config('product_images.max_pixels') || $estimatedBytes + memory_get_usage(true) > $budget) {
            throw new RuntimeException('Dimensi gambar melampaui batas pixel atau anggaran memori.');
        }

        $image = $this->decode($source, $extension);
        $temporary = null;
        try {
            $preserve = match (true) {
                ! $optimize => 'disabled',
                $extension === 'webp' => 'existing_webp',
                ($info['channels'] ?? 3) === 4 => 'preserved_cmyk',
                str_contains($markers['APP2'] ?? '', 'ICC_PROFILE') => 'preserved_profile',
                $extension === 'png' && (($info['bits'] ?? 8) > 8 || $this->protectedPng($source)) => 'preserved_png_metadata',
                $extension === 'jpg' && isset($markers['APP1']) && ! function_exists('exif_read_data') => 'preserved_exif',
                default => null,
            };
            if ($preserve !== null) {
                return new PreparedProductImage($source, $extension, $preserve, $bytes, $bytes);
            }

            if ($extension === 'jpg' && isset($markers['APP1'])) {
                $exif = @exif_read_data($source);
                if ($exif === false) {
                    return new PreparedProductImage($source, $extension, 'preserved_exif', $bytes, $bytes);
                }
                $image = $this->orient($image, (int) ($exif['Orientation'] ?? 1));
            }
            imagepalettetotruecolor($image);
            imagealphablending($image, false);
            imagesavealpha($image, true);
            $width = imagesx($image);
            $height = imagesy($image);
            $temporary = tempnam(sys_get_temp_dir(), 'lensku-webp-');
            if ($temporary === false || ! imagewebp($image, $temporary, $quality)) {
                throw new RuntimeException('Encoding WebP gagal.');
            }
            unset($image);
            clearstatcache(true, $temporary);
            $encodedBytes = filesize($temporary);
            $encodedInfo = @getimagesize($temporary);
            if (! $encodedBytes || ($encodedInfo[2] ?? null) !== IMAGETYPE_WEBP || $encodedInfo[0] !== $width || $encodedInfo[1] !== $height) {
                throw new RuntimeException('Validasi hasil WebP gagal.');
            }
            $verified = $this->decode($temporary, 'webp');
            unset($verified);
            if ($encodedBytes >= $bytes) {
                unlink($temporary);

                return new PreparedProductImage($source, $extension, 'no_savings', $bytes, $bytes);
            }

            return new PreparedProductImage($temporary, 'webp', 'webp', $bytes, $encodedBytes, true);
        } catch (Throwable $exception) {
            if ($temporary && is_file($temporary)) {
                unlink($temporary);
            }
            throw $exception;
        } finally {
            unset($image);
        }
    }

    public function store(PreparedProductImage $image): string
    {
        $path = 'products/'.Str::uuid().'.'.$image->extension;
        $stream = fopen($image->file, 'rb');
        try {
            if ($stream === false || ! Storage::disk('public')->put($path, $stream)) {
                throw new RuntimeException('Penyimpanan foto gagal.');
            }
            $stored = $this->localPath($path);
            if (filesize($stored) !== $image->afterBytes || hash_file('sha256', $stored) !== hash_file('sha256', $image->file)) {
                throw new RuntimeException('Verifikasi file tersimpan gagal.');
            }

            return $path;
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($path);
            throw $exception;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function discardUnreferenced(string $path): bool
    {
        try {
            if (DB::table('product_photos')->where('path', $path)->exists()
                || DB::table('products')->where('photo', $path)->exists()) {
                return false;
            }

            return Storage::disk('public')->delete($path);
        } catch (Throwable $exception) {
            // A lost connection during commit may leave a valid reference; retain the file if uncertain.
            report($exception);

            return false;
        }
    }

    private function decode(string $file, string $extension): GdImage
    {
        $ignoreJpegWarnings = ini_get('gd.jpeg_ignore_warning');
        ini_set('gd.jpeg_ignore_warning', '0');
        set_error_handler(static function (): never {
            throw new RuntimeException('Gambar rusak atau tidak dapat didecode dengan aman.');
        });
        try {
            $image = match ($extension) {
                'jpg' => imagecreatefromjpeg($file),
                'png' => imagecreatefrompng($file),
                'webp' => imagecreatefromwebp($file),
            };
        } finally {
            restore_error_handler();
            ini_set('gd.jpeg_ignore_warning', $ignoreJpegWarnings);
        }
        if (! $image instanceof GdImage) {
            throw new RuntimeException('Gambar rusak atau tidak dapat didecode.');
        }

        return $image;
    }

    private function orient(GdImage $image, int $orientation): GdImage
    {
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, in_array($orientation, [4, 5], true) ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
        }
        $angle = match ($orientation) {
            3 => 180,
            5, 6, 7 => -90,
            8 => 90,
            default => 0,
        };
        if ($angle !== 0) {
            $rotated = imagerotate($image, $angle, 0);
            if (! $rotated instanceof GdImage) {
                throw new RuntimeException('Normalisasi orientasi gagal.');
            }
            $image = $rotated;
        }

        return $image;
    }

    private function protectedPng(string $file): bool
    {
        $stream = fopen($file, 'rb');
        try {
            fseek($stream, 8);
            while (strlen($header = fread($stream, 8)) === 8) {
                $chunk = unpack('Nlength/a4type', $header);
                if (in_array($chunk['type'], ['iCCP', 'cICP', 'acTL', 'eXIf', 'gAMA'], true)) {
                    return true;
                }
                if (in_array($chunk['type'], ['IDAT', 'IEND'], true)) {
                    break;
                }
                fseek($stream, $chunk['length'] + 4, SEEK_CUR);
            }

            return false;
        } finally {
            fclose($stream);
        }
    }
}
