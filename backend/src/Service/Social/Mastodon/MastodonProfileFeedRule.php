<?php

declare(strict_types=1);

namespace App\Service\Social\Mastodon;

use App\Service\Social\Social\ProfileFeedRule\ProfileFeedRuleInterface;
use App\Enum\SocialNetwork;
use App\Service\Social\Mastodon\Support\MastodonProfileFeed;
use App\Service\Social\Social\Support\ProfileIdentity;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Mastodon->value)]
final readonly class MastodonProfileFeedRule implements ProfileFeedRuleInterface
{
    public function buildFeedUrl(string $identity): ?string
    {
        $nameAndHost = ProfileIdentity::parseNameAndHost($identity);
        if (null === $nameAndHost) {
            return null;
        }
        $url = MastodonProfileFeed::buildUrl(...$nameAndHost);

        return ProfileIdentity::parseRoundTripUrl($url, MastodonProfileFeed::parseIdentity($url), $identity);
    }

    public function parseIdentity(string $feedUrl): ?string
    {
        return MastodonProfileFeed::parseIdentity($feedUrl);
    }

    public function formatHandle(string $identity): string
    {
        return '@' . $identity;
    }

    /** An `@user.rss` channel already carries only the display name. */
    public function formatTitle(string $feedUrl, string $channelTitle): string
    {
        return $channelTitle;
    }

    public function buildExportUrl(string $feedUrl): ?string
    {
        return null;
    }
}
