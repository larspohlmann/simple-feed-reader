<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * "Mark all read until T" for a scope. Advances each affected subscription's
 * watermark to max(current, T) and — so entries a user had explicitly marked
 * unread also become read — flips the caller's existing EntryState rows in the
 * affected feeds whose effectiveDate <= T to isRead=true. Sparse (no-row)
 * entries are covered by the watermark alone.
 */
final readonly class MarkReadService
{
    public function __construct(
        private EntityManagerInterface $em,
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
            $feedIds[] = (int) $sub->getFeed()->getId();
            $current = $sub->getMarkedReadUntil();
            if ($current === null || $current < $until) {
                $sub->setMarkedReadUntil($until);
            }
        }

        // Atomic: the bulk read-flip and the watermark advance commit together,
        // so a crash between them can't leave entries half-marked (explicit rows
        // flipped but the watermark not yet advanced, or vice versa). Inside the
        // transaction the DQL UPDATE participates rather than auto-committing,
        // and wrapInTransaction() flushes the managed watermark changes before
        // committing.
        $this->em->wrapInTransaction(function () use ($user, $feedIds, $until): void {
            $this->em->createQuery(sprintf(
                'UPDATE %s es SET es.isRead = :true, es.readAt = :now
                 WHERE es.user = :user AND es.isRead = :false
                 AND es.entry IN (
                     SELECT e.id FROM %s e
                     WHERE e.feed IN (:feeds) AND COALESCE(e.publishedAt, e.createdAt) <= :until
                 )',
                EntryState::class,
                Entry::class,
            ))
                ->setParameter('true', true, Types::BOOLEAN)
                ->setParameter('false', false, Types::BOOLEAN)
                ->setParameter('now', $this->clock->now(), Types::DATETIME_IMMUTABLE)
                ->setParameter('user', $user->getId())
                ->setParameter('feeds', $feedIds)
                ->setParameter('until', $until, Types::DATETIME_IMMUTABLE)
                ->execute();
        });
    }

    /**
     * @return list<Subscription>
     */
    private function resolveScope(User $user, string $scope, ?int $id): array
    {
        $userId = (int) $user->getId();

        return match ($scope) {
            'all' => $this->subscriptions->findForUserWithTags($userId),
            'feed' => [$this->requireSubscription($id, $userId)],
            'tag' => $this->subscriptions->findForUserByTagId($userId, $this->requireTag($id, $userId)),
            default => throw new BadRequestHttpException(sprintf('Unknown scope "%s".', $scope)),
        };
    }

    private function requireSubscription(?int $id, int $userId): Subscription
    {
        if ($id === null) {
            throw new BadRequestHttpException('scope "feed" requires an id.');
        }

        return $this->subscriptions->findOneOwnedBy($id, $userId)
            ?? throw new NotFoundHttpException('No such subscription.');
    }

    private function requireTag(?int $id, int $userId): int
    {
        if ($id === null) {
            throw new BadRequestHttpException('scope "tag" requires an id.');
        }

        $tag = $this->tags->findOneOwnedBy($id, $userId)
            ?? throw new NotFoundHttpException('No such tag.');

        return (int) $tag->getId();
    }
}
