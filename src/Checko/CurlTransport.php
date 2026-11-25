<?php

declare(strict_types=1);

namespace CheckoApi\Checko;

use CheckoApi\Http\ApiException;
use CurlHandle;
use SensitiveParameter;

final class CurlTransport implements Transport
{
    private const MAX_RESPONSE_BYTES = 16 * 1024 * 1024;

    public function post(string $url, #[SensitiveParameter] array $payload): UpstreamResponse
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new ApiException(502, 'upstream_error', 'Не удалось открыть соединение');
        }

        $body = '';
        $configured = curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 12,
            // Переадресация тела запроса с ключом на другой сервер
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_WRITEFUNCTION => static function (CurlHandle $handle, string $chunk) use (&$body): int {
                $bytes = strlen($chunk);
                if (strlen($body) + $bytes > self::MAX_RESPONSE_BYTES) {
                    return 0;
                }
                $body .= $chunk;
                return $bytes;
            },
        ]);

        if (!$configured || curl_exec($handle) === false) {
            if (curl_errno($handle) === CURLE_OPERATION_TIMEDOUT) {
                throw new ApiException(504, 'upstream_timeout', 'Истекло время ожидания ответа');
            }
            throw new ApiException(502, 'upstream_error', 'Не удалось получить ответ');
        }

        return new UpstreamResponse(curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $body);
    }
}
