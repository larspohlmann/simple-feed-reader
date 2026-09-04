<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SavedSearch;
use App\Entity\Subscription;
use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\EnablesMailInTests;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The "send me a test digest" button (#636): a preview send over the last N
 * days that never advances digestLastSentAt, gated on mail being on and the
 * address being verified, and capped by the digest_test limiter.
 */
final class MeDigestTestControllerTest extends ApiTestCase
{
    use EnablesMailInTests;

    protected function setUp(): void
    {
        // Same reasoning as FeedPreviewControllerTest: the digest_test limiter
        // counts in a FILESYSTEM pool that outlives the run, so a prior case's
        // spend must not bleed into this one and trip a spurious 429.
        self::bootKernel();
        $rateLimiterCache = self::getContainer()->get('test.cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rateLimiterCache);
        $rateLimiterCache->clear();
        self::ensureKernelShutdown();
    }

    private function authenticate(KernelBrowser $client, User $user): void
    {
        /** @var JWTTokenManagerInterface $manager */
        $manager = self::getContainer()->get(JWTTokenManagerInterface::class);

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $manager->create($user));
    }

    /**
     * A verified user with one includeInDigest saved search that matches an
     * entry inside the requested window. Mirrors the fixture shape
     * EntryListRepositoryDigestTest and DigestComposerTest already rely on.
     */
    private function verifiedUserWithAMatchingDigestSearch(string $email): User
    {
        $em = $this->em();

        $user = $this->factory()->create($email);
        $user->markEmailVerified(new \DateTimeImmutable('2026-08-01T00:00:00Z'));

        $feed = new Feed('https://example.com/' . $email . '/feed.xml');
        $feed->setTitle('Example feed');
        $em->persist($feed);
        $em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));

        $entry = new Entry(
            $feed,
            'guid-' . $email,
            'https://example.com/' . $email,
            'A rust announcement',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('-1 day'),
        );
        $em->persist($entry);

        $search = new SavedSearch($user, 'rust', false);
        $search->setIncludeInDigest(true);
        $em->persist($search);

        $em->flush();

        return $user;
    }

    public function testAVerifiedUserWithAMatchReceivesOneTestDigest(): void
    {
        $client = static::createClient();
        $this->seedEnabledMailInstance();
        $user = $this->verifiedUserWithAMatchingDigestSearch('digest-test-ok@example.test');
        $this->authenticate($client, $user);

        $client->request(
            'POST',
            '/api/me/digest/test',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['days' => 7], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        self::assertSame(['sent' => true], $this->payload($client));
        // assertEmailCount, not a raw getMailerMessages() count: Mailer::send()
        // dispatches one "queued" pre-send MessageEvent plus one real-send event
        // per message when Messenger is wired in (see RegistrationTest's
        // identical use), and the constraint is what filters to the latter.
        self::assertEmailCount(1);
    }

    public function testATestSendDoesNotMoveDigestLastSentAt(): void
    {
        $client = static::createClient();
        $this->seedEnabledMailInstance();
        $user = $this->verifiedUserWithAMatchingDigestSearch('digest-test-watermark@example.test');
        $before = $user->getPreferences()->getDigestLastSentAt();

        $this->authenticate($client, $user);
        $client->request(
            'POST',
            '/api/me/digest/test',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['days' => 7], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();

        $this->em()->clear();
        $reloaded = $this->users()->find($user->getId());
        self::assertInstanceOf(User::class, $reloaded);
        self::assertEquals($before, $reloaded->getPreferences()->getDigestLastSentAt());
    }

    public function testAUserWithNothingToReportGetsSentFalseAndNoMail(): void
    {
        $client = static::createClient();
        $this->seedEnabledMailInstance();
        $user = $this->factory()->create('digest-test-empty@example.test');
        $user->markEmailVerified(new \DateTimeImmutable('2026-08-01T00:00:00Z'));
        $this->em()->flush();
        $this->authenticate($client, $user);

        $client->request(
            'POST',
            '/api/me/digest/test',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['days' => 7], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        self::assertSame(['sent' => false], $this->payload($client));
        self::assertEmailCount(0);
    }

    public function testAnUnverifiedUserIsForbidden(): void
    {
        $client = static::createClient();
        $user = $this->factory()->create('digest-test-unverified@example.test');
        self::assertFalse($user->isEmailVerified());
        $this->authenticate($client, $user);

        $client->request(
            'POST',
            '/api/me/digest/test',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['days' => 7], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    public function testDaysOutsideTheAllowedRangeIsRejected(): void
    {
        $client = static::createClient();
        $user = $this->verifiedUserWithAMatchingDigestSearch('digest-test-badrange@example.test');
        $this->authenticate($client, $user);

        $client->request(
            'POST',
            '/api/me/digest/test',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['days' => 31], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testAnonymousIsRejected(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/me/digest/test',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['days' => 7], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * digest_test is capped at 5 per 15 minutes (rate_limiter.yaml) — each
     * accepted call hands one mail to the relay, so the 6th call in the window
     * must be refused rather than silently spending unlimited outbound mail.
     */
    public function testTheSixthCallInTheWindowIsThrottled(): void
    {
        $client = static::createClient();
        $this->seedEnabledMailInstance();
        $user = $this->verifiedUserWithAMatchingDigestSearch('digest-test-throttled@example.test');
        $this->authenticate($client, $user);

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $client->request(
                'POST',
                '/api/me/digest/test',
                server: ['CONTENT_TYPE' => 'application/json'],
                content: json_encode(['days' => 7], \JSON_THROW_ON_ERROR),
            );
            self::assertResponseIsSuccessful(sprintf('attempt %d should still be accepted', $attempt));
        }

        $client->request(
            'POST',
            '/api/me/digest/test',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['days' => 7], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(429);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertSame('rate_limited', $this->payload($client)['type']);
        $retryAfter = $client->getResponse()->headers->get('Retry-After');
        self::assertNotNull($retryAfter);
        self::assertGreaterThan(0, (int) $retryAfter);
    }

    /** Seeds no mail row on purpose: with the null fallback, mail derives to off. */
    public function testMailDisabledInstanceIsForbidden(): void
    {
        $client = static::createClient();
        $user = $this->verifiedUserWithAMatchingDigestSearch('digest-test-maildisabled@example.test');
        $this->authenticate($client, $user);

        $client->request(
            'POST',
            '/api/me/digest/test',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['days' => 7], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }
}
