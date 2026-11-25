<?php
declare(strict_types=1);

namespace CheckoApi\Http;

use CheckoApi\Registry\EntityType;
use CheckoApi\Registry\Inn;
use CheckoApi\Registry\Registry;

final readonly class ApiController
{
    public function __construct(private Registry $registry, private string $accessToken = '')
    {
    }

    public function serve(): void
    {
        try {
            $response = $this->handle(
                $_SERVER['REQUEST_METHOD'],
                explode('?', $_SERVER['REQUEST_URI'], 2)[0],
                $_SERVER['HTTP_AUTHORIZATION'] ?? '',
            );
        } catch (ApiException $exception) {
            $response = Response::error($exception);
        } catch (\Throwable $exception) {
            // Сообщение исключения может содержать закрытые параметры запроса.
            error_log('checko-api: ' . $exception::class . ' (' . basename($exception->getFile()) . ':' . $exception->getLine() . ')');
            $response = Response::json(['error' => [
                'code' => 'internal_error',
                'message' => 'Внутренняя ошибка сервиса.',
            ]], 500);
        }
        $response->send();
    }

    public function handle(string $method, string $path, string $authorization = ''): Response
    {
        if (in_array($path, ['/', '/docs', '/docs/', '/docs.html', '/openapi.json', '/health'], true)) {
            $this->requireGet($method);
            return match ($path) {
                '/' => new Response(302, '', ['Location' => '/docs']),
                '/health' => Response::json(['status' => 'ok']),
                '/openapi.json' => $this->document('openapi.json', 'application/json'),
                default => $this->document('docs.html', 'text/html'),
            };
        }

        if (!preg_match('~^/api/v1/([^/]+)/([^/]+)$~D', $path, $parts)
            || ($type = EntityType::tryFrom($parts[1])) === null) {
            throw new ApiException(404, 'not_found', 'Ресурс не найден.');
        }
        $this->requireGet($method);
        $this->authorize($authorization);

        $inn = rawurldecode($parts[2]);
        if (!Inn::isValid($inn, $type)) {
            throw new ApiException(400, 'invalid_inn', sprintf(
                'Проверьте ИНН. Он должен содержать %d цифр и проходить проверку контрольных разрядов.',
                $type->innLength(),
            ));
        }
        return Response::json(['data' => $this->registry->find($type, $inn)]);
    }

    private function requireGet(string $method): void
    {
        if ($method !== 'GET') {
            throw new ApiException(405, 'method_not_allowed', 'Для этого ресурса доступен метод GET.');
        }
    }

    private function authorize(string $authorization): void
    {
        if ($this->accessToken === '') {
            return;
        }
        if (!preg_match('/^Bearer +([^\s]+)$/iD', $authorization, $match)
            || !hash_equals($this->accessToken, $match[1])) {
            throw new ApiException(401, 'unauthorized', 'Требуется действительный Bearer-токен.');
        }
    }

    private function document(string $file, string $contentType): Response
    {
        $body = file_get_contents(dirname(__DIR__, 2) . '/public/' . $file);
        if ($body === false) {
            throw new ApiException(500, 'internal_error', 'Не удалось загрузить документацию.');
        }
        return new Response(200, $body, ['Content-Type' => $contentType . '; charset=utf-8']);
    }
}
