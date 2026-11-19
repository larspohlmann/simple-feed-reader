<?php

declare(strict_types=1);

namespace App\Service\Social\Lemmy;

use App\Enum\SocialNetwork;
use App\Service\Social\Lemmy\Support\LemmyProfileFeed;
use App\Service\Social\Social\ProfileFeedRule\ProfileFeedRuleInterface;
use App\Service\Social\Social\Support\ProfileIdentity;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Lemmy->value)]
final readonly class LemmyProfileFeedRule implements ProfileFeedRuleInterface
{
    public function buildFeedUrl(string $identity): ?string
    {
        $nameAndHost = ProfileIdentity::parseNameAndHost($identity);
        if (null === $nameAndHost) {
            return null;
        }
        $url = LemmyProfileFeed::buildUrl(...$nameAndHost);

        return ProfileIdentity::parseRoundTripUrl($url, LemmyProfileFeed::parseIdentity($url), $identity);
    }

    public function parseIdentity(string $feedUrl): ?string
    {
        return LemmyProfileFeed::parseIdentity($feedUrl);
    }

    public function formatHandle(string $identity): string
    {
        return null === LemmyProfileFeed::parseUser($identity) ? '!' . $identity : $identity;
    }

    /** The channel reads `Site - community`; the reader names the community first. */
    public function formatTitle(string $feedUrl, string $channelTitle): string
    {
        $identity = (string) LemmyProfileFeed::parseIdentity($feedUrl);
        $identity = LemmyProfileFeed::parseUser($identity) ?? $identity;
        $community = strstr($identity, '@', true);
        $siteThenCommunity = '/^(?<site>.*\S) - (?<community>' . preg_quote((string) $community, '/') . ')$/sui';
        if (1 !== preg_match($siteThenCommunity, $channelTitle, $match)) {
            return $channelTitle;
        }

        return $match['community'] . ' - ' . $match['site'];
    }

    public function buildExportUrl(string $feedUrl): ?string
    {
        return null;
    }
}
