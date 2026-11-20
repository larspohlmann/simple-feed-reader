<?php

declare(strict_types=1);

namespace App\Service\Content\LinkCard;

use App\Entity\Entry;
use App\Entity\LinkCardContent;
use App\Entity\QuotedPostContent;
use App\Entity\ThreadPost;
use App\Enum\FeedLanguage;
use App\Repository\LinkCardRepository;

/** Puts cards on an entry, a reply or a quote as references to their shared links, which it finds or creates. */
final readonly class EntryLinks
{
    public function __construct(
        private SharedLinkWriter $linkWriter,
        private LinkCardRepository $linkCards,
    ) {
    }

    /**
     * @param list<LinkCardContent>       $cards       in post order
     * @param array<string, FeedLanguage> $statedByUrl the language a card's page states, by card URL
     */
    public function attach(Entry $entry, array $cards, array $statedByUrl = []): void
    {
        if ($cards === []) {
            return;
        }
        $links = $this->linkWriter->ensureLinks($cards, $statedByUrl);
        $this->linkCards->append($entry, array_map(static fn (LinkCardContent $card) => $links[$card->url], $cards));
    }

    public function attachToThreadPost(ThreadPost $post, LinkCardContent $card): void
    {
        $this->linkCards->appendToThreadPost($post, $this->linkWriter->ensureLink($card, null));
    }

    public function quote(Entry $entry, QuotedPostContent $quote): void
    {
        $card = $quote->linkCard;
        $entry->shareQuotedPost($quote, $card === null ? null : $this->linkWriter->ensureLink($card, null));
    }
}
