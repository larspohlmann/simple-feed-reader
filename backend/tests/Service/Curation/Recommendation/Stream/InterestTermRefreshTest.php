<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Recommendation\Stream;

use App\Entity\Feed;
use App\Entity\FeedTraits;
use App\Entity\InclusionChange;
use App\Entity\InterestTermRun;
use App\Entity\InterestTermSet;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\FeedLanguage;
use App\Enum\RunStatus;
use App\Repository\InterestTermRunRepository;
use App\Service\Curation\Ai\Exception\AiKeyUnreadableException;
use App\Service\Curation\Ai\Exception\CredentialsRejectedException;
use App\Service\Curation\Ai\Exception\ProviderRateLimitedException;
use App\Service\Curation\Ai\Exception\ProviderRejectedRequestException;
use App\Service\Curation\Ai\Exception\ProviderUnreachableException;
use App\Service\Curation\Recommendation\Run\RecommendationRunAdvancer;
use App\Service\Curation\Recommendation\Run\Support\ProviderFailedMessage;
use App\Service\Curation\Recommendation\Run\UserTickLock;
use App\Service\Curation\Recommendation\Stream\InterestTermRefresh;
use App\Service\Curation\Recommendation\Stream\Model\DueInterestTermsModel;
use App\Service\Curation\Recommendation\Stream\Model\InterestTermRefreshResult;
use App\Tests\DbTestCase;
use App\Tests\Support\SeedsProfiledAccounts;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\StubChatClient;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Lock\LockFactory;

final class InterestTermRefreshTest extends DbTestCase
{
    use SeedsProfiledAccounts;
    use SeedsUsers;

    private const string USABLE_REPLY = '{"terms":[{"language":"en","term":"solar","weight":8}]}';

