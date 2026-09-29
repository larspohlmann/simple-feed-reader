<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPUnit\Framework\TestCase;

/**
 * One spelling for PHPUnit's invocation matchers: PhpStorm's EA inspection warns on `self::once()` and
 * `self::never()`, and a warning blocks the lint gate (#1169).
 *
 * @implements Rule<StaticCall>
 */
final readonly class InvocationMatchersOnThisRule implements Rule
{
    private const array MATCHERS = ['any', 'atLeast', 'atLeastOnce', 'atMost', 'exactly', 'never', 'once'];

    private const array STATIC_RECEIVERS = ['self', 'static'];

    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->class instanceof Name || !$node->name instanceof Identifier || !self::isInATestCase($scope)) {
            return [];
        }

        $receiver = $node->class->toLowerString();
        $matcher = $node->name->toString();
        if (!\in_array($receiver, self::STATIC_RECEIVERS, true) || !\in_array($matcher, self::MATCHERS, true)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Call invocation matchers on $this: %s::%s() is $this->%s() (#1169).',
                $receiver,
                $matcher,
                $matcher,
            ))
                ->identifier('simpleFeedReader.invocationMatchersOnThis')
                ->build(),
        ];
    }

    private static function isInATestCase(Scope $scope): bool
    {
        $class = $scope->getClassReflection();

        return $class !== null && $class->isSubclassOf(TestCase::class);
    }
}
