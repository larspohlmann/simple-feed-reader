<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\LinkCardContent;
use App\Entity\SocialAccount;
use App\Entity\User;
use App\Repository\SocialAccountRepository;
use App\Service\Social\ActingAccountChooser;
use App\Service\Social\ActingAccountGuard;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Model\ComposerDefaultsModel;
use App\Service\Social\Model\PostPreviewModel;
use App\Service\Social\Model\ResponseKind;
use App\Service\Social\Model\ResponseContextModel;
use App\Service\Social\Model\ResponseTargetModel;
use App\Service\Social\PostComposer;
use App\Service\Social\ResponseTargetFinder;
use App\Service\Social\SocialAccountForUser;
use App\Service\Social\SocialPostFinder;
use App\Tests\DbTestCase;
use App\Tests\Support\FakeComposerDefaults;
use App\Tests\Support\FakeMastodonNetwork;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class PostComposerTest extends DbTestCase
{
    use SeedsUsers;

    private const string STATUS_URL = 'https://mastodon.social/@Gargron/110';
    private const string REPLY_URI = 'https://hachyderm.io/users/ann/statuses/200';

    private FakeMastodonNetwork $mastodon;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mastodon = new FakeMastodonNetwork();
        $this->owner = $this->user('reply-composer@example.test');
    }

    public function testTheComposerAnswersAsTheActingAccountAmongTheConnectedOnes(): void
    {
        $first = SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->owner));
        $default = SocialFeeds::persistedAccount(
            $this->entityManager,
            SocialAccounts::mastodonAs($this->owner, 'bea@mastodon.example'),
        );
        $default->becomeDefault();
        $broken = SocialFeeds::persistedAccount(
            $this->entityManager,
            SocialAccounts::mastodonAs($this->owner, 'cid@mastodon.example'),
        );
        $broken->markNeedsReconnect();
        $this->entityManager->flush();
        $this->mastodon->knowsTheResponseContext(new ResponseContextModel(canReply: false));

        $context = $this->composer()->contextFor(
            $this->owner,
            new ResponseTargetModel($this->post()),
            ResponseKind::Reply,
        );

        self::assertSame([$default, $first], $context->accounts);
        self::assertSame($default, $context->account);
        self::assertFalse($context->canSend);
        self::assertEquals(new ComposerDefaultsModel(500, 'public', ['110', '110']), $context->defaults);
        self::assertSame(['resolve ' . self::STATUS_URL], $this->mastodon->calls);
    }

    public function testTheComposerShowsThePostItAnswersWithTheLinkCardItCarries(): void
    {
        SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->owner));
        $preview = new PostPreviewModel(
            'Eugen',
            '@Gargron@mastodon.social',
            'Hello',
            new LinkCardContent('https://example.org/story', 'The story', null, null, 'Example'),
        );
        $this->mastodon->knowsTheResponseContext(new ResponseContextModel(preview: $preview));

        $context = $this->composer()->contextFor(
            $this->owner,
            new ResponseTargetModel($this->post()),
            ResponseKind::Quote,
        );

        self::assertSame($preview, $context->target);
    }

    public function testAReplyInTheThreadAlsoLooksUpTheEntrysPostAsItsStarter(): void
    {
        SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->owner));

        $this->composer()->contextFor(
            $this->owner,
            new ResponseTargetModel($this->post(), self::REPLY_URI),
            ResponseKind::Reply,
        );

        self::assertSame(['resolve ' . self::REPLY_URI, 'resolve ' . self::STATUS_URL], $this->mastodon->calls);
    }

    public function testAThreadStarterTheAccountCannotFindLeavesItsAuthorOut(): void
    {
        SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->owner));
        $calls = &$this->mastodon->calls;
        $this->mastodon->onResolve(static function () use (&$calls): void {
            if (\count($calls) > 1) {
                throw new SocialPostNotFoundException('The entry\'s post is gone.');
            }
        });

        $context = $this->composer()->contextFor(
            $this->owner,
            new ResponseTargetModel($this->post(), self::REPLY_URI),
            ResponseKind::Reply,
        );

        self::assertSame(['110', 'no thread starter'], $context->defaults->mentions);
    }

    public function testAQuoteMaySendOnlyWhereTheAuthorAllowsQuotes(): void
    {
        SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->owner));
        $this->mastodon->knowsTheResponseContext(new ResponseContextModel(canQuote: false));
        $target = new ResponseTargetModel($this->post());

        self::assertFalse($this->composer()->contextFor($this->owner, $target, ResponseKind::Quote)->canSend);
        self::assertTrue($this->composer()->contextFor($this->owner, $target, ResponseKind::Reply)->canSend);
    }

    public function testAQuoteOfAReplyStartsWithNoMentionsAndNeverLooksUpTheThreadStarter(): void
    {
        SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->owner));

        $context = $this->composer()->contextFor(
            $this->owner,
            new ResponseTargetModel($this->post(), self::REPLY_URI),
            ResponseKind::Quote,
        );

        self::assertSame([], $context->defaults->mentions);
        self::assertSame(['resolve ' . self::REPLY_URI], $this->mastodon->calls);
    }

    private function post(): Entry
    {
        $feed = new Feed('https://mastodon.social/@Gargron.rss');
        $this->entityManager->persist($feed);
        $now = new \DateTimeImmutable('2026-11-01 10:00:00');
        $entry = new Entry($feed, 'tag:mastodon.social,2026:110', self::STATUS_URL, 'Post', $now, $now);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function composer(): PostComposer
    {
        /** @var SocialAccountRepository $accounts */
        $accounts = $this->entityManager->getRepository(SocialAccount::class);
        $mastodon = $this->mastodon;
        $network = new ServiceLocator(['mastodon' => static fn (): FakeMastodonNetwork => $mastodon]);
        $chooser = new ActingAccountChooser(SocialFeeds::repository($this->entityManager), $accounts);
        $posts = new SocialPostFinder($chooser, new ActingAccountGuard($this->entityManager), $network);

        return new PostComposer(
            new ResponseTargetFinder($posts, $chooser, new SocialAccountForUser($accounts)),
            $posts,
            $accounts,
            new ServiceLocator(['mastodon' => static fn (): FakeComposerDefaults => new FakeComposerDefaults()]),
        );
    }
}
