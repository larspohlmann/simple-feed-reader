<?php

declare(strict_types=1);

namespace App\Service\Content\LinkCard\Model;

use App\Service\Foundation\Url\Support\TrackingFreeUrl;

/** What names a fetched page, short of its body: where it landed, the address it declares, and its title. */
final readonly class PageIdentityModel
{
    public function __construct(
        public string $finalUrl,
        public ?string $canonicalUrl,
        public string $title,
    ) {
    }

    public function isSamePageAs(self $other): bool
    {
        return $this->title === $other->title
            && (TrackingFreeUrl::stripTracking($this->finalUrl) === TrackingFreeUrl::stripTracking($other->finalUrl)
                || ($this->canonicalUrl !== null
                    && TrackingFreeUrl::stripTracking($this->canonicalUrl)
                        === TrackingFreeUrl::orNull($other->canonicalUrl)));
    }
}
