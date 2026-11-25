<?php
declare(strict_types=1);

namespace CheckoApi\Registry;

interface Registry
{
    /** @return array{inn: string, ogrn: string, name: string, status: ?string, registered_at: ?string, address: ?string} */
    public function find(EntityType $type, string $inn): array;
}
