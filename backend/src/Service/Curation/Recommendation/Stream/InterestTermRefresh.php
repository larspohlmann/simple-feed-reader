<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Stream;

use App\Entity\InterestTermRun;
use App\Enum\FeedLanguage;
use App\Repository\ForYouStreamSourceRepository;
use App\Service\Curation\Ai\Exception\AiKeyUnreadableException;
use App\Service\Curation\Ai\Exception\CredentialsRejectedException;
use App\Service\Curation\Ai\Exception\ProviderRateLimitedException;
use App\Service\Curation\Ai\Exception\ProviderRejectedRequestException;
use App\Service\Curation\Ai\Exception\ProviderUnreachableException;
use App\Service\Curation\Ai\Exception\RetryableProviderException;
use App\Service\Curation\Recommendation\Run\RecommendationRunAdvancer;
use App\Service\Curation\Recommendation\Run\Support\ProviderFailedMessage;
use App\Service\Curation\Recommendation\Run\TickLockTtl;
use App\Service\Curation\Recommendation\Run\UserTickLock;
use App\Service\Curation\Recommendation\Stream\InterestTermExtractor\InterestTermExtractorInterface;
use App\Service\Curation\Recommendation\Stream\Model\DueInterestTermsModel;
use App\Service\Curation\Recommendation\Stream\Model\InterestTermOutcomeModel;
use App\Service\Curation\Recommendation\Stream\Model\InterestTermRefreshResult;
use App\Service\Curation\Recommendation\Stream\Pass\InterestTermPass;
use App\Service\Foundation\Clock\NaiveUtcClock;
use Doctrine\ORM\EntityManagerInterface;

/** One account's term extraction under the tick lock its recommendation and profile runs take. */
final readonly class InterestTermRefresh
{
    private const string STORE_FAILED = 'The interest terms could not be stored.';

    public function __construct(
        private UserTickLock $tickLock,
        private TickLockTtl $lockTtl,
        private InterestTermRuns $runs,
        private ForYouStreamSourceRepository $sources,
        private InterestTermExtractorInterface $extractor,
        private InterestTermStore $store,
        private InterestTermRarity $rarity,
        private EntityManagerInterface $entityManager,
        private NaiveUtcClock $clock,
    ) {
    }

    public function refresh(DueInterestTermsModel $due): InterestTermRefreshResult
    {
        $held = $this->tickLock->acquire($due->user, $this->lockTtl->computeSecondsForConnection($due->connection));
        if (null === $held) {
            return InterestTermRefreshResult::Skipped;
        }

        try {
            return $this->extractAndSettle($due, $this->runs->openFor($due));
        } finally {
            $held->release();
        }
    }

    private function extractAndSettle(DueInterestTermsModel $due, InterestTermRun $run): InterestTermRefreshResult
    {
        if ($run->hasExhaustedTransportRetries()) {
            return $this->fail($run, self::STORE_FAILED);
        }

        $pass = new InterestTermPass($due->user, $due->connection, $run, $due->profileText, $this->readLanguages($due));

        try {
            return $this->settle($due, $run, $this->extractor->extractTerms($pass));
        } catch (ProviderRateLimitedException) {
            return InterestTermRefreshResult::Skipped;
        } catch (ProviderRejectedRequestException $exception) {
            return $this->failForProvider($due, $run, $exception->getMessage());
        } catch (
            ProviderUnreachableException | CredentialsRejectedException | RetryableProviderException $exception
        ) {
            return $this->recordStrike($due, $run, $exception->getMessage());
        } catch (AiKeyUnreadableException) {
            return $this->fail($run, RecommendationRunAdvancer::KEY_UNREADABLE);
        }
    }

    private function settle(
        DueInterestTermsModel $due,
        InterestTermRun $run,
        InterestTermOutcomeModel $outcome,
    ): InterestTermRefreshResult {
        if (null !== $outcome->unusableReply) {
            return $this->recordInvalidReply($run, $outcome->unusableReply);
        }

        $run->recordTransportFailure();
        $this->entityManager->flush();

        $set = $this->store->replaceTerms($due->user, $due->profileGeneratedAt, $outcome->stems);
        $run->complete($this->clock->now());
        $this->entityManager->flush();
        $this->rarity->refreshRarities($set);

        return InterestTermRefreshResult::Stored;
    }

    private function recordInvalidReply(InterestTermRun $run, string $reply): InterestTermRefreshResult
    {
        $run->recordInvalidReply($reply);
        if (!$run->hasExhaustedAttempts()) {
            $this->entityManager->flush();

            return InterestTermRefreshResult::Retrying;
        }

        return $this->fail(
            $run,
            \sprintf('The model gave no usable terms in %d attempts.', InterestTermRun::MAX_ATTEMPTS),
        );
    }

    private function recordStrike(
        DueInterestTermsModel $due,
        InterestTermRun $run,
        string $detail,
    ): InterestTermRefreshResult {
        $run->recordTransportFailure();
        if (!$run->hasExhaustedTransportRetries()) {
            $this->entityManager->flush();

            return InterestTermRefreshResult::Retrying;
        }

        return $this->failForProvider($due, $run, $detail);
    }

    private function failForProvider(
        DueInterestTermsModel $due,
        InterestTermRun $run,
        string $detail,
    ): InterestTermRefreshResult {
        return $this->fail($run, ProviderFailedMessage::formatMessage($due->connection->getBaseUrl(), $detail));
    }

    private function fail(InterestTermRun $run, string $message): InterestTermRefreshResult
    {
        $run->fail($message, $this->clock->now());
        $this->entityManager->flush();

        return InterestTermRefreshResult::Failed;
    }

    /** @return list<FeedLanguage> all four when the For you feeds declare none */
    private function readLanguages(DueInterestTermsModel $due): array
    {
        $declared = $this->sources->findFeedLanguages($due->user->requireId());

        return [] === $declared ? FeedLanguage::cases() : $declared;
    }
}
