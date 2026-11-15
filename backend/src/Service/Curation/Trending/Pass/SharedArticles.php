<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Pass;

use App\Enum\FeedLanguage;
use App\Service\Curation\Trending\CardLanguage;
use App\Service\Curation\Trending\Model\SharedArticleModel;
use App\Service\Curation\Trending\Model\WindowEntryModel;
use App\Service\Curation\Trending\Model\WindowLinkCardModel;
use App\Service\Curation\Trending\Support\EntryText;

/** One pass's shared articles: a card shared by several posts of one language is read and guessed once. */
final class SharedArticles
{
    /** @var array<string, SharedArticleModel> card URL and post language => the article */
    private array $byCard = [];

    public function __construct(private readonly CardLanguage $cardLanguage)
    {
    }

    public function of(WindowEntryModel $entry): ?SharedArticleModel
    {
        $card = $entry->links->storyCard();
        if ($card === null) {
            return null;
        }

        return $this->byCard[$entry->language?->value . ' ' . $card->url] ??= $this->read($card, $entry->language);
    }

    private function read(WindowLinkCardModel $card, ?FeedLanguage $postLanguage): SharedArticleModel
    {
        $body = EntryText::body($card->description);

        return new SharedArticleModel(
            $card->title,
            $body,
            $this->cardLanguage->of($card->title . "\n" . $body, $postLanguage),
        );
    }
}
