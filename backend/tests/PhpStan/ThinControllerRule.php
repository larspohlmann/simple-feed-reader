<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * CLAUDE.md's thin-controller rule for method shapes: no private or protected helper outside {@see self::ALLOW_LIST},
 * and no ObjectManager or ManagerRegistry parameter on any controller method (#1157).
 *
 * @implements Rule<InClassMethodNode>
 */
final readonly class ThinControllerRule implements Rule
{
    private const string CONTROLLER_NAMESPACE_PREFIX = 'App\\Controller\\';

    private const array PERSISTENCE_TYPES = [ObjectManager::class, ManagerRegistry::class];

    /**
     * Keyed `Fully\Qualified\Class::method`; only ever shrinks, and every entry carries a comment justifying it.
     *
     * @var array<string, string>
     */
    private const array ALLOW_LIST = [];

    /** @param array<string, string> $allowList overridable only for the rule's own test */
    public function __construct(private array $allowList = self::ALLOW_LIST)
    {
    }

    public function getNodeType(): string
    {
        return InClassMethodNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $className = $node->getClassReflection()->getName();
        if (!str_starts_with($className, self::CONTROLLER_NAMESPACE_PREFIX)) {
            return [];
        }

        $method = $node->getMethodReflection();

        return [
            ...$this->hiddenHelperErrors($className, $method),
            ...self::persistenceParameterErrors($className, $method),
        ];
    }

    /** @return list<IdentifierRuleError> */
    private function hiddenHelperErrors(string $className, ExtendedMethodReflection $method): array
    {
        if ($method->isPublic()) {
            return [];
        }

        $qualifiedName = $className . '::' . $method->getName();
        if (array_key_exists($qualifiedName, $this->allowList)) {
            return [];
        }

        $visibility = $method->isPrivate() ? 'private' : 'protected';

        return [
            RuleErrorBuilder::message(sprintf(
                'Controller %s has a %s method %s(). An action reads the request, delegates, and returns a '
                . 'response; move querying, response assembly, validation, entity mutation and security '
                . 'decisions into a service, a repository, or an src/Http/*Json.php mapper. See the '
                . '"Controllers hold no private methods that carry responsibility" rule in CLAUDE.md. If this '
                . 'is a trivial single-expression helper used by exactly one action in exactly one controller, '
                . 'add %s to ThinControllerRule::ALLOW_LIST with a comment that says why.',
                $className,
                $visibility,
                $method->getName(),
                $qualifiedName,
            ))
                ->identifier('simpleFeedReader.thinController')
                ->build(),
        ];
    }

    /** @return list<IdentifierRuleError> */
    private static function persistenceParameterErrors(string $className, ExtendedMethodReflection $method): array
    {
        $errors = [];
        foreach ($method->getOnlyVariant()->getParameters() as $parameter) {
            if (!self::isPersistence($parameter->getType())) {
                continue;
            }
            $errors[] = RuleErrorBuilder::message(sprintf(
                'Controller %s receives persistence through %s($%s). An action reads the request, delegates, '
                . 'and returns a response; persisting, flushing and removing entities belong in a service '
                . 'under src/Service (#1157).',
                $className,
                $method->getName(),
                $parameter->getName(),
            ))
                ->identifier('simpleFeedReader.thinController.persistence')
                ->build();
        }

        return $errors;
    }

    private static function isPersistence(Type $type): bool
    {
        $nonNullable = TypeCombinator::removeNull($type);
        foreach ($nonNullable->getObjectClassNames() as $className) {
            if (self::isPersistenceClass($className)) {
                return true;
            }
        }

        return false;
    }

    private static function isPersistenceClass(string $className): bool
    {
        $classType = new ObjectType($className);
        foreach (self::PERSISTENCE_TYPES as $persistenceClass) {
            if ((new ObjectType($persistenceClass))->isSuperTypeOf($classType)->yes()) {
                return true;
            }
        }

        return false;
    }
}
