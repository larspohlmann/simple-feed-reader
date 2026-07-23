<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SubscriptionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SubscriptionRepository::class)]
#[ORM\Table(name: 'subscription')]
#[ORM\UniqueConstraint(name: 'uniq_subscription_user_feed', columns: ['user_id', 'feed_id'])]
class Subscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Feed::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Feed $feed;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $customTitle = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $markedReadUntil = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @var Collection<int, SubscriptionTag>
     */
    #[ORM\OneToMany(
        targetEntity: SubscriptionTag::class,
        mappedBy: 'subscription',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    private Collection $subscriptionTags;

    /** Order in the untagged "Feeds" list (ascending). */
    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    public function __construct(User $user, Feed $feed, \DateTimeImmutable $createdAt)
    {
        $this->user = $user;
        $this->feed = $feed;
        $this->createdAt = $createdAt;
        $this->subscriptionTags = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getFeed(): Feed
    {
        return $this->feed;
    }

    public function getCustomTitle(): ?string
    {
        return $this->customTitle;
    }

    public function setCustomTitle(?string $customTitle): void
    {
        $this->customTitle = $customTitle;
    }

    public function getMarkedReadUntil(): ?\DateTimeImmutable
    {
        return $this->markedReadUntil;
    }

    public function setMarkedReadUntil(?\DateTimeImmutable $markedReadUntil): void
    {
        $this->markedReadUntil = $markedReadUntil;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }

    /**
     * The tags on this subscription, ordered by their per-tag position. Kept as
     * the public read shape so callers that only need tags are unaffected by the
     * join being an entity now.
     *
     * @return Collection<int, Tag>
     */
    public function getTags(): Collection
    {
        return new ArrayCollection(
            array_map(
                static fn (SubscriptionTag $st): Tag => $st->getTag(),
                $this->orderedSubscriptionTags(),
            ),
        );
    }

    /**
     * The join rows, ordered by position — for serialization that needs the
     * per-tag order.
     *
     * @return list<SubscriptionTag>
     */
    public function getSubscriptionTags(): array
    {
        return $this->orderedSubscriptionTags();
    }

    /**
     * Attach a tag at the given position within that tag. No-op if already
     * attached (so re-adding a retained tag preserves its existing position).
     */
    public function addTag(Tag $tag, int $position = 0): void
    {
        if (null !== $this->findJoin($tag)) {
            return;
        }
        $this->subscriptionTags->add(new SubscriptionTag($this, $tag, $position));
    }

    public function removeTag(Tag $tag): void
    {
        $join = $this->findJoin($tag);
        if (null !== $join) {
            $this->subscriptionTags->removeElement($join);
        }
    }

    private function findJoin(Tag $tag): ?SubscriptionTag
    {
        foreach ($this->subscriptionTags as $st) {
            // Identity, not id: unpersisted tags share a null id, and within one
            // unit of work the same tag is always the same instance.
            if ($st->getTag() === $tag) {
                return $st;
            }
        }

        return null;
    }

    /**
     * @return list<SubscriptionTag>
     */
    private function orderedSubscriptionTags(): array
    {
        $rows = array_values($this->subscriptionTags->toArray());
        usort(
            $rows,
            static fn (SubscriptionTag $a, SubscriptionTag $b): int => $a->getPosition() <=> $b->getPosition(),
        );

        return $rows;
    }
}
