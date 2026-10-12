<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\TimelineReader;

use App\Entity\Feed;
use App\Entity\SocialFeed;
use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Service\Completion\Model\KnownSharesModel;
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
