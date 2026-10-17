<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EntryOrigin;
use App\Service\LinkCard\Model\PlacedLinkCardModel;
use App\Service\Social\Model\ThreadPostModel;

final readonly class EntryListRowEnrichment
{
    public function __construct(
        /** @var list<string> the feed-declared category labels, in declared order */
        public array $categories = [],
        /** @var list<array{id: int, slug: string, term: string}> owned saved searches this entry belongs to, sidebar order */
        public array $savedSearches = [],
        /** @var list<PlacedLinkCardModel> the root post's links, as stored cards with their positions, in post order */
        public array $linkCards = [],
        /** a post whose embed or link cards are still to come, which a client may ask to complete */
        public bool $pending = false,
        /** null for an article, or for a post on a network the reader has no account on */
        public ?EntryListRowSocial $social = null,
        /** a post's replies stand in for its stored discussion; null keeps the stored one */
        public ?EntryListRowDiscussion $discussion = null,
        /** where a saved article came from; null for every other entry */
        public ?EntryOrigin $origin = null,
        /** @var list<ThreadPostModel> the author's own replies folded into this entry, in thread order */
        public array $threadPosts = [],
    ) {
    }
}
