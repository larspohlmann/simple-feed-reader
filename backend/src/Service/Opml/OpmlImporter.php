<?php

declare(strict_types=1);

namespace App\Service\Opml;

use App\Entity\User;
use App\Service\Opml\Exception\InvalidOpmlException;
use App\Service\Opml\Model\OpmlImportResultModel;
use App\Service\Subscription\BulkSubscriber;
use App\Service\Subscription\Model\BulkSubscribeItemModel;

/** Imports OPML into subscriptions WITHOUT fetching anything, mapping its outline tree onto BulkSubscriber items. */
final readonly class OpmlImporter
{
    private const int MAX_BYTES = 1_048_576;

    public function __construct(
        private OpmlBodyReader $bodyReader,
        private BulkSubscriber $subscriber,
    ) {
    }

    public function import(User $user, string $opml): OpmlImportResultModel
    {
        if ($opml === '' || \strlen($opml) > self::MAX_BYTES) {
            throw new InvalidOpmlException('The OPML body is empty or larger than 1 MB.');
        }

        $body = $this->bodyReader->read($opml);

        $items = [];
        // Depth-first: each feed outline inherits the nearest ancestor group's
        // title as its tag. `null` tag = body root (untagged). OPML carries no
        // styling, so imported tags get the app's default colour and icon.
        foreach ($this->collectFeeds($body, null) as [$xmlUrl, $tagName]) {
            $items[] = new BulkSubscribeItemModel(feedUrl: $xmlUrl, tagName: $tagName);
        }

        $result = $this->subscriber->subscribeAll($user, $items);

        return new OpmlImportResultModel(
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
