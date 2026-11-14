<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\Node;
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
        'App\\Service\\Content\\Reader\\' => [
            'App\\Service\\Library\\Search\\' => 'Reading state, and the search it needs, lives in '
                . 'Service/Library/Reading (#1163).',
        ],
        'App\\Service\\Curation\\Recommendation\\' => [
            'App\\Service\\Content\\Reader\\' => 'Mark-read goes through Service/Library/Reading, '
                . 'not the article extractor (#1163).',
        ],
        'App\\Service\\Library\\Reading\\' => [
            'App\\Service\\Curation\\Recommendation\\' => 'Reading sits below recommendations; '
                . 'the viewer time zone lives in Service/Foundation/Clock (#1169).',
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
            foreach ($references->forbiddenInFile($node, [$module]) as $reference) {
                $errors[] = self::error($module, $reference);
            }
        }

        return $errors;
    }

    private static function error(string $module, ForbiddenReference $reference): IdentifierRuleError
    {
        return RuleErrorBuilder::message(sprintf(
            'Service module boundary: %s references %s. %s',
            $reference->inNamespace,
            $reference->name,
            self::REMEDIES[$module][$reference->matchedRule],
        ))
            ->identifier('simpleFeedReader.serviceModuleBoundary')
            ->line($reference->line)
            ->build();
    }
}
