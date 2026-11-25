<?php
declare(strict_types=1);

use CheckoApi\Cache\SqliteRegistry;
use CheckoApi\Checko\CheckoClient;
use CheckoApi\Checko\Transport;
use CheckoApi\Checko\UpstreamResponse;
use CheckoApi\Http\ApiController;
use CheckoApi\Http\ApiException;
use CheckoApi\Http\Response;
use CheckoApi\Registry\EntityType;
use CheckoApi\Registry\Inn;

require dirname(__DIR__) . '/bootstrap.php';
// АВТОТЕСТЫ ################################################################################################################################
const KEY = 'fixture-upstream-key';
const COMPANY_INN = '7735560386';
const ENTREPRENEUR_INN = '402907671076';
const COMPANY_PATH = '/api/v1/companies/' . COMPANY_INN;
const ENTREPRENEUR_PATH = '/api/v1/entrepreneurs/' . ENTREPRENEUR_INN;

// Фейк карточки для наглядности
const COMPANY = [
    'ИНН' => COMPANY_INN,
    'ОГРН' => '1097746328360',
    'НаимПолн' => 'ООО «ТМЫВ»',
    'Статус' => ['Наим' => 'Действующая'],
    'ДатаРег' => '2009-06-01',
    'ЮрАдрес' => ['Адрес' => 'Москва, ул. Примерная, д. 1'],
];
const ENTREPRENEUR = [
    'ИНН' => ENTREPRENEUR_INN,
    'ОГРНИП' => '310402910200035',
    'ФИО' => 'Иванов Иван Иванович',
    'Регион' => ['Наим' => 'Калужская область'],
];

final class FakeTransport implements Transport
{
    public array $requests = [];

    public function __construct(public UpstreamResponse $response)
    {
    }

    public function post(string $url, array $payload): UpstreamResponse
    {
        $this->requests[] = ['url' => $url, 'payload' => $payload];
        return $this->response;
    }
}

function upstream(?array $data): UpstreamResponse
{
    return new UpstreamResponse(200, json_encode(['data' => $data, 'meta' => ['status' => 'ok']], JSON_THROW_ON_ERROR));
}

function request(ApiController $api, string $path, string $method = 'GET', string $authorization = ''): Response
{
    try {
        return $api->handle($method, $path, $authorization);
    } catch (ApiException $exception) {
        return Response::error($exception);
    }
}

function same(mixed $expected, mixed $actual, string $label): void
{
    if ($actual !== $expected) {
        throw new RuntimeException($label . ': ожидалось ' . var_export($expected, true)
            . ', получено ' . var_export($actual, true));
    }
}

function jsonBody(Response $response): array
{
    same('application/json', explode(';', $response->headers['Content-Type'])[0], 'Тип ответа');
    return json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
}

function expectError(Response $response, int $status, string $code): void
{
    same($status, $response->status, 'HTTP-статус');
    $body = jsonBody($response);
    same(['error'], array_keys($body), 'Оболочка ошибки');
    same($code, $body['error']['code'], 'Код ошибки');
    same(true, is_string($body['error']['message']) && $body['error']['message'] !== '', 'Описание ошибки');
    same(false, str_contains($response->body, KEY), 'Секрет источника попал в ответ');
}

function resolveRef(array $node, array $spec): array
{
    if (isset($node['$ref'])) {
        $path = explode('/', substr($node['$ref'], 2));
        $node = $spec;
        foreach ($path as $key) {
            $node = $node[$key];
        }
    }
    return $node;
}

// Ответы этих трёх методов используют OpenAPI 3.0.
function checkSchema(mixed $value, array $schema, array $spec, string $path): void
{
    $schema = resolveRef($schema, $spec);
    if ($value === null && ($schema['nullable'] ?? false)) {
        return;
    }
    if ($schema['type'] === 'object') {
        same(true, is_array($value), $path);
        foreach ($schema['required'] as $field) {
            same(true, array_key_exists($field, $value), "$path.$field");
        }
        foreach ($value as $field => $child) {
            same(true, isset($schema['properties'][$field]), "Незадокументированное поле $path.$field");
            checkSchema($child, $schema['properties'][$field], $spec, "$path.$field");
        }
        return;
    }
    same('string', $schema['type'], "Неизвестный тип схемы $path");
    same(true, is_string($value), $path);
    same(true, strlen($value) >= ($schema['minLength'] ?? 0), $path);
    if (isset($schema['enum'])) {
        same(true, in_array($value, $schema['enum'], true), $path);
    }
}

