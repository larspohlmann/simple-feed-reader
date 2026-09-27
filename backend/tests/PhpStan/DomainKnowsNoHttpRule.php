<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Stmt\Namespace_;
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

    private const string LET_HTTP_SHAPE_IT
        = 'Return a typed value or throw a typed exception, and let src/Http shape it (#1158).';

    private const array REMEDIES = [
        'App\\Dto\\' => 'Take the service value the request DTO builds, not the DTO (#1182).',
        'App\\Http\\' => self::LET_HTTP_SHAPE_IT,
        'Symfony\\Component\\HttpFoundation\\' => self::LET_HTTP_SHAPE_IT,
        'Symfony\\Component\\HttpKernel\\Exception\\' => self::LET_HTTP_SHAPE_IT,
        'Symfony\\Component\\Security\\Core\\Exception\\AccessDeniedException' => self::LET_HTTP_SHAPE_IT,
    ];

    private ClassNameReferences $references;

    public function __construct(NodeFinder $finder)
    {
        $this->references = new ClassNameReferences($finder, array_keys(self::REMEDIES));
    }

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];
        foreach ($this->references->namespacesIn($node) as $namespace) {
            $errors = [...$errors, ...$this->errorsIn($namespace)];
        }

        return $errors;
    }

    /** @return list<IdentifierRuleError> */
    private function errorsIn(Namespace_ $namespace): array
    {
        $namespaceName = $namespace->name?->toString() ?? '';
        if (!ClassNameReferences::isInAnyOf($namespaceName, self::DOMAIN_NAMESPACES)) {
            return [];
        }

        return array_map(
            static fn (ForbiddenReference $reference): IdentifierRuleError => self::error($namespaceName, $reference),
            $this->references->forbiddenIn($namespace),
        );
    }

    private static function error(string $namespaceName, ForbiddenReference $reference): IdentifierRuleError
    {
        return RuleErrorBuilder::message(sprintf(
            'Domain code must not know HTTP: %s references %s. %s',
            $namespaceName,
            $reference->name,
            self::REMEDIES[$reference->matchedRule],
        ))
            ->identifier('simpleFeedReader.domainKnowsNoHttp')
            ->line($reference->line)
            ->build();
    }
}
