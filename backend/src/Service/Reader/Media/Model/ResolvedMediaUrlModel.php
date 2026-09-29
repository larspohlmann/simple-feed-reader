<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Model;

/** What a URL is, paired with the durable form a layer must emit. */
final readonly class ResolvedMediaUrlModel
{
    public function __construct(
        public MediaKind $kind,
        public string $url,
    ) {
    }
}
