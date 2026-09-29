<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Param;
use PhpParser\Node\PropertyItem;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * No variable, parameter or property is a single letter or a truncated word (CLAUDE.md "Names reveal intent", #1172).
 * A method overriding one declared outside App keeps its parent's parameter names: named arguments bind to them.
 *
 * @implements Rule<FileNode>
 */
final readonly class AbbreviatedNameRule implements Rule
{
    /**
     * Class => the names it keeps for its wire shape; only ever shrinks, and every entry carries its reason.
     *
     * @var array<string, list<string>>
     */
    private const array WIRE_NAMES = [
        // The JSON body's key: `q` is the search term's key on every client.
        'App\\Dto\\Search\\MarkSearchReadRequest' => ['q'],
    ];

    /** @param array<string, list<string>> $wireNames overridable only for the rule's own test */
    public function __construct(
        private NodeFinder $nodeFinder,
        private ReflectionProvider $reflectionProvider,
        private array $wireNames = self::WIRE_NAMES,
    ) {
    }

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $kept = $this->keptIn($node->getNodes());
        $errors = [];
        foreach ($this->namedIn($node->getNodes()) as $named) {
            $name = self::nameOf($named);
            if (!AbbreviatedNames::isAbbreviated($name) || isset($kept[spl_object_id($named)])) {
                continue;
            }
            $line = $named->getStartLine();
            $errors[$line . '$' . $name] = self::error($name, $line);
        }

        return array_values($errors);
    }

    /**
     * @param array<Node> $nodes
     *
     * @return list<Variable|PropertyItem>
     */
    private function namedIn(array $nodes): array
    {
        /** @var list<Variable|PropertyItem> $named */
        $named = $this->nodeFinder->find(
            $nodes,
            static fn (Node $candidate): bool => ($candidate instanceof Variable && \is_string($candidate->name))
                || $candidate instanceof PropertyItem,
        );

        return $named;
    }

    /**
     * @param array<Node> $nodes
     *
     * @return array<int, true> the object ids of the names a class keeps: its wire keys, and the parameters of a method
     *                          whose names a parent outside App fixes, with their uses in that method
     */
    private function keptIn(array $nodes): array
    {
        $kept = [];
        foreach ($this->nodeFinder->findInstanceOf($nodes, Class_::class) as $class) {
            $className = isset($class->namespacedName) ? $class->namespacedName->toString() : '';
            $wireNames = $this->wireNames[$className] ?? [];
            foreach ($this->namedIn([$class]) as $named) {
                if (\in_array(self::nameOf($named), $wireNames, true)) {
                    $kept[spl_object_id($named)] = true;
                }
            }
            foreach ($class->getMethods() as $method) {
                foreach ($this->inheritedParametersOf($className, $method) as $named) {
                    $kept[spl_object_id($named)] = true;
                }
            }
        }

        return $kept;
    }

    /** @return list<Variable|PropertyItem> */
    private function inheritedParametersOf(string $className, ClassMethod $method): array
    {
        if (!$this->overridesForeignMethod($className, $method->name->toString())) {
            return [];
        }
        $parameters = array_map(self::parameterName(...), $method->params);

        return array_values(array_filter(
            $this->namedIn([$method]),
            static fn (Variable|PropertyItem $named): bool => \in_array(self::nameOf($named), $parameters, true),
        ));
    }

    private function overridesForeignMethod(string $className, string $methodName): bool
    {
        if ('__construct' === strtolower($methodName) || !$this->reflectionProvider->hasClass($className)) {
            return false;
        }
        $class = $this->reflectionProvider->getClass($className);
        foreach ([...$class->getParents(), ...array_values($class->getInterfaces())] as $ancestor) {
            if ($ancestor->hasNativeMethod($methodName) && !str_starts_with($ancestor->getName(), 'App\\')) {
                return true;
            }
        }

        return false;
    }

    private static function parameterName(Param $parameter): string
    {
        return $parameter->var instanceof Variable ? self::nameOf($parameter->var) : '';
    }

    private static function nameOf(Variable|PropertyItem $named): string
    {
        if ($named instanceof PropertyItem) {
            return $named->name->toString();
        }

        return \is_string($named->name) ? $named->name : '';
    }

    private static function error(string $name, int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message(sprintf(
            'Name reveals intent: $%s is a single letter or a truncated word; name what it holds (#1172).',
            $name,
        ))
            ->identifier('simpleFeedReader.abbreviatedName')
            ->line($line)
            ->build();
    }
}
