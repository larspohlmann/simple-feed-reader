<?php

declare(strict_types=1);

namespace App\Service\Library\Opml\Model;

final readonly class OpmlImportSelectionModel
{
    /**
     * @param list<string>              $feedUrls
     * @param array<string, list<int>> $tagIdsByFeedUrl
     */
    public function __construct(
        public string $opml,
        public ?string $parentTagName,
        public array $feedUrls,
        public array $tagIdsByFeedUrl,
    ) {
    }
}
