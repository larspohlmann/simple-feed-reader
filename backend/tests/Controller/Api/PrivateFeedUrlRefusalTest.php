<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Tests\Support\ApiTestCase;
use Symfony\Component\HttpFoundation\Response;

final class PrivateFeedUrlRefusalTest extends ApiTestCase
{
    public function testASocialUrlCannotBeSubscribedToByHand(): void
    {
        $client = static::createClient();
        $user = $this->factory()->create('private-url@example.test');

        $client->request(
            'POST',
            '/api/subscriptions',
            server: $this->bearerFor($user) + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['url' => 'social:bluesky:1:home'], \JSON_THROW_ON_ERROR),
        );

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('validation_error', $this->payload($client)['type']);
    }
}
