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
    private const string HTTP_EXCEPTION = 'Symfony\Component\HttpKernel\Exception\\';
    private const string RESPONSE = 'Symfony\Component\HttpFoundation\Response';
    private const string API_PROBLEM = 'App\Http\Problem\ApiProblem';

    protected function getRule(): Rule
    {
        return new DomainKnowsNoHttpRule(new NodeFinder());
    }

    public function testItReportsHttpInDomainCodeButNotInTheHttpLayer(): void
    {
        $this->analyse(
            [__DIR__ . '/data/domain-knows-no-http-fixtures.php'],
            [
                [self::message(self::SERVICE, self::HTTP_EXCEPTION . 'NotFoundHttpException'), 10],
                [self::message(self::SERVICE, self::HTTP_EXCEPTION . 'NotFoundHttpException'), 16],
                [self::message(self::SERVICE_EXCEPTION, self::API_PROBLEM), 27],
                [self::message(self::SERVICE_EXCEPTION, self::RESPONSE), 28],
                [self::message(self::SERVICE_EXCEPTION, self::RESPONSE), 32],
                [self::message(self::SERVICE_EXCEPTION, self::API_PROBLEM), 34],
                [self::message(self::REPOSITORY, self::HTTP_EXCEPTION . 'BadRequestHttpException'), 46],
            ],
        );
    }

    private static function message(string $namespaceName, string $reference): string
    {
        return sprintf(
            'Domain code must not know HTTP: %s references %s. '
            . 'Throw a typed exception and map it in src/Http/Problem (#1160).',
            $namespaceName,
            $reference,
        );
    }
}
