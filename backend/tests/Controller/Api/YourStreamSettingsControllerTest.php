<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\YourStreamWord;
use App\Enum\FeedLanguage;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class YourStreamSettingsControllerTest extends ApiTestCase
{
    private const string URI = '/api/me/your-stream';

    public function testAnAccountWithoutARowStreamsWideWithItsWords(): void
    {
        $client = static::createClient();
        [$headers, $user] = $this->bearerAndUser('your-stream-words@example.test');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->persist(new YourStreamWord($user, FeedLanguage::German, 'bundeslig', 'Bundesliga', 1.0, 2.0));
        $entityManager->flush();

        $client->request('GET', self::URI, server: $headers);

        self::assertResponseIsSuccessful();
        self::assertEquals([
            'enabled' => true,
            'precision' => 'wide',
            'defaultPrecision' => 'wide',
            'words' => [['language' => 'de', 'words' => [['word' => 'Bundesliga', 'weight' => 1.0]]]],
        ], $this->payload($client));
    }

    public function testAnAccountWithoutARowOrWordsStreamsWideWithAnEmptyList(): void
    {
        $client = static::createClient();
        $headers = $this->bearerFor($this->factory()->create('your-stream-empty@example.test'));

        $client->request('GET', self::URI, server: $headers);

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['enabled' => true, 'precision' => 'wide', 'defaultPrecision' => 'wide', 'words' => []],
            $this->payload($client),
        );
    }

    public function testPuttingTheSettingsAnswersWithTheGetBody(): void
    {
        $client = static::createClient();
        $headers = $this->bearerFor($this->factory()->create('your-stream-put@example.test'));

        $this->put($client, $headers, ['enabled' => false, 'precision' => 'narrow']);

        self::assertResponseIsSuccessful();
        $saved = $this->payload($client);
        self::assertFalse($saved['enabled']);
        self::assertSame('narrow', $saved['precision']);
        $client->request('GET', self::URI, server: $headers);
        self::assertSame($saved, $this->payload($client));
    }

    public function testASecondPutReplacesTheFirst(): void
    {
        $client = static::createClient();
        $headers = $this->bearerFor($this->factory()->create('your-stream-second-put@example.test'));
        $this->put($client, $headers, ['enabled' => false, 'precision' => 'narrow']);

        $this->put($client, $headers, ['enabled' => true, 'precision' => 'widest']);

        $client->request('GET', self::URI, server: $headers);
        $reloaded = $this->payload($client);
        self::assertTrue($reloaded['enabled']);
        self::assertSame('widest', $reloaded['precision']);
    }

    public function testAPutWithoutPrecisionIsRejected(): void
    {
        $client = static::createClient();
        $headers = $this->bearerFor($this->factory()->create('your-stream-no-precision@example.test'));

        $this->put($client, $headers, ['enabled' => true]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    public function testAPutWithoutTheSwitchIsRejected(): void
    {
        $client = static::createClient();
        $headers = $this->bearerFor($this->factory()->create('your-stream-no-switch@example.test'));

        $this->put($client, $headers, ['precision' => 'wide']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testAPutWithAnUnknownPrecisionIsRejected(): void
    {
        $client = static::createClient();
        $headers = $this->bearerFor($this->factory()->create('your-stream-unknown@example.test'));

        $this->put($client, $headers, ['enabled' => true, 'precision' => 'sharpest']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    public function testWithoutABearerTokenTheSettingsAreClosed(): void
    {
        $client = static::createClient();

        $client->request('GET', self::URI);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testWithoutABearerTokenAPutIsRefused(): void
    {
        $client = static::createClient();

        $this->put($client, [], ['enabled' => false, 'precision' => 'narrow']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $body
     */
    private function put(KernelBrowser $client, array $headers, array $body): void
    {
        $client->request(
            'PUT',
            self::URI,
            server: array_merge($headers, ['CONTENT_TYPE' => 'application/json']),
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }
}
