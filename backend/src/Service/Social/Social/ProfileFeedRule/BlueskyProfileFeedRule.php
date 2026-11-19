<?php

declare(strict_types=1);

namespace App\Service\Social\Social\ProfileFeedRule;

use App\Enum\SocialNetwork;
use App\Service\Social\Bluesky\Support\BlueskyActorKey;
use App\Service\Social\Social\Support\BlueskyProfileFeed;
use App\Service\Social\Social\Support\ProfileIdentity;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Bluesky->value)]
final readonly class BlueskyProfileFeedRule implements ProfileFeedRuleInterface
{
    private const string HANDLE_THEN_DISPLAY_NAME = '/^@\S+ - (.*)$/su';

    public function buildFeedUrl(string $identity): ?string
    {
        $url = BlueskyProfileFeed::buildUrl($identity);

        return ProfileIdentity::isRoundTrip(BlueskyProfileFeed::parseIdentity($url), $identity) ? $url : null;
    }

    public function parseIdentity(string $feedUrl): ?string
    {
        return BlueskyProfileFeed::parseIdentity($feedUrl);
    }

    public function formatHandle(string $identity): string
    {
        return BlueskyActorKey::isDid($identity) ? $identity : '@' . $identity;
    }

    /** The channel reads `@handle - Display Name`; the reader shows the person by their display name. */
    public function formatTitle(string $feedUrl, string $channelTitle): string
    {
        if (1 !== preg_match(self::HANDLE_THEN_DISPLAY_NAME, $channelTitle, $match) || '' === trim($match[1])) {
            return $channelTitle;
        }

        return trim($match[1]);
    }
}
