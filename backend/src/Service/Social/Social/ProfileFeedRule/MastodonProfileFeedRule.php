<?php

declare(strict_types=1);

namespace App\Service\Social\Social\ProfileFeedRule;

use App\Enum\SocialNetwork;
use App\Service\Social\Social\Support\MastodonProfileFeed;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Mastodon->value)]
final readonly class MastodonProfileFeedRule implements ProfileFeedRuleInterface
{
    public function buildFeedUrl(string $identity): ?string
    {
        $account = explode('@', $identity);
        if (2 !== \count($account)) {
            return null;
        }
        $url = MastodonProfileFeed::buildUrl($account[0], $account[1]);
        $builtIdentity = MastodonProfileFeed::parseIdentity($url);

        return null !== $builtIdentity && 0 === strcasecmp($builtIdentity, $identity) ? $url : null;
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
}
