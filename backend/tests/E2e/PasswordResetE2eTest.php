<?php

declare(strict_types=1);

namespace App\Tests\E2e;

use App\Tests\E2e\Support\E2eTestCase;

/**
 * A password reset revokes every token issued before it, end to end: the pre-reset JWT works, then gets 401 after the
 * reset, and the new password logs in.
 */
final class PasswordResetE2eTest extends E2eTestCase
{
    public function testResetRevokesPreResetTokenAndNewPasswordWorks(): void
    {
        $email = $this->uniqueEmail();
        $oldPassword = 'old-password-1234';
        $newPassword = 'new-password-5678';

        // Onboard to active (register → verify → admin approve).
        self::assertSame(202, $this->register($email, $oldPassword)->getStatusCode());
        $this->postJson('/api/auth/verify-email', ['token' => $this->tokenFromEmail($email)]);
        $adminToken = $this->login('e2e-admin@example.com', 'e2e-admin-password-123');
        $userId = $this->adminFindUserId($adminToken, $email);
        $this->postJson('/api/admin/users/' . $userId . '/approve', [], $adminToken);

        // A valid token obtained by logging in, proven to work.
        $preResetToken = $this->login($email, $oldPassword);
        self::assertSame(200, $this->getJson('/api/me', $preResetToken)->getStatusCode());

        // Revocation compares whole seconds (`iat < passwordChangedAt`): a reset a second later makes the 401 certain.
        sleep(1);

        // Clear the verification mail so tokenFromEmail() finds the reset mail; e2e runs serially, so this is safe.
        $this->mailpit->deleteAll();

        // Request + perform the reset with the token from the (now only) email.
        self::assertSame(200, $this->postJson('/api/auth/password-reset-request', [
            'email' => $email,
            'altcha' => $this->altcha->solve(),
        ])->getStatusCode());

        $reset = $this->postJson('/api/auth/password-reset', [
            'token' => $this->tokenFromEmail($email),
            'password' => $newPassword,
        ]);
        self::assertSame(200, $reset->getStatusCode());
        self::assertSame(['status' => 'reset'], $reset->toArray());

        // The pre-reset token is now rejected; the new password logs in and works.
        self::assertSame(401, $this->getJson('/api/me', $preResetToken)->getStatusCode());
        self::assertSame(200, $this->getJson('/api/me', $this->login($email, $newPassword))->getStatusCode());
    }

    private function adminFindUserId(string $adminToken, string $email): int
    {
        $body = $this->getJson('/api/admin/users', $adminToken)->toArray();

        if (!isset($body['users']) || !is_array($body['users'])) {
            self::fail('Admin users response did not contain a "users" list.');
        }

        foreach ($body['users'] as $user) {
            if (!is_array($user)) {
                continue;
            }

            $userEmail = $user['email'] ?? null;
            $userId = $user['id'] ?? null;

            if (is_string($userEmail) && $userEmail === $email && (is_int($userId) || is_string($userId))) {
                return (int) $userId;
            }
        }

        self::fail('User ' . $email . ' not found in the admin queue.');
    }
}
