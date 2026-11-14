<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai\Support;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Service\Ai\Support\ProviderHost;
use App\Tests\Support\AiProviderSettingsFactory;
use PHPUnit\Framework\TestCase;

final class ProviderHostTest extends TestCase
{
    public function testItIsTheHostOfTheConnectionsBaseUrl(): void
    {
        self::assertSame('api.example.test', ProviderHost::of($this->connection('https://api.example.test/v1')));
    }

    public function testAHostOfZeroSurvives(): void
    {
        self::assertSame('0', ProviderHost::of($this->connection('http://0:8080/v1')));
    }

    public function testAHostlessUrlHasNoHost(): void
    {
        self::assertNull(ProviderHost::of($this->connection('/v1')));
    }

    public function testNoConnectionHasNoHost(): void
    {
        self::assertNull(ProviderHost::of(null));
    }

    private function connection(string $baseUrl): AiProviderSettings
    {
        return AiProviderSettingsFactory::build(
            new User('provider-host@example.test', new \DateTimeImmutable('2026-10-03 09:00:00')),
            baseUrl: $baseUrl,
        );
    }
}
