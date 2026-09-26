<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use Doctrine\ORM\Mapping\Embeddable;
use Doctrine\ORM\Mapping\Entity;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * What the ControllerMutatesNoEntity rules share (#1157): an entity is a class Doctrine maps, and a controller
 * neither builds one, directly or through a static method that returns one, nor calls a non-query method on one.
 */
final readonly class ControllerEntityUse
{
    private const string CONTROLLER_NAMESPACE_PREFIX = 'App\\Controller\\';
    private const array MAPPING_ATTRIBUTES = [Entity::class, Embeddable::class];
    private const string QUERY_METHOD = '/^(get|is|has)[A-Z]/';
    private const string ID_READ = 'requireId';
    private const string ADVICE = 'An action reads the request, delegates, and returns a response; construct and '
        . 'change entities in a service under src/Service, which also persists them (#1157).';

    public function isInController(Scope $scope): bool
    {
        $classReflection = $scope->getClassReflection();

        return null !== $classReflection
            && str_starts_with($classReflection->getName(), self::CONTROLLER_NAMESPACE_PREFIX);
    }

    /** @return list<IdentifierRuleError> */
    public function constructionErrors(Node $class, Scope $scope): array
    {
        if (!$class instanceof Name) {
            return [];
        }

        $mapped = self::mappedClassesOf($scope->resolveTypeByName($class));
        if ([] === $mapped) {
            return [];
        }

        return [self::error(sprintf('A controller constructs the entity %s. %s', $mapped[0], self::ADVICE))];
    }

    /** @return list<IdentifierRuleError> */
    public function staticCallErrors(Node $class, Node $method, Scope $scope): array
    {
        if (!$class instanceof Name || !$method instanceof Identifier) {
            return [];
        }

        $classType = $scope->resolveTypeByName($class);
        $mapped = self::mappedClassesOf($classType);
        if ([] === $mapped || !self::returnsMappedClass(self::returnTypeOf($scope, $classType, $method->name))) {
            return [];
        }

        return [self::error(sprintf(
            'A controller calls %s::%s(), a static method that returns an entity: a disguised construction. %s',
            $mapped[0],
            $method->name,
            self::ADVICE,
        ))];
    }

    /** @return list<IdentifierRuleError> */
    public function methodCallErrors(Expr $receiver, Node $method, Scope $scope): array
    {
        if (!$method instanceof Identifier || self::isQuery($method->name)) {
            return [];
        }

        $receiverType = $scope->getType($receiver);
        $mapped = self::mappedClassesOf($receiverType);
        if ([] === $mapped) {
            return [];
        }

        return [self::error(sprintf(
            'A controller calls %s::%s(), which changes an entity. %s',
            self::declaringClassName($scope, $receiverType, $method->name) ?? $mapped[0],
            $method->name,
            self::ADVICE,
        ))];
    }

    private static function declaringClassName(Scope $scope, Type $receiverType, string $methodName): ?string
    {
        return $scope->getMethodReflection($receiverType, $methodName)?->getDeclaringClass()->getName();
    }

    private static function isQuery(string $methodName): bool
    {
        return self::ID_READ === $methodName || 1 === preg_match(self::QUERY_METHOD, $methodName);
    }

    private static function returnTypeOf(Scope $scope, Type $classType, string $methodName): ?Type
    {
        $variants = $scope->getMethodReflection($classType, $methodName)?->getVariants() ?? [];
        if ([] === $variants) {
            return null;
        }

        return TypeCombinator::union(...array_map(
            static fn (ParametersAcceptor $variant): Type => $variant->getReturnType(),
            $variants,
        ));
    }

    private static function returnsMappedClass(?Type $returnType): bool
    {
        if (null === $returnType) {
            return false;
        }

        $returned = TypeCombinator::removeNull($returnType);

        return [] !== self::mappedClassesOf($returned)
            || [] !== self::mappedClassesOf($returned->getIterableValueType());
    }

    /** @return list<string> */
    private static function mappedClassesOf(Type $type): array
    {
        $mapped = array_filter($type->getObjectClassReflections(), self::isMapped(...));

        return array_values(array_map(static fn (ClassReflection $class): string => $class->getName(), $mapped));
    }

    private static function isMapped(ClassReflection $class): bool
    {
        foreach ($class->getAttributes() as $attribute) {
            if (in_array($attribute->getName(), self::MAPPING_ATTRIBUTES, true)) {
                return true;
            }
        }

        return false;
    }

    private static function error(string $message): IdentifierRuleError
    {
        return RuleErrorBuilder::message($message)
            ->identifier('simpleFeedReader.thinController.entity')
            ->build();
    }
}
