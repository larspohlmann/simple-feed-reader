<?php

declare(strict_types=1);

namespace App\Service\Library\SavedListExport\Model;

use App\Service\Library\Subscription\Support\SubscribableUrl;

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
        return SubscribableUrl::isSubscribable($this->feedUrl);
    }
}
