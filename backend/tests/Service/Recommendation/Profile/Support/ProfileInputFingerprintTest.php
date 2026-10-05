<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Profile\Support;

use App\Entity\AiProviderSettings;
use App\Entity\RecommendationHistoryCaps;
use App\Entity\SealedSecret;
use App\Entity\User;
use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Pool\Model\RecommendationHistoryModel;
use App\Service\Recommendation\Profile\Model\ProfileInputsModel;
use App\Service\Recommendation\Profile\Support\ProfileInputFingerprint;
use App\Tests\Support\AssignsEntityIds;
use PHPUnit\Framework\TestCase;

final class ProfileInputFingerprintTest extends TestCase
{
    use AssignsEntityIds;

    public function testTheSameInputsGiveTheSameFingerprint(): void
    {
        self::assertSame(
            ProfileInputFingerprint::of($this->inputs([11], [12], [13]), $this->caps(), $this->connection(5, 'm')),
            ProfileInputFingerprint::of($this->inputs([11], [12], [13]), $this->caps(), $this->connection(5, 'm')),
        );
    }

    public function testAnEntryMovingFromViewedToKeptChangesIt(): void
    {
        self::assertNotSame(
            ProfileInputFingerprint::of($this->inputs([11], [12], [13]), $this->caps(), $this->connection(5, 'm')),
            ProfileInputFingerprint::of($this->inputs([11], [12, 13], []), $this->caps(), $this->connection(5, 'm')),
        );
    }

    public function testACapChangesIt(): void
    {
        self::assertNotSame(
            ProfileInputFingerprint::of($this->inputs([11], [12], [13]), $this->caps(), $this->connection(5, 'm')),
            ProfileInputFingerprint::of(
                $this->inputs([11], [12], [13]),
                new RecommendationHistoryCaps(40, 41, 80),
                $this->connection(5, 'm'),
            ),
        );
    }

    public function testTheFavouritesCapChangesIt(): void
    {
        self::assertNotSame(
            ProfileInputFingerprint::of($this->inputs([11], [12], [13]), $this->caps(), $this->connection(5, 'm')),
            ProfileInputFingerprint::of(
                $this->inputs([11], [12], [13]),
                new RecommendationHistoryCaps(41, 40, 80),
                $this->connection(5, 'm'),
            ),
        );
    }

    public function testAnEntryRetitledByItsFeedLeavesItAlone(): void
    {
        $retitled = new ProfileInputsModel(
            new RecommendationHistoryModel(
                [new ArticleLineModel(11, 'Corrected title', 'Feed', '2026-10-01', null)],
                self::lines([12]),
                self::lines([13]),
            ),
            [],
        );

        self::assertSame(
            ProfileInputFingerprint::of($this->inputs([11], [12], [13]), $this->caps(), $this->connection(5, 'm')),
            ProfileInputFingerprint::of($retitled, $this->caps(), $this->connection(5, 'm')),
        );
    }

    public function testAnotherModelOnTheSameConnectionChangesIt(): void
    {
        self::assertNotSame(
            ProfileInputFingerprint::of($this->inputs([11], [12], [13]), $this->caps(), $this->connection(5, 'm')),
            ProfileInputFingerprint::of($this->inputs([11], [12], [13]), $this->caps(), $this->connection(5, 'm2')),
        );
    }

    public function testAnotherConnectionWithTheSameModelChangesIt(): void
    {
        self::assertNotSame(
            ProfileInputFingerprint::of($this->inputs([11], [12], [13]), $this->caps(), $this->connection(5, 'm')),
            ProfileInputFingerprint::of($this->inputs([11], [12], [13]), $this->caps(), $this->connection(6, 'm')),
        );
    }

    public function testItIsASha256(): void
    {
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/',
            ProfileInputFingerprint::of($this->inputs([], [], []), $this->caps(), $this->connection(5, 'm')),
        );
    }

    public function testASavedSearchChangesIt(): void
    {
        self::assertNotSame(
            ProfileInputFingerprint::of($this->inputs([11], [12], [13]), $this->caps(), $this->connection(5, 'm')),
            ProfileInputFingerprint::of(
                $this->inputs([11], [12], [13], ['rust']),
                $this->caps(),
                $this->connection(5, 'm'),
            ),
        );
    }

    public function testASecondSavedSearchChangesIt(): void
    {
        self::assertNotSame(
            ProfileInputFingerprint::of(
                $this->inputs([11], [12], [13], ['maps']),
                $this->caps(),
                $this->connection(5, 'm'),
            ),
            ProfileInputFingerprint::of(
                $this->inputs([11], [12], [13], ['maps', 'rust']),
                $this->caps(),
                $this->connection(5, 'm'),
            ),
        );
    }

    public function testASearchSavedAsAPhraseChangesIt(): void
    {
        self::assertNotSame(
            ProfileInputFingerprint::of(
                $this->inputs([11], [12], [13], ['home assistant']),
                $this->caps(),
                $this->connection(5, 'm'),
            ),
            ProfileInputFingerprint::of(
                $this->inputs([11], [12], [13], ['"home assistant"']),
                $this->caps(),
                $this->connection(5, 'm'),
            ),
        );
    }

    public function testTheOrderOfTheSavedSearchesLeavesItAlone(): void
    {
        self::assertSame(
            ProfileInputFingerprint::of(
                $this->inputs([11], [12], [13], ['rust', 'maps']),
                $this->caps(),
                $this->connection(5, 'm'),
            ),
            ProfileInputFingerprint::of(
                $this->inputs([11], [12], [13], ['maps', 'rust']),
                $this->caps(),
                $this->connection(5, 'm'),
            ),
        );
    }

    /**
     * @param list<int>    $favorites
     * @param list<int>    $kept
     * @param list<int>    $viewed
     * @param list<string> $savedSearchTerms
     */
    private function inputs(
        array $favorites,
        array $kept,
        array $viewed,
        array $savedSearchTerms = [],
    ): ProfileInputsModel {
        return new ProfileInputsModel(
            new RecommendationHistoryModel(self::lines($favorites), self::lines($kept), self::lines($viewed)),
            $savedSearchTerms,
        );
    }

    /**
     * @param list<int> $entryIds
     *
     * @return list<ArticleLineModel>
     */
    private static function lines(array $entryIds): array
    {
        return array_map(
            static fn (int $entryId): ArticleLineModel
                => new ArticleLineModel($entryId, 'Title ' . $entryId, 'Feed', '2026-10-01', null),
            $entryIds,
        );
    }

    private function caps(): RecommendationHistoryCaps
    {
        return new RecommendationHistoryCaps(40, 40, 80);
    }

    private function connection(int $id, string $model): AiProviderSettings
    {
        $user = new User('fingerprint@example.test', new \DateTimeImmutable('2026-10-01 06:00:00'));
        $connection = new AiProviderSettings(
            $user,
            null,
            'https://llm.example.test/v1',
            new SealedSecret('ciphertext', 'nonce', 'salt', 1),
            'ab12',
            new \DateTimeImmutable('2026-10-01 06:00:00'),
        );
        $connection->chooseModel($model, new \DateTimeImmutable('2026-10-01 06:00:00'), 32768);

        return self::withId($connection, $id);
    }
}
