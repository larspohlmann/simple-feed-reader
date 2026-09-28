<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Service modules depend on each other without a cycle (docs/architecture.md §9). Each cycle is reported once, where
 * its last module names its first, the first being the earliest module by name that lies on a cycle.
 *
 * @implements Rule<CollectedDataNode>
 */
final readonly class ServiceModuleCycleRule implements Rule
{
    private const string MESSAGE = 'Service modules must not depend on each other in a cycle: %s. '
        . 'Move the class that closes it into the module that owns it, '
        . 'or let the lower module own an interface the higher one implements (docs/architecture.md §9).';

    public function getNodeType(): string
    {
        return CollectedDataNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $graph = ServiceModuleGraph::fromCollected($node->get(ServiceModuleDependencyCollector::class));

        return array_map(self::error(...), $graph->cycles());
    }

    private static function error(ServiceModuleCycle $cycle): IdentifierRuleError
    {
        return RuleErrorBuilder::message(sprintf(self::MESSAGE, implode(' -> ', $cycle->modules)))
            ->identifier('simpleFeedReader.serviceModuleCycle')
            ->file($cycle->closedInFile)
            ->line($cycle->closedOnLine)
            ->build();
    }
}
