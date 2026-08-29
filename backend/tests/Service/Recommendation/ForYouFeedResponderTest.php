<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationRun;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\ForYouFeedQuery;
use App\Repository\RecommendationItemRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\ForYouFeedResponder;
use App\Service\Recommendation\RecommendationFeedPager;
use App\Service\Recommendation\RecommendationSettingsResolver;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;

final class ForYouFeedResponderTest extends DbTestCase
{
    private User $user;
    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->em, $cipher);

        $this->user = new User('for-you-responder@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($this->user);

        $feed = new Feed('https://example.com/feed.xml');
        $this->em->persist($feed);
        $this->em->persist(new Subscription($this->user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));

        $run = new RecommendationRun($this->user, new \DateTimeImmutable('2026-08-07T09:00:00Z'));
        $run->snapshot([[1]]);
        $run->complete(new \DateTimeImmutable('2026-08-07T09:05:00Z'));
        $this->em->persist($run);

        $createdAt = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $entry = new Entry($feed, 'g1', null, 'Title g1', $createdAt, $createdAt);
        $this->em->persist($entry);
        $this->em->persist(new RecommendationItem($run, $entry, 1, 'reason g1', 88));
        $this->em->flush();
    }

    public function testOmitsBothAnnotationsWhenShowReasonsIsOff(): void
    {
        $first = $this->firstEntry();

        self::assertArrayNotHasKey('recommendationReason', $first);
        self::assertArrayNotHasKey('recommendationScore', $first);
    }

    public function testShowsTheReasonAndItsScoreWhenShowReasonsIsOn(): void
    {
        $this->fixtures->showReasonsEnabledSettings($this->user);

        $first = $this->firstEntry();

        self::assertSame('reason g1', $first['recommendationReason']);
        self::assertSame(88, $first['recommendationScore']);
    }

    /** Debug keeps the per-run call logs and nothing else — it is not a second
     *  way to reveal what the reader asked to keep hidden (#576). */
    public function testDebugAloneRevealsNeitherAnnotation(): void
    {
        $this->fixtures->debugEnabledSettings($this->user);

        $first = $this->firstEntry();

        self::assertArrayNotHasKey('recommendationReason', $first);
        self::assertArrayNotHasKey('recommendationScore', $first);
    }

    /** Debug does not take anything away either: with reasons on, the pair is
     *  shown whether or not the reader is also collecting call logs. */
    public function testDebugDoesNotChangeWhatShowReasonsReveals(): void
    {
        $this->fixtures->showReasonsAndDebugEnabledSettings($this->user);

        $first = $this->firstEntry();

        self::assertSame('reason g1', $first['recommendationReason']);
        self::assertSame(88, $first['recommendationScore']);
    }

    /** `assertIsArray()` narrows to a plain array, not to a keyed shape, so
     *  this says what the assertion actually proves.
     *
     *  @return array<mixed>
     */
    private function firstEntry(): array
    {
        $page = $this->responder()->page(new ForYouFeedQuery($this->user, null, 50));
        self::assertIsArray($page['entries']);
        $first = $page['entries'][0];
        self::assertIsArray($first);

        return $first;
    }

    private function responder(): ForYouFeedResponder
    {
        $repository = $this->em->getRepository(RecommendationItem::class);
        self::assertInstanceOf(RecommendationItemRepository::class, $repository);

        $settings = self::getContainer()->get(RecommendationSettingsResolver::class);
        self::assertInstanceOf(RecommendationSettingsResolver::class, $settings);

        return new ForYouFeedResponder(new RecommendationFeedPager($repository), $settings);
    }
}
