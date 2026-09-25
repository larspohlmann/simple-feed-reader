<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Queries live in src/Repository (#1170, docs/architecture.md §7): elsewhere no class builds DQL, opens a
 * QueryBuilder or holds the DBAL connection. src/Doctrine extends the ORM itself and is exempt, as are the tests.
 *
 * @implements Rule<InClassNode>
 */
final readonly class QueriesLiveInRepositoriesRule implements Rule
{
    private const array EXEMPT_NAMESPACES = ['App\\Repository\\', 'App\\Doctrine\\', 'App\\Tests\\'];

    private const array QUERY_METHODS = ['createNativeQuery', 'createQuery', 'createQueryBuilder', 'getConnection'];

    private const array QUERY_TYPES = [
        'Doctrine\\DBAL\\Connection',
        'Doctrine\\DBAL\\Query\\QueryBuilder',
        'Doctrine\\ORM\\NativeQuery',
        'Doctrine\\ORM\\Query',
        'Doctrine\\ORM\\QueryBuilder',
    ];

    /** Seeded with every offender the day the rule landed; each #1170 task deletes its own, so it only shrinks. */
    private const array ALLOW_LIST = [
        'App\\Controller\\Api\\HealthController',
        'App\\Service\\Backup\\EntryBatchInserter',
        'App\\Service\\ReaderAudit\\AuditSampler',
        'App\\Service\\ReaderAudit\\AuditUserResolver',
        'App\\Service\\Recommendation\\RecommendationCallRecorder',
        'App\\Service\\Recommendation\\RecommendationCandidateLoader',
        'App\\Service\\Recommendation\\RecommendationHistoryLoader',
        'App\\Service\\Recommendation\\RecordedCall',
        'App\\Service\\Search\\Membership\\DatabaseSavedSearchMatcher',
        'App\\Service\\Worker\\Handler\\PurgeFailedMessagesHandler',
    ];

    /** @param list<string> $allowList fully qualified class names; overridable only for the rule's own test */
    public function __construct(private NodeFinder $finder, private array $allowList = self::ALLOW_LIST)
    {
    }

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $className = $node->getClassReflection()->getName();
        if (!$this->isGuarded($className)) {
            return [];
        }

        $errors = [];
        foreach ($this->finder->find($node->getOriginalNode()->stmts, self::isQueryAccess(...)) as $access) {
            $errors[] = self::error($className, $access);
        }

        return $errors;
    }

    private function isGuarded(string $className): bool
    {
        if (!str_starts_with($className, 'App\\') || \in_array($className, $this->allowList, true)) {
            return false;
        }

        foreach (self::EXEMPT_NAMESPACES as $exempt) {
            if (str_starts_with($className, $exempt)) {
                return false;
            }
        }

        return true;
    }

    private static function isQueryAccess(Node $node): bool
    {
        return \in_array(self::typeName($node), self::QUERY_TYPES, true)
            || \in_array(self::calledMethod($node), self::QUERY_METHODS, true);
    }

    private static function typeName(Node $node): ?string
    {
        return $node instanceof Name ? $node->toString() : null;
    }

    private static function calledMethod(Node $node): ?string
    {
        if (!$node instanceof MethodCall && !$node instanceof NullsafeMethodCall) {
            return null;
        }

        return $node->name instanceof Identifier ? $node->name->toString() : null;
    }

    private static function error(string $className, Node $access): IdentifierRuleError
    {
        return RuleErrorBuilder::message(sprintf(
            'Queries live in src/Repository: %s uses %s. Move the query into a repository method (#1170).',
            $className,
            self::typeName($access) ?? '->' . self::calledMethod($access) . '()',
        ))
            ->identifier('simpleFeedReader.queriesLiveInRepositories')
            ->line($access->getStartLine())
            ->build();
    }
}
