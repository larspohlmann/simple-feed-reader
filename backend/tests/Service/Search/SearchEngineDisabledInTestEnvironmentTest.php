<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Service\Search\SearchEngineCapability;
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
 * SearchEngineCapability (#432 "Simplify") is now the ONE place that reads
 * MEILISEARCH_URL/MEILISEARCH_KEY — MeilisearchIndex, EntrySearchWithFallback
 * and SearchReindexCommand all resolve "is an engine configured?" through it,
 * so asserting its container-wired instance is enough to cover every
 * consumer; there is no second copy of the env read left to check
 * separately.
 *
 * This asserts the actual wiring, not just the raw environment, so it fails
 * for the reason that matters: a real engine reachable from a test. A real
 * MEILISEARCH_URL/MEILISEARCH_KEY set by a compose file, a CI job or a
 * developer's shell CANNOT make this test fail — phpunit.dist.xml's
 * force="true" overrides overwrite $_ENV, $_SERVER and getenv() before the
 * kernel boots, which is exactly what defeats a value from any of those
 * sources. If this test ever fails, the override in phpunit.dist.xml has
 * been removed or weakened (the <env>/<server> lines deleted, force flipped
 * to false), or something is running the suite without phpunit.dist.xml at
 * all. Check there first. Do not delete or weaken this test to make it pass.
 */
final class SearchEngineDisabledInTestEnvironmentTest extends KernelTestCase
{
    public function testTheCapabilitySeesNoEngineConfigured(): void
    {
        self::bootKernel();

        $capability = self::getContainer()->get(SearchEngineCapability::class);

        self::assertFalse(
            $capability->isConfigured(),
            'SearchEngineCapability resolved a configured engine inside the test environment — '
            . 'every search test would silently hit a live Meilisearch instead of the database '
            . 'fallback the tests assume.',
        );
    }
}
