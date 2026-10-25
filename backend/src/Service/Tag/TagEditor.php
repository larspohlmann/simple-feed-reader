<?php

declare(strict_types=1);

namespace App\Service\Tag;

use App\Entity\Tag;
use App\Entity\User;
use App\Repository\SubscriptionScopeRepository;
use App\Repository\TagRepository;
use App\Service\Tag\Exception\InvalidTagParentException;
use App\Service\Tag\Exception\TagNameTakenException;
use App\Service\Tag\Factory\TagFactory;
use App\Service\Tag\Model\TagDetailsModel;
use App\Service\Tag\Pass\TagTree;
use Doctrine\ORM\EntityManagerInterface;

final readonly class TagEditor
{
    public function __construct(
        private TagRepository $tags,
        private SubscriptionScopeRepository $subscriptionScope,
        private EntityManagerInterface $entityManager,
        private TagFactory $tagFactory,
    ) {
    }

    public function create(User $user, TagDetailsModel $details): Tag
    {
        if ($this->tags->existsForUserAndName($user->requireId(), $details->name)) {
            throw new TagNameTakenException();
        }

        $tag = $this->tagFactory->create($user, $details, $this->tags->nextPositionForUser($user->requireId()));
        $tag->setParent($this->parentForNewTag($user, $details->parentId));
        $this->entityManager->persist($tag);
        $this->entityManager->flush();

        return $tag;
    }

    public function update(Tag $tag, TagDetailsModel $details): void
    {
        if ($this->tags->existsForUserAndName($tag->getUser()->requireId(), $details->name, $tag->requireId())) {
            throw new TagNameTakenException();
        }

        $this->moveUnder($tag, $this->parentFor($tag, $details->parentId));
        $tag->setName($details->name);
        $tag->setColor($details->color);
        $tag->setIcon($details->icon);
        $this->entityManager->flush();
    }

    public function delete(Tag $tag): void
    {
        $this->liftChildren($tag);
        $carriers = $this->subscriptionScope->findForUserByTagId($tag->getUser()->requireId(), $tag->requireId());
        foreach ($carriers as $subscription) {
            $subscription->removeTag($tag);
        }
        $this->entityManager->remove($tag);
        $this->entityManager->flush();
    }

    private function moveUnder(Tag $tag, ?Tag $parent): void
    {
        if ($parent === $tag->getParent()) {
            return;
        }
        $tag->setParent($parent);
        $tag->setPosition($this->tags->nextPositionForUser($tag->getUser()->requireId()));
    }

    private function liftChildren(Tag $tag): void
    {
        $children = $this->tags->findChildren($tag);
        if ($children === []) {
            return;
        }
        $nextPosition = $this->tags->nextPositionForUser($tag->getUser()->requireId());
        foreach ($children as $child) {
            $child->setParent($tag->getParent());
            $child->setPosition($nextPosition++);
        }
    }

    private function parentForNewTag(User $user, ?int $parentId): ?Tag
    {
        if ($parentId === null) {
            return null;
        }
        $parent = $this->ownedParent($user, $parentId);
        $this->assertFitsUnder($this->treeOf($user), $parent, 0);

        return $parent;
    }

    private function parentFor(Tag $tag, ?int $parentId): ?Tag
    {
        if ($parentId === null) {
            return null;
        }
        if ($tag->requireId() === $parentId) {
            throw InvalidTagParentException::itself();
        }
        $parent = $this->ownedParent($tag->getUser(), $parentId);
        $tree = $this->treeOf($tag->getUser());
        if ($tree->isWithin($parent->requireId(), $tag->requireId())) {
            throw InvalidTagParentException::underItsOwnChild();
        }
        $this->assertFitsUnder($tree, $parent, $tree->heightOf($tag->requireId()));

        return $parent;
    }

    private function ownedParent(User $user, int $parentId): Tag
    {
        return $this->tags->findOneForUser($user->requireId(), $parentId)
            ?? throw InvalidTagParentException::unknown();
    }

    private function assertFitsUnder(TagTree $tree, Tag $parent, int $height): void
    {
        if ($tree->depthOf($parent->requireId()) + 1 + $height > Tag::MAX_DEPTH) {
            throw InvalidTagParentException::tooDeep();
        }
    }

    private function treeOf(User $user): TagTree
    {
        return new TagTree($this->tags->findForUser($user->requireId()));
    }
}
