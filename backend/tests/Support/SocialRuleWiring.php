<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Enum\SocialNetwork;
use App\Service\Social\Social\PostAddressRule\BlueskyPostAddressRule;
use App\Service\Social\Social\PostAddressRule\LemmyPostAddressRule;
use App\Service\Social\Social\PostAddressRule\MastodonPostAddressRule;
use App\Service\Social\Social\SocialPostAddresses;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;

/** The per-network rule pickers over their real adapters, as the container wires them. */
final class SocialRuleWiring
{
    public static function postAddresses(): SocialPostAddresses
    {
        $rules = [
            SocialNetwork::Bluesky->value => new BlueskyPostAddressRule(),
            SocialNetwork::Mastodon->value => new MastodonPostAddressRule(),
            SocialNetwork::Lemmy->value => new LemmyPostAddressRule(),
        ];

        return new SocialPostAddresses(self::locatorOf($rules), array_values($rules));
    }

    /** @param array<string, object> $rules keyed by SocialNetwork value */
    private static function locatorOf(array $rules): ContainerInterface
    {
        return new ServiceLocator(array_map(static fn (object $rule): \Closure => static fn () => $rule, $rules));
    }

    private function __construct()
    {
    }
}
