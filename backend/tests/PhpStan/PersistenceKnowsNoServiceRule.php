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
 * Entities, enums and the ORM extensions sit below the services, so what they share with a service lives with them
 * (docs/architecture.md §8, #1182).
 *
 * @implements Rule<FileNode>
 */
final readonly class PersistenceKnowsNoServiceRule implements Rule
{
    private const array PERSISTENCE_NAMESPACES = ['App\\Entity\\', 'App\\Enum\\', 'App\\Doctrine\\'];

    private const string MOVE_THE_VALUE_DOWN
        = 'Move the shared value to App\\Entity, App\\Enum or App\\Doctrine (docs/architecture.md §8).';

    private const array REMEDIES = ['App\\Service\\' => self::MOVE_THE_VALUE_DOWN];

    private ClassNameReferences $references;

    public function __construct(NodeFinder $finder)
    {
        $this->references = new ClassNameReferences($finder, array_keys(self::REMEDIES));
    }

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        return array_map(
            self::error(...),
            $this->references->forbiddenInFile($node, self::PERSISTENCE_NAMESPACES),
        );
    }

    private static function error(ForbiddenReference $reference): IdentifierRuleError
    {
        return RuleErrorBuilder::message(sprintf(
            'Persistence code must not know a service: %s references %s. %s',
            $reference->inNamespace,
            $reference->name,
            self::REMEDIES[$reference->matchedRule],
        ))
            ->identifier('simpleFeedReader.persistenceKnowsNoService')
            ->line($reference->line)
            ->build();
    }
}
