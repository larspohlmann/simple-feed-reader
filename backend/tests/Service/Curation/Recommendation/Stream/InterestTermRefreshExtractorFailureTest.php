<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Recommendation\Stream;

use App\Entity\InterestTermRun;
use App\Entity\User;
use App\Enum\RunStatus;
use App\Service\Curation\Ai\Exception\RetryableProviderException;
use App\Service\Curation\Recommendation\Run\Support\ProviderFailedMessage;
use App\Service\Curation\Recommendation\Stream\InterestTermExtractor\InterestTermExtractorInterface;
use App\Service\Curation\Recommendation\Stream\InterestTermRefresh;
use App\Service\Curation\Recommendation\Stream\Model\DueInterestTermsModel;
use App\Service\Curation\Recommendation\Stream\Model\InterestTermOutcomeModel;
use App\Service\Curation\Recommendation\Stream\Model\InterestTermRefreshResult;
use App\Service\Curation\Recommendation\Stream\Pass\InterestTermPass;
use App\Tests\DbTestCase;
use App\Tests\Support\SeedsProfiledAccounts;
use App\Tests\Support\SeedsUsers;
use Symfony\Component\HttpFoundation\Response;

final class InterestTermRefreshExtractorFailureTest extends DbTestCase
{
    use SeedsProfiledAccounts;
    use SeedsUsers;

    private User $owner;
    private InterestTermRefresh $refresh;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->profiledAccount('terms-extractor-failure@example.test');
        self::getContainer()->set(
            InterestTermExtractorInterface::class,
            new class implements InterestTermExtractorInterface {
                public function extractTerms(InterestTermPass $pass): InterestTermOutcomeModel
                {
                    throw new RetryableProviderException(Response::HTTP_BAD_GATEWAY);
                }
            },
        );
        $refresh = self::getContainer()->get(InterestTermRefresh::class);
        self::assertInstanceOf(InterestTermRefresh::class, $refresh);
        $this->refresh = $refresh;
    }

    public function testARetryableStatusThatReachesTheRefreshIsAStrikeAndTheThirdFailsTheRun(): void
    {
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
        $run = $this->entityManager->getRepository(InterestTermRun::class)->findOneBy(['user' => $this->owner]);
        self::assertInstanceOf(InterestTermRun::class, $run);
        self::assertSame(RunStatus::Failed, $run->getStatus());
        self::assertSame(
            ProviderFailedMessage::formatMessage(
                $this->requireConnection($this->owner)->getBaseUrl(),
                'That provider answered with status 502.',
            ),
            $run->getError(),
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
}
