<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

final class SavedSearchSlugRoutingTest extends ApiTestCase
{
    /** @return array<string, string> */
    private function authHeaderFor(User $user): array
    {
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);

        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user),
            'CONTENT_TYPE' => 'application/json',
        ];
    }

    public function testCreateReturnsAnIdPrefixedSlug(): void
    {
        $client = self::createClient();
        $headers = $this->authHeaderFor($this->factory()->create('slug-create@example.com'));

        $client->request(
            'POST',
            '/api/saved-searches',
            server: $headers,
            content: json_encode([
                'term' => 'Climate News',
                'wholeWord' => false,
                'phrase' => false,
            ], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(201);
        $created = $this->payload($client);
        self::assertIsArray($created['savedSearch']);
        $savedId = $created['savedSearch']['id'];
        self::assertIsInt($savedId);
        self::assertSame($savedId . '-climate-news', $created['savedSearch']['slug']);
    }
}
