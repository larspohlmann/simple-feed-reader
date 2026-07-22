<?php

declare(strict_types=1);

namespace App\Tests\E2e;

use App\Tests\E2e\Support\E2eTestCase;

/**
 * The whole account lifecycle through the real stack: register → verify (token
 * from Mailpit) → admin approves → login → authenticated /api/me. Every hop is a
 * real HTTP round-trip against MySQL, so this catches wiring the in-kernel tests
 * cannot — nginx routing, real mail delivery, the JWT actually validating.
 */
final class OnboardingJourneyE2eTest extends E2eTestCase
{
    public function testRegisterVerifyApproveLogin(): void
    {
        $email = $this->uniqueEmail();
        $password = 'onboarding-password-123';

        // 1. Register — 202, account exists but unusable.
        $register = $this->register($email, $password);
        self::assertSame(202, $register->getStatusCode());
        self::assertSame(['status' => 'pending_verification'], $register->toArray());

        // 2. Verify using the token that only exists in the email.
        $verify = $this->postJson('/api/auth/verify-email', ['token' => $this->tokenFromEmail($email)]);
        self::assertSame(200, $verify->getStatusCode());
        self::assertSame(['status' => 'pending_approval'], $verify->toArray());

        // 3. Admin approves. Find the id by email rather than assuming a value.
        $adminToken = $this->login('e2e-admin@example.com', 'e2e-admin-password-123');
        $userId = $this->adminFindUserId($adminToken, $email);

        $approve = $this->postJson('/api/admin/users/' . $userId . '/approve', [], $adminToken);
        self::assertSame(200, $approve->getStatusCode());

        $approveBody = $approve->toArray();
        self::assertArrayHasKey('status', $approveBody);
        self::assertSame('active', $approveBody['status']);

        // 4. The user can now log in and reach an authenticated endpoint.
        $userToken = $this->login($email, $password);
        $me = $this->getJson('/api/me', $userToken);
        self::assertSame(200, $me->getStatusCode());

        $meBody = $me->toArray();
        self::assertArrayHasKey('email', $meBody);
        self::assertSame($email, $meBody['email']);
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

        self::fail('Registered user ' . $email . ' not found in the admin queue.');
    }
}
