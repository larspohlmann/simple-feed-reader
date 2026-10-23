<?php

declare(strict_types=1);

namespace App\Service\Opml;

use App\Entity\User;
use App\Service\Opml\Exception\InvalidOpmlException;
use App\Service\Opml\Model\OpmlFeedPlacementModel;
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
        foreach ($this->collectFeeds($body, null, null) as $placement) {
            $items[] = new BulkSubscribeItemModel(
                feedUrl: $placement->feedUrl,
                tagName: $placement->tagName,
                parentTagName: $placement->parentTagName,
            );
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
     * Depth-first. A feed takes the innermost group as its tag and the outermost as that tag's parent, so a
     * one-level file imports as it always did and a deeper one keeps its innermost grouping.
     *
     * @return list<OpmlFeedPlacementModel>
     */
    private function collectFeeds(\DOMElement $node, ?string $inheritedTag, ?string $outermostTag): array
    {
        $placements = [];
        foreach ($node->childNodes as $child) {
            if (!$child instanceof \DOMElement || $child->localName !== 'outline') {
                continue;
            }

            $xmlUrl = trim($child->getAttribute('xmlUrl'));
            if ($xmlUrl !== '') {
                $parent = $inheritedTag === $outermostTag ? null : $outermostTag;
                $placements[] = new OpmlFeedPlacementModel($xmlUrl, $inheritedTag, $parent);
                continue;
            }

            $groupTag = $this->groupName($child) ?? $inheritedTag;
            foreach ($this->collectFeeds($child, $groupTag, $outermostTag ?? $groupTag) as $placement) {
                $placements[] = $placement;
            }
        }

        return $placements;
    }

    private function groupName(\DOMElement $outline): ?string
    {
        $name = trim($outline->getAttribute('text'));
        if ($name === '') {
            $name = trim($outline->getAttribute('title'));
        }

        return $name === '' ? null : $name;
    }
}
