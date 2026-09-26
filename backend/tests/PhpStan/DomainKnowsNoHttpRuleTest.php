<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\NodeFinder;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<DomainKnowsNoHttpRule> */
final class DomainKnowsNoHttpRuleTest extends RuleTestCase
{
    private const string SERVICE = 'App\Service\Fixtures';
    private const string SERVICE_EXCEPTION = 'App\Service\Fixtures\Exception';
    private const string REPOSITORY = 'App\Repository\Fixtures';
    private const string PAGINATION = 'App\Pagination\Fixtures';
    private const string FOUNDATION = 'Symfony\Component\HttpFoundation\\';
    private const string HTTP_KERNEL = 'Symfony\Component\HttpKernel\Exception\\';
    private const string ACCESS_DENIED = 'Symfony\Component\Security\Core\Exception\AccessDeniedException';
    private const string API_PROBLEM = 'App\Http\Problem\ApiProblem';
    private const string FEED_JSON = 'App\Http\RecommendationFeedJson';

    protected function getRule(): Rule
    {
        return new DomainKnowsNoHttpRule(new NodeFinder());
    }

    public function testItReportsHttpInDomainCodeButNotInTheHttpLayer(): void
    {
        $this->analyse(
            [__DIR__ . '/data/domain-knows-no-http-fixtures.php'],
            [
                [self::message(self::SERVICE, self::FEED_JSON), 9],
                [self::message(self::SERVICE, self::FOUNDATION . 'Request'), 10],
                [self::message(self::SERVICE, self::FOUNDATION . 'Response'), 11],
                [self::message(self::SERVICE, self::HTTP_KERNEL . 'NotFoundHttpException'), 12],
                [self::message(self::SERVICE, self::HTTP_KERNEL . 'NotFoundHttpException'), 18],
                [self::message(self::SERVICE, self::FOUNDATION . 'Response'), 23],
                [self::message(self::SERVICE, self::FOUNDATION . 'Request'), 26],
                [self::message(self::SERVICE, self::FEED_JSON), 33],
                [self::message(self::SERVICE, self::FEED_JSON), 38],
                [self::message(self::SERVICE, self::FOUNDATION . 'Response'), 43],
                [self::message(self::SERVICE_EXCEPTION, self::API_PROBLEM), 49],
                [self::message(self::SERVICE_EXCEPTION, self::FOUNDATION . 'Exception\BadRequestException'), 50],
                [self::message(self::SERVICE_EXCEPTION, self::ACCESS_DENIED), 51],
                [self::message(self::SERVICE_EXCEPTION, self::API_PROBLEM), 55],
                [self::message(self::SERVICE_EXCEPTION, self::FOUNDATION . 'Exception\BadRequestException'), 60],
                [self::message(self::SERVICE_EXCEPTION, self::FOUNDATION . 'Exception\BadRequestException'), 62],
                [self::message(self::SERVICE_EXCEPTION, self::ACCESS_DENIED), 65],
                [self::message(self::SERVICE_EXCEPTION, self::ACCESS_DENIED), 67],
                [self::message(self::REPOSITORY, self::HTTP_KERNEL . 'BadRequestHttpException'), 77],
                [self::message(self::REPOSITORY, 'App\Http\EntryPage'), 82],
                [self::message(self::PAGINATION, self::FOUNDATION . 'Cookie'), 88],
                [self::message(self::PAGINATION, self::FOUNDATION . 'Cookie'), 92],
                [self::message(self::PAGINATION, self::FOUNDATION . 'Cookie'), 94],
            ],
        );
    }

    private static function message(string $namespaceName, string $reference): string
    {
        return sprintf(
            'Domain code must not know HTTP: %s references %s. '
            . 'Return a typed value or throw a typed exception, and let src/Http shape it (#1158).',
            $namespaceName,
            $reference,
        );
    }
}
