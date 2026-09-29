<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\FileNode;

/**
 * [its module, the module it names, line] for every App\Service name a Service file mentions. A module is the first
 * segment under App\Service; a class loose in the root is a module of its own, named by its file (PSR-4).
 *
 * @implements Collector<FileNode, list<array{string, string, int}>>
 */
final readonly class ServiceModuleDependencyCollector implements Collector
{
    private const string SERVICE_NAMESPACE = 'App\\Service\\';

    private ClassNameReferences $references;

    public function __construct(NodeFinder $finder)
    {
        $this->references = new ClassNameReferences($finder, [self::SERVICE_NAMESPACE]);
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
        foreach ($this->references->forbiddenInFile($node, [self::SERVICE_NAMESPACE]) as $reference) {
            $module = self::moduleOf($reference->inNamespace . '\\' . $fileClassName);
            $dependency = self::moduleOf($reference->name);
            if ('' !== $dependency && $dependency !== $module) {
                $dependencies[] = [$module, $dependency, $reference->line];
            }
        }

        return [] === $dependencies ? null : $dependencies;
    }

    private static function moduleOf(string $className): string
    {
        return explode('\\', substr($className, \strlen(self::SERVICE_NAMESPACE)))[0];
    }
}
