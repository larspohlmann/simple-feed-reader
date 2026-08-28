<?php

declare(strict_types=1);

namespace App\Service\Backup\Dto;

/**
 * One of the account's subscriptions to a feed, with its tag assignments.
 */
final readonly class SubscriptionLine
{
    /**
     * @param list<SubscriptionTagRef> $tags
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
            includeInAllItems: LineField::boolWithDefault($line, 'includeInAllItems', true),
            includeInForYou: LineField::boolWithDefault($line, 'includeInForYou', true),
        );
    }

    /**
     * @param array<string, mixed> $line
     *
     * @return list<SubscriptionTagRef>
     */
    private static function tagsFromLine(array $line): array
    {
        return array_map(
            SubscriptionTagRef::fromLine(...),
            LineField::objectList($line, 'tags'),
        );
    }
}
