<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Module dependencies removed on purpose that close no cycle, so ServiceModuleCycleRule would let them back in
 * (docs/architecture.md §9).
 *
 * @implements Rule<FileNode>
 */
final readonly class ServiceModuleBoundaryRule implements Rule
{
    private const array REMEDIES = [
        'App\\Service\\Reader\\' => [
            'App\\Service\\Search\\' => 'Reading state, and the search it needs, lives in Service/Reading (#1163).',
        ],
        'App\\Service\\Recommendation\\' => [
            'App\\Service\\Reader\\' => 'Mark-read goes through Service/Reading, not the article extractor (#1163).',
        ],
    ];

    /** @var array<string, ClassNameReferences> */
    private array $referencesByModule;

    public function __construct(NodeFinder $finder)
    {
        $referencesByModule = [];
        foreach (self::REMEDIES as $module => $remedies) {
            $referencesByModule[$module] = new ClassNameReferences($finder, array_keys($remedies));
        }
        $this->referencesByModule = $referencesByModule;
    }

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];
        foreach ($this->referencesByModule as $module => $references) {
            foreach ($references->namespacesIn($node) as $namespace) {
                $errors = [...$errors, ...self::errorsIn($module, $namespace, $references)];
            }
        }

        return $errors;
    }

    /** @return list<IdentifierRuleError> */
    private static function errorsIn(string $module, Namespace_ $namespace, ClassNameReferences $references): array
    {
        $namespaceName = $namespace->name?->toString() ?? '';
        if (!ClassNameReferences::isInAnyOf($namespaceName, [$module])) {
            return [];
        }

        return array_map(
            static fn (ForbiddenReference $reference): IdentifierRuleError
                => self::error($module, $namespaceName, $reference),
            $references->forbiddenIn($namespace),
        );
    }

    private static function error(
        string $module,
        string $namespaceName,
        ForbiddenReference $reference,
    ): IdentifierRuleError {
        return RuleErrorBuilder::message(sprintf(
            'Service module boundary: %s references %s. %s',
            $namespaceName,
            $reference->name,
            self::REMEDIES[$module][$reference->matchedRule],
        ))
            ->identifier('simpleFeedReader.serviceModuleBoundary')
            ->line($reference->line)
            ->build();
    }
}
