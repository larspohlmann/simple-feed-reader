<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Lemmy;

use App\Entity\SocialAccount;
use App\Service\Social\Lemmy\LemmyOwnPosts;
use App\Service\Social\Lemmy\Model\LemmyPostAttemptModel;
use App\Service\Social\Publishing\Model\PublishedPostModel;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class LemmyOwnPostsTest extends TestCase
{
    private const string LISTING = 'GET https://lemmy.example/api/v3/user'
        . '?username=lars%40lemmy.example&sort=New&limit=10';
    private const string ADDRESS = 'https://lemmy.example/post/501';

    public function testFindsTheAccountsPostWithTheSameTitleInTheSameCommunity(): void
    {
        $found = $this->lookUp(self::listing(self::post('Title', 7, '2026-11-25T11:36:09.635002Z')));

        self::assertEquals(new PublishedPostModel(self::ADDRESS, self::ADDRESS), $found);
    }

    public function testAnOlderPostWithTheSameTitleIsNotTheOneJustSent(): void
    {
        self::assertNull($this->lookUp(self::listing(self::post('Title', 7, '2026-11-25T11:30:00Z'))));
    }

    public function testAPostPublishedUpToTwoMinutesBeforeTheSendCounts(): void
    {
        self::assertNotNull($this->lookUp(self::listing(self::post('Title', 7, '2026-11-25T11:34:09Z'))));
    }

    public function testAPostPublishedMoreThanTwoMinutesBeforeTheSendIsNoMatch(): void
    {
        self::assertNull($this->lookUp(self::listing(self::post('Title', 7, '2026-11-25T11:34:08Z'))));
    }

    public function testTheInstantIsComparedNotTheClockTime(): void
    {
        self::assertNotNull($this->lookUp(self::listing(self::post('Title', 7, '2026-11-25T13:36:09+02:00'))));
        self::assertNull($this->lookUp(self::listing(self::post('Title', 7, '2026-11-25T11:36:09+02:00'))));
    }

    public function testAZoneLessTimeIsReadAsUtcWhateverTheDefaultTimezone(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');
        try {
            $found = $this->lookUp(self::listing(self::post('Title', 7, '2026-11-25T11:36:09')));
        } finally {
            date_default_timezone_set($previous);
        }

        self::assertNotNull($found);
    }

    public function testAPostInAnotherCommunityIsNoMatch(): void
    {
        self::assertNull($this->lookUp(self::listing(self::post('Title', 8, '2026-11-25T11:36:09Z'))));
    }

    public function testAPostWithAnotherTitleIsNoMatch(): void
    {
        self::assertNull($this->lookUp(self::listing(self::post('Other', 7, '2026-11-25T11:36:09Z'))));
    }

    public function testADeletedPostIsNoMatch(): void
    {
        $deleted = self::post('Title', 7, '2026-11-25T11:36:09Z') + ['deleted' => true];

        self::assertNull($this->lookUp(self::listing($deleted)));
    }

    public function testAMatchWithoutAnHttpsAddressIsNoMatch(): void
    {
        $unaddressed = array_diff_key(self::post('Title', 7, '2026-11-25T11:36:09Z'), ['ap_id' => true]);

        self::assertNull($this->lookUp(self::listing($unaddressed)));
    }

    public function testALookupTheInstanceRefusesFindsNothing(): void
    {
        self::assertNull($this->lookUp(new MockResponse('', ['http_code' => Response::HTTP_UNAUTHORIZED])));
    }

    public function testALookupTheInstanceCannotAnswerFindsNothing(): void
    {
        self::assertNull($this->lookUp(new MockResponse('', ['http_code' => Response::HTTP_SERVICE_UNAVAILABLE])));
    }

    public function testAnUnreadablePublishedTimeIsNoMatch(): void
    {
        self::assertNull($this->lookUp(self::listing(self::post('Title', 7, 'yesterday-ish'))));
    }

    private function lookUp(MockResponse $listing): ?PublishedPostModel
    {
        $http = new RoutedHttpClient([self::LISTING => $listing]);
        $ownPosts = new LemmyOwnPosts(SocialApiWiring::lemmyAccountApi($http->client()));
        $attempt = new LemmyPostAttemptModel('Title', 7, new \DateTimeImmutable('2026-11-25T11:36:09Z'));

        return $ownPosts->findJustPublished($this->account(), $attempt);
    }

    private function account(): SocialAccount
    {
        return SocialAccounts::lemmyOf(SocialAccounts::owner());
    }

    /** @param array<string, mixed> ...$posts */
    private static function listing(array ...$posts): MockResponse
    {
        $views = array_map(static fn (array $post): array => ['post' => $post], $posts);

        return new MockResponse(json_encode(['posts' => $views], JSON_THROW_ON_ERROR));
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
