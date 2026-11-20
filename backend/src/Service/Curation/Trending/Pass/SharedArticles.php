<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Pass;

use App\Enum\FeedLanguage;
use App\Service\Curation\Trending\CardLanguage;
use App\Service\Curation\Trending\Model\SharedArticleModel;
use App\Service\Curation\Trending\Model\WindowEntryModel;
use App\Service\Curation\Trending\Model\WindowLinkCardModel;
use App\Service\Ingestion\Language\Support\PreviewText;

/**
 * One pass's shared articles: a shared link is read once, and guessed once per post language only when it stores no
 * language of its own.
 */
final class SharedArticles
{
    /** @var array<string, SharedArticleModel> shared link id, and the post language for an unlanguaged link */
    private array $byCard = [];

    public function __construct(private readonly CardLanguage $cardLanguage)
    {
    }

    public function buildArticle(WindowEntryModel $entry): ?SharedArticleModel
    {
        $card = $entry->links->storyCard();
        if ($card === null) {
            return null;
        }

        $key = $card->sharedLinkId . ' ' . ($card->language === null ? $entry->language?->value : 'stored');

        return $this->byCard[$key] ??= $this->read($card, $entry->language);
    }

    private function read(WindowLinkCardModel $card, ?FeedLanguage $postLanguage): SharedArticleModel
    {
        $body = PreviewText::cutDescription($card->description);

        return new SharedArticleModel(
            $card->title,
            $body,
            $card->language
                ?? $this->cardLanguage->resolveLanguage(PreviewText::joined($card->title, $body), $postLanguage),
        );
    }
}
