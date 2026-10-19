<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Entity\SocialAccount;
use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Service\Social\Model\PrivateFeedModel;
use App\Service\Social\Model\SocialFeedAddressModel;

/**
 * A private feed's synthetic URL, `social:<network>:<accountId>:<kind>[:<remoteId>]`. Keyed by the account, never the
 * DID: two users may connect the same network account, and each must get a feed row of their own.
 */
final class SocialFeedUrl
{
    private const string SCHEME = 'social:';
    private const string ACCOUNT_ID = '/^[1-9]\d*$/';
    private const array SEGMENTS = [
        'home' => 'home',
        'list' => 'list',
        'custom_feed' => 'feed',
        'author' => 'author',
        'tag' => 'tag',
        'trending' => 'trending',
    ];

    public static function of(SocialAccount $account, PrivateFeedModel $privateFeed): string
    {
        $url = self::SCHEME . $account->getNetwork()->value . ':' . $account->requireId() . ':'
            . self::segmentOf($privateFeed->kind);

        return '' === $privateFeed->remoteId ? $url : $url . ':' . $privateFeed->remoteId;
    }

    public static function isPrivate(string $url): bool
    {
        return str_starts_with($url, self::SCHEME);
    }

    public static function addressOf(string $url): ?SocialFeedAddressModel
    {
        if (!self::isPrivate($url)) {
            return null;
        }
        $parts = explode(':', $url, 5);
        $network = SocialNetwork::tryFrom($parts[1] ?? '');
        $kind = self::kindOf($parts[3] ?? '');
        $accountId = $parts[2] ?? '';
        if (null === $network || null === $kind || 1 !== preg_match(self::ACCOUNT_ID, $accountId)) {
            return null;
        }

        return new SocialFeedAddressModel($network, (int) $accountId, $kind, $parts[4] ?? '');
    }

    /** @return string|null the DID an author feed of $network follows; null for any other URL */
    public static function authorOf(SocialNetwork $network, string $url): ?string
    {
        $address = self::addressOf($url);
        if (null === $address || $network !== $address->network || !$address->kind->followsOnePerson()) {
            return null;
        }

        return BlueskyActor::isDid($address->remoteId) ? $address->remoteId : null;
    }

    private static function segmentOf(SocialFeedKind $kind): string
    {
        return self::SEGMENTS[$kind->value];
    }

    private static function kindOf(string $segment): ?SocialFeedKind
    {
        $kind = array_search($segment, self::SEGMENTS, true);

        return false === $kind ? null : SocialFeedKind::from($kind);
    }

    private function __construct()
    {
    }
}
