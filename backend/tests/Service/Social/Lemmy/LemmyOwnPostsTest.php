<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Lemmy;

use App\Entity\SocialAccount;
use App\Service\Outbound\Fetch\IpValidator;
use App\Service\Outbound\Fetch\UrlGuard;
use App\Service\Social\Lemmy\LemmyAccountApi;
use App\Service\Social\Lemmy\LemmyApiClient;
use App\Service\Social\Lemmy\LemmyOwnPosts;
use App\Service\Social\Lemmy\Model\LemmyPostAttemptModel;
use App\Service\Social\Publishing\Model\PublishedPostModel;
use App\Service\Social\Social\StoredCredential;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

final class LemmyOwnPostsTest extends TestCase
{
    private const string LISTING = 'GET https://lemmy.example/api/v3/user'
        . '?username=lars%40lemmy.example&sort=New&limit=10';
    private const string ADDRESS = 'https://lemmy.example/post/501';

    public function testFindsTheAccountsPostWithTheSameTitleInTheSameCommunity(): void
    {
        $found = $this->lookUp(self::listing('Title', 7, '2026-11-25T11:36:09.635002Z'));

        self::assertEquals(new PublishedPostModel(self::ADDRESS, self::ADDRESS), $found);
    }

    public function testAnOlderPostWithTheSameTitleIsNotTheOneJustSent(): void
    {
        self::assertNull($this->lookUp(self::listing('Title', 7, '2026-11-25T11:30:00Z')));
    }

    public function testAPostPublishedUpToTwoMinutesBeforeTheSendCounts(): void
    {
        self::assertNotNull($this->lookUp(self::listing('Title', 7, '2026-11-25T11:34:09Z')));
    }

    public function testAPostPublishedMoreThanTwoMinutesBeforeTheSendIsNoMatch(): void
    {
        self::assertNull($this->lookUp(self::listing('Title', 7, '2026-11-25T11:34:08Z')));
    }

    public function testTheInstantIsComparedNotTheClockTime(): void
    {
        self::assertNotNull($this->lookUp(self::listing('Title', 7, '2026-11-25T13:36:09+02:00')));
        self::assertNull($this->lookUp(self::listing('Title', 7, '2026-11-25T11:36:09+02:00')));
    }

    public function testAZoneLessTimeIsReadAsUtcWhateverTheDefaultTimezone(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');
        try {
            $found = $this->lookUp(self::listing('Title', 7, '2026-11-25T11:36:09'));
        } finally {
            date_default_timezone_set($previous);
        }

        self::assertNotNull($found);
    }

    public function testAPostInAnotherCommunityIsNoMatch(): void
    {
        self::assertNull($this->lookUp(self::listing('Title', 8, '2026-11-25T11:36:09Z')));
    }

    public function testAPostWithAnotherTitleIsNoMatch(): void
    {
        self::assertNull($this->lookUp(self::listing('Other', 7, '2026-11-25T11:36:09Z')));
    }

    public function testADeletedPostIsNoMatch(): void
    {
        self::assertNull($this->lookUp(self::deletedListing()));
    }

    public function testAMatchWithoutAnHttpsAddressIsNoMatch(): void
    {
        $listing = '{"posts":[{"post":{"name":"Title","community_id":7,"published":"2026-11-25T11:36:09Z"}}]}';

        self::assertNull($this->lookUp(new MockResponse($listing)));
    }

    public function testAnUnreadablePublishedTimeIsNoMatch(): void
    {
        self::assertNull($this->lookUp(self::listing('Title', 7, 'yesterday-ish')));
    }

    private function lookUp(MockResponse $listing): ?PublishedPostModel
    {
        $http = new RoutedHttpClient([self::LISTING => $listing]);
        $guard = new UrlGuard(new StaticDnsResolver(['lemmy.example' => ['93.184.216.34']]), new IpValidator());
        $client = new LemmyApiClient(FetchWiring::patientApiClient($http->client(), $guard));
        $ownPosts = new LemmyOwnPosts(new LemmyAccountApi($client, new StoredCredential(SocialAccounts::cipher())));
        $attempt = new LemmyPostAttemptModel('Title', 7, new \DateTimeImmutable('2026-11-25T11:36:09Z'));

        return $ownPosts->findJustPublished($this->account(), $attempt);
    }

    private function account(): SocialAccount
    {
        return SocialAccounts::lemmyOf(SocialAccounts::owner());
    }

    private static function listing(string $title, int $communityId, string $published): MockResponse
    {
        return self::listingOf(self::post($title, $communityId, $published));
    }

    private static function deletedListing(): MockResponse
    {
        return self::listingOf(self::post('Title', 7, '2026-11-25T11:36:09Z') + ['deleted' => true]);
    }

    /** @param array<string, mixed> $post */
    private static function listingOf(array $post): MockResponse
    {
        return new MockResponse(json_encode(['posts' => [['post' => $post]]], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private static function post(string $title, int $communityId, string $published): array
    {
        return [
            'id' => 501,
            'name' => $title,
            'community_id' => $communityId,
            'published' => $published,
            'ap_id' => self::ADDRESS,
        ];
    }
}
