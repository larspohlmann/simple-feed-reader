<?php

declare(strict_types=1);

namespace App\Service\LinkCard;

use App\Entity\Entry;
use App\Enum\ShareKind;
use App\Repository\LinkCardRepository;
use App\Service\Ingest\Support\EntrySnippet;
use App\Service\LinkCard\Model\EntryCardsModel;
use App\Service\LinkCard\Model\PageCardModel;
use App\Service\LinkCard\Support\QuoteLine;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Share\Support\TitledShareTarget;

/** Puts freshly read page cards on their entry: a carded or quoted target's RE: line leaves the body. */
final readonly class EntryCardWriter
{
    public function __construct(
        private LinkCardRepository $linkCards,
        private EntrySanitizer $sanitizer,
    ) {
    }

    public function write(Entry $entry, EntryCardsModel $cards): void
    {
        $this->linkCards->append($entry, $cards->stored, $cards->newCards());
        $this->dropQuoteLines($entry, $cards->quoteLineUrls());
        self::settleMediaTarget($entry, $cards->pageCards);
        if ($cards->quoted !== null) {
            $entry->shareQuotedPost($cards->quoted->post);
        }
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

    /** @param list<string> $urls */
    private function dropQuoteLines(Entry $entry, array $urls): void
    {
        $html = (string) $entry->getContentHtml();
        $withoutQuotes = $html;
        foreach ($urls as $url) {
            $withoutQuotes = QuoteLine::removedFrom($withoutQuotes, $url);
        }
        if ($withoutQuotes === $html) {
            return;
        }

        $sanitized = $this->sanitizer->sanitize($withoutQuotes);
        $entry->setContentHtml($sanitized);
        $entry->setSummary(EntrySnippet::from($sanitized));
    }
}
