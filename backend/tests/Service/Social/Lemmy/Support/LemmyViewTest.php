<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Lemmy\Support;

use App\Enum\VoteScore;
use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Social\Lemmy\Support\LemmyView;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Tests\Support\ReadsFixtures;
use PHPUnit\Framework\TestCase;

final class LemmyViewTest extends TestCase
{
    use ReadsFixtures;

    private const string FIXTURES = 'lemmy/';

    public function testAVoteOfOneIsUp(): void
    {
        $view = $this->post(['my_vote' => 1]);

        self::assertSame(VoteScore::Up, LemmyView::ofPost($view)->myVote);
    }

    public function testNoVoteIsNone(): void
    {
        self::assertSame(VoteScore::None, LemmyView::ofPost($this->post([]))->myVote);
    }

    public function testALockedPostIsLocked(): void
    {
        $view = $this->post(['post' => ['id' => 3, 'name' => 'T', 'locked' => true]]);

        self::assertTrue(LemmyView::ofPost($view)->isLocked);
    }

    public function testThePostReadsItsAuthorAndPreview(): void
    {
        $target = LemmyView::ofPost($this->post([]));

        self::assertSame(3, $target->postId);
        self::assertNull($target->commentId);
        self::assertSame(12, $target->score);
        self::assertSame('ada@origin.example', $target->authorIdentity);
        self::assertSame('Ada', $target->preview->authorName);
        self::assertSame('@ada@origin.example', $target->preview->authorHandle);
        self::assertSame('Lemmy.world', $target->preview->excerpt);
    }

    public function testAnAuthorHostIsLowerCased(): void
    {
        $view = $this->post(['creator' => ['name' => 'Ada', 'actor_id' => 'https://Origin.Example/u/Ada']]);

        self::assertSame('ada@origin.example', LemmyView::ofPost($view)->authorIdentity);
    }

    public function testAPostWithoutAnIdIsNotFound(): void
    {
        $this->expectException(SocialPostNotFoundException::class);

        LemmyView::ofPost(JsonNodeModel::of(['post' => ['name' => 'T']]));
    }

    public function testACommentReadsItsPostItsVoteAndItsText(): void
    {
        $view = JsonNodeModel::of($this->decodeFixture('resolve-comment.json'))->node('comment');

        $target = LemmyView::ofComment($view);

        self::assertSame(77, $target->commentId);
        self::assertSame(990, $target->postId);
        self::assertSame(VoteScore::Down, $target->myVote);
        self::assertSame(3, $target->score);
        self::assertSame('A reply', $target->preview->excerpt);
    }

    public function testTheExcerptIsCutToTwoHundredCharacters(): void
    {
        $view = JsonNodeModel::of([
            'comment' => ['id' => 1, 'post_id' => 2, 'content' => str_repeat('ä', 300)],
        ]);

        self::assertSame(str_repeat('ä', 200), LemmyView::ofComment($view)->preview->excerpt);
    }

    public function testACommentWithoutAnIdIsNotFound(): void
    {
        $this->expectException(SocialPostNotFoundException::class);

        LemmyView::ofComment(JsonNodeModel::of(['comment' => ['post_id' => 2]]));
    }

    public function testACommentWithoutItsPostIsNotFound(): void
    {
        $this->expectException(SocialPostNotFoundException::class);

        LemmyView::ofComment(JsonNodeModel::of(['comment' => ['id' => 2]]));
    }

    /** @param array<string, mixed> $overrides */
    private function post(array $overrides): JsonNodeModel
    {
        $view = $this->decodeFixture('post-anonymous.json')['post_view'] ?? [];

        return JsonNodeModel::of(array_replace(\is_array($view) ? $view : [], $overrides));
    }

    /** @return array<array-key, mixed> */
    private function decodeFixture(string $name): array
    {
        $decoded = json_decode($this->fixture(self::FIXTURES . $name), true, flags: \JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? $decoded : [];
    }
}
