<?php

declare(strict_types=1);

namespace Tests\Support;

final class Response
{
    public function __construct(public int $status, public array $headers, public string $body)
    {
    }

    public function location(): ?string
    {
        return $this->headers['location'] ?? null;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
