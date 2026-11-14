<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Digest;

use App\Entity\User;
use App\Service\Mail\Digest\Exception\TestDigestUnavailableException;
use App\Service\Mail\Digest\TestDigestEligibility;
use App\Tests\DbTestCase;
use App\Tests\Support\EnablesMailInTests;

final class TestDigestEligibilityTest extends DbTestCase
{
    use EnablesMailInTests;

    public function testAVerifiedAddressOnAMailSendingInstanceIsEligible(): void
    {
        $this->seedEnabledMailInstance();

        $this->expectNotToPerformAssertions();

        $this->eligibility()->assertEligible($this->verifiedUser());
    }

    public function testAnUnverifiedAddressIsRefused(): void
    {
        $this->seedEnabledMailInstance();

        $this->expectException(TestDigestUnavailableException::class);
        $this->expectExceptionMessage('Mail is unavailable for this account.');

        $this->eligibility()->assertEligible($this->unverifiedUser());
    }

    public function testAnInstanceThatSendsNoMailRefusesEvenAVerifiedAddress(): void
    {
        $this->expectException(TestDigestUnavailableException::class);
        $this->expectExceptionMessage('Mail is unavailable for this account.');

        $this->eligibility()->assertEligible($this->verifiedUser());
    }

    private function eligibility(): TestDigestEligibility
    {
        return self::getContainer()->get(TestDigestEligibility::class);
    }

    private function verifiedUser(): User
    {
        $user = $this->unverifiedUser();
        $user->markEmailVerified(new \DateTimeImmutable('2026-08-01T00:00:00Z'));

        return $user;
    }

    private function unverifiedUser(): User
    {
        return new User('test-digest@example.test', new \DateTimeImmutable('2026-08-01T00:00:00Z'));
    }
}
