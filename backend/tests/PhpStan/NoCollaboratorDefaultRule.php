<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Param;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A service takes its collaborators from the container, never from a `new` in a constructor parameter's default
 * (CLAUDE.md "Depend on interfaces, inject them", #1169). Models, DTOs and per-call objects may default a value.
 *
 * @implements Rule<InClassNode>
 */
final readonly class NoCollaboratorDefaultRule implements Rule
{
    private const array SERVICE_NAMESPACES = [
        'App\\Command\\',
        'App\\Controller\\',
        'App\\EventListener\\',
        'App\\Http\\',
        'App\\Security\\',
        'App\\Service\\',
    ];

    private const array VALUE_ROLES = [ServiceRoleNames::DTO, ServiceRoleNames::MODEL, ServiceRoleNames::PASS];

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $className = $node->getClassReflection()->getName();
        $constructor = $node->getOriginalNode()->getMethod('__construct');
        if (null === $constructor || !self::isService($className)) {
            return [];
        }

        $defaultedWithNew = array_filter(
            $constructor->params,
            static fn (Param $parameter): bool => $parameter->default instanceof New_,
        );

        return array_values(array_map(
            static fn (Param $parameter): IdentifierRuleError => self::error($className, $parameter),
            $defaultedWithNew,
        ));
    }

    private static function isService(string $className): bool
    {
        return ClassNameReferences::isInAnyOf($className, self::SERVICE_NAMESPACES)
            && !\in_array(ServiceRoleNames::roleOfClass($className), self::VALUE_ROLES, true);
    }

    private static function error(string $className, Param $parameter): IdentifierRuleError
    {
        $variable = $parameter->var;
        $name = $variable instanceof Variable && \is_string($variable->name) ? $variable->name : '';

        return RuleErrorBuilder::message(sprintf(
            'Inject the collaborator: %s::__construct() defaults $%s with new (#1169).',
            $className,
            $name,
        ))
            ->identifier('simpleFeedReader.noCollaboratorDefault')
            ->line($parameter->getStartLine())
            ->build();
    }
}
