<?php

declare(strict_types=1);

namespace CheckoApi\Cache;

use CheckoApi\Registry\EntityType;
use CheckoApi\Registry\Registry;
use PDO;
use PDOException;

final class SqliteRegistry implements Registry
{
    private ?PDO $connection = null;

    public function __construct(
        private readonly Registry $source,
        private readonly string $path,
        private readonly int $ttl = 300,
    ) {
    }

    public function find(EntityType $type, string $inn): array
    {
        if ($this->ttl <= 0 || !extension_loaded('pdo_sqlite')) {
            return $this->source->find($type, $inn);
        }

        try {
            $db = $this->connect();
            $query = $db->prepare(
                'SELECT inn, ogrn, name, status, registered_at, address FROM registry_cache
                 WHERE entity_type = ? AND inn = ? AND expires_at > ?'
            );
            $query->execute([$type->value, $inn, time()]);
            $cached = $query->fetch(PDO::FETCH_ASSOC);
            $query->closeCursor();
        } catch (PDOException) {
            // Даже при недоступной базе запрос должен дойти до Checko.
            return $this->source->find($type, $inn);
        }

        if ($cached !== false) {
            return $cached;
        }

        $card = $this->source->find($type, $inn);

        try {
            $this->store($db, $type, $card);
        } catch (PDOException) {
            // Получение карточки, ошибка записи не отменяет успешный ответ
        }

        return $card;
    }

    private function connect(): PDO
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        $db = new PDO('sqlite:' . $this->path, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 1,
        ]);
        $db->exec(
            'CREATE TABLE IF NOT EXISTS registry_cache (
                entity_type TEXT NOT NULL,
                inn TEXT NOT NULL,
                ogrn TEXT NOT NULL,
                name TEXT NOT NULL,
                status TEXT,
                registered_at TEXT,
                address TEXT,
                expires_at INTEGER NOT NULL,
                PRIMARY KEY (entity_type, inn)
            )'
        );
        $db->exec('CREATE INDEX IF NOT EXISTS registry_cache_expiry ON registry_cache (expires_at)');

        return $this->connection = $db;
    }

    private function store(PDO $db, EntityType $type, array $card): void
    {
        $now = time();
        $cleanup = $db->prepare('DELETE FROM registry_cache WHERE expires_at <= ?');
        $cleanup->execute([$now]);

        $write = $db->prepare(
            'INSERT INTO registry_cache (entity_type, inn, ogrn, name, status, registered_at, address, expires_at)
             VALUES (:entity_type, :inn, :ogrn, :name, :status, :registered_at, :address, :expires_at)
             ON CONFLICT (entity_type, inn) DO UPDATE SET
                 ogrn = excluded.ogrn, name = excluded.name, status = excluded.status,
                 registered_at = excluded.registered_at, address = excluded.address, expires_at = excluded.expires_at'
        );
        $write->execute([...$card, 'entity_type' => $type->value, 'expires_at' => $now + $this->ttl]);
    }
}
