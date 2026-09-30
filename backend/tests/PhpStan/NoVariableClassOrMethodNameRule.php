<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;

/** @implements Rule<Expr> */
final readonly class NoVariableClassOrMethodNameRule implements Rule
{
    public const string MESSAGE = 'A variable names a class or a method here, so neither Find usages nor PHPStan '
        . 'can follow it; call it by its name, through a match or an interface method (#1294).';

    public function getNodeType(): string
    {
        return Expr::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$this->namesThroughAVariable($node)) {
            return [];
        }

        return VariableClassOrMethodNameReport::inApplicationCode($scope);
    }

    private function namesThroughAVariable(Node $node): bool
    {
        return match (true) {
            $node instanceof Variable => $node->name instanceof Expr,
            $node instanceof MethodCall => $node->name instanceof Expr,
            $node instanceof StaticCall => $node->class instanceof Expr || $node->name instanceof Expr,
            $node instanceof StaticPropertyFetch => $node->class instanceof Expr,
            $node instanceof ClassConstFetch => $this->fetchesThroughAVariable($node),
            $node instanceof New_, $node instanceof Instanceof_ => $node->class instanceof Expr,
            default => false,
        };
    }

    private function fetchesThroughAVariable(ClassConstFetch $fetch): bool
    {
        $readsTheClassName = $fetch->name instanceof Identifier && 'class' === $fetch->name->toLowerString();

        return $fetch->name instanceof Expr || ($fetch->class instanceof Expr && !$readsTheClassName);
    }
}
