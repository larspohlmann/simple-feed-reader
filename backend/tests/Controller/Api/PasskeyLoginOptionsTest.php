<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Tests\Support\ApiTestCase;
use App\Tests\Support\TogglesPasskeySignIn;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/** Login options: anonymous, discoverable credentials only, and rate-limited on their own budget. */
final class PasskeyLoginOptionsTest extends ApiTestCase
{
    use TogglesPasskeySignIn;

    private const string OPTIONS_PATH = '/api/auth/passkey/login/options';

    /**
     * passkey_challenge is a filesystem-backed per-IP limiter that outlives the run, and the rate-limit test drains
     * it: setUp() and tearDown() both clear it, or the drained budget leaks into the next test in the process.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->clearRateLimiterCache();
    }

    protected function tearDown(): void
    {
        // The kernel is still booted from the test body here (createClient()
        // never shuts it down), so this can read the pool directly without
        // the boot/shutdown dance clearRateLimiterCache() needs in setUp().
        $this->rateLimiterCache()->clear();

        parent::tearDown();
    }

    /**
     * Boots only long enough to clear the pool: createClient() refuses to run on a kernel getContainer() left booted.
     */
    private function clearRateLimiterCache(): void
    {
        self::bootKernel();
        $this->rateLimiterCache()->clear();
        self::ensureKernelShutdown();
    }

    public function testTheOptionsAreIssuedToAnAnonymousCaller(): void
    {
        $client = static::createClient();
        $this->enablePasskeySignIn();

        $client->request('POST', self::OPTIONS_PATH);

        self::assertResponseIsSuccessful();
        $body = $this->payload($client);
        /** @var array<string, mixed> $options */
        $options = $body['options'];
        self::assertSame('required', $options['userVerification']);
        self::assertSame([], $options['allowCredentials']);
        self::assertNotEmpty($body['handle']);
    }

    /**
     * No enumeration: the endpoint takes no e-mail, and the response shape is
     * identical whether or not any account exists.
     */
    public function testTheResponseShapeDoesNotDependOnWhetherAccountsExist(): void
    {
        $client = static::createClient();
        $this->enablePasskeySignIn();

        $empty = $this->optionsBodyShape($client);
        $this->factory()->create('somebody@example.test');
        $populated = $this->optionsBodyShape($client);

        self::assertSame($empty, $populated);
    }

    /**
     * Every login-page view calls this and writes a cache entry, so it has its own budget. 30 is passkey_challenge's
     * limit in rate_limiter.yaml: change both together.
     */
    public function testTheChallengeEndpointIsRateLimited(): void
    {
        $client = static::createClient();
        $this->enablePasskeySignIn();

        for ($attempt = 0; $attempt < 30; $attempt++) {
            $client->request('POST', self::OPTIONS_PATH);
            self::assertResponseIsSuccessful();
        }

        $client->request('POST', self::OPTIONS_PATH);
        self::assertResponseStatusCodeSame(429);
    }

    /**
     * The sorted key structure of one options response, random values stripped. Takes the client because
     * createClient() may run once per test, and one test calls this twice.
     *
     * @return array<string, mixed>
     */
    private function optionsBodyShape(KernelBrowser $client): array
    {
        $client->request('POST', self::OPTIONS_PATH);
        self::assertResponseIsSuccessful();

        $body = $this->payload($client);
        $body['handle'] = null;

        /** @var array<string, mixed> $options */
        $options = $body['options'];
        $options['challenge'] = null;
        $body['options'] = $options;

        return $body;
    }

    /** The one anonymous guarded endpoint: a disabled instance must not even hand out a login challenge. */
    public function testTheOptionsEndpointRefusesWhenPasskeySignInIsDisabled(): void
    {
        $client = static::createClient();
        $this->disablePasskeySignIn();

        $client->request('POST', self::OPTIONS_PATH);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('application/problem+json', $client->getResponse()->headers->get('Content-Type'));
    }

    /**
     * The limiter charges before the availability guard: on a disabled instance the first 30 calls are 403 and the
     * 31st is 429. With the guard first, the 31st would be another free 403.
     */
    public function testTheDisabledInstanceStillGetsThrottledRatherThanRefusingForever(): void
    {
        $client = static::createClient();
        $this->disablePasskeySignIn();

        for ($attempt = 0; $attempt < 30; $attempt++) {
            $client->request('POST', self::OPTIONS_PATH);
            self::assertResponseStatusCodeSame(403, \sprintf('attempt %d should still be 403', $attempt));
        }

        $client->request('POST', self::OPTIONS_PATH);
        self::assertResponseStatusCodeSame(429);
    }

    private function rateLimiterCache(): CacheItemPoolInterface
    {
        /** @var CacheItemPoolInterface $cache */
        $cache = self::getContainer()->get('test.cache.rate_limiter');

        return $cache;
    }
}
