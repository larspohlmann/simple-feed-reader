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
        $tag->setParent($this->parentForNew($user, $details->parentId));
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
        $this->releaseChildren($tag);
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

    private function releaseChildren(Tag $parent): void
    {
        $nextPosition = $this->tags->nextPositionForUser($parent->getUser()->requireId());
        foreach ($this->tags->findChildren($parent) as $child) {
            $child->setParent(null);
            $child->setPosition($nextPosition++);
        }
    }

    private function parentForNew(User $user, ?int $parentId): ?Tag
    {
        return $parentId === null ? null : $this->resolveParent($user, $parentId);
    }

    private function parentFor(Tag $tag, ?int $parentId): ?Tag
    {
        if ($parentId === null) {
            return null;
        }
        if ($tag->requireId() === $parentId) {
            throw InvalidTagParentException::itself();
        }
        if ($this->tags->hasChildren($tag)) {
            throw InvalidTagParentException::tagHasChildren();
        }

        return $this->resolveParent($tag->getUser(), $parentId);
    }

    private function resolveParent(User $user, int $parentId): Tag
    {
        $parent = $this->tags->findOneForUser($user->requireId(), $parentId)
            ?? throw InvalidTagParentException::unknown();
        if ($parent->getParent() !== null) {
            throw InvalidTagParentException::parentIsAChild();
        }

        return $parent;
    }
}
