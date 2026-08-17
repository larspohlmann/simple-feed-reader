<?php

declare(strict_types=1);

namespace App\Service\Backup\Dto;

/**
 * A tag attached to a subscription, referenced by name rather than id — the
 * restore re-creates or looks up tags before wiring subscriptions, so ids
 * from the source account are meaningless on the target.
 */
final readonly class SubscriptionTagRef
{
    public function __construct(
        public string $name,
        public int $position,
    ) {
    }

    /**
     * @param array<string, mixed> $line
     */
    public static function fromLine(array $line): self
    {
        return new self(
            name: LineField::string($line, 'name'),
            position: LineField::int($line, 'position'),
        );
    }
}
