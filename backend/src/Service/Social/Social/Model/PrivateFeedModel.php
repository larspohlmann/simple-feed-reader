<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Model;

use App\Enum\SocialFeedKind;
use App\Service\Social\Social\Support\SocialSearchQuery;

final readonly class PrivateFeedModel
{
    private function __construct(
        public SocialFeedKind $kind,
        /** '' for Home, Trending, Local and All */
        public string $remoteId,
        public string $name,
    ) {
    }

    /** @throws \InvalidArgumentException for a kind that is no timeline of its own */
    public static function timeline(SocialFeedKind $kind): self
    {
        return new self($kind, '', self::timelineNameOf($kind));
    }

    public static function userList(string $listId, string $name): self
    {
        return new self(SocialFeedKind::UserList, $listId, $name);
    }

    public static function customFeed(string $feedUri, string $name): self
    {
        return new self(SocialFeedKind::CustomFeed, $feedUri, $name);
    }

    public static function followedTag(string $tagName, string $name): self
    {
        return new self(SocialFeedKind::Tag, mb_strtolower($tagName), $name);
    }

    public static function author(string $did, string $name): self
    {
        return new self(SocialFeedKind::Author, $did, $name);
    }

    public static function search(string $query): self
    {
        $normalized = SocialSearchQuery::normalized($query);

        return new self(SocialFeedKind::Search, $normalized, $normalized);
    }

    public static function of(SocialFeedKind $kind, string $remoteId, string $name): self
    {
        return match ($kind) {
            SocialFeedKind::UserList => self::userList($remoteId, $name),
            SocialFeedKind::CustomFeed => self::customFeed($remoteId, $name),
            SocialFeedKind::Author => self::author($remoteId, $name),
            SocialFeedKind::Tag => self::followedTag($remoteId, $name),
            SocialFeedKind::Search => self::search($remoteId),
            SocialFeedKind::Home,
            SocialFeedKind::Trending,
            SocialFeedKind::Local,
            SocialFeedKind::All => self::timeline($kind),
        };
    }

    public static function keyOf(SocialFeedKind $kind, string $remoteId): string
    {
        return $kind->value . ':' . $remoteId;
    }

    public function key(): string
    {
        return self::keyOf($this->kind, $this->remoteId);
    }

    private static function timelineNameOf(SocialFeedKind $kind): string
    {
        return match ($kind) {
            SocialFeedKind::Home => 'Home',
            SocialFeedKind::Trending => 'Trending',
            SocialFeedKind::Local => 'Local',
            SocialFeedKind::All => 'All',
            SocialFeedKind::UserList,
            SocialFeedKind::CustomFeed,
            SocialFeedKind::Author,
            SocialFeedKind::Tag,
            SocialFeedKind::Search => throw new \InvalidArgumentException(
                sprintf('A %s feed is named by its remote id.', $kind->value),
            ),
        };
    }
}
