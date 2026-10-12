<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Tests\Support\ApiTestCase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\Response;

final class PrivateFeedUrlRefusalTest extends ApiTestCase
{
    public function testASocialUrlCannotBeSubscribedToByHand(): void
    {
        $client = static::createClient();
        $user = $this->factory()->create('private-url@example.test');
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);

        $client->request(
            'POST',
            '/api/subscriptions',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user), 'CONTENT_TYPE' => 'application/json'],
            content: json_encode(['url' => 'social:bluesky:1:home'], \JSON_THROW_ON_ERROR),
        );

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('validation_error', $this->payload($client)['type']);
    }
}
