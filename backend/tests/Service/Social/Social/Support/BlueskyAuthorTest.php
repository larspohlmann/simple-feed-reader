<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Support\BlueskyAuthor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BlueskyAuthorTest extends TestCase
{
    /** @return iterable<string, array{array<string, string>, ?string}> */
    public static function names(): iterable
    {
        yield 'a display name' => [['displayName' => 'Ann', 'handle' => 'ann.bsky.social'], 'Ann'];
        yield 'an empty display name' => [['displayName' => '', 'handle' => 'ann.bsky.social'], 'ann.bsky.social'];
        yield 'no display name' => [['handle' => 'ann.bsky.social'], 'ann.bsky.social'];
        yield 'neither' => [['did' => 'did:plc:ann'], null];
    }

    /** @param array<string, string> $actor */
    #[DataProvider('names')]
    public function testTheNameFallsBackToTheHandle(array $actor, ?string $name): void
    {
        self::assertSame($name, BlueskyAuthor::name(JsonNodeModel::of($actor)));
    }

    /** @return iterable<string, array{array<string, string>, ?string}> */
    public static function profiles(): iterable
    {
        yield 'a handle' => [
            ['handle' => 'ann.bsky.social', 'did' => 'did:plc:ann'],
            'https://bsky.app/profile/ann.bsky.social',
        ];
        yield 'only a did' => [['did' => 'did:plc:ann'], 'https://bsky.app/profile/did:plc:ann'];
        yield 'neither' => [[], null];
    }

    /** @param array<string, string> $actor */
    #[DataProvider('profiles')]
    public function testTheProfileIsFoundByHandleThenDid(array $actor, ?string $profile): void
    {
        self::assertSame($profile, BlueskyAuthor::profile(JsonNodeModel::of($actor)));
    }

    public function testOnlyAnAbsoluteAvatarIsKept(): void
    {
        $avatar = 'https://cdn.bsky.app/img/avatar/plain/did:plc:ann/a@jpeg';

        self::assertSame($avatar, BlueskyAuthor::avatar(JsonNodeModel::of(['avatar' => $avatar])));
        self::assertNull(BlueskyAuthor::avatar(JsonNodeModel::of(['avatar' => '/avatar.jpg'])));
    }
}
