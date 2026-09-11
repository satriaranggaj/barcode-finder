<?php

namespace App\Services;

class PreparedProductImage
{
    public function __construct(
        public readonly string $file,
        public readonly string $extension,
        public readonly string $reason,
        public readonly int $beforeBytes,
        public readonly int $afterBytes,
        public readonly bool $temporary = false,
    ) {}

    public function cleanup(): void
    {
        if ($this->temporary && is_file($this->file)) {
            unlink($this->file);
        }
    }
}
