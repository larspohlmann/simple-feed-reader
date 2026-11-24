<?php

declare(strict_types=1);

namespace App\Service\Library\Opml\Model;

final readonly class OpmlFolderModel
{
    /**
     * @param list<OpmlFeedModel>   $feeds
     * @param list<OpmlFolderModel> $folders
     */
    public function __construct(
        public ?string $name,
        public array $feeds,
        public array $folders,
    ) {
    }
}
