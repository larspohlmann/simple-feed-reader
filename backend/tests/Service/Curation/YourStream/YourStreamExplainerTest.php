<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\YourStream;

use App\Entity\Feed;
use App\Entity\User;
use App\Entity\YourStreamEntry;
use App\Entity\YourStreamWord;
use App\Enum\FeedLanguage;
use App\Enum\YourStreamPrecision;
use App\Repository\SavedSearchRepository;
use App\Service\Curation\YourStream\Model\YourStreamExplanationModel;
use App\Service\Curation\YourStream\YourStreamExplainer;
use App\Tests\DbTestCase;
use App\Tests\Support\RequiresServices;
use App\Tests\Support\YourStreamExplainFixtures;

final class YourStreamExplainerTest extends DbTestCase
{
    use RequiresServices;

    private const string TODAY = YourStreamExplainFixtures::TODAY_AT;
    private const string BACKGROUND = YourStreamExplainFixtures::BACKGROUND_AT;

    private YourStreamExplainFixtures $fixtures;
    private User $user;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures = new YourStreamExplainFixtures($this->entityManager);
        $this->user = $this->fixtures->reader('explain@example.com');
        $this->feed = $this->fixtures->subscribedFeed(
            $this->user,
            'https://de.example.com/feed.xml',
            FeedLanguage::German,
        );
        $this->fixtures->favourite($this->user, $this->feed, 'Bundesliga heute');
        $this->fixtures->favourite($this->user, $this->feed, 'Bundesliga morgen');
        $this->fixtures->entries($this->feed, 600, 'Wetterbericht', self::BACKGROUND);
    }

    public function testTheExplanationCountsMatchesPerPrecisionWithoutWritingAnything(): void
    {
        $this->fixtures->entries($this->feed, 3, 'Bundesliga am Samstag', self::TODAY);
        $this->fixtures->entryWithSummary($this->feed, 'Sport', 'Die Bundesliga spielt', self::TODAY);
        $this->fixtures->entries($this->feed, 2, 'Wetterbericht', self::TODAY);

        $explanation = $this->explain(1);

        self::assertSame(
            ['narrowest' => 3, 'narrow' => 3, 'balanced' => 4, 'wide' => 4, 'widest' => 4],
            $explanation->matchesByVariantAndPrecision[YourStreamExplanationModel::WEIGHT_TIMES_RARITY],
        );
        self::assertSame(
            4.0,
            $explanation->matchesPerDay(YourStreamExplanationModel::WEIGHT_TIMES_RARITY, YourStreamPrecision::Wide),
        );
        self::assertSame(['Bundesliga'], array_column($explanation->words, 'word'));
        self::assertSame(6, $explanation->entryCount);
        self::assertSame(0, $this->entityManager->getRepository(YourStreamWord::class)->count([]));
        self::assertSame(0, $this->entityManager->getRepository(YourStreamEntry::class)->count([]));
    }

    public function testFlatRarityScoresEveryWordAtItsLanguagesMeanRarity(): void
    {
        $this->fixtures->favourite($this->user, $this->feed, 'Olympia heute');
        $this->fixtures->favourite($this->user, $this->feed, 'Olympia morgen');
        $this->fixtures->entries($this->feed, 1, 'Bundesliga', self::TODAY);
        $this->fixtures->entries($this->feed, 60, 'Olympia', self::TODAY);

        $matches = $this->explain(1)->matchesByVariantAndPrecision;

        self::assertSame(1, $matches[YourStreamExplanationModel::WEIGHT_TIMES_RARITY]['widest']);
        self::assertSame(61, $matches[YourStreamExplanationModel::FLAT_RARITY]['wide']);
        self::assertSame(1, $matches[YourStreamExplanationModel::FLAT_RARITY]['narrowest']);
    }

    public function testMatchesPerDaySpreadTheMatchesOverTheDays(): void
    {
        $this->fixtures->entries($this->feed, 3, 'Bundesliga am Samstag', self::TODAY);

        $explanation = $this->explain(2);

        self::assertSame(
            1.5,
            $explanation->matchesPerDay(YourStreamExplanationModel::FLAT_RARITY, YourStreamPrecision::Narrowest),
        );
    }

    private function explain(int $days): YourStreamExplanationModel
    {
        return $this->explainer()->buildExplanation($this->user->requireId(), $days);
    }

    private function explainer(): YourStreamExplainer
    {
        return $this->fixtures->explainer($this->requireFromContainer(SavedSearchRepository::class));
    }
}
