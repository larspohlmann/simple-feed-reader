<?php

declare(strict_types=1);

namespace App\Tests\Dto\Subscription;

use App\Dto\Subscription\BulkUnsubscribeRequest;
use App\Dto\Subscription\BulkUpdateSubscriptionsRequest;
use App\Service\Subscription\SubscriptionService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class BulkRequestValidationTest extends KernelTestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        self::bootKernel();
        $validator = self::getContainer()->get(ValidatorInterface::class);
        self::assertInstanceOf(ValidatorInterface::class, $validator);
        $this->validator = $validator;
    }

    public function testIdsOnlyIsValid(): void
    {
        $request = new BulkUpdateSubscriptionsRequest(subscriptionIds: [1, 2, 3]);

        self::assertCount(0, $this->validator->validate($request));
    }

    public function testAnEmptyIdListIsRejected(): void
    {
        $request = new BulkUpdateSubscriptionsRequest(subscriptionIds: []);

        self::assertGreaterThan(0, \count($this->validator->validate($request)));
    }

    /**
     * The cap is a hard technical ceiling on one request's payload, not "every
     * feed the caller could own" (#659 review): an admin can raise a single
     * account's subscription limit above MAX_SUBSCRIPTIONS_PER_USER via
     * SubscriptionLimitResolver, so a request naming more ids than the global
     * default must still validate as long as it stays under the technical
     * ceiling.
     */
    public function testMoreIdsThanTheDefaultAccountCapAreAccepted(): void
    {
        $moreThanTheDefaultCap = range(1, SubscriptionService::MAX_SUBSCRIPTIONS_PER_USER + 1);
        $request = new BulkUpdateSubscriptionsRequest(subscriptionIds: $moreThanTheDefaultCap);

        self::assertCount(0, $this->validator->validate($request));
    }

    public function testMoreIdsThanTheHardCeilingAreRejected(): void
    {
        $tooMany = range(1, SubscriptionService::MAX_BULK_REQUEST_IDS + 1);
        $request = new BulkUpdateSubscriptionsRequest(subscriptionIds: $tooMany);

        self::assertGreaterThan(0, \count($this->validator->validate($request)));
    }

    public function testExactlyTheHardCeilingIsAccepted(): void
    {
        $atCeiling = range(1, SubscriptionService::MAX_BULK_REQUEST_IDS);
        $request = new BulkUpdateSubscriptionsRequest(subscriptionIds: $atCeiling);

        self::assertCount(0, $this->validator->validate($request));
    }

    public function testANegativeTagIdIsRejected(): void
    {
        $request = new BulkUpdateSubscriptionsRequest(subscriptionIds: [1], addTagIds: [-4]);

        self::assertGreaterThan(0, \count($this->validator->validate($request)));
    }

    public function testUnsubscribeRequestSharesTheSameCap(): void
    {
        $tooMany = range(1, SubscriptionService::MAX_BULK_REQUEST_IDS + 1);

        self::assertCount(0, $this->validator->validate(new BulkUnsubscribeRequest([1])));
        self::assertGreaterThan(0, \count($this->validator->validate(new BulkUnsubscribeRequest($tooMany))));
    }

    public function testAnEmptyUnsubscribeIdListIsRejected(): void
    {
        $request = new BulkUnsubscribeRequest([]);

        self::assertGreaterThan(0, \count($this->validator->validate($request)));
    }
}
