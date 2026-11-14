<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

/** How many accounts one private feed sync pass may check. */
final readonly class PrivateFeedSyncBudgetModel
{
    /** What the tick asks to have left before it starts one account: typical checks take 1-3 s. */
    private const int TICK_ACCOUNT_SECONDS = 5;

    private function __construct(public int $accounts)
    {
    }

    public static function accounts(int $count): self
    {
        if ($count < 0) {
            throw new \InvalidArgumentException('A private feed sync budget cannot be negative.');
        }

        return new self($count);
    }

    /** The whole accounts that fit before $deadline, at most $maxAccounts: none once less than one fits. */
    public static function fittingBefore(\DateTimeImmutable $deadline, \DateTimeImmutable $now, int $maxAccounts): self
    {
        $remaining = $deadline->getTimestamp() - $now->getTimestamp();

        return self::accounts(min($maxAccounts, intdiv(max(0, $remaining), self::TICK_ACCOUNT_SECONDS)));
    }

    public function isEmpty(): bool
    {
        return $this->accounts === 0;
    }
}
