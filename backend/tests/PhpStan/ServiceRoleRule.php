<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

/**
 * Every class in src/Service and src/Http has one role, and its folder and name say which (#1202,
 * docs/architecture.md §10). Until #1202 closes, a run reports only the checks it is given.
 *
 * @implements Rule<CollectedDataNode>
 */
final readonly class ServiceRoleRule implements Rule
{
    /** @var list<ServiceRoleCheck> */
    private array $checks;

    /** @var list<ServiceRoleChecker> */
    private array $checkers;

    /** @param list<string> $checks */
    public function __construct(private ReflectionProvider $reflectionProvider, array $checks)
    {
        $this->checks = array_map(ServiceRoleCheck::from(...), $checks);
        $this->checkers = [
            new InterfacePlacement(),
            new RoleFolderNames(),
            new RoleFolderInterfaces(),
            new DataShapes(),
            new RootPlacement(),
            new ServiceShapes(),
            new MessagingNames(),
        ];
    }

    public function getNodeType(): string
    {
        return CollectedDataNode::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        $map = ServiceRoleMap::fromCollected($this->reflectionProvider, $node);
        $errors = array_map(
            static fn (UnresolvedServiceRoleClass $class): IdentifierRuleError => $class->toError(),
            $map->unresolvedClasses(),
        );
        foreach ($this->checkers as $checker) {
            foreach ($checker->violationsIn($map) as $violation) {
                if (\in_array($violation->check, $this->checks, true)) {
                    $errors[] = $violation->toError();
                }
            }
        }

        return $errors;
    }
}
