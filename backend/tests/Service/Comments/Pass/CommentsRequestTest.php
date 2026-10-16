<?php

declare(strict_types=1);

namespace App\Tests\Service\Comments\Pass;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\User;
use App\Service\Comments\CommentsSource\CommentsSourceInterface;
use App\Service\Comments\Model\CommentsAnswerModel;
use App\Service\Comments\Model\CommentsAudienceModel;
use App\Service\Comments\Model\CommentsPageModel;
use App\Service\Comments\Pass\CommentsRequest;
use App\Tests\Support\FakeCommentsSource;
use PHPUnit\Framework\TestCase;

final class CommentsRequestTest extends TestCase
{
    public function testTwoSourcesWithTheSameAudienceKeepApartInTheCache(): void
    {
        $now = new \DateTimeImmutable('2026-10-16 12:00:00');
        $user = new User('reader@example.com', $now);
        $entry = new Entry(new Feed('https://blog.example.org/feed/'), 'post-7', null, 'Hello', $now, $now);
        $sameAudience = new class implements CommentsSourceInterface {
            public function serves(Entry $entry): bool
            {
                return true;
            }

            public function audienceOf(User $user, Entry $entry): CommentsAudienceModel
            {
                return new CommentsAudienceModel('fake:' . $entry->getGuid());
            }

            public function fetch(CommentsRequest $request): CommentsAnswerModel
            {
                return CommentsAnswerModel::settled('');
            }

            public function read(Entry $entry, string $answer): CommentsPageModel
            {
                return new CommentsPageModel([]);
            }
        };

        $fakeKey = (new CommentsRequest(new FakeCommentsSource('answer'), $user, $entry))->cacheKey;
        $otherKey = (new CommentsRequest($sameAudience, $user, $entry))->cacheKey;

        self::assertNotSame($fakeKey, $otherKey);
        self::assertStringEndsWith(' fake:post-7', $fakeKey);
    }
}
