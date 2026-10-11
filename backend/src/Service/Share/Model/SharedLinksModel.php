<?php

declare(strict_types=1);

namespace App\Service\Share\Model;

final readonly class SharedLinksModel
{
    /** @param list<string> $urls in post order, each once */
    public function __construct(
        public array $urls,
        public string $linkedHtml,
    ) {
    }

    /**
     * @param list<string> $cardedUrls
     *
     * @return list<string> the shared links without a card, in post order
     */
    public function uncardedBy(array $cardedUrls): array
    {
        return array_values(array_diff($this->urls, $cardedUrls));
    }
}
