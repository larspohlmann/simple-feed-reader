<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Type;

/**
 * Thin-controller rule, expression half (#1157): no `new App\Entity\*`, and only get/is/has/requireId() on an entity.
 * Handles MethodCall only: PHPStan 2.2.5 also passes each ?-> call as a MethodCall, so this reports it once.
 *
 * @implements Rule<CallLike>
 */
final readonly class ControllerMutatesNoEntityRule implements Rule
{
    private const string CONTROLLER_NAMESPACE_PREFIX = 'App\\Controller\\';
    private const string ENTITY_NAMESPACE_PREFIX = 'App\\Entity\\';
    private const string QUERY_METHOD = '/^(get|is|has)[A-Z]/';
    private const string ID_READ = 'requireId';
    private const string ADVICE = 'An action reads the request, delegates, and returns a response; construct and '
        . 'change entities in a service under src/Service, which also persists them (#1157).';

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!self::isInController($scope)) {
            return [];
        }

        return match (true) {
            $node instanceof New_ => self::constructionErrors($node, $scope),
            $node instanceof MethodCall => self::mutationErrors($node, $scope),
            default => [],
        };
    }

    private static function isInController(Scope $scope): bool
    {
        $classReflection = $scope->getClassReflection();

        return null !== $classReflection
            && str_starts_with($classReflection->getName(), self::CONTROLLER_NAMESPACE_PREFIX);
    }

    /** @return list<IdentifierRuleError> */
    private static function constructionErrors(New_ $node, Scope $scope): array
    {
        if (!$node->class instanceof Name) {
            return [];
        }

        $className = $scope->resolveName($node->class);
        if (!self::isEntity($className)) {
            return [];
        }

        return [self::error(sprintf('A controller constructs the entity %s. %s', $className, self::ADVICE))];
    }

    /** @return list<IdentifierRuleError> */
    private static function mutationErrors(MethodCall $node, Scope $scope): array
    {
        if (!$node->name instanceof Identifier || self::isQuery($node->name->name)) {
            return [];
        }

        $receiverType = $scope->getType($node->var);
        $entities = array_values(array_filter($receiverType->getObjectClassNames(), self::isEntity(...)));
        if ([] === $entities) {
            return [];
        }

        return [self::error(sprintf(
            'A controller calls %s::%s(), which changes an entity. %s',
            self::declaringClassName($scope, $receiverType, $node->name->name) ?? $entities[0],
            $node->name->name,
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

    private static function isEntity(string $className): bool
    {
        return str_starts_with($className, self::ENTITY_NAMESPACE_PREFIX);
    }

    private static function error(string $message): IdentifierRuleError
    {
        return RuleErrorBuilder::message($message)
            ->identifier('simpleFeedReader.thinController.entity')
            ->build();
    }
}
