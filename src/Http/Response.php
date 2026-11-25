<?php
declare(strict_types=1);

namespace CheckoApi\Http;

final readonly class Response
{
    public function __construct(
        public int $status,
        public string $body,
        public array $headers,
    ) {
    }

    public static function json(array $data, int $status = 200, array $headers = []): self
    {
        return new self(
            $status,
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ['Content-Type' => 'application/json; charset=utf-8'] + $headers,
        );
    }

    public static function error(ApiException $exception): self
    {
        $headers = match ($exception->status) {
            401 => ['WWW-Authenticate' => 'Bearer realm="checko-api"'],
            405 => ['Allow' => 'GET'],
            default => [],
        };
        return self::json([
            'error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()],
        ], $exception->status, $headers);
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }
}
