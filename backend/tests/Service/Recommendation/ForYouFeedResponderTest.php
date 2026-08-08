<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationSettings;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\RecommendationItemRepository;
use App\Service\Recommendation\EffectiveRecommendationSettings;
use App\Service\Recommendation\ForYouFeedResponder;
use App\Service\Recommendation\RecommendationFeedPager;
use App\Service\Recommendation\RecommendationSettingsResolver;
use App\Service\Recommendation\RecommendationSettingsValues;
use App\Tests\DbTestCase;

final class ForYouFeedResponderTest extends DbTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('for-you-responder@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($this->user);

        $feed = new Feed('https://example.com/feed.xml');
        $this->em->persist($feed);
        $this->em->persist(new Subscription($this->user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));

        $run = new RecommendationRun($this->user, new \DateTimeImmutable('2026-08-07T09:00:00Z'));
        $run->snapshot([[1]]);
        $run->complete(new \DateTimeImmutable('2026-08-07T09:05:00Z'));
        $this->em->persist($run);

        $entry = new Entry($feed, 'g1', null, 'Title g1', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($entry);
        $this->em->persist(new RecommendationItem($run, $entry, 1, 'reason g1', 88));
        $this->em->flush();
    }

    public function testOmitsTheRecommendationScoreWhenDebugIsOff(): void
    {
        $page = $this->responder()->page($this->user, null, 50);
        self::assertIsArray($page['entries']);
        $first = $page['entries'][0];
        self::assertIsArray($first);

        self::assertArrayNotHasKey('recommendationScore', $first);
    }

    public function testIncludesTheRecommendationScoreWhenDebugIsOn(): void
    {
        $this->seedDebugSettings(true);

        $page = $this->responder()->page($this->user, null, 50);
        self::assertIsArray($page['entries']);
        $first = $page['entries'][0];
        self::assertIsArray($first);

        self::assertSame(88, $first['recommendationScore']);
    }

    private function seedDebugSettings(bool $enabled): void
    {
        $settings = new RecommendationSettings($this->user);
        $settings->update(new RecommendationSettingsValues(
            guidancePrompt: null,
            favoritesCap: EffectiveRecommendationSettings::DEFAULT_FAVORITES_CAP,
            keptCap: EffectiveRecommendationSettings::DEFAULT_KEPT_CAP,
            viewedCap: EffectiveRecommendationSettings::DEFAULT_VIEWED_CAP,
            candidatePoolSize: EffectiveRecommendationSettings::DEFAULT_CANDIDATE_POOL_SIZE,
            picksLimit: EffectiveRecommendationSettings::DEFAULT_PICKS_LIMIT,
            contextWindow: null,
            debugEnabled: $enabled,
        ));
        $this->em->persist($settings);
        $this->em->flush();
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
