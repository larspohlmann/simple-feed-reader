<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Recommendation\Stream;

use App\Entity\InterestTermRun;
use App\Entity\InterestTermSet;
use App\Entity\User;
use App\Enum\RunStatus;
use App\Repository\EntryMembershipSweepRepository;
use App\Repository\InterestTermRepository;
use App\Repository\InterestTermSetRepository;
use App\Service\Curation\Recommendation\Stream\InterestTermRefresh;
use App\Service\Curation\Recommendation\Stream\InterestTermStore;
use App\Service\Curation\Recommendation\Stream\Model\DueInterestTermsModel;
use App\Service\Curation\Recommendation\Stream\Model\InterestTermRefreshResult;
use App\Service\Curation\Recommendation\Stream\StreamCeiling;
use App\Tests\DbTestCase;
use App\Tests\Support\RequiresServices;
use App\Tests\Support\SeedsProfiledAccounts;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\StubChatClient;
use Psr\Clock\ClockInterface;

final class InterestTermRefreshStoreFailureTest extends DbTestCase
{
    use RequiresServices;
    use SeedsProfiledAccounts;
    use SeedsUsers;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->profiledAccount('terms-store-failure@example.test');
        $failingClock = new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                throw new \RuntimeException('store unavailable');
            }
        };
        self::getContainer()->set(InterestTermStore::class, new InterestTermStore(
            $this->entityManager,
            $this->requireFromContainer(InterestTermSetRepository::class),
            $this->requireFromContainer(InterestTermRepository::class),
            new StreamCeiling($this->requireFromContainer(EntryMembershipSweepRepository::class), $failingClock),
            $failingClock,
        ));
    }

    public function testAStoreFailureIsAStrikeAndTheThirdFailsTheRun(): void
    {
        $chat = $this->requireFromContainer(StubChatClient::class);
        foreach (range(1, InterestTermRun::MAX_TRANSPORT_FAILURES) as $ignored) {
            $chat->queueContent('{"terms":[{"language":"en","term":"solar","weight":8}]}');
        }
        $refresh = $this->requireFromContainer(InterestTermRefresh::class);

        $results = [];
        foreach (range(1, InterestTermRun::MAX_TRANSPORT_FAILURES) as $ignored) {
            $results[] = $refresh->refresh($this->due());
        }

        self::assertSame(
            [
                InterestTermRefreshResult::Retrying,
                InterestTermRefreshResult::Retrying,
                InterestTermRefreshResult::Failed,
            ],
            $results,
        );
        $manager = self::getContainer()->get('doctrine')->getManager();
        $run = $manager->getRepository(InterestTermRun::class)->findOneBy(['user' => $this->owner->requireId()]);
        self::assertInstanceOf(InterestTermRun::class, $run);
        self::assertSame(RunStatus::Failed, $run->getStatus());
        self::assertNull($manager->find(InterestTermSet::class, $this->owner->requireId()));
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
