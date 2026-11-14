<?php

declare(strict_types=1);

namespace App\Service\Proxy\Model;

use App\Entity\ProxyConnection;
use App\Entity\ProxyServerSettings;

final readonly class ProxySettingsSnapshotModel
{
    public function __construct(
        public ProxyConnection $connection,
        public bool $hasPassword,
    ) {
    }

    public static function fromEntity(ProxyServerSettings $settings): self
    {
        return new self($settings->connection(), $settings->hasPassword());
    }

    public function isConfigured(): bool
    {
        return '' !== $this->connection->host;
    }
}
