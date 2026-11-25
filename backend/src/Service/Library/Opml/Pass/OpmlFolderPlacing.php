<?php

declare(strict_types=1);

namespace App\Service\Library\Opml\Pass;

use App\Entity\Tag;
use App\Service\Library\Opml\Model\OpmlFolderModel;
use App\Service\Library\Opml\Model\OpmlPlacedFolderModel;

final readonly class OpmlFolderPlacing
{
    /** @param array<int|string, string> $folderNames */
    public function __construct(
        private OpmlTagPlan $tagPlan,
        private array $folderNames,
    ) {
    }

    /** @param list<string> $parentPath */
    public function place(OpmlFolderModel $folder, array $parentPath, ?string $position): OpmlPlacedFolderModel
    {
        $name = $position === null ? $folder->name : ($this->folderNames[$position] ?? $folder->name);
        $path = $name === null ? $parentPath : [...$parentPath, $name];
        $destination = $this->tagPlan->resolve(array_slice($path, 0, Tag::MAX_DEPTH + 1));
        $folders = [];
        foreach ($folder->folders as $index => $child) {
            $folders[] = $this->place($child, $path, $position === null ? (string) $index : "$position.$index");
        }

        return new OpmlPlacedFolderModel(
            $folder->name,
            count($path) > Tag::MAX_DEPTH + 1,
            $destination,
            $folder->feeds,
            $folders,
        );
    }
}
