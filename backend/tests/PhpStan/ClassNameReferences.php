<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\Node;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\UseItem;
use PhpParser\NodeFinder;
use PHPStan\Node\FileNode;

/** The forbidden class and namespace names a namespace mentions, in code, in strings and in imports. */
final readonly class ClassNameReferences
{
    /** @param list<string> $forbiddenNames a name ending in a separator forbids that namespace, any other one class */
    public function __construct(private NodeFinder $finder, private array $forbiddenNames)
    {
    }

    /** @return list<Namespace_> */
    public function namespacesIn(FileNode $file): array
    {
        return array_values($this->finder->findInstanceOf($file->getNodes(), Namespace_::class));
    }

    /** @return list<ForbiddenReference> */
    public function forbiddenIn(Namespace_ $namespace): array
    {
        $forbidden = [];
        foreach ($this->namesIn($namespace) as [$name, $line]) {
            $matchedRule = array_find(
                $this->forbiddenNames,
                static fn (string $forbiddenName): bool => self::matches($name, $forbiddenName),
            );
            if (null !== $matchedRule) {
                $forbidden[] = new ForbiddenReference($name, $line, $matchedRule);
            }
        }

        return $forbidden;
    }

    /**
     * Case-insensitive, as PHP names are. The appended separator lets a bare namespace, as an alias import names it,
     * match its own prefix.
     *
     * @param list<string> $namespaces
     */
    public static function isInAnyOf(string $name, array $namespaces): bool
    {
        $subject = strtolower($name) . '\\';

        return array_any(
            $namespaces,
            static fn (string $namespace): bool => str_starts_with($subject, strtolower($namespace)),
        );
    }

    private static function matches(string $name, string $forbiddenName): bool
    {
        if (str_ends_with($forbiddenName, '\\')) {
            return self::isInAnyOf($name, [$forbiddenName]);
        }

        return 0 === strcasecmp($forbiddenName, $name);
    }

    /** @return list<array{string, int}> every class or namespace name mentioned, with its line */
    private function namesIn(Namespace_ $namespace): array
    {
        $nodes = $this->finder->find(
            $namespace->stmts,
            static fn (Node $node): bool => $node instanceof Name
                || $node instanceof String_
                || $node instanceof InterpolatedStringPart
                || $node instanceof GroupUse,
        );

        $groupUsePrefixIds = [];
        foreach ($nodes as $node) {
            if ($node instanceof GroupUse) {
                $groupUsePrefixIds[] = spl_object_id($node->prefix);
            }
        }

        $names = [];
        foreach ($nodes as $node) {
            if ($node instanceof Name && \in_array(spl_object_id($node), $groupUsePrefixIds, true)) {
                continue;
            }
            $names = [...$names, ...self::namesInNode($node)];
        }

        return $names;
    }

    /** @return list<array{string, int}> */
    private static function namesInNode(Node $node): array
    {
        if ($node instanceof GroupUse) {
            return self::groupedNames($node);
        }
        if ($node instanceof Name) {
            return [[$node->toString(), $node->getStartLine()]];
        }
        if ($node instanceof String_ || $node instanceof InterpolatedStringPart) {
            return [[ltrim($node->value, '\\'), $node->getStartLine()]];
        }

        return [];
    }

    /** @return list<array{string, int}> a group import names each class by its prefix and its own tail */
    private static function groupedNames(GroupUse $groupUse): array
    {
        return array_values(array_map(
            static fn (UseItem $use): array => [
                $groupUse->prefix->toString() . '\\' . $use->name->toString(),
                $use->getStartLine(),
            ],
            $groupUse->uses,
        ));
    }
}
