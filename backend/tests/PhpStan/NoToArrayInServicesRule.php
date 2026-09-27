<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Services build no response arrays (#1182): a src/Http/*Json mapper shapes the wire, and a value that serialises for
 * a store names the method after it (toLogContext(), toCacheEntry()).
 *
 * @implements Rule<ClassMethod>
 */
final readonly class NoToArrayInServicesRule implements Rule
{
    private const string SERVICE_NAMESPACE = 'app\\service\\';

    private const array RESPONSE_SHAPERS = ['toarray', 'jsonserialize'];

    public function getNodeType(): string
    {
        return ClassMethod::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $namespaceName = $scope->getNamespace() ?? '';
        $method = $node->name->toString();
        if (!self::isService($namespaceName) || !\in_array(strtolower($method), self::RESPONSE_SHAPERS, true)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Services build no response arrays: %s() in %s. '
                . 'Map the value in a src/Http/*Json mapper, or name a store\'s serialiser after the store (#1182).',
                $method,
                $namespaceName,
            ))
                ->identifier('simpleFeedReader.noToArrayInServices')
                ->build(),
        ];
    }

    private static function isService(string $namespaceName): bool
    {
        return str_starts_with(strtolower($namespaceName) . '\\', self::SERVICE_NAMESPACE);
    }
}
