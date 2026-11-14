<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Scoring\Factory;

use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Scoring\Factory\RerankQueryFactory;
use App\Service\Recommendation\Scoring\Model\ScoringReaderModel;
use PHPUnit\Framework\TestCase;

final class RerankQueryFactoryTest extends TestCase
{
    public function testTheQueryIsTheQuestionAndTheProfile(): void
    {
        self::assertSame(
            RerankQueryFactory::QUESTION . "\nProfile: Likes Rust.",
            (new RerankQueryFactory())->create(new ScoringReaderModel('Likes Rust.', null, []), 1_000),
        );
    }

    /** Favourites stay out: the query is question, guidance and profile. */
    public function testTheGuidanceStandsBeforeTheProfile(): void
    {
        $reader = new ScoringReaderModel('Likes Rust.', 'More self-hosting.', [
            new ArticleLineModel(3, 'Liked', 'Feed', '2026-10-01', null),
        ]);

        self::assertSame(
            RerankQueryFactory::QUESTION . "\nGuidance: More self-hosting.\nProfile: Likes Rust.",
            (new RerankQueryFactory())->create($reader, 1_000),
        );
    }

    /** 100 tokens are 399 bytes: 94 for the question, 10 for the label, 295 of the profile. */
    public function testALongProfileIsCutToTheBudget(): void
    {
        $query = (new RerankQueryFactory())->create(new ScoringReaderModel(str_repeat('a', 10_000), null, []), 100);

        self::assertSame(RerankQueryFactory::QUESTION . "\nProfile: " . str_repeat('a', 295), $query);
    }

    /** The guidance is fitted beside an empty profile: 94 + 11 + 284 + 10 = 399 bytes, the label still in. */
    public function testALongGuidanceLeavesRoomForTheProfilesLabel(): void
    {
        $query = (new RerankQueryFactory())->create(
            new ScoringReaderModel('Likes Rust.', str_repeat('g', 10_000), []),
            100,
        );

        self::assertSame(
            RerankQueryFactory::QUESTION . "\nGuidance: " . str_repeat('g', 284) . "\nProfile: ",
            $query,
        );
    }

    /** The query is JSON-encoded with JSON_THROW_ON_ERROR: a feed's invalid byte must not reach it. */
    public function testAnInvalidByteNeverReachesTheQuery(): void
    {
        $query = (new RerankQueryFactory())->create(new ScoringReaderModel("Likes \xC3 Rust.", "Mehr \xC3", []), 1_000);

        self::assertTrue(mb_check_encoding($query, 'UTF-8'));
    }
}
