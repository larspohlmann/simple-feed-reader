<?php

declare(strict_types=1);

namespace App\Service\Opml;

use App\Entity\User;
use App\Enum\SourceFormat;
use App\Service\Opml\Exception\InvalidOpmlException;
use App\Service\Opml\Model\OpmlImportResultModel;
use App\Service\Subscription\BulkSubscriber;
use App\Service\Subscription\Model\BulkSubscribeItemModel;
use App\Service\Url\Support\MixcloudUrl;

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

        $items = $this->collectFeeds($body, []);
        $permitted = $this->withoutScraped($items);
        $result = $this->subscriber->subscribeAll($user, $permitted);

        return new OpmlImportResultModel(
            imported: $result->imported,
            alreadySubscribed: $result->alreadySubscribed,
            invalid: $result->invalid + \count($items) - \count($permitted),
            skippedOverLimit: $result->skippedOverLimit,
        );
    }

    /**
     * Depth-first; a feed carries every named group above it, outermost first.
     *
     * @param list<string> $groupPath
     *
     * @return list<BulkSubscribeItemModel>
     */
    private function collectFeeds(\DOMElement $node, array $groupPath): array
    {
        $items = [];
        foreach ($node->childNodes as $child) {
            if (!$child instanceof \DOMElement || $child->localName !== 'outline') {
                continue;
            }

            $xmlUrl = trim($child->getAttribute('xmlUrl'));
            if ($xmlUrl !== '') {
                $items[] = new BulkSubscribeItemModel(
                    feedUrl: $xmlUrl,
                    tagPath: $groupPath,
                    sourceFormat: $this->sourceFormatOf($child, $xmlUrl),
                );
                continue;
            }

            $groupName = $this->groupName($child);
            $childPath = $groupName === null ? $groupPath : [...$groupPath, $groupName];
            foreach ($this->collectFeeds($child, $childPath) as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param list<BulkSubscribeItemModel> $items
     *
     * @return list<BulkSubscribeItemModel>
     */
    private function withoutScraped(array $items): array
    {
        return array_values(array_filter(
            $items,
            static fn (BulkSubscribeItemModel $item): bool => $item->sourceFormat !== SourceFormat::SCRAPED,
        ));
    }

    private function sourceFormatOf(\DOMElement $outline, string $xmlUrl): string
    {
        if (!$outline->hasAttribute('sourceFormat')) {
            return MixcloudUrl::isCloudcastsUrl($xmlUrl) ? SourceFormat::MIXCLOUD_API : SourceFormat::XML;
        }
        $declared = trim($outline->getAttribute('sourceFormat'));

        return SourceFormat::isVerbatim($declared) ? $declared : SourceFormat::XML;
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
