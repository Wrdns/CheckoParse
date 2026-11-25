<?php

declare(strict_types=1);

namespace CheckoApi\Checko;

interface Transport
{
    /** @param array<string, string> $payload Параметры запроса к сервису. */
    public function post(string $url, array $payload): UpstreamResponse;
}
