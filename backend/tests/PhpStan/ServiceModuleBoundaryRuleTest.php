<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\NodeFinder;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<ServiceModuleBoundaryRule> */
final class ServiceModuleBoundaryRuleTest extends RuleTestCase
{
    private const string READER = 'App\Service\Reader\Fixtures';
    private const string RECOMMENDATION = 'App\Service\Recommendation\Run\Fixtures';
    private const string READING = 'App\Service\Reading\Fixtures\TimeZone';
    private const string VIEWER_TIME_ZONE = 'App\Service\Recommendation\Feed\Model\ViewerTimeZoneModel';
    private const string SEARCH_TERMS = 'App\Service\Search\SearchTerms';
    private const string EXTRACTOR = 'App\Service\Reader\ArticleExtractor';
    private const string SEARCH_IN_READER_REMEDY
        = 'Reading state, and the search it needs, lives in Service/Reading (#1163).';
    private const string READER_IN_RECOMMENDATION_REMEDY
        = 'Mark-read goes through Service/Reading, not the article extractor (#1163).';
    private const string RECOMMENDATION_IN_READING_REMEDY
        = 'Reading sits below recommendations; the viewer time zone lives in Service/Clock (#1169).';

    protected function getRule(): Rule
    {
        return new ServiceModuleBoundaryRule(new NodeFinder());
    }

    public function testItReportsOnlyTheDependenciesRemovedOnPurpose(): void
    {
        $this->analyse(
            [__DIR__ . '/data/service-module-boundary-fixtures.php'],
            [
                [self::message(self::READER, self::SEARCH_TERMS, self::SEARCH_IN_READER_REMEDY), 9],
                [self::message(self::READER, self::SEARCH_TERMS, self::SEARCH_IN_READER_REMEDY), 13],
                [self::message(self::RECOMMENDATION, self::EXTRACTOR, self::READER_IN_RECOMMENDATION_REMEDY), 24],
                [self::message(self::READING, self::VIEWER_TIME_ZONE, self::RECOMMENDATION_IN_READING_REMEDY), 67],
            ],
        );
    }

    private static function message(string $namespaceName, string $reference, string $remedy): string
    {
        return sprintf('Service module boundary: %s references %s. %s', $namespaceName, $reference, $remedy);
    }
}
