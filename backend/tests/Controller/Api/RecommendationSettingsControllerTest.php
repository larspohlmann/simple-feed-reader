<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Entity\WorkerHeartbeat;
use App\Repository\WorkerHeartbeatRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Worker\RecommendationDriverKind;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The GET/PUT pair in front of Task 2's resolver and writer. No AI provider
 * row is seeded anywhere here, so the context window resolves to the
 * fallback unless a case sets it itself — that is what proves
 * `contextWindowSource`.
 */
final class RecommendationSettingsControllerTest extends WebTestCase
{
    private const string URI = '/api/me/ai/recommendations';

    /** @return array{0: array<string,string>, 1: User} */
    private function auth(string $email): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $user = (new UserFactory($em, $hasher))->create($email);

        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);

        return [['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user)], $user];
    }

    /** @return array<string, mixed> */
    private function payload(KernelBrowser $client): array
    {
        $decoded = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function fullPayloadJson(): string
    {
        return json_encode([
            'guidancePrompt' => 'Prefer long-form pieces.',
            'favoritesCap' => 20,
            'keptCap' => 15,
            'viewedCap' => 30,
            'candidatePoolSize' => 500,
            'lookbackDays' => 3,
            'picksLimit' => 25,
            'contextWindow' => 65536,
            'batchCount' => 12,
            'debugEnabled' => true,
            'autoGenerateIntervalHours' => null,
        ], \JSON_THROW_ON_ERROR);
    }

    /**
     * Gives the account a verified AI provider row with a model context
     * window, without going through the connection endpoints — the only way
     * to exercise `contextWindowSource: 'provider'` in the resolver.
     */
    private function seedProviderContextWindow(User $user, int $contextWindow): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        self::assertInstanceOf(ApiKeyCipher::class, $cipher);

        $userId = $user->getId();
        self::assertNotNull($userId);
        $sealed = $cipher->seal($userId, 'sk-throwaway1234');
        $now = new \DateTimeImmutable('2026-08-07 09:00:00');

        $settings = new AiProviderSettings($user, null, 'https://api.example.test/v1', $sealed, '1234', $now);
        $em->persist($settings);
        $settings->chooseModel('m', $now, $contextWindow);
        $user->setActiveAiProviderSettings($settings);
        $em->flush();
    }

    public function testAnUnconfiguredAccountReportsAllDefaults(): void
    {
        $client = static::createClient();
        [$headers] = $this->auth('recsettings-empty@example.test');

        $client->request('GET', self::URI, server: $headers);

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client);
        self::assertNull($payload['guidancePrompt']);
        self::assertIsString($payload['defaultGuidancePrompt']);
        self::assertNotSame('', $payload['defaultGuidancePrompt']);
        self::assertIsArray($payload['fixedPrompt']);
        self::assertIsString($payload['fixedPrompt']['role']);
        self::assertNotSame('', $payload['fixedPrompt']['role']);
        self::assertIsString($payload['fixedPrompt']['outputContract']);
        // The score range, not the picks limit: the contract shown to the
        // reader is what the model is asked to produce per candidate.
        self::assertStringContainsString('"score": <0-1000>', $payload['fixedPrompt']['outputContract']);
        // Pins the card to the live batch prompt over the deleted
        // rank-then-dedup one (#493 Task 13, Ruling F): the old contract's
        // template carried a "reason" field the batch call never asks for.
        self::assertStringNotContainsString('"reason"', $payload['fixedPrompt']['outputContract']);
        self::assertSame(40, $payload['favoritesCap']);
        self::assertSame(40, $payload['keptCap']);
        self::assertSame(80, $payload['viewedCap']);
        self::assertSame(500, $payload['candidatePoolSize']);
        self::assertSame(2, $payload['lookbackDays']);
        self::assertSame(50, $payload['picksLimit']);
        self::assertSame(32768, $payload['contextWindow']);
        self::assertNull($payload['contextWindowOverride']);
        self::assertSame('fallback', $payload['contextWindowSource']);
        self::assertNull($payload['batchCount']);
        self::assertFalse($payload['debugEnabled']);
    }

    public function testAProviderContextWindowIsReportedAsTheEffectiveValueWithNoOverride(): void
    {
        $client = static::createClient();
        [$headers, $user] = $this->auth('recsettings-provider@example.test');
        $this->seedProviderContextWindow($user, 98304);

        $client->request('GET', self::URI, server: $headers);

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client);
        self::assertSame(98304, $payload['contextWindow']);
        self::assertNull($payload['contextWindowOverride']);
        self::assertSame('provider', $payload['contextWindowSource']);
    }

    public function testTheEndpointRefusesAnAnonymousCaller(): void
    {
        $client = static::createClient();

        $client->request('GET', self::URI);

        self::assertResponseStatusCodeSame(401);
    }

    public function testSavingFullSettingsEchoesTheNewStateAndPersists(): void
    {
        $client = static::createClient();
        [$headers] = $this->auth('recsettings-save@example.test');

        $client->request(
            'PUT',
            self::URI,
            server: array_merge($headers, ['CONTENT_TYPE' => 'application/json']),
            content: $this->fullPayloadJson(),
        );

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client);
        self::assertSame('Prefer long-form pieces.', $payload['guidancePrompt']);
        self::assertSame(20, $payload['favoritesCap']);
        self::assertSame(15, $payload['keptCap']);
        self::assertSame(30, $payload['viewedCap']);
        self::assertSame(500, $payload['candidatePoolSize']);
        self::assertSame(3, $payload['lookbackDays']);
        self::assertSame(25, $payload['picksLimit']);
        self::assertSame(65536, $payload['contextWindow']);
        self::assertSame(65536, $payload['contextWindowOverride']);
        self::assertSame('user', $payload['contextWindowSource']);
        self::assertSame(12, $payload['batchCount']);
        self::assertTrue($payload['debugEnabled']);

        // Persisted, not just echoed: a fresh GET on the same account reports it too.
        $client->request('GET', self::URI, server: $headers);
        self::assertResponseIsSuccessful();
        $reloaded = $this->payload($client);
        self::assertSame('Prefer long-form pieces.', $reloaded['guidancePrompt']);
        self::assertSame(65536, $reloaded['contextWindow']);
        self::assertSame(12, $reloaded['batchCount']);
    }

    public function testSavingANullBatchCountEchoesNullMeaningAutomaticPacking(): void
    {
        $client = static::createClient();
        [$headers] = $this->auth('recsettings-batchcount-null@example.test');

        $body = json_decode($this->fullPayloadJson(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        $body['batchCount'] = null;

        $client->request(
            'PUT',
            self::URI,
            server: array_merge($headers, ['CONTENT_TYPE' => 'application/json']),
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        self::assertNull($this->payload($client)['batchCount']);

        $client->request('GET', self::URI, server: $headers);
        self::assertResponseIsSuccessful();
        self::assertNull($this->payload($client)['batchCount']);
    }

    public function testAnOutOfRangeCapIsUnprocessable(): void
    {
        $client = static::createClient();
        [$headers] = $this->auth('recsettings-invalid@example.test');

        $body = json_decode($this->fullPayloadJson(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        $body['favoritesCap'] = 9999;

        $client->request(
            'PUT',
            self::URI,
            server: array_merge($headers, ['CONTENT_TYPE' => 'application/json']),
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);
        $payload = $this->payload($client);
        self::assertSame('validation_error', $payload['type']);
        self::assertIsArray($payload['errors']);
        self::assertArrayHasKey('favoritesCap', $payload['errors']);
    }

    /**
     * These three bounds are load-bearing rather than cosmetic: zero picks
     * makes a run meaningless, a smaller candidate pool degrades
     * recommendations silently, and a context window below 4096 breaks the
     * downstream prompt-budgeting maths. The maxima and the other minima are
     * UI ceilings with no failure mode and are deliberately not swept here.
     * lookbackDays is the one field whose maximum is also swept: unlike the
     * other maxima, 8 is a real ceiling, since a window nobody can reach past
     * is the entire point of the setting.
     *
     * @return iterable<string, array{string, int|null}>
     */
    public static function rejectedLoadBearingBounds(): iterable
    {
        yield 'picksLimit below its floor of 1' => ['picksLimit', 0];
        yield 'candidatePoolSize below its floor of 10' => ['candidatePoolSize', 9];
        yield 'contextWindow below its floor of 4096' => ['contextWindow', 4095];
        yield 'lookbackDays below its floor of 1' => ['lookbackDays', 0];
        yield 'lookbackDays above its ceiling of 7' => ['lookbackDays', 8];
    }

    #[DataProvider('rejectedLoadBearingBounds')]
    public function testARejectedLoadBearingBoundIsUnprocessable(string $field, int $rejectedValue): void
    {
        $client = static::createClient();
        [$headers] = $this->auth('recsettings-bound-' . strtolower($field) . '@example.test');

        $body = json_decode($this->fullPayloadJson(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        $body[$field] = $rejectedValue;

        $client->request(
            'PUT',
            self::URI,
            server: array_merge($headers, ['CONTENT_TYPE' => 'application/json']),
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);
        $payload = $this->payload($client);
        self::assertSame('validation_error', $payload['type']);
        self::assertIsArray($payload['errors']);
        self::assertArrayHasKey($field, $payload['errors']);
    }

    /**
     * The floor and ceiling in {@see rejectedLoadBearingBounds()} are proven
     * only from the outside: those cases show 0 and 8 are refused, but say
     * nothing about whether 1 and 7 themselves — the values `Assert\Range`
     * is actually supposed to let through — still are.
     *
     * @return iterable<string, array{int}>
     */
    public static function acceptedLookbackDaysBounds(): iterable
    {
        yield 'lookbackDays at its floor of 1' => [1];
        yield 'lookbackDays at its ceiling of 7' => [7];
    }

    #[DataProvider('acceptedLookbackDaysBounds')]
    public function testAnAcceptedLookbackDaysBoundIsPersisted(int $acceptedValue): void
    {
        $client = static::createClient();
        [$headers] = $this->auth('recsettings-lookback-bound-' . $acceptedValue . '@example.test');

        $body = json_decode($this->fullPayloadJson(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        $body['lookbackDays'] = $acceptedValue;

        $client->request(
            'PUT',
            self::URI,
            server: array_merge($headers, ['CONTENT_TYPE' => 'application/json']),
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        self::assertSame($acceptedValue, $this->payload($client)['lookbackDays']);

        $client->request('GET', self::URI, server: $headers);
        self::assertResponseIsSuccessful();
        self::assertSame($acceptedValue, $this->payload($client)['lookbackDays']);
    }

    public function testClearingAContextWindowOverrideFallsBackToTheProvider(): void
    {
        $client = static::createClient();
        [$headers, $user] = $this->auth('recsettings-clear-override@example.test');
        $this->seedProviderContextWindow($user, 98304);

        // First set a user override.
        $client->request(
            'PUT',
            self::URI,
            server: array_merge($headers, ['CONTENT_TYPE' => 'application/json']),
            content: $this->fullPayloadJson(),
        );
        self::assertResponseIsSuccessful();
        self::assertSame('user', $this->payload($client)['contextWindowSource']);

        // Then clear it.
        $body = json_decode($this->fullPayloadJson(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        $body['contextWindow'] = null;

        $client->request(
            'PUT',
            self::URI,
            server: array_merge($headers, ['CONTENT_TYPE' => 'application/json']),
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();

        $client->request('GET', self::URI, server: $headers);

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client);
        self::assertSame(98304, $payload['contextWindow']);
        self::assertNull($payload['contextWindowOverride']);
        self::assertSame('provider', $payload['contextWindowSource']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blankGuidancePrompts(): iterable
    {
        yield 'a single space' => ['   '];
        yield 'a tab and newline' => ["\t\n"];
    }

    #[DataProvider('blankGuidancePrompts')]
    public function testABlankGuidancePromptNormalisesToNull(string $blank): void
    {
        $client = static::createClient();
        [$headers] = $this->auth('recsettings-blank@example.test');

        $body = json_decode($this->fullPayloadJson(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        $body['guidancePrompt'] = $blank;

        $client->request(
            'PUT',
            self::URI,
            server: array_merge($headers, ['CONTENT_TYPE' => 'application/json']),
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();

        $client->request('GET', self::URI, server: $headers);

        self::assertResponseIsSuccessful();
        self::assertNull($this->payload($client)['guidancePrompt']);
    }

    public function testARealGuidancePromptIsKeptVerbatim(): void
    {
        $client = static::createClient();
        [$headers] = $this->auth('recsettings-real@example.test');

        $body = json_decode($this->fullPayloadJson(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        $body['guidancePrompt'] = '  Favor science coverage.  ';

        $client->request(
            'PUT',
            self::URI,
            server: array_merge($headers, ['CONTENT_TYPE' => 'application/json']),
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        self::assertSame('  Favor science coverage.  ', $this->payload($client)['guidancePrompt']);
    }

    public function testANullGuidancePromptStaysNull(): void
    {
        $client = static::createClient();
        [$headers] = $this->auth('recsettings-null@example.test');

        $body = json_decode($this->fullPayloadJson(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        $body['guidancePrompt'] = null;

        $client->request(
            'PUT',
            self::URI,
            server: array_merge($headers, ['CONTENT_TYPE' => 'application/json']),
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        self::assertNull($this->payload($client)['guidancePrompt']);
    }

    public function testShowExposesTheIntervalAndWorkerLiveness(): void
    {
        $client = static::createClient();
        [$headers] = $this->auth('recsettings-worker-liveness@example.test');

        $client->request('GET', self::URI, server: $headers);

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client);
        self::assertNull($payload['autoGenerateIntervalHours']);
        self::assertArrayHasKey('workerAlive', $payload);
        self::assertIsBool($payload['workerAlive']);
    }

    /**
     * `workerAlive` hides the "you still need a cron entry for scheduled
     * auto-generation" hint, so it must mean a PERSISTENT worker. The
     * on-demand drainer only advances runs that already exist — it never
     * starts a due one — so an operator who opens Settings while a drain
     * happens to run must still be told to set the cron up (#371 follow-up).
     */
    public function testALiveDrainerIsNotReportedAsAWorker(): void
    {
        $client = static::createClient();
        [$headers] = $this->auth('recsettings-drainer-alive@example.test');
        $this->touchHeartbeatNow(RecommendationDriverKind::OnDemandDrainer->heartbeatName());

        $client->request('GET', self::URI, server: $headers);

        self::assertResponseIsSuccessful();
        self::assertFalse($this->payload($client)['workerAlive']);
    }

    public function testAPersistentWorkerIsReportedAsAWorker(): void
    {
        $client = static::createClient();
        [$headers] = $this->auth('recsettings-worker-alive@example.test');
        $this->touchHeartbeatNow(RecommendationDriverKind::PersistentWorker->heartbeatName());

        $client->request('GET', self::URI, server: $headers);

        self::assertResponseIsSuccessful();
        self::assertTrue($this->payload($client)['workerAlive']);
    }

    /**
     * Through the real repository and the container's own clock, so this
     * proves something about the wiring the request path uses rather than
     * about a stand-in.
     */
    private function touchHeartbeatNow(string $name): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        /** @var WorkerHeartbeatRepository $repository */
        $repository = $em->getRepository(WorkerHeartbeat::class);

        $clock = self::getContainer()->get(ClockInterface::class);
        self::assertInstanceOf(ClockInterface::class, $clock);

        $repository->touch($name, $clock->now());
    }

    public function testSaveAcceptsAnAllowedInterval(): void
    {
        $client = static::createClient();
        [$headers] = $this->auth('recsettings-interval-allowed@example.test');

        $body = json_decode($this->fullPayloadJson(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        $body['autoGenerateIntervalHours'] = 6;

        $client->request(
            'PUT',
            self::URI,
            server: array_merge($headers, ['CONTENT_TYPE' => 'application/json']),
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        self::assertSame(6, $this->payload($client)['autoGenerateIntervalHours']);
    }

    public function testSaveRejectsADisallowedInterval(): void
    {
        $client = static::createClient();
        [$headers] = $this->auth('recsettings-interval-rejected@example.test');

        $body = json_decode($this->fullPayloadJson(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        $body['autoGenerateIntervalHours'] = 5;

        $client->request(
            'PUT',
            self::URI,
            server: array_merge($headers, ['CONTENT_TYPE' => 'application/json']),
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);
        $payload = $this->payload($client);
        self::assertSame('validation_error', $payload['type']);
        self::assertIsArray($payload['errors']);
        self::assertArrayHasKey('autoGenerateIntervalHours', $payload['errors']);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function allowedAutoGenerateIntervals(): iterable
    {
        yield 'every hour' => [1];
        yield 'every 3 hours' => [3];
        yield 'every 6 hours' => [6];
        yield 'every 12 hours' => [12];
        yield 'every 24 hours' => [24];
    }

    #[DataProvider('allowedAutoGenerateIntervals')]
    public function testSaveAcceptsEachAllowedInterval(int $hours): void
    {
        $client = static::createClient();
        [$headers] = $this->auth('recsettings-interval-ok-' . $hours . '@example.test');

        $body = json_decode($this->fullPayloadJson(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        $body['autoGenerateIntervalHours'] = $hours;

        $client->request(
            'PUT',
            self::URI,
            server: array_merge($headers, ['CONTENT_TYPE' => 'application/json']),
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        self::assertSame($hours, $this->payload($client)['autoGenerateIntervalHours']);
    }

    /**
     * Neighbours of each allowed value: an off-by-one on any of the
     * `Assert\Choice` literals would let one of these through.
     *
     * @return iterable<string, array{int}>
     */
    public static function disallowedAutoGenerateIntervals(): iterable
    {
        yield 'zero' => [0];
        yield 'two' => [2];
        yield 'four' => [4];
        yield 'seven' => [7];
        yield 'eleven' => [11];
        yield 'thirteen' => [13];
        yield 'twenty-three' => [23];
        yield 'twenty-five' => [25];
    }

    #[DataProvider('disallowedAutoGenerateIntervals')]
    public function testSaveRejectsEachDisallowedInterval(int $hours): void
    {
        $client = static::createClient();
        [$headers] = $this->auth('recsettings-interval-off-' . $hours . '@example.test');

        $body = json_decode($this->fullPayloadJson(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        $body['autoGenerateIntervalHours'] = $hours;

        $client->request(
            'PUT',
            self::URI,
            server: array_merge($headers, ['CONTENT_TYPE' => 'application/json']),
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);
        $errors = $this->payload($client)['errors'];
        self::assertIsArray($errors);
        self::assertArrayHasKey('autoGenerateIntervalHours', $errors);
    }
}
