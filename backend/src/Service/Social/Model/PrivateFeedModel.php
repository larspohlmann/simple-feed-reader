<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Enum\SocialFeedKind;

final readonly class PrivateFeedModel
{
    private const string HOME_NAME = 'Home';

    private function __construct(
        public SocialFeedKind $kind,
        /** '' for Home */
        public string $remoteId,
        public string $name,
    ) {
    }

    public static function home(): self
    {
        return new self(SocialFeedKind::Home, '', self::HOME_NAME);
    }

    public static function userList(string $listId, string $name): self
    {
        return new self(SocialFeedKind::UserList, $listId, $name);
    }

    public static function customFeed(string $feedUri, string $name): self
    {
        return new self(SocialFeedKind::CustomFeed, $feedUri, $name);
    }

    public static function author(string $did, string $name): self
    {
        return new self(SocialFeedKind::Author, $did, $name);
    }

    public static function of(SocialFeedKind $kind, string $remoteId, string $name): self
    {
        return match ($kind) {
            SocialFeedKind::Home => self::home(),
            SocialFeedKind::UserList => self::userList($remoteId, $name),
            SocialFeedKind::CustomFeed => self::customFeed($remoteId, $name),
            SocialFeedKind::Author => self::author($remoteId, $name),
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
}
