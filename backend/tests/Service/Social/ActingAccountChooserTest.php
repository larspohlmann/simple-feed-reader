<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SealedSecret;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Entity\User;
use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Repository\SocialAccountRepository;
use App\Service\Social\ActingAccountChooser;
use App\Service\Social\Exception\NoSocialAccountForNetworkException;
use App\Tests\DbTestCase;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\SocialFeeds;

final class ActingAccountChooserTest extends DbTestCase
{
    use SeedsUsers;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->user('acting-account@example.test');
    }

    public function testAProfileFeedPostIsActedOnByTheDefaultAccount(): void
    {
        $this->account($this->owner, 'did:plc:older', '2026-10-01 10:00:00');
        $default = $this->account($this->owner, 'did:plc:default', '2026-10-05 10:00:00');
        $default->becomeDefault();
        $this->entityManager->flush();

        self::assertSame($default, $this->chooser()->accountFor(
            $this->owner,
            $this->entryOn(new Feed('https://bsky.app/profile/did:plc:bob/rss')),
            SocialNetwork::Bluesky,
        ));
    }

    public function testAPrivateFeedPostIsActedOnByItsOwnAccountEvenBesideADefault(): void
    {
        $default = $this->account($this->owner, 'did:plc:default', '2026-10-01 10:00:00');
        $default->becomeDefault();
        $own = $this->account($this->owner, 'did:plc:own', '2026-10-05 10:00:00');

        self::assertSame($own, $this->chooser()->accountFor(
            $this->owner,
            $this->entryOn($this->privateFeedOf($own)),
            SocialNetwork::Bluesky,
        ));
    }

    public function testAnotherUsersPrivateFeedHasNoAccountToActWith(): void
    {
        $this->account($this->owner, 'did:plc:mine', '2026-10-01 10:00:00');
        $stranger = $this->user('acting-stranger@example.test');
        $theirs = $this->account($stranger, 'did:plc:theirs', '2026-10-01 10:00:00');

        $this->expectException(NoSocialAccountForNetworkException::class);

        $this->chooser()->accountFor(
            $this->owner,
            $this->entryOn($this->privateFeedOf($theirs)),
            SocialNetwork::Bluesky,
        );
    }

    public function testAPrivateFeedWithoutItsSocialFeedRowHasNoAccountToActWith(): void
    {
        $this->account($this->owner, 'did:plc:mine', '2026-10-01 10:00:00');
        $orphan = new Feed('social:bluesky:1:home');
        $orphan->setSourceFormat(SourceFormat::BLUESKY_API);

        $this->expectException(NoSocialAccountForNetworkException::class);

        $this->chooser()->accountFor($this->owner, $this->entryOn($orphan), SocialNetwork::Bluesky);
    }

    public function testNoAccountOnTheNetworkIsRefused(): void
    {
        $this->account($this->owner, 'did:plc:mine', '2026-10-01 10:00:00');

        $this->expectException(NoSocialAccountForNetworkException::class);
        $this->expectExceptionMessage('No Mastodon account');

        $this->chooser()->accountFor(
            $this->owner,
            $this->entryOn(new Feed('https://mastodon.social/@Gargron.rss')),
            SocialNetwork::Mastodon,
        );
    }

    private function account(User $user, string $identity, string $connectedAt): SocialAccount
    {
        $account = new SocialAccount(
            $user,
            SocialNetwork::Bluesky,
            $identity,
            '@' . $identity,
            'https://pds.example.test',
            new SealedSecret('c', 'n', 's', 1),
            'mnop',
            new \DateTimeImmutable($connectedAt),
        );
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $account;
    }

    private function privateFeedOf(SocialAccount $account): Feed
    {
        $feed = new Feed('social:bluesky:' . $account->requireId() . ':home');
        $feed->setSourceFormat(SourceFormat::BLUESKY_API);
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new SocialFeed($account, $feed, SocialFeedKind::Home, ''));
        $this->entityManager->flush();

        return $feed;
    }

    private function entryOn(Feed $feed): Entry
    {
        $this->entityManager->persist($feed);
        $now = new \DateTimeImmutable('2026-10-13 10:00:00');
        $entry = new Entry($feed, 'at://did:plc:bob/app.bsky.feed.post/3abc', null, 'Post', $now, $now);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function chooser(): ActingAccountChooser
    {
        /** @var SocialAccountRepository $accounts */
        $accounts = $this->entityManager->getRepository(SocialAccount::class);

        return new ActingAccountChooser(SocialFeeds::repository($this->entityManager), $accounts);
    }
}
