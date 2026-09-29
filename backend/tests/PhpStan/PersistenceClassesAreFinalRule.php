<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Entities, embeddables and repositories are final: native lazy objects need no proxy subclass, and a test doubles
 * the interface a consumer owns instead (#1169).
 *
 * @implements Rule<InClassNode>
 */
final readonly class PersistenceClassesAreFinalRule implements Rule
{
    private const array PERSISTENCE_NAMESPACES = ['App\\Entity\\', 'App\\Repository\\'];

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $class = $node->getClassReflection();
        if (!$class->isClass() || $class->isAnonymous() || $class->isFinalByKeyword()) {
            return [];
        }
        if (!ClassNameReferences::isInAnyOf($class->getName(), self::PERSISTENCE_NAMESPACES)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Persistence classes are final: %s is not. '
                . 'A test doubles the interface its consumer owns, never the persistence class (#1169).',
                $class->getName(),
            ))
                ->identifier('simpleFeedReader.persistenceClassesAreFinal')
                ->line($node->getOriginalNode()->getStartLine())
                ->build(),
        ];
    }
}
