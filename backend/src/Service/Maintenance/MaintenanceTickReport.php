<?php

declare(strict_types=1);

namespace App\Service\Maintenance;

/**
 * The outcome of one maintenance tick (#346): the feed-refresh report, the
 * For You sweep report, and the due-digests sweep report (#636), each
 * already serialised, merged under stable keys for a single JSON response.
 */
final readonly class MaintenanceTickReport
{
    /**
     * @param array<string,mixed> $refresh
     * @param array<string,mixed> $recommendations
     * @param array<string,mixed> $digests
     */
    public function __construct(
        public array $refresh,
        public array $recommendations,
        public array $digests,
    ) {
    }

    /**
     * @return array{refresh: array<string,mixed>, recommendations: array<string,mixed>, digests: array<string,mixed>}
     */
    public function toArray(): array
    {
        return [
            'refresh' => $this->refresh,
            'recommendations' => $this->recommendations,
            'digests' => $this->digests,
        ];
    }
}
