<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

final readonly class MastodonStatusModel
{
    public function __construct(
        public string $host,
        public string $id,
        public string $authorPrefix,
    ) {
    }

    public function apiUrl(): string
    {
        return sprintf('https://%s/api/v1/statuses/%s', $this->host, $this->id);
    }

    public function contextApiUrl(): string
    {
        return $this->apiUrl() . '/context';
    }
}
