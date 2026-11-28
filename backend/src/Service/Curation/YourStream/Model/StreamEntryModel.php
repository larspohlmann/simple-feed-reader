<?php

declare(strict_types=1);

namespace App\Service\Curation\YourStream\Model;

use App\Entity\Entry;
use App\Enum\FeedLanguage;

final readonly class StreamEntryModel
{
    public const int SUMMARY_CHARACTERS = 2000;

    public function __construct(
        public int $id,
        public ?FeedLanguage $language,
        public string $title,
        public ?string $summary,
    ) {
    }

    public static function fromEntry(Entry $entry): self
    {
        $summary = $entry->getText()->getSummary();

        return new self(
            $entry->requireId(),
            $entry->getText()->getLanguage() ?? $entry->getFeed()->getLanguage(),
            $entry->getTitle(),
            $summary === null ? null : mb_substr($summary, 0, self::SUMMARY_CHARACTERS),
        );
    }
}
