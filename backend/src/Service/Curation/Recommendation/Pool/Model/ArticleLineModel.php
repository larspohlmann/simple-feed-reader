<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Pool\Model;

use App\Repository\TitledEntry;
use App\Service\Content\LinkCard\Model\StoryLinkModel;
use App\Service\Foundation\Text\Support\PlainText;

final readonly class ArticleLineModel
{
    public function __construct(
        public int $entryId,
        public string $title,
        public string $feedName,
        public string $date,
        public ?string $description,
        public ?StoryLinkModel $sharedArticle = null,
        public ?string $ownArticleHash = null,
    ) {
    }

    public function articleKey(): ?string
    {
        return $this->ownArticleHash ?? $this->sharedArticle?->urlHash;
    }

    public function isAnOutletArticle(): bool
    {
        return $this->ownArticleHash !== null;
    }

    public static function fromTitledEntry(TitledEntry $titled, ?StoryLinkModel $storyLink): self
    {
        $entry = $titled->entry;
        $isOutlet = $entry->getFeed()->isFromAnOutlet();

        return new self(
            entryId: $entry->requireId(),
            title: $entry->getTitle(),
            feedName: $titled->feedName,
            date: $entry->getEffectiveDate()->format('Y-m-d'),
            description: PlainText::from($entry->getText()->getSummary() ?? $entry->getText()->getContentHtml()),
            sharedArticle: $isOutlet ? null : $storyLink,
            ownArticleHash: $isOutlet ? $entry->getUrlHash() : null,
        );
    }
}
