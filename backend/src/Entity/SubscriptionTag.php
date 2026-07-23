<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SubscriptionTagRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * The subscription↔tag association, promoted from a plain many-to-many join so
 * it can carry a per-tag ordering. A feed's order within one tag is independent
 * of its order within another.
 */
#[ORM\Entity(repositoryClass: SubscriptionTagRepository::class)]
#[ORM\Table(name: 'subscription_tag')]
class SubscriptionTag
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Subscription::class, inversedBy: 'subscriptionTags')]
    #[ORM\JoinColumn(name: 'subscription_id', onDelete: 'CASCADE')]
    private Subscription $subscription;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Tag::class)]
    #[ORM\JoinColumn(name: 'tag_id', onDelete: 'CASCADE')]
    private Tag $tag;

    /** The feed's order within this tag (ascending). */
    #[ORM\Column(options: ['default' => 0])]
    private int $position;

    public function __construct(Subscription $subscription, Tag $tag, int $position = 0)
    {
        $this->subscription = $subscription;
        $this->tag = $tag;
        $this->position = $position;
    }

    public function getSubscription(): Subscription
    {
        return $this->subscription;
    }

    public function getTag(): Tag
    {
        return $this->tag;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }
}
