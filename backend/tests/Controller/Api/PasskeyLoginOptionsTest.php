<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Service\Settings\InstanceSettings;
use App\Service\Settings\InstanceSettingsUpdate;
use App\Tests\Support\ApiTestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * WebAuthn login ("assertion") options (#624): issued to an anonymous
 * caller, discoverable-credential only, and rate-limited on its own budget.
 *
 * See PasskeyRegistrationTest for the sibling options endpoint. This one
 * takes no e-mail — the whole point of a discoverable-credential login is
 * that the server does not know who is asking — so there is no equivalent to
 * that file's pinRelyingParty()/authenticate() setup to reuse.
 */
final class PasskeyLoginOptionsTest extends ApiTestCase
{
    private const string OPTIONS_PATH = '/api/auth/passkey/login/options';

    /**
     * passkey_challenge is a per-IP FILESYSTEM-backed limiter (rate_limiter.yaml),
     * which survives both the kernel reboot between requests and the end of
     * the run. testTheChallengeEndpointIsRateLimited() below deliberately
     * exhausts it, so both setUp() and tearDown() clear the pool: without the
     * tearDown() half, a drained budget would leak into whichever test — in
     * this file or another — runs next in the same process, which is exactly
     * how the order-dependent flake in #651 keeps reappearing.
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
     * Boots the kernel only long enough to clear the pool, then shuts it
     * back down — self::createClient() refuses to run if the kernel is
     * already booted when a test method starts, which getContainer() would
     * otherwise leave it. See SetupControllerTest for the same shape, needed
     * for the same reason.
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

        $empty = $this->optionsBodyShape($client);
        $this->factory()->create('somebody@example.test');
        $populated = $this->optionsBodyShape($client);

        self::assertSame($empty, $populated);
    }

    /**
     * Conditional mediation calls this on every login-page view, from every
     * anonymous visitor, and each call writes a cache entry. Without its own
     * budget that is an unbounded write surface a stranger controls.
     *
     * 30 matches passkey_challenge's configured limit in rate_limiter.yaml —
     * keep the two in sync if that budget ever changes.
     */
    public function testTheChallengeEndpointIsRateLimited(): void
    {
        $client = static::createClient();

        for ($attempt = 0; $attempt < 30; $attempt++) {
            $client->request('POST', self::OPTIONS_PATH);
            self::assertResponseIsSuccessful();
        }

        $client->request('POST', self::OPTIONS_PATH);
        self::assertResponseStatusCodeSame(429);
    }

    /**
     * The sorted key structure of one options response, with every random
     * value stripped, so callers can compare SHAPE rather than bytes. Takes
     * the client rather than creating its own: static::createClient() may be
     * called at most once per test, and this is called twice in
     * testTheResponseShapeDoesNotDependOnWhetherAccountsExist().
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

    /**
     * The one anonymous endpoint among the six #624 follow-up enforces: a
     * disabled instance must not even hand out a login challenge.
     */
    public function testTheOptionsEndpointRefusesWhenPasskeySignInIsDisabled(): void
    {
        $client = static::createClient();
        /** @var InstanceSettings $settings */
        $settings = self::getContainer()->get(InstanceSettings::class);
        $settings->update(new InstanceSettingsUpdate(
            requireEmailConfirmation: true,
            requireApproval: true,
            publicBaseUrl: null,
            passkeyRpId: null,
            passkeyRpName: null,
            passkeySignInEnabled: false,
        ));

        $client->request('POST', self::OPTIONS_PATH);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('application/problem+json', $client->getResponse()->headers->get('Content-Type'));
    }

    private function rateLimiterCache(): CacheItemPoolInterface
    {
        /** @var CacheItemPoolInterface $cache */
        $cache = self::getContainer()->get('test.cache.rate_limiter');

        return $cache;
    }
}
