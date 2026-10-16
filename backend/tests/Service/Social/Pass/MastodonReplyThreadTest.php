<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Pass;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Pass\MastodonReplyThread;
use PHPUnit\Framework\TestCase;

final class MastodonReplyThreadTest extends TestCase
{
    private const string AUTHOR = 'https://mastodon.social/@Gargron';
    private const string POST_ID = '110';

    public function testTheRepliesToThePostAreTopLevelAndTheirAnswersNest(): void
    {
        $thread = new MastodonReplyThread(self::POST_ID, self::AUTHOR, [
            self::statusOf('1', self::POST_ID, 'public'),
            self::statusOf('2', '1', 'unlisted'),
            self::statusOf('3', self::POST_ID, 'public'),
            self::statusOf('4', null, 'public'),
        ]);

        self::assertSame(['1', '3'], self::idsOf($thread->topLevel()));
        self::assertSame(['2'], self::idsOf($thread->answersTo($thread->topLevel()[0])));
        self::assertSame([], $thread->answersTo($thread->topLevel()[1]));
    }

    public function testAStatusWhoseParentTheInstanceLeftOutIsDroppedWithItsAnswers(): void
    {
        $thread = new MastodonReplyThread(self::POST_ID, self::AUTHOR, [
            self::statusOf('1', self::POST_ID, 'public'),
            self::statusOf('2', 'muted', 'public'),
            self::statusOf('3', '2', 'public'),
        ]);

        self::assertSame(['1'], self::idsOf($thread->topLevel()));
        self::assertSame([], $thread->answersTo($thread->topLevel()[0]));
    }

    public function testThePostListedAmongItsOwnRepliesAnswersNothing(): void
    {
        $thread = new MastodonReplyThread(self::POST_ID, self::AUTHOR, [
            self::statusOf('1', self::POST_ID, 'public'),
            self::statusOf(self::POST_ID, '1', 'public'),
        ]);

        self::assertSame([], $thread->answersTo($thread->topLevel()[0]));
    }

    public function testAHiddenStatusAndItsAnswersAreNeverTopLevel(): void
    {
        $thread = new MastodonReplyThread(self::POST_ID, self::AUTHOR, [
            self::statusOf('1', self::POST_ID, 'private'),
            self::statusOf('2', '1', 'public'),
            self::statusOf('3', self::POST_ID, 'direct'),
        ]);

        self::assertSame([], $thread->topLevel());
    }

    public function testARepeatedIdOrAMissingOneIsReadOnceOrNotAtAll(): void
    {
        $thread = new MastodonReplyThread(self::POST_ID, self::AUTHOR, [
            self::statusOf('1', self::POST_ID, 'public'),
            self::statusOf('1', '1', 'public'),
            JsonNodeModel::of(['visibility' => 'public', 'in_reply_to_id' => self::POST_ID]),
        ]);

        self::assertSame(['1'], self::idsOf($thread->topLevel()));
        self::assertSame([], $thread->answersTo($thread->topLevel()[0]));
        self::assertSame([], $thread->answersTo(JsonNodeModel::of([])));
    }

    public function testThePostsAuthorIsKnownByProfileOrActorAddress(): void
    {
        $thread = new MastodonReplyThread(self::POST_ID, self::AUTHOR, []);
        $unaddressable = new MastodonReplyThread(self::POST_ID, null, []);

        self::assertTrue($thread->isByPostAuthor(JsonNodeModel::of(['url' => self::AUTHOR])));
        self::assertTrue($thread->isByPostAuthor(JsonNodeModel::of(['uri' => self::AUTHOR])));
        self::assertFalse($thread->isByPostAuthor(JsonNodeModel::of(['url' => 'https://mastodon.social/@other'])));
        self::assertFalse($unaddressable->isByPostAuthor(JsonNodeModel::of([])));
    }

    private static function statusOf(string $id, ?string $inReplyToId, string $visibility): JsonNodeModel
    {
        return JsonNodeModel::of(['id' => $id, 'in_reply_to_id' => $inReplyToId, 'visibility' => $visibility]);
    }

    /**
     * @param list<JsonNodeModel> $statuses
     *
     * @return list<?string>
     */
    private static function idsOf(array $statuses): array
    {
        return array_map(static fn (JsonNodeModel $status): ?string => $status->string('id'), $statuses);
    }
}
