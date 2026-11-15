<?php

declare(strict_types=1);

namespace App\Service\Content\LinkResolution\Model;

/** A queued short link and the attempts it has had, read before an attempt so its settle can find it unchanged. */
final readonly class PendingLinkModel
{
    public function __construct(
        public int $linkId,
        public string $url,
        public int $attempts,
    ) {
    }
}
