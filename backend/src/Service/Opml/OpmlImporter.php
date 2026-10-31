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

        $result = $this->subscriber->subscribeAll($user, $this->collectFeeds($body, []));

        return new OpmlImportResultModel(
            imported: $result->imported,
            alreadySubscribed: $result->alreadySubscribed,
            invalid: $result->invalid,
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

    private function sourceFormatOf(\DOMElement $outline, string $xmlUrl): string
    {
        $declared = $outline->getAttribute('sourceFormat');
        if (SourceFormat::isVerbatim($declared)) {
            return $declared;
        }

        return MixcloudUrl::isCloudcastsUrl($xmlUrl) ? SourceFormat::MIXCLOUD_API : SourceFormat::XML;
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
