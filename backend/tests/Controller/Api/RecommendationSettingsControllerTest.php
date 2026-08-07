<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
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
            'picksLimit' => 25,
            'contextWindow' => 65536,
            'debugEnabled' => true,
        ], \JSON_THROW_ON_ERROR);
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
        self::assertStringContainsString('100', $payload['fixedPrompt']['outputContract']);
        self::assertSame(40, $payload['favoritesCap']);
        self::assertSame(40, $payload['keptCap']);
        self::assertSame(80, $payload['viewedCap']);
        self::assertSame(1000, $payload['candidatePoolSize']);
        self::assertSame(100, $payload['picksLimit']);
        self::assertSame(32768, $payload['contextWindow']);
        self::assertNull($payload['contextWindowOverride']);
        self::assertSame('fallback', $payload['contextWindowSource']);
        self::assertFalse($payload['debugEnabled']);
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
        self::assertSame(25, $payload['picksLimit']);
        self::assertSame(65536, $payload['contextWindow']);
        self::assertSame(65536, $payload['contextWindowOverride']);
        self::assertSame('user', $payload['contextWindowSource']);
        self::assertTrue($payload['debugEnabled']);

        // Persisted, not just echoed: a fresh GET on the same account reports it too.
        $client->request('GET', self::URI, server: $headers);
        self::assertResponseIsSuccessful();
        $reloaded = $this->payload($client);
        self::assertSame('Prefer long-form pieces.', $reloaded['guidancePrompt']);
        self::assertSame(65536, $reloaded['contextWindow']);
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
}
