<?php

declare(strict_types=1);

namespace App\Service\Library\Opml\Model;

final readonly class OpmlPlacedFolderModel
{
    /**
     * @param list<string>                $destination final tag names, outermost first; [] for no tag
     * @param list<OpmlFeedModel>         $feeds
     * @param list<OpmlPlacedFolderModel> $folders
     */
    public function __construct(
        public ?string $name,
        public bool $dropped,
        public array $destination,
        public array $feeds,
        public array $folders,
    ) {
    }
}
