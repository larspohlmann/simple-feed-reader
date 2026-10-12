<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\SocialCandidates;

use App\Entity\SocialAccount;
use App\Service\Social\CandidateSource\CandidateSourceInterface;
use App\Service\Social\Model\NetworkCandidatesModel;
use App\Service\Social\SocialCandidates\NetworkSocialCandidates;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class NetworkSocialCandidatesTest extends TestCase
{
    private function sourceAnswering(NetworkCandidatesModel $answer): CandidateSourceInterface
    {
        return new class ($answer) implements CandidateSourceInterface {
            public function __construct(private readonly NetworkCandidatesModel $answer)
            {
            }

            public static function network(): string
            {
                return 'stub';
            }

            public function candidates(SocialAccount $account): NetworkCandidatesModel
            {
                return $this->answer;
            }
        };
    }

    public function testEachAccountReachesItsOwnNetworksSource(): void
    {
        $bluesky = new NetworkCandidatesModel([], [], [], false);
        $mastodon = new NetworkCandidatesModel([], [], [], true);
        $candidates = new NetworkSocialCandidates(new ServiceLocator([
            'bluesky' => fn () => $this->sourceAnswering($bluesky),
            'mastodon' => fn () => $this->sourceAnswering($mastodon),
        ]));

        self::assertSame($bluesky, $candidates->candidates(SocialAccounts::bluesky()));
        self::assertSame($mastodon, $candidates->candidates(SocialAccounts::mastodon()));
    }
}
