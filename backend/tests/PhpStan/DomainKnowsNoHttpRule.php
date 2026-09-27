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
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Domain code returns typed values and throws typed exceptions; src/Http shapes them (#1158), and a controller hands a
 * service a value, never a request DTO (#1182). Strings, group imports and namespace aliases count too.
 *
 * @implements Rule<FileNode>
 */
final readonly class DomainKnowsNoHttpRule implements Rule
{
    private const array DOMAIN_NAMESPACES = [
        'App\\Pagination\\',
        'App\\Service\\',
        'App\\Repository\\',
        'App\\Entity\\',
        'App\\Enum\\',
        'App\\Exception\\',
    ];

    private const array HTTP_PREFIXES = [
        'App\\Dto\\',
        'App\\Http\\',
        'Symfony\\Component\\HttpFoundation\\',
        'Symfony\\Component\\HttpKernel\\Exception\\',
    ];

    private const array HTTP_CLASSES = [
        'Symfony\\Component\\Security\\Core\\Exception\\AccessDeniedException',
    ];

    public function __construct(private NodeFinder $finder)
    {
    }

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];
        foreach ($this->finder->findInstanceOf($node->getNodes(), Namespace_::class) as $namespace) {
            $errors = [...$errors, ...$this->errorsIn($namespace)];
        }

        return $errors;
    }

    /** @return list<IdentifierRuleError> */
    private function errorsIn(Namespace_ $namespace): array
    {
        $namespaceName = $namespace->name?->toString() ?? '';
        if (!self::startsWithAny($namespaceName, self::DOMAIN_NAMESPACES)) {
            return [];
        }

        $errors = [];
        foreach ($this->references($namespace) as [$reference, $line]) {
            if (self::isHttp($reference)) {
                $errors[] = self::error($namespaceName, $reference, $line);
            }
        }

        return $errors;
    }

    /** @return list<array{string, int}> every class or namespace name mentioned, in code or in a string */
    private function references(Namespace_ $namespace): array
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

        $references = [];
        foreach ($nodes as $node) {
            if ($node instanceof Name && \in_array(spl_object_id($node), $groupUsePrefixIds, true)) {
                continue;
            }
            $references = [...$references, ...self::referencesIn($node)];
        }

        return $references;
    }

    /** @return list<array{string, int}> */
    private static function referencesIn(Node $node): array
    {
        if ($node instanceof GroupUse) {
            return self::groupedReferences($node);
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
    private static function groupedReferences(GroupUse $groupUse): array
    {
        return array_values(array_map(
            static fn (UseItem $use): array => [
                $groupUse->prefix->toString() . '\\' . $use->name->toString(),
                $use->getStartLine(),
            ],
            $groupUse->uses,
        ));
    }

    private static function isHttp(string $reference): bool
    {
        return array_any(self::HTTP_CLASSES, static fn (string $class): bool => 0 === strcasecmp($class, $reference))
            || self::startsWithAny($reference, self::HTTP_PREFIXES);
    }

    /**
     * Case-insensitive, as PHP names are. The appended separator lets a bare namespace, as an alias import names it,
     * match its own prefix.
     *
     * @param list<string> $prefixes
     */
    private static function startsWithAny(string $name, array $prefixes): bool
    {
        $subject = strtolower($name) . '\\';
        foreach ($prefixes as $prefix) {
            if (str_starts_with($subject, strtolower($prefix))) {
                return true;
            }
        }

        return false;
    }

    private static function error(string $namespaceName, string $reference, int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message(sprintf(
            'Domain code must not know HTTP: %s references %s. %s',
            $namespaceName,
            $reference,
            self::remedyFor($reference),
        ))
            ->identifier('simpleFeedReader.domainKnowsNoHttp')
            ->line($line)
            ->build();
    }

    private static function remedyFor(string $reference): string
    {
        if (self::startsWithAny($reference, ['App\\Dto\\'])) {
            return 'Take the service value the request DTO builds, not the DTO (#1182).';
        }

        return 'Return a typed value or throw a typed exception, and let src/Http shape it (#1158).';
    }
}
