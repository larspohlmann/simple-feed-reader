<?php

declare(strict_types=1);

namespace App\Tests\E2e;

use App\Tests\E2e\Support\E2eTestCase;

/**
 * The public providers list must answer without credentials — it is how the SPA
 * decides which OAuth buttons to render. Empty is a valid answer on a stack with
 * no configured providers; the contract is a 200 with a `providers` array.
 */
final class OAuthProvidersE2eTest extends E2eTestCase
{
    public function testProvidersListIsPubliclyReadable(): void
    {
        $response = $this->getJson('/api/auth/oauth/providers');

        self::assertSame(200, $response->getStatusCode());

        $body = $response->toArray();
        self::assertArrayHasKey('providers', $body);
        self::assertIsArray($body['providers']);
    }
}
