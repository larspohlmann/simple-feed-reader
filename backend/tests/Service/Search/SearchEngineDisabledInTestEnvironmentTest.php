<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Service\Search\EntrySearchWithFallback;
use App\Service\Search\Index\MeilisearchIndex;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Guards the fix for #432 "Fix round 1". The dev Docker stack sets a REAL
 * MEILISEARCH_URL/MEILISEARCH_KEY on the php/worker containers' environment
 * (docker-compose.yml), unconditionally — not scoped to APP_ENV=dev — and a
 * real environment variable beats an empty value from .env/.env.test in
 * Symfony's precedence. Without phpunit.dist.xml forcing both variables back
 * to empty for every test run, `docker compose exec php vendor/bin/phpunit`
 * silently talked to, and WROTE INTO, the developer's live Meilisearch index
 * instead of taking the database-fallback path every search test assumes —
 * corrupting shared state outside the database that no TEST_TOKEN isolation
 * covers (it isolates the database and the cache pools, not an external
 * service), and giving the same test a different verdict depending on which
 * of the two database legs ran it.
 *
 * This asserts the actual wiring, not just the raw environment, so it fails
 * for the reason that matters: a real engine reachable from a test. If it
 * ever fails, something — a compose file, a CI job, a developer's shell —
 * reintroduced a real MEILISEARCH_URL/MEILISEARCH_KEY into the test process.
 * Fix that source. Do not delete or weaken this test to make it pass.
 */
final class SearchEngineDisabledInTestEnvironmentTest extends KernelTestCase
{
    public function testTheFallbackDeciderSeesNoEngineConfigured(): void
    {
        self::bootKernel();

        $fallback = self::getContainer()->get(EntrySearchWithFallback::class);
        $engineUrl = (new \ReflectionProperty(EntrySearchWithFallback::class, 'engineUrl'))->getValue($fallback);

        self::assertSame(
            '',
            $engineUrl,
            'EntrySearchWithFallback was wired with a real engine URL inside the test '
            . 'environment — every search test would silently hit a live Meilisearch '
            . 'instead of the database fallback the tests assume.',
        );
    }

    public function testTheIndexAdapterSeesNoEngineConfigured(): void
    {
        self::bootKernel();

        $index = self::getContainer()->get(MeilisearchIndex::class);
        $baseUrl = (new \ReflectionProperty(MeilisearchIndex::class, 'baseUrl'))->getValue($index);
        $apiKey = (new \ReflectionProperty(MeilisearchIndex::class, 'apiKey'))->getValue($index);

        self::assertSame(
            '',
            $baseUrl,
            'MeilisearchIndex was wired with a real base URL inside the test environment.',
        );
        self::assertSame(
            '',
            $apiKey,
            'MeilisearchIndex was wired with a real API key inside the test environment.',
        );
    }
}
