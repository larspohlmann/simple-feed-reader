<?php

declare(strict_types=1);

namespace App\Service\Library\SavedListExport\Model;

final readonly class SavedListOriginModel
{
    public function __construct(
        public string $title,
        public string $feedUrl,
        public ?string $siteUrl,
    ) {
    }

    public function isSubscribable(): bool
    {
        $scheme = parse_url($this->feedUrl, \PHP_URL_SCHEME);

        return \is_string($scheme) && \in_array(strtolower($scheme), ['http', 'https'], true);
    }
}
