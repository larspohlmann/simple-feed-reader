<?php

declare(strict_types=1);

namespace App\Service\Library\Opml\Model;

final readonly class OpmlFeedModel
{
    public function __construct(
        public string $url,
        public ?string $title,
        public string $sourceFormat,
        public ?string $siteUrl = null,
    ) {
    }
}
