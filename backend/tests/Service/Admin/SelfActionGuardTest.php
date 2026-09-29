<?php

declare(strict_types=1);

namespace App\Tests\Service\Admin;

use App\Entity\User;
use App\Exception\ValidationException;
use App\Service\Admin\SelfActionGuard;
use App\Tests\Support\AssignsEntityIds;
use PHPUnit\Framework\TestCase;

final class SelfActionGuardTest extends TestCase
{
    use AssignsEntityIds;

    public function testItRejectsAnAdminActingOnTheirOwnAccount(): void
    {
        $admin = $this->userWithId(7);

        try {
            (new SelfActionGuard())->ensureNotSelf($admin, $admin);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['id' => ['You cannot change your own account status.']],
                $exception->errors,
            );
        }
    }

    public function testItAllowsAnAdminActingOnAnotherAccount(): void
    {
        $this->expectNotToPerformAssertions();

        (new SelfActionGuard())->ensureNotSelf($this->userWithId(7), $this->userWithId(8));
    }

    private function userWithId(int $id): User
    {
        $user = new User(sprintf('user-%d@example.com', $id), new \DateTimeImmutable('2026-07-01 10:00:00'));
        self::assignId($user, $id);

        return $user;
    }
}
