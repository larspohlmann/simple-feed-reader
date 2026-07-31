<?php

declare(strict_types=1);

namespace App\Tests\Service\Admin;

use App\Entity\User;
use App\Exception\ValidationException;
use App\Service\Admin\SelfActionGuard;
use PHPUnit\Framework\TestCase;

final class SelfActionGuardTest extends TestCase
{
    public function testItRejectsAnAdminActingOnTheirOwnAccount(): void
    {
        $admin = $this->userWithId(7);

        try {
            (new SelfActionGuard())->ensureNotSelf($admin, $admin);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            $this->assertSame(422, $exception->status);
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
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn($id);

        return $user;
    }
}
