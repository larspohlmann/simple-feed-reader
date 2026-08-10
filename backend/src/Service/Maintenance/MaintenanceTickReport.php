<?php

declare(strict_types=1);

namespace App\Service\Maintenance;

/**
 * The outcome of one maintenance tick (#346): the feed-refresh report and
 * the For You sweep report, each already serialised, merged under stable
 * keys for a single JSON response.
 */
final readonly class MaintenanceTickReport
{
    /**
     * @param array<string,mixed> $refresh
     * @param array<string,mixed> $recommendations
     */
    public function __construct(
        public array $refresh,
        public array $recommendations,
    ) {
    }

    /**
     * @return array{refresh: array<string,mixed>, recommendations: array<string,mixed>}
     */
    public function toArray(): array
    {
        return [
            'refresh' => $this->refresh,
            'recommendations' => $this->recommendations,
        ];
    }
}
