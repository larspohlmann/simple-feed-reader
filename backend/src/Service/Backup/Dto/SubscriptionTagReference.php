<?php

declare(strict_types=1);

namespace App\Service\Backup\Dto;

use App\Entity\SubscriptionTag;
use App\Service\Backup\Support\LineField;

/**
 * A tag attached to a subscription, referenced by name rather than id — the
 * restore re-creates or looks up tags before wiring subscriptions, so ids
 * from the source account are meaningless on the target.
 */
final readonly class SubscriptionTagReference
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

    public static function of(SubscriptionTag $subscriptionTag): self
    {
        return new self(name: $subscriptionTag->getTag()->getName(), position: $subscriptionTag->getPosition());
    }

    /** @return array{name: string, position: int} */
    public function toBackupFields(): array
    {
        return ['name' => $this->name, 'position' => $this->position];
    }
}
