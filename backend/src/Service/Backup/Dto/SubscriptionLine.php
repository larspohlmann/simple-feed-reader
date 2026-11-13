<?php

declare(strict_types=1);

namespace App\Service\Backup\Dto;

use App\Enum\InclusionSurface;
use App\Service\Backup\Support\BackupLineFormat;
use App\Service\Backup\Support\BackupSchema;
use App\Service\Backup\Support\LineField;
use App\Service\Backup\Support\LineFieldWithDefault;
use App\Service\Social\Model\ExportedSubscriptionModel;

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
        public bool $includeInAllItems,
        public bool $includeInForYou,
        public bool $includeInSavedSearches,
        public bool $includeInTrending,
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
            includeInAllItems: LineFieldWithDefault::bool($line, 'includeInAllItems', true),
            includeInForYou: LineFieldWithDefault::bool($line, 'includeInForYou', true),
            includeInSavedSearches: LineFieldWithDefault::bool($line, 'includeInSavedSearches', true),
            includeInTrending: LineFieldWithDefault::bool($line, 'includeInTrending', true),
        );
    }

    public static function of(ExportedSubscriptionModel $exported): self
    {
        $subscription = $exported->subscription;

        return new self(
            feedUrl: $exported->feedUrl,
            customTitle: $subscription->getCustomTitle(),
            position: $subscription->getPosition(),
            markedReadUntil: $subscription->getMarkedReadUntil(),
            createdAt: $subscription->getCreatedAt(),
            tags: array_map(SubscriptionTagReference::of(...), $subscription->getSubscriptionTags()),
            includeInAllItems: $subscription->isIncludedIn(InclusionSurface::AllItems),
            includeInForYou: $subscription->isIncludedIn(InclusionSurface::ForYou),
            includeInSavedSearches: $subscription->isIncludedIn(InclusionSurface::SavedSearches),
            includeInTrending: $subscription->isIncludedIn(InclusionSurface::Trending),
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
            'includeInAllItems' => $this->includeInAllItems,
            'includeInForYou' => $this->includeInForYou,
            'includeInSavedSearches' => $this->includeInSavedSearches,
            'includeInTrending' => $this->includeInTrending,
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
