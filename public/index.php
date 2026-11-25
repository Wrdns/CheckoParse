<?php
declare(strict_types=1);

use CheckoApi\Cache\SqliteRegistry;
use CheckoApi\Checko\CheckoClient;
use CheckoApi\Http\ApiController;

require dirname(__DIR__) . '/bootstrap.php';
$registry = new CheckoClient((string) getenv('CHECKO_API_KEY'));
if (getenv('CHECKO_CACHE') === '1') {
    $registry = new SqliteRegistry($registry, dirname(__DIR__) . '/var/cache.sqlite');
}

(new ApiController($registry, (string) getenv('APP_API_TOKEN')))->serve();
