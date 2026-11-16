<?php

declare(strict_types=1);

namespace App\Service\Operations\UrlCleanup\Pass;

use App\Service\Operations\UrlCleanup\Model\StoredLinkAddressModel;
use App\Service\Operations\UrlCleanup\Model\TrackingUrlCleanupReportModel;
use App\Service\Operations\UrlCleanup\StoredUrlWriter\StoredUrlWriterInterface;

/**
 * One batch of links moving to their cleaned address. It tracks which link holds each hash as it writes, so a link
 * whose new hash another row holds, or one an earlier link of the batch just took, merges into that holder.
 */
final class LinkRehash
{
    /** @param array<string, int> $holders link id by url hash */
    public function __construct(
        private readonly StoredUrlWriterInterface $writer,
        private array $holders,
    ) {
    }

    public function apply(
        StoredLinkAddressModel $stored,
        StoredLinkAddressModel $cleaned,
    ): TrackingUrlCleanupReportModel {
        $holderId = $this->holders[$cleaned->urlHash] ?? $stored->id;
        unset($this->holders[$stored->urlHash]);
        if ($holderId !== $stored->id) {
            $this->writer->mergeLink($stored->id, $holderId);

            return new TrackingUrlCleanupReportModel(cardsMerged: 1);
        }
        $this->writer->relocateLink($stored->id, $cleaned->url, $cleaned->urlHash);
        $this->holders[$cleaned->urlHash] = $stored->id;

        return new TrackingUrlCleanupReportModel(
            cardsCleaned: (int) ($cleaned->url !== $stored->url),
            cardHashesChanged: (int) ($cleaned->urlHash !== $stored->urlHash),
        );
    }
}
