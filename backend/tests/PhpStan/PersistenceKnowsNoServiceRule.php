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
 * (docs/architecture.md §8, #1182). Repositories may name Service values and the interfaces they implement, nothing
 * else.
 *
 * @implements Rule<FileNode>
 */
final readonly class PersistenceKnowsNoServiceRule implements Rule
{
    private const array PERSISTENCE_NAMESPACES = ['App\\Entity\\', 'App\\Enum\\', 'App\\Doctrine\\'];

    private const string MOVE_THE_VALUE_DOWN
        = 'Move the shared value to App\\Entity, App\\Enum or App\\Doctrine (docs/architecture.md §8).';

    private const array REMEDIES = ['App\\Service\\' => self::MOVE_THE_VALUE_DOWN];

    private const string REPOSITORY_NAMESPACE = 'App\\Repository\\';

    /** Service roles a query may speak: domain values, static helpers, typed exceptions (and any …Interface). */
    private const array REPOSITORY_MAY_NAME = ['\\Model\\', '\\Support\\', '\\Exception\\'];

    /** The restore's bulk insert reads the backup line format and hashes URLs itself: 14x the ORM (spec appendix). */
    private const array REPOSITORY_ALLOW_LIST = ['App\\Repository\\EntryBatchInserter'];

    private const string NAME_ONLY_VALUES = 'Hand the repository a model, a helper\'s result or an interface it '
        . 'implements (docs/architecture.md §8).';

    private ClassNameReferences $references;

    /** @param list<string> $repositoryAllowList class names; overridable only for the rule's own test */
    public function __construct(NodeFinder $finder, private array $repositoryAllowList = self::REPOSITORY_ALLOW_LIST)
    {
        $this->references = new ClassNameReferences($finder, array_keys(self::REMEDIES));
    }

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        return [
            ...array_map(
                self::error(...),
                $this->references->forbiddenInFile($node, self::PERSISTENCE_NAMESPACES),
            ),
            ...array_map(
                self::repositoryError(...),
                $this->forbiddenInRepository($node, basename($scope->getFile(), '.php')),
            ),
        ];
    }

    /** @return list<ForbiddenReference> */
    private function forbiddenInRepository(FileNode $file, string $fileClassName): array
    {
        $forbidden = [];
        foreach ($this->references->forbiddenInFile($file, [self::REPOSITORY_NAMESPACE]) as $reference) {
            $className = $reference->inNamespace . '\\' . $fileClassName;
            if (!\in_array($className, $this->repositoryAllowList, true) && !self::isServiceValue($reference->name)) {
                $forbidden[] = $reference;
            }
        }

        return $forbidden;
    }

    private static function isServiceValue(string $name): bool
    {
        return str_ends_with($name, 'Interface')
            || array_any(self::REPOSITORY_MAY_NAME, static fn (string $segment): bool => str_contains($name, $segment));
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

    private static function repositoryError(ForbiddenReference $reference): IdentifierRuleError
    {
        return RuleErrorBuilder::message(sprintf(
            'Repositories name only Service values: %s references %s. %s',
            $reference->inNamespace,
            $reference->name,
            self::NAME_ONLY_VALUES,
        ))
            ->identifier('simpleFeedReader.persistenceKnowsNoService')
            ->line($reference->line)
            ->build();
    }
}
