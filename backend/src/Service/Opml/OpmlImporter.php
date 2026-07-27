<?php

declare(strict_types=1);

namespace App\Service\Opml;

use App\Entity\User;
use App\Service\Subscription\BulkSubscribeItem;
use App\Service\Subscription\BulkSubscriber;

/**
 * Imports an OPML file into a user's subscriptions WITHOUT fetching anything.
 * Parsing is delegated to OpmlBodyReader (the single hardened OPML parser) and
 * the subscribe/tag/cap logic to BulkSubscriber, shared with the onboarding
 * catalog. This class only maps the OPML outline tree onto batch items.
 */
final readonly class OpmlImporter
{
    public function __construct(
        private OpmlBodyReader $bodyReader,
        private BulkSubscriber $subscriber,
    ) {
    }

    public function import(User $user, string $opml): OpmlImportResult
    {
        $body = $this->bodyReader->read($opml);

        $items = [];
        // Depth-first: each feed outline inherits the nearest ancestor group's
        // title as its tag. `null` tag = body root (untagged). OPML carries no
        // styling, so imported tags get the app's default colour and icon.
        foreach ($this->collectFeeds($body, null) as [$xmlUrl, $tagName]) {
            $items[] = new BulkSubscribeItem(feedUrl: $xmlUrl, tagName: $tagName);
        }

        $result = $this->subscriber->subscribeAll($user, $items);

        return new OpmlImportResult(
            imported: $result->imported,
            alreadySubscribed: $result->alreadySubscribed,
            invalid: $result->invalid,
            skippedOverLimit: $result->skippedOverLimit,
        );
    }

    /**
     * @return list<array{0: string, 1: string|null}> [xmlUrl, tagName]
     */
    private function collectFeeds(\DOMElement $node, ?string $inheritedTag): array
    {
        $out = [];
        foreach ($node->childNodes as $child) {
            if (!$child instanceof \DOMElement || $child->localName !== 'outline') {
                continue;
            }

            $xmlUrl = trim($child->getAttribute('xmlUrl'));
            if ($xmlUrl !== '') {
                $out[] = [$xmlUrl, $inheritedTag];
                continue;
            }

            // A group outline: its text/title becomes the tag for descendants.
            $groupName = trim($child->getAttribute('text'));
            if ($groupName === '') {
                $groupName = trim($child->getAttribute('title'));
            }
            $childTag = $groupName !== '' ? $groupName : $inheritedTag;
            foreach ($this->collectFeeds($child, $childTag) as $descendant) {
                $out[] = $descendant;
            }
        }

        return $out;
    }
}
