<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\TimelineReader;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Service\Completion\Model\KnownSharesModel;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Social\Model\TimelinePageModel;
use App\Service\Social\TimelineReader\NetworkTimelineReader;
use App\Service\Social\TimelineSource\TimelineSourceInterface;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class NetworkTimelineReaderTest extends TestCase
{
    public function testTheAccountsNetworkPicksTheSource(): void
    {
        $page = TimelinePageModel::of([], KnownSharesModel::none(), '7');
        $mastodon = new class ($page) implements TimelineSourceInterface {
            public function __construct(private readonly TimelinePageModel $page)
            {
            }

            public static function network(): string
            {
                return SocialNetwork::Mastodon->value;
            }

            public function page(SocialFeed $socialFeed): TimelinePageModel
            {
                return $this->page;
            }

            public function request(SocialFeed $socialFeed): ApiRequestModel
            {
                throw new \LogicException('Not asked by the blocking reader.');
            }

            public function pageOf(SocialFeed $socialFeed, FetchOutcomeModel $outcome): TimelinePageModel
            {
                throw new \LogicException('Not asked by the blocking reader.');
            }

            public function dropSession(SocialAccount $account): void
            {
            }
        };
        $reader = new NetworkTimelineReader(new ServiceLocator([
            SocialNetwork::Mastodon->value => static fn (): TimelineSourceInterface => $mastodon,
        ]));

        $socialFeed = new SocialFeed(
            SocialAccounts::mastodon(),
            new Feed('social:mastodon:4:home'),
            SocialFeedKind::Home,
            '',
        );

        self::assertSame($page, $reader->page($socialFeed));
    }
}
