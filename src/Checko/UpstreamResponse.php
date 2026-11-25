<?php

declare(strict_types=1);

namespace CheckoApi\Checko;

final readonly class UpstreamResponse
{
    public function __construct(public int $status, public string $body)
    {
    }
}
