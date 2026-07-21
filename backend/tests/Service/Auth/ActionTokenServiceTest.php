<?php

declare(strict_types=1);

namespace App\Tests\Service\Auth;

use App\Entity\ActionToken;
use App\Entity\User;
use App\Enum\TokenPurpose;
use App\Service\Auth\ActionTokenService;
use App\Tests\DbTestCase;
use Symfony\Component\Clock\MockClock;

final class ActionTokenServiceTest extends DbTestCase
{
    private MockClock $clock;
    private ActionTokenService $service;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new MockClock('2026-07-21 12:00:00');
        $this->service = new ActionTokenService($this->em, $this->clock);

        $this->user = new User('token@example.com', $this->clock->now());
        $this->em->persist($this->user);
        $this->em->flush();
    }

    public function testIssueReturnsAPlaintextTokenAndStoresOnlyItsHash(): void
    {
        $plain = $this->service->issue($this->user, TokenPurpose::VerifyEmail);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $plain);

        $stored = $this->em->getRepository(ActionToken::class)->findOneBy(['user' => $this->user]);
        self::assertNotNull($stored);
        self::assertSame(hash('sha256', $plain), $stored->getTokenHash());
        self::assertNotSame($plain, $stored->getTokenHash());
    }

    public function testExpiryIs24Hours(): void
    {
        $this->service->issue($this->user, TokenPurpose::VerifyEmail);

        $stored = $this->em->getRepository(ActionToken::class)->findOneBy(['user' => $this->user]);
        self::assertNotNull($stored);
        self::assertSame('2026-07-22 12:00:00', $stored->getExpiresAt()->format('Y-m-d H:i:s'));
    }

    public function testConsumeReturnsTheUser(): void
    {
        $plain = $this->service->issue($this->user, TokenPurpose::VerifyEmail);

        $consumed = $this->service->consume($plain, TokenPurpose::VerifyEmail);

        self::assertSame($this->user->getId(), $consumed?->getId());
    }

    public function testConsumeIsSingleUse(): void
    {
        $plain = $this->service->issue($this->user, TokenPurpose::VerifyEmail);
        $this->service->consume($plain, TokenPurpose::VerifyEmail);

        self::assertNull($this->service->consume($plain, TokenPurpose::VerifyEmail));
    }

    public function testConsumeRejectsTheWrongPurpose(): void
    {
        $plain = $this->service->issue($this->user, TokenPurpose::VerifyEmail);

        self::assertNull($this->service->consume($plain, TokenPurpose::ResetPassword));
    }

    public function testConsumeRejectsAnExpiredToken(): void
    {
        $plain = $this->service->issue($this->user, TokenPurpose::VerifyEmail);
        $this->clock->modify('+25 hours');

        self::assertNull($this->service->consume($plain, TokenPurpose::VerifyEmail));
    }

    public function testConsumeRejectsGarbage(): void
    {
        self::assertNull($this->service->consume('not-a-real-token', TokenPurpose::VerifyEmail));
    }

    public function testIssuingInvalidatesEarlierTokensOfTheSamePurpose(): void
    {
        $first = $this->service->issue($this->user, TokenPurpose::ResetPassword);
        $second = $this->service->issue($this->user, TokenPurpose::ResetPassword);

        // Requesting a new reset link must retire the previous one, otherwise
        // an old link stolen from an inbox stays usable for 24 hours.
        self::assertNull($this->service->consume($first, TokenPurpose::ResetPassword));
        self::assertNotNull($this->service->consume($second, TokenPurpose::ResetPassword));
    }

    /**
     * The purge command removes unverified users, and action_token's foreign key
     * is ON DELETE CASCADE, so redeeming a link belonging to a purged account
     * must come back as an ordinary "no such token" rather than exploding on a
     * dangling reference.
     */
    public function testConsumeRejectsATokenWhoseUserHasBeenDeleted(): void
    {
        $plain = $this->service->issue($this->user, TokenPurpose::VerifyEmail);

        $this->em->remove($this->user);
        $this->em->flush();
        $this->em->clear();

        self::assertNull($this->service->consume($plain, TokenPurpose::VerifyEmail));
    }

    /**
     * Later tasks fetch this service straight out of the test container to mint
     * a token. Redefining a service in services_test.yaml replaces its autowired
     * definition instead of amending it, so a missing `autowire: true` there
     * fails only at fetch time — this keeps that failure from surfacing as a
     * baffling error inside an unrelated feature test.
     */
    public function testTheServiceIsFetchableFromTheTestContainer(): void
    {
        self::assertInstanceOf(
            ActionTokenService::class,
            self::getContainer()->get(ActionTokenService::class),
        );
    }
}
