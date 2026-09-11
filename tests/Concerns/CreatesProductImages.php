<?php

namespace Tests\Concerns;

use Illuminate\Http\UploadedFile;

trait CreatesProductImages
{
    private function productImage(string $name = 'photo.jpg', int $width = 320, int $height = 240): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        for ($x = 0; $x < $width; $x++) {
            $color = imagecolorallocate($image, $x % 256, ($x * 3) % 256, ($x * 7) % 256);
            imageline($image, $x, 0, $x, $height - 1, $color);
        }
        ob_start();
        match (pathinfo($name, PATHINFO_EXTENSION)) {
            'png' => imagepng($image),
            'webp' => imagewebp($image, null, 88),
            default => imagejpeg($image, null, 100),
        };
        $content = ob_get_clean();
        unset($image);

        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private function orientedImage(int $orientation): UploadedFile
    {
        $jpeg = $this->productImageBytes();
        $exif = "Exif\0\0II*\0".pack('V', 8).pack('v', 1)
            .pack('vvV', 0x0112, 3, 1).pack('v', $orientation)."\0\0".pack('V', 0);

        return UploadedFile::fake()->createWithContent('camera.jpg', substr($jpeg, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2));
    }

    private function productImageBytes(): string
    {
        $image = $this->productImage();

        return file_get_contents($image->getRealPath());
    }
}
