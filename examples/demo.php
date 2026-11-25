<?php
declare(strict_types=1);

use CheckoApi\Checko\CheckoClient;
use CheckoApi\Checko\Transport;
use CheckoApi\Checko\UpstreamResponse;
use CheckoApi\Http\ApiController;

require dirname(__DIR__) . '/bootstrap.php';

// Демосервер, чтение карточек
$samples = new class implements Transport {
    public function post(string $url, array $payload): UpstreamResponse
    {
        $sample = match ($url) {
            'https://api.checko.ru/v2/company' => 'company',
            'https://api.checko.ru/v2/entrepreneur' => 'entrepreneur',
        };
        $body = file_get_contents(__DIR__ . '/responses/' . $sample . '.json');
        $record = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        return $record['data']['ИНН'] === $payload['inn']
            ? new UpstreamResponse(200, $body)
            : new UpstreamResponse(404, '');
    }
};

header('X-Data-Source: demo');
(new ApiController(new CheckoClient('demo', $samples), (string) getenv('APP_API_TOKEN')))->serve();
