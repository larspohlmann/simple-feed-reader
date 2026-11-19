<?php

declare(strict_types=1);

namespace App\Service\Social\Share\Model;

use App\Service\Foundation\Url\Support\TrackingFreeUrl;

final readonly class SharedLinksModel
{
    /**
     * @param list<string>          $urls        in post order, each once
     * @param array<string, string> $postedForms the posted href of each link that lost an undocumented key
     */
    public function __construct(
        public array $urls,
        public string $linkedHtml,
        public string $ownProse,
        public array $postedForms = [],
    ) {
    }

    /**
     * @param list<string> $cardedUrls
     *
     * @return list<string> the shared links without a card, in post order
     */
    public function uncardedBy(array $cardedUrls): array
    {
        return array_values(array_diff($this->urls, array_map(TrackingFreeUrl::stripTracking(...), $cardedUrls)));
    }
}
