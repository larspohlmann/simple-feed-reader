<?php

declare(strict_types=1);

namespace App\Service\Social\Lemmy;

use App\Entity\SocialAccount;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Repository\FeedRepository;
use App\Repository\LemmyPostRepository;
use App\Repository\SocialAccountRepository;

/** The hosts the reader's own accounts, feeds and posts show to be Lemmy instances. */
final readonly class LemmyHosts
{
    public function __construct(
        private SocialAccountRepository $socialAccounts,
        private FeedRepository $feeds,
        private LemmyPostRepository $lemmyPosts,
    ) {
    }

    /** @return list<string> lower-case, each once, sorted */
    public function collectForUser(User $user): array
    {
        $userId = $user->requireId();
        $addresses = [
            ...array_map(
                static fn (SocialAccount $account): string => $account->getServiceUrl(),
                $this->socialAccounts->findAllOnNetwork($user, SocialNetwork::Lemmy),
            ),
            ...$this->feeds->findUrlsOnNetworkForUser($userId, SocialNetwork::Lemmy),
            ...$this->lemmyPosts->findCommunityUrlsForUser($userId),
        ];

        $hosts = array_values(array_unique(array_filter(array_map(self::parseHost(...), $addresses))));
        sort($hosts);

        return $hosts;
    }

    private static function parseHost(string $address): ?string
    {
        $host = parse_url($address, PHP_URL_HOST);

        return \is_string($host) && '' !== $host ? strtolower($host) : null;
    }
}
