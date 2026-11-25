<?php
declare(strict_types=1);

namespace CheckoApi\Registry;

enum EntityType: string
{
    case Company = 'companies';
    case Entrepreneur = 'entrepreneurs';

    public function innLength(): int
    {
        return $this === self::Company ? 10 : 12;
    }
}
