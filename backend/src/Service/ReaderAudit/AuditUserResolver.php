<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit;

use App\Service\ReaderAudit\Exception\NoAuditUserException;
use Doctrine\DBAL\Connection;

/**
 * Decides whose subscriptions the audit reads. An installation holds test and
 * onboarding accounts beside the real one, so "no account given" resolves to the
 * account with the most subscriptions rather than to the first row.
 */
final readonly class AuditUserResolver
{
    public function __construct(private Connection $connection)
    {
    }

    public function resolve(?string $idOrEmail): int
    {
        return $idOrEmail === null ? $this->widestSubscriber() : $this->named($idOrEmail);
    }

    private function named(string $idOrEmail): int
    {
        $column = ctype_digit($idOrEmail) ? 'id' : 'email';
        $id = $this->connection->fetchOne("SELECT id FROM app_user WHERE {$column} = :value", ['value' => $idOrEmail]);
        if ($id === false) {
            throw new NoAuditUserException(\sprintf('No user matches "%s".', $idOrEmail));
        }

        return DatabaseValue::int($id);
    }

    private function widestSubscriber(): int
    {
        $id = $this->connection->fetchOne(
            'SELECT user_id FROM subscription GROUP BY user_id ORDER BY COUNT(*) DESC, user_id ASC',
        );
        if ($id === false) {
            throw new NoAuditUserException('No account holds a subscription to audit.');
        }

        return DatabaseValue::int($id);
    }
}