function expectSuccess(Response $response, string $route): array
{
    static $spec;
    $spec ??= json_decode(file_get_contents(dirname(__DIR__) . '/public/openapi.json'), true, flags: JSON_THROW_ON_ERROR);
    same(200, $response->status, 'HTTP-статус');
    $definition = resolveRef($spec['paths'][$route]['get']['responses']['200'], $spec);
    $body = jsonBody($response);
    checkSchema($body, $definition['content']['application/json']['schema'], $spec, $route);
    return $body;
}

$tests = [];

$tests['Организация и ИП — запросы к источнику и поля по контракту OpenAPI'] = function (): void {
    $cases = [
        ['companies', 'company', COMPANY, [
            'inn' => COMPANY_INN, 'ogrn' => COMPANY['ОГРН'], 'name' => COMPANY['НаимПолн'],
            'status' => 'Действующая', 'registered_at' => '2009-06-01', 'address' => COMPANY['ЮрАдрес']['АдресРФ'],
        ]],
        ['entrepreneurs', 'entrepreneur', ENTREPRENEUR, [
            'inn' => ENTREPRENEUR_INN, 'ogrn' => ENTREPRENEUR['ОГРНИП'], 'name' => ENTREPRENEUR['ФИО'],
            'status' => null, 'registered_at' => null, 'address' => 'Калужская область',
        ]],
    ];
    foreach ($cases as [$resource, $endpoint, $source, $expected]) {
        $transport = new FakeTransport(upstream($source));
        $api = new ApiController(new CheckoClient(KEY, $transport));
        $response = request($api, '/api/v1/' . $resource . '/' . $expected['inn']);
        $body = expectSuccess($response, '/api/v1/' . $resource . '/{inn}');
        foreach ($expected as $key => $value) {
            same($value, $body['data'][$key], "$resource.$key");
        }
        same(1, count($transport->requests), 'Количество запросов к Checko');
        $sent = $transport->requests[0];
        same('https://api.checko.ru/v2/' . $endpoint, $sent['url'], 'Адрес POST-запроса');
        ksort($sent['payload']);
        same(['inn' => $expected['inn'], 'key' => KEY], $sent['payload'], 'Параметры POST-запроса');
    }
};

$tests['Авторизация, методы, неизвестный путь и публичный /health'] = function (): void {
    $transport = new FakeTransport(upstream(COMPANY));
    $api = new ApiController(new CheckoClient(KEY, $transport), 'client-token');
    same(['status' => 'ok'], expectSuccess(request($api, '/health'), '/health'), '/health');
    foreach (['', 'Bearer other-token', 'Basic client-token'] as $header) {
        $denied = request($api, COMPANY_PATH, authorization: $header);
        expectError($denied, 401, 'unauthorized');
        same(true, str_starts_with($denied->headers['WWW-Authenticate'], 'Bearer'), 'Схема авторизации');
    }
    $method = request($api, COMPANY_PATH, 'POST');
    expectError($method, 405, 'method_not_allowed');
    same('GET', $method->headers['Allow'], 'Допустимый метод');
    expectError(request($api, '/unknown'), 404, 'not_found');
    same([], $transport->requests, 'Локальные ответы не расходуют квоту Checko');
    same(200, request($api, COMPANY_PATH, authorization: 'Bearer client-token')->status, 'Доступ по токену');
};

$tests['Контрольные цифры ИНН, длина и ведущий ноль'] = function (): void {
    same(true, Inn::isValid('0101000014', EntityType::Company), 'ИНН организации с ведущим нулём');
    same(true, Inn::isValid('010100000191', EntityType::Entrepreneur), 'ИНН ИП с ведущим нулём');
    $transport = new FakeTransport(upstream(COMPANY));
    $api = new ApiController(new CheckoClient(KEY, $transport));
    foreach ([
        '/companies/7735560387', '/entrepreneurs/402907671083', '/entrepreneurs/402907671075',
        '/companies/402907671076', '/entrepreneurs/7735560386', '/companies/773556038x',
    ] as $invalid) {
        expectError(request($api, '/api/v1' . $invalid), 400, 'invalid_inn');
    }
    same([], $transport->requests, 'Проверка ИНН предшествует обращению к источнику');
};

