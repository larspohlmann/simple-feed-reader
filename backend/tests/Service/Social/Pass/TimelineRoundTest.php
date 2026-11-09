<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Pass;

use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Enum\SocialAccountStatus;
use App\Enum\SocialFeedKind;
use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Social\Exception\PrivateFeedOrphanedException;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Model\BlueskySessionModel;
use App\Service\Social\Model\TimelineOutcome\AccountPausedTimelineModel;
use App\Service\Social\Model\TimelineOutcome\FailedTimelineModel;
use App\Service\Social\Model\TimelineOutcome\FetchedTimelineModel;
use App\Service\Social\Model\TimelineSlotsModel;
use App\Service\Social\Pass\TimelineRound;
use App\Tests\Support\CountingArrayAdapter;
use App\Tests\Support\PrivateFeeds;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Response;

final class TimelineRoundTest extends TestCase
{
    private const string SESSION = 'POST https://pds.example.test/xrpc/com.atproto.server.createSession';
    private const int HOME = 1;
    private const int LIST = 2;
    private const int SEARCH = 3;
    private const string SEARCH_URL = 'https://mastodon.example/api/v2/search?type=statuses&resolve=false&limit=40&q=';

    private CountingArrayAdapter $cache;

    private RoutedHttpClient $http;

    private SocialAccount $account;

