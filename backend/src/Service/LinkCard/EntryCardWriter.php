<?php

declare(strict_types=1);

namespace App\Service\LinkCard;

use App\Entity\Entry;
use App\Entity\LinkCardContent;
use App\Enum\ShareKind;
use App\Repository\LinkCardRepository;
use App\Service\Ingest\Support\EntrySnippet;
use App\Service\LinkCard\Model\EntryCardsModel;
use App\Service\LinkCard\Model\PageCardModel;
use App\Service\LinkCard\Support\QuoteLine;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Share\Support\TitledShareTarget;

/** Puts freshly read page cards on their entry: a carded quote's RE: line leaves the body. */
final readonly class EntryCardWriter
{
    public function __construct(
        private LinkCardRepository $linkCards,
        private EntrySanitizer $sanitizer,
    ) {
    }

    public function write(Entry $entry, EntryCardsModel $cards): void
    {
        $newCards = $cards->newCards();
        $this->linkCards->append($entry, $cards->stored, $newCards);
        $this->dropCardedQuoteLines($entry, $newCards);
        self::settleMediaTarget($entry, $cards->pageCards);
    }

    /**
     * A titled share whose page is a picture or video file is shown as that medium. A post keeps its link card: its
     * own media, not a linked file, make it a media post.
     *
     * @param list<PageCardModel> $pageCards
     */
    private static function settleMediaTarget(Entry $entry, array $pageCards): void
    {
        $medium = $pageCards[0]->medium ?? null;
        if ($medium === null || TitledShareTarget::of($entry) === null) {
            return;
        }
        $entry->setShareKind(ShareKind::Media);
        $entry->putMediumFirst($medium);
    }

    /** @param list<LinkCardContent> $cards */
    private function dropCardedQuoteLines(Entry $entry, array $cards): void
    {
        $html = (string) $entry->getContentHtml();
        $withoutQuotes = $html;
        foreach ($cards as $card) {
            $withoutQuotes = QuoteLine::removedFrom($withoutQuotes, $card->url);
        }
        if ($withoutQuotes === $html) {
            return;
        }

        $sanitized = $this->sanitizer->sanitize($withoutQuotes);
        $entry->setContentHtml($sanitized);
        $entry->setSummary(EntrySnippet::from($sanitized));
    }
}
