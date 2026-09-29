<?php

declare(strict_types=1);

namespace App\Http;

use App\Dto\Admin\AdminSubscriptionTag;
use App\Dto\Admin\AdminUserAccount;
use App\Dto\Admin\AdminUserFootprint;
use App\Dto\Admin\AdminUserLimits;
use App\Dto\Admin\AdminUserSubscription;
use App\Dto\Admin\AdminUserTag;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\Admin\Model\UserFootprintModel;

/**
 * The admin view of one account and of the approval queue, built field by field so a column added to User never
 * reaches an admin's browser by default. The builders never query: AdminUserController::detail() loads the rows once
 * (AdminUserControllerTest::testTheDetailListsCostTheSameNumberOfQueriesHoweverManySubscriptionsAndTagsExist).
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
                // How this person signed up: an OAuth account has no verification mail to chase and may
                // carry an `@oauth.invalid` placeholder, which both look like anomalies without it.
                'identities' => $providersByUserId[$user->getId()] ?? [],
                // Footprint at a glance. A user with none of either is absent
                // from the batched counts, hence the ?? 0.
                'feedsCount' => $feedCounts[$user->getId()] ?? 0,
                'tagsCount' => $tagCounts[$user->getId()] ?? 0,
                'lastLoginAt' => $user->getLastLoginAt()?->format(\DateTimeInterface::ATOM),
                'trialEndsAt' => TrialEndJson::of($user),
                'maxSubscriptions' => $user->getMaxSubscriptions(),
            ],
            $users,
        );
    }

    /**
     * The owner's own order (Subscription::position). Sorted here, not in findForUserWithTags(), whose other callers
     * keep its createdAt/id order.
     *
     * @param list<Subscription> $subscriptions
     *
     * @return list<Subscription>
     */
    public static function positionOrdered(array $subscriptions): array
    {
        $ordered = $subscriptions;
        usort(
            $ordered,
            static fn (Subscription $left, Subscription $right): int
                => $left->getPosition() <=> $right->getPosition(),
        );

        return $ordered;
    }

    /**
     * @param list<string> $identities the provider names, loaded by the caller
     */
    public static function account(User $user, array $identities): AdminUserAccount
    {
        return new AdminUserAccount(
            id: $user->requireId(),
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

    public static function limits(User $user): AdminUserLimits
    {
        return new AdminUserLimits(
            trialEndsAt: TrialEndJson::of($user),
            maxSubscriptions: $user->getMaxSubscriptions(),
        );
    }

    public static function footprint(UserFootprintModel $footprint): AdminUserFootprint
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
                $tagId = $tag->requireId();
                $feedsPerTag[$tagId] = ($feedsPerTag[$tagId] ?? 0) + 1;
            }
        }

        return array_map(
            static fn (Tag $tag): AdminUserTag => new AdminUserTag(
                id: $tag->requireId(),
                name: $tag->getName(),
                color: $tag->getColor(),
                icon: $tag->getIcon(),
                position: $tag->getPosition(),
                feedsCount: $feedsPerTag[$tag->requireId()] ?? 0,
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
                id: $subscription->requireId(),
                title: $subscription->getFeed()->getTitle(),
                customTitle: $subscription->getCustomTitle(),
                url: $subscription->getFeed()->getUrl(),
                position: $subscription->getPosition(),
                createdAt: $subscription->getCreatedAt()->format(\DateTimeInterface::ATOM),
                lastFetchedAt: $subscription->getFeed()->getLastFetchedAt()?->format(\DateTimeInterface::ATOM),
                tags: array_map(
                    static fn (Tag $tag): AdminSubscriptionTag => new AdminSubscriptionTag(
                        id: $tag->requireId(),
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
