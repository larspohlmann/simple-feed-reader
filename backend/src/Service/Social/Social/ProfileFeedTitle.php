<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Enum\SocialNetwork;
use App\Service\Ingestion\Ingest\FeedTitle\FeedTitleInterface;
use App\Service\Social\Social\Support\ProfileFeedUrl;

/**
 * A Bluesky profile feed names its channel `@handle - Display Name`; the reader shows the person by their display
 * name. A Lemmy community feed names its channel `Site - community`; the reader names the community first. A
 * Mastodon `@user.rss` channel already carries only the display name.
 */
final readonly class ProfileFeedTitle implements FeedTitleInterface
{
    private const string HANDLE_THEN_DISPLAY_NAME = '/^@\S+ - (.*)$/su';

    public function of(string $feedUrl, string $channelTitle): string
    {
        return match (ProfileFeedUrl::networkOf($feedUrl)) {
            SocialNetwork::Bluesky => $this->blueskyTitle($channelTitle),
            SocialNetwork::Lemmy => $this->lemmyTitle($feedUrl, $channelTitle),
            default => $channelTitle,
        };
    }

    private function blueskyTitle(string $channelTitle): string
    {
        if (1 !== preg_match(self::HANDLE_THEN_DISPLAY_NAME, $channelTitle, $match) || '' === trim($match[1])) {
            return $channelTitle;
        }

        return trim($match[1]);
    }

    private function lemmyTitle(string $feedUrl, string $channelTitle): string
    {
        $community = strstr((string) ProfileFeedUrl::identityOf(SocialNetwork::Lemmy, $feedUrl), '@', true);
        $siteThenCommunity = '/^(?<site>.*\S) - (?<community>' . preg_quote((string) $community, '/') . ')$/sui';
        if (1 !== preg_match($siteThenCommunity, $channelTitle, $match)) {
            return $channelTitle;
        }

        return $match['community'] . ' - ' . $match['site'];
    }
}
