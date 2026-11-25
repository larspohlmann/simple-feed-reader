<?php

declare(strict_types=1);

namespace App\Service\Library\Opml;

use App\Entity\User;
use App\Repository\TagRepository;
use App\Service\Library\Opml\Model\OpmlFolderModel;
use App\Service\Library\Opml\Model\OpmlPlacedFolderModel;
use App\Service\Library\Opml\Model\OpmlPlacementChoiceModel;
use App\Service\Library\Opml\Pass\OpmlFolderPlacing;
use App\Service\Library\Opml\Pass\OpmlTagPlan;

final readonly class OpmlPlacementPlanner
{
    public function __construct(private TagRepository $tags)
    {
    }

    public function plan(User $user, OpmlFolderModel $root, OpmlPlacementChoiceModel $choice): OpmlPlacedFolderModel
    {
        $placing = new OpmlFolderPlacing(
            new OpmlTagPlan($this->tags->findForUser($user->requireId())),
            $choice->folderNames,
        );
        $parentName = trim($choice->parentTagName ?? '');

        return $placing->place($root, $parentName === '' ? [] : [$parentName], null);
    }
}
