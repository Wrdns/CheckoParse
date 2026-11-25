<?php

declare(strict_types=1);

namespace CheckoApi\Checko;

use CheckoApi\Http\ApiException;
use CheckoApi\Registry\EntityType;
use CheckoApi\Registry\Registry;
use JsonException;
use SensitiveParameter;

final readonly class CheckoClient implements Registry
{
    public function __construct(
        #[SensitiveParameter] private string $apiKey,
        private Transport $transport = new CurlTransport(),
    ) {
    }

    public function find(EntityType $type, string $inn): array
    {
        if (trim($this->apiKey) === '') {
            throw new ApiException(503, 'service_unavailable', 'Для сервиса не настроен ключ API Checko.');
        }

        $url = match ($type) {
            EntityType::Company => 'https://api.checko.ru/v2/company',
            EntityType::Entrepreneur => 'https://api.checko.ru/v2/entrepreneur',
        };
        $response = $this->transport->post($url, ['key' => $this->apiKey, 'inn' => $inn]);
        $this->checkStatus($response->status);

        try {
            $result = json_decode($response->body, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $this->invalidResponse();
        }

        if (($result['meta']['status'] ?? null) !== 'ok' || !array_key_exists('data', $result)) {
            throw $this->invalidResponse();
        }
        $data = $result['data'];
        if ($data === [] || $data === null) {
            throw $this->notFound();
        }
        // Обработка чужой карточки
        if (!is_array($data) || ($data['ИНН'] ?? null) !== $inn) {
            throw $this->invalidResponse();
        }

        $isCompany = $type === EntityType::Company;
        $ogrn = $this->optionalText($data[$isCompany ? 'ОГРН' : 'ОГРНИП'] ?? null);
        $name = $this->optionalText($data[$isCompany ? 'НаимПолн' : 'ФИО'] ?? null);
        if ($ogrn === null || $name === null) {
            throw $this->invalidResponse();
        }

        return [
            'inn' => $inn,
            'ogrn' => $ogrn,
            'name' => $name,
            'status' => $this->optionalText($data['Статус']['Наим'] ?? null),
            'registered_at' => $this->optionalText($data['ДатаРег'] ?? null),
            'address' => $this->optionalText($isCompany
                ? ($data['ЮрАдрес']['АдресРФ'] ?? null)
                : ($data['Регион']['Наим'] ?? null)),
        ];
    }

    private function checkStatus(int $status): void
    {
        if ($status === 200) {
            return;
        }
        throw match ($status) {
            404 => $this->notFound(),
            408, 504 => new ApiException(504, 'upstream_timeout', 'Истекло время ожидания ответа Checko.'),
            401, 402, 403, 429, 503 => new ApiException(503, 'service_unavailable', 'Доступ сервиса к Checko временно ограничен.'),
            default => $this->invalidResponse(),
        };
    }

    private function optionalText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw $this->invalidResponse();
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private function invalidResponse(): ApiException
    {
        return new ApiException(502, 'upstream_error', 'Checko вернул некорректный ответ.');
    }

    private function notFound(): ApiException
    {
        return new ApiException(404, 'not_found', 'Запись с указанным ИНН не найдена.');
    }
}
