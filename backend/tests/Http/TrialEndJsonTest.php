<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\User;
use App\Http\TrialEndJson;
use PHPUnit\Framework\TestCase;

final class TrialEndJsonTest extends TestCase
{
    public function testAnAccountWithoutATrialHasNoEnd(): void
    {
        self::assertNull(TrialEndJson::of($this->user()));
    }

    public function testATrialEndsAtAnAtomInstantThatKeepsItsOffset(): void
    {
        $user = $this->user();
        $user->setTrialEndsAt(new \DateTimeImmutable('2026-10-01T09:30:00+02:00'));

        self::assertSame('2026-10-01T09:30:00+02:00', TrialEndJson::of($user));
    }

    private function user(): User
    {
        return new User('trial-end@example.test', new \DateTimeImmutable('2026-08-01T00:00:00Z'));
    }
}
