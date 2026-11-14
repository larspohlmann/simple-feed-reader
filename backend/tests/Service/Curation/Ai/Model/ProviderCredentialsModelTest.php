<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai\Model;

use App\Service\Ai\Model\ProviderCredentialsModel;
use PHPUnit\Framework\TestCase;

/**
 * authorizationHeaders() is the one place the "empty key means no header"
 * rule lives — both outbound callers merge its result rather than deciding
 * this themselves.
 */
final class ProviderCredentialsModelTest extends TestCase
{
    public function testAnEmptyKeyProducesNoAuthorizationHeader(): void
    {
        $credentials = ProviderCredentialsModel::fromStoredConfiguration('https://api.example.test/v1', '');

        self::assertSame([], $credentials->authorizationHeaders());
    }

    public function testAPresentKeyProducesTheBearerHeader(): void
    {
        $credentials = ProviderCredentialsModel::fromStoredConfiguration('https://api.example.test/v1', 'sk-test');

        self::assertSame(['Authorization' => 'Bearer sk-test'], $credentials->authorizationHeaders());
    }

    public function testTheKeyIsRedactedWhereverATextRepeatsIt(): void
    {
        $credentials = ProviderCredentialsModel::fromStoredConfiguration('https://llm.example.test/v1', 'sk-secret-1');

        self::assertSame(
            'Invalid key [redacted] (got [redacted]).',
            $credentials->withoutApiKey('Invalid key sk-secret-1 (got sk-secret-1).'),
        );
    }

    public function testAKeylessEndpointLeavesTheTextAlone(): void
    {
        $credentials = ProviderCredentialsModel::fromStoredConfiguration('http://localhost:1234/v1', '');

        self::assertSame('No models loaded.', $credentials->withoutApiKey('No models loaded.'));
    }
}
