<?php

declare(strict_types=1);

namespace App\Service\Social\Lemmy;

use App\Entity\SocialAccount;
use App\Entity\Subscription;
use App\Enum\SocialNetwork;
use App\Repository\LemmyPostRepository;

/** The hosts the reader's own accounts, feeds and posts show to be Lemmy instances. */
final readonly class LemmyHosts
{
    public function __construct(private LemmyPostRepository $lemmyPosts)
    {
    }

    /**
     * @param list<SocialAccount> $socialAccounts the reader's, already loaded
     * @param list<Subscription>  $subscriptions  the reader's, already loaded
     *
     * @return list<string> lower-case, each once, sorted; none without a Lemmy account, as no link could move then
     */
    public function collectFor(int $userId, array $socialAccounts, array $subscriptions): array
    {
        $accountAddresses = self::collectAccountAddresses($socialAccounts);
        if ([] === $accountAddresses) {
            return [];
        }
        $addresses = [
            ...$accountAddresses,
            ...self::collectFeedAddresses($subscriptions),
            ...$this->lemmyPosts->findCommunityUrlsForUser($userId),
        ];

        $hosts = array_unique(array_filter(array_map(self::parseHost(...), $addresses)));
        sort($hosts);

        return $hosts;
    }

    /**
     * @param list<SocialAccount> $socialAccounts
     *
     * @return list<string>
     */
    private static function collectAccountAddresses(array $socialAccounts): array
    {
        $addresses = [];
        foreach ($socialAccounts as $account) {
            if (SocialNetwork::Lemmy === $account->getNetwork()) {
                $addresses[] = $account->getServiceUrl();
            }
        }

        return $addresses;
    }

    /**
     * @param list<Subscription> $subscriptions
     *
     * @return list<string>
     */
    private static function collectFeedAddresses(array $subscriptions): array
    {
        $addresses = [];
        foreach ($subscriptions as $subscription) {
            $feed = $subscription->getFeed();
            if (SocialNetwork::Lemmy === $feed->getNetwork()) {
                $addresses[] = $feed->getUrl();
            }
        }

        return $addresses;
    }

    private static function parseHost(string $address): ?string
    {
        $host = parse_url($address, PHP_URL_HOST);

        return \is_string($host) && '' !== $host ? strtolower($host) : null;
    }
}
