<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Notification\Support;

use App\Enum\SocialNotificationKind;
use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Social\Notification\Model\ContextReferenceModel;
use App\Service\Social\Notification\Support\BlueskyReplyReferences;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BlueskyReplyReferencesTest extends TestCase
{
    private const string PARENT = 'at://did:plc:alice/app.bsky.feed.post/3lmine';
    private const string ROOT = 'at://did:plc:zoe/app.bsky.feed.post/3lroot';
    private const string QUOTED = 'at://did:plc:alice/app.bsky.feed.post/3lquoted';

    /** @param array<string, mixed> $record */
    #[DataProvider('records')]
    public function testReadsWhatTheRecordAnswers(
        SocialNotificationKind $kind,
        array $record,
        ContextReferenceModel $expected,
    ): void {
        self::assertEquals($expected, BlueskyReplyReferences::of(JsonNodeModel::of($record), $kind));
    }

    /** @return iterable<string, array{SocialNotificationKind, array<string, mixed>, ContextReferenceModel}> */
    public static function records(): iterable
    {
        yield 'a reply deeper in a thread' => [
            SocialNotificationKind::Reply,
            ['reply' => ['parent' => ['uri' => self::PARENT], 'root' => ['uri' => self::ROOT]]],
            new ContextReferenceModel(self::PARENT, self::ROOT),
        ];
        yield 'a reply straight to the thread start' => [
            SocialNotificationKind::Reply,
            ['reply' => ['parent' => ['uri' => self::PARENT], 'root' => ['uri' => self::PARENT]]],
            new ContextReferenceModel(self::PARENT),
        ];
        yield 'a record quote' => [
            SocialNotificationKind::Quote,
            ['embed' => ['$type' => 'app.bsky.embed.record', 'record' => ['uri' => self::QUOTED]]],
            new ContextReferenceModel(self::QUOTED),
        ];
        yield 'a record with media quote' => [
            SocialNotificationKind::Quote,
            ['embed' => [
                '$type' => 'app.bsky.embed.recordWithMedia',
                'record' => ['record' => ['uri' => self::QUOTED]],
            ]],
            new ContextReferenceModel(self::QUOTED),
        ];
        yield 'a like' => [
            SocialNotificationKind::Like,
            ['reply' => ['parent' => ['uri' => self::PARENT]]],
            new ContextReferenceModel(),
        ];
    }
}