$tests['Ответы Checko — отсутствие записи, отказы, тайм-аут и повреждённые данные'] = function (): void {
    $cases = [
        [upstream(null), 404, 'not_found'],
        [upstream([]), 404, 'not_found'],
        [new UpstreamResponse(404, ''), 404, 'not_found'],
        [new UpstreamResponse(403, KEY), 503, 'service_unavailable'],
        [new UpstreamResponse(429, KEY), 503, 'service_unavailable'],
        [new UpstreamResponse(504, KEY), 504, 'upstream_timeout'],
        [new UpstreamResponse(500, KEY), 502, 'upstream_error'],
        [new UpstreamResponse(200, '<html>' . KEY), 502, 'upstream_error'],
        [new UpstreamResponse(200, '{"meta":{"status":"error"},"data":null}'), 502, 'upstream_error'],
        [upstream(array_replace(COMPANY, ['ИНН' => '7707083893'])), 502, 'upstream_error'],
        [upstream(array_replace(COMPANY, ['НаимПолн' => ''])), 502, 'upstream_error'],
    ];
    foreach ($cases as [$upstream, $status, $code]) {
        $api = new ApiController(new CheckoClient(KEY, new FakeTransport($upstream)));
        expectError(request($api, COMPANY_PATH), $status, $code);
    }
    $transport = new FakeTransport(upstream(COMPANY));
    $api = new ApiController(new CheckoClient('', $transport));
    expectError(request($api, COMPANY_PATH), 503, 'service_unavailable');
    same([], $transport->requests, 'Запрос без ключа отклоняется локально');
};

if (extension_loaded('pdo_sqlite')) {
    $tests['SQLite сохраняет только успешные ответы, включая null, и обновляет просроченную запись'] = function (): void {
        $file = tempnam(sys_get_temp_dir(), 'checko-test-');
        if ($file === false) {
            throw new RuntimeException('Не удалось создать временную БД');
        }
        try {
            $transport = new FakeTransport(new UpstreamResponse(429, ''));
            $source = new CheckoClient(KEY, $transport);
            $api = new ApiController(new SqliteRegistry($source, $file, 300));
            expectError(request($api, ENTREPRENEUR_PATH), 503, 'service_unavailable');
            $transport->response = upstream(ENTREPRENEUR);
            $card = expectSuccess(request($api, ENTREPRENEUR_PATH), '/api/v1/entrepreneurs/{inn}')['data'];
            same(2, count($transport->requests), 'После отказа Checko был повторно вызван');

            $api = new ApiController(new SqliteRegistry($source, $file, 300));
            $transport->response = upstream(array_replace(ENTREPRENEUR, ['Статус' => ['Наим' => 'Действующий']]));
            $cached = jsonBody(request($api, ENTREPRENEUR_PATH))['data'];
            same($card, $cached, 'Повторное открытие БД сохраняет карточку и типы полей');
            same(null, $cached['status'], 'Отсутствующий статус в БД');
            same(2, count($transport->requests), 'Попадание в кэш');

            // Переводим срок хранения в прошлое, чтобы проверка не зависела от скорости машины.
            $db = new PDO('sqlite:' . $file);
            $db->exec('UPDATE registry_cache SET expires_at = 0');
            $fresh = jsonBody(request($api, ENTREPRENEUR_PATH))['data'];
            same('Действующий', $fresh['status'], 'Повторное получение карточки после истечения срока');
            same(3, count($transport->requests), 'Истекший срок вызвал обращение к Checko');
        } finally {
            unset($db, $api);
            unlink($file);
        }
    };
}

$failed = 0;
foreach ($tests as $label => $test) {
    try {
        $test();
        echo "[OK] $label\n";
    } catch (Throwable $failure) {
        ++$failed;
        fwrite(STDERR, "[FAIL] $label\n" . $failure->getMessage() . "\n");
    }
}
if (!extension_loaded('pdo_sqlite')) {
    echo "[SKIP] Проверка SQLite требует pdo_sqlite\n";
}
printf("Групп проверок: %d, ошибок: %d\n", count($tests), $failed);
exit($failed === 0 ? 0 : 1);
