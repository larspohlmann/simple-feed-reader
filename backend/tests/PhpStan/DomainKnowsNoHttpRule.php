<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Domain code returns typed values and throws typed exceptions; src/Http shapes them (#1158). Strings count too.
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

    private const array SYMFONY_HTTP_PREFIXES = [
        'Symfony\\Component\\HttpFoundation\\',
        'Symfony\\Component\\HttpKernel\\Exception\\',
    ];

    private const array SYMFONY_HTTP_CLASSES = [
        'Symfony\\Component\\Security\\Core\\Exception\\AccessDeniedException',
    ];

    private const string HTTP_LAYER = 'App\\Http\\';
    private const string SERVICES = 'App\\Service\\';

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
        if (!self::isDomain($namespaceName)) {
            return [];
        }

        $errors = [];
        foreach ($this->references($namespace) as [$reference, $line]) {
            if (self::isForbidden($reference, $namespaceName)) {
                $errors[] = self::error($namespaceName, $reference, $line);
            }
        }

        return $errors;
    }

    /** @return list<array{string, int}> every class name mentioned, in code or in a string, with its line */
    private function references(Namespace_ $namespace): array
    {
        $nodes = $this->finder->find(
            $namespace->stmts,
            static fn (Node $node): bool => $node instanceof Name || $node instanceof String_,
        );

        $references = [];
        foreach ($nodes as $node) {
            if ($node instanceof Name) {
                $references[] = [$node->toString(), $node->getStartLine()];
                continue;
            }
            if ($node instanceof String_) {
                $references[] = [ltrim($node->value, '\\'), $node->getStartLine()];
            }
        }

        return $references;
    }

    private static function isDomain(string $namespaceName): bool
    {
        return self::startsWithAny($namespaceName . '\\', self::DOMAIN_NAMESPACES);
    }

    private static function isForbidden(string $reference, string $namespaceName): bool
    {
        if (self::isSymfonyHttp($reference)) {
            return true;
        }

        return str_starts_with($reference, self::HTTP_LAYER) && !self::stillShapesJson($namespaceName);
    }

    private static function isSymfonyHttp(string $reference): bool
    {
        return \in_array($reference, self::SYMFONY_HTTP_CLASSES, true)
            || self::startsWithAny($reference, self::SYMFONY_HTTP_PREFIXES);
    }

    /** Until #1158's second PR, services outside exception namespaces may still call App\Http mappers. */
    private static function stillShapesJson(string $namespaceName): bool
    {
        return str_starts_with($namespaceName . '\\', self::SERVICES)
            && !\in_array('Exception', explode('\\', $namespaceName), true);
    }

    /** @param list<string> $prefixes */
    private static function startsWithAny(string $subject, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($subject, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function error(string $namespaceName, string $reference, int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message(sprintf(
            'Domain code must not know HTTP: %s references %s. '
            . 'Return a typed value or throw a typed exception, and let src/Http shape it (#1158).',
            $namespaceName,
            $reference,
        ))
            ->identifier('simpleFeedReader.domainKnowsNoHttp')
            ->line($line)
            ->build();
    }
}
