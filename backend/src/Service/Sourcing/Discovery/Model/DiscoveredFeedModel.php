<?php

declare(strict_types=1);

namespace App\Service\Discovery\Model;

use App\Service\Parser\Model\ParsedFeedModel;

/**
 * A feed discovery has read, not merely located: its final URL and the document that proved it a feed. The subscribe
 * stores this document instead of fetching the URL again, a second request some sites answer with 429 (#290).
 */
final readonly class DiscoveredFeedModel
{
    /**
     * The validators travel with the document: whoever stores it can send a
     * conditional request next time, exactly as the refresh pipeline does, so
     * seeding a feed does not cost it its first cheap poll.
     */
    public function __construct(
        public string $url,
        public ParsedFeedModel $document,
        public ?string $etag = null,
        public ?string $lastModified = null,
    ) {
    }
}
