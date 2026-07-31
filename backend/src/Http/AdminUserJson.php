<?php

declare(strict_types=1);

namespace App\Http;

use App\Dto\Admin\AdminSubscriptionTag;
use App\Dto\Admin\AdminUserAccount;
use App\Dto\Admin\AdminUserFootprint;
use App\Dto\Admin\AdminUserSubscription;
use App\Dto\Admin\AdminUserTag;
use App\Dto\Admin\UserFootprint;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;

/**
 * The admin view of one account and of the approval queue.
 *
 * Every row here is hand-built on purpose, like GET /api/me: a column added to
 * User (or its neighbours) later must not reach an admin's browser merely
 * because it exists. Note what is absent — the password hash and every token
 * column. That is the reason this mapper exists rather than serialising the
 * entities.
 *
 * The builders are pure: each takes the rows the caller already loaded and does
 * not query. That is load-bearing — detail() loads its heavy subscription×tag
 * set once and threads it through, pinned by
 * AdminUserControllerTest::testTheDetailListsCostTheSameNumberOfQueriesHoweverManySubscriptionsAndTagsExist.
 */
final class AdminUserJson
{
    /**
     * @param list<User>               $users
     * @param array<int, list<string>> $providersByUserId
     * @param array<int, int>          $feedCounts
     * @param array<int, int>          $tagCounts
     *
     * @return list<array<string, mixed>>
     */
    public static function listRows(
        array $users,
        array $providersByUserId,
        array $feedCounts,
        array $tagCounts,
    ): array {
        return array_map(
            static fn (User $user): array => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'status' => $user->getStatus()->value,
                'roles' => $user->getRoles(),
                'createdAt' => $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'approvedAt' => $user->getApprovedAt()?->format(\DateTimeInterface::ATOM),
                // How this person signed up. An OAuth account has no
                // verification mail for the admin to chase and may carry a
                // synthetic <provider>-<hash>@oauth.invalid address, and both of
                // those read as anomalies without this column.
                'identities' => $providersByUserId[$user->getId()] ?? [],
                // Footprint at a glance. A user with none of either is absent
                // from the batched counts, hence the ?? 0.
                'feedsCount' => $feedCounts[$user->getId()] ?? 0,
                'tagsCount' => $tagCounts[$user->getId()] ?? 0,
                'lastLoginAt' => $user->getLastLoginAt()?->format(\DateTimeInterface::ATOM),
            ],
            $users,
        );
    }

    /**
     * The user's own arrangement of their untagged/tagged "Feeds" list —
     * Subscription::position, assigned on every create path and rewritten
     * wholesale by the reorder endpoint. Sorted here rather than in
     * SubscriptionRepository::findForUserWithTags() itself: that method has
     * three other call sites (the reader's own subscription list, MarkReadService,
     * OpmlExporter) whose ordering needs were not part of this change, so its
     * existing createdAt/id order is left alone for them.
     *
     * @param list<Subscription> $subscriptions
     *
     * @return list<Subscription>
     */
    public static function positionOrdered(array $subscriptions): array
    {
        $ordered = $subscriptions;
        usort($ordered, static fn (Subscription $a, Subscription $b): int => $a->getPosition() <=> $b->getPosition());

        return $ordered;
    }

    /**
     * @param list<string> $identities the provider names, loaded by the caller
     */
    public static function account(User $user, array $identities): AdminUserAccount
    {
        return new AdminUserAccount(
            id: (int) $user->getId(),
            email: $user->getEmail(),
            status: $user->getStatus()->value,
            roles: $user->getRoles(),
            locale: $user->getLocale(),
            createdAt: $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
            approvedAt: $user->getApprovedAt()?->format(\DateTimeInterface::ATOM),
            lastLoginAt: $user->getLastLoginAt()?->format(\DateTimeInterface::ATOM),
            identities: $identities,
        );
    }

    public static function footprint(UserFootprint $footprint): AdminUserFootprint
    {
        return new AdminUserFootprint(
            feedsCount: $footprint->feedsCount,
            tagsCount: $footprint->tagsCount,
            feedsLimit: $footprint->feedsLimit,
            staleFeedsCount: $footprint->staleFeedsCount,
            lastRefreshAt: $footprint->lastRefreshAt?->format(\DateTimeInterface::ATOM),
            dormant: $footprint->dormant,
        );
    }

    /**
     * The account's tags in the order its owner arranged them, each with how
     * many of that account's feeds carry it.
     *
     * @param list<Tag>          $tags
     * @param list<Subscription> $subscriptions
     *
     * @return list<AdminUserTag>
     */
    public static function tags(array $tags, array $subscriptions): array
    {
        $feedsPerTag = [];
        foreach ($subscriptions as $subscription) {
            foreach ($subscription->getTags() as $tag) {
                $tagId = (int) $tag->getId();
                $feedsPerTag[$tagId] = ($feedsPerTag[$tagId] ?? 0) + 1;
            }
        }

        return array_map(
            static fn (Tag $tag): AdminUserTag => new AdminUserTag(
                id: (int) $tag->getId(),
                name: $tag->getName(),
                color: $tag->getColor(),
                icon: $tag->getIcon(),
                position: $tag->getPosition(),
                feedsCount: $feedsPerTag[(int) $tag->getId()] ?? 0,
            ),
            $tags,
        );
    }

    /**
     * The account's subscriptions in its owner's own position order, each with
     * the tags it carries and the freshness of the underlying feed.
     *
     * @param list<Subscription> $subscriptions
     *
     * @return list<AdminUserSubscription>
     */
    public static function subscriptions(array $subscriptions): array
    {
        return array_map(
            static fn (Subscription $subscription): AdminUserSubscription => new AdminUserSubscription(
                id: (int) $subscription->getId(),
                title: $subscription->getFeed()->getTitle(),
                customTitle: $subscription->getCustomTitle(),
                url: $subscription->getFeed()->getUrl(),
                position: $subscription->getPosition(),
                createdAt: $subscription->getCreatedAt()->format(\DateTimeInterface::ATOM),
                lastFetchedAt: $subscription->getFeed()->getLastFetchedAt()?->format(\DateTimeInterface::ATOM),
                tags: array_map(
                    static fn (Tag $tag): AdminSubscriptionTag => new AdminSubscriptionTag(
                        id: (int) $tag->getId(),
                        name: $tag->getName(),
                        color: $tag->getColor(),
                        icon: $tag->getIcon(),
                    ),
                    array_values($subscription->getTags()->toArray()),
                ),
            ),
            $subscriptions,
        );
    }
}
