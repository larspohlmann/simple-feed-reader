<?php

declare(strict_types=1);

namespace App\Service\Tag;

use App\Entity\Tag;
use App\Entity\User;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;
use App\Service\Tag\Exception\TagNameTakenException;
use App\Service\Tag\Factory\TagFactory;
use Doctrine\ORM\EntityManagerInterface;

final readonly class TagEditor
{
    public function __construct(
        private TagRepository $tags,
        private SubscriptionRepository $subscriptions,
        private EntityManagerInterface $entityManager,
        private TagFactory $tagFactory,
    ) {
    }

    public function create(User $user, TagDetails $details): Tag
    {
        if ($this->tags->existsForUserAndName($user->requireId(), $details->name)) {
            throw new TagNameTakenException();
        }

        $tag = $this->tagFactory->create($user, $details, $this->tags->nextPositionForUser($user->requireId()));
        $this->entityManager->persist($tag);
        $this->entityManager->flush();

        return $tag;
    }

    public function update(Tag $tag, TagDetails $details): void
    {
        if ($this->tags->existsForUserAndName($tag->getUser()->requireId(), $details->name, $tag->requireId())) {
            throw new TagNameTakenException();
        }

        $tag->setName($details->name);
        $tag->setColor($details->color);
        $tag->setIcon($details->icon);
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
