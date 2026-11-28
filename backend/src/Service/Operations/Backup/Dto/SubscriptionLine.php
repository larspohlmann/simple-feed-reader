<?php

declare(strict_types=1);

namespace App\Service\Operations\Backup\Dto;

use App\Entity\InclusionSwitches;
use App\Service\Operations\Backup\Support\BackupInclusions;
use App\Service\Operations\Backup\Support\BackupLineFormat;
use App\Service\Operations\Backup\Support\BackupSchema;
use App\Service\Operations\Backup\Support\LineField;
use App\Service\Social\Social\Model\ExportedSubscriptionModel;

/**
 * One of the account's subscriptions to a feed, with its tag assignments.
 */
final readonly class SubscriptionLine
{
    /**
     * @param list<SubscriptionTagReference> $tags
     */
    public function __construct(
        public string $feedUrl,
        public ?string $customTitle,
        public int $position,
        public ?\DateTimeImmutable $markedReadUntil,
        public \DateTimeImmutable $createdAt,
        public array $tags,
        public InclusionSwitches $inclusions,
    ) {
    }

    /**
     * @param array<string, mixed> $line
     */
    public static function fromLine(array $line): self
    {
        return new self(
            feedUrl: LineField::string($line, 'feedUrl'),
            customTitle: LineField::stringOrNull($line, 'customTitle'),
            position: LineField::int($line, 'position'),
            markedReadUntil: LineField::dateOrNull($line, 'markedReadUntil'),
            createdAt: LineField::date($line, 'createdAt'),
            tags: self::tagsFromLine($line),
            inclusions: BackupInclusions::fromLine($line),
        );
    }

    public static function fromSubscription(ExportedSubscriptionModel $exported): self
    {
        $subscription = $exported->subscription;

        return new self(
            feedUrl: $exported->feedUrl,
            customTitle: $subscription->getCustomTitle(),
            position: $subscription->getPosition(),
            markedReadUntil: $subscription->getMarkedReadUntil(),
            createdAt: $subscription->getCreatedAt(),
            tags: array_map(SubscriptionTagReference::fromSubscriptionTag(...), $subscription->getSubscriptionTags()),
            inclusions: $subscription->getInclusions(),
        );
    }

    public function toBackupLine(): string
    {
        return BackupLineFormat::encode([
            'kind' => BackupSchema::KIND_SUBSCRIPTION,
            'feedUrl' => $this->feedUrl,
            'customTitle' => $this->customTitle,
            'position' => $this->position,
            'markedReadUntil' => BackupLineFormat::dateOrNull($this->markedReadUntil),
            'createdAt' => BackupLineFormat::date($this->createdAt),
            'tags' => array_map(
                static fn (SubscriptionTagReference $tag): array => $tag->toBackupFields(),
                $this->tags,
            ),
            ...BackupInclusions::toFields($this->inclusions),
        ]);
    }

    /**
     * @param array<string, mixed> $line
     *
     * @return list<SubscriptionTagReference>
     */
    private static function tagsFromLine(array $line): array
    {
        return array_map(
            SubscriptionTagReference::fromLine(...),
            LineField::objectList($line, 'tags'),
        );
    }
}
