<?php

declare(strict_types=1);

namespace App\Service\Library\Opml;

use App\Entity\Tag;
use App\Entity\User;
use App\Repository\TagRepository;
use App\Service\Library\Opml\Model\OpmlFolderModel;
use App\Service\Library\Opml\Model\OpmlPlacedFolderModel;
use App\Service\Library\Opml\Pass\OpmlTagPlan;

final readonly class OpmlPlacementPlanner
{
    public function __construct(private TagRepository $tags)
    {
    }

    public function plan(User $user, OpmlFolderModel $root, ?string $parentTagName): OpmlPlacedFolderModel
    {
        $tagPlan = new OpmlTagPlan($this->tags->findForUser($user->requireId()));
        $parentName = trim($parentTagName ?? '');

        return self::place($tagPlan, $root, $parentName === '' ? [] : [$parentName]);
    }

    /** @param list<string> $parentPath */
    private static function place(
        OpmlTagPlan $tagPlan,
        OpmlFolderModel $folder,
        array $parentPath,
    ): OpmlPlacedFolderModel {
        $path = $folder->name === null ? $parentPath : [...$parentPath, $folder->name];
        $destination = $tagPlan->resolve(array_slice($path, 0, Tag::MAX_DEPTH + 1));
        $folders = array_map(
            static fn (OpmlFolderModel $child): OpmlPlacedFolderModel => self::place($tagPlan, $child, $path),
            $folder->folders,
        );

        return new OpmlPlacedFolderModel(
            $folder->name,
            count($path) > Tag::MAX_DEPTH + 1,
            $destination,
            $folder->feeds,
            $folders,
        );
    }
}
