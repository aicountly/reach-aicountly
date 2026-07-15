<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence\Connectors\DTOs;

final class ConnectorHealthResult
{
    public function __construct(
        public readonly bool    $healthy,
        public readonly string  $status,
        public readonly int     $latencyMs,
        public readonly ?string $errorMessage = null,
        public readonly ?int    $httpStatus = null,
        public readonly ?string $errorClass = null,
    ) {}

    public static function healthy(int $latencyMs): self
    {
        return new self(true, 'healthy', $latencyMs);
    }

    public static function failing(string $error, string $errorClass = 'Transient', ?int $httpStatus = null): self
    {
        return new self(false, 'failing', 0, $error, $httpStatus, $errorClass);
    }
}
