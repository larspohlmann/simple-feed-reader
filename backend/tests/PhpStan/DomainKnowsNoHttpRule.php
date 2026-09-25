<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Domain code throws typed exceptions and src/Http/Problem maps them (#1160). Response and App\Http are forbidden
 * only in exception namespaces until #1158 removes the presentation imports from services.
 *
 * @implements Rule<FileNode>
 */
final readonly class DomainKnowsNoHttpRule implements Rule
{
    private const array DOMAIN_NAMESPACES = [
        'App\\Service\\',
        'App\\Repository\\',
        'App\\Entity\\',
        'App\\Enum\\',
        'App\\Exception\\',
    ];

    private const string HTTP_EXCEPTIONS = 'Symfony\\Component\\HttpKernel\\Exception\\';
    private const string RESPONSE = 'Symfony\\Component\\HttpFoundation\\Response';
    private const string HTTP_LAYER = 'App\\Http\\';

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
        foreach ($this->finder->findInstanceOf($namespace->stmts, Name::class) as $reference) {
            if (self::isForbidden($reference->toString(), $namespaceName)) {
                $errors[] = self::error($namespaceName, $reference);
            }
        }

        return $errors;
    }

    private static function isDomain(string $namespaceName): bool
    {
        foreach (self::DOMAIN_NAMESPACES as $root) {
            if (str_starts_with($namespaceName . '\\', $root)) {
                return true;
            }
        }

        return false;
    }

    private static function isForbidden(string $reference, string $namespaceName): bool
    {
        if (str_starts_with($reference, self::HTTP_EXCEPTIONS)) {
            return true;
        }

        return self::isExceptionNamespace($namespaceName)
            && (self::RESPONSE === $reference || str_starts_with($reference, self::HTTP_LAYER));
    }

    private static function isExceptionNamespace(string $namespaceName): bool
    {
        return \in_array('Exception', explode('\\', $namespaceName), true);
    }

    private static function error(string $namespaceName, Name $reference): IdentifierRuleError
    {
        return RuleErrorBuilder::message(sprintf(
            'Domain code must not know HTTP: %s references %s. '
            . 'Throw a typed exception and map it in src/Http/Problem (#1160).',
            $namespaceName,
            $reference->toString(),
        ))
            ->identifier('simpleFeedReader.domainKnowsNoHttp')
            ->line($reference->getStartLine())
            ->build();
    }
}
