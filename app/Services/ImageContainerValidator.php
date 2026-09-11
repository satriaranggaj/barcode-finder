<?php

namespace App\Services;

use RuntimeException;

class ImageContainerValidator
{
    /** Validate container integrity in bounded buffers, without allocating a pixel raster. */
    public function validate(string $file, string $extension): void
    {
        $stream = fopen($file, 'rb');
        try {
            $valid = match ($extension) {
                'jpg' => $this->jpeg($stream),
                'png' => $this->png($stream),
                'webp' => $this->webp($stream, filesize($file)),
            };
            if (! $valid) {
                throw new RuntimeException('Struktur gambar rusak, terpotong, atau format tidak valid.');
            }
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $stream */
    private function jpeg($stream): bool
    {
        if (fread($stream, 2) !== "\xff\xd8") {
            return false;
        }
        $frame = $scan = false;
        while (! feof($stream)) {
            if (fgetc($stream) !== "\xff") {
                return false;
            }
            do {
                $byte = fgetc($stream);
            } while ($byte === "\xff");
            if ($byte === false) {
                return false;
            }
            $marker = ord($byte);
            if ($marker === 0xD9) {
                return $frame && $scan && fread($stream, 1) === '';
            }
            if ($marker === 0 || $marker === 0xD8 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                return false;
            }
            $length = fread($stream, 2);
            if (strlen($length) !== 2) {
                return false;
            }
            $size = unpack('n', $length)[1] - 2;
            if ($size < 0) {
                return false;
            }
            $data = $size > 0 ? fread($stream, $size) : '';
            if (strlen($data) !== $size) {
                return false;
            }
            if (in_array($marker, [0xC0, 0xC1, 0xC2], true)) {
                if ($size < 6 || $size !== 6 + 3 * ord($data[5])) {
                    return false;
                }
                $frame = true;
            }
            if ($marker === 0xDA) {
                if (! $frame || $size < 4 || $size !== 4 + 2 * ord($data[0])) {
                    return false;
                }
                $scan = true;
                if (! $this->seekJpegMarker($stream)) {
                    return false;
                }
            }
        }

        return false;
    }

    /** @param resource $stream */
    private function seekJpegMarker($stream): bool
    {
        while (($buffer = fread($stream, 65536)) !== '') {
            $start = ftell($stream) - strlen($buffer);
            $offset = 0;
            while (($position = strpos($buffer, "\xff", $offset)) !== false) {
                $next = $buffer[$position + 1] ?? fgetc($stream);
                if ($next === false) {
                    return false;
                }
                $marker = ord($next);
                if ($marker === 0xFF && $position + 1 === strlen($buffer)) {
                    fseek($stream, -1, SEEK_CUR);
                }
                if ($marker !== 0 && $marker !== 0xFF && ($marker < 0xD0 || $marker > 0xD7)) {
                    fseek($stream, $start + $position);

                    return true;
                }
                $offset = $position + ($marker === 0xFF ? 1 : 2);
            }
        }

        return false;
    }

    /** @param resource $stream */
    private function png($stream): bool
    {
        if (fread($stream, 8) !== "\x89PNG\r\n\x1a\n") {
            return false;
        }
        $first = true;
        $imageData = false;
        while (strlen($header = fread($stream, 8)) === 8) {
            $chunk = unpack('Nlength/a4type', $header);
            if ($first && ($chunk['type'] !== 'IHDR' || $chunk['length'] !== 13)) {
                return false;
            }
            if (! $first && $chunk['type'] === 'IHDR') {
                return false;
            }
            $first = false;
            $hash = hash_init('crc32b');
            hash_update($hash, $chunk['type']);
            $remaining = $chunk['length'];
            while ($remaining > 0) {
                $data = fread($stream, min(65536, $remaining));
                if ($data === '') {
                    return false;
                }
                hash_update($hash, $data);
                $remaining -= strlen($data);
            }
            if (fread($stream, 4) !== hash_final($hash, true)) {
                return false;
            }
            $imageData = $imageData || ($chunk['type'] === 'IDAT' && $chunk['length'] > 0);
            if ($chunk['type'] === 'IEND') {
                return $imageData && $chunk['length'] === 0 && fread($stream, 1) === '';
            }
        }

        return false;
    }

    /** @param resource $stream */
    private function webp($stream, int $bytes): bool
    {
        $header = fread($stream, 12);
        if (strlen($header) !== 12 || substr($header, 0, 4) !== 'RIFF' || substr($header, 8) !== 'WEBP'
            || unpack('V', substr($header, 4, 4))[1] + 8 !== $bytes) {
            return false;
        }
        $imageData = false;
        while (ftell($stream) < $bytes) {
            $header = fread($stream, 8);
            if (strlen($header) !== 8) {
                return false;
            }
            $chunk = unpack('a4type/Vlength', $header);
            $size = $chunk['length'];
            if ($size + ($size % 2) > $bytes - ftell($stream)) {
                return false;
            }
            if (in_array($chunk['type'], ['VP8 ', 'VP8L'], true)) {
                $signature = fread($stream, min(10, $size));
                if (($chunk['type'] === 'VP8 ' && ($size < 10 || substr($signature, 3, 3) !== "\x9d\x01\x2a"))
                    || ($chunk['type'] === 'VP8L' && ($size < 5 || $signature[0] !== "\x2f"))) {
                    return false;
                }
                $imageData = true;
                $size -= strlen($signature);
            }
            fseek($stream, $size + ($chunk['length'] % 2), SEEK_CUR);
        }

        return $imageData && ftell($stream) === $bytes;
    }
}
