<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Entity\Subscription;
use App\Entity\User;
use App\Exception\ValidationException;
use App\Repository\EntryReadMarkRepository;
use App\Repository\ReadMarking;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * "Mark all read until T" for a scope: advances each affected subscription's
 * watermark and flips existing EntryState rows already marked unread.
 */
final readonly class MarkReadService
{
    public function __construct(
        private EntityManagerInterface $em,
        private EntryReadMarkRepository $readMarks,
        private SubscriptionRepository $subscriptions,
        private TagRepository $tags,
        private ClockInterface $clock,
    ) {
    }

    public function mark(User $user, string $scope, ?int $id, \DateTimeImmutable $until): void
    {
        $subs = $this->resolveScope($user, $scope, $id);
        if ($subs === []) {
            return;
        }

        $feedIds = [];
        foreach ($subs as $sub) {
            $feedIds[] = $sub->getFeed()->requireId();
            $current = $sub->getMarkedReadUntil();
            if ($current === null || $current < $until) {
                $sub->setMarkedReadUntil($until);
            }
        }

        // Atomic: the read-flip joins the transaction, which flushes the watermark changes before it commits.
        $this->em->wrapInTransaction(function () use ($user, $feedIds, $until): void {
            $this->readMarks->hideUnreadInFeedsUntil(
                new ReadMarking($user->requireId(), $this->clock->now()),
                $feedIds,
                $until,
            );
        });
    }

    /**
     * @return list<Subscription>
     */
    private function resolveScope(User $user, string $scope, ?int $id): array
    {
        $userId = $user->requireId();

        return match ($scope) {
            'all' => $this->includedInAllItems($this->subscriptions->findForUserWithTags($userId)),
            'feed' => [$this->requireSubscription($id, $userId)],
            'tag' => $this->subscriptions->findForUserByTagId($userId, $this->requireTag($id, $userId)),
            default => throw new ValidationException(['scope' => [sprintf('Unknown scope "%s".', $scope)]]),
        };
    }

    /**
     * Scope "all" mirrors what the All-items list shows, so a feed hidden from
     * it must not have its watermark advanced or its entries flipped read.
     *
     * @param  list<Subscription> $subscriptions
     * @return list<Subscription>
     */
    private function includedInAllItems(array $subscriptions): array
    {
        return array_values(array_filter(
            $subscriptions,
            static fn (Subscription $subscription): bool => $subscription->isIncludeInAllItems(),
        ));
    }

    private function requireSubscription(?int $id, int $userId): Subscription
    {
        if ($id === null) {
            // Same validation_error contract as every other bad field, so the
            // client's type-switch handles a missing id uniformly.
            throw new ValidationException(['id' => ['An id is required when scope is "feed".']]);
        }

        return $this->subscriptions->getOneForUser($userId, $id);
    }

    private function requireTag(?int $id, int $userId): int
    {
        if ($id === null) {
            throw new ValidationException(['id' => ['An id is required when scope is "tag".']]);
        }

        return $this->tags->getOneForUser($userId, $id)->requireId();
    }
}
