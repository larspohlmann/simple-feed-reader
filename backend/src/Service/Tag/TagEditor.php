<?php

declare(strict_types=1);

namespace App\Service\Tag;

use App\Dto\Tag\CreateTagRequest;
use App\Dto\Tag\UpdateTagRequest;
use App\Entity\Tag;
use App\Entity\User;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;
use App\Service\Tag\Exception\TagNameTakenException;
use Doctrine\ORM\EntityManagerInterface;

final readonly class TagEditor
{
    public function __construct(
        private TagRepository $tags,
        private SubscriptionRepository $subscriptions,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function create(User $user, CreateTagRequest $request): Tag
    {
        if ($this->tags->existsForUserAndName($user->requireId(), $request->name)) {
            throw new TagNameTakenException();
        }

        $tag = new Tag($user, $request->name);
        $tag->setColor($request->color);
        $tag->setIcon($request->icon);
        $tag->setPosition($this->tags->nextPositionForUser($user->requireId()));
        $this->entityManager->persist($tag);
        $this->entityManager->flush();

        return $tag;
    }

    public function update(Tag $tag, UpdateTagRequest $request): void
    {
        if ($this->tags->existsForUserAndName($tag->getUser()->requireId(), $request->name, $tag->requireId())) {
            throw new TagNameTakenException();
        }

        $tag->setName($request->name);
        $tag->setColor($request->color);
        $tag->setIcon($request->icon);
        $this->entityManager->flush();
    }

    public function delete(Tag $tag): void
    {
        $carriers = $this->subscriptions->findForUserByTagId($tag->getUser()->requireId(), $tag->requireId());
        foreach ($carriers as $subscription) {
            $subscription->removeTag($tag);
        }
        $this->entityManager->remove($tag);
        $this->entityManager->flush();
    }
}
