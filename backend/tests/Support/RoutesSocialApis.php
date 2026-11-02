<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Entry;
use App\Entity\SocialAccount;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Service\Crypto\InstanceSecretCipher;
use App\Service\SavedPosts\Support\SavedFeedUrl;
use App\Service\Social\Crypto\SocialCredentialCipher;

/** For an ApiTestCase that answers the app's outbound social API calls itself. */
trait RoutesSocialApis
{
    use RoutesOutboundApis;

    private const string MASTODON_POST_URL = 'https://mastodon.social/@Gargron/110';

    private function mastodonPostFor(User $user): int
    {
        return $this->mastodonPostOf($user)->requireId();
    }

    private function mastodonPostOf(User $user): Entry
    {
        $fixtures = new NetworkFeedSubscriptions($this->entityManager());

        return $fixtures->post(
            $fixtures->subscribe($user, NetworkFeedSubscriptions::MASTODON_PROFILE),
            self::MASTODON_POST_URL,
            '2026-10-12T10:00:00Z',
        );
    }

    private function savedMastodonPostFor(User $user): int
    {
        $fixtures = new NetworkFeedSubscriptions($this->entityManager());
        $saved = $fixtures->subscribe(
            $user,
            SavedFeedUrl::of($user->requireId(), SocialNetwork::Mastodon),
            SourceFormat::SAVED,
        );

        return $fixtures->post($saved, self::MASTODON_POST_URL, '2026-10-12T10:00:00Z')->requireId();
    }

    private function mastodonAccountOf(User $user): SocialAccount
    {
        /** @var InstanceSecretCipher $instanceCipher */
        $instanceCipher = self::getContainer()->get(InstanceSecretCipher::class);
        $account = new SocialAccount(
            $user,
            SocialNetwork::Mastodon,
            'alice@mastodon.example',
            '@alice@mastodon.example',
            'https://mastodon.example',
            (new SocialCredentialCipher($instanceCipher))->seal($user->requireId(), SocialAccounts::ACCESS_TOKEN),
            '7890',
            new \DateTimeImmutable('2026-10-12 10:00:00'),
        );
        $this->entityManager()->persist($account);
        $this->entityManager()->flush();

        return $account;
    }

    /** @return list<string> */
    private function routesAsked(): array
    {
        return array_map(
            static fn (array $request): string => $request['method'] . ' ' . $request['url'],
            $this->http->requests,
        );
    }
}