    private User $owner;
    private InterestTermRefresh $refresh;
    private StubChatClient $chat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->profiledAccount('terms-refresh@example.test');
        $refresh = self::getContainer()->get(InterestTermRefresh::class);
        self::assertInstanceOf(InterestTermRefresh::class, $refresh);
        $this->refresh = $refresh;
        $chat = self::getContainer()->get(StubChatClient::class);
        self::assertInstanceOf(StubChatClient::class, $chat);
        $this->chat = $chat;
    }

    public function testAUsableReplyStoresTheTermsAndCompletesTheRun(): void
    {
        $this->chat->queueContent(self::USABLE_REPLY);

        self::assertSame(InterestTermRefreshResult::Stored, $this->refresh->refresh($this->due()));

        self::assertSame(RunStatus::Completed, $this->latestRun()->getStatus());
        self::assertEquals($this->profileGeneratedAt(), $this->termSet()?->getProfileGeneratedAt());
    }

    public function testATermWhoseStemsAllDropIsAnUnusableReplyThatRetriesAndStoresNothing(): void
    {
        $this->chat->queueContent('{"terms":[{"language":"en","term":"the","weight":8}]}');

        self::assertSame(InterestTermRefreshResult::Retrying, $this->refresh->refresh($this->due()));

        self::assertSame(RunStatus::Running, $this->latestRun()->getStatus());
        self::assertSame(1, $this->latestRun()->getAttempts());
        self::assertNull($this->termSet());
        self::assertSame(
            '{"terms":[{"language":"en","term":"the","weight":8}]}',
            $this->latestRun()->getLastInvalidReply(),
        );
    }

    public function testThreeUnusableRepliesFailTheRunAndKeepThePreviousTerms(): void
    {
        $previous = new \DateTimeImmutable('2026-10-01 06:00:00');
        $this->entityManager->persist(new InterestTermSet($this->owner, $previous, $previous, 0));
        $this->entityManager->flush();
        foreach (range(1, InterestTermRun::MAX_ATTEMPTS) as $ignored) {
            $this->chat->queueContent('not json');
        }

        $results = [];
        foreach (range(1, InterestTermRun::MAX_ATTEMPTS) as $ignored) {
            $results[] = $this->refresh->refresh($this->due());
        }

        self::assertSame(
            [
                InterestTermRefreshResult::Retrying,
                InterestTermRefreshResult::Retrying,
                InterestTermRefreshResult::Failed,
            ],
            $results,
        );
        $run = $this->latestRun();
        self::assertSame(RunStatus::Failed, $run->getStatus());
        self::assertSame('The model gave no usable terms in 3 attempts.', $run->getError());
        self::assertEquals($previous, $this->termSet()?->getProfileGeneratedAt());
    }

    public function testATransportFailureIsAStrikeAndTheThirdFailsTheRun(): void
    {
        foreach (range(1, InterestTermRun::MAX_TRANSPORT_FAILURES) as $ignored) {
            $this->chat->queueFailure(new ProviderUnreachableException('gone'));
        }

        $results = [];
        foreach (range(1, InterestTermRun::MAX_TRANSPORT_FAILURES) as $ignored) {
            $results[] = $this->refresh->refresh($this->due());
        }

        self::assertSame(
            [
                InterestTermRefreshResult::Retrying,
                InterestTermRefreshResult::Retrying,
                InterestTermRefreshResult::Failed,
            ],
            $results,
        );
        $run = $this->latestRun();
        self::assertSame(RunStatus::Failed, $run->getStatus());
        self::assertSame(
            ProviderFailedMessage::formatMessage($this->requireConnection($this->owner)->getBaseUrl(), 'gone'),
            $run->getError(),
        );
        self::assertNull($this->termSet());
    }

    public function testARefusedKeyIsAStrikeAndTheThirdFailsTheRun(): void
    {
        $this->assertThreeStrikesFailTheRun(CredentialsRejectedException::refusedKey());
    }

    public function testAnUnreadableKeyFailsTheRunWithTheKeyMessage(): void
    {
        $this->chat->queueFailure(new AiKeyUnreadableException());

        self::assertSame(InterestTermRefreshResult::Failed, $this->refresh->refresh($this->due()));

        $run = $this->latestRun();
        self::assertSame(RunStatus::Failed, $run->getStatus());
        self::assertSame(RecommendationRunAdvancer::KEY_UNREADABLE, $run->getError());
    }

    public function testARateLimitSkipsWithoutAStrike(): void
    {
        $this->chat->queueFailure(new ProviderRateLimitedException(30.0));

        self::assertSame(InterestTermRefreshResult::Skipped, $this->refresh->refresh($this->due()));

        $run = $this->latestRun();
        self::assertSame(RunStatus::Running, $run->getStatus());
        self::assertSame(0, $run->getTransportFailures());
        self::assertSame(0, $run->getAttempts());
    }

    public function testARejectedRequestFailsTheRun(): void
    {
        $this->chat->queueFailure(new ProviderRejectedRequestException(Response::HTTP_BAD_REQUEST, 'bad request'));

        self::assertSame(InterestTermRefreshResult::Failed, $this->refresh->refresh($this->due()));

        $run = $this->latestRun();
        self::assertSame(RunStatus::Failed, $run->getStatus());
        self::assertSame(
            ProviderFailedMessage::formatMessage($this->requireConnection($this->owner)->getBaseUrl(), 'bad request'),
            $run->getError(),
        );
    }

    public function testAHeldTickLockSkipsWithoutACall(): void
    {
        $lockFactory = self::getContainer()->get(LockFactory::class);
        self::assertInstanceOf(LockFactory::class, $lockFactory);
        $holder = $lockFactory->createLock(UserTickLock::buildName($this->owner), 60.0);
        self::assertTrue($holder->acquire());

        try {
            self::assertSame(InterestTermRefreshResult::Skipped, $this->refresh->refresh($this->due()));
        } finally {
            $holder->release();
        }

        self::assertSame([], $this->chat->calls());
        self::assertNull($this->runs()->findLatestForUser($this->owner));
    }

    public function testAReaderWithNoFeedLanguageIsAskedForAllFour(): void
    {
        $this->chat->queueContent(self::USABLE_REPLY);

        $this->refresh->refresh($this->due());

        self::assertStringStartsWith(
            'LANGUAGES: de (German), en (English), fr (French), es (Spanish)',
            $this->userMessageOfCall(0),
        );
    }

    public function testTheLanguagesAreThoseOfTheForYouFeeds(): void
    {
        $this->subscribe(FeedLanguage::German, true);
        $this->subscribe(FeedLanguage::French, false);
        $this->chat->queueContent(self::USABLE_REPLY);

        $this->refresh->refresh($this->due());

        self::assertStringStartsWith('LANGUAGES: de (German)' . "\n", $this->userMessageOfCall(0));
    }

    public function testANewProfileFailsTheOldRunAndOpensAFreshOne(): void
    {
        $olderProfile = new \DateTimeImmutable('2026-10-01 06:00:00');
        $oldRun = new InterestTermRun($this->owner, $olderProfile, $olderProfile, 'api.example.test', 'qwen3-14b');
        $oldRun->recordInvalidReply('{"nonsense":true}');
        $this->entityManager->persist($oldRun);
        $this->entityManager->flush();
        $this->chat->queueContent(self::USABLE_REPLY);

        $this->refresh->refresh($this->due());

        self::assertSame(RunStatus::Failed, $oldRun->getStatus());
        self::assertSame('A newer profile replaced this run before it finished.', $oldRun->getError());
        self::assertSame(1, $oldRun->getAttempts());
        $run = $this->latestRun();
        self::assertNotSame($oldRun->getId(), $run->getId());
        self::assertSame(0, $run->getAttempts());
        $messages = $this->chat->calls()[0]['messages'];
        self::assertSame('user', $messages[\count($messages) - 1]['role']);
    }

    private function assertThreeStrikesFailTheRun(\RuntimeException $failure): void
    {
        foreach (range(1, InterestTermRun::MAX_TRANSPORT_FAILURES) as $ignored) {
            $this->chat->queueFailure($failure);
        }

        $results = [];
        foreach (range(1, InterestTermRun::MAX_TRANSPORT_FAILURES) as $ignored) {
            $results[] = $this->refresh->refresh($this->due());
        }

        self::assertSame(
            [
                InterestTermRefreshResult::Retrying,
                InterestTermRefreshResult::Retrying,
                InterestTermRefreshResult::Failed,
            ],
            $results,
        );
        self::assertSame(
            ProviderFailedMessage::formatMessage(
                $this->requireConnection($this->owner)->getBaseUrl(),
                $failure->getMessage(),
            ),
            $this->latestRun()->getError(),
        );
    }

    private function due(): DueInterestTermsModel
    {
        return new DueInterestTermsModel(
            $this->owner,
            $this->requireConnection($this->owner),
            'Likes solar power.',
            $this->profileGeneratedAt(),
        );
    }

    private function subscribe(FeedLanguage $language, bool $inForYou): void
    {
        $feed = new Feed('https://terms.example.test/' . $language->value . '.xml');
        $feed->recordTraits(new FeedTraits(null, $language));
        $this->entityManager->persist($feed);
        $subscription = new Subscription($this->owner, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $subscription->changeInclusions(new InclusionChange(forYou: $inForYou));
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();
    }

    private function userMessageOfCall(int $call): string
    {
        $messages = $this->chat->calls()[$call]['messages'];

        return $messages[1]['content'];
    }

    private function latestRun(): InterestTermRun
    {
        $run = $this->runs()->findLatestForUser($this->owner);
        self::assertInstanceOf(InterestTermRun::class, $run);

        return $run;
    }

    private function termSet(): ?InterestTermSet
    {
        $ownerId = $this->owner->requireId();
        $this->entityManager->clear();

        return $this->entityManager->find(InterestTermSet::class, $ownerId);
    }

    private function runs(): InterestTermRunRepository
    {
        $runs = self::getContainer()->get(InterestTermRunRepository::class);
        self::assertInstanceOf(InterestTermRunRepository::class, $runs);

        return $runs;
    }
}
