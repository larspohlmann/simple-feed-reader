<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\FileNode;

/**
 * [its module, the module it names, line] for every App\Service name a Service file mentions. ServiceModules
 * names the module.
 *
 * @implements Collector<FileNode, list<array{string, string, int}>>
 */
final readonly class ServiceModuleDependencyCollector implements Collector
{
    private ClassNameReferences $references;

    public function __construct(NodeFinder $finder, private ServiceModules $modules)
    {
        $this->references = new ClassNameReferences($finder, [ServiceModules::SERVICE_NAMESPACE]);
    }

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    /**
     * @param FileNode $node
     *
     * @return list<array{string, string, int}>|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        $fileClassName = basename($scope->getFile(), '.php');
        $dependencies = [];
        foreach ($this->references->forbiddenInFile($node, [ServiceModules::SERVICE_NAMESPACE]) as $reference) {
            $module = $this->modules->of($reference->inNamespace . '\\' . $fileClassName);
            $dependency = $this->modules->of($reference->name);
            if ('' !== $dependency && $dependency !== $module) {
                $dependencies[] = [$module, $dependency, $reference->line];
            }
        }

        return [] === $dependencies ? null : $dependencies;
    }
}
