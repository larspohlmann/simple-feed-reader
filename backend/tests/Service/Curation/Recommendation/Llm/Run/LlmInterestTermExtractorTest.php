<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Recommendation\Llm\Run;

use App\Entity\AiProviderSettings;
use App\Entity\InterestTermRun;
use App\Entity\RecommendationRunLog;
use App\Entity\User;
use App\Enum\CallPhase;
use App\Enum\CallVerdict;
use App\Enum\FeedLanguage;
use App\Service\Curation\Ai\Crypto\ApiKeyCipher;
use App\Service\Curation\Ai\Exception\ProviderUnreachableException;
use App\Service\Curation\Recommendation\Llm\Prompt\Support\RecommendationPromptText;
use App\Service\Curation\Recommendation\Llm\Run\LlmInterestTermExtractor;
use App\Service\Curation\Recommendation\Stream\Model\TermStemModel;
use App\Service\Curation\Recommendation\Stream\Pass\InterestTermPass;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\StubChatClient;

final class LlmInterestTermExtractorTest extends DbTestCase
{
    use SeedsUsers;

    private User $owner;
    private AiProviderSettings $connection;
    private InterestTermRun $run;
    private LlmInterestTermExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
        $this->owner = $this->user('interest-terms@example.test');
        $this->connection = $fixtures->seedReadyAiSettingsFor($this->owner, 'qwen3-14b');
        $this->run = new InterestTermRun(
            $this->owner,
            new \DateTimeImmutable('2026-11-27T08:00:00Z'),
            new \DateTimeImmutable('2026-11-27T09:00:00Z'),
            'api.example.test',
            'qwen3-14b',
        );
        $this->entityManager->persist($this->run);
        $this->entityManager->flush();
        /** @var LlmInterestTermExtractor $extractor */
        $extractor = self::getContainer()->get(LlmInterestTermExtractor::class);
        $this->extractor = $extractor;
    }

    public function testAUsableReplyIsRecordedAsAUsableCallAndReturnsTheTerms(): void
    {
        $this->chat()->queueContent('{"terms":[{"language":"en","term":"solar","weight":9}]}');

        $outcome = $this->extractor->extractTerms($this->pass([FeedLanguage::English]));

        self::assertNull($outcome->unusableReply);
        self::assertSame(
            ['solar'],
            array_values(array_unique(array_map(
                static fn (TermStemModel $stem): string => substr($stem->stem, 0, 5),
                $outcome->stems,
            ))),
        );
        self::assertEqualsWithDelta(0.9, $outcome->stems[0]->weight, 1e-9);
        $log = $this->onlyLogRow();
        self::assertSame(CallPhase::InterestTerms, $log->getPhase());
        self::assertSame(CallVerdict::Usable, $log->getVerdict());
        self::assertSame('interest_terms', $this->chat()->calls()[0]['responseSchemaName']);
    }

    public function testAnUnusableReplyIsRecordedUnusableAndReturnedForTheRetry(): void
    {
        $this->chat()->queueContent('not json at all');

        $outcome = $this->extractor->extractTerms($this->pass([FeedLanguage::English]));

        self::assertSame('not json at all', $outcome->unusableReply);
        self::assertSame(CallVerdict::Unusable, $this->onlyLogRow()->getVerdict());
    }

    public function testATermWhoseStemsAllDropIsRecordedUnusableWithTheRealReply(): void
    {
        $reply = '{"terms":[{"language":"en","term":"the","weight":8}]}';
        $this->chat()->queueContent($reply);

        $outcome = $this->extractor->extractTerms($this->pass([FeedLanguage::English]));

        self::assertSame($reply, $outcome->unusableReply);
        self::assertSame(CallVerdict::Unusable, $this->onlyLogRow()->getVerdict());
    }

    public function testTheRetryCarriesTheLastInvalidReplyAndTheCorrective(): void
    {
        $this->run->recordInvalidReply('{"nonsense":true}');
        $this->chat()->queueContent('{"terms":[{"language":"en","term":"solar","weight":5}]}');

        $this->extractor->extractTerms($this->pass([FeedLanguage::English]));

        $messages = $this->chat()->calls()[0]['messages'];
        $count = \count($messages);
        self::assertSame('assistant', $messages[$count - 2]['role']);
        self::assertSame('{"nonsense":true}', $messages[$count - 2]['content']);
        self::assertSame(RecommendationPromptText::INTEREST_TERMS_CORRECTIVE, $messages[$count - 1]['content']);
    }

    public function testATransportFailureSettlesTheLogRowAndPropagates(): void
    {
        $this->chat()->queueFailure(new ProviderUnreachableException('gone'));

        try {
            $this->extractor->extractTerms($this->pass([FeedLanguage::English]));
            self::fail('The transport failure should propagate.');
        } catch (ProviderUnreachableException) {
            self::assertSame(CallVerdict::TransportFailed, $this->onlyLogRow()->getVerdict());
        }
    }

    /** @param list<FeedLanguage> $languages */
    private function pass(array $languages): InterestTermPass
    {
        return new InterestTermPass($this->owner, $this->connection, $this->run, 'Likes solar power.', $languages);
    }

    private function onlyLogRow(): RecommendationRunLog
    {
        $this->entityManager->clear();
        $rows = $this->entityManager->getRepository(RecommendationRunLog::class)->findAll();
        self::assertCount(1, $rows);

        return $rows[0];
    }

    private function chat(): StubChatClient
    {
        /** @var StubChatClient $chat */
        $chat = self::getContainer()->get(StubChatClient::class);

        return $chat;
    }
}
