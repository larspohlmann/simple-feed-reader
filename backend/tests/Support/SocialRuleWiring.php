<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Enum\SocialNetwork;
use App\Service\Foundation\CanonicalItem\CanonicalKeys;
use App\Service\Social\Bluesky\BlueskyCanonicalKeyRule;
use App\Service\Social\Bluesky\BlueskyImportRule;
use App\Service\Social\Bluesky\BlueskyPostAddressRule;
use App\Service\Social\Bluesky\BlueskyPrivateFeedRule;
use App\Service\Social\Bluesky\BlueskyProfileFeedRule;
use App\Service\Social\Bluesky\HiddenImportFollows;
use App\Service\Social\Bluesky\HiddenProfiles\HiddenProfilesInterface;
use App\Service\Social\Bluesky\TolerantHiddenProfiles;
use App\Service\Social\Lemmy\LemmyCanonicalKeyRule;
use App\Service\Social\Lemmy\LemmyImportRule;
use App\Service\Social\Lemmy\LemmyPostAddressRule;
use App\Service\Social\Lemmy\LemmyPrivateFeedRule;
use App\Service\Social\Lemmy\LemmyProfileFeedRule;
use App\Service\Social\Mastodon\MastodonCanonicalKeyRule;
use App\Service\Social\Mastodon\MastodonImportRule;
use App\Service\Social\Mastodon\MastodonPostAddressRule;
use App\Service\Social\Mastodon\MastodonPrivateFeedRule;
use App\Service\Social\Mastodon\MastodonProfileFeedRule;
use App\Service\Social\Social\ImportRules;
use App\Service\Social\Social\PrivateFeedRules;
use App\Service\Social\Social\ProfileFeeds;
use App\Service\Social\Social\SocialPostAddresses;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;

/** The per-network rule pickers over their real adapters, as the container wires them. */
final class SocialRuleWiring
{
    public static function buildCanonicalKeys(): CanonicalKeys
    {
        return new CanonicalKeys([
            new BlueskyCanonicalKeyRule(),
            new MastodonCanonicalKeyRule(),
            new LemmyCanonicalKeyRule(),
        ]);
    }

    public static function postAddresses(): SocialPostAddresses
    {
        $rules = [
            SocialNetwork::Bluesky->value => new BlueskyPostAddressRule(),
            SocialNetwork::Mastodon->value => new MastodonPostAddressRule(),
            SocialNetwork::Lemmy->value => new LemmyPostAddressRule(),
        ];

        return new SocialPostAddresses(self::locatorOf($rules));
    }

    public static function profileFeeds(): ProfileFeeds
    {
        return new ProfileFeeds(self::locatorOf([
            SocialNetwork::Bluesky->value => new BlueskyProfileFeedRule(),
            SocialNetwork::Mastodon->value => new MastodonProfileFeedRule(),
            SocialNetwork::Lemmy->value => new LemmyProfileFeedRule(),
        ]));
    }

    public static function privateFeedRules(): PrivateFeedRules
    {
        return new PrivateFeedRules(self::locatorOf([
            SocialNetwork::Bluesky->value => new BlueskyPrivateFeedRule(),
            SocialNetwork::Mastodon->value => new MastodonPrivateFeedRule(),
            SocialNetwork::Lemmy->value => new LemmyPrivateFeedRule(),
        ]));
    }

    /** Bluesky's handles resolve through $http; no follow is hidden unless $hiddenProfiles hides it. */
    public static function importRules(RoutedHttpClient $http, HiddenProfilesInterface $hiddenProfiles): ImportRules
    {
        $identities = SocialApiWiring::blueskyIdentityResolver($http->client());
        $hiddenFollows = new HiddenImportFollows(new TolerantHiddenProfiles($hiddenProfiles, new NullLogger()));

        return new ImportRules(self::locatorOf([
            SocialNetwork::Bluesky->value => new BlueskyImportRule($hiddenFollows, $identities),
            SocialNetwork::Mastodon->value => new MastodonImportRule(),
            SocialNetwork::Lemmy->value => new LemmyImportRule(),
        ]));
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
