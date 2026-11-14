<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Pool\Model;

use App\Repository\TitledEntry;
use App\Service\Text\Support\PlainText;

final readonly class ArticleLineModel
{
    public function __construct(
        public int $entryId,
        public string $title,
        public string $feedName,
        public string $date,
        public ?string $description,
    ) {
    }

    public static function of(TitledEntry $titled): self
    {
        $entry = $titled->entry;

        return new self(
            entryId: $entry->requireId(),
            title: $entry->getTitle(),
            feedName: $titled->feedName,
            date: $entry->getEffectiveDate()->format('Y-m-d'),
            description: PlainText::from($entry->getSummary() ?? $entry->getContentHtml()),
        );
    }
}
