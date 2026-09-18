<?php

namespace App\Exceptions;

use RuntimeException;

class RetrievalUnavailableException extends RuntimeException
{
    public static function offline(string $detail): self
    {
        return new self('Layanan pencarian AI tidak tersedia: '.$detail);
    }

    public static function invalidResponse(string $detail): self
    {
        return new self('Respons layanan pencarian AI tidak valid: '.$detail);
    }
}