    protected function setUp(): void
    {
        $this->cache = new CountingArrayAdapter();
        $this->http = new RoutedHttpClient([self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-2')]);
        $this->account = SocialApiWiring::blueskyAccount();
        SocialApiWiring::blueskySessionCache($this->cache)
            ->remember($this->account, new BlueskySessionModel('stale-jwt', 'alice.bsky.social'));
    }

    public function testAFeedWithoutItsSocialFeedSettlesAsOrphanedWithoutATicket(): void
    {
        $round = self::orphanRound();

        self::assertSame([], iterator_to_array($round->ticketFor(999)));
        $unasked = iterator_to_array($round->drainUnasked());

        self::assertSame([999], array_keys($unasked));
        self::assertInstanceOf(FailedTimelineModel::class, $unasked[999]);
        self::assertInstanceOf(PrivateFeedOrphanedException::class, $unasked[999]->failure);
        self::assertSame([], iterator_to_array($round->drainUnasked()));
    }

    public function testTheGateCannotAskAboutAFeedWithoutItsSocialFeed(): void
    {
        $this->expectException(PrivateFeedOrphanedException::class);

        self::orphanRound()->admits(999);
    }

    public function testAStaleAnswerRenewsTheSessionOnceAndFollowsUpOnTheFreshOne(): void
    {
        $round = $this->ticketedRound();

        $home = $round->settle(self::HOME, self::stale());
        $list = $round->settle(self::LIST, self::stale());

        self::assertSame('Bearer jwt-2', $home->requestTicket()->request->headers['Authorization']);
        self::assertSame('Bearer jwt-2', $list->requestTicket()->request->headers['Authorization']);
        self::assertSame(
            2,
            $this->cache->deletedItems,
            'one dropSession, plus the forget inside the one session open',
        );
        self::assertSame([self::SESSION], $this->http->requestLines(), 'and opened once');
    }

    public function testAFeedsSecondStaleAnswerRefusesItsAccountAndTheGateWithholdsItsOtherFeeds(): void
    {
        $round = $this->ticketedRound();
        $round->settle(self::HOME, self::stale());

        $refused = $round->settle(self::HOME, self::stale())->settledOutcome();

        self::assertInstanceOf(FailedTimelineModel::class, $refused);
        self::assertInstanceOf(SocialCredentialsRejectedException::class, $refused->failure);
        self::assertSame(SocialAccountStatus::NeedsReconnect, $this->account->getStatus());
        self::assertFalse($round->admits(self::LIST));
        self::assertInstanceOf(
            AccountPausedTimelineModel::class,
            $round->settle(self::LIST, FetchOutcomeModel::withheld())->settledOutcome(),
        );
    }

    public function testAFeedsFirstStaleAnswerOnTheRenewedSessionRefusesItsAccountAtOnce(): void
    {
        $round = $this->round();
        iterator_to_array($round->ticketFor(self::HOME));
        $round->settle(self::HOME, self::stale());
        iterator_to_array($round->ticketFor(self::LIST));

        $refused = $round->settle(self::LIST, self::stale())->settledOutcome();

        self::assertInstanceOf(FailedTimelineModel::class, $refused);
        self::assertInstanceOf(SocialCredentialsRejectedException::class, $refused->failure);
        self::assertSame(SocialAccountStatus::NeedsReconnect, $this->account->getStatus());
        self::assertSame([self::SESSION], $this->http->requestLines(), 'opened once, never renewed again');
    }

    public function testATicketBuiltBeforeItsAccountsRenewalIsWithheldAndBuiltAgain(): void
    {
        $round = $this->ticketedRound();
        self::assertTrue($round->admits(self::LIST));
        $round->settle(self::HOME, self::stale());

        self::assertFalse($round->admits(self::LIST), 'its ticket carries the dropped session');
        $rebuilt = $round->settle(self::LIST, FetchOutcomeModel::withheld());

        self::assertSame('Bearer jwt-2', $rebuilt->requestTicket()->request->headers['Authorization']);
        self::assertTrue($round->admits(self::LIST));
    }

    public function testATicketBuiltAfterItsAccountsRenewalIsAdmitted(): void
    {
        $round = $this->round();
        iterator_to_array($round->ticketFor(self::HOME));
        $round->settle(self::HOME, self::stale());

        iterator_to_array($round->ticketFor(self::LIST));

        self::assertTrue($round->admits(self::LIST));
    }

    private function round(): TimelineRound
    {
        return SocialApiWiring::timelineRounds($this->http->client(), $this->cache)->start([
            self::HOME => $this->socialFeed(self::HOME, SocialFeedKind::Home, ''),
            self::LIST => $this->socialFeed(
                self::LIST,
                SocialFeedKind::UserList,
                'at://did:plc:bob/app.bsky.graph.list/3l1',
            ),
        ]);
    }

    private function ticketedRound(): TimelineRound
    {
        $round = $this->round();
        iterator_to_array($round->ticketFor(self::HOME));
        iterator_to_array($round->ticketFor(self::LIST));

        return $round;
    }

    private function socialFeed(int $feedId, SocialFeedKind $kind, string $remoteId): SocialFeed
    {
        return PrivateFeeds::bluesky($this->account, $feedId, $kind, $remoteId);
    }

    private static function stale(): FetchOutcomeModel
    {
        return FetchOutcomeModel::answered(
            new ApiResponseModel(Response::HTTP_BAD_REQUEST, '{"error":"ExpiredToken"}'),
        );
    }

    private static function orphanRound(): TimelineRound
    {
        $sources = SocialApiWiring::timelineSources(new MockHttpClient(), new ArrayAdapter());

        return new TimelineRound($sources, new TimelineSlotsModel(3, 4), []);
    }

    public function testAMastodonSearchAsksForItsAlternativesOneAfterAnotherAndSettlesOnTheMergedPage(): void
    {
        $search = self::rssOrAtomSearch();
        $round = SocialApiWiring::timelineRounds($this->http->client(), $this->cache)->start([self::SEARCH => $search]);

        $tickets = iterator_to_array($round->ticketFor(self::SEARCH));
        self::assertSame(self::SEARCH_URL . 'rss', $tickets[self::SEARCH]->request->url);

        $followUp = $round->settle(self::SEARCH, self::mastodonSearchAnswer('111', 'rss'));
        self::assertSame(self::SEARCH_URL . 'atom', $followUp->requestTicket()->request->url);

        $fetched = $round->settle(self::SEARCH, self::mastodonSearchAnswer('112', 'atom'))->settledOutcome();
        self::assertInstanceOf(FetchedTimelineModel::class, $fetched);
        self::assertCount(2, $fetched->page->parsed->entries);
        self::assertSame('112', $search->getNewestSeenId());
    }

    public function testARefusedFirstAlternativeSettlesTheSearchWithoutAskingTheNext(): void
    {
        $search = self::rssOrAtomSearch();
        $round = SocialApiWiring::timelineRounds($this->http->client(), $this->cache)->start([self::SEARCH => $search]);
        iterator_to_array($round->ticketFor(self::SEARCH));

        $refused = $round->settle(
            self::SEARCH,
            FetchOutcomeModel::answered(new ApiResponseModel(Response::HTTP_UNAUTHORIZED, '{}')),
        );

        self::assertFalse($refused->isRequest());
        self::assertInstanceOf(FailedTimelineModel::class, $refused->settledOutcome());
    }

    private static function rssOrAtomSearch(): SocialFeed
    {
        return PrivateFeeds::mastodon(
            PrivateFeeds::mastodonAccount(),
            self::SEARCH,
            SocialFeedKind::Search,
            '(rss || atom)',
        );
    }

    private static function mastodonSearchAnswer(string $id, string $word): FetchOutcomeModel
    {
        return FetchOutcomeModel::answered(new ApiResponseModel(Response::HTTP_OK, json_encode(['statuses' => [[
            'id' => $id,
            'uri' => 'https://mastodon.example/users/bob/statuses/' . $id,
            'url' => 'https://mastodon.example/@bob/' . $id,
            'created_at' => '2026-11-09T10:00:00Z',
            'content' => '<p>' . $word . '</p>',
            'in_reply_to_id' => null,
            'account' => ['acct' => 'bob', 'display_name' => 'Bob', 'url' => 'https://mastodon.example/@bob'],
        ]]], \JSON_THROW_ON_ERROR)));
    }
}
