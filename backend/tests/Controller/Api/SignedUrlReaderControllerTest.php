<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Service\Reader\Model\ExtractionFailure;
use App\Service\Reader\Model\ExtractionResultModel;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\ReaderEndpointFixtures;
use App\Tests\Support\SignsReaderUrls;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SignedUrlReaderControllerTest extends ApiTestCase
{
    use ReaderEndpointFixtures;
    use SignsReaderUrls;

    private const string URL = 'https://example.com/article';

    protected function setUp(): void
    {
        $this->clearReaderRateLimiter();
    }

    public function testExtractsTheSignedCardsPage(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('card-reader-ok@example.com');
        $fake = $this->installFake();
        $fake->willReturn(ExtractionResultModel::ok(
            url: self::URL,
            title: 'Extracted',
            byline: null,
            siteName: 'Example',
            contentHtml: '<p>Body</p>',
            excerpt: null,
        ));

        $client->request('GET', self::readerPath(self::URL, $this->readerTokenFor(self::URL)), server: $headers);

        self::assertResponseIsSuccessful();
        $body = $this->payload($client);
        self::assertSame('ok', $body['status']);
        self::assertSame('<p>Body</p>', $body['contentHtml']);
        self::assertNull($body['originalHero']);
        self::assertSame([self::URL], $fake->calls);
    }

    public function testAFailedExtractionIsAnsweredWithoutALead(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('card-reader-fail@example.com');
        $this->installFake()->willReturn(
            ExtractionResultModel::failed(self::URL, ExtractionFailure::Fetch, 'HTTP 403 Forbidden'),
        );

        $client->request('GET', self::readerPath(self::URL, $this->readerTokenFor(self::URL)), server: $headers);

        $body = $this->payload($client);
        self::assertSame('failed', $body['status']);
        self::assertNull($body['originalHero']);
    }

    public function testAForgedTokenIsNotFoundAndSpendsNoBudget(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('card-reader-forged@example.com');
        $fake = $this->installFake();
        $this->spendReaderBudget($user);

        $client->request('GET', self::readerPath(self::URL, str_repeat('A', 43)), server: $headers);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertSame([], $fake->calls);
    }

    public function testAnotherCardsTokenIsNotFound(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('card-reader-swapped@example.com');
        $fake = $this->installFake();

        $client->request(
            'GET',
            self::readerPath('https://elsewhere.example/page', $this->readerTokenFor(self::URL)),
            server: $headers,
        );

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame([], $fake->calls);
    }

    public function testAMissingTokenIsNotFound(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('card-reader-tokenless@example.com');
        $fake = $this->installFake();

        $client->request('GET', '/api/reader?' . http_build_query(['url' => self::URL]), server: $headers);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame([], $fake->calls);
    }

    public function testSharesTheEntryReadersBudget(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('card-reader-limit@example.com');
        $fake = $this->installFake();
        $this->spendReaderBudget($user);

        $client->request('GET', self::readerPath(self::URL, $this->readerTokenFor(self::URL)), server: $headers);

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertSame([], $fake->calls);
    }

    public function testRequiresSigningIn(): void
    {
        $client = self::createClient();

        $client->request('GET', self::readerPath(self::URL, str_repeat('A', 43)));

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /** @return array{0: array<string,string>, 1: User} */
    private function auth(string $email): array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $user = (new UserFactory($entityManager, $hasher))->create($email);

        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);

        return [['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user)], $user];
    }

    private static function readerPath(string $url, string $token): string
    {
        return '/api/reader?' . http_build_query(['url' => $url, 'token' => $token]);
    }
}
