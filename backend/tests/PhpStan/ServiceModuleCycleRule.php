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
 * Service modules depend on each other without a cycle (docs/architecture.md §9). In name order, each module that no
 * reported cycle covers yet reports the shortest cycle through it, at ServiceModuleCycle::reportedSite().
 *
 * @implements Rule<CollectedDataNode>
 */
final readonly class ServiceModuleCycleRule implements Rule
{
    private const string MESSAGE = 'Service modules must not depend on each other in a cycle: %s. '
        . 'Break it at the reported dependency: move the class it names into the module that owns it, '
        . 'or let the lower module own an interface the higher one implements (docs/architecture.md §9).';

    public function __construct(private ServiceModules $modules)
    {
    }

    public function getNodeType(): string
    {
        return CollectedDataNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $graph = ServiceModuleGraph::fromCollected($node->get(ServiceModuleDependencyCollector::class));

        return array_map($this->error(...), $graph->cycles());
    }

    private function error(ServiceModuleCycle $cycle): IdentifierRuleError
    {
        $site = $cycle->reportedSite($this->modules);

        return RuleErrorBuilder::message(sprintf(self::MESSAGE, self::path($cycle)))
            ->identifier('simpleFeedReader.serviceModuleCycle')
            ->file($site->file)
            ->line($site->line)
            ->build();
    }

    /** `A -> B (A/X.php:3) -> A (B/Y.php:9)`: each hop names the first site where the module before it names it. */
    private static function path(ServiceModuleCycle $cycle): string
    {
        $path = $cycle->modules[0];
        foreach ($cycle->sites as $hop => $site) {
            $path .= sprintf(' -> %s (%s:%d)', $cycle->modules[$hop + 1], self::shortPath($site->file), $site->line);
        }

        return $path;
    }

    /** Below src/Service for a real class, the file name for a rule fixture. */
    private static function shortPath(string $file): string
    {
        $serviceRoot = strrpos($file, '/src/Service/');

        return false === $serviceRoot ? basename($file) : substr($file, $serviceRoot + \strlen('/src/Service/'));
    }
}
