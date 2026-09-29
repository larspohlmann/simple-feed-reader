<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Service\Search\SearchEngineCapability;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * phpunit.dist.xml forces MEILISEARCH_* empty, so the dev stack's real engine never receives a test's writes. A
 * failure means that override was removed or weakened, or the suite ran without it: fix it there, never this test.
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
